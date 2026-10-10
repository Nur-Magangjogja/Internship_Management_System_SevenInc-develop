<?php

namespace Database\Seeders;

use App\Models\MouTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

class MouTemplateSeeder extends Seeder
{
    /**
     * Seed default MOU template.
     */
    public function run(): void
    {
        $admin = User::where('role', 'admin')->first();

        MouTemplate::firstOrCreate(
            ['name' => 'Perjanjian Kerja Sama Standar Seven Inc', 'version_number' => 1],
            [
                'template_html'        => MouTemplate::getDefaultTemplateContent(),
                'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
                'default_values'       => [
                    'company_name'            => 'SEVEN INC (PT Tujuh Kreasi Digital)',
                    'company_address'         => 'Jl. Ringroad Timur, Wonocatur, Banguntapan, Bantul, D.I. Yogyakarta',
                    'company_phone'           => '0812-2555-5777',
                    'company_email'           => 'partnership@seveninc.co.id',
                    'company_signatory_name'  => 'Rekso Pamungkas',
                    'company_signatory_title' => 'Direktur Utama Seven Inc',
                    'agreement_place'         => 'Yogyakarta',
                ],
                'is_active'            => false,
                'created_by'           => $admin?->id,
            ]
        );

        // Template v2: Nota Kesepahaman Tri Dharma Perguruan Tinggi & Pengembangan SDM
        MouTemplate::firstOrCreate(
            ['name' => 'Nota Kesepahaman Tri Dharma Perguruan Tinggi & Kemitraan Industri', 'version_number' => 2],
            [
                'template_html'        => MouTemplate::getTriDharmaTemplateContent(),
                'allowed_placeholders' => array_keys(MouTemplate::getDefaultPlaceholders()),
                'default_values'       => [
                    'company_name'            => 'SEVEN INC (PT Tujuh Kreasi Digital)',
                    'company_address'         => 'Jl. Ringroad Timur, Wonocatur, Banguntapan, Bantul, D.I. Yogyakarta',
                    'company_phone'           => '0812-2555-5777',
                    'company_email'           => 'partnership@seveninc.co.id',
                    'company_signatory_name'  => 'Rekso Pamungkas',
                    'company_signatory_title' => 'Direktur Utama Seven Inc',
                    'agreement_place'         => 'Yogyakarta',
                ],
                'is_active'            => true,
                'created_by'           => $admin?->id,
            ]
        );
    }
}
