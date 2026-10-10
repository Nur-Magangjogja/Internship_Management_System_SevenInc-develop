<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\RegistrationContactLink;
use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\FollowUpSpreadsheetMappingService;
use Database\Seeders\SpreadsheetFollowUpSchemaSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StabilizationPhaseS9CTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $hrStaff1;
    protected User $hrStaff2;
    protected User $pemagang;
    protected Institution $institution;
    protected InstitutionContact $contact;
    protected SpreadsheetConnection $followupConnection;
    protected SpreadsheetSchemaVersion $schemaFollowup;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SpreadsheetFollowUpSchemaSeeder::class);

        $this->unique = uniqid('s9c_', true);

        $this->admin = User::create([
            'name'      => 'Admin S9C ' . substr($this->unique, 0, 6),
            'email'     => 'admin_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->hrStaff1 = User::create([
            'name'      => 'HR 1 S9C ' . substr($this->unique, 0, 6),
            'email'     => 'hr1_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'hr_staff',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->hrStaff2 = User::create([
            'name'      => 'HR 2 S9C ' . substr($this->unique, 0, 6),
            'email'     => 'hr2_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'hr_staff',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->pemagang = User::create([
            'name'      => 'Pemagang S9C ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_' . $this->unique . '@test.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
            'banned_at' => null,
        ]);

        $this->institution = Institution::create([
            'name'      => 'Universitas Gadjah Mada S9C ' . substr($this->unique, 0, 6),
            'is_active' => true,
        ]);

        $this->contact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Dr. Hendra Gunawan',
            'phone'          => '081234567890',
            'is_active'      => true,
            'is_verified'    => true,
        ]);

        $this->followupConnection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_FOLLOWUP)->first();
        $this->schemaFollowup = SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $this->followupConnection->id)->first();
    }

    /**
     * Test 1: Schema version and 21 exact columns are seeded idempotently for connection followup.
     */
    public function test_followup_schema_version_and_21_exact_columns_seeded(): void
    {
        $this->assertNotNull($this->schemaFollowup);
        $this->assertEquals(0, $this->schemaFollowup->year, 'Follow-up schema must use sentinel year = 0 for single operational tab');
        $this->assertEquals('Follow Up Guru Dosen', $this->schemaFollowup->sheet_tab_name);
        $this->assertEquals('v1', $this->schemaFollowup->schema_version);
        $this->assertTrue($this->schemaFollowup->is_active);

        $mappings = $this->schemaFollowup->columnMappings()->orderBy('column_order', 'asc')->get();
        $this->assertCount(21, $mappings);

        $expectedHeaders = [
            1  => 'No.',
            2  => 'Tahun Follow Up',
            3  => 'Sekolah/Kampus',
            4  => 'Dosen/Guru',
            5  => 'Nomor Telepon',
            6  => 'Status Guru Dosen',
            7  => 'Follow Up 1',
            8  => 'Follow Up 2',
            9  => 'Follow Up 3',
            10 => 'Kapan PKL/Magang Nya',
            11 => 'Respon',
            12 => 'Nama yang mengajukan Kerjasama/ Follow Up 1',
            13 => 'Nama yang mengajukan Kerjasama/ Follow Up 2',
            14 => 'Nama yang mengajukan Kerjasama/ Follow Up 3',
            15 => 'KET',
            16 => 'SERTIF_SYNC_ID',
            17 => 'SERTIF_INSTITUTION_ID',
            18 => 'SERTIF_CONTACT_ID',
            19 => 'SERTIF_FOLLOWUP_CYCLE_ID',
            20 => 'SERTIF_SYNC_STATUS',
            21 => 'SERTIF_LAST_SYNC_AT',
        ];

        foreach ($expectedHeaders as $order => $header) {
            $col = $mappings->firstWhere('column_order', $order);
            $this->assertNotNull($col, "Column order {$order} must exist");
            $this->assertEquals($header, $col->sheet_header, "Column {$order} header must match exact string");
        }
    }

    /**
     * Test 2: Seeder is idempotent and produces no errors or duplicates when run again.
     */
    public function test_seeder_is_idempotent(): void
    {
        $this->seed(SpreadsheetFollowUpSchemaSeeder::class);

        $versions = SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $this->followupConnection->id)->get();
        $this->assertCount(1, $versions);

        $mappings = $versions->first()->columnMappings;
        $this->assertCount(21, $mappings);
    }

    /**
     * Test 3: Outbound payload builds all 21 columns accurately for a FollowUpCycle.
     */
    public function test_outbound_payload_builds_all_21_columns_accurately(): void
    {
        $service = app(FollowUpSpreadsheetMappingService::class);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'final_notes'            => 'Siap mengirim 5 mahasiswa magang ke Seven Inc.',
            'current_attempt_count'  => 1,
            'started_at'             => '2026-04-01 09:00:00',
            'completed_at'           => '2026-04-01 10:30:00',
        ]);

        FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff1->id,
            'followed_up_at'    => '2026-04-01 09:30:00',
            'channel'           => 'whatsapp',
            'result'            => 'responded',
            'response_text'     => 'Berminat kerja sama',
            'notes'             => 'Kontak via WA resmi',
        ]);

        $payload = $service->buildOutboundRowPayload($cycle, 1);

        $this->assertCount(21, $payload);
        $this->assertEquals(1, $payload['No.']);
        $this->assertEquals(2026, $payload['Tahun Follow Up']);
        $this->assertEquals($this->institution->name, $payload['Sekolah/Kampus']);
        $this->assertEquals('Dr. Hendra Gunawan', $payload['Dosen/Guru']);
        $this->assertEquals('081234567890', $payload['Nomor Telepon']);
        $this->assertEquals('Terverifikasi', $payload['Status Guru Dosen']);
        $this->assertStringContainsString('2026-04-01', $payload['Follow Up 1']);
        $this->assertStringContainsString('[WhatsApp]', $payload['Follow Up 1']);
        $this->assertStringContainsString('Direspons Positif', $payload['Follow Up 1']);
        $this->assertNull($payload['Follow Up 2']);
        $this->assertNull($payload['Follow Up 3']);
        $this->assertEquals('Bersedia Kerja Sama', $payload['Respon']);
        $this->assertEquals($this->hrStaff1->name, $payload['Nama yang mengajukan Kerjasama/ Follow Up 1']);
        $this->assertNull($payload['Nama yang mengajukan Kerjasama/ Follow Up 2']);
        $this->assertNull($payload['Nama yang mengajukan Kerjasama/ Follow Up 3']);
        $this->assertEquals('Siap mengirim 5 mahasiswa magang ke Seven Inc.', $payload['KET']);
        $this->assertNull($payload['SERTIF_SYNC_ID']);
        $this->assertEquals((string) $this->institution->id, $payload['SERTIF_INSTITUTION_ID']);
        $this->assertEquals((string) $this->contact->id, $payload['SERTIF_CONTACT_ID']);
        $this->assertEquals((string) $cycle->id, $payload['SERTIF_FOLLOWUP_CYCLE_ID']);
        $this->assertEquals('pending', $payload['SERTIF_SYNC_STATUS']);
        $this->assertNull($payload['SERTIF_LAST_SYNC_AT']);
    }

    /**
     * Test 4: Outbound payload maps Attempt 1, 2, and 3 horizontally with separate HR performers.
     */
    public function test_outbound_payload_maps_attempt_1_2_3_horizontally(): void
    {
        $service = app(FollowUpSpreadsheetMappingService::class);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'final_notes'            => 'Disepakati pada attempt ketiga',
            'current_attempt_count'  => 3,
            'started_at'             => '2026-03-01 08:00:00',
            'completed_at'           => '2026-03-15 11:00:00',
        ]);

        FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff1->id,
            'followed_up_at'    => '2026-03-01 09:00:00',
            'channel'           => 'whatsapp',
            'result'            => 'no_answer',
            'notes'             => 'Pesan terkirim belum dibalas',
        ]);

        FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 2,
            'performed_by'      => $this->hrStaff2->id,
            'followed_up_at'    => '2026-03-08 14:00:00',
            'channel'           => 'phone',
            'result'            => 'call_back',
            'notes'             => 'Sedang mengajar minta ditelpon minggu depan',
        ]);

        FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 3,
            'performed_by'      => $this->hrStaff1->id,
            'followed_up_at'    => '2026-03-15 10:00:00',
            'channel'           => 'phone',
            'result'            => 'responded',
            'notes'             => 'Bersedia mengirim mahasiswa',
        ]);

        $payload = $service->buildOutboundRowPayload($cycle, 1);

        $this->assertStringContainsString('[WhatsApp]', $payload['Follow Up 1']);
        $this->assertStringContainsString('Tidak Menjawab', $payload['Follow Up 1']);
        $this->assertEquals($this->hrStaff1->name, $payload['Nama yang mengajukan Kerjasama/ Follow Up 1']);

        $this->assertStringContainsString('[Panggilan Telepon]', $payload['Follow Up 2']);
        $this->assertStringContainsString('Minta Dihubungi Lagi', $payload['Follow Up 2']);
        $this->assertEquals($this->hrStaff2->name, $payload['Nama yang mengajukan Kerjasama/ Follow Up 2']);

        $this->assertStringContainsString('[Panggilan Telepon]', $payload['Follow Up 3']);
        $this->assertStringContainsString('Direspons Positif', $payload['Follow Up 3']);
        $this->assertEquals($this->hrStaff1->name, $payload['Nama yang mengajukan Kerjasama/ Follow Up 3']);
    }

    /**
     * Test 5: Call back is represented as non-terminal without premature completion.
     */
    public function test_call_back_is_represented_as_non_terminal(): void
    {
        $service = app(FollowUpSpreadsheetMappingService::class);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_IN_PROGRESS,
            'final_result'           => null, // Not completed
            'current_attempt_count'  => 1,
            'started_at'             => '2026-03-01 08:00:00',
        ]);

        FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff1->id,
            'followed_up_at'    => '2026-03-01 09:00:00',
            'channel'           => 'whatsapp',
            'result'            => 'call_back',
            'notes'             => 'Minta dihubungi lagi besok sore',
            'next_followup_at'  => '2026-03-02',
        ]);

        $payload = $service->buildOutboundRowPayload($cycle, 1);

        $this->assertEquals('Minta Dihubungi Lagi', $payload['Respon']);
        $this->assertFalse($cycle->isClosed(), 'Cycle with call_back must not be closed');
    }

    /**
     * Test 6: S7 final result domain labels are preserved accurately.
     */
    public function test_s7_final_result_domain_labels_preserved(): void
    {
        $service = app(FollowUpSpreadsheetMappingService::class);

        $results = [
            FollowUpCycle::RESULT_WILLING             => 'Bersedia Kerja Sama',
            FollowUpCycle::RESULT_DECLINED            => 'Menolak',
            FollowUpCycle::RESULT_COOPERATION_STOPPED => 'Kerja Sama Berhenti',
            FollowUpCycle::RESULT_NO_RESPONSE         => 'Tidak Ada Respons',
            FollowUpCycle::RESULT_CALL_BACK           => 'Minta Dihubungi Lagi',
        ];

        foreach ($results as $result => $expectedLabel) {
            $contact = InstitutionContact::create([
                'institution_id' => $this->institution->id,
                'name'           => 'Dosen S9C ' . $result,
                'phone'          => '0812345' . rand(10000, 99999),
                'is_active'      => true,
                'is_verified'    => true,
            ]);

            $cycle = FollowUpCycle::create([
                'institution_id'         => $this->institution->id,
                'institution_contact_id' => $contact->id,
                'cycle_year'             => 2026,
                'status'                 => FollowUpCycle::STATUS_COMPLETED,
                'final_result'           => $result,
                'current_attempt_count'  => 1,
            ]);

            $payload = $service->buildOutboundRowPayload($cycle, 1);
            $this->assertEquals($expectedLabel, $payload['Respon'], "Result '{$result}' must map to '{$expectedLabel}'");
        }
    }

    /**
     * Test 7: Single operational tab handles cycles with different cycle_year (Mandatory Correction 2).
     */
    public function test_single_operational_tab_handles_different_cycle_years(): void
    {
        $service = app(FollowUpSpreadsheetMappingService::class);

        $cycle2025 = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2025,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'current_attempt_count'  => 1,
        ]);

        $cycle2026 = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'final_result'           => null,
            'current_attempt_count'  => 0,
        ]);

        $payload2025 = $service->buildOutboundRowPayload($cycle2025, 1);
        $payload2026 = $service->buildOutboundRowPayload($cycle2026, 2);

        $this->assertEquals(2025, $payload2025['Tahun Follow Up']);
        $this->assertEquals(2026, $payload2026['Tahun Follow Up']);
    }

    /**
     * Test 8: Multiple interns under same contact are aggregated without duplicating cycle row.
     */
    public function test_multiple_interns_under_same_contact_aggregated_in_single_row(): void
    {
        $service = app(FollowUpSpreadsheetMappingService::class);

        $faculty = Faculty::create(['institution_id' => $this->institution->id, 'name' => 'Fakultas Teknik']);
        $prodi = StudyProgram::create(['faculty_id' => $faculty->id, 'name' => 'Teknik Elektro']);
        $city = City::create(['name' => 'Sleman']);

        // Create 2 registrations
        $reg1 = InternshipRegistration::create([
            'user_id'            => $this->pemagang->id,
            'fullname'           => 'Pemagang Pertama',
            'institution_id'     => $this->institution->id,
            'faculty_id'         => $faculty->id,
            'study_program_id'   => $prodi->id,
            'city_id'            => $city->id,
            'start_date'         => '2026-07-01',
            'planned_duration'   => '3 Bulan',
            'internship_status'  => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        $pemagang2 = User::create([
            'name'     => 'Pemagang Kedua ' . substr($this->unique, 0, 6),
            'email'    => 'pemagang2_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $reg2 = InternshipRegistration::create([
            'user_id'            => $pemagang2->id,
            'fullname'           => 'Pemagang Kedua',
            'institution_id'     => $this->institution->id,
            'faculty_id'         => $faculty->id,
            'study_program_id'   => $prodi->id,
            'city_id'            => $city->id,
            'start_date'         => '2026-08-01',
            'planned_duration'   => '6 Bulan',
            'internship_status'  => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        // Link both to this contact
        RegistrationContactLink::create([
            'internship_registration_id' => $reg1->id,
            'institution_contact_id'     => $this->contact->id,
            'relation_year'              => 2026,
            'is_primary'                 => true,
            'source'                     => 'applicant',
        ]);

        RegistrationContactLink::create([
            'internship_registration_id' => $reg2->id,
            'institution_contact_id'     => $this->contact->id,
            'relation_year'              => 2026,
            'is_primary'                 => true,
            'source'                     => 'applicant',
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
        ]);

        $payload = $service->buildOutboundRowPayload($cycle, 1);

        $this->assertStringContainsString('2 Pemagang', $payload['Kapan PKL/Magang Nya']);
        $this->assertStringContainsString('2026-07-01', $payload['Kapan PKL/Magang Nya']);
        $this->assertStringContainsString('2026-08-01', $payload['Kapan PKL/Magang Nya']);
    }

    /**
     * Test 9: Soft-deleted HR performer retains historical name in payload.
     */
    public function test_soft_deleted_hr_performer_retains_historical_name(): void
    {
        $service = app(FollowUpSpreadsheetMappingService::class);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'current_attempt_count'  => 1,
        ]);

        $attempt = FollowUpAttempt::create([
            'followup_cycle_id' => $cycle->id,
            'attempt_number'    => 1,
            'performed_by'      => $this->hrStaff1->id,
            'followed_up_at'    => '2026-02-01 10:00:00',
            'channel'           => 'whatsapp',
            'result'            => 'responded',
        ]);

        $originalName = $this->hrStaff1->name;

        // Soft delete the HR staff user
        $this->hrStaff1->delete();
        $this->assertTrue($this->hrStaff1->trashed());

        $payload = $service->buildOutboundRowPayload($cycle, 1);

        $this->assertEquals($originalName, $payload['Nama yang mengajukan Kerjasama/ Follow Up 1']);
    }

    /**
     * Test 10: Unverified candidate contact status is labeled accurately.
     */
    public function test_unverified_candidate_contact_status_labeled_accurately(): void
    {
        $service = app(FollowUpSpreadsheetMappingService::class);

        $candidateContact = InstitutionContact::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Pak Guru Baru',
            'phone'          => '089988776655',
            'is_active'      => true,
            'is_verified'    => false, // Unverified candidate
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $this->institution->id,
            'institution_contact_id' => $candidateContact->id,
            'cycle_year'             => 2026,
            'status'                 => FollowUpCycle::STATUS_OPEN,
            'current_attempt_count'  => 0,
        ]);

        $payload = $service->buildOutboundRowPayload($cycle, 1);
        $this->assertEquals('Kandidat', $payload['Status Guru Dosen']);
    }

    /**
     * Test 11: Header compatibility validation detects missing, renamed, or misordered headers.
     */
    public function test_header_compatibility_validation(): void
    {
        $service = app(FollowUpSpreadsheetMappingService::class);

        $correctHeaders = $this->schemaFollowup->columnMappings()->orderBy('column_order', 'asc')->pluck('sheet_header')->toArray();

        // 1. Exact match -> compatible
        $val1 = $service->validateHeaderCompatibility($this->schemaFollowup, $correctHeaders);
        $this->assertTrue($val1['is_compatible']);
        $this->assertEmpty($val1['missing_headers']);
        $this->assertEmpty($val1['misordered_headers']);

        // 2. Missing header
        $missing = $correctHeaders;
        unset($missing[1]); // remove Tahun Follow Up
        $val2 = $service->validateHeaderCompatibility($this->schemaFollowup, array_values($missing));
        $this->assertFalse($val2['is_compatible']);
        $this->assertNotEmpty($val2['missing_headers']);

        // 3. Misordered header
        $misordered = $correctHeaders;
        $temp = $misordered[0];
        $misordered[0] = $misordered[1];
        $misordered[1] = $temp;
        $val3 = $service->validateHeaderCompatibility($this->schemaFollowup, $misordered);
        $this->assertFalse($val3['is_compatible']);
        $this->assertNotEmpty($val3['misordered_headers']);
    }

    /**
     * Test 12: Admin can view followup column mappings UI and non-admin is forbidden.
     */
    public function test_admin_can_view_followup_show_ui_and_non_admin_forbidden(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.spreadsheet-connections.show', $this->followupConnection));
        $response->assertStatus(200);
        $response->assertSee('Follow Up Guru Dosen');
        $response->assertSee('Dosen/Guru');
        $response->assertSee('Kapan PKL/Magang Nya');

        // HR staff -> 403 (JSON) or redirect (Web)
        $this->actingAs($this->hrStaff1)->getJson(route('admin.spreadsheet-connections.show', $this->followupConnection))
            ->assertStatus(403);
        $this->actingAs($this->hrStaff1)->get(route('admin.spreadsheet-connections.show', $this->followupConnection))
            ->assertRedirect(route('hr.dashboard'));

        // Pemagang -> 403 (JSON) or redirect (Web)
        $this->actingAs($this->pemagang)->getJson(route('admin.spreadsheet-connections.show', $this->followupConnection))
            ->assertStatus(403);
        $this->actingAs($this->pemagang)->get(route('admin.spreadsheet-connections.show', $this->followupConnection))
            ->assertRedirect(route('user.dashboard'));
    }
}
