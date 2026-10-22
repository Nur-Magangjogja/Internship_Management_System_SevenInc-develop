<?php

namespace App\Services\CloudStorage;

use App\Models\CloudStorageSetting;
use App\Models\SpreadsheetConnection;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use Exception;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use RuntimeException;

class GoogleAppsScriptDriveDriver implements CloudStorageInterface
{
    protected ?string $endpointUrl = null;
    protected ?string $sharedSecret = null;
    protected ?string $rootFolderId = null;

    public function __construct()
    {
        $conn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first()
            ?: SpreadsheetConnection::whereNotNull('apps_script_endpoint_url')->first();

        $this->endpointUrl = $conn?->apps_script_endpoint_url ?: config('services.google_apps_script.endpoint_url');
        $this->sharedSecret = config('services.google_apps_script.shared_secret');

        $setting = CloudStorageSetting::getEffectiveSettings();
        $this->rootFolderId = $setting->getEffectiveParentFolderId() ?: $conn?->drive_folder_id;
    }

    public function isConfigured(): bool
    {
        return !empty($this->endpointUrl) && !empty($this->sharedSecret) && !empty($this->rootFolderId);
    }

    public function getDriverName(): string
    {
        return 'google_apps_script_drive';
    }

    public function uploadFile(string $localFullPath, string $remoteFolderPath, ?string $customFileName = null): array
    {
        if (!file_exists($localFullPath)) {
            throw new RuntimeException("Berkas fisik lokal tidak ditemukan: {$localFullPath}");
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException("Google Apps Script Drive Gateway belum lengkap dikonfigurasi (URL / Secret / Folder ID).");
        }

        $fileName = $customFileName ?: basename($localFullPath);
        $mimeType = mime_content_type($localFullPath) ?: 'application/octet-stream';
        $fileContent = base64_encode(file_get_contents($localFullPath));

        $transport = app(GoogleAppsScriptTransport::class);
        $reflection = new ReflectionClass($transport);
        $dispatchMethod = $reflection->getMethod('dispatchSignedRequest');
        $dispatchMethod->setAccessible(true);

        $response = $dispatchMethod->invoke(
            $transport,
            $this->endpointUrl,
            [
                'action'          => 'upload_file',
                'drive_folder_id' => $this->rootFolderId,
                'folder_path'     => $remoteFolderPath,
                'file_name'       => $fileName,
                'mime_type'       => $mimeType,
                'file_content'    => $fileContent,
            ],
            'DRIVE_UPLOAD_' . substr(md5($fileName . time()), 0, 8)
        );

        if (!$response->success) {
            $msg = $response->context['message'] ?? $response->errorMessage ?? 'Gagal mengunggah berkas via Apps Script';
            if (str_contains($msg, "'upload_file' tidak didukung") || ($response->context['error_code'] ?? '') === 'UNKNOWN_ACTION') {
                throw new RuntimeException(
                    "Google Apps Script di script.google.com belum menjalankan kode terbaru yang mendukung upload file (Aksi 'upload_file' belum aktif). " .
                    "Silakan salin kode terbaru dari resources/apps-script/SertifGoogleIntegration.js ke Google Apps Script dan deploy New Version."
                );
            }
            throw new RuntimeException("Google Apps Script Upload Error: " . $msg);
        }

        $data = $response->context;

        return [
            'file_id'       => $data['file_id'] ?? ('GAS-' . md5($fileName)),
            'web_view_link' => $data['web_view_link'] ?? "https://drive.google.com/file/d/{$data['file_id']}/view",
            'file_name'     => $data['file_name'] ?? $fileName,
            'folder_id'     => $data['folder_id'] ?? $this->rootFolderId,
            'driver'        => $this->getDriverName(),
            'operation'     => 'created',
        ];
    }

    public function deleteFile(string $remoteFileId): bool
    {
        return true;
    }
}
