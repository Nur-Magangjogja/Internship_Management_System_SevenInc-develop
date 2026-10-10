<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\InternshipRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class AbsenSyncApiTest extends TestCase
{
    protected string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiKey = config('services.absen_djuragan.api_key', 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c');
    }

    public function test_health_check_requires_valid_api_key(): void
    {
        // Tanpa API Key
        $response = $this->getJson('/api/v1/external/health');
        $response->assertStatus(401);

        // Dengan API Key yang salah
        $response = $this->withHeaders(['X-API-KEY' => 'invalid_key'])->getJson('/api/v1/external/health');
        $response->assertStatus(401);

        // Dengan API Key yang benar
        $response = $this->withHeaders(['X-API-KEY' => $this->apiKey])->getJson('/api/v1/external/health');
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_register_from_absen_creates_user_and_internship_record(): void
    {
        $testEmail = 'absen_test_' . time() . '@example.com';

        $division = Division::firstOrCreate([
            'name' => 'Programmer',
        ], [
            'slug'      => 'programmer',
            'code'      => 'PROG',
            'is_active' => true,
        ]);

        $payload = [
            'email'            => $testEmail,
            'full_name'        => 'Ahmad Test Absen',
            'phone'            => '081298765432',
            'gender'           => 'Laki-laki',
            'date_of_birth'    => '2002-08-17',
            'birth_place'      => 'Yogyakarta',
            'religion'         => 'Islam',
            'school_name'      => 'Universitas Gadjah Mada',
            'nim'              => '123456789',
            'is_gps_available' => true,
            'division_name'    => 'Programmer (Front End / Backend)',
        ];

        $response = $this->withHeaders([
            'X-API-KEY' => $this->apiKey,
        ])->postJson('/api/v1/external/register-from-absen', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'email'          => $testEmail,
            'name'           => 'Ahmad Test Absen',
            'is_gps_support' => 1,
        ]);

        $this->assertDatabaseHas('internship_registrations', [
            'fullname'    => 'Ahmad Test Absen',
            'birth_place' => 'Yogyakarta',
            'religion'    => 'Islam',
            'division_id' => $division->id,
        ]);

        // Cleanup
        $user = User::where('email', $testEmail)->first();
        if ($user) {
            InternshipRegistration::where('user_id', $user->id)->forceDelete();
            $user->forceDelete();
        }
    }
}
