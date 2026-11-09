<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('spreadsheet_tab_profiles')) {
            Schema::table('spreadsheet_tab_profiles', function (Blueprint $table) {
                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'table_identifier')) {
                    $table->string('table_identifier', 64)->default('default')->after('sheet_id')
                        ->comment('Opaque stable table ID internal untuk membedakan multi-tabel dalam satu sheet');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'table_range')) {
                    $table->string('table_range', 50)->nullable()->after('tab_name')
                        ->comment('Rentang grid tabel (misal A10:AP500), mutable terhadap pergeseran');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'header_row_count')) {
                    $table->unsignedSmallInteger('header_row_count')->default(1)->after('header_row')
                        ->comment('Jumlah baris header untuk mendukung header bertingkat');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'orientation')) {
                    $table->string('orientation', 30)->default('row_per_intern')->after('data_start_row')
                        ->comment('row_per_intern (horizontal) atau col_per_intern (vertikal)');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'source_type')) {
                    $table->string('source_type', 50)->default('registration_form')->after('orientation')
                        ->comment('Klasifikasi tabel: registration_form, intern_roster, active_participants, reference, operational, ignored');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'status_source_type')) {
                    $table->string('status_source_type', 50)->default('none')->after('source_type')
                        ->comment('none, column, cell_color, row_color, manual');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'status_column_letter')) {
                    $table->string('status_column_letter', 10)->nullable()->after('status_source_type')
                        ->comment('Huruf kolom penentu status teks, misal AL');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'column_mapping')) {
                    $table->json('column_mapping')->nullable()->after('status_column_letter')
                        ->comment('Struktur pemetaan kolom universal berbasis koordinat & column_identity');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'field_ownership_policies')) {
                    $table->json('field_ownership_policies')->nullable()->after('column_mapping')
                        ->comment('Aturan kepemilikan data per-field: source_authoritative, sertif_authoritative, manual_approval, read_only');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'status_mapping')) {
                    $table->json('status_mapping')->nullable()->after('field_ownership_policies')
                        ->comment('Aturan pemetaan nilai teks atau warna ke enum status SertIF');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'last_schema_hash')) {
                    $table->string('last_schema_hash', 64)->nullable()->after('status_mapping')
                        ->comment('Hash skema kolom terakhir untuk deteksi schema drift');
                }

                if (!Schema::hasColumn('spreadsheet_tab_profiles', 'mapping_version')) {
                    $table->unsignedInteger('mapping_version')->default(1)->after('last_schema_hash')
                        ->comment('Versi konfigurasi pemetaan tabel');
                }
            });

            // Ganti unique constraint agar aman untuk multi-tabel per sheet_id
            try {
                Schema::table('spreadsheet_tab_profiles', function (Blueprint $table) {
                    $table->dropUnique('conn_sheet_id_unique');
                });
            } catch (\Throwable $e) {
                // Ignore if unique index was named differently or already dropped
            }

            try {
                Schema::table('spreadsheet_tab_profiles', function (Blueprint $table) {
                    $table->unique(['spreadsheet_connection_id', 'sheet_id', 'table_identifier'], 'conn_sheet_table_unique');
                });
            } catch (\Throwable $e) {
                // Ignore if already created
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('spreadsheet_tab_profiles')) {
            try {
                Schema::table('spreadsheet_tab_profiles', function (Blueprint $table) {
                    $table->dropUnique('conn_sheet_table_unique');
                });
            } catch (\Throwable $e) {}

            try {
                Schema::table('spreadsheet_tab_profiles', function (Blueprint $table) {
                    $table->unique(['spreadsheet_connection_id', 'sheet_id'], 'conn_sheet_id_unique');
                });
            } catch (\Throwable $e) {}

            Schema::table('spreadsheet_tab_profiles', function (Blueprint $table) {
                $cols = [
                    'mapping_version',
                    'last_schema_hash',
                    'status_mapping',
                    'field_ownership_policies',
                    'column_mapping',
                    'status_column_letter',
                    'status_source_type',
                    'source_type',
                    'orientation',
                    'header_row_count',
                    'table_range',
                    'table_identifier',
                ];

                foreach ($cols as $col) {
                    if (Schema::hasColumn('spreadsheet_tab_profiles', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
