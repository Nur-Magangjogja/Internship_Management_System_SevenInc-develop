<?php

/*
|--------------------------------------------------------------------------
| SERTIF Database Safety (DB-SAFETY-P0 / P1)
|--------------------------------------------------------------------------
|
| Manual local reset (`php artisan migrate:fresh [--seed]` or
| `php artisan sertif:dev-reset`) on a non-testing database is ONLY possible when:
|   1. APP_ENV=local (production / staging / testing are always rejected),
|   2. dev_reset_enabled is true,
|   3. the target database is listed in dev_reset_allowed_databases,
|   4. the DB host is a loopback address,
|   5. the command is typed directly in an interactive terminal (TTY),
|   6. no CI environment variables are present,
|   7. a verified SQL backup was produced, and
|   8. the operator types the database name AND the approval phrase.
|
| Rehearsal databases can never be reset by this mechanism.
|
*/

return [

    'dev_reset_enabled' => (bool) env('SERTIF_DEV_RESET_ENABLED', false),

    'dev_reset_allowed_databases' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SERTIF_DEV_RESET_ALLOWED_DATABASES', ''))
    ))),

    'dev_reset_allowed_hosts' => ['127.0.0.1', 'localhost', '::1'],

    'dev_reset_backup_directory' => storage_path('app/backups/dev_reset'),

    'mysqldump_path' => env('SERTIF_MYSQLDUMP_PATH', 'mysqldump'),

    'approval_phrase' => 'SAYA SETUJU MENGHAPUS SELURUH DATA',

];
