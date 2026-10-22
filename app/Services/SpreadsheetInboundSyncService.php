<?php

namespace App\Services;

use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\IntegrationOutboxEvent;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\SheetInboundChangeStaging;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetTabProfile;
use App\Models\User;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SpreadsheetInboundSyncService
{
    protected SpreadsheetTransportInterface $transport;

    public function __construct(SpreadsheetTransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function setTransport(SpreadsheetTransportInterface $transport): self
    {
        $this->transport = $transport;
        return $this;
    }

    public function getTransport(): SpreadsheetTransportInterface
    {
        return $this->transport;
    }

    /**
     * Scan tab Spreadsheet secara berkala dan tampung seluruh perubahan ke Staging (Non-Destructive).
     *
     * @param SpreadsheetConnection $connection
     * @param int|null $sheetId
     * @param string $tabName
     * @param User|null $actor
     * @param array $options
     * @return array
     */
    public function scanAndStageInboundChanges(
        SpreadsheetConnection $connection,
        ?int $sheetId,
        string $tabName,
        ?User $actor = null,
        array $options = []
    ): array {
        if (!$connection->is_enabled) {
            return [
                'success'    => false,
                'error_code' => 'CONNECTION_DISABLED',
                'message'    => "Koneksi Spreadsheet '{$connection->display_name}' sedang dinonaktifkan.",
            ];
        }

        // 1. Resolve Tab Profile & Baris Header
        $profile = SpreadsheetTabProfile::where('spreadsheet_connection_id', $connection->id)
            ->where(function ($q) use ($sheetId, $tabName) {
                if ($sheetId !== null) {
                    $q->where('sheet_id', (int) $sheetId);
                } else {
                    $q->where('tab_name', $tabName);
                }
            })
            ->first();

        // Resolve year without (int) truncation ambiguities (e.g., '[2026]' should not become 0)
        $tabYear = null;
        if (preg_match('/(\d{4})/', $tabName, $ym)) {
            $tabYear = (int) $ym[1];
        } elseif ($profile?->year) {
            $tabYear = (int) $profile->year;
        }

        $headerRow = $profile?->header_row ?? ($tabYear !== null ? 12 : 1);
        $dataStartRow = $profile?->data_start_row ?? ($headerRow + 1);
        $effectiveSheetId = (int) ($profile?->sheet_id ?? ($sheetId !== null ? (int) $sheetId : 0));

        // 2. Preflight Schema Inspection & Drift Check
        $schemaRes = $this->transport->inspectSchema($connection, $tabName, $headerRow);
        if (!$schemaRes->success) {
            return [
                'success'    => false,
                'error_code' => $schemaRes->errorCode ?: 'SCHEMA_INSPECTION_FAILED',
                'message'    => 'Gagal menginspeksi skema tab Spreadsheet: ' . $schemaRes->errorMessage,
            ];
        }

        $remoteColumns = $schemaRes->context['columns'] ?? [];
        $currentSchemaHash = (string) ($schemaRes->context['schema_hash'] ?? '');

        if (!empty($options['expected_schema_hash']) && $options['expected_schema_hash'] !== $currentSchemaHash) {
            return [
                'success'    => false,
                'error_code' => 'SCHEMA_CONFLICT',
                'message'    => "Schema drift terdeteksi pada tab '{$tabName}'. Hash remote ({$currentSchemaHash}) berbeda dengan expected ({$options['expected_schema_hash']}). Sinkronisasi inbound dihentikan.",
                'context'    => [
                    'current_schema_hash'  => $currentSchemaHash,
                    'expected_schema_hash' => $options['expected_schema_hash'],
                ],
            ];
        }

        // 3. Bangun Peta Kolom dari SpreadsheetColumnMapping aktif dan Remote Columns
        $activeMappings = SpreadsheetColumnMapping::whereHas('schemaVersion', function ($q) use ($connection, $tabName, $tabYear, $effectiveSheetId) {
            $q->where('spreadsheet_connection_id', $connection->id)
              ->where(function ($sub) use ($tabName, $tabYear, $effectiveSheetId) {
                  $sub->where('sheet_tab_name', $tabName);
                  if ($tabYear !== null) {
                      $sub->orWhere('year', $tabYear);
                  }
                  if ($effectiveSheetId !== null) {
                      $sub->orWhere('sheet_id', $effectiveSheetId);
                  }
              })
              ->where('is_active', true);
        })->get()->keyBy(fn ($m) => mb_strtolower(trim($m->sheet_header)));

        $columnMap = [];
        foreach ($remoteColumns as $col) {
            $hName = (string) ($col['header_name'] ?? '');
            if (empty(trim($hName))) {
                continue;
            }
            $colLetter = (string) ($col['col_letter'] ?? '');
            $colIndex  = (int) ($col['col_index'] ?? 0);
            $normHeader = mb_strtolower(trim($hName));
            $mapping   = $activeMappings->get($normHeader);

            if ($mapping) {
                $direction = strtolower(trim((string) $mapping->direction));
                $mappingStatus = strtolower(trim((string) ($mapping->mapping_status ?? 'approved')));

                // Hanya mapping aktif/approved dengan direction inbound atau bidirectional yang diizinkan
                $isAllowedInbound = in_array($direction, ['inbound', 'bidirectional'], true)
                    && in_array($mappingStatus, ['approved', 'active', ''], true)
                    && !$mapping->is_formula;

                if (!$isAllowedInbound) {
                    $classification = SheetInboundChangeStaging::CLASSIFICATION_UNMAPPED;
                    $fieldKey = (string) $mapping->target_field;
                } else {
                    $fieldKey = (string) $mapping->target_field;
                    if ($mapping->target_entity === 'internship_registration') {
                        $classification = $this->classifyField($hName, $fieldKey);
                        if ($classification === SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER) {
                            $classification = SheetInboundChangeStaging::CLASSIFICATION_CORE_ADMINISTRATIVE;
                        }
                    } else {
                        $classification = SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER;
                    }
                }
                $columnIdentity = $mapping->column_identity ?: $hName;
            } else {
                $derivedKey = Str::slug($hName, '_');
                $classification = $this->classifyField($hName, $derivedKey);

                // Jika sudah ada mapping aktif terdaftar untuk tab ini, kolom tanpa mapping tidak boleh di-sync
                // kecuali kolom sistem terlindungi agar dapat dideteksi dan ditolak secara aman
                if ($activeMappings->isNotEmpty() && $classification !== SheetInboundChangeStaging::CLASSIFICATION_SYSTEM_PROTECTED) {
                    $classification = SheetInboundChangeStaging::CLASSIFICATION_UNMAPPED;
                }

                $fieldKey = $derivedKey;
                $columnIdentity = $hName;
            }

            $columnMap[$hName] = [
                'header_name'     => $hName,
                'col_letter'      => $colLetter,
                'col_index'       => $colIndex,
                'column_identity' => $columnIdentity,
                'field_key'       => mb_substr($fieldKey, 0, 100),
                'classification'  => $classification,
                'mapping'         => $mapping,
            ];
        }

        // 4. Bounded Pagination Scan (Baca Baris Remote Tanpa Masking PII untuk Validasi DB)
        $page = 1;
        $pageSize = 50;
        $scannedRowsCount = 0;
        $stagedChangesCount = 0;
        $conflictsCount = 0;
        $protectedRejectedCount = 0;
        $cleanInboundCount = 0;
        $echoSkippedCount = 0;

        $seenSyncIds = [];

        do {
            $rowsRes = $this->transport->readRowsPage(
                $connection,
                $tabName,
                $headerRow,
                $page,
                $pageSize,
                maskPii: false
            );

            if (!$rowsRes->success) {
                return [
                    'success'    => false,
                    'error_code' => $rowsRes->errorCode ?: 'READ_ROWS_FAILED',
                    'message'    => 'Gagal membaca baris remote pada halaman ' . $page . ': ' . $rowsRes->errorMessage,
                ];
            }

            $rows = $rowsRes->context['rows'] ?? [];
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $scannedRowsCount++;
                $rowNumber = (int) ($row['_row_number'] ?? ($dataStartRow + $scannedRowsCount - 1));

                // A. Identifikasi Baris Stabil: SERTIF_SYNC_ID atau SERTIF_REGISTRATION_ID
                $syncId = $this->extractSyncIdFromRow($row);
                $registrationId = $this->extractRegistrationIdFromRow($row);

                if (empty($syncId) && empty($registrationId)) {
                    // Baris tidak memiliki identitas stabil SERTIF, lewati
                    continue;
                }

                $effectiveSyncId = $syncId ?: "REG-ID-{$registrationId}";

                // B. Cek Duplicate Row ID dalam Sheet
                if (isset($seenSyncIds[$effectiveSyncId])) {
                    $conflictsCount++;
                    SheetInboundChangeStaging::updateOrCreate([
                        'spreadsheet_connection_id' => $connection->id,
                        'tab_name'                  => $tabName,
                        'sync_id'                   => $effectiveSyncId,
                        'column_identity'           => 'ROW_DUPLICATE',
                    ], [
                        'public_uuid'               => (string) Str::uuid(),
                        'sheet_id'                  => $effectiveSheetId,
                        'row_index'                 => $rowNumber,
                        'registration_id'           => $registrationId,
                        'header_name'               => 'DUPLICATE_ROW_CHECK',
                        'field_key'                 => 'duplicate_row',
                        'field_classification'      => SheetInboundChangeStaging::CLASSIFICATION_SYSTEM_PROTECTED,
                        'conflict_status'           => SheetInboundChangeStaging::CONFLICT_DUPLICATE_ROW_ID,
                        'conflict_reason'           => "Ditemukan duplikasi baris dengan ID sinkronisasi '{$effectiveSyncId}' pada baris {$rowNumber} (pertama terlihat pada baris {$seenSyncIds[$effectiveSyncId]}).",
                        'staging_status'            => SheetInboundChangeStaging::STATUS_REJECTED,
                        'resolution_policy'         => SheetInboundChangeStaging::POLICY_KEEP_LOCAL,
                    ]);
                    continue;
                }
                $seenSyncIds[$effectiveSyncId] = $rowNumber;

                // C. Resolve Model Registrasi Database Lokal
                $registration = $this->resolveLocalRegistration($effectiveSyncId, $registrationId);
                if (!$registration) {
                    // Pendaftaran tidak ditemukan di database lokal
                    continue;
                }

                // D. Pencegahan Echo Loop: Cek apakah baris ini identik dengan Outbound Payload terakhir
                $rowChecksum = $this->computeRowChecksum($row, $columnMap);
                $priorRowSync = SpreadsheetRowSync::where('external_sync_id', $effectiveSyncId)
                    ->orWhere(function ($q) use ($registration) {
                        $q->where('syncable_id', $registration->id)
                          ->where('syncable_type', InternshipRegistration::class);
                    })
                    ->first();

                if ($priorRowSync && $priorRowSync->payload_checksum && $priorRowSync->payload_checksum === $rowChecksum) {
                    // Echo dari write SERTIF sendiri -> lewati tanpa memicu staging
                    $echoSkippedCount++;
                    continue;
                }

                // E. Periksa Perubahan per Kolom
                foreach ($columnMap as $headerName => $colMeta) {
                    $classification = $colMeta['classification'];
                    $fieldKey       = $colMeta['field_key'];

                    // Kolom unmapped atau external_only tidak disinkronkan ke DB
                    if ($classification === SheetInboundChangeStaging::CLASSIFICATION_EXTERNAL_ONLY ||
                        $classification === SheetInboundChangeStaging::CLASSIFICATION_UNMAPPED) {
                        continue;
                    }

                    // Kolom identitas baris stabil (SERTIF_SYNC_ID & SERTIF_REGISTRATION_ID)
                    // merupakan primary lookup key baris, bukan field data yang distage
                    if ($fieldKey === 'sertif_sync_id' || $fieldKey === 'sertif_registration_id' ||
                        $headerName === 'SERTIF_SYNC_ID' || $headerName === 'SERTIF_REGISTRATION_ID') {
                        continue;
                    }

                    $rawRemoteValue = isset($row[$headerName]) ? (string) $row[$headerName] : null;
                    $normalizedRemoteValue = $this->normalizeCellValue($rawRemoteValue);
                    $isFormula = $this->isFormulaValue($rawRemoteValue);

                    // Ambil nilai saat ini di database
                    $currentDbValue = $this->getCurrentDatabaseValue($registration, $classification, $headerName, $fieldKey, $colMeta['mapping'] ?? null);
                    $normalizedCurrentDbValue = $this->normalizeCellValue($currentDbValue);

                    // Jika nilai sama, tidak ada perubahan
                    if ($this->valuesAreEqual($normalizedRemoteValue, $normalizedCurrentDbValue)) {
                        continue;
                    }

                    // Terdapat perbedaan nilai -> evaluasi status konflik
                    $stagedChangesCount++;
                    $conflictStatus = SheetInboundChangeStaging::CONFLICT_CLEAN_INBOUND;
                    $conflictReason = null;
                    $stagingStatus  = SheetInboundChangeStaging::STATUS_STAGED;
                    $resolutionPolicy = SheetInboundChangeStaging::POLICY_PENDING;

                    if ($classification === SheetInboundChangeStaging::CLASSIFICATION_SYSTEM_PROTECTED) {
                        // Tolak keras perubahan kolom sistem
                        $protectedRejectedCount++;
                        $conflictsCount++;
                        $conflictStatus = SheetInboundChangeStaging::CONFLICT_REJECTED_PROTECTED;
                        $conflictReason = "Kolom '{$headerName}' merupakan field sistem/administratif terlindungi dan dilarang diubah melalui Google Spreadsheet.";
                        $stagingStatus = SheetInboundChangeStaging::STATUS_REJECTED;
                        $resolutionPolicy = SheetInboundChangeStaging::POLICY_KEEP_LOCAL;
                    } elseif ($classification === SheetInboundChangeStaging::CLASSIFICATION_CORE_ADMINISTRATIVE) {
                        // Data inti memerlukan persetujuan eksplisit (mask PII jika phone/email)
                        $conflictsCount++;
                        $conflictStatus = SheetInboundChangeStaging::CONFLICT_CORE_REQUIRES_APPROVAL;
                        $maskedCurrent = $this->maskPiiValue($headerName, (string)$normalizedCurrentDbValue);
                        $maskedRemote  = $this->maskPiiValue($headerName, (string)$normalizedRemoteValue);
                        $conflictReason = "Perubahan data inti '{$headerName}' dari '{$maskedCurrent}' ke '{$maskedRemote}' memerlukan review dan persetujuan Admin.";
                    } else {
                        // Custom answer: Cek concurrent divergence
                        if ($priorRowSync && $priorRowSync->last_synced_at && $registration->updated_at &&
                            $registration->updated_at->gt($priorRowSync->last_synced_at)) {
                            $conflictsCount++;
                            $conflictStatus = SheetInboundChangeStaging::CONFLICT_DIVERGENT;
                            $conflictReason = "Data lokal database telah diperbarui sejak sinkronisasi terakhir ({$registration->updated_at->toIso8601String()}). Diperlukan resolusi konflik Admin.";
                        } else {
                            $cleanInboundCount++;
                        }
                    }

                    // Simpan / perbarui ke sheet_inbound_change_staging tanpa menimpa baseline atau data yang sudah di-review
                    $existingStaging = SheetInboundChangeStaging::where('spreadsheet_connection_id', $connection->id)
                        ->where('tab_name', $tabName)
                        ->where('sync_id', $effectiveSyncId)
                        ->where('column_identity', $colMeta['column_identity'])
                        ->first();

                    if ($existingStaging) {
                        // Jangan overwrite data staging yang sudah pernah di-apply, rejected, atau ignored
                        if (in_array($existingStaging->staging_status, [
                            SheetInboundChangeStaging::STATUS_APPLIED,
                            SheetInboundChangeStaging::STATUS_REJECTED,
                            SheetInboundChangeStaging::STATUS_IGNORED,
                        ], true)) {
                            continue;
                        }

                        // Jaga baseline current_db_value selama siklus approval; hanya update remote jika nilai remote berubah
                        if (!$this->valuesAreEqual($existingStaging->normalized_remote_value, $normalizedRemoteValue)) {
                            $existingStaging->update([
                                'raw_remote_value'        => $rawRemoteValue,
                                'normalized_remote_value' => $normalizedRemoteValue,
                                'new_checksum'            => hash('sha256', (string) $normalizedRemoteValue),
                                'conflict_status'         => $conflictStatus,
                                'conflict_reason'         => $conflictReason,
                                'is_formula'              => $isFormula,
                                'source_revision'         => $currentSchemaHash ?: null,
                            ]);
                        }
                    } else {
                        SheetInboundChangeStaging::create([
                            'public_uuid'               => (string) Str::uuid(),
                            'spreadsheet_connection_id' => $connection->id,
                            'tab_name'                  => $tabName,
                            'sheet_id'                  => $effectiveSheetId,
                            'row_index'                 => $rowNumber,
                            'sync_id'                   => $effectiveSyncId,
                            'registration_id'           => $registration->id,
                            'column_identity'           => $colMeta['column_identity'],
                            'header_name'               => mb_substr($headerName, 0, 255),
                            'field_key'                 => mb_substr($fieldKey, 0, 100),
                            'field_classification'      => $classification,
                            'raw_remote_value'          => $rawRemoteValue,
                            'normalized_remote_value'   => $normalizedRemoteValue,
                            'current_db_value'          => $normalizedCurrentDbValue,
                            'old_checksum'              => hash('sha256', (string) $normalizedCurrentDbValue),
                            'new_checksum'              => hash('sha256', (string) $normalizedRemoteValue),
                            'conflict_status'           => $conflictStatus,
                            'conflict_reason'           => $conflictReason,
                            'staging_status'            => $stagingStatus,
                            'resolution_policy'         => $resolutionPolicy,
                            'is_formula'                => $isFormula,
                            'source_revision'           => $currentSchemaHash ?: null,
                        ]);
                    }
                }
            }

            $hasMore = !empty($rowsRes->context['has_more']);
            $page++;
        } while ($hasMore && $page <= 100);

        return [
            'success'                  => true,
            'tab_name'                 => $tabName,
            'schema_hash'              => $currentSchemaHash,
            'scanned_rows_count'       => $scannedRowsCount,
            'staged_changes_count'     => $stagedChangesCount,
            'clean_inbound_count'      => $cleanInboundCount,
            'conflicts_count'          => $conflictsCount,
            'protected_rejected_count' => $protectedRejectedCount,
            'echo_skipped_count'       => $echoSkippedCount,
        ];
    }

    /**
     * Terapkan perubahan inbound yang telah distaging ke database lokal via domain service.
     * Menggunakan transaksi atomik, row locking (anti-race/anti-stale), dan audit outbox terlindungi PII.
     *
     * @param SheetInboundChangeStaging $staging
     * @param User $actor
     * @return array
     */
    public function applyStagedChange(SheetInboundChangeStaging $staging, User $actor): array
    {
        // RBAC Check: Hanya Admin atau HR Staff yang berwenang
        if (!($actor->isAdmin() ?? false) && !($actor->isHrStaff() ?? false) && ($actor->role ?? '') !== 'admin') {
            return [
                'success'    => false,
                'error_code' => 'UNAUTHORIZED',
                'message'    => 'Hanya Administrator atau HR Staff yang memiliki wewenang untuk menyetujui perubahan inbound.',
            ];
        }

        if ($staging->isProtected()) {
            return [
                'success'    => false,
                'error_code' => 'CANNOT_APPLY_PROTECTED_FIELD',
                'message'    => "Field '{$staging->header_name}' dilindungi secara mutlak dan tidak boleh diterapkan ke database.",
            ];
        }

        return DB::transaction(function () use ($staging, $actor) {
            // 1. Lock staging record untuk mencegah concurrent double-apply
            $lockedStaging = SheetInboundChangeStaging::where('id', $staging->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedStaging) {
                return [
                    'success'    => false,
                    'error_code' => 'STAGING_NOT_FOUND',
                    'message'    => 'Data staging tidak ditemukan.',
                ];
            }

            if ($lockedStaging->staging_status === SheetInboundChangeStaging::STATUS_APPLIED) {
                return [
                    'success'    => false,
                    'error_code' => 'ALREADY_APPLIED',
                    'message'    => 'Perubahan staging ini sudah pernah diterapkan sebelumnya.',
                ];
            }

            if (in_array($lockedStaging->staging_status, [
                SheetInboundChangeStaging::STATUS_REJECTED,
                SheetInboundChangeStaging::STATUS_IGNORED,
            ], true)) {
                return [
                    'success'    => false,
                    'error_code' => 'INVALID_STAGING_STATUS',
                    'message'    => "Perubahan staging berstatus '{$lockedStaging->staging_status}' dan tidak dapat diterapkan.",
                ];
            }

            // 2. Lock model pendaftaran
            $registration = InternshipRegistration::where('id', $lockedStaging->registration_id)
                ->lockForUpdate()
                ->first();

            if (!$registration) {
                return [
                    'success'    => false,
                    'error_code' => 'REGISTRATION_NOT_FOUND',
                    'message'    => "Data pendaftaran magang ID '{$lockedStaging->registration_id}' tidak ditemukan.",
                ];
            }

            // 3. Anti-Stale Verification: Re-read nilai aktual database lokal dan bandingkan dengan baseline saat scan
            $freshDbValue = $this->getCurrentDatabaseValue(
                $registration,
                $lockedStaging->field_classification,
                $lockedStaging->header_name,
                $lockedStaging->field_key
            );
            $normalizedFreshDbValue = $this->normalizeCellValue($freshDbValue);

            if (!$this->valuesAreEqual($normalizedFreshDbValue, $lockedStaging->current_db_value)) {
                // Terdeteksi pembaruan lokal konkuren sejak scan. Batalkan apply dan tandai konflik.
                $lockedStaging->update([
                    'conflict_status'   => SheetInboundChangeStaging::CONFLICT_DIVERGENT,
                    'conflict_reason'   => 'Data lokal database telah diperbarui sejak pemindaian (stale approval terdeteksi). Penerapan dibatalkan demi keamanan data.',
                    'staging_status'    => SheetInboundChangeStaging::STATUS_STAGED,
                    'resolution_policy' => SheetInboundChangeStaging::POLICY_PENDING,
                ]);

                return [
                    'success'    => false,
                    'error_code' => 'STALE_DATA_CONFLICT',
                    'message'    => 'Data database lokal telah berubah sejak pemindaian staging. Perubahan dibatalkan demi mencegah penimpaan data terbaru.',
                ];
            }

            // 4. Bersihkan nilai remote dari formula injection prefix
            $val = $lockedStaging->normalized_remote_value;
            if (is_string($val) && in_array($val[0] ?? '', ['=', '+', '-', '@'], true)) {
                $val = ltrim($val, "=+-@'");
            }

            // 5. Terapkan pembaruan sesuai klasifikasi field
            if ($lockedStaging->field_classification === SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER) {
                // Terapkan ke RegistrationCustomAnswer
                RegistrationCustomAnswer::updateOrCreate([
                    'registration_id' => $registration->id,
                    'field_key'       => $lockedStaging->field_key,
                ], [
                    'answer_value'    => $val,
                ]);
            } elseif ($lockedStaging->field_classification === SheetInboundChangeStaging::CLASSIFICATION_CORE_ADMINISTRATIVE) {
                // Terapkan ke atribut core InternshipRegistration melalui whitelist eksplisit
                $applied = $this->applyCoreFieldUpdate($registration, $lockedStaging->field_key, $val);
                if (!$applied) {
                    return [
                        'success'    => false,
                        'error_code' => 'CORE_FIELD_UPDATE_FAILED',
                        'message'    => "Field '{$lockedStaging->field_key}' tidak diizinkan atau gagal divalidasi.",
                    ];
                }
            } else {
                return [
                    'success'    => false,
                    'error_code' => 'UNALLOWED_FIELD_CLASSIFICATION',
                    'message'    => "Klasifikasi field '{$lockedStaging->field_classification}' tidak diizinkan untuk diterapkan ke database.",
                ];
            }

            // 6. Mutasikan status staging menjadi applied
            $lockedStaging->update([
                'staging_status'    => SheetInboundChangeStaging::STATUS_APPLIED,
                'resolution_policy' => SheetInboundChangeStaging::POLICY_ACCEPT_REMOTE,
                'conflict_status'   => SheetInboundChangeStaging::CONFLICT_NONE,
                'reviewed_by'       => $actor->id,
                'reviewed_at'       => now(),
                'applied_at'        => now(),
            ]);

            // 7. Audit outbox event dengan masking PII
            IntegrationOutboxEvent::create([
                'idempotency_key'      => (string) Str::uuid(),
                'event_type'           => 'inbound_change_applied',
                'target_connection_id' => $lockedStaging->spreadsheet_connection_id,
                'aggregate_type'       => 'internship_registration',
                'aggregate_id'         => (string) $registration->id,
                'status'               => IntegrationOutboxEvent::STATUS_VERIFIED,
                'payload'              => [
                    'staging_id'      => $lockedStaging->id,
                    'header_name'     => $lockedStaging->header_name,
                    'field_key'       => $lockedStaging->field_key,
                    'old_value'       => $this->maskPiiValue($lockedStaging->header_name, (string) $lockedStaging->current_db_value),
                    'new_value'       => $this->maskPiiValue($lockedStaging->header_name, (string) $val),
                    'actor_id'        => $actor->id,
                    'actor_email'     => $this->maskEmail($actor->email),
                    'applied_at'      => now()->toIso8601String(),
                ],
            ]);

            return [
                'success'        => true,
                'message'        => "Perubahan kolom '{$lockedStaging->header_name}' berhasil diterapkan ke database.",
                'staging_id'     => $lockedStaging->id,
                'applied_value'  => $val,
                'actor'          => $actor->name,
            ];
        });
    }

    /**
     * Tolak perubahan inbound, mempertahankan database lokal sebagai single source of truth.
     *
     * @param SheetInboundChangeStaging $staging
     * @param User $actor
     * @param string|null $reason
     * @return array
     */
    public function rejectStagedChange(SheetInboundChangeStaging $staging, User $actor, ?string $reason = null): array
    {
        // RBAC Check
        if (!($actor->isAdmin() ?? false) && !($actor->isHrStaff() ?? false) && ($actor->role ?? '') !== 'admin') {
            return [
                'success'    => false,
                'error_code' => 'UNAUTHORIZED',
                'message'    => 'Hanya Administrator atau HR Staff yang memiliki wewenang untuk menolak perubahan inbound.',
            ];
        }

        if ($staging->staging_status === SheetInboundChangeStaging::STATUS_APPLIED) {
            return [
                'success'    => false,
                'error_code' => 'ALREADY_APPLIED',
                'message'    => 'Perubahan staging ini sudah pernah diterapkan dan tidak dapat ditolak.',
            ];
        }

        $staging->update([
            'staging_status'    => SheetInboundChangeStaging::STATUS_REJECTED,
            'resolution_policy' => SheetInboundChangeStaging::POLICY_KEEP_LOCAL,
            'conflict_reason'   => $reason ?? 'Ditolak oleh Admin; database lokal dipertahankan.',
            'reviewed_by'       => $actor->id,
            'reviewed_at'       => now(),
        ]);

        return [
            'success'    => true,
            'message'    => "Perubahan inbound untuk '{$staging->header_name}' telah ditolak. Data lokal dipertahankan.",
            'staging_id' => $staging->id,
            'actor'      => $actor->name,
        ];
    }

    /**
     * Klasifikasikan jenis field berdasarkan header dan key.
     */
    public function classifyField(string $headerName, ?string $fieldKey = null): string
    {
        $hClean = strtolower(trim($headerName));
        $kClean = strtolower(trim((string) $fieldKey));

        // 1. System Protected Fields
        $systemProtectedKeys = [
            'sertif_sync_id', 'sertif_registration_id', 'id', 'user_id',
            'internship_status', 'status', 'role', 'is_banned', 'student_id',
            'password', 'remember_token', 'created_at', 'updated_at', 'submitted_at',
            'deleted_at', 'email_verified_at', 'timestamp', 'waktu'
        ];

        if (in_array($hClean, $systemProtectedKeys, true) || in_array($kClean, $systemProtectedKeys, true)) {
            return SheetInboundChangeStaging::CLASSIFICATION_SYSTEM_PROTECTED;
        }

        if (str_starts_with($hClean, 'sertif_') || str_starts_with($hClean, 'status_') || str_contains($hClean, 'nomor_id')) {
            return SheetInboundChangeStaging::CLASSIFICATION_SYSTEM_PROTECTED;
        }

        // 2. Core Administrative Fields
        $coreAdminKeys = [
            'nama_lengkap', 'fullname', 'nama', 'nomor_telepon', 'phone_number',
            'telepon', 'no_hp', 'no_whatsapp', 'tanggal_lahir', 'born_date',
            'jenis_kelamin', 'gender', 'asal_sekolah_kampus', 'institusi', 'institution_name',
            'divisi', 'division', 'pilihan_divisi', 'internship_interest', 'supervisor_info'
        ];

        if (in_array($hClean, $coreAdminKeys, true) || in_array($kClean, $coreAdminKeys, true)) {
            return SheetInboundChangeStaging::CLASSIFICATION_CORE_ADMINISTRATIVE;
        }

        // 3. Custom Answer Fields (Jika ada di FormFieldRegistry, FormField, atau RegistrationCustomAnswer)
        $hasCustomField = FormFieldRegistry::where('field_key', $kClean)
            ->orWhere('canonical_label', $headerName)
            ->exists();

        if ($hasCustomField) {
            return SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER;
        }

        $hasFormField = FormField::where('field_key', $kClean)
            ->orWhere('label', $headerName)
            ->exists();

        if ($hasFormField) {
            return SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER;
        }

        if (RegistrationCustomAnswer::where('field_key', $kClean)->exists()) {
            return SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER;
        }

        if (str_starts_with($kClean, 'pilihan_') || str_starts_with($kClean, 'custom_') || str_starts_with($kClean, 'catatan_')) {
            return SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER;
        }

        // Default: UNMAPPED (bukan custom answer)
        return SheetInboundChangeStaging::CLASSIFICATION_UNMAPPED;
    }

    /**
     * Tentukan apakah suatu field diizinkan untuk disinkronkan dari Google Spreadsheet (C08).
     * Protected, status, ID, derived, dan unmapped field ditolak secara fail-closed.
     */
    public function isFieldInboundAllowed(string $headerName, ?string $fieldKey = null): bool
    {
        $classification = $this->classifyField($headerName, $fieldKey);

        return in_array($classification, [
            SheetInboundChangeStaging::CLASSIFICATION_CORE_ADMINISTRATIVE,
            SheetInboundChangeStaging::CLASSIFICATION_CUSTOM_ANSWER,
        ], true);
    }

    /**
     * Normalisasi nilai sel Google Spreadsheet secara konsisten.
     * Mengubah whitespace, tanda strip '-', atau string kosong menjadi null/clean.
     */
    public function normalizeCellValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '-' || $trimmed === '--' || strtolower($trimmed) === 'null' || strtolower($trimmed) === 'n/a') {
            return null;
        }

        return $trimmed;
    }

    /**
     * Deteksi apakah nilai sel merupakan formula Spreadsheet.
     */
    public function isFormulaValue(?string $value): bool
    {
        if (empty($value) || !is_string($value)) {
            return false;
        }

        $firstChar = trim($value)[0] ?? '';
        return in_array($firstChar, ['=', '+', '@'], true);
    }

    /**
     * Ambil nilai pendaftaran database lokal saat ini.
     */
    protected function getCurrentDatabaseValue(
        InternshipRegistration $registration,
        string $classification,
        string $headerName,
        string $fieldKey,
        ?SpreadsheetColumnMapping $mapping = null
    ): ?string {
        if ($mapping) {
            try {
                $mappingService = app(\App\Services\RegistrationSpreadsheetMappingService::class);
                $resolved = $mappingService->resolveColumnValue($registration, $mapping);
                if ($resolved !== null && $resolved !== '-') {
                    return (string) $resolved;
                }
            } catch (\Throwable) {
                // fallback below
            }
        }

        if ($classification === SheetInboundChangeStaging::CLASSIFICATION_SYSTEM_PROTECTED) {
            if (str_contains($fieldKey, 'status')) {
                return (string) $registration->internship_status;
            }
            if (str_contains($fieldKey, 'sync_id')) {
                return (string) ($registration->rowSync?->external_sync_id ?? '');
            }
            if (str_contains($fieldKey, 'registration_id') || $fieldKey === 'id') {
                return (string) $registration->id;
            }
            return (string) ($registration->{$fieldKey} ?? '');
        }

        if ($classification === SheetInboundChangeStaging::CLASSIFICATION_CORE_ADMINISTRATIVE) {
            if (in_array($fieldKey, ['fullname', 'nama_lengkap', 'nama'], true)) {
                return (string) $registration->fullname;
            }
            if (in_array($fieldKey, ['phone_number', 'nomor_telepon', 'telepon', 'no_hp'], true)) {
                return (string) $registration->phone_number;
            }
            if (in_array($fieldKey, ['born_date', 'tanggal_lahir'], true)) {
                return $registration->born_date instanceof \DateTimeInterface
                    ? $registration->born_date->format('Y-m-d')
                    : (string) $registration->born_date;
            }
            if (in_array($fieldKey, ['gender', 'jenis_kelamin'], true)) {
                return (string) $registration->gender;
            }
            if (in_array($fieldKey, ['divisi', 'division', 'pilihan_divisi'], true)) {
                return (string) ($registration->division?->name ?? $registration->internship_interest);
            }
            if ($fieldKey === 'supervisor_info') {
                $name = trim((string) $registration->supervisor_name);
                $contact = trim((string) $registration->supervisor_contact);
                if (!empty($name) && !empty($contact)) {
                    return "{$name} ({$contact})";
                }
                return $name ?: ($contact ?: null);
            }
            if (isset($registration->{$fieldKey})) {
                return (string) $registration->{$fieldKey};
            }
        }

        // Custom Answer lookup
        $ans = RegistrationCustomAnswer::where('registration_id', $registration->id)
            ->where(function ($q) use ($fieldKey, $headerName) {
                $q->where('field_key', $fieldKey)
                  ->orWhere('field_key', $headerName);
            })
            ->first();

        return $ans ? (string) $ans->answer_value : null;
    }

    /**
     * Terapkan update atribut data inti pada InternshipRegistration via explicit allowlist (C08).
     * Mencegah mass-assignment / update liar pada atribut administratif, status, atau akun.
     */
    protected function applyCoreFieldUpdate(InternshipRegistration $registration, string $fieldKey, ?string $value): bool
    {
        $key = strtolower(trim($fieldKey));

        if (in_array($key, ['fullname', 'nama_lengkap', 'nama'], true)) {
            if (empty(trim((string) $value))) {
                return false;
            }
            $registration->fullname = (string) $value;
        } elseif (in_array($key, ['phone_number', 'nomor_telepon', 'telepon', 'no_hp', 'no_whatsapp'], true)) {
            $cleanPhone = preg_replace('/[^\d+]/', '', (string) $value);
            if (strlen($cleanPhone) < 8 || strlen($cleanPhone) > 20) {
                return false;
            }
            $registration->phone_number = $cleanPhone;
        } elseif (in_array($key, ['born_date', 'tanggal_lahir'], true)) {
            try {
                $parsed = Carbon::parse($value);
                $registration->born_date = $parsed->format('Y-m-d');
            } catch (\Throwable) {
                return false;
            }
        } elseif (in_array($key, ['gender', 'jenis_kelamin'], true)) {
            $valLower = strtolower(trim((string) $value));
            if (in_array($valLower, ['l', 'laki-laki', 'male', 'pria'], true)) {
                $registration->gender = 'male';
            } elseif (in_array($valLower, ['p', 'perempuan', 'female', 'wanita'], true)) {
                $registration->gender = 'female';
            } else {
                $registration->gender = $valLower;
            }
        } elseif ($key === 'supervisor_info' && $value) {
            if (preg_match('/^(.*?)\s*\((.*?)\)$/', trim($value), $matches)) {
                $registration->supervisor_name = trim($matches[1]);
                $registration->supervisor_contact = trim($matches[2]);
            } else {
                $registration->supervisor_name = trim($value);
            }
        } elseif (in_array($key, ['internship_interest', 'pilihan_divisi', 'divisi', 'division'], true)) {
            $registration->internship_interest = (string) $value;
        } elseif (in_array($key, ['asal_sekolah_kampus', 'institusi', 'institution_name'], true)) {
            if (isset($registration->institution_name)) {
                $registration->institution_name = (string) $value;
            }
        } else {
            // Field administratif / fillable lainnya ditolak keras dari sinkronisasi inbound otomatis
            return false;
        }

        $registration->save();
        return true;
    }

    /**
     * Mask nilai PII (Personal Identifiable Information) agar log/audit/alasan konflik tidak bocor.
     */
    public function maskPiiValue(string $headerName, string $val): string
    {
        if (empty($val)) {
            return $val;
        }

        $h = strtolower($headerName);
        if (str_contains($h, 'telepon') || str_contains($h, 'phone') || str_contains($h, 'hp') || str_contains($h, 'wa')) {
            $digitsOnly = preg_replace('/[^\d+]/', '', $val);
            if (strlen($digitsOnly) > 4) {
                return substr($digitsOnly, 0, 3) . '***' . substr($digitsOnly, -2);
            }
            return '***';
        }

        if (str_contains($h, 'email') || str_contains($val, '@')) {
            return $this->maskEmail($val);
        }

        return $val;
    }

    /**
     * Mask alamat email untuk log dan outbox.
     */
    public function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) === 2) {
            $name = $parts[0];
            $domain = $parts[1];
            $maskedName = strlen($name) > 2 ? substr($name, 0, 2) . '***' : substr($name, 0, 1) . '***';
            return $maskedName . '@' . $domain;
        }
        return '***@***';
    }

    protected function valuesAreEqual(?string $a, ?string $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        return trim($a) === trim($b);
    }

    protected function extractSyncIdFromRow(array $row): ?string
    {
        foreach (['SERTIF_SYNC_ID', 'Sync ID', 'sync_id', 'External Sync ID'] as $k) {
            if (!empty($row[$k])) {
                return trim((string) $row[$k]);
            }
        }
        return null;
    }

    protected function extractRegistrationIdFromRow(array $row): ?int
    {
        foreach (['SERTIF_REGISTRATION_ID', 'Registration ID', 'registration_id', 'ID'] as $k) {
            if (!empty($row[$k]) && is_numeric($row[$k])) {
                return (int) $row[$k];
            }
        }
        return null;
    }

    protected function resolveLocalRegistration(string $syncId, ?int $registrationId): ?InternshipRegistration
    {
        if ($registrationId) {
            $reg = InternshipRegistration::find($registrationId);
            if ($reg) {
                return $reg;
            }
        }

        $rowSync = SpreadsheetRowSync::where('external_sync_id', $syncId)->first();
        if ($rowSync && $rowSync->syncable instanceof InternshipRegistration) {
            return $rowSync->syncable;
        }

        return null;
    }

    protected function computeRowChecksum(array $row, array $columnMap): string
    {
        $payload = [];
        foreach ($columnMap as $headerName => $meta) {
            $payload[$headerName] = isset($row[$headerName]) ? (string) $row[$headerName] : '';
        }
        return hash('sha256', json_encode($payload));
    }

    /**
     * Deteksi apakah baris ini adalah gema (echo) dari sinkronisasi outbound terakhir (C30).
     */
    public function detectEchoLoop(string $externalSyncId, string $incomingChecksum): bool
    {
        $existing = SpreadsheetRowSync::where('external_sync_id', $externalSyncId)->first();
        if (!$existing || empty($existing->payload_checksum)) {
            return false;
        }
        return hash_equals($existing->payload_checksum, $incomingChecksum);
    }

    /**
     * Cegah formula injection pada nilai yang dikirim ke Spreadsheet (CWE-1236, C30).
     */
    public function escapeFormulaInjection(string $value): string
    {
        if (preg_match('/^[=\+\-@]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}
