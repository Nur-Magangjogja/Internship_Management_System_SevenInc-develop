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
        $lifecycle    = app(\App\Services\RegistrationLifecycleService::class);
        $registration = $lifecycle->getOpenOrDraftRegistration($user);

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

        $cities = \App\Models\City::select('id', 'name')->orderBy('name')->get();
        $institutions = \App\Models\Institution::select('id', 'name')
            ->where('is_active', true)
            ->when($registration && $registration->institution_id, function ($q) use ($registration) {
                $q->orWhere('id', $registration->institution_id);
            })
            ->orderBy('name')
            ->get();
        $faculties = \App\Models\Faculty::select('id', 'name')->orderBy('name')->get();
        $studyPrograms = \App\Models\StudyProgram::select('id', 'name')->orderBy('name')->get();

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
            'phone_number.regex'               => 'No. HP hanya boleh berisi angka (10-15 digit).',
            'phone_number.required'            => 'No. HP wajib diisi.',
            'fullname.required'                => 'Nama lengkap wajib diisi.',
            'student_id.required'              => 'NIM/NPM wajib diisi.',
            'email.required'                   => 'Email wajib diisi.',
            'gender.required'                  => 'Jenis kelamin wajib dipilih.',
            'birth_place.required'             => 'Tempat lahir wajib diisi.',
            'religion.required'                => 'Agama wajib dipilih.',
            'institution_name.required'        => 'Nama universitas/sekolah wajib diisi.',
            'study_program.required'           => 'Program studi wajib diisi.',
            'faculty.required'                 => 'Fakultas wajib diisi.',
            'current_city.required'            => 'Kota domisili wajib diisi.',
            'internship_reason.required'       => 'Alasan magang wajib diisi.',
            'internship_interest.required'     => 'Divisi yang diminati wajib dipilih.',
            'wfo_readiness.required'           => 'Kesiapan WFO Yogyakarta wajib dipilih.',
            'schedule_shift_readiness.required' => 'Kesanggupan jadwal & shifting wajib dipilih.',
            'has_laptop.required'              => 'Kepemilikan laptop wajib dipilih.',
            'planned_duration.required'        => 'Durasi magang wajib dipilih/diisi.',
            'cv_ktp_portofolio_pdf.mimes'      => 'Berkas lampiran harus berformat PDF.',
            'cv_ktp_portofolio_pdf.max'        => 'Ukuran berkas lampiran maksimal 10MB.',
        ]);

        // Normalisasi tanggal
        foreach (['born_date', 'start_date', 'end_date'] as $field) {
            if (!empty($validated[$field])) {
                try {
                    $validated[$field] = Carbon::parse($validated[$field])->format('Y-m-d');
                } catch (\Throwable) { /* biarkan string asli */ }
            } else {
                $validated[$field] = null;
            }
        }

        // Normalisasi boolean has_laptop
        if (isset($validated['has_laptop']) && $validated['has_laptop'] !== null && $validated['has_laptop'] !== '') {
            $validated['has_laptop'] = filter_var($validated['has_laptop'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
            $validated['has_laptop'] = null;
        }

        // Checkbox arrays → CSV
        $validated['owned_tools'] = $request->input('owned_tools');
        $infoSourcesArr = $request->input('internship_info_sources', []);

        // Draft atau submit? Unanswered fields in draft remain null (No fake defaults)
        if ($isDraft) {
            $validated['internship_status'] = IR::STATUS_NEW;
        } else {
            $validated['internship_status'] = IR::STATUS_WAITING;
            $validated['user_id']           = $user->id;
        }
        unset($validated['is_draft'], $validated['draft_saved_at']);

        $getRelationId = function($modelClass, $name, $extra = []) {
            if (empty($name) || $name === '-') return null;
            $name = ucwords(strtolower(trim($name)));
            $query = $modelClass::whereRaw('LOWER(name) = ?', [strtolower($name)]);
            foreach ($extra as $col => $val) {
                if ($val !== null) {
                    $query->where($col, $val);
                }
            }
            $existing = $query->first();
            if ($existing) return $existing->id;
            return $modelClass::create(array_merge(['name' => $name], $extra))->id;
        };

        if (!empty($validated['current_city'])) {
            $validated['city_id'] = $getRelationId(\App\Models\City::class, $validated['current_city']);
            unset($validated['current_city']);
        }

        if (!empty($validated['institution_name'])) {
            $inputName = trim($validated['institution_name']);
            $normName  = \App\Models\Institution::normalizeName($inputName);

            $existingInst = \App\Models\Institution::whereRaw('LOWER(name) = ?', [strtolower($inputName)])
                ->orWhere('normalized_name', $normName)
                ->first();

            $currentReg = IR::where('user_id', $user->id)->latest('id')->first();
            if ($existingInst && !$existingInst->is_active && (!$currentReg || $currentReg->institution_id !== $existingInst->id)) {
                return back()->withInput()->withErrors([
                    'institution_name' => "Institusi {$existingInst->name} saat ini sedang tidak aktif menerima pendaftaran baru."
                ]);
            }

            if ($existingInst) {
                $validated['institution_id'] = $existingInst->id;
            } else {
                $validated['institution_id'] = \App\Models\Institution::create([
                    'name'            => ucwords(strtolower($inputName)),
                    'normalized_name' => $normName,
                    'is_active'       => true,
                ])->id;
            }
            unset($validated['institution_name']);
        }

        // Resolusi Fakultas & Program Studi:
        // faculty_id diturunkan dari study_program dan mempertahankan hirarki S4
        if (!empty($validated['faculty'])) {
            $extra = isset($validated['institution_id']) ? ['institution_id' => $validated['institution_id']] : [];
            $validated['faculty_id'] = $getRelationId(\App\Models\Faculty::class, $validated['faculty'], $extra);
            unset($validated['faculty']);
        }

        if (!empty($validated['study_program'])) {
            $progName = trim($validated['study_program']);
            $instId   = $validated['institution_id'] ?? null;

            // Cari StudyProgram yang memiliki faculty di institution terkait
            $scopedProg = \App\Models\StudyProgram::whereRaw('LOWER(name) = ?', [strtolower($progName)])
                ->when($instId, function ($q) use ($instId) {
                    $q->whereHas('faculty', fn($fq) => $fq->where('institution_id', $instId));
                })
                ->when(isset($validated['faculty_id']), function ($q) use ($validated) {
                    $q->where('faculty_id', $validated['faculty_id']);
                })
                ->first();

            if ($scopedProg) {
                $validated['study_program_id'] = $scopedProg->id;
                $validated['faculty_id']       = $scopedProg->faculty_id;
            } else {
                // Jika faculty_id belum ada, cari faculty yang ada di institution tersebut
                if (!isset($validated['faculty_id']) && $instId) {
                    $defaultFac = \App\Models\Faculty::where('institution_id', $instId)->first();
                    if (!$defaultFac) {
                        $defaultFac = \App\Models\Faculty::create([
                            'institution_id' => $instId,
                            'name'           => 'Fakultas Umum',
                        ]);
                    }
                    $validated['faculty_id'] = $defaultFac->id;
                }

                $extra = isset($validated['faculty_id']) ? ['faculty_id' => $validated['faculty_id']] : [];
                $validated['study_program_id'] = $getRelationId(\App\Models\StudyProgram::class, $progName, $extra);
            }
            unset($validated['study_program']);
        }

        // Resolusi Divisi yang dipilih
        $divisionInput = $request->input('division_id') ?? $request->input('internship_interest') ?? ($validated['internship_interest'] ?? null);
        unset($validated['internship_interest']);

        if (!empty($divisionInput) && $divisionInput !== '-') {
            $divisionId = self::resolveDivisionId($divisionInput);
            if ($divisionId) {
                $validated['division_id'] = $divisionId;
            } else {
                if ($isDraft) {
                    $validated['division_id'] = null;
                } else {
                    return back()->withInput()->withErrors([
                        'internship_interest' => 'Divisi yang dipilih tidak valid atau tidak tersedia.'
                    ]);
                }
            }
        } elseif (!$isDraft && empty($validated['division_id'])) {
            $validated['division_id'] = \App\Models\Division::where('is_active', true)->first()?->id ?? 1;
        }

        // Multi-period aware upsert:
        // Update hanya jika ada pendaftaran yang masih terbuka (draft / open).
        // Jika periode sebelumnya sudah selesai/tertutup, buat record pendaftaran baru untuk periode baru.
        $lifecycle = app(\App\Services\RegistrationLifecycleService::class);
        $existing  = $lifecycle->getOpenOrDraftRegistration($user);

        if ($existing) {
            // Jangan timpa status jika submit resmi pada pendaftaran yang sudah berjalan
            if (!$isDraft && !empty($existing->internship_status)) {
                if ($existing->is_draft) {
                    $validated['internship_status'] = IR::STATUS_WAITING;
                } else {
                    unset($validated['internship_status']);
                }
            }
            $existing->fill($validated)->save();
            $intern = $existing;
        } else {
            // Pengguna baru atau alumni yang mendaftar periode baru
            $validated['user_id'] = $user->id;
            if (!$isDraft) {
                $validated['internship_status'] = IR::STATUS_WAITING;
                if ($user->role !== 'pemagang') {
                    $user->role = 'pemagang';
                    $user->save();
                }
            }
            $intern = IR::create($validated);
        }

        if ($intern) {
            // Local attachment persistence (Rule 6 & 7: PDF max 10MB divalidasi dan tersimpan di DB)
            if ($request->hasFile('cv_ktp_portofolio_pdf')) {
                $file = $request->file('cv_ktp_portofolio_pdf');
                $path = $this->storeFile($file, 'uploads');
                $intern->attachments()->create([
                    'attachment_type' => 'cv_ktp_portofolio',
                    'file_path'       => $path,
                    'original_name'   => $file->getClientOriginalName(),
                    'file_size'       => $file->getSize(),
                    'mime_type'       => $file->getMimeType() ?: 'application/pdf',
                ]);
            }

            if ($request->hasFile('profile_photo')) {
                $file = $request->file('profile_photo');
                $path = $this->storeFile($file, 'uploads');
                $intern->profile_photo = $path;
                $intern->save();
                $intern->attachments()->create([
                    'attachment_type' => 'profile_photo',
                    'file_path'       => $path,
                    'original_name'   => $file->getClientOriginalName(),
                    'file_size'       => $file->getSize(),
                    'mime_type'       => $file->getMimeType() ?: 'image/jpeg',
                ]);
            }

            // Sync Skills (Preserve AS-IS per Rule 9)
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

            // Auto-link pembimbing kanonikal jika memenuhi Guardrail 1 (exact unique verified phone & name).
            // Applicant TIDAK PERNAH membuat baris baru di institution_contacts.
            \App\Services\ContactMatchingService::attemptAutoLink($intern);

            // Outbound audit & sync for submitted registration
            if (!$isDraft) {
                \App\Services\ActivityLogger::log(
                    action: 'intern_registration_submitted',
                    description: "Pendaftaran magang berhasil dikirim oleh {$user->name}.",
                    subject: $intern,
                    metadata: [
                        'registration_id'   => $intern->id,
                        'institution_name'  => $intern->institution_name,
                        'division_id'       => $intern->division_id,
                        'internship_status' => $intern->internship_status,
                    ]
                );

                try {
                    app(\App\Services\SpreadsheetSyncEngineService::class)->queueSyncForRegistration($intern);
                } catch (\Throwable) {
                    // Outbound sync queue failure must never abort registration submission
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
            ->with('success', 'Pendaftaran berhasil dikirim. Silakan masuk untuk memantau status magang Anda.');
    }

    private function draftRules(): array
    {
        // Draft: semua field opsional tanpa default palsu
        return [
            'fullname'                 => 'nullable|string|max:150',
            'born_date'                => 'nullable|string|max:255',
            'birth_place'              => 'nullable|string|max:100',
            'student_id'               => 'nullable|string|max:50',
            'email'                    => 'nullable|string|max:255',
            'gender'                   => 'nullable|string|max:50',
            'religion'                 => 'nullable|string|max:50',
            'phone_number'             => 'nullable|regex:/^[0-9]{0,15}$/',
            'institution_name'         => 'nullable|string|max:255',
            'study_program'            => 'nullable|string|max:255',
            'faculty'                  => 'nullable|string|max:255',
            'current_city'             => 'nullable|string|max:255',
            'internship_reason'        => 'nullable|string',
            'internship_type'          => 'nullable|string|max:50',
            'internship_arrangement'   => 'nullable|string|max:50',
            'current_status'           => 'nullable|string|max:100',
            'wfo_readiness'            => 'nullable|string|max:100',
            'schedule_shift_readiness' => 'nullable|string|max:100',
            'has_laptop'               => 'nullable|in:0,1,true,false',
            'planned_duration'         => 'nullable|string|max:50',
            'english_book_ability'     => 'nullable|string|max:50',
            'internship_interest'      => 'nullable|string|max:255',
            'start_date'               => 'nullable|string|max:255',
            'end_date'                 => 'nullable|string|max:255',
            'cv_ktp_portofolio_pdf'    => 'nullable|file|mimes:pdf|max:10240',
            'portofolio_visual'        => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'profile_photo'            => 'nullable|file|mimes:jpg,jpeg,png|max:5120',
            'design_software'          => 'nullable|string|max:255',
            'video_software'           => 'nullable|string|max:255',
            'programming_languages'    => 'nullable|string|max:255',
            'digital_marketing_type'   => 'nullable|string|max:255',
            'owned_tools'              => 'nullable|string|max:255',
            'supervisor_name'          => 'nullable|string|max:255',
            'supervisor_contact'       => 'nullable|string|max:50',
            'current_activities'       => 'nullable|string',
            'boarding_info'            => 'nullable|string|max:50',
            'social_media_instagram'   => 'nullable|string|max:255',
            'internship_info_sources'  => 'nullable|array',
            'internship_info_sources.*'=> 'nullable|string|max:100',
        ];
    }

    private function submitRules(): array
    {
        // Submit: wajib mengisi field contract form 2026.
        // Field legacy (born_date, faculty, english_book_ability, dll) tidak diwajibkan.
        // Berkas lampiran dan foto profil opsional saat submit awal namun divalidasi ketat bila diunggah.
        return array_merge($this->draftRules(), [
            // Data Diri
            'fullname'                 => 'required|string|max:150',
            'gender'                   => 'required|string|in:Laki-laki,Perempuan',
            'current_city'             => 'required|string|max:255',
            'email'                    => 'required|email|max:255',
            'phone_number'             => 'required|regex:/^[0-9]{10,15}$/',

            // Data Akademik
            'current_status'           => 'required|string|max:100',
            'student_id'               => 'required|string|max:50',
            'institution_name'         => 'required|string|max:255',
            'study_program'            => 'required|string|max:255',

            // Data Magang
            'internship_interest'      => 'required|string|max:255',
            'internship_type'          => 'required|string|max:50',
            'internship_reason'        => 'required|string',
            'wfo_readiness'            => 'required|string|max:100',
            'schedule_shift_readiness' => 'required|string|max:100',
            'has_laptop'               => 'required|in:0,1,true,false',
            'planned_duration'         => 'required|string|max:50',
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
