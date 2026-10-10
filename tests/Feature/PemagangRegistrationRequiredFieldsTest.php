<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\InternshipRegistration;
use App\Models\Division;
use Illuminate\Support\Facades\Hash;

class PemagangRegistrationRequiredFieldsTest extends TestCase
{
    public function test_can_submit_registration_with_only_2026_required_contract_fields(): void
    {
        $division = Division::firstOrCreate(['name' => 'Programmer (Front End / Backend)']);

        $user = User::create([
            'name' => 'pemagang_submit_' . time(),
            'email' => 'pemagang_submit_' . time() . '@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
        ]);

        $payload = [
            // Data Diri 2026
            'fullname'     => 'Budi Santoso',
            'gender'       => 'Laki-laki',
            'current_city' => 'Yogyakarta',
            'email'        => $user->email,
            'phone_number' => '081234567890',

            // Data Akademik 2026
            'current_status'   => 'Mahasiswa / Pelajar Aktif',
            'student_id'       => '12345678',
            'institution_name' => 'Universitas Gadjah Mada',
            'study_program'    => 'Teknologi Informasi',

            // Data Magang 2026
            'internship_interest'      => $division->name,
            'internship_type'          => 'Magang Mandiri',
            'internship_reason'        => 'Ingin menambah pengalaman kerja profesional.',
            'wfo_readiness'            => 'Siap WFO di Yogyakarta',
            'schedule_shift_readiness' => 'Sanggup',
            'has_laptop'               => 1,
            'planned_duration'         => '3 Bulan',

            // Field legacy/obsolete (born_date, faculty, english_book_ability) TIDAK DIISI
        ];

        $response = $this->actingAs($user)
            ->post(route('pemagang.registration.store'), $payload);

        $response->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('internship_registrations', [
            'user_id'          => $user->id,
            'fullname'         => 'Budi Santoso',
            'student_id'       => '12345678',
            'wfo_readiness'    => 'Siap WFO di Yogyakarta',
            'has_laptop'       => 1,
            'planned_duration' => '3 Bulan',
            'born_date'        => null,
        ]);

        // Clean up
        InternshipRegistration::where('user_id', $user->id)->forceDelete();
        $user->forceDelete();
    }

    public function test_fails_when_2026_required_contract_fields_are_missing(): void
    {
        $user = User::create([
            'name' => 'pemagang_fail_' . time(),
            'email' => 'pemagang_fail_' . time() . '@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
        ]);

        $response = $this->actingAs($user)
            ->from(route('pemagang.registration.form'))
            ->post(route('pemagang.registration.store'), [
                'email' => $user->email,
            ]);

        $response->assertSessionHasErrors([
            'fullname',
            'gender',
            'current_city',
            'phone_number',
            'current_status',
            'student_id',
            'institution_name',
            'study_program',
            'internship_interest',
            'internship_type',
            'internship_reason',
            'wfo_readiness',
            'schedule_shift_readiness',
            'has_laptop',
            'planned_duration',
        ]);

        // Field legacy tidak boleh ada di error bag
        $response->assertSessionDoesntHaveErrors(['born_date', 'faculty', 'english_book_ability']);

        // Clean up
        $user->forceDelete();
    }
}
