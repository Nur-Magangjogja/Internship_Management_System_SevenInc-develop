<?php

namespace App\Services;

use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\IntegrationOutboxEvent;
use App\Models\RegistrationCustomAnswer;
use App\Models\RegistrationFormSnapshot;
use App\Models\SheetDiscoveredColumn;
use App\Models\SheetDiscoverySnapshot;
use App\Models\SheetMappingProposal;
use App\Models\SheetMappingProposalItem;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetTabProfile;
use App\Models\User;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SpreadsheetDiscoveryService
{
    public const CONFIDENCE_EXACT   = 'EXACT';
    public const CONFIDENCE_HIGH    = 'HIGH';
    public const CONFIDENCE_MEDIUM  = 'MEDIUM';
    public const CONFIDENCE_LOW     = 'LOW';
    public const CONFIDENCE_NONE    = 'UNMATCHED';

    public const RESERVED_SYSTEM_KEYS = [
        'SERTIF_SYNC_ID',
        'SERTIF_REGISTRATION_ID',
        'FORM_SCHEMA_VERSION',
        'LAST_SYNC_AT',
        'sync_id',
        'registration_id',
    ];

    public function __construct(
        protected SpreadsheetTransportInterface $transport
    ) {}

    /**
     * Enumerate all tabs in the remote spreadsheet (Read-Only).
     */
    public function listTabs(SpreadsheetConnection $connection): array
    {
        $response = $this->transport->listTabs($connection);

        if (!$response->success) {
            return [
                'success'    => false,
                'error_code' => $response->errorCode ?: 'LIST_TABS_FAILED',
                'message'    => $response->errorMessage ?: 'Gagal mengambil daftar tab dari spreadsheet.',
                'tabs'       => [],
            ];
        }

        $context = $response->context;
        $rawTabs = $context['tabs'] ?? [];
        $tabs = [];

        foreach ($rawTabs as $tab) {
            $sheetId = $tab['id'] ?? null;
            $tabName = (string) ($tab['name'] ?? '');
            
            // Look up explicit profile if configured; otherwise use suggestion
            $profile = null;
            if ($sheetId !== null) {
                $profile = SpreadsheetTabProfile::where('spreadsheet_connection_id', $connection->id)
                    ->where('sheet_id', (int) $sheetId)
                    ->first();
            }

            $effectiveHeaderRow = $profile?->header_row ?? ($tab['header_row'] ?? SpreadsheetTabProfile::defaultHeaderRowSuggestion($tabName, $connection->connection_key));
            $effectiveDataStartRow = $profile?->data_start_row ?? ($effectiveHeaderRow + 1);

            $tabs[] = array_merge($tab, [
                'header_row'     => $effectiveHeaderRow,
                'data_start_row' => $effectiveDataStartRow,
                'has_profile'    => $profile !== null,
            ]);
        }

        return [
            'success'        => true,
            'spreadsheet_id' => $context['spreadsheet_id'] ?? $connection->spreadsheet_id,
            'tabs_count'     => count($tabs),
            'tabs'           => $tabs,
        ];
    }

    /**
     * Resolve explicit header & data start row for a tab.
     */
    public function resolveTabHeaderProfile(
        SpreadsheetConnection $connection,
        int|string $sheetId,
        string $tabName,
        ?int $explicitHeaderRow = null,
        ?int $explicitDataStartRow = null
    ): SpreadsheetTabProfile {
        return SpreadsheetTabProfile::resolveProfile(
            $connection,
            $sheetId,
            $tabName,
            $explicitHeaderRow,
            $explicitDataStartRow
        );
    }

    /**
     * Resolve tab identity against remote tab list, verifying sheetId and updating tabName if renamed.
     *
     * @throws DomainException if sheetId is provided but not found on remote spreadsheet
     */
    public function resolveRemoteTabIdentity(
        SpreadsheetConnection $connection,
        ?int $sheetId,
        string $tabName
    ): array {
        if ($sheetId === null) {
            return ['sheet_id' => null, 'tab_name' => $tabName];
        }

        $tabsResult = $this->listTabs($connection);
        if (!$tabsResult['success'] || empty($tabsResult['tabs'])) {
            return ['sheet_id' => $sheetId, 'tab_name' => $tabName];
        }

        $matchedTab = null;
        foreach ($tabsResult['tabs'] as $t) {
            if (isset($t['id']) && (int) $t['id'] === (int) $sheetId) {
                $matchedTab = $t;
                break;
            }
        }

        if (!$matchedTab && !empty($tabName)) {
            foreach ($tabsResult['tabs'] as $t) {
                if (isset($t['name']) && $t['name'] === $tabName) {
                    $matchedTab = $t;
                    break;
                }
            }
        }

        if (!$matchedTab) {
            throw new DomainException("INVALID_SHEET_IDENTITY: Tab dengan Sheet ID {$sheetId} tidak ditemukan pada remote Spreadsheet.");
        }

        return [
            'sheet_id' => (int) $matchedTab['id'],
            'tab_name' => !empty($matchedTab['name']) ? $matchedTab['name'] : $tabName,
        ];
    }

    /**
     * Inspect schema, headers, inferred types, and masked samples for a tab (Read-Only).
     */
    public function inspectTabSchema(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        ?int $sheetId = null
    ): array {
        $cleanTab = trim($tabName);
        if ($cleanTab === '') {
            return [
                'success'    => false,
                'error_code' => 'TAB_NAME_EMPTY',
                'message'    => 'Nama tab spreadsheet tidak boleh kosong.',
                'columns'    => [],
            ];
        }

        if ($sheetId !== null) {
            try {
                $resolved = $this->resolveRemoteTabIdentity($connection, $sheetId, $cleanTab);
                $cleanTab = $resolved['tab_name'];
                $sheetId = $resolved['sheet_id'];
            } catch (DomainException $e) {
                return [
                    'success'    => false,
                    'error_code' => 'INVALID_SHEET_IDENTITY',
                    'message'    => $e->getMessage(),
                    'columns'    => [],
                ];
            }
        }

        // Check if an explicit profile exists for this tab/sheetId
        if ($headerRow === null && $sheetId !== null) {
            $profile = SpreadsheetTabProfile::where('spreadsheet_connection_id', $connection->id)
                ->where('sheet_id', (int) $sheetId)
                ->first();
            if ($profile) {
                $headerRow = $profile->header_row;
            }
        }

        $response = $this->transport->inspectSchema($connection, $cleanTab, $headerRow);

        if (!$response->success) {
            return [
                'success'    => false,
                'error_code' => $response->errorCode ?: 'INSPECT_SCHEMA_FAILED',
                'message'    => $response->errorMessage ?: "Gagal menginspeksi schema tab '{$cleanTab}'.",
                'columns'    => [],
            ];
        }

        $context = $response->context;
        return [
            'success'       => true,
            'tab_name'      => $context['tab_name'] ?? $cleanTab,
            'sheet_id'      => $sheetId,
            'header_row'    => $context['header_row'] ?? ($headerRow ?? 1),
            'data_start_row'=> ($context['header_row'] ?? ($headerRow ?? 1)) + 1,
            'last_row'      => $context['last_row'] ?? 0,
            'last_column'   => $context['last_column'] ?? 0,
            'columns_count' => $context['columns_count'] ?? count($context['columns'] ?? []),
            'columns'       => $context['columns'] ?? [],
            'schema_hash'   => $context['schema_hash'] ?? '',
            'has_data'      => $context['has_data'] ?? false,
        ];
    }

    /**
     * Save an immutable discovery snapshot of the remote spreadsheet schema.
     */
    public function saveDiscoverySnapshot(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        ?int $sheetId = null,
        ?User $user = null
    ): SheetDiscoverySnapshot {
        if ($sheetId === null) {
            throw new DomainException("INVALID_SHEET_IDENTITY: sheet_id wajib disertakan untuk snapshot.");
        }

        $resolved = $this->resolveRemoteTabIdentity($connection, $sheetId, $tabName);
        $tabName = $resolved['tab_name'];
        $sheetId = $resolved['sheet_id'];

        $inspection = $this->inspectTabSchema($connection, $tabName, $headerRow, $sheetId);

        if (!$inspection['success']) {
            throw new DomainException($inspection['message'] ?? 'Gagal melakukan inspeksi remote spreadsheet.');
        }

        return DB::transaction(function () use ($connection, $tabName, $inspection, $sheetId, $user) {
            $snapshot = SheetDiscoverySnapshot::create([
                'spreadsheet_connection_id' => $connection->id,
                'spreadsheet_id'            => $connection->spreadsheet_id,
                'sheet_id'                  => (int) $sheetId,
                'tab_name'                  => $tabName,
                'header_row'                => $inspection['header_row'],
                'data_start_row'            => $inspection['data_start_row'],
                'schema_hash'               => $inspection['schema_hash'],
                'columns_count'             => $inspection['columns_count'],
                'scan_metadata'             => [
                    'connection_key' => $connection->connection_key,
                    'last_row'       => $inspection['last_row'],
                    'last_column'    => $inspection['last_column'],
                ],
                'scanned_by'                => $user?->id,
                'scanned_at'                => now(),
            ]);

            // Track duplicate labels for collision detection
            $labelCounts = [];
            foreach ($inspection['columns'] as $col) {
                $rawLabel = trim($col['header_name'] ?? '');
                if ($rawLabel !== '') {
                    $norm = strtolower($rawLabel);
                    $labelCounts[$norm] = ($labelCounts[$norm] ?? 0) + 1;
                }
            }

            foreach ($inspection['columns'] as $col) {
                $rawLabel = trim($col['header_name'] ?? '');
                $norm = strtolower($rawLabel);
                $isDuplicate = ($rawLabel !== '' && ($labelCounts[$norm] ?? 0) > 1);
                $isReserved = in_array(strtoupper($rawLabel), self::RESERVED_SYSTEM_KEYS, true);

                $columnIdentity = $this->generateColumnIdentity(
                    $snapshot->sheet_id,
                    $col['col_letter'],
                    $col['col_index'],
                    $rawLabel
                );

                SheetDiscoveredColumn::create([
                    'snapshot_id'        => $snapshot->id,
                    'column_identity'    => $columnIdentity,
                    'col_letter'         => $col['col_letter'],
                    'physical_index'     => $col['col_index'],
                    'observed_label'     => $rawLabel,
                    'inferred_type'      => $col['inferred_type'] ?? 'string',
                    'has_formula'        => (bool) ($col['has_formula'] ?? false),
                    'sample_values'      => $col['samples'] ?? [],
                    'is_duplicate_label' => $isDuplicate,
                    'is_system_reserved' => $isReserved,
                ]);
            }

            return $snapshot->load('columns');
        });
    }

    /**
     * Create a lightweight Draft Mapping Proposal (C05).
     */
    public function createDraftMappingProposal(
        SpreadsheetConnection $connection,
        string $sheetTab,
        array $proposedMappings,
        ?User $actor = null
    ): SheetMappingProposal {
        $snapshot = SheetDiscoverySnapshot::firstOrCreate(
            [
                'spreadsheet_connection_id' => $connection->id,
                'tab_name'                  => $sheetTab,
            ],
            [
                'spreadsheet_id' => $connection->spreadsheet_id ?? 'default',
                'sheet_id'       => 0,
                'header_row'     => 1,
                'data_start_row' => 2,
                'schema_hash'    => hash('sha256', $sheetTab),
                'columns_count'  => count($proposedMappings),
                'scanned_by'     => $actor?->id,
            ]
        );

        return SheetMappingProposal::create([
            'spreadsheet_connection_id' => $connection->id,
            'snapshot_id'               => $snapshot->id,
            'expected_schema_hash'      => $snapshot->schema_hash ?? hash('sha256', $sheetTab),
            'status'                    => SheetMappingProposal::STATUS_DRAFT,
            'year'                      => (int) date('Y'),
            'proposed_by'               => $actor?->id,
            'notes'                     => json_encode($proposedMappings),
        ]);
    }

    /**
     * Create a formal Mapping Proposal from a discovery snapshot.
     */
    public function createMappingProposal(
        SpreadsheetConnection $connection,
        SheetDiscoverySnapshot $snapshot,
        ?int $year = null,
        ?FormSchema $targetSchema = null,
        ?User $user = null
    ): SheetMappingProposal {
        return DB::transaction(function () use ($connection, $snapshot, $year, $targetSchema, $user) {
            $formFields = $this->getAvailableFormFields($targetSchema);

            $proposal = SheetMappingProposal::create([
                'spreadsheet_connection_id' => $connection->id,
                'snapshot_id'               => $snapshot->id,
                'expected_schema_hash'      => $snapshot->schema_hash,
                'year'                      => $year ?? ($snapshot->header_row === 12 ? (int) $snapshot->tab_name : (int) now()->year),
                'form_schema_id'            => $targetSchema?->id,
                'version'                   => 1,
                'status'                    => SheetMappingProposal::STATUS_DRAFT,
                'proposed_by'               => $user?->id,
            ]);

            $columns = $snapshot->columns;
            $seenFieldKeys = [];

            foreach ($columns as $col) {
                $rawLabel = trim($col->observed_label);

                // 1. Reserved System Fields
                if ($col->is_system_reserved) {
                    SheetMappingProposalItem::create([
                        'proposal_id'          => $proposal->id,
                        'discovered_column_id' => $col->id,
                        'column_identity'      => $col->column_identity,
                        'col_letter'           => $col->col_letter,
                        'physical_index'       => $col->physical_index,
                        'observed_label'       => $rawLabel,
                        'action'               => SheetMappingProposalItem::ACTION_SYSTEM_PROTECTED,
                        'field_key'            => strtolower($rawLabel),
                        'suggested_label'      => $rawLabel,
                        'suggested_field_type' => 'system',
                        'confidence_score'     => 1.00,
                        'confidence_level'     => self::CONFIDENCE_EXACT,
                        'match_reason'         => 'Kolom terproteksi sistem internal SERTIF (immutable).',
                        'has_collision'        => false,
                    ]);
                    continue;
                }

                // 2. Duplicate Header Collision (F2-03)
                if ($col->is_duplicate_label) {
                    SheetMappingProposalItem::create([
                        'proposal_id'          => $proposal->id,
                        'discovered_column_id' => $col->id,
                        'column_identity'      => $col->column_identity,
                        'col_letter'           => $col->col_letter,
                        'physical_index'       => $col->physical_index,
                        'observed_label'       => $rawLabel,
                        'action'               => SheetMappingProposalItem::ACTION_IGNORE,
                        'field_key'            => null,
                        'suggested_label'      => $rawLabel,
                        'suggested_field_type' => null,
                        'confidence_score'     => 0.00,
                        'confidence_level'     => self::CONFIDENCE_NONE,
                        'match_reason'         => 'Konflik collision: Ditemukan header ganda dengan teks persis sama.',
                        'has_collision'        => true,
                        'collision_detail'     => "Header '{$rawLabel}' muncul lebih dari satu kali pada tab ini.",
                    ]);
                    continue;
                }

                // 3. Empty Header
                if ($rawLabel === '') {
                    SheetMappingProposalItem::create([
                        'proposal_id'          => $proposal->id,
                        'discovered_column_id' => $col->id,
                        'column_identity'      => $col->column_identity,
                        'col_letter'           => $col->col_letter,
                        'physical_index'       => $col->physical_index,
                        'observed_label'       => '',
                        'action'               => SheetMappingProposalItem::ACTION_IGNORE,
                        'field_key'            => null,
                        'suggested_label'      => null,
                        'suggested_field_type' => null,
                        'confidence_score'     => 0.00,
                        'confidence_level'     => self::CONFIDENCE_NONE,
                        'match_reason'         => 'Kolom tidak memiliki teks header.',
                        'has_collision'        => false,
                    ]);
                    continue;
                }

                // 4. Normal Field Matching
                $match = $this->findBestFieldMatch($rawLabel, $formFields);

                // Check collision if same field_key matched multiple columns
                $hasKeyCollision = false;
                $collisionDetail = null;
                if (!empty($match['field_key'])) {
                    if (isset($seenFieldKeys[$match['field_key']])) {
                        $hasKeyCollision = true;
                        $collisionDetail = "Field key '{$match['field_key']}' telah terpetakan pada kolom {$seenFieldKeys[$match['field_key']]}.";
                    } else {
                        $seenFieldKeys[$match['field_key']] = $col->col_letter;
                    }
                }

                $action = SheetMappingProposalItem::ACTION_IGNORE;
                if ($hasKeyCollision) {
                    $action = SheetMappingProposalItem::ACTION_IGNORE;
                } elseif ($match['confidence_level'] === self::CONFIDENCE_EXACT || $match['confidence_level'] === self::CONFIDENCE_HIGH) {
                    $action = SheetMappingProposalItem::ACTION_LINK_EXISTING;
                } elseif ($match['confidence_level'] === self::CONFIDENCE_NONE) {
                    // Unknown header: defaults to unmapped / external-only (F2-04)
                    $action = SheetMappingProposalItem::ACTION_EXTERNAL_ONLY;
                }

                SheetMappingProposalItem::create([
                    'proposal_id'          => $proposal->id,
                    'discovered_column_id' => $col->id,
                    'column_identity'      => $col->column_identity,
                    'col_letter'           => $col->col_letter,
                    'physical_index'       => $col->physical_index,
                    'observed_label'       => $rawLabel,
                    'action'               => $action,
                    'field_key'            => $hasKeyCollision ? null : $match['field_key'],
                    'suggested_label'      => $match['label'] ?? $rawLabel,
                    'suggested_field_type' => $match['field_type'] ?? 'text',
                    'confidence_score'     => $hasKeyCollision ? 0.00 : $match['confidence_score'],
                    'confidence_level'     => $hasKeyCollision ? self::CONFIDENCE_NONE : $match['confidence_level'],
                    'match_reason'         => $hasKeyCollision ? $collisionDetail : $match['match_reason'],
                    'has_collision'        => $hasKeyCollision,
                    'collision_detail'     => $collisionDetail,
                ]);
            }

            return $proposal->load(['items', 'snapshot.columns']);
        });
    }

    /**
     * Preview non-destructive schema diff between current FormSchema and Proposal.
     * Evaluates ADD_COLUMN, LINK_EXISTING, RENAME_DISPLAY, ARCHIVE, CONFLICT, NOOP.
     * NEVER produces DELETE_COLUMN.
     */
    public function previewSchemaDiff(SheetMappingProposal $proposal, ?FormSchema $targetSchema = null): array
    {
        $schema = $targetSchema ?? $proposal->formSchema ?? FormSchema::where('status', FormSchema::STATUS_PUBLISHED)->latest('id')->first();
        $schemaFields = $schema ? $schema->fields()->get()->keyBy('field_key') : collect();

        $diffItems = [];
        $counts = [
            'add'      => 0,
            'link'     => 0,
            'rename'   => 0,
            'archive'  => 0,
            'conflict' => 0,
            'noop'     => 0,
        ];

        $proposalItems = $proposal->items()->get();
        $coveredFieldKeys = [];

        foreach ($proposalItems as $item) {
            if ($item->has_collision) {
                $counts['conflict']++;
                $diffItems[] = [
                    'diff_type'       => 'CONFLICT',
                    'col_letter'      => $item->col_letter,
                    'observed_label'  => $item->observed_label,
                    'field_key'       => $item->field_key,
                    'reason'          => $item->collision_detail ?: 'Konflik pada kolom spreadsheet.',
                    'action'          => $item->action,
                ];
                continue;
            }

            if ($item->action === SheetMappingProposalItem::ACTION_SYSTEM_PROTECTED) {
                $counts['noop']++;
                $diffItems[] = [
                    'diff_type'      => 'NOOP',
                    'col_letter'     => $item->col_letter,
                    'observed_label' => $item->observed_label,
                    'field_key'      => $item->field_key,
                    'reason'         => 'Kolom sistem dilindungi (read-only immutable).',
                    'action'         => $item->action,
                ];
                continue;
            }

            if ($item->action === SheetMappingProposalItem::ACTION_EXTERNAL_ONLY || $item->action === SheetMappingProposalItem::ACTION_IGNORE) {
                $counts['noop']++;
                $diffItems[] = [
                    'diff_type'      => 'NOOP',
                    'col_letter'     => $item->col_letter,
                    'observed_label' => $item->observed_label,
                    'field_key'      => null,
                    'reason'         => 'Kolom eksternal spreadsheet tidak dimasukkan ke Form Builder.',
                    'action'         => $item->action,
                ];
                continue;
            }

            if ($item->action === SheetMappingProposalItem::ACTION_CREATE_DRAFT) {
                $counts['add']++;
                $diffItems[] = [
                    'diff_type'      => 'ADD_COLUMN',
                    'col_letter'     => $item->col_letter,
                    'observed_label' => $item->observed_label,
                    'field_key'      => $item->field_key,
                    'label'          => $item->suggested_label,
                    'field_type'     => $item->suggested_field_type,
                    'reason'         => 'Akan dibuatkan field baru di draf Form Builder.',
                    'action'         => $item->action,
                ];
                $coveredFieldKeys[$item->field_key] = true;
                continue;
            }

            if ($item->action === SheetMappingProposalItem::ACTION_LINK_EXISTING && $item->field_key) {
                $coveredFieldKeys[$item->field_key] = true;
                $counts['link']++;
                $diffItems[] = [
                    'diff_type'      => 'LINK_EXISTING',
                    'col_letter'     => $item->col_letter,
                    'observed_label' => $item->observed_label,
                    'field_key'      => $item->field_key,
                    'reason'         => 'Terhubung dengan field Form Builder yang sudah ada (label Form Builder tetap dipertahankan).',
                    'action'         => $item->action,
                ];
            }
        }

        // Check for schema fields that are NOT in the spreadsheet: Candidate for ARCHIVE (NEVER delete)
        foreach ($schemaFields as $key => $field) {
            if (!$field->is_system && !isset($coveredFieldKeys[$key])) {
                $counts['archive']++;
                $diffItems[] = [
                    'diff_type'      => 'ARCHIVE',
                    'col_letter'     => '-',
                    'observed_label' => '-',
                    'field_key'      => $key,
                    'label'          => $field->label,
                    'reason'         => 'Field di Form Builder tidak ditemukan di spreadsheet; hanya dinonaktifkan/diarsipkan, data historis tetap aman.',
                    'action'         => 'archive_candidate',
                ];
            }
        }

        $canApply = ($counts['conflict'] === 0);

        return [
            'summary'     => $counts,
            'can_apply'   => $canApply,
            'items'       => $diffItems,
            'schema_info' => [
                'form_schema_id'   => $schema?->id,
                'version_code'     => $schema?->version_code,
                'is_draft'         => $schema ? $schema->isDraft() : false,
            ],
        ];
    }

    /**
     * Apply an approved proposal to a draft FormSchema.
     * Enforces optimistic version lock and schema-drift protection.
     */
    public function applyProposalToDraft(
        SheetMappingProposal $proposal,
        FormSchema $draftSchema,
        User $user
    ): array {
        if (!$draftSchema->isDraft()) {
            throw new DomainException("Hanya skema berstatus draft yang dapat menerima usulan mapping.");
        }

        // Schema Drift Guard: Verify expected schema hash
        if (empty($proposal->expected_schema_hash)) {
            throw new DomainException("SCHEMA_CONFLICT: expected_schema_hash tidak boleh kosong.");
        }

        $latestSnapshot = SheetDiscoverySnapshot::where('spreadsheet_connection_id', $proposal->spreadsheet_connection_id)
            ->where(function ($q) use ($proposal) {
                if ($proposal->snapshot && $proposal->snapshot->sheet_id !== null) {
                    $q->where('sheet_id', $proposal->snapshot->sheet_id);
                } else {
                    $q->where('tab_name', $proposal->snapshot?->tab_name);
                }
            })
            ->latest('scanned_at')
            ->first();

        if ($latestSnapshot && $latestSnapshot->schema_hash !== $proposal->expected_schema_hash) {
            $proposal->update([
                'status'          => SheetMappingProposal::STATUS_CONFLICT,
                'conflict_reason' => "SCHEMA_CONFLICT: Struktur header remote telah berubah sejak snapshot ini dibuat (expected: {$proposal->expected_schema_hash}, actual: {$latestSnapshot->schema_hash}). Lakukan scan ulang.",
            ]);
            throw new DomainException("SCHEMA_CONFLICT: Hash remote spreadsheet telah berubah. Lakukan scan ulang sebelum mengaplikasikan mapping.");
        }

        return DB::transaction(function () use ($proposal, $draftSchema, $user) {
            // Optimistic lock check
            $lockedProposal = SheetMappingProposal::where('id', $proposal->id)->lockForUpdate()->first();
            if ($lockedProposal->version !== $proposal->version) {
                throw new DomainException("OPTIMISTIC_LOCK_CONFLICT: Usulan mapping telah diubah oleh admin lain. Silakan muat ulang.");
            }

            $items = $proposal->items()->get();
            $appliedCount = 0;
            $createdCount = 0;

            foreach ($items as $item) {
                if ($item->has_collision || $item->action === SheetMappingProposalItem::ACTION_IGNORE || $item->action === SheetMappingProposalItem::ACTION_SYSTEM_PROTECTED) {
                    continue;
                }

                if ($item->action === SheetMappingProposalItem::ACTION_CREATE_DRAFT) {
                    $fieldKey = $item->field_key ?: Str::slug($item->observed_label, '_');
                    
                    $fieldType = $item->suggested_field_type ?: 'text';
                    if (!array_key_exists($fieldType, FormField::supportedTypes())) {
                        $fieldType = 'text';
                    }

                    // Ensure unique key in draft schema
                    $existing = $draftSchema->fields()->where('field_key', $fieldKey)->first();
                    if (!$existing) {
                        FormField::create([
                            'form_schema_id'   => $draftSchema->id,
                            'field_key'        => $fieldKey,
                            'field_type'       => $fieldType,
                            'label'            => $item->suggested_label ?: $item->observed_label,
                            'group_name'       => 'Pendaftaran',
                            'is_system'        => false,
                            'is_active'        => true,
                            'is_archived'      => false,
                            'is_required'      => false,
                        ]);
                        $createdCount++;
                    }
                    $appliedCount++;
                }

                if ($item->action === SheetMappingProposalItem::ACTION_LINK_EXISTING && $item->field_key) {
                    $field = $draftSchema->fields()->where('field_key', $item->field_key)->first();
                    if ($field) {
                        // P1.4: link_existing hanya membentuk relasi ke field_key sah, label Form Builder tidak diubah
                        $appliedCount++;
                    }
                }
            }

            $proposal->update([
                'status'         => SheetMappingProposal::STATUS_APPROVED_TO_DRAFT,
                'form_schema_id' => $draftSchema->id,
                'approved_by'    => $user->id,
                'approved_at'    => now(),
                'applied_at'     => now(),
                'version'        => $proposal->version + 1,
            ]);

            return [
                'success'       => true,
                'proposal_id'   => $proposal->id,
                'status'        => SheetMappingProposal::STATUS_APPROVED_TO_DRAFT,
                'applied_count' => $appliedCount,
                'created_count' => $createdCount,
                'schema_id'     => $draftSchema->id,
                'version_code'  => $draftSchema->version_code,
            ];
        });
    }

    /**
     * Read preview rows with PII masking and bounded pagination.
     */
    public function previewRows(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        int $page = 1,
        int $pageSize = 20
    ): array {
        $cleanTab = trim($tabName);
        if ($cleanTab === '') {
            return [
                'success'    => false,
                'error_code' => 'TAB_NAME_EMPTY',
                'message'    => 'Nama tab spreadsheet tidak boleh kosong.',
                'rows'       => [],
            ];
        }

        $response = $this->transport->readRowsPage(
            $connection,
            $cleanTab,
            $headerRow,
            max(1, $page),
            min(100, max(1, $pageSize))
        );

        if (!$response->success) {
            return [
                'success'    => false,
                'error_code' => $response->errorCode ?: 'READ_ROWS_FAILED',
                'message'    => $response->errorMessage ?: "Gagal membaca baris data dari tab '{$cleanTab}'.",
                'rows'       => [],
            ];
        }

        $context = $response->context;
        return [
            'success'     => true,
            'tab_name'    => $context['tab_name'] ?? $cleanTab,
            'header_row'  => $context['header_row'] ?? 1,
            'page'        => $context['page'] ?? $page,
            'page_size'   => $context['page_size'] ?? $pageSize,
            'total_rows'  => $context['total_rows'] ?? 0,
            'total_pages' => $context['total_pages'] ?? 0,
            'has_more'    => $context['has_more'] ?? false,
            'next_page'   => $context['next_page'] ?? null,
            'rows'        => $context['rows'] ?? [],
        ];
    }

    /**
     * Generate draft mapping proposals between scanned spreadsheet headers and Form Builder fields.
     */
    public function generateDraftMappingProposals(
        SpreadsheetConnection $connection,
        string $tabName,
        ?int $headerRow = null,
        ?int $sheetId = null
    ): array {
        $inspection = $this->inspectTabSchema($connection, $tabName, $headerRow, $sheetId);

        if (!$inspection['success']) {
            return [
                'success'    => false,
                'error_code' => $inspection['error_code'],
                'message'    => $inspection['message'],
                'proposals'  => [],
            ];
        }

        $scannedColumns = $inspection['columns'] ?? [];
        $formFields = $this->getAvailableFormFields();

        // Detect duplicate header collisions across scanned columns
        $labelCounts = [];
        foreach ($scannedColumns as $col) {
            $rawHeader = trim($col['header_name'] ?? '');
            if ($rawHeader !== '') {
                $norm = strtolower($rawHeader);
                $labelCounts[$norm] = ($labelCounts[$norm] ?? 0) + 1;
            }
        }

        $proposals = [];
        $matchedCount = 0;
        $unmatchedCount = 0;
        $conflictCount = 0;

        foreach ($scannedColumns as $col) {
            $headerName = trim($col['header_name'] ?? '');
            $colLetter  = $col['col_letter'] ?? '';
            $colIndex   = $col['col_index'] ?? 0;

            if ($headerName === '') {
                $proposals[] = [
                    'col_index'               => $colIndex,
                    'col_letter'              => $colLetter,
                    'scanned_header'          => '',
                    'suggested_field_key'     => null,
                    'suggested_field_label'   => null,
                    'suggested_field_type'    => null,
                    'is_system'               => false,
                    'confidence_score'        => 0.0,
                    'confidence_level'        => self::CONFIDENCE_NONE,
                    'match_reason'            => 'Kolom tidak memiliki teks header.',
                    'has_collision'           => false,
                    'sample_values'           => $col['samples'] ?? [],
                ];
                $unmatchedCount++;
                continue;
            }

            // Check for collision (duplicate header text)
            $norm = strtolower($headerName);
            if (($labelCounts[$norm] ?? 0) > 1) {
                $conflictCount++;
                $proposals[] = [
                    'col_index'             => $colIndex,
                    'col_letter'            => $colLetter,
                    'scanned_header'        => $headerName,
                    'suggested_field_key'   => null,
                    'suggested_field_label' => $headerName,
                    'suggested_field_type'  => null,
                    'is_system'             => false,
                    'confidence_score'      => 0.0,
                    'confidence_level'      => self::CONFIDENCE_NONE,
                    'match_reason'          => "Konflik: Ditemukan {$labelCounts[$norm]} kolom dengan nama header persis sama ('{$headerName}'). Wajib penanganan manual.",
                    'has_collision'         => true,
                    'sample_values'         => $col['samples'] ?? [],
                ];
                continue;
            }

            $bestMatch = $this->findBestFieldMatch($headerName, $formFields);

            if ($bestMatch['confidence_level'] !== self::CONFIDENCE_NONE) {
                $matchedCount++;
            } else {
                $unmatchedCount++;
            }

            $proposals[] = [
                'col_index'             => $colIndex,
                'col_letter'            => $colLetter,
                'scanned_header'        => $headerName,
                'suggested_field_key'   => $bestMatch['field_key'],
                'suggested_field_label' => $bestMatch['label'],
                'suggested_field_type'  => $bestMatch['field_type'],
                'is_system'             => $bestMatch['is_system'],
                'confidence_score'      => $bestMatch['confidence_score'],
                'confidence_level'      => $bestMatch['confidence_level'],
                'match_reason'          => $bestMatch['match_reason'],
                'has_collision'         => false,
                'sample_values'         => $col['samples'] ?? [],
            ];
        }

        return [
            'success'         => true,
            'tab_name'        => $inspection['tab_name'],
            'header_row'      => $inspection['header_row'],
            'schema_hash'     => $inspection['schema_hash'],
            'total_scanned'   => count($scannedColumns),
            'matched_count'   => $matchedCount,
            'unmatched_count' => $unmatchedCount,
            'conflict_count'  => $conflictCount,
            'proposals'       => $proposals,
        ];
    }

    /**
     * Generate opaque, deterministic column identity.
     */
    protected function generateColumnIdentity(int|string $sheetId, string $colLetter, int $colIndex, string $label): string
    {
        $raw = "col_{$sheetId}_{$colLetter}_{$colIndex}_" . Str::slug($label, '_');
        return 'cid_' . substr(hash('sha256', $raw), 0, 16);
    }

    /**
     * Retrieve all available form fields from active/published FormSchemas and Registry.
     */
    protected function getAvailableFormFields(?FormSchema $targetSchema = null): array
    {
        $fields = [];
        $seenKeys = [];

        $schema = $targetSchema ?? FormSchema::where('status', FormSchema::STATUS_PUBLISHED)->latest('published_at')->first() ?? FormSchema::latest('id')->first();

        if ($schema) {
            $schemaFields = $schema->fields()->get();
            foreach ($schemaFields as $f) {
                if (!isset($seenKeys[$f->field_key])) {
                    $fields[] = [
                        'field_key'   => $f->field_key,
                        'label'       => $f->label,
                        'field_type'  => $f->field_type,
                        'is_system'   => (bool) $f->is_system,
                        'group_name'  => $f->group_name,
                    ];
                    $seenKeys[$f->field_key] = true;
                }
            }
        }

        $registries = FormFieldRegistry::all();
        foreach ($registries as $reg) {
            if (!isset($seenKeys[$reg->field_key])) {
                $fields[] = [
                    'field_key'   => $reg->field_key,
                    'label'       => $reg->canonical_label,
                    'field_type'  => $reg->field_type,
                    'is_system'   => false,
                    'group_name'  => 'Pendaftaran',
                ];
                $seenKeys[$reg->field_key] = true;
            }
        }

        $systemCore = [
            ['field_key' => 'name', 'label' => 'Nama Lengkap', 'field_type' => 'text', 'is_system' => true],
            ['field_key' => 'email', 'label' => 'Email', 'field_type' => 'email', 'is_system' => true],
            ['field_key' => 'phone', 'label' => 'No WhatsApp', 'field_type' => 'phone', 'is_system' => true],
            ['field_key' => 'institution', 'label' => 'Asal Sekolah / Kampus', 'field_type' => 'text', 'is_system' => true],
            ['field_key' => 'major', 'label' => 'Jurusan', 'field_type' => 'text', 'is_system' => true],
            ['field_key' => 'division_id', 'label' => 'Divisi Magang', 'field_type' => 'division', 'is_system' => true],
            ['field_key' => 'start_date', 'label' => 'Tanggal Mulai', 'field_type' => 'date', 'is_system' => true],
            ['field_key' => 'end_date', 'label' => 'Tanggal Selesai', 'field_type' => 'date', 'is_system' => true],
            ['field_key' => 'internship_type', 'label' => 'Jenis Magang', 'field_type' => 'select', 'is_system' => true],
            ['field_key' => 'notes', 'label' => 'Catatan Tambahan', 'field_type' => 'textarea', 'is_system' => true],
        ];

        foreach ($systemCore as $sc) {
            if (!isset($seenKeys[$sc['field_key']])) {
                $fields[] = array_merge($sc, ['group_name' => 'Data Utama']);
                $seenKeys[$sc['field_key']] = true;
            }
        }

        return $fields;
    }

    /**
     * Find best field match for a given raw spreadsheet header.
     */
    protected function findBestFieldMatch(string $header, array $formFields): array
    {
        $cleanHeader = trim($header);
        $normalizedHeader = $this->normalizeString($cleanHeader);

        // 1. Exact match on field_key or canonical label
        foreach ($formFields as $field) {
            $fieldKeyNorm = $this->normalizeString($field['field_key']);
            $fieldLabelNorm = $this->normalizeString($field['label']);

            if ($normalizedHeader === $fieldKeyNorm || $normalizedHeader === $fieldLabelNorm) {
                return [
                    'field_key'        => $field['field_key'],
                    'label'            => $field['label'],
                    'field_type'       => $field['field_type'],
                    'is_system'        => $field['is_system'],
                    'confidence_score' => 1.00,
                    'confidence_level' => self::CONFIDENCE_EXACT,
                    'match_reason'     => "Pencocokan persis pada label/kunci field ('{$field['label']}').",
                ];
            }
        }

        // 2. High confidence: common synonyms / alias dictionary
        $aliasMap = [
            'name'            => ['nama', 'nama lengkap', 'nama pemagang', 'nama peserta', 'nama mahasiswa', 'nama siswa'],
            'email'           => ['email', 'surel', 'alamat email', 'e-mail'],
            'phone'           => ['wa', 'whatsapp', 'no wa', 'nomor wa', 'nomor whatsapp', 'nomor telepon', 'no hp', 'telepon', 'hp'],
            'institution'     => ['sekolah', 'asal sekolah', 'universitas', 'asal kampus', 'institusi', 'asal instansi', 'nama sekolah', 'nama kampus'],
            'major'           => ['jurusan', 'program studi', 'prodi', 'keahlian', 'kompetensi keahlian'],
            'division_id'     => ['divisi', 'pilihan divisi', 'divisi magang', 'posisi magang', 'posisi'],
            'start_date'      => ['tanggal mulai', 'tgl mulai', 'start date', 'periode mulai'],
            'end_date'        => ['tanggal selesai', 'tgl selesai', 'end date', 'periode selesai'],
            'internship_type' => ['jenis magang', 'tipe magang', 'kategori magang'],
            'notes'           => ['catatan', 'keterangan', 'notes', 'informasi tambahan'],
        ];

        foreach ($aliasMap as $key => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalizedHeader === $this->normalizeString($alias)) {
                    $matchedField = collect($formFields)->firstWhere('field_key', $key);
                    if ($matchedField) {
                        return [
                            'field_key'        => $matchedField['field_key'],
                            'label'            => $matchedField['label'],
                            'field_type'       => $matchedField['field_type'],
                            'is_system'        => $matchedField['is_system'],
                            'confidence_score' => 0.85,
                            'confidence_level' => self::CONFIDENCE_HIGH,
                            'match_reason'     => "Pencocokan sinonim umum ('{$alias}' -> '{$matchedField['label']}').",
                        ];
                    }
                }
            }
        }

        // 3. Medium confidence: substring or word inclusion
        foreach ($formFields as $field) {
            $labelNorm = $this->normalizeString($field['label']);
            if (strlen($labelNorm) >= 4 && (str_contains($normalizedHeader, $labelNorm) || str_contains($labelNorm, $normalizedHeader))) {
                return [
                    'field_key'        => $field['field_key'],
                    'label'            => $field['label'],
                    'field_type'       => $field['field_type'],
                    'is_system'        => $field['is_system'],
                    'confidence_score' => 0.60,
                    'confidence_level' => self::CONFIDENCE_MEDIUM,
                    'match_reason'     => "Kemiripan teks frasa ('{$cleanHeader}' mendekati '{$field['label']}').",
                ];
            }
        }

        // 4. Low confidence: word overlap
        $headerWords = explode(' ', $normalizedHeader);
        foreach ($formFields as $field) {
            $labelWords = explode(' ', $this->normalizeString($field['label']));
            $intersect = array_intersect($headerWords, $labelWords);
            if (count($intersect) > 0 && reset($intersect) !== '') {
                return [
                    'field_key'        => $field['field_key'],
                    'label'            => $field['label'],
                    'field_type'       => $field['field_type'],
                    'is_system'        => $field['is_system'],
                    'confidence_score' => 0.30,
                    'confidence_level' => self::CONFIDENCE_LOW,
                    'match_reason'     => "Ditemukan kata beririsan: '" . implode(', ', $intersect) . "'.",
                ];
            }
        }

        // 5. Unmatched
        return [
            'field_key'        => null,
            'label'            => null,
            'field_type'       => null,
            'is_system'        => false,
            'confidence_score' => 0.00,
            'confidence_level' => self::CONFIDENCE_NONE,
            'match_reason'     => 'Tidak ditemukan field Form Builder yang cocok.',
        ];
    }

    /**
     * Normalize string for comparison (lowercase, remove symbols).
     */
    protected function normalizeString(string $str): string
    {
        $clean = strtolower(trim($str));
        $clean = preg_replace('/[_\-\/\\\.,\(\)]+/', ' ', $clean);
        return trim(preg_replace('/\s+/', ' ', $clean));
    }
}
