<?php

namespace App\Services\CloudStorage;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GoogleDriveStorageDriver implements CloudStorageInterface
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
            $errorMsg = $response->json('error_description') ?? $response->body();
            Log::error('Google Drive OAuth Token Refresh Failed: ' . $errorMsg);
            throw new RuntimeException("Gagal melakukan otentikasi ke Google Drive: {$errorMsg}");
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
        $token = $this->getAccessToken();
        $folders = array_values(array_filter(explode('/', str_replace('\\', '/', $folderPath))));

        $currentParentId = $this->rootFolderId ?: 'root';

        foreach ($folders as $folderName) {
            $folderNameClean = trim($folderName);
            if (empty($folderNameClean)) {
                continue;
            }

            // Cari apakah folder sudah ada di dalam parent saat ini
            $escapedName = str_replace("'", "\\'", $folderNameClean);
            $query = "name = '{$escapedName}' and '{$currentParentId}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";

            $searchResponse = Http::withToken($token)
                ->get('https://www.googleapis.com/drive/v3/files', [
                    'q'      => $query,
                    'fields' => 'files(id, name)',
                    'spaces' => 'drive',
                ]);

            if ($searchResponse->successful() && !empty($searchResponse->json('files'))) {
                $files = $searchResponse->json('files');
                $currentParentId = $files[0]['id'];
            } else {
                // Buat folder baru jika belum ada
                $createResponse = Http::withToken($token)
                    ->post('https://www.googleapis.com/drive/v3/files', [
                        'name'     => $folderNameClean,
                        'mimeType' => 'application/vnd.google-apps.folder',
                        'parents'  => [$currentParentId],
                    ]);

                if (!$createResponse->successful()) {
                    throw new RuntimeException("Gagal membuat folder Google Drive '{$folderNameClean}': " . $createResponse->body());
                }

                $currentParentId = $createResponse->json('id');
            }
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

        // Upload berkas via multipart upload Google Drive API
        $metadata = [
            'name'    => $fileName,
            'parents' => $targetFolderId ? [$targetFolderId] : [],
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
            'driver'        => $this->getDriverName(),
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
}
