<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SsoBridgeTest extends TestCase
{
    use DatabaseTransactions;

    protected string $secret = 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c';

    public function test_sso_login_success_with_valid_token_from_absen()
    {
        $payload = [
            'email'          => 'admin_sso_test@seveninc.com',
            'name'           => 'Admin SSO Test',
            'username'       => 'admin_sso_test',
            'role'           => 'admin',
            'role_id'        => 1,
            'is_super_admin' => false,
            'timestamp'      => time(),
            'expires'        => time() + 300,
        ];

        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $this->secret);
        $encodedPayload = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $token = $encodedPayload . '.' . $signature;

        $response = $this->get('/auth/sso?token=' . urlencode($token));

        $response->assertRedirect(route('admin.dashboard.index'));
        $this->assertAuthenticated();

        $loggedInUser = auth()->user();
        $this->assertEquals('admin_sso_test@seveninc.com', $loggedInUser->email);
        $this->assertEquals('admin', $loggedInUser->role);
    }

    public function test_sso_login_fails_with_invalid_signature()
    {
        $payload = [
            'email'          => 'hacker@seveninc.com',
            'name'           => 'Hacker',
            'username'       => 'hacker',
            'role'           => 'admin',
            'role_id'        => 1,
            'is_super_admin' => false,
            'timestamp'      => time(),
            'expires'        => time() + 300,
        ];

        $payloadJson = json_encode($payload);
        $fakeSignature = hash_hmac('sha256', $payloadJson, 'wrong_secret_key');
        $encodedPayload = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $token = $encodedPayload . '.' . $fakeSignature;

        $response = $this->get('/auth/sso?token=' . urlencode($token));

        $response->assertRedirect(route('user.login'));
        $response->assertSessionHas('error', 'Tanda tangan token SSO tidak sah.');
        $this->assertGuest();
    }

    public function test_sso_login_fails_with_expired_token()
    {
        $payload = [
            'email'          => 'expired_admin@seveninc.com',
            'name'           => 'Expired Admin',
            'username'       => 'expired_admin',
            'role'           => 'admin',
            'role_id'        => 1,
            'is_super_admin' => false,
            'timestamp'      => time() - 500,
            'expires'        => time() - 200, // Sudah kadaluarsa
        ];

        $payloadJson = json_encode($payload);
        $signature = hash_hmac('sha256', $payloadJson, $this->secret);
        $encodedPayload = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $token = $encodedPayload . '.' . $signature;

        $response = $this->get('/auth/sso?token=' . urlencode($token));

        $response->assertRedirect(route('user.login'));
        $response->assertSessionHas('error', 'Token SSO telah kadaluarsa. Silakan klik kembali tombol di Absen Djuragan.');
        $this->assertGuest();
    }
}
