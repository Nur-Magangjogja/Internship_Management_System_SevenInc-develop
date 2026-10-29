<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Division;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Institution;
use App\Models\InternAssessment;
use App\Models\InternCertificate;
use App\Models\InternExtra;
use App\Models\InternLoa;
use App\Models\InternshipRegistration as IR;
use App\Models\SklDocument;
use App\Models\SystemAsset;
use App\Models\User;
use App\Services\InternDocumentIssuanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class InternDocumentIssuanceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $pemagangUser;
    private Brand $brand;
    private Division $division;
    private Institution $institution;
    private string $unique;

    public function createApplication(): \Illuminate\Foundation\Application
    {
        $worktreePath = realpath(dirname(__DIR__, 2));
        $_ENV['APP_BASE_PATH'] = $worktreePath;
        putenv("APP_BASE_PATH={$worktreePath}");

        $loader = require $worktreePath . '/vendor/autoload.php';
        $loader->setPsr4('App\\', [$worktreePath . '/app'], true);
        $loader->setPsr4('Tests\\', [$worktreePath . '/tests'], true);

        $app = require $worktreePath . '/bootstrap/app.php';
        $app->setBasePath($worktreePath);
        $app->register(\App\Providers\DocumentTemplateServiceProvider::class);
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $app['view']->addLocation($worktreePath . '/resources/views');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        $this->unique = uniqid('issuance_', true);

        $this->admin = User::create([
            'name'     => 'Admin ' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->pemagangUser = User::create([
            'name'     => 'Pemagang ' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->brand = Brand::where('code', 'SI')->first() ?? new Brand();
        $this->brand->fill([
            'name'               => 'Seven Inc ' . substr($this->unique, 0, 6),
            'code'               => 'SI',
            'company_address'    => 'Jl. Raya Janti No. 59, Yogyakarta',
            'signatory_name'     => 'Rekario Danny Sanjaya, S.Kom.',
            'signatory_position' => 'Pimpinan',
        ]);
        $this->brand->save();

        $this->division = Division::firstOrCreate(
            ['code' => 'DEV'],
            ['name' => 'Software Engineering', 'slug' => 'software-engineering']
        );

        $this->institution = Institution::create([
            'name'    => 'Universitas Teknologi ' . substr($this->unique, 0, 6),
            'address' => 'Yogyakarta',
        ]);

        $this->faculty = \App\Models\Faculty::firstOrCreate(
            ['name' => 'Fakultas Teknik ' . substr($this->unique, 0, 6)],
            ['institution_id' => $this->institution->id]
        );

        $this->studyProgram = \App\Models\StudyProgram::firstOrCreate(
            ['name' => 'Informatika ' . substr($this->unique, 0, 6)],
            ['faculty_id' => $this->faculty->id]
        );

        // Inisialisasi template standar sistem pada database agar penerbitan resmi menggunakan template nyata dari DB
        DocumentTemplate::seedStandardDefaultTemplates($this->admin->id);
    }

    private function createIntern(string $status = IR::STATUS_ACCEPTED): IR
    {
        return IR::create([
            'user_id'             => $this->pemagangUser->id,
            'fullname'            => 'Budi Pratama ' . substr($this->unique, 0, 6),
            'student_id'          => '12345678',
            'institution_id'      => $this->institution->id,
            'institution_name'    => $this->institution->name,
            'faculty_id'          => $this->faculty->id,
            'study_program_id'    => $this->studyProgram->id,
            'brand_id'            => $this->brand->id,
            'brand'               => $this->brand->name,
            'division_id'         => $this->division->id,
            'internship_interest' => $this->division->name,
            'study_program'       => 'Informatika',
            'phone_number'        => '081234567890',
            'gender'              => 'Laki-laki',
            'born_date'           => '2002-01-01',
            'birth_place'         => 'Yogyakarta',
            'religion'            => 'Islam',
            'start_date'          => '2026-01-01',
            'end_date'            => '2026-04-01',
            'internship_status'   => $status,
        ]);
    }

    /**
     * 1. Penerbitan LOA untuk pemagang berstatus accepted/active.
     */
    public function test_issuance_service_issues_loa_and_persists_generated_document_and_syncs_legacy()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        $doc = $service->issueDocument(
            intern: $intern,
            documentType: 'loa',
            options: [],
            actor: $this->admin
        );

        $this->assertInstanceOf(GeneratedDocument::class, $doc);
        $this->assertEquals(GeneratedDocument::TYPE_LOA, $doc->document_type);
        $this->assertEquals($intern->id, $doc->internship_registration_id);
        $this->assertNotEmpty($doc->document_number);
        $this->assertNotEmpty($doc->checksum_sha256);
        $this->assertGreaterThan(0, $doc->file_size_bytes);
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));

        // Verifikasi snapshot variabel
        $snapshot = $doc->variable_snapshot;
        $this->assertIsArray($snapshot);
        $this->assertEquals($intern->fullname, $snapshot['pemagang.nama']);
        $this->assertEquals($this->brand->name, $snapshot['perusahaan.nama']);

        // Verifikasi sinkronisasi ke tabel legacy intern_loas
        $legacy = InternLoa::where('intern_id', $intern->id)->first();
        $this->assertNotNull($legacy);
        $this->assertEquals($doc->document_number, $legacy->loa_number);
    }

    /**
     * 2. Penerbitan SKL, Sertifikat, Penilaian, dan Rekomendasi untuk pemagang completed.
     */
    public function test_issuance_service_issues_completed_documents_and_syncs_all_legacy_tables()
    {
        $intern = $this->createIntern(IR::STATUS_COMPLETED);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        // A. SKL
        $sklDoc = $service->issueDocument($intern, 'skl', [], $this->admin);
        $this->assertEquals(GeneratedDocument::TYPE_SKL, $sklDoc->document_type);
        $this->assertTrue(Storage::disk('local')->exists($sklDoc->file_path));
        $sklLegacy = SklDocument::where('intern_id', $intern->id)->first();
        $this->assertNotNull($sklLegacy);
        $this->assertEquals($sklDoc->document_number, $sklLegacy->skl_number);

        // B. Sertifikat
        $certDoc = $service->issueDocument($intern, 'certificate', [], $this->admin);
        $this->assertEquals(GeneratedDocument::TYPE_CERTIFICATE, $certDoc->document_type);
        $this->assertTrue(Storage::disk('local')->exists($certDoc->file_path));
        $certLegacy = InternCertificate::where('intern_id', $intern->id)->first();
        $this->assertNotNull($certLegacy);
        $this->assertEquals($certDoc->document_number, $certLegacy->certificate_number);

        // C. Surat Penilaian
        $assessmentDoc = $service->issueDocument($intern, 'assessment', ['rata_rata' => 92.5], $this->admin);
        $this->assertEquals(GeneratedDocument::TYPE_ASSESSMENT, $assessmentDoc->document_type);
        $this->assertTrue(Storage::disk('local')->exists($assessmentDoc->file_path));
        $assessmentLegacy = InternAssessment::where('intern_id', $intern->id)->first();
        $this->assertNotNull($assessmentLegacy);
        $this->assertEquals($assessmentDoc->document_number, $assessmentLegacy->assessment_number);

        // D. Surat Rekomendasi
        $recDoc = $service->issueDocument($intern, 'recommendation', [], $this->admin);
        $this->assertEquals(GeneratedDocument::TYPE_RECOMMENDATION, $recDoc->document_type);
        $this->assertTrue(Storage::disk('local')->exists($recDoc->file_path));
        $recLegacy = InternExtra::where('intern_id', $intern->id)->first();
        $this->assertNotNull($recLegacy);
        $this->assertNotEmpty($recLegacy->rekomendasi_path);
    }

    /**
     * 3. Validasi status pemagang: menolak status yang belum memenuhi syarat.
     */
    public function test_status_validation_rejects_unauthorized_statuses_before_issuance()
    {
        $waitingIntern = $this->createIntern(IR::STATUS_WAITING);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        $this->assertFalse($service->canIssue($waitingIntern, 'loa'));
        $this->assertFalse($service->canIssue($waitingIntern, 'skl'));

        $this->expectException(InvalidArgumentException::class);
        $service->issueDocument($waitingIntern, 'loa', [], $this->admin);
    }

    public function test_accepted_intern_cannot_issue_completed_documents()
    {
        $acceptedIntern = $this->createIntern(IR::STATUS_ACCEPTED);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        $this->assertTrue($service->canIssue($acceptedIntern, 'loa'));
        $this->assertFalse($service->canIssue($acceptedIntern, 'skl'));
        $this->assertFalse($service->canIssue($acceptedIntern, 'certificate'));

        $this->expectException(InvalidArgumentException::class);
        $service->issueDocument($acceptedIntern, 'skl', [], $this->admin);
    }

    /**
     * 4. Isolasi Preview: Preview dokumen TIDAK BOLEH menulis ke GeneratedDocument atau Cloud Storage.
     */
    public function test_preview_document_guarantees_zero_db_persistence_and_no_cloud_sync()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        $initialDocCount = GeneratedDocument::count();

        $preview = $service->previewDocument($intern, 'loa');

        $this->assertIsArray($preview);
        $this->assertNotEmpty($preview['html']);
        $this->assertStringContainsString($intern->fullname, $preview['html']);

        // JAMINAN ISOLASI: Jumlah rekam GeneratedDocument tidak berubah
        $this->assertEquals($initialDocCount, GeneratedDocument::count());
    }

    /**
     * 5. Idempotensi penerbitan dan retry sync tanpa duplikasi record.
     */
    public function test_issuance_is_idempotent_and_retry_sync_is_safe()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        $doc1 = $service->issueDocument($intern, 'loa', [], $this->admin);
        $doc2 = $service->issueDocument($intern, 'loa', ['document_number' => $doc1->document_number], $this->admin);

        // Harus mengembalikan entitas yang sama tanpa duplikasi
        $this->assertEquals($doc1->id, $doc2->id);
        $this->assertEquals($doc1->public_uuid, $doc2->public_uuid);

        // Uji retry sync
        $retryResult = $service->retryDocumentSync($doc1, $this->admin);
        $this->assertIsArray($retryResult);
        $this->assertArrayHasKey('file_id', $retryResult);

        // Jumlah GeneratedDocument untuk intern ini tetap 1
        $count = GeneratedDocument::where('internship_registration_id', $intern->id)
            ->where('document_type', 'loa')
            ->count();
        $this->assertEquals(1, $count);
    }

    /**
     * 6. GenerateDocController: request POST menerbitkan dokumen langsung tanpa GET redirect.
     */
    public function test_generate_doc_controller_post_endpoint_issues_document_directly()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.interns.generate.doc'), [
                'intern_id'   => $intern->id,
                'jenis_surat' => 'loa',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
        ]);

        $data = $response->json();
        $this->assertNotNull($data['document']['number']);
        $this->assertNotNull($data['download_url']);
        $this->assertNull($data['redirect_url'] ?? null);

        // Verifikasi dokumen fisik dapat diunduh via download_url
        $downloadRes = $this->actingAs($this->admin)->get($data['download_url']);
        $downloadRes->assertStatus(200);
        $this->assertEquals('application/pdf', $downloadRes->headers->get('content-type'));
    }

    /**
     * 7. GenerateDocController: request preview mengembalikan HTML terisolasi.
     */
    public function test_generate_doc_controller_preview_action_returns_isolated_html()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);
        $initialCount = GeneratedDocument::count();

        $response = $this->actingAs($this->admin)
            ->postJson(route('admin.interns.generate.doc'), [
                'intern_id'   => $intern->id,
                'jenis_surat' => 'loa',
                'action'      => 'preview',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'ok'     => true,
            'action' => 'preview',
        ]);

        $this->assertEquals($initialCount, GeneratedDocument::count());
    }

    /**
     * 8. RBAC: Pemagang dan tamu tidak diizinkan memanggil endpoint penerbitan dokumen admin.
     */
    public function test_rbac_protects_document_issuance_endpoints()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        // Tamu (Unauthenticated) diarahkan ke login
        $guestResponse = $this->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $intern->id,
            'jenis_surat' => 'loa',
        ]);
        $guestResponse->assertStatus(401);

        // Pemagang diblokir (403)
        $pemagangResponse = $this->actingAs($this->pemagangUser)
            ->postJson(route('admin.interns.generate.doc'), [
                'intern_id'   => $intern->id,
                'jenis_surat' => 'loa',
            ]);
        $pemagangResponse->assertStatus(403);
    }

    /**
     * 9. Idempotensi penerbitan berulang tanpa custom document_number tidak menambah sequence nomor dokumen.
     */
    public function test_repeated_issuance_without_options_reuses_exact_number_and_idempotent_record()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        // Panggilan pertama tanpa options
        $doc1 = $service->issueDocument($intern, 'loa', [], $this->admin);
        $number1 = $doc1->document_number;

        // Panggilan kedua tanpa options (simulasi double click)
        $doc2 = $service->issueDocument($intern, 'loa', [], $this->admin);
        $number2 = $doc2->document_number;

        $this->assertEquals($doc1->id, $doc2->id);
        $this->assertEquals($number1, $number2);

        // Pastikan hanya 1 record GeneratedDocument dan 1 InternLoa yang tercipta
        $this->assertEquals(1, GeneratedDocument::where('internship_registration_id', $intern->id)->where('document_type', 'loa')->count());
        $this->assertEquals(1, InternLoa::where('intern_id', $intern->id)->count());
    }

    /**
     * 10. Folder Google Drive: drive_folder_id tersimpan permanen dan digunakan kembali pada sync berikutnya.
     */
    public function test_drive_folder_id_is_recorded_and_reused_without_rebuilding_path()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        $doc = $service->issueDocument($intern, 'loa', [], $this->admin);

        // Verifikasi drive_folder_id terisi permanen di GeneratedDocument
        $this->assertNotNull($doc->drive_folder_id);
        $savedFolderId = $doc->drive_folder_id;

        // Buat mock upload driver yang memverifikasi folder target menggunakan folder ID yang sudah tercatat
        $mockDriver = $this->createMock(\App\Services\CloudStorage\CloudStorageInterface::class);
        $mockDriver->method('isConfigured')->willReturn(true);
        $mockDriver->method('getDriverName')->willReturn('google_drive');
        $mockDriver->expects($this->once())
            ->method('uploadFile')
            ->with(
                $this->anything(),
                $this->equalTo($savedFolderId), // Harus menggunakan folder ID langsung!
                $this->anything()
            )
            ->willReturn([
                'file_id'       => 'DRIVE-FILE-REUSED-001',
                'web_view_link' => 'https://drive.google.com/file/d/DRIVE-FILE-REUSED-001/view',
                'file_name'     => $doc->file_name,
                'folder_id'     => $savedFolderId,
                'driver'        => 'google_drive',
            ]);

        $publishingService = new \App\Services\DocumentPublishingService(
            app(\App\Services\CloudStorage\SystemStorageConfigService::class),
            app(\App\Services\DocumentGeneratorService::class),
            app(\App\Services\MailMerge\VariableResolverService::class),
            $mockDriver
        );

        $publishingService->syncDocumentToDrive($doc, $this->admin);

        $freshDoc = $doc->fresh();
        $this->assertEquals($savedFolderId, $freshDoc->drive_folder_id);
        $this->assertEquals('DRIVE-FILE-REUSED-001', $freshDoc->drive_file_id);
        $this->assertEquals(GeneratedDocument::STATUS_SYNCED, $freshDoc->status);
    }

    /**
     * 11. Keamanan dokumen: validasi kepemilikan pemagang dan pembatasan retry-sync.
     */
    public function test_security_ownership_restricts_pemagang_and_blocks_unauthorized_retry_sync()
    {
        $internOwner = $this->createIntern(IR::STATUS_ACCEPTED, $this->pemagangUser);

        // Buat pemagang lain (bukan pemilik)
        $otherUser = User::create([
            'name'     => 'Other Intern',
            'email'    => 'other_' . uniqid() . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);
        $doc = $service->issueDocument($internOwner, 'loa', [], $this->admin);

        // Pemilik dapat mengunduh dokumen miliknya sendiri
        $ownerDownload = $this->actingAs($this->pemagangUser)
            ->get(route('admin.generated-documents.download', $doc->public_uuid));
        $ownerDownload->assertStatus(200);

        // Pemilik dapat melihat preview dokumen miliknya sendiri
        $ownerPreview = $this->actingAs($this->pemagangUser)
            ->get(route('admin.generated-documents.preview', $doc->public_uuid));
        $ownerPreview->assertStatus(200);

        // Pemilik dapat melihat status dokumen miliknya sendiri
        $ownerStatus = $this->actingAs($this->pemagangUser)
            ->get(route('admin.generated-documents.status', $doc->public_uuid));
        $ownerStatus->assertStatus(200);

        // Pengguna lain (pemagang lain) DIBLOKIR (403) saat mengakses download
        $unauthorizedDownload = $this->actingAs($otherUser)
            ->get(route('admin.generated-documents.download', $doc->public_uuid));
        $unauthorizedDownload->assertStatus(403);

        // Pengguna lain DIBLOKIR (403) saat mengakses preview
        $unauthorizedPreview = $this->actingAs($otherUser)
            ->get(route('admin.generated-documents.preview', $doc->public_uuid));
        $unauthorizedPreview->assertStatus(403);

        // Pemagang DIBLOKIR (403) dari endpoint retry-sync
        $pemagangRetry = $this->actingAs($this->pemagangUser)
            ->postJson(route('admin.generated-documents.retry-sync', $doc->public_uuid));
        $pemagangRetry->assertStatus(403);

        // Admin diizinkan melakukan retry-sync
        $adminRetry = $this->actingAs($this->admin)
            ->postJson(route('admin.generated-documents.retry-sync', $doc->public_uuid));
        $adminRetry->assertStatus(200);
        $adminRetry->assertJson(['success' => true]);
    }

    /**
     * 12. Kompatibilitas historis: dokumen yang sudah memiliki data historis tidak berubah nomor atau timestamp-nya.
     */
    public function test_historical_records_and_numbers_are_preserved_without_corruption()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        $historicDate = now()->subMonths(6);
        $historicNumber = 'LEGACY/LOA/999/HISTORIC';

        // Buat record historis lama di intern_loas
        $legacyLoa = InternLoa::create([
            'intern_id'            => $intern->id,
            'loa_number'           => $historicNumber,
            'accepted_start_date'  => $intern->start_date,
            'accepted_end_date'    => $intern->end_date,
            'company_name'         => 'Seven Inc Historic',
            'company_logo_path'    => 'logos/historic.png',
            'signatory_name'       => 'Historic Signatory',
            'signatory_position'   => 'Direktur',
            'created_at'           => $historicDate,
        ]);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        // Terbitkan LOA
        $doc = $service->issueDocument($intern, 'loa', [], $this->admin);

        // Nomor dokumen harus tetap sama dengan nomor historis
        $this->assertEquals($historicNumber, $doc->document_number);

        // Record legacy tidak terduplikasi
        $this->assertEquals(1, InternLoa::where('intern_id', $intern->id)->count());

        $freshLegacy = $legacyLoa->fresh();
        // Nomor tidak berubah
        $this->assertEquals($historicNumber, $freshLegacy->loa_number);

        // Verifikasi relasi Eloquent
        $this->assertNotNull($doc->internLoa);
        $this->assertEquals($legacyLoa->id, $doc->internLoa->id);

        $this->assertNotNull($freshLegacy->generatedDocument);
        $this->assertEquals($doc->id, $freshLegacy->generatedDocument->id);

        $this->assertTrue($intern->generatedDocuments()->exists());
    }

    /**
     * 14. Folder Drive Pemagang: Dokumen pertama menggunakan folder ID pendaftar yang sudah ada
     * dari proses registrasi tanpa membuat folder pemagang paralel.
     */
    public function test_first_issuance_reuses_registration_drive_folder_without_creating_parallel_folder()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        // Simulasi folder Drive pendaftar yang sudah dibentuk saat proses registrasi/sinkronisasi spreadsheet
        $regFolderId = '1folder_registration_abc123';
        \App\Models\RegistrationAttachment::create([
            'internship_registration_id' => $intern->id,
            'attachment_type'            => \App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE,
            'category'                   => \App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE,
            'file_path'                  => 'drive_root:default',
            'original_name'              => 'Folder_Budi_Pratama_REG' . $intern->id,
            'file_size'                  => 0,
            'mime_type'                  => 'application/vnd.google-apps.folder',
            'drive_file_id'              => $regFolderId,
            'drive_web_view_link'        => "https://drive.google.com/drive/folders/{$regFolderId}",
            'drive_sync_status'          => \App\Models\RegistrationAttachment::STATUS_SYNCED,
        ]);

        $this->assertEquals($regFolderId, $intern->resolveDriveFolderId());

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        // Terbitkan dokumen pertama (LOA)
        $doc = $service->issueDocument($intern, 'loa', [], $this->admin);

        $this->assertNotNull($doc);
        $this->assertEquals(GeneratedDocument::TYPE_LOA, $doc->document_type);
        // Memastikan tidak ada attachment folder kedua (paralel) yang dibuat
        $folderCount = $intern->attachments()
            ->where(function ($q) {
                $q->where('mime_type', 'application/vnd.google-apps.folder')
                  ->orWhere('attachment_type', \App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE);
            })->count();
        $this->assertEquals(1, $folderCount);
    }

    /**
     * 15. Idempotensi vs Revisi Resmi: double-click dan retry tidak membuat duplikat,
     * tetapi penerbitan revisi resmi menghasilkan snapshot, file PDF, dan versi baru tanpa merusak histori.
     */
    public function test_idempotency_vs_official_revision_preserves_history_and_creates_new_version()
    {
        $intern = $this->createIntern(IR::STATUS_COMPLETED);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        // Penerbitan awal (Versi 1)
        $v1 = $service->issueDocument($intern, 'skl', [], $this->admin);
        $this->assertEquals(1, $v1->template_version);
        $this->assertTrue(Storage::disk('local')->exists($v1->file_path));
        $v1Path = $v1->file_path;
        $v1Checksum = $v1->checksum_sha256;

        // Double-click / retry penerbitan biasa: IDEMPOTEN, mengembalikan record yang sama
        $v1Duplicate = $service->issueDocument($intern, 'skl', [], $this->admin);
        $this->assertEquals($v1->id, $v1Duplicate->id);
        $this->assertEquals(1, GeneratedDocument::where('internship_registration_id', $intern->id)->where('document_type', 'skl')->count());

        // Routine retry sync: TIDAK membuat ulang dokumen atau meregenerasi file
        $retryResult = $service->retryDocumentSync($v1, $this->admin);
        $this->assertNotEmpty($retryResult);
        $this->assertEquals(1, GeneratedDocument::where('internship_registration_id', $intern->id)->where('document_type', 'skl')->count());
        $this->assertEquals($v1Path, $v1->fresh()->file_path);

        // Penerbitan Revisi Resmi (Versi 2)
        $v2 = $service->issueDocument($intern, 'skl', ['is_revision' => true], $this->admin);
        $this->assertNotEquals($v1->id, $v2->id);
        $this->assertEquals(2, $v2->template_version);
        $this->assertNotEquals($v1->file_path, $v2->file_path);
        $this->assertStringContainsString('_v2_', $v2->file_name);
        $this->assertTrue(Storage::disk('local')->exists($v2->file_path));

        // Verifikasi dokumen historis (v1) tetap utuh di disk dan database
        $this->assertTrue(Storage::disk('local')->exists($v1Path));
        $this->assertEquals($v1Checksum, $v1->fresh()->checksum_sha256);
        $this->assertEquals(2, GeneratedDocument::where('internship_registration_id', $intern->id)->where('document_type', 'skl')->count());

        // Double-click pada revisi juga idempoten
        $v2Duplicate = $service->issueDocument($intern, 'skl', ['is_revision' => true], $this->admin);
        $this->assertEquals($v2->id, $v2Duplicate->id);
        $this->assertEquals(2, GeneratedDocument::where('internship_registration_id', $intern->id)->where('document_type', 'skl')->count());
    }

    /**
     * 16. Otorisasi RBAC dan Status: Super Admin memiliki akses penuh, pemagang dibatasi status dan kepemilikan.
     */
    public function test_authorization_rbac_and_status_eligibility_enforcement()
    {
        $intern = $this->createIntern(IR::STATUS_ACCEPTED);

        // Buat Admin / HR Staff berwenang
        $superAdmin = User::create([
            'name'     => 'Super Admin ' . substr($this->unique, 0, 6),
            'email'    => 'superadmin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        /** @var InternDocumentIssuanceService $service */
        $service = app(InternDocumentIssuanceService::class);

        // Super Admin dapat menerbitkan dokumen via generate controller
        $res = $this->actingAs($superAdmin)->postJson(route('admin.interns.generate.doc'), [
            'intern_id'   => $intern->id,
            'jenis_surat' => 'loa',
            'action'      => 'issue',
        ]);
        $res->assertStatus(200);
        $res->assertJson(['ok' => true]);

        $loa = GeneratedDocument::where('internship_registration_id', $intern->id)->where('document_type', 'loa')->first();
        $this->assertNotNull($loa);

        // Pemagang pemilik DAPAT mengakses dokumen LOA miliknya karena statusnya ACCEPTED
        $resDownload = $this->actingAs($this->pemagangUser)->get(route('admin.generated-documents.download', $loa->public_uuid));
        $resDownload->assertStatus(200);

        // Pemagang TIDAK DAPAT melakukan retry-sync
        $resRetry = $this->actingAs($this->pemagangUser)->postJson(route('admin.generated-documents.retry-sync', $loa->public_uuid));
        $resRetry->assertStatus(403);

        // Pengguna pemagang lain TIDAK DAPAT mengakses dokumen pemagang ini
        $otherPemagang = User::create([
            'name'     => 'Other Pemagang ' . substr($this->unique, 0, 6),
            'email'    => 'other_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);
        $resOther = $this->actingAs($otherPemagang)->get(route('admin.generated-documents.download', $loa->public_uuid));
        $resOther->assertStatus(403);

        // Pemagang tidak dapat mengakses dokumen jika status pemagang tidak memenuhi syarat penerbitan
        // Contoh: SKL dibuatkan oleh admin secara manual atau paksa, namun status pemagang masih ACCEPTED
        $unqualifiedSkl = GeneratedDocument::create([
            'public_uuid'                => (string) \Illuminate\Support\Str::uuid(),
            'document_type'              => GeneratedDocument::TYPE_SKL,
            'document_number'            => 'SKL/TEMP/TEST',
            'internship_registration_id' => $intern->id,
            'template_type'              => 'skl',
            'template_version'           => 1,
            'file_path'                  => $loa->file_path,
            'file_name'                  => $loa->file_name,
            'file_size_bytes'            => 100,
            'checksum_sha256'            => 'dummy',
            'status'                     => GeneratedDocument::STATUS_GENERATED,
            'idempotency_key'            => 'TEST-SKL-' . $intern->id,
        ]);

        // Pemagang mencoba download SKL padahal statusnya masih ACCEPTED (belum COMPLETED)
        $resUnqualified = $this->actingAs($this->pemagangUser)->get(route('admin.generated-documents.download', $unqualifiedSkl->public_uuid));
        $resUnqualified->assertStatus(403);
    }
}
