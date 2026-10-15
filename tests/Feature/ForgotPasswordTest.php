<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private string $userPassword = 'oldpassword123';
    private string $unique;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unique = uniqid('fp_', true);

        $this->user = User::create([
            'name'     => 'user_' . substr($this->unique, 0, 8),
            'email'    => 'user_' . $this->unique . '@example.com',
            'password' => Hash::make($this->userPassword),
            'role'     => 'user',
        ]);
    }

    public function test_forgot_password_page_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
        $response->assertSee('Lupa Password');
        $response->assertSee(route('password.email'));
    }

    public function test_forgot_password_link_is_present_on_login_page(): void
    {
        $response = $this->get(route('user.login'));

        $response->assertOk();
        $response->assertSee(route('password.request'));
        $response->assertSee('Lupa password?');
    }

    public function test_email_format_validation(): void
    {
        $response = $this->post(route('password.email'), [
            'email' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_reset_link_request_for_registered_user_creates_token(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $response = $this->post(route('password.email'), [
            'email' => $this->user->email,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $this->user,
            \Illuminate\Auth\Notifications\ResetPassword::class,
            function ($notification) {
                return !empty($notification->token);
            }
        );

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $this->user->email,
        ]);
    }


    public function test_unregistered_email_does_not_leak_account_existence(): void
    {
        $response = $this->post(route('password.email'), [
            'email' => 'nonexistent_' . time() . '@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_forgot_password_returns_identical_generic_message_for_registered_and_unregistered(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        // 1. Registered email
        $resRegistered = $this->post(route('password.email'), [
            'email' => $this->user->email,
        ]);
        $resRegistered->assertRedirect();
        $resRegistered->assertSessionHas('status');
        $statusRegistered = session('status');

        // 2. Unregistered email
        $resUnregistered = $this->post(route('password.email'), [
            'email' => 'unknown_' . uniqid() . '@example.com',
        ]);
        $resUnregistered->assertRedirect();
        $resUnregistered->assertSessionHas('status');
        $statusUnregistered = session('status');

        // 3. Status messages MUST be identical (anti-enumeration)
        $this->assertEquals(
            'Jika alamat email terdaftar pada sistem kami, tautan pemulihan password telah dikirimkan.',
            $statusRegistered
        );
        $this->assertEquals($statusRegistered, $statusUnregistered);
    }

    public function test_reset_password_screen_can_be_rendered_with_valid_token(): void
    {
        $token = Password::broker()->createToken($this->user);

        $response = $this->get(route('password.reset', [
            'token' => $token,
            'email' => $this->user->email,
        ]));

        $response->assertOk();
        $response->assertSee('Atur Ulang Password');
        $response->assertSee($token);
        $response->assertSee($this->user->email);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $token = Password::broker()->createToken($this->user);
        $newPassword = 'newpassword123';

        $response = $this->post(route('password.update'), [
            'token'                 => $token,
            'email'                 => $this->user->email,
            'password'              => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertRedirect(route('user.login'));
        $response->assertSessionHas('status');

        $this->user->refresh();

        // Password hash check
        $this->assertTrue(Hash::check($newPassword, $this->user->password));
        $this->assertFalse(Hash::check($this->userPassword, $this->user->password));

        // Token must be consumed
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $this->user->email,
        ]);

        // Login with new password succeeds
        $loginResponse = $this->post(route('user.login.submit'), [
            'login'    => $this->user->email,
            'password' => $newPassword,
        ]);
        $loginResponse->assertRedirect(route('pemagang.dashboard'));
        $this->assertAuthenticated();

        auth()->logout();

        // Login with old password fails
        $failedLogin = $this->post(route('user.login.submit'), [
            'login'    => $this->user->email,
            'password' => $this->userPassword,
        ]);
        $failedLogin->assertSessionHas('error');
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $response = $this->post(route('password.update'), [
            'token'                 => 'invalid-token-12345',
            'email'                 => $this->user->email,
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');

        $this->user->refresh();
        $this->assertTrue(Hash::check($this->userPassword, $this->user->password));
    }

    public function test_password_cannot_be_reset_with_expired_token(): void
    {
        $token = Password::broker()->createToken($this->user);

        // Manually expire the token by setting created_at to 61 minutes ago
        DB::table('password_reset_tokens')
            ->where('email', $this->user->email)
            ->update(['created_at' => Carbon::now()->subMinutes(61)]);

        $response = $this->post(route('password.update'), [
            'token'                 => $token,
            'email'                 => $this->user->email,
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');

        $this->user->refresh();
        $this->assertTrue(Hash::check($this->userPassword, $this->user->password));
    }

    public function test_consumed_token_cannot_be_reused(): void
    {
        $token = Password::broker()->createToken($this->user);
        $newPassword = 'newpassword123';

        // First reset
        $this->post(route('password.update'), [
            'token'                 => $token,
            'email'                 => $this->user->email,
            'password'              => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        // Attempt second reset with same token
        $response = $this->post(route('password.update'), [
            'token'                 => $token,
            'email'                 => $this->user->email,
            'password'              => 'anotherpassword123',
            'password_confirmation' => 'anotherpassword123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_password_confirmation_must_match(): void
    {
        $token = Password::broker()->createToken($this->user);

        $response = $this->post(route('password.update'), [
            'token'                 => $token,
            'email'                 => $this->user->email,
            'password'              => 'newpassword123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
