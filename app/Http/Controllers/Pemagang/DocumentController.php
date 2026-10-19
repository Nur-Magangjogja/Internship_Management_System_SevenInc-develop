<?php

namespace App\Http\Controllers\Pemagang;

use App\Http\Controllers\Controller;
use App\Models\InternshipRegistration as IR;
use App\Models\DocumentDownload;
use App\Models\InternAssessment;
use App\Models\InternExtra;
use App\Models\WebinarAttendance;
use App\Models\InternLoa;
use App\Models\InternCertificate;
use App\Models\WebinarCertificate;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Halaman Dokumen Saya — tampilkan card dokumen sesuai status.
     *
     * Dokumen yang ditampilkan:
     * - Bukti Pendaftaran  → tersedia setelah submit form
     * - Surat Diterima     → tersedia setelah status = accepted
     * - LOA                → tersedia setelah admin generate (doc_downloads)
     * - SKL                → tersedia setelah admin generate (doc_downloads)
     * - Sertifikat         → tersedia setelah admin generate (certificates table)
     * - Surat Penilaian    → tersedia setelah admin generate (intern_assessments)
     * - Membercard         → tersedia setelah admin generate (downloads table)
     */
    public function index()
    {
        $user         = auth()->user();
        $regId        = request()->query('registration_id');
        $lifecycle    = app(\App\Services\RegistrationLifecycleService::class);
        $registration = $lifecycle->resolveRegistrationForDocuments($user, $regId ? (int) $regId : null);

        $status = $registration?->internship_status;
        $isCompleted = $status === IR::STATUS_COMPLETED;
        $isAccepted  = in_array($status, [IR::STATUS_ACCEPTED, IR::STATUS_ACTIVE, IR::STATUS_COMPLETED]);

        // --- Cek apakah masing-masing dokumen sudah di-generate admin ---

        // SKL: Karena SKL sekarang digenerate secara on-the-fly,
        // SKL selalu tersedia jika status magang sudah selesai.
        $sklDownload = null;
        if ($registration && $isCompleted) {
            $sklDownload = true;
        }

        // LOA: cek di tabel intern_loas, jika berstatus accepted/active tapi belum ada, buat otomatis
        $loaRecord = null;
        if ($registration && $isAccepted) {
            $loaRecord = InternLoa::where('intern_id', $registration->id)->latest('id')->first();
            if (!$loaRecord) {
                try {
                    $loaRecord = app(\App\Services\DocumentGeneratorService::class)->ensureLoaGenerated($registration);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("Gagal auto-generate LOA pemagang #{$registration->id}: " . $e->getMessage());
                }
            }
        }

        // Sertifikat: cek di tabel intern_certificates berdasarkan intern_id
        $sertifikatRecord = null;
        if ($isCompleted && $registration) {
            $sertifikatRecord = InternCertificate::where('intern_id', $registration->id)->latest('id')->first();
        }

        // Surat Penilaian: cek di intern_assessments
        $assessmentRecord = null;
        if ($isCompleted && $registration) {
            $assessmentRecord = InternAssessment::where('intern_id', $registration->id)->latest()->first();
        }

        // Membercard: cek di tabel alumni_membercards
        $membercardRecord = ($isCompleted && $registration)
            ? \App\Models\AlumniMembercard::where('intern_id', $registration->id)->latest()->first()
            : null;

        // Tentukan availability tiap dokumen
        $docs = [
            'loa' => [
                'label'       => 'LOA (Letter of Acceptance)',
                'description' => 'Surat penerimaan magang dari perusahaan',
                'icon'        => 'fa-file-signature',
                'available'   => $isAccepted && $loaRecord !== null,
                'pending'     => $isAccepted && $loaRecord === null,
                'route'       => null, // pakai form POST di view karena butuh intern_id
                'intern_id'   => $registration?->id,
                'date'        => $loaRecord?->created_at,
            ],
            'skl' => [
                'label'       => 'SKL (Surat Keterangan Lulus)',
                'description' => 'Diberikan setelah magang selesai',
                'icon'        => 'fa-certificate',
                'available'   => $isCompleted && $sklDownload !== null,
                'pending'     => $isCompleted && $sklDownload === null,
                'route'       => ($isCompleted)
                    ? route('user.skl.download', ['intern_id' => $registration->id])
                    : null,
                'date'        => null,
            ],
            'sertifikat' => [
                'label'       => 'Sertifikat Magang',
                'description' => 'Diberikan setelah masa magang selesai',
                'icon'        => 'fa-award',
                'available'   => $isCompleted && $sertifikatRecord !== null,
                'pending'     => $isCompleted && $sertifikatRecord === null,
                'route'       => ($isCompleted && $sertifikatRecord !== null)
                    ? route('pemagang.documents.sertifikat')
                    : null,
                'date'        => null,
            ],
            'surat_penilaian' => [
                'label'       => 'Surat Penilaian',
                'description' => 'Penilaian kinerja selama magang',
                'icon'        => 'fa-star-half-alt',
                'available'   => $isCompleted && $assessmentRecord !== null,
                'pending'     => $isCompleted && $assessmentRecord === null,
                'route'       => ($isCompleted && $assessmentRecord !== null)
                    ? route('pemagang.documents.surat_penilaian')
                    : null,
                'date'        => null,
            ],
            'membercard' => [
                'label'       => 'Membercard Digital',
                'description' => 'Kartu anggota alumni magang Seveninc',
                'icon'        => 'fa-id-card',
                'available'   => $isCompleted && $membercardRecord !== null,
                'pending'     => $isCompleted && $membercardRecord === null,
                'route'       => ($isCompleted && $membercardRecord !== null)
                    ? route('pemagang.membercard')
                    : null,
                'date'        => null,
            ],
        ];

        // Sertifikat Webinar — ambil dari webinar_certificates via relasi attendance
        $webinarCerts = WebinarCertificate::whereHas('attendance', function ($q) use ($user) {
            $q->where('user_id', $user->id)
              ->where('status', WebinarAttendance::STATUS_APPROVED);
        })->with(['attendance.webinar'])->latest('id')->get();

        // Riwayat download dihapus karena tabel document_downloads dihapus
        $downloadHistory = collect([]);

        // Extras — surat rekomendasi, alumni group, job info
        $extras = $registration
            ? InternExtra::where('intern_id', $registration->id)->first()
            : null;

        return view('pemagang.documents.index', compact(
            'user',
            'registration',
            'docs',
            'downloadHistory',
            'extras',
            'webinarCerts'
        ));
    }

    /**
     * Halaman lihat & download Membercard digital milik pemagang (2D PDF).
     * Hanya tersedia setelah status = completed.
     */
    public function viewMembercard(Request $request)
    {
        $user       = auth()->user();
        $lifecycle  = app(\App\Services\RegistrationLifecycleService::class);
        $regId      = $request->query('registration_id');
        $reg        = $lifecycle->resolveRegistrationForDocuments($user, $regId ? (int) $regId : null, 'membercard');

        // Membercard hanya tersedia setelah selesai magang
        if (!$reg || $reg->internship_status !== IR::STATUS_COMPLETED) {
            return back()->with('error', 'Membercard hanya tersedia setelah masa magang selesai.');
        }

        $membercard = \App\Models\AlumniMembercard::with(['intern.brandRel', 'intern.institution', 'intern.division'])
            ->where('intern_id', $reg->id)->latest()->first();

        if (!$membercard) {
            return back()->with('error', 'Membercard belum tersedia. Hubungi admin.');
        }

        $brandObj = $membercard->intern?->brandRel;

        return view('pemagang.membercard', compact('membercard', 'reg', 'brandObj'));
    }

    /**
     * Download Membercard sebagai PDF.
     * Hanya tersedia setelah status = completed.
     */
    public function downloadMembercard(Request $request)
    {
        $user       = auth()->user();
        $lifecycle  = app(\App\Services\RegistrationLifecycleService::class);
        $regId      = $request->query('registration_id');
        $reg        = $lifecycle->resolveRegistrationForDocuments($user, $regId ? (int) $regId : null, 'membercard');

        // Membercard hanya tersedia setelah selesai magang
        if (!$reg || $reg->internship_status !== IR::STATUS_COMPLETED) {
            abort(403, 'Membercard hanya tersedia setelah masa magang selesai.');
        }

        $membercard = \App\Models\AlumniMembercard::with(['intern.brandRel', 'intern.institution', 'intern.division'])
            ->where('intern_id', $reg->id)->latest()->first();

        if (!$membercard) {
            return back()->with('error', 'Membercard belum tersedia. Hubungi admin.');
        }

        $data = [
            'name'     => $membercard->intern->fullname,
            'code'     => $membercard->member_code,
            'brand'    => $membercard->intern->brand ?? 'magangjogja.com',
            'brandObj' => $membercard->intern?->brandRel,
            'angkatan' => $membercard->batch_year,
            'instansi' => $membercard->intern->institution_name,
            'divisi'   => $membercard->intern->division?->name ?? $membercard->intern->internship_interest,
        ];

        // Pakai Browsershot — set ukuran persis kartu kredit standar (85.6 × 54mm)
        $html = view('pemagang.membercard-pdf', $data)->render();

        $safeName = \Illuminate\Support\Str::slug($membercard->intern->fullname);
        $filename = "Membercard-{$safeName}-{$membercard->member_code}.pdf";
        $tmpPath  = storage_path("app/tmp/{$filename}");

        if (!is_dir(dirname($tmpPath))) {
            mkdir(dirname($tmpPath), 0775, true);
        }

        // Ukuran kartu kredit standar: 85.6mm × 53.98mm
        // @ 96dpi: 85.6mm ÷ 25.4 × 96 ≈ 323px wide, 53.98mm ÷ 25.4 × 96 ≈ 204px high
        // Scale 2× untuk kualitas tinggi → 646 × 408
        \Spatie\Browsershot\Browsershot::html($html)
            ->emulateMedia('screen')
            ->showBackground()
            ->margins(0, 0, 0, 0)
            ->windowSize(646, 408)
            ->deviceScaleFactor(2)
            ->paperSize(85.6, 53.98, 'mm')
            ->setOption('printBackground', true)
            ->timeout(60)
            ->savePdf($tmpPath);

        // Update status has_downloaded
        if (!$membercard->has_downloaded) {
            $membercard->update([
                'has_downloaded' => true,
                'downloaded_at'  => now(),
            ]);
        }

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Download Sertifikat milik pemagang yang login.
     * Cari sertifikat berdasarkan fullname pemagang di tabel certificates.
     */
    public function downloadSertifikat(Request $request)
    {
        $user         = auth()->user();
        $lifecycle    = app(\App\Services\RegistrationLifecycleService::class);
        $regId        = $request->query('registration_id');
        $registration = $lifecycle->resolveRegistrationForDocuments($user, $regId ? (int) $regId : null, 'certificate');

        if (!$registration || $registration->internship_status !== IR::STATUS_COMPLETED) {
            abort(403, 'Sertifikat hanya tersedia setelah magang selesai.');
        }

        // Cari sertifikat berdasarkan intern_id
        $certificate = InternCertificate::where('intern_id', $registration->id)->latest('id')->first();

        if (!$certificate) {
            return back()->with('error', 'Sertifikat belum tersedia. Hubungi admin.');
        }

        // Delegate ke CertificateController
        return app(\App\Http\Controllers\CertificateController::class)
            ->downloadInternPdf($certificate);
    }

    /**
     * Download Sertifikat Webinar milik pemagang yang login.
     * Validasi bahwa attendance ini benar-benar milik user yang sedang login.
     */
    public function downloadSertifikatWebinar(WebinarCertificate $certificate)
    {
        $user = auth()->user();

        // Pastikan sertifikat ini memang milik user — cek via webinar_attendances
        $ownership = WebinarAttendance::where('user_id', $user->id)
            ->where('certificate_id', $certificate->id)
            ->where('status', WebinarAttendance::STATUS_APPROVED)
            ->exists();

        if (!$ownership) {
            abort(403, 'Anda tidak memiliki akses ke sertifikat ini.');
        }

        // Delegate ke CertificateController
        return app(\App\Http\Controllers\CertificateController::class)
            ->downloadWebinarPdf($certificate);
    }

    /**
     * Download Surat Rekomendasi milik pemagang yang login.
     * Hanya tersedia jika admin sudah memberikan (rekomendasi_path diisi).
     */
    public function downloadRekomendasi(Request $request)
    {
        $user         = auth()->user();
        $lifecycle    = app(\App\Services\RegistrationLifecycleService::class);
        $regId        = $request->query('registration_id');
        $registration = $lifecycle->resolveRegistrationForDocuments($user, $regId ? (int) $regId : null, 'rekomendasi');

        if (!$registration || $registration->internship_status !== IR::STATUS_COMPLETED) {
            abort(403, 'Surat rekomendasi hanya tersedia setelah magang selesai.');
        }

        $extra = \App\Models\InternExtra::where('intern_id', $registration->id)->first();

        if (!$extra || !$extra->rekomendasi_path) {
            return back()->with('error', 'Surat rekomendasi belum tersedia. Hubungi admin.');
        }

        $fullPath = storage_path('app/public/' . $extra->rekomendasi_path);

        if (!file_exists($fullPath)) {
            return back()->with('error', 'File surat rekomendasi tidak ditemukan. Hubungi admin.');
        }

        $filename = 'Surat-Rekomendasi-' . \Illuminate\Support\Str::slug($registration->fullname) . '.pdf';
        return response()->download($fullPath, $filename, ['Content-Type' => 'application/pdf']);
    }

    /**
     * Download Surat Penilaian milik pemagang yang login.
     * Cari assessment berdasarkan intern_id (FK ke internship_registrations).
     */
    public function downloadSuratPenilaian(Request $request)
    {
        $user         = auth()->user();
        $lifecycle    = app(\App\Services\RegistrationLifecycleService::class);
        $regId        = $request->query('registration_id');
        $registration = $lifecycle->resolveRegistrationForDocuments($user, $regId ? (int) $regId : null, 'assessment');

        if (!$registration || $registration->internship_status !== IR::STATUS_COMPLETED) {
            abort(403, 'Surat penilaian hanya tersedia setelah magang selesai.');
        }

        // Cari assessment berdasarkan intern_id
        $assessment = InternAssessment::where('intern_id', $registration->id)->latest()->first();

        if (!$assessment) {
            return back()->with('error', 'Surat penilaian belum tersedia. Hubungi admin.');
        }

        // Delegate ke InternAssessmentController
        return app(\App\Http\Controllers\InternAssessmentController::class)
            ->downloadPDF($assessment->id);
    }
}
