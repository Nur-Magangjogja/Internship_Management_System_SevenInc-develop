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
                'name' => 'SevenInc',
                'company_address' => 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul',
                'signatory_name' => 'John Doe',
                'signatory_position' => 'CEO',
            ]
        );
        DB::table('brands')->updateOrInsert(
            ['code' => 'ALB'],
            [
                'name' => 'Alona',
                'company_address' => 'Yogyakarta',
                'signatory_name' => 'Jane Smith',
                'signatory_position' => 'Manager',
            ]
        );
    }
}
