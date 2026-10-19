<?php

namespace App\Http\Controllers;

use App\Models\InternCertificate;
use App\Models\WebinarCertificate;
use App\Models\InternshipRegistration as IR;
use App\Models\Brand;
use App\Models\WebinarAttendance;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Facades\View;

class CertificateController extends Controller
{
    public function index()
    {
        $internCerts = InternCertificate::with('intern')->orderByDesc('id')->get()->map(function($cert) {
            $cert->type = 'intern';
            $cert->name = $cert->intern->fullname ?? '-';
            $cert->division = $cert->intern->internship_interest ?? 'ADM';
            $cert->company = $cert->company_name;
            $cert->brand = $cert->intern->brand ?? '-';
            $cert->serial_number = $cert->certificate_number;
            return $cert;
        });

        $webinarCerts = WebinarCertificate::with('attendance.user')->orderByDesc('id')->get()->map(function($cert) {
            $cert->type = 'webinar';
            $cert->name = $cert->attendance->user->name ?? '-';
            $cert->division = 'Webinar';
            $cert->company = $cert->company_name;
            $cert->brand = '-';
            $cert->serial_number = $cert->certificate_number;
            return $cert;
        });

        $certificates = $internCerts->concat($webinarCerts)->sortByDesc('id')->values();

        return view('certificates.index', compact('certificates'));
    }

    public function storeFromInterns(Request $request)
    {
        $request->validate([
            'intern_ids'   => ['required','array','min:1'],
            'intern_ids.*' => ['integer','exists:internship_registrations,id'],
        ]);

        $interns = IR::whereIn('id', $request->intern_ids)->get();
        if ($interns->isEmpty()) {
            return back()->withErrors(['intern_ids'=>'Data pemagang tidak ditemukan'])->withInput();
        }

        $seqCache = [];
        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];

        $issuanceService = app(\App\Services\InternDocumentIssuanceService::class);

        foreach ($interns as $ir) {
            $end = Carbon::parse($ir->end_date);
            $brandData = Brand::where('code', $ir->brand)->first();
            $divisionCode = $this->divisionFromInterest((string)$ir->internship_interest) ?? 'ADM';

            $ym = $end->format('Y-m');
            if (!isset($seqCache[$ym])) {
                $last = InternCertificate::whereYear('created_at', $end->year)
                    ->whereMonth('created_at', $end->month)
                    ->orderByDesc('id')->first();
                $seq = 1;
                if ($last && preg_match('/^(\d{3})\/SERT\//', $last->certificate_number, $m)) {
                    $seq = (int)$m[1] + 1;
                }
                $seqCache[$ym] = $seq;
            }
            $seq = $seqCache[$ym]++;
            $seqStr = str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
            
            $companyCode = $this->companyCode($brandData?->name ?? 'Seven Inc');
            $brandCode = strtoupper($ir->brand ?? 'SI');
            $serial = "{$seqStr}/SERT/{$divisionCode}/{$companyCode}.{$brandCode}/".$roman[$end->month]."/".$end->year;

            $issuanceService->issueDocument(
                intern: $ir,
                documentType: 'certificate',
                options: ['document_number' => $serial],
                actor: auth()->user()
            );
        }

        return redirect()->route('admin.certificate.index')->with('success', 'Sertifikat pemagang berhasil dibuat.');
    }

    public function storeWebinar(Request $request)
    {
        $request->validate([
            'attendance_ids'   => ['required','array','min:1'],
            'attendance_ids.*' => ['integer','exists:webinar_attendances,id'],
        ]);

        $attendances = WebinarAttendance::with(['user', 'webinar'])->whereIn('id', $request->attendance_ids)->get();
        if ($attendances->isEmpty()) {
            return back()->withErrors(['attendance_ids'=>'Data kehadiran tidak ditemukan'])->withInput();
        }

        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        
        // Brand for webinar is assumed to be SI for Seven Inc, but we could fetch default brand
        $brandData = Brand::where('code', 'SI')->first(); 
        
        $seqCache = [];
        DB::transaction(function () use ($attendances, $roman, $brandData, &$seqCache) {
            foreach ($attendances as $attendance) {
                $webinar = $attendance->webinar;
                $endDate = Carbon::parse($webinar->tanggal);
                $ym = $endDate->format('Y-m');
                
                if (!isset($seqCache[$ym])) {
                    $last = WebinarCertificate::whereYear('created_at', $endDate->year)
                        ->whereMonth('created_at', $endDate->month)
                        ->orderByDesc('id')
                        ->first();
                    $seq = 1;
                    if ($last && preg_match('/^(\d{3})\/SERT\//', $last->certificate_number, $m)) {
                        $seq = (int)$m[1] + 1;
                    }
                    $seqCache[$ym] = $seq;
                }
                
                $seq = $seqCache[$ym]++;
                $seqStr = str_pad($seq, 3, '0', STR_PAD_LEFT);
                $serial = "{$seqStr}/SERT/WBN/SI.SI/".$roman[$endDate->month]."/".$endDate->year;

                $cert = WebinarCertificate::create([
                    'attendance_id'         => $attendance->id,
                    'certificate_number'    => $serial,
                    'company_name'          => $brandData?->name ?? 'Seven Inc',
                    'background_image_path' => $brandData?->webinar_certificate_bg,
                    'company_logo_path'     => $brandData?->logo,
                    'signatory_name'        => $brandData?->signatory_name ?? 'Ari Setia Husbana',
                    'signatory_position'    => $brandData?->signatory_position ?? 'HRD',
                    'signature_image_path'  => $brandData?->signature,
                ]);

                $attendance->update([
                    'reviewed_by'    => auth()->id(),
                    'reviewed_at'    => now(),
                ]);
            }
        });

        return redirect()->route('admin.certificate.index')->with('success', 'Sertifikat webinar berhasil dibuat.');
    }

    public function downloadUserPdf(Request $request)
    {
        $authUser = auth()->user();
        $internId = $request->get('intern_id');

        if (!$internId && $authUser) {
            $lifecycle = app(\App\Services\RegistrationLifecycleService::class);
            $ir = $lifecycle->resolveRegistrationForDocuments($authUser, documentType: 'certificate');
            $internId = $ir?->id;
        }

        $certificate = InternCertificate::where('intern_id', $internId)->firstOrFail();

        // Enforce ownership tampering check: non-admin can only download their own certificate
        if ($authUser && $authUser->role !== 'admin' && (int) $certificate->intern?->user_id !== (int) $authUser->id) {
            abort(403, 'Anda tidak berhak mengakses sertifikat milik pengguna lain.');
        }

        $nameSlug  = Str::slug($certificate->intern->fullname ?? 'cert', '-');
        $fileName  = "Sertifikat-{$nameSlug}.pdf";
        $fullPath = public_path("storage/documents/certificates/{$fileName}");

        if (!file_exists($fullPath)) {
            // Jika file tidak ada, mungkin belum digenerate oleh job
            // Fallback: generate on the fly
            return $this->generatePdf($certificate, 'intern');
        }

        return response()->download($fullPath);
    }

    public function downloadPdf($id)
    {
        // For backwards compatibility with the generic route
        if ($cert = InternCertificate::find($id)) {
            return $this->downloadInternPdf($cert);
        }
        if ($cert = WebinarCertificate::find($id)) {
            return $this->downloadWebinarPdf($cert);
        }
        abort(404, 'Sertifikat tidak ditemukan');
    }

    public function downloadInternPdf(InternCertificate $certificate)
    {
        return $this->generatePdf($certificate, 'intern');
    }

    public function downloadWebinarPdf(WebinarCertificate $certificate)
    {
        return $this->generatePdf($certificate, 'webinar');
    }

    protected function generatePdf($certificate, $type)
    {
        $nameSlug  = Str::slug($certificate->intern->fullname ?? $certificate->attendance->user->name ?? 'cert', '-');
        $pdfTitle  = "Sertifikat-{$nameSlug}";

        $viewName  = $type === 'webinar' ? 'certificates.webinar-pdf' : 'certificates.pdf';
        $filename  = "{$pdfTitle}.pdf";

        $html = View::make($viewName, [
            'certificate' => $certificate,
            'pdfTitle'    => $pdfTitle,
        ])->render();

        $tmpPath = storage_path('app/tmp/' . $filename);
        if (!is_dir(dirname($tmpPath))) {
            mkdir(dirname($tmpPath), 0775, true);
        }

        $shot = Browsershot::html($html)
            ->format('A4')
            ->landscape()
            ->margins(0, 0, 0, 0)
            ->timeout(180)
            ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox']);

        $shot->emulateMedia('screen')
             ->showBackground()
             ->setOption('printBackground', true);

        $shot->savePdf($tmpPath);

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
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
        $map = [
            'administration'=>'ADM','administrasi'=>'ADM',
            'uiux'=>'UIUX','ui-ux'=>'UIUX','ui/ux'=>'UIUX',
            'programmer'=>'PROG','programmer (front end / backend)'=>'PROG',
            'hr'=>'HR','human resources (hr)'=>'HR',
            'social-media-specialist'=>'SMM','spesialis media sosial'=>'SMM',
            'photographer'=>'PV','videographer'=>'VID','fotografer'=>'PV','videografer'=>'VID',
            'content-writer'=>'CW','penulis konten'=>'CW',
            'marketing-and-sales'=>'MS','penjualan & pemasaran'=>'MS','penjualan dan pemasaran'=>'MS',
            'graphic-designer'=>'CD','desainer grafis'=>'CD',
            'digital-marketing'=>'DM','pemasaran digital'=>'DM',
            'public-relation'=>'PR','public relations (marcomm)'=>'PR','hubungan masyarakat (marcomm)'=>'PR',
            'tiktok-creator'=>'TC','kreator tiktok'=>'TC',
            'content-planner'=>'CP','perencana konten'=>'CP',
            'project-manager'=>'PM','manajer proyek'=>'PM',
            'welding'=>'LAS','pengelasan'=>'LAS',
            'animation'=>'ANIM','animasi'=>'ANIM',
        ];
        $key = Str::of($interest)->lower()->replace('/', '-')->toString();
        return $map[$key] ?? null;
    }
}
