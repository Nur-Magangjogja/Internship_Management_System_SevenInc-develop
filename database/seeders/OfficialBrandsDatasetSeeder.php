<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Division;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OfficialBrandsDatasetSeeder extends Seeder
{
    /**
     * Seed dataset resmi 11 Brand historis beserta pemetaan divisi khusus.
     * Dipisahkan dari DatabaseSeeder utama agar instalasi baru/produksi
     * bersih dan siap untuk input manual melalui Admin Dashboard.
     */
    public function run(): void
    {
        $brands = [
            [
                'code'                      => 'SI',
                'name'                      => 'SEVEN INC',
                'registration_host'         => 'daftar.seveninc.web.id',
                'primary_domain'            => 'seveninc.web.id',
                'company_address'           => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'magangjogjadotcom@gmail.com',
                'is_default'                => true,
            ],
            [
                'code'                      => 'RW',
                'name'                      => 'Republik Web',
                'registration_host'         => 'daftar.republikweb.net',
                'primary_domain'            => 'republikweb.net',
                'company_address'           => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'info@republikweb.net',
            ],
            [
                'code'                      => 'AKM',
                'name'                      => 'Ada Kamar',
                'registration_host'         => 'daftar.adakamar.id',
                'primary_domain'            => 'adakamar.id',
                'company_address'           => 'Yogyakarta',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'info@adakamar.id',
            ],
            [
                'code'                      => 'MJ',
                'name'                      => 'Magang Jogja',
                'registration_host'         => 'daftar.magangjogja.com',
                'primary_domain'            => 'magangjogja.com',
                'company_address'           => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'magangjogjadotcom@gmail.com',
                'is_default'                => false,
            ],
            [
                'code'                      => 'MK',
                'name'                      => 'Magangku',
                'registration_host'         => 'daftar.magangku.com',
                'primary_domain'            => 'magangku.com',
                'company_address'           => 'Yogyakarta',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'info@magangku.com',
            ],
            [
                'code'                      => 'TT',
                'name'                      => 'Tuan Tanah',
                'registration_host'         => 'daftar.tuantanah.com',
                'primary_domain'            => 'tuantanah.com',
                'company_address'           => 'Yogyakarta',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'info@tuantanah.com',
            ],
            [
                'code'                      => 'MO',
                'name'                      => 'Makeover',
                'registration_host'         => 'daftar.makeover.id',
                'primary_domain'            => 'makeover.id',
                'company_address'           => 'Yogyakarta',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'info@makeover.id',
            ],
            [
                'code'                      => 'BC',
                'name'                      => 'Bimbel Cerdas',
                'registration_host'         => 'daftar.bimbelcerdas.com',
                'primary_domain'            => 'bimbelcerdas.com',
                'company_address'           => 'Yogyakarta',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'info@bimbelcerdas.com',
            ],
            [
                'code'                      => 'AK',
                'name'                      => 'Area Kerja',
                'registration_host'         => 'daftar.areakerja.com',
                'primary_domain'            => 'areakerja.com',
                'company_address'           => 'Jl. Janti Gg. Arjuna No.59, Banguntapan, Bantul, Yogyakarta',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Direktur Utama',
                'signatory_email'           => 'info@areakerja.com',
            ],
            [
                'code'                      => 'TL',
                'name'                      => 'Tukang Las',
                'registration_host'         => 'daftar.tukanglas.org',
                'primary_domain'            => 'tukanglas.org',
                'company_address'           => 'Yogyakarta',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'info@tukanglas.org',
            ],
            [
                'code'                      => 'TS',
                'name'                      => 'Titip Sini',
                'registration_host'         => 'daftar.titipsini.com',
                'primary_domain'            => 'titipsini.com',
                'company_address'           => 'Jl. Ringroad Timur No. 10, Yogyakarta',
                'logo'                      => '/images/mou/seven-inc-logo.png',
                'signatory_name'            => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'        => 'Pimpinan',
                'signatory_email'           => 'cs@titipsini.com',
            ],
        ];

        // Seeder hanya mengelola data master 11 brand bawaan.
        // Domain adalah konfigurasi operasional yang dikelola Admin; jangan menimpanya
        // ketika seeder dijalankan ulang pada brand yang sudah ada.
        foreach ($brands as $brandData) {
            $code = $brandData['code'];
            $existing = DB::table('brands')->where('code', $code)->first();

            if ($existing) {
                // Domain, status lifecycle, dan brand default adalah konfigurasi operasional.
                // Seeder tidak boleh menimpa keputusan Admin pada data yang sudah ada.
                unset($brandData['registration_host'], $brandData['primary_domain'], $brandData['is_default']);
                DB::table('brands')->where('id', $existing->id)->update($brandData);
            } else {
                DB::table('brands')->insert($brandData);
            }
        }

        // Tetapkan SI sebagai default awal hanya jika belum ada default, dan jangan
        // mengganti default yang telah dipilih Admin pada eksekusi seeder berikutnya.
        if (!DB::table('brands')->where('is_default', true)->exists()) {
            DB::table('brands')->where('code', 'SI')->update(['is_default' => true]);
        }

        // Tandai hanya pasangan host/domain dari allowlist resmi lama sebagai migrated legacy trust.
        $legacyDomainMap = [];
        foreach (config('brand_domains.hosts', []) as $host => $meta) {
            if (!empty($meta['code']) && !empty($meta['primary_domain'])) {
                $legacyDomainMap[strtolower($host)] = [
                    'code' => strtoupper($meta['code']),
                    'primary_domain' => strtolower($meta['primary_domain']),
                ];
            }
        }

        foreach ($legacyDomainMap as $host => $meta) {
            $official = DB::table('brands')
                ->where('code', $meta['code'])
                ->where('registration_host', $host)
                ->where('primary_domain', $meta['primary_domain'])
                ->whereNull('domain_verified_at')
                ->whereNull('domain_verification_token')
                ->whereIn('lifecycle_status', ['configuration_pending', 'draft'])
                ->first();

            if ($official) {
                DB::table('brands')->where('id', $official->id)->update([
                    'lifecycle_status' => 'active',
                    'domain_verified_at' => now(),
                    'domain_verification_method' => 'legacy_static_allowlist',
                ]);
            }
        }

        \Illuminate\Support\Facades\Cache::forget(\App\Services\BrandHostRegistryService::CACHE_KEY);

        // Seeding mapping divisi khusus untuk brand bawaan.
        $divisionMap = [
            'SI'  => ['Administrasi', 'Human Resource', 'UI/UX Designer', 'Programmer', 'Desain Grafis', 'Social Media Specialist', 'Content Writer', 'Content Planner', 'Marketing / Sales', 'Marcom/Public Relation', 'Digital Marketing', 'Project Manager', 'Customer Service', 'Research & Development'],
            'RW'  => ['Programmer', 'UI/UX Designer', 'Project Manager', 'Digital Marketing', 'Desain Grafis', 'Administrasi', 'Customer Service'],
            'AKM' => ['Marketing / Sales', 'Customer Service', 'Administrasi', 'Social Media Specialist', 'Tiktok Creator', 'Fotografer', 'Videografer'],
            'MJ'  => ['Administrasi', 'Human Resource', 'Content Writer', 'Social Media Specialist', 'Desain Grafis', 'Marketing / Sales', 'Customer Service'],
            'MK'  => ['Administrasi', 'Content Writer', 'Social Media Specialist', 'Desain Grafis', 'Marketing / Sales', 'Customer Service', 'Digital Marketing'],
            'TT'  => ['Marketing / Sales', 'Fotografer', 'Videografer', 'Desain Grafis', 'Social Media Specialist', 'Administrasi', 'Host/Presenter'],
            'MO'  => ['Fotografer', 'Videografer', 'Desain Grafis', 'Animasi', 'Tiktok Creator', 'Voice Over Talent', 'Host/Presenter', 'Content Planner'],
            'BC'  => ['Administrasi', 'Customer Service', 'Content Writer', 'Desain Grafis', 'Social Media Specialist', 'Marketing / Sales'],
            'AK'  => ['Marketing / Sales', 'Social Media Specialist', 'Content Writer', 'Desain Grafis', 'Digital Marketing', 'Customer Service', 'Administrasi'],
            'TL'  => ['Las', 'Administrasi', 'Customer Service', 'Marketing / Sales', 'Desain Grafis', 'Social Media Specialist'],
            'TS'  => ['Customer Service', 'Administrasi', 'Marketing / Sales', 'Social Media Specialist', 'Desain Grafis'],
        ];

        $allDivisions = Division::pluck('id', 'name')->all();

        foreach ($divisionMap as $brandCode => $divisionNames) {
            $brand = Brand::where('code', $brandCode)->first();
            if (!$brand) continue;

            $divisionIds = [];
            foreach ($divisionNames as $divName) {
                if (isset($allDivisions[$divName])) {
                    $divisionIds[] = $allDivisions[$divName];
                }
            }

            if (!empty($divisionIds)) {
                $brand->divisions()->sync($divisionIds);
                if ($brand->division_mode === null) {
                    $brand->update(['division_mode' => Brand::DIVISION_MODE_BRAND_SPECIFIC]);
                }
            }
        }
    }
}
