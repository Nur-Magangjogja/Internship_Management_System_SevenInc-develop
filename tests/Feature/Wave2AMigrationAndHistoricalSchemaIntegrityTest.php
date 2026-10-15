<?php

namespace Tests\Feature;

use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\InternshipRegistration;
use App\Models\RegistrationFormSnapshot;
use App\Models\User;
use App\Services\FormBuilderService;
use App\Services\MigrationRollbackPreflightService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Wave2AMigrationAndHistoricalSchemaIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name'      => 'Admin Wave2A ' . uniqid(),
            'email'     => 'admin_wave2a_' . uniqid() . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);
    }

    // =========================================================================
    // FIX 1 TESTS: MIGRATION 000005 DATA PRESERVATION
    // =========================================================================

    /**
     * 1. Rollback migration 000005 diblokir jika terdapat skema published.
     */
    public function test_migration_000005_down_blocked_when_published_schema_exists(): void
    {
        $schema = FormSchema::create([
            'version_code'      => 'v2026.1-published-' . uniqid(),
            'year'              => 2026,
            'title'             => 'Formulir Published',
            'status'            => FormSchema::STATUS_PUBLISHED,
            'grace_period_days' => 7,
            'is_active'         => true,
            'published_at'      => now(),
        ]);

        $migration = require database_path('migrations/2026_10_13_000005_add_grace_period_to_form_schemas_table.php');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ROLLBACK_ABORTED_DATA_HAZARD');

        try {
            $migration->down();
        } finally {
            // Kolom grace_period_days harus tetap ada dan tidak terhapus
            $this->assertTrue(Schema::hasColumn('form_schemas', 'grace_period_days'));
            $this->assertEquals(7, $schema->fresh()->grace_period_days);
        }
    }

    /**
     * 2. Rollback migration 000005 diblokir jika terdapat skema archived.
     */
    public function test_migration_000005_down_blocked_when_archived_schema_exists(): void
    {
        $schema = FormSchema::create([
            'version_code'      => 'v2026.1-archived-' . uniqid(),
            'year'              => 2026,
            'title'             => 'Formulir Archived',
            'status'            => FormSchema::STATUS_ARCHIVED,
            'grace_period_days' => 14,
            'is_active'         => false,
            'published_at'      => now()->subDays(30),
        ]);

        $migration = require database_path('migrations/2026_10_13_000005_add_grace_period_to_form_schemas_table.php');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ROLLBACK_ABORTED_DATA_HAZARD');

        try {
            $migration->down();
        } finally {
            $this->assertTrue(Schema::hasColumn('form_schemas', 'grace_period_days'));
        }
    }

    /**
     * 3. Rollback migration 000005 diblokir jika ada skema dengan nilai grace_period_days kustom.
     */
    public function test_migration_000005_down_blocked_when_custom_grace_period_saved_on_draft(): void
    {
        FormSchema::create([
            'version_code'      => 'v2026.1-draft-' . uniqid(),
            'year'              => 2026,
            'title'             => 'Formulir Draft Khusus',
            'status'            => FormSchema::STATUS_DRAFT,
            'grace_period_days' => 10,
            'is_active'         => false,
        ]);

        $migration = require database_path('migrations/2026_10_13_000005_add_grace_period_to_form_schemas_table.php');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ROLLBACK_ABORTED_DATA_HAZARD');

        try {
            $migration->down();
        } finally {
            $this->assertTrue(Schema::hasColumn('form_schemas', 'grace_period_days'));
        }
    }

    /**
     * 4. Forward migration 000005 up() bersifat idempotent dan aman dijalankan ulang.
     */
    public function test_migration_000005_up_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_10_13_000005_add_grace_period_to_form_schemas_table.php');

        // Menjalankan up saat kolom sudah ada tidak boleh melempar exception
        $migration->up();
        $this->assertTrue(Schema::hasColumn('form_schemas', 'grace_period_days'));
    }

    private function createHierarchy(): array
    {
        $unique = \Illuminate\Support\Str::random(6);
        $inst = \App\Models\Institution::create([
            'name'            => 'Universitas Wave2A ' . $unique,
            'normalized_name' => 'universitas wave2a ' . $unique,
            'is_active'       => true,
        ]);
        $fac = \App\Models\Faculty::create([
            'name'           => 'Fakultas Teknik ' . $unique,
            'institution_id' => $inst->id,
        ]);
        $prog = \App\Models\StudyProgram::create([
            'name'       => 'Informatika ' . $unique,
            'faculty_id' => $fac->id,
        ]);
        $city = \App\Models\City::create(['name' => 'Kota ' . $unique]);
        $div  = \App\Models\Division::create([
            'name'        => 'Divisi ' . $unique,
            'code'        => strtoupper(substr($unique, 0, 4)),
            'description' => 'Desc',
            'is_active'   => true,
        ]);

        return [$inst, $fac, $prog, $city, $div];
    }

    private function createRegistration(?User $user = null): InternshipRegistration
    {
        if (!$user) {
            $user = User::create([
                'name'      => 'Peserta ' . uniqid(),
                'email'     => 'peserta_' . uniqid() . '@example.com',
                'password'  => bcrypt('secret'),
                'role'      => 'pemagang',
                'is_banned' => false,
            ]);
        }

        [$inst, $fac, $prog, $city, $div] = $this->createHierarchy();

        return InternshipRegistration::create([
            'user_id'                  => $user->id,
            'fullname'                 => $user->name,
            'email'                    => $user->email,
            'student_id'               => 'NIM' . rand(1000, 9999),
            'institution_id'           => $inst->id,
            'institution_name'         => $inst->name,
            'faculty_id'               => $fac->id,
            'faculty'                  => $fac->name,
            'study_program_id'         => $prog->id,
            'study_program'            => $prog->name,
            'current_city_id'          => $city->id,
            'current_city'             => $city->name,
            'division_id'              => $div->id,
            'internship_type'          => 'online',
            'internship_arrangement'   => 'individual',
            'current_status'           => 'mahasiswa',
            'wfo_readiness'            => 'ready',
            'schedule_shift_readiness' => 'ready',
            'internship_status'        => InternshipRegistration::STATUS_ACCEPTED,
            'followup_status'          => InternshipRegistration::FOLLOWUP_NOT_FOLLOWED_UP,
        ]);
    }

    // =========================================================================
    // FIX 2 TESTS: BATCH ROLLBACK PREFLIGHT SERVICE
    // =========================================================================

    /**
     * 5. Batch preflight memblokir batch jika terdapat migration yang memuat data pada langkah berikutnya.
     */
    public function test_batch_preflight_blocks_batch_when_hazards_exist(): void
    {
        // Daftarkan batch 99 yang memuat migration 000006, 000005, dan 000004
        DB::table('migrations')->insert([
            ['migration' => '2026_10_13_000006_create_spreadsheet_sidecar_syncs_table', 'batch' => 99],
            ['migration' => '2026_10_13_000005_add_grace_period_to_form_schemas_table', 'batch' => 99],
            ['migration' => '2026_10_13_000004_create_registration_form_snapshots_and_answers_tables', 'batch' => 99],
        ]);

        // Masukkan snapshot pendaftaran historis (hazard untuk migration 000004)
        $reg = $this->createRegistration();

        RegistrationFormSnapshot::create([
            'registration_id'     => $reg->id,
            'form_schema_version' => 'v2026.1',
            'schema_hash'         => 'hash123',
            'schema_snapshot'     => ['fields' => []],
            'submitted_at'        => now(),
        ]);

        $service = new MigrationRollbackPreflightService();
        $result = $service->inspectBatchRollbackHazards(99);

        $this->assertFalse($result['is_safe']);
        $this->assertEquals(MigrationRollbackPreflightService::STATUS_BLOCKED, $result['status']);
        $this->assertNotEmpty($result['hazards']);
        $this->assertStringContainsString('MySQL mengeksekusi IMPLICIT COMMIT', $result['mysql_non_atomic_warning']);
    }

    /**
     * 6. Batch preflight menandai UNKNOWN secara fail-closed jika terdapat migration yang tidak ada dalam katalog risiko.
     */
    public function test_batch_preflight_flags_unknown_migration_as_unknown(): void
    {
        // Daftarkan migration fiktif ke ledger migrations pada batch 999
        DB::table('migrations')->insert([
            'migration' => '2026_10_99_999999_unknown_foreign_plugin_migration',
            'batch'     => 999,
        ]);

        $service = new MigrationRollbackPreflightService();
        $result = $service->inspectBatchRollbackHazards(999);

        $this->assertEquals(MigrationRollbackPreflightService::STATUS_UNKNOWN, $result['status']);
        $this->assertFalse($result['is_safe']);
        $this->assertContains('2026_10_99_999999_unknown_foreign_plugin_migration', $result['unknown_migrations']);
    }

    /**
     * 7. Batch preflight bersifat murni read-only dan menghasilkan data konsisten pada eksekusi berulang.
     */
    public function test_batch_preflight_is_purely_read_only_and_idempotent(): void
    {
        $service = new MigrationRollbackPreflightService();

        $firstRun  = $service->inspectBatchRollbackHazards();
        $secondRun = $service->inspectBatchRollbackHazards();

        $this->assertEquals($firstRun['status'], $secondRun['status']);
        $this->assertEquals($firstRun['hazards'], $secondRun['hazards']);
        $this->assertEquals($firstRun['unknown_migrations'], $secondRun['unknown_migrations']);
    }

    // =========================================================================
    // FIX 3 TESTS: FORMSCHEMA HISTORICAL IMMUTABILITY
    // =========================================================================

    /**
     * 8. Metadata historis pada skema published dilarang dimutasi.
     */
    public function test_published_schema_metadata_cannot_be_mutated(): void
    {
        $published = FormSchema::create([
            'version_code'      => 'v2026.1-pub-' . uniqid(),
            'year'              => 2026,
            'title'             => 'Formulir Publikasi 2026',
            'status'            => FormSchema::STATUS_PUBLISHED,
            'schema_hash'       => 'hash_initial_published',
            'grace_period_days' => 7,
            'is_active'         => true,
            'published_at'      => now()->subDays(5),
            'published_by'      => $this->adminUser->id,
            'created_by'        => $this->adminUser->id,
        ]);

        // A. Mutasi version_code -> Ditolak
        $this->assertThrowsDomainException(fn() => $published->update(['version_code' => 'v2026.modified']));

        // B. Mutasi year -> Ditolak
        $this->assertThrowsDomainException(fn() => $published->update(['year' => 2027]));

        // C. Mutasi title -> Ditolak
        $this->assertThrowsDomainException(fn() => $published->update(['title' => 'Judul Diubah']));

        // D. Mutasi schema_hash -> Ditolak
        $this->assertThrowsDomainException(fn() => $published->update(['schema_hash' => 'tampered_hash']));

        // E. Mutasi grace_period_days -> Ditolak
        $this->assertThrowsDomainException(fn() => $published->update(['grace_period_days' => 30]));

        // F. Mutasi published_at -> Ditolak
        $this->assertThrowsDomainException(fn() => $published->update(['published_at' => now()]));

        // G. Pengembalian ke draft -> Ditolak
        $this->assertThrowsDomainException(fn() => $published->update(['status' => FormSchema::STATUS_DRAFT]));
    }

    /**
     * 9. Transisi resmi dari published ke archived diizinkan tanpa merusak metadata historis.
     */
    public function test_published_schema_official_archival_transition_is_permitted(): void
    {
        $published = FormSchema::create([
            'version_code'      => 'v2026.1-to-archive-' . uniqid(),
            'year'              => 2026,
            'title'             => 'Formulir Siap Arsip',
            'status'            => FormSchema::STATUS_PUBLISHED,
            'schema_hash'       => 'hash_archive_test',
            'grace_period_days' => 7,
            'is_active'         => true,
            'published_at'      => now()->subDays(10),
        ]);

        // Transisi resmi: status -> archived, is_active -> false
        $published->update([
            'status'    => FormSchema::STATUS_ARCHIVED,
            'is_active' => false,
        ]);

        $fresh = $published->fresh();
        $this->assertEquals(FormSchema::STATUS_ARCHIVED, $fresh->status);
        $this->assertFalse($fresh->is_active);
        $this->assertEquals(7, $fresh->grace_period_days);
        $this->assertEquals('Formulir Siap Arsip', $fresh->title);
    }

    /**
     * 10. Skema archived bersifat imutabel permanen untuk seluruh atribut kebijakan.
     */
    public function test_archived_schema_is_completely_immutable(): void
    {
        $archived = FormSchema::create([
            'version_code'      => 'v2026.1-perm-archived-' . uniqid(),
            'year'              => 2026,
            'title'             => 'Formulir Arsip Permanen',
            'status'            => FormSchema::STATUS_ARCHIVED,
            'schema_hash'       => 'hash_permanent_archived',
            'grace_period_days' => 7,
            'is_active'         => false,
            'published_at'      => now()->subDays(20),
        ]);

        // Seluruh mutasi atribut harus ditolak:
        $this->assertThrowsDomainException(fn() => $archived->fresh()->update(['status' => FormSchema::STATUS_PUBLISHED]));
        $this->assertThrowsDomainException(fn() => $archived->fresh()->update(['status' => FormSchema::STATUS_DRAFT]));
        $this->assertThrowsDomainException(fn() => $archived->fresh()->update(['title' => 'Judul Baru Arsip']));
        $this->assertThrowsDomainException(fn() => $archived->fresh()->update(['grace_period_days' => 99]));
        $this->assertThrowsDomainException(fn() => $archived->fresh()->update(['is_active' => true]));
        $this->assertThrowsDomainException(fn() => $archived->fresh()->delete());
    }

    /**
     * 11. Skema draft dapat diedit secara bebas sesuai wewenang admin.
     */
    public function test_draft_schema_can_be_edited_freely(): void
    {
        $draft = FormSchema::create([
            'version_code'      => 'v2026.1-draft-editable-' . uniqid(),
            'year'              => 2026,
            'title'             => 'Draf Awal',
            'status'            => FormSchema::STATUS_DRAFT,
            'grace_period_days' => 7,
            'is_active'         => false,
        ]);

        $draft->update([
            'title'             => 'Draf Revisi Admin',
            'grace_period_days' => 14,
            'year'              => 2027,
        ]);

        $fresh = $draft->fresh();
        $this->assertEquals('Draf Revisi Admin', $fresh->title);
        $this->assertEquals(14, $fresh->grace_period_days);
        $this->assertEquals(2027, $fresh->year);
    }

    /**
     * 12. Publikasi multi-versi (v1 -> v2 -> v3) menjaga kestabilan grace period masing-masing versi.
     */
    public function test_multi_version_publication_and_grace_period_stability(): void
    {
        $formService = app(FormBuilderService::class);

        // 1. Publikasikan v1
        $draftV1 = $formService->getOrCreateDraft(2026, $this->adminUser);
        $draftV1->update(['grace_period_days' => 7]);
        $formService->addFieldToDraft($draftV1, [
            'field_key'   => 'pertanyaan_v1_' . uniqid(),
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Versi 1',
            'is_required' => true,
        ]);
        $publishedV1 = $formService->publishVersion($draftV1, $this->adminUser);

        $this->assertEquals(FormSchema::STATUS_PUBLISHED, $publishedV1->status);
        $this->assertEquals(7, $publishedV1->grace_period_days);

        // 2. Publikasikan v2
        $draftV2 = $formService->getOrCreateDraft(2026, $this->adminUser);
        $draftV2->update(['grace_period_days' => 14]);
        $formService->addFieldToDraft($draftV2, [
            'field_key'   => 'pertanyaan_v2_' . uniqid(),
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Versi 2',
            'is_required' => false,
        ]);
        $publishedV2 = $formService->publishVersion($draftV2, $this->adminUser);

        // V1 sekarang diarsipkan, V2 published
        $freshV1 = $publishedV1->fresh();
        $this->assertEquals(FormSchema::STATUS_ARCHIVED, $freshV1->status);
        $this->assertEquals(7, $freshV1->grace_period_days); // Grace period V1 tidak berubah!
        $this->assertEquals(FormSchema::STATUS_PUBLISHED, $publishedV2->status);
        $this->assertEquals(14, $publishedV2->grace_period_days);
    }

    // =========================================================================
    // FIX 4 TESTS: FORM FIELD CONTROLLER ADMIN MUTATION PATHS
    // =========================================================================

    /**
     * 13. Endpoint toggle() menolak field pada skema published atau archived.
     */
    public function test_admin_toggle_rejected_for_published_or_archived_schema_field(): void
    {
        $published = FormSchema::create([
            'version_code' => 'v2026.1-pub-field-' . uniqid(),
            'year'         => 2026,
            'title'        => 'Skema Published',
            'status'       => FormSchema::STATUS_PUBLISHED,
            'is_active'    => true,
        ]);

        $field = FormField::create([
            'form_schema_id' => $published->id,
            'field_key'      => 'field_locked_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Field Terkunci',
            'is_active'      => true,
            'is_required'    => false,
            'is_archived'    => false,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->json('POST', route('admin.form-settings.fields.toggle', ['field' => $field->id]));

        $response->assertStatus(422);
        $response->assertJsonFragment(['ok' => false]);
        $this->assertTrue($field->fresh()->is_active);
    }

    /**
     * 14. Endpoint toggleRequired() menolak field pada skema published atau archived.
     */
    public function test_admin_toggle_required_rejected_for_published_or_archived_schema_field(): void
    {
        $published = FormSchema::create([
            'version_code' => 'v2026.1-pub-field2-' . uniqid(),
            'year'         => 2026,
            'title'        => 'Skema Published 2',
            'status'       => FormSchema::STATUS_PUBLISHED,
            'is_active'    => true,
        ]);

        $field = FormField::create([
            'form_schema_id' => $published->id,
            'field_key'      => 'field_req_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Field Required',
            'is_active'      => true,
            'is_required'    => false,
            'is_archived'    => false,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->json('POST', route('admin.form-settings.fields.toggle-required', ['field' => $field->id]));

        $response->assertStatus(422);
        $response->assertJsonFragment(['ok' => false]);
        $this->assertFalse($field->fresh()->is_required);
    }

    /**
     * 15. Endpoint reorder() menolak duplikasi ID field.
     */
    public function test_admin_reorder_rejects_duplicate_ids(): void
    {
        $draft = FormSchema::create([
            'version_code' => 'v2026.1-draft-reorder-' . uniqid(),
            'year'         => 2026,
            'title'        => 'Draft Reorder',
            'status'       => FormSchema::STATUS_DRAFT,
            'is_active'    => false,
        ]);

        $f1 = FormField::create([
            'form_schema_id' => $draft->id,
            'field_key'      => 'f1_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Field 1',
            'sort_order'     => 10,
        ]);

        $f2 = FormField::create([
            'form_schema_id' => $draft->id,
            'field_key'      => 'f2_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Field 2',
            'sort_order'     => 20,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->json('POST', route('admin.form-settings.fields.reorder'), [
                'ids' => [$f1->id, $f2->id, $f1->id], // Duplikasi f1
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Ditemukan ID duplikat dalam permintaan reorder.']);
    }

    /**
     * 16. Endpoint reorder() menolak field dari skema yang berstatus published atau archived.
     */
    public function test_admin_reorder_rejects_published_schema_fields(): void
    {
        $published = FormSchema::create([
            'version_code' => 'v2026.1-pub-reorder-' . uniqid(),
            'year'         => 2026,
            'title'        => 'Published Reorder',
            'status'       => FormSchema::STATUS_PUBLISHED,
            'is_active'    => true,
        ]);

        $f1 = FormField::create([
            'form_schema_id' => $published->id,
            'field_key'      => 'pub_f1_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Pub F1',
            'sort_order'     => 10,
        ]);

        $f2 = FormField::create([
            'form_schema_id' => $published->id,
            'field_key'      => 'pub_f2_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Pub F2',
            'sort_order'     => 20,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->json('POST', route('admin.form-settings.fields.reorder'), [
                'ids' => [$f2->id, $f1->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Reorder urutan pertanyaan hanya diizinkan pada skema berstatus draft.']);
    }

    /**
     * 17. Endpoint reorder() menolak field yang bercampur dari skema yang berbeda.
     */
    public function test_admin_reorder_rejects_mixed_schemas(): void
    {
        $draftA = FormSchema::create([
            'version_code' => 'v2026.1-draft-a-' . uniqid(),
            'year'         => 2026,
            'title'        => 'Draft A',
            'status'       => FormSchema::STATUS_DRAFT,
        ]);

        $draftB = FormSchema::create([
            'version_code' => 'v2027.1-draft-b-' . uniqid(),
            'year'         => 2027,
            'title'        => 'Draft B',
            'status'       => FormSchema::STATUS_DRAFT,
        ]);

        $fA = FormField::create([
            'form_schema_id' => $draftA->id,
            'field_key'      => 'fa_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Field A',
            'sort_order'     => 10,
        ]);

        $fB = FormField::create([
            'form_schema_id' => $draftB->id,
            'field_key'      => 'fb_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Field B',
            'sort_order'     => 10,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->json('POST', route('admin.form-settings.fields.reorder'), [
                'ids' => [$fA->id, $fB->id],
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Seluruh field yang direorder harus berasal dari satu skema formulir yang sama.']);
    }

    /**
     * 18. Endpoint reorder() berhasil mengubah urutan secara atomik untuk field draft yang sah.
     */
    public function test_admin_reorder_succeeds_atomically_for_valid_draft_fields(): void
    {
        $draft = FormSchema::create([
            'version_code' => 'v2026.1-draft-valid-' . uniqid(),
            'year'         => 2026,
            'title'        => 'Draft Valid Reorder',
            'status'       => FormSchema::STATUS_DRAFT,
        ]);

        $f1 = FormField::create([
            'form_schema_id' => $draft->id,
            'field_key'      => 'fv1_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Field 1',
            'sort_order'     => 10,
        ]);

        $f2 = FormField::create([
            'form_schema_id' => $draft->id,
            'field_key'      => 'fv2_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Field 2',
            'sort_order'     => 20,
        ]);

        $f3 = FormField::create([
            'form_schema_id' => $draft->id,
            'field_key'      => 'fv3_' . uniqid(),
            'field_type'     => 'text',
            'label'          => 'Field 3',
            'sort_order'     => 30,
        ]);

        // Balik urutan: f3, f1, f2
        $response = $this->actingAs($this->adminUser)
            ->json('POST', route('admin.form-settings.fields.reorder'), [
                'ids'  => [$f3->id, $f1->id, $f2->id],
                'year' => 2026,
            ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['ok' => true]);

        $this->assertEquals(10, $f3->fresh()->sort_order);
        $this->assertEquals(20, $f1->fresh()->sort_order);
        $this->assertEquals(30, $f2->fresh()->sort_order);
    }

    /**
     * Helper untuk memverifikasi eksepsi DomainException.
     */
    protected function assertThrowsDomainException(callable $callback): void
    {
        $thrown = false;
        try {
            $callback();
        } catch (DomainException $e) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Diharapkan melempar DomainException namun tidak ada eksepsi yang dilempar.');
    }
}
