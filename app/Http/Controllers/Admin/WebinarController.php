<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webinar;
use App\Models\WebinarAttendance;
use App\Models\InternshipRegistration as IR;
use App\Models\Certificate;
use App\Models\DocumentDownload;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebinarController extends Controller
{
    // ===== CRUD WEBINAR =====

    public function index()
    {
        $webinars = Webinar::withCount(['attendances', 'approvedAttendances', 'pendingAttendances'])
            ->latest()
            ->paginate(20);

        return view('admin.webinars.index', compact('webinars'));
    }

    public function create()
    {
        \App\Models\DocumentTemplate::ensureDefaultTemplatesExist();

        $brands = Brand::all();
        $certificateTemplates = \App\Models\DocumentTemplate::where('type', \App\Models\DocumentTemplate::TYPE_CERTIFICATE)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN certificate_type = 'webinar' THEN 0 ELSE 1 END")
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('admin.webinars.create', compact('brands', 'certificateTemplates'));
    }

    public function store(Request $request)
    {
        $source = $request->input('certificate_source', Webinar::CERT_SOURCE_TEMPLATE) ?: Webinar::CERT_SOURCE_TEMPLATE;

        $validated = $request->validate([
            'title'                      => 'required|string|max:255',
            'description'                => 'nullable|string',
            'event_date'                 => 'required|date',
            'zoom_link'                  => 'nullable|url|max:500',
            'brand_id'                   => 'required|exists:brands,id',
            'allowed_brands'             => 'nullable|array',
            'allowed_brands.*'           => 'string|max:10',
            'certificate_source'         => 'nullable|in:template,gdrive',
            'certificate_template_id'    => 'nullable|exists:document_templates,id',
            'gdrive_certificate_link'    => 'nullable|required_if:certificate_source,gdrive|url|max:1000',
        ], [
            'gdrive_certificate_link.required_if' => 'Link Google Drive sertifikat wajib diisi jika memilih opsi Google Drive.',
            'gdrive_certificate_link.url'         => 'Format Link Google Drive harus berupa URL yang valid (diawali https://).',
        ]);

        $validated['certificate_source'] = $source;

        $mode = $request->input('_allowed_brands_mode', 'all');
        $validated['allowed_brands'] = ($mode === 'specific' && !empty($validated['allowed_brands']))
            ? array_values(array_unique($validated['allowed_brands']))
            : null;

        if ($validated['certificate_source'] === 'gdrive') {
            $validated['certificate_template_id'] = null;
        } else {
            $validated['gdrive_certificate_link'] = null;
            if (empty($validated['certificate_template_id'])) {
                $validated['certificate_template_id'] = \App\Models\DocumentTemplate::where('type', \App\Models\DocumentTemplate::TYPE_CERTIFICATE)
                    ->where('certificate_type', \App\Models\DocumentTemplate::CERT_TYPE_WEBINAR)
                    ->where('is_active', true)
                    ->value('id')
                    ?? \App\Models\DocumentTemplate::where('type', \App\Models\DocumentTemplate::TYPE_CERTIFICATE)
                    ->where('is_active', true)
                    ->value('id');
            }
        }

        Webinar::create($validated);

        return redirect()->route('admin.webinars.index')
            ->with('success', '✅ Webinar berhasil dibuat.');
    }

    public function edit(Webinar $webinar)
    {
        \App\Models\DocumentTemplate::ensureDefaultTemplatesExist();

        $brands = Brand::all();
        $certificateTemplates = \App\Models\DocumentTemplate::where('type', \App\Models\DocumentTemplate::TYPE_CERTIFICATE)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN certificate_type = 'webinar' THEN 0 ELSE 1 END")
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return view('admin.webinars.edit', compact('webinar', 'brands', 'certificateTemplates'));
    }

    public function update(Request $request, Webinar $webinar)
    {
        $source = $request->input('certificate_source', $webinar->certificate_source ?? Webinar::CERT_SOURCE_TEMPLATE) ?: Webinar::CERT_SOURCE_TEMPLATE;

        $validated = $request->validate([
            'title'                      => 'required|string|max:255',
            'description'                => 'nullable|string',
            'event_date'                 => 'required|date',
            'zoom_link'                  => 'nullable|url|max:500',
            'brand_id'                   => 'required|exists:brands,id',
            'allowed_brands'             => 'nullable|array',
            'allowed_brands.*'           => 'string|max:10',
            'certificate_source'         => 'nullable|in:template,gdrive',
            'certificate_template_id'    => 'nullable|exists:document_templates,id',
            'gdrive_certificate_link'    => 'nullable|required_if:certificate_source,gdrive|url|max:1000',
        ], [
            'gdrive_certificate_link.required_if' => 'Link Google Drive sertifikat wajib diisi jika memilih opsi Google Drive.',
            'gdrive_certificate_link.url'         => 'Format Link Google Drive harus berupa URL yang valid (diawali https://).',
        ]);

        $validated['certificate_source'] = $source;

        $mode = $request->input('_allowed_brands_mode', 'all');
        $validated['allowed_brands'] = ($mode === 'specific' && !empty($validated['allowed_brands']))
            ? array_values(array_unique($validated['allowed_brands']))
            : null;

        if ($validated['certificate_source'] === 'gdrive') {
            $validated['certificate_template_id'] = null;
        } else {
            $validated['gdrive_certificate_link'] = null;
            if (empty($validated['certificate_template_id'])) {
                $validated['certificate_template_id'] = \App\Models\DocumentTemplate::where('type', \App\Models\DocumentTemplate::TYPE_CERTIFICATE)
                    ->where('certificate_type', \App\Models\DocumentTemplate::CERT_TYPE_WEBINAR)
                    ->where('is_active', true)
                    ->value('id')
                    ?? \App\Models\DocumentTemplate::where('type', \App\Models\DocumentTemplate::TYPE_CERTIFICATE)
                    ->where('is_active', true)
                    ->value('id');
            }
        }

        $webinar->update($validated);

        return redirect()->route('admin.webinars.index')
            ->with('success', 'Webinar berhasil diperbarui.');
    }

    public function destroy(Webinar $webinar)
    {
        $webinar->delete();
        return redirect()->route('admin.webinars.index')
            ->with('success', 'Webinar berhasil dihapus.');
    }

    // ===== REVIEW BUKTI KEHADIRAN =====

    /**
     * Daftar semua bukti kehadiran untuk satu webinar.
     */
    public function attendances(Webinar $webinar)
    {
        $attendances = WebinarAttendance::with('user')
            ->where('webinar_id', $webinar->id)
            ->latest()
            ->paginate(30);

        return view('admin.webinars.attendances', compact('webinar', 'attendances'));
    }

    /**
     * Generate sertifikat untuk SEMUA peserta approved webinar ini.
     * Skip peserta yang sudah punya sertifikat (idempoten).
     */
    public function generateCerts(Webinar $webinar)
    {
        $approvedAttendances = WebinarAttendance::with('user')
            ->where('webinar_id', $webinar->id)
            ->where('status', WebinarAttendance::STATUS_APPROVED)
            ->get();

        if ($approvedAttendances->isEmpty()) {
            return back()->with('error', 'Tidak ada peserta yang sudah diapprove untuk webinar ini.');
        }

        $generated = 0;
        $skipped   = 0;

        foreach ($approvedAttendances as $attendance) {
            // Skip kalau sudah punya sertifikat
            if ($attendance->certificate_id) {
                $skipped++;
                continue;
            }

            $cert = $this->generateWebinarCertificate($webinar, $attendance->user, $attendance->id);

            $attendance->update([
                'certificate_id' => $cert?->id,
                'reviewed_by'    => $attendance->reviewed_by ?? auth()->id(),
                'reviewed_at'    => $attendance->reviewed_at ?? now(),
            ]);

            if ($cert) {
                // Simpan ke document_downloads agar muncul di Dokumen Saya pemagang
                /* DocumentDownload log removed */
                $generated++;
            }
        }

        $msg = "✅ {$generated} sertifikat berhasil di-generate.";
        if ($skipped > 0) {
            $msg .= " {$skipped} peserta dilewati (sudah punya sertifikat).";
        }

        return back()->with('success', $msg);
    }

    /**
     * Approve bukti kehadiran + generate sertifikat otomatis.
     */
    public function approve(Request $request, Webinar $webinar, WebinarAttendance $attendance)
    {
        if (!$attendance->isPending()) {
            return back()->with('error', 'Bukti kehadiran ini sudah diproses sebelumnya.');
        }

        try {
            // Generate sertifikat webinar
            $cert = $this->generateWebinarCertificate($webinar, $attendance->user, $attendance->id);

            // Update attendance
            $attendance->update([
                'status'         => WebinarAttendance::STATUS_APPROVED,
                'reviewed_by'    => auth()->id(),
                'reviewed_at'    => now(),
                'certificate_id' => $cert?->id,
            ]);

            // Simpan ke document_downloads supaya muncul di Dokumen Saya pemagang
            if ($cert) {
                /* DocumentDownload log removed */
            }
        } catch (\Exception $e) {
            \Log::error("Approve webinar attendance #{$attendance->id} gagal: " . $e->getMessage());
            return back()->with('error', 'Gagal generate sertifikat: ' . $e->getMessage());
        }

        return back()->with('success',
            "✅ Bukti kehadiran <strong>{$attendance->user->name}</strong> disetujui. Sertifikat sudah tersedia di Dokumen Saya pemagang."
        );
    }

    /**
     * Reject bukti kehadiran.
     */
    public function reject(Request $request, Webinar $webinar, WebinarAttendance $attendance)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $attendance->update([
            'status'           => WebinarAttendance::STATUS_REJECTED,
            'rejection_reason' => $request->rejection_reason,
            'reviewed_by'      => auth()->id(),
            'reviewed_at'      => now(),
        ]);

        return back()->with('success', "Bukti kehadiran {$attendance->user->name} ditolak.");
    }

    /**
     * Approve data yang dipilih.
     */
    public function approveAll(\Illuminate\Http\Request $request, Webinar $webinar)
    {
        $ids = $request->input('ids', []);
        
        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data yang dipilih untuk diapprove.');
        }

        $attendances = WebinarAttendance::with('user')
            ->where('webinar_id', $webinar->id)
            ->whereIn('id', $ids)
            ->where('status', WebinarAttendance::STATUS_PENDING)
            ->get();

        $success = 0;
        $failed  = 0;

        foreach ($attendances as $attendance) {
            if ($attendance->status !== WebinarAttendance::STATUS_PENDING) continue;

            try {
                $cert = $this->generateWebinarCertificate($webinar, $attendance->user, $attendance->id);

                $attendance->update([
                    'status'         => WebinarAttendance::STATUS_APPROVED,
                    'reviewed_by'    => auth()->id(),
                    'reviewed_at'    => now(),
                    'certificate_id' => $cert?->id,
                ]);

                if ($cert) {
                    /* DocumentDownload log removed */
                }

                $success++;
            } catch (\Exception $e) {
                \Log::error("Approve webinar attendance #{$attendance->id} gagal: " . $e->getMessage());
                $failed++;
            }
        }

        if ($failed > 0) {
            return back()->with('error', "⚠️ {$success} berhasil diapprove, {$failed} gagal. Silakan coba approve yang gagal secara manual.");
        }

        return back()->with('success',
            "✅ {$success} bukti kehadiran disetujui. Sertifikat sudah tersedia untuk masing-masing pemagang."
        );
    }

    // ===== PRIVATE HELPERS =====

    /**
     * Generate sertifikat webinar untuk satu peserta.
     * Menggunakan DB transaction + lock untuk mencegah duplicate serial number
     * ketika banyak peserta di-approve bersamaan.
     */
    private function generateWebinarCertificate(Webinar $webinar, $user, $attendanceId = null): ?\App\Models\WebinarCertificate
    {
        $attendanceId = $attendanceId ?? \App\Models\WebinarAttendance::where('webinar_id', $webinar->id)->where('user_id', $user->id ?? null)->value('id');
        $webinar->loadMissing('brand', 'certificateTemplate');
        
        $startDate = $webinar->event_date;
        $endDate   = $webinar->event_date;

        $roman = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];
        $monthRoman = $roman[$endDate->month];
        $year       = $endDate->year;

        $brand = $webinar->brand;
        $brandCode    = strtoupper($brand->code ?? 'SEVEN');
        $companyName  = $brand->name ?? 'Seven Inc';
        $companyCode  = $this->companyCode($companyName);
        $divisionCode = 'WBN';

        // Gunakan path relatif tanpa storage/ karena dicetak di PDF, fungsi gambar_logo di Helper yang akan me-resolve path
        $bg   = $brand->webinar_certificate_bg ?? null;
        $l1   = $brand->logo ?? null;
        $sig1 = $brand->signature ?? null;

        $certSource = $webinar->certificate_source ?? Webinar::CERT_SOURCE_TEMPLATE;
        $templateId = $webinar->certificate_template_id;
        if ($certSource === Webinar::CERT_SOURCE_TEMPLATE && !$templateId) {
            $templateId = \App\Models\DocumentTemplate::where('type', \App\Models\DocumentTemplate::TYPE_CERTIFICATE)
                ->where('certificate_type', \App\Models\DocumentTemplate::CERT_TYPE_WEBINAR)
                ->where('is_active', true)
                ->value('id');
        }
        $gdriveLink = ($certSource === Webinar::CERT_SOURCE_GDRIVE) ? $webinar->gdrive_certificate_link : null;

        return \Illuminate\Support\Facades\DB::transaction(function () use (
            $attendanceId, $webinar, $startDate, $endDate,
            $monthRoman, $year, $brandCode, $companyName, $companyCode,
            $divisionCode, $bg, $l1, $sig1, $brand, $templateId, $gdriveLink
        ) {
            $serialSuffix = "/SERT/{$divisionCode}/{$companyCode}.{$brandCode}/{$monthRoman}/{$year}";

            $last = \App\Models\WebinarCertificate::where('certificate_number', 'LIKE', "%{$serialSuffix}")
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $seq = 1;
            if ($last && preg_match('/^(\d{3})\/SERT\//', $last->certificate_number, $m)) {
                $seq = (int)$m[1] + 1;
            }
            $seqStr = str_pad($seq, 3, '0', STR_PAD_LEFT);
            $serial = "{$seqStr}{$serialSuffix}";

            return \App\Models\WebinarCertificate::create([
                'attendance_id'         => $attendanceId,
                'certificate_number'    => $serial,
                'document_template_id'  => $templateId,
                'gdrive_link'           => $gdriveLink,
                'company_name'          => $companyName,
                'background_image_path' => $bg,
                'company_logo_path'     => $l1,
                'signatory_name'        => $brand->signatory_name ?? 'Penandatangan',
                'signatory_position'    => $brand->signatory_position ?? 'Penyelenggara',
                'signature_image_path'  => $sig1,
                'created_at'            => now(),
            ]);
        });
    }

    private function companyCode(string $company): string
    {
        $t = strtoupper(preg_replace('/\b(PT|CV|CO\.?|LTD\.?|INC\.?|TBK|PERSERO)\b\.?/i', '', $company));
        $first = preg_split('/\s+/', trim($t))[0] ?? $t;
        return preg_replace('/[^A-Z0-9]/', '', $first) ?: 'COMP';
    }
}

