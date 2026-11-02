<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudStorageSetting;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Services\CloudStorage\GoogleAppsScriptDriveDriver;
use App\Services\CloudStorage\SystemStorageConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CloudStorageSettingController extends Controller
{
    public function __construct(
        protected SystemStorageConfigService $storageService
    ) {}

    /**
     * Tampilkan halaman pengaturan Penyimpanan Cloud Google Drive sistem.
     * Hanya melakukan pembacaan konfigurasi & status lokal (tanpa mutasi ke Drive).
     */
    public function index()
    {
        $settings = $this->storageService->getSettings();
        $registrationRef = $this->storageService->getRegistrationFolderReference();
        $spreadsheetConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first()
            ?: SpreadsheetConnection::whereNotNull('apps_script_endpoint_url')->first();

        $gasDriver = new GoogleAppsScriptDriveDriver();
        $isGasConfigured = $gasDriver->isConfigured();
        $activeProvider = $settings->getActiveProvider();

        // 1. Metrik Status Antrean Unggah & Sinkronisasi Dokumen
        $queueStats = [
            'pending'  => RegistrationAttachment::where('drive_sync_status', RegistrationAttachment::STATUS_PENDING)->count(),
            'retrying' => RegistrationAttachment::where('drive_sync_status', RegistrationAttachment::STATUS_RETRYING)->count(),
            'failed'   => RegistrationAttachment::where('drive_sync_status', RegistrationAttachment::STATUS_FAILED)->count(),
            'synced'   => RegistrationAttachment::where('drive_sync_status', RegistrationAttachment::STATUS_SYNCED)->count(),
        ];

        // 2. Pemeriksaan Kesesuaian Folder Induk (UI vs ENV & Spreadsheet Connection)
        $hasEnvConflict = $settings->hasRootConfigConflict();
        $hasSpreadsheetMismatch = $settings->hasSpreadsheetFolderMismatch($spreadsheetConn);

        // 3. Status Endpoint Google Apps Script (Masked untuk Keamanan)
        $appsScriptEndpoint = $settings->apps_script_endpoint_url
            ?: $spreadsheetConn?->apps_script_endpoint_url
            ?: config('services.google_apps_script.endpoint_url');
        $hasSharedSecret = !empty(config('services.google_apps_script.shared_secret'));

        $maskedEndpoint = null;
        if (!empty($appsScriptEndpoint)) {
            $parsed = parse_url($appsScriptEndpoint);
            $path = $parsed['path'] ?? '';
            $maskedEndpoint = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . substr($path, 0, 16) . '.../exec';
        }

        // 4. Daftar Referensi Folder Registrasi Multi-Brand & Deteksi Shared Folder
        $allBrands = \App\Models\Brand::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $brandConnections = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
            ->with('brand')
            ->get();

        $folderUsageCounts = $brandConnections
            ->whereNotNull('drive_folder_id')
            ->pluck('drive_folder_id')
            ->countBy();

        $connectionsByBrand = $brandConnections->whereNotNull('brand_id')->keyBy('brand_id');
        $brandFolderItems = collect();

        foreach ($allBrands as $brand) {
            $conn = $connectionsByBrand->get($brand->id);
            $folderId = $conn?->drive_folder_id;
            $folderUrl = $conn?->drive_folder_url;
            if (empty($folderUrl) && !empty($folderId)) {
                $folderUrl = 'https://drive.google.com/drive/folders/' . $folderId;
            }

            $isShared = !empty($folderId) && (($folderUsageCounts[$folderId] ?? 0) > 1);
            $sharedCount = $isShared ? (int) $folderUsageCounts[$folderId] : 1;

            $brandFolderItems->push([
                'brand_id'           => $brand->id,
                'brand_code'         => $brand->code,
                'brand_name'         => $brand->name,
                'is_default'         => (bool) $brand->is_default,
                'connection_id'      => $conn?->id,
                'connection_status'  => $conn?->connection_status ?? 'not_configured',
                'is_enabled'         => (bool) ($conn?->is_enabled ?? false),
                'drive_folder_id'    => $folderId,
                'drive_folder_url'   => $folderUrl,
                'is_drive_connected' => !empty($folderId),
                'is_shared'          => $isShared,
                'shared_count'       => $sharedCount,
                'edit_url'           => $conn
                    ? route('admin.spreadsheet-connections.edit', $conn->id)
                    : route('admin.spreadsheet-connections.index'),
            ]);
        }

        // Sertakan koneksi registrasi global / legacy (jika ada brand_id null)
        $legacyGlobals = $brandConnections->whereNull('brand_id');
        foreach ($legacyGlobals as $legacyConn) {
            $folderId = $legacyConn->drive_folder_id;
            $folderUrl = $legacyConn->drive_folder_url;
            if (empty($folderUrl) && !empty($folderId)) {
                $folderUrl = 'https://drive.google.com/drive/folders/' . $folderId;
            }

            $isShared = !empty($folderId) && (($folderUsageCounts[$folderId] ?? 0) > 1);

            $brandFolderItems->prepend([
                'brand_id'           => null,
                'brand_code'         => 'GLOBAL',
                'brand_name'         => $legacyConn->display_name ?: 'Koneksi Pendaftaran Global (Legacy)',
                'is_default'         => false,
                'connection_id'      => $legacyConn->id,
                'connection_status'  => $legacyConn->connection_status ?? 'not_configured',
                'is_enabled'         => (bool) $legacyConn->is_enabled,
                'drive_folder_id'    => $folderId,
                'drive_folder_url'   => $folderUrl,
                'is_drive_connected' => !empty($folderId),
                'is_shared'          => $isShared,
                'shared_count'       => $isShared ? (int) $folderUsageCounts[$folderId] : 1,
                'edit_url'           => route('admin.spreadsheet-connections.edit', $legacyConn->id),
            ]);
        }

        $folderStats = [
            'total_brands'       => $allBrands->count() ?: $brandFolderItems->count(),
            'configured_count'   => $brandFolderItems->where('is_drive_connected', true)->count(),
            'unconfigured_count' => $brandFolderItems->where('is_drive_connected', false)->count(),
            'shared_count'       => $folderUsageCounts->filter(fn($c) => $c > 1)->count(),
        ];

        return view('admin.cloud-storage.index', [
            'settings'                => $settings,
            'registrationRef'         => $registrationRef,
            'spreadsheetConn'         => $spreadsheetConn,
            'activeProvider'          => $activeProvider,
            'isGasConfigured'         => $isGasConfigured,
            'queueStats'              => $queueStats,
            'hasEnvConflict'          => $hasEnvConflict,
            'hasSpreadsheetMismatch'  => $hasSpreadsheetMismatch,
            'appsScriptEndpoint'      => $appsScriptEndpoint,
            'maskedEndpoint'          => $maskedEndpoint,
            'hasSharedSecret'         => $hasSharedSecret,
            'brandFolderItems'        => $brandFolderItems,
            'folderStats'             => $folderStats,
        ]);
    }

    /**
     * Perbarui konfigurasi folder Google Drive sistem.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'name'                          => 'required|string|max:255',
            'provider'                      => 'nullable|string|in:apps_script_drive,local_fallback',
            'canonical_parent_folder_url'   => 'nullable|string|max:500',
            'apps_script_endpoint_url'      => 'nullable|string|max:500',
            'system_assets_folder_url'      => 'nullable|string|max:500',
            'document_templates_folder_url' => 'nullable|string|max:500',
            'generated_docs_folder_url'     => 'nullable|string|max:500',
            'archives_folder_url'           => 'nullable|string|max:500',
            'is_enabled'                    => 'nullable|boolean',
        ]);

        $validated['is_enabled'] = $request->has('is_enabled');
        if (empty($validated['provider'])) {
            $validated['provider'] = CloudStorageSetting::PROVIDER_APPS_SCRIPT_DRIVE;
        }

        $this->storageService->updateSettings($validated, Auth::user());

        return redirect()->route('admin.cloud-storage.index')
            ->with('success', 'Konfigurasi integrasi Google Drive & Apps Script Gateway berhasil disimpan.');
    }

    /**
     * Uji koneksi dan izin akses folder Google Drive secara read-only via drive_ping.
     */
    public function test(Request $request)
    {
        $result = $this->storageService->testConnection(null, Auth::user());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($result, $result['success'] ? 200 : 422);
        }

        if ($result['success']) {
            return redirect()->route('admin.cloud-storage.index')
                ->with('success', $result['message']);
        }

        return redirect()->route('admin.cloud-storage.index')
            ->with('error', $result['message']);
    }

    /**
     * Otorisasi dan aktifkan folder induk Google Drive secara resmi ke Google Apps Script Gateway (dengan persetujuan Admin).
     */
    public function activateRoot(Request $request)
    {
        $settings = $this->storageService->getSettings();
        $result = $this->storageService->activateAuthorizedDriveRoot($settings, Auth::user());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($result, $result['success'] ? 200 : 422);
        }

        if ($result['success']) {
            return redirect()->route('admin.cloud-storage.index')
                ->with('success', $result['message']);
        }

        return redirect()->route('admin.cloud-storage.index')
            ->with('error', $result['message']);
    }
}
