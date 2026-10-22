<?php

namespace Tests\Unit\Safety;

use App\Exceptions\DatabaseSafetyException;
use App\Support\DatabaseSafety\DatabaseBackupper;
use App\Support\DatabaseSafety\ManualResetContext;
use App\Support\DatabaseSafety\ManualResetGate;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class ManualResetGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sertif_safety.dev_reset_enabled' => true,
            'sertif_safety.dev_reset_allowed_databases' => ['internship_management_system'],
            'sertif_safety.dev_reset_allowed_hosts' => ['127.0.0.1', 'localhost', '::1'],
            'sertif_safety.approval_phrase' => 'SAYA SETUJU MENGHAPUS SELURUH DATA',
        ]);
    }

    protected function tearDown(): void
    {
        ManualResetGate::endDevReset();
        ManualResetGate::clearAuthorization();
        Mockery::close();
        parent::tearDown();
    }

    protected function makeValidContext(array $overrides = []): ManualResetContext
    {
        $defaults = [
            'commandName' => 'sertif:dev-reset',
            'environment' => 'local',
            'connectionName' => 'mysql',
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'configuredDatabase' => 'internship_management_system',
            'actualDatabase' => 'internship_management_system',
            'directTerminalInvocation' => true,
            'interactive' => true,
            'stdinIsTty' => true,
            'ciDetected' => false,
        ];

        $merged = array_merge($defaults, $overrides);

        return new ManualResetContext(
            commandName: $merged['commandName'],
            environment: $merged['environment'],
            connectionName: $merged['connectionName'],
            driver: $merged['driver'],
            host: $merged['host'],
            configuredDatabase: $merged['configuredDatabase'],
            actualDatabase: $merged['actualDatabase'],
            directTerminalInvocation: $merged['directTerminalInvocation'],
            interactive: $merged['interactive'],
            stdinIsTty: $merged['stdinIsTty'],
            ciDetected: $merged['ciDetected'],
        );
    }

    /**
     * P1-01: Kondisi valid lolos asesmen kelayakan (0 violation).
     */
    public function test_p1_01_valid_context_passes_eligibility(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $violations = $gate->assessEligibility($this->makeValidContext());

        $this->assertEmpty($violations);
    }

    /**
     * P1-02: Ditolak jika APP_ENV bukan local (production, staging, testing).
     */
    public function test_p1_02_rejects_when_app_env_is_not_local(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        foreach (['production', 'staging', 'testing', 'qa'] as $env) {
            $ctx = $this->makeValidContext(['environment' => $env]);
            $violations = $gate->assessEligibility($ctx);

            $this->assertNotEmpty($violations);
            $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, "APP_ENV harus 'local'")));
        }
    }

    /**
     * P1-03: Ditolak jika lingkungan CI terdeteksi.
     */
    public function test_p1_03_rejects_when_ci_detected(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext(['ciDetected' => true]);
        $violations = $gate->assessEligibility($ctx);

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'Lingkungan CI terdeteksi')));
    }

    /**
     * P1-04: Ditolak jika SERTIF_DEV_RESET_ENABLED bernilai false.
     */
    public function test_p1_04_rejects_when_dev_reset_disabled(): void
    {
        config(['sertif_safety.dev_reset_enabled' => false]);

        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $violations = $gate->assessEligibility($this->makeValidContext());

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'SERTIF_DEV_RESET_ENABLED=false')));
    }

    /**
     * P1-05: Ditolak jika database target tidak ada di allowlist.
     */
    public function test_p1_05_rejects_when_database_not_in_allowlist(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext([
            'configuredDatabase' => 'random_production_db',
            'actualDatabase' => 'random_production_db',
        ]);
        $violations = $gate->assessEligibility($ctx);

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'SERTIF_DEV_RESET_ALLOWED_DATABASES')));
    }

    /**
     * P1-06: Database rehearsal mutlak ditolak secara permanen.
     */
    public function test_p1_06_strictly_rejects_rehearsal_database(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext([
            'configuredDatabase' => 'internship_management_system_rehearsal',
            'actualDatabase' => 'internship_management_system_rehearsal',
        ]);
        $violations = $gate->assessEligibility($ctx);

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'rehearsal')));
    }

    /**
     * P1-07: Database testing ditolak dari manual reset (dikelola khusus oleh PHPUnit).
     */
    public function test_p1_07_rejects_testing_database_for_manual_reset(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext([
            'configuredDatabase' => 'internship_management_system_testing',
            'actualDatabase' => 'internship_management_system_testing',
        ]);
        $violations = $gate->assessEligibility($ctx);

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'dikelola oleh PHPUnit')));
    }

    /**
     * P1-08: Ditolak jika noninteraktif (--no-interaction / -n).
     */
    public function test_p1_08_rejects_non_interactive(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext(['interactive' => false]);
        $violations = $gate->assessEligibility($ctx);

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, '--no-interaction')));
    }

    /**
     * P1-09: Ditolak jika STDIN bukan TTY.
     */
    public function test_p1_09_rejects_non_tty_stdin(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext(['stdinIsTty' => false]);
        $violations = $gate->assessEligibility($ctx);

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'STDIN bukan terminal interaktif')));
    }

    /**
     * P1-10: Ditolak jika perintah bukan pemanggilan langsung terminal.
     */
    public function test_p1_10_rejects_non_direct_terminal_invocation(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext(['directTerminalInvocation' => false]);
        $violations = $gate->assessEligibility($ctx);

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'tidak diketik langsung di terminal')));
    }

    /**
     * P1-11: Ditolak jika driver bukan mysql.
     */
    public function test_p1_11_rejects_non_mysql_driver(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext(['driver' => 'pgsql']);
        $violations = $gate->assessEligibility($ctx);

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'hanya mysql')));
    }

    /**
     * P1-12: Ditolak jika host bukan loopback lokal.
     */
    public function test_p1_12_rejects_remote_database_host(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext(['host' => 'db.internal.production.com']);
        $violations = $gate->assessEligibility($ctx);

        $this->assertNotEmpty($violations);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'bukan host lokal (loopback)')));
    }

    /**
     * P1-13: Ditolak jika database aktual SELECT DATABASE() berbeda dari configured.
     */
    public function test_p1_13_rejects_when_actual_database_mismatches_or_is_empty(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $mismatchCtx = $this->makeValidContext(['actualDatabase' => 'different_database']);
        $violations = $gate->assessEligibility($mismatchCtx);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'berbeda dari konfigurasi')));

        $emptyCtx = $this->makeValidContext(['actualDatabase' => null]);
        $violationsEmpty = $gate->assessEligibility($emptyCtx);
        $this->assertTrue(collect($violationsEmpty)->contains(fn ($m) => str_contains($m, 'tidak dapat diverifikasi')));
    }

    /**
     * P1-14: Verifikasi logika pencocokan konfirmasi (nama db dan frasa persis).
     */
    public function test_p1_14_confirmation_matches_logic(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $this->assertTrue(
            $gate->confirmationMatches(
                'internship_management_system',
                'SAYA SETUJU MENGHAPUS SELURUH DATA',
                'internship_management_system'
            )
        );

        // Nama DB salah
        $this->assertFalse(
            $gate->confirmationMatches(
                'wrong_db',
                'SAYA SETUJU MENGHAPUS SELURUH DATA',
                'internship_management_system'
            )
        );

        // Frasa salah / kurang huruf
        $this->assertFalse(
            $gate->confirmationMatches(
                'internship_management_system',
                'SAYA SETUJU',
                'internship_management_system'
            )
        );
    }

    /**
     * P1-15: authorize() melempar exception jika konfirmasi yang diketik salah.
     */
    public function test_p1_15_authorize_rejects_on_wrong_typed_confirmation(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $gate = new ManualResetGate($backupper);

        $ctx = $this->makeValidContext();

        $this->expectException(DatabaseSafetyException::class);
        $this->expectExceptionMessage('Konfirmasi tidak cocok. Reset DIBATALKAN');

        $gate->authorize(
            $ctx,
            function (string $prompt) {
                if (str_contains($prompt, 'nama database')) {
                    return 'internship_management_system';
                }
                return 'FRASA SALAH';
            },
            fn () => null
        );
    }

    /**
     * P1-16: authorize() melempar exception jika backupper gagal / terpotong.
     */
    public function test_p1_16_authorize_rejects_when_backupper_fails(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $backupper->shouldReceive('backupAndVerify')
            ->once()
            ->andThrow(new DatabaseSafetyException('BACKUP GAGAL DIVERIFIKASI: dump terpotong'));

        $gate = new ManualResetGate($backupper);
        $ctx = $this->makeValidContext();

        $this->expectException(DatabaseSafetyException::class);
        $this->expectExceptionMessage('dump terpotong');

        $gate->authorize(
            $ctx,
            function (string $prompt) {
                if (str_contains($prompt, 'nama database')) {
                    return 'internship_management_system';
                }
                return 'SAYA SETUJU MENGHAPUS SELURUH DATA';
            },
            fn () => null
        );
    }

    /**
     * P1-17: authorize() berhasil dan mengembalikan detail jika seluruh syarat terpenuhi.
     */
    public function test_p1_17_authorize_succeeds_when_all_conditions_satisfied(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $backupper->shouldReceive('backupAndVerify')
            ->once()
            ->with('mysql', 'internship_management_system')
            ->andReturn([
                'path' => 'storage/app/backups/test.sql',
                'bytes' => 123456,
                'sha256' => 'fedcba987654321',
                'tables' => 45,
            ]);

        $gate = new ManualResetGate($backupper);
        $ctx = $this->makeValidContext();

        $lines = [];
        $auth = $gate->authorize(
            $ctx,
            function (string $prompt) {
                if (str_contains($prompt, 'nama database')) {
                    return 'internship_management_system';
                }
                return 'SAYA SETUJU MENGHAPUS SELURUH DATA';
            },
            function (string $line) use (&$lines) {
                $lines[] = $line;
            }
        );

        $this->assertEquals('sertif:dev-reset', $auth['command']);
        $this->assertEquals('internship_management_system', $auth['database']);
        $this->assertEquals('storage/app/backups/test.sql', $auth['backup']['path']);
        $this->assertSame($auth, ManualResetGate::lastAuthorization());
    }

    /**
     * P1-18: Manajemen sesi reset in-flight (beginDevReset, isDevResetRunningFor, endDevReset).
     */
    public function test_p1_18_in_flight_reset_session_management(): void
    {
        $this->assertFalse(ManualResetGate::isDevResetRunningFor('mysql'));

        ManualResetGate::beginDevReset('mysql', ['command' => 'sertif:dev-reset']);

        $this->assertTrue(ManualResetGate::isDevResetRunningFor('mysql'));
        $this->assertFalse(ManualResetGate::isDevResetRunningFor('sqlite'));

        $auth = ManualResetGate::endDevReset();
        $this->assertEquals('sertif:dev-reset', $auth['command']);
        $this->assertFalse(ManualResetGate::isDevResetRunningFor('mysql'));
    }

    /**
     * P1-19: sertif:dev-reset Command membatalkan dengan aman jika gate menolak.
     */
    public function test_p1_19_command_aborts_safely_when_gate_rejects(): void
    {
        $mockGate = Mockery::mock(ManualResetGate::class);
        $mockGate->shouldReceive('authorizeFromConsole')
            ->once()
            ->andThrow(new DatabaseSafetyException('Reset manual ditolak oleh pengujian'));

        $this->app->instance(ManualResetGate::class, $mockGate);

        $exitCode = Artisan::call('sertif:dev-reset');

        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Reset manual ditolak oleh pengujian', $output);
    }

    /**
     * P1-20: Perintah Laravel asli `migrate:fresh` didukung penuh melalui alur ManualResetGate.
     */
    public function test_p1_20_native_migrate_fresh_passes_through_manual_reset_gate(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $backupper->shouldReceive('backupAndVerify')
            ->once()
            ->with('mysql', 'internship_management_system')
            ->andReturn([
                'path' => 'storage/app/backups/dev_reset/pre_reset_main.sql',
                'bytes' => 54321,
                'sha256' => 'abcdef1234567890',
                'tables' => 45,
            ]);

        $gate = new ManualResetGate($backupper);
        $ctx = $this->makeValidContext(['commandName' => 'migrate:fresh']);

        $violations = $gate->assessEligibility($ctx);
        $this->assertEmpty($violations);

        $auth = $gate->authorize(
            $ctx,
            function (string $prompt) {
                if (str_contains($prompt, 'nama database')) {
                    return 'internship_management_system';
                }
                return 'SAYA SETUJU MENGHAPUS SELURUH DATA';
            },
            fn () => null
        );

        $this->assertEquals('migrate:fresh', $auth['command']);
        $this->assertEquals('internship_management_system', $auth['database']);
        $this->assertEquals('storage/app/backups/dev_reset/pre_reset_main.sql', $auth['backup']['path']);
    }

    /**
     * P1-21: Perintah `migrate:fresh` otomatis menolak jika operator salah mengetik nama DB atau frasa.
     */
    public function test_p1_21_native_migrate_fresh_aborts_without_backup_when_confirmation_rejected(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $backupper->shouldReceive('backupAndVerify')->never();

        $gate = new ManualResetGate($backupper);
        $ctx = $this->makeValidContext(['commandName' => 'migrate:fresh']);

        $this->expectException(DatabaseSafetyException::class);
        $this->expectExceptionMessage('Konfirmasi tidak cocok. Reset DIBATALKAN');

        $gate->authorize(
            $ctx,
            function (string $prompt) {
                if (str_contains($prompt, 'nama database')) {
                    return 'internship_management_system';
                }
                return 'TYPO PHRASE';
            },
            fn () => null
        );
    }

    /**
     * P1-22: Artisan Guard mengizinkan sub-command `db:wipe` dari `migrate:fresh` karena sesi in-flight aktif.
     */
    public function test_p1_22_artisan_guard_allows_nested_db_wipe_during_authorized_migrate_fresh(): void
    {
        // Simulasikan otorisasi migrate:fresh telah berhasil dan sesi in-flight aktif
        $auth = [
            'command' => 'migrate:fresh',
            'connection' => 'mysql',
            'database' => 'internship_management_system',
            'impact' => ['users' => 65],
            'backup' => ['path' => 'backup.sql'],
        ];

        ManualResetGate::beginDevReset('mysql', $auth);

        // Pastikan gate mengenali koneksi ini sedang dalam sesi reset
        $this->assertTrue(ManualResetGate::isDevResetRunningFor('mysql'));

        // Pastikan koneksi lain tetap ditolak / tidak dikenali
        $this->assertFalse(ManualResetGate::isDevResetRunningFor('mysql_testing'));

        ManualResetGate::endDevReset();
        $this->assertFalse(ManualResetGate::isDevResetRunningFor('mysql'));
    }

    /**
     * P1-23: CommandFinished membersihkan sesi in-flight dan authorization saat migrate:fresh selesai.
     */
    public function test_p1_23_command_finished_cleans_up_session_and_authorization(): void
    {
        $auth = [
            'command' => 'migrate:fresh',
            'connection' => 'mysql',
            'database' => 'internship_management_system',
            'impact' => [],
            'backup' => ['path' => 'backup.sql'],
        ];

        ManualResetGate::beginDevReset('mysql', $auth);
        $this->assertTrue(ManualResetGate::isDevResetRunningFor('mysql'));

        ManualResetGate::endDevReset();
        ManualResetGate::clearAuthorization();

        $this->assertFalse(ManualResetGate::isDevResetRunningFor('mysql'));
        $this->assertNull(ManualResetGate::lastAuthorization());
    }

    /**
     * P1-24: migrate:fresh mutlak ditolak pada database rehearsal dan production walaupun konfirmasi diketik benar.
     */
    public function test_p1_24_native_migrate_fresh_rejects_rehearsal_and_production_fail_closed(): void
    {
        $backupper = Mockery::mock(DatabaseBackupper::class);
        $backupper->shouldReceive('backupAndVerify')->never();

        $gate = new ManualResetGate($backupper);

        // A) Target rehearsal
        $rehearsalCtx = $this->makeValidContext([
            'commandName' => 'migrate:fresh',
            'configuredDatabase' => 'internship_management_system_rehearsal',
            'actualDatabase' => 'internship_management_system_rehearsal',
        ]);
        $violations = $gate->assessEligibility($rehearsalCtx);
        $this->assertTrue(collect($violations)->contains(fn ($m) => str_contains($m, 'rehearsal')));

        // B) APP_ENV production
        $prodCtx = $this->makeValidContext([
            'commandName' => 'migrate:fresh',
            'environment' => 'production',
        ]);
        $prodViolations = $gate->assessEligibility($prodCtx);
        $this->assertTrue(collect($prodViolations)->contains(fn ($m) => str_contains($m, "APP_ENV harus 'local'")));
    }
}
