<?php

use App\Models\Brand;
use App\Models\SystemAsset;
use App\Services\SystemAssetService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $assetService = app(SystemAssetService::class);

        // 1. Sinkronisasikan aset dari seluruh brand yang sudah ada ke SystemAsset
        $brands = Brand::all();
        foreach ($brands as $brand) {
            try {
                $assetService->syncBrandAssets($brand);
            } catch (\Throwable $e) {
                Log::warning("Gagal sinkronisasi aset untuk brand {$brand->name}: " . $e->getMessage());
            }
        }

        // 2. Daftarkan aset universal/global default jika berkas fisiknya ada
        $universalAssets = [
            [
                'asset_code' => 'asset.logo.seveninc_universal',
                'name'       => 'Logo SEVEN INC (Universal Default)',
                'category'   => SystemAsset::CATEGORY_LOGO,
                'scope'      => SystemAsset::SCOPE_GLOBAL,
                'path'       => 'images/mou/seven-inc-logo.png',
                'is_default' => true,
            ],
            [
                'asset_code' => 'asset.logo.institution_universal',
                'name'       => 'Logo Institusi Mitra (Universal Placeholder)',
                'category'   => SystemAsset::CATEGORY_LOGO,
                'scope'      => SystemAsset::SCOPE_GLOBAL,
                'path'       => 'images/mou/institution-logo-placeholder.png',
                'is_default' => false,
            ],
        ];

        foreach ($universalAssets as $u) {
            $fullPath = public_path($u['path']);
            if (file_exists($fullPath)) {
                $fileSize = filesize($fullPath);
                $checksum = hash_file('sha256', $fullPath);
                $mimeType = mime_content_type($fullPath) ?: 'image/png';

                SystemAsset::firstOrCreate(
                    ['asset_code' => $u['asset_code']],
                    [
                        'public_uuid'     => (string) Str::uuid(),
                        'name'            => $u['name'],
                        'category'        => $u['category'],
                        'scope'           => $u['scope'],
                        'brand_id'        => null,
                        'institution_id'  => null,
                        'mime_type'       => $mimeType,
                        'file_size_bytes' => $fileSize,
                        'checksum_sha256' => $checksum,
                        'local_path'      => '/' . ltrim($u['path'], '/'),
                        'version'         => 1,
                        'is_active'       => true,
                        'is_default'      => $u['is_default'],
                        'sync_status'     => SystemAsset::SYNC_STATUS_PENDING,
                    ]
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SystemAsset::whereIn('asset_code', [
            'asset.logo.seveninc_universal',
            'asset.logo.institution_universal',
        ])->delete();
    }
};
