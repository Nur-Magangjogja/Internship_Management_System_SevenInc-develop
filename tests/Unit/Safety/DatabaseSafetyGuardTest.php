<?php

namespace Tests\Unit\Safety;

use App\Exceptions\DatabaseSafetyException;
use App\Support\DatabaseSafetyGuard;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSafetyGuardTest extends TestCase
{
    /**
     * S01: Verifikasi database aktif saat PHPUnit berjalan benar-benar database testing dan MySQL user khusus.
     */
    public function test_s01_active_testing_database_is_strictly_isolated(): void
    {
        $actualDb = DB::selectOne('SELECT DATABASE() AS current_db')->current_db;
        $currentUser = DB::selectOne('SELECT CURRENT_USER() AS active_user')->active_user;

        $this->assertEquals('internship_management_system_testing', $actualDb);
        $this->assertStringStartsWith('sertif_tester@', $currentUser);
        $this->assertEquals('testing', app()->environment());
        $this->assertEquals('testing', config('app.env'));
    }

    /**
     * S02: Guard meloloskan database testing yang sah.
     */
    public function test_s02_guard_passes_for_valid_testing_database(): void
    {
        // Kondisi sah saat ini tidak boleh melempar exception
        DatabaseSafetyGuard::assertSafeForTesting();
        $this->assertTrue(true);
    }

    /**
     * S03: Guard menolak fail-closed jika APP_ENV bukan testing.
     */
    public function test_s03_guard_rejects_when_app_env_is_not_testing(): void
    {
        config(['app.env' => 'production']);

        $this->expectException(DatabaseSafetyException::class);
        $this->expectExceptionMessage('APP_ENV tidak valid untuk testing');

        DatabaseSafetyGuard::assertSafeForTesting();
    }

    /**
     * S04: Guard menolak fail-closed jika target konfigurasi adalah database utama.
     */
    public function test_s04_guard_rejects_when_configured_database_is_main_db(): void
    {
        $defaultConn = config('database.default');
        config(["database.connections.{$defaultConn}.database" => 'internship_management_system']);

        $this->expectException(DatabaseSafetyException::class);
        $this->expectExceptionMessage("mengarah ke database utama 'internship_management_system'");

        DatabaseSafetyGuard::assertSafeForTesting();
    }

    /**
     * S05: Guard menolak fail-closed jika target konfigurasi adalah database rehearsal.
     */
    public function test_s05_guard_rejects_when_configured_database_is_rehearsal_db(): void
    {
        $defaultConn = config('database.default');
        config(["database.connections.{$defaultConn}.database" => 'internship_management_system_rehearsal']);

        $this->expectException(DatabaseSafetyException::class);
        $this->expectExceptionMessage("mengarah ke database utama 'internship_management_system_rehearsal'");

        DatabaseSafetyGuard::assertSafeForTesting();
    }

    /**
     * S06: Guard menolak database asing yang tidak termasuk allowlist resmi.
     */
    public function test_s06_guard_rejects_unlisted_databases(): void
    {
        $defaultConn = config('database.default');
        config(["database.connections.{$defaultConn}.database" => 'some_unknown_database']);

        $this->expectException(DatabaseSafetyException::class);
        $this->expectExceptionMessage("tidak termasuk database testing resmi");

        DatabaseSafetyGuard::assertSafeForTesting();
    }

    /**
     * S07: Perintah destruktif (migrate:fresh, db:wipe, dll) ditolak jika database adalah database utama.
     */
    public function test_s07_destructive_commands_blocked_on_protected_databases(): void
    {
        $defaultConn = config('database.default');

        foreach (DatabaseSafetyGuard::DESTRUCTIVE_COMMANDS as $cmd) {
            config(["database.connections.{$defaultConn}.database" => 'internship_management_system']);

            try {
                DatabaseSafetyGuard::assertSafeForDestructiveArtisanCommand($cmd);
                $this->fail("Perintah destruktif {$cmd} harus dilempar DatabaseSafetyException jika database utama.");
            } catch (DatabaseSafetyException $e) {
                $this->assertStringContainsString("OPERASI DESTRUKTIF DITOLAK", $e->getMessage());
                $this->assertStringContainsString("DILARANG pada database utama", $e->getMessage());
            }
        }
    }

    /**
     * S08: Perintah destruktif diizinkan jika database benar-benar database testing resmi.
     */
    public function test_s08_destructive_commands_allowed_only_on_test_database(): void
    {
        $defaultConn = config('database.default');
        config(["database.connections.{$defaultConn}.database" => 'internship_management_system_testing']);

        foreach (DatabaseSafetyGuard::DESTRUCTIVE_COMMANDS as $cmd) {
            DatabaseSafetyGuard::assertSafeForDestructiveArtisanCommand($cmd);
        }

        $this->assertTrue(true);
    }

    /**
     * S09: Helper isAllowedTestDatabase memvalidasi allowlist dan parallel worker secara akurat.
     */
    public function test_s09_is_allowed_test_database_validation(): void
    {
        $this->assertTrue(DatabaseSafetyGuard::isAllowedTestDatabase('internship_management_system_testing'));
        $this->assertTrue(DatabaseSafetyGuard::isAllowedTestDatabase('internship_management_system_testing_worker_1'));
        $this->assertTrue(DatabaseSafetyGuard::isAllowedTestDatabase('internship_management_system_testing_test_2'));

        $this->assertFalse(DatabaseSafetyGuard::isAllowedTestDatabase('internship_management_system'));
        $this->assertFalse(DatabaseSafetyGuard::isAllowedTestDatabase('internship_management_system_rehearsal'));
        $this->assertFalse(DatabaseSafetyGuard::isAllowedTestDatabase('production_db'));
        $this->assertFalse(DatabaseSafetyGuard::isAllowedTestDatabase('my_testing_db'));
    }
}
