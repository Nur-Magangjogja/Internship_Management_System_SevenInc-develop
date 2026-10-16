<?php

namespace Tests\Feature;

use App\Http\Middleware\AbsenApiKeyMiddleware;
use App\Models\Division;
use App\Models\InternshipRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AbsenSyncApiTest extends TestCase
{
    use DatabaseTransactions;

    protected string $testApiKey = 'test_secret_key_phase_a_12345';
    protected string $inboundKey = 'test_inbound_secret_phase_c1a_67890';

    protected function tearDown(): void
    {
        \Mockery::close();
        Schema::clearResolvedInstances();
        parent::tearDown();
    }

    /**
     * Memverifikasi fail-closed (503 Service Unavailable) jika server belum mengonfigurasi API key.
     */
    public function test_health_check_fails_503_when_api_key_is_unconfigured(): void
    {
        Config::set('services.absen_djuragan.api_key', null);
        Config::set('services.absen_djuragan.inbound_api_key', null);
        putenv('ABSEN_DJURAGAN_API_KEY=');
        putenv('ABSEN_DJURAGAN_INBOUND_API_KEY=');

        $response = $this->withHeaders(['X-API-KEY' => 'any_key'])
            ->getJson('/api/v1/external/health');

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'message' => 'Layanan API eksternal belum dikonfigurasi pada server.',
            ]);
    }

    /**
     * Memverifikasi penolakan (401 Unauthorized) saat request tidak menyertakan API key.
     */
    public function test_health_check_fails_401_without_api_key(): void
    {
        Config::set('services.absen_djuragan.api_key', $this->testApiKey);

        $response = $this->getJson('/api/v1/external/health');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. Kunci API (X-API-KEY) tidak valid atau tidak disertakan.',
            ]);
    }

    /**
     * Memverifikasi penolakan (401 Unauthorized) saat API key tidak cocok.
     */
    public function test_health_check_fails_401_with_invalid_api_key(): void
    {
        Config::set('services.absen_djuragan.api_key', $this->testApiKey);

        $response = $this->withHeaders(['X-API-KEY' => 'wrong_api_key_value'])
            ->getJson('/api/v1/external/health');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. Kunci API (X-API-KEY) tidak valid atau tidak disertakan.',
            ]);
    }

    /**
     * Memverifikasi health check sukses (200 OK) dengan struktur JSON terstandarisasi.
     */
    public function test_health_check_succeeds_200_with_valid_api_key(): void
    {
        Config::set('services.absen_djuragan.api_key', $this->testApiKey);

        $response = $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->getJson('/api/v1/external/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'server_time',
            ])
            ->assertJson([
                'success' => true,
                'message' => 'Internship Management System External API is healthy and connected.',
            ]);
    }

    /**
     * Memverifikasi fail-closed (503 Service Unavailable) jika feature flag inbound dinonaktifkan.
     */
    public function test_inbound_endpoint_fails_503_when_inbound_feature_flag_is_disabled(): void
    {
        Config::set('services.absen_djuragan.inbound_api_key', $this->inboundKey);
        Config::set('services.absen_djuragan.inbound_enabled', false);

        $response = $this->withHeaders(['X-API-KEY' => $this->inboundKey])
            ->postJson('/api/v1/external/register-from-absen', [
                'source_system'            => 'absen_djuragan',
                'external_user_id'         => 'ext-usr-100',
                'external_registration_id' => 'ext-reg-200',
                'email'                    => 'candidate@example.com',
                'name'                     => 'Candidate User',
            ]);

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'message' => 'Layanan inbound registration API saat ini dinonaktifkan.',
            ]);
    }

    /**
     * Memverifikasi penolakan (503 Service Unavailable) jika skema database belum siap (missing required columns/tables).
     */
    public function test_inbound_endpoint_fails_503_when_schema_dependencies_are_missing(): void
    {
        Config::set('services.absen_djuragan.inbound_api_key', $this->inboundKey);
        Config::set('services.absen_djuragan.inbound_enabled', true);

        // Simulasi skema kolom birth_place / religion belum ada
        Schema::shouldReceive('hasColumns')
            ->once()
            ->with('internship_registrations', ['birth_place', 'religion'])
            ->andReturn(false);

        $response = $this->withHeaders(['X-API-KEY' => $this->inboundKey])
            ->postJson('/api/v1/external/register-from-absen', [
                'source_system'            => 'absen_djuragan',
                'external_user_id'         => 'ext-usr-100',
                'external_registration_id' => 'ext-reg-200',
                'email'                    => 'candidate@example.com',
                'name'                     => 'Candidate User',
            ]);

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'message' => 'Dependensi skema database target belum terpenuhi (kolom birth_place / religion tidak tersedia).',
            ]);

        Schema::clearResolvedInstances();
    }

    /**
     * Memverifikasi penolakan (422 Unprocessable Entity) jika request menyertakan password / password_hash.
     */
    public function test_inbound_endpoint_fails_422_when_password_or_password_hash_is_provided(): void
    {
        Config::set('services.absen_djuragan.inbound_api_key', $this->inboundKey);
        Config::set('services.absen_djuragan.inbound_enabled', true);

        // Uji dengan field password
        $response1 = $this->withHeaders(['X-API-KEY' => $this->inboundKey])
            ->postJson('/api/v1/external/register-from-absen', [
                'source_system'            => 'absen_djuragan',
                'external_user_id'         => 'ext-usr-100',
                'external_registration_id' => 'ext-reg-200',
                'email'                    => 'candidate@example.com',
                'name'                     => 'Candidate User',
                'password'                 => 'secret_attempt_123',
            ]);

        $response1->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Field kredensial (password / password_hash) dilarang dikirim melalui API integrasi.',
            ]);

        // Uji dengan field password_hash
        $response2 = $this->withHeaders(['X-API-KEY' => $this->inboundKey])
            ->postJson('/api/v1/external/register-from-absen', [
                'source_system'            => 'absen_djuragan',
                'external_user_id'         => 'ext-usr-100',
                'external_registration_id' => 'ext-reg-200',
                'email'                    => 'candidate@example.com',
                'name'                     => 'Candidate User',
                'password_hash'            => '$2y$10$abcdefghijklmnopqrstuvwxyz1234567890',
            ]);

        $response2->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Field kredensial (password / password_hash) dilarang dikirim melalui API integrasi.',
            ]);
    }

    /**
     * Memverifikasi validasi input (422 Unprocessable Entity) saat field wajib tidak disertakan.
     */
    public function test_inbound_endpoint_fails_422_when_required_fields_are_missing(): void
    {
        Config::set('services.absen_djuragan.inbound_api_key', $this->inboundKey);
        Config::set('services.absen_djuragan.inbound_enabled', true);

        $response = $this->withHeaders(['X-API-KEY' => $this->inboundKey])
            ->postJson('/api/v1/external/register-from-absen', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
            ]);
    }

    /**
     * Memverifikasi anti-hijacking (403 Forbidden) jika email yang dikirim dimiliki akun staf (Admin/HR/Superadmin).
     */
    public function test_inbound_endpoint_fails_403_when_email_belongs_to_internal_staff(): void
    {
        Config::set('services.absen_djuragan.inbound_api_key', $this->inboundKey);
        Config::set('services.absen_djuragan.inbound_enabled', true);

        $adminUser = User::create([
            'name'     => 'admin_test_staff_' . uniqid(),
            'email'    => 'admin_staff_' . uniqid() . '@example.com',
            'password' => Hash::make('AdminPass123!'),
            'role'     => User::ROLE_ADMIN,
        ]);

        $response = $this->withHeaders(['X-API-KEY' => $this->inboundKey])
            ->postJson('/api/v1/external/register-from-absen', [
                'source_system'            => 'absen_djuragan',
                'external_user_id'         => 'ext-usr-staff-1',
                'external_registration_id' => 'ext-reg-staff-1',
                'email'                    => $adminUser->email,
                'name'                     => 'Malicious Attempt Overwrite',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Email terdaftar sebagai staf internal. Registrasi pemagang via API ditolak.',
            ]);
    }

    /**
     * Memverifikasi fondasi validasi Phase C.1A dan menjamin zero database mutation
     * (tidak ada baris user atau pendaftaran baru yang dibuat).
     */
    public function test_inbound_endpoint_validates_foundation_without_mutating_database(): void
    {
        Config::set('services.absen_djuragan.inbound_api_key', $this->inboundKey);
        Config::set('services.absen_djuragan.inbound_enabled', true);

        $userCountBefore = \Illuminate\Support\Facades\DB::table('users')->count();
        $registrationCountBefore = \Illuminate\Support\Facades\DB::table('internship_registrations')->count();

        $email = 'candidate_clean_' . uniqid() . '@example.com';

        $response = $this->withHeaders(['X-API-KEY' => $this->inboundKey])
            ->postJson('/api/v1/external/register-from-absen', [
                'source_system'            => 'absen_djuragan',
                'external_user_id'         => 'ext-usr-999',
                'external_registration_id' => 'ext-reg-888',
                'email'                    => $email,
                'name'                     => 'Budi Pratama',
                'gender'                   => 'L',
                'birth_place'              => 'Yogyakarta',
                'religion'                 => 'Islam',
                'phone'                    => '081234567890',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Fondasi keamanan inbound API tervalidasi. Penulisan data ditangguhkan menunggu otorisasi Phase C.1B.',
                'data'    => [
                    'source_system'            => 'absen_djuragan',
                    'external_user_id'         => 'ext-usr-999',
                    'external_registration_id' => 'ext-reg-888',
                    'email'                    => $email,
                    'user_status'              => 'new_pemagang_candidate',
                    'schema_ready'             => true,
                    'mutations_performed'      => false,
                ],
            ]);

        // Verifikasi mutlak: TIDAK ADA DATA DITULIS KE DATABASE pada Phase C.1A
        $this->assertSame($userCountBefore, \Illuminate\Support\Facades\DB::table('users')->count(), 'Zero User mutation invariant violated!');
        $this->assertSame($registrationCountBefore, \Illuminate\Support\Facades\DB::table('internship_registrations')->count(), 'Zero InternshipRegistration mutation invariant violated!');
        $this->assertNull(\Illuminate\Support\Facades\DB::table('users')->where('email', $email)->first());
    }

    /**
     * Memverifikasi pendaftaran langsung dari Web Absen (feat--API-to-web-absen).
     */
    public function test_register_from_absen_creates_user_and_internship_record(): void
    {
        Config::set('services.absen_djuragan.api_key', $this->inboundKey);
        Config::set('services.absen_djuragan.inbound_enabled', true);

        $testEmail = 'absen_test_' . time() . '@example.com';

        $division = Division::where('name', 'like', '%Programmer%')->first()
            ?: Division::first();

        if (!$division) {
            $division = Division::create([
                'name'      => 'Programmer (Front End / Backend)',
                'slug'      => 'programmer-front-end-backend',
                'code'      => 'PROG',
                'is_active' => true,
            ]);
        }

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
            'division_id'      => $division->id,
            'division_name'    => $division->name,
        ];

        $response = $this->withHeaders([
            'X-API-KEY' => $this->inboundKey,
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
