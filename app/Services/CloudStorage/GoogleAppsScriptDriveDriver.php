<?php

namespace App\Services\CloudStorage;

use App\Models\CloudStorageSetting;
use App\Models\SpreadsheetConnection;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleAppsScriptDriveDriver implements CloudStorageProviderInterface
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

    public function setRootFolderId(?string $folderId): self
    {
        if (!empty($folderId)) {
            $this->rootFolderId = trim($folderId);
        }

        return $this;
    }

    public function getRootFolderId(): ?string
    {
        return $this->rootFolderId;
    }

    public function getOrCreateFolderPath(string $folderPath): ?string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Google Apps Script Drive Gateway belum lengkap dikonfigurasi (URL / Secret / Folder ID).");
        }

        $cleanPath = trim(str_replace('\\', '/', $folderPath), '/');
        if (empty($cleanPath)) {
            return (string) $this->rootFolderId;
        }

        $transport = app(GoogleAppsScriptTransport::class);
        $transport->setTimeoutSeconds(120);
        $response = $transport->dispatchSignedRequest(
            $this->endpointUrl,
            [
                'action'          => 'drive_resolve_folder',
                'drive_folder_id' => $this->rootFolderId,
                'folder_path'     => $cleanPath,
            ],
            'DRIVE_FOLDER_' . substr(md5($cleanPath . time()), 0, 8)
        );

        if (!$response->success) {
            $err = $response->errorMessage ?: 'Gagal resolusi folder di Apps Script';
            Log::warning("GoogleAppsScriptDriveDriver getOrCreateFolderPath: {$err}");
            return (string) ($response->context['folder_id'] ?? $this->rootFolderId);
        }

        return (string) ($response->context['folder_id'] ?? $this->rootFolderId);
    }

    public function uploadFile(string $localFullPath, string $remoteFolderPath, ?string $customFileName = null): array
    {
        if (!file_exists($localFullPath)) {
            throw new RuntimeException("Berkas fisik lokal tidak ditemukan: {$localFullPath}");
        }

        if (filesize($localFullPath) > 10 * 1024 * 1024) {
            throw new RuntimeException("Ukuran berkas melebihi batas maksimum 10 MB untuk transfer Google Apps Script.");
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException("Google Apps Script Drive Gateway belum lengkap dikonfigurasi (URL / Secret / Folder ID).");
        }

        $fileName = $customFileName ?: basename($localFullPath);
        $mimeType = mime_content_type($localFullPath) ?: 'application/octet-stream';
        $fileContent = base64_encode(file_get_contents($localFullPath));
        $fileChecksum = hash_file('sha256', $localFullPath);
        $fileSize = filesize($localFullPath);

        $transport = app(GoogleAppsScriptTransport::class);
        $uploadTimeout = max(90, min(300, (int) ceil($fileSize / (100 * 1024)) * 3));
        $transport->setTimeoutSeconds($uploadTimeout);

        $response = $transport->dispatchSignedRequest(
            $this->endpointUrl,
            [
                'action'          => 'drive_upload',
                'drive_folder_id' => $this->rootFolderId,
                'folder_path'     => $remoteFolderPath,
                'file_name'       => $fileName,
                'mime_type'       => $mimeType,
                'file_content'    => $fileContent,
                'checksum_sha256' => $fileChecksum,
            ],
            'DRIVE_UPLOAD_' . substr(md5($fileName . time()), 0, 8)
        );

        // Kompatibilitas jika Apps Script versi lama merespon UNKNOWN_ACTION untuk drive_upload, coba fallback upload_file
        if (!$response->success && (($response->context['error_code'] ?? '') === 'UNKNOWN_ACTION' || str_contains($response->errorMessage ?? '', 'tidak didukung'))) {
            $response = $transport->dispatchSignedRequest(
                $this->endpointUrl,
                [
                    'action'          => 'upload_file',
                    'drive_folder_id' => $this->rootFolderId,
                    'folder_path'     => $remoteFolderPath,
                    'file_name'       => $fileName,
                    'mime_type'       => $mimeType,
                    'file_content'    => $fileContent,
                    'checksum_sha256' => $fileChecksum,
                ],
                'DRIVE_UPLOAD_FB_' . substr(md5($fileName . time()), 0, 8)
            );
        }

        if (!$response->success) {
            $msg = $response->context['message'] ?? $response->errorMessage ?? 'Gagal mengunggah berkas via Apps Script';
            throw new RuntimeException("Google Apps Script Upload Error: " . $msg);
        }

        $data = $response->context;
        $remoteFileId = $data['file_id'] ?? null;

        if (empty($remoteFileId) || str_starts_with($remoteFileId, 'GAS-')) {
            throw new RuntimeException("Google Apps Script upload berhasil tetapi respons remote tidak memuat ID file Google Drive yang sah.");
        }

        if (!empty($data['checksum_sha256'])) {
            if (!hash_equals(strtolower((string) $data['checksum_sha256']), strtolower((string) $fileChecksum))) {
                throw new RuntimeException("Integritas berkas terganggu: Checksum SHA-256 remote tidak sesuai dengan file lokal.");
            }
        }

        return [
            'file_id'       => $remoteFileId,
            'web_view_link' => $data['web_view_link'] ?? "https://drive.google.com/file/d/{$remoteFileId}/view",
            'file_name'     => $data['file_name'] ?? $fileName,
            'folder_id'     => $data['folder_id'] ?? $this->rootFolderId,
            'driver'        => $this->getDriverName(),
            'operation'     => $data['operation'] ?? 'created',
            'checksum'      => $data['checksum_sha256'] ?? $fileChecksum,
            'size'          => $data['size_bytes'] ?? filesize($localFullPath),
        ];
    }

    public function deleteFile(string $remoteFileId): bool
    {
        if (empty($remoteFileId) || !$this->isConfigured()) {
            return false;
        }

        $transport = app(GoogleAppsScriptTransport::class);
        $transport->setTimeoutSeconds(120);
        $response = $transport->dispatchSignedRequest(
            $this->endpointUrl,
            [
                'action'          => 'drive_trash',
                'drive_folder_id' => $this->rootFolderId,
                'file_id'         => $remoteFileId,
            ],
            'DRIVE_TRASH_' . substr(md5($remoteFileId . time()), 0, 8)
        );

        return $response->success && ($response->context['trashed'] ?? false) === true;
    }

    public function getFileMetadata(string $remoteFileId): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Google Apps Script Drive Gateway belum lengkap dikonfigurasi.");
        }

        $transport = app(GoogleAppsScriptTransport::class);
        $transport->setTimeoutSeconds(120);
        $response = $transport->dispatchSignedRequest(
            $this->endpointUrl,
            [
                'action'          => 'drive_get_metadata',
                'drive_folder_id' => $this->rootFolderId,
                'file_id'         => $remoteFileId,
            ],
            'DRIVE_META_' . substr(md5($remoteFileId . time()), 0, 8)
        );

        if (!$response->success) {
            throw new RuntimeException("Gagal mengambil metadata berkas dari Apps Script: " . ($response->errorMessage ?: 'Unknown error'));
        }

        return $response->context['metadata'] ?? [
            'id'          => $remoteFileId,
            'name'        => $response->context['file_name'] ?? 'remote_file',
            'size'        => (int) ($response->context['size_bytes'] ?? 0),
            'mimeType'    => $response->context['mime_type'] ?? 'application/octet-stream',
            'trashed'     => false,
            'createdTime' => $response->context['created_time'] ?? now()->toIso8601String(),
        ];
    }

    public function downloadFileBytes(string $remoteFileId): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Google Apps Script Drive Gateway belum lengkap dikonfigurasi.");
        }

        $transport = app(GoogleAppsScriptTransport::class);
        $transport->setTimeoutSeconds(120);
        $response = $transport->dispatchSignedRequest(
            $this->endpointUrl,
            [
                'action'          => 'drive_download_chunk',
                'drive_folder_id' => $this->rootFolderId,
                'file_id'         => $remoteFileId,
            ],
            'DRIVE_DOWNLOAD_' . substr(md5($remoteFileId . time()), 0, 8)
        );

        if (!$response->success) {
            throw new RuntimeException("Gagal mengunduh berkas dari Apps Script: " . ($response->errorMessage ?: 'Unknown error'));
        }

        $base64 = $response->context['file_content'] ?? '';
        if (empty($base64)) {
            throw new RuntimeException("Konten berkas dari Apps Script kosong.");
        }

        $rawBytes = base64_decode($base64);
        $expectedChecksum = $response->context['checksum_sha256'] ?? null;
        if (!empty($expectedChecksum)) {
            $actualChecksum = hash('sha256', $rawBytes);
            if (!hash_equals(strtolower((string) $expectedChecksum), strtolower((string) $actualChecksum))) {
                throw new RuntimeException("Integritas berkas terganggu: Checksum SHA-256 hasil unduhan tidak cocok.");
            }
        }

        return $rawBytes;
    }

    public function openFileStream(string $remoteFileId): mixed
    {
        $bytes = $this->downloadFileBytes($remoteFileId);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
    }

    public function listChildren(string $folderId, ?string $pageToken = null, int $pageSize = 50): array
    {
        if (!$this->isConfigured()) {
            return ['files' => [], 'nextPageToken' => null];
        }

        $transport = app(GoogleAppsScriptTransport::class);
        $transport->setTimeoutSeconds(120);
        $response = $transport->dispatchSignedRequest(
            $this->endpointUrl,
            [
                'action'          => 'drive_list_children',
                'drive_folder_id' => $this->rootFolderId,
                'folder_id'       => $folderId,
                'page_token'      => $pageToken,
                'page_size'       => $pageSize,
            ],
            'DRIVE_LIST_' . substr(md5($folderId . time()), 0, 8)
        );

        if (!$response->success) {
            return ['files' => [], 'nextPageToken' => null];
        }

        return [
            'files'         => $response->context['files'] ?? [],
            'nextPageToken' => $response->context['next_page_token'] ?? null,
        ];
    }

    public function searchWithinCanonicalRoot(string $canonicalRootId, string $query, ?string $pageToken = null, int $pageSize = 50): array
    {
        if (!$this->isConfigured()) {
            return ['files' => [], 'nextPageToken' => null];
        }

        $transport = app(GoogleAppsScriptTransport::class);
        $transport->setTimeoutSeconds(120);
        $response = $transport->dispatchSignedRequest(
            $this->endpointUrl,
            [
                'action'          => 'drive_search_files',
                'drive_folder_id' => $canonicalRootId ?: $this->rootFolderId,
                'query'           => $query,
                'page_token'      => $pageToken,
                'page_size'       => $pageSize,
            ],
            'DRIVE_SEARCH_' . substr(md5($query . time()), 0, 8)
        );

        if (!$response->success) {
            return ['files' => [], 'nextPageToken' => null];
        }

        return [
            'files'         => $response->context['files'] ?? [],
            'nextPageToken' => $response->context['next_page_token'] ?? null,
        ];
    }
}
