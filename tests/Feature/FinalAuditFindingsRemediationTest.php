<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Division;
use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\RegistrationAttachment;
use App\Models\RegistrationCustomAnswer;
use App\Models\RegistrationFormSnapshot;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSidecarSync;
use App\Models\User;
use App\Services\FormBuilderService;
use App\Services\MigrationRollbackPreflightService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinalAuditFindingsRemediationTest extends TestCase
{
    use DatabaseTransactions;

    protected FormBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FormBuilderService::class);
    }

    private function createAdmin(): User
    {
        return User::create([
            'name'      => 'Admin User ' . uniqid(),
            'email'     => 'admin_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);
    }

    private function createPemagang(): User
    {
        return User::create([
            'name'      => 'Pemagang User ' . uniqid(),
            'email'     => 'pemagang_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);
    }

    private function createRegistration(User $user): InternshipRegistration
    {
        $city = City::firstOrCreate(['name' => 'Yogyakarta'], ['province' => 'DIY']);
        $inst = Institution::firstOrCreate(['name' => 'UGM ' . uniqid()], ['type' => 'universitas']);
        $faculty = \App\Models\Faculty::firstOrCreate(
            ['institution_id' => $inst->id, 'name' => 'Fakultas Teknik']
        );
        $prodi = \App\Models\StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Teknik Informatika']
        );
        $div = Division::firstOrCreate(['name' => 'Teknologi Informasi'], ['code' => 'TI', 'slug' => 'teknologi-informasi']);

        return InternshipRegistration::create([
            'user_id'             => $user->id,
            'fullname'            => $user->name,
            'email'               => $user->email,
            'phone_number'        => '081234567890',
            'gender'              => 'Laki-laki',
            'city_id'             => $city->id,
            'institution_id'      => $inst->id,
            'faculty_id'          => $faculty->id,
            'study_program_id'    => $prodi->id,
            'division_id'         => $div->id,
            'internship_reason'   => 'Riset',
            'internship_interest' => 'Programmer',
            'internship_type'     => 'campus',
            'current_status'      => 'Student',
            'wfo_readiness'       => 'ready',
            'schedule_shift_readiness' => 'ready',
            'has_laptop'          => true,
            'planned_duration'    => '3 bulan',
            'internship_status'   => InternshipRegistration::STATUS_ACCEPTED,
            'followup_status'     => InternshipRegistration::FOLLOWUP_MOVED_TO_PARTICIPANTS,
        ]);
    }

    // =========================================================================
    // FIX 1: MIGRATION ROLLBACK DATA SAFETY
    // =========================================================================

    public function test_migration_000004_down_is_blocked_when_snapshots_or_answers_exist(): void
    {
        $migration = require database_path('migrations/2026_10_13_000004_create_registration_form_snapshots_and_answers_tables.php');

        $user = $this->createPemagang();
        $reg = $this->createRegistration($user);

        // A. Blocked by custom answers
        RegistrationCustomAnswer::create([
            'registration_id' => $reg->id,
            'field_key'       => 'test_hazard_key',
            'answer_value'    => 'hazard_val',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ROLLBACK_ABORTED_DATA_HAZARD');
        $migration->down();
    }

    public function test_migration_000006_down_is_blocked_when_sidecar_syncs_exist(): void
    {
        $migration = require database_path('migrations/2026_10_13_000006_create_spreadsheet_sidecar_syncs_table.php');

        $user = $this->createPemagang();
        $reg = $this->createRegistration($user);
        $conn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => 'registration'],
            ['display_name' => 'Registration Sheet', 'spreadsheet_id' => 'sheet_test_id', 'is_active' => true]
        );

        SpreadsheetSidecarSync::create([
            'spreadsheet_connection_id'  => $conn->id,
            'internship_registration_id' => $reg->id,
            'external_sync_id'           => 'REG-' . $reg->id . '-test1234',
            'form_schema_version'        => 'v1_test',
            'sync_status'                => SpreadsheetSidecarSync::STATUS_SYNCED,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ROLLBACK_ABORTED_DATA_HAZARD');
        $migration->down();
    }

    public function test_migration_000002_down_is_blocked_when_schemas_or_registries_exist(): void
    {
        $migration = require database_path('migrations/2026_10_13_000002_create_form_schemas_and_registries_table.php');

        // Pastikan ada skema formulir
        FormSchema::firstOrCreate(
            ['version_code' => 'v1_test_hazard'],
            ['year' => 2026, 'title' => 'Test Hazard Schema', 'status' => FormSchema::STATUS_DRAFT]
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ROLLBACK_ABORTED_DATA_HAZARD');
        $migration->down();
    }

    public function test_preflight_service_detects_data_hazards_before_destructive_rollback(): void
    {
        $preflight = new MigrationRollbackPreflightService();
        $inspection = $preflight->inspectRollbackHazards();

        $this->assertIsArray($inspection);
        $this->assertArrayHasKey('can_safely_rollback', $inspection);
        $this->assertArrayHasKey('hazards', $inspection);
        $this->assertArrayHasKey('details', $inspection);

        // On populated database, preflight must identify hazards
        if (!$inspection['can_safely_rollback']) {
            $this->assertNotEmpty($inspection['hazards']);
            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('PREFLIGHT_ROLLBACK_BLOCKED');
            $preflight->assertSafeRollback();
        }
    }

    // =========================================================================
    // FIX 2: LEGACY DOCUMENT PRIVACY & PATH TRAVERSAL
    // =========================================================================

    public function test_document_download_rejects_path_traversal(): void
    {
        $user = $this->createPemagang();
        $reg = $this->createRegistration($user);

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio_pdf',
            'file_path'                  => '../../../../etc/passwd',
            'original_name'              => 'passwd.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
        ]);

        $response = $this->actingAs($user)->get("/registration/documents/{$attachment->id}");
        $response->assertStatus(400);
    }

    public function test_document_download_authorization_and_ownership(): void
    {
        Storage::fake('local');
        $owner = $this->createPemagang();
        $stranger = $this->createPemagang();
        $admin = $this->createAdmin();

        $reg = $this->createRegistration($owner);
        $filePath = 'private/registrations/dokumen_owner.pdf';
        Storage::disk('local')->put($filePath, 'konten rahasia dokumen');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio_pdf',
            'file_path'                  => $filePath,
            'original_name'              => 'dokumen_owner.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
        ]);

        // 1. Guest -> redirect to login (302) or 401 JSON
        $resGuest = $this->get("/registration/documents/{$attachment->id}");
        $resGuest->assertRedirect('/login');

        $resGuestJson = $this->getJson("/registration/documents/{$attachment->id}");
        $resGuestJson->assertStatus(401);

        // 2. Stranger (non-owner) -> 403
        $resStranger = $this->actingAs($stranger)->get("/registration/documents/{$attachment->id}");
        $resStranger->assertStatus(403);

        // 3. Owner -> 200 Download
        $resOwner = $this->actingAs($owner)->get("/registration/documents/{$attachment->id}");
        $resOwner->assertOk();

        // 4. Admin -> 200 Download
        $resAdmin = $this->actingAs($admin)->get("/registration/documents/{$attachment->id}");
        $resAdmin->assertOk();
    }

    public function test_migrate_legacy_documents_command_dry_run_simulation(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $owner = $this->createPemagang();
        $reg = $this->createRegistration($owner);

        $legacyPublicPath = 'registrations/legacy_test_file.pdf';
        Storage::disk('public')->put($legacyPublicPath, 'dummy legacy pdf content');

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'cv_ktp_portofolio_pdf',
            'file_path'                  => $legacyPublicPath,
            'original_name'              => 'legacy_test_file.pdf',
            'file_size'                  => 1024,
            'mime_type'                  => 'application/pdf',
        ]);

        // Jalankan dry-run command
        $this->artisan('registrations:migrate-legacy-documents', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN (Simulasi)')
            ->expectsOutputToContain($legacyPublicPath)
            ->assertExitCode(0);

        // File publik tetap ada dan database tidak diubah
        $this->assertTrue(Storage::disk('public')->exists($legacyPublicPath));
        $this->assertFalse(Storage::disk('local')->exists("private/registrations/legacy_test_file.pdf"));
        $this->assertEquals($legacyPublicPath, $attachment->fresh()->file_path);
    }

    // =========================================================================
    // FIX 3: FORM BUILDER SERVICE STATE ISOLATION
    // =========================================================================

    public function test_form_builder_service_is_transient_and_scoped_without_leaking(): void
    {
        $instanceA = app(FormBuilderService::class);

        // Scoped override auto-reset test
        $result = $instanceA->runWithGracePeriodOverride(45, function (FormBuilderService $svc) {
            $this->assertEquals(45, $svc->getEffectiveGracePeriodMinutes());
            return 'done';
        });

        $this->assertEquals('done', $result);
        // Setelah scope selesai, instanceA kembali ke konfigurasi default (tidak leak ke operasi berikutnya)
        $this->assertNotEquals(45, $instanceA->getEffectiveGracePeriodMinutes());

        // Simulasi siklus request / worker job baru: scoped instance di-flush
        $this->app->forgetScopedInstances();
        $instanceNextCycle = app(FormBuilderService::class);
        $this->assertNotSame($instanceA, $instanceNextCycle);
        $this->assertNotEquals(45, $instanceNextCycle->getEffectiveGracePeriodMinutes());
    }

    // =========================================================================
    // FIX 4: REPRODUCIBLE TEST DATABASE FALLBACK SIMULATION
    // =========================================================================

    public function test_schema_readiness_fallback_works_non_destructively(): void
    {
        $admin = $this->createAdmin();

        // Aktifkan simulasi via config
        config(['internship.simulate_unmigrated_form_schema' => true]);

        $res = $this->actingAs($admin)->get('/admin/form-settings/fields');
        $res->assertOk();
        $res->assertSee('Layanan Pengaturan Formulir Belum Tersedia');

        $resAjax = $this->actingAs($admin)->getJson('/admin/form-settings/fields');
        $resAjax->assertStatus(503);

        // Reset config
        config(['internship.simulate_unmigrated_form_schema' => false]);

        $resNormal = $this->actingAs($admin)->get('/admin/form-settings/fields');
        $resNormal->assertOk();
        $resNormal->assertDontSee('Layanan Pengaturan Formulir Belum Tersedia');
    }

    // =========================================================================
    // FIX 6: FORM CONTRACT VERIFICATION (WHITELIST DISCARD & YEAR 2028)
    // =========================================================================

    public function test_form_builder_year_2028_support_and_schema_isolation(): void
    {
        $admin = $this->createAdmin();

        $draft2028 = $this->service->getOrCreateDraft(2028, $admin);
        $this->assertEquals(2028, $draft2028->year);

        $res = $this->actingAs($admin)->get('/admin/form-settings/fields?year=2028');
        $res->assertOk();
        $res->assertSee('Tahun 2028');
    }

    public function test_whitelist_discard_preserves_required_validations_and_system_fields(): void
    {
        $admin = $this->createAdmin();
        $draft = $this->service->getOrCreateDraft(2026, $admin);

        $field = $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_mandatory_project',
            'field_type'  => 'text',
            'label'       => 'Judul Proyek Terakhir',
            'is_required' => true,
        ]);

        $published = $this->service->publishVersion($draft, $admin);

        // 1. Missing mandatory field fails validation
        $valFail = $this->service->validateCustomAnswers($published, [
            'unknown_spurious_input' => 'hacked',
        ]);
        $this->assertFalse($valFail['is_valid']);
        $this->assertArrayHasKey('custom_mandatory_project', $valFail['errors']);

        // 2. Valid input succeeds and unknown input is silently discarded
        $valSuccess = $this->service->validateCustomAnswers($published, [
            'custom_mandatory_project' => 'Aplikasi Magang SertIF',
            'unknown_spurious_input'   => 'discarded_value',
            'created_at'               => '2020-01-01', // Cannot mutate system field
        ]);
        $this->assertTrue($valSuccess['is_valid']);
        $this->assertEquals('Aplikasi Magang SertIF', $valSuccess['cleaned']['custom_mandatory_project']);
        $this->assertArrayNotHasKey('unknown_spurious_input', $valSuccess['cleaned']);
        $this->assertArrayNotHasKey('created_at', $valSuccess['cleaned']);
    }
}
