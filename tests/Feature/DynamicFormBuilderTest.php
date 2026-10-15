<?php

namespace Tests\Feature;

use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\RegistrationFormSnapshot;
use App\Models\User;
use App\Services\FormBuilderService;
use App\Services\RegistrationLifecycleService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class DynamicFormBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected FormBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FormBuilderService::class);
    }

    /**
     * Helper to create an admin user.
     */
    protected function createAdmin(): User
    {
        return User::create([
            'name'      => 'Admin User ' . uniqid(),
            'email'     => 'admin_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);
    }

    /**
     * Helper to create a pemagang user.
     */
    protected function createPemagang(): User
    {
        return User::create([
            'name'      => 'Pemagang User ' . uniqid(),
            'email'     => 'pemagang_' . uniqid() . '@example.com',
            'password'  => bcrypt('password123'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);
    }

    protected function createRegistration(string $internshipStatus = 'waiting', ?string $followupStatus = null, ?User $user = null): InternshipRegistration
    {
        $u = $user ?? $this->createPemagang();
        $institution = \App\Models\Institution::firstOrCreate(
            ['name' => 'Universitas Indonesia'],
            ['type' => 'universitas']
        );
        $faculty = \App\Models\Faculty::firstOrCreate(
            ['institution_id' => $institution->id, 'name' => 'Fakultas Teknik']
        );
        $prodi = \App\Models\StudyProgram::firstOrCreate(
            ['faculty_id' => $faculty->id, 'name' => 'Teknik Informatika']
        );
        $city = \App\Models\City::firstOrCreate(['name' => 'Jakarta']);

        return InternshipRegistration::create([
            'user_id'                  => $u->id,
            'fullname'                 => 'Peserta Test ' . uniqid(),
            'gender'                   => 'male',
            'phone_number'             => '081234567890',
            'institution_id'           => $institution->id,
            'faculty_id'               => $faculty->id,
            'study_program_id'         => $prodi->id,
            'city_id'                  => $city->id,
            'internship_type'          => 'campus',
            'internship_arrangement'   => 'onsite',
            'current_status'           => 'Student',
            'wfo_readiness'            => 'ready',
            'schedule_shift_readiness' => 'ready',
            'has_laptop'               => true,
            'start_date'               => '2026-03-01',
            'planned_duration'         => '3 bulan',
            'end_date'                 => '2026-06-01',
            'internship_status'        => $internshipStatus,
            'followup_status'          => $followupStatus,
        ]);
    }

    /**
     * 1. Test Admin-Only CRUD for draft fields.
     */
    public function test_admin_only_crud_for_draft_fields(): void
    {
        $admin = $this->createAdmin();
        $pemagang = $this->createPemagang();

        // Guest access is redirected to user.login
        $resGuest = $this->get(route('admin.form-settings.fields'));
        $resGuest->assertRedirect(route('user.login'));

        // Pemagang web access is redirected to user.dashboard
        $resPemagang = $this->actingAs($pemagang)->get(route('admin.form-settings.fields'));
        $resPemagang->assertRedirect(route('user.dashboard'));

        // Pemagang JSON request is forbidden (403)
        $resPemagangJson = $this->actingAs($pemagang)->getJson(route('admin.form-settings.fields'));
        $resPemagangJson->assertForbidden();

        // Admin access succeeds
        $resAdmin = $this->actingAs($admin)->get(route('admin.form-settings.fields'));
        $resAdmin->assertOk();

        // Admin can add a field to draft via controller
        $resStore = $this->actingAs($admin)->postJson(route('admin.form-settings.fields.store'), [
            'label'       => 'Link Portofolio Dribbble',
            'field_type'  => 'url',
            'is_required' => 1,
            'column_span' => 2,
        ]);
        $resStore->assertOk();
        $this->assertTrue($resStore->json('ok'));

        $this->assertDatabaseHas('form_fields', [
            'label'      => 'Link Portofolio Dribbble',
            'field_type' => 'url',
        ]);
    }

    /**
     * 2. Test draft creation and published immutability.
     */
    public function test_published_schema_is_immutable(): void
    {
        $admin = $this->createAdmin();
        $draft = $this->service->getOrCreateDraft(2026, $admin);

        $field = $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_portfolio_url',
            'field_type'  => 'url',
            'label'       => 'Portofolio Online',
            'is_required' => true,
        ]);

        $published = $this->service->publishVersion($draft, $admin);
        $this->assertTrue($published->isPublished());
        $this->assertTrue($published->is_active);
        $this->assertNotNull($published->schema_hash);

        // Modifying a published schema directly must fail with DomainException
        $this->expectException(DomainException::class);
        $this->service->addFieldToDraft($published, [
            'field_key'   => 'custom_extra',
            'field_type'  => 'text',
            'label'       => 'Extra Info',
        ]);
    }

    /**
     * 3. Test atomic publish and automatic cloning of new working draft.
     */
    public function test_atomic_publish_and_working_draft_clone(): void
    {
        $admin = $this->createAdmin();
        $draft = $this->service->getOrCreateDraft(2026, $admin);

        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_github_link',
            'field_type'  => 'url',
            'label'       => 'GitHub Repository',
        ]);

        $published = $this->service->publishVersion($draft, $admin);
        $this->assertEquals('v2026.1', $published->version_code);
        $this->assertTrue($published->is_active);

        // A new draft v2026.2-draft must automatically exist and clone the active field
        $newDraft = $this->service->getOrCreateDraft(2026, $admin);
        $this->assertEquals('v2026.2-draft', $newDraft->version_code);
        $this->assertTrue($newDraft->isDraft());
        $this->assertFalse($newDraft->is_active);

        $this->assertDatabaseHas('form_fields', [
            'form_schema_id' => $newDraft->id,
            'field_key'      => 'custom_github_link',
        ]);
    }

    /**
     * 4. Test permanent field_key lifetime identity: incompatible mutations are rejected.
     */
    public function test_field_key_lifetime_identity_rejects_incompatible_mutations(): void
    {
        $admin = $this->createAdmin();
        $draft = $this->service->getOrCreateDraft(2026, $admin);

        $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_device_type',
            'field_type'  => 'select',
            'label'       => 'Tipe Perangkat',
            'options'     => [
                ['label' => 'MacBook', 'value' => 'macbook'],
                ['label' => 'Windows Laptop', 'value' => 'windows'],
            ],
        ]);

        // Publish version 1
        $this->service->publishVersion($draft, $admin);

        // The field_key is now permanently registered and locked in FormFieldRegistry
        $registry = FormFieldRegistry::where('field_key', 'custom_device_type')->first();
        $this->assertNotNull($registry);
        $this->assertTrue($registry->is_locked);
        $this->assertEquals('select', $registry->field_type);

        // In new draft v2, attempting to add or change custom_device_type to 'file' must throw InvalidArgumentException
        $newDraft = $this->service->getOrCreateDraft(2026, $admin);
        $fieldInDraft = $newDraft->fields()->where('field_key', 'custom_device_type')->first();

        $this->expectException(InvalidArgumentException::class);
        $this->service->updateFieldInDraft($fieldInDraft, [
            'field_type' => 'file',
        ]);
    }

    /**
     * 5. Test no hard-delete for published or answered fields; archiving works cleanly.
     */
    public function test_no_hard_delete_for_historical_fields_and_safe_archiving(): void
    {
        $admin = $this->createAdmin();
        $draft = $this->service->getOrCreateDraft(2026, $admin);

        $field = $this->service->addFieldToDraft($draft, [
            'field_key'   => 'custom_linkedin',
            'field_type'  => 'url',
            'label'       => 'LinkedIn URL',
        ]);

        $published = $this->service->publishVersion($draft, $admin);

        // Attempting to physically delete a published field throws DomainException
        try {
            $this->service->deleteFieldFromDraft($field);
            $this->fail("Expected DomainException when deleting published field");
        } catch (DomainException $e) {
            $this->assertStringContainsString('dilarang dihapus fisik', $e->getMessage());
        }

        // Attempting to archive directly on a published schema throws DomainException
        try {
            $this->service->archiveField($field);
            $this->fail("Expected DomainException when archiving field directly on published schema");
        } catch (DomainException $e) {
            $this->assertStringContainsString('dilarang diarsipkan langsung', $e->getMessage());
        }

        // Archiving the cloned field on the next working draft succeeds
        $nextDraft = $this->service->getOrCreateDraft(2026, $admin);
        $draftField = $nextDraft->fields()->where('field_key', 'custom_linkedin')->first();
        $this->assertNotNull($draftField);
        $this->service->archiveField($draftField);
        $draftField->refresh();
        $this->assertTrue($draftField->is_archived);
        $this->assertFalse($draftField->is_active);
    }

    /**
     * 6. Test submission version validation with configurable TTL grace period.
     */
    public function test_form_version_validation_and_grace_period_policy(): void
    {
        $admin = $this->createAdmin();
        $draft1 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft1, [
            'field_key'  => 'custom_hobby',
            'field_type' => 'text',
            'label'      => 'Hobi',
        ]);
        $published1 = $this->service->publishVersion($draft1, $admin); // v2026.1 published

        // Publish version 2
        $draft2 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2, [
            'field_key'   => 'custom_driver_license',
            'field_type'  => 'text',
            'label'       => 'SIM',
            'is_required' => true,
        ]);
        $published2 = $this->service->publishVersion($draft2, $admin); // v2026.2 published

        // Set configurable grace period to 30 minutes
        $this->service->setGracePeriodMinutes(30);

        // A user submitting under v2026.1 within 30 minutes is ACCEPTED under grace period
        $resGrace = $this->service->validateSubmissionVersion('v2026.1', ['custom_hobby' => 'Membaca']);
        $this->assertTrue($resGrace['is_valid']);
        $this->assertTrue($resGrace['is_grace_period']);

        // Set grace period to 0 minutes (immediate expiry)
        $this->service->setGracePeriodMinutes(0);
        // Simulate expired check
        $resExpired = $this->service->validateSubmissionVersion('v2026.1', ['custom_hobby' => 'Membaca']);
        $this->assertFalse($resExpired['is_valid']);
        $this->assertEquals('VERSION_EXPIRED', $resExpired['reason']);
        $this->assertEquals(['custom_hobby' => 'Membaca'], $resExpired['preserved_inputs']);
    }

    /**
     * 7. Test snapshot immutability and answer storage uniqueness with non-PII audit logging.
     */
    public function test_snapshot_immutability_and_answer_storage_with_non_pii_audit(): void
    {
        $admin = $this->createAdmin();
        $draft = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft, [
            'field_key'  => 'custom_laptop_spec',
            'field_type' => 'text',
            'label'      => 'Spesifikasi Laptop',
        ]);
        $published = $this->service->publishVersion($draft, $admin);

        $reg = $this->createRegistration(InternshipRegistration::STATUS_WAITING);

        // Freeze snapshot
        $snapshot = $this->service->freezeSnapshot($reg, $published);
        $this->assertNotNull($snapshot);
        $this->assertEquals('v2026.1', $snapshot->form_schema_version);

        // Re-freezing returns the exact same snapshot
        $snapshot2 = $this->service->freezeSnapshot($reg, $published);
        $this->assertEquals($snapshot->id, $snapshot2->id);

        // Save custom answers
        $this->service->saveCustomAnswers($reg, [
            'custom_laptop_spec' => 'Core i7 16GB RAM',
        ], $admin);

        $this->assertDatabaseHas('registration_custom_answers', [
            'registration_id' => $reg->id,
            'field_key'       => 'custom_laptop_spec',
            'answer_value'    => 'Core i7 16GB RAM',
        ]);

        // Updating answer performs idempotent upsert (exactly 1 record per registration & key)
        $this->service->saveCustomAnswers($reg, [
            'custom_laptop_spec' => 'Core i7 32GB RAM Upgrade',
        ], $admin);

        $this->assertEquals(1, RegistrationCustomAnswer::where('registration_id', $reg->id)->where('field_key', 'custom_laptop_spec')->count());
        $this->assertEquals('Core i7 32GB RAM Upgrade', RegistrationCustomAnswer::where('registration_id', $reg->id)->first()->answer_value);

        // Verify audit log exists and does NOT contain private answer text
        $log = \App\Models\ActivityLog::where('action', 'registration_custom_answers_updated')->latest('id')->first();
        $this->assertNotNull($log);
        $meta = (array) $log->metadata;
        $this->assertArrayHasKey('updated_keys', $meta);
        $this->assertStringNotContainsString('Core i7 32GB RAM Upgrade', json_encode($meta));
    }

    /**
     * 8. Test S17.18C Prerequisite Priority Correction:
     * Completed intern transitioning to exited preserves follow-up status #7, NOT #4!
     */
    public function test_prerequisite_priority_correction_completed_to_exited_preserves_status_7(): void
    {
        $lifecycle = app(RegistrationLifecycleService::class);

        $reg = $this->createRegistration(
            InternshipRegistration::STATUS_ACCEPTED,
            InternshipRegistration::FOLLOWUP_NOT_FOLLOWED_UP
        );

        // 1. accepted -> active
        $lifecycle->transition($reg, InternshipRegistration::STATUS_ACTIVE, isAdmin: true, reason: 'Mulai');
        $this->assertEquals(InternshipRegistration::FOLLOWUP_INTERNSHIP_STARTED, $reg->fresh()->followup_status);

        // 2. active -> completed
        $lifecycle->transition($reg, InternshipRegistration::STATUS_COMPLETED, isAdmin: true, reason: 'Selesai Magang');
        $this->assertEquals(InternshipRegistration::FOLLOWUP_INTERNSHIP_COMPLETED, $reg->fresh()->followup_status);

        // 3. completed -> exited (e.g. administrative cleanup or post-completion exit)
        // Must preserve follow-up status #7 (internship_completed), NEVER demote to #4!
        $lifecycle->transition($reg, InternshipRegistration::STATUS_EXITED, isAdmin: true, reason: 'Koreksi administratif keluar');
        $fresh = $reg->fresh();
        $this->assertEquals(InternshipRegistration::STATUS_EXITED, $fresh->internship_status);
        $this->assertEquals(InternshipRegistration::FOLLOWUP_INTERNSHIP_COMPLETED, $fresh->followup_status);
        $this->assertEquals('Sudah selesai magang', $fresh->current_condition_label);
    }

    /**
     * 9. Test N+1 Query Growth is strictly O(1) across dataset sizes N=1, N=5, N=20.
     */
    public function test_n_plus_one_query_growth_is_o_one_constant_across_scales(): void
    {
        $admin = $this->createAdmin();

        // Create 20 sample registrations
        $registrations = [];
        for ($i = 1; $i <= 20; $i++) {
            $registrations[] = $this->createRegistration(
                InternshipRegistration::STATUS_ACCEPTED,
                InternshipRegistration::FOLLOWUP_NOT_FOLLOWED_UP
            );
        }

        $controller = app(\App\Http\Controllers\Admin\InternApiController::class);

        // Test scale N = 1
        DB::enableQueryLog();
        DB::flushQueryLog();
        $req1 = Request::create('/api/admin/interns', 'GET', ['per_page' => 1]);
        $this->actingAs($admin);
        $controller->index($req1);
        $queriesN1 = count(DB::getQueryLog());

        // Test scale N = 5
        DB::flushQueryLog();
        $req5 = Request::create('/api/admin/interns', 'GET', ['per_page' => 5]);
        $controller->index($req5);
        $queriesN5 = count(DB::getQueryLog());

        // Test scale N = 20
        DB::flushQueryLog();
        $req20 = Request::create('/api/admin/interns', 'GET', ['per_page' => 20]);
        $controller->index($req20);
        $queriesN20 = count(DB::getQueryLog());

        // The query count must be constant O(1), completely independent of N!
        $this->assertEquals($queriesN1, $queriesN5, "Query count for N=5 ({$queriesN5}) must match N=1 ({$queriesN1})");
        $this->assertEquals($queriesN1, $queriesN20, "Query count for N=20 ({$queriesN20}) must match N=1 ({$queriesN1})");

        // Verify serializing collection does not trigger redundant queries due to removal from $appends
        DB::flushQueryLog();
        $models = InternshipRegistration::take(5)->get();
        $queryCountBeforeSerialize = count(DB::getQueryLog());
        $models->toArray();
        $queriesDuringSerialize = count(DB::getQueryLog()) - $queryCountBeforeSerialize;
        $this->assertEquals(0, $queriesDuringSerialize, "Model collection serialization must trigger 0 extra queries!");
    }

    /**
     * 10. Multi-generation grace period: v1 cutoff is unaffected by subsequent v3/v4 publication.
     */
    public function test_multigeneration_grace_period_v1_cutoff_unaffected_by_v3_publication(): void
    {
        $admin = $this->createAdmin();

        // 1. Publikasikan v1 pada 1 Januari 2026
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 1, 1, 10, 0, 0));
        $draft1 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft1, [
            'field_key'   => 'custom_field_v1',
            'field_type'  => 'text',
            'label'       => 'Field V1',
            'is_required' => false,
        ]);
        $v1 = $this->service->publishVersion($draft1, $admin);
        $this->assertEquals('v2026.1', $v1->version_code);

        // 2. Publikasikan v2 pada 10 Januari 2026 -> v1 archived
        // Grace period v1 = 7 hari (cutoff = 17 Jan 10:00:00)
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 1, 10, 10, 0, 0));
        $draft2 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft2, [
            'field_key'   => 'custom_field_v2',
            'field_type'  => 'text',
            'label'       => 'Field V2',
            'is_required' => false,
        ]);
        $v2 = $this->service->publishVersion($draft2, $admin);
        $this->assertEquals('v2026.2', $v2->version_code);
        $this->assertTrue($v1->fresh()->isArchived());

        // 3. Kasus A: Pada 12 Januari 2026 (masih dalam grace period v1), v3 dipublikasikan!
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 1, 12, 12, 0, 0));
        $draft3 = $this->service->getOrCreateDraft(2026, $admin);
        $v3 = $this->service->publishVersion($draft3, $admin);
        $this->assertEquals('v2026.3', $v3->version_code);

        // Validasi v1 pada 12 Jan: Harus valid karena cutoff terikat pada v2 (17 Jan), bukan v3!
        $checkA = $this->service->validateSubmissionVersion('v2026.1', ['custom_field_v1' => 'jawaban']);
        $this->assertTrue($checkA['is_valid']);
        $this->assertTrue($checkA['is_grace_period']);

        // 4. Kasus B: Pada 18 Januari 2026 (setelah grace period v1 habis, cutoff 17 Jan terlewati)
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 1, 18, 10, 0, 0));
        // Validasi v1 pada 18 Jan harus EXPIRED
        $checkExpired = $this->service->validateSubmissionVersion('v2026.1', ['custom_field_v1' => 'jawaban']);
        $this->assertFalse($checkExpired['is_valid']);
        $this->assertEquals('VERSION_EXPIRED', $checkExpired['reason']);

        // 5. Kasus C: Admin menerbitkan v4 pada 20 Januari 2026
        // Publikasi v4 TIDAK BOLEH membangkitkan v1 yang sudah kedaluwarsa!
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 1, 20, 10, 0, 0));
        $draft4 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->publishVersion($draft4, $admin);

        $checkStillExpired = $this->service->validateSubmissionVersion('v2026.1', ['custom_field_v1' => 'jawaban']);
        $this->assertFalse($checkStillExpired['is_valid'], 'v1 yang sudah kedaluwarsa tidak boleh bangkit kembali akibat publikasi v4!');
        $this->assertEquals('VERSION_EXPIRED', $checkStillExpired['reason']);

        \Illuminate\Support\Carbon::setTestNow();
    }

    /**
     * 11. FormFieldRegistry preserves first_published_version across v1 -> v2 -> v3.
     */
    public function test_field_registry_preserves_first_published_version_across_v1_v2_v3(): void
    {
        $admin = $this->createAdmin();

        // 1. Publikasikan v1 dengan field 'custom_skill'
        $draft1 = $this->service->getOrCreateDraft(2026, $admin);
        $this->service->addFieldToDraft($draft1, [
            'field_key'   => 'custom_skill',
            'field_type'  => 'text',
            'label'       => 'Keahlian Khusus',
            'is_required' => false,
        ]);
        $v1 = $this->service->publishVersion($draft1, $admin);
        $this->assertEquals('v2026.1', $v1->version_code);

        $reg1 = FormFieldRegistry::where('field_key', 'custom_skill')->first();
        $this->assertNotNull($reg1);
        $this->assertEquals('v2026.1', $reg1->first_published_version);

        // 2. Publikasikan v2 (field dipertahankan)
        $draft2 = $this->service->getOrCreateDraft(2026, $admin);
        $v2 = $this->service->publishVersion($draft2, $admin);
        $this->assertEquals('v2026.2', $v2->version_code);

        $reg2 = FormFieldRegistry::where('field_key', 'custom_skill')->first();
        $this->assertEquals('v2026.1', $reg2->first_published_version, 'first_published_version tidak boleh tertimpa v2!');

        // 3. Publikasikan v3 (field dipertahankan)
        $draft3 = $this->service->getOrCreateDraft(2026, $admin);
        $v3 = $this->service->publishVersion($draft3, $admin);
        $this->assertEquals('v2026.3', $v3->version_code);

        $reg3 = FormFieldRegistry::where('field_key', 'custom_skill')->first();
        $this->assertEquals('v2026.1', $reg3->first_published_version, 'first_published_version tidak boleh tertimpa v3!');

        // 4. Coba manipulasi langsung first_published_version pada registry terkunci -> throws DomainException
        $this->expectException(DomainException::class);
        $reg3->update(['first_published_version' => 'v2026.99']);
    }

    /**
     * 12. Custom field server validation matrix across all supported types.
     */
    public function test_custom_field_server_validation_matrix_across_all_supported_types(): void
    {
        $admin = $this->createAdmin();
        $draft = $this->service->getOrCreateDraft(2026, $admin);

        $this->service->addFieldToDraft($draft, ['field_key' => 'cf_date', 'field_type' => 'date', 'label' => 'Tanggal Tes', 'is_required' => true]);
        $this->service->addFieldToDraft($draft, ['field_key' => 'cf_url', 'field_type' => 'url', 'label' => 'Portofolio URL', 'is_required' => true]);
        $this->service->addFieldToDraft($draft, ['field_key' => 'cf_num', 'field_type' => 'number', 'label' => 'Skor', 'is_required' => true]);
        $this->service->addFieldToDraft($draft, ['field_key' => 'cf_sel', 'field_type' => 'select', 'label' => 'Shift', 'is_required' => true, 'options' => [['value' => 'pagi', 'label' => 'Pagi'], ['value' => 'siang', 'label' => 'Siang']]]);
        $this->service->addFieldToDraft($draft, ['field_key' => 'cf_file', 'field_type' => 'file', 'label' => 'Berkas Pendukung', 'is_required' => false]);

        $schema = $this->service->publishVersion($draft, $admin);

        // A. Valid inputs
        $validInputs = [
            'cf_date' => '2026-05-15',
            'cf_url'  => 'https://github.com/internship',
            'cf_num'  => '95',
            'cf_sel'  => 'pagi',
        ];
        $resValid = $this->service->validateCustomAnswers($schema, $validInputs);
        $this->assertTrue($resValid['is_valid'], 'Expected valid inputs to pass: ' . json_encode($resValid['errors']));

        // B. Invalid date (2026-02-31)
        $resInvalidDate = $this->service->validateCustomAnswers($schema, array_merge($validInputs, ['cf_date' => '2026-02-31']));
        $this->assertFalse($resInvalidDate['is_valid']);
        $this->assertArrayHasKey('cf_date', $resInvalidDate['errors']);

        // C. Invalid URL (ftp:// or non-url)
        $resInvalidUrl = $this->service->validateCustomAnswers($schema, array_merge($validInputs, ['cf_url' => 'ftp://invalidscheme.com']));
        $this->assertFalse($resInvalidUrl['is_valid']);
        $this->assertArrayHasKey('cf_url', $resInvalidUrl['errors']);

        // D. Invalid number ('bukan_angka')
        $resInvalidNum = $this->service->validateCustomAnswers($schema, array_merge($validInputs, ['cf_num' => 'bukan_angka']));
        $this->assertFalse($resInvalidNum['is_valid']);
        $this->assertArrayHasKey('cf_num', $resInvalidNum['errors']);

        // E. Invalid select (array passed or option not in allowed list)
        $resInvalidSel = $this->service->validateCustomAnswers($schema, array_merge($validInputs, ['cf_sel' => 'malam']));
        $this->assertFalse($resInvalidSel['is_valid']);
        $this->assertArrayHasKey('cf_sel', $resInvalidSel['errors']);

        // F. Array passed to select
        $resArraySel = $this->service->validateCustomAnswers($schema, array_merge($validInputs, ['cf_sel' => ['pagi']]));
        $this->assertFalse($resArraySel['is_valid']);
        $this->assertArrayHasKey('cf_sel', $resArraySel['errors']);

        // G. Unknown custom field key discard (Design Decision: Whitelist filtering)
        $resUnknown = $this->service->validateCustomAnswers($schema, array_merge($validInputs, ['cf_unknown_evil' => 'injected_data']));
        $this->assertTrue($resUnknown['is_valid']);
        $this->assertArrayNotHasKey('cf_unknown_evil', $resUnknown['cleaned']);
    }

    /**
     * 13. Admin form builder supports year 2026 and 2027 with strict isolation and 400 on invalid year.
     */
    public function test_admin_form_builder_year_support_and_isolation_2026_vs_2027(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin);

        // A. Kelola tahun 2026
        $res2026 = $this->get('/admin/form-settings/fields?year=2026');
        $res2026->assertOk();
        $res2026->assertSee('Tahun 2026');

        // B. Kelola tahun 2027
        $res2027 = $this->get('/admin/form-settings/fields?year=2027');
        $res2027->assertOk();
        $res2027->assertSee('Tahun 2027');

        // C. Tahun tidak valid -> 400 Bad Request
        $resInvalid = $this->get('/admin/form-settings/fields?year=1999');
        $resInvalid->assertStatus(400);

        $resString = $this->get('/admin/form-settings/fields?year=abc');
        $resString->assertStatus(400);
    }

    /**
     * 14. Admin form builder returns graceful unavailable view / 503 JSON when tables are unmigrated.
     * S17.18G.4R Fix 4: Pengujian non-destruktif tanpa memanipulasi DDL atau me-drop tabel pada shared testing database.
     */
    public function test_admin_form_builder_fallback_when_tables_unmigrated(): void
    {
        $admin = $this->createAdmin();
        $this->actingAs($admin);

        try {
            // Simulasi non-destruktif: tabel belum siap tanpa merusak skema nyata
            config(['internship.simulate_unmigrated_form_schema' => true]);

            // GET web page harus merender view unavailable (bukan HTTP 500 QueryException)
            $res = $this->get('/admin/form-settings/fields');
            $res->assertOk();
            $res->assertSee('Layanan Pengaturan Formulir Belum Tersedia');

            // AJAX / JSON request harus mengembalikan HTTP 503
            $resAjax = $this->getJson('/admin/form-settings/fields');
            $resAjax->assertStatus(503);
            $resAjax->assertJsonFragment(['ok' => false]);
        } finally {
            // Kembalikan konfigurasi simulasi ke default
            config(['internship.simulate_unmigrated_form_schema' => false]);
        }
    }
}

