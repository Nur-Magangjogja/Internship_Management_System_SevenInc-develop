<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\SpreadsheetConnection;
use Illuminate\Database\Seeder;

class SpreadsheetConnectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Follow-Up (CRM Global Guru/Dosen)
        SpreadsheetConnection::firstOrCreate(
            [
                'connection_key' => SpreadsheetConnection::KEY_FOLLOWUP,
                'brand_id'       => null,
            ],
            [
                'display_name'      => 'Spreadsheet Follow-Up Guru/Dosen',
                'is_enabled'        => false,
                'connection_status' => SpreadsheetConnection::STATUS_UNTESTED,
            ]
        );

        // 2. Dedicated registration connection for every configured brand.
        $brands = Brand::whereNotNull('registration_host')
            ->orderBy('name')
            ->get();

        foreach ($brands as $brand) {
            SpreadsheetConnection::firstOrCreate(
                [
                    'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
                    'brand_id'       => $brand->id,
                ],
                [
                    'display_name'      => "Spreadsheet Pendaftaran ({$brand->name})",
                    'is_enabled'        => false,
                    'connection_status' => SpreadsheetConnection::STATUS_NOT_CONFIGURED,
                ]
            );
        }
    }
}
