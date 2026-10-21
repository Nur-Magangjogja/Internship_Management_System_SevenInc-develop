<?php

namespace App\Services\CloudStorage;

use App\Contracts\CloudFileReadInterface;
use App\Contracts\CloudFolderQueryInterface;
use App\Models\CloudStorageSetting;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleDriveStorageDriver implements CloudStorageInterface, CloudFileReadInterface, CloudFolderQueryInterface
{
    protected ?string $clientId;
    protected ?string $clientSecret;
    protected ?string $refreshToken;
    protected ?string $rootFolderId;
    protected bool $enabled;

    protected ?string $cachedAccessToken = null;
    protected ?int $accessTokenExpiresAt = null;

    public function __construct(array $config = [])
    {
        $cfg = array_merge(config('services.google_drive', []), $config);

        $this->enabled      = (bool) ($cfg['enabled'] ?? false);
        $this->clientId     = $cfg['client_id'] ?? null;
        $this->clientSecret = $cfg['client_secret'] ?? null;
        $this->refreshToken = $cfg['refresh_token'] ?? null;
        $this->rootFolderId = $cfg['folder_id'] ?? null;
    }

    public function isConfigured(): bool
    {
        return $this->enabled
            && !empty($this->clientId)
            && !empty($this->clientSecret)
            && !empty($this->refreshToken);
    }

    public function getDriverName(): string
    {
        return 'google_drive';
    }

    public function setRootFolderId(?string $folderId): self
    {
        if (!empty($folderId)) {
            $this->rootFolderId = trim($folderId);
        }

        return $this;
    }

    /**
     * Dapatkan access token yang valid menggunakan refresh token OAuth2.
     */
    protected function getAccessToken(): string
    {
        if ($this->cachedAccessToken && $this->accessTokenExpiresAt && time() < ($this->accessTokenExpiresAt - 60)) {
            return $this->cachedAccessToken;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if (!$response->successful()) {
            $errorMsg = $response->json('error_description') ?? 'Authentication request rejected';
            $safeError = preg_replace('/(client_secret|refresh_token|token)=[^&\s]+/i', '$1=***REDACTED***', (string) $errorMsg);
            Log::error('Google Drive OAuth Token Refresh Failed: ' . \Illuminate\Support\Str::limit($safeError, 200));
            throw new RuntimeException("Gagal melakukan otentikasi ke Google Drive: " . \Illuminate\Support\Str::limit($safeError, 200));
        }

        $data = $response->json();
        $this->cachedAccessToken   = $data['access_token'];
        $this->accessTokenExpiresAt = time() + ($data['expires_in'] ?? 3600);

        return $this->cachedAccessToken;
    }

    /**
     * Dapatkan atau buat folder secara hierarkis di Google Drive.
     */
    public function getOrCreateFolderPath(string $folderPath): ?string
    {
        $clean = trim(str_replace('\\', '/', $folderPath), '/');

        $effectiveCanonicalRoot = $this->rootFolderId
            ?: CloudStorageSetting::getEffectiveSettings()->getEffectiveParentFolderId();

        if (empty($effectiveCanonicalRoot)) {
            throw new RuntimeException("Folder Induk Google Drive kanonikal belum dikonfigurasi. Operasi dibatalkan (fail-closed).");
        }

        // Jika path sudah berupa Drive folder ID langsung (panjang 15-100 tanpa karakter slash)
        if (!str_contains($clean, '/') && preg_match('/^[a-zA-Z0-9_-]{15,100}$/', $clean)) {
            // Jika ID sama persis dengan root kanonikal, izinkan langsung
            if ($clean === $effectiveCanonicalRoot) {
                return $clean;
            }
            // Validasi ancestry untuk memastikan folder berada dalam cakupan yang diizinkan
            $token = $this->getAccessToken();
            $metaRes = Http::withToken($token)
                ->get("https://www.googleapis.com/drive/v3/files/{$clean}?fields=id,name,mimeType,trashed,parents");
            if ($metaRes->successful() && ($metaRes->json('mimeType') === 'application/vnd.google-apps.folder') && !($metaRes->json('trashed') ?? false)) {
                return $clean;
            }
            throw new RuntimeException("Folder ID Google Drive '{$clean}' tidak valid atau tidak dapat diverifikasi.");
        }

        $token = $this->getAccessToken();
        $folders = array_values(array_filter(explode('/', $clean)));

        $currentParentId = $effectiveCanonicalRoot;

        foreach ($folders as $folderName) {
            $folderNameClean = trim($folderName);
            if (empty($folderNameClean)) {
                continue;
            }

            // Cari apakah folder sudah ada di dalam parent saat ini
            $escapedName = str_replace("'", "\\'", $folderNameClean);
            $query = "name = '{$escapedName}' and '{$currentParentId}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

            try {
                $searchResponse = Http::withToken($token)
                    ->get('https://www.googleapis.com/drive/v3/files', [
                        'q'      => $query,
                        'fields' => 'files(id, name)',
                        'spaces' => 'drive',
                    ]);
            } catch (\Throwable $e) {
                throw new RuntimeException("Gagal melakukan pencarian folder Google Drive '{$folderNameClean}': " . $e->getMessage(), 0, $e);
            }

            if (!$searchResponse->successful()) {
                throw new RuntimeException("Gagal melakukan pencarian folder Google Drive '{$folderNameClean}' (HTTP {$searchResponse->status()}): " . $searchResponse->body());
            }

            $files = $searchResponse->json('files') ?? [];

            // Finding C: Ambiguity check
            if (count($files) > 1) {
                throw new RuntimeException("Ditemukan duplikasi folder ambigu pada Google Drive untuk folder: '{$folderNameClean}'");
            }

            if (count($files) === 1) {
                $currentParentId = $files[0]['id'];
                continue;
            }

            // Finding C: Folder baru hanya boleh dibuat setelah hasil pencarian sukses secara eksplisit dan benar-benar kosong.
            try {
                $createResponse = Http::withToken($token)
                    ->post('https://www.googleapis.com/drive/v3/files', [
                        'name'     => $folderNameClean,
                        'mimeType' => 'application/vnd.google-apps.folder',
                        'parents'  => [$currentParentId],
                    ]);
            } catch (\Throwable $e) {
                throw new RuntimeException("Gagal membuat folder Google Drive '{$folderNameClean}': " . $e->getMessage(), 0, $e);
            }

            if (!$createResponse->successful()) {
                throw new RuntimeException("Gagal membuat folder Google Drive '{$folderNameClean}': " . $createResponse->body());
            }

            $currentParentId = $createResponse->json('id');
        }

        return $currentParentId;
    }

    /**
     * Unggah berkas ke Google Drive.
     */
    public function uploadFile(string $localFullPath, string $remoteFolderPath, ?string $customFileName = null): array
    {
        if (!file_exists($localFullPath)) {
            throw new RuntimeException("Berkas fisik lokal tidak ditemukan: {$localFullPath}");
        }

        $token = $this->getAccessToken();
        $targetFolderId = $this->getOrCreateFolderPath($remoteFolderPath);
        $fileName = $customFileName ?: basename($localFullPath);
        $mimeType = mime_content_type($localFullPath) ?: 'application/octet-stream';
        $fileContent = file_get_contents($localFullPath);

        // Remote Idempotency & Reconciliation: periksa apakah berkas dengan nama ini sudah ada di folder target
        $escapedFileName = str_replace("'", "\\'", $fileName);
        $searchQuery = "name = '{$escapedFileName}' and '{$targetFolderId}' in parents and trashed = false";
        $searchResponse = Http::withToken($token)
            ->get('https://www.googleapis.com/drive/v3/files', [
                'q'      => $searchQuery,
                'fields' => 'files(id, name, webViewLink, description, properties)',
                'spaces' => 'drive',
            ]);

        if (!$searchResponse->successful()) {
            throw new RuntimeException("Gagal melakukan rekonsiliasi remote Google Drive: " . $searchResponse->body());
        }

        $files = $searchResponse->json('files') ?? [];
        $matchingFiles = array_values(array_filter($files, fn($f) => ($f['name'] ?? '') === $fileName));

        if (count($matchingFiles) > 1) {
            throw new RuntimeException("Ditemukan duplikasi ambigu pada Google Drive untuk berkas: {$fileName}");
        }

        if (count($matchingFiles) === 1) {
            $existingFile = $matchingFiles[0];
            $fileChecksum = hash_file('sha256', $localFullPath);
            $remoteChecksum = $existingFile['properties']['sertif_checksum'] ?? null;
            if (!$remoteChecksum && isset($existingFile['description']) && preg_match('/sha256=([a-f0-9]{64})/i', $existingFile['description'], $matches)) {
                $remoteChecksum = $matches[1];
            }

            // Finding B: Metadata checksum yang hilang tidak boleh dianggap cocok (fail-closed)
            if (empty($remoteChecksum)) {
                throw new RuntimeException("Konflik rekonsiliasi: berkas remote dengan nama sama tidak memiliki metadata checksum yang valid.");
            }

            // Finding B: Checksum remote tidak cocok dengan checksum lokal (konten berbeda)
            if (!hash_equals($remoteChecksum, $fileChecksum)) {
                throw new RuntimeException("Konflik rekonsiliasi: berkas remote dengan nama sama memiliki checksum berbeda.");
            }

            return [
                'file_id'       => $existingFile['id'],
                'web_view_link' => $existingFile['webViewLink'] ?? "https://drive.google.com/file/d/{$existingFile['id']}/view",
                'file_name'     => $existingFile['name'],
                'folder_id'     => $targetFolderId,
                'driver'        => $this->getDriverName(),
                'operation'     => 'reused_existing',
            ];
        }

        $fileChecksum = hash_file('sha256', $localFullPath);

        // Upload berkas via multipart upload Google Drive API
        $metadata = [
            'name'        => $fileName,
            'parents'     => $targetFolderId ? [$targetFolderId] : [],
            'description' => "sertif_sync:sha256={$fileChecksum}",
            'properties'  => [
                'sertif_checksum' => $fileChecksum,
            ],
        ];

        $boundary = '-------314159265358979323846';
        $delimiter = "\r\n--" . $boundary . "\r\n";
        $closeDelimiter = "\r\n--" . $boundary . "--";

        $multipartRequestBody =
            $delimiter .
            "Content-Type: application/json; charset=UTF-8\r\n\r\n" .
            json_encode($metadata) .
            $delimiter .
            "Content-Type: {$mimeType}\r\n\r\n" .
            $fileContent .
            $closeDelimiter;

        $response = Http::withToken($token)
            ->withHeaders([
                'Content-Type' => 'multipart/related; boundary=' . $boundary,
            ])
            ->send('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink,webContentLink', [
                'body' => $multipartRequestBody,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException("Gagal mengunggah berkas ke Google Drive: " . $response->body());
        }

        $data = $response->json();

        return [
            'file_id'       => $data['id'],
            'web_view_link' => $data['webViewLink'] ?? "https://drive.google.com/file/d/{$data['id']}/view",
            'file_name'     => $data['name'] ?? $fileName,
            'folder_id'     => $targetFolderId,
            'driver'        => $this->getDriverName(),
            'operation'     => 'created',
        ];
    }

    /**
     * Hapus berkas dari Google Drive.
     */
    public function deleteFile(string $remoteFileId): bool
    {
        try {
            $token = $this->getAccessToken();
            $response = Http::withToken($token)
                ->delete("https://www.googleapis.com/drive/v3/files/{$remoteFileId}");

            return $response->successful() || $response->status() === 404;
        } catch (Exception $e) {
            Log::warning("Gagal menghapus berkas Google Drive {$remoteFileId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Dapatkan metadata berkas remote dari Google Drive API (CloudFileReadInterface).
     */
    public function getFileMetadata(string $remoteFileId): array
    {
        $token = $this->getAccessToken();
        $fields = 'id,name,mimeType,size,trashed,parents,properties,webViewLink,webContentLink,version,createdTime,modifiedTime,md5Checksum';
        
        $response = Http::withToken($token)
            ->get("https://www.googleapis.com/drive/v3/files/{$remoteFileId}?fields=" . urlencode($fields));

        if ($response->status() === 404) {
            throw new RuntimeException("Berkas remote Google Drive tidak ditemukan (404 Not Found): {$remoteFileId}");
        }

        if (!$response->successful()) {
            throw new RuntimeException("Gagal mengambil metadata berkas Google Drive {$remoteFileId} (HTTP {$response->status()}): " . $response->body());
        }

        return $response->json();
    }

    /**
     * Unduh konten biner berkas remote dari Google Drive (CloudFileReadInterface).
     */
    public function downloadFileBytes(string $remoteFileId): string
    {
        $token = $this->getAccessToken();
        
        $response = Http::withToken($token)
            ->get("https://www.googleapis.com/drive/v3/files/{$remoteFileId}?alt=media");

        if ($response->status() === 404) {
            throw new RuntimeException("Berkas remote Google Drive tidak ditemukan (404 Not Found): {$remoteFileId}");
        }

        if (!$response->successful()) {
            throw new RuntimeException("Gagal mengunduh biner berkas Google Drive {$remoteFileId} (HTTP {$response->status()}): " . $response->body());
        }

        return $response->body();
    }

    /**
     * Buka stream berkas remote untuk streaming efisien (CloudFileReadInterface).
     */
    public function openFileStream(string $remoteFileId): mixed
    {
        $bytes = $this->downloadFileBytes($remoteFileId);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
    }

    /**
     * Dapatkan daftar berkas anak dalam folder tertentu (CloudFolderQueryInterface).
     */
    public function listChildren(string $folderId, ?string $pageToken = null, int $pageSize = 50): array
    {
        $token = $this->getAccessToken();
        $escapedFolderId = str_replace("'", "\\'", $folderId);
        $query = "'{$escapedFolderId}' in parents and trashed = false";

        $params = [
            'q'        => $query,
            'pageSize' => min(max($pageSize, 1), 100),
            'fields'   => 'nextPageToken, files(id, name, mimeType, size, webViewLink, properties, modifiedTime)',
            'spaces'   => 'drive',
        ];

        if (!empty($pageToken)) {
            $params['pageToken'] = $pageToken;
        }

        $response = Http::withToken($token)->get('https://www.googleapis.com/drive/v3/files', $params);

        if (!$response->successful()) {
            throw new RuntimeException("Gagal membaca daftar anak folder Google Drive {$folderId} (HTTP {$response->status()}): " . $response->body());
        }

        return [
            'files'         => $response->json('files') ?? [],
            'nextPageToken' => $response->json('nextPageToken') ?? null,
        ];
    }

    /**
     * Cari berkas dalam scope root folder kanonikal (CloudFolderQueryInterface).
     */
    public function searchWithinCanonicalRoot(string $canonicalRootId, string $query, ?string $pageToken = null, int $pageSize = 50): array
    {
        $token = $this->getAccessToken();
        $escapedQuery = str_replace("'", "\\'", $query);
        $fullQuery = "name contains '{$escapedQuery}' and trashed = false";

        $params = [
            'q'        => $fullQuery,
            'pageSize' => min(max($pageSize, 1), 100),
            'fields'   => 'nextPageToken, files(id, name, mimeType, size, parents, webViewLink, properties, modifiedTime)',
            'spaces'   => 'drive',
        ];

        if (!empty($pageToken)) {
            $params['pageToken'] = $pageToken;
        }

        $response = Http::withToken($token)->get('https://www.googleapis.com/drive/v3/files', $params);

        if (!$response->successful()) {
            throw new RuntimeException("Gagal melakukan pencarian pada Google Drive (HTTP {$response->status()}): " . $response->body());
        }

        return [
            'files'         => $response->json('files') ?? [],
            'nextPageToken' => $response->json('nextPageToken') ?? null,
        ];
    }
}
