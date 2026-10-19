<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CloudStorageSetting;
use App\Models\SystemAsset;
use App\Models\User;
use App\Services\CloudStorage\CloudStorageInterface;
use App\Services\CloudStorage\CloudSyncManager;
use App\Services\CloudStorage\GoogleDriveStorageDriver;
use App\Services\CloudStorage\LocalStorageFallbackDriver;
use App\Services\CloudStorage\SystemStorageConfigService;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class SystemAssetService
{
    protected CloudStorageInterface $cloudDriver;

    public function __construct(
        protected SystemStorageConfigService $storageConfigService,
        ?CloudStorageInterface $cloudDriver = null
    ) {
        if ($cloudDriver) {
            $this->cloudDriver = $cloudDriver;
        } else {
            $googleDriver = new GoogleDriveStorageDriver();
            $this->cloudDriver = $googleDriver->isConfigured() ? $googleDriver : new LocalStorageFallbackDriver();
        }
    }

    /**
     * Simpan file aset baru ke penyimpanan lokal privat dan daftarkan ke database.
     */
    public function storeAsset(UploadedFile $file, array $attributes, ?User $actor = null): SystemAsset
    {
        $category = $attributes['category'] ?? SystemAsset::CATEGORY_OTHER;
        if (!in_array($category, SystemAsset::VALID_CATEGORIES, true)) {
            throw new InvalidArgumentException("Kategori aset '{$category}' tidak valid.");
        }

        $scope = $attributes['scope'] ?? SystemAsset::SCOPE_GLOBAL;
        if (!in_array($scope, SystemAsset::VALID_SCOPES, true)) {
            throw new InvalidArgumentException("Scope aset '{$scope}' tidak valid.");
        }

        $mimeType = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream';
        $sizeBytes = $file->getSize();
        $checksum = hash_file('sha256', $file->getRealPath());

        // Penamaan aman lokal di disk 'local' (privat)
        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $safeFileName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), '_') . '_' . substr($checksum, 0, 10) . '.' . $extension;
        $relativeLocalDir = "assets/{$category}";
        $relativeLocalPath = "{$relativeLocalDir}/{$safeFileName}";

        Storage::disk('local')->putFileAs($relativeLocalDir, $file, $safeFileName);

        $isDefault = (bool) ($attributes['is_default'] ?? false);
        if ($isDefault) {
            $this->resetOtherDefaults($category, $scope, $attributes['brand_id'] ?? null, $attributes['institution_id'] ?? null);
        }

        $asset = SystemAsset::create([
            'public_uuid'      => (string) Str::uuid(),
            'name'             => trim($attributes['name'] ?? $file->getClientOriginalName()),
            'asset_code'       => !empty($attributes['asset_code']) ? trim($attributes['asset_code']) : null,
            'category'         => $category,
            'scope'            => $scope,
            'brand_id'         => !empty($attributes['brand_id']) ? (int) $attributes['brand_id'] : null,
            'institution_id'   => !empty($attributes['institution_id']) ? (int) $attributes['institution_id'] : null,
            'mime_type'        => $mimeType,
            'file_size_bytes'  => $sizeBytes,
            'checksum_sha256'  => $checksum,
            'local_path'       => $relativeLocalPath,
            'version'          => 1,
            'is_active'        => true,
            'is_default'       => $isDefault,
            'sync_status'      => SystemAsset::SYNC_STATUS_PENDING,
            'created_by'       => $actor?->id,
            'updated_by'       => $actor?->id,
        ]);

        ActivityLogger::log(
            action: 'system_asset_created',
            description: "Aset sistem '{$asset->name}' ({$asset->category}) berhasil ditambahkan.",
            subject: $asset,
            actor: $actor,
            metadata: [
                'category'  => $asset->category,
                'scope'     => $asset->scope,
                'checksum'  => $asset->checksum_sha256,
                'version'   => $asset->version,
            ]
        );

        // Jika auto-sync diaktifkan, coba lakukan sinkronisasi Drive di background
        try {
            $this->syncAssetToDrive($asset, $actor);
        } catch (\Throwable $e) {
            // Biarkan status pending jika belum berhasil connect
        }

        return $asset->fresh();
    }

    /**
     * Ganti berkas aset dengan versi baru (versi bertambah, berkas fisik lama tetap terjaga).
     */
    public function replaceVersion(SystemAsset $asset, UploadedFile $file, ?User $actor = null): SystemAsset
    {
        $newVersion = $asset->version + 1;
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream';
        $sizeBytes = $file->getSize();
        $checksum = hash_file('sha256', $file->getRealPath());

        $extension = $file->getClientOriginalExtension() ?: 'bin';
        $safeFileName = Str::slug($asset->name, '_') . "_v{$newVersion}_" . substr($checksum, 0, 8) . '.' . $extension;
        $relativeLocalDir = "assets/{$asset->category}";
        $relativeLocalPath = "{$relativeLocalDir}/{$safeFileName}";

        Storage::disk('local')->putFileAs($relativeLocalDir, $file, $safeFileName);

        $asset->update([
            'mime_type'          => $mimeType,
            'file_size_bytes'    => $sizeBytes,
            'checksum_sha256'    => $checksum,
            'local_path'         => $relativeLocalPath,
            'version'            => $newVersion,
            'drive_file_id'      => null, // Reset drive ID until new version is synced
            'drive_web_view_link'=> null,
            'sync_status'        => SystemAsset::SYNC_STATUS_PENDING,
            'drive_synced_at'    => null,
            'updated_by'         => $actor?->id,
        ]);

        ActivityLogger::log(
            action: 'system_asset_version_replaced',
            description: "Aset sistem '{$asset->name}' diperbarui ke versi {$newVersion}.",
            subject: $asset,
            actor: $actor,
            metadata: [
                'previous_version' => $newVersion - 1,
                'new_version'      => $newVersion,
                'checksum'         => $checksum,
            ]
        );

        try {
            $this->syncAssetToDrive($asset, $actor);
        } catch (\Throwable $e) {
            // Abaikan kegagalan upload awal
        }

        return $asset->fresh();
    }

    /**
     * Sinkronisasikan berkas aset ke Google Drive.
     */
    public function syncAssetToDrive(SystemAsset $asset, ?User $actor = null): array
    {
        if (!Storage::disk('local')->exists($asset->local_path)) {
            throw new RuntimeException("Berkas fisik lokal aset tidak ditemukan: {$asset->local_path}");
        }

        $fullPath = Storage::disk('local')->path($asset->local_path);
        $settings = $this->storageConfigService->getSettings();

        // Tentukan remote folder
        $categoryDir = Str::plural(Str::studly($asset->category));
        $remoteFolder = match ($asset->scope) {
            SystemAsset::SCOPE_BRAND       => "System_Assets/Brands/" . Str::slug($asset->brand?->name ?? 'brand', '_'),
            SystemAsset::SCOPE_INSTITUTION => "System_Assets/Institutions/" . Str::slug($asset->institution?->name ?? 'institution', '_'),
            default                        => "System_Assets/Global/{$categoryDir}",
        };

        // Konfigurasikan root folder sistem jika ada
        if (!empty($settings->system_assets_folder_id) && $this->cloudDriver instanceof GoogleDriveStorageDriver) {
            $this->cloudDriver->setRootFolderId($settings->system_assets_folder_id);
        }

        $fileName = Str::slug($asset->name, '_') . "_v{$asset->version}." . pathinfo($asset->local_path, PATHINFO_EXTENSION);

        try {
            $uploadResult = $this->cloudDriver->uploadFile($fullPath, $remoteFolder, $fileName);

            $isRealDrive = $this->cloudDriver->getDriverName() === 'google_drive'
                && !str_starts_with($uploadResult['file_id'], 'local_sim_')
                && !str_starts_with($uploadResult['file_id'], 'DRIVE-MOCK-');

            $asset->update([
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'sync_status'         => $isRealDrive ? SystemAsset::SYNC_STATUS_SYNCED : SystemAsset::SYNC_STATUS_PENDING,
                'drive_synced_at'     => $isRealDrive ? now() : null,
                'sync_error_message'  => $isRealDrive ? null : 'Simulasi lokal: Google Drive belum terkonfigurasi aktif.',
            ]);

            ActivityLogger::log(
                action: 'system_asset_drive_synced',
                description: "Aset '{$asset->name}' berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
                subject: $asset,
                actor: $actor,
                metadata: [
                    'drive_file_id'       => $uploadResult['file_id'],
                    'drive_web_view_link' => $uploadResult['web_view_link'],
                    'folder'              => $remoteFolder,
                    'driver'              => $uploadResult['driver'],
                ]
            );

            return $uploadResult;
        } catch (Exception $e) {
            $asset->update([
                'sync_status'        => SystemAsset::SYNC_STATUS_FAILED,
                'sync_error_message' => Str::limit($e->getMessage(), 500),
            ]);
            throw $e;
        }
    }

    /**
     * Resolusi aset aktif berdasarkan hierarki prioritas (Dokumen Override > Brand/Institusi > Global Default > Global).
     */
    public function resolveAsset(
        string $category,
        ?string $assetCode = null,
        ?int $brandId = null,
        ?int $institutionId = null
    ): ?SystemAsset {
        // 1. Cari berdasarkan kode aset spesifik jika ada
        if (!empty($assetCode)) {
            $asset = SystemAsset::active()->where('asset_code', $assetCode)->first();
            if ($asset) {
                return $asset;
            }
        }

        // 2. Cari aset Brand spesifik jika ada
        if ($brandId) {
            $brandAsset = SystemAsset::active()
                ->where('category', $category)
                ->where('scope', SystemAsset::SCOPE_BRAND)
                ->where('brand_id', $brandId)
                ->latest('is_default')
                ->latest('id')
                ->first();
            if ($brandAsset) {
                return $brandAsset;
            }
        }

        // 3. Cari aset Institusi spesifik jika ada
        if ($institutionId) {
            $instAsset = SystemAsset::active()
                ->where('category', $category)
                ->where('scope', SystemAsset::SCOPE_INSTITUTION)
                ->where('institution_id', $institutionId)
                ->latest('is_default')
                ->latest('id')
                ->first();
            if ($instAsset) {
                return $instAsset;
            }
        }

        // 4. Cari aset Global Default
        $globalDefault = SystemAsset::active()
            ->where('category', $category)
            ->where('scope', SystemAsset::SCOPE_GLOBAL)
            ->where('is_default', true)
            ->first();
        if ($globalDefault) {
            return $globalDefault;
        }

        // 5. Fallback ke aset Global manapun yang aktif
        return SystemAsset::active()
            ->where('category', $category)
            ->where('scope', SystemAsset::SCOPE_GLOBAL)
            ->latest('id')
            ->first();
    }

    /**
     * Kembalikan isi file lokal secara aman untuk streaming preview tanpa mengekspos public URL.
     */
    public function getAssetFileBinary(SystemAsset $asset): array
    {
        if (!Storage::disk('local')->exists($asset->local_path)) {
            throw new RuntimeException("Berkas aset fisik tidak ditemukan di server: {$asset->local_path}");
        }

        return [
            'content'   => Storage::disk('local')->get($asset->local_path),
            'mime_type' => $asset->mime_type,
            'size'      => $asset->file_size_bytes,
            'filename'  => basename($asset->local_path),
        ];
    }

    /**
     * Dapatkan representasi Data URI Base64 untuk rendering dokumen DomPDF secara stabil.
     */
    public function getAssetAsBase64DataUri(?SystemAsset $asset, ?string $fallbackRelativePath = null): ?string
    {
        if ($asset && Storage::disk('local')->exists($asset->local_path)) {
            $content = Storage::disk('local')->get($asset->local_path);
            return 'data:' . $asset->mime_type . ';base64,' . base64_encode($content);
        }

        if ($fallbackRelativePath) {
            $fullPath = public_path($fallbackRelativePath);
            if (file_exists($fullPath)) {
                $mime = mime_content_type($fullPath) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
            }
        }

        return null;
    }

    /**
     * Dapatkan model aset berdasarkan ID integer.
     */
    public function getAssetById(int $id): ?SystemAsset
    {
        return SystemAsset::find($id);
    }

    /**
     * Dapatkan model aset berdasarkan UUID public.
     */
    public function getAssetByUuid(string $uuid): ?SystemAsset
    {
        return SystemAsset::where('public_uuid', $uuid)->first();
    }

    /**
     * Dapatkan model aset berdasarkan kode variabel aset (misal: asset.company.logo).
     */
    public function getAssetByCode(string $code): ?SystemAsset
    {
        return SystemAsset::where('asset_code', $code)->first();
    }

    /**
     * Cari aset dengan filter dinamis untuk kebutuhan modal editor dokumen.
     */
    public function searchAssets(array $filters = [], int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = SystemAsset::with(['brand', 'institution'])->active();

        if (!empty($filters['q'])) {
            $s = trim($filters['q']);
            $query->where(function ($q) use ($s) {
                $q->where('name', 'LIKE', "%{$s}%")
                  ->orWhere('asset_code', 'LIKE', "%{$s}%");
            });
        }

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (!empty($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }

        if (!empty($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }

        if (!empty($filters['institution_id'])) {
            $query->where('institution_id', (int) $filters['institution_id']);
        }

        return $query->latest('is_default')->latest('id')->paginate($perPage);
    }

    /**
     * Dapatkan statistik penggunaan aset di seluruh dokumen yang telah digenerate.
     */
    public function getUsageStats(SystemAsset $asset): array
    {
        $generatedDocsCount = \App\Models\GeneratedDocument::whereRaw(
            "JSON_CONTAINS(asset_references, ?, '$')",
            [json_encode(['asset_id' => $asset->id])]
        )->count();

        return [
            'asset_id'               => $asset->id,
            'name'                   => $asset->name,
            'version'                => $asset->version,
            'generated_docs_count'   => $generatedDocsCount,
            'is_in_use'              => $generatedDocsCount > 0,
        ];
    }

    protected function resetOtherDefaults(string $category, string $scope, ?int $brandId, ?int $institutionId): void
    {
        SystemAsset::where('category', $category)
            ->where('scope', $scope)
            ->when($scope === SystemAsset::SCOPE_BRAND && $brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->when($scope === SystemAsset::SCOPE_INSTITUTION && $institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->update(['is_default' => false]);
    }
}
