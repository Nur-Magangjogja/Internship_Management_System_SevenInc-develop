<?php

namespace App\Services\DocumentTemplates;

class AssetLibraryContract
{
    /**
     * Ambil katalog aset yang tersedia untuk disisipkan ke editor.
     * Menggunakan mock aman jika endpoint remote Asset Registry belum aktif di worktree ini.
     * Beradaptasi otomatis jika model SystemAsset milik Antigravity 1 sudah aktif di database runtime.
     */
    public static function getAssetCatalog(): array
    {
        // 1. Ambil aset riil aktif dari SystemAsset Registry jika tersedia di runtime
        $realAssets = [
            'logos'       => [],
            'signatures'  => [],
            'stamps'      => [],
            'backgrounds' => [],
            'letterheads' => [],
        ];

        if (class_exists(\App\Models\SystemAsset::class) && \Illuminate\Support\Facades\Schema::hasTable('system_assets')) {
            try {
                $assets = \App\Models\SystemAsset::where('is_active', true)
                    ->orderBy('is_default', 'desc')
                    ->orderBy('id', 'asc')
                    ->get();

                foreach ($assets as $asset) {
                    $cat = match ($asset->category) {
                        'logo'       => 'logos',
                        'signature'  => 'signatures',
                        'stamp'      => 'stamps',
                        'background' => 'backgrounds',
                        'letterhead' => 'letterheads',
                        default      => null,
                    };

                    if ($cat) {
                        $previewUrl = route('admin.system-assets.preview', $asset);
                        $realAssets[$cat][] = [
                            'id'         => 'asset_' . $asset->id,
                            'asset_id'   => $asset->id,
                            'name'       => $asset->name,
                            'slot'       => $asset->category,
                            'url'        => $previewUrl,
                            'thumb'      => $previewUrl,
                            'tag'        => '{{' . ($asset->asset_code ?: 'asset.' . $asset->category) . '}}',
                            'asset_code' => $asset->asset_code,
                            'is_sample'  => false,
                            'is_real'    => true,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fallback gracefully jika koneksi DB terganggu
            }
        }

        // 2. Katalog fallback/contoh development statis (diberi tanda jelas)
        $fallbackCatalog = [
            'logos' => [
                [
                    'id'          => 'asset_logo_seven',
                    'name'        => '[Contoh/Fallback] Logo Resmi Seven Inc (Warna)',
                    'slot'        => 'company_logo',
                    'url'         => '/images/mou/seven-inc-logo.png',
                    'thumb'       => '/images/mou/seven-inc-logo.png',
                    'tag'         => '{{asset.company.logo}}',
                    'is_sample'   => true,
                    'is_fallback' => true,
                ],
                [
                    'id'          => 'asset_logo_institution',
                    'name'        => '[Contoh/Fallback] Logo Institusi (Placeholder)',
                    'slot'        => 'institution_logo',
                    'url'         => '/images/mou/institution-logo-placeholder.png',
                    'thumb'       => '/images/mou/institution-logo-placeholder.png',
                    'tag'         => '{{asset.institution.logo}}',
                    'is_sample'   => true,
                    'is_fallback' => true,
                ],
            ],
            'signatures' => [
                [
                    'id'          => 'asset_sig_director',
                    'name'        => '[Contoh/Fallback] Tanda Tangan Direktur Utama (Rekario Danny Sanjaya)',
                    'slot'        => 'signature',
                    'url'         => '/images/mou/signature-sample.png',
                    'thumb'       => '/images/mou/signature-sample.png',
                    'tag'         => '{{asset.signature}}',
                    'is_sample'   => true,
                    'is_fallback' => true,
                ],
            ],
            'stamps' => [
                [
                    'id'          => 'asset_stamp_official',
                    'name'        => '[Contoh/Fallback] Stempel Resmi Seven Inc (Transparan)',
                    'slot'        => 'stamp',
                    'url'         => '/images/mou/stamp-sample.png',
                    'thumb'       => '/images/mou/stamp-sample.png',
                    'tag'         => '{{asset.stamp}}',
                    'is_sample'   => true,
                    'is_fallback' => true,
                ],
            ],
            'backgrounds' => [
                [
                    'id'          => 'asset_bg_gold',
                    'name'        => '[Contoh/Fallback] Background Sertifikat Gold Border (A4 Landscape)',
                    'slot'        => 'background',
                    'url'         => '/images/certificate/certificate-bg.png',
                    'thumb'       => '/images/certificate/certificate-bg.png',
                    'tag'         => '{{asset.background}}',
                    'is_sample'   => true,
                    'is_fallback' => true,
                ],
            ],
        ];

        // 3. Gabungkan: Aset nyata diletakkan di urutan teratas, diikuti data contoh fallback
        $mergedCatalog = [];
        foreach (['logos', 'signatures', 'stamps', 'backgrounds'] as $category) {
            $mergedCatalog[$category] = array_merge(
                $realAssets[$category] ?? [],
                $fallbackCatalog[$category] ?? []
            );
        }
        if (!empty($realAssets['letterheads'])) {
            $mergedCatalog['letterheads'] = $realAssets['letterheads'];
        }

        return $mergedCatalog;
    }
}
