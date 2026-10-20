<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrandSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('brands')->updateOrInsert(
            ['code' => 'SVII'],
            [
                'name' => 'SEVEN INC',
                'company_address' => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198',
                'logo' => '/images/mou/seven-inc-logo.png',
                'signatory_name' => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position' => 'Pimpinan',
                'signatory_email' => 'magangjogjadotcom@gmail.com',
            ]
        );
        DB::table('brands')->updateOrInsert(
            ['code' => 'ALB'],
            [
                'name' => 'Alona',
                'company_address' => 'Yogyakarta',
                'logo' => '/images/mou/seven-inc-logo.png',
                'signatory_name' => 'Jane Smith',
                'signatory_position' => 'Manager',
                'signatory_email' => 'alona@seveninc.com',
            ]
        );
        DB::table('brands')->updateOrInsert(
            ['code' => 'AK'],
            [
                'name' => 'AreaKerja',
                'company_address' => 'Jl. Janti Gg. Arjuna No.59, Banguntapan, Bantul, Yogyakarta',
                'logo' => '/images/mou/seven-inc-logo.png',
                'signatory_name' => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position' => 'Direktur Utama',
                'signatory_email' => 'info@areakerja.com',
            ]
        );
        DB::table('brands')->updateOrInsert(
            ['code' => 'MJ'],
            [
                'name' => 'MagangJogja',
                'company_address' => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198',
                'logo' => '/images/mou/seven-inc-logo.png',
                'signatory_name' => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position' => 'Pimpinan',
                'signatory_email' => 'magangjogjadotcom@gmail.com',
            ]
        );
        DB::table('brands')->updateOrInsert(
            ['code' => 'TS'],
            [
                'name' => 'TitipSini',
                'company_address' => 'Jl. Ringroad Timur No. 10, Yogyakarta',
                'logo' => '/images/mou/seven-inc-logo.png',
                'signatory_name' => 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position' => 'Pimpinan',
                'signatory_email' => 'cs@titipsini.com',
            ]
        );
    }
}
