<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Institution;
use App\Models\InternAssessment;
use App\Models\InternCertificate;
use App\Models\InternExtra;
use App\Models\InternLoa;
use App\Models\InternshipRegistration;
use App\Models\SklDocument;
use App\Models\SystemAsset;
use App\Models\User;
use App\Services\DocumentTemplates\DocumentTemplateRenderer;
use App\Services\DocumentTemplates\MailMergePlaceholderContract;
use App\Services\MailMerge\VariableRegistry;
use App\Services\MailMerge\VariableResolverService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class InternDocumentIssuanceService
{
    public const TYPE_LOA            = 'loa';
    public const TYPE_SKL            = 'skl';
    public const TYPE_CERTIFICATE    = 'certificate';
    public const TYPE_ASSESSMENT     = 'assessment';
    public const TYPE_RECOMMENDATION = 'recommendation';

    public function __construct(
        protected DocumentPublishingService $publishingService,
        protected DocumentGeneratorService $docGeneratorService,
        protected VariableResolverService $resolverService,
        protected SystemAssetService $assetService
    ) {}

    /**
     * Normalisasi alias jenis dokumen ke tipe kanonikal sistem.
     */
    public static function normalizeType(string $type): string
    {
        $t = strtolower(trim($type));
        return match ($t) {
            'sertifikat'  => self::TYPE_CERTIFICATE,
            'penilaian'   => self::TYPE_ASSESSMENT,
            'rekomendasi' => self::TYPE_RECOMMENDATION,
            default       => $t,
        };
    }

    /**
     * Periksa apakah status pemagang memenuhi syarat penerbitan dokumen tertentu.
     */
    public function canIssue(InternshipRegistration $intern, string $documentType): bool
    {
        $canonical = self::normalizeType($documentType);
        $status = strtolower((string) ($intern->internship_status ?? ''));

        return match ($canonical) {
            self::TYPE_LOA => in_array($status, [
                InternshipRegistration::STATUS_ACCEPTED,
                InternshipRegistration::STATUS_ACTIVE,
                InternshipRegistration::STATUS_COMPLETED,
            ], true),
            self::TYPE_SKL,
            self::TYPE_CERTIFICATE,
            self::TYPE_ASSESSMENT,
            self::TYPE_RECOMMENDATION => in_array($status, [
                InternshipRegistration::STATUS_COMPLETED,
            ], true),
            default => false,
        };
    }

    /**
     * Resolusi template aktif yang dipilih Admin untuk jenis dokumen terkait.
     */
    public function resolveActiveTemplate(string $documentType): DocumentTemplate
    {
        $canonical = self::normalizeType($documentType);

        // 1. Ambil template default yang aktif
        $template = DocumentTemplate::ofType($canonical)
            ->active()
            ->default()
            ->first();

        // 2. Jika tidak ada default, ambil template aktif manapun
        if (!$template) {
            $template = DocumentTemplate::ofType($canonical)
                ->active()
                ->latest('id')
                ->first();
        }

        // 3. Fallback jika template belum ada di DB (membuat instance preset tanpa error)
        if (!$template) {
            $isCanvas = ($canonical === self::TYPE_CERTIFICATE);
            $template = new DocumentTemplate([
                'uuid'           => (string) Str::uuid(),
                'type'           => $canonical,
                'name'           => 'Template Standar ' . strtoupper($canonical),
                'editor_type'    => $isCanvas ? 'visual_canvas' : 'visual_html',
                'content_html'   => $isCanvas ? null : DocumentTemplate::getDefaultStandardContent($canonical),
                'canvas_layout'  => $isCanvas ? DocumentTemplate::getDefaultCertificateCanvasLayout() : null,
                'page_config'    => DocumentTemplate::getDefaultPageConfig($canonical),
                'is_active'      => true,
                'is_default'     => true,
                'version_number' => 1,
            ]);
        }

        return $template;
    }

    /**
     * Resolusi nomor dokumen yang sudah pernah diterbitkan sebelumnya (GeneratedDocument atau tabel legacy).
     */
    public function resolveExistingDocumentNumber(InternshipRegistration $intern, string $documentType): ?string
    {
        $canonical = self::normalizeType($documentType);

        // 1. Cek dari GeneratedDocument yang sudah ada
        $existingGen = GeneratedDocument::where('internship_registration_id', $intern->id)
            ->where('document_type', $canonical)
            ->whereNotNull('document_number')
            ->latest('id')
            ->value('document_number');

        if (!empty($existingGen)) {
            return $existingGen;
        }

        // 2. Cek dari tabel legacy historis jika pernah diterbitkan
        return match ($canonical) {
            self::TYPE_LOA            => InternLoa::where('intern_id', $intern->id)->value('loa_number'),
            self::TYPE_SKL            => SklDocument::where('intern_id', $intern->id)->value('skl_number'),
            self::TYPE_CERTIFICATE    => InternCertificate::where('intern_id', $intern->id)->value('certificate_number'),
            self::TYPE_ASSESSMENT     => InternAssessment::where('intern_id', $intern->id)->value('assessment_number'),
            self::TYPE_RECOMMENDATION => InternExtra::where('intern_id', $intern->id)->value('letter_number'),
            default                   => null,
        };
    }

    /**
     * Bangun konteks variabel dan referensi aset sistem untuk dokumen pemagang.
     */
    public function buildContext(
        InternshipRegistration $intern,
        string $documentType,
        array $options = []
    ): array {
        $canonical = self::normalizeType($documentType);
        $intern->loadMissing(['brandRel', 'division', 'institution', 'studyProgramRel', 'user']);

        $brandModel = $intern->brandRel;
        $instModel  = $intern->institution;

        // 1. Ekstraksi nomor dokumen: utamakan nomor custom dari options, nomor historis existing, baru generate nomor baru
        $docNumber = $options['document_number'] ?? $this->resolveExistingDocumentNumber($intern, $canonical);
        if (empty($docNumber)) {
            $docNumber = match ($canonical) {
                self::TYPE_LOA            => $this->docGeneratorService->generateUniqueLoaNumber($intern, $brandModel),
                self::TYPE_SKL            => $this->docGeneratorService->generateUniqueSklNumber($intern, $brandModel),
                self::TYPE_CERTIFICATE    => $this->generateUniqueCertificateNumber($intern),
                self::TYPE_ASSESSMENT     => $this->generateUniqueAssessmentNumber($intern),
                self::TYPE_RECOMMENDATION => $this->generateUniqueRecommendationNumber($intern),
                default                   => strtoupper($canonical) . '/' . date('Ymd') . '/' . $intern->id,
            };
        }

        // 2. Resolusi dasar dari VariableResolverService
        $baseContext = $this->resolverService->resolveContext(
            intern: $intern,
            institution: $instModel,
            brand: $brandModel,
            overrides: $options['variables'] ?? [],
            assetOverrides: $options['asset_overrides'] ?? []
        );

        $variables = $baseContext['variables'];
        $assetReferences = $baseContext['asset_references'];

        // 3. Tambahkan variabel-variabel detail spesifik dokumen
        $companyName = $brandModel?->name ?? ($intern->brand ?: 'Seven Inc');
        $companyAddress = $brandModel?->company_address ?? 'Jl. Raya Janti, Gang Arjuna No. 59, Karangjambe, Banguntapan, Bantul, Yogyakarta';
        $signatoryName = $brandModel?->signatory_name ?? 'Ari Setia Husbana';
        $signatoryPosition = $brandModel?->signatory_position ?? 'HRD';

        if ($canonical === self::TYPE_RECOMMENDATION) {
            $signatoryName = $brandModel?->signatory_name ?? 'Rekario Danny Sanjaya, S.Kom.';
            $signatoryPosition = $brandModel?->signatory_position ?? 'CEO';
        }

        $startDateStr = $intern->start_date ? Carbon::parse($intern->start_date)->translatedFormat('d F Y') : '-';
        $endDateStr   = $intern->end_date ? Carbon::parse($intern->end_date)->translatedFormat('d F Y') : '-';

        $durationStr = 'beberapa bulan';
        if ($intern->start_date && $intern->end_date) {
            $months = (int) round(Carbon::parse($intern->start_date)->diffInDays(Carbon::parse($intern->end_date)) / 30);
            $durationStr = max(1, $months) . ' bulan';
        }

        // Variabel tambahan
        $additionalVars = [
            'dokumen.nomor'                 => $docNumber,
            'dokumen.tanggal'               => now()->translatedFormat('d F Y'),
            'dokumen.penandatangan_nama'    => $signatoryName,
            'dokumen.penandatangan_jabatan' => $signatoryPosition,
            'perusahaan.nama'               => $companyName,
            'perusahaan.alamat'             => $companyAddress,
            'perusahaan.kota'               => 'Yogyakarta',
            'perusahaan.telepon'            => $brandModel?->phone ?? '0274-4534571',
            'perusahaan.kodepos'            => '55198',
            'pemagang.nama'                 => $intern->fullname ?? 'Nama Pemagang',
            'pemagang.nim'                  => ($intern->student_id ?? $intern->nim_nis ?? $intern->nim ?? '-') ?: '-',
            'pemagang.institusi'            => $intern->institution_name ?? ($instModel?->name ?? '-'),
            'pemagang.prodi'                => $intern->study_program ?? ($intern->major ?? '-'),
            'pemagang.divisi'               => $intern->division?->name ?? ($intern->internship_interest ?? 'Umum'),
            'pemagang.brand'                => $companyName,
            'pemagang.tanggal_mulai'        => $startDateStr,
            'pemagang.tanggal_selesai'      => $endDateStr,
            'pemagang.periode'              => "{$startDateStr} - {$endDateStr}",
            'pemagang.durasi'               => $durationStr,
            'nilai.rata_rata'               => $options['rata_rata'] ?? '85.0',
            'nilai.predikat'                => $options['predikat'] ?? 'Sangat Baik (A)',
            'rekomendasi.isi'               => $options['body_text'] ?? $this->getDefaultRecommendationBody($intern, $companyName, $durationStr),
        ];

        foreach ($additionalVars as $k => $v) {
            if (!isset($variables[$k]) || empty($variables[$k])) {
                $variables[$k] = $v;
            }
        }

        // Resolusi aset background sertifikat jika tipe sertifikat
        if ($canonical === self::TYPE_CERTIFICATE) {
            $bgAsset = $this->assetService->resolveAsset(
                category: SystemAsset::CATEGORY_BACKGROUND,
                assetCode: 'asset.background',
                brandId: $brandModel?->id
            );

            if ($bgAsset) {
                $assetReferences['asset.background'] = [
                    'asset_id' => $bgAsset->id,
                    'name'     => $bgAsset->name,
                    'version'  => $bgAsset->version,
                    'checksum' => $bgAsset->checksum_sha256,
                    'category' => $bgAsset->category,
                ];
                $bgDataUri = $this->assetService->getAssetAsBase64DataUri($bgAsset);
                $variables['asset.background'] = $bgDataUri;
            }
        }

        return [
            'variables'        => $variables,
            'asset_references' => $assetReferences,
            'document_number'  => $docNumber,
        ];
    }

    /**
     * Preview dokumen pemagang secara terisolasi.
     * JAMINAN: TIDAK menulis ke GeneratedDocument, TIDAK menyimpan berkas final, TIDAK memicu Drive sync.
     */
    public function previewDocument(
        InternshipRegistration $intern,
        string $documentType,
        array $options = []
    ): array {
        $canonical = self::normalizeType($documentType);

        $template = !empty($options['template_id'])
            ? DocumentTemplate::findOrFail($options['template_id'])
            : $this->resolveActiveTemplate($canonical);

        $context = $this->buildContext($intern, $canonical, $options);
        $variables = $context['variables'];

        if ($template->type === self::TYPE_CERTIFICATE || $template->editor_type === 'visual_canvas') {
            $html = DocumentTemplateRenderer::renderCertificateHtml($template, $variables);
        } else {
            $html = DocumentTemplateRenderer::renderLetterHtml($template, $variables);
        }

        return [
            'html'             => $html,
            'template'         => $template,
            'document_type'    => $canonical,
            'document_number'  => $context['document_number'],
            'variables'        => $variables,
            'asset_references' => $context['asset_references'],
        ];
    }

    /**
     * Terbitkan dokumen pemagang secara resmi.
     * Menggunakan template aktif, render DomPDF, simpan berkas fisik,
     * catat immutable snapshot & sha256 checksum ke GeneratedDocument,
     * dan sinkronisasikan ke subfolder Drive pemagang existing tanpa duplikasi folder.
     */
    public function issueDocument(
        InternshipRegistration $intern,
        string $documentType,
        array $options = [],
        ?User $actor = null
    ): GeneratedDocument {
        $canonical = self::normalizeType($documentType);

        // 1. Validasi status pemagang
        if (!$this->canIssue($intern, $canonical)) {
            throw new InvalidArgumentException(
                "Status pemagang '{$intern->internship_status}' belum memenuhi syarat untuk penerbitan dokumen {$canonical}."
            );
        }

        return DB::transaction(function () use ($intern, $canonical, $options, $actor) {
            // Row lock untuk mencegah race condition concurrent issuance
            InternshipRegistration::where('id', $intern->id)->lockForUpdate()->first();

            // 2. Resolve template aktif
            $template = !empty($options['template_id'])
                ? DocumentTemplate::findOrFail($options['template_id'])
                : $this->resolveActiveTemplate($canonical);

            // 3. Bangun konteks variabel dan aset (dengan resolusi nomor dokumen yang stabil)
            $context = $this->buildContext($intern, $canonical, $options);
            $variables = $context['variables'];
            $assetReferences = $context['asset_references'];
            $docNumber = $context['document_number'];

            // 4. Render binary PDF
            $pdfBinary = DocumentTemplateRenderer::renderPdfBinary($template, $variables);

            // 5. Publikasikan berkas fisik, catat immutable snapshot, dan sinkronkan ke Google Drive
            $generatedDoc = $this->publishingService->publishInternDocument(
                intern: $intern,
                documentType: $canonical,
                htmlContent: null,
                variableSnapshot: $variables,
                assetReferences: $assetReferences,
                customDocNumber: $docNumber,
                actor: $actor,
                pdfBinary: $pdfBinary,
                template: $template
            );

            // 6. Sinkronisasi tabel historis/legacy untuk kompatibilitas mundur 100% tanpa mengubah riwayat
            $this->syncLegacyRecord($intern, $canonical, $docNumber, $variables, $generatedDoc, $options);

            // 7. Audit log penerbitan
            if ($actor && in_array($actor->role, DocumentGeneratorService::PRIVILEGED_ROLES, true)) {
                $this->docGeneratorService->logDocumentAccess($canonical, $intern, $actor, 'generated');
            }

            return $generatedDoc;
        });
    }

    /**
     * Ulangi sinkronisasi cloud drive untuk GeneratedDocument secara aman dan idempotent.
     */
    public function retryDocumentSync(GeneratedDocument $doc, ?User $actor = null): array
    {
        return $this->publishingService->retrySync($doc, $actor);
    }

    /**
     * Sinkronisasikan rekam dokumen ke tabel legacy aplikasi tanpa merusak atau mengubah data historis.
     */
    protected function syncLegacyRecord(
        InternshipRegistration $intern,
        string $canonicalType,
        string $docNumber,
        array $variables,
        GeneratedDocument $generatedDoc,
        array $options = []
    ): void {
        $brand = $intern->brandRel;
        $companyName = $variables['perusahaan.nama'] ?? ($brand?->name ?? 'Seven Inc');
        $signatoryName = $variables['dokumen.penandatangan_nama'] ?? ($brand?->signatory_name ?? 'Ari Setia Husbana');
        $signatoryPosition = $variables['dokumen.penandatangan_jabatan'] ?? ($brand?->signatory_position ?? 'HRD');

        match ($canonicalType) {
            self::TYPE_LOA => tap(InternLoa::firstOrNew(['intern_id' => $intern->id]), function ($rec) use ($docNumber, $intern, $companyName, $brand, $signatoryName, $signatoryPosition) {
                if (!$rec->exists) {
                    $rec->created_at = now();
                }
                $rec->fill([
                    'loa_number'           => $docNumber,
                    'accepted_start_date'  => $intern->start_date ?? now(),
                    'accepted_end_date'    => $intern->end_date ?? now(),
                    'company_name'         => $companyName,
                    'company_logo_path'    => $brand?->logo ?? $rec->company_logo_path,
                    'signatory_name'       => $signatoryName,
                    'signatory_position'   => $signatoryPosition,
                    'signature_image_path' => $brand?->signature ?? $rec->signature_image_path,
                ])->save();
            }),
            self::TYPE_SKL => tap(SklDocument::firstOrNew(['intern_id' => $intern->id]), function ($rec) use ($docNumber, $companyName, $brand, $signatoryName, $signatoryPosition) {
                $rec->fill([
                    'skl_number'           => $docNumber,
                    'company_name'         => $companyName,
                    'company_logo_path'    => $brand?->logo ?? $rec->company_logo_path ?? 'images/logos/logo_seveninc.png',
                    'signatory_name'       => $signatoryName,
                    'signatory_position'   => $signatoryPosition,
                    'signature_image_path' => $brand?->signature ?? $rec->signature_image_path ?? 'images/signature/ttd_arisetiahusbana.png',
                ])->save();
            }),
            self::TYPE_CERTIFICATE => tap(InternCertificate::firstOrNew(['intern_id' => $intern->id]), function ($rec) use ($docNumber, $companyName, $brand, $signatoryName, $signatoryPosition) {
                if (!$rec->exists) {
                    $rec->created_at = now();
                }
                $rec->fill([
                    'certificate_number'    => $docNumber,
                    'company_name'          => $companyName,
                    'background_image_path' => $brand?->internship_certificate_bg ?? $rec->background_image_path,
                    'company_logo_path'     => $brand?->logo ?? $rec->company_logo_path,
                    'signatory_name'        => $signatoryName,
                    'signatory_position'    => $signatoryPosition,
                    'signature_image_path'  => $brand?->signature ?? $rec->signature_image_path,
                ])->save();
            }),
            self::TYPE_ASSESSMENT => tap(InternAssessment::firstOrNew(['intern_id' => $intern->id]), function ($rec) use ($docNumber, $companyName, $brand, $signatoryName, $signatoryPosition, $variables, $options) {
                $rec->fill([
                    'assessment_number'    => $docNumber,
                    'aspek_penilaian'      => $options['aspek_penilaian'] ?? ($rec->aspek_penilaian ?: [
                        ['aspek' => 'Kedisiplinan & Kehadiran', 'nilai' => 90],
                        ['aspek' => 'Kualitas Kerja & Ketelitian', 'nilai' => 88],
                        ['aspek' => 'Inisiatif & Kerjasama Tim', 'nilai' => 87],
                    ]),
                    'rata_rata'            => (float) ($options['rata_rata'] ?? ($rec->rata_rata ?: 88.33)),
                    'company_name'         => $companyName,
                    'company_address'      => $variables['perusahaan.alamat'] ?? ($rec->company_address ?: ''),
                    'company_logo_path'    => $brand?->logo ?? $rec->company_logo_path,
                    'signatory_name'       => $signatoryName,
                    'signatory_position'   => $signatoryPosition,
                    'signature_image_path' => $brand?->signature ?? $rec->signature_image_path,
                ])->save();
            }),
            self::TYPE_RECOMMENDATION => tap(InternExtra::firstOrNew(['intern_id' => $intern->id]), function ($rec) use ($docNumber, $companyName, $brand, $signatoryName, $signatoryPosition, $variables, $generatedDoc, $intern) {
                $rec->fill([
                    'rekomendasi_path'       => $generatedDoc->file_path,
                    'rekomendasi_url'        => $generatedDoc->drive_web_view_link,
                    'rekomendasi_granted_at' => $rec->rekomendasi_granted_at ?: now(),
                    'letter_number'          => $docNumber,
                    'brand_id'               => $intern->brand_id,
                    'company_name'           => $companyName,
                    'company_address'        => $variables['perusahaan.alamat'] ?? ($rec->company_address ?: ''),
                    'company_logo_path'      => $brand?->logo ?? $rec->company_logo_path,
                    'signatory_name'         => $signatoryName,
                    'signatory_position'     => $signatoryPosition,
                    'signature_image_path'   => $brand?->signature ?? $rec->signature_image_path,
                ])->save();
            }),
            default => null,
        };
    }

    /**
     * Generate format nomor unik sertifikat pemagang.
     */
    public function generateUniqueCertificateNumber(InternshipRegistration $intern): string
    {
        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $month = (int) date('n');
        $romanMonth = $roman[$month];
        $year = date('Y');

        $brandModel = $intern->brandRel;
        $brandStr = strtoupper($brandModel?->code ?? ($intern->brand ? strtoupper($intern->brand) : 'SI'));
        $divisionCode = $intern->division?->code ?? 'ADM';

        $last = InternCertificate::where('certificate_number', 'LIKE', "%/SERT/%/{$romanMonth}/{$year}")
            ->orderByDesc('id')
            ->first();

        $seq = 1;
        if ($last && preg_match('/^(\d{3})\/SERT\//', $last->certificate_number, $m)) {
            $seq = (int) $m[1] + 1;
        }
        $seqStr = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

        return "{$seqStr}/SERT/{$divisionCode}/SEVEN.{$brandStr}/{$romanMonth}/{$year}";
    }

    /**
     * Generate format nomor unik surat penilaian pemagang.
     */
    public function generateUniqueAssessmentNumber(InternshipRegistration $intern): string
    {
        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $month = (int) date('n');
        $romanMonth = $roman[$month];
        $year = date('Y');

        $brandModel = $intern->brandRel;
        $brandStr = strtoupper($brandModel?->code ?? ($intern->brand ? strtoupper($intern->brand) : 'SI'));
        $divisionCode = $intern->division?->code ?? 'UMUM';

        $last = InternAssessment::where('assessment_number', 'LIKE', "%/PEN/%/{$romanMonth}/{$year}")
            ->orderByDesc('id')
            ->first();

        $seq = 1;
        if ($last && preg_match('/^(\d{3})\/PEN\//', $last->assessment_number, $m)) {
            $seq = (int) $m[1] + 1;
        }
        $seqStr = str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

        return "{$seqStr}/PEN/{$divisionCode}/SEVEN.{$brandStr}/{$romanMonth}/{$year}";
    }

    /**
     * Generate format nomor unik surat rekomendasi pemagang.
     */
    public function generateUniqueRecommendationNumber(InternshipRegistration $intern): string
    {
        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $month = (int) date('n');
        $romanMonth = $roman[$month];
        $year = date('Y');

        $brandModel = $intern->brandRel;
        $brandStr = strtoupper($brandModel?->code ?? ($intern->brand ? strtoupper($intern->brand) : 'SVII'));
        $divisionCode = $intern->division?->code ?? 'UMUM';

        $running = str_pad((string) $intern->id, 3, '0', STR_PAD_LEFT);
        return "{$running}/SR/{$divisionCode}/SEVEN.{$brandStr}/{$romanMonth}/{$year}";
    }

    protected function getDefaultRecommendationBody(
        InternshipRegistration $intern,
        string $companyName,
        string $duration
    ): string {
        $division = $intern->division?->name ?? ($intern->internship_interest ?? 'divisi kerja');
        return "Dengan ini menerangkan bahwa pemagang tersebut di atas telah menyelesaikan program magang selama {$duration} di divisi {$division} {$companyName} dengan dedikasi, integritas, dan performa kerja yang sangat baik. Kami merekomendasikan yang bersangkutan untuk berkarier secara profesional.";
    }
}
