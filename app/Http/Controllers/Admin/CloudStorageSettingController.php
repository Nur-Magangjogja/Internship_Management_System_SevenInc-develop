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
        $appsScriptEndpoint = $spreadsheetConn?->apps_script_endpoint_url
            ?: config('services.google_apps_script.endpoint_url');
        $hasSharedSecret = !empty(config('services.google_apps_script.shared_secret'));

        $maskedEndpoint = null;
        if (!empty($appsScriptEndpoint)) {
            $parsed = parse_url($appsScriptEndpoint);
            $path = $parsed['path'] ?? '';
            $maskedEndpoint = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . substr($path, 0, 16) . '.../exec';
        }

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
            ->with('success', 'Konfigurasi penyimpanan cloud Google Drive berhasil disimpan.');
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
}
