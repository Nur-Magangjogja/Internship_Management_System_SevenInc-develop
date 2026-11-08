<?php

namespace Tests\Feature\Spreadsheet;

use App\Models\Brand;
use App\Models\FormField;
use App\Models\FormSchema;
use App\Models\User;
use App\Services\FormBuilderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrandAwareFormSchemaTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected Brand $brandJogja;
    protected Brand $brandAreaKerja;
    protected FormBuilderService $formBuilderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formBuilderService = app(FormBuilderService::class);

        $this->adminUser = User::create([
            'name'      => 'Admin Brand Test',
            'email'     => 'admin_brand_' . uniqid() . '@seveninc.test',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $this->brandJogja = Brand::create([
            'code'              => 'JOG',
            'name'              => 'Seven Inc Jogja',
            'registration_host' => 'jogja.seveninc.test',
            'primary_domain'    => 'seveninc.id',
            'is_default'        => true,
            'lifecycle_status'  => 'active',
            'domain_verified_at'=> now(),
        ]);

        $this->brandAreaKerja = Brand::create([
            'code'              => 'AK',
            'name'              => 'Area Kerja',
            'registration_host' => 'areakerja.seveninc.test',
            'primary_domain'    => 'areakerja.com',
            'is_default'        => false,
            'lifecycle_status'  => 'active',
            'domain_verified_at'=> now(),
        ]);
    }

    public function test_fallback_to_global_baseline_when_brand_has_no_custom_published_schema(): void
    {
        // 1. Create a published global schema
        $globalDraft = $this->formBuilderService->getOrCreateDraft(2026, $this->adminUser, brandId: null);
        $this->formBuilderService->addFieldToDraft($globalDraft, [
            'field_key'   => 'custom_global_question',
            'field_type'  => 'text',
            'label'       => 'Pertanyaan Baseline Global',
            'is_required' => true,
        ]);
        $globalPublished = $this->formBuilderService->publishVersion($globalDraft, $this->adminUser);

        $this->assertNull($globalPublished->brand_id);
        $this->assertTrue($globalPublished->is_active);

        // 2. Resolve for brandJogja (has no custom published schema yet)
        $resolvedSchema = $this->formBuilderService->resolveEffectivePublishedSchema($this->brandJogja->id, 2026);

        // Must fallback to global published baseline
        $this->assertNotNull($resolvedSchema);
        $this->assertNull($resolvedSchema->brand_id);
        $this->assertEquals($globalPublished->id, $resolvedSchema->id);
    }

    public function test_forking_global_template_creates_isolated_brand_draft_with_brand_code(): void
    {
        // 1. Setup published global baseline
        $globalDraft = $this->formBuilderService->getOrCreateDraft(2026, $this->adminUser, brandId: null);
        $this->formBuilderService->addFieldToDraft($globalDraft, [
            'field_key'   => 'custom_baseline_portfolio',
            'field_type'  => 'text',
            'label'       => 'Link Portfolio Utama',
            'is_required' => false,
        ]);
        $globalPublished = $this->formBuilderService->publishVersion($globalDraft, $this->adminUser);

        // 2. Fork to Brand Jogja
        $brandDraft = $this->formBuilderService->forkGlobalTemplateToBrandDraft(2026, $this->brandJogja->id, $this->adminUser);

        $this->assertEquals($this->brandJogja->id, $brandDraft->brand_id);
        $this->assertEquals($globalPublished->id, $brandDraft->source_schema_id);
        $this->assertEquals(FormSchema::STATUS_DRAFT, $brandDraft->status);
        $this->assertStringContainsString('JOG', $brandDraft->version_code);
        $this->assertStringContainsString('-draft', $brandDraft->version_code);

        // Brand draft inherits fields from global baseline
        $this->assertTrue($brandDraft->fields()->where('field_key', 'custom_baseline_portfolio')->exists());
    }

    public function test_brand_draft_modification_is_isolated_from_global_baseline(): void
    {
        // 1. Setup published global baseline
        $globalDraft = $this->formBuilderService->getOrCreateDraft(2026, $this->adminUser, brandId: null);
        $this->formBuilderService->addFieldToDraft($globalDraft, [
            'field_key'   => 'custom_common_info',
            'field_type'  => 'text',
            'label'       => 'Informasi Umum',
        ]);
        $globalPublished = $this->formBuilderService->publishVersion($globalDraft, $this->adminUser);

        // 2. Fork to Brand Jogja
        $brandJogjaDraft = $this->formBuilderService->forkGlobalTemplateToBrandDraft(2026, $this->brandJogja->id, $this->adminUser);

        // 3. Add custom question specifically to Brand Jogja draft
        $this->formBuilderService->addFieldToDraft($brandJogjaDraft, [
            'field_key'   => 'custom_jogja_specific_interview',
            'field_type'  => 'text',
            'label'       => 'Jadwal Interview Khusus Kantor Jogja',
        ]);

        // Publish Brand Jogja draft
        $brandJogjaPublished = $this->formBuilderService->publishVersion($brandJogjaDraft, $this->adminUser);

        $this->assertEquals(FormSchema::STATUS_PUBLISHED, $brandJogjaPublished->status);
        $this->assertTrue($brandJogjaPublished->is_active);
        $this->assertEquals($this->brandJogja->id, $brandJogjaPublished->brand_id);

        // Global baseline must NOT have the jogja-specific field
        $refreshedGlobal = $globalPublished->fresh();
        $this->assertFalse($refreshedGlobal->fields()->where('field_key', 'custom_jogja_specific_interview')->exists());

        // Brand Area Kerja (unforked) still resolves to global baseline
        $akSchema = $this->formBuilderService->resolveEffectivePublishedSchema($this->brandAreaKerja->id, 2026);
        $this->assertNull($akSchema->brand_id);
        $this->assertFalse($akSchema->fields()->where('field_key', 'custom_jogja_specific_interview')->exists());

        // Brand Jogja resolves to its own custom published schema
        $jogSchema = $this->formBuilderService->resolveEffectivePublishedSchema($this->brandJogja->id, 2026);
        $this->assertEquals($this->brandJogja->id, $jogSchema->brand_id);
        $this->assertTrue($jogSchema->fields()->where('field_key', 'custom_jogja_specific_interview')->exists());
    }

    public function test_publishing_brand_draft_archives_previous_brand_published_version_only(): void
    {
        // 1. Publish Global Baseline v2026.1
        $globalDraft = $this->formBuilderService->getOrCreateDraft(2026, $this->adminUser, brandId: null);
        $globalPublished = $this->formBuilderService->publishVersion($globalDraft, $this->adminUser);

        // 2. Publish Brand Jogja v2026.1-JOG
        $jogjaDraft1 = $this->formBuilderService->forkGlobalTemplateToBrandDraft(2026, $this->brandJogja->id, $this->adminUser);
        $jogjaPub1 = $this->formBuilderService->publishVersion($jogjaDraft1, $this->adminUser);

        // 3. Publish Brand Jogja v2026.2-JOG
        $jogjaDraft2 = $this->formBuilderService->getOrCreateDraft(2026, $this->adminUser, $this->brandJogja->id);
        $this->formBuilderService->addFieldToDraft($jogjaDraft2, [
            'field_key'   => 'custom_jogja_v2_field',
            'field_type'  => 'text',
            'label'       => 'Field Versi 2 Jogja',
        ]);
        $jogjaPub2 = $this->formBuilderService->publishVersion($jogjaDraft2, $this->adminUser);

        // Verify:
        // - jogjaPub1 must now be archived and inactive
        $this->assertEquals(FormSchema::STATUS_ARCHIVED, $jogjaPub1->fresh()->status);
        $this->assertFalse((bool) $jogjaPub1->fresh()->is_active);

        // - jogjaPub2 is now active
        $this->assertEquals(FormSchema::STATUS_PUBLISHED, $jogjaPub2->fresh()->status);
        $this->assertTrue((bool) $jogjaPub2->fresh()->is_active);

        // - Global baseline remains PUBLISHED and ACTIVE!
        $this->assertEquals(FormSchema::STATUS_PUBLISHED, $globalPublished->fresh()->status);
        $this->assertTrue((bool) $globalPublished->fresh()->is_active);
    }

    public function test_database_constraint_rejects_duplicate_active_published_schemas_for_same_brand_and_year(): void
    {
        // 1. Create first active published schema for Brand Jogja
        FormSchema::create([
            'version_code' => 'v2026.1-JOG',
            'year'         => 2026,
            'brand_id'     => $this->brandJogja->id,
            'title'        => 'Form Jogja v1',
            'status'       => FormSchema::STATUS_PUBLISHED,
            'is_active'    => true,
        ]);

        // 2. Attempting to insert a second active published schema for the exact same year and brand must fail at DB level
        $this->expectException(\Illuminate\Database\QueryException::class);

        FormSchema::create([
            'version_code' => 'v2026.2-JOG',
            'year'         => 2026,
            'brand_id'     => $this->brandJogja->id,
            'title'        => 'Form Jogja v2 Duplicate',
            'status'       => FormSchema::STATUS_PUBLISHED,
            'is_active'    => true,
        ]);
    }

    public function test_database_constraint_rejects_duplicate_active_published_schemas_for_global_baseline(): void
    {
        // 1. Create first active published global schema
        FormSchema::create([
            'version_code' => 'v2026.1',
            'year'         => 2026,
            'brand_id'     => null,
            'title'        => 'Form Global v1',
            'status'       => FormSchema::STATUS_PUBLISHED,
            'is_active'    => true,
        ]);

        // 2. Attempting to insert a second active published global schema must fail at DB level
        $this->expectException(\Illuminate\Database\QueryException::class);

        FormSchema::create([
            'version_code' => 'v2026.2',
            'year'         => 2026,
            'brand_id'     => null,
            'title'        => 'Form Global v2 Duplicate',
            'status'       => FormSchema::STATUS_PUBLISHED,
            'is_active'    => true,
        ]);
    }
}
