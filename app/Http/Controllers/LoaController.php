<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\InternshipRegistration as IR;
use App\Models\InternLoa;
use App\Models\Brand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class LoaController extends Controller
{
    public function getInternsByBrand(Request $request)
    {
        $brand = $request->query('brand');

        if (!$brand) {
            return response()->json(['interns' => []]);
        }

        $interns = IR::query()
            ->with(['institution', 'studyProgramRel', 'brandRel', 'division'])
            ->where('internship_status', IR::STATUS_ACCEPTED)
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
            ->select('id', 'user_id', 'brand_id', 'institution_id', 'study_program_id', 'division_id', 'fullname', 'student_id', 'start_date', 'end_date', 'phone_number')
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
                'phone_number'     => $r->phone_number ?? '',
            ]);

        return response()->json(['interns' => $interns]);
    }

    public function generateForBrand(Request $request)
    {
        $validated = $request->validate([
            'intern_ids'         => ['required', 'array', 'min:1'],
            'intern_ids.*'       => ['integer', 'exists:internship_registrations,id'],
        ]);

        $user = $request->user();
        $interns = IR::with(['brandRel', 'division', 'institution', 'studyProgramRel'])->whereIn('id', $validated['intern_ids'])->get();

        if ($interns->isEmpty()) {
            return back()->with('error', 'Data pemagang tidak ditemukan.');
        }

        $firstIntern = $interns->first();
        $brandCode = $firstIntern->brandRel?->code ?? $firstIntern->brand;
        $brandData = $firstIntern->brandRel ?? Brand::whereRaw('LOWER(name) = ?', [strtolower(trim((string)$brandCode))])
            ->orWhere('code', $brandCode)
            ->first();

        $companyName = $brandData->name ?? $brandCode ?? 'Seven Inc';
        $signatoryName = $brandData->signatory_name ?? 'Ari Setia Husbana';
        $signatoryPosition = $brandData->signatory_position ?? 'HRD';
        
        $logoData  = $this->resolveBase64Image($brandData?->logo, 'images/logos/logo_seveninc.png');
        $sigData   = $this->resolveBase64Image($brandData?->signature, 'images/signature/ttd_arisetiahusbana.png');
        $stampData = $this->resolveBase64Image($brandData?->stamp, 'images/mou/stamp-sample.png');

        $dir = 'documents/loa';
        $this->ensurePublicDir($dir);

        $generatedFiles = [];
        $errors         = [];

        /** @var \App\Services\DocumentGeneratorService $docService */
        $docService = app(\App\Services\DocumentGeneratorService::class);

        foreach ($interns as $intern) {
            try {
                $internLoa = $docService->ensureLoaGenerated($intern, $user, forceRefresh: true);
                $safeLoaNumber = str_replace(['/', '\\'], '-', $internLoa->loa_number);
                $safeName = Str::slug($intern->fullname ?? 'intern', '-');
                $fileName = $safeLoaNumber . '-' . $safeName . '.pdf';
                $storedPath = storage_path('app/documents/loa/' . $fileName);

                $tmpPath = storage_path('app/tmp/' . $fileName);
                if (!is_dir(dirname($tmpPath))) {
                    mkdir(dirname($tmpPath), 0775, true);
                }

                if (file_exists($storedPath) && filesize($storedPath) > 0) {
                    copy($storedPath, $tmpPath);
                    $generatedFiles[$intern->id] = [
                        'fullname' => $intern->fullname,
                        'path'     => $tmpPath,
                        'filename' => $fileName,
                    ];
                } else {
                    $errors[] = $intern->fullname;
                }
            } catch (\Throwable $e) {
                Log::error('Gagal generate LOA (brand)', ['err' => $e->getMessage(), 'intern_id' => $intern->id]);
                $errors[] = $intern->fullname;
            }
        }

        if (empty($generatedFiles)) {
            return back()->with('error', 'Gagal membuat LOA. Silakan coba lagi.');
        }

        if (count($generatedFiles) === 1) {
            $file = reset($generatedFiles);
            return response()->download($file['path'], $file['filename'])->deleteFileAfterSend(true);
        }

        $zipName = 'LOA-BATCH-' . now()->format('Ymd_His') . '.zip';
        $zipPath = storage_path("app/tmp/{$zipName}");

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return back()->with('error', 'Gagal membuat file ZIP.');
        }

        foreach ($generatedFiles as $file) {
            if (file_exists($file['path'])) {
                $zip->addFile($file['path'], $file['filename']);
            }
        }
        $zip->close();

        // Delete individual temporary PDFs after zip creation
        foreach ($generatedFiles as $file) {
            if (file_exists($file['path'])) @unlink($file['path']);
        }

        return response()->download($zipPath, $zipName)->deleteFileAfterSend(true);

    }

    protected function resolveBase64Image(?string $savedPath, string $fallbackRelative): ?string
    {
        $candidates = array_filter([$savedPath, $fallbackRelative]);
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

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'intern_id'     => ['required', 'integer', 'exists:internship_registrations,id'],
            'force_refresh' => ['nullable', 'boolean'],
            'is_revision'   => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        /** @var \App\Services\DocumentGeneratorService $docService */
        $docService = app(\App\Services\DocumentGeneratorService::class);

        if ($user->role === 'admin' || $user->role === 'hr_staff') {
            $intern = IR::where('id', $validated['intern_id'])->firstOrFail();
        } elseif ($user->role === 'pemagang') {
            $intern = IR::where('id', $validated['intern_id'])->first();
            if (!$intern) {
                abort(404, 'Data pemagang tidak ditemukan.');
            }
            if ((int)$intern->user_id !== (int)$user->id) {
                abort(403, 'Anda tidak berhak mengakses LOA milik pengguna lain.');
            }
            $this->ensureCanAccessCompletedDocs($user, $intern);
        } else {
            abort(403, 'Anda tidak memiliki hak untuk membuat LOA.');
        }

        $forceRefresh = $request->boolean('force_refresh');
        $isRevision   = $request->boolean('is_revision');

        if ($forceRefresh || $isRevision) {
            $internLoa = $docService->ensureLoaGenerated(
                intern: $intern,
                actor: $user,
                forceRefresh: true,
                isRevision: $isRevision
            );

            $msg = $isRevision
                ? "Revisi resmi LOA berhasil diterbitkan (Nomor: {$internLoa->loa_number}) dan disinkronkan ke Google Drive."
                : "Dokumen LOA berhasil dirender ulang dan disinkronkan ke Google Drive.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'ok'           => true,
                    'message'      => $msg,
                    'loa_number'   => $internLoa->loa_number,
                    'download_url' => route('admin.loa.download', $intern->id),
                ]);
            }

            return redirect()->route('admin.loa.review', $intern->id)->with('success', $msg);
        }

        // Delegate to downloadSingle
        return $this->downloadSingle($request, $intern->id);
    }

    /**
     * Unduh atau buka langsung PDF LOA untuk satu pemagang.
     */
    public function downloadSingle(Request $request, $internId = null)
    {
        $id = $internId ?? $request->get('intern_id');
        if (!$id) {
            abort(404, 'ID pemagang tidak diberikan.');
        }

        $intern = IR::findOrFail($id);
        $user = $request->user();

        // Hak akses: admin / hr_staff, atau pemagang yang bersangkutan
        if ($user && $user->role === 'pemagang') {
            if ((int)$intern->user_id !== (int)$user->id) {
                abort(403, 'Anda tidak berhak mengakses LOA milik pengguna lain.');
            }
            $this->ensureCanAccessCompletedDocs($user, $intern);
        }

        /** @var \App\Services\DocumentGeneratorService $docService */
        $docService = app(\App\Services\DocumentGeneratorService::class);
        $internLoa = $docService->ensureLoaGenerated($intern, $user);

        $safeLoaNumber = str_replace(['/', '\\'], '-', $internLoa->loa_number);
        $safeName = Str::slug($intern->fullname ?? 'intern', '-');
        $fileName = "{$safeLoaNumber}-{$safeName}.pdf";

        $candidates = [
            storage_path("app/documents/loa/{$fileName}"),
            storage_path("app/public/documents/loa/{$fileName}"),
        ];

        $fullPath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate) && filesize($candidate) > 0) {
                $fullPath = $candidate;
                break;
            }
        }

        if (!$fullPath) {
            abort(404, 'File LOA belum dapat ditemukan.');
        }

        // Jika dipanggil via POST (misal tombol Unduh PDF di portal pemagang) atau download=1
        if ($request->isMethod('POST') || $request->query('download') === '1' || $request->query('inline') === '0') {
            return response()->download($fullPath, $fileName, [
                'Content-Type' => 'application/pdf',
            ]);
        }

        return response()->file($fullPath, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fileName . '"',
        ]);
    }

    /**
     * Halaman review & pratinjau LOA sebelum / sesudah generate (S17).
     * GET /admin/loa/generate/{intern}
     */
    public function review(Request $request, $id)
    {
        $intern = IR::with(['brandRel', 'division', 'institution', 'studyProgramRel', 'user'])->findOrFail($id);

        return view('admin.documents.loa_review', compact('intern'));
    }

    /**
     * Konten raw HTML LOA untuk iframe pratinjau visual A4.
     * GET /admin/loa/raw/{intern}
     */
    public function rawPreview(Request $request, $id)
    {
        $intern = IR::with(['brandRel', 'division', 'institution', 'studyProgramRel', 'user'])->findOrFail($id);

        /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
        $template = $issuanceService->resolveActiveTemplate('loa', failClosed: false);

        if ($template && $template->exists) {
            $preview = $issuanceService->previewDocument($intern, 'loa', ['template_id' => $template->id]);
            return response($preview['html'], 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $user   = $request->user();

        $brandCode = $intern->brandRel?->code ?? $intern->brand;
        $brandData = $intern->brandRel ?? Brand::whereRaw('LOWER(name) = ?', [strtolower(trim((string)$brandCode))])
            ->orWhere('code', $brandCode)
            ->first();

        $companyName       = $brandData->name ?? $brandCode ?? 'Seven Inc';
        $signatoryName     = $brandData->signatory_name ?? 'Ari Setia Husbana';
        $signatoryPosition = $brandData->signatory_position ?? 'HRD';

        $logoData  = $this->resolveBase64Image($brandData?->logo, 'images/logos/logo_seveninc.png');
        $sigData   = $this->resolveBase64Image($brandData?->signature, 'images/signature/ttd_arisetiahusbana.png');
        $stampData = $this->resolveBase64Image($brandData?->stamp, 'images/mou/stamp-sample.png');

        // Jika sudah ada record snapshot LOA, gunakan nomor yang sama
        $loaRecord = InternLoa::where('intern_id', $intern->id)->latest('id')->first();
        if ($loaRecord && !empty($loaRecord->loa_number)) {
            $loaNumber = $loaRecord->loa_number;
        } else {
            /** @var \App\Services\DocumentGeneratorService $docService */
            $docService = app(\App\Services\DocumentGeneratorService::class);
            $loaNumber  = $docService->generateUniqueLoaNumber($intern, $brandData);
        }

        $rows = $this->buildRows([$intern]);

        return view('user.loa', [
            'intern'          => $intern,
            'loaNumber'       => $loaNumber,
            'user'            => $user,
            'loaSettings'     => (object)[
                'header_text'        => 'Dengan ini kami mengonfirmasi bahwa pendaftar di bawah ini telah diterima untuk mengikuti program magang.',
                'footer_text'        => 'Harap konfirmasi kehadiran Anda melalui email atau telepon yang tertera.',
                'company_name'       => $companyName,
                'company_address'    => $brandData?->company_address,
                'signatory_name'     => $signatoryName,
                'signatory_position' => $signatoryPosition,
            ],
            'rows'            => $rows,
            'openingGreeting' => 'Dengan ini kami mengonfirmasi bahwa pendaftar di bawah ini telah diterima untuk mengikuti program magang.',
            'closingGreeting' => 'Harap konfirmasi kehadiran Anda melalui email atau telepon yang tertera.',
            'logoData'        => $logoData,
            'sigData'         => $sigData,
            'stampData'       => $stampData,
        ]);
    }

    public function generateBatch(Request $request)
    {
        return $this->generateForBrand($request);
    }

    public function indexInterns()
    {
        $registrations = IR::latest('id')->paginate(20);
        if (view()->exists('admin.loa_interns')) {
            return view('admin.loa_interns', compact('registrations'));
        }
        return redirect()->route('admin.documents.loas');
    }

    protected function buildRows($interns): array
    {
        $rows = [];
        foreach ($interns as $intern) {
            $rows[] = [
                'nama_siswa' => $intern->fullname ?? 'Nama Tidak Diketahui',
                'nim_nis'    => ($intern->student_id ?? $intern->nim_nis ?? $intern->nim ?? null) ?: 'NIM/NIS Tidak Diketahui',
                'jurusan'    => ($intern->study_program ?? $intern->major ?? null) ?: 'Jurusan Tidak Diketahui',
                'instansi'   => $intern->institution_name ?? 'Instansi Tidak Diketahui',
                'periode'    => ($intern->start_date && $intern->end_date)
                    ? Carbon::parse($intern->start_date)->format('d F Y') . ' - ' . Carbon::parse($intern->end_date)->format('d F Y')
                    : 'Periode Tidak Diketahui',
                'kontak'     => ($intern->phone_number ?? $intern->contact_info ?? $intern->email ?? null) ?: 'Kontak Tidak Diketahui',
            ];
        }
        return $rows;
    }

    protected function ensureCanAccessCompletedDocs($user, $intern): void
    {
        $status = strtolower((string)($intern->internship_status ?? ''));

        if ((int)$intern->user_id !== (int)$user->id) {
            abort(403, 'Anda tidak berhak membuat/akses LOA untuk data ini.');
        }

        $allowed = ['accepted', 'active', 'completed'];
        if (!($user->role === 'pemagang' && in_array($status, $allowed))) {
            abort(403, 'LOA hanya tersedia setelah pendaftaran diterima.');
        }
    }

    protected function ensurePublicDir(string $dir): void
    {
        if (!Storage::disk('local')->exists($dir)) {
            Storage::disk('local')->makeDirectory($dir);
        }
    }

    protected function maybeToPublicUrlOrAsset(?string $path, string $fallbackAsset): string
    {
        if ($path && Storage::disk('public')->exists($path)) {
            return asset('storage/'.$path);
        }
        return asset($fallbackAsset);
    }
}
