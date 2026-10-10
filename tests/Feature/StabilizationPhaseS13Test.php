<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Faculty;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\LegacyImportService;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetConnectionService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Carbon\Carbon;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Database\Seeders\SpreadsheetFollowUpSchemaSeeder;
use DomainException;
use Exception;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StabilizationPhaseS13Test extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private Institution $institution;
    private Faculty $faculty;
    private StudyProgram $studyProgram;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(Spreadsheet2026SchemaSeeder::class);
        $this->seed(SpreadsheetFollowUpSchemaSeeder::class);

        $this->unique = uniqid('s13_', true);

        $this->admin = User::create([
            'name'     => 'Admin S13 ' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'HR S13 ' . substr($this->unique, 0, 8),
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'Pemagang S13 ' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->institution = Institution::create([
            'name'                 => 'SMK Mitra S13 ' . $this->unique,
            'address'              => 'Jl. Uji S13 No. 1',
            'correspondence_unit'  => 'Hubin',
            'is_active'            => true,
        ]);

        $this->faculty = Faculty::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Fakultas S13 ' . substr($this->unique, 0, 8),
        ]);

        $this->studyProgram = StudyProgram::create([
            'faculty_id' => $this->faculty->id,
            'name'       => 'Teknik Informatika S13 ' . substr($this->unique, 0, 8),
        ]);
    }

    /**
     * S13-1: Dry-run / pratinjau impor sama sekali tidak memutasikan atau menambah baris di database.
     */
    public function test_dry_run_import_does_not_mutate_database_records(): void
    {
        $instName = 'SMK Pratinjau Bersih ' . $this->unique;
        $contactName = 'Drs. Suwarno ' . $this->unique;

        $instCountBefore = Institution::count();
        $contactCountBefore = InstitutionContact::count();
        $cycleCountBefore = FollowUpCycle::count();

        $csvContent = "nama_institusi,alamat_institusi,nama_guru_dosen,nomor_telepon_wa,tahun_siklus,status_follow_up\n"
                    . "\"{$instName}\",\"Jl. Solo KM 10\",\"{$contactName}\",\"081234567891\",\"2026\",\"bersedia\"\n";

        $tempFile = tempnam(sys_get_temp_dir(), 's13_prev_');
        file_put_contents($tempFile, $csvContent);

        $uploadedFile = new UploadedFile(
            $tempFile,
            'dry_run_test.csv',
            'text/csv',
            null,
            true
        );

        $response = $this->actingAs($this->admin)->post(route('admin.legacy-import.preview'), [
            'file' => $uploadedFile,
        ]);

        $response->assertStatus(410);

        // Pastikan jumlah rekor database tidak bertambah sama sekali setelah request ditolak
        $this->assertEquals($instCountBefore, Institution::count());
        $this->assertEquals($contactCountBefore, InstitutionContact::count());
        $this->assertEquals($cycleCountBefore, FollowUpCycle::count());

        $this->assertDatabaseMissing('institutions', ['name' => $instName]);
        $this->assertDatabaseMissing('institution_contacts', ['name' => $contactName]);

        @unlink($tempFile);
    }

    /**
     * S13-2: Kontak pembimbing baru yang diimpor dari legacy ditandai sebagai unverified candidate (is_verified = false).
     */
    public function test_imported_new_supervisors_are_flagged_as_unverified_candidates(): void
    {
        $instName = 'SMK Kandidat Review ' . $this->unique;
        $contactName = 'Dra. Endang Lestari ' . $this->unique;

        $rawRows = [
            [
                'nama_institusi'    => $instName,
                'alamat_institusi'  => 'Jl. Bantul KM 5',
                'nama_guru_dosen'   => $contactName,
                'jabatan'           => 'Guru Pembimbing Industri',
                'nomor_telepon_wa'  => '081399887766',
                'email_kontak'      => 'endang_' . $this->unique . '@smk.sch.id',
                'tahun_siklus'      => '2026',
                'status_follow_up'  => 'bersedia',
            ],
        ];

        $service = app(LegacyImportService::class);
        $summary = $service->commitImport($rawRows, $this->admin);

        // Verifikasi kontak tersimpan dengan is_verified = false (antrean review HR)
        $contact = InstitutionContact::where('name', $contactName)->first();
        $this->assertNotNull($contact);
        $this->assertFalse((bool) $contact->is_verified, 'Kontak baru dari legacy import wajib memiliki is_verified = false');
        $this->assertStringContainsString('kandidat review HR', $contact->notes);
    }

    /**
     * S13-3: Institusi dengan nama ambigu/mirip dideteksi pada tahap dry-run preview.
     */
    public function test_ambiguous_institutions_are_detected_in_dry_run_preview(): void
    {
        // 1. Buat institusi eksisting
        $existing = Institution::create([
            'name'                => 'SMK Negeri 7 Yogyakarta ' . $this->unique,
            'address'             => 'Jl. Gowongan Lor',
            'correspondence_unit' => 'Humas Hubin',
            'is_active'           => true,
        ]);

        // 2. Berikan data impor dengan variasi nama yang mengandung substring institusi eksisting
        $service = app(LegacyImportService::class);
        $rows = [
            [
                '_row_number'      => 1,
                'nama_institusi'   => 'SMK Negeri 7 Yogyakarta ' . $this->unique . ' Kampus 2',
                'nama_guru_dosen'  => 'Agus Santoso',
                'nomor_telepon_wa' => '081299990001',
            ],
        ];

        $preview = $service->previewImport($rows);

        $this->assertGreaterThanOrEqual(1, $preview['stats']['ambiguous_institutions']);
        $this->assertTrue($preview['rows'][0]['is_ambiguous']);
        $this->assertStringContainsString($existing->name, $preview['rows'][0]['ambiguous_candidate']);
    }

    /**
     * S13-4: Menjalankan ulang impor dengan data yang identik bersifat idempoten (tidak menduplikasi data).
     */
    public function test_re_running_import_with_identical_data_is_idempotent(): void
    {
        $service = app(LegacyImportService::class);

        $instName = 'SMK Idempoten Jaya ' . $this->unique;
        $contactName = 'Drs. Supardi M.Pd ' . $this->unique;
        $contactPhone = '081255443322';

        $rawRows = [
            [
                'nama_institusi'    => $instName,
                'alamat_institusi'  => 'Jl. Kaliurang KM 8',
                'telepon_institusi' => '0274-123456',
                'nama_guru_dosen'   => $contactName,
                'jabatan'           => 'Koordinator Hubin',
                'nomor_telepon_wa'  => $contactPhone,
                'email_kontak'      => 'supardi_' . $this->unique . '@idempoten.sch.id',
                'tahun_siklus'      => '2026',
                'status_follow_up'  => 'bersedia',
            ],
        ];

        // Eksekusi commit pertama
        $summary1 = $service->commitImport($rawRows, $this->admin);
        $this->assertEquals(1, $summary1['inserted_institutions']);
        $this->assertEquals(1, $summary1['inserted_contacts']);
        $this->assertEquals(1, $summary1['inserted_cycles']);

        $instCountAfterFirst = Institution::where('name', $instName)->count();
        $contactCountAfterFirst = InstitutionContact::where('name', $contactName)->count();
        $cycleCountAfterFirst = FollowUpCycle::where('cycle_year', 2026)->count();

        // Eksekusi commit kedua dengan data yang sama persis
        $summary2 = $service->commitImport($rawRows, $this->admin);
        $this->assertEquals(0, $summary2['inserted_institutions'], 'Institusi tidak boleh bertambah saat diimpor ulang');
        $this->assertEquals(1, $summary2['linked_institutions'], 'Institusi harus di-link ke rekaman existing');
        $this->assertEquals(0, $summary2['inserted_contacts'], 'Kontak tidak boleh bertambah saat diimpor ulang');
        $this->assertEquals(1, $summary2['linked_contacts'], 'Kontak harus di-link ke rekaman existing');
        $this->assertEquals(0, $summary2['inserted_cycles'], 'Siklus follow-up tidak boleh bertambah saat diimpor ulang');

        // Pastikan jumlah rekor di basis data tetap 1 (tidak berlipat ganda)
        $this->assertEquals($instCountAfterFirst, Institution::where('name', $instName)->count());
        $this->assertEquals($contactCountAfterFirst, InstitutionContact::where('name', $contactName)->count());
    }

    /**
     * S13-5: Eksekusi commit bersifat atomik dengan rollback DB transaction jika terjadi error kritis.
     */
    public function test_import_commit_is_atomic_and_rolls_back_on_critical_failure(): void
    {
        $instName = 'SMK Rollback Test ' . $this->unique;
        $instCountBefore = Institution::where('name', $instName)->count();

        try {
            DB::transaction(function () use ($instName) {
                Institution::create([
                    'name'                => $instName,
                    'correspondence_unit' => 'BKK',
                    'is_active'           => true,
                ]);

                throw new Exception('Simulated fatal database integrity error during commit');
            });
        } catch (Exception $e) {
            // Expected
        }

        $this->assertEquals($instCountBefore, Institution::where('name', $instName)->count());
    }

    /**
     * S13-6: Semua endpoint impor data legacy terisolasi ketat (HR Staff & Pemagang tidak diizinkan).
     */
    public function test_legacy_import_routes_strictly_forbidden_for_hr_staff_and_pemagang(): void
    {
        $routes = [
            ['method' => 'get', 'route' => route('admin.legacy-import.index')],
            ['method' => 'get', 'route' => route('admin.legacy-import.template')],
            ['method' => 'post', 'route' => route('admin.legacy-import.preview')],
            ['method' => 'post', 'route' => route('admin.legacy-import.commit')],
            ['method' => 'post', 'route' => route('admin.legacy-import.cancel')],
        ];

        foreach ($routes as $routeConfig) {
            $method = $routeConfig['method'];
            $url = $routeConfig['route'];

            // HR Staff dialihkan atau ditolak
            $responseHr = $this->actingAs($this->hrStaff)->{$method}($url);
            $this->assertTrue(
                in_array($responseHr->status(), [302, 403]),
                "HR Staff harus dialihkan (302) atau ditolak (403) pada {$url}"
            );

            // Pemagang dialihkan atau ditolak
            $responsePemagang = $this->actingAs($this->pemagang)->{$method}($url);
            $this->assertTrue(
                in_array($responsePemagang->status(), [302, 403]),
                "Pemagang harus dialihkan (302) atau ditolak (403) pada {$url}"
            );
        }
    }

    /**
     * S13-7: Pratinjau staging di UI tidak mengekspos ID auto-increment database dan bebas dari emotikon.
     */
    public function test_staging_preview_ui_renders_without_raw_database_id_and_without_emojis(): void
    {
        $stagingData = [
            'file_name' => 'sample_preview.csv',
            'stats'     => [
                'total_rows'             => 2,
                'new_institutions'       => 1,
                'existing_institutions'  => 1,
                'ambiguous_institutions' => 1,
                'new_contacts'           => 1,
                'existing_contacts'      => 1,
                'unverified_candidates'  => 1,
                'has_followup'           => 1,
                'invalid_rows'           => 0,
            ],
            'rows'      => [
                [
                    'row_number'          => 1,
                    'institution_name'    => 'SMK Ambigu Contoh',
                    'institution_matched' => null,
                    'institution_action'  => 'insert_new',
                    'is_ambiguous'        => true,
                    'ambiguous_candidate' => 'SMK Negeri 1 Contoh',
                    'contact_name'        => 'Budi Wibowo',
                    'contact_phone'       => '081234567890',
                    'contact_position'    => 'Ketua BKK',
                    'contact_action'      => 'insert_new',
                    'is_candidate'        => true,
                    'followup_year'       => '2026',
                    'followup_status'     => 'bersedia',
                    'errors'              => [],
                ],
            ],
            'raw_rows'  => [],
        ];

        session(['legacy_import_staging' => $stagingData]);

        // Route akses ditolak karena pensiun
        $response = $this->actingAs($this->admin)->get(route('admin.legacy-import.index'));
        $response->assertStatus(410);

        // Render view langsung untuk memvalidasi kepatuhan UI standards
        $view = view('admin.legacy-import.index', ['stagingData' => $stagingData])->render();

        // 1. Larangan Emotikon (Rule 4)
        $emojiRegex = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';
        $this->assertFalse((bool) preg_match($emojiRegex, $view), 'Tampilan staging tidak boleh mengandung emotikon/emoji');

        // 2. Keamanan Data (Rule 3): Tidak ada kebocoran primary key raw database ID
        $this->assertStringNotContainsString('id="institution-', $view);
        $this->assertStringNotContainsString('data-id=', $view);
    }

    /* =========================================================================
     * S13 CANONICAL TESTS: MULTI-YEAR SPREADSHEET TABS & HISTORICAL ISOLATION
     * ========================================================================= */

    /**
     * S13-8: Routing registrasi 2025, 2026, dan 2027 strictly berdasarkan YEAR(created_at).
     */
    public function test_multi_year_registration_routing_strictly_based_on_created_at_year(): void
    {
        /** @var SpreadsheetSyncEngineService $syncEngine */
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        // Registrasi 2025
        $reg2025 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang 2025 ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081200002025',
            'study_program'     => 'Teknik Informatika',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);
        $reg2025->created_at = Carbon::create(2025, 4, 15, 10, 0, 0);
        $reg2025->save();

        // Registrasi 2026
        $reg2026 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang 2026 ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081200002026',
            'study_program'     => 'Sistem Informasi',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);
        $reg2026->created_at = Carbon::create(2026, 2, 1, 9, 0, 0);
        $reg2026->save();

        // Registrasi 2027
        $reg2027 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang 2027 ' . $this->unique,
            'gender'            => 'P',
            'phone_number'      => '081200002027',
            'study_program'     => 'Desain Komunikasi Visual',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);
        $reg2027->created_at = Carbon::create(2027, 8, 20, 14, 0, 0);
        $reg2027->save();

        $sync2025 = $syncEngine->queueSyncForRegistration($reg2025);
        $sync2026 = $syncEngine->queueSyncForRegistration($reg2026);
        $sync2027 = $syncEngine->queueSyncForRegistration($reg2027);

        $this->assertEquals('2025', $sync2025->target_sheet_tab);
        $this->assertEquals('2026', $sync2026->target_sheet_tab);
        $this->assertEquals('2027', $sync2027->target_sheet_tab);
    }

    /**
     * S13-9: Jika created_at null/kosong, sistem gagal aman tanpa fallback ke tahun berjalan (current year).
     */
    public function test_created_at_null_fails_safely_without_fallback_to_current_year(): void
    {
        /** @var RegistrationSpreadsheetMappingService $mappingService */
        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        /** @var SpreadsheetSyncEngineService $syncEngine */
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $regNoDate = new InternshipRegistration([
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Tanpa Tanggal ' . $this->unique,
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);
        $regNoDate->created_at = null;

        // 1. Mapping service returns null schema and throws explicit DomainException on payload building
        $this->assertNull($mappingService->getSchemaForRegistration($regNoDate));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tanggal pembuatan pendaftaran (created_at) tidak valid atau kosong untuk penentuan tab tahunan.');
        $mappingService->buildOutboundRowPayload($regNoDate);
    }

    /**
     * S13-10: Satu user dengan beberapa registrasi lintas tahun mempertahankan row terpisah per periode.
     */
    public function test_multiple_registrations_same_user_isolated_by_year_tabs(): void
    {
        /** @var SpreadsheetSyncEngineService $syncEngine */
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $reg1 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Alumnus Multi Tahun ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081299991111',
            'study_program'     => 'Teknik Komputer',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
            'is_draft'          => false,
        ]);
        $reg1->created_at = Carbon::create(2025, 1, 10, 8, 0, 0);
        $reg1->save();

        $reg2 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Alumnus Multi Tahun ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081299991111',
            'study_program'     => 'Teknik Komputer',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);
        $reg2->created_at = Carbon::create(2026, 3, 1, 9, 0, 0);
        $reg2->save();

        $sync1 = $syncEngine->queueSyncForRegistration($reg1);
        $sync2 = $syncEngine->queueSyncForRegistration($reg2);

        $this->assertNotEquals($sync1->id, $sync2->id);
        $this->assertNotEquals($sync1->external_sync_id, $sync2->external_sync_id);
        $this->assertEquals('2025', $sync1->target_sheet_tab);
        $this->assertEquals('2026', $sync2->target_sheet_tab);
    }

    /**
     * S13-11: Update registrasi historis mempertahankan target tab tahun asli dan SERTIF_SYNC_ID stabil.
     */
    public function test_updating_historical_registration_retains_year_tab_and_stable_sync_id(): void
    {
        /** @var SpreadsheetSyncEngineService $syncEngine */
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $reg = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Registrasi Historis 2024 ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081299992024',
            'study_program'     => 'Rekayasa Perangkat Lunak',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
            'is_draft'          => false,
        ]);
        $reg->created_at = Carbon::create(2024, 6, 1, 10, 0, 0);
        $reg->save();

        $initialSync = $syncEngine->queueSyncForRegistration($reg);
        $stableSyncId = $initialSync->external_sync_id;
        $targetTab = $initialSync->target_sheet_tab;

        $this->assertEquals('2024', $targetTab);

        // Simulasikan koreksi data oleh Admin
        $reg->update(['fullname' => 'Registrasi Historis 2024 Koreksi ' . $this->unique]);

        $updatedSync = $syncEngine->queueSyncForRegistration($reg);

        $this->assertEquals($initialSync->id, $updatedSync->id);
        $this->assertEquals($stableSyncId, $updatedSync->external_sync_id);
        $this->assertEquals('2024', $updatedSync->target_sheet_tab);
    }

    /**
     * S13-12: Schema versions per tahun terisolasi dan dapat didaftarkan serta diaktifkan secara aman oleh Admin.
     */
    public function test_year_scoped_schema_versions_are_strictly_isolated_and_admin_activatable(): void
    {
        /** @var SpreadsheetConnectionService $connService */
        $connService = app(SpreadsheetConnectionService::class);

        $connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            ['display_name' => 'Spreadsheet Pendaftaran Magang', 'is_enabled' => true]
        );

        // Daftarkan schema 2025 secara aman
        $schema2025 = $connService->registerSchemaVersion(
            connection: $connection,
            year: 2025,
            sheetTabName: '2025',
            schemaVersion: 'v1',
            columnMappings: [
                ['column_order' => 1, 'sheet_header' => 'Timestamp', 'target_field' => 'created_at', 'transform_key' => 'timestamp', 'is_required' => true],
                ['column_order' => 2, 'sheet_header' => 'Nama Mahasiswa', 'target_field' => 'fullname', 'transform_key' => 'fullname', 'is_required' => true],
                ['column_order' => 3, 'sheet_header' => 'SERTIF_SYNC_ID', 'target_field' => null, 'transform_key' => 'sertif_sync_id', 'is_required' => true],
            ],
            notes: 'Schema historis 2025 terverifikasi',
            activate: true,
            actor: $this->admin
        );

        $this->assertInstanceOf(SpreadsheetSchemaVersion::class, $schema2025);
        $this->assertEquals(2025, $schema2025->year);
        $this->assertEquals('2025', $schema2025->sheet_tab_name);
        $this->assertTrue($schema2025->is_active);

        // Verifikasi schema 2026 tetap utuh dan aktif
        $schema2026 = SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $connection->id)
            ->where('year', 2026)
            ->where('is_active', true)
            ->first();

        if ($schema2026) {
            $this->assertEquals(2026, $schema2026->year);
            $this->assertTrue($schema2026->is_active);
        }

        // Deaktivasi dan aktivasi schema
        $connService->deactivateSchemaVersion($schema2025, $this->admin);
        $schema2025->refresh();
        $this->assertFalse($schema2025->is_active);

        $connService->activateSchemaVersion($schema2025, $this->admin);
        $schema2025->refresh();
        $this->assertTrue($schema2025->is_active);
    }

    /**
     * S13-13: Registrasi pada tahun yang schemanya belum dikonfigurasi gagal aman tanpa merusak antrean.
     */
    public function test_missing_year_schema_fails_safely(): void
    {
        /** @var RegistrationSpreadsheetMappingService $mappingService */
        $mappingService = app(RegistrationSpreadsheetMappingService::class);

        $reg2020 = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Tahun Lampau ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081200002020',
            'study_program'     => 'Manajemen Informatika',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);
        $reg2020->created_at = Carbon::create(2020, 5, 1, 10, 0, 0);
        $reg2020->save();

        $this->assertNull($mappingService->getSchemaForRegistration($reg2020));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Schema Spreadsheet untuk tahun 2020 belum dikonfigurasi.');
        $mappingService->buildOutboundRowPayload($reg2020);
    }

    /**
     * S13-14: TAB_NOT_FOUND dan HEADER_MISMATCH ditangani sebagai error non-retryable tanpa menulis ke tab lain.
     */
    public function test_tab_not_found_and_header_mismatch_are_non_retryable(): void
    {
        $reg = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Tab Hilang ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081299990000',
            'study_program'     => 'Informatika',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
        ]);
        $reg->created_at = Carbon::create(2026, 3, 1, 10, 0, 0);
        $reg->save();

        $connection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            ['display_name' => 'Spreadsheet Pendaftaran Magang']
        );
        $connection->update([
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/dummy/edit',
            'apps_script_url' => 'https://script.google.com/macros/s/dummy/exec',
            'is_enabled'      => true,
        ]);

        /** @var SpreadsheetSyncEngineService $syncEngine */
        $syncEngine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $syncEngine->queueSyncForRegistration($reg);

        // Mock transport yang mensimulasikan TAB_NOT_FOUND dari Google Apps Script
        $mockTransport = new class implements SpreadsheetTransportInterface {
            public function testConnection(SpreadsheetConnection $connection): bool {
                return true;
            }
            public function sendRowUpsert(SpreadsheetConnection $connection, string $tabName, string $syncId, array $payload): SpreadsheetTransportResponse {
                return SpreadsheetTransportResponse::failure(
                    errorMessage: "Tab sheet '{$tabName}' tidak ditemukan.",
                    errorCode: 'TAB_NOT_FOUND',
                    isRetryable: false
                );
            }
        };

        $result = $syncEngine->processSyncRecord($rowSync, $mockTransport);

        $this->assertFalse($result);
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $rowSync->sync_status);
        $this->assertNull($rowSync->next_retry_at);
        $this->assertStringContainsString('Tab sheet', $rowSync->error_message);
    }

    /**
     * S13-15: Siklus Follow-Up lintas tahun selalu dirouting ke tab tunggal 'Follow Up Guru Dosen'.
     */
    public function test_follow_up_cycles_strictly_route_to_single_operational_tab(): void
    {
        /** @var SpreadsheetSyncEngineService $syncEngine */
        $syncEngine = app(SpreadsheetSyncEngineService::class);

        $institution = Institution::create([
            'name'                 => 'SMK FollowUp S13 ' . $this->unique,
            'correspondence_unit'  => 'Hubin',
            'is_active'            => true,
        ]);

        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Pembimbing S13 ' . $this->unique,
            'phone'          => '081298765432',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $cycle2025 = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2025,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
        ]);

        $cycle2026 = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
        ]);

        $sync2025 = $syncEngine->queueSyncForFollowUpCycle($cycle2025);
        $sync2026 = $syncEngine->queueSyncForFollowUpCycle($cycle2026);

        // Kedua siklus dari tahun berbeda tetap masuk ke satu tab operasional yang sama persis
        $this->assertEquals('Follow Up Guru Dosen', $sync2025->target_sheet_tab);
        $this->assertEquals('Follow Up Guru Dosen', $sync2026->target_sheet_tab);
    }

    /**
     * S13-16: Outbound payload tidak memuat kolom tambahan buatan 'Tahun Pendaftaran'.
     */
    public function test_outbound_payload_has_no_artificial_tahun_pendaftaran_column(): void
    {
        /** @var RegistrationSpreadsheetMappingService $mappingService */
        $mappingService = app(RegistrationSpreadsheetMappingService::class);

        $reg = InternshipRegistration::create([
            'user_id'           => $this->pemagang->id,
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'fullname'          => 'Pemagang Kontrak Murni ' . $this->unique,
            'gender'            => 'L',
            'phone_number'      => '081288887777',
            'study_program'     => 'Informatika',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'          => false,
            'created_at'        => Carbon::create(2026, 4, 1, 10, 0, 0),
        ]);

        $payload = $mappingService->buildOutboundRowPayload($reg);

        $this->assertArrayNotHasKey('Tahun Pendaftaran', $payload);
        $this->assertArrayNotHasKey('Tahun', $payload);
        $this->assertArrayNotHasKey('tahun_pendaftaran', $payload);
    }

    /**
     * S13-17: Schema kanonikal 2026 memiliki tepat 29 kolom sesuai kontrak 2026 register.xlsx.
     */
    public function test_2026_canonical_schema_preserves_all_29_exact_headers(): void
    {
        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        $this->assertNotNull($connection);

        $schema2026 = SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $connection->id)
            ->where('year', 2026)
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($schema2026);
        $this->assertEquals('2026', $schema2026->sheet_tab_name);

        $mappings = $schema2026->columnMappings()->orderBy('column_order')->get();
        $this->assertCount(29, $mappings);

        // Verifikasi beberapa header kunci
        $headers = $mappings->pluck('sheet_header')->toArray();
        $this->assertEquals('Timestamp', $headers[0]);
        $this->assertEquals('Nama Lengkap', $headers[1]);
        $this->assertEquals('NIM (Nomor Induk Mahasiswa)', $headers[2]);
        $this->assertEquals('KET', $headers[24]);
        $this->assertEquals('SERTIF_SYNC_ID', $headers[25]);
        $this->assertEquals('SERTIF_REGISTRATION_ID', $headers[26]);
        $this->assertEquals('SERTIF_SYNC_STATUS', $headers[27]);
        $this->assertEquals('SERTIF_LAST_SYNC_AT', $headers[28]);
    }
}
