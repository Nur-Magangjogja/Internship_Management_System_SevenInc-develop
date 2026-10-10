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
        
        $logoData = $this->resolveBase64Image($brandData?->logo, 'images/logos/logo_seveninc.png');
        $stampData = $this->resolveBase64Image($brandData?->signature, 'images/signature/ttd_arisetiahusbana.png');

        $dir = 'documents/loa';
        $this->ensurePublicDir($dir);

        $generatedFiles = [];
        $errors         = [];

        /** @var \App\Services\DocumentGeneratorService $docService */
        $docService = app(\App\Services\DocumentGeneratorService::class);

        foreach ($interns as $intern) {
            try {
                $loaNumber = $docService->generateUniqueLoaNumber($intern, $brandData);
                $safeLoaNumber = str_replace('/', '-', $loaNumber);

                $rows = $this->buildRows([$intern]);

                $pdf = Pdf::loadView('user.loa', [
                    'intern'          => $intern,
                    'loaNumber'       => $loaNumber,
                    'user'            => $user,
                    'loaSettings'     => (object)[
                        'header_text' => 'Dengan ini kami mengonfirmasi bahwa pendaftar di bawah ini telah diterima untuk mengikuti program magang.',
                        'footer_text' => 'Harap konfirmasi kehadiran Anda melalui email atau telepon yang tertera.',
                        'company_name' => $companyName,
                        'company_address' => $brandData?->company_address,
                        'signatory_name' => $signatoryName,
                        'signatory_position' => $signatoryPosition,
                    ],
                    'rows'            => $rows,
                    'openingGreeting' => 'Dengan ini kami mengonfirmasi bahwa pendaftar di bawah ini telah diterima untuk mengikuti program magang.',
                    'closingGreeting' => 'Harap konfirmasi kehadiran Anda melalui email atau telepon yang tertera.',
                    'logoData'        => $logoData,
                    'stampData'       => $stampData,
                ])->setPaper('A4', 'portrait');

                $pdf->setOptions(['isRemoteEnabled' => true, 'isPhpEnabled' => true]);

                $safeName = Str::slug($intern->fullname ?? 'intern', '-');
                $fileName = $safeLoaNumber . '-' . $safeName . '.pdf';
                $path     = $dir . '/' . $fileName;

                Storage::disk('local')->put($path, $pdf->output());

                // Insert snapshot to intern_loas (if missing or update if needed)
                $internLoa = InternLoa::updateOrCreate(
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

                // Catat audit log setelah file PDF LOA dan snapshot DB berhasil dibuat
                if ($user && $user->role === 'admin') {
                    $docService->logDocumentAccess('loa', $intern, $user, 'generated');
                }

                $html = view('user.loa', [
                    'intern'          => $intern,
                    'loaNumber'       => $loaNumber,
                    'user'            => $user,
                    'loaSettings'     => (object)[
                        'header_text' => 'Dengan ini kami mengonfirmasi bahwa pendaftar di bawah ini telah diterima untuk mengikuti program magang.',
                        'footer_text' => 'Harap konfirmasi kehadiran Anda melalui email atau telepon yang tertera.',
                        'company_name' => $companyName,
                        'company_address' => $brandData?->company_address,
                        'signatory_name' => $signatoryName,
                        'signatory_position' => $signatoryPosition,
                    ],
                    'rows'            => $rows,
                    'openingGreeting' => 'Dengan ini kami mengonfirmasi bahwa pendaftar di bawah ini telah diterima untuk mengikuti program magang.',
                    'closingGreeting' => 'Harap konfirmasi kehadiran Anda melalui email atau telepon yang tertera.',
                    'logoData'        => $logoData,
                    'stampData'       => $stampData,
                ])->render();

                $safeName = Str::slug($intern->fullname ?? 'intern', '-');
                $fileName = $safeLoaNumber . '-' . $safeName . '.pdf';
                $tmpPath  = storage_path('app/tmp/' . $fileName);
                
                if (!is_dir(dirname($tmpPath))) {
                    mkdir(dirname($tmpPath), 0775, true);
                }

                try {
                    if (app()->environment('testing')) {
                        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                            \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->save($tmpPath);
                        } else {
                            file_put_contents($tmpPath, '%PDF-1.4 test loa');
                        }
                    } else {
                        \Spatie\Browsershot\Browsershot::html($html)
                            ->setOption('no-sandbox', true)
                            ->setOption('args', ['--disable-setuid-sandbox'])
                            ->emulateMedia('print')
                            ->format('A4')
                            ->margins(0, 0, 0, 0)
                            ->showBackground()
                            ->waitUntilNetworkIdle()
                            ->timeout(180)
                            ->savePdf($tmpPath);
                    }
                } catch (\Throwable $errBrowser) {
                    \Illuminate\Support\Facades\Log::warning('Browsershot LOA generation failed, falling back to DomPDF: ' . $errBrowser->getMessage());
                    if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                        \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->save($tmpPath);
                    } else {
                        file_put_contents($tmpPath, '%PDF-1.4 fallback loa');
                    }
                }

                if (file_exists($tmpPath) && filesize($tmpPath) > 0) {
                    $generatedFiles[$intern->id] = [
                        'fullname' => $intern->fullname,
                        'path'     => $tmpPath,
                        'filename' => $fileName,
                    ];
                } else {
                    if (file_exists($tmpPath)) @unlink($tmpPath);
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
        if ($savedPath) {
            $fullPath = storage_path('app/public/' . $savedPath);
            if (file_exists($fullPath)) {
                $mime = mime_content_type($fullPath);
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
            }
        }

        $fallbackPath = storage_path('app/public/' . $fallbackRelative);
        if (file_exists($fallbackPath)) {
            $mime = mime_content_type($fallbackPath);
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fallbackPath));
        }

        return null;
    }

    public function generate(Request $request)
    {
        $validated = $request->validate([
            'intern_id' => ['required', 'integer', 'exists:internship_registrations,id'],
        ]);

        $user = $request->user();

        /** @var \App\Services\DocumentGeneratorService $docService */
        $docService = app(\App\Services\DocumentGeneratorService::class);

        if ($user->role === 'admin') {
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

        // Delegate to generateForBrand which now generates on the fly
        $request->merge(['intern_ids' => [$intern->id]]);
        return $this->generateForBrand($request);

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
