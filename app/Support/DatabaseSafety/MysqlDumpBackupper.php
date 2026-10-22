<?php

namespace App\Support\DatabaseSafety;

use App\Exceptions\DatabaseSafetyException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Creates a mysqldump backup and verifies it before any destructive reset is allowed.
 * The password is passed via MYSQL_PWD (never on the command line) and never printed.
 */
class MysqlDumpBackupper implements DatabaseBackupper
{
    public function backupAndVerify(string $connectionName, string $databaseName): array
    {
        $cfg = config("database.connections.{$connectionName}");
        if (!is_array($cfg) || ($cfg['driver'] ?? null) !== 'mysql') {
            throw new DatabaseSafetyException("BACKUP GAGAL: koneksi '{$connectionName}' bukan koneksi MySQL yang didukung.");
        }

        $dir = config('sertif_safety.dev_reset_backup_directory');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new DatabaseSafetyException("BACKUP GAGAL: direktori backup '{$dir}' tidak dapat dibuat.");
        }

        $path = $dir . DIRECTORY_SEPARATOR . sprintf('pre_reset_%s_%s.sql', $databaseName, now()->format('Ymd_His'));

        $process = new Process([
            config('sertif_safety.mysqldump_path', 'mysqldump'),
            '--no-defaults',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--host=' . ($cfg['host'] ?? '127.0.0.1'),
            '--port=' . ($cfg['port'] ?? 3306),
            '--user=' . ($cfg['username'] ?? ''),
            '--result-file=' . $path,
            $databaseName,
        ], null, ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')], null, 600);

        try {
            $process->run();
        } catch (Throwable $e) {
            throw new DatabaseSafetyException('BACKUP GAGAL: mysqldump tidak dapat dijalankan: ' . $e->getMessage());
        }

        if (!$process->isSuccessful()) {
            throw new DatabaseSafetyException('BACKUP GAGAL: mysqldump keluar dengan kode ' . $process->getExitCode() . ': ' . trim($process->getErrorOutput()));
        }

        return $this->verify($path, $connectionName, $databaseName);
    }

    /**
     * Verify a dump file: exists, non-empty, completed footer, and table count matches the live schema.
     */
    public function verify(string $path, string $connectionName, string $databaseName): array
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw new DatabaseSafetyException("BACKUP GAGAL DIVERIFIKASI: file '{$path}' tidak ada atau kosong.");
        }

        $contents = file_get_contents($path);
        if (!str_contains($contents, '-- Dump completed')) {
            throw new DatabaseSafetyException('BACKUP GAGAL DIVERIFIKASI: penanda "-- Dump completed" tidak ditemukan (dump terpotong).');
        }

        $dumpTables = preg_match_all('/^CREATE TABLE `/m', $contents);
        $liveTables = (int) DB::connection($connectionName)->selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'",
            [$databaseName]
        )->c;

        if ($dumpTables !== $liveTables) {
            throw new DatabaseSafetyException("BACKUP GAGAL DIVERIFIKASI: jumlah tabel di dump ({$dumpTables}) tidak sama dengan database ({$liveTables}).");
        }

        $sha = hash_file('sha256', $path);
        file_put_contents($path . '.sha256', $sha . '  ' . basename($path) . PHP_EOL);

        return ['path' => $path, 'bytes' => filesize($path), 'sha256' => $sha, 'tables' => $dumpTables];
    }
}
