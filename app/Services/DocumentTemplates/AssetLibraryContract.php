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
                        $isDrive = !empty($asset->drive_file_id);
                        $source = $isDrive ? 'Google Drive' : 'Penyimpanan Sistem';
                        $realAssets[$cat][] = [
                            'id'         => 'asset_' . $asset->id,
                            'asset_id'   => $asset->id,
                            'name'       => $asset->name,
                            'slot'       => $asset->category,
                            'url'        => $previewUrl,
                            'thumb'      => $previewUrl,
                            'tag'        => '{{' . ($asset->asset_code ?: 'asset.' . $asset->category) . '}}',
                            'asset_code' => $asset->asset_code,
                            'source'     => $source,
                            'is_drive'   => $isDrive,
                            'drive_id'   => $asset->drive_file_id,
                            'is_sample'  => false,
                            'is_real'    => true,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fallback gracefully jika koneksi DB terganggu
            }
        }

        // 1b. Muat aset dari tabel Brands (Setting Brand: background sertifikat magang, webinar, logo, dan tanda tangan)
        if (class_exists(\App\Models\Brand::class) && \Illuminate\Support\Facades\Schema::hasTable('brands')) {
            try {
                $brands = \App\Models\Brand::all();
                foreach ($brands as $brand) {
                    // Background Sertifikat Magang Brand
                    if (!empty($brand->internship_certificate_bg_url)) {
                        $url = $brand->internship_certificate_bg_url;
                        $realAssets['backgrounds'][] = [
                            'id'         => 'brand_bg_magang_' . $brand->id,
                            'name'       => "Background Sertifikat Magang — {$brand->name}",
                            'slot'       => 'background',
                            'url'        => $url,
                            'thumb'      => $url,
                            'tag'        => '{{asset.background}}',
                            'source'     => "Brand: {$brand->name}",
                            'is_sample'  => false,
                            'is_real'    => true,
                        ];
                    }
                    // Background Sertifikat Webinar Brand
                    if (!empty($brand->webinar_certificate_bg_url)) {
                        $url = $brand->webinar_certificate_bg_url;
                        $realAssets['backgrounds'][] = [
                            'id'         => 'brand_bg_webinar_' . $brand->id,
                            'name'       => "Background Sertifikat Webinar — {$brand->name}",
                            'slot'       => 'background',
                            'url'        => $url,
                            'thumb'      => $url,
                            'tag'        => '{{asset.background}}',
                            'source'     => "Brand: {$brand->name}",
                            'is_sample'  => false,
                            'is_real'    => true,
                        ];
                    }
                    // Logo Brand
                    if (!empty($brand->logo_url)) {
                        $url = $brand->logo_url;
                        $realAssets['logos'][] = [
                            'id'         => 'brand_logo_' . $brand->id,
                            'name'       => "Logo Brand — {$brand->name}",
                            'slot'       => 'company_logo',
                            'url'        => $url,
                            'thumb'      => $url,
                            'tag'        => '{{asset.company.logo}}',
                            'source'     => "Brand: {$brand->name}",
                            'is_sample'  => false,
                            'is_real'    => true,
                        ];
                    }
                    // Tanda Tangan Brand
                    if (!empty($brand->signature_url)) {
                        $url = $brand->signature_url;
                        $realAssets['signatures'][] = [
                            'id'         => 'brand_sig_' . $brand->id,
                            'name'       => "Tanda Tangan {$brand->signatory_name} ({$brand->name})",
                            'slot'       => 'signature',
                            'url'        => $url,
                            'thumb'      => $url,
                            'tag'        => '{{asset.signature}}',
                            'source'     => "Brand: {$brand->name}",
                            'is_sample'  => false,
                            'is_real'    => true,
                        ];
                    }
                    // Stempel Brand
                    if (!empty($brand->stamp_url)) {
                        $url = $brand->stamp_url;
                        $realAssets['stamps'][] = [
                            'id'         => 'brand_stamp_' . $brand->id,
                            'name'       => "Stempel Resmi ({$brand->name})",
                            'slot'       => 'stamp',
                            'url'        => $url,
                            'thumb'      => $url,
                            'tag'        => '{{asset.stamp}}',
                            'source'     => "Brand: {$brand->name}",
                            'is_sample'  => false,
                            'is_real'    => true,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fallback
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
                    'name'        => 'Background Border Emas Klasik (Bawaan)',
                    'slot'        => 'background',
                    'url'         => '/images/certificate/certificate-bg.png',
                    'thumb'       => '/images/certificate/certificate-bg.png',
                    'tag'         => '{{asset.background}}',
                    'source'      => 'Bawaan Sistem',
                    'is_sample'   => true,
                    'is_fallback' => true,
                    'is_drive'    => false,
                ],
            ],
        ];

        // 3. Scan dinamis berkas latar belakang tambahan di public/images/certificate/ jika ada
        $certDir = public_path('images/certificate');
        if (is_dir($certDir)) {
            $files = glob($certDir . '/*.{png,jpg,jpeg,webp,svg}', GLOB_BRACE) ?: [];
            foreach ($files as $file) {
                $filename = basename($file);
                if ($filename === 'certificate-bg.png') {
                    continue; // Sudah didaftarkan di atas
                }
                $url = '/images/certificate/' . $filename;
                $cleanTitle = ucwords(str_replace(['certificate-bg-', 'bg-', '-', '_', '.png', '.jpg', '.jpeg', '.webp', '.svg'], ' ', $filename));
                $fallbackCatalog['backgrounds'][] = [
                    'id'          => 'asset_bg_' . substr(md5($filename), 0, 8),
                    'name'        => "Background {$cleanTitle} (Bawaan)",
                    'slot'        => 'background',
                    'url'         => $url,
                    'thumb'       => $url,
                    'tag'         => '{{asset.background}}',
                    'source'      => 'Bawaan Sistem',
                    'is_sample'   => true,
                    'is_fallback' => true,
                    'is_drive'    => false,
                ];
            }
        }

        // 4. Gabungkan: Aset nyata diletakkan di urutan teratas, diikuti data contoh fallback
        $mergedCatalog = [];
        foreach (['logos', 'signatures', 'stamps', 'backgrounds'] as $category) {
            $merged = array_merge(
                $realAssets[$category] ?? [],
                $fallbackCatalog[$category] ?? []
            );

            // Deduplikasi berdasarkan URL untuk mencegah duplikasi entri
            $seenUrls = [];
            $uniqueList = [];
            foreach ($merged as $item) {
                $u = $item['url'] ?? '';
                if (!isset($seenUrls[$u])) {
                    $seenUrls[$u] = true;
                    $uniqueList[] = $item;
                }
            }
            $mergedCatalog[$category] = $uniqueList;
        }
        if (!empty($realAssets['letterheads'])) {
            $mergedCatalog['letterheads'] = $realAssets['letterheads'];
        }

        return $mergedCatalog;
    }
}
