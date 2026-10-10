<?php

namespace Database\Seeders;

use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use Illuminate\Database\Seeder;

class Spreadsheet2026SchemaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Pastikan koneksi registration tersedia
        $connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name' => 'Spreadsheet Pendaftaran Magang',
                'is_enabled' => false,
                'connection_status' => SpreadsheetConnection::STATUS_UNTESTED,
            ]
        );

        // 2. Buat atau perbarui schema version 2026
        $schemaVersion = SpreadsheetSchemaVersion::firstOrCreate(
            [
                'spreadsheet_connection_id' => $connection->id,
                'year' => 2026,
                'schema_version' => 'v1',
            ],
            [
                'sheet_tab_name' => '2026',
                'is_active' => true,
                'notes' => 'Official 2026 29-Column Registration Contract from 2026 register.xlsx',
            ]
        );

        // 3. Kolom-kolom kanonikal 2026 (29 kolom persis sesuai Guardrail 1)
        $columns = [
            [
                'order' => 1,
                'header' => 'Timestamp',
                'entity' => 'internship_registration',
                'field' => 'created_at',
                'type' => 'timestamp',
                'direction' => 'system',
                'transform' => 'timestamp',
                'required' => true,
            ],
            [
                'order' => 2,
                'header' => 'Nama Lengkap',
                'entity' => 'internship_registration',
                'field' => 'fullname',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'fullname',
                'required' => true,
            ],
            [
                'order' => 3,
                'header' => 'NIM (Nomor Induk Mahasiswa)',
                'entity' => 'internship_registration',
                'field' => 'student_id',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'student_id',
                'required' => false,
            ],
            [
                'order' => 4,
                'header' => 'Program Studi',
                'entity' => 'internship_registration',
                'field' => 'study_program',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'study_program',
                'required' => true,
            ],
            [
                'order' => 5,
                'header' => 'Email',
                'entity' => 'user',
                'field' => 'email',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'email',
                'required' => true,
            ],
            [
                'order' => 6,
                'header' => 'Jenis Kelamin',
                'entity' => 'internship_registration',
                'field' => 'gender',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'gender',
                'required' => true,
            ],
            [
                'order' => 7,
                'header' => 'No. HP Aktif yang bisa dihubungi dan chat WA',
                'entity' => 'internship_registration',
                'field' => 'phone_number',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'phone_number',
                'required' => true,
            ],
            [
                'order' => 8,
                'header' => 'Asal Sekolah/Kampus',
                'entity' => 'institution',
                'field' => 'institution_name',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'institution_name',
                'required' => true,
            ],
            [
                'order' => 9,
                'header' => 'Nama Kota/Daerah tempat tinggal Anda saat ini',
                'entity' => 'city',
                'field' => 'current_city',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'current_city',
                'required' => false,
            ],
            [
                'order' => 10,
                'header' => 'Mengapa Anda ingin Magang/PKL disini?',
                'entity' => 'internship_registration',
                'field' => 'internship_reason',
                'type' => 'text',
                'direction' => 'outbound',
                'transform' => 'internship_reason',
                'required' => false,
            ],
            [
                'order' => 11,
                'header' => 'Jenis Magang apa yang Anda pilih?',
                'entity' => 'internship_registration',
                'field' => 'internship_type',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'internship_type',
                'required' => true,
            ],
            [
                'order' => 12,
                'header' => 'Status Anda saat ini',
                'entity' => 'internship_registration',
                'field' => 'current_status',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'current_status',
                'required' => true,
            ],
            [
                'order' => 13,
                'header' => 'Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?',
                'entity' => 'internship_registration',
                'field' => 'wfo_readiness',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'wfo_readiness',
                'required' => true,
            ],
            [
                'order' => 14,
                'header' => 'No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan',
                'entity' => 'internship_registration',
                'field' => 'supervisor_info',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'supervisor_info',
                'required' => false,
            ],
            [
                'order' => 15,
                'header' => 'Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)',
                'entity' => 'internship_registration',
                'field' => 'schedule_shift_readiness',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'schedule_shift_readiness',
                'required' => true,
            ],
            [
                'order' => 16,
                'header' => 'Apakah ada laptop sendiri',
                'entity' => 'internship_registration',
                'field' => 'has_laptop',
                'type' => 'boolean',
                'direction' => 'outbound',
                'transform' => 'has_laptop',
                'required' => true,
            ],
            [
                'order' => 17,
                'header' => 'Jika YA, alat apa yang dimiliki/dibawa',
                'entity' => 'intern_tools',
                'field' => 'owned_tools',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'laptop_equipment',
                'required' => false,
            ],
            [
                'order' => 18,
                'header' => 'Kapan berencana mulai Magang/PKL',
                'entity' => 'internship_registration',
                'field' => 'start_date',
                'type' => 'date',
                'direction' => 'outbound',
                'transform' => 'start_date',
                'required' => true,
            ],
            [
                'order' => 19,
                'header' => 'Berapa lama akan Magang/PKL',
                'entity' => 'internship_registration',
                'field' => 'planned_duration',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'planned_duration',
                'required' => true,
            ],
            [
                'order' => 20,
                'header' => 'Sumber info magang',
                'entity' => 'intern_info_sources',
                'field' => 'internship_info_sources',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'internship_info_sources',
                'required' => false,
            ],
            [
                'order' => 21,
                'header' => 'Instagram',
                'entity' => 'internship_registration',
                'field' => 'social_media_instagram',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'social_media_instagram',
                'required' => false,
            ],
            [
                'order' => 22,
                'header' => 'Butuh info kost/kontrakan',
                'entity' => 'internship_registration',
                'field' => 'boarding_info',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'boarding_info',
                'required' => false,
            ],
            [
                'order' => 23,
                'header' => 'Program Magang/PKL yang diminati',
                'entity' => 'division',
                'field' => 'internship_interest',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'internship_interest',
                'required' => true,
            ],
            [
                'order' => 24,
                'header' => 'Upload PDF CV + scan KTM/KTP + portofolio max 10MB',
                'entity' => 'registration_attachments',
                'field' => 'attachment_url',
                'type' => 'url',
                'direction' => 'outbound',
                'transform' => 'attachment_url',
                'required' => false,
            ],
            [
                'order' => 25,
                'header' => 'KET',
                'entity' => 'internship_registration',
                'field' => 'status_label',
                'type' => 'string',
                'direction' => 'display_only',
                'transform' => 'status_label',
                'required' => true,
            ],
            [
                'order' => 26,
                'header' => 'SERTIF_SYNC_ID',
                'entity' => 'spreadsheet_row_syncs',
                'field' => 'sertif_sync_id',
                'type' => 'string',
                'direction' => 'system',
                'transform' => 'sertif_sync_id',
                'required' => false,
            ],
            [
                'order' => 27,
                'header' => 'SERTIF_REGISTRATION_ID',
                'entity' => 'internship_registration',
                'field' => 'id',
                'type' => 'string',
                'direction' => 'system',
                'transform' => 'sertif_registration_id',
                'required' => false,
            ],
            [
                'order' => 28,
                'header' => 'SERTIF_SYNC_STATUS',
                'entity' => 'spreadsheet_row_syncs',
                'field' => 'sync_status',
                'type' => 'string',
                'direction' => 'system',
                'transform' => 'sertif_sync_status',
                'required' => false,
            ],
            [
                'order' => 29,
                'header' => 'SERTIF_LAST_SYNC_AT',
                'entity' => 'spreadsheet_row_syncs',
                'field' => 'last_synced_at',
                'type' => 'timestamp',
                'direction' => 'system',
                'transform' => 'sertif_last_sync_at',
                'required' => false,
            ],
        ];

        foreach ($columns as $col) {
            SpreadsheetColumnMapping::updateOrCreate(
                [
                    'spreadsheet_schema_version_id' => $schemaVersion->id,
                    'column_order' => $col['order'],
                ],
                [
                    'sheet_header' => $col['header'],
                    'target_entity' => $col['entity'],
                    'target_field' => $col['field'],
                    'data_type' => $col['type'],
                    'direction' => $col['direction'],
                    'transform_key' => $col['transform'],
                    'is_required' => $col['required'],
                ]
            );
        }
    }
}
