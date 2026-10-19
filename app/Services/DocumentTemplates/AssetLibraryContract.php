<?php

namespace App\Services\DocumentTemplates;

class AssetLibraryContract
{
    /**
     * Ambil katalog aset yang tersedia untuk disisipkan ke editor.
     * Menggunakan mock aman jika endpoint remote Asset Registry belum aktif di worktree ini.
     */
    public static function getAssetCatalog(): array
    {
        return [
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
    }
}
