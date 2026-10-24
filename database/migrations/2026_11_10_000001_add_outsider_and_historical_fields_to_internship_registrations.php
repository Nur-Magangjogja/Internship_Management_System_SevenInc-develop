<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('internship_registrations', function (Blueprint $table) {
            // 1. Tambah kolom email mandiri secara nullable terlebih dahulu
            if (!Schema::hasColumn('internship_registrations', 'email')) {
                $table->string('email', 150)->nullable()->after('user_id')->index();
            }

            // 2. Tambah kolom raw text untuk data akademik historis yang belum terpetakan ke master
            if (!Schema::hasColumn('internship_registrations', 'raw_institution_name')) {
                $table->string('raw_institution_name', 255)->nullable()->after('institution_id');
            }
            if (!Schema::hasColumn('internship_registrations', 'raw_faculty_name')) {
                $table->string('raw_faculty_name', 255)->nullable()->after('faculty_id');
            }
            if (!Schema::hasColumn('internship_registrations', 'raw_study_program_name')) {
                $table->string('raw_study_program_name', 255)->nullable()->after('study_program_id');
            }

            // 3. Tambah kolom identitas outsider, provenance, dan batch id
            if (!Schema::hasColumn('internship_registrations', 'is_outsider')) {
                $table->boolean('is_outsider')->default(false)->after('internship_status')->index();
            }
            if (!Schema::hasColumn('internship_registrations', 'registration_provenance')) {
                $table->string('registration_provenance', 50)->default('direct_web')->after('is_outsider');
            }
            if (!Schema::hasColumn('internship_registrations', 'historical_import_batch_id')) {
                $table->foreignId('historical_import_batch_id')->nullable()->after('registration_provenance')
                    ->constrained('historical_import_batches')->nullOnDelete();
            }
            if (!Schema::hasColumn('internship_registrations', 'event_fingerprint')) {
                $table->string('event_fingerprint', 64)->nullable()->after('historical_import_batch_id')->index();
            }
        });

        // 4. Backfill email dari tabel users untuk registrasi reguler yang sudah ada
        try {
            DB::statement("
                UPDATE internship_registrations ir
                INNER JOIN users u ON ir.user_id = u.id
                SET ir.email = u.email
                WHERE ir.email IS NULL AND u.email IS NOT NULL
            ");
        } catch (\Throwable $e) {
            // Lewati jika testing environment in-memory tanpa JOIN UPDATE
        }

        // 5. Ubah foreign key user_id dan relaksasi FK akademik menjadi nullable secara aman
        Schema::table('internship_registrations', function (Blueprint $table) {
            // Drop cascade foreign key on user_id and re-add as nullOnDelete
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable) {
                // Ignore if constraint name differs or already dropped
            }

            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // Relaksasi foreign key akademik & operasional agar data historis tidak menghasilkan fake DB records
            $table->unsignedBigInteger('institution_id')->nullable()->change();
            $table->unsignedBigInteger('faculty_id')->nullable()->change();
            $table->unsignedBigInteger('study_program_id')->nullable()->change();
            $table->unsignedBigInteger('city_id')->nullable()->change();
            $table->unsignedBigInteger('division_id')->nullable()->change();
            $table->date('born_date')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internship_registrations', function (Blueprint $table) {
            $table->dropColumn([
                'email',
                'raw_institution_name',
                'raw_faculty_name',
                'raw_study_program_name',
                'is_outsider',
                'registration_provenance',
                'historical_import_batch_id',
                'event_fingerprint',
            ]);
        });
    }
};
