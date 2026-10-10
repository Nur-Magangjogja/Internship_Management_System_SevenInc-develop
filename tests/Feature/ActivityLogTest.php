<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ActivityLogTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeUser(string $role = 'admin'): User
    {
        $unique = uniqid('act_', true);
        return User::create([
            'name'      => 'act_' . substr($unique, 0, 15),
            'email'     => 'act_' . $unique . '@example.com',
            'password'  => Hash::make('password123'),
            'role'      => $role,
            'is_banned' => 0,
        ]);
    }

    /**
     * 1. ActivityLogger mencatat event lengkap dengan snapshot actor dan UUID
     */
    public function test_activity_logger_records_event_with_actor_snapshot(): void
    {
        $admin = $this->makeUser('admin');
        $targetUser = $this->makeUser('hr_staff');

        $this->actingAs($admin);

        $log = ActivityLogger::log(
            action: 'hr_staff_created',
            description: 'Admin menambahkan akun HR Staff baru',
            subject: $targetUser,
            before: [],
            after: ['name' => $targetUser->name, 'email' => $targetUser->email, 'role' => 'hr_staff'],
            metadata: ['module' => 'hr_management']
        );

        $this->assertInstanceOf(ActivityLog::class, $log);
        $this->assertTrue(Str::isUuid($log->public_uuid));

        $this->assertDatabaseHas('activity_logs', [
            'id'                  => $log->id,
            'action'              => 'hr_staff_created',
            'actor_user_id'       => $admin->id,
            'actor_name_snapshot' => $admin->name,
            'actor_email_snapshot'=> $admin->email,
            'actor_role_snapshot' => 'admin',
            'subject_id'          => $targetUser->id,
            'subject_type'        => User::class,
        ]);

        $this->assertEquals('hr_staff', $log->after_data['role']);
    }

    /**
     * 2. ActivityLogger otomatis menyensor (redact) password, token, dan data sensitif
     */
    public function test_activity_logger_redacts_sensitive_fields_like_password_and_tokens(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);

        $log = ActivityLogger::log(
            action: 'user_password_changed',
            description: 'Pengubahan kata sandi user',
            subject: $admin,
            before: ['password' => '$2y$10$oldhashsecret', 'remember_token' => 'old_secret_token'],
            after: ['password' => 'NewPlainPassword123', 'password_confirmation' => 'NewPlainPassword123'],
            metadata: ['api_token' => 'top_secret_bearer_token', 'safe_info' => 'v1.0']
        );

        // Pastikan field sensitif diubah menjadi '[REDACTED]'
        $this->assertEquals('[REDACTED]', $log->before_data['password']);
        $this->assertEquals('[REDACTED]', $log->before_data['remember_token']);
        $this->assertEquals('[REDACTED]', $log->after_data['password']);
        $this->assertEquals('[REDACTED]', $log->after_data['password_confirmation']);
        $this->assertEquals('[REDACTED]', $log->metadata['api_token']);

        // Data yang aman tetap utuh
        $this->assertEquals('v1.0', $log->metadata['safe_info']);

        // Verifikasi ke raw database bahwa teks 'NewPlainPassword123' tidak pernah tertulis
        $rawLog = ActivityLog::find($log->id);
        $this->assertStringNotContainsString('NewPlainPassword123', json_encode($rawLog->after_data));
        $this->assertStringNotContainsString('top_secret_bearer_token', json_encode($rawLog->metadata));
    }

    /**
     * 3. ActivityLogger dapat mencatat aktivitas background/system tanpa active user
     */
    public function test_activity_log_persists_even_when_actor_is_null_or_system(): void
    {
        auth()->logout();

        $log = ActivityLogger::log(
            action: 'system_periodic_check',
            description: 'Pengecekan masa berlaku MOU berkala',
            metadata: ['triggered_by' => 'cron_scheduler']
        );

        $this->assertDatabaseHas('activity_logs', [
            'id'                  => $log->id,
            'actor_user_id'       => null,
            'actor_name_snapshot' => 'SYSTEM',
            'actor_role_snapshot' => 'system',
            'action'              => 'system_periodic_check',
        ]);
    }

    /**
     * 4. Histori log tetap utuh dan snapshot nama tetap ada meski record user dihapus
     */
    public function test_activity_log_remains_intact_if_user_is_deleted(): void
    {
        $temporaryUser = $this->makeUser('admin');
        $actorName = $temporaryUser->name;
        $actorEmail = $temporaryUser->email;

        $log = ActivityLogger::log(
            action: 'sensitive_operation',
            description: 'Operasi sebelum user dihapus',
            actor: $temporaryUser
        );

        $logId = $log->id;

        // Hard delete user untuk menguji constraint nullOnDelete database
        $temporaryUser->forceDelete();

        $persistedLog = ActivityLog::find($logId);
        $this->assertNotNull($persistedLog);
        $this->assertNull($persistedLog->actor_user_id);
        $this->assertEquals($actorName, $persistedLog->actor_name_snapshot);
        $this->assertEquals($actorEmail, $persistedLog->actor_email_snapshot);
    }
}
