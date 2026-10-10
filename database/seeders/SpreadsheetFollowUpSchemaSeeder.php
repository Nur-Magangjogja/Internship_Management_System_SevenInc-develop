<?php

namespace Database\Seeders;

use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SpreadsheetFollowUpSchemaSeeder extends Seeder
{
    /**
     * Seed canonical 21 columns for Follow-Up Guru/Dosen spreadsheet.
     * Idempotent and safe across multiple executions.
     */
    public function run(): void
    {
        // 1. Ensure connection exists
        $connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_FOLLOWUP],
            [
                'public_uuid'      => (string) Str::uuid(),
                'display_name'     => 'Spreadsheet Follow Up Guru Dosen',
                'spreadsheet_url'  => 'https://docs.google.com/spreadsheets/d/1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4/edit?usp=sharing',
                'spreadsheet_id'   => '1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4',
                'is_enabled'       => true,
            ]
        );

        // 2. Ensure schema version exists (using sentinel year = 0 for single operational tab)
        $schemaVersion = SpreadsheetSchemaVersion::firstOrCreate(
            [
                'spreadsheet_connection_id' => $connection->id,
                'year'                      => 0,
                'schema_version'            => 'v1',
            ],
            [
                'public_uuid'    => (string) Str::uuid(),
                'sheet_tab_name' => 'Follow Up Guru Dosen',
                'is_active'      => true,
                'notes'          => 'Skema mapping tab operasional tunggal Follow Up Guru Dosen',
            ]
        );

        // Update tab name and is_active if existing
        $schemaVersion->update([
            'sheet_tab_name' => 'Follow Up Guru Dosen',
            'is_active'      => true,
        ]);

        // 3. Define all 21 exact columns audited from the live Google Spreadsheet
        $canonicalColumns = [
            1 => [
                'header'         => 'No.',
                'target_entity'  => 'followup_cycle',
                'target_field'   => 'row_number',
                'data_type'      => 'integer',
                'transform_key'  => 'row_number',
                'is_required'    => false,
                'direction'      => 'system',
            ],
            2 => [
                'header'         => 'Tahun Follow Up',
                'target_entity'  => 'followup_cycle',
                'target_field'   => 'cycle_year',
                'data_type'      => 'integer',
                'transform_key'  => 'cycle_year',
                'is_required'    => true,
                'direction'      => 'outbound',
            ],
            3 => [
                'header'         => 'Sekolah/Kampus',
                'target_entity'  => 'institution',
                'target_field'   => 'name',
                'data_type'      => 'string',
                'transform_key'  => 'institution_name',
                'is_required'    => true,
                'direction'      => 'outbound',
            ],
            4 => [
                'header'         => 'Dosen/Guru',
                'target_entity'  => 'institution_contact',
                'target_field'   => 'name',
                'data_type'      => 'string',
                'transform_key'  => 'contact_name',
                'is_required'    => true,
                'direction'      => 'outbound',
            ],
            5 => [
                'header'         => 'Nomor Telepon',
                'target_entity'  => 'institution_contact',
                'target_field'   => 'phone',
                'data_type'      => 'string',
                'transform_key'  => 'phone',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            6 => [
                'header'         => 'Status Guru Dosen',
                'target_entity'  => 'institution_contact',
                'target_field'   => 'verification_status',
                'data_type'      => 'string',
                'transform_key'  => 'verification_status',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            7 => [
                'header'         => 'Follow Up 1',
                'target_entity'  => 'followup_attempt',
                'target_field'   => 'attempt_1_summary',
                'data_type'      => 'string',
                'transform_key'  => 'attempt_1_summary',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            8 => [
                'header'         => 'Follow Up 2',
                'target_entity'  => 'followup_attempt',
                'target_field'   => 'attempt_2_summary',
                'data_type'      => 'string',
                'transform_key'  => 'attempt_2_summary',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            9 => [
                'header'         => 'Follow Up 3',
                'target_entity'  => 'followup_attempt',
                'target_field'   => 'attempt_3_summary',
                'data_type'      => 'string',
                'transform_key'  => 'attempt_3_summary',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            10 => [
                'header'         => 'Kapan PKL/Magang Nya',
                'target_entity'  => 'internship_registration',
                'target_field'   => 'internship_period_summary',
                'data_type'      => 'string',
                'transform_key'  => 'internship_period_summary',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            11 => [
                'header'         => 'Respon',
                'target_entity'  => 'followup_cycle',
                'target_field'   => 'final_result_label',
                'data_type'      => 'string',
                'transform_key'  => 'final_result_label',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            12 => [
                'header'         => 'Nama yang mengajukan Kerjasama/ Follow Up 1',
                'target_entity'  => 'followup_attempt',
                'target_field'   => 'attempt_1_performer_name',
                'data_type'      => 'string',
                'transform_key'  => 'attempt_1_performer_name',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            13 => [
                'header'         => 'Nama yang mengajukan Kerjasama/ Follow Up 2',
                'target_entity'  => 'followup_attempt',
                'target_field'   => 'attempt_2_performer_name',
                'data_type'      => 'string',
                'transform_key'  => 'attempt_2_performer_name',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            14 => [
                'header'         => 'Nama yang mengajukan Kerjasama/ Follow Up 3',
                'target_entity'  => 'followup_attempt',
                'target_field'   => 'attempt_3_performer_name',
                'data_type'      => 'string',
                'transform_key'  => 'attempt_3_performer_name',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            15 => [
                'header'         => 'KET',
                'target_entity'  => 'followup_cycle',
                'target_field'   => 'final_notes',
                'data_type'      => 'string',
                'transform_key'  => 'final_notes',
                'is_required'    => false,
                'direction'      => 'outbound',
            ],
            16 => [
                'header'         => 'SERTIF_SYNC_ID',
                'target_entity'  => 'spreadsheet_row_sync',
                'target_field'   => 'external_sync_id',
                'data_type'      => 'string',
                'transform_key'  => 'external_sync_id',
                'is_required'    => false,
                'direction'      => 'system',
            ],
            17 => [
                'header'         => 'SERTIF_INSTITUTION_ID',
                'target_entity'  => 'institution',
                'target_field'   => 'id',
                'data_type'      => 'string',
                'transform_key'  => 'institution_id',
                'is_required'    => true,
                'direction'      => 'system',
            ],
            18 => [
                'header'         => 'SERTIF_CONTACT_ID',
                'target_entity'  => 'institution_contact',
                'target_field'   => 'id',
                'data_type'      => 'string',
                'transform_key'  => 'contact_id',
                'is_required'    => true,
                'direction'      => 'system',
            ],
            19 => [
                'header'         => 'SERTIF_FOLLOWUP_CYCLE_ID',
                'target_entity'  => 'followup_cycle',
                'target_field'   => 'id',
                'data_type'      => 'string',
                'transform_key'  => 'followup_cycle_id',
                'is_required'    => true,
                'direction'      => 'system',
            ],
            20 => [
                'header'         => 'SERTIF_SYNC_STATUS',
                'target_entity'  => 'spreadsheet_row_sync',
                'target_field'   => 'sync_status',
                'data_type'      => 'string',
                'transform_key'  => 'sync_status',
                'is_required'    => false,
                'direction'      => 'system',
            ],
            21 => [
                'header'         => 'SERTIF_LAST_SYNC_AT',
                'target_entity'  => 'spreadsheet_row_sync',
                'target_field'   => 'last_synced_at',
                'data_type'      => 'timestamp',
                'transform_key'  => 'last_synced_at',
                'is_required'    => false,
                'direction'      => 'system',
            ],
        ];

        foreach ($canonicalColumns as $order => $col) {
            SpreadsheetColumnMapping::updateOrCreate(
                [
                    'spreadsheet_schema_version_id' => $schemaVersion->id,
                    'column_order'                  => $order,
                ],
                [
                    'public_uuid'   => (string) Str::uuid(),
                    'sheet_header'  => $col['header'],
                    'target_entity' => $col['target_entity'],
                    'target_field'  => $col['target_field'],
                    'data_type'     => $col['data_type'],
                    'transform_key' => $col['transform_key'],
                    'is_required'   => $col['is_required'],
                    'direction'     => $col['direction'],
                ]
            );
        }
    }
}
