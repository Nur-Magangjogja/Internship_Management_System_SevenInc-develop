<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternCertificate;
use App\Models\InternLoa;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PemagangNewDocumentBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected User $pemagang;
    protected InternshipRegistration $registration;

    protected function setUp(): void
    {
        parent::setUp();

        $city = City::create(['name' => 'Yogyakarta']);
        $institution = Institution::create(['name' => 'Universitas Gadjah Mada', 'type' => 'universitas']);
        $faculty = Faculty::create(['name' => 'Fakultas Teknik', 'institution_id' => $institution->id]);
        $studyProgram = StudyProgram::create(['name' => 'Teknologi Informasi', 'faculty_id' => $faculty->id]);
        $division = Division::create(['name' => 'IT Development']);

        $this->pemagang = User::create([
            'name'     => 'Pemagang Test',
            'email'    => 'pemagang.badge@example.com',
            'password' => bcrypt('password123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);

        $this->registration = InternshipRegistration::create([
            'user_id'                  => $this->pemagang->id,
            'fullname'                 => 'Pemagang Test',
            'born_date'                => '2001-01-01',
            'gender'                   => 'Laki-laki',
            'phone_number'             => '081234567890',
            'student_id'               => '123456',
            'city_id'                  => $city->id,
            'institution_id'           => $institution->id,
            'faculty_id'               => $faculty->id,
            'study_program_id'         => $studyProgram->id,
            'internship_reason'        => 'Belajar',
            'internship_type'          => 'Mandiri',
            'internship_arrangement'   => 'Onsite',
            'current_status'           => 'Mahasiswa',
            'english_book_ability'     => 'Bisa',
            'division_id'              => $division->id,
            'internship_status'        => InternshipRegistration::STATUS_PENDING,
        ]);
    }

    public function test_pending_pemagang_has_no_unread_documents_badge(): void
    {
        $this->assertFalse($this->pemagang->hasUnreadDocuments());

        $response = $this->actingAs($this->pemagang)->get(route('pemagang.dashboard'));
        $response->assertStatus(200);
        $response->assertDontSee('Ada file baru');
    }

    public function test_accepted_pemagang_with_loa_sees_unread_document_badge_until_opened(): void
    {
        // 1. Ubah status menjadi accepted dan buat LOA
        $this->registration->update([
            'internship_status' => InternshipRegistration::STATUS_ACCEPTED,
        ]);

        InternLoa::create([
            'intern_id'           => $this->registration->id,
            'loa_number'          => 'LOA/TEST/001',
            'accepted_start_date' => '2026-01-01',
            'accepted_end_date'   => '2026-06-30',
            'created_at'          => now()->subMinute(),
        ]);

        $this->assertTrue($this->pemagang->fresh()->hasUnreadDocuments());

        // 2. Pada halaman dashboard, badge lingkaran merah penanda file baru terlihat
        $response = $this->actingAs($this->pemagang)->get(route('pemagang.dashboard'));
        $response->assertStatus(200);
        $response->assertSee('Ada file baru');
        $response->assertSee('bg-red-500 ring-2 ring-white/20');

        // 3. Pemagang membuka halaman Dokumen Saya
        $docResponse = $this->actingAs($this->pemagang)->get(route('pemagang.documents'));
        $docResponse->assertStatus(200);
        // Pada halaman dokumen yang sedang aktif, badge disembunyikan
        $docResponse->assertDontSee('Ada file baru');

        // 4. Setelah dibuka, dokumen ditandai telah dibaca di database dan cache
        $this->assertNotNull($this->pemagang->fresh()->documents_viewed_at);
        $this->assertFalse($this->pemagang->fresh()->hasUnreadDocuments());

        // 5. Saat kembali ke dashboard, badge menghilang
        $dashResponse = $this->actingAs($this->pemagang)->get(route('pemagang.dashboard'));
        $dashResponse->assertStatus(200);
        $dashResponse->assertDontSee('Ada file baru');
    }

    public function test_badge_reappears_when_new_certificate_is_issued(): void
    {
        // 1. Pemagang sudah pernah membuka dokumen sebelumnya
        $this->registration->update([
            'internship_status' => InternshipRegistration::STATUS_COMPLETED,
        ]);
        $this->pemagang->markDocumentsAsViewed();
        $this->assertFalse($this->pemagang->fresh()->hasUnreadDocuments());

        // 2. Admin menerbitkan sertifikat baru
        InternCertificate::create([
            'intern_id'          => $this->registration->id,
            'certificate_number' => 'CERT/TEST/001',
            'created_at'         => now()->addMinute(),
        ]);

        // 3. Badge harus kembali muncul karena ada file baru setelah last viewed
        $this->assertTrue($this->pemagang->fresh()->hasUnreadDocuments());

        $response = $this->actingAs($this->pemagang)->get(route('pemagang.dashboard'));
        $response->assertStatus(200);
        $response->assertSee('Ada file baru');
    }
}
