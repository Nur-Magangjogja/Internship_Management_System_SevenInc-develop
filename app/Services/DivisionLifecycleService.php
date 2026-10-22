<?php

namespace App\Services;

use App\Models\Division;
use App\Models\FormField;
use App\Models\FormFieldRegistry;
use App\Models\FormSchema;
use App\Models\InternshipRegistration;
use App\Models\RegistrationCustomAnswer;
use App\Models\RegistrationFormSnapshot;
use App\Models\SpreadsheetColumnMapping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DivisionLifecycleService
{
    /**
     * Periksa secara menyeluruh apakah suatu divisi layak dihapus permanen atau harus dinonaktifkan/diarsipkan.
     *
     * @param Division $division
     * @return array{can_delete: bool, reason: string|null, dependencies: array}
     */
    public function canDeleteDivision(Division $division): array
    {
        $dependencies = [];

        // 1. Periksa relasi registrasi pemagang langsung
        try {
            if (Schema::hasTable('internship_registrations')) {
                $regCount = InternshipRegistration::where('division_id', $division->id)->count();
                if ($regCount > 0) {
                    $dependencies[] = "Digunakan oleh {$regCount} data pendaftaran pemagang.";
                }

                // Cek kolom legacy internship_interest
                if (Schema::hasColumn('internship_registrations', 'internship_interest')) {
                    $legacyInterestCount = InternshipRegistration::where('internship_interest', $division->name)
                        ->orWhere('internship_interest', $division->slug)
                        ->count();
                    if ($legacyInterestCount > 0) {
                        $dependencies[] = "Digunakan pada peminatan historis pemagang ({$legacyInterestCount} baris).";
                    }
                }
            }
        } catch (\Throwable) {}

        // 2. Periksa akun user yang terhubung ke divisi ini
        try {
            if (Schema::hasTable('users') && Schema::hasColumn('users', 'division_id')) {
                $userCount = User::where('division_id', $division->id)->count();
                if ($userCount > 0) {
                    $dependencies[] = "Digunakan oleh {$userCount} akun pengguna sistem.";
                }
            }
        } catch (\Throwable) {}

        // 3. Periksa snapshot formulir pendaftaran historis (frozen JSON snapshots)
        try {
            if (Schema::hasTable('registration_form_snapshots')) {
                $snapshotCount = RegistrationFormSnapshot::where(function ($q) use ($division) {
                    $q->where('schema_snapshot', 'LIKE', '%"' . $division->name . '"%')
                      ->orWhere('schema_snapshot', 'LIKE', '%"' . $division->slug . '"%')
                      ->orWhere('schema_snapshot', 'LIKE', '%"division_id":' . $division->id . '%');
                })->count();

                if ($snapshotCount > 0) {
                    $dependencies[] = "Terekam dalam {$snapshotCount} snapshot formulir pendaftaran historis (immutable snapshot).";
                }
            }
        } catch (\Throwable) {}

        // 4. Periksa opsi pada Form Builder (seluruh versi: draft, published, archived)
        try {
            if (Schema::hasTable('form_fields')) {
                $fieldOptionCount = FormField::where(function ($q) use ($division) {
                    $q->where('options', 'LIKE', '%"' . $division->name . '"%')
                      ->orWhere('options', 'LIKE', '%"' . $division->slug . '"%');
                })->count();

                if ($fieldOptionCount > 0) {
                    $dependencies[] = "Tercatat sebagai opsi pilihan dalam {$fieldOptionCount} pertanyaan formulir pendaftaran.";
                }
            }

            if (Schema::hasTable('form_field_registries')) {
                $registryCount = FormFieldRegistry::where(function ($q) use ($division) {
                    $q->where('allowed_options', 'LIKE', '%"' . $division->name . '"%')
                      ->orWhere('allowed_options', 'LIKE', '%"' . $division->slug . '"%');
                })->count();

                if ($registryCount > 0) {
                    $dependencies[] = "Terkunci dalam registry identitas formulir ({$registryCount} entri).";
                }
            }
        } catch (\Throwable) {}

        // 5. Periksa jawaban pendaftaran custom (RegistrationCustomAnswer)
        try {
            if (Schema::hasTable('registration_custom_answers')) {
                $answerCount = RegistrationCustomAnswer::where(function ($q) use ($division) {
                    $q->where('answer_value', $division->name)
                      ->orWhere('answer_value', $division->slug)
                      ->orWhere('answer_value', (string) $division->id);
                })->count();

                if ($answerCount > 0) {
                    $dependencies[] = "Terdapat dalam {$answerCount} jawaban pendaftaran pemagang.";
                }
            }
        } catch (\Throwable) {}

        // 6. Periksa pemetaan kolom spreadsheet & integrasi
        try {
            if (Schema::hasTable('spreadsheet_column_mappings')) {
                $mappingCount = SpreadsheetColumnMapping::where(function ($q) use ($division) {
                    $q->where('target_field', 'LIKE', '%' . $division->slug . '%')
                      ->orWhere('sheet_header', 'LIKE', '%' . $division->name . '%');
                })->count();

                if ($mappingCount > 0) {
                    $dependencies[] = "Terkait dengan {$mappingCount} pemetaan kolom spreadsheet aktif.";
                }
            }
        } catch (\Throwable) {}

        // 7. Periksa penilaian pemagang (InternAssessment)
        try {
            if (Schema::hasTable('intern_assessments') && Schema::hasTable('internship_registrations')) {
                $assessmentCount = DB::table('intern_assessments')
                    ->join('internship_registrations', 'intern_assessments.intern_id', '=', 'internship_registrations.id')
                    ->where('internship_registrations.division_id', $division->id)
                    ->count();

                if ($assessmentCount > 0) {
                    $dependencies[] = "Terkait dengan {$assessmentCount} data penilaian pemagang.";
                }
            }
        } catch (\Throwable) {}

        $canDelete = empty($dependencies);
        $reason = $canDelete
            ? null
            : "Divisi '{$division->name}' tidak dapat dihapus permanen karena: " . implode(' ', $dependencies);

        return [
            'can_delete'   => $canDelete,
            'reason'       => $reason,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * Hapus divisi jika unused, atau otomatis nonaktifkan/arsipkan jika terdapat dependensi historis.
     *
     * @param Division $division
     * @param User|null $actor
     * @return array{action: string, is_active: bool, message: string}
     */
    public function deleteOrDeactivate(Division $division, ?User $actor = null): array
    {
        $name = $division->name;
        $check = $this->canDeleteDivision($division);

        if (!$check['can_delete']) {
            // Transisi aman ke nonaktif / arsip tanpa memutus relasi historis
            $division->update(['is_active' => false]);

            $message = "Divisi \"{$name}\" memiliki riwayat data terkait sehingga tidak dapat dihapus permanen. Divisi telah dinonaktifkan/diarsipkan secara aman.";

            return [
                'action'    => 'deactivated',
                'is_active' => false,
                'message'   => $message,
                'reason'    => $check['reason'],
            ];
        }

        // Jika benar-benar bersih dan belum pernah digunakan, boleh dihapus fisik
        $division->delete();

        return [
            'action'    => 'deleted',
            'is_active' => false,
            'message'   => "Divisi \"{$name}\" berhasil dihapus permanen.",
            'reason'    => null,
        ];
    }

    /**
     * Dapatkan daftar divisi yang valid untuk pendaftaran baru (hanya divisi aktif).
     */
    public function getActiveDivisionsForRegistration(): \Illuminate\Support\Collection
    {
        return Division::active()->get();
    }
}
