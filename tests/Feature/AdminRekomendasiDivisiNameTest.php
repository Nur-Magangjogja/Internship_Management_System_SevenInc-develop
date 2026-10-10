<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\City;
use App\Models\Institution;
use App\Models\Faculty;
use App\Models\StudyProgram;
use App\Models\Division;
use App\Models\Brand;
use App\Models\InternshipRegistration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;

class AdminRekomendasiDivisiNameTest extends TestCase
{
    use DatabaseTransactions;
    public function test_rekomendasi_preview_uses_division_name_instead_of_code_for_placeholder(): void
    {
        $admin = User::create([
            'name'     => 'admin_rek_' . time(),
            'email'    => 'admin_rek_' . time() . '@example.com',
            'password' => Hash::make('password123'),
            'role'     => 'admin',
        ]);

        $city = City::first() ?? City::create(['name' => 'Yogyakarta']);
        $inst = Institution::first() ?? Institution::create(['name' => 'Universitas Contoh', 'type' => 'Universitas']);
        $fac  = Faculty::first() ?? Faculty::create(['name' => 'Fakultas Teknik', 'institution_id' => $inst->id]);
        $sp   = StudyProgram::first() ?? StudyProgram::create(['name' => 'Informatika', 'faculty_id' => $fac->id]);
        
        $division = Division::firstOrCreate(
            ['code' => 'DKPW'],
            [
                'name' => 'Divisi Khusus Pengembangan Web',
                'slug' => 'divisi-khusus-pengembangan-web-' . time(),
            ]
        );

        $brand = Brand::firstOrCreate(
            ['code' => 'BRT'],
            [
                'name'               => 'Brand Rekomendasi Test',
                'company_address'    => 'Jl. Test No. 123',
                'signatory_name'     => 'Direktur Utama',
                'signatory_position' => 'Direktur',
            ]
        );

        $user = User::create([
            'name'     => 'u_rek_' . time(),
            'email'    => 'u_rek_' . time() . '@example.com',
            'password' => Hash::make('password123'),
            'role'     => 'pemagang',
        ]);

        $intern = InternshipRegistration::create([
            'user_id'                => $user->id,
            'fullname'               => 'Budi Pemagang Rekomendasi',
            'born_date'              => '2000-01-01',
            'gender'                 => 'Laki-laki',
            'phone_number'           => '08123456789',
            'city_id'                => $city->id,
            'student_id'             => '12345678',
            'institution_id'         => $inst->id,
            'faculty_id'             => $fac->id,
            'study_program_id'       => $sp->id,
            'internship_reason'      => 'Belajar',
            'internship_type'        => 'Magang Mandiri',
            'internship_arrangement' => 'Onsite',
            'current_status'         => 'Mahasiswa/Pelajar',
            'english_book_ability'   => 'Saya bisa',
            'division_id'            => $division->id,
            'brand_id'               => $brand->id,
            'internship_status'      => InternshipRegistration::STATUS_COMPLETED,
        ]);

        // Cek preview rekomendasi
        $response = $this->actingAs($admin)->get(route('admin.rekomendasi.preview', [
            'intern_id'     => $intern->id,
            'brand_id'      => $brand->id,
            'body_template' => 'Telah magang sebagai {divisi} di {company_brand}.',
        ]));

        $response->assertStatus(200);

        // Nomor surat tetap menggunakan code
        $response->assertSee('/SR/DKPW/SEVEN.BRT/', false);

        // Isi surat {divisi} HARUS menggunakan name ('Divisi Khusus Pengembangan Web'), BUKAN code ('DKPW')
        $response->assertSee('Telah magang sebagai Divisi Khusus Pengembangan Web di Brand Rekomendasi Test.');
        $response->assertDontSee('Telah magang sebagai DKPW di Brand Rekomendasi Test.');

        // Cleanup dilakukan secara otomatis oleh DatabaseTransactions rollback.
    }
}
