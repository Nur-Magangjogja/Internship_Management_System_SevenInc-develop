<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\SystemAsset;
use App\Models\User;
use App\Services\MailMerge\VariableRegistry;
use App\Services\MailMerge\VariableResolverService;
use App\Services\SystemAssetService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VariableRegistryAndResolverTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Brand $brand;
    private Institution $institution;
    private InternshipRegistration $intern;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->unique = uniqid('var_test_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->brand = Brand::create([
            'name'               => 'Seven Inc ' . $this->unique,
            'code'               => 'SEVEN',
            'signatory_name'     => 'Rekario Danny Sanjaya, S.Kom.',
            'signatory_position' => 'Direktur Utama',
        ]);

        $this->institution = Institution::create([
            'name'                => 'Universitas Gadjah Mada',
            'address'             => 'Bulaksumur, Yogyakarta',
            'phone'               => '0274588688',
            'email'               => 'ugm_' . $this->unique . '@test.com',
            'correspondence_unit' => 'Humas',
            'is_active'           => true,
        ]);

        $fac = \App\Models\Faculty::firstOrCreate(['name' => 'Fakultas Teknik ' . $this->unique, 'institution_id' => $this->institution->id]);
        $prog = \App\Models\StudyProgram::firstOrCreate(['name' => 'Teknologi Informasi ' . $this->unique, 'faculty_id' => $fac->id]);
        $div = \App\Models\Division::firstOrCreate(['name' => 'Web Dev ' . $this->unique], ['code' => 'DEV', 'is_active' => true]);

        $this->intern = InternshipRegistration::create([
            'user_id'           => $this->admin->id,
            'fullname'          => 'Budi Pratama',
            'student_id'        => '21/475890/TK/52100',
            'institution_id'    => $this->institution->id,
            'institution_name'  => $this->institution->name,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'study_program'     => 'Teknologi Informasi',
            'division_id'       => $div->id,
            'brand_id'          => $this->brand->id,
            'brand'             => $this->brand->name,
            'gender'            => 'Laki-laki',
            'born_date'         => '2002-01-01',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'start_date'        => '2026-03-01',
            'end_date'          => '2026-08-31',
            'phone_number'      => '081234567899',
        ]);
    }

    public function test_variable_registry_contains_required_definitions(): void
    {
        $defs = VariableRegistry::getDefinitions();

        $this->assertArrayHasKey('pemagang.nama', $defs);
        $this->assertArrayHasKey('pemagang.nim', $defs);
        $this->assertArrayHasKey('pemagang.institusi', $defs);
        $this->assertArrayHasKey('pemagang.prodi', $defs);
        $this->assertArrayHasKey('dokumen.nomor', $defs);
        $this->assertArrayHasKey('dokumen.tanggal', $defs);
        $this->assertArrayHasKey('asset.company.logo', $defs);
        $this->assertArrayHasKey('asset.signature', $defs);
        $this->assertArrayHasKey('asset.stamp', $defs);
    }

    public function test_variable_resolver_resolves_data_and_legacy_placeholders(): void
    {
        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        $resolved = $resolver->resolveContext(
            intern: $this->intern,
            institution: $this->institution,
            brand: $this->brand
        );

        $variables = $resolved['variables'];
        $this->assertEquals('Budi Pratama', $variables['pemagang.nama']);
        $this->assertEquals('21/475890/TK/52100', $variables['pemagang.nim']);
        $this->assertEquals('Universitas Gadjah Mada', $variables['pemagang.institusi']);

        // Test rendering with new double curly syntax
        $template1 = "Halo {{pemagang.nama}}, NIM Anda adalah {{pemagang.nim}} dari kampus {{pemagang.institusi}}.";
        $rendered1 = $resolver->renderContent($template1, $resolved);
        $this->assertEquals("Halo Budi Pratama, NIM Anda adalah 21/475890/TK/52100 dari kampus Universitas Gadjah Mada.", $rendered1);

        // Test rendering with legacy bracket aliases syntax ([fullname], [nim_nis], [institution_name])
        $template2 = "Surat Keterangan: Pemagang [fullname] ([nim_nis]) asal [institution_name].";
        $rendered2 = $resolver->renderContent($template2, $resolved);
        $this->assertEquals("Surat Keterangan: Pemagang Budi Pratama (21/475890/TK/52100) asal Universitas Gadjah Mada.", $rendered2);
    }

    public function test_asset_resolver_embeds_base64_data_uri_and_records_references(): void
    {
        /** @var SystemAssetService $assetService */
        $assetService = app(SystemAssetService::class);
        $logoFile = UploadedFile::fake()->image('company_logo.png', 200, 100);

        $logoAsset = $assetService->storeAsset($logoFile, [
            'name'       => 'Logo Utama Seven Inc',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_GLOBAL,
            'is_default' => true,
        ], $this->admin);

        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        $resolved = $resolver->resolveContext(
            intern: $this->intern,
            institution: $this->institution,
            brand: $this->brand
        );

        $this->assertArrayHasKey('asset.company.logo', $resolved['asset_references']);
        $this->assertEquals($logoAsset->id, $resolved['asset_references']['asset.company.logo']['asset_id']);

        $imageUri = $resolved['resolved_images']['asset.company.logo'];
        $this->assertStringStartsWith('data:image/png;base64,', $imageUri);
    }
}
