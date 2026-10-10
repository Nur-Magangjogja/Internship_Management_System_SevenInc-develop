<?php

namespace App\Http\Controllers\Pemagang;

use App\Http\Controllers\Controller;
use App\Models\InternshipRegistration as IR;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class RegistrationController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Tampilkan form pendaftaran.
     * Jika sudah ada data (termasuk draft), isi form dengan data tersebut.
     */
    public function showForm()
    {
        $user         = auth()->user();
        $registration = IR::where('user_id', $user->id)->latest('id')->first();

        // Ambil divisi aktif dari DB; fallback ke list hardcode jika DB kosong
        $divisions = \App\Models\Division::active()->pluck('name');

        if ($divisions->isEmpty()) {
            $divisions = collect([
                'UI/UX Designer', 'Marketing / Sales', 'Social Media Specialist',
                'Programmer', 'Marcom/Public Relation', 'Tiktok Creator',
                'Desain Grafis', 'Content Writer', 'Host/Presenter',
                'Fotografer', 'Content Planner', 'Voice Over Talent',
                'Videografer', 'Administrasi', 'Las', 'Digital Marketing',
                'Project Manager', 'Human Resource', 'Research & Development',
                'Animasi', 'Customer Service',
            ]);
        }

        // Ambil konfigurasi field dari DB (jika tabel sudah ada)
        $mainFields  = collect();
        $extraFields = collect();
        $useFormFields = false;

        try {
            if (Schema::hasTable('form_fields')) {
                $mainFields  = \App\Models\FormField::active()->whereNull('group_name')->get();
                $extraFields = \App\Models\FormField::active()->where('group_name', 'informasi_tambahan')->get();
                $useFormFields = $mainFields->isNotEmpty();
            }
        } catch (\Throwable $e) {
            // Tabel belum ada → fallback ke form statis
        }

        $cities = \App\Models\City::orderBy('name')->get();
        $institutions = \App\Models\Institution::orderBy('name')->get();
        $faculties = \App\Models\Faculty::orderBy('name')->get();
        $studyPrograms = \App\Models\StudyProgram::orderBy('name')->get();

        return view('pemagang.registration.form', compact(
            'registration', 'divisions', 'mainFields', 'extraFields', 'useFormFields',
            'cities', 'institutions', 'faculties', 'studyPrograms'
        ));
    }

    /**
     * Kirim pendaftaran resmi (is_draft = false, status = waiting).
     */
    public function store(Request $request)
    {
        return $this->saveRegistration($request, isDraft: false);
    }

    /**
     * Simpan sebagai draft (is_draft = true, status tidak berubah).
     */
    public function saveDraft(Request $request)
    {
        return $this->saveRegistration($request, isDraft: true);
    }

    // ──────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────

    private function saveRegistration(Request $request, bool $isDraft): \Illuminate\Http\RedirectResponse
    {
        $user = auth()->user();

        // Aturan validasi — draft boleh isi sebagian, submit wajib semua
        $rules = $isDraft
            ? $this->draftRules()
            : $this->submitRules();

        $validated = $request->validate($rules, [
            'phone_number.regex'   => 'No. HP hanya boleh berisi angka (10-15 digit).',
            'phone_number.required' => 'No. HP wajib diisi.',
            'fullname.required'    => 'Nama lengkap wajib diisi.',
            'student_id.required'  => 'NIM/NPM wajib diisi.',
            'email.required'       => 'Email wajib diisi.',
            'gender.required'      => 'Jenis kelamin wajib dipilih.',
            'birth_place.required' => 'Tempat lahir wajib diisi.',
            'religion.required'    => 'Agama wajib dipilih.',
            'institution_name.required' => 'Nama universitas wajib diisi.',
            'study_program.required'    => 'Program studi wajib diisi.',
            'faculty.required'          => 'Fakultas wajib diisi.',
            'current_city.required'     => 'Kota domisili wajib diisi.',
            'internship_reason.required' => 'Alasan magang wajib diisi.',
            'internship_interest.required' => 'Divisi yang diminati wajib dipilih.',
        ]);

        // Normalisasi tanggal
        foreach (['born_date', 'start_date', 'end_date'] as $field) {
            if (!empty($validated[$field])) {
                try {
                    $validated[$field] = Carbon::parse($validated[$field])->format('Y-m-d');
                } catch (\Throwable) { /* biarkan string asli */ }
            }
        }

        // Checkbox arrays → CSV
        $validated['owned_tools'] = $request->input('owned_tools');
        $infoSourcesArr = $request->input('internship_info_sources', []);

        // Upload file
        foreach (['cv_ktp_portofolio_pdf', 'portofolio_visual', 'profile_photo'] as $fileField) {
            if ($request->hasFile($fileField)) {
                $validated[$fileField] = $this->storeFile($request->file($fileField), 'uploads');
            }
        }

        // Pastikan kolom NOT NULL yang tidak tampil di form selalu punya nilai
        $notNullDefaults = [
            'boarding_info'          => 'Tidak',
            'supervisor_contact'     => '-',
            'social_media_instagram' => '-',
            'current_activities'     => '-',
            'design_software'        => $validated['design_software'] ?? '-',
            'digital_marketing_type' => $validated['digital_marketing_type'] ?? '-',
            'video_software'         => $validated['video_software'] ?? '-',
            'programming_languages'  => $validated['programming_languages'] ?? '-',
            'laptop_equipment'       => $validated['laptop_equipment'] ?? '-',
            'owned_tools'            => $validated['owned_tools'] ?? '-',
            // Kolom NOT NULL yang bisa kosong saat draft
            'gender'                 => $validated['gender'] ?? 'Laki-laki',
            'birth_place'            => $validated['birth_place'] ?? '-',
            'religion'               => $validated['religion'] ?? '-',
            'internship_type'        => $validated['internship_type'] ?? 'Magang Mandiri',
            'internship_arrangement' => $validated['internship_arrangement'] ?? 'Onsite',
            'current_status'         => $validated['current_status'] ?? 'Mahasiswa/Pelajar',
            'english_book_ability'   => $validated['english_book_ability'] ?? 'Saya bisa',
            'internship_reason'      => $validated['internship_reason'] ?? '-',
            'fullname'               => $validated['fullname'] ?? '-',
            'born_date'              => $validated['born_date'] ?? '-',
            'student_id'             => $validated['student_id'] ?? '-',
            'email'                  => $validated['email'] ?? (auth()->user()->email ?? '-'),
            'phone_number'           => $validated['phone_number'] ?? '-',
            'institution_name'       => $validated['institution_name'] ?? '-',
            'study_program'          => $validated['study_program'] ?? '-',
            'faculty'                => $validated['faculty'] ?? '-',
            'current_city'           => $validated['current_city'] ?? '-',
        ];

        foreach ($notNullDefaults as $field => $default) {
            if (empty($validated[$field])) {
                $validated[$field] = $default;
            }
        }

        // Draft atau submit?
        $validated['is_draft']      = $isDraft;
        $validated['draft_saved_at'] = $isDraft ? now() : null;

        if (!$isDraft) {
            $validated['internship_status'] = IR::STATUS_WAITING;
            $validated['user_id']           = $user->id;
        }

        $getRelationId = function($modelClass, $name, $extra = []) {
            if (empty($name) || $name === '-') return null;
            $name = ucwords(strtolower(trim($name)));
            $existing = $modelClass::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
            if ($existing) return $existing->id;
            return $modelClass::create(array_merge(['name' => $name], $extra))->id;
        };

        if (!empty($validated['current_city'])) {
            $validated['city_id'] = $getRelationId(\App\Models\City::class, $validated['current_city']);
            unset($validated['current_city']);
        }
        if (!empty($validated['institution_name'])) {
            $validated['institution_id'] = $getRelationId(\App\Models\Institution::class, $validated['institution_name']);
            unset($validated['institution_name']);
        }
        if (!empty($validated['faculty'])) {
            $extra = isset($validated['institution_id']) ? ['institution_id' => $validated['institution_id']] : [];
            $validated['faculty_id'] = $getRelationId(\App\Models\Faculty::class, $validated['faculty'], $extra);
            unset($validated['faculty']);
        }
        if (!empty($validated['study_program'])) {
            $extra = isset($validated['faculty_id']) ? ['faculty_id' => $validated['faculty_id']] : [];
            $validated['study_program_id'] = $getRelationId(\App\Models\StudyProgram::class, $validated['study_program'], $extra);
            unset($validated['study_program']);
        }

        // Resolusi Divisi yang dipilih
        $divisionInput = $request->input('division_id') ?? $request->input('internship_interest') ?? ($validated['internship_interest'] ?? null);
        unset($validated['internship_interest']);

        if (!empty($divisionInput) && $divisionInput !== '-') {
            $validated['division_id'] = self::resolveDivisionId($divisionInput);
        } elseif (!$isDraft && empty($validated['division_id'])) {
            $validated['division_id'] = \App\Models\Division::where('is_active', true)->first()?->id ?? 1;
        }

        // Upsert — update kalau sudah ada, buat baru kalau belum
        $existing = IR::where('user_id', $user->id)->latest('id')->first();

        if ($existing) {
            // Jangan timpa status kalau submit (status dikelola admin)
            if (!$isDraft) {
                unset($validated['internship_status']);
            }
            $existing->fill($validated)->save();
        } else {
            $validated['user_id'] = $user->id;
            if (!$isDraft) {
                // Ubah role user saat submit resmi
                $user->role = 'pemagang';
                $user->save();
            }
            IR::create($validated);
        }

        // Get the latest registration to sync relations
        $intern = IR::where('user_id', $user->id)->latest('id')->first();

        if ($intern) {
            // Sync Skills
            $intern->skills()->delete();
            $skillCategories = [
                'design' => $validated['design_software'] ?? '',
                'video' => $validated['video_software'] ?? '',
                'programming' => $validated['programming_languages'] ?? '',
                'digital_marketing' => $validated['digital_marketing_type'] ?? ''
            ];
            foreach ($skillCategories as $cat => $val) {
                if (!empty($val) && $val !== '-') {
                    $intern->skills()->create(['skill_category' => $cat, 'skill_name' => $val]);
                }
            }

            // Sync Tools
            $intern->tools()->delete();
            $mergedTools = collect(explode(',', $validated['owned_tools'] ?? ''))
                ->map('trim')->filter(fn($val) => !empty($val) && $val !== '-')->unique();
            foreach ($mergedTools as $t) {
                $intern->tools()->create(['tool_name' => $t]);
            }

            // Sync Info Sources
            $intern->infoSources()->delete();
            if (!empty($infoSourcesArr)) {
                foreach ($infoSourcesArr as $s) {
                    $intern->infoSources()->create(['source_name' => trim($s)]);
                }
            }
        }

        if ($isDraft) {
            return back()->with('success', 'Draft berhasil disimpan.');
        }

        // Kalau data sudah ada sebelumnya (update) vs baru submit
        $isNewSubmission = !$existing;

        if (!$isNewSubmission) {
            return back()->with('success', 'Data pendaftaran berhasil diperbarui.');
        }

        // Logout setelah submit perdana — user harus login ulang untuk cek status
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')
            ->with('registration_success', true)
            ->with('success', '🎉 Pendaftaran berhasil dikirim! Silakan login untuk memantau status magangmu.');
    }

    private function draftRules(): array
    {
        // Draft: semua field opsional
        return [
            'fullname'           => 'nullable|string|max:255',
            'born_date'          => 'nullable|string|max:255',
            'birth_place'        => 'nullable|string|max:100',
            'student_id'         => 'nullable|string|max:50',
            'email'              => 'nullable|string|max:255',
            'gender'             => 'nullable|string|max:50',
            'religion'           => 'nullable|string|max:50',
            'phone_number'       => 'nullable|regex:/^[0-9]{0,15}$/',
            'institution_name'   => 'nullable|string|max:255',
            'study_program'      => 'nullable|string|max:255',
            'faculty'            => 'nullable|string|max:255',
            'current_city'       => 'nullable|string|max:255',
            'internship_reason'  => 'nullable|string',
            'internship_type'    => 'nullable|string|max:50',
            'internship_arrangement' => 'nullable|string|max:50',
            'current_status'     => 'nullable|string|max:50',
            'english_book_ability' => 'nullable|string|max:50',
            'internship_interest'  => 'nullable|string|max:255',
            'start_date'         => 'nullable|string|max:255',
            'end_date'           => 'nullable|string|max:255',
            'cv_ktp_portofolio_pdf' => 'nullable|file|mimes:pdf|max:10240',
            'portofolio_visual'  => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'profile_photo'      => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            // fields lainnya opsional
            'design_software'    => 'nullable|string|max:255',
            'video_software'     => 'nullable|string|max:255',
            'programming_languages' => 'nullable|string|max:255',
            'digital_marketing_type' => 'nullable|string|max:255',
            'owned_tools'        => 'nullable|string|max:255',
            'supervisor_name'    => 'nullable|string|max:255',
            'supervisor_contact' => 'nullable|regex:/^[0-9]{0,15}$/',
            'current_activities' => 'nullable|string',
            'boarding_info'      => 'nullable|string|max:50',
            'social_media_instagram' => 'nullable|string|max:255',
            'internship_info_sources' => 'nullable|array',
            'internship_info_sources.*' => 'nullable|string|max:100',
        ];
    }

    private function submitRules(): array
    {
        // Submit: hanya field terkait Data Diri, Data Mahasiswa, dan Data Magang yang wajib diisi.
        // Berkas (surat pengantar, CV/portofolio, foto), keahlian, kemampuan bahasa inggris, dll bersifat opsional.
        return array_merge($this->draftRules(), [
            // Data Diri
            'fullname'           => 'required|string|max:255',
            'born_date'          => 'required|string|max:255',
            'birth_place'        => 'required|string|max:100',
            'gender'             => 'required|string|max:50',
            'religion'           => 'required|string|max:50',
            'current_city'       => 'required|string|max:255',
            'email'              => 'required|string|max:255',
            'phone_number'       => 'required|regex:/^[0-9]{10,15}$/',

            // Data Mahasiswa
            'current_status'     => 'required|string|max:50',
            'student_id'         => 'required|string|max:50',
            'institution_name'   => 'required|string|max:255',
            'faculty'            => 'required|string|max:255',
            'study_program'      => 'required|string|max:255',

            // Data Magang
            'internship_interest'    => 'required|string|max:255',
            'internship_type'        => 'required|string|max:50',
            'internship_arrangement' => 'nullable|string|max:50',
            'internship_reason'      => 'required|string',
        ]);
    }

    private function arrayToCsv(mixed $input): ?string
    {
        if (!is_array($input)) return is_string($input) && $input !== '' ? $input : null;
        $vals = array_filter(array_map('trim', $input), fn($v) => $v !== '');
        return empty($vals) ? null : implode(', ', array_values($vals));
    }

    private function storeFile(\Illuminate\Http\UploadedFile $file, string $dir): string
    {
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $ext      = strtolower($file->getClientOriginalExtension());
        // Gunakan slug dengan underscore, hindari karakter spesial termasuk tanda kurung
        $safe     = Str::slug($original, '_');
        $i = 0;

        do {
            $name = $i === 0 ? "{$safe}.{$ext}" : "{$safe}_{$i}.{$ext}";
            $path = "{$dir}/{$name}";
            $i++;
        } while (Storage::disk('public')->exists($path));

        return $file->storeAs($dir, $name, 'public');
    }

    /**
     * Resolusi ID Divisi dengan dukungan ID numerik, nama persis, kamus sinonim, dan flexible matching.
     */
    public static function resolveDivisionId(mixed $input): ?int
    {
        if (empty($input) || $input === '-') return null;

        // 1. Jika ID numerik dan ada di database
        if (is_numeric($input)) {
            $div = \App\Models\Division::find((int) $input);
            if ($div) return $div->id;
        }

        $rawName = trim((string) $input);
        $clean   = strtolower($rawName);

        // 2. Cek kecocokan nama langsung (case-insensitive)
        $div = \App\Models\Division::whereRaw('LOWER(name) = ?', [$clean])->first();
        if ($div) return $div->id;

        // 3. Kamus sinonim & variasi nama
        $synonyms = [
            'programmer (front end / backend)'  => 'Programmer',
            'programmer (frontend / backend)'   => 'Programmer',
            'web programmer'                    => 'Programmer',
            'developer'                         => 'Programmer',
            'frontend'                          => 'Programmer',
            'backend'                           => 'Programmer',
            'administration'                    => 'Administrasi',
            'admin'                             => 'Administrasi',
            'adm'                               => 'Administrasi',
            'human resources (hr)'              => 'Human Resource',
            'human resources'                   => 'Human Resource',
            'hrd'                               => 'Human Resource',
            'hr'                                => 'Human Resource',
            'ui/ux'                             => 'UI/UX Designer',
            'uiux'                              => 'UI/UX Designer',
            'ui-ux'                             => 'UI/UX Designer',
            'photographer'                      => 'Fotografer',
            'fotografi'                         => 'Fotografer',
            'videographer'                      => 'Videografer',
            'videografi'                        => 'Videografer',
            'graphic designer (konten kreatif)' => 'Desain Grafis',
            'graphic designer'                  => 'Desain Grafis',
            'desainer grafis'                   => 'Desain Grafis',
            'social media'                      => 'Social Media Specialist',
            'smm'                               => 'Social Media Specialist',
            'content writter'                   => 'Content Writer',
            'penulis konten'                    => 'Content Writer',
            'sales & marketing'                 => 'Marketing / Sales',
            'sales and marketing'               => 'Marketing / Sales',
            'marketing'                         => 'Marketing / Sales',
            'sales'                             => 'Marketing / Sales',
            'public relations (marcomm)'        => 'Marcom/Public Relation',
            'public relations'                  => 'Marcom/Public Relation',
            'marcomm'                           => 'Marcom/Public Relation',
            'marcom'                            => 'Marcom/Public Relation',
            'pr'                                => 'Marcom/Public Relation',
            'tiktok'                            => 'Tiktok Creator',
            'kreator tiktok'                    => 'Tiktok Creator',
            'pengelasan'                        => 'Las',
            'welder'                            => 'Las',
            'welding'                           => 'Las',
            'animation'                         => 'Animasi',
            'animator'                          => 'Animasi',
            'cs'                                => 'Customer Service',
            'layanan pelanggan'                 => 'Customer Service',
            'host'                              => 'Host/Presenter',
            'presenter'                         => 'Host/Presenter',
            'voice over'                        => 'Voice Over Talent',
            'pengisi suara'                     => 'Voice Over Talent',
            'rnd'                               => 'Research & Development',
            'r&d'                               => 'Research & Development',
        ];

        if (isset($synonyms[$clean])) {
            $mapped = \App\Models\Division::where('name', $synonyms[$clean])->first();
            if ($mapped) return $mapped->id;
        }

        // 4. Flexible LIKE search (dua arah)
        $flexible = \App\Models\Division::where(function ($q) use ($clean) {
            $q->whereRaw('LOWER(name) LIKE ?', ["%{$clean}%"])
              ->orWhereRaw('? LIKE CONCAT("%", LOWER(name), "%")', [$clean]);
        })->first();

        if ($flexible) return $flexible->id;

        // 5. Jika benar-benar baru, buat record divisi baru yang aktif
        $newDiv = \App\Models\Division::create([
            'name'      => ucwords($clean),
            'slug'      => \Illuminate\Support\Str::slug($clean, '_'),
            'is_active' => true,
        ]);

        return $newDiv->id;
    }
}
