<?php

namespace Database\Seeders;

use App\Models\SpreadsheetConnection;
use Illuminate\Database\Seeder;

class SpreadsheetConnectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name' => 'Spreadsheet Pendaftaran Magang',
                'is_enabled' => false,
                'connection_status' => SpreadsheetConnection::STATUS_UNTESTED,
            ]
        );

        SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_FOLLOWUP],
            [
                'display_name' => 'Spreadsheet Follow-Up Guru/Dosen',
                'is_enabled' => false,
                'connection_status' => SpreadsheetConnection::STATUS_UNTESTED,
            ]
        );
    }
}
