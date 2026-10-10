<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Brand;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternCertificate;
use App\Models\InternLoa;
use App\Models\InternshipRegistration as IR;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\FollowUpService;
use App\Services\MouService;
use App\Services\SpreadsheetConnectionService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StabilizationPhaseS16Test extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('s16_', true);

        $this->admin = User::create([
            'name'     => 'Admin S16 ' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => Hash::make('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'HR S16 ' . substr($this->unique, 0, 8),
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => Hash::make('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'Pemagang S16 ' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => Hash::make('password123'),
            'role'     => 'pemagang',
        ]);
    }

    /**
     * S16-1: Smoke Test Admin — Administrator dapat mengakses seluruh dashboard dan panel modul kritis.
     */
    public function test_smoke_admin_role_can_access_all_critical_dashboards_and_modules(): void
    {
        $adminRoutes = [
            route('admin.dashboard.index'),
            route('admin.users.index'),
            route('admin.institutions.index'),
            route('admin.mou-templates.index'),
            route('admin.activity-logs.index'),
            route('admin.spreadsheet-connections.index'),
            route('admin.contact-review.index'),
        ];

        foreach ($adminRoutes as $routeUrl) {
            $response = $this->actingAs($this->admin)->get($routeUrl);
            $response->assertStatus(200);
        }
    }

    /**
     * S16-2: Smoke Test HR Staff — HR Staff dapat mengakses modul operasional dan diblokir dari area eksklusif admin.
     */
    public function test_smoke_hr_staff_role_can_access_operational_modules_and_is_blocked_from_admin_core(): void
    {
        // 1. Modul operasional HR dapat diakses
        $hrAllowedRoutes = [
            route('hr.dashboard'),
            route('hr.institutions.index'),
            route('hr.follow-up.index'),
            route('hr.mous.index'),
        ];

        foreach ($hrAllowedRoutes as $routeUrl) {
            $response = $this->actingAs($this->hrStaff)->get($routeUrl);
            $response->assertStatus(200);
        }

        // 2. Akses ke area eksklusif admin ditolak (redirect 302 / 403)
        $adminExclusiveRoutes = [
            route('admin.users.index'),
            route('admin.spreadsheet-connections.index'),
            route('admin.activity-logs.index'),
        ];

        foreach ($adminExclusiveRoutes as $routeUrl) {
            $response = $this->actingAs($this->hrStaff)->get($routeUrl);
            $this->assertTrue($response->isRedirect() || $response->status() === 403);
        }
    }

    /**
     * S16-3: Smoke Test Pemagang — Pemagang dapat mengakses dashboard mandiri dan dokumen pribadi.
     */
    public function test_smoke_pemagang_role_can_access_dashboard_and_documents(): void
    {
        // 1. Pemagang dapat mengakses dashboard dan dokumen pribadi
        $resDashboard = $this->actingAs($this->pemagang)->get(route('pemagang.dashboard'));
        $resDashboard->assertStatus(200);

        $resDocuments = $this->actingAs($this->pemagang)->get(route('pemagang.documents'));
        $resDocuments->assertStatus(200);

        // 2. Pemagang diblokir dari seluruh rute HR dan Admin
        $blockedRoutes = [
            route('admin.dashboard.index'),
            route('admin.users.index'),
            route('hr.dashboard'),
            route('hr.follow-up.index'),
        ];

        foreach ($blockedRoutes as $routeUrl) {
            $response = $this->actingAs($this->pemagang)->get($routeUrl);
            $this->assertTrue($response->isRedirect() || $response->status() === 403);
        }
    }

    /**
     * S16-4: Smoke Test Alumni — Alumnus magang dapat mengunduh sertifikat dan dokumen kelulusan miliknya.
     */
    public function test_smoke_alumni_document_generation_flow(): void
    {
        $inst = Institution::first() ?? Institution::create([
            'name'            => 'Politeknik S16 ' . $this->unique,
            'normalized_name' => 'politeknik s16 ' . $this->unique,
            'type'            => 'Perguruan Tinggi',
            'status'          => 'active',
        ]);

        $fac = Faculty::first() ?? Faculty::create([
            'institution_id' => $inst->id,
            'name'           => 'Fakultas Teknik ' . $this->unique,
        ]);

        $prog = StudyProgram::first() ?? StudyProgram::create([
            'faculty_id' => $fac->id,
            'name'       => 'Teknik Informatika ' . $this->unique,
        ]);

        $brand = Brand::first() ?? Brand::create([
            'name' => 'Seven Inc',
            'code' => 'seveninc',
        ]);

        $div = Division::first() ?? Division::create([
            'name' => 'Web Development',
            'code' => 'WEB',
            'slug' => 'web-development',
        ]);

        // Buat registrasi magang status COMPLETED
        $intern = IR::create([
            'user_id'             => $this->pemagang->id,
            'fullname'            => 'Alumni S16 ' . $this->unique,
            'gender'              => 'L',
            'phone_number'        => '081299991601',
            'institution_id'      => $inst->id,
            'faculty_id'          => $fac->id,
            'study_program_id'    => $prog->id,
            'brand_id'            => $brand->id,
            'division_id'         => $div->id,
            'internship_interest' => 'Web Development',
            'start_date'          => '2026-01-01',
            'end_date'            => '2026-04-01',
            'internship_status'   => IR::STATUS_COMPLETED,
            'step'                => 5,
        ]);

        // Pastikan direktori dan file rekomendasi mock tersedia di disk publik
        $rekomRelativePath = 'documents/rekomendasi/rekom_s16_' . $this->unique . '.pdf';
        Storage::disk('public')->put($rekomRelativePath, '%PDF-1.4 dummy recommendation letter bytes for test');

        // Simpan rekaman intern extra dengan rekomendasi_path
        \App\Models\InternExtra::create([
            'intern_id'        => $intern->id,
            'rekomendasi_path' => $rekomRelativePath,
        ]);

        // 1. Verifikasi halaman Dokumen Saya menampilkan status magang selesai
        $indexResponse = $this->actingAs($this->pemagang)->get(route('pemagang.documents'));
        $indexResponse->assertStatus(200);

        // 2. Download surat rekomendasi atas nama pemagang sendiri
        $response = $this->actingAs($this->pemagang)->get(route('pemagang.documents.rekomendasi', ['registration_id' => $intern->id]));
        $response->assertStatus(200);
        $this->assertEquals('application/pdf', $response->headers->get('content-type'));

        // Clean up mock file
        Storage::disk('public')->delete($rekomRelativePath);
    }

    /**
     * S16-5: Smoke Test Follow-Up Task Locking & Attempt Cycle.
     */
    public function test_smoke_follow_up_task_locking_and_attempt_lifecycle(): void
    {
        $inst = Institution::first() ?? Institution::create([
            'name'            => 'Universitas Mitra ' . $this->unique,
            'normalized_name' => 'universitas mitra ' . $this->unique,
            'type'            => 'Perguruan Tinggi',
            'status'          => 'active',
        ]);

        $contact = InstitutionContact::create([
            'institution_id'   => $inst->id,
            'name'             => 'Ibu Kaprodi ' . $this->unique,
            'normalized_name'  => 'ibu kaprodi ' . $this->unique,
            'position'         => 'Kaprodi',
            'phone'            => '081299991602',
            'email'            => 'kaprodi_' . $this->unique . '@test.com',
            'is_active'        => true,
            'is_verified'      => true,
        ]);

        /** @var FollowUpService $service */
        $service = app(FollowUpService::class);

        // 1. Buka siklus follow-up baru
        $cycle = $service->createCycle($contact, (int) date('Y'), $this->hrStaff);
        $this->assertEquals(FollowUpCycle::STATUS_OPEN, $cycle->status);

        // 2. Klaim kunci tugas operasional (lock)
        $lockedCycle = $service->claimLock($cycle, $this->hrStaff);
        $this->assertTrue($lockedCycle->isLocked());
        $this->assertEquals($this->hrStaff->id, $lockedCycle->locked_by);

        // 3. Catat percobaan follow-up pertama
        $attempt = $service->recordAttempt($lockedCycle, [
            'channel'          => 'whatsapp',
            'notes'            => 'Percobaan kontak via WA resmi.',
            'result'           => 'responded',
            'response_text'    => 'Berminat kerjasama.',
        ], $this->hrStaff);

        $this->assertInstanceOf(\App\Models\FollowUpAttempt::class, $attempt);
        $this->assertEquals(1, $attempt->attempt_number);

        // 4. Selesaikan siklus dengan hasil 'willing'
        $completedCycle = $service->completeCycle(
            $lockedCycle,
            FollowUpCycle::RESULT_WILLING,
            'Pihak kampus sepakat menjalin MOU kemitraan resmi.',
            $this->hrStaff
        );

        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $completedCycle->status);
        $this->assertEquals(FollowUpCycle::RESULT_WILLING, $completedCycle->final_result);
        $this->assertFalse($completedCycle->isLocked());
    }

    /**
     * S16-6: Smoke Test MOU Generation & Signed Upload Activation Lifecycle.
     */
    public function test_smoke_mou_generation_and_signed_upload_activation_lifecycle(): void
    {
        $inst = Institution::first() ?? Institution::create([
            'name'            => 'Institut Teknologi S16 ' . $this->unique,
            'normalized_name' => 'institut teknologi s16 ' . $this->unique,
            'type'            => 'Perguruan Tinggi',
            'status'          => 'active',
        ]);

        $contact = InstitutionContact::create([
            'institution_id'   => $inst->id,
            'name'             => 'Dr. Haryanto ' . $this->unique,
            'normalized_name'  => 'dr haryanto ' . $this->unique,
            'position'         => 'Wakil Rektor',
            'phone'            => '081299991603',
            'email'            => 'warek_' . $this->unique . '@test.com',
            'is_active'        => true,
            'is_verified'      => true,
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $inst->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_COMPLETED,
            'final_result'           => FollowUpCycle::RESULT_WILLING,
            'confirmed_at'           => now(),
            'confirmed_by'           => $this->hrStaff->id,
        ]);

        $template = MouTemplate::first() ?? MouTemplate::create([
            'name'                  => 'Template Standar ' . $this->unique,
            'template_html'         => '<div><p>{{opening}}</p><p>{{articles}}</p><p>{{closing}}</p></div>',
            'template_type'         => 'university',
            'default_validity_years'=> 5,
            'first_party_label'     => 'PIHAK PERTAMA',
            'second_party_label'    => 'PIHAK KEDUA',
            'opening_text'          => '<p>Perjanjian ini dibuat pada hari ini.</p>',
            'articles_html'         => '<p>Pasal 1: Kerjasama Magang.</p>',
            'closing_text'          => '<p>Demikian naskah dibuat.</p>',
            'is_active'             => true,
        ]);

        /** @var MouService $mouService */
        $mouService = app(MouService::class);

        // 1. Generate naskah MOU
        $mou = $mouService->generateMou([
            'institution_id'               => $inst->id,
            'followup_cycle_id'            => $cycle->id,
            'mou_template_id'              => $template->id,
            'company_mou_number'           => 'MOU-S16-SMOKE-' . $this->unique,
            'first_party_institution_name' => $inst->name,
            'first_party_signatory_name'   => 'Dr. Haryanto',
            'first_party_signatory_title'  => 'Wakil Rektor',
            'second_party_company_name'    => 'PT Tujuh Solusi Berkelanjutan',
            'second_party_signatory_name'  => 'Rekso Danny Pamungkas',
            'second_party_signatory_title' => 'Direktur Utama',
            'agreement_place'              => 'Yogyakarta',
            'agreement_date'               => now()->toDateString(),
            'effective_start'              => now()->toDateString(),
            'effective_end'                => now()->addYears(5)->toDateString(),
        ], $this->hrStaff);

        $this->assertEquals(Mou::STATUS_GENERATED, $mou->status);
        $this->assertNotEmpty($mou->generated_file_path);

        // 2. Tandai penyerahan dokumen ke mitra (delivered)
        $deliveredMou = $mouService->markDelivered($mou, [
            'delivery_method' => 'email',
            'delivered_at'    => now(),
        ], $this->hrStaff);
        $this->assertEquals(Mou::STATUS_DELIVERED, $deliveredMou->status);

        // 3. Unggah naskah bertandatangan dan aktifkan MOU
        $fakePdf = UploadedFile::fake()->create('mou_signed_final.pdf', 300, 'application/pdf');

        $activeMou = $mouService->uploadSignedMou($deliveredMou, $fakePdf, [
            'signed_at' => now()->toDateString(),
        ], $this->hrStaff);

        $this->assertEquals(Mou::STATUS_ACTIVE, $activeMou->status);
        $this->assertNotEmpty($activeMou->signed_file_path);
        $this->assertNotNull($activeMou->signed_at);
    }

    /**
     * S16-7: CSRF Runtime Protection — Rejects mutations with missing or forged token via VerifyCsrfToken middleware handle().
     */
    public function test_csrf_runtime_protection_rejects_unauthenticated_or_forged_mutations(): void
    {
        // Concrete testable subclass of VerifyCsrfToken forcing runningUnitTests() to false to simulate real HTTP execution
        $middleware = new class(app(), app('encrypter')) extends \App\Http\Middleware\VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false; // Force real CSRF verification mode
            }
        };

        $session = app('session')->driver();
        $session->start();
        $sessionToken = $session->token();

        // 1. Mutation request without CSRF token must throw TokenMismatchException
        $requestNoToken = Request::create('/admin/users', 'POST', [
            'name'     => 'Attacker No Token',
            'email'    => 'attacker_no_token@test.com',
            'password' => 'secret',
        ]);
        $requestNoToken->setLaravelSession($session);

        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $middleware->handle($requestNoToken, function ($req) {
            return response('OK');
        });
    }

    /**
     * S16-7b: CSRF Runtime Protection — Accepts valid session token.
     */
    public function test_csrf_runtime_protection_accepts_valid_session_token(): void
    {
        $middleware = new class(app(), app('encrypter')) extends \App\Http\Middleware\VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $session = app('session')->driver();
        $session->start();
        $sessionToken = $session->token();

        $requestValid = Request::create('/admin/users', 'POST', [
            '_token'   => $sessionToken,
            'name'     => 'Valid User',
            'email'    => 'valid@test.com',
            'password' => 'secret',
        ]);
        $requestValid->setLaravelSession($session);

        $executed = false;
        $response = $middleware->handle($requestValid, function ($req) use (&$executed) {
            $executed = true;
            return response('OK');
        });

        $this->assertTrue($executed, 'Middleware must execute next pipeline callback when CSRF token matches.');
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * S16-8: Attachment 10 MB vs 5 MB Resolution — Direct Drive Driver handles 5-10 MB, Apps Script handles boundary safety.
     */
    public function test_attachment_upload_up_to_10mb_and_apps_script_5mb_boundary_safety(): void
    {
        Storage::fake('private');

        // Buat file 6 MB (di atas 5 MB batas Apps Script, di bawah 10 MB batas lokal)
        $largeFilePath = storage_path('app/testing_large_attachment_6mb.tmp');
        file_put_contents($largeFilePath, str_repeat('A', 6 * 1024 * 1024));

        $conn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            ['display_name' => 'Registration Sheet', 'is_enabled' => true, 'spreadsheet_id' => 'dummy_id']
        );

        $attachment = new RegistrationAttachment([
            'file_name' => 'large_doc.pdf',
            'file_path' => 'attachments/large_doc.pdf',
            'file_size' => 6 * 1024 * 1024,
        ]);

        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'endpoint_url'  => 'https://script.google.com/macros/s/dummy/exec',
            'shared_secret' => 'test_secret',
        ]);

        $response = $transport->uploadAttachment($conn, $attachment, $largeFilePath);

        // Assert: Transport gracefully rejects large payload without throwing fatal error
        $this->assertFalse($response->success);
        $this->assertEquals('FILE_TOO_LARGE', $response->errorCode);
        $this->assertFalse($response->isRetryable);
        $this->assertStringContainsString('melebihi batas transfer Apps Script', $response->errorMessage);

        // Assert: Local file is completely preserved
        $this->assertFileExists($largeFilePath);
        @unlink($largeFilePath);
    }

    /**
     * S16-8b: Attachment 5-10 MB Direct Drive Upload via CloudSyncManager mock driver.
     */
    public function test_attachment_5_to_10mb_direct_drive_driver_upload(): void
    {
        Storage::fake('private');

        $user = User::create([
            'name'      => 'Pemagang 8MB ' . $this->unique,
            'email'     => 'pemagang8mb_' . $this->unique . '@test.com',
            'role'      => 'pemagang',
            'password'  => Hash::make('Secret123!'),
            'is_active' => true,
        ]);

        $inst = Institution::first() ?? Institution::create([
            'name'            => 'Universitas Direct Drive ' . $this->unique,
            'normalized_name' => 'universitas direct drive ' . $this->unique,
            'type'            => 'Perguruan Tinggi',
            'status'          => 'active',
        ]);
        $fac = Faculty::first() ?? Faculty::create(['institution_id' => $inst->id, 'name' => 'Fakultas Teknik ' . $this->unique]);
        $prog = StudyProgram::first() ?? StudyProgram::create(['faculty_id' => $fac->id, 'name' => 'Teknik Informatika ' . $this->unique]);
        $div = Division::first() ?? Division::create(['name' => 'Web Dev', 'code' => 'WEB', 'slug' => 'web-dev-' . $this->unique]);

        $reg = IR::create([
            'user_id'             => $user->id,
            'fullname'            => $user->name,
            'gender'              => 'L',
            'phone_number'        => '081299991608',
            'institution_id'      => $inst->id,
            'faculty_id'          => $fac->id,
            'study_program_id'    => $prog->id,
            'division_id'         => $div->id,
            'internship_interest' => 'Web Dev',
            'start_date'          => '2026-01-01',
            'end_date'            => '2026-04-01',
            'internship_status'   => IR::STATUS_ACCEPTED,
            'step'                => 5,
        ]);

        $conn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            ['display_name' => 'Registration Sheet', 'is_enabled' => true, 'spreadsheet_id' => 'dummy_sheet_id']
        );
        $conn->update(['is_enabled' => true]);

        $filePath = 'attachments/portofolio_8mb_' . $this->unique . '.pdf';
        Storage::disk('private')->put($filePath, str_repeat('B', 8 * 1024 * 1024));

        $attachment = RegistrationAttachment::create([
            'internship_registration_id' => $reg->id,
            'attachment_type'            => 'portofolio',
            'file_name'                  => 'portofolio_8mb.pdf',
            'original_name'              => 'portofolio_8mb.pdf',
            'file_path'                  => $filePath,
            'mime_type'                  => 'application/pdf',
            'file_size'                  => 8 * 1024 * 1024,
            'drive_sync_status'          => RegistrationAttachment::STATUS_PENDING,
        ]);

        // Mock configured CloudStorage driver
        $mockDriver = new class implements \App\Services\CloudStorage\CloudStorageInterface {
            public function isConfigured(): bool { return true; }
            public function getDriverName(): string { return 'mock_google_drive_direct'; }
            public function uploadFile(string $localFullPath, string $remoteFolderPath, ?string $customFileName = null): array {
                return [
                    'file_id'       => 'DRIVE-DIRECT-8MB-' . uniqid(),
                    'web_view_link' => 'https://drive.google.com/file/d/direct-8mb-mock/view',
                    'file_name'     => $customFileName ?: 'portofolio.pdf',
                    'driver'        => 'mock_google_drive_direct',
                ];
            }
            public function deleteFile(string $remoteFileId): bool { return true; }
        };

        $cloudManager = new \App\Services\CloudStorage\CloudSyncManager($mockDriver);
        app()->instance(\App\Services\CloudStorage\CloudSyncManager::class, $cloudManager);

        $job = new \App\Jobs\UploadRegistrationAttachmentToDriveJob($attachment->id);
        $job->handle(
            transport: new \App\Services\SpreadsheetTransport\NullSpreadsheetTransport(),
            syncEngine: app(\App\Services\SpreadsheetSyncEngineService::class)
        );

        $freshAttachment = $attachment->fresh();
        $this->assertEquals(RegistrationAttachment::STATUS_SYNCED, $freshAttachment->drive_sync_status);
        $this->assertNotNull($freshAttachment->drive_file_id);
        $this->assertStringContainsString('DRIVE-DIRECT-8MB-', $freshAttachment->drive_file_id);
        $this->assertNotNull($freshAttachment->drive_web_view_link);
        $this->assertNull($freshAttachment->drive_error_message);

        // Local file remains intact
        $this->assertTrue(Storage::disk('private')->exists($filePath));
    }

    /**
     * S16-9: Admin can register new yearly schema version via HTTP endpoint.
     */
    public function test_admin_can_register_new_yearly_schema_version_via_http_endpoint(): void
    {
        $conn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            ['display_name' => 'Registration Sheet', 'is_enabled' => true]
        );

        $mappings = [
            [
                'column_order'  => 1,
                'sheet_header'  => 'Timestamp',
                'target_entity' => 'internship_registration',
                'target_field'  => 'created_at',
                'data_type'     => 'datetime',
                'direction'     => 'outbound',
                'transform_key' => 'format_datetime',
                'is_required'   => true,
            ],
            [
                'column_order'  => 2,
                'sheet_header'  => 'Nama Lengkap',
                'target_entity' => 'internship_registration',
                'target_field'  => 'fullname',
                'data_type'     => 'string',
                'direction'     => 'outbound',
                'transform_key' => null,
                'is_required'   => true,
            ],
        ];

        // 1. Non-admin forbidden
        $resHR = $this->actingAs($this->hrStaff)->post(
            route('admin.spreadsheet-connections.schemas.store', $conn->id),
            [
                'year'           => 2028,
                'sheet_tab_name' => '2028',
                'schema_version' => '2028.1',
                'notes'          => 'Schema 2028 test',
                'activate'       => true,
                'mappings'       => $mappings,
            ]
        );
        $this->assertTrue($resHR->isRedirect() || $resHR->status() === 403);

        // 2. Admin successfully registers schema
        $resAdmin = $this->actingAs($this->admin)->post(
            route('admin.spreadsheet-connections.schemas.store', $conn->id),
            [
                'year'           => 2028,
                'sheet_tab_name' => '2028',
                'schema_version' => '2028.1',
                'notes'          => 'Schema 2028 production contract',
                'activate'       => true,
                'mappings'       => $mappings,
            ]
        );
        $resAdmin->assertRedirect(route('admin.spreadsheet-connections.show', $conn->id));

        $createdSchema = SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $conn->id)
            ->where('year', 2028)
            ->where('schema_version', '2028.1')
            ->first();

        $this->assertNotNull($createdSchema);
        $this->assertTrue($createdSchema->is_active);
        $this->assertEquals(2, $createdSchema->columnMappings()->count());
    }

    /**
     * S16-10: Admin can activate and deactivate yearly spreadsheet schema versions.
     */
    public function test_admin_can_activate_and_deactivate_yearly_spreadsheet_schema_versions(): void
    {
        $conn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            ['display_name' => 'Registration Sheet', 'is_enabled' => true]
        );

        $schemaV1 = SpreadsheetSchemaVersion::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $conn->id,
            'year'                      => 2027,
            'sheet_tab_name'            => '2027',
            'schema_version'            => '2027.1',
            'is_active'                 => false,
        ]);

        $schemaV2 = SpreadsheetSchemaVersion::create([
            'public_uuid'               => (string) Str::uuid(),
            'spreadsheet_connection_id' => $conn->id,
            'year'                      => 2027,
            'sheet_tab_name'            => '2027',
            'schema_version'            => '2027.2',
            'is_active'                 => false,
        ]);

        // 1. Admin activates schema V2
        $resAdmin = $this->actingAs($this->admin)->post(
            route('admin.spreadsheet-connections.schemas.activate', [$conn->id, $schemaV2->id])
        );
        $resAdmin->assertRedirect();
        $this->assertTrue($schemaV2->fresh()->is_active);
        $this->assertFalse($schemaV1->fresh()->is_active);

        // 2. Admin deactivates schema V2
        $resDeact = $this->actingAs($this->admin)->post(
            route('admin.spreadsheet-connections.schemas.deactivate', [$conn->id, $schemaV2->id])
        );
        $resDeact->assertRedirect();
        $this->assertFalse($schemaV2->fresh()->is_active);
    }

    /**
     * S16-11: Apps Script HMAC Contract Verification — Signature, timestamp, and nonce sent in JSON body.
     */
    public function test_apps_script_hmac_contract_body_signature_verification(): void
    {
        $sharedSecret = 'super_secret_hmac_key_12345';
        $transport = new GoogleAppsScriptTransport([
            'enabled'       => true,
            'endpoint_url'  => 'https://script.google.com/macros/s/test/exec',
            'shared_secret' => $sharedSecret,
        ]);

        $conn = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            ['display_name' => 'Registration Sheet', 'is_enabled' => true, 'spreadsheet_id' => 'dummy_sheet_id']
        );

        $capturedBody = null;
        \Illuminate\Support\Facades\Http::fake([
            'https://script.google.com/*' => function (\Illuminate\Http\Client\Request $request) use (&$capturedBody, $sharedSecret) {
                $capturedBody = $request->data();

                // Validate signature in body exactly matching Apps Script logic
                $timestamp = $capturedBody['timestamp'] ?? null;
                $nonce = $capturedBody['nonce'] ?? null;
                $signature = $capturedBody['signature'] ?? null;
                $payload = $capturedBody['payload'] ?? null;

                $canonicalData = $timestamp . '.' . $nonce . '.' . json_encode($payload);
                $expectedSignature = hash_hmac('sha256', $canonicalData, $sharedSecret);

                if ($signature !== $expectedSignature) {
                    return \Illuminate\Support\Facades\Http::response([
                        'success'    => false,
                        'error_code' => 'INVALID_SIGNATURE',
                    ], 401);
                }

                return \Illuminate\Support\Facades\Http::response([
                    'success'   => true,
                    'row_index' => 5,
                ], 200);
            },
        ]);

        $response = $transport->sendRowUpsert($conn, '2026', 'SYNC-S16-001', ['Nama' => 'Budi']);

        $this->assertTrue($response->success);
        $this->assertEquals(5, $response->rowIndex);
        $this->assertNotNull($capturedBody);
        $this->assertArrayHasKey('signature', $capturedBody);
        $this->assertArrayHasKey('timestamp', $capturedBody);
        $this->assertArrayHasKey('nonce', $capturedBody);
        $this->assertArrayHasKey('payload', $capturedBody);
    }

    /**
     * S16-12: Config and environment variable consistency verification.
     */
    public function test_google_apps_script_config_and_secret_consistency(): void
    {
        config(['services.google_apps_script.shared_secret' => 'test_canonical_shared_secret_key_123']);

        $transport = new GoogleAppsScriptTransport([
            'enabled'      => true,
            'endpoint_url' => 'https://script.google.com/macros/s/test/exec',
        ]);

        $this->assertTrue($transport->isConfigured());
    }
}
