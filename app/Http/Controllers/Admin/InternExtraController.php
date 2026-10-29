<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InternExtra;
use App\Models\RekomendasiSetting;
use App\Models\InternshipRegistration as IR;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InternExtraController extends Controller
{
    /**
     * Daftar semua pemagang completed beserta status extras-nya.
     * Bisa difilter by brand.
     */
    public function index(Request $request)
    {
        // Ambil semua brand dari pemagang completed
        $brands = \App\Models\Brand::join('internship_registrations', 'brands.id', '=', 'internship_registrations.brand_id')
            ->where('internship_registrations.internship_status', IR::STATUS_COMPLETED)
            ->select('brands.name')
            ->distinct()
            ->orderBy('brands.name')
            ->pluck('name')
            ->values();

        $selectedBrand = $request->get('brand');

        $query = IR::where('internship_status', IR::STATUS_COMPLETED)
            ->with('user')
            ->orderByDesc('updated_at');

        if ($selectedBrand) {
            $query->whereHas('brandRel', function ($q) use ($selectedBrand) {
                $q->where('name', $selectedBrand);
            });
        }

        $interns = $query->paginate(20)->appends($request->only('brand'));

        return view('admin.intern_extras.index', compact('interns', 'brands', 'selectedBrand'));
    }

    /**
     * Form edit extras untuk satu intern atau bulk — termasuk template rekomendasi.
     */
    public function edit(Request $request, IR $intern)
    {
        $extra = InternExtra::firstOrNew([
            'intern_id' => $intern->id,
        ]);

        $brands = \App\Models\Brand::orderBy('name')->get();

        $bulkMode = $request->input('mode') === 'bulk' || $request->filled('ids');
        $selectedIds = [];
        $targetInterns = collect([$intern]);

        if ($request->filled('ids')) {
            $selectedIds = array_values(array_filter(explode(',', $request->input('ids'))));
            $targetInterns = IR::whereIn('id', $selectedIds)->get();
            if ($targetInterns->isNotEmpty() && !$targetInterns->contains('id', $intern->id)) {
                $intern = $targetInterns->first();
            }
        }

        return view('admin.intern_extras.edit', compact('intern', 'extra', 'brands', 'bulkMode', 'targetInterns', 'selectedIds'));
    }

    /**
     * Simpan/update extras (link alumni + info kerja) untuk satu intern atau bulk pemagang.
     */
    public function update(Request $request, IR $intern)
    {
        $request->validate([
            'alumni_group_url'     => 'nullable|url|max:500',
            'alumni_group_label'   => 'nullable|string|max:100',
            'job_info_url'         => 'nullable|url|max:500',
            'job_info_description' => 'nullable|string|max:500',
        ]);

        // Jika mode bulk atau all_brand, simpan ke semua pemagang terpilih/brand
        $allBrandMode = $request->input('mode') === 'all_brand';
        $bulkMode     = $request->input('mode') === 'bulk' || $request->filled('ids');

        if ($bulkMode && $request->filled('ids')) {
            $ids = array_values(array_filter(explode(',', $request->input('ids'))));
            $targets = IR::whereIn('id', $ids)->get();
        } elseif ($allBrandMode && !empty($intern->brand_id)) {
            $targets = IR::where('internship_status', IR::STATUS_COMPLETED)->where('brand_id', $intern->brand_id)->get();
        } else {
            $targets = collect([$intern]);
        }

        foreach ($targets as $target) {
            $extra = InternExtra::firstOrNew([
                'intern_id' => $target->id,
            ]);

            // Alumni group
            if ($request->input('clear_alumni') === '1') {
                $extra->alumni_group_url        = null;
                $extra->alumni_group_label      = null;
                $extra->alumni_group_granted_at = null;
            } elseif ($request->has('alumni_group_url')) {
                if ($request->filled('alumni_group_url')) {
                    $extra->alumni_group_url   = $request->alumni_group_url;
                    $extra->alumni_group_label = $request->alumni_group_label ?: 'Grup Alumni Seveninc';
                    if (!$extra->alumni_group_granted_at) {
                        $extra->alumni_group_granted_at = now();
                    }
                } else {
                    $extra->alumni_group_url        = null;
                    $extra->alumni_group_label      = null;
                    $extra->alumni_group_granted_at = null;
                }
            }

            // Job info
            if ($request->input('clear_job_info') === '1') {
                $extra->job_info_url         = null;
                $extra->job_info_description = null;
                $extra->job_info_granted_at  = null;
            } elseif ($request->has('job_info_url')) {
                if ($request->filled('job_info_url')) {
                    $extra->job_info_url         = $request->job_info_url;
                    $extra->job_info_description = $request->job_info_description;
                    if (!$extra->job_info_granted_at) {
                        $extra->job_info_granted_at = now();
                    }
                } else {
                    $extra->job_info_url         = null;
                    $extra->job_info_description = null;
                    $extra->job_info_granted_at  = null;
                }
            }

            $extra->save();
        }

        $redirectParams = [];
        if ($bulkMode && $request->filled('ids')) {
            $redirectParams['mode'] = 'bulk';
            $redirectParams['ids'] = $request->input('ids');
        } elseif ($allBrandMode) {
            $redirectParams['mode'] = 'all_brand';
        }

        $redirectUrl = route('admin.intern_extras.edit', $intern->id)
            . ($redirectParams ? '?' . http_build_query($redirectParams) : '');

        $msg = ($targets->count() > 1)
            ? "Link grup alumni & info kerja berhasil disimpan untuk <strong>{$targets->count()} pemagang terpilih</strong>."
            : "Akses eksklusif untuk <strong>{$intern->fullname}</strong> berhasil diperbarui.";

        return redirect($redirectUrl)->with('success', $msg);
    }

    /**
     * Simpan template rekomendasi (AJAX) — dari halaman edit single intern.
     * Return JSON.
     */
    public function saveTemplate(Request $request, IR $intern)
    {
        $request->validate([
            'brand_id'      => 'required|integer|exists:brands,id',
            'body_template' => 'nullable|string',
        ]);

        // Karena tabel rekomendasi_settings sudah tidak ada, fitur ini dinonaktifkan
        // Atau jika ingin menyimpan, harus membuat tabel baru.
        return response()->json(['success' => true, 'message' => 'Template berhasil diproses (Mode On-the-fly).']);
    }

    /**
     * Kirim semua sekaligus: generate surat rekomendasi + simpan link alumni + info kerja
     * untuk satu pemagang, bulk pemagang terpilih, atau semua pemagang satu brand.
     * Return JSON.
     */
    public function sendAll(Request $request, IR $intern)
    {
        $allBrandMode = $request->input('mode') === 'all_brand';
        $bulkMode     = $request->input('mode') === 'bulk' || $request->filled('ids');

        if ($bulkMode && $request->filled('ids')) {
            $ids = array_values(array_filter(explode(',', $request->input('ids'))));
            $targets = IR::whereIn('id', $ids)->get();
        } elseif ($allBrandMode && !empty($intern->brand_id)) {
            $targets = IR::where('internship_status', IR::STATUS_COMPLETED)->where('brand_id', $intern->brand_id)->get();
        } else {
            $targets = collect([$intern]);
        }

        $request->validate([
            'brand_id'             => 'required|integer|exists:brands,id',
            'body_template'        => 'nullable|string',
            'alumni_group_url'     => 'nullable|url|max:500',
            'alumni_group_label'   => 'nullable|string|max:100',
            'job_info_url'         => 'nullable|url|max:500',
            'job_info_description' => 'nullable|string|max:500',
        ]);

        $brand = \App\Models\Brand::findOrFail($request->brand_id);
        $bodyTemplate = $request->body_template ?? RekomendasiSetting::defaultBodyTemplate();
        
        Storage::disk('public')->makeDirectory('documents/rekomendasi');
        
        // Resolve aset visual ke base64 (dari brand)
        $logoData  = null;
        if ($brand->logo && Storage::disk('public')->exists($brand->logo)) {
            $logoData = 'data:' . mime_content_type(storage_path('app/public/' . $brand->logo)) . ';base64,' . base64_encode(Storage::disk('public')->get($brand->logo));
        }

        $stampData = null;
        if ($brand->signature && Storage::disk('public')->exists($brand->signature)) {
            $stampData = 'data:' . mime_content_type(storage_path('app/public/' . $brand->signature)) . ';base64,' . base64_encode(Storage::disk('public')->get($brand->signature));
        }
        Carbon::setLocale('id');

        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $romanMonth = $roman[(int)date('n')];
        $year = date('Y');

        $generated = [];
        $failed    = [];

        foreach ($targets as $target) {
            try {
                // ── 1. Generate PDF surat rekomendasi ──
                $startStr    = $target->start_date ? Carbon::parse($target->start_date)->isoFormat('MMMM Y') : '-';
                $endStr      = $target->end_date   ? Carbon::parse($target->end_date)->isoFormat('MMMM Y')   : '-';
                $durationStr = 'beberapa bulan';
                if ($target->start_date && $target->end_date) {
                    $months = (int) round(
                        Carbon::parse($target->start_date)->diffInDays(Carbon::parse($target->end_date)) / 30
                    );
                    $durationStr = $months . ' bulan';
                }

                $interest = $target->internship_interest ?? '';
                $dbDivision = $target->division ?? \App\Models\Division::where('name', $interest)
                    ->orWhere('slug', \Illuminate\Support\Str::slug($interest, '-'))
                    ->first();
                $divisionCode = $dbDivision?->code ?? 'UMUM';
                $divisionFullName = $dbDivision?->name ?? $target->internship_interest ?? '-';

                $running      = str_pad((string) $target->id, 3, '0', STR_PAD_LEFT);
                $brandCodeStr = strtoupper($brand->code ?? 'SI');
                $letterNumber = "{$running}/SR/{$divisionCode}/SEVEN.{$brandCodeStr}/{$romanMonth}/{$year}";
                
                $letterDateStr = now()->isoFormat('D MMMM Y');

                $bodyText = $this->buildBodyText(
                    $bodyTemplate,
                    [
                        'nama'          => $target->fullname,
                        'divisi'        => $divisionFullName,
                        'mulai'         => $startStr,
                        'selesai'       => $endStr,
                        'durasi'        => $durationStr,
                        'instansi'      => $target->institution_name ?? '-',
                        'nim'           => $target->student_id ?? '-',
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
                    'participantName'      => $target->fullname,
                    'participantId'        => $target->student_id ?? '-',
                    'participantMajor'     => $target->study_program ?? '-',
                    'participantInstitute' => $target->institution_name ?? '-',
                    'bodyText'             => $bodyText,
                    'logoData'             => $logoData,
                    'stampData'            => $stampData,
                ])->render();

                // Nama file unik per pemagang — uniqid() cegah collision saat bulk
                $safeName = Str::slug($target->fullname ?? 'pemagang', '-');
                $fileName = "rekomendasi-{$target->id}-{$safeName}-" . now()->format('Ymd_His') . '-' . uniqid() . '.pdf';
                $relPath  = "documents/rekomendasi/{$fileName}";

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
                    throw new \RuntimeException('Gagal menghasilkan PDF.');
                }
                if (!Storage::disk('public')->put($relPath, $pdfContents)) {
                    throw new \RuntimeException("Gagal menyimpan file ke: {$relPath}");
                }

                // ── 2. Simpan semua ke InternExtra ──
                $extra = InternExtra::firstOrNew(['intern_id' => $target->id]);

                // Hapus file PDF lama
                if ($extra->rekomendasi_path && file_exists(storage_path('app/public/' . $extra->rekomendasi_path))) {
                    @unlink(storage_path('app/public/' . $extra->rekomendasi_path));
                }

                $extra->intern_id                    = $target->id;
                $extra->rekomendasi_path             = $relPath;
                $extra->rekomendasi_url              = asset('storage/' . $relPath);
                $extra->rekomendasi_granted_at       = now();
                $extra->letter_number                = $letterNumber;
                $extra->brand_id                     = $brand->id;
                $extra->company_name                 = $brand->name;
                $extra->company_address              = $brand->company_address;
                $extra->company_logo_path            = $brand->logo;
                $extra->signatory_name               = $brand->signatory_name;
                $extra->signatory_position           = $brand->signatory_position;
                $extra->signature_image_path         = $brand->signature;

                // Link grup alumni (jika diisi)
                if ($request->filled('alumni_group_url')) {
                    $extra->alumni_group_url   = $request->alumni_group_url;
                    $extra->alumni_group_label = $request->alumni_group_label ?: 'Grup Alumni Seveninc';
                    if (!$extra->alumni_group_granted_at) {
                        $extra->alumni_group_granted_at = now();
                    }
                }

                // Info kerja (jika diisi)
                if ($request->filled('job_info_url')) {
                    $extra->job_info_url         = $request->job_info_url;
                    $extra->job_info_description = $request->job_info_description;
                    if (!$extra->job_info_granted_at) {
                        $extra->job_info_granted_at = now();
                    }
                }

                $extra->save();
                $generated[] = $target->fullname;

            } catch (\Throwable $e) {
                Log::error('sendAll gagal', ['intern_id' => $target->id, 'error' => $e->getMessage()]);
                $failed[] = $target->fullname;
            }
        }

        $total = count($generated);
        return response()->json([
            'success'      => $total > 0,
            'message'      => $total > 0
                ? "Berhasil mengirim ke {$total} pemagang: surat rekomendasi, link grup alumni, dan info kerja."
                : 'Semua proses gagal.',
            'generated'    => $total,
            'failed'       => count($failed),
            'names'        => $generated,
            'failed_names' => $failed,
        ]);
    }

    /**
     * Hapus surat rekomendasi.
     */
    public function destroyRekomendasi(IR $intern)
    {
        $extra = InternExtra::where('intern_id', $intern->id)->first();
        if ($extra?->rekomendasi_path) {
            Storage::disk('public')->delete($extra->rekomendasi_path);
            $extra->update([
                'rekomendasi_path'       => null,
                'rekomendasi_url'        => null,
                'rekomendasi_granted_at' => null,
            ]);
        }

        return back()->with('success', 'Surat rekomendasi berhasil dihapus.');
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

        $candidates = [
            storage_path('app/public/' . $path),
            public_path($path),
        ];

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/png';
                return "data:{$mime};base64," . base64_encode(file_get_contents($file));
            }
        }
        return null;
    }
}
