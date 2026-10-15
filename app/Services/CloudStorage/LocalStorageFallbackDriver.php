<?php

namespace App\Services\CloudStorage;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LocalStorageFallbackDriver implements CloudStorageInterface
{
    protected string $diskName;
    protected string $basePrefix;

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

        return [
            'file_id'       => $fileId,
            'web_view_link' => $simulatedViewUrl,
            'file_name'     => $fileName,
            'remote_path'   => $targetRelativePath,
            'driver'        => $this->getDriverName(),
        ];
    }

    public function deleteFile(string $remoteFileId): bool
    {
        // Dalam implementasi simulasi, kembalikan true
        return true;
    }
}
