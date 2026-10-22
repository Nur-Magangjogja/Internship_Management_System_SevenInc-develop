<?php

namespace App\Support;

use App\Exceptions\DatabaseSafetyException;
use App\Support\DatabaseSafety\ManualResetGate;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

class DatabaseSafetyGuard
{
    /**
     * Database utama yang dilindungi secara mutlak dari testing dan operasi destruktif.
     */
    public const PROTECTED_DATABASES = [
        'internship_management_system',
        'internship_management_system_rehearsal',
    ];

    /**
     * Database testing kanonikal yang diizinkan untuk eksekusi PHPUnit dan RefreshDatabase.
     */
    public const ALLOWED_TEST_DATABASES = [
        'internship_management_system_testing',
    ];

    /**
     * Perintah Artisan destruktif yang dapat menghapus seluruh tabel/skema.
     */
    public const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    /**
     * Daftarkan pendengar event Artisan untuk memblokir perintah destruktif pada database utama.
     */
    public static function registerArtisanGuard(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (!in_array($event->command, self::DESTRUCTIVE_COMMANDS, true)) {
                return;
            }

            $connection = static::resolveTargetConnection($event->input);
            $configuredDb = (string) config("database.connections.{$connection}.database");

            // If a manual reset session is actively running for this connection (e.g. from sertif:dev-reset),
            // allow the sub-command to execute without prompting or clearing authorization.
            if (ManualResetGate::isDevResetRunningFor($connection)) {
                return;
            }

            ManualResetGate::clearAuthorization();

            // A) Officially allowed testing database: strict automated guard (PHPUnit / RefreshDatabase path).
            if (static::isAllowedTestDatabase($configuredDb)) {
                static::assertSafeForDestructiveArtisanCommand($event->command, $connection);
                return;
            }

            // B) Any other database (main, rehearsal, unknown): DB-SAFETY-P1 manual gate, evaluated per invocation.
            //    Rejects automated / non-interactive / non-local / rehearsal / production and requires
            //    a verified backup plus typed confirmation. Throws on any failure (fail-closed).
            $auth = app(ManualResetGate::class)->authorizeFromConsole(
                $event->command, $event->input, $event->output, $connection
            );

            // Mark session as in-flight so nested commands (e.g. migrate:fresh calling db:wipe) are recognized
            ManualResetGate::beginDevReset($connection, $auth);
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            if (!in_array($event->command, self::DESTRUCTIVE_COMMANDS, true)) {
                return;
            }
            $auth = ManualResetGate::lastAuthorization();
            if ($auth === null || $auth['command'] !== $event->command) {
                return;
            }
            $seeded = $event->input->hasOption('seed') && (bool) $event->input->getOption('seed');
            try {
                app(ManualResetGate::class)->report($auth, $event->output, $seeded, (int) $event->exitCode);
            } finally {
                ManualResetGate::endDevReset();
                ManualResetGate::clearAuthorization();
            }
        });
    }

    /**
     * Resolve the connection a migrate/db command will actually operate on (`--database` or default).
     */
    public static function resolveTargetConnection(InputInterface $input): string
    {
        $opt = null;
        try {
            if ($input->hasOption('database')) {
                $opt = $input->getOption('database');
            }
        } catch (Throwable) {
            $opt = null;
        }

        return (string) ($opt ?: config('database.default'));
    }

    /**
     * Validasi ketat fail-closed sebelum PHPUnit / TestCase mengeksekusi pengujian.
     *
     * @throws DatabaseSafetyException
     */
    public static function assertSafeForTesting(): void
    {
        // 1. Validasi APP_ENV benar-benar testing
        $appEnv = \Illuminate\Support\Facades\App::environment();
        $configEnv = config('app.env');
        $serverEnv = $_SERVER['APP_ENV'] ?? null;
        $envEnv = $_ENV['APP_ENV'] ?? null;

        if ($appEnv !== 'testing' || $configEnv !== 'testing' || $serverEnv !== 'testing' || $envEnv !== 'testing') {
            throw new DatabaseSafetyException(
                "CRITICAL DB-SAFETY VIOLATION: APP_ENV tidak valid untuk testing! " .
                "(app: {$appEnv}, config: {$configEnv}, SERVER: {$serverEnv}, ENV: {$envEnv}). " .
                "Testing DIBATALKAN untuk mencegah kerusakan data."
            );
        }

        // 2. Validasi konfigurasi database koneksi default
        $defaultConn = config('database.default');
        $configuredDb = config("database.connections.{$defaultConn}.database");

        if (in_array(strtolower((string) $configuredDb), self::PROTECTED_DATABASES, true)) {
            throw new DatabaseSafetyException(
                "CRITICAL DB-SAFETY VIOLATION: Konfigurasi database testing mengarah ke database utama '{$configuredDb}'! " .
                "Testing DIBATALKAN sebelum koneksi dibuka."
            );
        }

        if (!static::isAllowedTestDatabase((string) $configuredDb)) {
            throw new DatabaseSafetyException(
                "CRITICAL DB-SAFETY VIOLATION: Database yang dikonfigurasi '{$configuredDb}' tidak termasuk database testing resmi! " .
                "Testing hanya diizinkan pada: " . implode(', ', self::ALLOWED_TEST_DATABASES)
            );
        }

        // 3. Validasi database AKTUAL dari koneksi SQL langsung (SELECT DATABASE())
        try {
            $actualDb = static::queryActualDatabaseName($defaultConn);
        } catch (Throwable $e) {
            throw new DatabaseSafetyException(
                "CRITICAL DB-SAFETY VIOLATION: Gagal memverifikasi database aktual melalui query SQL: " . $e->getMessage()
            );
        }

        if (empty($actualDb)) {
            throw new DatabaseSafetyException(
                "CRITICAL DB-SAFETY VIOLATION: Database aktual kosong (tidak ada database yang dipilih pada koneksi)."
            );
        }

        if (in_array(strtolower($actualDb), self::PROTECTED_DATABASES, true)) {
            throw new DatabaseSafetyException(
                "CRITICAL DB-SAFETY VIOLATION: Koneksi SQL aktif terhubung langsung ke database utama '{$actualDb}'! " .
                "Operasi pengujian dan RefreshDatabase DIBLOKIR secara fail-closed."
            );
        }

        if (!static::isAllowedTestDatabase($actualDb)) {
            throw new DatabaseSafetyException(
                "CRITICAL DB-SAFETY VIOLATION: Database SQL aktual '{$actualDb}' tidak diizinkan untuk testing! " .
                "Testing hanya diizinkan pada: " . implode(', ', self::ALLOWED_TEST_DATABASES)
            );
        }

        // 4. Pastikan nama database aktual dan nama yang dikonfigurasi sama persis
        if (strtolower($actualDb) !== strtolower((string) $configuredDb)) {
            throw new DatabaseSafetyException(
                "CRITICAL DB-SAFETY VIOLATION: Terjadi ketidakcocokan antara database konfigurasi '{$configuredDb}' " .
                "dan database SQL aktual '{$actualDb}'!"
            );
        }
    }

    /**
     * Validasi ketat fail-closed sebelum perintah destruktif dijalankan di terminal atau kode.
     *
     * @param string|null $commandName
     * @throws DatabaseSafetyException
     */
    public static function assertSafeForDestructiveArtisanCommand(?string $commandName = null, ?string $connectionName = null): void
    {
        $defaultConn = $connectionName ?: config('database.default');
        $configuredDb = config("database.connections.{$defaultConn}.database");

        // 1. Periksa konfigurasi
        if (in_array(strtolower((string) $configuredDb), self::PROTECTED_DATABASES, true)) {
            $msg = "OPERASI DESTRUKTIF DITOLAK: Perintah '{$commandName}' DILARANG pada database utama '{$configuredDb}'. " .
                   "Operasi drop/wipe/refresh schema hanya diizinkan pada database testing resmi!";
            Log::critical($msg);
            throw new DatabaseSafetyException($msg);
        }

        if (!static::isAllowedTestDatabase((string) $configuredDb)) {
            $msg = "OPERASI DESTRUKTIF DITOLAK: Perintah '{$commandName}' DILARANG karena database '{$configuredDb}' " .
                   "bukan database testing yang diizinkan! (Hanya diizinkan pada: " . implode(', ', self::ALLOWED_TEST_DATABASES) . ")";
            Log::critical($msg);
            throw new DatabaseSafetyException($msg);
        }

        // 2. Periksa database aktual via SQL query
        try {
            $actualDb = static::queryActualDatabaseName($defaultConn);
        } catch (Throwable $e) {
            $msg = "OPERASI DESTRUKTIF DITOLAK: Gagal memverifikasi database aktual sebelum mengeksekusi '{$commandName}': " . $e->getMessage();
            Log::critical($msg);
            throw new DatabaseSafetyException($msg);
        }

        if (in_array(strtolower((string) $actualDb), self::PROTECTED_DATABASES, true) || !static::isAllowedTestDatabase((string) $actualDb)) {
            $msg = "OPERASI DESTRUKTIF DITOLAK: Database SQL aktual '{$actualDb}' adalah database terlindungi! " .
                   "Perintah '{$commandName}' DIBATALKAN seketika.";
            Log::critical($msg);
            throw new DatabaseSafetyException($msg);
        }
    }

    /**
     * Tentukan apakah nama database termasuk daftar database testing resmi (termasuk paralel worker).
     */
    public static function isAllowedTestDatabase(string $dbName): bool
    {
        $clean = strtolower(trim($dbName));

        if (in_array($clean, self::ALLOWED_TEST_DATABASES, true)) {
            return true;
        }

        // Dukungan untuk parallel testing (Paratest worker DB: internship_management_system_testing_test_1, dsb.)
        if (preg_match('/^internship_management_system_testing(_worker_\d+|_test_\d+)$/', $clean)) {
            return true;
        }

        return false;
    }

    /**
     * Ambil nama database aktual langsung dari SQL engine.
     */
    public static function queryActualDatabaseName(?string $connectionName = null): ?string
    {
        $conn = DB::connection($connectionName);
        $result = $conn->selectOne('SELECT DATABASE() AS current_db');

        return $result ? ($result->current_db ?? null) : null;
    }
}
