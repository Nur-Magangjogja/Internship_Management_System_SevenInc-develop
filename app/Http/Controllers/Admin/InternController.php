<?php

namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\InternshipRegistration as IR;
use App\Services\CertificatePdf;
use App\Mail\InternAcceptedMail;
use App\Mail\InternRejectedMail;
use App\Mail\InternWaitingMail;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Models\InternCertificate;

use Spatie\Browsershot\Browsershot;
use Carbon\Carbon;

class InternController extends Controller
{

    public function showSKL($internId)
    {
        $intern = \App\Models\InternshipRegistration::findOrFail($internId);
        $user = $intern->user ?? null;

        // Jika view skl.blade.php tidak ditemukan
        if (!view()->exists('user.skl')) {
            abort(404, 'File SKL tidak ditemukan di resources/views/user/skl.blade.php');
        }

        // Render surat SKL dengan data peserta
        return view('user.skl', compact('intern', 'user'));
    }


    private function sendAcceptedEmail(IR $intern): void
    {
        // Ambil email tujuan: prioritas ke kolom email pendaftar
        $to = $intern->email ?: optional($intern->user)->email;
        if (!$to) return;

        try {
            // Kirim email dengan queue
            Mail::to($to)->queue(new InternAcceptedMail($intern)); 
        } catch (\Exception $e) {
            // Log error jika ada masalah dengan pengiriman email
            Log::error("Email gagal terkirim ke {$to}: {$e->getMessage()}");
        }
    }


    public function certificatePdfDynamic(IR $intern, string $template)
    {
        if ($intern->internship_status !== IR::STATUS_COMPLETED) {
            abort(403, 'Sertifikat hanya tersedia untuk pemagang yang sudah selesai.');
        }

        $view = 'certificates.' . $template;
        if (!view()->exists($view)) {
            abort(404, "Template {$template} tidak ditemukan");
        }

        // Kirim hanya yang esensial
        $data = ['intern' => $intern, 'template' => $template];

        $filename = 'Sertifikat_' . \Illuminate\Support\Str::slug($intern->fullname ?: 'Pemagang', '_')
                . "_{$template}.pdf";

        return $this->downloadPdfFromView($view, $data, $filename);
    }

    private function downloadPdfFromView(string $view, array $data, string $downloadName)
    {
        $html = view($view, $data)->render();

        // ---- Embed <img src> ----
        $toPublicFile = function (string $src) {
            if (preg_match('~^https?://~i', $src)) {
                $path = parse_url($src, PHP_URL_PATH) ?: '';
            } else {
                $path = $src;
            }
            $path = ltrim($path, '/');

            // dukung public/storage/... dan public/images/...
            $candidates = [];
            if (stripos($path, 'storage/') === 0 || stripos($path, 'images/') === 0) {
                $candidates[] = public_path($path);
            }
            foreach ($candidates as $full) {
                if (is_file($full)) return $full;
            }
            return null;
        };

        $imgToDataUri = function (string $file) {
            $mime = FileFacade::mimeType($file) ?: 'image/png';
            $data = base64_encode(FileFacade::get($file));
            return "data:{$mime};base64,{$data}";
        };

        // DOM parse
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        // <img src="...">
        $imgs = $dom->getElementsByTagName('img');
        $imgNodes = [];
        foreach ($imgs as $i) { $imgNodes[] = $i; }
        foreach ($imgNodes as $img) {
            if (!($img instanceof \DOMElement)) continue;
            $src = $img->getAttribute('src');
            if (!$src) continue;
            if ($file = $toPublicFile($src)) {
                $img->setAttribute('src', $imgToDataUri($file));
            }
        }

        // inline style url(...)
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[@style]') as $el) {
            if (!($el instanceof \DOMElement)) continue;
            $style = $el->getAttribute('style');
            $style = preg_replace_callback(
                '~url\((["\']?)([^)\'"]+)\1\)~i',
                function ($m) use ($toPublicFile, $imgToDataUri) {
                    $file = $toPublicFile($m[2]);
                    return $file ? 'url(' . $imgToDataUri($file) . ')' : $m[0];
                },
                $style
            );
            $el->setAttribute('style', $style);
        }

        // <style> blocks url(...)
        $styleNodes = $dom->getElementsByTagName('style');
        for ($i = 0; $i < $styleNodes->length; $i++) {
            /** @var \DOMElement $styleEl */
            $styleEl = $styleNodes->item($i);
            $css = $styleEl->nodeValue ?? '';
            $css = preg_replace_callback(
                '~url\((["\']?)([^)\'"]+)\1\)~i',
                function ($m) use ($toPublicFile, $imgToDataUri) {
                    $file = $toPublicFile($m[2]);
                    return $file ? 'url(' . $imgToDataUri($file) . ')' : $m[0];
                },
                $css
            );
            while ($styleEl->firstChild) { $styleEl->removeChild($styleEl->firstChild); }
            $styleEl->appendChild($dom->createTextNode($css));
        }

        $html = $dom->saveHTML();

        // Hook ready
        $html .= <<<'HTML'
    <script>
    (function(){
    function imagesReady(){
        var imgs=[].slice.call(document.images||[]);
        if(!imgs.length) return Promise.resolve();
        return Promise.all(imgs.map(function(i){
        if(i.complete) return Promise.resolve();
        return new Promise(function(r){
            i.addEventListener('load', r, {once:true});
            i.addEventListener('error', r, {once:true});
        });
        }));
    }
    var timer=setTimeout(function(){window.__CERT_READY=true;}, 800);
    imagesReady().then(function(){ clearTimeout(timer); window.__CERT_READY=true; });
    })();
    </script>
    HTML;

        // Save PDF
        $safe = trim(preg_replace('/[^A-Za-z0-9_\- ]+/', '', pathinfo($downloadName, PATHINFO_FILENAME))) ?: 'Sertifikat';
        $filename = $safe . '.pdf';
        $dir  = storage_path('app/public/certificates');
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        $bs = Browsershot::html($html)
            ->showBackground()
            ->margins(0, 0, 0, 0)
            ->setOption('printBackground', true)
            ->setOption('preferCSSPageSize', true)
            ->emulateMedia('print')
            ->windowSize(1123, 794)
            ->deviceScaleFactor(2)
            ->waitForFunction('window.__CERT_READY === true')
            ->setOption('waitUntil', 'networkidle0')
            ->timeout(180);

        if ($chromePath = env('BROWSERSHOT_CHROME_PATH')) {
            $bs->setChromePath($chromePath);
        }
        // $bs->addChromiumArguments(['--no-sandbox','--disable-setuid-sandbox']);

        $bs->savePdf($path);

        return response()->download($path, $filename, ['Content-Type' => 'application/pdf'])
                        ->deleteFileAfterSend(true);
    }



    /**
     * Baca file dari storage:public lalu ubah jadi data URI (base64).
     * Return null bila file tidak ada.
     */
    private function dataUriPublic(string $relPath): ?string
    {
        if (!Storage::disk('public')->exists($relPath)) {
            return null;
        }

        $bytes = Storage::disk('public')->get($relPath);
        $ext   = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));

        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'svg'         => 'image/svg+xml',
            default       => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * Simpan file memakai nama asli; bila bentrok, beri (n).
     */
    private function storeWithOriginalName($file, string $directory = 'uploads'): string
    {
        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension    = $file->getClientOriginalExtension();

        $base = preg_replace('/[^A-Za-z0-9_\- ]+/', '', $originalName);
        $base = preg_replace('/\s+/', ' ', trim($base));
        $base = str_replace(' ', '-', $base);

        $filename = "{$base}.{$extension}";
        $path     = "{$directory}/{$filename}";
        $i = 1;

        while (Storage::disk('public')->exists($path)) {
            $filename = "{$base}({$i}).{$extension}";
            $path     = "{$directory}/{$filename}";
            $i++;
        }

        $file->storeAs($directory, $filename, 'public');
        return $path;
    }

    /**
     * Helper list tabel + pencarian ringan.
     */
    private function table(Request $request, $query, string $title, string $scope)
    {
        // Jika ada pencarian, hanya ambil data tanpa pagination
        if ($request->filled('q')) {
            $s = trim($request->get('q'));
            $query->where(function ($qq) use ($s) {
                $qq->where('fullname', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('student_id', 'like', "%{$s}%")
                ->orWhereHas('institution', function ($q) use ($s) {
                    $q->where('name', 'like', "%{$s}%");
                });
            });

            // Ambil semua data yang sesuai dengan pencarian
            $interns = $query->get();
        } else {
            // Jika tidak ada pencarian, gunakan pagination
            $interns = $query->paginate(25)->withQueryString();
        }

        return view('interns.index', [
            'interns'   => $interns,
            'title'     => $title,
            'scope'     => $scope,
            'brands'    => \App\Models\Brand::orderBy('name')->get(),
            'divisions' => \App\Models\Division::orderBy('name')->get(),
            'cities'    => \App\Models\City::orderBy('name')->get(),
            'institutions' => \App\Models\Institution::orderBy('name')->get(),
            'faculties' => \App\Models\Faculty::orderBy('name')->get(),
            'studyPrograms' => \App\Models\StudyProgram::orderBy('name')->get(),
        ]);
    }

    public function index(Request $request)
    {
        return $this->table(
            $request,
            IR::query()->orderByDesc('created_at'),
            'Semua Pemagang',
            'all'
        );
        
    }

    public function active(Request $request)
    {
        return $this->table(
            $request,
            IR::where('internship_status', IR::STATUS_ACTIVE)->orderByDesc('updated_at'),
            'Pemagang Aktif',
            'active'
        );
    }

    public function completed(Request $request)
    {
        return $this->table(
            $request,
            IR::where('internship_status', IR::STATUS_COMPLETED)->orderByDesc('updated_at'),
            'Pemagang Selesai',
            'completed'
        );
    }

    public function exited(Request $request)
    {
        return $this->table(
            $request,
            IR::where('internship_status', IR::STATUS_EXITED)->orderByDesc('updated_at'),
            'Pemagang Keluar',
            'exited'
        );
    }

    public function pending(Request $request)
    {
        return $this->table(
            $request,
            IR::where('internship_status', IR::STATUS_PENDING)->orderByDesc('created_at'),
            'Pemagang Pending',
            'pending'
        );
    }

    /**
     * PATCH /admin/interns/{intern}/status
     */
    public function update(Request $request, $id)
    {
        $intern = IR::findOrFail($id);

        // Validasi semua field yang bisa diedit via modal Edit
        $validatedData = $request->validate([
            // Data Pribadi
            'fullname'               => 'required|string|max:255',
            'born_date'              => 'nullable|string|max:255',
            'birth_place'            => 'nullable|string|max:100',
            'student_id'             => 'required|string|max:50',
            'email'                  => 'required|email|max:255',
            'gender'                 => 'nullable|string|max:50',
            'religion'               => 'nullable|string|max:50',
            'phone_number'           => 'nullable|string|max:20',
            'current_city'           => 'nullable|string|max:255',
            // Data Akademik
            'institution_name'       => 'nullable|string|max:255',
            'study_program'          => 'nullable|string|max:255',
            'faculty'                => 'nullable|string|max:255',
            // Informasi Magang
            'internship_type'        => 'nullable|string|max:50',
            'internship_arrangement' => 'nullable|string|max:50',
            'internship_interest'    => 'nullable|string|max:255',
            'internship_reason'      => 'nullable|string',
            'current_status'         => 'nullable|string|max:50',
            'english_book_ability'   => 'nullable|string|max:100',
            'supervisor_name'        => 'nullable|string|max:255',
            'supervisor_contact'     => 'nullable|string|max:20',
            'start_date'             => 'nullable|string|max:255',
            'end_date'               => 'nullable|string|max:255',
            // Keahlian & Alat
            'design_software'        => 'nullable|string|max:255',
            'video_software'         => 'nullable|string|max:255',
            'programming_languages'  => 'nullable|string|max:255',
            'digital_marketing_type' => 'nullable|string|max:255',
            'owned_tools'            => 'nullable|string|max:255',
            // Informasi Tambahan
            'current_activities'     => 'nullable|string',
            'boarding_info'          => 'nullable|string|max:50',

            'social_media_instagram' => 'nullable|string|max:255',
            'internship_info_sources'=> 'nullable|string|max:500',
            // Status & Brand (dikelola admin)
            'internship_status'      => 'nullable|in:waiting,active,completed,exited,pending,accepted,rejected',
            'brand'                  => 'nullable|string|max:100',
        ]);

        // Normalisasi tanggal ke Y-m-d jika berhasil di-parse
        foreach (['born_date', 'start_date', 'end_date'] as $dateField) {
            if (!empty($validatedData[$dateField])) {
                try {
                    $validatedData[$dateField] = Carbon::parse($validatedData[$dateField])->format('Y-m-d');
                } catch (\Throwable) {
                    // biarkan string asli
                }
            }
        }

        // Normalisasi string kosong birth_place & religion ke NULL
        if (array_key_exists('birth_place', $validatedData) && trim((string)$validatedData['birth_place']) === '') {
            $validatedData['birth_place'] = null;
        }
        if (array_key_exists('religion', $validatedData) && trim((string)$validatedData['religion']) === '') {
            $validatedData['religion'] = null;
        }

        // Cegah silent data loss: tolak nilai baru jika database belum siap
        $hasBirthPlaceCol = \Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'birth_place');
        $hasReligionCol   = \Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'religion');

        if (!$hasBirthPlaceCol && !empty($validatedData['birth_place'])) {
            $msg = 'Kolom tempat lahir belum didukung oleh konfigurasi database saat ini.';
            return $request->expectsJson() || $request->ajax()
                ? response()->json(['message' => $msg, 'errors' => ['birth_place' => [$msg]]], 422)
                : back()->withInput()->withErrors(['birth_place' => $msg]);
        }

        if (!$hasReligionCol && !empty($validatedData['religion'])) {
            $msg = 'Kolom agama belum didukung oleh konfigurasi database saat ini.';
            return $request->expectsJson() || $request->ajax()
                ? response()->json(['message' => $msg, 'errors' => ['religion' => [$msg]]], 422)
                : back()->withInput()->withErrors(['religion' => $msg]);
        }

        // Helper untuk mencari atau membuat relasi (case-insensitive dengan scoping parent)
        $getRelationId = function($modelClass, $name, $extra = []) {
            if (empty($name)) return null;
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

        if (array_key_exists('current_city', $validatedData)) {
            if (!empty($validatedData['current_city'])) {
                $validatedData['city_id'] = $getRelationId(\App\Models\City::class, $validatedData['current_city']);
            }
            unset($validatedData['current_city']);
        }
        if (array_key_exists('institution_name', $validatedData)) {
            if (!empty($validatedData['institution_name'])) {
                $instName = trim($validatedData['institution_name']);
                $normName = \App\Models\Institution::normalizeName($instName);
                $existingInst = \App\Models\Institution::whereRaw('LOWER(name) = ?', [strtolower($instName)])
                    ->orWhere('normalized_name', $normName)
                    ->first();
                if ($existingInst) {
                    $validatedData['institution_id'] = $existingInst->id;
                } else {
                    $validatedData['institution_id'] = \App\Models\Institution::create([
                        'name'            => ucwords(strtolower($instName)),
                        'normalized_name' => $normName,
                        'is_active'       => true,
                    ])->id;
                }
            }
            unset($validatedData['institution_name']);
        }
        if (array_key_exists('faculty', $validatedData)) {
            if (!empty($validatedData['faculty'])) {
                $extra = isset($validatedData['institution_id']) ? ['institution_id' => $validatedData['institution_id']] : [];
                $validatedData['faculty_id'] = $getRelationId(\App\Models\Faculty::class, $validatedData['faculty'], $extra);
            }
            unset($validatedData['faculty']);
        }
        if (array_key_exists('study_program', $validatedData)) {
            if (!empty($validatedData['study_program'])) {
                $extra = isset($validatedData['faculty_id']) ? ['faculty_id' => $validatedData['faculty_id']] : [];
                $validatedData['study_program_id'] = $getRelationId(\App\Models\StudyProgram::class, $validatedData['study_program'], $extra);
            }
            unset($validatedData['study_program']);
        }

        if (array_key_exists('internship_interest', $validatedData)) {
            if (!empty($validatedData['internship_interest'])) {
                // frontend sends division name
                $validatedData['division_id'] = \App\Http\Controllers\Pemagang\RegistrationController::resolveDivisionId($validatedData['internship_interest']);
            }
            unset($validatedData['internship_interest']);
        }
        if (array_key_exists('division_id', $validatedData)) {
            if (!empty($validatedData['division_id'])) {
                $validatedData['division_id'] = \App\Http\Controllers\Pemagang\RegistrationController::resolveDivisionId($validatedData['division_id']);
            }
        }

        if (array_key_exists('brand', $validatedData)) {
            if (!empty($validatedData['brand'])) {
                $brandStr = trim($validatedData['brand']);
                $existingBrand = \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower($brandStr)])
                    ->orWhereRaw('LOWER(code) = ?', [strtolower($brandStr)])
                    ->first();
                if ($existingBrand) {
                    $validatedData['brand_id'] = $existingBrand->id;
                }
            }
            unset($validatedData['brand']);
        }

        if (array_key_exists('email', $validatedData)) {
            if (!empty($validatedData['email']) && $intern->user) {
                // Check jika email sudah dipakai user lain
                $emailExists = \App\Models\User::where('email', $validatedData['email'])->where('id', '!=', $intern->user_id)->exists();
                if (!$emailExists) {
                    $intern->user->update(['email' => $validatedData['email']]);
                }
            }
            unset($validatedData['email']);
        }

        // Sync Skills
        $skillFields = [
            'design_software' => 'design',
            'video_software' => 'video',
            'programming_languages' => 'programming',
            'digital_marketing_type' => 'digital_marketing',
        ];
        
        $intern->skills()->delete();
        foreach ($skillFields as $field => $category) {
            if (array_key_exists($field, $validatedData)) {
                $val = trim($validatedData[$field]);
                if (!empty($val) && strtolower($val) !== 'tidak ada' && strtolower($val) !== '-') {
                    $items = array_filter(array_map('trim', explode(',', $val)));
                    foreach ($items as $item) {
                        $intern->skills()->create([
                            'skill_category' => $category,
                            'skill_name' => $item
                        ]);
                    }
                }
                unset($validatedData[$field]);
            }
        }

        // Sync Tools
        $intern->tools()->delete();
        $allTools = [];
        if (array_key_exists('owned_tools', $validatedData)) {
            $val = trim($validatedData['owned_tools']);
            if (!empty($val) && strtolower($val) !== 'tidak ada' && strtolower($val) !== '-') {
                $allTools = array_merge($allTools, array_filter(array_map('trim', explode(',', $val))));
            }
            unset($validatedData['owned_tools']);
        }
        foreach (array_unique($allTools) as $item) {
            $intern->tools()->create([
                'tool_name' => $item
            ]);
        }

        // Sync Info Sources
        if (array_key_exists('internship_info_sources', $validatedData)) {
            $intern->infoSources()->delete();
            $val = trim($validatedData['internship_info_sources']);
            if (!empty($val) && strtolower($val) !== 'tidak ada' && strtolower($val) !== '-') {
                $items = array_filter(array_map('trim', explode(',', $val)));
                foreach ($items as $item) {
                    $intern->infoSources()->create([
                        'source_name' => $item
                    ]);
                }
            }
            unset($validatedData['internship_info_sources']);
        }

        // Track meaningful attribute changes for audit logging (excluding status transitions handled by lifecycle)
        $trackedFields = [
            'name', 'fullname', 'birth_place', 'religion', 'phone', 'phone_number', 'institution_id', 'faculty_id',
            'study_program_id', 'major', 'division_id', 'brand_id', 'start_date', 'end_date',
            'status_pembimbing', 'pembimbing_nama', 'pembimbing_phone'
        ];
        $beforeChanges = [];
        $afterChanges = [];
        foreach ($validatedData as $key => $val) {
            if (in_array($key, $trackedFields) && $intern->getAttribute($key) != $val) {
                if ($key === 'religion') {
                    // Privasi: catat identitas field tanpa menyimpan nilai sensitif agama ke riwayat log
                    $beforeChanges[$key] = '[DIUBAH]';
                    $afterChanges[$key] = '[DIUBAH]';
                } else {
                    $beforeChanges[$key] = $intern->getAttribute($key);
                    $afterChanges[$key] = $val;
                }
            }
        }

        // Proteksi kompatibilitas environment unmigrated
        if (!\Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'birth_place')) {
            unset($validatedData['birth_place']);
        }
        if (!\Illuminate\Support\Facades\Schema::hasColumn('internship_registrations', 'religion')) {
            unset($validatedData['religion']);
        }

        // Jangan timpa internship_status lewat update biasa jika tidak dikirim
        if (isset($validatedData['internship_status']) && $validatedData['internship_status'] !== $intern->internship_status) {
            $oldStatus = $intern->internship_status;
            $newStatus = $validatedData['internship_status'];
            $reason    = $request->input('reason') ?? $request->input('correction_reason');
            $lifecycle = app(\App\Services\RegistrationLifecycleService::class);

            try {
                $lifecycle->transition($intern, $newStatus, isAdmin: true, reason: $reason);
            } catch (\InvalidArgumentException $e) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }
                return back()->withInput()->with('error', $e->getMessage());
            }

            unset($validatedData['internship_status']);
            $intern->fill($validatedData)->save();
            $this->syncPemagangRole($intern);
            
            // Buat membercard hanya saat status active atau completed
            if (in_array($intern->internship_status, [IR::STATUS_ACTIVE, IR::STATUS_COMPLETED])) {
                $intern->user?->createMemberCard();
            }

            if ($oldStatus !== IR::STATUS_ACCEPTED && $intern->internship_status === IR::STATUS_ACCEPTED) {
                $this->sendAcceptedEmail($intern);
            }
            
            // Trigger SKL generation if completed
            $this->checkAndGenerateSkl($intern, $oldStatus);

            // Sinkronkan data ke Absen Djuragan saat pemagang di-ACC (accepted / active)
            if (in_array($intern->internship_status, [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE])) {
                \App\Services\AbsenDjuraganSyncService::syncIntern($intern, $validatedData['brand'] ?? null);
            }
        } else {
            unset($validatedData['internship_status']);
            $intern->fill($validatedData)->save();
        }

        if (!empty($afterChanges)) {
            \App\Services\ActivityLogger::log(
                action: 'admin_intern_registration_updated',
                description: "Data pendaftaran {$intern->name} diperbarui oleh Administrator.",
                subject: $intern,
                before: $beforeChanges,
                after: $afterChanges,
                metadata: ['registration_id' => $intern->id]
            );
        }

        return response()->json($intern, 200);
    }




    public function destroy(Request $request, $id)
    {
        $intern = IR::findOrFail($id);

        $request->validate([
            'admin_password' => ['required', 'string'],
        ], [
            'admin_password.required' => 'Konfirmasi kata sandi admin wajib diisi.',
        ]);

        if (!\Illuminate\Support\Facades\Hash::check($request->admin_password, auth()->user()->password)) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'Konfirmasi kata sandi admin salah. Aksi penghapusan dibatalkan.'
                ], 403);
            }
            return back()->with('error', 'Konfirmasi kata sandi admin salah. Aksi penghapusan dibatalkan.');
        }

        $internName = $intern->fullname;
        $internId   = $intern->id;
        $hadDocs    = $intern->hasDocuments();

        // Soft delete pendaftaran (history-safe, tidak memicu MySQL cascade)
        $intern->delete();

        \App\Services\ActivityLogger::log(
            action: 'intern_registration_deleted',
            description: "Admin melakukan soft delete pendaftaran pemagang ke arsip: {$internName}",
            metadata: [
                'auth_verified' => true,
                'intern_id'     => $internId,
                'had_documents' => $hadDocs,
            ]
        );

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => "Data pendaftaran {$internName} berhasil dipindahkan ke arsip (soft delete)."
            ], 200);
        }

        return redirect()->back()->with('success', "Data pendaftaran {$internName} berhasil dipindahkan ke arsip (soft delete).");
    }

    /**
     * Memulihkan pendaftaran pemagang yang telah di-soft-delete (dengan re-autentikasi kata sandi admin).
     */
    public function restore(Request $request, $id)
    {
        $request->validate([
            'admin_password' => ['required', 'string'],
        ], [
            'admin_password.required' => 'Konfirmasi kata sandi admin wajib diisi.',
        ]);

        if (!\Illuminate\Support\Facades\Hash::check($request->admin_password, auth()->user()->password)) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'Konfirmasi kata sandi admin salah. Aksi pemulihan dibatalkan.'
                ], 403);
            }
            return back()->with('error', 'Konfirmasi kata sandi admin salah. Aksi pemulihan dibatalkan.');
        }

        $intern = IR::onlyTrashed()->findOrFail($id);

        $internName = $intern->fullname;
        $intern->restore();

        // PENTING: Restore registration tidak memulihkan User atau mengubah role/status akun.
        // Account state dan registration lifecycle tetap terpisah.

        \App\Services\ActivityLogger::log(
            action: 'intern_registration_restored',
            description: "Admin memulihkan data pendaftaran pemagang dari arsip: {$internName}",
            metadata: [
                'auth_verified' => true,
                'intern_id'     => $intern->id,
            ]
        );

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => "Data pendaftaran {$internName} berhasil dipulihkan dari arsip.",
            ], 200);
        }

        return redirect()->back()->with('success', "Data pendaftaran {$internName} berhasil dipulihkan dari arsip.");
    }

    // ===== helper: tetapkan / cabut role "pemagang" sesuai status & kondisi =====
    private function syncPemagangRole(IR $intern): void
    {
        $user = $intern->user;
        if (!$user) return;

        // Status yang memberikan role "pemagang": accepted, active, completed
        $activePemagangStatuses = [
            IR::STATUS_ACCEPTED,
            IR::STATUS_ACTIVE,
            IR::STATUS_COMPLETED,
        ];

        if (in_array($intern->internship_status, $activePemagangStatuses)) {
            // Beri role pemagang (jaga idempotensi)
            if (mb_strtolower($user->role ?? '') !== 'pemagang') {
                $user->role = 'pemagang';
                $user->save();
            }
        } elseif (in_array($intern->internship_status, [IR::STATUS_EXITED, IR::STATUS_REJECTED])) {
            // Hanya cabut role bila status keluar atau ditolak DAN pengguna tidak memiliki riwayat completed lainnya (bukan alumni)
            $lifecycle = app(\App\Services\RegistrationLifecycleService::class);
            if (!$lifecycle->shouldRetainPemagangRole($user)) {
                if (mb_strtolower($user->role ?? '') === 'pemagang') {
                    $user->role = 'user';
                    $user->save();
                }
            }
        }
        // Status waiting/pending: jangan ubah role
    }

    private function checkAndGenerateSkl(IR $intern, $oldStatus)
    {
        if ($oldStatus !== IR::STATUS_COMPLETED && $intern->internship_status === IR::STATUS_COMPLETED) {
            $brandData = null;
            if ($intern->brand_id) {
                $brandData = \App\Models\Brand::find($intern->brand_id);
            } elseif ($intern->brand) {
                $brandData = \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower(trim($intern->brand))])->first();
            }

            $companyName    = $brandData->name ?? $intern->brand ?? 'Seven Inc';
            $signatoryName  = $brandData->signatory_name ?? 'Ari Setia Husbana';
            $signatoryTitle = $brandData->signatory_position ?? 'HRD';
            
            $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
            $romanMonth = $roman[(int)date('n')];
            $year = date('Y');

            $lastSkl = \App\Models\SklDocument::where('skl_number', 'LIKE', "%/SKL/%/{$romanMonth}/{$year}")
                ->orderByDesc('id')->first();
            $seq = 1;
            if ($lastSkl && preg_match('/^(\d{3})\/SKL\//', $lastSkl->skl_number, $m)) {
                $seq = (int)$m[1] + 1;
            }

            $divisionCode = $this->divisionFromInterest((string)$intern->internship_interest) ?? 'UMUM';
            $brandCodeStr = strtoupper($intern->brand ?? 'SI');
            $seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
            $sklNumber = "{$seqStr}/SKL/{$divisionCode}/SEVEN.{$brandCodeStr}/{$romanMonth}/{$year}";

            $sklDoc = \App\Models\SklDocument::updateOrCreate(
                ['intern_id' => $intern->id],
                [
                    'skl_number' => $sklNumber,
                    'company_name' => $companyName,
                    'company_logo_path' => $brandData?->logo ?? 'images/logos/logo_seveninc.png',
                    'signatory_name' => $signatoryName,
                    'signatory_position' => $signatoryTitle,
                    'signature_image_path' => $brandData?->signature ?? 'images/signature/ttd_arisetiahusbana.png',
                ]
            );
            if ($sklDoc) {
                // \App\Jobs\GenerateSklJob::dispatch($intern->id);
            }

            // Generate Surat Penilaian jika data penilaian sudah ada
            $assessment = \App\Models\InternAssessment::where('intern_id', $intern->id)->first();
            if ($assessment) {
                // \App\Jobs\GenerateAssessmentJob::dispatch($intern->id);
            }
        }
    }

    private function checkAndGenerateLoa(IR $intern, $oldStatus)
    {
        if ($oldStatus !== IR::STATUS_ACCEPTED && $intern->internship_status === IR::STATUS_ACCEPTED) {
            $brandData = null;
            if ($intern->brand_id) {
                $brandData = \App\Models\Brand::find($intern->brand_id);
            } elseif ($intern->brand) {
                $brandData = \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower(trim($intern->brand))])->first();
            }

            $companyName = $brandData->name ?? $intern->brand ?? 'Seven Inc';
            $signatoryName = $brandData->signatory_name ?? 'Ari Setia Husbana';
            $signatoryPosition = $brandData->signatory_position ?? 'HRD';

            $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
            $romanMonth = $roman[(int)date('n')];
            $year = date('Y');

            $last = \App\Models\InternLoa::where('loa_number', 'LIKE', "%/LOA/%/{$romanMonth}/{$year}")
                ->orderByDesc('id')->first();
            $seq = 1;
            if ($last && preg_match('/^(\d{3})\/LOA\//', $last->loa_number, $m)) {
                $seq = (int)$m[1] + 1;
            }

            $interest = $intern->internship_interest ?? '';
            $dbDivision = \App\Models\Division::where('name', $interest)
                ->orWhere('slug', \Illuminate\Support\Str::slug($interest, '-'))
                ->first();
                
            $division = $dbDivision?->code ?? 'UMUM';
            $brandStr = strtoupper($brandData?->code ?? 'SI');

            $seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
            $loaNumber = "{$seqStr}/LOA/{$division}/SEVEN.{$brandStr}/{$romanMonth}/{$year}";

            \App\Models\InternLoa::updateOrCreate(
                ['intern_id' => $intern->id],
                [
                    'loa_number' => $loaNumber,
                    'accepted_start_date' => $intern->start_date ?? now(),
                    'accepted_end_date' => $intern->end_date ?? now(),
                    'company_name' => $companyName,
                    'company_logo_path' => $brandData?->logo,
                    'signatory_name' => $signatoryName,
                    'signatory_position' => $signatoryPosition,
                    'signature_image_path' => $brandData?->signature,
                ]
            );
        }
    }

    private function checkAndGenerateCertificate(IR $intern, $oldStatus)
    {
        if ($oldStatus !== IR::STATUS_COMPLETED && $intern->internship_status === IR::STATUS_COMPLETED) {
            $existingCert = InternCertificate::where('intern_id', $intern->id)->first();
            if ($existingCert) {
                return; // Already has certificate
            }

            $end = $intern->end_date ? Carbon::parse($intern->end_date) : now();
            $brandData = \App\Models\Brand::where('code', $intern->brand)->first();
            if (!$brandData && $intern->brand_id) {
                $brandData = \App\Models\Brand::find($intern->brand_id);
            }

            $divisionCode = $this->divisionFromInterest((string)$intern->internship_interest) ?? 'ADM';

            $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
            $romanMonth = $roman[$end->month];

            // Find max seq for this month/year by parsing the actual certificate_number
            $last = InternCertificate::where('certificate_number', 'LIKE', "%/{$romanMonth}/{$end->year}")
                ->orderByDesc('id')->first();
            
            $seq = 1;
            if ($last && preg_match('/^(\d{3})\/SERT\//', $last->certificate_number, $m)) {
                $seq = (int)$m[1] + 1;
            }

            $seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
            
            $brandCode = strtoupper($intern->brand ?? 'SI');
            $serial = "{$seqStr}/SERT/{$divisionCode}/SEVEN.{$brandCode}/".$roman[$end->month]."/".$end->year;

            $cert = InternCertificate::create([
                'intern_id'             => $intern->id,
                'certificate_number'    => $serial,
                'company_name'          => $brandData?->name ?? 'Seven Inc',
                'background_image_path' => $brandData?->internship_certificate_bg,
                'company_logo_path'     => $brandData?->logo,
                'signatory_name'        => $brandData?->signatory_name ?? 'Ari Setia Husbana',
                'signatory_position'    => $brandData?->signatory_position ?? 'HRD',
                'signature_image_path'  => $brandData?->signature,
            ]);

            if ($cert) {
                // \App\Jobs\GenerateCertificateJob::dispatch($intern->id);
            }
        }
    }

    private function companyCode(string $companyName): string
    {
        $map = [
            'seven inc' => 'SI',
            'pt. seven inc' => 'SI',
            'magang jogja' => 'MJ',
            'magangjogja' => 'MJ',
        ];
        $k = strtolower(trim($companyName));
        if (isset($map[$k])) return $map[$k];

        $words = explode(' ', strtoupper($k));
        if (count($words) >= 2) {
            return substr($words[0],0,1) . substr($words[1],0,1);
        }
        return substr($k,0,2);
    }

    private function divisionFromInterest(string $interest): ?string
    {
        $dbDivision = \App\Models\Division::where('name', $interest)
            ->orWhere('slug', \Illuminate\Support\Str::slug($interest, '-'))
            ->first();
            
        return $dbDivision?->code;
    }

    public function updateStatus(Request $request, $id)
    {
        // Menemukan data berdasarkan ID yang diberikan
        $intern = IR::findOrFail($id);

        // Validasi status yang diterima
        $validated = $request->validate([
            'internship_status' => 'required|in:waiting,active,completed,exited,pending,accepted,rejected',
            'brand'             => 'nullable|string|max:100',
            'reason'            => 'nullable|string|max:500',
            'correction_reason' => 'nullable|string|max:500',
        ]);

        $oldStatus = $intern->internship_status; // Menyimpan status lama
        $newStatus = $validated['internship_status']; // Status baru yang diterima
        $reason    = $validated['reason'] ?? $validated['correction_reason'] ?? $request->input('reason') ?? $request->input('correction_reason');

        // Gunakan RegistrationLifecycleService untuk validasi & transisi
        $lifecycle = app(\App\Services\RegistrationLifecycleService::class);
        try {
            $lifecycle->transition($intern, $newStatus, isAdmin: true, reason: $reason);
        } catch (\InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Simpan brand jika status accepted & brand dikirim
        if ($newStatus === IR::STATUS_ACCEPTED && !empty($validated['brand'])) {
            $brandName = trim($validated['brand']);
            $existingBrand = \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower($brandName)])
                ->orWhereRaw('LOWER(code) = ?', [strtolower($brandName)])
                ->first();
            if ($existingBrand) {
                $intern->brand_id = $existingBrand->id;
                $intern->save();
            }
        }

        // Sinkronkan role user sesuai status baru
        $this->syncPemagangRole($intern);

        // Buat membercard hanya saat status active atau completed
        if (in_array($intern->internship_status, [IR::STATUS_ACTIVE, IR::STATUS_COMPLETED])) {
            $intern->user?->createMemberCard();
        }

        // Jika berubah menjadi diterima, simpan info LOA
        $this->checkAndGenerateLoa($intern, $oldStatus);
        
        if ($oldStatus !== IR::STATUS_ACCEPTED && $intern->internship_status === IR::STATUS_ACCEPTED) {
            $this->sendAcceptedEmail($intern);
        }

        // Sinkronkan data ke Absen Djuragan saat pemagang di-ACC (accepted / active)
        if (in_array($newStatus, [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE])) {
            \App\Services\AbsenDjuraganSyncService::syncIntern($intern, $validated['brand'] ?? null);
        }

        // Jika berubah menjadi selesai, generate SKL via Background Job
        $this->checkAndGenerateSkl($intern, $oldStatus);
        $this->checkAndGenerateCertificate($intern, $oldStatus);

        // Return JSON untuk AJAX (fetch), redirect untuk request biasa
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok'     => true,
                'status' => $intern->internship_status,
                'name'   => $intern->fullname,
            ]);
        }

        return redirect()->route('admin.interns.index')->with('success', 'Status berhasil diperbarui!');
    }

    /**
     * PATCH /admin/interns/{intern}/followup-status
     * Memperbarui status follow-up pendaftar oleh admin (S17.18C).
     */
    public function updateFollowupStatus(Request $request, $id)
    {
        $intern = $id instanceof IR ? $id : IR::findOrFail($id);

        $validStatuses = implode(',', array_keys(IR::FOLLOWUP_STATUS_LABELS));
        $validated = $request->validate([
            'followup_status' => 'required|in:' . $validStatuses,
            'reason'          => 'nullable|string|max:500',
        ]);

        $newFollowupStatus = $validated['followup_status'];
        $reason = $validated['reason'] ?? null;

        $lifecycle = app(\App\Services\RegistrationLifecycleService::class);
        try {
            $lifecycle->updateFollowupStatus($intern, $newFollowupStatus, $request->user(), $reason);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => $e->getMessage()], 403);
            }
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withInput()->with('error', $e->getMessage());
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok'                    => true,
                'followup_status'       => $intern->followup_status,
                'followup_status_label' => $intern->followup_status_label,
                'message'               => 'Status follow-up berhasil diperbarui.',
            ]);
        }

        return redirect()->back()->with('success', 'Status follow-up berhasil diperbarui.');
    }


    /**
     * PATCH /admin/interns/bulk/status
     */
    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'ids'               => 'required|array|min:1',
            'ids.*'             => 'integer|exists:internship_registrations,id',
            'internship_status' => 'required|in:waiting,active,completed,exited,pending,accepted,rejected',
        ]);

        $affected = 0;
        $mailCount = 0;
        $mailList  = [];

        $lifecycle = app(\App\Services\RegistrationLifecycleService::class);
        $reason    = $request->input('reason') ?? $request->input('correction_reason');

        DB::transaction(function () use ($validated, &$affected, &$mailCount, &$mailList, $lifecycle, $reason) {
            $interns = IR::whereIn('id', $validated['ids'])->lockForUpdate()->get();

            foreach ($interns as $intern) {
                if ($intern->internship_status === $validated['internship_status']) {
                    continue;
                }

                $old = $intern->internship_status;
                $lifecycle->transition($intern, $validated['internship_status'], isAdmin: true, reason: $reason);

                // Buat membercard hanya saat status active atau completed
                if (in_array($intern->internship_status, [IR::STATUS_ACTIVE, IR::STATUS_COMPLETED])) {
                    $intern->user?->createMemberCard();
                }
                $this->syncPemagangRole($intern);

                if ($old !== IR::STATUS_ACCEPTED && $intern->internship_status === IR::STATUS_ACCEPTED) {
                    $this->sendAcceptedEmail($intern);
                    $mailCount++;
                    $to = $intern->email ?: optional($intern->user)->email;
                    if ($to) {
                        $mailList[] = ['to' => $to, 'name' => $intern->fullname];
                    }
                }
                
                $this->checkAndGenerateSkl($intern, $old);
                $this->checkAndGenerateCertificate($intern, $old);

                // Sinkronkan data ke Absen Djuragan saat pemagang di-ACC (accepted / active)
                if (in_array($validated['internship_status'], [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE])) {
                    \App\Services\AbsenDjuraganSyncService::syncIntern($intern);
                }

                $affected++;
            }
        });

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'ok'       => true,
                'affected' => $affected,
                'mail'     => [
                    'count' => $mailCount,
                    'list'  => $mailList,
                ],
            ]);
        }

        return back()
            ->with('success', "Status {$affected} pemagang diperbarui.")
            ->with('mail_info', $mailCount ? [
                'title' => "Email notifikasi terkirim ({$mailCount})",
                'list'  => $mailList,
            ] : null);
    }


    /**
     * Admin unggah/ganti file untuk satu pemagang.
     */
    public function updateFiles(Request $request, IR $intern)
    {
        $request->validate([
            'cv_ktp_portofolio_pdf' => 'nullable|file|mimes:pdf|max:10240',
            'portofolio_visual'     => 'nullable|file|mimes:jpg,jpeg,png|max:10240',
        ]);

        $data = [];

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

        if (!empty($data)) {
            $intern->fill($data)->save();
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'paths' => $data]);
        }

        return back()->with('success', 'Berkas berhasil diperbarui.');
    }

        /**
     * Spatie Browsershot — render JS/canvas → PDF 1 halaman (full-bleed).
     * GET /admin/interns/{intern}/certificate.pdf
     */
    public function certificatePdf(IR $intern, CertificatePdf $pdf)
    {
        if ($intern->internship_status !== IR::STATUS_COMPLETED) {
            abort(403, 'Sertifikat hanya tersedia untuk pemagang yang sudah selesai.');
        }

        Carbon::setLocale('id');
        $start = $intern->start_date ? Carbon::parse($intern->start_date) : null;
        $end   = $intern->end_date   ? Carbon::parse($intern->end_date)   : null;

        $startDateStr = $start ? $start->translatedFormat('j F Y') : '';
        $endDateStr   = $end   ? $end->translatedFormat('j F Y')   : '';

        $durationText = 'beberapa bulan';
        if ($start && $end) {
            $months = round($start->diffInDays($end) / 30, 1);
            $durationText = str_replace('.', ',', (string) $months) . ' bulan';
        }

        $data = [
            'title'          => 'Sertifikat',
            'name'           => (string) $intern->fullname,
            'role'           => (string) ($intern->internship_interest ?: 'Programmer'),
            'company'        => (string) ($intern->brand ?: 'Seven Inc.'),
            'duration'       => $durationText,
            'start_date'     => $startDateStr,
            'end_date'       => $endDateStr,
            'city'           => (string) ($intern->current_city ?: 'Yogyakarta'),

            // label & penandatangan
            'hr_label'       => 'HR Department',
            'owner_label'    => 'Owner ' . ($intern->brand ?: 'Seven Inc.'),
            'hr_name'        => 'Ari Setia Husbana',
            'owner_name'     => 'Rekario Danny',

        ];

        $safe = trim(preg_replace('/[^A-Za-z0-9_\- ]+/', '', (string) $intern->fullname)) ?: 'Pemagang';
        $downloadName = 'Sertifikat_' . Str::slug($safe, '_') . '.pdf';

        // Ganti view sesuai template yang ingin dipakai
        return $pdf->download('certificates.certmagangjogjacom', $data, $downloadName);
    }

    /**
     * PREVIEW HTML — certareakerjacom (opsional)
     */
    public function certificateAreaKerjaCom(IR $intern)
    {
        if ($intern->internship_status !== IR::STATUS_COMPLETED) {
            abort(403, 'Sertifikat hanya tersedia untuk pemagang yang sudah selesai.');
        }

        Carbon::setLocale('id');
        $start = $intern->start_date ? Carbon::parse($intern->start_date) : null;
        $end   = $intern->end_date   ? Carbon::parse($intern->end_date)   : null;

        $startDate = $start ? $start->translatedFormat('j F Y') : '';
        $endDate   = $end   ? $end->translatedFormat('j F Y')   : '';

        $durationText = 'beberapa bulan';
        if ($start && $end) {
            $months = round($start->diffInDays($end) / 30, 1);
            $durationText = str_replace('.', ',', (string)$months) . ' bulan';
        }

        // >>> embed aset jadi data URI supaya 100% ter-render
        $bg     = $this->dataUriPublic('images/bg_areakerja.png');
        $logo   = $this->dataUriPublic('images/logo_areakerja.png');
        $ttdHr  = $this->dataUriPublic('images/ttd_arisetiahusbana.png');
        $ttdDir = $this->dataUriPublic('images/ttd_pipitdamayanti.png');

        return view('certificates.certareakerjacom', [
            'title'        => 'SERTIFIKAT',
            'recipient'    => (string) $intern->fullname,
            'deptText'     => (string) $intern->internship_interest,
            'durationText' => $durationText,
            'startDate'    => $startDate,
            'endDate'      => $endDate,

            'hrRole'       => 'HR Departement',
            'hrName'       => 'Ari Setia Husbana',
            'dirRole'      => 'Direktur',
            'dirName'      => 'Pipit Damayanti',

            'bg'     => $bg,
            'logo'   => $logo,
            'ttdHr'  => $ttdHr,
            'ttdDir' => $ttdDir,
        ]);
    }

    /**
     * PDF DOWNLOAD — certareakerjacom
     */
    public function certificateAreaKerjaComPdf(IR $intern)
    {
        if ($intern->internship_status !== IR::STATUS_COMPLETED) {
            abort(403, 'Sertifikat hanya tersedia untuk pemagang yang sudah selesai.');
        }

        @set_time_limit(180);
        @ini_set('max_execution_time', '180');

        Carbon::setLocale('id');
        $start = $intern->start_date ? Carbon::parse($intern->start_date) : null;
        $end   = $intern->end_date   ? Carbon::parse($intern->end_date)   : null;

        $startDate = $start ? $start->translatedFormat('j F Y') : '';
        $endDate   = $end   ? $end->translatedFormat('j F Y')   : '';

        $durationText = 'beberapa bulan';
        if ($start && $end) {
            $months = round($start->diffInDays($end) / 30, 1);
            $durationText = str_replace('.', ',', (string)$months) . ' bulan';
        }

        // >>> data URI (base64)
        $bg     = $this->dataUriPublic('images/bg_areakerja.png');
        $logo   = $this->dataUriPublic('images/logo_areakerja.png');
        $ttdHr  = $this->dataUriPublic('images/ttd_arisetiahusbana.png');
        $ttdDir = $this->dataUriPublic('images/ttd_pipitdamayanti.png');

        $html = view('certificates.certareakerjacom', [
            'title'        => 'SERTIFIKAT',
            'recipient'    => (string) $intern->fullname,
            'deptText'     => (string) $intern->internship_interest,
            'durationText' => $durationText,
            'startDate'    => $startDate,
            'endDate'      => $endDate,

            'hrRole'       => 'HR Departement',
            'hrName'       => 'Ari Setia Husbana',
            'dirRole'      => 'Direktur',
            'dirName'      => 'Pipit Damayanti',

            'bg'     => $bg,
            'logo'   => $logo,
            'ttdHr'  => $ttdHr,
            'ttdDir' => $ttdDir,
        ])->render();

        // Fallback: set flag siap render (tanpa nunggu Google Fonts)
        $html .= <<<HTML
    <script>
    (function(){
    function imagesReady(){
        var imgs=[].slice.call(document.images||[]);
        if(!imgs.length) return Promise.resolve();
        return Promise.all(imgs.map(function(i){
        if(i.complete) return Promise.resolve();
        return new Promise(function(r){
            i.addEventListener('load', r, {once:true});
            i.addEventListener('error', r, {once:true});
        });
        }));
    }
    var timer=setTimeout(function(){window.__CERT_READY=true;}, 800);
    imagesReady().then(function(){ clearTimeout(timer); window.__CERT_READY=true; });
    })();
    </script>
    HTML;

        $safeName = trim(preg_replace('/[^A-Za-z0-9_\- ]+/', '', (string) $intern->fullname)) ?: 'Pemagang';
        $filename = 'Sertifikat_AreaKerja_' . Str::slug($safeName, '_') . '.pdf';
        $dir  = storage_path('app/public/certificates');
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $path = $dir . DIRECTORY_SEPARATOR . $filename;

        $bs = Browsershot::html($html)
            ->showBackground()
            ->margins(0, 0, 0, 0)
            ->setOption('printBackground', true)
            ->setOption('preferCSSPageSize', true)
            ->emulateMedia('print')
            ->windowSize(1123, 794)
            ->deviceScaleFactor(2)
            ->waitForFunction('document.readyState === "complete" || window.__CERT_READY === true')
            ->setOption('waitUntil', 'domcontentloaded')
            ->timeout(180);

        if ($chromePath = env('BROWSERSHOT_CHROME_PATH')) {
            $bs->setChromePath($chromePath);
        }
        // $bs->addChromiumArguments(['--no-sandbox','--disable-setuid-sandbox']); // bila perlu (Linux)

        $bs->savePdf($path);

        return response()->download($path, $filename, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    /**
     * S17.18D.2: Admin dapat mengoreksi jawaban dinamis sesuai schema snapshot pendaftaran.
     */
    public function updateCustomAnswers(Request $request, IR $intern)
    {
        $snapshot = $intern->formSnapshot;
        $targetYear = $intern->created_at ? (int) $intern->created_at->year : (int) now()->year;
        $schema = $snapshot ?: (\App\Models\FormSchema::where('year', $targetYear)->activePublished()->first() ?: \App\Models\FormSchema::activePublished()->first());

        if (!$schema) {
            return response()->json([
                'success' => false,
                'message' => 'Skema formulir pendaftaran tidak ditemukan.',
            ], 404);
        }

        $formBuilderService = app(\App\Services\FormBuilderService::class);
        $validation = $formBuilderService->validateCustomAnswers($schema, $request->input('answers', []), $intern);

        if (!$validation['is_valid']) {
            return response()->json([
                'success' => false,
                'errors'  => $validation['errors'],
            ], 422);
        }

        $formBuilderService->saveCustomAnswers($intern, $validation['cleaned'], $schema, auth()->user());

        return response()->json([
            'success' => true,
            'message' => 'Jawaban dinamis pendaftaran berhasil diperbarui.',
            'answers' => $intern->customAnswers()->pluck('answer_value', 'field_key'),
        ]);
    }
}
