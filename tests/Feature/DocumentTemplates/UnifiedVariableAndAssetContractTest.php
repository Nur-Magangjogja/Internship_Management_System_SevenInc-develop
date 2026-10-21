<?php

namespace Tests\Feature\DocumentTemplates;

use App\Models\Brand;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\Mou;
use App\Models\SystemAsset;
use App\Models\User;
use App\Services\DocumentTemplates\MailMergePlaceholderContract;
use App\Services\MailMerge\VariableRegistry;
use App\Services\MailMerge\VariableResolverService;
use App\Services\SystemAssetService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UnifiedVariableAndAssetContractTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Brand $brandA;
    private Brand $brandB;
    private Institution $institutionX;
    private Institution $institutionY;
    private InternshipRegistration $intern;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->unique = uniqid('contract_test_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->brandA = Brand::create([
            'name'               => 'Seven Inc Brand A ' . $this->unique,
            'code'               => 'SIA',
            'signatory_name'     => 'Rekario Danny Sanjaya, S.Kom.',
            'signatory_position' => 'Direktur Utama',
            'address'            => 'Yogyakarta A',
            'phone'              => '0274-111111',
            'email'              => 'branda@seveninc.com',
        ]);

        $this->brandB = Brand::create([
            'name'               => 'Seven Inc Brand B ' . $this->unique,
            'code'               => 'SIB',
            'signatory_name'     => 'Budi Santoso, M.Kom.',
            'signatory_position' => 'Direktur Operasional',
            'address'            => 'Yogyakarta B',
            'phone'              => '0274-222222',
            'email'              => 'brandb@seveninc.com',
        ]);

        $this->institutionX = Institution::create([
            'name'                => 'Universitas Gadjah Mada ' . $this->unique,
            'address'             => 'Bulaksumur, Sleman, Yogyakarta',
            'phone'               => '0274-555555',
            'email'               => 'ugm_' . $this->unique . '@test.com',
            'correspondence_unit' => 'Direktorat Kemitraan',
            'is_active'           => true,
        ]);

        $this->institutionY = Institution::create([
            'name'                => 'Universitas Indonesia ' . $this->unique,
            'address'             => 'Depok, Jawa Barat',
            'phone'               => '021-777777',
            'email'               => 'ui_' . $this->unique . '@test.com',
            'correspondence_unit' => 'Humas UI',
            'is_active'           => true,
        ]);

        // Tambah kontak institusi dengan position_title
        InstitutionContact::create([
            'institution_id' => $this->institutionX->id,
            'name'           => 'Prof. Dr. Suratman, M.Sc.',
            'position_title' => 'Wakil Rektor Bidang Kerja Sama',
            'phone'          => '081234567890',
            'email'          => 'suratman@ugm.ac.id',
            'is_active'      => true,
        ]);

        $fac = \App\Models\Faculty::firstOrCreate([
            'name'           => 'Fakultas MIPA ' . $this->unique,
            'institution_id' => $this->institutionX->id,
        ]);
        $prog = \App\Models\StudyProgram::firstOrCreate([
            'name'       => 'Ilmu Komputer ' . $this->unique,
            'faculty_id' => $fac->id,
        ]);
        $div = \App\Models\Division::firstOrCreate(
            ['name' => 'Backend Dev ' . $this->unique],
            ['code' => 'BACK', 'is_active' => true]
        );

        $this->intern = InternshipRegistration::create([
            'user_id'           => $this->admin->id,
            'fullname'          => 'Muhammad Alif',
            'student_id'        => '21/480000/PA/20000',
            'institution_id'    => $this->institutionX->id,
            'institution_name'  => $this->institutionX->name,
            'faculty_id'        => $fac->id,
            'study_program_id'  => $prog->id,
            'study_program'     => 'Ilmu Komputer',
            'division_id'       => $div->id,
            'brand_id'          => $this->brandA->id,
            'brand'             => $this->brandA->name,
            'gender'            => 'Laki-laki',
            'born_date'         => '2003-05-10',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
            'start_date'        => '2026-02-01',
            'end_date'          => '2026-07-31',
            'phone_number'      => '081298765432',
        ]);
    }

    /**
     * 1. Validasi Matriks 6 Jenis Dokumen pada VariableRegistry.
     */
    public function test_variable_registry_covers_all_six_document_types(): void
    {
        $allTypes = VariableRegistry::ALL_DOC_TYPES;
        $this->assertCount(6, $allTypes);
        $this->assertContains('mou', $allTypes);
        $this->assertContains('loa', $allTypes);
        $this->assertContains('skl', $allTypes);
        $this->assertContains('certificate', $allTypes);
        $this->assertContains('assessment', $allTypes);
        $this->assertContains('recommendation', $allTypes);

        // Periksa filter token per jenis dokumen
        foreach ($allTypes as $type) {
            $tokens = VariableRegistry::getTokensForDocumentType($type);
            $this->assertNotEmpty($tokens, "Token untuk tipe {$type} tidak boleh kosong");
            $this->assertIsArray($tokens);

            $required = VariableRegistry::getRequiredTokens($type);
            $this->assertIsArray($required);
        }

        // MoU wajib memiliki brand.nama dan institusi.nama
        $mouRequired = VariableRegistry::getRequiredTokens('mou');
        $this->assertContains('brand.nama', $mouRequired);
        $this->assertContains('institusi.nama', $mouRequired);
        $this->assertContains('brand.penandatangan.nama', $mouRequired);

        // LOA wajib memiliki pemagang.nama
        $loaRequired = VariableRegistry::getRequiredTokens('loa');
        $this->assertContains('pemagang.nama', $loaRequired);
    }

    /**
     * 2. Validasi Normalisasi Alias Legacy (MoU legacy & DocumentTemplate legacy).
     */
    public function test_legacy_aliases_normalize_to_canonical_keys(): void
    {
        // MoU Aliases
        $this->assertEquals('institusi.nama', VariableRegistry::normalizeToken('institution_name'));
        $this->assertEquals('institusi.nama', VariableRegistry::normalizeToken('{{institution_name}}'));
        $this->assertEquals('institusi.nama', VariableRegistry::normalizeToken('[institution_name]'));
        $this->assertEquals('brand.nama', VariableRegistry::normalizeToken('company_name'));
        $this->assertEquals('brand.nama', VariableRegistry::normalizeToken('{{company_name}}'));
        $this->assertEquals('brand.alamat', VariableRegistry::normalizeToken('company_address'));
        $this->assertEquals('institusi.penandatangan.nama', VariableRegistry::normalizeToken('institution_signatory_name'));
        $this->assertEquals('institusi.penandatangan.jabatan', VariableRegistry::normalizeToken('institution_signatory_title'));
        $this->assertEquals('asset.institusi.logo', VariableRegistry::normalizeToken('institution_logo'));
        $this->assertEquals('asset.brand.logo', VariableRegistry::normalizeToken('company_logo'));

        // Surat Pemagang Aliases
        $this->assertEquals('pemagang.nama', VariableRegistry::normalizeToken('student_name'));
        $this->assertEquals('pemagang.nama', VariableRegistry::normalizeToken('fullname'));
        $this->assertEquals('pemagang.nim', VariableRegistry::normalizeToken('nim_nis'));
        $this->assertEquals('penilaian.nilai_akhir', VariableRegistry::normalizeToken('nilai.angka'));
        $this->assertEquals('penilaian.predikat', VariableRegistry::normalizeToken('nilai.predikat'));
        $this->assertEquals('rekomendasi.catatan', VariableRegistry::normalizeToken('recommendation_note'));
    }

    /**
     * 3. Validasi Resolusi position_title dari institution_contacts (Akar Masalah 3).
     */
    public function test_resolver_correctly_reads_contact_position_title(): void
    {
        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        $resolved = $resolver->resolveContext(
            intern: $this->intern,
            institution: $this->institutionX,
            brand: $this->brandA
        );

        $variables = $resolved['variables'];

        $this->assertEquals('Prof. Dr. Suratman, M.Sc.', $variables['institusi.kontak_nama']);
        $this->assertEquals('Wakil Rektor Bidang Kerja Sama', $variables['institusi.kontak_jabatan']);
        $this->assertEquals('Prof. Dr. Suratman, M.Sc.', $variables['institusi.penandatangan.nama']);
        $this->assertEquals('Wakil Rektor Bidang Kerja Sama', $variables['institusi.penandatangan.jabatan']);

        // Pastikan alias legacy MoU juga terisi otomatis
        $this->assertEquals('Prof. Dr. Suratman, M.Sc.', $variables['institution_signatory_name']);
        $this->assertEquals('Wakil Rektor Bidang Kerja Sama', $variables['institution_signatory_title']);
    }

    /**
     * 4. Validasi Isolasi Aset Institusi: Logo Institusi X tidak bocor ke Institusi Y atau Brand (INV-05).
     */
    public function test_institution_asset_isolation_prevents_cross_owner_leakage(): void
    {
        /** @var SystemAssetService $assetService */
        $assetService = app(SystemAssetService::class);

        // Upload logo untuk Institusi X
        $fileX = UploadedFile::fake()->image('ugm_logo.png', 200, 200);
        $assetLogoX = $assetService->storeAsset($fileX, [
            'name'           => 'Logo Resmi UGM',
            'category'       => SystemAsset::CATEGORY_LOGO,
            'scope'          => SystemAsset::SCOPE_INSTITUTION,
            'institution_id' => $this->institutionX->id,
            'is_default'     => true,
        ], $this->admin);

        // Upload logo untuk Brand A
        $fileBrand = UploadedFile::fake()->image('seveninc_logo.png', 200, 100);
        $assetBrandA = $assetService->storeAsset($fileBrand, [
            'name'       => 'Logo Seven Inc Brand A',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_BRAND,
            'brand_id'   => $this->brandA->id,
            'is_default' => true,
        ], $this->admin);

        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        // Selesaikan konteks untuk Institusi X
        $resolvedX = $resolver->resolveContext(
            intern: $this->intern,
            institution: $this->institutionX,
            brand: $this->brandA,
            isLiveIssuance: true
        );

        $this->assertArrayHasKey('asset.institusi.logo', $resolvedX['asset_references']);
        $this->assertEquals($assetLogoX->id, $resolvedX['asset_references']['asset.institusi.logo']['asset_id']);
        $this->assertEquals($assetBrandA->id, $resolvedX['asset_references']['asset.brand.logo']['asset_id']);

        // Selesaikan konteks untuk Institusi Y (yang TIDAK memiliki logo)
        $resolvedY = $resolver->resolveContext(
            intern: null,
            institution: $this->institutionY,
            brand: $this->brandA,
            isLiveIssuance: true
        );

        // JAMINAN INV-05: Logo Institusi Y HARUS NULL dan TIDAK BOLEH mengambil logo Institusi X atau logo Brand A!
        $this->assertNull($resolvedY['variables']['asset.institusi.logo'] ?? null);
        $this->assertArrayNotHasKey('asset.institusi.logo', $resolvedY['asset_references']);
        $this->assertContains('asset.institusi.logo', $resolvedY['missing_assets']);
    }

    /**
     * 5. Validasi Keamanan Tanda Tangan: Tanda tangan Brand A tidak boleh bocor ke Brand B (INV-05, INV-06).
     */
    public function test_signature_and_stamp_owner_isolation(): void
    {
        /** @var SystemAssetService $assetService */
        $assetService = app(SystemAssetService::class);

        // Simpan TTD untuk Brand A
        $fileSigA = UploadedFile::fake()->image('ttd_direktur_a.png', 200, 100);
        $sigAssetA = $assetService->storeAsset($fileSigA, [
            'name'       => 'TTD Direktur Brand A',
            'category'   => SystemAsset::CATEGORY_SIGNATURE,
            'scope'      => SystemAsset::SCOPE_BRAND,
            'brand_id'   => $this->brandA->id,
            'is_default' => true,
        ], $this->admin);

        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        // Resolusi Brand A mendapatkan TTD miliknya
        $resolvedA = $resolver->resolveContext(
            intern: null,
            institution: $this->institutionX,
            brand: $this->brandA,
            isLiveIssuance: true
        );
        $this->assertArrayHasKey('asset.brand.tanda_tangan', $resolvedA['asset_references']);
        $this->assertEquals($sigAssetA->id, $resolvedA['asset_references']['asset.brand.tanda_tangan']['asset_id']);

        // Resolusi Brand B (belum memiliki TTD) TIDAK BOLEH mengambil TTD Brand A!
        $resolvedB = $resolver->resolveContext(
            intern: null,
            institution: $this->institutionX,
            brand: $this->brandB,
            isLiveIssuance: true
        );
        $this->assertNull($resolvedB['variables']['asset.brand.tanda_tangan'] ?? null);
        $this->assertArrayNotHasKey('asset.brand.tanda_tangan', $resolvedB['asset_references']);
        $this->assertContains('asset.brand.tanda_tangan', $resolvedB['missing_assets']);
    }

    /**
     * 6. Validasi Aturan Penerbitan Resmi: Tanpa Mock / Fake Signature pada Live Issuance (INV-06, INV-14).
     */
    public function test_no_mock_fallback_on_live_issuance(): void
    {
        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        // Live issuance tanpa aset sama sekali di DB
        $resolved = $resolver->resolveContext(
            intern: $this->intern,
            institution: $this->institutionY, // Tidak ada logo
            brand: $this->brandB,             // Tidak ada TTD / stempel
            isLiveIssuance: true,
            isPreviewMock: false
        );

        // Seluruh aset yang tidak ada harus NULL (bukan path/base64 mock hardcoded)
        $this->assertNull($resolved['variables']['asset.institusi.logo']);
        $this->assertNull($resolved['variables']['asset.brand.tanda_tangan']);
        $this->assertNull($resolved['variables']['asset.brand.stempel']);

        // Konten render pada live issuance tidak boleh menyisipkan teks contoh sembarangan
        $content = "Penerbitan resmi: {{unknown_variable}}";
        $rendered = $resolver->renderContent($content, $resolved, isLiveIssuance: true);
        $this->assertEquals("Penerbitan resmi: {{unknown_variable}}", $rendered);

        // MailMergePlaceholderContract juga harus menghormati flag isLiveIssuance
        $contractHtml = MailMergePlaceholderContract::resolve(
            "Surat Resmi: {{asset.signature}}",
            $resolved['variables'],
            isLiveIssuance: true
        );
        // Tidak boleh menyisipkan tag <img> mock sample jika nilainya null
        $this->assertStringNotContainsString('signature-sample.png', $contractHtml);
        $this->assertStringNotContainsString('ttd_arisetiahusbana.png', $contractHtml);
    }

    /**
     * 7. Validasi Diagnosis Variabel Kurang (Missing Diagnostics).
     */
    public function test_diagnose_missing_variables(): void
    {
        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        // Konteks kosong
        $usedTokens = ['{{pemagang.nama}}', '{{pemagang.institusi}}', '{{dokumen.nomor}}', '{{pemagang.nim}}'];
        $resolvedVariables = [
            'pemagang.nama' => 'Budi',
            'pemagang.nim'  => '', // optional
            // pemagang.institusi dan dokumen.nomor hilang
        ];

        $diagnostics = $resolver->diagnoseMissingVariables($usedTokens, $resolvedVariables, 'loa');

        $this->assertFalse($diagnostics['valid']);
        $this->assertArrayHasKey('pemagang.institusi', $diagnostics['missing_required']);
        $this->assertArrayHasKey('dokumen.nomor', $diagnostics['missing_required']);
        // pemagang.nim adalah optional, jadi masuk ke warnings
        $this->assertArrayHasKey('pemagang.nim', $diagnostics['warnings']);
    }

    /**
     * V01: Collision disambiguation across all six document types.
     */
    public function test_v01_collision_disambiguation_matrix_across_all_six_document_types(): void
    {
        $allTypes = ['mou', 'loa', 'skl', 'certificate', 'assessment', 'recommendation'];

        // 1. Matriks token collision untuk setiap tipe dokumen
        foreach ($allTypes as $type) {
            // company_name dan signatory_name berlaku sama
            $this->assertEquals('brand.nama', VariableRegistry::normalizeToken('company_name', $type));
            $this->assertEquals('brand.nama', VariableRegistry::normalizeToken('{{company_name}}', $type));
            $this->assertEquals('brand.penandatangan.nama', VariableRegistry::normalizeToken('signatory_name', $type));

            if ($type === 'mou') {
                // Konteks MoU
                $this->assertEquals('institusi.nama', VariableRegistry::normalizeToken('institution_name', 'mou'));
                $this->assertEquals('mou.tanggal_mulai', VariableRegistry::normalizeToken('start_date', 'mou'));
                $this->assertEquals('mou.tanggal_selesai', VariableRegistry::normalizeToken('end_date', 'mou'));
                $this->assertEquals('mou.durasi', VariableRegistry::normalizeToken('duration', 'mou'));
            } else {
                // Konteks Dokumen Pemagang (loa, skl, certificate, assessment, recommendation)
                $this->assertEquals('pemagang.institusi', VariableRegistry::normalizeToken('institution_name', $type));
                $this->assertEquals('pemagang.divisi', VariableRegistry::normalizeToken('division_name', $type));
                $this->assertEquals('pemagang.tanggal_mulai', VariableRegistry::normalizeToken('start_date', $type));
                $this->assertEquals('pemagang.tanggal_selesai', VariableRegistry::normalizeToken('end_date', $type));
                $this->assertEquals('pemagang.durasi', VariableRegistry::normalizeToken('duration', $type));
            }
        }

        // 2. Round-trip substitution test
        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        // MoU template
        $mouTemplate = "MOU antara {{company_name}} dan {{institution_name}} mulai {{start_date}} s/d {{end_date}}.";
        $mouContext = $resolver->resolveContext(
            institution: $this->institutionX,
            brand: $this->brandA,
            documentType: 'mou'
        );
        $mouContext['variables']['mou.tanggal_mulai'] = '01 Januari 2026';
        $mouContext['variables']['mou.tanggal_selesai'] = '31 Desember 2026';
        $renderedMou = $resolver->renderContent($mouTemplate, $mouContext, documentType: 'mou');
        $this->assertStringContainsString($this->brandA->name, $renderedMou);
        $this->assertStringContainsString($this->institutionX->name, $renderedMou);
        $this->assertStringContainsString('01 Januari 2026', $renderedMou);

        // Intern LOA template
        $loaTemplate = "Surat Diterima: {{institution_name}} divisi {{division_name}} mulai {{start_date}}.";
        $loaContext = $resolver->resolveContext(
            intern: $this->intern,
            institution: $this->institutionX,
            brand: $this->brandA,
            documentType: 'loa'
        );
        $renderedLoa = $resolver->renderContent($loaTemplate, $loaContext, documentType: 'loa');
        $this->assertStringContainsString($this->institutionX->name, $renderedLoa);
        $this->assertStringContainsString('Backend Dev', $renderedLoa);
        $this->assertStringContainsString('01 Februari 2026', $renderedLoa);
    }

    /**
     * V02: Audit keamanan asset_overrides (ID palsu / lintas pemilik / kategori salah / arsip).
     */
    public function test_v02_asset_overrides_security_and_forged_id_rejection(): void
    {
        /** @var SystemAssetService $assetService */
        $assetService = app(SystemAssetService::class);
        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        // Aset resmi Brand A
        $fileA = UploadedFile::fake()->image('brand_a_logo.png', 100, 100);
        $assetLogoA = $assetService->storeAsset($fileA, [
            'name'       => 'Logo Brand A',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_BRAND,
            'brand_id'   => $this->brandA->id,
            'is_default' => true,
        ], $this->admin);

        // Aset resmi Brand B
        $fileB = UploadedFile::fake()->image('brand_b_logo.png', 100, 100);
        $assetLogoB = $assetService->storeAsset($fileB, [
            'name'       => 'Logo Brand B',
            'category'   => SystemAsset::CATEGORY_LOGO,
            'scope'      => SystemAsset::SCOPE_BRAND,
            'brand_id'   => $this->brandB->id,
            'is_default' => true,
        ], $this->admin);

        // TTD resmi Brand B
        $fileSigB = UploadedFile::fake()->image('sig_b.png', 100, 100);
        $sigAssetB = $assetService->storeAsset($fileSigB, [
            'name'       => 'TTD Brand B',
            'category'   => SystemAsset::CATEGORY_SIGNATURE,
            'scope'      => SystemAsset::SCOPE_BRAND,
            'brand_id'   => $this->brandB->id,
            'is_default' => true,
        ], $this->admin);

        // 1. Skenario Serangan: Dokumen Brand A mencoba disusupi ID Aset Brand B via asset_overrides
        $maliciousOverrides = [
            'asset.brand.logo'         => $assetLogoB->id, // Forged Brand B logo
            'asset.brand.tanda_tangan' => $sigAssetB->id,   // Forged Brand B signature
        ];

        $resolved = $resolver->resolveContext(
            brand: $this->brandA,
            assetOverrides: $maliciousOverrides,
            isLiveIssuance: true
        );

        // Logo Brand B HARUS DITOLAK karena pemiliknya bukan Brand A! Fallback ke Logo resmi Brand A
        $this->assertEquals($assetLogoA->id, $resolved['asset_references']['asset.brand.logo']['asset_id']);
        // TTD Brand B HARUS DITOLAK dan hasilnya NULL (tidak boleh bocor!)
        $this->assertNull($resolved['variables']['asset.brand.tanda_tangan'] ?? null);
        $this->assertContains('asset.brand.tanda_tangan', $resolved['missing_assets']);

        // 2. Skenario Serangan Kategori: Mencoba memasukkan ID Logo ke slot Tanda Tangan
        $categorySpoofOverrides = [
            'asset.brand.tanda_tangan' => $assetLogoA->id, // ID valid Brand A, tapi kategorinya LOGO bukan SIGNATURE
        ];

        $resolvedSpoof = $resolver->resolveContext(
            brand: $this->brandA,
            assetOverrides: $categorySpoofOverrides,
            isLiveIssuance: true
        );

        $this->assertNull($resolvedSpoof['variables']['asset.brand.tanda_tangan'] ?? null);

        // 3. Skenario Aset Non-Aktif / Diarsipkan
        $assetLogoA->update(['is_active' => false]);
        $inactiveOverrides = [
            'asset.brand.logo' => $assetLogoA->id,
        ];
        $resolvedInactive = $resolver->resolveContext(
            brand: $this->brandA,
            assetOverrides: $inactiveOverrides,
            isLiveIssuance: true
        );
        $this->assertNull($resolvedInactive['variables']['asset.brand.logo'] ?? null);
    }

    /**
     * V03: Penghapusan Mock dari Penerbitan Resmi (tidak ada 85.0 / Sangat Baik jika belum dinilai).
     */
    public function test_v03_no_mock_in_official_issuance(): void
    {
        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);

        // Pemagang belum dinilai sama sekali
        $context = $issuanceService->buildContext(
            intern: $this->intern,
            documentType: 'assessment',
            options: [],
            isLiveIssuance: true
        );

        // Pada live issuance, nilai dan predikat HARUS null jika belum dinilai secara nyata (bukan 85.0)
        $this->assertNull($context['variables']['nilai.rata_rata']);
        $this->assertNull($context['variables']['nilai.predikat']);
        $this->assertNull($context['variables']['penilaian.nilai_akhir']);
        $this->assertNull($context['variables']['penilaian.predikat']);

        // Pada preview mode, mock data diperbolehkan untuk visualisasi
        $previewContext = $issuanceService->buildContext(
            intern: $this->intern,
            documentType: 'assessment',
            options: [],
            isLiveIssuance: false
        );
        $this->assertEquals('85.0', $previewContext['variables']['nilai.rata_rata']);
        $this->assertEquals('Sangat Baik (A)', $previewContext['variables']['nilai.predikat']);
    }

    /**
     * V04: Diagnostik variabel wajib menolak penerbitan resmi jika ada variabel yang kurang.
     */
    public function test_v04_diagnose_missing_variables_blocks_official_issuance(): void
    {
        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);

        // Buat template surat yang memuat token wajib
        $template = \App\Models\DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'Template LOA Validasi Wajib ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Penerbitan LOA untuk {{pemagang.nama}} dengan nomor {{dokumen.nomor}} dan ttd {{dokumen.penandatangan_nama}}.</p>',
            'is_active'      => true,
            'is_default'     => true,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        // Hapus signatory_name dari brandA sehingga variabel wajib dokumen.penandatangan_nama menjadi kosong
        $this->brandA->update(['signatory_name' => null]);
        $this->intern->refresh();

        // Penerbitan resmi HARUS GAGAL dengan InvalidArgumentException menyebutkan variabel wajib yang kurang!
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Penerbitan dokumen loa ditolak: Variabel wajib belum lengkap');

        $issuanceService->issueDocument(
            intern: $this->intern,
            documentType: 'loa',
            options: ['template_id' => $template->id],
            actor: $this->admin
        );
    }

    /**
     * V05: Kompatibilitas Legacy Placeholder dan Immutability Dokumen Terbit.
     */
    public function test_v05_legacy_placeholders_compatibility_and_immutability(): void
    {
        // 1. Format DocumentTemplate::getDefaultPlaceholders()
        $docPlaceholders = \App\Models\DocumentTemplate::getDefaultPlaceholders();
        $this->assertIsArray($docPlaceholders);
        $this->assertArrayHasKey('{{pemagang.nama}}', $docPlaceholders);
        $this->assertArrayHasKey('label', $docPlaceholders['{{pemagang.nama}}']);
        $this->assertArrayHasKey('group', $docPlaceholders['{{pemagang.nama}}']);
        $this->assertArrayHasKey('example', $docPlaceholders['{{pemagang.nama}}']);

        // 2. Format MouTemplate::getDefaultPlaceholders()
        $mouPlaceholders = \App\Models\MouTemplate::getDefaultPlaceholders();
        $this->assertIsArray($mouPlaceholders);
        $this->assertArrayHasKey('institution_name', $mouPlaceholders);
        $this->assertIsString($mouPlaceholders['institution_name']);

        // 3. Immutability dokumen terbit
        /** @var \App\Services\DocumentPublishingService $publishingService */
        $publishingService = app(\App\Services\DocumentPublishingService::class);

        $firstDoc = $publishingService->publishInternDocument(
            intern: $this->intern,
            documentType: 'loa',
            htmlContent: '<html><body>Test Surat LOA Asli</body></html>',
            variableSnapshot: ['test' => 'initial'],
            actor: $this->admin
        );

        $this->assertNotNull($firstDoc->id);
        $this->assertContains($firstDoc->status, [
            \App\Models\GeneratedDocument::STATUS_GENERATED,
            \App\Models\GeneratedDocument::STATUS_SYNC_PENDING,
            \App\Models\GeneratedDocument::STATUS_SYNCED,
        ]);

        // Percobaan menerbitkan ulang dokumen yang sama (idempotent) tidak boleh menimpa file
        $secondDoc = $publishingService->publishInternDocument(
            intern: $this->intern,
            documentType: 'loa',
            htmlContent: '<html><body>Percobaan Timpa Dokumen</body></html>',
            variableSnapshot: ['test' => 'attempted_overwrite'],
            actor: $this->admin
        );

        $this->assertEquals($firstDoc->id, $secondDoc->id);
        $this->assertEquals('initial', $secondDoc->variable_snapshot['test']);
    }

    /**
     * V06: DocumentContext DTO authoring mode (0 DB queries & entity isolation).
     */
    public function test_v06_document_context_dto_authoring_mode(): void
    {
        $context = \App\Services\MailMerge\DocumentContext::forAuthoring('certificate');

        $this->assertTrue($context->isAuthoring());
        $this->assertNull($context->internshipRegistrationId);
        $this->assertNull($context->institutionId);
        $this->assertNull($context->brandId);
        $this->assertNull($context->mouId);
        $this->assertFalse($context->isLiveIssuance);
        $this->assertTrue($context->isPreviewMock);

        // ArrayAccess compatibility
        $this->assertNotNull($context['variables']);
        $this->assertNotNull($context['asset_references']);
        $this->assertArrayHasKey('pemagang.nama', $context['variables']);

        // JSON serialization
        $json = json_encode($context);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertEquals('certificate', $decoded['document_type']);
        $this->assertNull($decoded['internship_registration_id']);
    }

    /**
     * FG-01: Validasi penerbitan MoU resmi menolak identitas/penandatangan yang hilang tanpa fallback palsu.
     */
    public function test_fg01_mou_official_issuance_rejects_missing_signatory_and_identity(): void
    {
        /** @var \App\Services\MouService $mouService */
        $mouService = app(\App\Services\MouService::class);

        // Institusi tanpa kontak penandatangan
        $bareInstitution = Institution::create([
            'name'      => 'Kampus Tanpa Kontak ' . $this->unique,
            'address'   => 'Alamat Mitra',
            'phone'     => '0274-123456',
            'email'     => 'bare_' . $this->unique . '@test.com',
            'is_active' => true,
        ]);

        $cycleContact = InstitutionContact::create([
            'institution_id' => $bareInstitution->id,
            'name'           => 'Staf Humas',
            'phone'          => '081234567890',
            'is_active'      => true,
        ]);

        $cycle = \App\Models\FollowUpCycle::create([
            'institution_id'         => $bareInstitution->id,
            'institution_contact_id' => $cycleContact->id,
            'cycle_number'           => 1,
            'started_at'             => now(),
            'status'                 => \App\Models\FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => \App\Models\FollowUpCycle::RESULT_WILLING,
            'completed_at'           => now(),
            'created_by'             => $this->admin->id,
        ]);

        $template = \App\Models\MouTemplate::create([
            'name'                  => 'Template MOU Resmi ' . $this->unique,
            'template_html'         => '<p>Perjanjian antara [company_name] dan [institution_name] ditandatangani oleh [institution_signatory_name].</p>',
            'version_number'        => 1,
            'is_active'             => true,
            'allowed_placeholders'  => array_keys(\App\Models\MouTemplate::getDefaultPlaceholders()),
            'company_default_values'=> \App\Models\MouTemplate::getDefaultCompanyValues(),
            'created_by'            => $this->admin->id,
        ]);

        // Hapus kontak agar penandatangan kosong, atau kirim first_party_signatory_name kosong
        $cycleContact->delete();

        // 1. Skenario Negatif: Penandatangan pihak kesatu tidak ada sama sekali
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nama Penandatangan Institusi Mitra (first_party_signatory_name)');

        $mouService->generateMou([
            'institution_id'             => $bareInstitution->id,
            'followup_cycle_id'          => $cycle->id,
            'mou_template_id'            => $template->id,
            'company_mou_number'         => 'MOU/7INC/FG01/' . substr($this->unique, 0, 8),
            'cooperation_confirmed'      => true,
            'first_party_signatory_name' => '', // Sengaja dikosongkan
        ], $this->admin);
    }

    /**
     * FG-02: Placeholder salah ketik / tidak terdaftar ditolak pada penerbitan resmi, tapi dipertahankan di editor/draft.
     */
    public function test_fg02_unknown_placeholders_rejected_on_official_issuance_but_preserved_in_editor(): void
    {
        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
        /** @var VariableResolverService $resolver */
        $resolver = app(VariableResolverService::class);

        // 1. Buat template dengan placeholder salah ketik {{pemagang.nma}} dan token asing {{token_palsu}}
        $invalidTemplate = \App\Models\DocumentTemplate::create([
            'type'           => 'loa',
            'name'           => 'Template LOA Typo ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Nama: {{pemagang.nma}}, Token: {{token_palsu}}, Nomor: {{dokumen.nomor}}</p>',
            'is_active'      => true,
            'is_default'     => true,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        // 2. Editor / Draft: renderContent harus mempertahankan placeholder tanpa menghapusnya
        $context = $resolver->resolveContext(
            intern: $this->intern,
            institution: $this->institutionX,
            brand: $this->brandA,
            documentType: 'loa'
        );
        $renderedDraft = $resolver->renderContent($invalidTemplate->content_html, $context, isLiveIssuance: false, documentType: 'loa');
        $this->assertStringContainsString('{{pemagang.nma}}', $renderedDraft);
        $this->assertStringContainsString('{{token_palsu}}', $renderedDraft);

        // 3. Penerbitan Resmi: Wajib menolak token salah ketik dengan pesan kesalahan spesifik
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ditemukan placeholder tidak dikenal atau salah ketik: pemagang.nma, token_palsu');

        $issuanceService->issueDocument(
            intern: $this->intern,
            documentType: 'loa',
            options: ['template_id' => $invalidTemplate->id],
            actor: $this->admin
        );
    }

    /**
     * FG-03: Snapshot dokumen terbit bersifat immutable dan retry penerbitan bersifat idempotent.
     */
    public function test_fg03_published_document_snapshot_immutability_and_retry_idempotence(): void
    {
        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);

        // 1. Buat template valid
        $template = \App\Models\DocumentTemplate::create([
            'type'           => 'skl',
            'name'           => 'Template SKL Asli ' . $this->unique,
            'editor_type'    => 'visual_doc',
            'content_html'   => '<p>Surat Keterangan Lulus untuk {{pemagang.nama}} divisi {{pemagang.divisi}} nomor {{dokumen.nomor}}.</p>',
            'is_active'      => true,
            'is_default'     => true,
            'version_number' => 1,
            'created_by'     => $this->admin->id,
        ]);

        // 2. Terbitkan dokumen
        $doc = $issuanceService->issueDocument(
            intern: $this->intern,
            documentType: 'skl',
            options: ['template_id' => $template->id],
            actor: $this->admin
        );

        $initialId = $doc->id;
        $initialDocNumber = $doc->document_number;
        $initialChecksum = $doc->checksum_sha256;
        $initialInternNameInSnapshot = $doc->variable_snapshot['pemagang.nama'];
        $initialFilePath = Storage::disk('local')->path($doc->file_path);
        $initialFileBinary = file_get_contents($initialFilePath);

        $this->assertNotEmpty($initialChecksum);
        $this->assertEquals($initialChecksum, hash('sha256', $initialFileBinary));

        // 3. Mutasi eksternal setelah dokumen terbit:
        // - Ubah template HTML
        $template->update(['content_html' => '<p>TEMPLATE DIUBAH SECARA DRASTIS SETELAH TERBIT</p>']);
        // - Ubah data pemagang
        $this->intern->update(['fullname' => 'Nama Pemagang Telah Diganti Total']);
        // - Ubah brand
        $this->brandA->update(['name' => 'Brand Berubah Nama']);

        // 4. Verifikasi Immutability:
        // Dokumen terbit harus sama persis seperti saat diterbitkan
        $docFresh = $doc->fresh();
        $this->assertEquals($initialChecksum, $docFresh->checksum_sha256);
        $this->assertEquals($initialDocNumber, $docFresh->document_number);
        $this->assertEquals($initialInternNameInSnapshot, $docFresh->variable_snapshot['pemagang.nama']);
        $this->assertEquals($initialChecksum, hash_file('sha256', Storage::disk('local')->path($docFresh->file_path)));

        // 5. Verifikasi Idempotensi Retry:
        // Memanggil issueDocument() kembali tanpa opsi revisi HARUS mengembalikan dokumen existing yang sama tanpa alokasi nomor baru
        $retriedDoc = $issuanceService->issueDocument(
            intern: $this->intern,
            documentType: 'skl',
            options: ['template_id' => $template->id],
            actor: $this->admin
        );

        $this->assertEquals($initialId, $retriedDoc->id);
        $this->assertEquals($initialDocNumber, $retriedDoc->document_number);
        $this->assertEquals($initialChecksum, $retriedDoc->checksum_sha256);
    }
}
