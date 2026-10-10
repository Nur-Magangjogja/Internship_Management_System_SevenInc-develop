<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ArchiveExport;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\InstitutionContact;
use App\Models\InternshipRegistration as IR;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Models\StudyProgram;
use App\Models\User;
use App\Services\ArchiveExportService;
use App\Services\FollowUpService;
use App\Services\MouService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FullSystemRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('uat_reg_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => Hash::make('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'hr_' . substr($this->unique, 0, 8),
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => Hash::make('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => Hash::make('password123'),
            'role'     => 'pemagang',
        ]);
    }

    /**
     * Skenario UAT 1: Pemagang mendaftar akun, mengisi data pendaftaran, dan login dashboard.
     */
    public function test_uat_pemagang_lifecycle(): void
    {
        // 1. Registrasi akun baru pendaftar
        $regEmail = 'calon_' . $this->unique . '@test.com';
        $regResponse = $this->post(route('user.register.submit'), [
            'username'              => 'calon_' . substr($this->unique, 0, 8),
            'email'                 => $regEmail,
            'password'              => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $regResponse->assertRedirect(route('pemagang.registration.form'));

        $newPendaftar = User::where('email', $regEmail)->first();
        $this->assertNotNull($newPendaftar);
        $this->assertEquals('user', $newPendaftar->role);

        // 2. Akses dashboard pemagang oleh pemagang yang sudah ada
        $dashResponse = $this->actingAs($this->pemagang)->get(route('pemagang.dashboard'));
        $dashResponse->assertStatus(200);

        // 3. Pemagang dilarang mengakses modul Admin atau HR
        $adminAttempt = $this->actingAs($this->pemagang)->get(route('admin.dashboard.index'));
        $this->assertTrue(in_array($adminAttempt->getStatusCode(), [302, 403]));

        $hrAttempt = $this->actingAs($this->pemagang)->get(route('hr.dashboard'));
        $this->assertTrue(in_array($hrAttempt->getStatusCode(), [302, 403]));
    }

    /**
     * Skenario UAT 2: HR Staff mengelola follow-up kemitraan dan siklus MOU sampai aktif.
     */
    public function test_uat_hr_staff_followup_and_mou_workflow(): void
    {
        Storage::fake('public');

        // 1. HR melihat dashboard operasional
        $responseDash = $this->actingAs($this->hrStaff)->get(route('hr.dashboard'));
        $responseDash->assertStatus(200);

        // 2. Siapkan Institusi & Kontak
        $institution = Institution::create([
            'name'                => 'Universitas UAT ' . $this->unique,
            'address'             => 'Jl. UAT No. 99, Yogyakarta',
            'phone'               => '0274-998877',
            'email'               => 'uat_' . $this->unique . '@test.ac.id',
            'correspondence_unit' => 'Biro Kerja Sama',
            'status'              => 'active',
            'created_at'          => now(),
        ]);

        $contact = InstitutionContact::create([
            'institution_id' => $institution->id,
            'name'           => 'Prof. Dr. UAT Mitra ' . $this->unique,
            'position'       => 'Dekan Fakultas Teknik',
            'phone'          => '081299887766',
            'email'          => 'dekan_' . $this->unique . '@test.ac.id',
            'status'         => 'active',
            'is_verified'    => true,
            'created_at'     => now(),
        ]);

        // 3. Buat Siklus Follow-up
        /** @var FollowUpService $followUpService */
        $followUpService = app(FollowUpService::class);
        $cycle = $followUpService->createCycle($contact, (int)date('Y'), $this->hrStaff);
        $this->assertEquals(FollowUpCycle::STATUS_OPEN, $cycle->status);

        // 4. HR mengklaim tugas (Claim Lock)
        $followUpService->claimLock($cycle, $this->hrStaff, 20);
        $this->assertTrue($cycle->fresh()->isLocked());
        $this->assertEquals($this->hrStaff->id, $cycle->fresh()->locked_by);

        // 5. Catat Percobaan 1 (Attempt)
        $attempt = $followUpService->recordAttempt($cycle, [
            'result'           => FollowUpCycle::RESULT_CALL_BACK,
            'channel'          => 'whatsapp',
            'response_text'    => 'Sedang rapat, hubungi kembali siang ini.',
            'next_followup_at' => now()->addHours(3)->format('Y-m-d H:i:s'),
        ], $this->hrStaff);
        $this->assertEquals(1, $cycle->fresh()->current_attempt_count);

        // 6. Catat Percobaan 2 (Hasil: Willing / Bersedia MOU)
        $followUpService->claimLock($cycle, $this->hrStaff, 20);
        $attempt2 = $followUpService->recordAttempt($cycle, [
            'result'        => FollowUpCycle::RESULT_WILLING,
            'channel'       => 'whatsapp',
            'response_text' => 'Fakultas setuju menjalin kerja sama magang Seven Inc.',
        ], $this->hrStaff);
        $this->assertEquals(2, $cycle->fresh()->current_attempt_count);

        // 7. Selesaikan Siklus (Complete Cycle)
        $followUpService->claimLock($cycle, $this->hrStaff, 20);
        $followUpService->completeCycle(
            $cycle,
            FollowUpCycle::RESULT_WILLING,
            'Mitra setuju dan siap menandatangani draft naskah MOU.',
            $this->hrStaff
        );
        $this->assertEquals(FollowUpCycle::STATUS_COMPLETED, $cycle->fresh()->status);

        // 8. Generate Draft MOU
        $template = MouTemplate::create([
            'name'          => 'Template Standar UAT ' . $this->unique,
            'template_type' => 'standar',
            'template_html' => '<div>Surat Perjanjian [company_mou_number] antara [institution_name] dan [company_name]</div>',
            'is_active'     => true,
            'created_by'    => $this->admin->id,
        ]);

        /** @var MouService $mouService */
        $mouService = app(MouService::class);
        $companyMouNumber = '001/MOU/SI-UAT/' . date('Y');

        $mou = $mouService->generateMou([
            'institution_id'                  => $institution->id,
            'followup_cycle_id'               => $cycle->id,
            'mou_template_id'                 => $template->id,
            'company_mou_number'              => $companyMouNumber,
            'first_party_institution_name'    => $institution->name,
            'first_party_signatory_name'      => $contact->name,
            'first_party_signatory_title'     => $contact->position,
            'first_party_address'             => $institution->address,
            'second_party_company_name'       => 'Seven Inc',
            'second_party_signatory_name'     => 'Rekso Pamungkas',
            'second_party_signatory_title'    => 'Direktur Utama',
            'agreement_place'                 => 'Yogyakarta',
            'agreement_date'                  => now()->format('Y-m-d'),
            'effective_start'                 => now()->format('Y-m-d'),
            'effective_end'                   => now()->addYears(2)->format('Y-m-d'),
        ], $this->hrStaff);

        $this->assertEquals(Mou::STATUS_GENERATED, $mou->status);

        // 9. Serahkan naskah (Mark Delivered)
        $mouService->markDelivered($mou, [
            'delivered_at'       => now()->format('Y-m-d H:i:s'),
            'delivery_method'    => 'courier',
            'delivery_recipient' => $contact->name,
        ], $this->hrStaff);
        $this->assertEquals(Mou::STATUS_DELIVERED, $mou->fresh()->status);

        // 10. Unggah berkas signed (Upload Signed -> Status Active)
        $fakePdf = UploadedFile::fake()->create('mou-signed.pdf', 800, 'application/pdf');
        $mouService->uploadSignedMou($mou, $fakePdf, [
            'signed_at'              => now()->format('Y-m-d'),
            'institution_mou_number' => 'MOU-UNIV/001/2026',
        ], $this->hrStaff);

        $this->assertEquals(Mou::STATUS_ACTIVE, $mou->fresh()->status);
        $this->assertNotNull($mou->fresh()->signed_file_path);
    }

    /**
     * Skenario UAT 3: Admin mengelola sistem, monitoring log aktivitas, dan ekspor arsip tahunan.
     */
    public function test_uat_admin_monitoring_and_annual_export(): void
    {
        // 1. Admin login dan akses dashboard utama
        $responseAdminDash = $this->actingAs($this->admin)->get(route('admin.dashboard.index'));
        $responseAdminDash->assertStatus(200);

        // 2. Admin membuka Log Aktivitas Terpusat
        $responseLogs = $this->actingAs($this->admin)->get(route('admin.activity-logs.index'));
        $responseLogs->assertStatus(200);

        // 3. Admin meng-generate paket arsip tahunan
        /** @var ArchiveExportService $archiveService */
        $archiveService = app(ArchiveExportService::class);
        $archive = $archiveService->generateArchive(2023, $this->admin);

        $this->assertEquals(2023, $archive->archive_year);
        $this->assertEquals(ArchiveExport::STATUS_COMPLETED, $archive->status);

        // 4. Unduh berkas ZIP arsip menggunakan public_uuid
        $responseDownload = $this->actingAs($this->admin)->get(route('admin.archive-exports.download', $archive));
        $responseDownload->assertStatus(200);
        $responseDownload->assertHeader('content-type', 'application/zip');

        // Bersihkan berkas setelah pengujian
        $archiveService->deleteArchive($archive);
    }
}
