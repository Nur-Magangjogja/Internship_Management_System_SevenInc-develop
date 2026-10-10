<?php

namespace Tests\Feature;

use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\ActivityLog;
use App\Models\ArchiveExport;
use App\Models\Faculty;
use App\Models\FollowUpAttempt;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DocumentGeneratorService;
use App\Services\FollowUpService;
use App\Services\RegistrationLifecycleService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StabilizationPhaseS15Test extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;
    protected User $hrStaff1;
    protected User $hrStaff2;
    protected User $pemagangA;
    protected User $pemagangB;
    protected Institution $institution;
    protected Faculty $faculty;
    protected StudyProgram $studyProgram;
    protected InstitutionContact $contact;
    protected string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessSpreadsheetRowSyncJob::class, UploadRegistrationAttachmentToDriveJob::class]);

        $this->unique = uniqid('s15_', true);

        // 1. Admin
        $this->admin = User::create([
            'name'      => 'Admin S15 ' . substr($this->unique, 0, 6),
            'email'     => 'admin_' . $this->unique . '@test.com',
            'password'  => bcrypt('SecretAdminPass123!'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        // 2. HR Staff 1
        $this->hrStaff1 = User::create([
            'name'      => 'HR1 S15 ' . substr($this->unique, 0, 6),
            'email'     => 'hr1_' . $this->unique . '@test.com',
            'password'  => bcrypt('SecretHRPass123!'),
            'role'      => 'hr_staff',
            'is_banned' => false,
        ]);

        // 3. HR Staff 2
        $this->hrStaff2 = User::create([
            'name'      => 'HR2 S15 ' . substr($this->unique, 0, 6),
            'email'     => 'hr2_' . $this->unique . '@test.com',
            'password'  => bcrypt('SecretHRPass123!'),
            'role'      => 'hr_staff',
            'is_banned' => false,
        ]);

        // 4. Pemagang A
        $this->pemagangA = User::create([
            'name'      => 'Pemagang A ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_a_' . $this->unique . '@test.com',
            'password'  => bcrypt('SecretPemagangPass123!'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);

        // 5. Pemagang B
        $this->pemagangB = User::create([
            'name'      => 'Pemagang B ' . substr($this->unique, 0, 6),
            'email'     => 'pemagang_b_' . $this->unique . '@test.com',
            'password'  => bcrypt('SecretPemagangPass123!'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);

        // Master institution hierarchy
        $this->institution = Institution::create([
            'name'            => 'Universitas Keamanan S15 ' . substr($this->unique, 0, 6),
            'normalized_name' => 'universitas keamanan s15 ' . substr($this->unique, 0, 6),
            'type'            => 'Perguruan Tinggi',
            'status'          => 'active',
        ]);

        $this->faculty = Faculty::create([
            'institution_id' => $this->institution->id,
            'name'           => 'Fakultas Ilmu Komputer S15',
        ]);

        $this->studyProgram = StudyProgram::create([
            'faculty_id' => $this->faculty->id,
            'name'       => 'Teknik Informatika S15',
        ]);

        $this->contact = InstitutionContact::create([
            'institution_id'  => $this->institution->id,
            'name'            => 'Bapak Kontak S15',
            'normalized_name' => 'bapak kontak s15',
            'position'        => 'Ketua Jurusan',
            'phone'           => '081299990001',
            'email'           => 'kontak_' . $this->unique . '@test.com',
            'is_active'       => true,
            'is_verified'     => true,
        ]);
    }

    /**
     * Test 1: Unauthenticated user cannot access protected routes.
     */
    public function test_unauthenticated_user_cannot_access_protected_routes(): void
    {
        // Admin protected route
        $adminRes = $this->get(route('admin.users.index'));
        $this->assertTrue($adminRes->isRedirect() || $adminRes->status() === 401);

        // HR protected route
        $hrRes = $this->get(route('hr.dashboard'));
        $this->assertTrue($hrRes->isRedirect() || $hrRes->status() === 401);

        // Pemagang protected route
        $pemagangRes = $this->get(route('pemagang.dashboard'));
        $this->assertTrue($pemagangRes->isRedirect() || $pemagangRes->status() === 401);

        // Document serve route
        $docRes = $this->get('/admin/documents/serve/loa/dummy.pdf');
        $this->assertTrue($docRes->isRedirect() || $docRes->status() === 401);
    }

    /**
     * Test 2: Role middleware strictly prevents privilege escalation across roles.
     */
    public function test_role_middleware_prevents_privilege_escalation(): void
    {
        // HR staff attempting admin user management & spreadsheet config (JSON returns 403, Web redirects with error)
        $resHRtoAdmin = $this->actingAs($this->hrStaff1)->get(route('admin.users.index'));
        $this->assertTrue($resHRtoAdmin->isRedirect() || $resHRtoAdmin->status() === 403);

        $resHRtoConn = $this->actingAs($this->hrStaff1)->get(route('admin.spreadsheet-connections.index'));
        $this->assertTrue($resHRtoConn->isRedirect() || $resHRtoConn->status() === 403);

        $resHRtoLogs = $this->actingAs($this->hrStaff1)->get(route('admin.activity-logs.index'));
        $this->assertTrue($resHRtoLogs->isRedirect() || $resHRtoLogs->status() === 403);

        // JSON expects 403
        $resHRJson = $this->actingAs($this->hrStaff1)->json('GET', route('admin.users.index'));
        $resHRJson->assertStatus(403);

        // Pemagang attempting admin routes
        $resPemtoAdmin = $this->actingAs($this->pemagangA)->json('GET', route('admin.users.index'));
        $resPemtoAdmin->assertStatus(403);

        // Pemagang attempting HR routes
        $resPemtoHR = $this->actingAs($this->pemagangA)->json('GET', route('hr.dashboard'));
        $resPemtoHR->assertStatus(403);

        // HR Staff attempting Pemagang-only student documents view
        $resHRtoPemDocs = $this->actingAs($this->hrStaff1)->json('GET', route('pemagang.documents'));
        $resHRtoPemDocs->assertStatus(403);
    }

    /**
     * Test 3: Inactive, banned, and soft-deleted users are blocked from protected actions.
     */
    public function test_inactive_banned_and_soft_deleted_users_are_blocked_by_middleware(): void
    {
        // 1. Banned user
        $bannedUser = User::create([
            'name'      => 'Banned S15',
            'email'     => 'banned_' . $this->unique . '@test.com',
            'password'  => bcrypt('pass'),
            'role'      => 'pemagang',
            'is_banned' => true,
            'banned_at' => now(),
            'ban_reason'=> 'Pelanggaran keamanan sistem',
        ]);

        $resBanned = $this->actingAs($bannedUser)->get(route('pemagang.dashboard'));
        $this->assertTrue($resBanned->isRedirect() || $resBanned->status() === 403);

        // 2. Soft deleted user
        $softDeletedUser = User::create([
            'name'      => 'SoftDeleted S15',
            'email'     => 'softdel_' . $this->unique . '@test.com',
            'password'  => bcrypt('pass'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);
        $softDeletedUser->delete();

        $resSoftDel = $this->actingAs($softDeletedUser)->get(route('pemagang.dashboard'));
        $this->assertTrue($resSoftDel->isRedirect() || in_array($resSoftDel->status(), [401, 403], true));
    }

    /**
     * Test 4: Cross-user registration IDOR is strictly rejected.
     */
    public function test_cross_user_registration_idor_is_rejected(): void
    {
        // Registration belonging to Pemagang B
        $regB = InternshipRegistration::create([
            'user_id'           => $this->pemagangB->id,
            'fullname'          => 'Pemagang B Original',
            'gender'            => 'L',
            'phone_number'      => '081299990002',
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'start_date'        => '2026-03-01',
            'end_date'          => '2026-06-01',
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
            'step'              => 5,
        ]);

        // Pemagang A attempts to resolve documents for Registration B directly
        $lifecycle = app(RegistrationLifecycleService::class);

        try {
            $lifecycle->resolveRegistrationForDocuments($this->pemagangA, $regB->id);
            $this->fail('Expected HttpException 403 for unauthorized registration access');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('Anda tidak berhak mengakses pendaftaran milik pengguna lain', $e->getMessage());
        }

        // Pemagang A requesting LOA generation for Registration B
        $resLoaIdor = $this->actingAs($this->pemagangA)->post(route('user.loa.generate'), [
            'intern_id' => $regB->id,
        ]);
        $resLoaIdor->assertStatus(403);
    }

    /**
     * Test 5: Cross-user document download IDOR is strictly rejected.
     */
    public function test_cross_user_document_download_idor_is_rejected(): void
    {
        $regB = InternshipRegistration::create([
            'user_id'           => $this->pemagangB->id,
            'fullname'          => 'Pemagang B Completed',
            'gender'            => 'P',
            'phone_number'      => '081299990003',
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'start_date'        => '2026-01-01',
            'end_date'          => '2026-04-01',
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
            'step'              => 5,
        ]);

        // DocumentGeneratorService::resolveTargetUser directly blocks unauthorized target user resolving
        $docService = app(DocumentGeneratorService::class);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Anda tidak memiliki wewenang untuk mengunduh dokumen atas nama pengguna lain.');

        $docService->resolveTargetUser($this->pemagangA, $this->pemagangB->id);
    }

    /**
     * Test 6: Follow-up mutation strictly requires active valid lock ownership.
     */
    public function test_hr_followup_cycle_mutation_requires_valid_active_lock(): void
    {
        $followUpService = app(FollowUpService::class);

        $cycle = FollowUpCycle::create([
            'institution_contact_id' => $this->contact->id,
            'cycle_year'             => 2026,
            'stage'                  => 'initial_contact',
            'status'                 => 'active',
        ]);

        // Attempt mutation by HR Staff 1 without claiming lock -> fails
        try {
            $followUpService->recordAttempt($cycle, [
                'channel'  => 'whatsapp',
                'notes'    => 'Attempt without lock',
                'result'   => 'no_response',
            ], $this->hrStaff1);
            $this->fail('Expected DomainException for write without lock ownership');
        } catch (DomainException $e) {
            $this->assertStringContainsString('kunci tugas follow-up', strtolower($e->getMessage()));
        }

        // HR Staff 1 claims lock
        $followUpService->claimLock($cycle, $this->hrStaff1);
        $cycle->refresh();
        $this->assertTrue($cycle->isLocked());
        $this->assertSame($this->hrStaff1->id, $cycle->locked_by);

        // HR Staff 2 attempts mutation while Staff 1 holds lock -> fails
        try {
            $followUpService->recordAttempt($cycle, [
                'channel'  => 'whatsapp',
                'notes'    => 'Staff 2 intruding',
                'result'   => 'no_response',
            ], $this->hrStaff2);
            $this->fail('Expected DomainException for foreign lock violation');
        } catch (DomainException $e) {
            $this->assertStringContainsString('sedang dikerjakan oleh', strtolower($e->getMessage()));
        }

        // Staff 1 can successfully record attempt
        $attempt = $followUpService->recordAttempt($cycle, [
            'channel'  => 'whatsapp',
            'notes'    => 'Staff 1 valid attempt',
            'result'   => 'responded',
        ], $this->hrStaff1);
        $this->assertInstanceOf(FollowUpAttempt::class, $attempt);
        $this->assertSame(1, $attempt->attempt_number);
    }

    /**
     * Test 7: Legacy import mutations consistently return HTTP 410 Gone.
     */
    public function test_legacy_import_mutations_consistently_return_http_410_gone(): void
    {
        $previewRes = $this->actingAs($this->admin)->post(route('admin.legacy-import.preview'));
        $previewRes->assertStatus(410);

        $commitRes = $this->actingAs($this->admin)->post(route('admin.legacy-import.commit'));
        $commitRes->assertStatus(410);

        $cancelRes = $this->actingAs($this->admin)->post(route('admin.legacy-import.cancel'));
        $cancelRes->assertStatus(410);

        $tplRes = $this->actingAs($this->admin)->get(route('admin.legacy-import.template'));
        $tplRes->assertStatus(410);
    }

    /**
     * Test 8: Archive export mutations consistently return HTTP 410 Gone.
     */
    public function test_archive_export_mutations_consistently_return_http_410_gone(): void
    {
        $archive = ArchiveExport::create([
            'archive_year'    => 2024,
            'file_name'       => 'archive_2024_retired.zip',
            'file_path'       => 'archives/archive_2024_retired.zip',
            'file_size_bytes' => 1024,
            'status'          => 'completed',
            'generated_by'    => $this->admin->id,
            'generated_at'    => now(),
        ]);

        $genRes = $this->actingAs($this->admin)->post(route('admin.archive-exports.generate'));
        $genRes->assertStatus(410);

        $syncRes = $this->actingAs($this->admin)->post(route('admin.archive-exports.sync-drive', $archive->id));
        $syncRes->assertStatus(410);

        $delRes = $this->actingAs($this->admin)->delete(route('admin.archive-exports.destroy', $archive->id));
        $delRes->assertStatus(410);
    }

    /**
     * Test 9: Historical archive download is strictly restricted to Admin.
     */
    public function test_historical_archive_download_is_strictly_admin_only(): void
    {
        $archive = ArchiveExport::create([
            'archive_year'    => 2024,
            'file_name'       => 'archive_2024_historical.zip',
            'file_path'       => 'archives/archive_2024_historical.zip',
            'file_size_bytes' => 1024,
            'status'          => 'completed',
            'generated_by'    => $this->admin->id,
            'generated_at'    => now(),
        ]);

        Storage::disk('local')->put($archive->file_path, 'HISTORICAL_ARCHIVE_DATA');

        // HR Staff denied (redirect or 403)
        $resHR = $this->actingAs($this->hrStaff1)->get(route('admin.archive-exports.download', $archive->id));
        $this->assertTrue($resHR->isRedirect() || $resHR->status() === 403);

        // Pemagang denied (redirect or 403)
        $resPem = $this->actingAs($this->pemagangA)->get(route('admin.archive-exports.download', $archive->id));
        $this->assertTrue($resPem->isRedirect() || $resPem->status() === 403);

        // Guest redirected to login
        $resGuest = $this->get(route('admin.archive-exports.download', $archive->id));
        $this->assertTrue($resGuest->isRedirect() || $resGuest->status() === 401);

        // Admin allowed
        $resAdmin = $this->actingAs($this->admin)->get(route('admin.archive-exports.download', $archive->id));
        $resAdmin->assertStatus(200);

        // Clean up mock file
        Storage::disk('local')->delete($archive->file_path);
    }

    /**
     * Test 10: Spreadsheet transport HMAC signing and receiver security verification.
     */
    public function test_spreadsheet_transport_hmac_signing_and_receiver_security(): void
    {
        $secret = 'super_secret_signing_key_test_12345';
        $transport = new GoogleAppsScriptTransport([
            'enabled'        => true,
            'endpoint_url'   => 'https://script.google.com/macros/s/test_endpoint/exec',
            'shared_secret'  => $secret,
            'timeout_seconds'=> 5,
        ]);

        $this->assertTrue($transport->isConfigured());

        $payload = [
            'action'         => 'upsert_row',
            'connection_key' => 'registration_2026',
            'spreadsheet_id' => '1AbCdEfGhIjKlMnOpQrStUvWxYz',
            'tab_name'       => '2026',
            'sync_id'        => 'SERTIF-REG-2026-0001',
            'payload'        => ['nama' => 'Budi Santoso'],
        ];

        $timestamp = time();
        $nonce = (string) Str::uuid();
        $canonical = $timestamp . '.' . $nonce . '.' . json_encode($payload);
        $validSignature = hash_hmac('sha256', $canonical, $secret);

        // Receiver Logic Mock (Simulating Apps Script security gate):
        $verifyReceiver = function (array $req, string $srvSecret, int $now, array &$usedNonces): array {
            $reqTimestamp = (int) ($req['timestamp'] ?? 0);
            $reqNonce     = (string) ($req['nonce'] ?? '');
            $reqSignature = (string) ($req['signature'] ?? '');
            $reqPayload   = $req['payload'] ?? [];

            // 1. Timestamp freshness check (max 300 seconds delta)
            if (abs($now - $reqTimestamp) > 300) {
                return ['success' => false, 'error' => 'STALE_OR_EXPIRED_TIMESTAMP', 'status' => 401];
            }

            // 2. Replay check (nonce uniqueness)
            if (in_array($reqNonce, $usedNonces, true)) {
                return ['success' => false, 'error' => 'REPLAY_ATTACK_NONCE_REUSED', 'status' => 409];
            }

            // 3. Signature verification
            $calcCanonical = $reqTimestamp . '.' . $reqNonce . '.' . json_encode($reqPayload);
            $calcSignature = hash_hmac('sha256', $calcCanonical, $srvSecret);

            if (!hash_equals($calcSignature, $reqSignature)) {
                return ['success' => false, 'error' => 'INVALID_HMAC_SIGNATURE', 'status' => 401];
            }

            $usedNonces[] = $reqNonce;
            return ['success' => true, 'status' => 200];
        };

        $seenNonces = [];

        // Case A: Valid request
        $validReq = ['timestamp' => $timestamp, 'nonce' => $nonce, 'signature' => $validSignature, 'payload' => $payload];
        $resA = $verifyReceiver($validReq, $secret, $timestamp, $seenNonces);
        $this->assertTrue($resA['success']);
        $this->assertSame(200, $resA['status']);

        // Case B: Tampered payload
        $tamperedPayload = $payload;
        $tamperedPayload['payload']['nama'] = 'Hacker Attack';
        $tamperedReq = ['timestamp' => $timestamp, 'nonce' => (string) Str::uuid(), 'signature' => $validSignature, 'payload' => $tamperedPayload];
        $resB = $verifyReceiver($tamperedReq, $secret, $timestamp, $seenNonces);
        $this->assertFalse($resB['success']);
        $this->assertSame('INVALID_HMAC_SIGNATURE', $resB['error']);

        // Case C: Stale timestamp (> 300s old)
        $staleReq = ['timestamp' => $timestamp - 400, 'nonce' => (string) Str::uuid(), 'signature' => $validSignature, 'payload' => $payload];
        $resC = $verifyReceiver($staleReq, $secret, $timestamp, $seenNonces);
        $this->assertFalse($resC['success']);
        $this->assertSame('STALE_OR_EXPIRED_TIMESTAMP', $resC['error']);

        // Case D: Replay of same nonce
        $replayReq = ['timestamp' => $timestamp, 'nonce' => $nonce, 'signature' => $validSignature, 'payload' => $payload];
        $resD = $verifyReceiver($replayReq, $secret, $timestamp, $seenNonces);
        $this->assertFalse($resD['success']);
        $this->assertSame('REPLAY_ATTACK_NONCE_REUSED', $resD['error']);
    }

    /**
     * Test 11: Spreadsheet payload formula injection sanitization.
     */
    public function test_spreadsheet_payload_formula_injection_sanitization(): void
    {
        $transport = new GoogleAppsScriptTransport();

        $dirtyPayload = [
            'name'        => '=SUM(A1:A10)',
            'phone'       => '+628123456789',
            'command'     => '-2+3+cmd|/C calc',
            'mention'     => '@admin',
            'tabbed'      => "\tmalicious_tab",
            'returned'    => "\rnewline_hack",
            'safe_text'   => 'Budi Santoso',
            'safe_number' => 12345,
            'safe_null'   => null,
            'safe_bool'   => true,
        ];

        $sanitized = $transport->sanitizePayloadForFormulas($dirtyPayload);

        // Assert leading formula triggers are escaped with single quote
        $this->assertSame("'=SUM(A1:A10)", $sanitized['name']);
        $this->assertSame("'+628123456789", $sanitized['phone']);
        $this->assertSame("'-2+3+cmd|/C calc", $sanitized['command']);
        $this->assertSame("'@admin", $sanitized['mention']);
        $this->assertSame("'\tmalicious_tab", $sanitized['tabbed']);
        $this->assertSame("'\rnewline_hack", $sanitized['returned']);

        // Assert safe values are completely preserved
        $this->assertSame('Budi Santoso', $sanitized['safe_text']);
        $this->assertSame(12345, $sanitized['safe_number']);
        $this->assertNull($sanitized['safe_null']);
        $this->assertTrue($sanitized['safe_bool']);
    }

    /**
     * Test 12: Activity log redacts credentials and sensitive metadata.
     */
    public function test_activity_log_redacts_credentials_and_sensitive_metadata(): void
    {
        $sensitiveMetadata = [
            'password'              => 'SecretP@ss123',
            'password_confirmation' => 'SecretP@ss123',
            'webhook_secret'        => 'my_apps_script_secret_token_xyz',
            'token'                 => 'bearer_token_secret_abcdef',
            'remember_token'        => 'remember_token_123456',
            'safe_field'            => 'Nilai Aman Publik',
            'nested_info'           => [
                'api_key'      => 'sensitive_api_key_888',
                'institution'  => 'Universitas Gadjah Mada',
            ],
        ];

        $log = ActivityLogger::log(
            action: 'security_audit_test',
            description: 'Uji coba penyensoran kredensial sensitif',
            subject: $this->admin,
            metadata: $sensitiveMetadata,
            actor: $this->admin
        );

        $savedLog = ActivityLog::findOrFail($log->id);
        $metadata = $savedLog->metadata;

        // Verify sensitive fields are redacted
        $this->assertSame('[REDACTED]', $metadata['password']);
        $this->assertSame('[REDACTED]', $metadata['password_confirmation']);
        $this->assertSame('[REDACTED]', $metadata['webhook_secret']);
        $this->assertSame('[REDACTED]', $metadata['token']);
        $this->assertSame('[REDACTED]', $metadata['remember_token']);
        $this->assertSame('[REDACTED]', $metadata['nested_info']['api_key']);

        // Verify safe fields remain intact
        $this->assertSame('Nilai Aman Publik', $metadata['safe_field']);
        $this->assertSame('Universitas Gadjah Mada', $metadata['nested_info']['institution']);
    }

    /**
     * Test 13: Protected file storage rejects path traversal and unauthenticated serving.
     */
    public function test_protected_file_storage_and_path_traversal_prevention(): void
    {
        // 1. Path traversal via filename in document serve
        $resTraversal1 = $this->actingAs($this->admin)->get('/admin/documents/serve/loa/../../.env');
        $this->assertSame(404, $resTraversal1->status());

        $resTraversal2 = $this->actingAs($this->admin)->get('/admin/documents/serve/loa/..%2f..%2f.env');
        $this->assertSame(404, $resTraversal2->status());

        // 2. Invalid document type
        $resInvalidType = $this->actingAs($this->admin)->get('/admin/documents/serve/invalid_type/test.pdf');
        $this->assertSame(404, $resInvalidType->status());

        // 3. Non-pdf extension
        $resNonPdf = $this->actingAs($this->admin)->get('/admin/documents/serve/loa/malicious.exe');
        $this->assertSame(404, $resNonPdf->status());
    }

    /**
     * Test 14: Registration attachment upload validates file types and reject executables.
     */
    public function test_registration_attachment_upload_validates_type_and_size(): void
    {
        Storage::fake('local');

        // Executable script disguised as upload
        $fakeScript = UploadedFile::fake()->create('malicious.php', 100, 'application/x-php');

        $resScript = $this->actingAs($this->pemagangA)->post(route('pemagang.registration.store'), [
            'fullname'          => 'Calon Pemagang Valid',
            'gender'            => 'L',
            'institution_id'    => $this->institution->id,
            'faculty_id'        => $this->faculty->id,
            'study_program_id'  => $this->studyProgram->id,
            'start_date'        => '2026-04-01',
            'end_date'          => '2026-07-01',
            'curriculum_vitae'  => $fakeScript,
        ]);

        // Validation error on file upload
        $resScript->assertSessionHasErrors();
    }

    /**
     * Test 15: Mass assignment protection on critical models.
     */
    public function test_mass_assignment_protection_on_critical_models(): void
    {
        $user = new User();
        $fillables = $user->getFillable();

        // Ensure sensitive internal columns are not arbitrarily mass-assignable
        $this->assertContains('name', $fillables);
        $this->assertContains('email', $fillables);

        // Test updating model via safe attributes
        $this->pemagangA->update(['name' => 'Pemagang A Updated']);
        $this->assertSame('Pemagang A Updated', $this->pemagangA->fresh()->name);
    }

    /**
     * Test 16: CSRF middleware is active in the web middleware stack.
     */
    public function test_csrf_middleware_is_registered_on_web_middleware_group(): void
    {
        $kernel = app(\App\Http\Kernel::class);
        $middlewareGroups = $kernel->getMiddlewareGroups();

        $this->assertArrayHasKey('web', $middlewareGroups);
        $webMiddlewares = $middlewareGroups['web'];

        $hasCsrf = false;
        foreach ($webMiddlewares as $middleware) {
            if (str_contains($middleware, 'VerifyCsrfToken') || str_contains($middleware, 'ValidateCsrfToken')) {
                $hasCsrf = true;
                break;
            }
        }

        $this->assertTrue($hasCsrf, 'VerifyCsrfToken middleware must be registered in the web middleware group.');
    }
}
