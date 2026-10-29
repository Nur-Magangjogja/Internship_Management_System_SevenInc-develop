<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Men-seed 11 entitas brand resmi dasar HANYA dengan Nama, Kode, dan Brand Default (SEVEN INC).
     * Seluruh isian operasional (alamat kantor, nama/jabatan penandatangan, logo, stempel,
     * host pendaftaran, dan domain utama) dibiarkan KOSONG (NULL) dengan status DRAFT.
     *
     * Hal ini bertujuan untuk:
     * 1. Mencegah tercetaknya data lama/placeholder fiktif pada dokumen resmi (MoU, Sertifikat, LoA, dll).
     * 2. Mengharuskan Admin untuk mengisi dan memverifikasi data operasional riil melalui Dashboard.
     * 3. Memberikan status profil dinamis (Draft/Belum Lengkap) sebelum brand dapat diaktifkan.
     */
    public function run(): void
    {
        $brands = [
            ['code' => 'SI',  'name' => 'SEVEN INC',    'is_default' => true],
            ['code' => 'RW',  'name' => 'Republik Web', 'is_default' => false],
            ['code' => 'AKM', 'name' => 'Ada Kamar',    'is_default' => false],
            ['code' => 'MJ',  'name' => 'Magang Jogja', 'is_default' => false],
            ['code' => 'MK',  'name' => 'Magangku',     'is_default' => false],
            ['code' => 'TT',  'name' => 'Tuan Tanah',   'is_default' => false],
            ['code' => 'MO',  'name' => 'Makeover',     'is_default' => false],
            ['code' => 'BC',  'name' => 'Bimbel Cerdas','is_default' => false],
            ['code' => 'AK',  'name' => 'Area Kerja',   'is_default' => false],
            ['code' => 'TL',  'name' => 'Tukang Las',   'is_default' => false],
            ['code' => 'TS',  'name' => 'Titip Sini',   'is_default' => false],
        ];

        foreach ($brands as $b) {
            $existing = DB::table('brands')->where('code', $b['code'])->first();

            if ($existing) {
                // Jangan menimpa data operasional yang sudah pernah diisi oleh Admin
                DB::table('brands')->where('id', $existing->id)->update([
                    'name' => $b['name'],
                ]);
            } else {
                DB::table('brands')->insert([
                    'code'                       => $b['code'],
                    'name'                       => $b['name'],
                    'is_default'                 => $b['is_default'],
                    'lifecycle_status'           => Brand::STATUS_DRAFT,
                    'registration_host'          => null,
                    'primary_domain'             => null,
                    'domain_verification_token'  => null,
                    'domain_verified_at'         => null,
                    'domain_verification_method' => null,
                    'company_address'            => null,
                    'signatory_name'             => null,
                    'signatory_position'         => null,
                    'signatory_email'            => null,
                    'logo'                       => null,
                    'signature'                  => null,
                    'stamp'                      => null,
                ]);
            }
        }

        // Pastikan Brand Default terpasang pada SI jika belum ada brand default
        if (!DB::table('brands')->where('is_default', true)->exists()) {
            DB::table('brands')->where('code', 'SI')->update(['is_default' => true]);
        }

        \Illuminate\Support\Facades\Cache::forget(\App\Services\BrandHostRegistryService::CACHE_KEY);
    }
}
