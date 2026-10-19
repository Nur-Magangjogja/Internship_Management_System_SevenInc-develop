<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\FollowUpCycle;
use App\Models\Institution;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Services\MouDocumentRenderer;
use App\Services\MouService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MouController extends Controller
{
    public function __construct(
        protected MouService $mouService
    ) {}

    /**
     * Pastikan pengguna saat ini berwenang mengakses dokumen MOU terkait.
     */
    protected function authorizeMouAccess(Mou $mou): void
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['admin', 'hr_staff'], true)) {
            abort(403, 'Anda tidak memiliki hak akses terhadap dokumen MOU ini.');
        }

        if ($mou->trashed()) {
            abort(404, 'Dokumen MOU tidak ditemukan.');
        }
    }

    /**
     * Tampilkan daftar seluruh dokumen MOU beserta filter pencarian dan status.
     */
    public function index(Request $request)
    {
        $query = Mou::with(['institution', 'generatedBy', 'signedUploadedBy'])
            ->latest('id');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('company_mou_number', 'like', "%{$search}%")
                  ->orWhere('institution_mou_number', 'like', "%{$search}%")
                  ->orWhere('first_party_institution_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('institution_id')) {
            $query->where('institution_id', $request->institution_id);
        }

        $mous = $query->paginate(15)->withQueryString();

        $statsRow = Mou::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as `active`,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as `generated`,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as `delivered`
        ", [Mou::STATUS_ACTIVE, Mou::STATUS_GENERATED, Mou::STATUS_DELIVERED])->first();

        $stats = [
            'total'     => (int) ($statsRow->total ?? 0),
            'active'    => (int) ($statsRow->active ?? 0),
            'generated' => (int) ($statsRow->generated ?? 0),
            'delivered' => (int) ($statsRow->delivered ?? 0),
        ];

        $institutions = Institution::select('id', 'name')->active()->orderBy('name')->get();

        return view('hr.mou.index', compact('mous', 'stats', 'institutions'));
    }

    /**
     * Formulir pembuatan dan generate naskah MOU baru.
     */
    public function create(Request $request)
    {
        $selectedInstitutionId = $request->input('institution_id');
        $selectedCycleId       = $request->input('cycle_id');

        $selectedInstitution = $selectedInstitutionId
            ? Institution::with('contacts')->find($selectedInstitutionId)
            : null;

        $selectedCycle = $selectedCycleId
            ? FollowUpCycle::with(['institution.contacts', 'contact'])->find($selectedCycleId)
            : null;

        if (!$selectedInstitution && $selectedCycle) {
            $selectedInstitution = $selectedCycle->institution;
        }

        $selectedContact = $selectedCycle?->contact
            ?? ($request->filled('contact_id') ? InstitutionContact::find($request->contact_id) : null)
            ?? $selectedInstitution?->contacts->first();

        // Ambil siklus follow-up yang completed & willing untuk institusi terpilih
        $eligibleCycles = $selectedInstitution
            ? FollowUpCycle::where('institution_id', $selectedInstitution->id)
                ->where('status', FollowUpCycle::STATUS_COMPLETED)
                ->where('final_result', FollowUpCycle::RESULT_WILLING)
                ->with('contact')
                ->latest('id')
                ->get()
            : collect();

        if (!$selectedCycle && $eligibleCycles->count() === 1) {
            $selectedCycle = $eligibleCycles->first();
            if (!$selectedContact) {
                $selectedContact = $selectedCycle->contact;
            }
        }

        $availableTemplates = MouTemplate::orderByDesc('is_active')
            ->orderByDesc('version_number')
            ->orderByDesc('id')
            ->get();

        $activeTemplate = $availableTemplates->firstWhere('is_active', true) ?? $availableTemplates->first();
        $selectedTemplateId = $request->input('mou_template_id');
        $selectedTemplate = $selectedTemplateId
            ? ($availableTemplates->firstWhere('id', (int) $selectedTemplateId) ?? $activeTemplate)
            : $activeTemplate;

        $institutions   = Institution::active()->orderBy('name')->get();

        // Rekomendasi nomor surat Seven Inc sesuai format standar
        $year = date('Y');
        $romanMonths = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'];
        $romanMonth = $romanMonths[(int) date('n')] ?? 'I';
        $nextNumber = str_pad((Mou::whereYear('created_at', $year)->count() + 1), 3, '0', STR_PAD_LEFT);
        $suggestedNumber = "{$nextNumber}/SPK.MAGANG/CEO/SEVEN/{$romanMonth}/{$year}";

        // Cek apakah institusi terpilih telah memiliki MOU yang sedang aktif
        $activeMou = $selectedInstitution ? $selectedInstitution->activeMou : null;

        return view('hr.mou.create', compact(
            'institutions',
            'selectedInstitution',
            'selectedCycle',
            'selectedContact',
            'eligibleCycles',
            'availableTemplates',
            'selectedTemplate',
            'activeTemplate',
            'suggestedNumber',
            'activeMou'
        ));
    }

    /**
     * Simpan data dan generate dokumen MOU.
     */
    public function store(Request $request)
    {
        $request->validate([
            'institution_id'                  => 'required|exists:institutions,id',
            'company_mou_number'              => 'required|string|max:100|unique:mous,company_mou_number',
            'institution_mou_number'          => 'nullable|string|max:100',
            'agreement_place'                 => 'required|string|max:100',
            'agreement_date'                  => 'required|date',
            'effective_start'                 => 'required|date',
            'effective_end'                   => 'required|date|after:effective_start',
            'agreement_duration'              => 'nullable|string|max:100',
            'first_party_institution_name'    => 'required|string|max:255',
            'first_party_signatory_name'      => 'required|string|max:255',
            'first_party_signatory_title'     => 'required|string|max:255',
            'first_party_address'             => 'nullable|string|max:500',
            'first_party_phone'               => 'nullable|string|max:100',
            'first_party_fax'                 => 'nullable|string|max:100',
            'first_party_email'               => 'nullable|email|max:150',
            'first_party_correspondence_unit' => 'nullable|string|max:255',
            'second_party_company_name'       => 'required|string|max:255',
            'second_party_signatory_name'     => 'required|string|max:255',
            'second_party_signatory_title'    => 'required|string|max:255',
            'second_party_address'            => 'nullable|string|max:500',
            'second_party_phone'              => 'nullable|string|max:100',
            'second_party_fax'                => 'nullable|string|max:100',
            'second_party_email'              => 'nullable|email|max:150',
            'second_party_correspondence_unit'=> 'nullable|string|max:255',
            'notes'                           => 'nullable|string|max:1000',
            'cooperation_confirmed'           => 'nullable|boolean',
            'mou_template_id'                 => 'nullable|exists:mou_templates,id',
            'followup_cycle_id'               => 'required|exists:followup_cycles,id',
            'supersedes_mou_id'               => 'nullable|exists:mous,id',
            'supersede_reason'                => 'nullable|string|max:500',
        ]);

        try {
            $mou = $this->mouService->generateMou($request->all(), auth()->user());

            $routePrefix = request()->routeIs('hr.*') ? 'hr.mous' : 'admin.mous';

            return redirect()->route($routePrefix . '.show', $mou)
                ->with('success', "Dokumen MOU {$mou->company_mou_number} berhasil dibuat dan disimpan.");
        } catch (\InvalidArgumentException|\RuntimeException|\DomainException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Tampilkan detail dokumen MOU, riwayat lifecycle, dan pratinjau naskah.
     */
    public function show(Mou $mou)
    {
        $this->authorizeMouAccess($mou);

        $mou->load(['institution', 'followupCycle', 'template', 'generatedBy', 'deliveredBy', 'signedUploadedBy', 'supersededBy', 'supersedes']);

        $pageConfig   = $mou->render_data_snapshot['_page_config'] ?? MouTemplate::getDefaultPageConfig();
        $canonicalCss = MouDocumentRenderer::getCanonicalDocumentCss($pageConfig);

        return view('hr.mou.show', compact('mou', 'pageConfig', 'canonicalCss'));
    }

    /**
     * Tampilan cetak / pratinjau layout lembar A4 naskah MOU.
     */
    public function print(Mou $mou)
    {
        $this->authorizeMouAccess($mou);

        $pageConfig   = $mou->render_data_snapshot['_page_config'] ?? MouTemplate::getDefaultPageConfig();
        $canonicalCss = MouDocumentRenderer::getCanonicalDocumentCss($pageConfig);

        return view('hr.mou.print', compact('mou', 'pageConfig', 'canonicalCss'));
    }

    /**
     * Ekspor / Unduh PDF A4 langsung dari snapshot dokumen MOU yang telah diterbitkan.
     * Menggunakan snapshot mandiri (rendered_document_html & render_data_snapshot) agar data historis tidak berubah.
     */
    public function downloadPdf(Mou $mou)
    {
        $this->authorizeMouAccess($mou);

        if (empty($mou->rendered_document_html)) {
            abort(404, 'Konten naskah dokumen MOU tidak ditemukan.');
        }

        $pageConfig = $mou->render_data_snapshot['_page_config'] ?? MouTemplate::getDefaultPageConfig();

        $pdfBinary = MouDocumentRenderer::renderPdfBinary(
            $mou->rendered_document_html,
            $pageConfig,
            $mou->company_mou_number . ' - ' . $mou->first_party_institution_name
        );

        $safeFileName = 'MOU-' . Str::slug(str_replace('/', '-', $mou->company_mou_number), '-') . '.pdf';

        return response($pdfBinary, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $safeFileName . '"',
        ]);
    }

    /**
     * Catat status penyerahan / pengiriman naskah ke institusi mitra.
     */
    public function markDelivered(Request $request, Mou $mou)
    {
        $this->authorizeMouAccess($mou);

        $request->validate([
            'delivery_method'    => 'required|string|max:50',
            'delivery_recipient' => 'nullable|string|max:255',
            'delivered_at'       => 'required|date',
            'delivery_notes'     => 'nullable|string|max:1000',
        ]);

        $this->mouService->markDelivered($mou, $request->all(), auth()->user());

        return back()->with('success', "Status dokumen MOU {$mou->company_mou_number} telah diperbarui menjadi 'Delivered'.");
    }

    /**
     * Unggah berkas naskah PDF yang telah ditandatangani lengkap & aktifkan MOU.
     */
    public function uploadSigned(Request $request, Mou $mou)
    {
        $this->authorizeMouAccess($mou);

        if ($mou->status !== Mou::STATUS_DELIVERED) {
            return back()->withErrors(['signed_file' => "Naskah bertandatangan hanya dapat diunggah jika status dokumen adalah 'Delivered' (telah diserahkan ke mitra)."]);
        }

        $request->validate([
            'signed_file'            => 'required|file|mimes:pdf|max:10240', // Max 10MB
            'signed_at'              => 'required|date',
            'institution_mou_number' => 'nullable|string|max:100',
            'effective_start'        => 'nullable|date',
            'effective_end'          => 'nullable|date|after:effective_start',
        ]);

        try {
            $this->mouService->uploadSignedMou(
                $mou,
                $request->file('signed_file'),
                $request->all(),
                auth()->user()
            );

            return back()->with('success', "Berkas bertandatangan berhasil diunggah. Dokumen MOU {$mou->company_mou_number} kini berstatus AKTIF.");
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            return back()->withErrors(['signed_file' => $e->getMessage()]);
        }
    }

    /**
     * Unduh berkas naskah yang telah ditandatangani secara aman.
     */
    public function downloadSigned(Mou $mou)
    {
        $this->authorizeMouAccess($mou);

        if (empty($mou->signed_file_path) || !Storage::disk('public')->exists($mou->signed_file_path)) {
            abort(404, 'Berkas bertandatangan tidak ditemukan atau belum diunggah.');
        }

        $safeName = Str::slug($mou->company_mou_number, '-') . '-signed.pdf';

        return Storage::disk('public')->download($mou->signed_file_path, $safeName);
    }

    /**
     * Batalkan naskah dokumen MOU.
     */
    public function cancel(Request $request, Mou $mou)
    {
        $this->authorizeMouAccess($mou);

        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        $this->mouService->cancelMou($mou, $request->cancel_reason, auth()->user());

        return back()->with('success', "Dokumen MOU {$mou->company_mou_number} berhasil dibatalkan.");
    }

    /**
     * Sinkronisasikan berkas MOU ke Cloud Storage / Google Drive.
     */
    public function syncDrive(Mou $mou, \App\Services\CloudStorage\CloudSyncManager $syncManager)
    {
        $this->authorizeMouAccess($mou);

        try {
            $result = $syncManager->syncMou($mou, auth()->user());

            return back()->with('success', "Dokumen MOU {$mou->company_mou_number} berhasil disinkronkan ke Cloud Storage ({$result['driver']}).");
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal menyinkronkan MOU ke cloud: ' . $e->getMessage());
        }
    }
}
