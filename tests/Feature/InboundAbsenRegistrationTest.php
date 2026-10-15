<?php

namespace Tests\Feature;

use App\Models\ExternalIdentityMapping;
use App\Models\ExternalRegistrationMapping;
use App\Models\InternshipRegistration;
use App\Models\User;
use App\Models\UserAccountActivation;
use App\Notifications\AccountActivationNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature Test untuk Phase C.1C:
 * Inbound Registration & Account Activation Foundation.
 */
class InboundAbsenRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    protected string $testApiKey = 'test_inbound_secret_phase_c1c_key_12345';

    protected function setUp(): void
    {
        parent::setUp();

        // Konfigurasi default environment testing
        Config::set('services.absen_djuragan.inbound_api_key', $this->testApiKey);
        Config::set('services.absen_djuragan.api_key', $this->testApiKey);
        Config::set('services.absen_djuragan.inbound_write_enabled', true);
        putenv('ABSEN_DJURAGAN_INBOUND_API_KEY=' . $this->testApiKey);
        putenv('ABSEN_DJURAGAN_API_KEY=' . $this->testApiKey);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        Schema::clearResolvedInstances();
        parent::tearDown();
    }

    protected function validPayload(array $overrides = []): array
    {
        $unique = uniqid('absen_', true);
        return array_merge([
            'source_system'            => 'absen_djuragan',
            'external_user_id'         => 'usr_' . $unique,
            'external_registration_id' => 'reg_' . $unique,
            'email'                    => 'intern_' . $unique . '@example.com',
            'name'                     => 'Calon Pemagang ' . $unique,
            'gender'                   => 'Laki-laki',
            'birth_place'              => 'Yogyakarta',
            'religion'                 => 'Islam',
            'phone'                    => '081234567890',
        ], $overrides);
    }

    /**
     * 01: Inbound feature flag default OFF menolak request dengan 503.
     */
    public function test_01_inbound_feature_flag_default_off_returns_503(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', false);

        $response = $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $this->validPayload());

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
                'message' => 'Layanan inbound registration API saat ini dinonaktifkan.',
            ]);
    }

    /**
     * 02: API key invalid dan missing ditolak dengan 401.
     */
    public function test_02_api_key_missing_or_invalid_returns_401(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);

        // Tanpa API Key
        $this->postJson('/api/v1/external/register-from-absen', $this->validPayload())
            ->assertStatus(401);

        // Dengan API Key Salah
        $this->withHeaders(['X-API-KEY' => 'invalid_secret_key'])
            ->postJson('/api/v1/external/register-from-absen', $this->validPayload())
            ->assertStatus(401);
    }

    /**
     * 03: Pembuatan akun user baru & pendaftaran magang berhasil (HTTP 201).
     */
    public function test_03_successful_new_user_and_registration_creation(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Notification::fake();

        $payload = $this->validPayload();
        $response = $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'account_status'               => 'pending_activation',
                    'identity_verification_status' => 'pending_verification',
                ],
            ]);

        // Verifikasi database User
        $user = User::where('email', $payload['email'])->first();
        $this->assertNotNull($user);
        $this->assertEquals(User::ROLE_PEMAGANG, $user->role);

        // Verifikasi record aktivasi akun dibuat dengan activated_at = null
        $this->assertNotNull($user->accountActivation);
        $this->assertTrue($user->isActivationPending());
        $this->assertNull($user->accountActivation->activated_at);

        // Verifikasi external identity mapping
        $this->assertDatabaseHas('external_identity_mappings', [
            'source_system'    => 'absen_djuragan',
            'external_user_id' => $payload['external_user_id'],
            'ims_user_id'      => $user->id,
            'verification_status' => 'pending_verification',
        ]);

        // Verifikasi registration record
        $this->assertDatabaseHas('internship_registrations', [
            'user_id'     => $user->id,
            'fullname'    => $payload['name'],
            'birth_place' => 'Yogyakarta',
            'religion'    => 'Islam',
        ]);

        // Verifikasi external registration mapping
        $this->assertDatabaseHas('external_registration_mappings', [
            'source_system'            => 'absen_djuragan',
            'external_registration_id' => $payload['external_registration_id'],
        ]);

        // Verifikasi notifikasi aktivasi dikirimkan
        Notification::assertSentTo($user, AccountActivationNotification::class);
    }

    /**
     * 04: Atomic rollback jika salah satu tahap pembuatan data gagal.
     */
    public function test_04_atomic_rollback_on_failure(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Notification::fake();

        // Mock failure dengan memberikan payload yang akan memicu QueryException saat registrasi
        $payload = $this->validPayload([
            'email' => 'rollback_test_' . uniqid() . '@example.com',
        ]);

        // Buat mock exception di service layer atau force error
        \App\Models\ExternalRegistrationMapping::saving(function () {
            throw new \RuntimeException('Simulated fatal persistence failure');
        });

        $response = $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload);

        $response->assertStatus(500);

        // Verifikasi seluruh entitas di-rollback (tidak ada user yatim piatu)
        $this->assertNull(User::where('email', $payload['email'])->first());
        $this->assertDatabaseMissing('external_identity_mappings', [
            'external_user_id' => $payload['external_user_id'],
        ]);
        $this->assertDatabaseMissing('internship_registrations', [
            'fullname' => $payload['name'],
        ]);
    }

    /**
     * 05: Request idempotent mengembalikan HTTP 200 tanpa duplikasi baris.
     */
    public function test_05_idempotent_duplicate_request_returns_200(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Notification::fake();

        $payload = $this->validPayload();

        // Request pertama -> 201 Created
        $res1 = $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload);
        $res1->assertStatus(201);

        $user = User::where('email', $payload['email'])->first();
        $initialUserCount = User::where('email', $payload['email'])->count();
        $initialRegCount  = InternshipRegistration::where('user_id', $user->id)->count();

        // Request kedua dengan data identik -> 200 OK idempotent
        $res2 = $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload);
        $res2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_idempotent' => true,
                ],
            ]);

        // Verifikasi tidak ada baris tambahan yang dibuat
        $this->assertEquals($initialUserCount, User::where('email', $payload['email'])->count());
        $this->assertEquals($initialRegCount, InternshipRegistration::where('user_id', $user->id)->count());
    }

    /**
     * 06: Penolakan duplikasi external_user_id pada akun lain (HTTP 409).
     */
    public function test_06_rejection_of_duplicate_external_user_id_on_different_email(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Notification::fake();

        $payload1 = $this->validPayload(['external_user_id' => 'shared_ext_usr_01']);
        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload1)
            ->assertStatus(201);

        // Request kedua dengan external_user_id sama tapi email berbeda
        $payload2 = $this->validPayload([
            'external_user_id'         => 'shared_ext_usr_01',
            'external_registration_id' => 'different_reg_id_' . uniqid(),
            'email'                    => 'another_' . uniqid() . '@example.com',
        ]);

        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload2)
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
            ]);
    }

    /**
     * 07: Penolakan duplikasi external_registration_id (HTTP 409).
     */
    public function test_07_rejection_of_duplicate_external_registration_id(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Notification::fake();

        $sharedRegId = 'shared_reg_id_999';
        $payload1 = $this->validPayload(['external_registration_id' => $sharedRegId]);
        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload1)
            ->assertStatus(201);

        $payload2 = $this->validPayload([
            'external_user_id'         => 'diff_user_' . uniqid(),
            'external_registration_id' => $sharedRegId,
            'email'                    => 'diff_email_' . uniqid() . '@example.com',
        ]);

        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload2)
            ->assertStatus(409);
    }

    /**
     * 08: Identity collision: Email sudah terdaftar pada akun IMS tanpa verified mapping (HTTP 409).
     */
    public function test_08_rejection_of_existing_ims_email_without_verified_mapping(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);

        // Buat akun IMS manual terlebih dahulu
        $existing = User::create([
            'name'     => 'Existing IMS User',
            'email'    => 'existing_ims_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);

        // Request registrasi eksternal dengan email yang sama tapi belum ada mapping
        $payload = $this->validPayload(['email' => $existing->email]);

        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload)
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Email sudah terdaftar pada sistem IMS namun belum memiliki pemetaan identitas eksternal terverifikasi.',
            ]);
    }

    /**
     * 09: Pencegahan pengambilalihan akun internal (Admin / HR Staff / Superadmin) (HTTP 403).
     */
    public function test_09_prevention_of_internal_staff_account_takeover(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);

        $admin = User::create([
            'name'     => 'Admin IMS',
            'email'    => 'admin_protect_' . uniqid() . '@example.com',
            'password' => Hash::make('password123'),
            'role'     => User::ROLE_ADMIN,
        ]);

        $payload = $this->validPayload(['email' => $admin->email]);

        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload)
            ->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Email terdaftar sebagai staf internal. Registrasi pemagang via API ditolak.',
            ]);
    }

    /**
     * 10: Multi-periode: User existing yang sudah verified menerima pendaftaran baru (HTTP 201).
     */
    public function test_10_multi_period_registration_for_verified_existing_user(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Notification::fake();

        $extUserId = 'ext_usr_multi_' . uniqid();
        $payload1 = $this->validPayload([
            'external_user_id'         => $extUserId,
            'external_registration_id' => 'period_1_' . uniqid(),
        ]);

        // Pendaftaran periode 1 -> 201
        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload1)
            ->assertStatus(201);

        // Pendaftaran periode 2 untuk identitas eksternal yang sama -> 201 (multi-period)
        $payload2 = $this->validPayload([
            'external_user_id'         => $extUserId,
            'external_registration_id' => 'period_2_' . uniqid(),
            'email'                    => $payload1['email'],
        ]);

        $response2 = $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload2);

        $response2->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'is_multi_period' => true,
                ],
            ]);

        $user = User::where('email', $payload1['email'])->first();
        $this->assertCount(2, $user->internshipRegistrations);
    }

    /**
     * 11: Data historis registrasi lama tetap utuh dan tidak ditimpa saat pendaftaran baru.
     */
    public function test_11_historical_registration_not_overwritten(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Notification::fake();

        $extUserId = 'ext_hist_' . uniqid();
        $payload1 = $this->validPayload([
            'external_user_id'         => $extUserId,
            'external_registration_id' => 'hist_reg_1_' . uniqid(),
        ]);

        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload1)
            ->assertStatus(201);

        $user = User::where('email', $payload1['email'])->first();
        $reg1 = $user->internshipRegistrations()->first();
        $reg1->update(['internship_status' => InternshipRegistration::STATUS_COMPLETED]);

        // Pendaftaran periode 2
        $payload2 = $this->validPayload([
            'external_user_id'         => $extUserId,
            'external_registration_id' => 'hist_reg_2_' . uniqid(),
            'email'                    => $payload1['email'],
        ]);

        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload2)
            ->assertStatus(201);

        // Registrasi 1 statusnya tetap COMPLETED (tidak ditimpa menjadi ACCEPTED)
        $reg1->refresh();
        $this->assertEquals(InternshipRegistration::STATUS_COMPLETED, $reg1->internship_status);
    }

    /**
     * 12: Akun baru dengan aktivasi pending ditolak saat mencoba login.
     */
    public function test_12_pending_activation_account_login_rejected(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Notification::fake();

        $payload = $this->validPayload();
        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload)
            ->assertStatus(201);

        $user = User::where('email', $payload['email'])->first();

        // Set password sementara untuk pengujian autentikasi
        $user->update(['password' => Hash::make('known_password_123')]);

        // Percobaan login email
        $response = $this->post('/login', [
            'login'    => $user->email,
            'password' => 'known_password_123',
        ]);

        $response->assertSessionHas('error', 'Akun Anda belum diaktivasi. Silakan periksa email Anda untuk mengaktifkan akun sebelum masuk.');
        $this->assertFalse(auth()->check());
    }

    /**
     * 13: Akun existing lama tanpa record aktivasi dapat login secara normal.
     */
    public function test_13_existing_user_without_activation_record_can_login_normally(): void
    {
        $existing = User::create([
            'name'     => 'Regular Pemagang',
            'email'    => 'regular_' . uniqid() . '@example.com',
            'password' => Hash::make('mypassword123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);

        $this->assertFalse($existing->isActivationPending());

        $response = $this->post('/login', [
            'login'    => $existing->email,
            'password' => 'mypassword123',
        ]);

        $response->assertRedirect(route('pemagang.dashboard'));
        $this->assertTrue(auth()->check());
        $this->assertEquals($existing->id, auth()->id());
    }

    /**
     * 14: Aktivasi akun berhasil menggunakan token Password Broker yang sah.
     */
    public function test_14_account_activation_succeeds_with_valid_token(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Notification::fake();

        $payload = $this->validPayload();
        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload)
            ->assertStatus(201);

        $user = User::where('email', $payload['email'])->first();
        $this->assertTrue($user->isActivationPending());

        // Dapatkan token yang sah dari Password Broker
        $token = Password::broker()->createToken($user);

        // Submit form aktivasi
        $response = $this->post(route('user.activate.submit'), [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'NewStrongPass123!',
            'password_confirmation' => 'NewStrongPass123!',
        ]);

        $response->assertRedirect(route('user.login'))
            ->assertSessionHas('status', 'Akun Anda berhasil diaktivasi! Silakan login menggunakan password baru Anda.');

        $user->refresh();
        $this->assertFalse($user->isActivationPending());
        $this->assertNotNull($user->accountActivation->activated_at);

        // Setelah aktif, user dapat login dengan password barunya
        $this->post('/login', [
            'login'    => $user->email,
            'password' => 'NewStrongPass123!',
        ])->assertRedirect(route('pemagang.dashboard'));

        $this->assertTrue(auth()->check());
    }

    /**
     * 15: Token aktivasi invalid / expired / reused ditolak.
     */
    public function test_15_invalid_or_expired_activation_token_rejected(): void
    {
        $user = User::create([
            'name'     => 'Token Test User',
            'email'    => 'token_test_' . uniqid() . '@example.com',
            'password' => Hash::make('initialpass123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);

        UserAccountActivation::create([
            'user_id'      => $user->id,
            'activated_at' => null,
        ]);

        // Token asal-asalan
        $response = $this->post(route('user.activate.submit'), [
            'token'                 => 'completely_fake_invalid_token',
            'email'                 => $user->email,
            'password'              => 'AnotherPass123!',
            'password_confirmation' => 'AnotherPass123!',
        ]);

        $response->assertSessionHasErrors(['email']);
        $user->refresh();
        $this->assertTrue($user->isActivationPending());
    }

    /**
     * 16: Reset password biasa tidak mengaktifkan akun secara tidak sengaja.
     */
    public function test_16_regular_password_reset_does_not_activate_account(): void
    {
        $user = User::create([
            'name'     => 'Reset Check User',
            'email'    => 'reset_check_' . uniqid() . '@example.com',
            'password' => Hash::make('old_pass_123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);

        $act = UserAccountActivation::create([
            'user_id'      => $user->id,
            'activated_at' => null,
        ]);

        $token = Password::broker()->createToken($user);

        // Gunakan endpoint password.update biasa (bukan activate.submit)
        $this->post(route('password.update'), [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'ResetViaRegular123!',
            'password_confirmation' => 'ResetViaRegular123!',
        ]);

        // Record aktivasi harus tetap null (belum aktif)
        $act->refresh();
        $this->assertNull($act->activated_at);
        $this->assertTrue($user->isActivationPending());
    }

    /**
     * 17: Permintaan pengiriman ulang notifikasi aktivasi (resend) berjalan dan ter-throttle.
     */
    public function test_17_resend_activation_notification_and_throttling(): void
    {
        Notification::fake();

        $user = User::create([
            'name'     => 'Resend Test User',
            'email'    => 'resend_' . uniqid() . '@example.com',
            'password' => Hash::make('initialpass123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);

        $act = UserAccountActivation::create([
            'user_id'                        => $user->id,
            'activated_at'                   => null,
            'last_notification_requested_at' => now()->subMinutes(5),
            'resend_count'                   => 1,
        ]);

        // Resend pertama sukses
        $this->post(route('user.activate.resend'), ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, AccountActivationNotification::class);
        $act->refresh();
        $this->assertEquals(2, $act->resend_count);

        // Resend kedua langsung (dalam rentang throttle 60 detik) ditolak
        $this->post(route('user.activate.resend'), ['email' => $user->email])
            ->assertSessionHas('error', 'Mohon tunggu beberapa saat sebelum meminta pengiriman ulang email aktivasi.');
    }

    /**
     * 18: Payload mengandung kredensial (password / password_hash) ditolak dengan 422.
     */
    public function test_18_payload_with_credentials_rejected_with_422(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);

        $payload = $this->validPayload(['password' => 'secret123']);
        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload)
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Field kredensial (password / password_hash) dilarang dikirim melalui API integrasi.',
            ]);

        $payloadHash = $this->validPayload(['password_hash' => 'hash123']);
        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payloadHash)
            ->assertStatus(422);
    }

    /**
     * 19: Validasi input ketat (missing required fields) menghasilkan HTTP 422.
     */
    public function test_19_strict_validation_returns_422(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);

        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', [
                'source_system' => 'absen_djuragan',
                // missing external_user_id, external_registration_id, email, name
            ])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Validasi data registrasi inbound gagal.',
            ]);
    }

    /**
     * 20: Zero outbound HTTP calls dilakukan selama pemrosesan inbound.
     */
    public function test_20_zero_outbound_http_calls_during_inbound(): void
    {
        Config::set('services.absen_djuragan.inbound_enabled', true);
        Http::fake();
        Notification::fake();

        $payload = $this->validPayload();
        $this->withHeaders(['X-API-KEY' => $this->testApiKey])
            ->postJson('/api/v1/external/register-from-absen', $payload)
            ->assertStatus(201);

        Http::assertNothingSent();
    }

    /**
     * 21: isActivationPending() aman dan mengembalikan false ketika tabel aktivasi belum ada (unmigrated).
     */
    public function test_21_is_activation_pending_safe_when_table_does_not_exist(): void
    {
        Schema::shouldReceive('connection')
            ->andReturnSelf();
        Schema::shouldReceive('hasTable')
            ->with('user_account_activations')
            ->andReturn(false);

        $user = User::create([
            'name'     => 'testuser_c1c1',
            'email'    => 'testuser_c1c1@example.com',
            'password' => Hash::make('secret123'),
            'role'     => User::ROLE_PEMAGANG,
        ]);

        $this->assertFalse($user->isActivationPending());
    }

    /**
     * 22: Pengguna existing tetap dapat login pada schema lama tanpa error SQL 42S02.
     */
    public function test_22_existing_user_login_succeeds_on_unmigrated_schema(): void
    {
        $password = 'ExistingPass123!';
        $user = User::create([
            'name'     => 'existing_unmigrated_user',
            'email'    => 'existing_unmigrated@example.com',
            'password' => Hash::make($password),
            'role'     => User::ROLE_PEMAGANG,
        ]);

        // Simulasikan lingkungan unmigrated dimana tabel aktivasi belum ada
        Schema::shouldReceive('connection')
            ->andReturnSelf();
        Schema::shouldReceive('hasTable')
            ->with('user_account_activations')
            ->andReturn(false);

        $response = $this->post('/login', [
            'login'    => $user->email,
            'password' => $password,
        ]);

        $response->assertRedirect('/pemagang/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    /**
     * 23: Route aktivasi menangani schema belum siap dengan aman (graceful redirect tanpa exception).
     */
    public function test_23_activation_routes_handle_unready_schema_gracefully(): void
    {
        Schema::shouldReceive('hasTable')
            ->with('user_account_activations')
            ->andReturn(false);

        // GET showActivationForm (/activate/{token})
        $this->get('/activate/dummy-token')
            ->assertRedirect(route('user.login'))
            ->assertSessionHas('error', 'Layanan aktivasi akun belum tersedia.');

        // POST activateAccount (/activate)
        $this->post('/activate', [
            'token'                 => 'dummy-token',
            'email'                 => 'test@example.com',
            'password'              => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])
            ->assertRedirect(route('user.login'))
            ->assertSessionHas('error', 'Layanan aktivasi akun belum tersedia.');

        // POST resendActivation (/resend-activation)
        $this->post('/resend-activation', [
            'email' => 'test@example.com',
        ])
            ->assertSessionHas('error', 'Layanan aktivasi akun belum tersedia.');
    }
}
