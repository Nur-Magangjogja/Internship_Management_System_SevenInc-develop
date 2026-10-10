<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\InternInfoSource;
use App\Models\InternTool;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\RegistrationSpreadsheetMappingService;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StabilizationPhaseS9BTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $hrStaff;
    protected User $pemagang;
    protected Institution $defaultInstitution;
    protected Faculty $defaultFaculty;
    protected StudyProgram $defaultStudyProgram;
    protected SpreadsheetConnection $regConnection;
    protected SpreadsheetSchemaVersion $schema2026;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(Spreadsheet2026SchemaSeeder::class);

        $this->unique = uniqid('s9b_', true);

        $this->admin = User::create([
            'name'      => 'Admin S9B ' . substr($this->unique, 0, 6),
            'email'     => 'admin_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->hrStaff = User::create([
            'name'      => 'HR S9B ' . substr($this->unique, 0, 6),
            'email'     => 'hr_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'hr_staff',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang S9B ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->defaultInstitution = Institution::create([
            'name' => 'Universitas Default S9B ' . substr($this->unique, 0, 6),
            'is_active' => true,
        ]);

        $this->defaultFaculty = Faculty::create([
            'institution_id' => $this->defaultInstitution->id,
            'name' => 'Fakultas Default S9B',
        ]);

        $this->defaultStudyProgram = StudyProgram::create([
            'faculty_id' => $this->defaultFaculty->id,
            'name' => 'Prodi Default S9B',
        ]);

        $this->regConnection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        $this->schema2026 = SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $this->regConnection->id)
            ->where('year', 2026)
            ->first();
    }

    private function createRegistration(array $attributes = []): InternshipRegistration
    {
        return InternshipRegistration::create(array_merge([
            'user_id' => $this->pemagang->id,
            'fullname' => 'Test Applicant',
            'institution_id' => $this->defaultInstitution->id,
            'faculty_id' => $this->defaultFaculty->id,
            'study_program_id' => $this->defaultStudyProgram->id,
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ], $attributes));
    }

    /**
     * Test 1: Schema version exists and is linked to registration connection.
     */
    public function test_schema_version_can_be_created_for_connection(): void
    {
        $this->assertNotNull($this->schema2026);
        $this->assertEquals(2026, $this->schema2026->year);
        $this->assertEquals('2026', $this->schema2026->sheet_tab_name);
        $this->assertEquals('v1', $this->schema2026->schema_version);
        $this->assertTrue($this->schema2026->is_active);
    }

    /**
     * Test 2: Schema version uniqueness constraint per connection, year, and version.
     */
    public function test_schema_version_is_unique_per_connection_year_and_version(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'year' => 2026,
            'sheet_tab_name' => '2026',
            'schema_version' => 'v1',
            'is_active' => true,
        ]);
    }

    /**
     * Test 3: Seeder populates exactly 29 exact columns for 2026 schema (Guardrail 1).
     */
    public function test_seeder_populates_29_exact_columns_for_2026_schema(): void
    {
        $mappings = $this->schema2026->columnMappings()->orderBy('column_order', 'asc')->get();

        $this->assertCount(29, $mappings);

        $expectedHeaders = [
            1 => 'Timestamp',
            2 => 'Nama Lengkap',
            3 => 'NIM (Nomor Induk Mahasiswa)',
            4 => 'Program Studi',
            5 => 'Email',
            6 => 'Jenis Kelamin',
            7 => 'No. HP Aktif yang bisa dihubungi dan chat WA',
            8 => 'Asal Sekolah/Kampus',
            9 => 'Nama Kota/Daerah tempat tinggal Anda saat ini',
            10 => 'Mengapa Anda ingin Magang/PKL disini?',
            11 => 'Jenis Magang apa yang Anda pilih?',
            12 => 'Status Anda saat ini',
            13 => 'Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?',
            14 => 'No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan',
            15 => 'Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)',
            16 => 'Apakah ada laptop sendiri',
            17 => 'Jika YA, alat apa yang dimiliki/dibawa',
            18 => 'Kapan berencana mulai Magang/PKL',
            19 => 'Berapa lama akan Magang/PKL',
            20 => 'Sumber info magang',
            21 => 'Instagram',
            22 => 'Butuh info kost/kontrakan',
            23 => 'Program Magang/PKL yang diminati',
            24 => 'Upload PDF CV + scan KTM/KTP + portofolio max 10MB',
            25 => 'KET',
            26 => 'SERTIF_SYNC_ID',
            27 => 'SERTIF_REGISTRATION_ID',
            28 => 'SERTIF_SYNC_STATUS',
            29 => 'SERTIF_LAST_SYNC_AT',
        ];

        foreach ($expectedHeaders as $order => $header) {
            $mapping = $mappings->firstWhere('column_order', $order);
            $this->assertNotNull($mapping, "Column order {$order} must exist");
            $this->assertEquals($header, $mapping->sheet_header, "Column {$order} header must match exact string");
        }
    }

    /**
     * Test 4: Column order and sheet header are unique within a schema version.
     */
    public function test_column_order_and_headers_are_unique_within_schema_version(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SpreadsheetColumnMapping::create([
            'spreadsheet_schema_version_id' => $this->schema2026->id,
            'column_order' => 1, // duplicate order
            'sheet_header' => 'Timestamp Duplikat',
            'target_entity' => 'internship_registration',
            'target_field' => 'created_at',
            'data_type' => 'timestamp',
            'direction' => 'system',
        ]);
    }

    /**
     * Test 5: Registration timestamp routes to correct year tab based on created_at.
     */
    public function test_registration_timestamp_routes_to_correct_year_tab(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $reg = $this->createRegistration([
            'fullname' => 'Calon Magang 2026',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'gender' => 'Laki-laki',
            'phone_number' => '081234567890',
            'start_date' => '2026-06-01',
            'planned_duration' => '3 Bulan',
            'wfo_readiness' => 'Siap WFO',
            'schedule_shift_readiness' => 'Bersedia',
            'has_laptop' => true,
            'internship_type' => 'Mandiri',
            'current_status' => 'Masih Kuliah',
        ]);

        // Override created_at to 2026-03-15
        $reg->created_at = \Carbon\Carbon::create(2026, 3, 15, 10, 0, 0);
        $reg->save();

        $schema = $service->getSchemaForRegistration($reg);

        $this->assertNotNull($schema);
        $this->assertEquals(2026, $schema->year);
        $this->assertEquals('2026', $schema->sheet_tab_name);
    }

    /**
     * Test 6: Registration from another year routes to respective year schema.
     */
    public function test_registration_from_different_year_routes_to_respective_tab(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        // Create 2025 schema version
        $schema2025 = SpreadsheetSchemaVersion::create([
            'spreadsheet_connection_id' => $this->regConnection->id,
            'year' => 2025,
            'sheet_tab_name' => '2025',
            'schema_version' => 'v1',
            'is_active' => true,
        ]);

        $reg2025 = $this->createRegistration([
            'fullname' => 'Alumni 2025',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
            'gender' => 'Perempuan',
            'phone_number' => '081298765432',
            'start_date' => '2025-02-01',
            'planned_duration' => '6 Bulan',
            'wfo_readiness' => 'Siap WFO',
            'schedule_shift_readiness' => 'Bersedia',
            'has_laptop' => false,
            'internship_type' => 'Reguler',
            'current_status' => 'Lulus Kuliah',
        ]);

        $reg2025->created_at = \Carbon\Carbon::create(2025, 1, 10, 8, 30, 0);
        $reg2025->save();

        $schema = $service->getSchemaForRegistration($reg2025);

        $this->assertNotNull($schema);
        $this->assertEquals(2025, $schema->year);
        $this->assertEquals('2025', $schema->sheet_tab_name);
    }

    /**
     * Test 7: Draft registration is strictly ineligible for sync.
     */
    public function test_draft_registration_is_strictly_ineligible_for_sync(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $draftReg = $this->createRegistration([
            'fullname' => 'Draft Applicant',
            'internship_status' => InternshipRegistration::STATUS_NEW,
        ]);

        $this->assertFalse($service->isEligibleForSync($draftReg));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pendaftaran berstatus draft/belum submitted tidak eligible untuk sinkronisasi Spreadsheet.');

        $service->buildOutboundRowPayload($draftReg);
    }

    /**
     * Test 8: Submitted registrations are eligible for sync.
     */
    public function test_submitted_registration_is_eligible_for_sync(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $eligibleStatuses = [
            InternshipRegistration::STATUS_WAITING,
            InternshipRegistration::STATUS_ACCEPTED,
            InternshipRegistration::STATUS_REJECTED,
            InternshipRegistration::STATUS_ACTIVE,
            InternshipRegistration::STATUS_COMPLETED,
            InternshipRegistration::STATUS_EXITED,
            InternshipRegistration::STATUS_PENDING,
        ];

        foreach ($eligibleStatuses as $status) {
            $reg = $this->createRegistration([
                'fullname' => 'Applicant ' . $status,
                'internship_status' => $status,
            ]);

            $this->assertTrue(
                $service->isEligibleForSync($reg),
                "Registration with status '{$status}' must be eligible for sync"
            );
        }
    }

    /**
     * Test 9: Outbound payload builds all 29 columns accurately with exact headers.
     */
    public function test_outbound_payload_builds_all_29_columns_accurately(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $institution = Institution::create(['name' => 'Universitas Gadjah Mada']);
        $faculty = Faculty::create(['institution_id' => $institution->id, 'name' => 'Fakultas MIPA']);
        $prodi = StudyProgram::create(['faculty_id' => $faculty->id, 'name' => 'Ilmu Komputer']);
        $city = City::create(['name' => 'Yogyakarta']);
        $division = Division::create(['name' => 'Web Developer', 'code' => 'WEB']);

        $reg = $this->createRegistration([
            'fullname' => 'Budi Santoso',
            'student_id' => '22/123456/PA/99999',
            'gender' => 'Laki-laki',
            'phone_number' => '08123456789',
            'institution_id' => $institution->id,
            'faculty_id' => $faculty->id,
            'study_program_id' => $prodi->id,
            'city_id' => $city->id,
            'division_id' => $division->id,
            'internship_reason' => 'Ingin memperdalam Laravel dan Vue',
            'internship_type' => 'Mandiri',
            'current_status' => 'Masih Kuliah',
            'wfo_readiness' => 'Siap WFO di Yogyakarta',
            'supervisor_name' => 'Dr. Bambang',
            'supervisor_contact' => '08198765432',
            'schedule_shift_readiness' => 'Sanggup jadwal shift',
            'has_laptop' => true,
            'start_date' => '2026-07-01',
            'planned_duration' => '3 Bulan',
            'social_media_instagram' => '@budi_santoso',
            'boarding_info' => 'Butuh Info',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $reg->created_at = \Carbon\Carbon::create(2026, 5, 20, 14, 30, 0);
        $reg->save();

        InternTool::create(['internship_registration_id' => $reg->id, 'tool_name' => 'Laptop Core i7, Mouse Wireless']);
        InternInfoSource::create(['internship_registration_id' => $reg->id, 'source_name' => 'Instagram Seven Inc']);

        $payload = $service->buildOutboundRowPayload($reg);

        $this->assertCount(29, $payload);
        $this->assertEquals('2026-05-20 14:30:00', $payload['Timestamp']);
        $this->assertEquals('Budi Santoso', $payload['Nama Lengkap']);
        $this->assertEquals('22/123456/PA/99999', $payload['NIM (Nomor Induk Mahasiswa)']);
        $this->assertEquals('Ilmu Komputer', $payload['Program Studi']);
        $this->assertEquals($this->pemagang->email, $payload['Email']);
        $this->assertEquals('Laki-laki', $payload['Jenis Kelamin']);
        $this->assertEquals('08123456789', $payload['No. HP Aktif yang bisa dihubungi dan chat WA']);
        $this->assertEquals('Universitas Gadjah Mada', $payload['Asal Sekolah/Kampus']);
        $this->assertEquals('Yogyakarta', $payload['Nama Kota/Daerah tempat tinggal Anda saat ini']);
        $this->assertEquals('Ingin memperdalam Laravel dan Vue', $payload['Mengapa Anda ingin Magang/PKL disini?']);
        $this->assertEquals('Mandiri', $payload['Jenis Magang apa yang Anda pilih?']);
        $this->assertEquals('Masih Kuliah', $payload['Status Anda saat ini']);
        $this->assertEquals('Siap WFO di Yogyakarta', $payload['Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?']);
        $this->assertEquals('Dr. Bambang (08198765432)', $payload['No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan']);
        $this->assertEquals('Sanggup jadwal shift', $payload['Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)']);
        $this->assertEquals('YA', $payload['Apakah ada laptop sendiri']);
        $this->assertStringContainsString('Laptop Core i7', $payload['Jika YA, alat apa yang dimiliki/dibawa']);
        $this->assertEquals('2026-07-01', $payload['Kapan berencana mulai Magang/PKL']);
        $this->assertEquals('3 Bulan', $payload['Berapa lama akan Magang/PKL']);
        $this->assertStringContainsString('Instagram Seven Inc', $payload['Sumber info magang']);
        $this->assertEquals('@budi_santoso', $payload['Instagram']);
        $this->assertEquals('Butuh Info', $payload['Butuh info kost/kontrakan']);
        $this->assertEquals('Web Developer', $payload['Program Magang/PKL yang diminati']);
        $this->assertEquals('Diterima', $payload['KET']);
        $this->assertEquals((string) $reg->id, $payload['SERTIF_REGISTRATION_ID']);
    }

    /**
     * Test 10: Outbound payload formats has_laptop boolean accurately.
     */
    public function test_outbound_payload_formats_has_laptop_boolean_as_text(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $regNoLaptop = $this->createRegistration([
            'fullname' => 'Tanpa Laptop',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
            'has_laptop' => false,
        ]);
        $regNoLaptop->created_at = \Carbon\Carbon::create(2026, 1, 1);
        $regNoLaptop->save();

        $payload = $service->buildOutboundRowPayload($regNoLaptop);
        $this->assertEquals('TIDAK', $payload['Apakah ada laptop sendiri']);
    }

    /**
     * Test 11: Outbound payload status KET matches SertIF status label (Source of Truth).
     */
    public function test_outbound_payload_status_ket_matches_sertif_label(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $statusPairs = [
            InternshipRegistration::STATUS_WAITING => 'Menunggu',
            InternshipRegistration::STATUS_ACCEPTED => 'Diterima',
            InternshipRegistration::STATUS_REJECTED => 'Ditolak',
            InternshipRegistration::STATUS_ACTIVE => 'Aktif',
            InternshipRegistration::STATUS_COMPLETED => 'Selesai',
            InternshipRegistration::STATUS_EXITED => 'Keluar',
        ];

        foreach ($statusPairs as $status => $expectedLabel) {
            $reg = $this->createRegistration([
                'fullname' => 'Test Status ' . $status,
                'internship_status' => $status,
            ]);
            $reg->created_at = \Carbon\Carbon::create(2026, 1, 1);
            $reg->save();

            $payload = $service->buildOutboundRowPayload($reg);
            $this->assertEquals($expectedLabel, $payload['KET'], "KET for status '{$status}' must match SertIF label");
        }
    }

    /**
     * Test 12: Guardrail 2 — Attachment column returns null in S9B without exposing local paths.
     */
    public function test_outbound_payload_attachment_url_returns_null_in_s9b(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $reg = $this->createRegistration([
            'fullname' => 'Attachment Test',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);
        $reg->created_at = \Carbon\Carbon::create(2026, 1, 1);
        $reg->save();

        $payload = $service->buildOutboundRowPayload($reg);
        $this->assertNull($payload['Upload PDF CV + scan KTM/KTP + portofolio max 10MB']);
    }

    /**
     * Test 13: Guardrail 2 — Metadata columns do not fabricate synced state in S9B.
     */
    public function test_outbound_payload_meta_columns_do_not_fabricate_synced_state(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $reg = $this->createRegistration([
            'fullname' => 'Metadata Test',
            'internship_status' => InternshipRegistration::STATUS_WAITING,
        ]);
        $reg->created_at = \Carbon\Carbon::create(2026, 1, 1);
        $reg->save();

        $payload = $service->buildOutboundRowPayload($reg);

        $this->assertNull($payload['SERTIF_SYNC_ID'], 'SERTIF_SYNC_ID must be null in S9B');
        $this->assertEquals((string) $reg->id, $payload['SERTIF_REGISTRATION_ID']);
        $this->assertEquals('pending', $payload['SERTIF_SYNC_STATUS'], 'SERTIF_SYNC_STATUS must be pending');
        $this->assertNull($payload['SERTIF_LAST_SYNC_AT'], 'SERTIF_LAST_SYNC_AT must be null in S9B');
    }

    /**
     * Test 14: Guardrail 1 — Header compatibility validation detects missing, renamed, or misordered headers.
     */
    public function test_header_compatibility_validation_detects_incompatibilities(): void
    {
        $service = app(RegistrationSpreadsheetMappingService::class);

        $correctHeaders = $this->schema2026->columnMappings()->orderBy('column_order', 'asc')->pluck('sheet_header')->toArray();

        // 1. Exact match -> compatible
        $val1 = $service->validateHeaderCompatibility($this->schema2026, $correctHeaders);
        $this->assertTrue($val1['is_compatible']);
        $this->assertEmpty($val1['missing_headers']);
        $this->assertEmpty($val1['misordered_headers']);

        // 2. Missing header
        $missingHeaders = $correctHeaders;
        unset($missingHeaders[1]); // remove Nama Lengkap
        $val2 = $service->validateHeaderCompatibility($this->schema2026, array_values($missingHeaders));
        $this->assertFalse($val2['is_compatible']);
        $this->assertNotEmpty($val2['missing_headers']);

        // 3. Misordered header
        $misorderedHeaders = $correctHeaders;
        $temp = $misorderedHeaders[0];
        $misorderedHeaders[0] = $misorderedHeaders[1];
        $misorderedHeaders[1] = $temp;
        $val3 = $service->validateHeaderCompatibility($this->schema2026, $misorderedHeaders);
        $this->assertFalse($val3['is_compatible']);
        $this->assertNotEmpty($val3['misordered_headers']);
    }

    /**
     * Test 15: Admin can view connection schema mappings show UI and non-admin is forbidden.
     */
    public function test_admin_can_view_show_ui_and_non_admin_forbidden(): void
    {
        // Admin -> 200 OK
        $response = $this->actingAs($this->admin)->get(route('admin.spreadsheet-connections.show', $this->regConnection));
        $response->assertStatus(200);
        $response->assertViewIs('admin.spreadsheet-connections.show');
        $response->assertSee('2026');
        $response->assertSee('Timestamp');
        $response->assertSee('Upload PDF CV + scan KTM/KTP + portofolio max 10MB');

        // HR staff -> 403 (JSON) or redirect (Web)
        $this->actingAs($this->hrStaff)->getJson(route('admin.spreadsheet-connections.show', $this->regConnection))
            ->assertStatus(403);
        $this->actingAs($this->hrStaff)->get(route('admin.spreadsheet-connections.show', $this->regConnection))
            ->assertRedirect(route('hr.dashboard'));

        // Pemagang -> 403 (JSON) or redirect (Web)
        $this->actingAs($this->pemagang)->getJson(route('admin.spreadsheet-connections.show', $this->regConnection))
            ->assertStatus(403);
        $this->actingAs($this->pemagang)->get(route('admin.spreadsheet-connections.show', $this->regConnection))
            ->assertRedirect(route('user.dashboard'));
    }
}
