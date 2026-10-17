<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Brand;
use App\Models\Institution;
use App\Models\InternshipRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteInternAuthTest extends TestCase
{
    use DatabaseTransactions;

    protected string $apiKey = 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
        Config::set('services.absen_djuragan.api_key', $this->apiKey);
        Config::set('services.absen_djuragan.url', 'http://absen-djuragan.test/api/v1/external');
    }

    public function test_intern_from_absen_can_login_via_login_form(): void
    {
        $email = 'remote_intern_' . uniqid() . '@example.com';
        $password = 'SecretIntern123!';

        Http::fake([
            '*/verify-admin' => Http::response([
                'success' => false,
                'message' => 'Akses ditolak: Akun bukan Admin atau Super Admin.',
            ], 403),
            '*/verify-intern' => Http::response([
                'success' => true,
                'message' => 'Kredensial Pemagang valid.',
                'user'    => [
                    'absen_user_id' => 888,
                    'email'         => $email,
                    'username'      => 'intern_test',
                    'full_name'     => 'Budi Pemagang Absen',
                    'phone'         => '081234567890',
                    'gender'        => 'Laki-laki',
                    'birth_place'   => 'Sleman',
                    'born_date'     => '2004-06-20',
                    'religion'      => 'Islam',
                    'student_id'    => 'NIM-888',
                    'school_name'   => 'Politeknik Negeri Yogyakarta',
                    'division_name' => 'Programmer',
                    'brand_name'    => 'SevenInc',
                    'start_date'    => '2026-10-01',
                    'end_date'      => '2026-12-31',
                    'role'          => 'pemagang',
                    'role_id'       => 3,
                    'is_active'     => true,
                    'status'        => 'active',
                ],
            ], 200),
        ]);

        $response = $this->post(route('user.login.submit'), [
            'login'    => $email,
            'password' => $password,
        ]);

        $response->assertRedirect(route('pemagang.dashboard'));
        $this->assertAuthenticated();

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertEquals('pemagang', $user->role);
        $this->assertTrue(Hash::check($password, $user->password));

        $reg = InternshipRegistration::where('user_id', $user->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals('Budi Pemagang Absen', $reg->fullname);
        $this->assertEquals('Laki-laki', $reg->gender);
        $this->assertEquals('081234567890', $reg->phone_number);
        $this->assertEquals('active', $reg->internship_status);
        $this->assertEquals('Programmer', $reg->division?->name);
        $this->assertEquals('SevenInc', $reg->brandRel?->name);
    }

    public function test_intern_from_absen_can_login_via_sso_token(): void
    {
        $email = 'sso_intern_' . uniqid() . '@example.com';
        $secret = $this->apiKey;

        $payload = [
            'email'          => $email,
            'name'           => 'Rina Rahmawati',
            'username'       => 'rina_intern',
            'role'           => 'pemagang',
            'role_id'        => 3,
            'is_super_admin' => false,
            'timestamp'      => time(),
            'expires'        => time() + 300,
            'intern_data'    => [
                'full_name'     => 'Rina Rahmawati',
                'phone'         => '089876543210',
                'gender'        => 'Perempuan',
                'birth_place'   => 'Bantul',
                'born_date'     => '2005-02-14',
                'religion'      => 'Islam',
                'student_id'    => '2026/TK/099',
                'school_name'   => 'Universitas Mercu Buana',
                'division_name' => 'UI/UX Designer',
                'brand_name'    => 'SevenInc',
                'start_date'    => '2026-11-01',
                'end_date'      => '2027-01-31',
                'is_active'     => false,
                'status'        => 'accepted',
            ],
        ];

        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $secret);
        $encodedPayload = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $token = $encodedPayload . '.' . $signature;

        $response = $this->get('/auth/sso?token=' . urlencode($token));

        $response->assertRedirect(route('pemagang.dashboard'));
        $this->assertAuthenticated();

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertEquals('pemagang', $user->role);

        $reg = InternshipRegistration::where('user_id', $user->id)->first();
        $this->assertNotNull($reg);
        $this->assertEquals('Rina Rahmawati', $reg->fullname);
        $this->assertEquals('accepted', $reg->internship_status);
        $this->assertEquals('UI/UX Designer', $reg->division?->name);
    }

    public function test_sso_token_with_tampered_signature_is_rejected(): void
    {
        $payload = [
            'email'   => 'fake@example.com',
            'role'    => 'pemagang',
            'role_id' => 3,
            'expires' => time() + 300,
        ];
        $payloadJson = json_encode($payload);
        $fakeSignature = hash_hmac('sha256', $payloadJson, 'wrong_secret');
        $token = base64_encode($payloadJson) . '.' . $fakeSignature;

        $response = $this->get('/auth/sso?token=' . urlencode($token));

        $response->assertRedirect(route('user.login'));
        $this->assertGuest();
    }

    public function test_existing_intern_syncs_new_password_and_profile_on_remote_login(): void
    {
        $email = 'existing_intern_' . uniqid() . '@example.com';
        $oldPassword = 'OldPassword123!';
        $newPassword = 'NewSecretPassword456!';

        // Buat user lama di IMS
        $user = User::create([
            'name'     => 'Nama Lama',
            'email'    => $email,
            'password' => Hash::make($oldPassword),
            'role'     => 'pemagang',
        ]);

        Http::fake([
            '*/verify-admin' => Http::response(['success' => false], 403),
            '*/verify-intern' => Http::response([
                'success' => true,
                'message' => 'Kredensial Pemagang valid.',
                'user'    => [
                    'absen_user_id' => 999,
                    'email'         => $email,
                    'username'      => 'intern_existing',
                    'full_name'     => 'Nama Baru Terupdate',
                    'phone'         => '08111222333',
                    'gender'        => 'Laki-laki',
                    'role'          => 'pemagang',
                    'role_id'       => 3,
                    'is_active'     => true,
                    'status'        => 'active',
                ],
            ], 200),
        ]);

        // Coba login dengan password baru
        $response = $this->post(route('user.login.submit'), [
            'login'    => $email,
            'password' => $newPassword,
        ]);

        $response->assertRedirect(route('pemagang.dashboard'));
        $this->assertAuthenticatedAs($user);

        // Verifikasi bahwa password lokal terupdate ke password baru
        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
        $this->assertEquals('Nama Baru Terupdate', $user->fresh()->name);
    }

    public function test_intern_gender_and_religion_normalization_and_form_prefill(): void
    {
        $email = 'remote_norm_' . uniqid() . '@example.com';
        $password = 'Secret123!';

        Http::fake([
            '*/verify-admin' => Http::response(['success' => false], 403),
            '*/verify-intern' => Http::response([
                'success' => true,
                'message' => 'Kredensial Pemagang valid.',
                'user'    => [
                    'absen_user_id' => 777,
                    'email'         => $email,
                    'username'      => 'norm_intern',
                    'full_name'     => 'Bunga Lestari',
                    'phone'         => '081234567899',
                    'gender'        => 'P', // Short gender from Absen
                    'birth_place'   => 'Solo',
                    'born_date'     => '2005-04-01',
                    'religion'      => 'Kristen', // Absen style religion
                    'student_id'    => 'NIM-777',
                    'school_name'   => 'Universitas Sebelas Maret',
                    'division_name' => 'UI/UX Designer',
                    'brand_name'    => 'SevenInc',
                    'start_date'    => '2026-11-01',
                    'end_date'      => '2027-01-31',
                    'role'          => 'pemagang',
                    'role_id'       => 3,
                    'is_active'     => false,
                    'status'        => 'accepted',
                ],
            ], 200),
        ]);

        $response = $this->post(route('user.login.submit'), [
            'login'    => $email,
            'password' => $password,
        ]);

        $response->assertRedirect(route('pemagang.dashboard'));
        $user = User::where('email', $email)->firstOrFail();

        $reg = InternshipRegistration::where('user_id', $user->id)->firstOrFail();
        // Cek bahwa gender 'P' dinormalisasi ke 'Perempuan'
        $this->assertEquals('Perempuan', $reg->gender);
        // Cek bahwa agama 'Kristen' dinormalisasi ke 'Kristen Protestan'
        $this->assertEquals('Kristen Protestan', $reg->religion);

        // Buka form pendaftaran magang
        $formResponse = $this->actingAs($user)->get(route('pemagang.registration.form'));
        $formResponse->assertStatus(200);

        // Pastikan radio gender Perempuan terpilih
        $content = $formResponse->getContent();
        $this->assertStringContainsString('name="gender" value="Perempuan"', $content);
        $this->assertMatchesRegularExpression('/name="gender"\s+value="Perempuan"[^>]*checked/i', $content);

        // Pastikan dropdown agama terpilih Kristen Protestan
        $this->assertMatchesRegularExpression('/<option\s+value="Kristen Protestan"\s+selected/i', $content);
    }
}
