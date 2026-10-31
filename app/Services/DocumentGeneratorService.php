<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\Division;
use App\Models\InternLoa;
use App\Models\InternshipRegistration;
use App\Models\SklDocument;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentGeneratorService
{
    /**
     * Daftar role internal staf yang berhak membuat atau mengunduh dokumen atas nama pengguna lain.
     */
    public const PRIVILEGED_ROLES = ['admin', 'hr_staff', 'staff', 'hrd'];

    /**
     * Periksa apakah pemagang memenuhi syarat untuk dokumen tertentu.
     */
    public function canGenerate(InternshipRegistration $intern, string $documentType): bool
    {
        $status = strtolower((string) ($intern->internship_status ?? ''));

        return match (strtolower($documentType)) {
            'loa'                   => in_array($status, [
                InternshipRegistration::STATUS_ACCEPTED,
                InternshipRegistration::STATUS_ACTIVE,
                InternshipRegistration::STATUS_COMPLETED
            ], true),
            'skl',
            'certificate',
            'sertifikat',
            'assessment',
            'penilaian',
            'recommendation',
            'rekomendasi',
            'membercard'            => in_array($status, [InternshipRegistration::STATUS_COMPLETED], true),
            default                 => false,
        };
    }

    /**
     * Resolusi pengguna target dokumen secara aman tanpa kebocoran konteks otentikasi.
     */
    public function resolveTargetUser(User $actor, ?int $targetUserId = null): User
    {
        if (empty($targetUserId) || $targetUserId === $actor->id) {
            return $actor;
        }

        if (!in_array($actor->role, self::PRIVILEGED_ROLES, true)) {
            throw new HttpException(403, 'Anda tidak memiliki wewenang untuk mengunduh dokumen atas nama pengguna lain.');
        }

        return User::findOrFail($targetUserId);
    }

    /**
     * Catat rekam jejak audit saat dokumen digenerate atau diunduh atas nama pengguna lain.
     */
    public function logDocumentAccess(
        string $documentType,
        Model $subject,
        User $actor,
        string $actionType = 'generated'
    ): ActivityLog {
        $actionName = $actionType === 'downloaded' ? 'document_downloaded_for_user' : 'document_generated_for_user';
        $subjectName = $subject instanceof InternshipRegistration
            ? ($subject->fullname ?? 'Pemagang #' . $subject->id)
            : ($subject->name ?? 'User #' . $subject->id);

        return ActivityLogger::log(
            action: $actionName,
            description: strtoupper($documentType) . " milik {$subjectName} telah di-{$actionType} oleh {$actor->name}",
            subject: $subject,
            actor: $actor,
            metadata: [
                'document_type' => $documentType,
                'actor_role'    => $actor->role,
                'target_id'     => $subject->getKey(),
            ]
        );
    }

    /**
     * Hasilkan nomor surat LOA yang unik dan aman dari race condition menggunakan DB transaction & row lock.
     */
    public function generateUniqueLoaNumber(InternshipRegistration $intern, ?Brand $brand = null): string
    {
        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $romanMonth = $roman[(int)date('n')];
        $year = date('Y');

        $brandModel = $brand ?? $intern->brandRel;
        $brandStr = strtoupper($brandModel?->code ?? 'SI');

        $divisionCode = 'UMUM';
        if ($intern->division) {
            $divisionCode = $intern->division->code ?? 'UMUM';
        } elseif ($intern->internship_interest) {
            $interest = $intern->internship_interest;
            $dbDivision = Division::where('name', $interest)->orWhere('slug', Str::slug($interest, '-'))->first();
            $divisionCode = $dbDivision?->code ?? 'UMUM';
        }

        return DB::transaction(function () use ($intern, $divisionCode, $brandStr, $romanMonth, $year) {
            // Kunci baris terakhir untuk bulan/tahun ini
            $last = InternLoa::where('loa_number', 'LIKE', "%/LOA/%/{$romanMonth}/{$year}")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $seq = 1;
            if ($last && preg_match('/^(\d{3})\/LOA\//', $last->loa_number, $m)) {
                $seq = (int)$m[1] + 1;
            }

            // Loop verifikasi keunikan nomor jika sudah ada di DB
            do {
                $seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
                $candidateNumber = "{$seqStr}/LOA/{$divisionCode}/SEVEN.{$brandStr}/{$romanMonth}/{$year}";
                $exists = InternLoa::where('loa_number', $candidateNumber)->exists();
                if ($exists) {
                    $seq++;
                }
            } while ($exists);

            return $candidateNumber;
        });
    }

    /**
     * Hasilkan nomor surat SKL yang unik dan aman dari race condition menggunakan DB transaction & row lock.
     */
    public function generateUniqueSklNumber(InternshipRegistration $intern, ?Brand $brand = null): string
    {
        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $romanMonth = $roman[(int)date('n')];
        $year = date('Y');

        $brandModel = $brand ?? $intern->brandRel;
        $brandStr = strtoupper($brandModel?->code ?? 'SI');

        $divisionCode = 'UMUM';
        if ($intern->division) {
            $divisionCode = $intern->division->code ?? 'UMUM';
        } elseif ($intern->internship_interest) {
            $interest = $intern->internship_interest;
            $dbDivision = Division::where('name', $interest)->orWhere('slug', Str::slug($interest, '-'))->first();
            $divisionCode = $dbDivision?->code ?? 'UMUM';
        }

        return DB::transaction(function () use ($intern, $divisionCode, $brandStr, $romanMonth, $year) {
            // Kunci baris terakhir untuk bulan/tahun ini
            $last = SklDocument::where('skl_number', 'LIKE', "%/SKL/%/{$romanMonth}/{$year}")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $seq = 1;
            if ($last && preg_match('/^(\d{3})\/SKL\//', $last->skl_number, $m)) {
                $seq = (int)$m[1] + 1;
            }

            // Loop verifikasi keunikan nomor jika sudah ada di DB
            do {
                $seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
                $candidateNumber = "{$seqStr}/SKL/{$divisionCode}/SEVEN.{$brandStr}/{$romanMonth}/{$year}";
                $exists = SklDocument::where('skl_number', $candidateNumber)->exists();
                if ($exists) {
                    $seq++;
                }
            } while ($exists);

            return $candidateNumber;
        });
    }

    /**
     * Pastikan dokumen LOA (record database dan file PDF) telah digenerate untuk pemagang.
     * Sangat berguna saat pemagang masuk langsung berstatus aktif (misal dari Absen Djuragan).
     */
    public function ensureLoaGenerated(
        InternshipRegistration $intern,
        ?User $actor = null,
        bool $forceRefresh = false,
        bool $isRevision = false
    ): InternLoa
    {
        $intern->loadMissing(['brandRel', 'division', 'institution', 'studyProgramRel', 'user']);

        $brandCode = $intern->brandRel?->code ?? $intern->brand;
        $brandData = $intern->brandRel ?? Brand::whereRaw('LOWER(name) = ?', [strtolower(trim((string)$brandCode))])
            ->orWhere('code', $brandCode)
            ->first();

        // 1. Dapatkan atau alokasikan nomor LOA
        $internLoa = InternLoa::where('intern_id', $intern->id)->latest('id')->first();
        $loaNumber = $internLoa?->loa_number ?: $this->generateUniqueLoaNumber($intern, $brandData);

        // 2. Cek apakah berkas PDF valid sudah ada di disk local dan public
        $safeLoaNumber = str_replace(['/', '\\'], '-', $loaNumber);
        $safeName      = Str::slug($intern->fullname ?? 'intern', '-');
        $fileName      = "{$safeLoaNumber}-{$safeName}.pdf";
        $relPath       = 'documents/loa/' . $fileName;

        $localSize     = Storage::disk('local')->exists($relPath) ? (int)Storage::disk('local')->size($relPath) : 0;
        $publicSize    = Storage::disk('public')->exists($relPath) ? (int)Storage::disk('public')->size($relPath) : 0;

        // Berkas PDF legacy berukuran ~3KB, template modern ~150KB+. Jika < 15KB atau dipaksa refresh, render ulang dari template.
        $needsRegen    = $forceRefresh || $isRevision || $localSize < 15000 || $publicSize < 15000;

        if ($needsRegen) {
            /** @var \App\Services\InternDocumentIssuanceService $issuanceService */
            $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);
            $template = $issuanceService->resolveActiveTemplate('loa', failClosed: false);

            if ($template && $template->exists) {
                try {
                    $context = $issuanceService->buildContext($intern, 'loa', ['document_number' => $loaNumber], isLiveIssuance: true);
                    $pdfBinary = \App\Services\DocumentTemplates\DocumentTemplateRenderer::renderPdfBinary($template, $context['variables'], isLiveIssuance: true);

                    Storage::disk('local')->put($relPath, $pdfBinary);
                    Storage::disk('public')->put($relPath, $pdfBinary);

                    $generatedDoc = $issuanceService->issueDocument(
                        intern: $intern,
                        documentType: 'loa',
                        options: [
                            'document_number' => $isRevision ? null : $loaNumber,
                            'template_id'     => $template->id,
                            'force_refresh'   => $forceRefresh || $isRevision,
                            'is_revision'     => $isRevision,
                        ],
                        actor: $actor
                    );

                    if ($generatedDoc) {
                        $loaNumber = $generatedDoc->document_number;
                        $safeLoaNumber = str_replace(['/', '\\'], '-', $loaNumber);
                        $fileName = "{$safeLoaNumber}-{$safeName}.pdf";
                        $relPath = 'documents/loa/' . $fileName;

                        Storage::disk('local')->put($relPath, $pdfBinary);
                        Storage::disk('public')->put($relPath, $pdfBinary);

                        if (Storage::disk('local')->exists($generatedDoc->file_path)) {
                            Storage::disk('local')->put($generatedDoc->file_path, $pdfBinary);
                            $generatedDoc->file_size_bytes = strlen($pdfBinary);
                            $generatedDoc->checksum_sha256 = hash('sha256', $pdfBinary);
                            $generatedDoc->save();
                        }

                        $internLoa = InternLoa::where('intern_id', $intern->id)->latest('id')->first() ?: $internLoa;
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Gagal issue LOA via DocumentTemplate: " . $e->getMessage() . " — mencoba render langsung");
                    try {
                        $context = $issuanceService->buildContext($intern, 'loa', ['document_number' => $loaNumber], isLiveIssuance: true);
                        $pdfBinary = \App\Services\DocumentTemplates\DocumentTemplateRenderer::renderPdfBinary($template, $context['variables'], isLiveIssuance: true);
                        Storage::disk('local')->put($relPath, $pdfBinary);
                        Storage::disk('public')->put($relPath, $pdfBinary);
                    } catch (\Throwable $errRender) {
                        \Illuminate\Support\Facades\Log::error("Gagal fallback render LOA: " . $errRender->getMessage());
                    }
                }
            } else {
                // Fallback legacy bila template aktif belum tersedia sama sekali
                $companyName       = $brandData->name ?? $brandCode ?? 'Seven Inc';
                $companyAddress    = $brandData?->company_address;
                $signatoryName     = $brandData->signatory_name ?? 'Ari Setia Husbana';
                $signatoryPosition = $brandData->signatory_position ?? 'HRD';
                $logoData  = $this->resolveBase64Image($brandData?->logo, 'images/logos/logo_seveninc.png');
                $stampData = $this->resolveBase64Image($brandData?->signature, 'images/signature/ttd_arisetiahusbana.png');
                $rows      = $this->buildRows([$intern]);

                $pdf = Pdf::loadView('user.loa', [
                    'intern'          => $intern,
                    'loaNumber'       => $loaNumber,
                    'user'            => $intern->user,
                    'loaSettings'     => (object)[
                        'header_text'        => 'Dengan ini kami mengonfirmasi bahwa pendaftar di bawah ini telah diterima untuk mengikuti program magang.',
                        'footer_text'        => 'Harap konfirmasi kehadiran Anda melalui email atau telepon yang tertera.',
                        'company_name'       => $companyName,
                        'company_address'    => $companyAddress,
                        'signatory_name'     => $signatoryName,
                        'signatory_position' => $signatoryPosition,
                    ],
                    'rows'            => $rows,
                    'openingGreeting' => 'Dengan ini kami mengonfirmasi bahwa pendaftar di bawah ini telah diterima untuk mengikuti program magang.',
                    'closingGreeting' => 'Harap konfirmasi kehadiran Anda melalui email atau telepon yang tertera.',
                    'logoData'        => $logoData,
                    'stampData'       => $stampData,
                ])->setPaper('A4', 'portrait');

                $pdf->setOptions(['isRemoteEnabled' => true, 'isPhpEnabled' => true]);
                $pdfOutput = $pdf->output();

                Storage::disk('local')->put($relPath, $pdfOutput);
                Storage::disk('public')->put($relPath, $pdfOutput);
            }
        }

        // 2.5 Pastikan GeneratedDocument tercatat dan tersinkronkan ke Google Drive
        try {
            $genDoc = \App\Models\GeneratedDocument::where('internship_registration_id', $intern->id)
                ->where('document_type', 'loa')
                ->latest('id')
                ->first();

            $pubService = app(\App\Services\DocumentPublishingService::class);
            if (!$genDoc) {
                $fileContent = Storage::disk('local')->exists($relPath) ? Storage::disk('local')->get($relPath) : null;
                if ($fileContent) {
                    $activeTpl = isset($template) && $template && $template->exists ? $template : null;
                    $genDoc = $pubService->publishInternDocument(
                        intern: $intern,
                        documentType: 'loa',
                        customDocNumber: $loaNumber,
                        actor: $actor,
                        pdfBinary: $fileContent,
                        template: $activeTpl
                    );
                }
            } elseif ($genDoc->status !== \App\Models\GeneratedDocument::STATUS_SYNCED) {
                $pubService->syncDocumentToDrive($genDoc, $actor);
            }
        } catch (\Throwable $eSync) {
            \Illuminate\Support\Facades\Log::warning("Gagal memastikan sinkronisasi Drive untuk LOA Pemagang #{$intern->id}: " . $eSync->getMessage());
        }

        // 3. Pastikan record InternLoa selalu tersinkron
        $internLoa = InternLoa::where('intern_id', $intern->id)->latest('id')->first();
        if (!$internLoa) {
            $internLoa = InternLoa::create([
                'intern_id'            => $intern->id,
                'loa_number'           => $loaNumber,
                'accepted_start_date'  => $intern->start_date ?? now(),
                'accepted_end_date'    => $intern->end_date ?? now(),
                'company_name'         => $brandData?->name ?? 'Seven Inc',
                'company_logo_path'    => $brandData?->logo,
                'signatory_name'       => $brandData?->signatory_name ?? 'Rekario Danny Sanjaya, S.Kom.',
                'signatory_position'   => $brandData?->signatory_position ?? 'Direktur Utama',
                'signature_image_path' => $brandData?->signature,
                'created_at'           => now(),
            ]);
        }

        // 4. Catat audit log jika actor diberikan
        if ($actor && in_array($actor->role, self::PRIVILEGED_ROLES, true)) {
            $this->logDocumentAccess('loa', $intern, $actor, 'generated');
        }

        return $internLoa;
    }

    /**
     * Format data baris untuk tabel dokumen LOA.
     */
    public function buildRows(iterable $interns): array
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

    /**
     * Konversi gambar ke base64 URI secara aman dengan fallback.
     */
    public function resolveBase64Image(?string $savedPath, string $fallbackRelative): ?string
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

        $publicFallback = public_path($fallbackRelative);
        if (file_exists($publicFallback)) {
            $mime = mime_content_type($publicFallback);
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($publicFallback));
        }

        return null;
    }
}
