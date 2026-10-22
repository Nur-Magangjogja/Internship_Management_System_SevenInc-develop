<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudStorageSetting;
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
     */
    public function index()
    {
        $settings = $this->storageService->getSettings();
        $registrationRef = $this->storageService->getRegistrationFolderReference();
        $driver = new \App\Services\CloudStorage\GoogleDriveStorageDriver();
        $isDriverConfigured = $driver->isConfigured();
        $spreadsheetConn = \App\Models\SpreadsheetConnection::where('connection_key', \App\Models\SpreadsheetConnection::KEY_REGISTRATION)->first();

        $missingEnvKeys = [];
        if (!config('services.google_drive.enabled')) {
            $missingEnvKeys[] = 'GOOGLE_DRIVE_ENABLED';
        }
        if (empty(config('services.google_drive.client_id'))) {
            $missingEnvKeys[] = 'GOOGLE_DRIVE_CLIENT_ID';
        }
        if (empty(config('services.google_drive.client_secret'))) {
            $missingEnvKeys[] = 'GOOGLE_DRIVE_CLIENT_SECRET';
        }
        if (empty(config('services.google_drive.refresh_token'))) {
            $missingEnvKeys[] = 'GOOGLE_DRIVE_REFRESH_TOKEN';
        }

        return view('admin.cloud-storage.index', [
            'settings'           => $settings,
            'registrationRef'    => $registrationRef,
            'isDriverConfigured' => $isDriverConfigured,
            'spreadsheetConn'    => $spreadsheetConn,
            'missingEnvKeys'     => $missingEnvKeys,
        ]);
    }

    /**
     * Perbarui konfigurasi folder Google Drive sistem.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'name'                          => 'required|string|max:255',
            'canonical_parent_folder_url'   => 'nullable|string|max:500',
            'system_assets_folder_url'      => 'nullable|string|max:500',
            'document_templates_folder_url' => 'nullable|string|max:500',
            'generated_docs_folder_url'     => 'nullable|string|max:500',
            'archives_folder_url'           => 'nullable|string|max:500',
            'is_enabled'                    => 'nullable|boolean',
        ]);

        $validated['is_enabled'] = $request->has('is_enabled');

        $this->storageService->updateSettings($validated, Auth::user());

        return redirect()->route('admin.cloud-storage.index')
            ->with('success', 'Konfigurasi folder induk penyimpanan cloud Google Drive berhasil disimpan.');
    }

    /**
     * Uji koneksi dan izin akses folder Google Drive.
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
