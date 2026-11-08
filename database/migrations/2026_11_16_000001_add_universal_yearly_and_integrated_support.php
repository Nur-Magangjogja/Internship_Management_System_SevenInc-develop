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
        // 1. Tambah pengaturan tahunan dan pembuatan tab pada spreadsheet_connections
        if (Schema::hasTable('spreadsheet_connections')) {
            Schema::table('spreadsheet_connections', function (Blueprint $table) {
                if (!Schema::hasColumn('spreadsheet_connections', 'is_yearly_cloning_enabled')) {
                    $table->boolean('is_yearly_cloning_enabled')->default(false)->after('is_enabled')
                        ->comment('Menentukan apakah spreadsheet brand ini wajib di-clone per tahun atau satu tab berkelanjutan');
                }

                if (!Schema::hasColumn('spreadsheet_connections', 'tab_creation_mode')) {
                    $table->string('tab_creation_mode', 50)->default('manual_link')->after('is_yearly_cloning_enabled')
                        ->comment('manual_link (admin link/scan) atau auto_clone (Apps Script clone otomatis)');
                }

                if (!Schema::hasColumn('spreadsheet_connections', 'yearly_tab_template_name')) {
                    $table->string('yearly_tab_template_name', 100)->nullable()->default('TEMPLATE_TAHUN')->after('tab_creation_mode')
                        ->comment('Nama template tab sumber untuk proses kloning tahunan');
                }

                if (!Schema::hasColumn('spreadsheet_connections', 'allow_custom_tab_naming')) {
                    $table->boolean('allow_custom_tab_naming')->default(true)->after('yearly_tab_template_name')
                        ->comment('Mengizinkan penamaan tab kustom/manual selain 4 digit tahun');
                }
            });
        }

        // 2. Tambah penanda pendaftaran terintegrasi pada internship_registrations
        if (Schema::hasTable('internship_registrations')) {
            Schema::table('internship_registrations', function (Blueprint $table) {
                if (!Schema::hasColumn('internship_registrations', 'registration_type')) {
                    $table->string('registration_type', 50)->default('web')->after('internship_status')
                        ->comment('web (pendaftaran website reguler) atau integrated (rekonsiliasi spreadsheet langsung)');
                }

                if (!Schema::hasColumn('internship_registrations', 'is_integrated')) {
                    $table->boolean('is_integrated')->default(false)->after('registration_type')
                        ->comment('True jika data berasal dari baris Google Sheets yang diintegrasikan');
                }

                $table->index('registration_type');
                $table->index('is_integrated');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('internship_registrations')) {
            Schema::table('internship_registrations', function (Blueprint $table) {
                if (Schema::hasColumn('internship_registrations', 'registration_type')) {
                    $table->dropIndex(['registration_type']);
                    $table->dropColumn('registration_type');
                }
                if (Schema::hasColumn('internship_registrations', 'is_integrated')) {
                    $table->dropIndex(['is_integrated']);
                    $table->dropColumn('is_integrated');
                }
            });
        }

        if (Schema::hasTable('spreadsheet_connections')) {
            Schema::table('spreadsheet_connections', function (Blueprint $table) {
                if (Schema::hasColumn('spreadsheet_connections', 'allow_custom_tab_naming')) {
                    $table->dropColumn('allow_custom_tab_naming');
                }
                if (Schema::hasColumn('spreadsheet_connections', 'yearly_tab_template_name')) {
                    $table->dropColumn('yearly_tab_template_name');
                }
                if (Schema::hasColumn('spreadsheet_connections', 'tab_creation_mode')) {
                    $table->dropColumn('tab_creation_mode');
                }
                if (Schema::hasColumn('spreadsheet_connections', 'is_yearly_cloning_enabled')) {
                    $table->dropColumn('is_yearly_cloning_enabled');
                }
            });
        }
    }
};
