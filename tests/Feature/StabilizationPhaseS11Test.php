<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration as IR;
use App\Models\Mou;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\FollowUpService;
use App\Services\RegistrationLifecycleService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Database\Seeders\Spreadsheet2026SchemaSeeder;
use Database\Seeders\SpreadsheetFollowUpSchemaSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StabilizationPhaseS11Test extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Institution $institution;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(Spreadsheet2026SchemaSeeder::class);
        $this->seed(SpreadsheetFollowUpSchemaSeeder::class);

        $this->unique = uniqid('s11_', true);

        $this->adminUser = User::create([
            'role'      => 'admin',
            'email'     => 'admin_' . $this->unique . '@example.com',
            'name'      => 'Super Admin Auditor ' . substr($this->unique, 0, 6),
            'is_active' => true,
            'password'  => Hash::make('Secret123!'),
        ]);

        $this->institution = Institution::create([
            'name'            => 'Universitas S11 ' . substr($this->unique, 0, 6),
            'normalized_name' => Institution::normalizeName('Universitas S11 ' . substr($this->unique, 0, 6)),
            'is_active'       => true,
        ]);
    }

    /**
     * Helper to create a valid registration
     */
    private function createDummyRegistration(User $user, array $overrides = []): IR
    {
        $faculty = Faculty::firstOrCreate(
            ['institution_id' => $this->institution->id, 'name' => 'Fakultas S11']
        );
        $prodi = StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Informatika S11']
        );
        $city = City::firstOrCreate(['name' => 'Yogyakarta']);

        return IR::create(array_merge([
            'user_id'           => $user->id,
            'fullname'          => $user->name,
            'name'              => $user->name,
            'student_id'        => '22/PA/10001',
            'gender'            => 'Laki-laki',
            'phone'             => '08123456789',
            'phone_number'      => '08123456789',
            'institution_id'    => $this->institution->id,
            'institution_name'  => $this->institution->name,
            'faculty_id'        => $faculty->id,
            'study_program_id'  => $prodi->id,
            'major'             => 'Informatika',
            'city_id'           => $city->id,
            'internship_status' => IR::STATUS_WAITING,
        ], $overrides));
    }

    /**
     * 1. Activity log snapshot & sensitive data redaction.
     */
    public function test_activity_log_records_actor_snapshot_and_redacts_sensitive_keys(): void
    {
        $this->actingAs($this->adminUser);

        $log = ActivityLogger::log(
            action: 'test_action_audit',
            description: 'Test logging action description',
            subject: $this->adminUser,
            before: ['password' => 'secret_plain', 'name' => 'Old Name'],
            after: ['password' => 'new_secret_plain', 'name' => 'New Name'],
            metadata: [
                'api_key'       => 'live_sk_1234567890',
                'client_secret' => 'super_confidential_secret',
                'safe_info'     => 'public_identifier_abc',
            ]
        );

        $this->assertInstanceOf(ActivityLog::class, $log);
        $this->assertDatabaseHas('activity_logs', [
            'action'               => 'test_action_audit',
            'actor_user_id'        => $this->adminUser->id,
            'actor_name_snapshot'  => $this->adminUser->name,
            'actor_email_snapshot' => $this->adminUser->email,
            'actor_role_snapshot'  => 'admin',
        ]);

        $savedLog = ActivityLog::where('action', 'test_action_audit')
            ->where('actor_user_id', $this->adminUser->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($savedLog);

        // Verify password redaction in before & after
        $this->assertEquals('[REDACTED]', $savedLog->before_data['password']);
        $this->assertEquals('Old Name', $savedLog->before_data['name']);
        $this->assertEquals('[REDACTED]', $savedLog->after_data['password']);
        $this->assertEquals('New Name', $savedLog->after_data['name']);

        // Verify metadata redaction
        $this->assertEquals('[REDACTED]', $savedLog->metadata['api_key']);
        $this->assertEquals('[REDACTED]', $savedLog->metadata['client_secret']);
        $this->assertEquals('public_identifier_abc', $savedLog->metadata['safe_info']);
    }

    /**
     * 2. Actor snapshot preserved even if actor user is soft-deleted.
     */
    public function test_activity_log_actor_relationship_works_with_soft_deleted_users(): void
    {
        $staff = User::create([
            'role'      => 'hr_staff',
            'name'      => 'HR Staff One ' . substr($this->unique, 0, 6),
            'email'     => 'staff_one_' . $this->unique . '@example.com',
            'password'  => bcrypt('password123'),
            'is_active' => true,
        ]);

        $this->actingAs($staff);

        $log = ActivityLogger::log(
            action: 'staff_operation_performed',
            description: 'Staff executed an operation'
        );

        $this->assertEquals($staff->name, $log->actor_name_snapshot);
        $this->assertEquals($staff->email, $log->actor_email_snapshot);
        $this->assertEquals('hr_staff', $log->actor_role_snapshot);

        // Soft delete the staff user
        $staff->delete();
        $this->assertSoftDeleted('users', ['id' => $staff->id]);

        // Reload log and check relation using withTrashed()
        $freshLog = ActivityLog::find($log->id);
        $this->assertNotNull($freshLog->actor);
        $this->assertEquals($staff->name, $freshLog->actor->name);
        $this->assertEquals($staff->email, $freshLog->actor_email_snapshot);
    }

    /**
     * 3. Admin can view activity logs and filter by action and actor_role.
     */
    public function test_admin_can_view_activity_logs_and_filter_by_action_and_actor_role(): void
    {
        ActivityLog::create([
            'public_uuid'          => (string) Str::uuid(),
            'actor_user_id'        => $this->adminUser->id,
            'actor_name_snapshot'  => 'Admin S11 Filter',
            'actor_email_snapshot' => 'admin_filter@test.com',
            'actor_role_snapshot'  => 'admin',
            'action'               => 'user_created',
            'description'          => 'Admin created user ' . $this->unique,
            'created_at'           => now(),
        ]);

        ActivityLog::create([
            'public_uuid'          => (string) Str::uuid(),
            'actor_user_id'        => null,
            'actor_name_snapshot'  => 'Pemagang S11 Filter',
            'actor_email_snapshot' => 'pemagang_filter@test.com',
            'actor_role_snapshot'  => 'pemagang',
            'action'               => 'intern_registration_submitted',
            'description'          => 'Pemagang submitted registration ' . $this->unique,
            'created_at'           => now(),
        ]);

        $response = $this->actingAs($this->adminUser)->get(route('admin.activity-logs.index'));
        $response->assertStatus(200);
        $response->assertSee('Admin created user ' . $this->unique);
        $response->assertSee('Pemagang submitted registration ' . $this->unique);

        // Test filter by action
        $filterAction = $this->actingAs($this->adminUser)->get(route('admin.activity-logs.index', ['action' => 'user_created']));
        $filterAction->assertStatus(200);
        $filterAction->assertSee('Admin created user ' . $this->unique);
        $filterAction->assertDontSee('Pemagang submitted registration ' . $this->unique);

        // Test filter by actor_role
        $filterRole = $this->actingAs($this->adminUser)->get(route('admin.activity-logs.index', ['actor_role' => 'pemagang']));
        $filterRole->assertStatus(200);
        $filterRole->assertSee('Pemagang submitted registration ' . $this->unique);
        $filterRole->assertDontSee('Admin created user ' . $this->unique);
    }

    /**
     * 4. Non-admin roles (guest, pemagang, hr_staff) cannot access activity logs.
     */
    public function test_non_admin_cannot_access_activity_logs(): void
    {
        $guestResp = $this->get(route('admin.activity-logs.index'));
        $guestResp->assertRedirect(route('user.login'));

        $pemagang = User::create([
            'role'      => 'pemagang',
            'name'      => 'Pemagang User ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_' . $this->unique . '@example.com',
            'password'  => bcrypt('password123'),
            'is_active' => true,
        ]);
        $pemagangResp = $this->actingAs($pemagang)->get(route('admin.activity-logs.index'));
        $pemagangResp->assertRedirect(route('user.dashboard'));

        $pemagangJson = $this->actingAs($pemagang)->getJson(route('admin.activity-logs.index'));
        $pemagangJson->assertStatus(403);

        $hrStaff = User::create([
            'role'      => 'hr_staff',
            'name'      => 'HR Staff ' . substr($this->unique, 0, 6),
            'email'     => 'hr_staff_' . $this->unique . '@example.com',
            'password'  => bcrypt('password123'),
            'is_active' => true,
        ]);
        $hrResp = $this->actingAs($hrStaff)->get(route('admin.activity-logs.index'));
        $hrResp->assertRedirect(route('hr.dashboard'));

        $hrJson = $this->actingAs($hrStaff)->getJson(route('admin.activity-logs.index'));
        $hrJson->assertStatus(403);
    }

    /**
     * 5. User & HR Staff lifecycle events are logged.
     */
    public function test_user_and_hr_staff_lifecycle_events_are_logged(): void
    {
        $this->actingAs($this->adminUser);

        // Create HR Staff via controller
        $storeResp = $this->post(route('admin.hr-staff.store'), [
            'name'                  => 'Ahmad HR ' . substr($this->unique, 0, 6),
            'email'                 => 'ahmad_hr_' . $this->unique . '@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'is_active'             => '1',
        ]);
        $storeResp->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'hr_staff_created',
            'actor_user_id' => $this->adminUser->id,
        ]);

        // Create standard pemagang user to test ban
        $pemagang = User::create([
            'role'      => 'pemagang',
            'name'      => 'Banned User ' . substr($this->unique, 0, 6),
            'email'     => 'banned_' . $this->unique . '@example.com',
            'password'  => bcrypt('password123'),
            'is_active' => true,
        ]);

        // Ban user via admin.users.ban
        $banResp = $this->post(route('admin.users.ban', $pemagang->id), [
            'ban_reason' => 'Pelanggaran kebijakan sistem.',
        ]);
        $banResp->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'user_banned',
            'actor_user_id' => $this->adminUser->id,
        ]);
    }

    /**
     * 6. Registration submission vs draft audit log.
     */
    public function test_intern_registration_submission_and_status_correction_are_logged(): void
    {
        $user = User::create([
            'role'      => 'user',
            'name'      => 'Budi ' . substr($this->unique, 0, 6),
            'email'     => 'budi_' . $this->unique . '@example.com',
            'password'  => bcrypt('password123'),
            'is_active' => true,
        ]);
        $this->actingAs($user);

        // Ensure Division exists
        Division::firstOrCreate(
            ['slug' => 'web-development'],
            ['name' => 'Web Development']
        );

        // 1. Draft registration should NOT trigger intern_registration_submitted
        $draftResp = $this->post(route('pemagang.registration.draft'), [
            'is_draft'         => '1',
            'fullname'         => 'Draft Intern User',
            'institution_name' => 'Universitas Gadjah Mada',
            'study_program'    => 'Informatika',
        ]);
        $draftResp->assertSessionHas('success');

        $this->assertDatabaseMissing('activity_logs', [
            'action'              => 'intern_registration_submitted',
            'actor_name_snapshot' => $user->name,
        ]);

        // 2. Official final submission DOES trigger intern_registration_submitted
        $submitResp = $this->post(route('pemagang.registration.store'), [
            'fullname'                  => 'Budi Prakoso ' . substr($this->unique, 0, 6),
            'name'                      => 'Budi Prakoso ' . substr($this->unique, 0, 6),
            'student_id'                => '22/PA/10001',
            'gender'                    => 'Laki-laki',
            'email'                     => $user->email,
            'phone_number'              => '081234567890',
            'phone'                     => '081234567890',
            'institution_name'          => 'Universitas Gadjah Mada',
            'study_program'             => 'Teknik Informatika',
            'major'                     => 'Teknik Informatika',
            'current_city'              => 'Yogyakarta',
            'current_address'           => 'Jl. Kaliurang KM 5',
            'internship_type'           => 'smk_prakerin',
            'current_status'            => 'belum_lulus',
            'internship_reason'         => 'Mengembangkan keterampilan industri.',
            'internship_interest'       => 'Web Development',
            'wfo_readiness'             => '1',
            'schedule_shift_readiness'  => '1',
            'has_laptop'                => '1',
            'planned_duration'          => '3_bulan',
            'start_date'                => now()->format('Y-m-d'),
            'end_date'                  => now()->addMonths(3)->format('Y-m-d'),
            'status_pembimbing'         => 'ada',
            'pembimbing_nama'           => 'Dosen Penguji',
            'pembimbing_phone'          => '081987654321',
            'internship_info_sources'   => ['Instagram'],
        ]);
        $submitResp->assertRedirect();

        $this->assertDatabaseHas('activity_logs', [
            'action'               => 'intern_registration_submitted',
            'actor_name_snapshot'  => $user->name,
            'actor_email_snapshot' => $user->email,
        ]);

        // 3. Status update / correction by Admin
        $intern = IR::where('user_id', $user->id)->first();
        $this->assertNotNull($intern);

        $this->actingAs($this->adminUser);
        $lifecycle = app(RegistrationLifecycleService::class);
        $lifecycle->transition($intern, IR::STATUS_ACCEPTED, isAdmin: true, reason: 'Dokumen dan wawancara valid');

        $this->assertDatabaseHas('activity_logs', [
            'action'               => 'intern_registration_status_updated',
            'actor_user_id'        => $this->adminUser->id,
            'actor_role_snapshot'  => 'admin',
        ]);
    }

    /**
     * 7. Admin registration update logs meaningful diff without duplicating lifecycle status event.
     */
    public function test_admin_intern_registration_update_logs_meaningful_diff_without_duplicate_status_event(): void
    {
        $user = User::create([
            'role'      => 'pemagang',
            'name'      => 'Intern User ' . substr($this->unique, 0, 6),
            'email'     => 'intern_' . $this->unique . '@example.com',
            'password'  => bcrypt('password123'),
            'is_active' => true,
        ]);

        $intern = $this->createDummyRegistration($user, [
            'name'             => 'Original Name',
            'fullname'         => 'Original Name',
            'student_id'       => '22/PA/10001',
            'phone'            => '0811111111',
            'phone_number'     => '0811111111',
            'institution_name' => 'Original University',
            'major'            => 'Original Major',
            'brand_id'         => null,
            'internship_status'=> IR::STATUS_WAITING,
        ]);

        $this->actingAs($this->adminUser);

        // Update non-status fields via JSON PATCH
        $updateResp = $this->patchJson(route('admin.interns.update', $intern->id), [
            'fullname'         => 'Original Name',
            'student_id'       => '22/PA/10001',
            'email'            => $user->email,
            'phone_number'     => '0899999999',
            'institution_name' => 'Updated University',
            'major'            => 'Original Major',
        ]);
        $updateResp->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'admin_intern_registration_updated',
            'actor_user_id' => $this->adminUser->id,
        ]);

        $log = ActivityLog::where('action', 'admin_intern_registration_updated')
            ->where('subject_id', $intern->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('0811111111', $log->before_data['phone_number']);
        $this->assertEquals('0899999999', $log->after_data['phone_number']);
    }

    /**
     * 8. Institution and contact lifecycle events are logged.
     */
    public function test_institution_and_contact_events_are_logged(): void
    {
        $this->actingAs($this->adminUser);

        $inst = Institution::create([
            'name'            => 'Institut Teknologi Bandung ' . substr($this->unique, 0, 6),
            'normalized_name' => Institution::normalizeName('Institut Teknologi Bandung ' . substr($this->unique, 0, 6)),
            'is_active'       => true,
        ]);

        ActivityLogger::log(
            action: 'institution_created',
            description: "Institusi {$inst->name} berhasil ditambahkan oleh Administrator.",
            subject: $inst
        );

        $contact = InstitutionContact::create([
            'institution_id'   => $inst->id,
            'name'             => 'Bambang Sudarsono',
            'normalized_name'  => InstitutionContact::normalizeName('Bambang Sudarsono'),
            'phone'            => '081234567899',
            'normalized_phone' => InstitutionContact::normalizePhone('081234567899'),
            'position'         => 'Kaprodi',
            'is_verified'      => false,
            'is_active'        => true,
        ]);

        ActivityLogger::log(
            action: 'institution_contact_created',
            description: "Kontak institusi {$contact->name} berhasil dibuat.",
            subject: $contact
        );

        $this->assertDatabaseHas('activity_logs', ['action' => 'institution_created']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'institution_contact_created']);
    }

    /**
     * 9. Follow-up cycle and attempt events are logged.
     */
    public function test_followup_cycle_and_attempt_events_are_logged(): void
    {
        $this->actingAs($this->adminUser);

        $inst = Institution::create([
            'name'            => 'Universitas Indonesia ' . substr($this->unique, 0, 6),
            'normalized_name' => Institution::normalizeName('Universitas Indonesia ' . substr($this->unique, 0, 6)),
            'is_active'       => true,
        ]);

        $contact = InstitutionContact::create([
            'institution_id'   => $inst->id,
            'name'             => 'Dr. Hendra ' . substr($this->unique, 0, 6),
            'normalized_name'  => InstitutionContact::normalizeName('Dr. Hendra ' . substr($this->unique, 0, 6)),
            'phone'            => '081333444555',
            'normalized_phone' => InstitutionContact::normalizePhone('081333444555'),
            'is_verified'      => true,
            'is_active'        => true,
        ]);

        $followUpService = app(FollowUpService::class);
        $cycle = $followUpService->createCycle($contact, 2026, $this->adminUser);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'followup_cycle_created',
        ]);

        // Claim lock
        $followUpService->claimLock($cycle, $this->adminUser);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'followup_cycle_locked',
        ]);

        // Record attempt with correct argument signature ($cycle, $data, $performer)
        $followUpService->recordAttempt($cycle, [
            'channel'          => 'whatsapp',
            'result'           => 'responded',
            'response_text'    => 'Respon positif',
            'notes'            => 'Follow-up berhasil disepakati.',
            'next_followup_at' => now()->addDays(7)->format('Y-m-d'),
        ], $this->adminUser);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'followup_attempt_recorded',
        ]);
    }

    /**
     * 10. MOU lifecycle events are logged.
     */
    public function test_mou_lifecycle_events_are_logged(): void
    {
        $this->actingAs($this->adminUser);

        $inst = Institution::create([
            'name'            => 'Universitas Brawijaya ' . substr($this->unique, 0, 6),
            'normalized_name' => Institution::normalizeName('Universitas Brawijaya ' . substr($this->unique, 0, 6)),
            'is_active'       => true,
        ]);

        $mou = Mou::create([
            'company_mou_number'           => 'MOU-2026-UB-' . substr($this->unique, 0, 6),
            'first_party_institution_name' => $inst->name,
            'first_party_signatory_name'   => 'Rektor Universitas Brawijaya',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'PT Sinergi Edukasi Nusantara',
            'second_party_signatory_name'  => 'Direktur Utama',
            'second_party_signatory_title' => 'Direktur',
            'agreement_date'               => now(),
            'institution_id'               => $inst->id,
            'status'                       => Mou::STATUS_DRAFT,
            'valid_start'                  => now(),
            'valid_end'                    => now()->addYears(3),
        ]);

        ActivityLogger::log(
            action: 'mou_generated',
            description: "Draft dokumen MOU {$mou->company_mou_number} berhasil digenerate.",
            subject: $mou
        );

        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'mou_generated',
            'actor_user_id' => $this->adminUser->id,
        ]);
    }

    /**
     * 11. Document generation & download events are logged.
     */
    public function test_document_generation_and_download_events_are_logged(): void
    {
        $user = User::create([
            'role'      => 'pemagang',
            'name'      => 'Citra Permata ' . substr($this->unique, 0, 6),
            'email'     => 'citra_' . $this->unique . '@example.com',
            'password'  => bcrypt('password123'),
            'is_active' => true,
        ]);

        $intern = $this->createDummyRegistration($user, [
            'internship_status' => IR::STATUS_ACCEPTED,
        ]);

        $this->actingAs($this->adminUser);

        ActivityLogger::log(
            action: 'document_generated_for_user',
            description: "Dokumen LOA berhasil dibuat untuk {$intern->name}.",
            subject: $intern,
            metadata: ['document_type' => 'loa', 'registration_id' => $intern->id]
        );

        ActivityLogger::log(
            action: 'document_downloaded_for_user',
            description: "Dokumen LOA untuk {$intern->name} diunduh oleh {$this->adminUser->name}.",
            subject: $intern,
            metadata: ['document_type' => 'loa', 'registration_id' => $intern->id]
        );

        $this->assertDatabaseHas('activity_logs', ['action' => 'document_generated_for_user']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'document_downloaded_for_user']);
    }

    /**
     * 12. Spreadsheet connection events and terminal sync failure events are logged.
     */
    public function test_spreadsheet_connection_and_terminal_sync_failure_events_are_logged(): void
    {
        $this->actingAs($this->adminUser);

        $connection = SpreadsheetConnection::where('connection_key', 'registration')->first();
        if (!$connection) {
            $connection = SpreadsheetConnection::create([
                'connection_key'  => 'registration',
                'display_name'    => 'Pendaftaran Magang',
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/test12345/edit',
                'script_endpoint' => 'https://script.google.com/macros/s/test12345/exec',
                'is_enabled'      => true,
                'auth_mode'       => 'hmac',
            ]);
        } else {
            $connection->update([
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/test12345/edit',
                'script_endpoint' => 'https://script.google.com/macros/s/test12345/exec',
                'is_enabled'      => true,
            ]);
        }

        ActivityLogger::log(
            action: 'spreadsheet_connection_updated',
            description: "Konfigurasi koneksi spreadsheet {$connection->connection_key} diperbarui oleh Administrator.",
            subject: $connection
        );

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'spreadsheet_connection_updated',
        ]);

        // Test terminal sync failure logging
        $user = User::create([
            'role'      => 'pemagang',
            'name'      => 'Failing Sync User ' . substr($this->unique, 0, 6),
            'email'     => 'fail_' . $this->unique . '@example.com',
            'password'  => bcrypt('password123'),
            'is_active' => true,
        ]);

        $intern = $this->createDummyRegistration($user, [
            'name'     => 'Failing Sync Intern',
            'fullname' => 'Failing Sync Intern',
        ]);

        $rowSync = SpreadsheetRowSync::create([
            'syncable_type'             => IR::class,
            'syncable_id'               => $intern->id,
            'spreadsheet_connection_id' => $connection->id,
            'target_sheet_tab'          => 'Pendaftaran 2026',
            'external_sync_id'          => 'REG-' . $intern->id,
            'sync_status'               => SpreadsheetRowSync::STATUS_PENDING,
            'attempt_count'             => 4,
            'max_attempts'              => 5,
        ]);

        // Mock transport that always fails
        $mockTransport = new class implements SpreadsheetTransportInterface {
            public function sendRowUpsert(SpreadsheetConnection $connection, string $tabName, string $syncId, array $payload): SpreadsheetTransportResponse
            {
                return new SpreadsheetTransportResponse(
                    success: false,
                    isRetryable: false,
                    errorMessage: 'Fatal remote error HTTP 500'
                );
            }

            public function testConnection(SpreadsheetConnection $connection): bool
            {
                return true;
            }
        };

        $syncEngine = app(SpreadsheetSyncEngineService::class);
        $syncEngine->processSyncRecord($rowSync, $mockTransport);

        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $rowSync->sync_status);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'spreadsheet_sync_failed_terminal',
        ]);

        $failLog = ActivityLog::where('action', 'spreadsheet_sync_failed_terminal')
            ->where('subject_id', $rowSync->id)
            ->first();

        $this->assertNotNull($failLog);
        $this->assertEquals('registration', $failLog->metadata['connection_key']);
        $this->assertEquals('Pendaftaran 2026', $failLog->metadata['target_sheet_tab']);
        $this->assertStringContainsString('Fatal remote error', $failLog->metadata['error_summary']);
    }
}
