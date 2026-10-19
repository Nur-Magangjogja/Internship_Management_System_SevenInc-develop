<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RekomendasiSetting;
use App\Models\InternExtra;
use App\Models\InternshipRegistration as IR;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class RekomendasiController extends Controller
{
    /** GET /admin/rekomendasi/editor */
    public function edit()
    {
        $config = new RekomendasiSetting([
            'company_name'         => 'SEVEN INC.',
            'company_address'      => 'Jl. Raya Janti, Gang Arjuna No. 59, Karangjambe, Banguntapan, Bantul, Yogyakarta',
            'company_city'         => 'Yogyakarta',
            'company_phone'        => '0274-4534571',
            'company_postal_code'  => '55198',
            'leader_name'          => 'Rekario Danny Sanjaya, S.Kom',
            'leader_title'         => 'CEO',
            'company_brand'        => 'Seven Inc (Magangjogja.com)',
            'body_template'        => RekomendasiSetting::defaultBodyTemplate(),
        ]);

        $brands = \App\Models\Brand::orderBy('name')->get();

        return view('admin.intern_extras.rekomendasi_editor', compact('config', 'brands'));
    }

    /**
     * GET /admin/rekomendasi/interns-by-brand?brand=XXX
     * API: ambil pemagang completed berdasarkan brand
     */
    public function getInternsByBrand(Request $request)
    {
        $brand = $request->query('brand');

        if (!$brand) {
            return response()->json(['interns' => []]);
        }

        $interns = IR::query()
            ->where('internship_status', IR::STATUS_COMPLETED)
            ->where('brand_id', $brand)
            ->select('id', 'fullname', 'student_id', 'study_program', 'institution_name', 'start_date', 'end_date', 'internship_interest')
            ->latest('id')
            ->get()
            ->map(fn ($r) => [
                'id'               => $r->id,
                'fullname'         => $r->fullname,
                'student_id'       => $r->student_id ?? '',
                'study_program'    => $r->study_program ?? '',
                'institution_name' => $r->institution_name ?? '',
                'start_date'       => $r->start_date ?? '',
                'end_date'         => $r->end_date ?? '',
                'internship_interest' => $r->internship_interest ?? '',
                'has_rekomendasi'  => InternExtra::where('intern_id', $r->id)
                    ->whereNotNull('rekomendasi_path')
                    ->exists(),
            ]);

        return response()->json(['interns' => $interns]);
    }

    /** POST /admin/rekomendasi/editor */
    public function update(Request $request)
    {
        // Fitur simpan ke DB dinonaktifkan karena tabel rekomendasi_settings dihapus.
        // Return back dengan error message jika user mencoba.
        return back()->with('error', 'Penyimpanan template global tidak didukung pada versi ini (tabel settings dihapus).');
    }



    /**
     * POST /admin/rekomendasi/generate-brand
     * Generate surat rekomendasi untuk banyak pemagang sekaligus (bulk).
     * PDF disimpan ke InternExtra masing-masing, TIDAK didownload.
     * Mengembalikan JSON { success, generated, failed, names }.
     */
    public function generateBulk(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'intern_ids'    => 'required|array|min:1',
            'intern_ids.*'  => 'integer|exists:internship_registrations,id',
            'brand_id'      => 'required|integer|exists:brands,id',
            'body_template' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'generated' => 0,
                'failed' => 0,
            ], 422);
        }

        $brand = \App\Models\Brand::findOrFail($request->brand_id);
        $bodyTemplate = $request->body_template ?? RekomendasiSetting::defaultBodyTemplate();

        // Resolve aset visual ke base64 (dari brand)
        $logoData  = null;
        if ($brand->logo && Storage::disk('public')->exists($brand->logo)) {
            $logoData = 'data:' . mime_content_type(storage_path('app/public/' . $brand->logo)) . ';base64,' . base64_encode(Storage::disk('public')->get($brand->logo));
        }

        $stampData = null;
        if ($brand->signature && Storage::disk('public')->exists($brand->signature)) {
            $stampData = 'data:' . mime_content_type(storage_path('app/public/' . $brand->signature)) . ';base64,' . base64_encode(Storage::disk('public')->get($brand->signature));
        }

        $interns = IR::whereIn('id', $request->intern_ids)
            ->where('internship_status', IR::STATUS_COMPLETED)
            ->get();

        if ($interns->isEmpty()) {
            return response()->json([
                'success'   => false,
                'message'   => 'Tidak ada pemagang valid (status selesai) yang dipilih.',
                'generated' => 0,
                'failed'    => 0,
                'names'     => [],
            ]);
        }

        Storage::disk('public')->makeDirectory('documents/rekomendasi');

        $generated = [];
        $failed    = [];

        Carbon::setLocale('id');

        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $romanMonth = $roman[(int)date('n')];
        $year = date('Y');

        foreach ($interns as $intern) {
            try {
                $startStr = $intern->start_date
                    ? Carbon::parse($intern->start_date)->isoFormat('MMMM Y')
                    : '-';
                $endStr = $intern->end_date
                    ? Carbon::parse($intern->end_date)->isoFormat('MMMM Y')
                    : '-';

                $durationStr = 'beberapa bulan';
                if ($intern->start_date && $intern->end_date) {
                    $months = (int) round(
                        Carbon::parse($intern->start_date)->diffInDays(Carbon::parse($intern->end_date)) / 30
                    );
                    $durationStr = $months . ' bulan';
                }

                $interest = $intern->internship_interest ?? '';
                $dbDivision = $intern->division ?? \App\Models\Division::where('name', $interest)
                    ->orWhere('slug', \Illuminate\Support\Str::slug($interest, '-'))
                    ->first();
                $divisionCode = $dbDivision?->code ?? 'UMUM';
                $divisionFullName = $dbDivision?->name ?? $intern->internship_interest ?? '-';

                $running      = str_pad((string) $intern->id, 3, '0', STR_PAD_LEFT);
                $brandCodeStr = strtoupper($brand->code ?? 'SVII');
                $letterNumber = "{$running}/SR/{$divisionCode}/SEVEN.{$brandCodeStr}/{$romanMonth}/{$year}";
                
                $letterDateStr = now()->isoFormat('D MMMM Y');

                $bodyText = $this->buildBodyText(
                    $bodyTemplate,
                    [
                        'nama'          => $intern->fullname,
                        'divisi'        => $divisionFullName,
                        'mulai'         => $startStr,
                        'selesai'       => $endStr,
                        'durasi'        => $durationStr,
                        'instansi'      => $intern->institution_name ?? '-',
                        'nim'           => $intern->student_id ?? '-',
                        'company_brand' => $brand->name,
                    ]
                );

                $html = view('admin.rekomendasi_letter', [
                    'companyName'          => $brand->name,
                    'companyAddress'       => $brand->company_address,
                    'leaderName'           => $brand->signatory_name,
                    'leaderTitle'          => $brand->signatory_position,
                    'letterNumber'         => $letterNumber,
                    'letterDateStr'        => $letterDateStr,
                    'participantName'      => $intern->fullname,
                    'participantId'        => $intern->student_id ?? '-',
                    'participantMajor'     => $intern->study_program ?? '-',
                    'participantInstitute' => $intern->institution_name ?? '-',
                    'bodyText'             => $bodyText,
                    'logoData'             => $logoData,
                    'stampData'            => $stampData,
                ])->render();

                /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
                $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
                $generatedDoc = $issuanceService->issueDocument(
                    intern: $intern,
                    documentType: 'recommendation',
                    options: [
                        'document_number' => $letterNumber,
                        'body_text'       => $bodyText,
                        'variables'       => [
                            'perusahaan.nama'               => $brand->name,
                            'perusahaan.alamat'             => $brand->company_address,
                            'dokumen.penandatangan_nama'    => $brand->signatory_name,
                            'dokumen.penandatangan_jabatan' => $brand->signatory_position,
                            'rekomendasi.isi'               => $bodyText,
                        ],
                    ],
                    actor: auth()->user()
                );

                $safeName = Str::slug($intern->fullname ?? 'pemagang', '-');
                $fileName = "rekomendasi-{$intern->id}-{$safeName}-" . now()->format('Ymd_His') . '-' . uniqid() . '.pdf';
                $relPath  = "documents/rekomendasi/{$fileName}";
                $fullGeneratedPath = Storage::disk('local')->path($generatedDoc->file_path);

                if (file_exists($fullGeneratedPath)) {
                    Storage::disk('public')->put($relPath, file_get_contents($fullGeneratedPath));
                }

                // Update InternExtra untuk kompatibilitas
                $extra = InternExtra::firstOrNew(['intern_id' => $intern->id]);
                $extra->letter_number        = $letterNumber;
                $extra->brand_id             = $brand->id;
                $extra->company_name         = $brand->name;
                $extra->company_address      = $brand->company_address;
                $extra->company_logo_path    = $brand->logo;
                $extra->signatory_name       = $brand->signatory_name;
                $extra->signatory_position   = $brand->signatory_position;
                $extra->signature_image_path = $brand->signature;
                $extra->rekomendasi_path     = $relPath;
                $extra->rekomendasi_url      = $generatedDoc->drive_web_view_link ?: asset('storage/' . $relPath);
                $extra->rekomendasi_granted_at = now();
                $extra->save();

                $generated[] = $intern->fullname;

            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Generate rekomendasi bulk gagal', [
                    'intern_id' => $intern->id,
                    'error'     => $e->getMessage(),
                ]);
                $failed[] = $intern->fullname;
            }
        }

        return response()->json([
            'success'   => count($generated) > 0,
            'message'   => count($generated) > 0
                ? count($generated) . ' surat rekomendasi berhasil digenerate.'
                : 'Semua surat gagal digenerate.',
            'generated' => count($generated),
            'failed'    => count($failed),
            'names'     => $generated,
            'failed_names' => $failed,
        ]);
    }

    /** GET /admin/rekomendasi/preview */
    public function preview(Request $request)
    {
        Carbon::setLocale('id');

        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $romanMonth = $roman[(int)date('n')];
        $year = date('Y');

        // Jika ada intern_id di query, load data pemagang asli
        $intern = null;
        if ($request->filled('intern_id')) {
            $intern = IR::find((int) $request->get('intern_id'));
        }

        $bodyTemplate = $request->get('body_template', RekomendasiSetting::defaultBodyTemplate());

        $brand = null;
        if ($request->filled('brand_id')) {
            $brand = \App\Models\Brand::find($request->get('brand_id'));
        } elseif ($intern && $intern->brand_id) {
            $brand = \App\Models\Brand::find($intern->brand_id);
        }

        if ($intern) {
            $participantName      = $intern->fullname;
            $participantId        = $intern->student_id ?? '-';
            $participantMajor     = $intern->study_program ?? '-';
            $participantInstitute = $intern->institution_name ?? '-';
            
            $interest = $intern->internship_interest ?? '';
            $dbDivision = $intern->division ?? \App\Models\Division::where('name', $interest)
                ->orWhere('slug', \Illuminate\Support\Str::slug($interest, '-'))
                ->first();
            $divisionCode = $dbDivision?->code ?? 'UMUM';
            $divisionFullName = $dbDivision?->name ?? $interest ?: '-';

            $startStr = $intern->start_date
                ? Carbon::parse($intern->start_date)->isoFormat('MMMM Y')
                : '-';
            $endStr = $intern->end_date
                ? Carbon::parse($intern->end_date)->isoFormat('MMMM Y')
                : '-';

            $durationStr = 'beberapa bulan';
            if ($intern->start_date && $intern->end_date) {
                $months = (int) round(
                    Carbon::parse($intern->start_date)->diffInDays(Carbon::parse($intern->end_date)) / 30
                );
                $durationStr = $months . ' bulan';
            }

            $running      = str_pad((string) $intern->id, 3, '0', STR_PAD_LEFT);
            $brandCodeStr = strtoupper($brand ? $brand->code : 'SVII');
            $letterNumber = "{$running}/SR/{$divisionCode}/SEVEN.{$brandCodeStr}/{$romanMonth}/{$year}";
        } else {
            // Dummy data jika belum ada pemagang dipilih
            $participantName      = '— Pilih pemagang untuk preview —';
            $participantId        = '-';
            $participantMajor     = '-';
            $participantInstitute = '-';
            $divisionCode         = 'UMUM';
            $divisionFullName     = '-';
            $startStr             = 'Bulan Tahun';
            $endStr               = 'Bulan Tahun';
            $durationStr          = '? bulan';
            $letterNumber         = '000/SR/UMUM/SEVEN.BRAND/' . $romanMonth . '/' . $year;
        }

        $letterDateStr = Carbon::now()->isoFormat('D MMMM Y');

        $bodyText = $this->buildBodyText($bodyTemplate, [
            'nama'          => $participantName,
            'divisi'        => $divisionFullName,
            'mulai'         => $startStr,
            'selesai'       => $endStr,
            'durasi'        => $durationStr,
            'instansi'      => $participantInstitute,
            'nim'           => $participantId,
            'company_brand' => $brand ? $brand->name : 'Seven Inc',
        ]);

        $logoData  = null;
        if ($brand && $brand->logo && Storage::disk('public')->exists($brand->logo)) {
            $logoData = 'data:' . mime_content_type(storage_path('app/public/' . $brand->logo)) . ';base64,' . base64_encode(Storage::disk('public')->get($brand->logo));
        }

        $stampData = null;
        if ($brand && $brand->signature && Storage::disk('public')->exists($brand->signature)) {
            $stampData = 'data:' . mime_content_type(storage_path('app/public/' . $brand->signature)) . ';base64,' . base64_encode(Storage::disk('public')->get($brand->signature));
        }

        return view('admin.rekomendasi_letter', [
            'companyName'         => $brand ? $brand->name : 'Seven Inc',
            'companyAddress'      => $brand ? $brand->company_address : '-',
            'leaderName'          => $brand ? $brand->signatory_name : '-',
            'leaderTitle'         => $brand ? $brand->signatory_position : '-',
            'letterNumber'        => $letterNumber,
            'letterDateStr'       => $letterDateStr,
            'participantName'     => $participantName,
            'participantId'       => $participantId,
            'participantMajor'    => $participantMajor,
            'participantInstitute'=> $participantInstitute,
            'bodyText'            => $bodyText,
            'logoData'            => $logoData,
            'stampData'           => $stampData,
        ]);
    }

    /**
     * POST /admin/rekomendasi/generate/{intern}
     * Generate PDF single + simpan ke InternExtra (dari halaman edit intern_extra).
     * Tidak download — redirect kembali dengan notif.
     */
    public function generate(Request $request, IR $intern)
    {
        if ($intern->internship_status !== IR::STATUS_COMPLETED) {
            return back()->with('error', 'Surat rekomendasi hanya dapat di-generate untuk pemagang yang sudah selesai.');
        }

        $request->validate([
            'brand_id'      => 'required|integer|exists:brands,id',
            'body_template' => 'nullable|string',
        ]);

        $brand = \App\Models\Brand::findOrFail($request->brand_id);
        $bodyTemplate = $request->body_template ?? RekomendasiSetting::defaultBodyTemplate();

        try {
            Carbon::setLocale('id');

            $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
            $romanMonth = $roman[(int)date('n')];
            $year = date('Y');

            $startStr = $intern->start_date
                ? Carbon::parse($intern->start_date)->isoFormat('MMMM Y')
                : '-';
            $endStr = $intern->end_date
                ? Carbon::parse($intern->end_date)->isoFormat('MMMM Y')
                : '-';

            $durationStr = 'beberapa bulan';
            if ($intern->start_date && $intern->end_date) {
                $months = (int) round(Carbon::parse($intern->start_date)->diffInDays(Carbon::parse($intern->end_date)) / 30);
                $durationStr = $months . ' bulan';
            }

            $interest = $intern->internship_interest ?? '';
            $dbDivision = $intern->division ?? \App\Models\Division::where('name', $interest)
                ->orWhere('slug', \Illuminate\Support\Str::slug($interest, '-'))
                ->first();
            $divisionCode = $dbDivision?->code ?? 'UMUM';
            $divisionFullName = $dbDivision?->name ?? $intern->internship_interest ?? '-';

            $running      = str_pad((string) $intern->id, 3, '0', STR_PAD_LEFT);
            $brandCodeStr = strtoupper($brand->code ?? 'SVII');
            $letterNumber = "{$running}/SR/{$divisionCode}/SEVEN.{$brandCodeStr}/{$romanMonth}/{$year}";
            
            $letterDateStr = now()->isoFormat('D MMMM Y');

            $bodyText = $this->buildBodyText(
                $bodyTemplate,
                [
                    'nama'          => $intern->fullname,
                    'divisi'        => $divisionFullName,
                    'mulai'         => $startStr,
                    'selesai'       => $endStr,
                    'durasi'        => $durationStr,
                    'instansi'      => $intern->institution_name ?? '-',
                    'nim'           => $intern->student_id ?? '-',
                    'company_brand' => $brand->name,
                ]
            );

            // Resolve aset visual ke base64 (dari brand)
            $logoData  = null;
            if ($brand->logo && Storage::disk('public')->exists($brand->logo)) {
                $logoData = 'data:' . mime_content_type(storage_path('app/public/' . $brand->logo)) . ';base64,' . base64_encode(Storage::disk('public')->get($brand->logo));
            }

            $stampData = null;
            if ($brand->signature && Storage::disk('public')->exists($brand->signature)) {
                $stampData = 'data:' . mime_content_type(storage_path('app/public/' . $brand->signature)) . ';base64,' . base64_encode(Storage::disk('public')->get($brand->signature));
            }

            $html = view('admin.rekomendasi_letter', [
                'companyName'          => $brand->name,
                'companyAddress'       => $brand->company_address,
                'leaderName'           => $brand->signatory_name,
                'leaderTitle'          => $brand->signatory_position,
                'letterNumber'         => $letterNumber,
                'letterDateStr'        => $letterDateStr,
                'participantName'      => $intern->fullname,
                'participantId'        => $intern->student_id ?? '-',
                'participantMajor'     => $intern->study_program ?? '-',
                'participantInstitute' => $intern->institution_name ?? '-',
                'bodyText'             => $bodyText,
                'logoData'             => $logoData,
                'stampData'            => $stampData,
            ])->render();

            // Simpan PDF
            $safeName = Str::slug($intern->fullname ?? 'pemagang', '-');
            $fileName = "rekomendasi-{$intern->id}-{$safeName}-" . now()->format('Ymd_His') . '-' . uniqid() . '.pdf';
            $relPath  = "documents/rekomendasi/{$fileName}";
            $fullPath = storage_path("app/public/{$relPath}");

            Storage::disk('public')->makeDirectory('documents/rekomendasi');

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHtml($html)
                ->setPaper('A4', 'portrait')
                ->setOptions([
                    'isRemoteEnabled'      => true,
                    'isHtml5ParserEnabled' => true,
                    'defaultPaperSize'     => 'A4',
                    'defaultFont'          => 'serif',
                    'dpi'                  => 96,
                ]);

            $pdfContents = $pdf->output();
            if ($pdfContents === false) {
                throw new \RuntimeException('Gagal menghasilkan PDF rekomendasi.');
            }

            if (!Storage::disk('public')->put($relPath, $pdfContents)) {
                throw new \RuntimeException("Gagal menyimpan file rekomendasi ke: {$relPath}");
            }

            if (!file_exists($fullPath)) {
                throw new \RuntimeException("PDF gagal disimpan ke: {$fullPath}");
            }

            // Update InternExtra
            $extra = InternExtra::firstOrNew(['intern_id' => $intern->id]);

            // Simpan snapshot data ke intern_extras
            $extra->letter_number        = $letterNumber;
            $extra->brand_id             = $brand->id;
            $extra->company_name         = $brand->name;
            $extra->company_address      = $brand->company_address;
            $extra->company_logo_path    = $brand->logo;
            $extra->signatory_name       = $brand->signatory_name;
            $extra->signatory_position   = $brand->signatory_position;
            $extra->signature_image_path = $brand->signature;

            if ($extra->rekomendasi_path && Storage::disk('public')->exists($extra->rekomendasi_path)) {
                Storage::disk('public')->delete($extra->rekomendasi_path);
            }

            $extra->intern_id = $intern->id;
            $extra->rekomendasi_path           = $relPath;
            $extra->rekomendasi_url            = asset('storage/' . $relPath);
            $extra->rekomendasi_granted_at     = now();
            $extra->save();

            // Tidak download — simpan saja ke InternExtra, pemagang bisa akses di halaman dokumen mereka
            return back()->with('success', "Surat rekomendasi untuk <strong>{$intern->fullname}</strong> berhasil digenerate dan sudah tersedia di halaman dokumen pemagang.");

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Generate rekomendasi gagal', [
                'intern_id' => $intern->id,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Gagal generate surat rekomendasi: ' . $e->getMessage());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function buildBodyText(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        return $template;
    }

    private function toDataUri(?string $path): ?string
    {
        if (!$path) return null;

        // Coba beberapa lokasi
        $candidates = [
            storage_path('app/public/' . $path),
            public_path($path),
        ];

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $mime = in_array($ext, ['jpg','jpeg']) ? 'image/jpeg' : 'image/png';
                return "data:{$mime};base64," . base64_encode(file_get_contents($file));
            }
        }
        return null;
    }
}
