<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\InternAssessment;
use App\Models\Brand;
use App\Models\InternshipRegistration as IR;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Str;

class InternAssessmentController extends Controller
{
    // =========================================================================
    // Helpers
    // =========================================================================

    private function publicUrl(?string $path): ?string
    {
        if (!$path) return null;
        if (!Storage::disk('public')->exists($path)) return null;
        return url(Storage::url($path));
    }

    private function imageDataUri(string $path): ?string
    {
        if (!file_exists($path)) return null;
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    private function resolveLogoAndSignature(Request $request, ?string $savedLogoPath, ?string $savedSigPath): array
    {
        // Logo
        $logoPath = $savedLogoPath;
        if ($request->filled('company_logo_select')) {
            $logoPath = $request->company_logo_select;
        } elseif ($request->hasFile('company_logo')) {
            $logoPath = $request->file('company_logo')->store('images/logos', 'public');
        }

        // Tanda tangan
        $sigPath = $savedSigPath;
        if ($request->filled('signature_image_select')) {
            $sigPath = $request->signature_image_select;
        } elseif ($request->hasFile('signature_image')) {
            $sigPath = $request->file('signature_image')->store('images/signatures', 'public');
        }

        return [$logoPath, $sigPath];
    }

    private function generateAssessmentNumber($intern): string
    {
        if (!$intern) return '';

        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $romanMonth = $roman[(int)date('n')];
        $year = date('Y');

        $last = InternAssessment::where('assessment_number', 'LIKE', "%/PEN/%/{$romanMonth}/{$year}")
            ->orderByDesc('id')->first();
        $seq = 1;
        if ($last && preg_match('/^(\d{3})\/PEN\//', $last->assessment_number, $m)) {
            $seq = (int)$m[1] + 1;
        }

        $interest = $intern->internship_interest ?? '';
        $dbDivision = \App\Models\Division::where('name', $interest)
            ->orWhere('slug', \Illuminate\Support\Str::slug($interest, '-'))
            ->first();
        $division = $dbDivision?->code ?? 'UMUM';
        
        $brandStr = strtoupper($intern->brand ?? 'SI');
        $seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        
        return "{$seqStr}/PEN/{$division}/SEVEN.{$brandStr}/{$romanMonth}/{$year}";
    }

    private function buildAspekData(array $aspeks, array $nilais): array
    {
        $data  = [];
        $total = 0;
        foreach ($aspeks as $i => $aspek) {
            $nilai  = max(0, min(100, (float) ($nilais[$i] ?? 0)));
            $data[] = ['aspek' => $aspek, 'nilai' => $nilai];
            $total += $nilai;
        }
        $avg = count($aspeks) > 0 ? round($total / count($aspeks), 2) : 0;
        return [$data, $avg];
    }

    private function getDivisionOptions(): array
    {
        return [
            'Project Manager',
            'Administration',
            'Human Resources (HR)',
            'UI/UX',
            'Programmer (Front End / Backend)',
            'Photographer',
            'Videographer',
            'Graphic Designer',
            'Social Media Specialist',
            'Content Writer',
            'Content Planner',
            'Sales & Marketing',
            'Public Relations (Marcomm)',
            'Digital Marketing',
            'TikTok Creator',
            'Welding',
            'Customer Service',
            'Lainnya',
        ];
    }

    private function getDefaultAspects(): array
    {
        return [
            'Content Writer' => [
                ['aspek' => 'Copywriting',    'nilai' => 95],
                ['aspek' => 'Branding',        'nilai' => 95],
                ['aspek' => 'Riset Konten',    'nilai' => 95],
                ['aspek' => 'Kedisiplinan',    'nilai' => 95],
                ['aspek' => 'Kreativitas',     'nilai' => 95],
                ['aspek' => 'Kerjasama',       'nilai' => 95],
                ['aspek' => 'Kehadiran',       'nilai' => 95],
            ],
            'Programmer' => [
                ['aspek' => 'Coding Front/Backend', 'nilai' => 95],
                ['aspek' => 'Database',              'nilai' => 95],
                ['aspek' => 'Debugging',             'nilai' => 95],
                ['aspek' => 'Problem Solving',       'nilai' => 95],
                ['aspek' => 'Kedisiplinan',          'nilai' => 95],
                ['aspek' => 'Kerjasama',             'nilai' => 95],
                ['aspek' => 'Kehadiran',             'nilai' => 95],
            ],
            'UI/UX' => [
                ['aspek' => 'User Research',          'nilai' => 95],
                ['aspek' => 'Wireframing & Prototyping','nilai' => 95],
                ['aspek' => 'Visual Design',          'nilai' => 95],
                ['aspek' => 'Layout & Typography',    'nilai' => 95],
                ['aspek' => 'Color Theory',           'nilai' => 95],
                ['aspek' => 'Kedisiplinan',           'nilai' => 95],
                ['aspek' => 'Kerjasama',              'nilai' => 95],
                ['aspek' => 'Kehadiran',              'nilai' => 95],
            ],
            'UI/UX Designer' => [
                ['aspek' => 'User Research',          'nilai' => 95],
                ['aspek' => 'Wireframing & Prototyping','nilai' => 95],
                ['aspek' => 'Visual Design',          'nilai' => 95],
                ['aspek' => 'Layout & Typography',    'nilai' => 95],
                ['aspek' => 'Color Theory',           'nilai' => 95],
                ['aspek' => 'Kedisiplinan',           'nilai' => 95],
                ['aspek' => 'Kerjasama',              'nilai' => 95],
                ['aspek' => 'Kehadiran',              'nilai' => 95],
            ],
            'Programmer (Front End / Backend)' => [
                ['aspek' => 'Coding Front/Backend', 'nilai' => 95],
                ['aspek' => 'Database',              'nilai' => 95],
                ['aspek' => 'Debugging',             'nilai' => 95],
                ['aspek' => 'Problem Solving',       'nilai' => 95],
                ['aspek' => 'Kedisiplinan',          'nilai' => 95],
                ['aspek' => 'Kerjasama',             'nilai' => 95],
                ['aspek' => 'Kehadiran',             'nilai' => 95],
            ],
            'Graphic Designer' => [
                ['aspek' => 'Desain Visual',          'nilai' => 95],
                ['aspek' => 'Kreativitas & Inovasi',  'nilai' => 95],
                ['aspek' => 'Typography & Warna',     'nilai' => 95],
                ['aspek' => 'Infografis & Branding',  'nilai' => 95],
                ['aspek' => 'Kedisiplinan',           'nilai' => 95],
                ['aspek' => 'Kerjasama',              'nilai' => 95],
                ['aspek' => 'Kehadiran',              'nilai' => 95],
            ],
            'Digital Marketing' => [
                ['aspek' => 'Strategi Konten',           'nilai' => 95],
                ['aspek' => 'Analisis Data & Keyword',   'nilai' => 95],
                ['aspek' => 'Social Media Marketing',    'nilai' => 95],
                ['aspek' => 'Copywriting & Engagement',  'nilai' => 95],
                ['aspek' => 'Kedisiplinan',              'nilai' => 95],
                ['aspek' => 'Kreativitas',               'nilai' => 95],
                ['aspek' => 'Kerjasama',                 'nilai' => 95],
                ['aspek' => 'Kehadiran',                 'nilai' => 95],
            ],
            'Project Manager' => [
                ['aspek' => 'Perencanaan Proyek', 'nilai' => 95],
                ['aspek' => 'Koordinasi Tim',     'nilai' => 95],
                ['aspek' => 'Manajemen Waktu',    'nilai' => 95],
                ['aspek' => 'Komunikasi',         'nilai' => 95],
                ['aspek' => 'Kedisiplinan',       'nilai' => 95],
                ['aspek' => 'Kerjasama',          'nilai' => 95],
                ['aspek' => 'Kehadiran',          'nilai' => 95],
            ],
            'Administration' => [
                ['aspek' => 'Administrasi',  'nilai' => 95],
                ['aspek' => 'Pengarsipan',   'nilai' => 95],
                ['aspek' => 'Komunikasi',    'nilai' => 95],
                ['aspek' => 'Koordinasi',    'nilai' => 95],
                ['aspek' => 'Kedisiplinan',  'nilai' => 95],
                ['aspek' => 'Kerjasama',     'nilai' => 95],
                ['aspek' => 'Kehadiran',     'nilai' => 95],
            ],
            'Human Resources (HR)' => [
                ['aspek' => 'Rekrutmen',    'nilai' => 95],
                ['aspek' => 'Pelatihan',    'nilai' => 95],
                ['aspek' => 'Komunikasi',   'nilai' => 95],
                ['aspek' => 'Kedisiplinan', 'nilai' => 95],
                ['aspek' => 'Kerjasama',    'nilai' => 95],
                ['aspek' => 'Kehadiran',    'nilai' => 95],
            ],
            'Photographer' => [
                ['aspek' => 'Pemotretan',        'nilai' => 95],
                ['aspek' => 'Komposisi Visual',  'nilai' => 95],
                ['aspek' => 'Editing Foto',      'nilai' => 95],
                ['aspek' => 'Kreativitas',       'nilai' => 95],
                ['aspek' => 'Kedisiplinan',      'nilai' => 95],
                ['aspek' => 'Kehadiran',         'nilai' => 95],
            ],
            'Videographer' => [
                ['aspek' => 'Pengambilan Video', 'nilai' => 95],
                ['aspek' => 'Editing Video',     'nilai' => 95],
                ['aspek' => 'Storytelling',      'nilai' => 95],
                ['aspek' => 'Kreativitas',       'nilai' => 95],
                ['aspek' => 'Kedisiplinan',      'nilai' => 95],
                ['aspek' => 'Kehadiran',         'nilai' => 95],
            ],
            'Social Media Specialist' => [
                ['aspek' => 'Strategi Media Sosial', 'nilai' => 95],
                ['aspek' => 'Content Engagement',    'nilai' => 95],
                ['aspek' => 'Analisis Data',         'nilai' => 95],
                ['aspek' => 'Kedisiplinan',          'nilai' => 95],
                ['aspek' => 'Kerjasama',             'nilai' => 95],
                ['aspek' => 'Kehadiran',             'nilai' => 95],
            ],
            'Content Planner' => [
                ['aspek' => 'Perencanaan Konten', 'nilai' => 95],
                ['aspek' => 'Riset Audience',     'nilai' => 95],
                ['aspek' => 'Kalender Konten',    'nilai' => 95],
                ['aspek' => 'Kolaborasi',         'nilai' => 95],
                ['aspek' => 'Kedisiplinan',       'nilai' => 95],
                ['aspek' => 'Kehadiran',          'nilai' => 95],
            ],
            'Sales & Marketing' => [
                ['aspek' => 'Strategi Penjualan', 'nilai' => 95],
                ['aspek' => 'Negosiasi',          'nilai' => 95],
                ['aspek' => 'Analisis Pasar',     'nilai' => 95],
                ['aspek' => 'Komunikasi',         'nilai' => 95],
                ['aspek' => 'Kedisiplinan',       'nilai' => 95],
                ['aspek' => 'Kehadiran',          'nilai' => 95],
            ],
            'Public Relations (Marcomm)' => [
                ['aspek' => 'Hubungan Media',    'nilai' => 95],
                ['aspek' => 'Komunikasi Publik', 'nilai' => 95],
                ['aspek' => 'Event Support',     'nilai' => 95],
                ['aspek' => 'Kedisiplinan',      'nilai' => 95],
                ['aspek' => 'Kerjasama',         'nilai' => 95],
                ['aspek' => 'Kehadiran',         'nilai' => 95],
            ],
            'TikTok Creator' => [
                ['aspek' => 'Pembuatan Video Pendek', 'nilai' => 95],
                ['aspek' => 'Kreativitas',            'nilai' => 95],
                ['aspek' => 'Editing Singkat',        'nilai' => 95],
                ['aspek' => 'Engagement',             'nilai' => 95],
                ['aspek' => 'Kedisiplinan',           'nilai' => 95],
                ['aspek' => 'Kehadiran',              'nilai' => 95],
            ],
            'Welding' => [
                ['aspek' => 'Keterampilan Las', 'nilai' => 95],
                ['aspek' => 'Keamanan Kerja',   'nilai' => 95],
                ['aspek' => 'Kualitas Jahitan', 'nilai' => 95],
                ['aspek' => 'Kedisiplinan',     'nilai' => 95],
                ['aspek' => 'Kerjasama',        'nilai' => 95],
                ['aspek' => 'Kehadiran',        'nilai' => 95],
            ],
            'Customer Service' => [
                ['aspek' => 'Pelayanan Pelanggan',  'nilai' => 95],
                ['aspek' => 'Komunikasi',           'nilai' => 95],
                ['aspek' => 'Penyelesaian Masalah', 'nilai' => 95],
                ['aspek' => 'Empati',               'nilai' => 95],
                ['aspek' => 'Kedisiplinan',         'nilai' => 95],
                ['aspek' => 'Kehadiran',            'nilai' => 95],
            ],
            'Lainnya' => [
                ['aspek' => 'Kedisiplinan', 'nilai' => 95],
                ['aspek' => 'Kerjasama',    'nilai' => 95],
                ['aspek' => 'Kehadiran',    'nilai' => 95],
            ],
        ];
    }

    /** Map internship_interest slug → division option label */
    private function mapInterestToDivision(string $raw): ?string
    {
        $map = [
            'project-manager'                   => 'Project Manager',
            'administration'                    => 'Administration',
            'administrasi'                      => 'Administration',
            'hr'                                => 'Human Resources (HR)',
            'human resources (hr)'              => 'Human Resources (HR)',
            'uiux'                              => 'UI/UX',
            'ui/ux'                             => 'UI/UX',
            'programmer'                        => 'Programmer (Front End / Backend)',
            'programmer (front end / backend)'  => 'Programmer (Front End / Backend)',
            'photographer'                      => 'Photographer',
            'fotografer'                        => 'Photographer',
            'videographer'                      => 'Videographer',
            'videografer'                       => 'Videographer',
            'graphic-designer'                  => 'Graphic Designer',
            'desainer grafis'                   => 'Graphic Designer',
            'social-media-specialist'           => 'Social Media Specialist',
            'content-writer'                    => 'Content Writer',
            'content-planner'                   => 'Content Planner',
            'marketing-and-sales'               => 'Sales & Marketing',
            'sales & marketing'                 => 'Sales & Marketing',
            'public-relation'                   => 'Public Relations (Marcomm)',
            'public relations (marcomm)'        => 'Public Relations (Marcomm)',
            'digital-marketing'                 => 'Digital Marketing',
            'tiktok-creator'                    => 'TikTok Creator',
            'welding'                           => 'Welding',
            'pengelasan'                        => 'Welding',
            'customer-service'                  => 'Customer Service',
        ];
        $key = strtolower(trim($raw));
        return $map[$key] ?? (in_array($raw, $this->getDivisionOptions()) ? $raw : null);
    }

    // =========================================================================
    // API: pemagang selesai berdasarkan brand
    // =========================================================================

    /**
     * GET /admin/interns/assessment/interns-by-brand?brand=XXX
     */
    public function getInternsByBrand(Request $request)
    {
        $brand = $request->query('brand');
        if (!$brand) {
            return response()->json(['interns' => [], 'signatory' => null]);
        }

        $interns = IR::query()
            ->where('internship_status', IR::STATUS_COMPLETED)
            ->whereHas('brandRel', function ($q) use ($brand) {
                $q->where('name', $brand);
            })
            ->with('brandRel')
            ->select('id', 'fullname', 'student_id', 'study_program', 'institution_name',
                     'start_date', 'end_date', 'internship_interest', 'brand_id')
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
                'brand'               => $r->brand ?? '',
                'has_assessment'      => InternAssessment::where('intern_id', $r->id)->exists(),
            ]);

        $signatory = Brand::where('name', $brand)->first();

        return response()->json([
            'interns'   => $interns,
            'signatory' => $signatory ? [
                'company_name'         => $signatory->name,
                'company_address'      => $signatory->company_address,
                'signature_name'       => $signatory->signatory_name,
                'signature_position'   => $signatory->signatory_position,
                'signature_image_path' => $signatory->signature,
                'company_logo_path'    => $signatory->logo,
            ] : null,
        ]);
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    public function index(Request $request)
    {
        $query = IR::with(['assessment', 'brandRel'])
            ->whereIn('internship_status', [IR::STATUS_ACTIVE, IR::STATUS_COMPLETED]);

        if ($request->filled('search')) {
            $search = strtolower($request->search);
            $query->where(function($q) use ($search) {
                $q->whereRaw('LOWER(fullname) LIKE ?', ["%{$search}%"])
                  ->orWhereHas('institution', function($q2) use ($search) {
                      $q2->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]);
                  });
            });
        }
        if ($request->filled('brand')) {
            $query->whereHas('brandRel', function($q) use ($request) {
                $q->where('name', $request->brand);
            });
        }
        if ($request->filled('status_penilaian')) {
            if ($request->status_penilaian == 'sudah') {
                $query->has('assessment');
            } else {
                $query->doesntHave('assessment');
            }
        }
        if ($request->filled('divisi')) {
            $query->whereHas('division', function($q) use ($request) {
                $q->where('name', $request->divisi);
            });
        }

        $data = $query->latest('id')->paginate(20);

        $brands = \App\Models\Brand::pluck('name');
        $divisions = $this->getDivisionOptions();
        
        return view('admin.interns.index_assessment', compact('data', 'brands', 'divisions'));
    }

    // =========================================================================
    // CREATE (step 1: pilih brand + pemagang)
    // =========================================================================

    public function create(Request $request)
    {
        $internIds = $request->input('intern_ids', []);
        
        if (empty($internIds)) {
            return redirect()->route('interns.assessment.index')->with('error', 'Pilih minimal satu pemagang untuk dinilai.');
        }

        // Fetch interns
        $interns = IR::with('brandRel')
            ->whereIn('id', $internIds)
            ->whereIn('internship_status', [IR::STATUS_ACTIVE, IR::STATUS_COMPLETED])
            ->get();

        if ($interns->isEmpty()) {
            return redirect()->route('interns.assessment.index')->with('error', 'Pemagang tidak ditemukan atau status tidak valid.');
        }

        $defaultAspects = $this->getDefaultAspects();
        $wizardData = [];

        foreach ($interns as $intern) {
            $division = $this->mapInterestToDivision($intern->internship_interest ?? '');
            if (!$division || !isset($defaultAspects[$division])) {
                $division = 'Content Writer';
            }

            // Find signatory for brand
            $brandModel = $intern->brandRel;
            $brandName = $brandModel?->name ?? $intern->brand;
            
            $wizardData[] = [
                'intern_id' => $intern->id,
                'fullname' => $intern->fullname,
                'nim_nis' => $intern->student_id ?? $intern->nim_nis ?? '-',
                'study_program' => $intern->study_program ?? '-',
                'brand' => $brandName ?? '-',
                'division' => $intern->internship_interest ?? '-',
                'aspects' => $defaultAspects[$division],
                'signatory' => $brandModel ? [
                    'company_name' => $brandModel->name,
                    'company_address' => $brandModel->company_address,
                    'signatory_name' => $brandModel->signatory_name,
                    'signatory_position' => $brandModel->signatory_position,
                    'company_logo_path' => $brandModel->logo,
                    'signature_image_path' => $brandModel->signature,
                ] : null
            ];
        }

        return view('admin.interns.wizard_assessment', [
            'wizardData' => json_encode($wizardData)
        ]);
    }


    // =========================================================================
    // STORE BULK — simpan banyak penilaian sekaligus (1 per pemagang)
    // =========================================================================

    /**
     * POST /admin/interns/assessment/store-bulk
     * Form dikirim sebagai array per-intern: interns[0][fullname], interns[0][aspek][], dst.
     */
    public function storeBulk(Request $request)
    {
        $request->validate([
            'assessments' => 'required|array',
            'assessments.*.intern_id' => 'required|integer|exists:internship_registrations,id',
            'assessments.*.aspek' => 'required|array',
            'assessments.*.nilai' => 'required|array',
            'assessments.*.company_name' => 'nullable|string',
            'assessments.*.company_address' => 'nullable|string',
            'assessments.*.signatory_name' => 'nullable|string',
            'assessments.*.signatory_position' => 'nullable|string',
            'assessments.*.company_logo_path' => 'nullable|string',
            'assessments.*.signature_image_path' => 'nullable|string',
        ]);

        $saved = 0;
        $errors = [];

        foreach ($request->input('assessments') as $idx => $item) {
            try {
                [$data, $avg] = $this->buildAspekData($item['aspek'] ?? [], $item['nilai'] ?? []);

                $intern = IR::find($item['intern_id']);
                $assessmentNumber = $this->generateAssessmentNumber($intern);

                InternAssessment::updateOrCreate(
                    ['intern_id' => $item['intern_id']],
                    [
                        'assessment_number'     => $assessmentNumber,
                        'company_name'          => $item['company_name'] ?? null,
                        'company_address'       => $item['company_address'] ?? null,
                        'signatory_name'        => $item['signatory_name'] ?? null,
                        'signatory_position'    => $item['signatory_position'] ?? null,
                        'company_logo_path'     => $item['company_logo_path'] ?? null,
                        'signature_image_path'  => $item['signature_image_path'] ?? null,
                        'aspek_penilaian'       => $data,
                        'rata_rata'             => $avg,
                    ]
                );

                $saved++;
            } catch (\Throwable $e) {
                $errors[] = "Pemagang ID {$item['intern_id']}: " . $e->getMessage();
                \Log::error('Bulk assessment store gagal', ['idx' => $idx, 'err' => $e->getMessage()]);
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "✅ {$saved} penilaian berhasil disimpan.",
                'errors' => $errors
            ]);
        }

        $msg = "✅ {$saved} penilaian berhasil disimpan.";
        if (!empty($errors)) {
            $msg .= ' Gagal: ' . implode('; ', $errors);
        }

        return redirect()->route('interns.assessment.index')->with('success', $msg);
    }

    // =========================================================================
    // SAVE SIGNATORY SETTING PER BRAND
    // =========================================================================

    /**
     * POST /admin/interns/assessment/save-signatory
     * AJAX — simpan setting penandatangan untuk brand tertentu.
     */
    public function saveSignatoryAjax(Request $request)
    {
        $request->validate([
            'brand'              => 'required|string',
            'company_name'       => 'nullable|string|max:255',
            'company_address'    => 'nullable|string|max:1000',
            'signature_name'     => 'nullable|string|max:255',
            'signature_position' => 'nullable|string|max:255',
            'company_logo'       => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'signature_image'    => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $brand    = $request->input('brand');
        $existing = Brand::where('name', $brand)->first();
        
        if (!$existing) {
            return response()->json(['success' => false, 'message' => 'Brand tidak ditemukan.'], 404);
        }

        [$logoPath, $sigPath] = $this->resolveLogoAndSignature(
            $request,
            $existing->logo,
            $existing->signature
        );

        $existing->fill([
            'company_address'      => $request->input('company_address', $existing->company_address),
            'signatory_name'       => $request->input('signature_name', $existing->signatory_name),
            'signatory_position'   => $request->input('signature_position', $existing->signatory_position),
            'logo'                 => $logoPath ?? $existing->logo,
            'signature'            => $sigPath ?? $existing->signature,
        ])->save();

        return response()->json(['success' => true, 'message' => 'Setting penandatangan berhasil disimpan.']);
    }



    // =========================================================================
    // DESTROY
    // =========================================================================

    public function destroy($id)
    {
        $assessment = InternAssessment::findOrFail($id);

        // Hapus file aset kalau upload khusus (bukan shared)
        if ($assessment->company_logo_path && Storage::disk('public')->exists($assessment->company_logo_path)) {
            // Hanya hapus kalau tidak ada assessment lain yang pakai file yang sama
            $count = InternAssessment::where('company_logo_path', $assessment->company_logo_path)
                ->where('id', '!=', $assessment->id)->count();
            if ($count === 0) {
                Storage::disk('public')->delete($assessment->company_logo_path);
            }
        }

        if ($assessment->signature_image_path && Storage::disk('public')->exists($assessment->signature_image_path)) {
            $count = InternAssessment::where('signature_image_path', $assessment->signature_image_path)
                ->where('id', '!=', $assessment->id)->count();
            if ($count === 0) {
                Storage::disk('public')->delete($assessment->signature_image_path);
            }
        }

        $assessment->delete();

        return redirect()->route('interns.assessment.index')->with('success', 'Penilaian berhasil dihapus.');
    }

    // =========================================================================
    // PDF PREVIEW & DOWNLOAD
    // =========================================================================

    public function previewPDF($id)
    {
        $assessment = InternAssessment::with('intern')->findOrFail($id);
        $user = auth()->user();

        if (!$user) {
            abort(401);
        }

        if ($user->role === 'pemagang') {
            if (!$assessment->intern || (int)$assessment->intern->user_id !== (int)$user->id) {
                abort(403, 'Anda tidak memiliki hak untuk mengakses dokumen penilaian ini.');
            }
        }

        if ($assessment->intern->internship_status !== IR::STATUS_COMPLETED) {
            return redirect()->back()->with('error', 'Dokumen hanya bisa dilihat setelah status pemagang selesai.');
        }

        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
        $activeTemplate = $issuanceService->resolveActiveTemplate('assessment', failClosed: false);

        if ($activeTemplate && $activeTemplate->exists && $assessment->intern) {
            $preview = $issuanceService->previewDocument($assessment->intern, 'assessment', [
                'template_id'     => $activeTemplate->id,
                'document_number' => $assessment->assessment_number,
            ]);
            return response($preview['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $resolveImg = function (...$paths) {
            foreach ($paths as $path) {
                if (empty($path)) continue;
                if (str_starts_with($path, 'data:image/')) return $path;
                $cleanPath = ltrim($path, '/');
                if (str_starts_with($cleanPath, 'storage/')) {
                    $cleanPath = substr($cleanPath, 8);
                }
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($cleanPath)) {
                    $content = \Illuminate\Support\Facades\Storage::disk('public')->get($cleanPath);
                    $mime = \Illuminate\Support\Facades\Storage::disk('public')->mimeType($cleanPath) ?: 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode($content);
                }
                if (file_exists($path) && is_file($path)) {
                    $mime = mime_content_type($path) ?: 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
                }
                $inPub = public_path(ltrim($path, '/'));
                if (file_exists($inPub) && is_file($inPub)) {
                    $mime = mime_content_type($inPub) ?: 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($inPub));
                }
                $inStorage = storage_path('app/public/' . ltrim($path, '/'));
                if (file_exists($inStorage) && is_file($inStorage)) {
                    $mime = mime_content_type($inStorage) ?: 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($inStorage));
                }
            }
            return null;
        };

        $intern = $assessment->intern;
        $brandCode = $intern?->brand;
        $brandModel = $intern?->brandRel ?? ($brandCode ? Brand::where('code', $brandCode)->first() : null);

        $logoSrc = $resolveImg(
            $brandModel?->logo,
            $assessment->company_logo_path,
            'images/mou/seven-inc-logo.png',
            'images/logos/logo_seveninc.png'
        );
        $sigSrc = $resolveImg(
            $brandModel?->signature,
            $assessment->signature_image_path,
            'images/mou/signature-sample.png',
            'images/signature/ttd_arisetiahusbana.png',
            'images/signatures/ttd.png'
        );
        $stampSrc = $resolveImg(
            $brandModel?->stamp,
            'images/mou/stamp-sample.png',
            'images/stamps/stamp.png'
        );

        return view('admin.interns.pdf_assessment', [
            'assessment' => $assessment,
            'logoSrc'    => $logoSrc,
            'sigSrc'     => $sigSrc,
            'stampSrc'   => $stampSrc,
            'autoPrint'  => false,
        ]);
    }

    public function downloadPDF($id)
    {
        $assessment = InternAssessment::with('intern')->findOrFail($id);
        $user = auth()->user();

        if (!$user) {
            abort(401);
        }

        if ($user->role === 'pemagang') {
            if (!$assessment->intern || (int)$assessment->intern->user_id !== (int)$user->id) {
                abort(403, 'Anda tidak memiliki hak untuk mengakses dokumen penilaian ini.');
            }
        }

        if ($assessment->intern->internship_status !== IR::STATUS_COMPLETED) {
            return redirect()->back()->with('error', 'Dokumen hanya bisa diunduh setelah status pemagang selesai.');
        }

        $assessmentSlug = Str::slug($assessment->intern->fullname ?? 'assessment', '-');
        $filename       = "intern-assessment-{$assessmentSlug}.pdf";

        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
        $activeTemplate = $issuanceService->resolveActiveTemplate('assessment', failClosed: false);

        if ($activeTemplate && $activeTemplate->exists && $assessment->intern) {
            // 1. Cek berkas GeneratedDocument valid sudah ada di local storage
            $genDoc = \App\Models\GeneratedDocument::select(['id', 'file_path', 'file_name'])
                ->where('internship_registration_id', $assessment->intern->id)
                ->where('document_type', 'assessment')
                ->latest('id')
                ->first();

            if ($genDoc && \Illuminate\Support\Facades\Storage::disk('local')->exists($genDoc->file_path)) {
                $fullPath = \Illuminate\Support\Facades\Storage::disk('local')->path($genDoc->file_path);
                return response()->download($fullPath, $filename, ['Content-Type' => 'application/pdf']);
            }

            // 2. Render PDF dari template aktif
            try {
                $context = $issuanceService->buildContext($assessment->intern, 'assessment', [
                    'document_number' => $assessment->assessment_number,
                    'template_id'     => $activeTemplate->id,
                ], isLiveIssuance: true);

                $pdfBinary = \App\Services\DocumentTemplates\DocumentTemplateRenderer::renderPdfBinary($activeTemplate, $context['variables'], isLiveIssuance: true);

                $tmpPath = storage_path('app/tmp/' . $filename);
                if (!is_dir(dirname($tmpPath))) {
                    mkdir(dirname($tmpPath), 0775, true);
                }
                file_put_contents($tmpPath, $pdfBinary);

                return response()->download($tmpPath, $filename, ['Content-Type' => 'application/pdf'])
                    ->deleteFileAfterSend(true);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Gagal generate surat penilaian dari template aktif: " . $e->getMessage() . " — mencoba fallback legacy view");
            }
        }

        $resolveImg = function (...$paths) {
            foreach ($paths as $path) {
                if (empty($path)) continue;
                if (str_starts_with($path, 'data:image/')) return $path;
                $cleanPath = ltrim($path, '/');
                if (str_starts_with($cleanPath, 'storage/')) {
                    $cleanPath = substr($cleanPath, 8);
                }
                if (\Illuminate\Support\Facades\Storage::disk('public')->exists($cleanPath)) {
                    $content = \Illuminate\Support\Facades\Storage::disk('public')->get($cleanPath);
                    $mime = \Illuminate\Support\Facades\Storage::disk('public')->mimeType($cleanPath) ?: 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode($content);
                }
                if (file_exists($path) && is_file($path)) {
                    $mime = mime_content_type($path) ?: 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
                }
                $inPub = public_path(ltrim($path, '/'));
                if (file_exists($inPub) && is_file($inPub)) {
                    $mime = mime_content_type($inPub) ?: 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($inPub));
                }
                $inStorage = storage_path('app/public/' . ltrim($path, '/'));
                if (file_exists($inStorage) && is_file($inStorage)) {
                    $mime = mime_content_type($inStorage) ?: 'image/png';
                    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($inStorage));
                }
            }
            return null;
        };

        $intern = $assessment->intern;
        $brandCode = $intern?->brand;
        $brandModel = $intern?->brandRel ?? ($brandCode ? Brand::where('code', $brandCode)->first() : null);

        $logoSrc = $resolveImg(
            $brandModel?->logo,
            $assessment->company_logo_path,
            'images/mou/seven-inc-logo.png',
            'images/logos/logo_seveninc.png'
        );
        $sigSrc = $resolveImg(
            $brandModel?->signature,
            $assessment->signature_image_path,
            'images/mou/signature-sample.png',
            'images/signature/ttd_arisetiahusbana.png',
            'images/signatures/ttd.png'
        );
        $stampSrc = $resolveImg(
            $brandModel?->stamp,
            'images/mou/stamp-sample.png',
            'images/stamps/stamp.png'
        );

        $assessmentSlug = Str::slug($assessment->intern->fullname ?? 'assessment', '-');
        $filename       = "intern-assessment-{$assessmentSlug}.pdf";

        $htmlContent = view('admin.interns.pdf_assessment', [
            'assessment' => $assessment,
            'logoSrc'    => $logoSrc,
            'sigSrc'     => $sigSrc,
            'stampSrc'   => $stampSrc,
            'autoPrint'  => false,
        ])->render();

        $tmpPath = storage_path('app/tmp/' . $filename);
        if (!is_dir(dirname($tmpPath))) {
            mkdir(dirname($tmpPath), 0775, true);
        }

        try {
            if (app()->environment('testing')) {
                if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                    \Barryvdh\DomPDF\Facade\Pdf::loadHTML($htmlContent)->save($tmpPath);
                } else {
                    file_put_contents($tmpPath, '%PDF-1.4 test assessment');
                }
            } else {
                Browsershot::html($htmlContent)
                    ->emulateMedia('print')
                    ->format('A4')
                    ->landscape()
                    ->margins(0, 0, 0, 0)
                    ->timeout(180)
                    ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox'])
                    ->savePdf($tmpPath);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Browsershot assessment generation failed, falling back to DomPDF: ' . $e->getMessage());
            if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                \Barryvdh\DomPDF\Facade\Pdf::loadHTML($htmlContent)->save($tmpPath);
            } else {
                file_put_contents($tmpPath, '%PDF-1.4 fallback assessment');
            }
        }

        if (!file_exists($tmpPath) || filesize($tmpPath) === 0) {
            if (file_exists($tmpPath)) @unlink($tmpPath);
            return back()->with('error', 'Gagal menghasilkan dokumen penilaian PDF.');
        }

        return response()->download($tmpPath, $filename, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    // =========================================================================
    // AJAX — aspek berdasarkan divisi
    // =========================================================================

    public function getAspekByDivision(Request $request)
    {
        $division = $request->get('division', 'Content Writer');
        $all = $this->getDefaultAspects();
        $division = array_key_exists($division, $all) ? $division : 'Lainnya';
        return response()->json(['aspek' => $all[$division]]);
    }

    // =========================================================================
    // Private helper: simpan setting penandatangan per brand
    // =========================================================================

    private function saveSignatorySetting(Request $request, string $brand, ?string $logoPath, ?string $sigPath): void
    {
        $existing = Brand::where('name', $brand)->first();
        
        if ($existing) {
            $existing->fill([
                'company_address'      => $request->input('company_address', $existing->company_address),
                'signatory_name'       => $request->input('signature_name', $existing->signatory_name),
                'signatory_position'   => $request->input('signature_position', $existing->signatory_position),
                'logo'                 => $logoPath ?? $existing->logo,
                'signature'            => $sigPath  ?? $existing->signature,
            ])->save();
        }
    }
}

