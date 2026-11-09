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
use Illuminate\Validation\Rule;

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
        $centralEndpoint = $this->service->resolveAppsScriptEndpoint();

        return view('admin.spreadsheet-connections.index', compact('connections', 'centralEndpoint'));
    }

    /**
     * Buat atau daftarkan koneksi registrasi spreadsheet baru khusus untuk brand tertentu.
     */
    public function createBrandConnection(Request $request)
    {
        $validated = $request->validate([
            'brand_id' => 'required|exists:brands,id',
        ]);

        $brand = \App\Models\Brand::findOrFail($validated['brand_id']);

        $connection = SpreadsheetConnection::firstOrCreate(
            [
                'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
                'brand_id'       => $brand->id,
            ],
            [
                'display_name'      => "Spreadsheet Pendaftaran ({$brand->name})",
                'is_enabled'        => false,
                'connection_status' => SpreadsheetConnection::STATUS_NOT_CONFIGURED,
            ]
        );

        // Pastikan skema kanonikal 2026 langsung tersedia untuk brand baru
        app(\App\Services\SpreadsheetConnectionService::class)->ensureCanonicalSchemaForConnection($connection);

        return redirect()
            ->route('admin.spreadsheet-connections.edit', $connection)
            ->with('success', "Koneksi spreadsheet untuk brand '{$brand->name}' berhasil disiapkan. Silakan atur URL Spreadsheet dan Google Drive Folder.");
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
        $pausedQueuesSummary = app(\App\Services\SpreadsheetQueueReconciliationService::class)->getPausedQueuesSummary($spreadsheetConnection);

        $cachedTabs = \Illuminate\Support\Facades\Cache::get("sheet_tabs_{$spreadsheetConnection->id}");
        $preloadedTabs = (is_array($cachedTabs) && ($cachedTabs['success'] ?? false)) ? ($cachedTabs['tabs'] ?? []) : [];

        if (empty($preloadedTabs) && $tabProfiles->isNotEmpty()) {
            $preloadedTabs = $tabProfiles->map(function ($tp, $idx) {
                return [
                    'id'             => $tp->sheet_id,
                    'name'           => $tp->tab_name,
                    'index'          => $idx,
                    'row_count'      => $tp->total_rows ?? 0,
                    'column_count'   => $tp->total_columns ?? 0,
                    'header_row'     => $tp->header_row ?? 10,
                    'data_start_row' => $tp->data_start_row ?? 11,
                    'is_configured'  => true,
                ];
            })->values()->all();
        }

        return view('admin.spreadsheet-connections.show', [
            'connection'          => $spreadsheetConnection,
            'schemaVersions'      => $activeSchemaVersions,
            'totalSchemaCount'    => $totalSchemaCount,
            'rowSyncs'            => $rowSyncs,
            'stats'               => $stats,
            'pausedQueuesSummary' => $pausedQueuesSummary,
            'tabProfiles'         => $tabProfiles,
            'recentSnapshots'     => $recentSnapshots,
            'recentProposals'     => $recentProposals,
            'outboxEvents'        => $outboxEvents,
            'formFields'          => $formFields,
            'preloadedTabs'       => $preloadedTabs,
        ]);
    }

    /**
     * Form edit konfigurasi koneksi spreadsheet (Read-Only).
     */
    public function edit(SpreadsheetConnection $spreadsheetConnection)
    {
        $pausedQueuesSummary = app(\App\Services\SpreadsheetQueueReconciliationService::class)->getPausedQueuesSummary($spreadsheetConnection);
        $centralEndpoint = $this->service->resolveAppsScriptEndpoint($spreadsheetConnection);

        return view('admin.spreadsheet-connections.edit', [
            'connection'          => $spreadsheetConnection->fresh(),
            'pausedQueuesSummary' => $pausedQueuesSummary,
            'centralEndpoint'     => $centralEndpoint,
        ]);
    }

    /**
     * Simpan perubahan konfigurasi koneksi.
     */
    public function update(Request $request, SpreadsheetConnection $spreadsheetConnection)
    {
        // connection_key bersifat tetap dari record yang sedang diedit, bukan input bebas pengguna.
        // Validasi memakai connection_key sebagai kolom unik agar kombinasi brand_id NULL pun diperiksa.
        $request->merge(['_connection_key_scope' => $spreadsheetConnection->connection_key]);
        $targetBrandId = array_key_exists('brand_id', $request->all())
            ? $request->input('brand_id')
            : $spreadsheetConnection->brand_id;
        $scopeUniqueRule = Rule::unique('spreadsheet_connections', 'connection_key')
            ->where(function ($query) use ($targetBrandId) {
                if ($targetBrandId === null || $targetBrandId === '') {
                    $query->whereNull('brand_id');
                } else {
                    $query->where('brand_id', (int) $targetBrandId);
                }
            })
            ->ignore($spreadsheetConnection->id);

        $validated = $request->validate([
            '_connection_key_scope'     => ['required', 'string', $scopeUniqueRule],
            'display_name'             => 'required|string|max:255',
            'spreadsheet_url'          => 'nullable|string|max:500',
            'apps_script_endpoint_url' => 'nullable|url|max:500',
            'drive_folder_url'         => 'nullable|string|max:500',
            'brand_id'                 => 'nullable|integer|exists:brands,id',
            'is_yearly_cloning_enabled' => 'nullable|boolean',
            'tab_creation_mode'         => 'nullable|string|in:manual_link,auto_clone',
            'yearly_tab_template_name'  => 'nullable|string|max:100',
            'allow_custom_tab_naming'   => 'nullable|boolean',
            'default_integrated_status' => 'nullable|string|in:waiting,pending,accepted,active',
        ], [
            '_connection_key_scope.unique' => 'Brand tujuan sudah memiliki koneksi spreadsheet dengan jenis yang sama.',
        ]);
        unset($validated['_connection_key_scope']);

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
     * Otorisasi dan aktifkan target Spreadsheet secara resmi ke Google Apps Script Gateway (dengan persetujuan Admin).
     */
    public function activateTarget(Request $request, SpreadsheetConnection $spreadsheetConnection)
    {
        try {
            $confirmPromote = $request->boolean('confirm_promote_legacy', false);
            $result = $this->service->activateAuthorizedTarget($spreadsheetConnection, Auth::user(), $confirmPromote);

            if ($result['success']) {
                return redirect()
                    ->back()
                    ->with('success', $result['message']);
            }

            return redirect()
                ->back()
                ->with('error', $result['message']);
        } catch (\Throwable $e) {
            return redirect()
                ->back()
                ->with('error', 'Gagal mengaktifkan target: ' . $e->getMessage());
        }
    }

    /**
     * Rekonsiliasi antrean sinkronisasi yang dibekukan karena pergantian target Spreadsheet.
     */
    public function reconcileQueue(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\SpreadsheetQueueReconciliationService $reconciliationService
    ) {
        $validated = $request->validate([
            'decision'       => 'required|string|in:rebind_new_target,cancel_sync',
            'row_sync_id'    => 'nullable|integer|exists:spreadsheet_row_syncs,id',
            'row_sync_ids'   => 'nullable|array',
            'row_sync_ids.*' => 'integer|exists:spreadsheet_row_syncs,id',
        ]);

        try {
            if (!empty($validated['row_sync_id'])) {
                $rowSync = SpreadsheetRowSync::findOrFail($validated['row_sync_id']);
                if ((int) $rowSync->spreadsheet_connection_id !== (int) $spreadsheetConnection->id) {
                    abort(404);
                }
                $result = $reconciliationService->reconcileRowSync($rowSync, $validated['decision'], Auth::user());
                return redirect()->back()->with('success', $result['message']);
            }

            // Batch reconcile
            $rowIds = $validated['row_sync_ids'] ?? [];
            $stats = $reconciliationService->reconcileBatch(
                $spreadsheetConnection,
                $validated['decision'],
                $rowIds,
                [],
                Auth::user()
            );

            $msg = "Rekonsiliasi antrean selesai. Diproses: {$stats['total_processed']}, " .
                   "dialihkan: {$stats['rebound_queued']}, deduplikasi remote: {$stats['deduplicated_synced']}, dibatalkan: {$stats['cancelled']}.";
            if ($stats['failed'] > 0) {
                $msg .= " (Gagal: {$stats['failed']})";
            }

            return redirect()->back()->with('success', $msg);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Gagal memproses rekonsiliasi: ' . $e->getMessage());
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

            return redirect()
                ->back()
                ->with('success', "Proses retry sinkronisasi baris {$spreadsheetRowSync->external_sync_id} telah dijadwalkan.");
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
     * Endpoint monitoring ringkasan status sinkronisasi global (Read-Only, tanpa remote write / mutasi antrean).
     */
    public function globalSyncTick()
    {
        Gate::authorize('view-sync-monitoring');

        $activeConnectionCount = SpreadsheetConnection::where('is_enabled', true)
            ->whereNotNull('spreadsheet_id')
            ->count();

        $stats = [
            'pending_count'    => SpreadsheetRowSync::where('sync_status', SpreadsheetRowSync::STATUS_PENDING)->count(),
            'retrying_count'   => SpreadsheetRowSync::where('sync_status', SpreadsheetRowSync::STATUS_RETRYING)->count(),
            'processing_count' => SpreadsheetRowSync::where('sync_status', SpreadsheetRowSync::STATUS_PROCESSING)->count(),
            'failed_count'     => SpreadsheetRowSync::where('sync_status', SpreadsheetRowSync::STATUS_FAILED)->count(),
            'paused_count'     => SpreadsheetRowSync::where('sync_status', SpreadsheetRowSync::STATUS_PAUSED_TARGET_REBIND)->count(),
            'synced_count'     => SpreadsheetRowSync::where('sync_status', SpreadsheetRowSync::STATUS_SYNCED)->count(),
        ];

        return response()->json([
            'success'            => true,
            'readonly'           => true,
            'processed'          => 0,
            'active_connections' => $activeConnectionCount,
            'pending_count'      => $stats['pending_count'],
            'stats'              => $stats,
        ]);
    }

    /**
     * Endpoint monitoring status sinkronisasi baris dan statistik (Read-Only, tanpa remote write / mutasi antrean).
     */
    public function syncStatus(Request $request, SpreadsheetConnection $spreadsheetConnection)
    {
        Gate::authorize('view-sync-monitoring', $spreadsheetConnection);

        $spreadsheetConnection->refresh();

        $page = max(1, (int) $request->get('page', 1));
        $rowSyncs = SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)
            ->latest('id')
            ->paginate(15, ['*'], 'page', $page);

        $pausedQueuesSummary = app(\App\Services\SpreadsheetQueueReconciliationService::class)->getPausedQueuesSummary($spreadsheetConnection);

        $stats = [
            'total'    => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->count(),
            'synced'   => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->where('sync_status', SpreadsheetRowSync::STATUS_SYNCED)->count(),
            'pending'  => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->where('sync_status', SpreadsheetRowSync::STATUS_PENDING)->count(),
            'retrying' => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->where('sync_status', SpreadsheetRowSync::STATUS_RETRYING)->count(),
            'failed'   => SpreadsheetRowSync::where('spreadsheet_connection_id', $spreadsheetConnection->id)->where('sync_status', SpreadsheetRowSync::STATUS_FAILED)->count(),
            'paused'   => (int) ($pausedQueuesSummary['total_count'] ?? 0),
        ];

        $outboxEvents = $spreadsheetConnection->outboxEvents()->limit(10)->get();

        $rowsHtml = view('admin.spreadsheet-connections.partials.sync-rows', [
            'rowSyncs'   => $rowSyncs,
            'connection' => $spreadsheetConnection,
        ])->render();

        $paginationHtml = $rowSyncs->hasPages() ? (string) view('admin.spreadsheet-connections.partials.sync-pagination', [
            'rowSyncs' => $rowSyncs,
        ])->render() : '';

        $outboxHtml = view('admin.spreadsheet-connections.partials.outbox-events', [
            'outboxEvents' => $outboxEvents,
        ])->render();

        return response()->json([
            'success'         => true,
            'readonly'        => true,
            'stats'           => $stats,
            'rows_html'       => $rowsHtml,
            'pagination_html' => $paginationHtml,
            'outbox_html'     => $outboxHtml,
        ]);
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
        $refresh = $request->boolean('refresh', false);
        $cacheKey = "sheet_tabs_{$spreadsheetConnection->id}";

        if (!$refresh && \Illuminate\Support\Facades\Cache::has($cacheKey)) {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (is_array($cached) && ($cached['success'] ?? false)) {
                return response()->json($cached, 200);
            }
        }

        $result = $discoveryService->listTabs($spreadsheetConnection);

        if ($result['success'] ?? false) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 1800); // Simpan 30 menit untuk akses instan
        }

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
     * Duplikasi tab template atau buat tab baru untuk tahunan / batch baru secara otomatis via Apps Script (Fase 5 Universal).
     */
    public function duplicateTab(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\SpreadsheetTransport\SpreadsheetTransportInterface $transport,
        \App\Services\SpreadsheetDiscoveryService $discoveryService
    ) {
        $validated = $request->validate([
            'source_tab_name' => 'nullable|string|max:100',
            'new_tab_name'    => 'required|string|max:100',
            'header_row'      => 'nullable|integer|min:1|max:100',
        ]);

        if (!$spreadsheetConnection->is_yearly_cloning_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Mode kloning tab tahunan dinonaktifkan untuk koneksi spreadsheet brand ini.',
            ], 422);
        }

        $sourceTab = !empty($validated['source_tab_name'])
            ? trim($validated['source_tab_name'])
            : ($spreadsheetConnection->yearly_tab_template_name ?: 'TEMPLATE_TAHUN');
        $newTabName = trim($validated['new_tab_name']);

        if (!$spreadsheetConnection->allow_custom_tab_naming && !preg_match('/^20\d{2}$/', $newTabName)) {
            return response()->json([
                'success' => false,
                'message' => 'Penamaan tab kustom dinonaktifkan. Nama tab harus berupa 4 digit tahun (contoh: 2027).',
            ], 422);
        }

        try {
            $res = $transport->duplicateOrCreateTab($spreadsheetConnection, $sourceTab, $newTabName);

            if (!$res->success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal membuat/menduplikasi tab: ' . $res->errorMessage,
                ], 422);
            }

            // Daftarkan SpreadsheetTabProfile untuk tab baru
            $sheetId = $res->context['sheet_id'] ?? rand(100000, 999999);
            $defaultHeaderRow = SpreadsheetTabProfile::defaultHeaderRowSuggestion($newTabName, $spreadsheetConnection->connection_key);
            $headerRow = $validated['header_row'] ?? $defaultHeaderRow;

            $profile = $discoveryService->resolveTabHeaderProfile(
                $spreadsheetConnection,
                $sheetId,
                $newTabName,
                $headerRow,
                $headerRow + 1
            );

            return response()->json([
                'success' => true,
                'message' => "Tab '{$newTabName}' berhasil disiapkan di Spreadsheet dan terdaftar di profil SertIF.",
                'profile' => $profile,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage(),
            ], 500);
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
        $draftSchema = $formBuilderService->getOrCreateDraft($year, Auth::user(), $spreadsheetConnection->brand_id);

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

        if ($request->has('expected_version')) {
            if ((int) $proposal->version !== (int) $request->input('expected_version')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Konflik konkurensi: Usulan mapping telah diubah oleh admin lain. Silakan muat ulang halaman.',
                ], 409);
            }
        }

        $supportedTypes = implode(',', array_keys(\App\Models\FormField::supportedTypes()));
        $validated = $request->validate([
            'action'               => 'required|in:link_existing,create_draft_field,external_only,ignore,system_derived,attachment_adapter',
            'field_key'            => 'nullable|string|max:100',
            'suggested_label'      => 'nullable|string|max:255',
            'suggested_field_type' => "nullable|string|in:{$supportedTypes}",
            'expected_version'     => 'nullable|integer',
        ]);

        // Guardrail: Reserved system fields cannot be overwritten
        if ($item->action === \App\Models\SheetMappingProposalItem::ACTION_SYSTEM_PROTECTED) {
            return response()->json([
                'success' => false,
                'message' => 'Kolom terproteksi sistem internal SERTIF tidak dapat diubah.',
            ], 422);
        }

        $action = $validated['action'];
        if (in_array($action, ['external_only', 'ignore', 'system_derived', 'attachment_adapter'], true)) {
            $fieldKey = null;
        } else {
            $fieldKey = $validated['field_key'] ?? null;
        }

        if (!empty($fieldKey) && in_array($action, ['link_existing', 'create_draft_field'], true)) {
            $duplicateTarget = \App\Models\SheetMappingProposalItem::where('proposal_id', $proposal->id)
                ->where('id', '!=', $item->id)
                ->where('field_key', $fieldKey)
                ->whereNotIn('action', ['ignore'])
                ->first();
            if ($duplicateTarget) {
                return response()->json([
                    'success' => false,
                    'message' => "Target field '{$fieldKey}' telah dipilih oleh kolom '{$duplicateTarget->col_letter}'. Dua kolom tidak boleh mengarah ke target field yang sama.",
                ], 422);
            }
        }

        $item->update([
            'action'               => $action,
            'field_key'            => $fieldKey,
            'suggested_label'      => $validated['suggested_label'] ?? $item->suggested_label,
            'suggested_field_type' => $validated['suggested_field_type'] ?? $item->suggested_field_type,
            'has_collision'        => false,
            'resolved_at'          => now(),
            'resolved_by'          => auth()->id(),
        ]);

        // Re-evaluate siblings with identical header
        $normHeader = strtolower(trim($item->observed_label));
        $siblings = \App\Models\SheetMappingProposalItem::where('proposal_id', $proposal->id)
            ->where('id', '!=', $item->id)
            ->whereRaw('LOWER(TRIM(observed_label)) = ?', [$normHeader])
            ->get();

        foreach ($siblings as $sib) {
            if (in_array($sib->action, ['link_existing', 'create_draft_field', 'system_derived', 'attachment_adapter', 'external_only', 'ignore'], true) && (!empty($sib->field_key) || in_array($sib->action, ['system_derived', 'attachment_adapter', 'external_only', 'ignore'], true))) {
                $sib->update(['has_collision' => false, 'resolved_at' => now(), 'resolved_by' => auth()->id()]);
            }
        }

        $proposal->increment('version');

        return response()->json([
            'success'          => true,
            'message'          => "Keputusan pemetaan kolom '{$item->col_letter}' berhasil diperbarui.",
            'item'             => $item->fresh(),
            'proposal_version' => $proposal->fresh()->version,
        ]);
    }

    /**
     * Hapus usulan pemetaan (proposal) yang tidak valid / draft usulan lama.
     */
    public function destroyProposal(
        SpreadsheetConnection $spreadsheetConnection,
        \App\Models\SheetMappingProposal $proposal
    ) {
        if ((int) $proposal->spreadsheet_connection_id !== (int) $spreadsheetConnection->id) {
            abort(404);
        }

        try {
            $proposal->items()->delete();
            $proposal->delete();

            return response()->json([
                'success' => true,
                'message' => 'Usulan pemetaan berhasil dihapus.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus usulan pemetaan: ' . $e->getMessage(),
            ], 500);
        }
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

    /**
     * Memindai baris pada spreadsheet yang belum memiliki SERTIF_SYNC_ID untuk persiapan rekonsiliasi manual.
     */
    public function getUnsyncedRows(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $tabName = $request->query('tab_name');
        if (empty($tabName)) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter tab_name wajib disertakan.',
            ], 422);
        }

        $page = (int) $request->query('page', 1);
        $pageSize = min((int) $request->query('page_size', 50), 100);

        try {
            $result = $reconcileService->scanUnsyncedRows($spreadsheetConnection, $tabName, $page, $pageSize);
            return response()->json([
                'success' => true,
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memindai baris tanpa ID: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Pasangkan SERTIF_SYNC_ID secara manual atau buatkan akun terintegrasi untuk baris spreadsheet.
     */
    public function reconcileRow(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $validated = $request->validate([
            'tab_name'          => 'required|string|max:100',
            'row_index'         => 'required|integer|min:2',
            'sync_id'           => 'nullable|string|max:100',
            'registration_id'   => 'nullable|integer|exists:internship_registrations,id',
            'expected_checksum' => 'nullable|string|max:100',
            'row_data'          => 'nullable|array',
            'internship_status' => 'nullable|string|in:waiting,pending,accepted,active,completed,rejected,exited',
        ]);

        try {
            $result = $reconcileService->reconcileRowSyncId(
                connection: $spreadsheetConnection,
                tabName: $validated['tab_name'],
                rowIndex: (int) $validated['row_index'],
                existingSyncId: $validated['sync_id'] ?? null,
                registrationId: isset($validated['registration_id']) ? (int) $validated['registration_id'] : null,
                expectedChecksum: $validated['expected_checksum'] ?? null,
                rowData: $validated['row_data'] ?? [],
                targetStatus: $validated['internship_status'] ?? null
            );

            $status = ($result['success'] ?? false) ? 200 : 422;
            return response()->json($result, $status);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal merekonsiliasi baris: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pindai dan analisis baris workspace secara semi-otomatis dengan warna dan deteksi status.
     */
    public function workspaceRows(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $tabName = $request->query('tab_name');
        if (empty($tabName)) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter tab_name wajib disertakan.',
            ], 422);
        }

        $startRow = $request->filled('start_row') ? (int) $request->query('start_row') : null;
        $endRow = $request->filled('end_row') ? (int) $request->query('end_row') : null;
        $headerRow = $request->filled('header_row') ? (int) $request->query('header_row') : null;
        $page = max(1, (int) $request->query('page', 1));
        // Batasi ukuran per blok maksimal 500 baris agar responsif dan mencakup jangkauan luas
        $pageSize = min(max(1, (int) $request->query('page_size', 100)), 500);
        $sortBy = $request->query('sort_by');
        $sortDir = $request->query('sort_dir', 'asc');
        $fetchColors = $request->boolean('fetch_colors', true);
        $skipSynced = $request->boolean('skip_synced', false);

        // Parse color_mapping & range_mapping jika dikirim via JSON query / payload
        $colorMapping = [];
        if ($request->filled('color_mapping')) {
            $rawColor = $request->input('color_mapping');
            $colorMapping = is_array($rawColor) ? $rawColor : (json_decode($rawColor, true) ?: []);
        }

        $rangeMapping = [];
        if ($request->filled('range_mapping')) {
            $rawRanges = $request->input('range_mapping');
            $rangeMapping = is_array($rawRanges) ? $rawRanges : (json_decode($rawRanges, true) ?: []);
        }

        $defaultStatus = $request->query('default_status');
        $statusColumn = $request->query('status_column');

        // Optimasi Cache 2 Menit untuk pemindaian baris identik agar filter UI instan (0 ms)
        $cacheHash = md5(json_encode([
            'tab'            => $tabName,
            'start'          => $startRow,
            'end'            => $endRow,
            'header'         => $headerRow,
            'page'           => $page,
            'page_size'      => $pageSize,
            'fetch_colors'   => $fetchColors,
            'skip_synced'    => $skipSynced,
            'status_col'     => $statusColumn,
            'default_status' => $defaultStatus,
            'colors'         => $colorMapping,
            'ranges'         => $rangeMapping
        ]));
        $cacheKey = "ws_rows_{$spreadsheetConnection->id}_{$cacheHash}";
        $refresh = $request->boolean('refresh', false);

        if (!$refresh && \Illuminate\Support\Facades\Cache::has($cacheKey)) {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (is_array($cached)) {
                return response()->json(array_merge([
                    'success' => true,
                    'data'    => $cached,
                ], $cached));
            }
        }

        // Safeguard batas baris tab spreadsheet (cegah pencarian looping di luar baris data riil)
        $profile = \App\Models\SpreadsheetTabProfile::where('spreadsheet_connection_id', $spreadsheetConnection->id)
            ->where('tab_name', $tabName)
            ->first();
        if ($profile && $profile->total_rows > 0 && $startRow !== null && $startRow > $profile->total_rows) {
            return response()->json([
                'success'            => true,
                'message'            => 'Baris mulai melebihi total baris data yang ada pada tab spreadsheet.',
                'tab_name'           => $tabName,
                'start_row'          => $startRow,
                'total_rows_scanned' => 0,
                'returned_rows'      => 0,
                'palette'            => [],
                'dividers'           => [],
                'rows'               => [],
            ], 200);
        }

        try {
            $result = $reconcileService->scanWorkspaceRows(
                connection: $spreadsheetConnection,
                tabName: $tabName,
                startRow: $startRow,
                endRow: $endRow,
                headerRow: $headerRow,
                page: $page,
                pageSize: $pageSize,
                sortBy: $sortBy,
                sortDir: $sortDir,
                colorMapping: $colorMapping,
                rangeMapping: $rangeMapping,
                defaultStatus: $defaultStatus,
                statusColumn: $statusColumn,
                fetchColors: $fetchColors,
                skipSynced: $skipSynced
            );

            \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 120); // Simpan 2 menit

            return response()->json(array_merge([
                'success' => true,
                'data'    => $result,
            ], $result));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("workspaceRows error for conn {$spreadsheetConnection->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal memindai workspace spreadsheet: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Uji coba rekonsiliasi (Dry-Run) tanpa efek samping mutasi database atau spreadsheet.
     */
    public function dryRunReconcile(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $validated = $request->validate([
            'tab_name'         => 'required|string|max:100',
            'items'            => 'required|array|min:1',
            'items.*.row_index'=> 'required|integer|min:1',
            'items.*.status'   => 'nullable|string',
            'items.*.row_data' => 'nullable|array',
        ]);

        try {
            $result = $reconcileService->dryRunReconciliation(
                connection: $spreadsheetConnection,
                tabName: $validated['tab_name'],
                items: $validated['items']
            );

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menjalankan dry-run rekonsiliasi: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Uji coba parser kolom gabungan (Composite Column Parser).
     */
    public function testCompositeParser(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $sampleValue = (string) $request->input('sample_value', '');
        $rule = $request->input('rule');

        if (!is_array($rule)) {
            $rule = [
                'type'          => $request->input('rule_type', 'delimiter'),
                'delimiter'     => $request->input('delimiter', ' - '),
                'pattern'       => $request->input('regex_pattern', $request->input('pattern', '')),
                'target_fields' => $request->input('target_keys', $request->input('target_fields', [])),
            ];
        }

        if (empty($sampleValue)) {
            return response()->json([
                'success' => false,
                'message' => 'sample_value wajib diisi.',
            ], 422);
        }

        $result = $reconcileService->parseCompositeField($sampleValue, $rule);
        return response()->json($result);
    }

    /**
     * Eksekusi rekonsiliasi batch untuk sekumpulan baris terpilih ke SertIF.
     */
    public function batchReconcile(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $validated = $request->validate([
            'tab_name'         => 'required|string|max:100',
            'items'            => 'required|array|min:1',
            'items.*.row_index'=> 'required|integer|min:1',
            'items.*.status'   => 'nullable|string|in:waiting,pending,accepted,active,completed,rejected,exited',
            'items.*.row_data' => 'nullable|array',
            'items.*.expected_checksum' => 'nullable|string|max:100',
            'items.*.registration_id'   => 'nullable|integer',
            'items.*.sync_id'           => 'nullable|string|max:100',
            'ownership_policies'        => 'nullable|array',
        ]);

        try {
            $result = $reconcileService->reconcileBatchRows(
                connection: $spreadsheetConnection,
                tabName: $validated['tab_name'],
                items: $validated['items'],
                ownershipPolicies: $validated['ownership_policies'] ?? []
            );

            return response()->json([
                'success' => true,
                'data'    => $result,
                'message' => "Berhasil memproses {$result['success_count']} dari {$result['total']} baris data ke SertIF.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal melakukan rekonsiliasi batch: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pulihkan dan tarik kembali baris yang SUDAH memiliki ID di spreadsheet ke database SertIF secara massal.
     */
    public function batchRecover(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $validated = $request->validate([
            'tab_name'                  => 'required|string|max:100',
            'items'                     => 'required|array|min:1',
            'items.*.row_index'         => 'required|integer|min:1',
            'items.*.sync_id'           => 'nullable|string|max:100',
            'items.*.status'            => 'nullable|string|in:waiting,pending,accepted,active,completed,rejected,exited',
            'items.*.row_data'          => 'nullable|array',
            'items.*.expected_checksum' => 'nullable|string|max:100',
            'items.*.registration_id'   => 'nullable|integer',
            'ownership_policies'        => 'nullable|array',
        ]);

        try {
            $result = $reconcileService->batchRecoverRows(
                connection: $spreadsheetConnection,
                tabName: $validated['tab_name'],
                items: $validated['items'],
                ownershipPolicies: $validated['ownership_policies'] ?? []
            );

            return response()->json([
                'success' => true,
                'data'    => $result,
                'message' => "Berhasil memulihkan {$result['recovered_count']} dari {$result['total']} baris data ber-ID ke SertIF.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memulihkan baris ber-ID: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pulihkan dan tarik kembali satu baris yang ber-ID di spreadsheet ke database SertIF.
     */
    public function recoverRow(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $validated = $request->validate([
            'tab_name'          => 'required|string|max:100',
            'row_index'         => 'required|integer|min:2',
            'sync_id'           => 'required|string|max:100',
            'registration_id'   => 'nullable|integer',
            'expected_checksum' => 'nullable|string|max:100',
            'row_data'          => 'nullable|array',
            'internship_status' => 'nullable|string|in:waiting,pending,accepted,active,completed,rejected,exited',
        ]);

        try {
            $result = $reconcileService->recoverOrReingestExistingRow(
                connection: $spreadsheetConnection,
                tabName: $validated['tab_name'],
                rowIndex: (int) $validated['row_index'],
                existingSyncId: $validated['sync_id'],
                remoteRegistrationId: isset($validated['registration_id']) ? (int) $validated['registration_id'] : null,
                rowData: $validated['row_data'] ?? [],
                targetStatus: $validated['internship_status'] ?? null,
                expectedChecksum: $validated['expected_checksum'] ?? null
            );

            $status = ($result['success'] ?? false) ? 200 : 422;
            return response()->json($result, $status);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memulihkan baris ber-ID: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pindai dan identifikasi semua kolom spreadsheet beserta deteksi Header ID SertIF.
     */
    public function inspectColumns(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $tabName = $request->query('tab_name');
        if (empty($tabName)) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter tab_name wajib disertakan.',
            ], 422);
        }

        $headerRow = $request->filled('header_row') ? (int) $request->query('header_row') : 10;
        $sheetId = $request->filled('sheet_id') ? (int) $request->query('sheet_id') : null;
        $refresh = $request->boolean('refresh', false);

        $cacheKey = "sheet_cols_{$spreadsheetConnection->id}_" . md5("{$tabName}_{$headerRow}_{$sheetId}");

        if (!$refresh && \Illuminate\Support\Facades\Cache::has($cacheKey)) {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (is_array($cached) && ($cached['success'] ?? false)) {
                return response()->json($cached, 200);
            }
        }

        try {
            $result = $reconcileService->inspectSheetColumns(
                connection: $spreadsheetConnection,
                tabName: $tabName,
                headerRow: $headerRow,
                sheetId: $sheetId
            );

            if ($result['success'] ?? false) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $result, 1800); // 30 menit
            }

            return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menginspeksi kolom spreadsheet: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Berikan / tambahkan kolom Header ID SertIF ke Google Spreadsheet.
     */
    public function injectHeaderIds(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $validated = $request->validate([
            'tab_name'   => 'required|string|max:100',
            'header_row' => 'nullable|integer|min:1|max:1000',
        ]);

        try {
            $result = $reconcileService->injectSystemHeaderColumns(
                connection: $spreadsheetConnection,
                tabName: $validated['tab_name'],
                headerRow: $validated['header_row'] ?? 10
            );

            // Invalidate cache inspeksi kolom
            $hRow = $validated['header_row'] ?? 10;
            \Illuminate\Support\Facades\Cache::forget("sheet_cols_{$spreadsheetConnection->id}_" . md5("{$validated['tab_name']}_{$hRow}_"));

            return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan kolom Header ID: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Simpan susunan kolom dan sesuaikan ke formulir brand.
     */
    public function saveBrandColumns(
        Request $request,
        SpreadsheetConnection $spreadsheetConnection,
        \App\Services\ActiveSheetManualRowReconciliationService $reconcileService
    ) {
        $validated = $request->validate([
            'tab_name'   => 'required|string|max:100',
            'header_row' => 'nullable|integer|min:1|max:1000',
            'columns'    => 'required|array|min:1',
            'columns.*.header_name'  => 'nullable|string',
            'columns.*.col_letter'   => 'nullable|string',
            'columns.*.category'     => 'nullable|string',
            'columns.*.field_key'    => 'nullable|string',
            'columns.*.inferred_type'=> 'nullable|string',
        ]);

        try {
            $result = $reconcileService->saveBrandColumnMapping(
                connection: $spreadsheetConnection,
                tabName: $validated['tab_name'],
                headerRow: $validated['header_row'] ?? 10,
                columnDefinitions: $validated['columns']
            );

            // Invalidate cache inspeksi kolom
            $hRow = $validated['header_row'] ?? 10;
            \Illuminate\Support\Facades\Cache::forget("sheet_cols_{$spreadsheetConnection->id}_" . md5("{$validated['tab_name']}_{$hRow}_"));

            return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan pemetaan kolom brand: ' . $e->getMessage(),
            ], 500);
        }
    }
}
