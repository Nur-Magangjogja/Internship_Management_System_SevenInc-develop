<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Exceptions\Handler;
use ReflectionClass;
use Illuminate\Support\ViewErrorBag;

class PasswordTemporaryStorageAndEyeSyncTest extends TestCase
{
    /**
     * Pastikan semua variasi kredensial password terdaftar di $dontFlash Handler
     * sehingga tidak pernah disimpan sementara ke dalam session flash Laravel (_old_input).
     */
    public function test_handler_dont_flash_contains_all_password_variations(): void
    {
        $reflector = new ReflectionClass(Handler::class);
        $property = $reflector->getProperty('dontFlash');
        $property->setAccessible(true);
        $handler = app(Handler::class);
        $dontFlash = $property->getValue($handler);

        $expectedKeys = [
            'password',
            'password_confirmation',
            'current_password',
            'admin_password',
            'old_password',
            'new_password',
            'new_password_confirmation',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertContains(
                $key,
                $dontFlash,
                "Field '{$key}' harus ada di dalam \$dontFlash agar tidak disimpan sementara di session."
            );
        }
    }

    /**
     * Pastikan view registrasi admin memuat autocomplete, sanitasi cache keyboard, dan script sinkronisasi 2 eye.
     */
    public function test_admin_register_view_contains_synced_eye_and_security_attributes(): void
    {
        $view = view('auth.admin-register', ['errors' => new ViewErrorBag()])->render();

        $this->assertStringContainsString('autocomplete="new-password"', $view);
        $this->assertStringContainsString('autocorrect="off"', $view);
        $this->assertStringContainsString('spellcheck="false"', $view);
        $this->assertStringContainsString('toggle-admin-register-password-btn', $view);
        $this->assertStringContainsString('togglePasswordVisibility', $view);
    }

    /**
     * Pastikan view edit profil admin memuat sinkronisasi tombol eye untuk password baru dan konfirmasi.
     */
    public function test_admin_profile_edit_contains_synced_eye_and_security_attributes(): void
    {
        $user = new \App\Models\User([
            'id'    => 999,
            'name'  => 'Admin Test',
            'email' => 'admin@test.com',
            'role'  => 'admin',
        ]);
        $this->be($user);

        $view = view('admin.profile.edit', ['user' => $user, 'errors' => new ViewErrorBag()])->render();

        $this->assertStringContainsString('toggle-new-password-btn', $view);
        $this->assertStringContainsString('autocomplete="current-password"', $view);
        $this->assertStringContainsString('autocomplete="new-password"', $view);
        $this->assertStringContainsString('autocorrect="off"', $view);
    }

    /**
     * Pastikan view HR staff create memuat toggle-hr-password-btn yang sinkron untuk kedua field sandi.
     */
    public function test_hr_staff_create_view_contains_synced_eye(): void
    {
        $admin = new \App\Models\User([
            'id'    => 998,
            'name'  => 'Admin HR',
            'email' => 'adminhr@test.com',
            'role'  => 'admin',
        ]);
        $this->be($admin);

        $view = view('admin.hr-staff.create', ['errors' => new ViewErrorBag()])->render();

        $this->assertStringContainsString('toggle-hr-password-btn', $view);
        $this->assertStringContainsString('autocomplete="new-password"', $view);
        $this->assertStringContainsString('togglePassword', $view);
    }
}
