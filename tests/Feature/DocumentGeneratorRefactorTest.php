<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Division;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\SklDocument;
use App\Models\User;
use App\Services\DocumentGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DocumentGeneratorRefactorTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $hrStaff;
    private User $pemagang;
    private User $otherPemagang;
    private Brand $brand;
    private Division $division;
    private Institution $institution;
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('doc_test_', true);

        $this->admin = User::create([
            'name'     => 'admin_' . substr($this->unique, 0, 8),
            'email'    => 'admin_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'admin',
        ]);

        $this->hrStaff = User::create([
            'name'     => 'hr_' . substr($this->unique, 0, 8),
            'email'    => 'hr_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'hr_staff',
        ]);

        $this->pemagang = User::create([
            'name'     => 'pemagang_' . substr($this->unique, 0, 8),
            'email'    => 'pemagang_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->otherPemagang = User::create([
            'name'     => 'other_' . substr($this->unique, 0, 8),
            'email'    => 'other_' . $this->unique . '@test.com',
            'password' => bcrypt('password123'),
            'role'     => 'pemagang',
        ]);

        $this->institution = Institution::create([
            'name'                => 'Politeknik Refactor ' . $this->unique,
            'address'             => 'Jl. Uji Refactor No. 1',
            'phone'               => '0274-123456',
            'email'               => 'poltek_' . $this->unique . '@test.com',
            'correspondence_unit' => 'BKK',
            'is_active'           => true,
        ]);

        $this->brand = Brand::firstOrCreate(
            ['code' => 'SEVEN_INC'],
            [
                'name'               => 'Seven Inc',
                'company_address'    => 'Yogyakarta',
                'signatory_name'     => 'Rekso Pamungkas',
                'signatory_position' => 'Direktur',
            ]
        );

        $this->division = Division::firstOrCreate(
            ['name' => 'IT Support'],
            [
                'code' => 'ITS',
                'slug' => 'it-support',
            ]
        );
    }

    /**
     * Test validasi kelayakan generasi dokumen sesuai status pemagang.
     */
    public function test_document_service_checks_eligibility_correctly(): void
    {
        $service = app(DocumentGeneratorService::class);

        $internWaiting = new IR(['internship_status' => IR::STATUS_WAITING]);
        $this->assertFalse($service->canGenerate($internWaiting, 'loa'));
        $this->assertFalse($service->canGenerate($internWaiting, 'skl'));

        $internAccepted = new IR(['internship_status' => IR::STATUS_ACCEPTED]);
        $this->assertTrue($service->canGenerate($internAccepted, 'loa'));
        $this->assertFalse($service->canGenerate($internAccepted, 'skl'));

        $internCompleted = new IR(['internship_status' => IR::STATUS_COMPLETED]);
        $this->assertTrue($service->canGenerate($internCompleted, 'loa'));
        $this->assertTrue($service->canGenerate($internCompleted, 'skl'));
        $this->assertTrue($service->canGenerate($internCompleted, 'sertifikat'));
    }

    /**
     * Test isolasi akses target user: Admin & HR Staff dapat menyelesaikan target user lain, pemagang biasa dilarang.
     */
    public function test_admin_and_hr_staff_can_resolve_target_user_cleanly(): void
    {
        $service = app(DocumentGeneratorService::class);

        // Admin dapat menyelesaikan target user lain
        $resolvedByAdmin = $service->resolveTargetUser($this->admin, $this->pemagang->id);
        $this->assertEquals($this->pemagang->id, $resolvedByAdmin->id);

        // HR Staff dapat menyelesaikan target user lain
        $resolvedByHr = $service->resolveTargetUser($this->hrStaff, $this->pemagang->id);
        $this->assertEquals($this->pemagang->id, $resolvedByHr->id);

        // Pemagang menyelesaikan dirinya sendiri
        $selfResolved = $service->resolveTargetUser($this->pemagang, null);
        $this->assertEquals($this->pemagang->id, $selfResolved->id);

        // Pemagang mencoba menyelesaikan user lain -> dilarang 403
        $this->expectException(HttpException::class);
        $service->resolveTargetUser($this->pemagang, $this->otherPemagang->id);
    }

    private function createIntern(User $user, string $status, array $overrides = []): IR
    {
        $cityId = \App\Models\City::first()?->id ?? 1;
        $facultyId = \App\Models\Faculty::first()?->id ?? 1;
        $studyProgId = \App\Models\StudyProgram::first()?->id ?? 1;

        return IR::create(array_merge([
            'user_id'                => $user->id,
            'fullname'               => $user->name,
            'born_date'              => '2001-01-01',
            'gender'                 => 'Laki-laki',
            'phone_number'           => '081234567890',
            'city_id'                => $cityId,
            'student_id'             => '12345678',
            'institution_id'         => $this->institution->id,
            'faculty_id'             => $facultyId,
            'study_program_id'       => $studyProgId,
            'internship_reason'      => 'Pengembangan skill profesional',
            'internship_type'        => 'Magang Mandiri',
            'internship_arrangement' => 'Onsite',
            'current_status'         => 'Mahasiswa/Pelajar',
            'english_book_ability'   => 'Saya bisa',
            'division_id'            => $this->division->id,
            'brand'                  => 'Seven Inc',
            'internship_status'      => $status,
            'start_date'             => Carbon::now()->subMonths(3)->format('Y-m-d'),
            'end_date'               => Carbon::now()->subDays(5)->format('Y-m-d'),
            'internship_interest'    => 'IT Support',
        ], $overrides));
    }

    /**
     * Test admin dapat mengunduh dokumen SKL atas nama pemagang lain dan tercatat di audit log.
     */
    public function test_admin_can_download_skl_for_other_completed_intern_and_audit_log_is_recorded(): void
    {
        $intern = $this->createIntern($this->pemagang, IR::STATUS_COMPLETED, [
            'fullname' => 'Pemagang Lulus ' . $this->unique,
        ]);

        $sklDoc = SklDocument::create([
            'intern_id'            => $intern->id,
            'skl_number'           => 'SKL/TEST/' . $this->unique,
            'company_name'         => 'Seven Inc',
            'signatory_name'       => 'Rekso Pamungkas',
            'signatory_position'   => 'Direktur Utama',
            'created_at'           => now(),
        ]);

        // Simulasikan unduhan SKL oleh Admin
        $response = $this->actingAs($this->admin)->get(route('admin.skl.download.for_user', ['user' => $this->pemagang->id]));

        // Respons bisa berupa PDF download atau eksekusi view yang berhasil
        $this->assertTrue(in_array($response->getStatusCode(), [200, 302]));

        // Pastikan rekam jejak audit tercatat di activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'document_downloaded_for_user',
            'actor_user_id' => $this->admin->id,
            'subject_id'    => $this->pemagang->id,
        ]);
    }

    /**
     * Test Admin dapat men-generate LOA untuk pemagang yang diterima dan tercatat di audit log.
     */
    public function test_admin_can_generate_loa_for_accepted_intern_and_audit_log_is_recorded(): void
    {
        $intern = $this->createIntern($this->otherPemagang, IR::STATUS_ACCEPTED, [
            'fullname'   => 'Pemagang Diterima ' . $this->unique,
            'gender'     => 'Perempuan',
            'start_date' => Carbon::now()->addDays(5)->format('Y-m-d'),
            'end_date'   => Carbon::now()->addMonths(3)->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.loa.generate'), [
            'intern_id' => $intern->id,
        ]);

        // Response mengembalikan download LOA atau redirect sukses
        $this->assertTrue(in_array($response->getStatusCode(), [200, 302]));

        // Pastikan rekam jejak audit tercatat di activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'action'        => 'document_generated_for_user',
            'actor_user_id' => $this->admin->id,
            'subject_id'    => $intern->id,
        ]);
    }
}

