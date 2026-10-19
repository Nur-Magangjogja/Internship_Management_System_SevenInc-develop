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
        $catalog = [
            'logos' => [
                [
                    'id'    => 'asset_logo_seven',
                    'name'  => 'Logo Resmi Seven Inc (Warna)',
                    'slot'  => 'company_logo',
                    'url'   => '/images/mou/seven-inc-logo.png',
                    'thumb' => '/images/mou/seven-inc-logo.png',
                    'tag'   => '{{asset.company.logo}}',
                ],
                [
                    'id'    => 'asset_logo_institution',
                    'name'  => 'Logo Institusi (Placeholder)',
                    'slot'  => 'institution_logo',
                    'url'   => '/images/mou/institution-logo-placeholder.png',
                    'thumb' => '/images/mou/institution-logo-placeholder.png',
                    'tag'   => '{{asset.institution.logo}}',
                ],
            ],
            'signatures' => [
                [
                    'id'    => 'asset_sig_director',
                    'name'  => 'Tanda Tangan Direktur Utama (Rekario Danny Sanjaya)',
                    'slot'  => 'signature',
                    'url'   => '/images/mou/signature-sample.png',
                    'thumb' => '/images/mou/signature-sample.png',
                    'tag'   => '{{asset.signature}}',
                ],
            ],
            'stamps' => [
                [
                    'id'    => 'asset_stamp_official',
                    'name'  => 'Stempel Resmi Seven Inc (Transparan)',
                    'slot'  => 'stamp',
                    'url'   => '/images/mou/stamp-sample.png',
                    'thumb' => '/images/mou/stamp-sample.png',
                    'tag'   => '{{asset.stamp}}',
                ],
            ],
            'backgrounds' => [
                [
                    'id'    => 'asset_bg_gold',
                    'name'  => 'Background Sertifikat Gold Border (A4 Landscape)',
                    'slot'  => 'background',
                    'url'   => '/images/certificate/certificate-bg.png',
                    'thumb' => '/images/certificate/certificate-bg.png',
                    'tag'   => '{{asset.background}}',
                ],
            ],
        ];

        // Jika SystemAsset aktif di database runtime, integrasikan item dinamis
        if (class_exists(\App\Models\SystemAsset::class) && \Illuminate\Support\Facades\Schema::hasTable('system_assets')) {
            try {
                $assets = \App\Models\SystemAsset::where('is_active', true)->get();
                foreach ($assets as $asset) {
                    $cat = match ($asset->category) {
                        'logo'       => 'logos',
                        'signature'  => 'signatures',
                        'stamp'      => 'stamps',
                        'background' => 'backgrounds',
                        default      => null,
                    };
                    if ($cat) {
                        $url = $asset->file_url ?? $asset->public_url ?? ('/media/private-serve?path=' . base64_encode($asset->file_path));
                        $catalog[$cat][] = [
                            'id'    => 'asset_' . $asset->id,
                            'name'  => $asset->name,
                            'slot'  => $asset->slot ?? $asset->category,
                            'url'   => $url,
                            'thumb' => $url,
                            'tag'   => '{{asset.' . ($asset->slot ?? $asset->category) . '}}',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fallback gracefully ke catalog default
            }
        }

        return $catalog;
    }
}
