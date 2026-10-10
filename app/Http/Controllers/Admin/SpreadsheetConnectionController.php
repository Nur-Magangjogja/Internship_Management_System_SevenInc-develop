<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Services\SpreadsheetConnectionService;
use App\Services\SpreadsheetSyncEngineService;
use DomainException;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        // Pastikan canonical connections terinisialisasi
        $this->service->getConnectionByKey(SpreadsheetConnection::KEY_REGISTRATION);
        $this->service->getConnectionByKey(SpreadsheetConnection::KEY_FOLLOWUP);

        $connections = $this->service->getAllConnections();

        return view('admin.spreadsheet-connections.index', compact('connections'));
    }

    /**
     * Tampilkan detail konfigurasi, riwayat versi schema, pemetaan kolom, dan status sync baris.
     */
    public function show(Request $request, SpreadsheetConnection $spreadsheetConnection)
    {
        $spreadsheetConnection->load(['schemaVersions.columnMappings', 'createdByUser', 'updatedByUser']);

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

        return view('admin.spreadsheet-connections.show', [
            'connection'     => $spreadsheetConnection,
            'schemaVersions' => $spreadsheetConnection->schemaVersions,
            'rowSyncs'       => $rowSyncs,
            'stats'          => $stats,
        ]);
    }

    /**
     * Form edit konfigurasi koneksi spreadsheet.
     */
    public function edit(SpreadsheetConnection $spreadsheetConnection)
    {
        return view('admin.spreadsheet-connections.edit', [
            'connection' => $spreadsheetConnection,
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
        if ((int) $spreadsheetRowSync->spreadsheet_connection_id !== (int) $spreadsheetConnection->id) {
            abort(404);
        }

        try {
            $syncEngine->retrySyncRecord($spreadsheetRowSync, Auth::user());

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
}
