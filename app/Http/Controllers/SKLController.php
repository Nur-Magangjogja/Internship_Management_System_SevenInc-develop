<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\InternshipRegistration as IR;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Spatie\Browsershot\Browsershot;
use App\Models\DocumentDownload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class SKLController extends Controller
{
    /**
     * Tampilkan form pengaturan SKL
     */

    /**
     * GET /admin/skl/interns-by-brand?brand=XXX
     * API: ambil pemagang completed yang belum punya SKL untuk brand tertentu
     */
    public function getInternsByBrand(Request $request)
    {
        $brand = $request->query('brand');

        if (!$brand) {
            return response()->json(['interns' => []]);
        }

        $alreadyHasSKL = []; // Disable filter since table is removed
        
        $interns = IR::query()
            ->with(['institution', 'studyProgramRel', 'brandRel', 'division'])
            ->where('internship_status', IR::STATUS_COMPLETED)
            ->where(function ($q) use ($brand) {
                if (is_numeric($brand)) {
                    $q->where('brand_id', $brand);
                }
                $q->orWhereHas('brandRel', function ($bq) use ($brand) {
                    $bq->where('code', $brand)
                       ->orWhere('name', $brand)
                       ->orWhereRaw('LOWER(name) = ?', [strtolower(trim($brand))]);
                });
            })
            ->select('id', 'user_id', 'brand_id', 'institution_id', 'study_program_id', 'division_id', 'fullname', 'student_id', 'start_date', 'end_date')
            ->latest('id')
            ->get()
            ->map(fn ($r) => [
                'id'                  => $r->id,
                'fullname'            => $r->fullname,
                'student_id'          => $r->student_id ?? '',
                'study_program'       => $r->study_program ?? '',
                'institution_name'    => $r->institution_name ?? '',
                'start_date'          => $r->start_date ?? '',
                'end_date'            => $r->end_date ?? '',
                'internship_interest' => $r->internship_interest ?? '',
            ]);

        return response()->json(['interns' => $interns]);
    }

    /**
     * POST /admin/skl/generate-brand
     * Generate SKL untuk setiap pemagang yang dipilih (1 PDF per pemagang), dikemas ZIP jika lebih dari 1
     */
    public function generateForBrand(Request $request)
    {
        $validated = $request->validate([
            'intern_ids'              => ['required', 'array', 'min:1'],
            'intern_ids.*'            => ['integer', 'exists:internship_registrations,id'],
            'company_name'            => ['required', 'string', 'max:100'],
            'company_address'         => ['required', 'string', 'max:500'],
            'company_city'            => ['required', 'string', 'max:100'],
            'leader_name'             => ['required', 'string', 'max:150'],
            'leader_title'            => ['required', 'string', 'max:100'],
            'activity_description'    => ['nullable', 'string', 'max:2000'],
            'participant_achievement' => ['nullable', 'string', 'max:2000'],
            'logo'                    => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
            'stamp'                   => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ]);

        $interns = IR::with(['brandRel', 'division', 'institution', 'studyProgramRel'])
            ->whereIn('id', $validated['intern_ids'])
            ->where('internship_status', IR::STATUS_COMPLETED)
            ->get();

        if ($interns->isEmpty()) {
            return back()->with('error', 'Data pemagang tidak ditemukan atau belum berstatus Selesai.');
        }

        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->storeAs('images/logos', 'logo_' . Str::slug($validated['company_name']) . '_' . time() . '.png', 'public');
        }
        $stampPath = null;
        if ($request->hasFile('stamp')) {
            $stampPath = $request->file('stamp')->storeAs('images/signature', 'ttd_' . Str::slug($validated['company_name']) . '_' . time() . '.png', 'public');
        }

        $fullDir = public_path('storage/documents/skl');
        if (!is_dir($fullDir)) {
            mkdir($fullDir, 0777, true);
        }

        $generatedFiles = [];
        $errors         = [];

        Carbon::setLocale('id');

        /** @var \App\Services\DocumentGeneratorService $docService */
        $docService = app(\App\Services\DocumentGeneratorService::class);

        foreach ($interns as $intern) {
            try {
                $participantName      = $intern->fullname ?? '-';
                $participantId        = $intern->student_id ?? '-';
                $participantMajor     = $intern->study_program ?? '-';
                $participantInstitute = $intern->institution_name ?? '-';
                
                $interest = $intern->internship_interest ?? '';
                $dbDivision = \App\Models\Division::where('name', $interest)
                    ->orWhere('slug', \Illuminate\Support\Str::slug($interest, '-'))
                    ->first();
                $divisionName = $dbDivision?->code ?? 'UMUM';

                // Gunakan brand pemagang sebagai nama perusahaan di surat
                $brandData = $intern->brandRel ?? \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower(trim((string)$intern->brand))])
                    ->orWhere('code', $intern->brand)
                    ->first();
                $companyName = $intern->brand ?: ($brandData->name ?? 'Seven Inc');
                $companyAddress = $brandData?->company_address ?? 'Jl. Raya Janti, Gang Arjuna No. 59, Karangjambe, Banguntapan, Bantul, Yogyakarta';

                $startStr      = $intern->start_date ? Carbon::parse($intern->start_date)->isoFormat('D MMMM Y') : '-';
                $endStr        = $intern->end_date   ? Carbon::parse($intern->end_date)->isoFormat('D MMMM Y')   : '-';
                $letterDateStr = now()->translatedFormat('d F Y');
                
                $letterNumber = $docService->generateUniqueSklNumber($intern, $brandData);

                // Simpan atau update data SklDocument
                \App\Models\SklDocument::updateOrCreate(
                    ['intern_id' => $intern->id],
                    [
                        'skl_number'           => $letterNumber,
                        'company_name'         => $validated['company_name'] ?? $companyName,
                        'company_logo_path'    => $logoPath ?? $brandData?->logo ?? 'images/logos/logo_seveninc.png',
                        'signatory_name'       => $validated['leader_name'] ?? $brandData?->signatory_name ?? 'Ari Setia Husbana',
                        'signatory_position'   => $validated['leader_title'] ?? $brandData?->signatory_position ?? 'HRD',
                        'signature_image_path' => $stampPath ?? $brandData?->signature ?? 'images/signature/ttd_arisetiahusbana.png',
                    ]
                );

                /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
                $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
                $activeTemplate = $issuanceService->resolveActiveTemplate('skl', failClosed: false);
                $issued = null;
                if ($activeTemplate && $activeTemplate->exists) {
                    try {
                        $issued = $issuanceService->issueDocument(
                            intern: $intern,
                            documentType: 'skl',
                            options: [
                                'document_number' => $letterNumber,
                                'template_id'     => $activeTemplate->id,
                            ],
                            actor: $request->user()
                        );
                    } catch (\Throwable $eIssue) {
                        Log::warning("Gagal issue SKL template di batch: " . $eIssue->getMessage());
                    }
                }

                if (!$issued) {
                    try {
                        $pdfData = [
                            'companyName'            => $validated['company_name'] ?? $companyName,
                            'companyAddress'         => $companyAddress,
                            'companyCity'            => $validated['company_city'] ?? 'Yogyakarta',
                            'leaderName'             => $validated['leader_name'] ?? $brandData?->signatory_name ?? 'Ari Setia Husbana',
                            'leaderTitle'            => $validated['leader_title'] ?? $brandData?->signatory_position ?? 'HRD',
                            'letterNumber'           => $letterNumber,
                            'logoData'               => $this->resolveBase64Image($request, 'logo', $logoPath ?? $brandData?->logo, 'images/logos/logo_seveninc.png'),
                            'stampData'              => $this->resolveBase64Image($request, 'stamp', $stampPath ?? $brandData?->stamp, 'images/mou/stamp-sample.png'),
                            'sigData'                => $this->resolveBase64Image($request, 'sig', $brandData?->signature, 'images/signature/ttd_arisetiahusbana.png'),
                            'participantName'        => $participantName,
                            'participantId'          => $participantId,
                            'participantMajor'       => $participantMajor,
                            'participantInstitute'   => $participantInstitute,
                            'divisionName'           => $divisionName,
                            'startStr'               => $startStr,
                            'endStr'                 => $endStr,
                            'letterDateStr'          => $letterDateStr,
                            'activityDescription'    => $validated['activity_description'] ?? 'Telah menyelesaikan seluruh rangkaian kegiatan program magang.',
                            'participantAchievement' => $validated['participant_achievement'] ?? 'Sangat Baik',
                        ];
                        $html = view('user.skl', $pdfData)->render();
                        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
                        $pdfBinary = $pdf->output();

                        $pubService = app(\App\Services\DocumentPublishingService::class);
                        $pubService->publishInternDocument(
                            intern: $intern,
                            documentType: 'skl',
                            customDocNumber: $letterNumber,
                            actor: $request->user(),
                            pdfBinary: $pdfBinary,
                            template: null
                        );
                    } catch (\Throwable $eFallback) {
                        Log::warning("Gagal issue fallback SKL: " . $eFallback->getMessage());
                    }
                }

                $generatedFiles[$intern->id] = [
                    'fullname' => $participantName,
                    'path'     => '', // Removed physical path
                    'filename' => "SKL_{$safeName}.pdf",
                ];


            } catch (\Throwable $e) {
                Log::error('Gagal generate SKL (brand)', ['err' => $e->getMessage(), 'intern_id' => $intern->id]);
                $errors[] = $intern->fullname;
            }
        }

        if (empty($generatedFiles)) {
            $errorMsg = 'Gagal membuat SKL untuk semua pemagang.';
            if (!empty($errors)) {
                $errorMsg .= ' Gagal: ' . implode(', ', $errors);
            }
            // Cek apakah AJAX request
            if (request()->header('X-Requested-With') === 'XMLHttpRequest' || request()->wantsJson()) {
                return response()->json(['success' => false, 'message' => $errorMsg], 422);
            }
            return back()->with('error', $errorMsg);
        }

        $successCount = count($generatedFiles);
        $names = collect($generatedFiles)->pluck('fullname')->toArray();
        $successMsg = "✅ SKL untuk {$successCount} pemagang berhasil dibuat dan tersedia untuk diunduh.";
        $details = array_map(fn($n) => "SKL {$n} berhasil dibuat", $names);

        if (!empty($errors)) {
            $details = array_merge($details, array_map(fn($n) => "❌ Gagal: {$n}", $errors));
        }

        // Cek apakah AJAX request → return JSON (tidak download)
        if (request()->header('X-Requested-With') === 'XMLHttpRequest' || request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $successMsg,
                'details' => $details,
                'count'   => $successCount,
            ]);
        }

        // Fallback non-AJAX (tidak harusnya tercapai dari UI baru): redirect dengan notif
        return redirect()->route('admin.skl.editor')->with('success', $successMsg);
    }

    /**
     * Resolve gambar: dari upload baru → dari path tersimpan → dari fallback default
     */
    protected function resolveBase64Image(Request $request, string $inputName, ?string $savedPath, string $fallbackRelative): ?string
    {
        if ($request->hasFile($inputName) && $request->file($inputName)->isValid()) {
            $file = $request->file($inputName);
            $mime = $file->getMimeType();
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file->getRealPath()));
        }

        $candidates = array_filter([
            $savedPath,
            $fallbackRelative,
            'images/mou/seven-inc-logo.png',
            'images/mou/signature-sample.png',
            'images/mou/stamp-sample.png',
            'images/logos/logo_seveninc.png',
            'images/signature/ttd_arisetiahusbana.png',
        ]);

        foreach ($candidates as $cand) {
            if (empty($cand)) continue;
            if (str_starts_with($cand, 'data:image/')) return $cand;
            $cleanCand = ltrim($cand, '/');
            if (str_starts_with($cleanCand, 'storage/')) {
                $cleanCand = substr($cleanCand, 8);
            }
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($cleanCand)) {
                $content = \Illuminate\Support\Facades\Storage::disk('public')->get($cleanCand);
                $mime = \Illuminate\Support\Facades\Storage::disk('public')->mimeType($cleanCand) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode($content);
            }
            if (file_exists($cand) && is_file($cand)) {
                $mime = mime_content_type($cand) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($cand));
            }
            $inPub = public_path(ltrim($cand, '/'));
            if (file_exists($inPub) && is_file($inPub)) {
                $mime = mime_content_type($inPub) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($inPub));
            }
            $inStorage = storage_path('app/public/' . ltrim($cand, '/'));
            if (file_exists($inStorage) && is_file($inStorage)) {
                $mime = mime_content_type($inStorage) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($inStorage));
            }
        }

        return null;
    }

    /**
     * Update data SKL
     */

    /**
     * Preview SKL berdasarkan data dari database
     */
    public function preview(Request $request)
    {
        // Company block (boleh override dari query agar realtime di iframe)
        $companyName    = $request->get('company_name',    'Seven Inc');
        $companyAddress = $request->get('company_address', 'Jl. Raya Janti Gg. Harjuna No.59, Jaranan, Karangjambe, Kec. Banguntapan, Kabupaten Bantul, Daerah Istimewa Yogyakarta 55198');
        $companyCity    = $request->get('company_city',    'Yogyakarta');
        $leaderName     = $request->get('leader_name',     'Nama Pimpinan / HRD');
        $leaderTitle    = $request->get('leader_title',    'Manajer HRD');

        // Dummy peserta untuk preview
        $participantName      = $request->get('participant_name', 'Nama Pemagang (Preview)');
        $participantId        = $request->get('participant_id', '1234567890 (Preview)');
        $participantMajor     = $request->get('participant_major', 'Teknik Informatika (Preview)');
        $participantInstitute = $request->get('participant_institute', 'Universitas Contoh (Preview)');
        $divisionName         = $request->get('division_name', 'Divisi Teknologi (Preview)');

        // Periode (boleh override)
        $startAt  = $request->get('start_date', Carbon::now()->subMonths(1)->format('Y-m-d'));
        $endAt    = $request->get('end_date',   Carbon::now()->format('Y-m-d'));
        $startStr = Carbon::parse($startAt)->isoFormat('D MMMM Y');
        $endStr   = Carbon::parse($endAt)->isoFormat('D MMMM Y');

        // Letter meta
        $letterDateStr = Carbon::parse($endAt)->isoFormat('D MMMM Y');
        $letterNumber  = 'SKL/'.Carbon::parse($endAt)->format('Y').'/DEMO';

        // Assets
        $logoData  = $this->resolveBase64Image($request, 'logo',  null, 'images/mou/seven-inc-logo.png');
        $stampData = $this->resolveBase64Image($request, 'stamp', null, 'images/mou/stamp-sample.png');
        $sigData   = $this->resolveBase64Image($request, 'sig',   null, 'images/mou/signature-sample.png');

        // Mendapatkan data dari request atau menggunakan default value
        $activityDescription = $request->get('activity_description', '');
        $participantAchievement = $request->get('participant_achievement', '');

        return view('user.skl', compact(
            'companyName','companyAddress','companyCity','leaderName','leaderTitle',
            'letterNumber','logoData','stampData','sigData',
            'participantName','participantId','participantMajor','participantInstitute','divisionName',
            'startStr','endStr','letterDateStr','activityDescription', 'participantAchievement'
        ));
    }

    /**
     * GET /admin/skl/generate/{intern}
     * Form review sebelum generate SKL
     */
    public function review(Request $request, $id)
    {
        $intern = IR::with(['brandRel', 'division', 'institution', 'studyProgramRel', 'user'])->findOrFail($id);

        return view('admin.documents.skl_review', compact('intern'));
    }

    /**
     * Konten raw HTML SKL untuk iframe pratinjau.
     * GET /admin/skl/raw/{intern}
     */
    public function rawPreview(Request $request, $id)
    {
        $intern = IR::with(['brandRel', 'division', 'institution', 'studyProgramRel', 'user'])->findOrFail($id);

        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
        $template = $issuanceService->resolveActiveTemplate('skl', failClosed: false);

        if ($template && $template->exists) {
            $preview = $issuanceService->previewDocument($intern, 'skl', ['template_id' => $template->id]);
            return response($preview['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $brandCode = $intern->brandRel?->code ?? $intern->brand;
        $brandData = $intern->brandRel ?? \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower(trim((string)$brandCode))])
            ->orWhere('code', $brandCode)
            ->first();

        $companyName    = $brandData->name ?? $brandCode ?? 'Seven Inc';
        $companyAddress = $brandData?->company_address ?? 'Jl. Raya Janti Gg. Harjuna No.59, Jaranan, Karangjambe, Kec. Banguntapan, Kabupaten Bantul, Daerah Istimewa Yogyakarta 55198';
        $companyCity    = 'Yogyakarta';
        $leaderName     = $brandData->signatory_name ?? 'Ari Setia Husbana';
        $leaderTitle    = $brandData->signatory_position ?? 'Manajer HRD';

        $participantName      = $intern->fullname;
        $participantId        = $intern->student_id ?? '-';
        $participantMajor     = $intern->study_program ?? '-';
        $participantInstitute = $intern->institution_name ?? '-';
        $divisionName         = $intern->division?->name ?? $intern->internship_interest ?? '-';

        $startAt  = $intern->start_date ? Carbon::parse($intern->start_date)->format('Y-m-d') : Carbon::now()->subMonths(1)->format('Y-m-d');
        $endAt    = $intern->end_date ? Carbon::parse($intern->end_date)->format('Y-m-d') : Carbon::now()->format('Y-m-d');
        $startStr = Carbon::parse($startAt)->isoFormat('D MMMM Y');
        $endStr   = Carbon::parse($endAt)->isoFormat('D MMMM Y');

        $letterDateStr = Carbon::parse($endAt)->isoFormat('D MMMM Y');
        $letterNumber  = 'SKL/' . Carbon::parse($endAt)->format('Y') . '/' . str_pad($intern->id, 3, '0', STR_PAD_LEFT);

        $logoData  = $this->resolveBase64Image(new Request(), 'logo',  $brandData?->logo,      'images/mou/seven-inc-logo.png');
        $stampData = $this->resolveBase64Image(new Request(), 'stamp', $brandData?->stamp,     'images/mou/stamp-sample.png');
        $sigData   = $this->resolveBase64Image(new Request(), 'sig',   $brandData?->signature, 'images/mou/signature-sample.png');

        $activityDescription = 'Telah menyelesaikan seluruh rangkaian kegiatan program magang sesuai dengan ketentuan yang berlaku.';
        $participantAchievement = 'Sangat Baik';

        return view('user.skl', compact(
            'companyName','companyAddress','companyCity','leaderName','leaderTitle',
            'letterNumber','logoData','stampData','sigData',
            'participantName','participantId','participantMajor','participantInstitute','divisionName',
            'startStr','endStr','letterDateStr','activityDescription', 'participantAchievement'
        ));
    }

    /**
     * POST /admin/skl/regenerate/{intern}
     * Render ulang SKL (force refresh) atau terbitkan versi revisi baru resmi.
     */
    public function regenerate(Request $request, $id)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'super_admin', 'hr_staff'], true)) {
            abort(403, 'Hanya Admin atau HR Staff yang berwenang merevisi dokumen.');
        }

        $intern = IR::with(['brandRel', 'division', 'institution', 'studyProgramRel', 'user'])->findOrFail($id);

        if ($intern->internship_status !== 'completed') {
            $msg = 'SKL hanya dapat diproses setelah status pemagang Selesai (completed).';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        $isRevision = $request->boolean('is_revision');

        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
        $activeTemplate = $issuanceService->resolveActiveTemplate('skl', failClosed: false);

        try {
            $issued = $issuanceService->issueDocument(
                intern: $intern,
                documentType: 'skl',
                options: [
                    'template_id'   => $activeTemplate?->id,
                    'force_refresh' => true,
                    'is_revision'   => $isRevision,
                ],
                actor: $user
            );

            $msg = $isRevision
                ? "Revisi resmi SKL berhasil diterbitkan (v{$issued->template_version}, Nomor: {$issued->document_number}) dan disinkronkan ke Google Drive."
                : "Dokumen SKL berhasil dirender ulang dan disinkronkan ke Google Drive.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'ok'              => true,
                    'message'         => $msg,
                    'document_number' => $issued->document_number,
                    'download_url'    => route('admin.skl.download.for_user', ['user' => $intern->user_id ?: $user->id]) . '?intern_id=' . $intern->id . '&force_refresh=1',
                ]);
            }

            return redirect()->route('skl.review', $intern->id)->with('success', $msg);
        } catch (\Throwable $e) {
            $err = 'Gagal memperbarui SKL: ' . $e->getMessage();
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => $err], 500);
            }
            return back()->with('error', $err);
        }
    }

    /**
     * GET /admin/skl/download/{user} — download SKL dari Data SKL (tetap ada)
     */
    public function download(Request $request, $userId = null)
    {
        $authUser = auth()->user();
        $targetUser = $authUser;

        // Support route param {user} dari admin route
        $resolvedUserId = $userId ?? $request->get('user_id');

        /** @var \App\Services\DocumentGeneratorService $docService */
        $docService = app(\App\Services\DocumentGeneratorService::class);
        $targetUser = $docService->resolveTargetUser($authUser, $resolvedUserId ? (int)$resolvedUserId : null);

        // Jika staf mengunduh untuk user lain, catat log audit
        if ($targetUser->id !== $authUser->id) {
            $docService->logDocumentAccess('skl', $targetUser, $authUser, 'downloaded');
        }

        // Ambil data magang (multi-period aware: utamakan completed internship)
        $lifecycle     = app(\App\Services\RegistrationLifecycleService::class);
        $specificRegId = $request->query('registration_id') ?? $request->get('intern_id');
        $ir            = $lifecycle->resolveRegistrationForDocuments($targetUser, $specificRegId ? (int) $specificRegId : null);

        if (!$ir && $request->get('intern_id')) {
            $ir = IR::find($request->get('intern_id'));
        }

        if (!$ir || $ir->internship_status !== 'completed') {
            abort(403, 'SKL hanya dapat diunduh setelah status magang completed.');
        }

        $safeName = preg_replace('/[^a-z0-9\-_]+/i', '_', $ir->fullname ?? $targetUser->name);
        $fileName = "SKL_{$safeName}.pdf";

        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
        $activeTemplate = $issuanceService->resolveActiveTemplate('skl', failClosed: false);

        if ($activeTemplate && $activeTemplate->exists) {
            $genDoc = \App\Models\GeneratedDocument::select(['id', 'file_path', 'file_name'])
                ->where('internship_registration_id', $ir->id)
                ->where('document_type', 'skl')
                ->latest('id')
                ->first();

            // Cek apakah berkas fisik yang valid dan berukuran penuh (> 260KB) sudah tersedia (abaikan jika force_refresh)
            if (!$request->boolean('force_refresh') && $genDoc && Storage::disk('local')->exists($genDoc->file_path)) {
                $fullPath = Storage::disk('local')->path($genDoc->file_path);
                if (filesize($fullPath) > 260000) {
                    return response()->download($fullPath, $fileName, ['Content-Type' => 'application/pdf']);
                }
            }

            // Jika belum ada atau berkas lama belum memuat gambar, render PDF dari template aktif
            try {
                $context = $issuanceService->buildContext($ir, 'skl', ['template_id' => $activeTemplate->id], isLiveIssuance: true);
                $pdfBinary = \App\Services\DocumentTemplates\DocumentTemplateRenderer::renderPdfBinary($activeTemplate, $context['variables'], isLiveIssuance: true);

                if ($genDoc && !empty($genDoc->file_path)) {
                    Storage::disk('local')->put($genDoc->file_path, $pdfBinary);
                    $fullPath = Storage::disk('local')->path($genDoc->file_path);
                    return response()->download($fullPath, $fileName, ['Content-Type' => 'application/pdf']);
                }

                $issued = $issuanceService->issueDocument(
                    intern: $ir,
                    documentType: 'skl',
                    options: ['template_id' => $activeTemplate->id],
                    actor: $authUser
                );

                if ($issued && Storage::disk('local')->exists($issued->file_path)) {
                    Storage::disk('local')->put($issued->file_path, $pdfBinary);
                    $fullPath = Storage::disk('local')->path($issued->file_path);
                    return response()->download($fullPath, $fileName, ['Content-Type' => 'application/pdf']);
                }
            } catch (\Throwable $e) {
                Log::warning("Gagal issue SKL: " . $e->getMessage() . " — mencoba render template langsung");
                try {
                    $context = $issuanceService->buildContext($ir, 'skl', ['template_id' => $activeTemplate->id], isLiveIssuance: true);
                    $pdfBinary = \App\Services\DocumentTemplates\DocumentTemplateRenderer::renderPdfBinary($activeTemplate, $context['variables'], isLiveIssuance: true);

                    $tmpPath = storage_path('app/tmp/' . $fileName);
                    if (!is_dir(dirname($tmpPath))) {
                        mkdir(dirname($tmpPath), 0775, true);
                    }
                    file_put_contents($tmpPath, $pdfBinary);

                    if (file_exists($tmpPath) && filesize($tmpPath) > 0) {
                        try {
                            $pubService = app(\App\Services\DocumentPublishingService::class);
                            $pubService->publishInternDocument(
                                intern: $ir,
                                documentType: 'skl',
                                customDocNumber: $context['document_number'] ?? null,
                                actor: $authUser,
                                pdfBinary: file_get_contents($tmpPath),
                                template: $activeTemplate
                            );
                        } catch (\Throwable $ePub) {
                            \Illuminate\Support\Facades\Log::warning("Gagal publish SKL template fallback ke Drive: " . $ePub->getMessage());
                        }
                    }

                    return response()->download($tmpPath, $fileName, ['Content-Type' => 'application/pdf'])
                        ->deleteFileAfterSend(true);
                } catch (\Throwable $errRender) {
                    Log::error("Gagal fallback render SKL: " . $errRender->getMessage());
                }
            }
        }

        // Cari rekam SKL di database
        $sklRecord = \App\Models\SklDocument::where('intern_id', $ir->id)->latest()->first();
        if (!$sklRecord) {
            return back()->with('error', 'SKL belum tersedia. Hubungi admin untuk mendapatkan SKL Anda.');
        }

        $brandData = \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower(trim($ir->brand))])
            ->orWhere('code', $ir->brand)
            ->first();

        // Siapkan data untuk view
        $companyName    = $sklRecord->company_name ?? $ir->brand ?? $brandData?->name ?? 'Seven Inc';
        $companyAddress = $brandData?->company_address ?? 'Jl. Raya Janti, Gang Arjuna No. 59, Karangjambe, Banguntapan, Bantul, Yogyakarta';
        
        $interest = $ir->internship_interest ?? '';
        $dbDivision = \App\Models\Division::where('name', $interest)
            ->orWhere('slug', \Illuminate\Support\Str::slug($interest, '-'))
            ->first();
        $divisionName = $dbDivision?->code ?? 'UMUM';

        Carbon::setLocale('id');
        $startStr      = $ir->start_date ? Carbon::parse($ir->start_date)->isoFormat('D MMMM Y') : '-';
        $endStr        = $ir->end_date   ? Carbon::parse($ir->end_date)->isoFormat('D MMMM Y')   : '-';
        $letterDateStr = $ir->end_date   ? Carbon::parse($ir->end_date)->isoFormat('D MMMM Y')   : now()->translatedFormat('d F Y');

        $logoData  = $this->resolveBase64Image(new Request(), 'logo',  $sklRecord->company_logo_path,  'images/mou/seven-inc-logo.png');
        $stampData = $this->resolveBase64Image(new Request(), 'stamp', $brandData?->stamp,            'images/mou/stamp-sample.png');
        $sigData   = $this->resolveBase64Image(new Request(), 'sig',   $sklRecord->signature_image_path, 'images/mou/signature-sample.png');

        $data = [
            'companyName'            => $companyName,
            'companyAddress'         => $companyAddress,
            'companyCity'            => 'Yogyakarta',
            'leaderName'             => $sklRecord->signatory_name ?? $brandData?->signatory_name ?? 'Ari Setia Husbana',
            'leaderTitle'            => $sklRecord->signatory_position ?? $brandData?->signatory_position ?? 'HRD',
            'letterNumber'           => $sklRecord->skl_number,
            'logoData'               => $logoData,
            'stampData'              => $stampData,
            'sigData'                => $sigData,
            'participantName'        => $ir->fullname ?? '-',
            'participantId'          => $ir->student_id ?? '-',
            'participantMajor'       => $ir->study_program ?? '-',
            'participantInstitute'   => $ir->institution_name ?? '-',
            'divisionName'           => $divisionName,
            'startStr'               => $startStr,
            'endStr'                 => $endStr,
            'letterDateStr'          => $letterDateStr,
            'activityDescription'    => '',
            'participantAchievement' => '',
        ];

        $html = view('user.skl', $data)->render();

        $tmpPath = storage_path('app/tmp/' . $fileName);
        if (!is_dir(dirname($tmpPath))) {
            mkdir(dirname($tmpPath), 0775, true);
        }

        try {
            if (app()->environment('testing')) {
                if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                    \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->save($tmpPath);
                } else {
                    file_put_contents($tmpPath, '%PDF-1.4 test document');
                }
            } else {
                Browsershot::html($html)
                    ->setOption('no-sandbox', true)
                    ->setOption('args', ['--disable-setuid-sandbox'])
                    ->emulateMedia('print')
                    ->format('A4')
                    ->margins(10, 10, 10, 10)
                    ->showBackground()
                    ->waitUntilNetworkIdle()
                    ->timeout(180)
                    ->savePdf($tmpPath);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Browsershot generation failed, falling back to DomPDF: ' . $e->getMessage());
            if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->save($tmpPath);
            } else {
                file_put_contents($tmpPath, '%PDF-1.4 fallback');
            }
        }

        if (file_exists($tmpPath) && filesize($tmpPath) > 0) {
            try {
                $pubService = app(\App\Services\DocumentPublishingService::class);
                $pubService->publishInternDocument(
                    intern: $ir,
                    documentType: 'skl',
                    customDocNumber: $sklRecord->skl_number,
                    actor: $authUser,
                    pdfBinary: file_get_contents($tmpPath),
                    template: null
                );
            } catch (\Throwable $ePub) {
                \Illuminate\Support\Facades\Log::warning("Gagal publish SKL fallback ke Drive: " . $ePub->getMessage());
            }
        }

        return response()->download($tmpPath, $fileName, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }
}
