<?php

namespace App\Services\CloudStorage;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LocalStorageFallbackDriver implements CloudStorageProviderInterface
{
    protected string $diskName;
    protected string $basePrefix;
    protected ?string $rootFolderId = null;

    public function __construct(string $diskName = 'local', string $basePrefix = 'cloud_simulated')
    {
        $this->diskName = $diskName;
        $this->basePrefix = $basePrefix;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function getDriverName(): string
    {
        return 'local_fallback';
    }

    public function setRootFolderId(?string $folderId): self
    {
        $this->rootFolderId = $folderId;
        return $this;
    }

    public function getRootFolderId(): ?string
    {
        return $this->rootFolderId ?? 'sim_root';
    }

    public function getOrCreateFolderPath(string $folderPath): ?string
    {
        $cleanFolder = trim(str_replace('\\', '/', $folderPath), '/');
        $fullSimPath = "{$this->basePrefix}/{$cleanFolder}";
        Storage::disk($this->diskName)->makeDirectory($fullSimPath);

        return 'sim_folder_' . md5($cleanFolder);
    }

    public function uploadFile(string $localFullPath, string $remoteFolderPath, ?string $customFileName = null): array
    {
        if (!file_exists($localFullPath)) {
            throw new RuntimeException("Berkas fisik lokal tidak ditemukan: {$localFullPath}");
        }

        $fileName = $customFileName ?: basename($localFullPath);
        $cleanFolder = trim(str_replace('\\', '/', $remoteFolderPath), '/');
        $targetRelativePath = "{$this->basePrefix}/{$cleanFolder}/{$fileName}";

        $fileContent = file_get_contents($localFullPath);
        Storage::disk($this->diskName)->put($targetRelativePath, $fileContent);

        $fileId = 'local_sim_' . md5($targetRelativePath . time());
        $simulatedViewUrl = "sertif://simulated-storage/{$targetRelativePath}";
        $folderId = 'sim_folder_' . md5($cleanFolder);

        return [
            'file_id'       => $fileId,
            'web_view_link' => $simulatedViewUrl,
            'file_name'     => $fileName,
            'remote_path'   => $targetRelativePath,
            'folder_id'     => $folderId,
            'driver'        => $this->getDriverName(),
        ];
    }

    public function deleteFile(string $remoteFileId): bool
    {
        return true;
    }

    public function getFileMetadata(string $remoteFileId): array
    {
        return [
            'id'          => $remoteFileId,
            'name'        => 'simulated_file.bin',
            'size'        => 0,
            'mimeType'    => 'application/octet-stream',
            'trashed'     => false,
            'createdTime' => now()->toIso8601String(),
        ];
    }

    public function downloadFileBytes(string $remoteFileId): string
    {
        return "SIMULATED_CONTENT_FOR_{$remoteFileId}";
    }

    public function openFileStream(string $remoteFileId): mixed
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $this->downloadFileBytes($remoteFileId));
        rewind($stream);
        return $stream;
    }

    public function listChildren(string $folderId, ?string $pageToken = null, int $pageSize = 50): array
    {
        return [
            'files'         => [],
            'nextPageToken' => null,
        ];
    }

    public function searchWithinCanonicalRoot(string $canonicalRootId, string $query, ?string $pageToken = null, int $pageSize = 50): array
    {
        return [
            'files'         => [],
            'nextPageToken' => null,
        ];
    }
}
