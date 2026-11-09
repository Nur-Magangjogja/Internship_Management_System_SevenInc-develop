<?php

namespace App\Services;

use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetTabProfile;
use App\Models\FormFieldRegistry;
use App\Models\RegistrationCustomAnswer;
use App\Models\User;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ActiveSheetManualRowReconciliationService
{
    public function __construct(
        protected SpreadsheetTransportInterface $transport,
        protected RegistrationSpreadsheetMappingService $mappingService
    ) {}

    /**
     * Memindai baris-baris pada tab aktif yang belum memiliki SERTIF_SYNC_ID.
     *
     * @return array{total_scanned: int, unsynced_rows: array<int, array<string, mixed>>}
     */
    public function scanUnsyncedRows(SpreadsheetConnection $connection, string $tabName, int $page = 1, int $pageSize = 50): array
    {
        $profile = SpreadsheetTabProfile::where('spreadsheet_connection_id', $connection->id)
            ->where('tab_name', $tabName)
            ->first();
        $headerRow = $profile?->header_row ?? 10;

        $response = $this->transport->readRowsPage(
            connection: $connection,
            tabName: $tabName,
            headerRow: $headerRow,
            page: $page,
            pageSize: $pageSize,
            maskPii: false
        );

        if (!$response->success) {
            throw new DomainException("Gagal memindai baris spreadsheet: " . $response->errorMessage);
        }

        $rows = $response->context['rows'] ?? [];
        $unsyncedRows = [];

        foreach ($rows as $row) {
            $rowIndex = $row['_row_number'] ?? $row['row_index'] ?? null;
            $data = $row;
            if (isset($row['data']) && is_array($row['data'])) {
                $data = $row['data'];
            }

            // Periksa apakah kolom SERTIF_SYNC_ID kosong atau '-'
            $syncId = $data['SERTIF_SYNC_ID'] ?? $data['sertif_sync_id'] ?? null;
            $isUnsynced = empty($syncId) || trim((string) $syncId) === '' || trim((string) $syncId) === '-';

            if ($isUnsynced && $rowIndex !== null) {
                $checksum = $this->computeRowContentChecksum($data);
                $unsyncedRows[] = [
                    'row_index'        => (int) $rowIndex,
                    'data'             => $data,
                    'content_checksum' => $checksum,
                    'matched_local_id' => $this->findMatchingLocalRegistrationId($connection, $data),
                ];
            }
        }

        return [
            'total_scanned' => count($rows),
            'unsynced_rows' => $unsyncedRows,
        ];
    }

    /**
     * Memasangkan SERTIF_SYNC_ID secara atomik pada baris spreadsheet tanpa append/insert baris baru.
     * Dilengkapi Pre-Write Fingerprint Guard dan Field Ownership Policy.
     */
    public function reconcileRowSyncId(
        SpreadsheetConnection $connection,
        string $tabName,
        int $rowIndex,
        ?string $existingSyncId = null,
        ?int $registrationId = null,
        ?string $expectedChecksum = null,
        array $rowData = [],
        ?string $targetStatus = null,
        array $ownershipPolicies = []
    ): array {
        // Pre-Write Fingerprint Guard: Periksa perubahan konkuren jika expectedChecksum diberikan
        if ($expectedChecksum !== null && !empty($rowData)) {
            $computed = $this->computeRowContentChecksum($rowData);
            if ($computed !== $expectedChecksum) {
                return [
                    'success'    => false,
                    'row_index'  => $rowIndex,
                    'error_code' => 'CONCURRENCY_CONFLICT',
                    'message'    => "Struktur atau isi data baris {$rowIndex} telah berubah secara konkuren di Google Sheets. Operasi dibatalkan demi keamanan data.",
                ];
            }
        }

        $syncId = $existingSyncId ?: (string) Str::uuid();
        $effectiveRegistrationId = $registrationId;
        $isNewlyIntegrated = false;

        // Source-row idempotency key to prevent duplicate account creation on retries
        $sourceRowKey = "row_sync_{$connection->id}_{$tabName}_{$rowIndex}_" . substr(md5(json_encode($rowData)), 0, 12);

        $sheetSyncId = $rowData['SERTIF_SYNC_ID'] ?? $rowData['sertif_sync_id'] ?? null;
        if (empty($existingSyncId) && !empty($sheetSyncId) && trim((string) $sheetSyncId) !== '' && trim((string) $sheetSyncId) !== '-') {
            $existingSyncId = trim((string) $sheetSyncId);
        }

        $sheetRegId = $rowData['SERTIF_REGISTRATION_ID'] ?? $rowData['sertif_registration_id'] ?? null;
        if (empty($registrationId) && !empty($sheetRegId) && is_numeric($sheetRegId)) {
            $registrationId = (int) $sheetRegId;
        }

        // Recovery Guard: Jika baris SUDAH ber-ID di sheet, periksa apakah datanya hilang di database lokal
        if ($existingSyncId) {
            $existingRowSync = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
                ->where('external_sync_id', $existingSyncId)
                ->first();
            $localRegExists = false;
            if ($existingRowSync && $existingRowSync->syncable_id) {
                $localRegExists = InternshipRegistration::where('id', $existingRowSync->syncable_id)->whereNull('deleted_at')->exists();
            } elseif ($registrationId) {
                $localRegExists = InternshipRegistration::where('id', $registrationId)->whereNull('deleted_at')->exists();
            }

            if (!$localRegExists) {
                return $this->recoverOrReingestExistingRow(
                    connection: $connection,
                    tabName: $tabName,
                    rowIndex: $rowIndex,
                    existingSyncId: $existingSyncId,
                    remoteRegistrationId: $registrationId,
                    rowData: $rowData,
                    targetStatus: $targetStatus,
                    expectedChecksum: $expectedChecksum,
                    ownershipPolicies: $ownershipPolicies
                );
            }
        }

        $existingRowSync = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
            ->where(function ($q) use ($sourceRowKey, $existingSyncId) {
                if ($existingSyncId) {
                    $q->where('external_sync_id', $existingSyncId);
                } else {
                    $q->where('locked_by', $sourceRowKey);
                }
            })
            ->first();

        if ($existingRowSync) {
            $syncId = $existingRowSync->external_sync_id;
            if ($existingRowSync->syncable_id && !$effectiveRegistrationId) {
                $effectiveRegistrationId = $existingRowSync->syncable_id;
            }
        }

        if (!$effectiveRegistrationId && !empty($rowData)) {
            $identityMatch = $this->resolveIdentityMatch($connection, $rowData);
            if ($identityMatch['matched_registration_id']) {
                $effectiveRegistrationId = $identityMatch['matched_registration_id'];
            } else {
                $integratedReg = $this->createIntegratedUserAndRegistration(
                    $connection,
                    $rowData,
                    $syncId,
                    $rowIndex,
                    $targetStatus
                );
                $effectiveRegistrationId = $integratedReg->id;
                $isNewlyIntegrated = true;

                // Keep is_integrated = false until remote write-back is confirmed
                $integratedReg->is_integrated = false;
                $integratedReg->save();
            }
        }

        // PRE-WRITE PERSISTENCE: Record SpreadsheetRowSync as PENDING before remote call
        if ($effectiveRegistrationId) {
            SpreadsheetRowSync::updateOrCreate(
                [
                    'spreadsheet_connection_id' => $connection->id,
                    'syncable_type'             => InternshipRegistration::class,
                    'syncable_id'               => $effectiveRegistrationId,
                ],
                [
                    'external_sync_id' => $syncId,
                    'target_sheet_tab' => $tabName,
                    'sync_channel'     => $isNewlyIntegrated ? 'integrated_external' : 'manual_reconciliation',
                    'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                    'row_index'        => $rowIndex,
                    'locked_by'        => $sourceRowKey,
                    'last_attempt_at'  => now(),
                ]
            );
        }

        // 1. Panggil attach_row_sync_id ke Apps Script
        $response = $this->transport->attachRowSyncId(
            connection: $connection,
            tabName: $tabName,
            rowIndex: $rowIndex,
            syncId: $syncId,
            registrationId: $effectiveRegistrationId ? (string) $effectiveRegistrationId : null,
            expectedChecksum: $expectedChecksum
        );

        if (!$response->success) {
            Log::warning("Reconciliation failed for row {$rowIndex} on tab {$tabName}: " . $response->errorMessage);
            if ($effectiveRegistrationId) {
                SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
                    ->where('syncable_id', $effectiveRegistrationId)
                    ->update([
                        'sync_status'   => SpreadsheetRowSync::STATUS_FAILED,
                        'error_message' => $response->errorMessage,
                    ]);
            }
            return [
                'success'    => false,
                'row_index'  => $rowIndex,
                'error_code' => $response->errorCode,
                'message'    => $response->errorMessage,
            ];
        }

        // 2. Catat atau perbarui SpreadsheetRowSync setelah terkonfirmasi sukses
        if ($effectiveRegistrationId) {
            SpreadsheetRowSync::updateOrCreate(
                [
                    'spreadsheet_connection_id' => $connection->id,
                    'syncable_type'             => InternshipRegistration::class,
                    'syncable_id'               => $effectiveRegistrationId,
                ],
                [
                    'external_sync_id' => $syncId,
                    'target_sheet_tab' => $tabName,
                    'sync_channel'     => $isNewlyIntegrated ? 'integrated_external' : 'manual_reconciliation',
                    'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
                    'last_synced_at'   => now(),
                    'error_message'    => null,
                ]
            );

            if ($effectiveRegistrationId) {
                $matchedReg = InternshipRegistration::find($effectiveRegistrationId);
                if ($matchedReg) {
                    $matchedReg->is_integrated = true;
                    if (empty($matchedReg->registration_provenance) || $matchedReg->registration_provenance === 'regular_web') {
                        $matchedReg->registration_provenance = 'reconciled_sheet';
                    }
                    $matchedReg->save();

                    if (!$isNewlyIntegrated && !empty($rowData)) {
                        $this->saveCustomAnswersFromRowData($matchedReg, $rowData);
                        if (!empty($ownershipPolicies)) {
                            $this->applyFieldOwnership($matchedReg, $rowData, $ownershipPolicies);
                        }
                    }
                }
            }
        }

        return [
            'success'         => true,
            'row_index'       => $rowIndex,
            'sync_id'         => $syncId,
            'registration_id' => $effectiveRegistrationId,
            'is_integrated'   => $isNewlyIntegrated,
            'message'         => $isNewlyIntegrated
                ? "Data baris {$rowIndex} berhasil diintegrasikan dan akun pemagang telah dibuat."
                : "SERTIF_SYNC_ID berhasil direkonsiliasi pada baris {$rowIndex}.",
        ];
    }

    /**
     * Ekstrak atribut calon pemagang dari baris spreadsheet dengan fallback universal.
     */
    public function extractCandidateAttributesFromRow(
        SpreadsheetConnection $connection,
        array $rowData,
        ?string $targetStatus = null
    ): array {
        $fullname = null;
        $email = null;
        $phone = null;
        $studentId = null;
        $institution = null;
        $studyProgram = null;
        $divisionId = null;
        $detectedStatus = null;

        foreach ($rowData as $k => $v) {
            if (empty($v) || is_array($v)) continue;
            $cleanK = strtolower(trim((string) $k));
            $cleanV = strtolower(trim((string) $v));
            if (str_starts_with($cleanK, '_') || str_starts_with($cleanK, 'sertif_')) continue;

            if ((str_contains($cleanK, 'nama lengkap') || $cleanK === 'nama' || $cleanK === 'fullname' || $cleanK === 'field_nama_lengkap') && !$fullname) {
                $fullname = trim((string) $v);
            } elseif ((str_contains($cleanK, 'email') || $cleanK === 'surel' || $cleanK === 'field_email') && !$email) {
                $email = strtolower(trim((string) $v));
            } elseif ((str_contains($cleanK, 'no. hp') || str_contains($cleanK, 'whatsapp') || $cleanK === 'no hp' || $cleanK === 'wa' || $cleanK === 'field_no_hp' || $cleanK === 'phone_number') && !$phone) {
                $phone = preg_replace('/\D/', '', (string) $v);
            } elseif ((str_contains($cleanK, 'nim') || str_contains($cleanK, 'nis') || $cleanK === 'field_nim' || $cleanK === 'student_id') && !$studentId) {
                $studentId = trim((string) $v);
            } elseif ((str_contains($cleanK, 'sekolah') || str_contains($cleanK, 'universitas') || str_contains($cleanK, 'instansi') || str_contains($cleanK, 'kampus') || $cleanK === 'field_asal_sekolah') && !$institution) {
                $institution = trim((string) $v);
            } elseif ((str_contains($cleanK, 'jurusan') || str_contains($cleanK, 'prodi') || str_contains($cleanK, 'program studi') || $cleanK === 'field_jurusan') && !$studyProgram) {
                $studyProgram = trim((string) $v);
            } elseif ((str_contains($cleanK, 'status') || str_contains($cleanK, 'ket')) && !$detectedStatus) {
                if (str_contains($cleanV, 'aktif') || str_contains($cleanV, 'masuk')) {
                    $detectedStatus = InternshipRegistration::STATUS_ACTIVE;
                } elseif (str_contains($cleanV, 'terima') || str_contains($cleanV, 'acc') || str_contains($cleanV, 'lolos')) {
                    $detectedStatus = InternshipRegistration::STATUS_ACCEPTED;
                } elseif (str_contains($cleanV, 'selesai') || str_contains($cleanV, 'lulus') || str_contains($cleanV, 'alumni')) {
                    $detectedStatus = InternshipRegistration::STATUS_COMPLETED;
                } elseif (str_contains($cleanV, 'tolak')) {
                    $detectedStatus = InternshipRegistration::STATUS_REJECTED;
                } elseif (str_contains($cleanV, 'keluar')) {
                    $detectedStatus = InternshipRegistration::STATUS_EXITED;
                } elseif (str_contains($cleanV, 'pending') || str_contains($cleanV, 'tunda')) {
                    $detectedStatus = InternshipRegistration::STATUS_PENDING;
                } elseif (str_contains($cleanV, 'menunggu') || str_contains($cleanV, 'calon')) {
                    $detectedStatus = InternshipRegistration::STATUS_WAITING;
                }
            }
        }

        $validStatuses = [
            InternshipRegistration::STATUS_WAITING,
            InternshipRegistration::STATUS_PENDING,
            InternshipRegistration::STATUS_ACCEPTED,
            InternshipRegistration::STATUS_ACTIVE,
            InternshipRegistration::STATUS_COMPLETED,
            InternshipRegistration::STATUS_REJECTED,
            InternshipRegistration::STATUS_EXITED,
        ];

        $effectiveStatus = $targetStatus && in_array($targetStatus, $validStatuses, true)
            ? $targetStatus
            : ($detectedStatus ?: ($connection->default_integrated_status ?: InternshipRegistration::STATUS_WAITING));

        return [
            'fullname'               => $fullname,
            'email'                  => $email,
            'phone_number'           => $phone,
            'student_id'             => $studentId,
            'raw_institution_name'   => $institution,
            'raw_study_program_name' => $studyProgram,
            'division_id'            => $divisionId,
            'internship_status'      => $effectiveStatus,
        ];
    }

    /**
     * Buat otomatis akun User pemagang dan record InternshipRegistration bertipe terintegrasi.
     */
    public function createIntegratedUserAndRegistration(
        SpreadsheetConnection $connection,
        array $rowData,
        string $syncId,
        int $rowIndex,
        ?string $targetStatus = null
    ): InternshipRegistration {
        $extracted = $this->extractCandidateAttributesFromRow($connection, $rowData, $targetStatus);

        $fullname = $extracted['fullname'] ?: "Peserta Terintegrasi Baris {$rowIndex}";
        $cleanEmail = $extracted['email'] ?: ("integrated." . substr(Str::slug($syncId, ''), 0, 10) . "@brand" . ($connection->brand_id ?? 'global') . ".test");

        $user = User::firstOrCreate(
            ['email' => $cleanEmail],
            [
                'name'      => $fullname,
                'password'  => Hash::make(Str::random(16)),
                'role'      => 'pemagang',
                'brand_id'  => $connection->brand_id,
                'is_banned' => false,
            ]
        );

        $reg = InternshipRegistration::create([
            'user_id'                 => $user->id,
            'fullname'                => $fullname,
            'email'                   => $cleanEmail,
            'phone_number'            => $extracted['phone_number'],
            'student_id'              => $extracted['student_id'],
            'raw_institution_name'    => $extracted['raw_institution_name'],
            'raw_study_program_name'  => $extracted['raw_study_program_name'],
            'division_id'             => $extracted['division_id'],
            'brand_id'                => $connection->brand_id,
            'internship_status'       => $extracted['internship_status'],
            'registration_type'       => 'integrated',
            'is_integrated'           => true,
            'registration_provenance' => 'reconciled_sheet',
            'created_at'              => now(),
        ]);

        if (!empty($rowData)) {
            $this->saveCustomAnswersFromRowData($reg, $rowData);
        }

        return $reg;
    }

    /**
     * Pulihkan / ambil kembali (re-ingest) data pendaftaran dari baris spreadsheet yang SUDAH memiliki ID
     * namun datanya hilang/belum ada di database SertIF lokal, tanpa mengubah SERTIF_SYNC_ID pada spreadsheet.
     */
    public function recoverOrReingestExistingRow(
        SpreadsheetConnection $connection,
        string $tabName,
        int $rowIndex,
        string $existingSyncId,
        ?int $remoteRegistrationId = null,
        array $rowData = [],
        ?string $targetStatus = null,
        ?string $expectedChecksum = null,
        array $ownershipPolicies = []
    ): array {
        // Pre-Write Fingerprint Guard
        if ($expectedChecksum !== null && !empty($rowData)) {
            $computed = $this->computeRowContentChecksum($rowData);
            if ($computed !== $expectedChecksum) {
                return [
                    'success'    => false,
                    'row_index'  => $rowIndex,
                    'error_code' => 'CONCURRENCY_CONFLICT',
                    'message'    => "Struktur atau isi data baris {$rowIndex} telah berubah secara konkuren di Google Sheets. Operasi dibatalkan demi keamanan data.",
                ];
            }
        }

        $existingRowSync = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
            ->where('external_sync_id', $existingSyncId)
            ->first();

        $registration = null;
        if ($existingRowSync && $existingRowSync->syncable_id) {
            $registration = InternshipRegistration::where('id', $existingRowSync->syncable_id)
                ->whereNull('deleted_at')
                ->first();
        }

        if (!$registration && $remoteRegistrationId) {
            $registration = InternshipRegistration::where('id', $remoteRegistrationId)
                ->where('brand_id', $connection->brand_id)
                ->whereNull('deleted_at')
                ->first();
        }

        $extracted = $this->extractCandidateAttributesFromRow($connection, $rowData, $targetStatus);

        if (!$registration && !empty($extracted['email'])) {
            $registration = InternshipRegistration::where('email', $extracted['email'])
                ->where('brand_id', $connection->brand_id)
                ->whereNull('deleted_at')
                ->latest('id')
                ->first();
        }

        $isNewlyRestored = false;

        if ($registration) {
            $registration->is_integrated = true;
            if (empty($registration->registration_provenance) || $registration->registration_provenance === 'regular_web') {
                $registration->registration_provenance = 'reconciled_sheet';
            }
            $registration->save();

            if (!empty($rowData)) {
                $this->saveCustomAnswersFromRowData($registration, $rowData);
                if (!empty($ownershipPolicies)) {
                    $this->applyFieldOwnership($registration, $rowData, $ownershipPolicies);
                }
            }
        } else {
            // Data hilang di database lokal SertIF -> Pulihkan dan buat ulang akun serta registrasi
            $fullname = $extracted['fullname'] ?: "Peserta Pulih Baris {$rowIndex}";
            $cleanEmail = $extracted['email'] ?: ("recovered." . substr(Str::slug($existingSyncId, ''), 0, 10) . "@brand" . ($connection->brand_id ?? 'global') . ".test");

            $user = User::firstOrCreate(
                ['email' => $cleanEmail],
                [
                    'name'      => $fullname,
                    'password'  => Hash::make(Str::random(16)),
                    'role'      => 'pemagang',
                    'brand_id'  => $connection->brand_id,
                    'is_banned' => false,
                ]
            );

            $reg = new InternshipRegistration([
                'user_id'                 => $user->id,
                'fullname'                => $fullname,
                'email'                   => $cleanEmail,
                'phone_number'            => $extracted['phone_number'],
                'student_id'              => $extracted['student_id'],
                'raw_institution_name'    => $extracted['raw_institution_name'],
                'raw_study_program_name'  => $extracted['raw_study_program_name'],
                'division_id'             => $extracted['division_id'],
                'brand_id'                => $connection->brand_id,
                'internship_status'       => $extracted['internship_status'],
                'registration_type'       => 'integrated',
                'is_integrated'           => true,
                'registration_provenance' => 'recovered_sheet',
                'created_at'              => now(),
            ]);

            // Pertahankan ID asli jika remoteRegistrationId tersedia dan primary key tersebut belum terpakai
            if ($remoteRegistrationId && !InternshipRegistration::where('id', $remoteRegistrationId)->exists()) {
                $reg->id = $remoteRegistrationId;
            }

            $reg->save();

            if (!empty($rowData)) {
                $this->saveCustomAnswersFromRowData($reg, $rowData);
                if (!empty($ownershipPolicies)) {
                    $this->applyFieldOwnership($reg, $rowData, $ownershipPolicies);
                }
            }

            $registration = $reg;
            $isNewlyRestored = true;
        }

        // Tautkan SpreadsheetRowSync dengan SERTIF_SYNC_ID asli tanpa mengubah ID di spreadsheet
        SpreadsheetRowSync::updateOrCreate(
            [
                'spreadsheet_connection_id' => $connection->id,
                'external_sync_id'          => $existingSyncId,
            ],
            [
                'syncable_type'             => InternshipRegistration::class,
                'syncable_id'               => $registration->id,
                'target_sheet_tab'          => $tabName,
                'sync_channel'              => 'recovered_external',
                'sync_status'               => SpreadsheetRowSync::STATUS_SYNCED,
                'row_index'                 => $rowIndex,
                'last_synced_at'            => now(),
                'payload_checksum'          => $this->computeRowContentChecksum($rowData),
                'error_message'             => null,
            ]
        );

        // Jika di remote spreadsheet kolom SERTIF_REGISTRATION_ID masih kosong atau belum cocok dengan ID lokal,
        // perbarui informasi registrasi di Google Sheets dengan tetap mempertahankan SERTIF_SYNC_ID asli
        $remoteRegIdStr = !empty($rowData['SERTIF_REGISTRATION_ID']) ? (string) $rowData['SERTIF_REGISTRATION_ID'] : null;
        if (empty($remoteRegIdStr) || (string) $remoteRegIdStr !== (string) $registration->id) {
            try {
                $this->transport->attachRowSyncId(
                    connection: $connection,
                    tabName: $tabName,
                    rowIndex: $rowIndex,
                    syncId: $existingSyncId,
                    registrationId: (string) $registration->id,
                    expectedChecksum: $expectedChecksum
                );
            } catch (\Throwable $e) {
                Log::warning("Gagal memperbarui SERTIF_REGISTRATION_ID remote saat recovery baris {$rowIndex}: " . $e->getMessage());
            }
        }

        return [
            'success'         => true,
            'row_index'       => $rowIndex,
            'sync_id'         => $existingSyncId,
            'registration_id' => $registration->id,
            'is_recovered'    => true,
            'is_integrated'   => true,
            'message'         => $isNewlyRestored
                ? "Data baris {$rowIndex} dengan ID {$existingSyncId} berhasil dipulihkan ke database SertIF (#{$registration->id})."
                : "Data baris {$rowIndex} berhasil ditautkan kembali ke pendaftaran #{$registration->id}.",
        ];
    }

    /**
     * Bangun cache index identitas massal dalam satu batch query (O(1) in-memory lookup)
     * untuk mencegah ribuan query N+1 per baris saat pemindaian atau dry-run.
     */
    public function buildBulkIdentityLookupCache(SpreadsheetConnection $connection, array $rows): array
    {
        $syncIds = [];
        $regIds = [];
        $emails = [];
        $phones = [];
        $studentIds = [];

        foreach ($rows as $r) {
            $rowData = [];
            foreach ($r as $k => $v) {
                if (!in_array($k, ['_row_number', '_row_background', '_is_divider', '_divider_text'], true)) {
                    $rowData[$k] = $v;
                }
            }

            $syncId = $rowData['SERTIF_SYNC_ID'] ?? $rowData['sertif_sync_id'] ?? null;
            if (!empty($syncId) && trim((string) $syncId) !== '' && trim((string) $syncId) !== '-') {
                $syncIds[] = trim((string) $syncId);
            }

            $regId = $rowData['SERTIF_REGISTRATION_ID'] ?? $rowData['sertif_registration_id'] ?? null;
            if (!empty($regId) && is_numeric($regId)) {
                $regIds[] = (int) $regId;
            }

            foreach ($rowData as $k => $v) {
                $cleanK = strtolower(trim((string) $k));
                if (str_contains($cleanK, 'email') && !empty($v)) {
                    $emails[] = strtolower(trim((string) $v));
                } elseif ((str_contains($cleanK, 'nim') || str_contains($cleanK, 'nis')) && !empty($v)) {
                    $studentIds[] = trim((string) $v);
                } elseif (str_contains($cleanK, 'no. hp') || str_contains($cleanK, 'whatsapp') || $cleanK === 'no hp') {
                    $cleanP = preg_replace('/\D/', '', (string) $v);
                    if (strlen($cleanP) >= 8) {
                        $phones[] = $cleanP;
                    }
                }
            }
        }

        $syncIds = array_unique($syncIds);
        $regIds = array_unique($regIds);
        $emails = array_unique($emails);
        $phones = array_unique($phones);
        $studentIds = array_unique($studentIds);

        $syncMap = [];
        if (!empty($syncIds)) {
            $syncMap = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
                ->whereIn('external_sync_id', $syncIds)
                ->whereNotNull('syncable_id')
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('internship_registrations')
                        ->whereColumn('internship_registrations.id', 'spreadsheet_row_syncs.syncable_id')
                        ->whereNull('internship_registrations.deleted_at');
                })
                ->pluck('syncable_id', 'external_sync_id')
                ->all();
        }

        $regMap = [];
        if (!empty($regIds)) {
            $regMap = InternshipRegistration::where('brand_id', $connection->brand_id)
                ->whereIn('id', $regIds)
                ->pluck('id', 'id')
                ->all();
        }

        $emailMap = [];
        if (!empty($emails)) {
            $emailRecords = InternshipRegistration::where('brand_id', $connection->brand_id)
                ->whereIn('email', $emails)
                ->orderBy('id', 'desc')
                ->get(['id', 'email']);
            foreach ($emailRecords as $rec) {
                $low = strtolower(trim((string) $rec->email));
                if (!isset($emailMap[$low])) {
                    $emailMap[$low] = $rec->id;
                }
            }
        }

        $phoneMap = [];
        if (!empty($phones)) {
            $phoneRecords = InternshipRegistration::where('brand_id', $connection->brand_id)
                ->whereNotNull('phone_number')
                ->orderBy('id', 'desc')
                ->get(['id', 'phone_number']);

            $dbPhoneIndex = [];
            foreach ($phoneRecords as $rec) {
                $norm = preg_replace('/\D/', '', (string) $rec->phone_number);
                if (strlen($norm) >= 8 && !isset($dbPhoneIndex[$norm])) {
                    $dbPhoneIndex[$norm] = $rec->id;
                }
            }

            foreach ($phones as $p) {
                if (isset($dbPhoneIndex[$p])) {
                    $phoneMap[$p] = $dbPhoneIndex[$p];
                } else {
                    $sub = substr($p, -8);
                    foreach ($dbPhoneIndex as $norm => $id) {
                        if (str_ends_with($norm, $sub)) {
                            $phoneMap[$p] = $id;
                            break;
                        }
                    }
                }
            }
        }

        $studentMap = [];
        if (!empty($studentIds)) {
            $studentRecords = InternshipRegistration::where('brand_id', $connection->brand_id)
                ->whereIn('student_id', $studentIds)
                ->orderBy('id', 'desc')
                ->get(['id', 'student_id']);
            foreach ($studentRecords as $rec) {
                if (!isset($studentMap[$rec->student_id])) {
                    $studentMap[$rec->student_id] = $rec->id;
                }
            }
        }

        return [
            'syncs'    => $syncMap,
            'regs'     => $regMap,
            'emails'   => $emailMap,
            'phones'   => $phoneMap,
            'students' => $studentMap,
        ];
    }

    /**
     * Resolusi Identitas Granular: Klasifikasi status pencocokan baris sumber dengan data lokal.
     * Taksonomi: exact_source_match, exact_registration_match, strong_candidate, conflicting_identity, ambiguous_identity, no_match.
     *
     * @return array{match_type: string, confidence: float, matched_registration_id: ?int, sync_id: ?string, reason: string, requires_review: bool}
     */
    public function resolveIdentityMatch(SpreadsheetConnection $connection, array $rowData, ?array $lookupCache = null): array
    {
        // 1. Exact Source Identity Match (valid SERTIF_SYNC_ID linked to SpreadsheetRowSync)
        $syncId = $rowData['SERTIF_SYNC_ID'] ?? $rowData['sertif_sync_id'] ?? null;
        if (!empty($syncId) && trim((string) $syncId) !== '' && trim((string) $syncId) !== '-') {
            $cleanSync = trim((string) $syncId);
            $syncableId = null;

            if ($lookupCache !== null) {
                $syncableId = $lookupCache['syncs'][$cleanSync] ?? null;
            } else {
                $existingSync = SpreadsheetRowSync::where('spreadsheet_connection_id', $connection->id)
                    ->where('external_sync_id', $cleanSync)
                    ->first();
                if ($existingSync && $existingSync->syncable_id) {
                    if (InternshipRegistration::where('id', $existingSync->syncable_id)->whereNull('deleted_at')->exists()) {
                        $syncableId = $existingSync->syncable_id;
                    }
                }
            }

            if ($syncableId) {
                return [
                    'match_type'              => 'exact_source_match',
                    'confidence'              => 1.0,
                    'matched_registration_id' => (int) $syncableId,
                    'sync_id'                 => $cleanSync,
                    'reason'                  => "Cocok pasti berdasarkan SERTIF_SYNC_ID ({$cleanSync})",
                    'requires_review'         => false,
                ];
            }
        }

        // 2. Exact Registration Identity Match (valid SERTIF_REGISTRATION_ID)
        $regId = $rowData['SERTIF_REGISTRATION_ID'] ?? $rowData['sertif_registration_id'] ?? null;
        if (!empty($regId) && is_numeric($regId)) {
            $matchedRegId = null;

            if ($lookupCache !== null) {
                $matchedRegId = $lookupCache['regs'][(int) $regId] ?? null;
            } else {
                $matchedReg = InternshipRegistration::where('id', (int) $regId)
                    ->where('brand_id', $connection->brand_id)
                    ->first();
                $matchedRegId = $matchedReg?->id;
            }

            if ($matchedRegId) {
                return [
                    'match_type'              => 'exact_registration_match',
                    'confidence'              => 1.0,
                    'matched_registration_id' => (int) $matchedRegId,
                    'sync_id'                 => null,
                    'reason'                  => "Cocok pasti berdasarkan SERTIF_REGISTRATION_ID (#{$regId})",
                    'requires_review'         => false,
                ];
            }
        }

        $email = null;
        $phone = null;
        $studentId = null;
        foreach ($rowData as $k => $v) {
            $cleanK = strtolower(trim((string) $k));
            if (str_starts_with($cleanK, '_')) continue;
            if (!is_string($v) && !is_numeric($v)) continue;

            if (str_contains($cleanK, 'email') && !empty($v)) {
                $email = strtolower(trim((string) $v));
            } elseif ((str_contains($cleanK, 'nim') || str_contains($cleanK, 'nis')) && !empty($v)) {
                $studentId = trim((string) $v);
            } elseif (str_contains($cleanK, 'no. hp') || str_contains($cleanK, 'whatsapp') || $cleanK === 'no hp') {
                $phone = preg_replace('/\D/', '', (string) $v);
            }
        }

        // 3. Evaluasi Strong Candidate & Conflicting Identity (Email + No HP)
        $emailMatchId = null;
        if (!empty($email)) {
            if ($lookupCache !== null) {
                $emailMatchId = $lookupCache['emails'][$email] ?? null;
            } else {
                $emailMatch = InternshipRegistration::where('brand_id', $connection->brand_id)
                    ->where('email', $email)
                    ->latest('id')
                    ->first();
                $emailMatchId = $emailMatch?->id;
            }
        }

        $phoneMatchId = null;
        if (!empty($phone) && strlen($phone) >= 8) {
            if ($lookupCache !== null) {
                $phoneMatchId = $lookupCache['phones'][$phone] ?? null;
            } else {
                $phoneMatch = InternshipRegistration::where('brand_id', $connection->brand_id)
                    ->where('phone_number', 'like', "%{$phone}%")
                    ->latest('id')
                    ->first();
                $phoneMatchId = $phoneMatch?->id;
            }
        }

        if ($emailMatchId && $phoneMatchId) {
            if ($emailMatchId === $phoneMatchId) {
                return [
                    'match_type'              => 'strong_candidate',
                    'confidence'              => 0.95,
                    'matched_registration_id' => $emailMatchId,
                    'sync_id'                 => null,
                    'reason'                  => "Kandidat kuat: Email dan No. HP cocok pada pendaftaran #{$emailMatchId}",
                    'requires_review'         => false,
                ];
            } else {
                return [
                    'match_type'              => 'conflicting_identity',
                    'confidence'              => 0.50,
                    'matched_registration_id' => $emailMatchId,
                    'sync_id'                 => null,
                    'reason'                  => "Konflik identitas: Email cocok pada #{$emailMatchId}, namun No. HP cocok pada #{$phoneMatchId}",
                    'requires_review'         => true,
                ];
            }
        }

        if ($emailMatchId) {
            return [
                'match_type'              => 'strong_candidate',
                'confidence'              => 0.85,
                'matched_registration_id' => $emailMatchId,
                'sync_id'                 => null,
                'reason'                  => "Kandidat: Email cocok pada pendaftaran #{$emailMatchId}",
                'requires_review'         => false,
            ];
        }

        if ($phoneMatchId) {
            return [
                'match_type'              => 'strong_candidate',
                'confidence'              => 0.80,
                'matched_registration_id' => $phoneMatchId,
                'sync_id'                 => null,
                'reason'                  => "Kandidat: No. HP cocok pada pendaftaran #{$phoneMatchId}",
                'requires_review'         => false,
            ];
        }

        if (!empty($studentId)) {
            $studentMatchId = null;
            if ($lookupCache !== null) {
                $studentMatchId = $lookupCache['students'][$studentId] ?? null;
            } else {
                $studentMatch = InternshipRegistration::where('brand_id', $connection->brand_id)
                    ->where('student_id', $studentId)
                    ->latest('id')
                    ->first();
                $studentMatchId = $studentMatch?->id;
            }

            if ($studentMatchId) {
                return [
                    'match_type'              => 'strong_candidate',
                    'confidence'              => 0.80,
                    'matched_registration_id' => $studentMatchId,
                    'sync_id'                 => null,
                    'reason'                  => "Kandidat: NIM/NIS cocok pada pendaftaran #{$studentMatchId}",
                    'requires_review'         => false,
                ];
            }
        }

        if (!empty($cleanSync) || (!empty($regId) && is_numeric($regId))) {
            $idLabel = !empty($cleanSync) ? $cleanSync : ('#' . $regId);
            return [
                'match_type'              => 'orphaned_remote_id',
                'confidence'              => 1.0,
                'matched_registration_id' => null,
                'sync_id'                 => $cleanSync,
                'remote_registration_id'  => (!empty($regId) && is_numeric($regId)) ? (int) $regId : null,
                'reason'                  => "Baris telah memiliki ID ({$idLabel}) di spreadsheet, namun data belum ada / hilang di database lokal SertIF. Siap untuk dipulihkan (recovery).",
                'requires_review'         => false,
                'is_recovery_candidate'   => true,
            ];
        }

        return [
            'match_type'              => 'no_match',
            'confidence'              => 0.0,
            'matched_registration_id' => null,
            'sync_id'                 => null,
            'remote_registration_id'  => null,
            'reason'                  => 'Tidak ada kecocokan identitas lokal (Siap dibuatkan pendaftaran baru)',
            'requires_review'         => false,
            'is_recovery_candidate'   => false,
        ];
    }

    /**
     * Cari ID pendaftaran lokal yang cocok (terisolasi ketat per brand).
     */
    protected function findMatchingLocalRegistrationId(SpreadsheetConnection $connection, array $rowData): ?int
    {
        $match = $this->resolveIdentityMatch($connection, $rowData);
        return $match['matched_registration_id'];
    }

    /**
     * Parser Kolom Gabungan (Composite Column Parser) Tiga Tingkat:
     * 1. Delimiter sederhana
     * 2. Regex custom dengan ReDoS protection
     * 3. Manual selector
     *
     * @return array{success: bool, parts: array, raw: string, error?: string}
     */
    public function parseCompositeField(string $rawValue, array $rule): array
    {
        $type = $rule['type'] ?? 'delimiter';
        $targetFields = (array) ($rule['target_fields'] ?? $rule['target_keys'] ?? []);
        $raw = trim($rawValue);
        if ($raw === '') {
            return ['success' => true, 'parts' => [], 'parsed' => [], 'raw' => $rawValue];
        }

        if ($type === 'regex') {
            $pattern = (string) ($rule['pattern'] ?? $rule['regex_pattern'] ?? '');
            if (empty($pattern)) {
                return ['success' => false, 'error' => 'Pola regex tidak boleh kosong', 'parts' => [], 'parsed' => [], 'raw' => $rawValue];
            }
            $prevLimit = ini_get('pcre.backtrack_limit');
            ini_set('pcre.backtrack_limit', '10000');
            $matches = [];
            $matched = @preg_match($pattern, $raw, $matches);
            ini_set('pcre.backtrack_limit', $prevLimit);
            if ($matched === false) {
                return ['success' => false, 'error' => 'Sintaks regex tidak valid', 'parts' => [], 'parsed' => [], 'raw' => $rawValue];
            }
            if ($matched === 0) {
                return ['success' => false, 'error' => 'Nilai tidak cocok dengan pola regex', 'parts' => [], 'parsed' => [], 'raw' => $rawValue];
            }

            $namedMatches = array_filter($matches, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
            $numericMatches = array_values(array_filter($matches, fn($k) => is_int($k) && $k > 0, ARRAY_FILTER_USE_KEY));
            $parts = !empty($namedMatches) ? array_values($namedMatches) : $numericMatches;
            $parsed = !empty($namedMatches) ? $namedMatches : [];

            if (empty($parsed) && !empty($targetFields)) {
                foreach ($targetFields as $idx => $fKey) {
                    if (isset($parts[$idx])) {
                        $parsed[$fKey] = $parts[$idx];
                    }
                }
            }

            $parts = array_map(fn($v) => is_string($v) ? trim($v) : $v, $parts);
            $parsed = array_map(fn($v) => is_string($v) ? trim($v) : $v, $parsed);

            return ['success' => true, 'parts' => $parts, 'parsed' => $parsed, 'raw' => $rawValue];
        }

        if ($type === 'manual') {
            $indices = (array) ($rule['indices'] ?? [0]);
            $delimiter = $rule['delimiter'] ?? '/';
            $splits = array_map('trim', explode($delimiter, $raw));
            $parts = [];
            foreach ($indices as $i) {
                $parts[] = $splits[$i] ?? '';
            }
            $parsed = [];
            if (!empty($targetFields)) {
                foreach ($targetFields as $idx => $fKey) {
                    if (isset($parts[$idx])) {
                        $parsed[$fKey] = $parts[$idx];
                    }
                }
            }
            return ['success' => true, 'parts' => $parts, 'parsed' => $parsed, 'raw' => $rawValue];
        }

        // Default delimiter
        $delimiter = $rule['delimiter'] ?? '/';
        $parts = array_map('trim', explode($delimiter, $raw));
        $parsed = [];
        if (!empty($targetFields)) {
            foreach ($targetFields as $idx => $fKey) {
                if (isset($parts[$idx])) {
                    $parsed[$fKey] = $parts[$idx];
                }
            }
        }
        return ['success' => true, 'parts' => $parts, 'parsed' => $parsed, 'raw' => $rawValue];
    }

    /**
     * Terapkan kebijakan kepemilikan field (Field Ownership Policy).
     */
    public function applyFieldOwnership(
        InternshipRegistration $registration,
        array $rowData,
        array $ownershipPolicies
    ): void {
        $updatable = [];

        foreach ($ownershipPolicies as $field => $policy) {
            $val = $rowData[$field] ?? null;
            if ($val === null || $val === '') continue;

            if ($policy === 'source_authoritative') {
                if (in_array($field, ['fullname', 'phone_number', 'email', 'student_id'], true)) {
                    $updatable[$field] = $val;
                }
            }
            // sertif_authoritative & read_only do not overwrite
        }

        if (!empty($updatable)) {
            $registration->update($updatable);
        }
    }

    /**
     * Pindai baris workspace secara semi-otomatis dengan metadata warna, deteksi divider, dan rule engine dinamis.
     */
    public function scanWorkspaceRows(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $startRow = null,
        ?int $endRow = null,
        ?int $headerRow = null,
        int $page = 1,
        int $pageSize = 100,
        ?string $sortBy = null,
        string $sortDir = 'asc',
        array $colorMapping = [],
        array $rangeMapping = [],
        ?string $defaultStatus = null,
        ?string $statusColumn = null,
        bool $fetchColors = true,
        bool $skipSynced = false
    ): array {
        $profile = SpreadsheetTabProfile::where('spreadsheet_connection_id', $connection->id)
            ->where('tab_name', $tabName)
            ->first();

        if ($headerRow === null && $profile) {
            $headerRow = $profile->header_row;
        }
        if ($startRow === null && $profile) {
            $startRow = $profile->data_start_row;
        }

        // Fallback cerdas: tab pendaftaran/response default ke 10, selain itu 1
        if ($headerRow === null) {
            $cleanName = strtolower(trim($tabName));
            if (str_contains($cleanName, 'pendaftaran') || str_contains($cleanName, 'response') || str_contains($cleanName, 'registrasi')) {
                $headerRow = 10;
            } elseif ($startRow !== null && $startRow > 1) {
                $headerRow = $startRow - 1;
            } else {
                $headerRow = 1;
            }
        }

        if ($startRow === null) {
            $startRow = $headerRow + 1;
        }

        $effectiveStatusCol = $statusColumn ?: ($profile?->status_column_letter ?? null);

        $response = $this->transport->readRowsPage(
            connection: $connection,
            tabName: $tabName,
            headerRow: $headerRow,
            page: $page,
            pageSize: $pageSize,
            maskPii: false,
            fetchColors: $fetchColors,
            dataStartRow: $startRow
        );

        if (!$response->success) {
            throw new DomainException("Gagal memindai baris spreadsheet: " . $response->errorMessage);
        }

        $rawRows = $response->context['rows'] ?? [];

        // Optimasi Performa Ekstrem: Buat indeks in-memory massal (O(1) lookup)
        // untuk seluruh baris, menghindari ribuan round-trip SQL N+1 per baris
        $lookupCache = $this->buildBulkIdentityLookupCache($connection, $rawRows);

        // Normalisasi key warna pada colorMapping menjadi huruf kecil
        $normalizedColorMap = [];
        foreach ($colorMapping as $hex => $st) {
            if (!empty($hex) && !empty($st)) {
                $normalizedColorMap[strtolower(trim((string) $hex))] = trim((string) $st);
            }
        }

        $paletteMap = [];
        $columnValuesMap = [];
        $detectedDividers = [];
        $processedRows = [];

        foreach ($rawRows as $r) {
            $rowNum = (int) ($r['_row_number'] ?? 0);
            if ($startRow !== null && $rowNum < $startRow) {
                continue;
            }
            if ($endRow !== null && $rowNum > $endRow) {
                continue;
            }

            $rawBg = (string) ($r['_row_background'] ?? '');
            $cleanBg = strtolower(trim($rawBg));
            $isTransparent = empty($cleanBg) || $cleanBg === 'null' || $cleanBg === 'transparent' || $cleanBg === '#00000000';
            if ($isTransparent) {
                $cleanBg = 'transparent';
            }

            if (!isset($paletteMap[$cleanBg])) {
                $paletteMap[$cleanBg] = 0;
            }
            $paletteMap[$cleanBg]++;

            $isDivider = !empty($r['_is_divider']);
            $dividerText = (string) ($r['_divider_text'] ?? '');

            if ($isDivider) {
                $detectedDividers[] = [
                    'row_number'   => $rowNum,
                    'text'         => $dividerText,
                    'background'   => $cleanBg,
                ];
            }

            // Pisahkan kolom data mentah (abaikan semua metadata berawalan underscore)
            $rowData = [];
            foreach ($r as $k => $v) {
                if (!str_starts_with((string) $k, '_')) {
                    $rowData[$k] = $v;
                }
            }

            // Kumpulkan varian nilai unik per kolom di seluruh baris sheet (untuk deteksi status berbasis nilai kolom)
            foreach ($rowData as $k => $v) {
                if (is_string($v) || is_numeric($v)) {
                    $valStr = trim((string) $v);
                    if ($valStr !== '' && strlen($valStr) <= 100) {
                        $columnValuesMap[$k][$valStr] = ($columnValuesMap[$k][$valStr] ?? 0) + 1;
                    }
                }
            }

            // Periksa keberadaan SERTIF_SYNC_ID
            $syncId = $rowData['SERTIF_SYNC_ID'] ?? $rowData['sertif_sync_id'] ?? null;
            $hasSyncId = !empty($syncId) && trim((string) $syncId) !== '' && trim((string) $syncId) !== '-';

            // Filter skip jika sudah ada ID SertIF (agar jangkauan pemberian status lebih luas pada baris baru)
            if ($skipSynced && $hasSyncId) {
                continue;
            }

            // Rule Engine Penentuan Status Magang: Status awal MUTLAK NULL
            $assignedStatus = null;

            // 1. Sumber Kolom Teks Status
            if (!empty($effectiveStatusCol)) {
                foreach ($rowData as $k => $val) {
                    if (str_starts_with(strtoupper(trim($k)), strtoupper($effectiveStatusCol) . '_') || strtoupper(trim($k)) === strtoupper($effectiveStatusCol)) {
                        $cVal = strtolower(trim((string) $val));
                        if (str_contains($cVal, 'terima') || str_contains($cVal, 'acc')) $assignedStatus = 'accepted';
                        elseif (str_contains($cVal, 'aktif') || str_contains($cVal, 'masuk')) $assignedStatus = 'active';
                        elseif (str_contains($cVal, 'tolak')) $assignedStatus = 'rejected';
                        elseif (str_contains($cVal, 'selesai') || str_contains($cVal, 'alumni')) $assignedStatus = 'completed';
                        elseif (str_contains($cVal, 'keluar')) $assignedStatus = 'exited';
                        elseif (str_contains($cVal, 'pending') || str_contains($cVal, 'tunda')) $assignedStatus = 'pending';
                        elseif (str_contains($cVal, 'calon') || str_contains($cVal, 'waiting') || str_contains($cVal, 'follow up')) $assignedStatus = 'waiting';
                        break;
                    }
                }
            }

            // 2. Aturan Rentang Baris (Range Rule)
            if (!$assignedStatus) {
                foreach ($rangeMapping as $rule) {
                    $from = (int) ($rule['from'] ?? 0);
                    $to = (int) ($rule['to'] ?? 0);
                    $rStatus = trim((string) ($rule['status'] ?? ''));
                    if ($from > 0 && $to > 0 && $rowNum >= $from && $rowNum <= $to && !empty($rStatus) && $rStatus !== 'none') {
                        $assignedStatus = $rStatus;
                        break;
                    }
                }
            }

            // 3. Aturan Warna Baris
            if (!$assignedStatus && !empty($cleanBg) && isset($normalizedColorMap[$cleanBg])) {
                $mapped = $normalizedColorMap[$cleanBg];
                if (!empty($mapped) && $mapped !== 'none') {
                    $assignedStatus = $mapped;
                }
            }

            // 4. Fallback Default Status Admin
            if (!$assignedStatus && !empty($defaultStatus) && $defaultStatus !== 'none') {
                $assignedStatus = $defaultStatus;
            }

            $checksum = $this->computeRowContentChecksum($rowData);
            $identityMatch = $this->resolveIdentityMatch($connection, $rowData, $lookupCache);

            // Ekstrak nama dan kontak akurat
            $displayName = null;
            $displayContact = null;

            // Prioritaskan kolom yang namanya mengandung nama lengkap
            foreach ($rowData as $k => $val) {
                if (str_starts_with((string) $k, '_')) continue;
                if (!is_string($val)) continue;
                $cleanK = strtolower(trim((string) $k));
                if ((str_contains($cleanK, 'nama lengkap') || $cleanK === 'nama') && !empty($val)) {
                    $displayName = $val;
                    break;
                }
            }

            // Cari kontak
            foreach ($rowData as $k => $val) {
                if (str_starts_with((string) $k, '_')) continue;
                if (!is_string($val) && !is_numeric($val)) continue;
                $cleanK = strtolower(trim((string) $k));
                if ((str_contains($cleanK, 'email') || str_contains($cleanK, 'whatsapp') || str_contains($cleanK, 'no. hp') || $cleanK === 'no hp') && !empty($val)) {
                    $displayContact = (string) $val;
                    break;
                }
            }

            if (!$displayName) {
                foreach ($rowData as $k => $val) {
                    $cleanK = strtolower(trim((string) $k));
                    if (str_starts_with($cleanK, '_') || str_starts_with($cleanK, 'sertif_')) continue;
                    if (!empty($val) && is_string($val) && strlen($val) > 2) {
                        $displayName = $val;
                        break;
                    }
                }
            }

            $processedRows[] = [
                'row_number'             => $rowNum,
                'row_background'         => $cleanBg === 'transparent' ? '#ffffff' : $cleanBg,
                'raw_background'         => $cleanBg,
                'is_transparent'         => $isTransparent,
                'is_divider'             => $isDivider,
                'divider_text'           => $dividerText,
                'display_name'           => (string) ($displayName ?: "Baris {$rowNum}"),
                'display_contact'        => (string) ($displayContact ?: '-'),
                'status'                 => $assignedStatus,
                'has_sync_id'            => $hasSyncId,
                'sync_id'                => $hasSyncId ? (string) $syncId : null,
                'matched_local_id'       => $identityMatch['matched_registration_id'],
                'is_missing_local'       => $hasSyncId && empty($identityMatch['matched_registration_id']),
                'remote_registration_id' => $identityMatch['remote_registration_id'] ?? null,
                'is_recovery_candidate'  => !empty($identityMatch['is_recovery_candidate']),
                'match_type'             => $identityMatch['match_type'],
                'match_reason'           => $identityMatch['reason'],
                'content_checksum'       => $checksum,
                'data'                   => $rowData,
            ];
        }

        // Susun palet warna terstruktur untuk UI (memisahkan transparan & putih)
        $palette = [];
        foreach ($paletteMap as $hex => $count) {
            $isTransparent = ($hex === 'transparent');
            $isWhite = ($hex === '#ffffff');
            $name = $isTransparent ? 'Tanpa Warna (Transparan)' : ($isWhite ? 'Warna Putih Eksplisit' : strtoupper($hex));

            $palette[] = [
                'hex'            => $hex,
                'name'           => $name,
                'is_white'       => $isWhite,
                'is_transparent' => $isTransparent,
                'count'          => $count,
            ];
        }

        // Pengurutan
        if (!empty($sortBy)) {
            usort($processedRows, function ($a, $b) use ($sortBy, $sortDir) {
                $valA = $a[$sortBy] ?? ($a['data'][$sortBy] ?? '');
                $valB = $b[$sortBy] ?? ($b['data'][$sortBy] ?? '');

                if (is_numeric($valA) && is_numeric($valB)) {
                    $cmp = $valA <=> $valB;
                } else {
                    $cmp = strcasecmp((string) $valA, (string) $valB);
                }

                return strtolower($sortDir) === 'desc' ? -$cmp : $cmp;
            });
        }

        return [
            'success'                => true,
            'total_rows'             => count($processedRows),
            'header_row'             => $headerRow,
            'start_row'              => $startRow,
            'palette'                => $palette,
            'column_distinct_values' => $columnValuesMap,
            'detected_dividers'      => $detectedDividers,
            'page'                   => (int) ($response->context['page'] ?? $page),
            'total_pages'            => (int) ($response->context['total_pages'] ?? 1),
            'rows'                   => array_values($processedRows),
        ];
    }

    /**
     * Dry-Run Reconciliation: Simulasi rekonsiliasi batch tanpa mutasi database maupun Google Sheets.
     */
    public function dryRunReconciliation(
        SpreadsheetConnection $connection,
        string $tabName,
        array $items
    ): array {
        $totalEvaluated = count($items);
        $counts = [
            'exact_source_match'       => 0,
            'exact_registration_match' => 0,
            'orphaned_remote_id'       => 0,
            'strong_candidate'         => 0,
            'conflicting_identity'     => 0,
            'ambiguous_identity'       => 0,
            'no_match'                 => 0,
            'skipped'                  => 0,
        ];
        $previewActions = [];

        // Optimasi massal O(1) in-memory lookup cache untuk dry-run
        $itemRows = array_map(fn($it) => (array) ($it['row_data'] ?? []), $items);
        $lookupCache = $this->buildBulkIdentityLookupCache($connection, $itemRows);

        foreach ($items as $item) {
            $rIndex = (int) ($item['row_index'] ?? 0);
            $status = trim((string) ($item['status'] ?? ''));
            $rowData = (array) ($item['row_data'] ?? []);

            if ($rIndex < 1) continue;
            if (empty($status) || $status === 'skip' || $status === 'none') {
                $counts['skipped']++;
                $previewActions[] = [
                    'row_index' => $rIndex,
                    'action'    => 'skip',
                    'reason'    => 'Status diabaikan atau belum ditentukan',
                ];
                continue;
            }

            $resolution = $this->resolveIdentityMatch($connection, $rowData, $lookupCache);
            $mType = $resolution['match_type'];
            if (isset($counts[$mType])) {
                $counts[$mType]++;
            }

            $action = 'create_new';
            if ($mType === 'exact_source_match' || $mType === 'exact_registration_match') {
                $action = 'sync_existing';
            } elseif ($mType === 'orphaned_remote_id') {
                $action = 'recover_and_reingest';
            } elseif ($mType === 'strong_candidate') {
                $action = 'link_and_sync';
            } elseif ($mType === 'conflicting_identity') {
                $action = 'requires_manual_review';
            }

            $displayName = $rowData['Nama Lengkap'] ?? $rowData['nama_lengkap'] ?? $rowData['fullname'] ?? null;
            if (!$displayName) {
                foreach ($rowData as $k => $v) {
                    if (str_contains(strtolower((string) $k), 'nama') && !empty($v)) {
                        $displayName = (string) $v;
                        break;
                    }
                }
            }

            $previewActions[] = [
                'row_index'       => $rIndex,
                'display_name'    => $displayName,
                'target_status'   => $status,
                'match_type'      => $mType,
                'action'          => $action,
                'registration_id' => $resolution['matched_registration_id'],
                'confidence'      => $resolution['confidence'],
                'reason'          => $resolution['reason'],
                'requires_review' => $resolution['requires_review'],
            ];
        }

        $canWrite = $counts['no_match'] + $counts['strong_candidate'] + $counts['exact_registration_match'] + $counts['exact_source_match'] + $counts['orphaned_remote_id'];
        $conflicts = $counts['conflicting_identity'] + $counts['ambiguous_identity'];

        $report = [
            'total_evaluated'   => $totalEvaluated,
            'can_write_count'   => $canWrite,
            'safe_to_reconcile' => $canWrite,
            'conflicts'         => $conflicts,
            'warnings'          => $counts['ambiguous_identity'],
            'skip_count'        => $counts['skipped'],
            'counts'            => $counts,
            'evaluated_rows'    => array_map(function ($a) {
                return [
                    'row_index'             => $a['row_index'],
                    'display_name'          => $a['display_name'] ?? ('Row #' . $a['row_index']),
                    'status'                => $a['target_status'] ?? null,
                    'match_type'            => $a['match_type'] ?? 'no_match',
                    'matched_id'            => $a['registration_id'] ?? null,
                    'has_conflict'          => in_array($a['match_type'] ?? '', ['conflicting_identity', 'ambiguous_identity']),
                    'reconciliation_status' => $a['action'],
                    'action_taken'          => $a['reason'],
                    'warnings'              => (!empty($a['requires_review'])) ? [$a['reason']] : [],
                ];
            }, $previewActions),
            'drift_detected'    => false,
        ];

        return [
            'success'         => true,
            'dry_run'         => true,
            'tab_name'        => $tabName,
            'total_evaluated' => $totalEvaluated,
            'ready_to_import' => $canWrite,
            'conflict_count'  => $conflicts,
            'counts'          => $counts,
            'actions_preview' => $previewActions,
            'report'          => $report,
        ];
    }

    /**
     * Rekonsiliasi batch untuk sekumpulan baris terpilih dalam satu transaksi aman.
     *
     * @param array<int, array{row_index: int, status: string, row_data?: array, expected_checksum?: string, registration_id?: int}> $items
     */
    public function reconcileBatchRows(
        SpreadsheetConnection $connection,
        string $tabName,
        array $items,
        array $ownershipPolicies = []
    ): array {
        $successCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($items as $item) {
            $rIndex = (int) ($item['row_index'] ?? 0);
            $status = trim((string) ($item['status'] ?? ''));
            $rowData = (array) ($item['row_data'] ?? []);
            $checksum = $item['expected_checksum'] ?? null;
            $regId = !empty($item['registration_id']) ? (int) $item['registration_id'] : null;
            $syncId = !empty($item['sync_id']) ? trim((string) $item['sync_id']) : null;

            if ($rIndex < 1) continue;
            if (empty($status) || $status === 'skip' || $status === 'none') {
                continue;
            }

            try {
                $res = $this->reconcileRowSyncId(
                    connection: $connection,
                    tabName: $tabName,
                    rowIndex: $rIndex,
                    existingSyncId: $syncId,
                    registrationId: $regId,
                    expectedChecksum: $checksum,
                    rowData: $rowData,
                    targetStatus: $status,
                    ownershipPolicies: $ownershipPolicies
                );

                if (!empty($res['success'])) {
                    $successCount++;
                    $results[] = [
                        'row_index' => $rIndex,
                        'success'   => true,
                        'sync_id'   => $res['sync_id'] ?? null,
                    ];
                } else {
                    $failedCount++;
                    $results[] = [
                        'row_index' => $rIndex,
                        'success'   => false,
                        'message'   => $res['message'] ?? 'Gagal rekonsiliasi baris.',
                    ];
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $results[] = [
                    'row_index' => $rIndex,
                    'success'   => false,
                    'message'   => $e->getMessage(),
                ];
            }
        }

        return [
            'total'         => count($items),
            'success_count' => $successCount,
            'failed_count'  => $failedCount,
            'results'       => $results,
        ];
    }

    /**
     * Pulihkan dan tarik kembali sekumpulan baris yang SUDAH memiliki ID di spreadsheet ke SertIF secara massal.
     *
     * @param array<int, array{row_index: int, status?: string, sync_id: string, row_data?: array, expected_checksum?: string, registration_id?: int}> $items
     */
    public function batchRecoverRows(
        SpreadsheetConnection $connection,
        string $tabName,
        array $items,
        array $ownershipPolicies = []
    ): array {
        $recoveredCount = 0;
        $failedCount = 0;
        $results = [];

        foreach ($items as $item) {
            $rIndex = (int) ($item['row_index'] ?? 0);
            $status = trim((string) ($item['status'] ?? ''));
            $rowData = (array) ($item['row_data'] ?? []);
            $checksum = $item['expected_checksum'] ?? null;
            $syncId = $item['sync_id'] ?? ($rowData['SERTIF_SYNC_ID'] ?? $rowData['sertif_sync_id'] ?? null);
            $regId = !empty($item['registration_id']) ? (int) $item['registration_id'] : (!empty($rowData['SERTIF_REGISTRATION_ID']) ? (int) $rowData['SERTIF_REGISTRATION_ID'] : null);

            if ($rIndex < 1 || empty($syncId)) continue;

            try {
                $res = $this->recoverOrReingestExistingRow(
                    connection: $connection,
                    tabName: $tabName,
                    rowIndex: $rIndex,
                    existingSyncId: (string) $syncId,
                    remoteRegistrationId: $regId,
                    rowData: $rowData,
                    targetStatus: (!empty($status) && $status !== 'unassigned' && $status !== 'skip') ? $status : null,
                    expectedChecksum: $checksum,
                    ownershipPolicies: $ownershipPolicies
                );

                if (!empty($res['success'])) {
                    $recoveredCount++;
                    $results[] = [
                        'row_index'       => $rIndex,
                        'success'         => true,
                        'sync_id'         => $res['sync_id'],
                        'registration_id' => $res['registration_id'],
                    ];
                } else {
                    $failedCount++;
                    $results[] = [
                        'row_index' => $rIndex,
                        'success'   => false,
                        'message'   => $res['message'] ?? 'Gagal memulihkan baris.',
                    ];
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $results[] = [
                    'row_index' => $rIndex,
                    'success'   => false,
                    'message'   => $e->getMessage(),
                ];
            }
        }

        return [
            'total'           => count($items),
            'recovered_count' => $recoveredCount,
            'failed_count'    => $failedCount,
            'results'         => $results,
        ];
    }

    /**
     * Hitung checksum isi baris data mentah (mengabaikan kolom sistem).
     */
    public function computeRowContentChecksum(array $rowData): string
    {
        $filtered = [];
        foreach ($rowData as $k => $v) {
            // Abaikan metadata internal berawalan underscore (_cells, _cells_by_letter, _row_background, dll)
            if (str_starts_with((string) $k, '_')) {
                continue;
            }

            $cleanK = strtoupper(trim((string) $k));
            if (!in_array($cleanK, ['SERTIF_SYNC_ID', 'SERTIF_REGISTRATION_ID', 'SERTIF_SYNC_STATUS', 'SERTIF_LAST_SYNC_AT'], true)) {
                if (is_array($v)) {
                    $filtered[$cleanK] = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $filtered[$cleanK] = trim((string) ($v ?? ''));
                }
            }
        }
        ksort($filtered);
        return hash('sha256', json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Inspect sheet columns comprehensively, categorize them, and check for SertIF Header IDs.
     */
    public function inspectSheetColumns(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        ?int $sheetId = null
    ): array {
        $discoveryService = app(SpreadsheetDiscoveryService::class);
        $result = $discoveryService->inspectTabSchema($connection, $tabName, $headerRow, $sheetId);

        if (!$result['success']) {
            return $result;
        }

        $columns = $result['columns'] ?? [];
        $requiredSystemIds = [
            'SERTIF_SYNC_ID'         => false,
            'SERTIF_REGISTRATION_ID' => false,
            'SERTIF_SYNC_STATUS'     => false,
            'SERTIF_LAST_SYNC_AT'    => false,
        ];

        $standardKeys = [
            'timestamp', 'email', 'nama', 'nama lengkap', 'no hp', 'no. hp', 'whatsapp', 'wa',
            'nim', 'nis', 'asal sekolah', 'sekolah', 'universitas', 'kampus', 'jurusan',
            'program studi', 'prodi', 'fakultas', 'jenis magang', 'sistem kerja', 'minat',
            'alasan magang', 'tgl mulai', 'tgl selesai', 'software', 'laptop', 'pembimbing'
        ];

        $seenHeaders = [];
        $categorizedColumns = [];
        $ketColumns = [];

        foreach ($columns as $col) {
            $hClean = strtoupper(trim((string) ($col['header_name'] ?? '')));
            $hLower = strtolower(trim((string) ($col['header_name'] ?? '')));
            $colLetter = $col['col_letter'] ?? '';

            $isSystem = false;
            $category = 'custom';

            if ($hClean === 'KET') {
                $ketColumns[] = $colLetter;
                if (count($ketColumns) === 1) {
                    $category = 'external';
                    $fieldKey = 'ket_external';
                } else {
                    $category = 'standard';
                    $fieldKey = 'ket_status_followup';
                }
            } elseif (isset($requiredSystemIds[$hClean]) || str_starts_with($hClean, 'SERTIF_')) {
                if (isset($requiredSystemIds[$hClean])) {
                    $requiredSystemIds[$hClean] = true;
                }
                $isSystem = true;
                $category = 'system';
            } else {
                foreach ($standardKeys as $std) {
                    if (str_contains($hLower, $std)) {
                        $category = 'standard';
                        break;
                    }
                }
            }

            $isDuplicate = isset($seenHeaders[$hClean]);
            $seenHeaders[$hClean] = true;

            if ($hClean !== 'KET') {
                $fieldKey = $isSystem ? $hClean : substr('field_' . Str::slug($col['header_name'] ?: ('col_' . $colLetter), '_'), 0, 50);
            }

            $categorizedColumns[] = [
                'col_letter'      => $colLetter,
                'col_index'       => $col['col_index'],
                'header_name'     => $col['header_name'],
                'column_identity' => $colLetter . '_' . Str::slug($col['header_name'] ?: ('col_' . $colLetter), '_'),
                'inferred_type'   => $col['inferred_type'] ?? 'string',
                'has_formula'     => $col['has_formula'] ?? false,
                'samples'         => $col['samples'] ?? [],
                'category'        => $category,
                'is_system'       => $isSystem,
                'is_duplicate'    => $isDuplicate,
                'field_key'       => $fieldKey,
            ];
        }

        $missingSystemIds = [];
        foreach ($requiredSystemIds as $sysKey => $found) {
            if (!$found) {
                $missingSystemIds[] = $sysKey;
            }
        }

        $hasDuplicateKet = count($ketColumns) > 1;

        $counts = [
            'standard'     => count(array_filter($categorizedColumns, fn($c) => $c['category'] === 'standard')),
            'custom_brand' => count(array_filter($categorizedColumns, fn($c) => $c['category'] === 'custom' || $c['category'] === 'custom_brand')),
            'system'       => count(array_filter($categorizedColumns, fn($c) => $c['category'] === 'system')),
        ];

        return [
            'success'                => true,
            'tab_name'               => $result['tab_name'] ?? $tabName,
            'header_row'             => $result['header_row'] ?? ($headerRow ?: 10),
            'total_columns'          => count($columns),
            'has_system_headers'     => empty($missingSystemIds),
            'has_system_ids'         => empty($missingSystemIds),
            'missing_system_headers' => $missingSystemIds,
            'missing_system_ids'     => $missingSystemIds,
            'has_duplicate_ket'      => $hasDuplicateKet,
            'ket_columns'            => $ketColumns,
            'columns'                => $categorizedColumns,
            'counts'                 => $counts,
            'brand_id'               => $connection->brand_id,
            'brand_name'             => $connection->brand?->name,
        ];
    }

    /**
     * Berikan / tambahkan kolom header ID SertIF ke Google Spreadsheet secara aman & idempotent.
     */
    public function injectSystemHeaderColumns(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null
    ): array {
        $effectiveHeaderRow = $headerRow ?: 10;
        $response = $this->transport->appendSystemHeaderIds($connection, $tabName, $effectiveHeaderRow);

        if (!$response->success) {
            return [
                'success' => false,
                'message' => 'Gagal menambahkan kolom Header ID ke Spreadsheet: ' . $response->errorMessage,
            ];
        }

        return [
            'success'          => true,
            'message'          => "Kolom Header ID SertIF (KET, SERTIF_SYNC_ID, dll.) berhasil ditambahkan pada baris {$effectiveHeaderRow}.",
            'appended_columns' => $response->context['appended_columns'] ?? [],
            'tab_name'         => $tabName,
            'header_row'       => $effectiveHeaderRow,
        ];
    }

    /**
     * Simpan pemetaan kolom spreadsheet dan sesuaikan ke konfigurasi form brand.
     */
    public function saveBrandColumnMapping(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        array $columnDefinitions = []
    ): array {
        $effectiveHeaderRow = $headerRow ?: 10;
        $year = (int) now()->year;

        $schemaVersion = SpreadsheetSchemaVersion::firstOrCreate(
            [
                'spreadsheet_connection_id' => $connection->id,
                'sheet_tab_name'            => $tabName,
            ],
            [
                'year'        => $year,
                'schema_hash' => hash('sha256', json_encode($columnDefinitions)),
            ]
        );

        $savedCount = 0;
        $customFieldsRegistered = [];

        foreach ($columnDefinitions as $idx => $col) {
            $headerName = trim((string) ($col['header_name'] ?? ''));
            if ($headerName === '') continue;

            $colLetter = $col['col_letter'] ?? \App\Services\SpreadsheetDiscoveryService::columnIndexToLetter($idx + 1);
            $category = $col['category'] ?? 'custom';
            $fieldKey = substr($col['field_key'] ?? ('field_' . Str::slug($headerName, '_')), 0, 50);
            $isSystem = ($category === 'system');

            $targetEntity = $isSystem ? 'spreadsheet_row_syncs' : ($category === 'standard' ? 'internship_registration' : ($category === 'external' ? 'external_sheet' : 'custom_answer'));
            $targetField = substr($fieldKey, 0, 50);
            $transformKey = null;
            $direction = $isSystem ? 'system' : 'display_only';

            if ($colLetter === 'AL' && strtoupper($headerName) === 'KET') {
                $targetEntity = 'external_sheet';
                $targetField = 'ket_external';
                $direction = 'display_only';
            } elseif ($colLetter === 'AM' && strtoupper($headerName) === 'KET') {
                $targetEntity = 'internship_registration';
                $targetField = 'ket';
                $transformKey = 'status_label';
                $direction = 'display_only';
            } elseif ($isSystem) {
                $targetField = substr(strtolower($headerName), 0, 50);
                $transformKey = substr(strtolower($headerName), 0, 50);
            } else {
                $normTransform = \App\Services\RegistrationSpreadsheetMappingService::normalizeTransformKey($fieldKey, $headerName);
                if ($normTransform !== $fieldKey) {
                    $transformKey = $normTransform;
                    $targetEntity = in_array($normTransform, ['email'], true) ? 'user' : (in_array($normTransform, ['institution_name'], true) ? 'institution' : 'internship_registration');
                    $targetField = $normTransform;
                    $direction = 'outbound';
                }
            }

            $existing = SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $schemaVersion->id)
                ->where('column_order', $idx + 1)
                ->first();

            $columnIdentity = $existing?->column_identity ?: \App\Services\SpreadsheetDiscoveryService::generatePhysicalColumnIdentity(0, $colLetter, $idx + 1, $headerName);

            SpreadsheetColumnMapping::updateOrCreate(
                [
                    'spreadsheet_schema_version_id' => $schemaVersion->id,
                    'column_order'                  => $idx + 1,
                ],
                [
                    'column_identity' => $columnIdentity,
                    'sheet_header'   => $headerName,
                    'target_entity'  => $targetEntity,
                    'target_field'   => $targetField,
                    'transform_key'  => $transformKey,
                    'direction'      => $direction,
                    'data_type'      => $col['inferred_type'] ?? 'string',
                    'mapping_status' => 'active',
                ]
            );
            $savedCount++;

            if (($category === 'custom' || $category === 'custom_brand') && !empty($fieldKey)) {
                FormFieldRegistry::firstOrCreate(
                    ['field_key' => $fieldKey],
                    [
                        'field_type'          => $col['inferred_type'] ?? 'text',
                        'canonical_label'     => $headerName,
                        'source_header_alias' => $headerName,
                        'is_locked'           => false,
                    ]
                );
                $customFieldsRegistered[] = [
                    'key'   => $fieldKey,
                    'label' => $headerName,
                ];
            }
        }

        $schemaVersion->update(['is_active' => true]);

        $existingProfile = SpreadsheetTabProfile::where('spreadsheet_connection_id', $connection->id)
            ->where(function ($q) use ($tabName) {
                $q->where('tab_name', $tabName);
            })
            ->first();

        $sheetId = $existingProfile?->sheet_id;
        if (empty($sheetId)) {
            try {
                $discoveryService = app(\App\Services\SpreadsheetDiscoveryService::class);
                $resolved = $discoveryService->resolveRemoteTabIdentity($connection, null, $tabName);
                $sheetId = $resolved['sheet_id'] ?? null;
            } catch (\Throwable $e) {
                $sheetId = null;
            }
        }

        if (empty($sheetId)) {
            $sheetId = abs(crc32($tabName)) ?: 1001;
        }

        SpreadsheetTabProfile::updateOrCreate(
            [
                'spreadsheet_connection_id' => $connection->id,
                'sheet_id'                  => $sheetId,
            ],
            [
                'table_identifier' => 'default',
                'tab_name'         => $tabName,
                'header_row'       => $effectiveHeaderRow,
                'data_start_row'   => $effectiveHeaderRow + 1,
                'column_mapping'   => $columnDefinitions,
                'last_schema_hash' => hash('sha256', json_encode($columnDefinitions)),
                'mapping_version'  => ($existingProfile?->mapping_version ?? 0) + 1,
                'is_active'        => true,
            ]
        );

        return [
            'success'                  => true,
            'message'                  => "Berhasil menyimpan {$savedCount} kolom dan menyesuaikan form untuk brand {$connection->brand?->name}.",
            'saved_count'              => $savedCount,
            'custom_fields_registered' => $customFieldsRegistered,
            'registered_custom_fields' => count($customFieldsRegistered),
            'tab_name'                 => $tabName,
        ];
    }

    /**
     * Simpan nilai kolom-kolom kustom brand ke tabel registration_custom_answers.
     */
    protected function saveCustomAnswersFromRowData(InternshipRegistration $registration, array $rowData): void
    {
        $ignoreLower = [
            'timestamp', 'email', 'nama lengkap', 'nama', 'no hp', 'no. hp', 'whatsapp', 'wa',
            'nim', 'nis', 'asal sekolah', 'sekolah', 'universitas', 'kampus', 'jurusan',
            'program studi', 'prodi', 'fakultas', 'jenis magang', 'sistem kerja', 'minat',
            'alasan magang', 'tgl mulai', 'tgl selesai', 'software', 'laptop', 'pembimbing',
            'ket', 'status'
        ];

        foreach ($rowData as $header => $val) {
            if ($val === null || $val === '') continue;
            $cleanH = strtolower(trim((string) $header));
            if (str_starts_with($cleanH, '_') || str_starts_with($cleanH, 'sertif_')) continue;
            if (in_array($cleanH, $ignoreLower, true)) continue;

            $fieldKey = 'field_' . Str::slug($header, '_');

            FormFieldRegistry::firstOrCreate(
                ['field_key' => $fieldKey],
                [
                    'field_type'          => 'text',
                    'canonical_label'     => (string) $header,
                    'source_header_alias' => (string) $header,
                    'is_locked'           => false,
                ]
            );

            RegistrationCustomAnswer::updateOrCreate(
                [
                    'registration_id' => $registration->id,
                    'field_key'       => $fieldKey,
                ],
                [
                    'answer_value'    => is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val,
                    'answer_label'    => (string) $header,
                ]
            );
        }
    }
}
