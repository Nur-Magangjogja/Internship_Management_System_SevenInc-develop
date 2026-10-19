<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InternshipRegistration as IR;
use App\Services\InternDocumentIssuanceService;
use Illuminate\Http\Request;

class GenerateDocController extends Controller
{
    public function __construct(
        protected InternDocumentIssuanceService $issuanceService
    ) {}

    /**
     * POST /admin/interns/generate-doc
     *
     * Menerima: intern_id, jenis_surat (loa | skl | sertifikat | certificate | penilaian | assessment | rekomendasi | recommendation)
     * Mengeluarkan berkas GeneratedDocument resmi, melakukan sinkronisasi Drive,
     * dan mengembalikan respons akurat tanpa redirect GET ke POST route yang rusak.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'intern_id'   => 'required|integer|exists:internship_registrations,id',
            'jenis_surat' => 'required|string',
            'action'      => 'nullable|string|in:issue,preview,revision',
            'is_revision' => 'nullable|boolean',
            'options'     => 'nullable|array',
        ]);

        $user = $request->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'super_admin', 'hr_staff'], true)) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => 'Hanya Admin atau HR Staff yang berwenang menerbitkan dokumen.'], 403);
            }
            abort(403, 'Hanya Admin atau HR Staff yang berwenang menerbitkan dokumen.');
        }

        $intern = IR::with(['brandRel', 'division', 'institution'])->findOrFail($validated['intern_id']);
        $jenis  = InternDocumentIssuanceService::normalizeType($validated['jenis_surat']);
        $action = $validated['action'] ?? 'issue';
        $options = $validated['options'] ?? [];

        if ($action === 'revision' || !empty($validated['is_revision'])) {
            $options['is_revision'] = true;
        }

        // Validasi kelayakan status pemagang
        if (!$this->issuanceService->canIssue($intern, $jenis)) {
            $statusLabel = match($jenis) {
                'loa'   => 'diterima (accepted), aktif, atau selesai',
                default => 'selesai (completed)',
            };
            $errMsg = "Dokumen '{$jenis}' hanya bisa diproses jika status pemagang sudah {$statusLabel}. Status saat ini: {$intern->internship_status}.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => $errMsg], 422);
            }
            return back()->with('error', $errMsg);
        }

        // Preview Dokumen (Tanpa menulis DB atau Cloud Sync)
        if ($action === 'preview') {
            try {
                $preview = $this->issuanceService->previewDocument($intern, $jenis, $options);
                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json([
                        'ok'              => true,
                        'action'          => 'preview',
                        'jenis'           => $jenis,
                        'document_number' => $preview['document_number'],
                        'html'            => $preview['html'],
                    ]);
                }
                return response($preview['html']);
            } catch (\Throwable $e) {
                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json(['ok' => false, 'error' => 'Gagal preview dokumen: ' . $e->getMessage()], 500);
                }
                return back()->with('error', 'Gagal preview dokumen: ' . $e->getMessage());
            }
        }

        // Penerbitan Dokumen Resmi (Issue)
        try {
            $generatedDoc = $this->issuanceService->issueDocument(
                intern: $intern,
                documentType: $jenis,
                options: $options,
                actor: $request->user()
            );

            $downloadUrl = route('admin.generated-documents.download', $generatedDoc->public_uuid);

            $isRevision = !empty($options['is_revision']);
            $successMsg = $isRevision
                ? "Revisi dokumen {$jenis} untuk {$intern->fullname} berhasil diterbitkan (v{$generatedDoc->template_version}, Nomor: {$generatedDoc->document_number})."
                : "Dokumen {$jenis} untuk {$intern->fullname} berhasil diterbitkan (Nomor: {$generatedDoc->document_number}).";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'ok'           => true,
                    'status'       => $generatedDoc->status,
                    'message'      => $successMsg,
                    'is_revision'  => $isRevision,
                    'document'     => [
                        'id'                  => $generatedDoc->id,
                        'uuid'                => $generatedDoc->public_uuid,
                        'number'              => $generatedDoc->document_number,
                        'status'              => $generatedDoc->status,
                        'file_name'           => $generatedDoc->file_name,
                        'download_url'        => $downloadUrl,
                        'drive_web_view_link' => $generatedDoc->drive_web_view_link,
                    ],
                    'download_url' => $downloadUrl,
                    'jenis'        => $jenis,
                    'intern_name'  => $intern->fullname,
                ]);
            }

            return back()->with('success', $successMsg);
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'ok'    => false,
                    'error' => "Gagal menerbitkan dokumen: " . $e->getMessage(),
                ], 500);
            }
            return back()->with('error', "Gagal menerbitkan dokumen: " . $e->getMessage());
        }
    }
}
