<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\City;
use App\Models\Division;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\StudyProgram;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarAttendance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebinarNotificationBadgeTest extends TestCase
{
    use DatabaseTransactions;

    protected User $pemagang;
    protected Brand $brand;
    protected InternshipRegistration $registration;

    protected function setUp(): void
    {
        parent::setUp();

        $city = City::firstOrCreate(['name' => 'Yogyakarta Test City']);
        $institution = Institution::firstOrCreate(
            ['name' => 'Universitas Test Badge'],
            ['type' => 'universitas']
        );
        $faculty = Faculty::firstOrCreate(
            ['name' => 'Fakultas Test Badge', 'institution_id' => $institution->id]
        );
        $studyProgram = StudyProgram::firstOrCreate(
            ['name' => 'Informatika Test Badge', 'faculty_id' => $faculty->id]
        );
        $division = Division::firstOrCreate(['name' => 'IT Badge Test']);

        $this->brand = Brand::firstOrCreate(
            ['code' => 'SI'],
            [
                'name'               => 'SEVEN INC',
                'company_address'    => 'Jl. Test No. 1, Yogyakarta',
                'signatory_name'     => 'Direktur Test',
                'signatory_position' => 'Direktur',
            ]
        );

        $this->pemagang = User::create([
            'name'     => 'Pemagang Webinar Badge Test',
            'email'    => 'pemagang.webinar.badge.' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);

        $this->registration = InternshipRegistration::create([
            'user_id'                => $this->pemagang->id,
            'fullname'               => 'Pemagang Webinar Badge Test',
            'born_date'              => '2001-01-01',
            'gender'                 => 'Laki-laki',
            'phone_number'           => '081234567890',
            'student_id'             => '12345678',
            'city_id'                => $city->id,
            'institution_id'         => $institution->id,
            'faculty_id'             => $faculty->id,
            'study_program_id'       => $studyProgram->id,
            'internship_reason'      => 'Belajar',
            'internship_type'        => 'Mandiri',
            'internship_arrangement' => 'Onsite',
            'current_status'         => 'Mahasiswa',
            'english_book_ability'   => 'Bisa',
            'division_id'            => $division->id,
            'brand_id'               => $this->brand->id,
            'brand'                  => $this->brand->name,
            'is_draft'               => false,
            'internship_status'      => InternshipRegistration::STATUS_ACCEPTED,
        ]);
    }

    /**
     * Test bahwa webinar yang sudah selesai (event_date di masa lalu)
     * tidak menimbulkan count indikator pada menu Webinar.
     */
    public function test_finished_webinar_does_not_show_count_indicator(): void
    {
        // Buat webinar yang sudah selesai (event_date 2 hari lalu)
        Webinar::create([
            'title'          => 'Webinar Selesai Kemarin',
            'event_date'     => now()->subDays(2),
            'brand_id'       => $this->brand->id,
            'allowed_brands' => null,
            'created_at'     => now()->subDays(1),
        ]);

        $response = $this->actingAs($this->pemagang)->get(route('pemagang.webinar.index'));

        $response->assertStatus(200);
        // Indikator angka badge tidak boleh muncul di sidebar layout
        $response->assertDontSee('#ef4444;color:#fff;padding:1px 6px;border-radius:100px', false);
    }

    /**
     * Test bahwa webinar yang akan datang (event_date di masa depan)
     * menimbulkan count indikator bagi pemagang yang belum hadir.
     */
    public function test_upcoming_webinar_shows_count_indicator(): void
    {
        // Buat webinar yang akan datang (event_date 2 hari ke depan)
        $upcoming = Webinar::create([
            'title'          => 'Webinar Masa Depan',
            'event_date'     => now()->addDays(2),
            'brand_id'       => $this->brand->id,
            'allowed_brands' => null,
        ]);

        $response = $this->actingAs($this->pemagang)->get(route('pemagang.webinar.index'));

        $response->assertStatus(200);
        $response->assertSee('#ef4444;color:#fff;padding:1px 6px;border-radius:100px', false);
        $response->assertSee('>1</span>', false);

        // Setelah pemagang mendaftar / menghadiri webinar tersebut, count hilang
        WebinarAttendance::create([
            'webinar_id' => $upcoming->id,
            'user_id'    => $this->pemagang->id,
            'status'     => WebinarAttendance::STATUS_PENDING,
            'proof_file' => 'webinar-proofs/fake.jpg',
        ]);

        $responseAfterAttending = $this->actingAs($this->pemagang)->get(route('pemagang.webinar.index'));
        $responseAfterAttending->assertDontSee('#ef4444;color:#fff;padding:1px 6px;border-radius:100px', false);
    }

    /**
     * Test bahwa webinar dengan batasan brand lain tidak memunculkan indikator bagi pemagang.
     */
    public function test_webinar_for_different_brand_does_not_show_count_indicator(): void
    {
        // Buat webinar mendatang khusus brand 'RW', sedangkan pemagang ber-brand 'SI'
        Webinar::create([
            'title'          => 'Webinar Brand Lain',
            'event_date'     => now()->addDays(3),
            'brand_id'       => $this->brand->id,
            'allowed_brands' => ['RW'],
        ]);

        $response = $this->actingAs($this->pemagang)->get(route('pemagang.webinar.index'));

        $response->assertStatus(200);
        $response->assertDontSee('#ef4444;color:#fff;padding:1px 6px;border-radius:100px', false);
    }
}
