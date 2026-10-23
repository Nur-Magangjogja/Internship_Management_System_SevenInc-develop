<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SheetInboundChangeStaging;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Services\SpreadsheetConnectionService;
use App\Services\SpreadsheetColumnDeletionEligibilityService;
use App\Services\SpreadsheetInboundSyncService;
use App\Services\SpreadsheetSyncEngineService;
use DomainException;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class SpreadsheetConnectionController extends Controller
{
    public function __construct(
        protected SpreadsheetConnectionService $service
    ) {}

    /**
     * Tampilkan halaman daftar konfigurasi koneksi Google Spreadsheet.
     */
    public function index()
    {
        $connections = $this->service->getAllConnections();

        return view('admin.spreadsheet-connections.index', compact('connections'));
    }

    /**
     * Tampilkan detail konfigurasi, riwayat versi schema, pemetaan kolom, dan status sync baris (Read-Only).
     */
    public function show(Request $request, SpreadsheetConnection $spreadsheetConnection)
    {
        $spreadsheetConnection->refresh()->load(['schemaVersions.columnMappings', 'createdByUser', 'updatedByUser']);

        $rowSyncs = SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)
            ->latest('id')
            ->paginate(15);

        $stats = [
            'total'      => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->count(),
            'synced'     => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->where('sync_status', SpreadsheetRowSync::STATUS_SYNCED)->count(),
            'pending'    => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->where('sync_status', SpreadsheetRowSync::STATUS_PENDING)->count(),
            'retrying'   => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->where('sync_status', SpreadsheetRowSync::STATUS_RETRYING)->count(),
            'failed'     => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->where('sync_status', SpreadsheetRowSync::STATUS_FAILED)->count(),
        ];

        $tabProfiles = $spreadsheetConnection->tabProfiles()->get();
        $recentSnapshots = $spreadsheetConnection->discoverySnapshots()->limit(5)->get();
        $recentProposals = $spreadsheetConnection->mappingProposals()->with(['items', 'formSchema'])->limit(5)->get();
        $outboxEvents = $spreadsheetConnection->outboxEvents()->limit(10)->get();

        $activeProposal = $recentProposals->first();
        $targetSchema = $activeProposal?->formSchema;
        if (!$targetSchema && $activeProposal?->year) {
            $targetSchema = \App\Models\FormSchema::where('year', $activeProposal->year)
                ->where('status', \App\Models\FormSchema::STATUS_PUBLISHED)
                ->latest('published_at')
                ->first();
        }

        $discoveryService = app(\App\Services\SpreadsheetDiscoveryService::class);
        $availableFields = $discoveryService->getAvailableFormFields($targetSchema);

        // Jika ada item proposal yang telah terhubung ke field_key tertentu, pastikan tetap ada dalam opsi pilihan
        if ($activeProposal) {
            $existingKeys = collect($availableFields)->pluck('field_key')->all();
            foreach ($activeProposal->items as $item) {
                if ($item->field_key && !in_array($item->field_key, $existingKeys, true)) {
                    $availableFields[] = [
                        'field_key'   => $item->field_key,
                        'label'       => $item->suggested_label ?: $item->field_key,
                        'field_type'  => $item->suggested_field_type ?: 'text',
                        'is_system'   => false,
                        'group_name'  => 'Tersimpan',
                    ];
                    $existingKeys[] = $item->field_key;
                }
            }
        }

        $formFields = collect($availableFields)->map(function ($f) {
            return (object) [
                'id'          => $f['field_key'],
                'field_key'   => $f['field_key'],
                'label'       => $f['label'] ?? $f['field_key'],
                'field_label' => $f['label'] ?? $f['field_key'],
                'field_type'  => $f['field_type'] ?? 'text',
                'is_system'   => $f['is_system'] ?? false,
                'group_name'  => $f['group_name'] ?? 'Lainnya',
            ];
        })->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $activeSchemaVersions = $spreadsheetConnection->schemaVersions()
            ->where('is_active', true)
            ->with(['columnMappings' => fn($q) => $q->orderBy('column_order', 'asc')])
            ->orderBy('year', 'desc')
            ->get();

        if ($activeSchemaVersions->isEmpty()) {
            $activeSchemaVersions = $spreadsheetConnection->schemaVersions()
                ->with(['columnMappings' => fn($q) => $q->orderBy('column_order', 'asc')])
                ->orderBy('year', 'desc')
                ->orderBy('id', 'desc')
                ->take(1)
                ->get();
        }

        $totalSchemaCount = $spreadsheetConnection->schemaVersions()->count();

        return view('admin.spreadsheet-connections.show', [
            'connection'       => $spreadsheetConnection,
            'schemaVersions'   => $activeSchemaVersions,
            'totalSchemaCount' => $totalSchemaCount,
            'rowSyncs'         => $rowSyncs,
            'stats'            => $stats,
            'tabProfiles'      => $tabProfiles,
            'recentSnapshots'  => $recentSnapshots,
            'recentProposals'  => $recentProposals,
            'outboxEvents'     => $outboxEvents,
            'formFields'       => $formFields,
        ]);
    }

    /**
     * Form edit konfigurasi koneksi spreadsheet (Read-Only).
     */
    public function edit(SpreadsheetConnection $spreadsheetConnection)
    {
        return view('admin.spreadsheet-connections.edit', [
            'connection' => $spreadsheetConnection->fresh(),
        ]);
    }

    /**
     * Simpan perubahan konfigurasi koneksi.
     */
    public function update(Request $request, SpreadsheetConnection $spreadsheetConnection)
    {
        $validated = $request->validate([
            'display_name'             => 'required|string|max:255',
            'spreadsheet_url'          => 'nullable|string|max:500',
            'apps_script_endpoint_url' => 'nullable|url|max:500',
            'drive_folder_url'         => 'nullable|string|max:500',
        ]);

        try {
            $this->service->updateConnection($spreadsheetConnection, $validated, Auth::user());

            return redirect()
                ->route('admin.spreadsheet-connections.index')
                ->with('success', "Konfigurasi koneksi '{$spreadsheetConnection->display_name}' berhasil diperbarui.");
        } catch (DomainException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (Exception $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        }
    }

    /**
     * Toggle status aktif/nonaktif koneksi.
     */
    public function toggle(SpreadsheetConnection $spreadsheetConnection)
    {
        try {
            $connection = $this->service->toggleStatus($spreadsheetConnection, Auth::user());
            $statusText = $connection->is_enabled ? 'diaktifkan' : 'dinonaktifkan';

            return redirect()
                ->back()
                ->with('success', "Koneksi '{$connection->display_name}' berhasil {$statusText}.");
        } catch (Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Gagal mengubah status koneksi: ' . $e->getMessage());
        }
    }

    /**
     * Jalankan pengujian koneksi secara aman (read-only format/health check).
     */
    public function test(SpreadsheetConnection $spreadsheetConnection)
    {
        try {
            $result = $this->service->testConnection($spreadsheetConnection, Auth::user());

            if ($result['status'] === SpreadsheetConnection::STATUS_CONNECTED) {
                return redirect()
                    ->back()
                    ->with('success', $result['message']);
            } elseif ($result['status'] === SpreadsheetConnection::STATUS_PENDING_TRANSPORT) {
                return redirect()
                    ->back()
                    ->with('info', "Hasil Uji: " . $result['message']);
            } else {
                return redirect()
                    ->back()
                    ->with('error', "Hasil Uji ({$result['status']}): " . $result['message']);
            }
        } catch (Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Gagal menjalankan pengujian koneksi: ' . $e->getMessage());
        }
    }

    /**
     * Memicu proses retry manual untuk satu baris sinkronisasi spreadsheet tertentu.
     */
    public function retryRow(SpreadsheetConnection $spreadsheetConnection, SpreadsheetRowSync $spreadsheetRowSync, SpreadsheetSyncEngineService $syncEngine)
    {
        Gate::authorize('retry-sync-monitoring', $spreadsheetConnection);

        if ((int) $spreadsheetRowSync->spreadsheet_connection_id !== (int) $spreadsheetConnection->id) {
            abort(404);
        }

        try {
            $success = $syncEngine->queueManualRetry($spreadsheetRowSync, Auth::user());
            if (!$success) {
                return redirect()
                    ->back()
                    ->with('error', 'Gagal memicu retry: Record sedang diproses oleh worker aktif atau tidak eligible.');
            }

            if (!app()->runningUnitTests()) {
                $freshSync = $spreadsheetRowSync->fresh();
                if ($freshSync && $freshSync->isPending()) {
                    $syncEngine->processSyncRecord($freshSync, force: true);
                }
                $freshSync = $spreadsheetRowSync->fresh();
                if ($freshSync && $freshSync->isFailed()) {
                    return redirect()
                        ->back()
                        ->with('error', "Retry baris {$freshSync->external_sync_id} gagal: " . ($freshSync->error_message ?? 'Kesalahan tidak diketahui.'));
                }
            }

            return redirect()
                ->back()
                ->with('success', "Proses retry sinkronisasi baris {$spreadsheetRowSync->external_sync_id} telah dijalankan.");
        } catch (Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: ' . $e->getMessage());
        }
    }

    /**
     * Memicu proses batch retry untuk seluruh baris yang gagal pada koneksi ini.
     */
    public function retryAllFailed(SpreadsheetConnection $spreadsheetConnection, SpreadsheetSyncEngineService $syncEngine)
    {
        Gate::authorize('retry-sync-monitoring', $spreadsheetConnection);

        try {
            $count = $syncEngine->retryAllFailed($spreadsheetConnection, Auth::user());

            if (!app()->runningUnitTests() && $count > 0) {
                $pendingSyncs = SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)
                    ->where('sync_status', SpreadsheetRowSync::STATUS_PENDING)
                    ->get();

                foreach ($pendingSyncs as $pendingSync) {
                    $syncEngine->processSyncRecord($pendingSync, force: true);
                }
            }

            return redirect()
                ->back()
                ->with('success', "Berhasil menjadwalkan ulang retry untuk {$count} baris yang gagal.");
        } catch (Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu batch retry: ' . $e->getMessage());
        }
    }

    /**
     * Mendaftarkan versi schema tahunan baru beserta column mappings untuk koneksi ini.
     */
    public function storeSchema(Request $request, SpreadsheetConnection $spreadsheetConnection)
    {
        $validated = $request->validate([
            'year'                      => 'required|integer|between:2020,2035',
            'sheet_tab_name'            => 'required|string|max:100',
            'schema_version'            => 'required|string|max:50',
            'notes'                     => 'nullable|string|max:500',
            'activate'                  => 'nullable|boolean',
            'mappings'                  => 'required|array|min:1',
            'mappings.*.column_order'   => 'required|integer|min:1',
            'mappings.*.sheet_header'   => 'required|string|max:255',
            'mappings.*.target_entity'  => 'nullable|string|max:100',
            'mappings.*.target_field'   => 'nullable|string|max:100',
            'mappings.*.data_type'      => 'required|string|in:string,integer,boolean,date,datetime,array,json',
            'mappings.*.direction'      => 'required|string|in:outbound,inbound,bidirectional',
            'mappings.*.transform_key'  => 'nullable|string|max:100',
            'mappings.*.is_required'    => 'nullable|boolean',
        ]);

        try {
            $version = $this->service->registerSchemaVersion(
                connection: $spreadsheetConnection,
                year: (int) $validated['year'],
                sheetTabName: $validated['sheet_tab_name'],
                schemaVersion: $validated['schema_version'],
                columnMappings: $validated['mappings'],
                notes: $validated['notes'] ?? null,
                activate: (bool) ($validated['activate'] ?? true),
                actor: Auth::user()
            );

            return redirect()
                ->route('admin.spreadsheet-connections.show', $spreadsheetConnection)
                ->with('success', "Versi schema '{$version->schema_version}' tahun {$version->year} berhasil didaftarkan.");
        } catch (DomainException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (Exception $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Gagal mendaftarkan versi schema: ' . $e->getMessage());
        }
    }

    /**
     * Mengaktifkan versi schema tahun tertentu untuk koneksi ini.
     */
    public function activateSchema(SpreadsheetConnection $spreadsheetConnection, \App\Models\SpreadsheetSchemaVersion $schemaVersion)
    {
        if ((int) $schemaVersion->spreadsheet_connection_id !== (int) $spreadsheetConnection->id) {
            abort(404);
        }

        try {
            $this->service->activateSchemaVersion($schemaVersion, Auth::user());

            return redirect()
                ->back()
                ->with('success', "Versi schema '{$schemaVersion->schema_version}' tahun {$schemaVersion->year} berhasil diaktifkan.");
        } catch (Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Gagal mengaktifkan versi schema: ' . $e->getMessage());
        }
    }

    /**
     * Menonaktifkan versi schema tahun tertentu untuk koneksi ini.
     */
    public function deactivateSchema(SpreadsheetConnection $spreadsheetConnection, \App\Models\SpreadsheetSchemaVersion $schemaVersion)
    {
        if ((int) $schemaVersion->spreadsheet_connection_id !== (int) $spreadsheetConnection->id) {
            abort(404);
        }

        try {
            $this->service->deactivateSchemaVersion($schemaVersion, Auth::user());

            return redirect()
                ->back()
                ->with('success', "Versi schema '{$schemaVersion->schema_version}' tahun {$schemaVersion->year} dinonaktifkan.");
        } catch (Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Gagal menonaktifkan versi schema: ' . $e->getMessage());
        }
    }

    /**
     * Tampilkan riwayat log dan seluruh versi schema (aktif maupun nonaktif) untuk koneksi spreadsheet.
     */
    public function schemaHistory(Request $request, SpreadsheetConnection $spreadsheetConnection)
    {
        $spreadsheetConnection->refresh()->load(['createdByUser', 'updatedByUser']);

        $allSchemaVersions = $spreadsheetConnection->schemaVersions()
            ->with(['columnMappings' => fn($q) => $q->orderBy('column_order', 'asc'), 'createdByUser', 'updatedByUser'])
            ->orderBy('year', 'desc')
            ->orderBy('is_active', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return view('admin.spreadsheet-connections.schema-history', [
            'connection'     => $spreadsheetConnection,
            'schemaVersions' => $allSchemaVersions,
        ]);
    }

    /**
     * Ambil daftar seluruh tab dari Google Spreadsheet (Read-Only).
     */
    public function getTabs(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\SpreadsheetDiscoveryService $discoveryService
    ) {
        $result = $discoveryService->listTabs($spreadsheetConnection);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Inspeksi header, tipe kolom terinferensi, dan sample data tersensor untuk tab tertentu (Read-Only).
     */
    public function inspectSchema(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\SpreadsheetDiscoveryService $discoveryService
    ) {
        $tabName = $request->query('tab_name', '');
        $headerRow = $request->filled('header_row') ? (int) $request->query('header_row') : null;
        $sheetId = $request->has('sheet_id') && $request->query('sheet_id') !== null && $request->query('sheet_id') !== '' ? (int) $request->query('sheet_id') : null;

        $result = $discoveryService->inspectTabSchema($spreadsheetConnection, $tabName, $headerRow, $sheetId);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Preview baris data dari tab tertentu dengan pagination terbatas dan sensor PII (Read-Only).
     */
    public function previewRows(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\SpreadsheetDiscoveryService $discoveryService
    ) {
        $tabName = $request->query('tab_name', '');
        $headerRow = $request->filled('header_row') ? (int) $request->query('header_row') : null;
        $sheetId = $request->has('sheet_id') && $request->query('sheet_id') !== null && $request->query('sheet_id') !== '' ? (int) $request->query('sheet_id') : null;
        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('page_size', 20);

        $result = $discoveryService->previewRows($spreadsheetConnection, $tabName, $headerRow, $page, $pageSize, $sheetId);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Dapatkan rekomendasi pemetaan draft kolom spreadsheet ke Form Builder (Read-Only).
     */
    public function draftMappingProposals(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\SpreadsheetDiscoveryService $discoveryService
    ) {
        $tabName = $request->query('tab_name', '');
        $headerRow = $request->filled('header_row') ? (int) $request->query('header_row') : null;
        $sheetId = $request->has('sheet_id') && $request->query('sheet_id') !== null && $request->query('sheet_id') !== '' ? (int) $request->query('sheet_id') : null;

        $result = $discoveryService->generateDraftMappingProposals($spreadsheetConnection, $tabName, $headerRow, $sheetId);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Simpan profil eksplisit header_row dan data_start_row per tab (Fase 2).
     */
    public function saveTabProfile(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\SpreadsheetDiscoveryService $discoveryService
    ) {
        $validated = $request->validate([
            'sheet_id'       => 'required|integer',
            'tab_name'       => 'required|string|max:150',
            'header_row'     => 'required|integer|min:1|max:100',
            'data_start_row' => 'nullable|integer|min:2|max:200',
        ]);

        if (isset($validated['data_start_row']) && $validated['data_start_row'] <= $validated['header_row']) {
            return response()->json([
                'success' => false,
                'message' => 'data_start_row harus lebih besar dari header_row.',
            ], 422);
        }

        try {
            $profile = $discoveryService->resolveTabHeaderProfile(
                $spreadsheetConnection,
                $validated['sheet_id'],
                $validated['tab_name'],
                $validated['header_row'],
                $validated['data_start_row'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => "Profil header untuk tab '{$profile->tab_name}' berhasil disimpan.",
                'profile' => $profile,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Simpan snapshot immutable dari hasil scanning remote spreadsheet (Fase 2).
     */
    public function saveSnapshot(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\SpreadsheetDiscoveryService $discoveryService
    ) {
        $validated = $request->validate([
            'tab_name'   => 'required|string|max:150',
            'sheet_id'   => 'required|integer',
            'header_row' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $snapshot = $discoveryService->saveDiscoverySnapshot(
                $spreadsheetConnection,
                $validated['tab_name'],
                $validated['header_row'] ?? null,
                $validated['sheet_id'],
                Auth::user()
            );

            return response()->json([
                'success'  => true,
                'message'  => "Snapshot skema tab '{$snapshot->tab_name}' berhasil disimpan.",
                'snapshot' => $snapshot,
            ]);
        } catch (\Throwable $e) {
            $status = str_contains($e->getMessage(), 'INVALID_SHEET_IDENTITY') ? 422 : 422;
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan snapshot skema: ' . $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Buat usulan pemetaan resmi dari snapshot terpilih (Fase 2).
     */
    public function createProposal(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\SpreadsheetDiscoveryService $discoveryService
    ) {
        $validated = $request->validate([
            'snapshot_id'    => 'nullable|integer|exists:sheet_discovery_snapshots,id',
            'tab_name'       => 'nullable|string|max:150',
            'header_row'     => 'nullable|integer|min:1|max:100',
            'sheet_id'       => 'nullable|integer',
            'year'           => 'nullable|integer|min:2020|max:2099',
            'form_schema_id' => 'nullable|integer|exists:form_schemas,id',
        ]);

        try {
            $snapshot = null;
            if (!empty($validated['snapshot_id'])) {
                $snapshot = \App\Models\SheetDiscoverySnapshot::findOrFail($validated['snapshot_id']);
            } else {
                $tabName = $validated['tab_name'] ?? '2026';
                $snapshot = $discoveryService->saveDiscoverySnapshot(
                    $spreadsheetConnection,
                    $tabName,
                    $validated['header_row'] ?? null,
                    $validated['sheet_id'] ?? null,
                    Auth::user()
                );
            }

            $targetSchema = !empty($validated['form_schema_id'])
                ? \App\Models\FormSchema::find($validated['form_schema_id'])
                : null;

            $proposal = $discoveryService->createMappingProposal(
                $spreadsheetConnection,
                $snapshot,
                $validated['year'] ?? null,
                $targetSchema,
                Auth::user()
            );

            return response()->json([
                'success'  => true,
                'message'  => "Usulan pemetaan untuk snapshot '{$snapshot->tab_name}' berhasil dibuat.",
                'proposal' => $proposal,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat usulan pemetaan: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Preview non-destructive schema diff antara usulan pemetaan dan FormSchema (Fase 2).
     */
    public function previewDiff(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Models\SheetMappingProposal $proposal,
        \App\Services\SpreadsheetDiscoveryService $discoveryService
    ) {
        if ((int) $proposal->spreadsheet_connection_id !== (int) $spreadsheetConnection->id) {
            abort(404);
        }

        $diff = $discoveryService->previewSchemaDiff($proposal);

        return response()->json([
            'success' => true,
            'diff'    => $diff,
        ]);
    }

    /**
     * Terapkan usulan pemetaan yang telah disetujui ke draf Form Builder (Fase 2).
     */
    public function applyProposal(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Models\SheetMappingProposal $proposal,
        \App\Services\SpreadsheetDiscoveryService $discoveryService,
        \App\Services\FormBuilderService $formBuilderService
    ) {
        if ((int) $proposal->spreadsheet_connection_id !== (int) $spreadsheetConnection->id) {
            abort(404);
        }

        $year = $proposal->year ?? (int) now()->year;
        $draftSchema = $formBuilderService->ensureYearFormInitialized($year, Auth::user());

        try {
            $result = $discoveryService->applyProposalToDraft($proposal, $draftSchema, Auth::user());

            return response()->json([
                'success' => true,
                'message' => "Usulan pemetaan berhasil diaplikasikan ke draf Formulir Tahun {$year} (Versi: {$draftSchema->version_code}).",
                'result'  => $result,
            ]);
        } catch (\Throwable $e) {
            $status = str_contains($e->getMessage(), 'SCHEMA_CONFLICT') ? 409 : 422;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }

    /**
     * Perbarui keputusan pemetaan item per kolom secara manual oleh admin (Fase 2).
     */
    public function updateProposalItem(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Models\SheetMappingProposal $proposal,
        \App\Models\SheetMappingProposalItem $item
    ) {
        if ((int) $proposal->spreadsheet_connection_id !== (int) $spreadsheetConnection->id
            || (int) $item->proposal_id !== (int) $proposal->id
        ) {
            abort(404);
        }

        $supportedTypes = implode(',', array_keys(\App\Models\FormField::supportedTypes()));
        $validated = $request->validate([
            'action'               => 'required|in:link_existing,create_draft_field,external_only,ignore',
            'field_key'            => 'nullable|string|max:100',
            'suggested_label'      => 'nullable|string|max:255',
            'suggested_field_type' => "nullable|string|in:{$supportedTypes}",
        ]);

        // Guardrail: Reserved system fields cannot be overwritten
        if ($item->action === \App\Models\SheetMappingProposalItem::ACTION_SYSTEM_PROTECTED) {
            return response()->json([
                'success' => false,
                'message' => 'Kolom terproteksi sistem internal SERTIF tidak dapat diubah.',
            ], 422);
        }

        $item->update([
            'action'               => $validated['action'],
            'field_key'            => $validated['field_key'] ?? $item->field_key,
            'suggested_label'      => $validated['suggested_label'] ?? $item->suggested_label,
            'suggested_field_type' => $validated['suggested_field_type'] ?? $item->suggested_field_type,
            'has_collision'        => false, // Resolved by admin manual intervention
        ]);

        return response()->json([
            'success' => true,
            'message' => "Keputusan pemetaan kolom '{$item->col_letter}' berhasil diperbarui.",
            'item'    => $item->fresh(),
        ]);
    }

    /**
     * Periksa kelayakan penghapusan aman untuk kolom spreadsheet (Fase 3).
     */
    public function checkColumnDeletionEligibility(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        int $sheetId,
        string $columnIdentity,
        SpreadsheetColumnDeletionEligibilityService $eligibilityService
    ) {
        $tabName = $request->query('tab_name');
        if (empty($tabName)) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter tab_name wajib disertakan.',
            ], 400);
        }

        $result = $eligibilityService->evaluateEligibility(
            $spreadsheetConnection,
            $sheetId,
            $tabName,
            urldecode($columnIdentity)
        );

        return response()->json([
            'success'     => true,
            'eligibility' => $result,
        ]);
    }

    /**
     * Eksekusi penghapusan aman kolom kosong spreadsheet (Fase 3).
     * Memerlukan verifikasi kelayakan lengkap, optimistic schema hash, dan audit logging.
     */
    public function deleteEmptyColumn(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        int $sheetId,
        string $columnIdentity,
        SpreadsheetColumnDeletionEligibilityService $eligibilityService
    ) {
        $validated = $request->validate([
            'tab_name'             => 'required|string|max:100',
            'expected_schema_hash' => 'required|string',
        ]);

        $result = $eligibilityService->executeSafeDeletion(
            $spreadsheetConnection,
            $sheetId,
            $validated['tab_name'],
            urldecode($columnIdentity),
            $validated['expected_schema_hash'],
            Auth::user()
        );

        $status = ($result['success'] ?? false)
            ? 200
            : (($result['error_code'] ?? '') === 'SCHEMA_CONFLICT' ? 409 : 422);

        return response()->json($result, $status);
    }

    /**
     * F4: Scan tab Google Spreadsheet dan simpan perubahan inbound ke Staging.
     */
    public function scanInboundChanges(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        int $sheetId,
        SpreadsheetInboundSyncService $inboundService
    ) {
        $validated = $request->validate([
            'tab_name'             => 'required|string|max:100',
            'expected_schema_hash' => 'nullable|string',
        ]);

        $result = $inboundService->scanAndStageInboundChanges(
            $spreadsheetConnection,
            $sheetId,
            $validated['tab_name'],
            Auth::user(),
            ['expected_schema_hash' => $validated['expected_schema_hash'] ?? null]
        );

        $status = ($result['success'] ?? false)
            ? 200
            : (($result['error_code'] ?? '') === 'SCHEMA_CONFLICT' ? 409 : 422);

        return response()->json($result, $status);
    }

    /**
     * F4: Ambil daftar perubahan inbound pada staging.
     */
    public function getInboundStaging(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection
    ) {
        $query = SheetInboundChangeStaging::where('spreadsheet_connection_id', $spreadsheetConnection->id)
            ->with(['registration', 'reviewer']);

        if ($request->filled('tab_name')) {
            $query->where('tab_name', $request->input('tab_name'));
        }

        if ($request->filled('staging_status')) {
            $query->where('staging_status', $request->input('staging_status'));
        }

        if ($request->filled('conflict_status')) {
            $query->where('conflict_status', $request->input('conflict_status'));
        }

        $records = $query->orderBy('created_at', 'desc')->paginate(30);

        return response()->json([
            'success' => true,
            'data'    => $records,
        ]);
    }

    /**
     * F4: Setujui perubahan inbound pada staging dan terapkan ke database lokal.
     */
    public function approveInboundStaging(
        SpreadsheetConnection $spreadsheetConnection,
        SheetInboundChangeStaging $staging,
        SpreadsheetInboundSyncService $inboundService
    ) {
        if ($staging->spreadsheet_connection_id !== $spreadsheetConnection->id) {
            return response()->json([
                'success'    => false,
                'error_code' => 'CONNECTION_MISMATCH',
                'message'    => 'Record staging tidak sesuai dengan koneksi yang dipilih.',
            ], 422);
        }

        $result = $inboundService->applyStagedChange($staging, Auth::user());
        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }

    /**
     * F4: Tolak perubahan inbound pada staging.
     */
    public function rejectInboundStaging(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        SheetInboundChangeStaging $staging,
        SpreadsheetInboundSyncService $inboundService
    ) {
        if ($staging->spreadsheet_connection_id !== $spreadsheetConnection->id) {
            return response()->json([
                'success'    => false,
                'error_code' => 'CONNECTION_MISMATCH',
                'message'    => 'Record staging tidak sesuai dengan koneksi yang dipilih.',
            ], 422);
        }

        $reason = $request->input('reason', 'Ditolak oleh Admin.');
        $result = $inboundService->rejectStagedChange($staging, Auth::user(), $reason);

        return response()->json($result, 200);
    }
}
