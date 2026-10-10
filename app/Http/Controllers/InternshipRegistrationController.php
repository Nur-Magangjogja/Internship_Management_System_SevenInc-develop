<?php

namespace App\Http\Controllers;

use App\Models\InternshipRegistration as IR;
use Illuminate\Http\Request;
use Illuminate\Support\Str; 
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class InternshipRegistrationController extends Controller
{
    public function showForm()
    {
        return view('user.form'); // Pastikan view yang benar
    }

    /**
     * Simpan file dengan NAMA ASLI.
     * Jika terdapat nama yang sama, tambahkan penomoran di belakang nama.
     * Return: path relatif pada disk 'public', mis: "uploads/cv-budi(1).pdf"
     */
    private function storeWithOriginalName(\Illuminate\Http\UploadedFile $file, string $dir): string
    {
        $disk = 'public';
        $original = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $ext = $file->getClientOriginalExtension();
        $safe = Str::slug($original, '_');

        $i = 0;
        do {
            $name = $i === 0 ? "{$safe}.{$ext}" : "{$safe}({$i}).{$ext}";
            $path = "{$dir}/{$name}";
            $i++;
        } while (Storage::disk($disk)->exists($path));

        return $file->storeAs($dir, $name, $disk);
    }

    /**
     * Form pendaftaran.
     */
    public function create()
    {
        $userId = auth()->id();
        if (IR::where('user_id', $userId)->exists()) {
            return redirect()->route('internship.submitted');
        }
        // render form-page normal
        return view('pages.internship.form-page');
    }

    /**
     * Simpan data pendaftaran.
     */
    // InternshipRegistrationController.php
    public function store(Request $request)
    {
        // Cek apakah user sudah mengisi form sebelumnya
        $userId = auth()->id();

        if (IR::where('user_id', $userId)->exists()) {
            return redirect()->route('internship.submitted')
                ->with('info', 'Anda sudah mengisi form pendaftaran sebelumnya.');
        }

        // Validasi (sinkron dengan field yang ada di FORM)
        $validated = $request->validate([
            // Wajib (existing)
            'fullname'              => 'required|string|max:255',
            'born_date'             => 'required|string|max:255', // form pakai teks bebas
            'birth_place'           => 'nullable|string|max:100',
            'student_id'            => 'required|string|max:50',
            'email'                 => 'required|string|max:255',
            'gender'                => 'required|string|max:50',
            'religion'              => 'nullable|string|max:50',
            'phone_number'          => 'required|string|max:30',
            'institution_name'      => 'required|string|max:255',
            'study_program'         => 'required|string|max:255',
            'faculty'               => 'required|string|max:255',
            'current_city'          => 'required|string|max:255',
            'internship_reason'     => 'required|string',
            'internship_type'       => 'required|string|max:50',
            'internship_arrangement'=> 'required|string|max:50',
            'current_status'        => 'required|string|max:50',
            'english_book_ability'  => 'required|string|max:50',
            'supervisor_contact'    => 'nullable|string|max:255',
            'internship_interest'   => 'required|string|max:255',
            'internship_interest_other' => 'nullable|string|max:255',

            // Tambahan dari form
            // Periode magang di form masih input teks → validasi string (nanti kita parse Carbon)
            'start_date'            => 'nullable|string|max:255',
            'end_date'              => 'nullable|string|max:255',

            // Bidang minat/skill
            'design_software'           => 'required|string|max:255',
            'video_software'            => 'required|string|max:255',
            'programming_languages'     => 'required|string|max:255',
            'digital_marketing_type'    => 'nullable|string|max:100',
            'digital_marketing_type_other' => 'nullable|string|max:255',

            // Perlengkapan & alat
            'laptop_equipment'      => 'nullable|string|max:50',   // Ya / Tidak
            'owned_tools'           => 'nullable|array',           // dari checkbox []
            'owned_tools.*'         => 'nullable|string|max:255',
            'owned_tools_other'     => 'nullable|string|max:255',

            // Sumber info
            'internship_info_sources'   => 'nullable|array',       // dari checkbox []
            'internship_info_sources.*' => 'nullable|string|max:100',
            'internship_info_other'     => 'nullable|string|max:255',

            // Lain-lain
            'current_activities'    => 'nullable|string|max:1000',
            'boarding_info'         => 'nullable|string|max:50',   // Ya / Tidak
            'social_media_instagram'=> 'nullable|string|max:255',

            // File
            'cv_ktp_portofolio_pdf' => 'nullable|file|mimes:pdf|max:10240',             // 10MB
            'portofolio_visual'     => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $data = $validated;

        // Normalisasi tanggal → coba Y-m-d, jika gagal biarkan apa adanya
        foreach (['born_date', 'start_date', 'end_date'] as $fld) {
            if (!empty($data[$fld])) {
                try {
                    $data[$fld] = \Carbon\Carbon::parse($data[$fld])->format('Y-m-d');
                } catch (\Throwable $e) {
                    // biarkan string asli
                }
            }
        }

        // Helper: gabung checkbox array → CSV (abaikan "other"/"lainnya")
        $toCsv = static function ($arr, array $ignore = []) {
            if (!is_array($arr)) return $arr;
            $vals = array_filter(array_map('trim', $arr), function ($v) use ($ignore) {
                if ($v === '' || $v === null) return false;
                $lv = mb_strtolower($v);
                return !in_array($lv, $ignore, true);
            });
            return empty($vals) ? null : implode(', ', $vals);
        };

        // owned_tools[] + owned_tools_other → string
        $ownedToolsCsv = $toCsv($request->input('owned_tools', []), ['lainnya', 'other']);
        $ownedOther    = trim((string)$request->input('owned_tools_other'));
        if ($ownedOther !== '') {
            $ownedToolsCsv = $ownedToolsCsv ? ($ownedToolsCsv . ', ' . $ownedOther) : $ownedOther;
        }
        $data['owned_tools'] = $ownedToolsCsv;

        // internship_info_sources[] + internship_info_other → string
        $infoCsv   = $toCsv($request->input('internship_info_sources', []), ['other']);
        $infoOther = trim((string)$request->input('internship_info_other'));
        if ($infoOther !== '') {
            $infoCsv = $infoCsv ? ($infoCsv . ', ' . $infoOther) : $infoOther;
        }
        $data['internship_info_sources'] = $infoCsv;



        // Upload file: pakai nama asli + penomoran jika duplikat
        if ($request->hasFile('cv_ktp_portofolio_pdf')) {
            $data['cv_ktp_portofolio_pdf'] = $this->storeWithOriginalName(
                $request->file('cv_ktp_portofolio_pdf'),
                'uploads'
            );
        }
        if ($request->hasFile('portofolio_visual')) {
            $data['portofolio_visual'] = $this->storeWithOriginalName(
                $request->file('portofolio_visual'),
                'uploads'
            );
        }

        // Resolusi Divisi
        $divisionInput = $request->input('division_id') ?? $request->input('internship_interest') ?? ($data['internship_interest'] ?? null);
        if (!empty($divisionInput)) {
            $data['division_id'] = \App\Http\Controllers\Pemagang\RegistrationController::resolveDivisionId($divisionInput);
        }
        unset($data['internship_interest']);

        // Status awal & relasi user
        $data['internship_status'] = IR::STATUS_WAITING;
        $data['user_id'] = $userId;

        // Simpan
        IR::create($data);
        
        $user = auth()->user();

        if ($user instanceof \App\Models\User) {
            $user->role = 'pemagang';
            $user->save();
        } else {
            return back()->with('error', 'Failed to update user role.');
        }

        // Tandai session & redirect
        session(['internship_submitted' => true]);

        return redirect()->route('internship.submitted')
            ->with('success', 'Data berhasil disimpan! Anda dapat melihat profil Anda di dashboard.');
    }


    public function editProfile()
    {
        $intern = optional(auth()->user())->internshipRegistration; // aman kalau null
        return view('user.edit-profile', compact('intern'));
    }

    public function updateProfile(Request $request)
    {
        $user   = $request->user();
        $intern = optional($user)->internshipRegistration;

        $validated = $request->validate([
            'fullname'                    => 'required|string|max:255',
            'born_date'                   => 'required|string|max:255',
            'birth_place'                 => 'nullable|string|max:100',
            'student_id'                  => 'required|string|max:50',
            'email'                       => 'required|string|max:255',
            'gender'                      => 'required|string|max:50',
            'religion'                    => 'nullable|string|max:50',
            'phone_number'                => 'required|string|max:30',
            'institution_name'            => 'required|string|max:255',
            'study_program'               => 'required|string|max:255',
            'faculty'                     => 'required|string|max:255',
            'current_city'                => 'required|string|max:255',
            'internship_reason'           => 'required|string',
            'internship_type'             => 'required|string|max:50',
            'internship_arrangement'      => 'required|string|max:50',
            'current_status'              => 'required|string|max:50',
            'english_book_ability'        => 'required|string|max:50',
            'supervisor_contact'          => 'nullable|string|max:255',
            'internship_interest'         => 'required|string|max:255',
            'internship_interest_other'   => 'nullable|string|max:255',

            // Tambahan field (sinkron dengan form)
            'design_software'                 => 'nullable|string|max:255',
            'video_software'                  => 'nullable|string|max:255',
            'programming_languages'           => 'nullable|string|max:255',
            'digital_marketing_type'          => 'nullable|string|max:255',
            'digital_marketing_type_other'    => 'nullable|string|max:255',

            'laptop_equipment'          => 'nullable|string|max:20',   // Ya/Tidak
            'owned_tools'               => 'nullable|array',
            'owned_tools.*'             => 'nullable|string|max:100',
            'owned_tools_other'         => 'nullable|string|max:255',

            'internship_info_sources'   => 'nullable|array',
            'internship_info_sources.*' => 'nullable|string|max:100',
            'internship_info_other'     => 'nullable|string|max:255',

            'current_activities'        => 'nullable|string|max:255',
            'boarding_info'             => 'nullable|string|max:20',   // Ya/Tidak
            'social_media_instagram'    => 'nullable|string|max:100',

            'start_date' => 'nullable', // parse manual
            'end_date'   => 'nullable', // parse manual

            // Files
            'cv_ktp_portofolio_pdf'     => 'nullable|file|mimes:pdf|max:10240',
            'portofolio_visual'         => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        // ——— Normalisasi tanggal → Y-m-d (aman bila gagal parse)
        foreach (['born_date','start_date','end_date'] as $fld) {
            if ($request->filled($fld)) {
                try {
                    $validated[$fld] = \Carbon\Carbon::parse($request->input($fld))->format('Y-m-d');
                } catch (\Throwable $e) {
                    // biarkan nilai aslinya jika gagal parse
                }
            }
        }
        // Cek end_date >= start_date bila keduanya valid
        if (!empty($validated['start_date']) && !empty($validated['end_date'])) {
            try {
                if (\Carbon\Carbon::parse($validated['end_date'])->lt(\Carbon\Carbon::parse($validated['start_date']))) {
                    return back()
                        ->withErrors(['end_date' => 'Tanggal selesai harus setelah atau sama dengan tanggal mulai.'])
                        ->withInput();
                }
            } catch (\Throwable $e) {}
        }

        // ——— Normalisasi Ya/Tidak (dan JANGAN tulis NULL ke DB)
        $yn = function ($v) {
            $v = \Illuminate\Support\Str::lower(trim((string) $v));
            if (in_array($v, ['ya','y','yes','1','true']))   return 'Ya';
            if (in_array($v, ['tidak','no','n','0','false'])) return 'Tidak';
            return null;
        };
        if (($v = $yn($request->input('laptop_equipment'))) !== null) $validated['laptop_equipment'] = $v; else unset($validated['laptop_equipment']);
        if (($v = $yn($request->input('boarding_info')))    !== null) $validated['boarding_info']    = $v; else unset($validated['boarding_info']);

        // ——— Checkbox arrays → simpan CSV
        $owned = $request->input('owned_tools');
        if (is_array($owned)) {
            $validated['owned_tools'] = implode(', ',
                array_values(array_filter(array_map('trim', $owned), fn($x) => $x !== ''))
            );
        } elseif (is_string($owned) && $owned !== '') {
            $validated['owned_tools'] = $owned;
        }

        $infos = $request->input('internship_info_sources');
        if (is_array($infos)) {
            $validated['internship_info_sources'] = implode(', ',
                array_values(array_filter(array_map('trim', $infos), fn($x) => $x !== ''))
            );
        } elseif (is_string($infos) && $infos !== '') {
            $validated['internship_info_sources'] = $infos;
        }

        // ——— Upload file (nama asli + penomoran)
        if ($request->hasFile('cv_ktp_portofolio_pdf')) {
            $validated['cv_ktp_portofolio_pdf'] = $this->storeWithOriginalName(
                $request->file('cv_ktp_portofolio_pdf'), 'uploads'
            );
        }
        if ($request->hasFile('portofolio_visual')) {
            $validated['portofolio_visual'] = $this->storeWithOriginalName(
                $request->file('portofolio_visual'), 'uploads'
            );
        }

        // ——— Pastikan record milik user ada; kalau belum, buat dengan status awal "waiting"
        if (!$intern) {
            $intern = new \App\Models\InternshipRegistration();
            $intern->user_id = $user->id;
            $intern->internship_status = defined(\App\Models\InternshipRegistration::class.'::STATUS_WAITING')
                ? \App\Models\InternshipRegistration::STATUS_WAITING
                : 'waiting';
        }

        // Jangan izinkan override status dari form
        unset($validated['internship_status']);

        // Resolusi Divisi
        $divisionInput = $request->input('division_id') ?? $request->input('internship_interest') ?? ($validated['internship_interest'] ?? null);
        if (!empty($divisionInput)) {
            $validated['division_id'] = \App\Http\Controllers\Pemagang\RegistrationController::resolveDivisionId($divisionInput);
        }
        unset($validated['internship_interest']);

        // Simpan
        $intern->fill($validated);
        $intern->save();

        return redirect()
            ->route('user.editProfile')
            ->with('success', 'Profil berhasil diperbarui!');
    }

}
