<?php

namespace App\Support\DatabaseSafety;

use App\Exceptions\DatabaseSafetyException;
use App\Support\DatabaseSafetyGuard;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * DB-SAFETY-P1: Controlled manual reset gate.
 *
 * Every destructive schema command targeting a NON-testing database must pass this gate on
 * each invocation. There is no global switch that disables the guard; the gate only opens for
 * a single, directly typed, interactive command after environment validation, a verified
 * backup, and explicit typed confirmation.
 */
class ManualResetGate
{
    /** Tables shown to the operator as "data at risk". */
    public const IMPACT_TABLES = [
        'users',
        'internship_registrations',
        'registration_attachments',
        'brands',
        'institutions',
        'divisions',
        'document_templates',
        'generated_documents',
        'form_schemas',
        'spreadsheet_connections',
        'activity_logs',
    ];

    /** Tables whose active row-level locks indicate a running sync/queue writer. */
    public const LOCK_TABLES = [
        'spreadsheet_row_syncs'     => 'locked_at',
        'spreadsheet_sidecar_syncs' => 'locked_at',
        'absen_sync_states'         => 'locked_at',
        'integration_outbox_events' => 'locked_at',
    ];

    public const CI_ENV_VARS = [
        'CI', 'GITHUB_ACTIONS', 'GITLAB_CI', 'JENKINS_URL', 'BUILDKITE',
        'TEAMCITY_VERSION', 'BITBUCKET_BUILD_NUMBER', 'CIRCLECI', 'TRAVIS',
    ];

    /** Result of the most recent authorization in this process, used for the post-reset report. */
    protected static ?array $lastAuthorization = null;

    /** Holds in-flight reset authorization so chained sub-commands (e.g. migrate:fresh) know they are authorized */
    protected static ?array $activeResetSession = null;

    public function __construct(protected DatabaseBackupper $backupper) {}

    // ---------------------------------------------------------------------
    // Context
    // ---------------------------------------------------------------------

    public function buildContext(InputInterface $input, string $commandName, string $connectionName): ManualResetContext
    {
        $cfg = config("database.connections.{$connectionName}", []);

        $actual = null;
        try {
            $actual = DatabaseSafetyGuard::queryActualDatabaseName($connectionName);
        } catch (Throwable) {
            $actual = null; // unreachable / access denied => fail closed below
        }

        return new ManualResetContext(
            commandName: $commandName,
            environment: (string) App::environment(),
            connectionName: $connectionName,
            driver: $cfg['driver'] ?? null,
            host: isset($cfg['host']) ? (string) $cfg['host'] : null,
            configuredDatabase: isset($cfg['database']) ? (string) $cfg['database'] : null,
            actualDatabase: $actual,
            directTerminalInvocation: static::isDirectTerminalInvocation($input, $commandName),
            interactive: $input->isInteractive(),
            stdinIsTty: static::stdinIsTty(),
            ciDetected: static::ciDetected(),
        );
    }

    public static function isDirectTerminalInvocation(InputInterface $input, string $commandName): bool
    {
        return PHP_SAPI === 'cli'
            && $input instanceof ArgvInput
            && $input->getFirstArgument() === $commandName;
    }

    public static function stdinIsTty(): bool
    {
        if (!defined('STDIN')) {
            return false;
        }
        if (function_exists('stream_isatty') && @stream_isatty(STDIN)) {
            return true;
        }
        if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
            return true;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            if (getenv('WT_SESSION') || getenv('ConEmuPID') || getenv('TERM') || getenv('ANSICON') || getenv('PROMPT') || getenv('PSModulePath')) {
                return true;
            }
        }

        return false;
    }

    public static function ciDetected(): bool
    {
        foreach (self::CI_ENV_VARS as $var) {
            $val = getenv($var);
            if ($val === false) {
                $val = $_SERVER[$var] ?? $_ENV[$var] ?? null;
            }
            if ($val !== null && $val !== false && $val !== '' && strtolower((string) $val) !== 'false' && $val !== '0') {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Eligibility (pure)
    // ---------------------------------------------------------------------

    /**
     * @return string[] list of violations; empty means eligible
     */
    public function assessEligibility(ManualResetContext $ctx): array
    {
        $v = [];
        $db = strtolower((string) $ctx->configuredDatabase);

        if ($ctx->environment !== 'local') {
            $v[] = "APP_ENV harus 'local' untuk reset manual (saat ini '{$ctx->environment}'). Production, staging, dan testing selalu ditolak.";
        }
        if ($ctx->ciDetected) {
            $v[] = 'Lingkungan CI terdeteksi. Reset manual tidak diizinkan di CI.';
        }
        if ($db === '' ) {
            $v[] = 'Nama database target tidak dikonfigurasi.';
        }
        if (str_contains($db, 'rehearsal')) {
            $v[] = "Database rehearsal '{$ctx->configuredDatabase}' TIDAK PERNAH boleh direset.";
        }
        if (DatabaseSafetyGuard::isAllowedTestDatabase($db)) {
            $v[] = "Database testing '{$ctx->configuredDatabase}' dikelola oleh PHPUnit, bukan reset manual.";
        }
        if (!config('sertif_safety.dev_reset_enabled')) {
            $v[] = 'Reset manual belum diaktifkan (SERTIF_DEV_RESET_ENABLED=false).';
        }
        $allowed = array_map('strtolower', (array) config('sertif_safety.dev_reset_allowed_databases', []));
        if ($db !== '' && !in_array($db, $allowed, true)) {
            $v[] = "Database '{$ctx->configuredDatabase}' tidak terdaftar di SERTIF_DEV_RESET_ALLOWED_DATABASES.";
        }
        if ($ctx->driver !== 'mysql') {
            $v[] = "Driver '{$ctx->driver}' tidak didukung untuk reset manual (hanya mysql).";
        }
        $hosts = array_map('strtolower', (array) config('sertif_safety.dev_reset_allowed_hosts', []));
        if (!in_array(strtolower((string) $ctx->host), $hosts, true)) {
            $v[] = "Host database '{$ctx->host}' bukan host lokal (loopback).";
        }
        if (empty($ctx->actualDatabase)) {
            $v[] = 'Database aktual tidak dapat diverifikasi melalui SELECT DATABASE().';
        } elseif (strtolower($ctx->actualDatabase) !== $db) {
            $v[] = "Database aktual '{$ctx->actualDatabase}' berbeda dari konfigurasi '{$ctx->configuredDatabase}'.";
        }
        if (!$ctx->directTerminalInvocation) {
            $v[] = 'Perintah tidak diketik langsung di terminal (pemanggilan terprogram / Artisan::call / queue / scheduler ditolak).';
        }
        if (!$ctx->interactive) {
            $v[] = 'Eksekusi noninteraktif (--no-interaction / -n) ditolak; konfirmasi tidak dapat dilewati.';
        }
        if (!$ctx->stdinIsTty) {
            $v[] = 'STDIN bukan terminal interaktif (TTY).';
        }

        return $v;
    }

    // ---------------------------------------------------------------------
    // Impact & writer checks (read-only)
    // ---------------------------------------------------------------------

    public function impactSummary(string $connectionName): array
    {
        $schema = Schema::connection($connectionName);
        $conn = DB::connection($connectionName);
        $out = [];

        foreach (self::IMPACT_TABLES as $table) {
            if ($schema->hasTable($table)) {
                $out[$table] = (int) $conn->table($table)->count();
            }
        }

        return $out;
    }

    /**
     * @return string[] blockers caused by in-progress queue jobs or sync leases
     */
    public function activeWriterBlockers(string $connectionName): array
    {
        $schema = Schema::connection($connectionName);
        $conn = DB::connection($connectionName);
        $blockers = [];

        if ($schema->hasTable('jobs') && $schema->hasColumn('jobs', 'reserved_at')) {
            $reserved = $conn->table('jobs')->whereNotNull('reserved_at')->count();
            if ($reserved > 0) {
                $blockers[] = "{$reserved} queue job sedang diproses (jobs.reserved_at terisi). Hentikan queue worker terlebih dahulu.";
            }
        }

        foreach (self::LOCK_TABLES as $table => $column) {
            if ($schema->hasTable($table) && $schema->hasColumn($table, $column)) {
                $active = $conn->table($table)->where($column, '>', now()->subMinutes(10))->count();
                if ($active > 0) {
                    $blockers[] = "{$active} lease sinkronisasi aktif pada {$table}.{$column}.";
                }
            }
        }

        if ($schema->hasTable('registration_attachments') && $schema->hasColumn('registration_attachments', 'drive_lock_expires_at')) {
            $active = $conn->table('registration_attachments')->where('drive_lock_expires_at', '>', now())->count();
            if ($active > 0) {
                $blockers[] = "{$active} upload Google Drive sedang berjalan (registration_attachments.drive_lock_expires_at).";
            }
        }

        return $blockers;
    }

    public function confirmationMatches(?string $typedDatabase, ?string $typedPhrase, string $expectedDatabase): bool
    {
        return trim((string) $typedDatabase) === $expectedDatabase
            && trim((string) $typedPhrase) === config('sertif_safety.approval_phrase');
    }

    // ---------------------------------------------------------------------
    // Orchestration
    // ---------------------------------------------------------------------

    /**
     * Run the full gate. Throws DatabaseSafetyException on any failure; returns authorization data on success.
     * This method NEVER performs the reset itself.
     *
     * @param callable(string):?string $ask
     * @param callable(string):void    $say
     */
    public function authorize(ManualResetContext $ctx, callable $ask, callable $say): array
    {
        static::$lastAuthorization = null;
        $db = (string) $ctx->configuredDatabase;

        $violations = $this->assessEligibility($ctx);
        if ($violations !== []) {
            $this->reject($ctx, "Reset manual '{$ctx->commandName}' pada '{$db}' DITOLAK:\n - " . implode("\n - ", $violations));
        }

        $say('');
        $say('==================== SERTIF MANUAL DATABASE RESET ====================');
        $say("Perintah        : {$ctx->commandName}");
        $say("Koneksi         : {$ctx->connectionName} ({$ctx->host})");
        $say("Database aktual : {$ctx->actualDatabase}   (SELECT DATABASE())");
        $say('Data yang akan TERHAPUS:');
        $impact = $this->impactSummary($ctx->connectionName);
        foreach ($impact as $table => $count) {
            $say(sprintf('   - %-28s %d baris', $table, $count));
        }

        $blockers = $this->activeWriterBlockers($ctx->connectionName);
        if ($blockers !== []) {
            $this->reject($ctx, "Reset DITOLAK karena ada penulis aktif:\n - " . implode("\n - ", $blockers));
        }

        $say('');
        $typedDb = $ask("Ketik nama database yang akan DIHAPUS ({$db})");
        $typedPhrase = $ask('Ketik pernyataan persetujuan persis: "' . config('sertif_safety.approval_phrase') . '"');

        if (!$this->confirmationMatches($typedDb, $typedPhrase, $db)) {
            $this->reject($ctx, 'Konfirmasi tidak cocok. Reset DIBATALKAN, tidak ada perubahan.');
        }

        $say('Membuat backup SQL terverifikasi...');
        $backup = $this->backupper->backupAndVerify($ctx->connectionName, $db); // throws on failure
        $say("Backup OK       : {$backup['path']}");
        $say("SHA-256         : {$backup['sha256']}  ({$backup['bytes']} bytes, {$backup['tables']} tabel)");

        // Final re-check right before the destructive step.
        $blockers = $this->activeWriterBlockers($ctx->connectionName);
        if ($blockers !== []) {
            $this->reject($ctx, "Reset DITOLAK (penulis aktif muncul setelah backup):\n - " . implode("\n - ", $blockers));
        }

        $result = [
            'command'    => $ctx->commandName,
            'connection' => $ctx->connectionName,
            'database'   => $db,
            'impact'     => $impact,
            'backup'     => $backup,
        ];

        Log::warning('DB-SAFETY-P1: manual reset authorized', [
            'command' => $ctx->commandName, 'database' => $db, 'backup' => $backup['path'],
        ]);

        return static::$lastAuthorization = $result;
    }

    /**
     * Console entry point used by the CommandStarting listener and sertif:dev-reset.
     */
    public function authorizeFromConsole(string $commandName, InputInterface $input, OutputInterface $output, string $connectionName): array
    {
        $ctx = $this->buildContext($input, $commandName, $connectionName);
        $io = new SymfonyStyle($input, $output);

        return $this->authorize(
            $ctx,
            fn (string $q) => $io->ask($q),
            fn (string $line) => $output->writeln($line),
        );
    }

    public static function beginDevReset(string $connection, array $auth): void
    {
        static::$activeResetSession = [
            'connection' => $connection,
            'auth' => $auth,
        ];
    }

    public static function isDevResetRunningFor(string $connection): bool
    {
        return static::$activeResetSession !== null
            && static::$activeResetSession['connection'] === $connection;
    }

    public static function endDevReset(): ?array
    {
        $session = static::$activeResetSession;
        static::$activeResetSession = null;
        return $session !== null ? $session['auth'] : null;
    }

    public static function lastAuthorization(): ?array
    {
        return static::$lastAuthorization;
    }

    public static function clearAuthorization(): void
    {
        static::$lastAuthorization = null;
    }

    /**
     * Post-reset report: migrations, seed status, row counts, backup location.
     */
    public function report(array $auth, OutputInterface $output, bool $seeded, int $exitCode): void
    {
        $conn = $auth['connection'];
        $output->writeln('');
        $output->writeln('==================== HASIL RESET MANUAL ====================');
        $output->writeln('Exit code       : ' . $exitCode);
        try {
            $actual = DatabaseSafetyGuard::queryActualDatabaseName($conn);
            $migrations = Schema::connection($conn)->hasTable('migrations')
                ? DB::connection($conn)->table('migrations')->count() : 0;
            $output->writeln("Database        : {$actual}");
            $output->writeln("Migrations ran  : {$migrations}");
            $output->writeln('Seeder          : ' . ($seeded ? 'dijalankan (--seed)' : 'tidak dijalankan'));
            foreach ($this->impactSummary($conn) as $table => $count) {
                $output->writeln(sprintf('   - %-28s %d baris', $table, $count));
            }
        } catch (Throwable $e) {
            $output->writeln('Status database tidak dapat dibaca: ' . $e->getMessage());
        }
        $output->writeln("Backup          : {$auth['backup']['path']}");
        $output->writeln('Data historis TIDAK dipulihkan otomatis. Gunakan file backup di atas bila diperlukan.');
    }

    protected function reject(ManualResetContext $ctx, string $message): never
    {
        static::$lastAuthorization = null;
        $msg = 'OPERASI DESTRUKTIF DITOLAK: ' . $message;
        Log::critical($msg, ['command' => $ctx->commandName, 'database' => $ctx->configuredDatabase]);
        throw new DatabaseSafetyException($msg);
    }
}
