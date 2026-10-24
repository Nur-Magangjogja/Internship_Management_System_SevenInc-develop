<?php

namespace App\Services;

use App\Contracts\CloudFileReadInterface;
use App\Contracts\CloudFolderQueryInterface;
use App\Models\CloudFileRegistry;
use App\Models\CloudFolderRegistry;
use App\Models\CloudStorageSetting;
use App\Models\User;
use App\Policies\FileAccessPolicy;
use App\Services\CloudStorage\CloudStorageInterface;
use App\Services\CloudStorage\CloudStorageProviderInterface;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class UniversalFileService
{
    protected CloudStorageInterface $driver;
    protected FileAccessPolicy $policy;

    public function __construct(?CloudStorageInterface $driver = null, ?FileAccessPolicy $policy = null)
    {
        $this->driver = $driver ?? app(CloudStorageInterface::class);
        $this->policy = $policy ?? new FileAccessPolicy();
    }

    /**
     * Dapatkan instance storage driver aktif.
     */
    public function getDriver(): CloudStorageInterface
    {
        return $this->driver;
    }

    /**
     * Resolusi folder path hierarkis target sesuai standar Addendum Final V4 Bagian 5.2.
     */
    public function resolveFolderPath(string $category, string $ownerType, int|string $ownerId, ?int $year = null): string
    {
        $currentYear = $year ?: (int) date('Y');

        return match ($category) {
            CloudFileRegistry::CATEGORY_CV => "REGISTRASI/{$currentYear}/REG-{$ownerId}/CV",
            CloudFileRegistry::CATEGORY_IDENTITY => "REGISTRASI/{$currentYear}/REG-{$ownerId}/IDENTITAS",
            CloudFileRegistry::CATEGORY_PORTFOLIO => "REGISTRASI/{$currentYear}/REG-{$ownerId}/PORTOFOLIO",
            CloudFileRegistry::CATEGORY_RECOMMENDATION_LETTER => "REGISTRASI/{$currentYear}/REG-{$ownerId}/SURAT-PENGANTAR",
            CloudFileRegistry::CATEGORY_PROPOSAL => "REGISTRASI/{$currentYear}/REG-{$ownerId}/PROPOSAL",
            CloudFileRegistry::CATEGORY_BRAND_LOGO,
            CloudFileRegistry::CATEGORY_BRAND_SIGNATURE => "SYSTEM-ASSETS/BRANDS/{$ownerId}",
            CloudFileRegistry::CATEGORY_INSTITUTION_LOGO,
            CloudFileRegistry::CATEGORY_INSTITUTION_SIGNATURE => "SYSTEM-ASSETS/INSTITUTIONS/{$ownerId}",
            CloudFileRegistry::CATEGORY_ISSUED_DOCUMENT => "GENERATED-DOCUMENTS/ISSUED/{$currentYear}/{$ownerId}",
            default => "REGISTRASI/{$currentYear}/REG-{$ownerId}/UMUM",
        };
    }

    /**
     * Lazy-create folder Google Drive dengan mutex/lock untuk mencegah duplikasi (C11, C12).
     */
    public function getOrCreateFolder(
        string $folderPath,
        ?string $ownerType = null,
        ?int $ownerId = null,
        string $category = 'GENERAL'
    ): CloudFolderRegistry {
        $setting = CloudStorageSetting::getEffectiveSettings();
        $canonicalRootId = $setting->getEffectiveParentFolderId();

        if (empty($canonicalRootId)) {
            throw new RuntimeException("Folder Induk Google Drive kanonikal belum dikonfigurasi.");
        }

        $cleanPath = trim(str_replace('\\', '/', $folderPath), '/');

        // Periksa registry database terlebih dahulu
        $existing = CloudFolderRegistry::where('canonical_root_id', $canonicalRootId)
            ->where('folder_path', $cleanPath)
            ->first();

        if ($existing && !empty($existing->drive_folder_id)) {
            return $existing;
        }

        // Mutex lock untuk mencegah duplikasi folder saat dua upload bersamaan (C11)
        $lockKey = 'drive_folder_lock_' . md5($canonicalRootId . ':' . $cleanPath);
        $lock = Cache::lock($lockKey, 20);

        try {
            $lock->block(15);

            // Double check setelah mendapatkan lock
            $existing = CloudFolderRegistry::where('canonical_root_id', $canonicalRootId)
                ->where('folder_path', $cleanPath)
                ->first();

            if ($existing && !empty($existing->drive_folder_id)) {
                return $existing;
            }

            // Buat remote folder via driver
            $driveFolderId = method_exists($this->driver, 'getOrCreateFolderPath')
                ? $this->driver->getOrCreateFolderPath($cleanPath)
                : null;

            if (empty($driveFolderId)) {
                throw new RuntimeException("Gagal memperoleh ID folder Google Drive untuk path: {$cleanPath}");
            }

            return CloudFolderRegistry::findOrRegisterFolder(
                canonicalRootId: $canonicalRootId,
                folderPath: $cleanPath,
                driveFolderId: $driveFolderId,
                ownerType: $ownerType,
                ownerId: $ownerId,
                category: $category
            );
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Simpan berkas melalui Laravel Google Drive API (Write Cutover F6, C21, C22, C23, C24).
     */
    public function put(
        UploadedFile|string $fileOrContent,
        string $category,
        string $ownerType,
        int|string $ownerId,
        ?string $customFileName = null,
        ?string $customMimeType = null,
        ?User $actor = null,
        ?int $issuanceId = null
    ): CloudFileRegistry {
        $setting = CloudStorageSetting::getEffectiveSettings();
        $canonicalRootId = $setting->getEffectiveParentFolderId();

        // 1. Ekstraksi konten, ukuran, MIME, dan SHA-256 lokal
        $logicalFileName = $customFileName;
        $mimeType = $customMimeType;
        $tempLocalRelPath = 'cloud_temp/' . (string) Str::uuid();

        if ($fileOrContent instanceof UploadedFile) {
            $logicalFileName = $logicalFileName ?: $fileOrContent->getClientOriginalName();
            $mimeType = $mimeType ?: ($fileOrContent->getMimeType() ?: 'application/octet-stream');
            $byteSize = $fileOrContent->getSize();
            $checksum = hash_file('sha256', $fileOrContent->getRealPath());

            // Simpan ke private local temp cache
            $fileOrContent->storeAs('cloud_temp', basename($tempLocalRelPath), 'private');
        } elseif (is_string($fileOrContent) && file_exists($fileOrContent)) {
            $logicalFileName = $logicalFileName ?: basename($fileOrContent);
            $mimeType = $mimeType ?: (mime_content_type($fileOrContent) ?: 'application/octet-stream');
            $byteSize = filesize($fileOrContent);
            $checksum = hash_file('sha256', $fileOrContent);

            $bytes = file_get_contents($fileOrContent);
            Storage::disk('private')->put($tempLocalRelPath, $bytes);
        } else {
            // Binary string
            $bytes = (string) $fileOrContent;
            $logicalFileName = $logicalFileName ?: 'file_' . time() . '.bin';
            $mimeType = $mimeType ?: 'application/octet-stream';
            $byteSize = strlen($bytes);
            $checksum = hash('sha256', $bytes);

            Storage::disk('private')->put($tempLocalRelPath, $bytes);
        }

        $localAbsPath = Storage::disk('private')->path($tempLocalRelPath);

        // 2. Buat record awal di registry dengan status QUEUED
        $registry = CloudFileRegistry::create([
            'public_uuid'                => (string) Str::uuid(),
            'owner_type'                 => $ownerType,
            'owner_id'                   => (int) $ownerId,
            'category'                   => $category,
            'logical_filename'           => $logicalFileName,
            'provider'                   => $this->driver->getDriverName(),
            'canonical_root_id'          => $canonicalRootId,
            'byte_size'                  => $byteSize,
            'mime_type'                  => $mimeType,
            'checksum_sha256'            => $checksum,
            'is_current'                 => true,
            'sync_status'                => CloudFileRegistry::STATUS_QUEUED,
            'generated_from_issuance_id' => $issuanceId,
            'created_by'                 => $actor?->id,
        ]);

        // 3. Resolusi folder anak sesuai struktur hierarki
        $folderPath = $this->resolveFolderPath($category, $ownerType, $ownerId);

        try {
            $registry->update(['sync_status' => CloudFileRegistry::STATUS_UPLOADING]);

            $folderRegistry = $this->getOrCreateFolder($folderPath, $ownerType, (int) $ownerId, $category);
            $registry->update(['drive_folder_id' => $folderRegistry->drive_folder_id]);

            // 4. Unggah ke Google Drive API
            $uploadResult = $this->driver->uploadFile(
                localFullPath: $localAbsPath,
                remoteFolderPath: $folderRegistry->drive_folder_id,
                customFileName: $logicalFileName
            );

            $driveFileId = $uploadResult['file_id'] ?? null;
            if (empty($driveFileId)) {
                throw new RuntimeException("Upload remote selesai tetapi file_id Google Drive kosong.");
            }

            // 5. Verifikasi dan Update Status Registry
            $registry->update([
                'drive_file_id'    => $driveFileId,
                'sync_status'      => CloudFileRegistry::STATUS_VERIFIED,
                'verified_at'      => now(),
                'provider_version' => $uploadResult['operation'] ?? 'created',
                'error_message'    => null,
            ]);

            // Nonaktifkan versi lama jika bukan kategori multi-file portofolio
            if ($category !== CloudFileRegistry::CATEGORY_PORTFOLIO) {
                CloudFileRegistry::where('owner_type', $ownerType)
                    ->where('owner_id', (int) $ownerId)
                    ->where('category', $category)
                    ->where('id', '!=', $registry->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);
            }

            return $registry->fresh();
        } catch (\Throwable $e) {
            Log::error("UniversalFileService put error: " . $e->getMessage());

            $registry->update([
                'sync_status'   => CloudFileRegistry::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Dapatkan model berkas dari registry dengan validasi otorisasi RBAC (C18, C19).
     */
    public function get(string $publicUuid, ?User $actor = null): CloudFileRegistry
    {
        $file = CloudFileRegistry::where('public_uuid', $publicUuid)->firstOrFail();

        if ($actor && !$this->policy->view($actor, $file)) {
            abort(403, 'Akses ditolak: Anda tidak memiliki wewenang untuk melihat berkas ini.');
        }

        $file->update(['last_accessed_at' => now()]);

        return $file;
    }

    /**
     * Unduh konten biner berkas registry dengan validasi integritas checksum (C18, C20).
     */
    public function downloadBytes(CloudFileRegistry $file, ?User $actor = null): string
    {
        if ($actor && !$this->policy->download($actor, $file)) {
            abort(403, 'Akses ditolak: Anda tidak memiliki wewenang untuk mengunduh berkas ini.');
        }

        // 1. Cek private local cache
        $cacheRelPath = "cloud_cache/{$file->public_uuid}_" . $file->logical_filename;
        if (Storage::disk('private')->exists($cacheRelPath)) {
            $bytes = Storage::disk('private')->get($cacheRelPath);
            $localChecksum = hash('sha256', $bytes);

            if ($file->checksum_sha256 && hash_equals($file->checksum_sha256, $localChecksum)) {
                $file->update(['last_accessed_at' => now()]);
                return $bytes;
            }
        }

        // 2. Unduh dari Google Drive jika cache lokal belum ada atau checksum mismatch
        if (empty($file->drive_file_id)) {
            throw new RuntimeException("Berkas belum terverifikasi di Google Drive.");
        }

        if (!method_exists($this->driver, 'downloadFileBytes')) {
            throw new RuntimeException("Driver penyimpanan tidak mendukung pengunduhan berkas biner.");
        }

        $bytes = $this->driver->downloadFileBytes($file->drive_file_id);

        if ($file->checksum_sha256) {
            $fetchedChecksum = hash('sha256', $bytes);
            if (!hash_equals($file->checksum_sha256, $fetchedChecksum)) {
                throw new RuntimeException("Integritas berkas terganggu: checksum remote tidak cocok dengan data tercatat.");
            }
        }

        // Simpan ke private local cache
        Storage::disk('private')->put($cacheRelPath, $bytes);

        $file->update(['last_accessed_at' => now()]);

        return $bytes;
    }

    /**
     * Pratinjau berkas (stream/preview data).
     */
    public function preview(CloudFileRegistry $file, ?User $actor = null): array
    {
        $bytes = $this->downloadBytes($file, $actor);

        return [
            'bytes'     => $bytes,
            'mime_type' => $file->mime_type,
            'filename'  => $file->logical_filename,
            'size'      => strlen($bytes),
        ];
    }

    /**
     * Dapatkan daftar berkas milik entitas tertentu (C21 multi-file portfolio).
     */
    public function listByOwner(string $ownerType, int|string $ownerId, ?string $category = null): Collection
    {
        $query = CloudFileRegistry::where('owner_type', $ownerType)
            ->where('owner_id', (int) $ownerId)
            ->where('is_current', true);

        if (!empty($category)) {
            $query->where('category', $category);
        }

        return $query->latest('id')->get();
    }
}
