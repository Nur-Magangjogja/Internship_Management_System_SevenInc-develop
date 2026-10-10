<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ArchiveExport;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration as IR;
use App\Models\Mou;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\FollowUpService;
use App\Services\MouService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityAndPrivacyHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaffA;
    private User $hrStaffB;
    private User $pemagang1;
    private User $pemagang2;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('sec_test_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => Hash::make('secretpassword123'),
            'role'     => 'admin',
        ]);

        $this->hrStaffA = User::create([
            'name'     => 'hra_' . substr($this->unique, 0, 8),
            'email'    => 'hra_' . $this->unique . '@test.com',
            'password' => Hash::make('secretpassword123'),
            'role'     => 'hr_staff',
        ]);

        $this->hrStaffB = User::create([
            'name'     => 'hrb_' . substr($this->unique, 0, 8),
            'email'    => 'hrb_' . $this->unique . '@test.com',
            'password' => Hash::make('secretpassword123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang1 = User::create([
            'name'     => 'p1_' . substr($this->unique, 0, 8),
            'email'    => 'p1_' . $this->unique . '@test.com',
            'password' => Hash::make('secretpassword123'),
            'role'     => 'pemagang',
        ]);

        $this->pemagang2 = User::create([
            'name'     => 'p2_' . substr($this->unique, 0, 8),
            'email'    => 'p2_' . $this->unique . '@test.com',
            'password' => Hash::make('secretpassword123'),
            'role'     => 'pemagang',
        ]);
    }

    /**
     * Test ActivityLogger secara ketat menyensor kredensial dan kunci sensitif.
     */
    public function test_activity_logger_redacts_sensitive_keys_thoroughly(): void
    {
        $log = ActivityLogger::log(
            action: 'security_audit_test',
            description: 'Uji penyaringan data sensitif',
            before: [
                'name'         => 'John Doe',
                'password'     => 'PlainPassword123!',
                'bearer_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
            ],
            after: [
                'name'         => 'John Doe Updated',
                'secret_key'   => 'sk_live_1234567890abcdef',
                'access_token' => 'tok_987654321',
                'client_secret'=> 'cs_99887766',
            ],
            metadata: [
                'nested' => [
                    'admin_password' => 'supersecret',
                    'cvv'            => '123',
                    'pin'            => '9988',
                ],
                'public_data' => 'allowed_value',
            ],
            actor: $this->admin
        );

        $this->assertDatabaseHas('activity_logs', [
            'id'     => $log->id,
            'action' => 'security_audit_test',
        ]);

        $fetchedLog = ActivityLog::find($log->id);

        // Pastikan key sensitif tidak pernah tersimpan dalam bentuk raw
        $this->assertEquals('[REDACTED]', $fetchedLog->before_data['password']);
        $this->assertEquals('[REDACTED]', $fetchedLog->before_data['bearer_token']);
        $this->assertEquals('John Doe', $fetchedLog->before_data['name']);

        $this->assertEquals('[REDACTED]', $fetchedLog->after_data['secret_key']);
        $this->assertEquals('[REDACTED]', $fetchedLog->after_data['access_token']);
        $this->assertEquals('[REDACTED]', $fetchedLog->after_data['client_secret']);

        $this->assertEquals('[REDACTED]', $fetchedLog->metadata['nested']['admin_password']);
        $this->assertEquals('[REDACTED]', $fetchedLog->metadata['nested']['cvv']);
        $this->assertEquals('[REDACTED]', $fetchedLog->metadata['nested']['pin']);
        $this->assertEquals('allowed_value', $fetchedLog->metadata['public_data']);
    }

    /**
     * Test ArchiveExport menggunakan public_uuid di route dan menolak akses non-admin.
     */
    public function test_archive_export_uses_public_uuid_and_prevents_unauthorized_download(): void
    {
        $archive = ArchiveExport::create([
            'archive_year'    => 2023,
            'file_name'       => 'archive-2023-test.zip',
            'file_path'       => 'archives/2023/test.zip',
            'file_size_bytes' => 1024,
            'status'          => ArchiveExport::STATUS_COMPLETED,
            'generated_by'    => $this->admin->id,
            'generated_at'    => now(),
        ]);

        $this->assertNotEmpty($archive->public_uuid);

        // URL generator otomatis menggunakan public_uuid
        $downloadUrl = route('admin.archive-exports.download', $archive);
        $this->assertStringContainsString($archive->public_uuid, $downloadUrl);
        $this->assertStringNotContainsString("/archive-exports/{$archive->id}/", $downloadUrl);

        // Pemagang dan HR Staff tidak boleh mengakses route download ini
        $responsePemagang = $this->actingAs($this->pemagang1)->get($downloadUrl);
        $this->assertTrue(in_array($responsePemagang->getStatusCode(), [302, 403]));

        $responseHr = $this->actingAs($this->hrStaffA)->get($downloadUrl);
        $this->assertTrue(in_array($responseHr->getStatusCode(), [302, 403]));
    }

    /**
     * Test FollowUpCycle route binding mendukung public_uuid dan mencegah eksposur ID internal.
     */
    public function test_followup_cycle_resolves_via_public_uuid(): void
    {
        $institution = Institution::create([
            'name'       => 'Politeknik Keamanan ' . $this->unique,
            'status'     => 'active',
            'created_at' => now(),
        ]);

        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dr. Security ' . $this->unique,
            'phone'          => '08988776655',
            'status'         => 'active',
            'created_at'     => now(),
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        $this->assertNotEmpty($cycle->public_uuid);

        // Model binding di route menggunakan public_uuid
        $routeUrl = route('hr.follow-up.show', $cycle);
        $this->assertStringContainsString($cycle->public_uuid, $routeUrl);
        $this->assertStringNotContainsString("/follow-up/{$cycle->id}", $routeUrl);

        // HR Staff dapat mengakses halaman detail menggunakan UUID
        $response = $this->actingAs($this->hrStaffA)->get($routeUrl);
        $response->assertStatus(200);
    }

    /**
     * Test IDOR Prevention: Pemagang tidak dapat mengunduh SKL milik pemagang lain.
     */
    public function test_pemagang_cannot_download_skl_for_other_intern(): void
    {
        $cityId = \App\Models\City::first()?->id ?? 1;
        $facultyId = \App\Models\Faculty::first()?->id ?? 1;
        $studyProgId = \App\Models\StudyProgram::first()?->id ?? 1;
        $divisionId = \App\Models\Division::first()?->id ?? 1;

        $inst = Institution::create([
            'name'   => 'Universitas A ' . $this->unique,
            'status' => 'active',
        ]);

        // Pemagang 1 (target completed)
        $intern1 = IR::create([
            'user_id'                 => $this->pemagang1->id,
            'fullname'                => 'Intern Satu ' . $this->unique,
            'gender'                  => 'Laki-laki',
            'phone_number'           => '081234567890',
            'born_date'               => '2001-01-01',
            'institution_id'          => $inst->id,
            'institution_name'        => $inst->name,
            'city_id'                 => $cityId,
            'faculty_id'              => $facultyId,
            'study_program_id'        => $studyProgId,
            'division_id'             => $divisionId,
            'student_id'              => 'NIM001',
            'internship_reason'       => 'Belajar',
            'internship_type'         => 'Magang Mandiri',
            'internship_arrangement'  => 'Onsite',
            'current_status'          => 'Mahasiswa/Pelajar',
            'english_book_ability'    => 'Saya bisa',
            'internship_status'       => IR::STATUS_COMPLETED,
            'brand'                   => 'Seven Inc',
            'created_at'              => now(),
        ]);

        // Pemagang 2 mencoba mengakses endpoint unduh admin untuk pemagang 1
        $response = $this->actingAs($this->pemagang2)->get(route('admin.skl.download.for_user', ['user' => $this->pemagang1->id]));

        // Wajib ditolak (302 redirect / 403 Forbidden)
        $this->assertTrue(in_array($response->getStatusCode(), [302, 403]));
    }

    /**
     * Test upload signed MOU menolak berkas non-PDF (pencegahan upload spoofing).
     */
    public function test_upload_signed_mou_rejects_non_pdf(): void
    {
        Storage::fake('public');

        $institution = Institution::create([
            'name'       => 'Institut Upload Test ' . $this->unique,
            'status'     => 'active',
            'created_at' => now(),
        ]);

        $mou = Mou::create([
            'institution_id'               => $institution->id,
            'company_mou_number'           => 'MOU/SEC/' . $this->unique,
            'first_party_institution_name' => $institution->name,
            'first_party_signatory_name'   => 'Bapak Kepala',
            'first_party_signatory_title'  => 'Rektor',
            'second_party_company_name'    => 'Seven Inc',
            'second_party_signatory_name'  => 'Rekso Pamungkas',
            'second_party_signatory_title' => 'Direktur',
            'agreement_date'               => now()->format('Y-m-d'),
            'status'                       => Mou::STATUS_DELIVERED,
        ]);

        // Simulasikan file teks biasa berkedok dokumen
        $fakeTxtFile = UploadedFile::fake()->create('malicious.txt', 500, 'text/plain');

        $response = $this->actingAs($this->hrStaffA)->post(route('hr.mous.upload-signed', $mou), [
            'signed_file' => $fakeTxtFile,
            'signed_at'   => now()->format('Y-m-d'),
        ]);

        // Validasi wajib gagal pada field signed_file
        $response->assertSessionHasErrors(['signed_file']);
    }

    /**
     * Test Task Locking Prevention: HR Staff B tidak dapat mencatat percobaan pada task yang dikunci HR Staff A.
     */
    public function test_hr_staff_cannot_record_attempt_on_locked_cycle_by_other_staff(): void
    {
        $institution = Institution::create([
            'name'       => 'Poltek Locking ' . $this->unique,
            'status'     => 'active',
            'created_at' => now(),
        ]);

        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Dosen Terkunci ' . $this->unique,
            'phone'          => '08123445566',
            'status'         => 'active',
            'created_at'     => now(),
        ]);

        $cycle = FollowUpCycle::create([
            'institution_id'         => $institution->id,
            'institution_contact_id' => $contact->id,
            'cycle_year'             => (int) date('Y'),
            'status'                 => FollowUpCycle::STATUS_OPEN,
        ]);

        /** @var FollowUpService $followUpService */
        $followUpService = app(FollowUpService::class);

        // HR Staff A mengunci task follow-up selama 15 menit
        $followUpService->claimLock($cycle, $this->hrStaffA, 15);

        // HR Staff B mencoba mencatat percobaan
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Tugas follow-up ini sedang dikerjakan oleh');

        $followUpService->recordAttempt($cycle, [
            'result'        => FollowUpCycle::RESULT_WILLING,
            'channel'       => 'whatsapp',
            'response_text' => 'Berminat kerja sama',
        ], $this->hrStaffB);
    }
}
