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
        // 1. Konsolidasi data duplikat pada tabel faculties sebelum menambahkan constraint unique
        $duplicateFacultyGroups = DB::table('faculties')
            ->select('institution_id', 'name')
            ->groupBy('institution_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateFacultyGroups as $group) {
            $facultyRecords = DB::table('faculties')
                ->where('institution_id', $group->institution_id)
                ->where('name', $group->name)
                ->orderBy('id', 'asc')
                ->get();

            $canonicalFaculty = $facultyRecords->first();
            $duplicateFaculties = $facultyRecords->slice(1);

            foreach ($duplicateFaculties as $dupFaculty) {
                // Re-point pendaftaran magang ke fakultas kanonikal
                DB::table('internship_registrations')
                    ->where('faculty_id', $dupFaculty->id)
                    ->update(['faculty_id' => $canonicalFaculty->id]);

                // Periksa study programs di bawah fakultas duplikat
                $dupStudyPrograms = DB::table('study_programs')
                    ->where('faculty_id', $dupFaculty->id)
                    ->get();

                foreach ($dupStudyPrograms as $dupSp) {
                    $existingCanonicalSp = DB::table('study_programs')
                        ->where('faculty_id', $canonicalFaculty->id)
                        ->where('name', $dupSp->name)
                        ->first();

                    if ($existingCanonicalSp) {
                        // Jika prodi dengan nama sama sudah ada di fakultas kanonikal, re-point registrasi lalu hapus prodi duplikat
                        DB::table('internship_registrations')
                            ->where('study_program_id', $dupSp->id)
                            ->update(['study_program_id' => $existingCanonicalSp->id]);

                        DB::table('study_programs')->where('id', $dupSp->id)->delete();
                    } else {
                        // Jika belum ada, pindahkan study program ke fakultas kanonikal
                        DB::table('study_programs')
                            ->where('id', $dupSp->id)
                            ->update(['faculty_id' => $canonicalFaculty->id]);
                    }
                }

                // Hapus rekor fakultas duplikat
                DB::table('faculties')->where('id', $dupFaculty->id)->delete();
            }
        }

        // 2. Konsolidasi data duplikat pada tabel study_programs sebelum menambahkan constraint unique
        $duplicateSpGroups = DB::table('study_programs')
            ->select('faculty_id', 'name')
            ->groupBy('faculty_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateSpGroups as $group) {
            $spRecords = DB::table('study_programs')
                ->where('faculty_id', $group->faculty_id)
                ->where('name', $group->name)
                ->orderBy('id', 'asc')
                ->get();

            $canonicalSp = $spRecords->first();
            $duplicateSps = $spRecords->slice(1);

            foreach ($duplicateSps as $dupSp) {
                DB::table('internship_registrations')
                    ->where('study_program_id', $dupSp->id)
                    ->update(['study_program_id' => $canonicalSp->id]);

                DB::table('study_programs')->where('id', $dupSp->id)->delete();
            }
        }

        // 3. Bersihkan integritas referensial pada mous.superseded_by_id sebelum menambahkan foreign key
        if (Schema::hasColumn('mous', 'superseded_by_id')) {
            $validMouIds = DB::table('mous')->pluck('id')->toArray();
            if (!empty($validMouIds)) {
                DB::table('mous')
                    ->whereNotNull('superseded_by_id')
                    ->whereNotIn('superseded_by_id', $validMouIds)
                    ->update(['superseded_by_id' => null]);
            } else {
                DB::table('mous')->whereNotNull('superseded_by_id')->update(['superseded_by_id' => null]);
            }
        }

        // 4. Tambahkan Unique Constraint pada faculties (scoped ke institution_id)
        $hasFacultyUnique = collect(DB::select("SHOW INDEX FROM faculties WHERE Key_name = 'faculties_institution_name_unique'"))->isNotEmpty();
        if (!$hasFacultyUnique) {
            Schema::table('faculties', function (Blueprint $table) {
                $table->unique(['institution_id', 'name'], 'faculties_institution_name_unique');
            });
        }

        // 5. Tambahkan Unique Constraint pada study_programs (scoped ke faculty_id)
        $hasSpUnique = collect(DB::select("SHOW INDEX FROM study_programs WHERE Key_name = 'study_programs_faculty_name_unique'"))->isNotEmpty();
        if (!$hasSpUnique) {
            Schema::table('study_programs', function (Blueprint $table) {
                $table->unique(['faculty_id', 'name'], 'study_programs_faculty_name_unique');
            });
        }

        // 6. Tambahkan Foreign Key formal pada mous.superseded_by_id
        $hasMouFk = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mous' AND CONSTRAINT_NAME = 'mous_superseded_by_id_foreign'"))->isNotEmpty();
        if (!$hasMouFk) {
            Schema::table('mous', function (Blueprint $table) {
                $table->foreign('superseded_by_id', 'mous_superseded_by_id_foreign')
                    ->references('id')
                    ->on('mous')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasMouFk = collect(DB::select("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mous' AND CONSTRAINT_NAME = 'mous_superseded_by_id_foreign'"))->isNotEmpty();
        if ($hasMouFk) {
            Schema::table('mous', function (Blueprint $table) {
                $table->dropForeign('mous_superseded_by_id_foreign');
            });
        }

        $hasSpUnique = collect(DB::select("SHOW INDEX FROM study_programs WHERE Key_name = 'study_programs_faculty_name_unique'"))->isNotEmpty();
        if ($hasSpUnique) {
            Schema::table('study_programs', function (Blueprint $table) {
                $hasSpIndex = collect(DB::select("SHOW INDEX FROM study_programs WHERE Key_name = 'study_programs_faculty_id_foreign'"))->isNotEmpty();
                if (!$hasSpIndex) {
                    $table->index('faculty_id', 'study_programs_faculty_id_foreign');
                }
                $table->dropUnique('study_programs_faculty_name_unique');
            });
        }

        $hasFacultyUnique = collect(DB::select("SHOW INDEX FROM faculties WHERE Key_name = 'faculties_institution_name_unique'"))->isNotEmpty();
        if ($hasFacultyUnique) {
            Schema::table('faculties', function (Blueprint $table) {
                $hasFacIndex = collect(DB::select("SHOW INDEX FROM faculties WHERE Key_name = 'faculties_institution_id_foreign'"))->isNotEmpty();
                if (!$hasFacIndex) {
                    $table->index('institution_id', 'faculties_institution_id_foreign');
                }
                $table->dropUnique('faculties_institution_name_unique');
            });
        }
    }
};
