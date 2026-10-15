<?php

namespace App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrationRollbackPreflightService
{
    public const STATUS_PASS    = 'PASS';
    public const STATUS_BLOCKED = 'BLOCKED';
    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const MYSQL_NON_ATOMIC_DISCLAIMER = 'PERINGATAN DDL MYSQL: MySQL mengeksekusi IMPLICIT COMMIT pada setiap pernyataan DDL (DROP TABLE, DROP COLUMN, ALTER TABLE). Jika satu migration dalam batch berhasil di-rollback dan migration berikutnya gagal/terblokir, state skema database tidak dapat di-rollback secara atomik transaksional. Pemeriksaan preflight ini bersifat read-only dan wajib dilakukan SEBELUM perintah migrate:rollback pertama dieksekusi.';

    /**
     * Katalog migration yang telah diaudit risikonya.
     * Setiap entri mendefinisikan operasi DDL destruktif dan fungsi evaluasi bahayanya.
     */
    protected array $knownMigrationRiskCatalog = [
        '2026_10_13_000006_create_spreadsheet_sidecar_syncs_table' => [
            'type'        => 'DROP_TABLE',
            'tables'      => ['spreadsheet_sidecar_syncs'],
            'description' => "Menghapus tabel 'spreadsheet_sidecar_syncs'.",
        ],
        '2026_10_13_000005_add_grace_period_to_form_schemas_table' => [
            'type'        => 'DROP_COLUMN',
            'table'       => 'form_schemas',
            'columns'     => ['grace_period_days'],
            'description' => "Menghapus kolom 'grace_period_days' dari tabel 'form_schemas'.",
        ],
        '2026_10_13_000004_create_registration_form_snapshots_and_answers_tables' => [
            'type'        => 'DROP_TABLE',
            'tables'      => ['registration_custom_answers', 'registration_form_snapshots'],
            'description' => "Menghapus tabel 'registration_custom_answers' dan 'registration_form_snapshots'.",
        ],
        '2026_10_13_000003_evolve_form_fields_table' => [
            'type'        => 'DROP_COLUMN_AND_CONSTRAINTS',
            'table'       => 'form_fields',
            'columns'     => ['form_schema_id', 'is_archived', 'validation_rules', 'deleted_at'],
            'description' => "Menghapus foreign key form_schema_id, kolom validation_rules, kolom is_archived, soft deletes, dan memulihkan unique index field_key.",
        ],
        '2026_10_13_000002_create_form_schemas_and_registries_table' => [
            'type'        => 'DROP_TABLE',
            'tables'      => ['form_field_registries', 'form_schemas'],
            'description' => "Menghapus tabel master 'form_field_registries' dan 'form_schemas'.",
        ],
        '2026_10_13_000001_add_remember_token_to_users_table' => [
            'type'        => 'DROP_COLUMN',
            'table'       => 'users',
            'columns'     => ['remember_token'],
            'description' => "Menghapus kolom 'remember_token' dari tabel 'users'.",
        ],
        '2026_09_30_000010_add_followup_status_to_internship_registrations_table' => [
            'type'        => 'DROP_COLUMN',
            'table'       => 'internship_registrations',
            'columns'     => ['followup_status'],
            'description' => "Menghapus kolom 'followup_status' dari 'internship_registrations'.",
        ],
    ];

    /**
     * Lakukan inspeksi read-only preflight untuk seluruh batch rollback berdasarkan migration ledger.
     *
     * @param int|null $targetBatch Nomor batch target; jika null, otomatis mengambil batch terakhir.
     * @return array{
     *     status: string,
     *     batch: int|null,
     *     migrations_count: int,
     *     migrations: string[],
     *     hazards: string[],
     *     foreign_key_hazards: string[],
     *     unknown_migrations: string[],
     *     details: array<string, mixed>,
     *     mysql_non_atomic_warning: string,
     *     is_safe: bool
     * }
     */
    public function inspectBatchRollbackHazards(?int $targetBatch = null): array
    {
        $hazards           = [];
        $foreignKeyHazards = [];
        $unknownMigrations = [];
        $details           = [];

        // 1. Validasi keberadaan ledger migration
        if (!Schema::hasTable('migrations')) {
            return [
                'status'                   => self::STATUS_UNKNOWN,
                'batch'                    => $targetBatch,
                'migrations_count'         => 0,
                'migrations'               => [],
                'hazards'                  => ["Tabel ledger 'migrations' tidak ditemukan di database. Status batch rollback tidak dapat dipastikan (fail-closed)."],
                'foreign_key_hazards'      => [],
                'unknown_migrations'       => [],
                'details'                  => ['error' => 'MISSING_MIGRATIONS_TABLE'],
                'mysql_non_atomic_warning' => self::MYSQL_NON_ATOMIC_DISCLAIMER,
                'is_safe'                  => false,
            ];
        }

        // 2. Tentukan target batch
        if ($targetBatch === null) {
            $latestBatch = DB::table('migrations')->max('batch');
            if ($latestBatch === null) {
                return [
                    'status'                   => self::STATUS_PASS,
                    'batch'                    => null,
                    'migrations_count'         => 0,
                    'migrations'               => [],
                    'hazards'                  => [],
                    'foreign_key_hazards'      => [],
                    'unknown_migrations'       => [],
                    'details'                  => ['message' => 'Tidak ada migration yang tercatat dalam ledger untuk di-rollback.'],
                    'mysql_non_atomic_warning' => self::MYSQL_NON_ATOMIC_DISCLAIMER,
                    'is_safe'                  => true,
                ];
            }
            $targetBatch = (int) $latestBatch;
        }

        // 3. Ambil seluruh migration dalam batch target dengan urutan rollback aktual (ID DESC)
        $migrationsInBatch = DB::table('migrations')
            ->where('batch', $targetBatch)
            ->orderBy('id', 'desc')
            ->pluck('migration')
            ->toArray();

        if (empty($migrationsInBatch)) {
            return [
                'status'                   => self::STATUS_PASS,
                'batch'                    => $targetBatch,
                'migrations_count'         => 0,
                'migrations'               => [],
                'hazards'                  => [],
                'foreign_key_hazards'      => [],
                'unknown_migrations'       => [],
                'details'                  => ['message' => "Batch #{$targetBatch} tidak memuat migration apapun."],
                'mysql_non_atomic_warning' => self::MYSQL_NON_ATOMIC_DISCLAIMER,
                'is_safe'                  => true,
            ];
        }

        $details['target_batch']       = $targetBatch;
        $details['migrations_in_batch'] = $migrationsInBatch;

        // 4. Evaluasi setiap migration dalam batch
        foreach ($migrationsInBatch as $migrationName) {
            if (!isset($this->knownMigrationRiskCatalog[$migrationName])) {
                $unknownMigrations[] = $migrationName;
                $hazards[] = "Migration unknown '{$migrationName}' tidak terdaftar dalam katalog risiko preflight. Operasi DDL tidak dapat dipastikan aman (fail-closed).";
                continue;
            }

            $risk = $this->knownMigrationRiskCatalog[$migrationName];

            // Evaluasi bahaya per migration
            switch ($migrationName) {
                case '2026_10_13_000006_create_spreadsheet_sidecar_syncs_table':
                    if (Schema::hasTable('spreadsheet_sidecar_syncs')) {
                        $count = DB::table('spreadsheet_sidecar_syncs')->count();
                        $details['spreadsheet_sidecar_syncs_count'] = $count;
                        if ($count > 0) {
                            $hazards[] = "Migration [000006]: Tabel 'spreadsheet_sidecar_syncs' memuat {$count} log sinkronisasi. DROP TABLE akan memusnahkan audit trail.";
                        }
                    }
                    break;

                case '2026_10_13_000005_add_grace_period_to_form_schemas_table':
                    if (Schema::hasTable('form_schemas') && Schema::hasColumn('form_schemas', 'grace_period_days')) {
                        $pubArchived = DB::table('form_schemas')->whereIn('status', ['published', 'archived'])->count();
                        $withGrace   = DB::table('form_schemas')->whereNotNull('grace_period_days')->count();
                        $details['form_schemas_grace_period_count'] = $withGrace;
                        if ($pubArchived > 0 || $withGrace > 0) {
                            $hazards[] = "Migration [000005]: Tabel 'form_schemas' memuat {$withGrace} konfigurasi 'grace_period_days' ({$pubArchived} skema aktif/arsip). DROP COLUMN akan merusak kebijakan toleransi submisi.";
                        }
                    }
                    break;

                case '2026_10_13_000004_create_registration_form_snapshots_and_answers_tables':
                    if (Schema::hasTable('registration_custom_answers')) {
                        $answers = DB::table('registration_custom_answers')->count();
                        $details['registration_custom_answers_count'] = $answers;
                        if ($answers > 0) {
                            $hazards[] = "Migration [000004]: Tabel 'registration_custom_answers' memuat {$answers} jawaban peserta. DROP TABLE akan memusnahkan data pendaftar.";
                        }
                    }
                    if (Schema::hasTable('registration_form_snapshots')) {
                        $snaps = DB::table('registration_form_snapshots')->count();
                        $details['registration_form_snapshots_count'] = $snaps;
                        if ($snaps > 0) {
                            $hazards[] = "Migration [000004]: Tabel 'registration_form_snapshots' memuat {$snaps} snapshot formulir historis. DROP TABLE akan memusnahkan snapshot pendaftaran.";
                        }
                    }
                    break;

                case '2026_10_13_000003_evolve_form_fields_table':
                    if (Schema::hasTable('form_fields')) {
                        // A. Duplicate field keys across schemas
                        $duplicates = DB::table('form_fields')
                            ->select('field_key', DB::raw('COUNT(*) as total'))
                            ->groupBy('field_key')
                            ->havingRaw('COUNT(*) > 1')
                            ->pluck('field_key')
                            ->all();
                        $details['duplicate_field_keys'] = $duplicates;
                        if (!empty($duplicates)) {
                            $keysStr = implode(', ', $duplicates);
                            $hazards[] = "Migration [000003]: Tabel 'form_fields' memuat duplikasi field_key ({$keysStr}). Pemulihan unique index global pasti gagal (MySQL Error 1062).";
                        }

                        // B. DROP COLUMN validation_rules data loss
                        if (Schema::hasColumn('form_fields', 'validation_rules')) {
                            $rulesCount = DB::table('form_fields')->whereNotNull('validation_rules')->count();
                            $details['form_fields_validation_rules_count'] = $rulesCount;
                            if ($rulesCount > 0) {
                                $hazards[] = "Migration [000003]: Tabel 'form_fields' memuat {$rulesCount} aturan validasi dinamis. DROP COLUMN 'validation_rules' akan menghilangkan definisi validasi field.";
                            }
                        }

                        // C. DROP COLUMN form_schema_id
                        if (Schema::hasColumn('form_fields', 'form_schema_id')) {
                            $linkedCount = DB::table('form_fields')->whereNotNull('form_schema_id')->count();
                            $details['form_fields_schema_linked_count'] = $linkedCount;
                            if ($linkedCount > 0) {
                                $hazards[] = "Migration [000003]: Tabel 'form_fields' memuat {$linkedCount} field yang terikat ke skema formulir. DROP COLUMN 'form_schema_id' akan memutus relasi versi formulir.";
                            }
                        }
                    }
                    break;

                case '2026_10_13_000002_create_form_schemas_and_registries_table':
                    if (Schema::hasTable('form_schemas')) {
                        $schemas = DB::table('form_schemas')->count();
                        $details['form_schemas_count'] = $schemas;
                        if ($schemas > 0) {
                            $hazards[] = "Migration [000002]: Tabel 'form_schemas' memuat {$schemas} skema formulir. DROP TABLE akan menghapus seluruh definisi formulir.";
                        }
                    }
                    if (Schema::hasTable('form_field_registries')) {
                        $registries = DB::table('form_field_registries')->count();
                        $details['form_field_registries_count'] = $registries;
                        if ($registries > 0) {
                            $hazards[] = "Migration [000002]: Tabel 'form_field_registries' memuat {$registries} registri field. DROP TABLE akan memutus rekam jejak identitas field.";
                        }
                    }

                    // Foreign key dependency check: Apakah ada tabel di luar batch atau di langkah berikutnya yang masih mereferensikan form_schemas?
                    if (Schema::hasTable('form_fields') && Schema::hasColumn('form_fields', 'form_schema_id')) {
                        $referencingFields = DB::table('form_fields')->whereNotNull('form_schema_id')->count();
                        if ($referencingFields > 0 && !in_array('2026_10_13_000003_evolve_form_fields_table', $migrationsInBatch, true)) {
                            $foreignKeyHazards[] = "Foreign Key Conflict: Tabel 'form_fields' memuat {$referencingFields} baris yang mereferensikan 'form_schemas', namun migration 000003 tidak termasuk dalam batch rollback ini. MySQL akan menolak DROP TABLE form_schemas.";
                        }
                    }
                    break;
            }
        }

        // 5. Evaluasi status akhir
        $status = self::STATUS_PASS;
        if (!empty($unknownMigrations)) {
            $status = self::STATUS_UNKNOWN;
        } elseif (!empty($hazards) || !empty($foreignKeyHazards)) {
            $status = self::STATUS_BLOCKED;
        }

        return [
            'status'                   => $status,
            'batch'                    => $targetBatch,
            'migrations_count'         => count($migrationsInBatch),
            'migrations'               => $migrationsInBatch,
            'hazards'                  => $hazards,
            'foreign_key_hazards'      => $foreignKeyHazards,
            'unknown_migrations'       => $unknownMigrations,
            'details'                  => $details,
            'mysql_non_atomic_warning' => self::MYSQL_NON_ATOMIC_DISCLAIMER,
            'is_safe'                  => ($status === self::STATUS_PASS),
        ];
    }

    /**
     * Memastikan batch aman sebelum rollback; melempar DomainException jika status bukan PASS.
     *
     * @throws DomainException
     */
    public function assertSafeBatchRollback(?int $targetBatch = null): void
    {
        $inspection = $this->inspectBatchRollbackHazards($targetBatch);

        if (!$inspection['is_safe']) {
            $allHazards = array_merge($inspection['hazards'], $inspection['foreign_key_hazards']);
            $msg = implode("\n- ", $allHazards);
            throw new DomainException(
                "PREFLIGHT_ROLLBACK_BLOCKED [Status: {$inspection['status']}]: Rollback batch #{$inspection['batch']} dicegah fail-closed:\n- {$msg}\nDisarankan menggunakan strategi forward-fix."
            );
        }
    }

    /**
     * Lakukan inspeksi read-only preflight tabel untuk mendeteksi bahaya data loss sebelum rollback.
     * (Backward-compatible method untuk pemanggil G.4R)
     *
     * @return array{can_safely_rollback: bool, hazards: string[], details: array<string, mixed>}
     */
    public function inspectRollbackHazards(): array
    {
        $hazards = [];
        $details = [];

        // 1. Periksa data jawaban pendaftaran kustom (000004)
        if (Schema::hasTable('registration_custom_answers')) {
            $answerCount = DB::table('registration_custom_answers')->count();
            $details['registration_custom_answers_count'] = $answerCount;
            if ($answerCount > 0) {
                $hazards[] = "Tabel 'registration_custom_answers' memuat {$answerCount} jawaban peserta aktif. Rollback akan menghapus data jawaban pendaftar secara permanen.";
            }
        }

        // 2. Periksa snapshot formulir pendaftaran historis (000004)
        if (Schema::hasTable('registration_form_snapshots')) {
            $snapshotCount = DB::table('registration_form_snapshots')->count();
            $details['registration_form_snapshots_count'] = $snapshotCount;
            if ($snapshotCount > 0) {
                $hazards[] = "Tabel 'registration_form_snapshots' memuat {$snapshotCount} snapshot formulir historis. Rollback akan menghapus data snapshot versi formulir peserta.";
            }
        }

        // 3. Periksa record sinkronisasi sidecar spreadsheet (000006)
        if (Schema::hasTable('spreadsheet_sidecar_syncs')) {
            $sidecarCount = DB::table('spreadsheet_sidecar_syncs')->count();
            $details['spreadsheet_sidecar_syncs_count'] = $sidecarCount;
            if ($sidecarCount > 0) {
                $hazards[] = "Tabel 'spreadsheet_sidecar_syncs' memuat {$sidecarCount} status sinkronisasi sidecar. Rollback akan merusak audit trail sinkronisasi.";
            }
        }

        // 4. Periksa duplikasi field_key pada skema versi (000003)
        if (Schema::hasTable('form_fields')) {
            $duplicateKeys = DB::table('form_fields')
                ->select('field_key', DB::raw('COUNT(*) as total'))
                ->groupBy('field_key')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('field_key')
                ->all();
            $details['duplicate_field_keys'] = $duplicateKeys;
            if (!empty($duplicateKeys)) {
                $keysStr = implode(', ', $duplicateKeys);
                $hazards[] = "Tabel 'form_fields' memuat duplikasi field_key pada multi-versi ({$keysStr}). Pemulihan unique index global pada field_key akan gagal (MySQL Error 1062).";
            }
        }

        // 5. Periksa definisi skema dan registri formulir (000002)
        if (Schema::hasTable('form_schemas')) {
            $schemaCount = DB::table('form_schemas')->count();
            $details['form_schemas_count'] = $schemaCount;
            if ($schemaCount > 0) {
                $hazards[] = "Tabel 'form_schemas' memuat {$schemaCount} definisi formulir. Rollback akan menghapus master formulir magang.";
            }
        }

        if (Schema::hasTable('form_field_registries')) {
            $registryCount = DB::table('form_field_registries')->count();
            $details['form_field_registries_count'] = $registryCount;
            if ($registryCount > 0) {
                $hazards[] = "Tabel 'form_field_registries' memuat {$registryCount} registri field kustom. Rollback akan memutus riwayat versi field.";
            }
        }

        return [
            'can_safely_rollback' => empty($hazards),
            'hazards'             => $hazards,
            'details'             => $details,
        ];
    }

    /**
     * Memastikan rollback aman; melempar DomainException jika ada bahaya data loss.
     *
     * @throws DomainException
     */
    public function assertSafeRollback(): void
    {
        $inspection = $this->inspectRollbackHazards();

        if (!$inspection['can_safely_rollback']) {
            $msg = implode("\n- ", $inspection['hazards']);
            throw new DomainException(
                "PREFLIGHT_ROLLBACK_BLOCKED: Terdeteksi bahaya data loss destruktif:\n- {$msg}\nDisarankan menggunakan strategi forward-fix."
            );
        }
    }
}
