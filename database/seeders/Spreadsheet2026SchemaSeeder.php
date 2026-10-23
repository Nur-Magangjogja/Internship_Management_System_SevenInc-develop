<?php

namespace Database\Seeders;

use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use Illuminate\Database\Seeder;

class Spreadsheet2026SchemaSeeder extends Seeder
{
        public static function getCanonicalColumns(): array
    {
        return [
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
                'header' => 'NIM/NIS',
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
                'header' => 'Mengapa Anda ingin Magang/PKL disini?',
                'entity' => 'internship_registration',
                'field' => 'internship_reason',
                'type' => 'text',
                'direction' => 'outbound',
                'transform' => 'internship_reason',
                'required' => true,
            ],
            [
                'order' => 10,
                'header' => 'Jenis Magang apa yang Anda pilih?',
                'entity' => 'internship_registration',
                'field' => 'internship_type',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'internship_type',
                'required' => true,
            ],
            [
                'order' => 11,
                'header' => 'Status Anda saat ini',
                'entity' => 'internship_registration',
                'field' => 'current_status',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'current_status',
                'required' => true,
            ],
            [
                'order' => 12,
                'header' => 'Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?',
                'entity' => 'internship_registration',
                'field' => 'wfo_readiness',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'wfo_readiness',
                'required' => true,
            ],
            [
                'order' => 13,
                'header' => 'No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan (bisa diisi menyusul, jika belum dapat dosen pembimbing).',
                'entity' => 'internship_registration',
                'field' => 'supervisor_info',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'supervisor_info',
                'required' => false,
            ],
            [
                'order' => 14,
                'header' => 'Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)',
                'entity' => 'internship_registration',
                'field' => 'schedule_shift_readiness',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'schedule_shift_readiness',
                'required' => true,
            ],
            [
                'order' => 15,
                'header' => 'Apakah Anda ada alat kerja sendiri (LAPTOP) yang bisa dipakai selama Magang/PKL?',
                'entity' => 'internship_registration',
                'field' => 'has_laptop',
                'type' => 'boolean',
                'direction' => 'outbound',
                'transform' => 'has_laptop',
                'required' => true,
            ],
            [
                'order' => 16,
                'header' => 'Jika YA, alat apa yang Anda miliki, yang bisa Anda bawa selama Magang/PKL?',
                'entity' => 'intern_tools',
                'field' => 'owned_tools',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'laptop_equipment',
                'required' => false,
            ],
            [
                'order' => 17,
                'header' => 'Kapan Anda berencana mulai Magang/PKL? (tulis lengkap tanggal, bulan, tahun)',
                'entity' => 'internship_registration',
                'field' => 'start_date',
                'type' => 'date',
                'direction' => 'outbound',
                'transform' => 'start_date',
                'required' => true,
            ],
            [
                'order' => 18,
                'header' => 'Berapa lama Anda akan Magang/PKL?',
                'entity' => 'internship_registration',
                'field' => 'planned_duration',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'planned_duration',
                'required' => true,
            ],
            [
                'order' => 19,
                'header' => 'Anda tahu info magang ini dari mana?',
                'entity' => 'intern_info_sources',
                'field' => 'internship_info_sources',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'internship_info_sources',
                'required' => false,
            ],
            [
                'order' => 20,
                'header' => 'Sosial Media (Instagram) yang dimiliki',
                'entity' => 'internship_registration',
                'field' => 'social_media_instagram',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'social_media_instagram',
                'required' => false,
            ],
            [
                'order' => 21,
                'header' => 'Apakah anda membutuhkan info kost/kontrakan deket kantor?',
                'entity' => 'internship_registration',
                'field' => 'boarding_info',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'boarding_info',
                'required' => false,
            ],
            [
                'order' => 22,
                'header' => 'Program Magang/PKL apa yang Anda minati?',
                'entity' => 'division',
                'field' => 'internship_interest',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'internship_interest',
                'required' => true,
            ],
            [
                'order' => 23,
                'header' => 'Tanggal Lahir',
                'entity' => 'internship_registration',
                'field' => 'born_date',
                'type' => 'date',
                'direction' => 'outbound',
                'transform' => 'born_date',
                'required' => false,
            ],
            [
                'order' => 24,
                'header' => 'Agama',
                'entity' => 'internship_registration',
                'field' => 'religion',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'religion',
                'required' => false,
            ],
            [
                'order' => 25,
                'header' => 'Software Desain',
                'entity' => 'internship_registration',
                'field' => 'design_software',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'design_software',
                'required' => false,
            ],
            [
                'order' => 26,
                'header' => 'Software Video',
                'entity' => 'internship_registration',
                'field' => 'video_software',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'video_software',
                'required' => false,
            ],
            [
                'order' => 27,
                'header' => 'Bahasa Pemrograman',
                'entity' => 'internship_registration',
                'field' => 'programming_languages',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'programming_languages',
                'required' => false,
            ],
            [
                'order' => 28,
                'header' => 'Digital Marketing',
                'entity' => 'internship_registration',
                'field' => 'digital_marketing_type',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'digital_marketing_type',
                'required' => false,
            ],
            [
                'order' => 29,
                'header' => 'Peralatan (Laptop, Kamera, Tripod, dll)',
                'entity' => 'internship_registration',
                'field' => 'owned_tools',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'equipment',
                'required' => false,
            ],
            [
                'order' => 30,
                'header' => 'Nama Pembimbing',
                'entity' => 'internship_registration',
                'field' => 'supervisor_name',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'supervisor_name',
                'required' => false,
            ],
            [
                'order' => 31,
                'header' => 'No. WA Pembimbing',
                'entity' => 'internship_registration',
                'field' => 'supervisor_contact',
                'type' => 'string',
                'direction' => 'outbound',
                'transform' => 'supervisor_contact',
                'required' => false,
            ],
            [
                'order' => 32,
                'header' => 'Kegiatan Lain Selain Magang',
                'entity' => 'internship_registration',
                'field' => 'current_activities',
                'type' => 'text',
                'direction' => 'outbound',
                'transform' => 'current_activities',
                'required' => false,
            ],
            [
                'order' => 33,
                'header' => 'Silakan Upload (berupa file Pdf) : CV, Scan KTM/KTP dan Portofolio max. 10 MB',
                'entity' => 'registration_attachments',
                'field' => 'attachment_url',
                'type' => 'url',
                'direction' => 'outbound',
                'transform' => 'attachment_url',
                'required' => false,
            ],
            [
                'order' => 34,
                'header' => 'KET',
                'entity' => 'internship_registration',
                'field' => 'status_label',
                'type' => 'string',
                'direction' => 'display_only',
                'transform' => 'status_label',
                'required' => true,
            ],
            [
                'order' => 35,
                'header' => 'SERTIF_SYNC_ID',
                'entity' => 'spreadsheet_row_syncs',
                'field' => 'sertif_sync_id',
                'type' => 'string',
                'direction' => 'system',
                'transform' => 'sertif_sync_id',
                'required' => false,
            ],
            [
                'order' => 36,
                'header' => 'SERTIF_REGISTRATION_ID',
                'entity' => 'internship_registration',
                'field' => 'id',
                'type' => 'string',
                'direction' => 'system',
                'transform' => 'sertif_registration_id',
                'required' => false,
            ],
            [
                'order' => 37,
                'header' => 'SERTIF_SYNC_STATUS',
                'entity' => 'spreadsheet_row_syncs',
                'field' => 'sync_status',
                'type' => 'string',
                'direction' => 'system',
                'transform' => 'sertif_sync_status',
                'required' => false,
            ],
            [
                'order' => 38,
                'header' => 'SERTIF_LAST_SYNC_AT',
                'entity' => 'spreadsheet_row_syncs',
                'field' => 'last_synced_at',
                'type' => 'timestamp',
                'direction' => 'system',
                'transform' => 'sertif_last_sync_at',
                'required' => false,
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Dapatkan seluruh koneksi registration yang ada, atau buat baru jika belum ada
        $connections = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->get();
        if ($connections->isEmpty()) {
            $connections = collect([
                SpreadsheetConnection::create([
                    'connection_key' => SpreadsheetConnection::KEY_REGISTRATION,
                    'display_name' => 'Spreadsheet Pendaftaran Magang',
                    'is_enabled' => false,
                    'connection_status' => SpreadsheetConnection::STATUS_UNTESTED,
                ])
            ]);
        }

        // 3. Kolom-kolom kanonikal 2026 (40 kolom lengkap terpadu)
        $columns = self::getCanonicalColumns();

        foreach ($connections as $connection) {
            $schemaVersion = SpreadsheetSchemaVersion::updateOrCreate(
                [
                    'spreadsheet_connection_id' => $connection->id,
                    'year' => 2026,
                    'schema_version' => 'v1',
                ],
                [
                    'sheet_tab_name' => '2026',
                    'is_active' => true,
                    'notes' => 'Official 2026 40-Column Unified Registration Contract',
                ]
            );

            // Hapus kolom lama di atas urutan 40 agar tidak tersisa 47 kolom
            SpreadsheetColumnMapping::where('spreadsheet_schema_version_id', $schemaVersion->id)
                ->where('column_order', '>', count($columns))
                ->delete();

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
}
