<?php

namespace Database\Seeders;

use App\Models\MouTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

class MouTemplateSeeder extends Seeder
{
    /**
     * Seed default MOU templates safely without overwriting existing Admin customizations.
     */
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();

        // Template v1 (Legacy Arsip): tidak pernah menimpa jika sudah ada
        MouTemplate::firstOrCreate(
            ['name' => 'Perjanjian Kerja Sama Standar Seven Inc', 'version_number' => 1],
            [
                'template_html'        => MouTemplate::getDefaultTemplateContent(),
                'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
                'default_values'       => array_merge(MouTemplate::getDefaultCompanyValues(), [
                    '_page_config'    => MouTemplate::getDefaultPageConfig(),
                    '_media_assets'   => MouTemplate::getDefaultMediaAssets(),
                    '_schema_version' => 2,
                ]),
                'is_active'            => false,
                'created_by'           => $admin?->id,
            ]
        );

        // Template v2 (Legacy Tri Dharma): tidak pernah menimpa jika sudah ada
        MouTemplate::firstOrCreate(
            ['name' => 'Nota Kesepahaman Tri Dharma Perguruan Tinggi & Kemitraan Industri', 'version_number' => 2],
            [
                'template_html'        => MouTemplate::getTriDharmaTemplateContent(),
                'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
                'default_values'       => array_merge(MouTemplate::getDefaultCompanyValues(), [
                    '_page_config'    => MouTemplate::getDefaultPageConfig(),
                    '_media_assets'   => MouTemplate::getDefaultMediaAssets(),
                    '_schema_version' => 2,
                ]),
                'is_active'            => false,
                'created_by'           => $admin?->id,
            ]
        );

        // Cek apakah sudah ada template aktif yang dikustomisasi Admin (updated_by != null, versi kustom, atau nama kustom)
        $stockNames = [
            'Perjanjian Kerja Sama Standar Seven Inc',
            'Nota Kesepahaman Tri Dharma Perguruan Tinggi & Kemitraan Industri',
            'Nota Kesepahaman Pelaksanaan Tri Dharma Perguruan Tinggi (Format Baku Word)',
        ];

        $hasAdminCustomizedActive = MouTemplate::where('is_active', true)
            ->where(function ($q) use ($stockNames) {
                $q->whereNotNull('updated_by')
                  ->orWhereNotIn('version_number', [1, 2, 3])
                  ->orWhereNotIn('name', $stockNames);
            })
            ->exists();

        // Template v3 (Format Baku Word Nota Kesepahaman Tri Dharma Perguruan Tinggi)
        $v3 = MouTemplate::firstOrCreate(
            ['version_number' => 3],
            [
                'name'                 => 'Nota Kesepahaman Pelaksanaan Tri Dharma Perguruan Tinggi (Format Baku Word)',
                'template_html'        => MouTemplate::getWordStandardTemplateContent(),
                'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
                'default_values'       => array_merge(MouTemplate::getDefaultCompanyValues(), [
                    '_page_config'    => MouTemplate::getDefaultPageConfig(),
                    '_media_assets'   => MouTemplate::getDefaultMediaAssets(),
                    '_schema_version' => 3,
                ]),
                'is_active'            => !$hasAdminCustomizedActive,
                'created_by'           => $admin?->id,
            ]
        );

        // Jika v3 baru saja dibuat dan belum ada kustomisasi Admin aktif, jadikan v3 sebagai template aktif utama
        if ($v3->wasRecentlyCreated && !$hasAdminCustomizedActive) {
            MouTemplate::where('id', '!=', $v3->id)->update(['is_active' => false]);
            $v3->update(['is_active' => true]);
        }
    }
}
