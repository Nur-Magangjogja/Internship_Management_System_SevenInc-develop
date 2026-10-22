<?php

namespace App\Console\Commands;

use App\Exceptions\DatabaseSafetyException;
use App\Support\DatabaseSafety\ManualResetGate;
use Illuminate\Console\Command;
use Throwable;

class DevResetDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sertif:dev-reset
                            {--seed : Seed the database with records after reset}
                            {--database= : The database connection to reset (defaults to default connection)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely and manually reset the local development database with pre-reset backup and confirmation (DB-SAFETY-P1)';

    public function handle(ManualResetGate $gate): int
    {
        $connection = (string) ($this->option('database') ?: config('database.default'));

        $this->info('Memulai prosedur reset manual database pengembangan (DB-SAFETY-P1)...');

        try {
            $auth = $gate->authorizeFromConsole(
                $this->getName(),
                $this->input,
                $this->output,
                $connection
            );
        } catch (DatabaseSafetyException $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        ManualResetGate::beginDevReset($connection, $auth);

        $exitCode = Command::SUCCESS;
        $seed = (bool) $this->option('seed');

        try {
            $params = ['--database' => $connection, '--force' => true];
            if ($seed) {
                $params['--seed'] = true;
            }

            $this->newLine();
            $this->warn('Menjalankan migrate:fresh...');
            $exitCode = $this->call('migrate:fresh', $params);
        } catch (Throwable $e) {
            $this->error('Terjadi kesalahan saat mengeksekusi migrate:fresh: ' . $e->getMessage());
            $exitCode = Command::FAILURE;
        } finally {
            ManualResetGate::endDevReset();
        }

        $gate->report($auth, $this->output, $seed, $exitCode);
        ManualResetGate::clearAuthorization();

        return $exitCode;
    }
}
