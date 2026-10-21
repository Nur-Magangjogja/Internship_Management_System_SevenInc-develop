<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\InternshipRegistration as IR;
use App\Models\Mou;
use App\Models\MouTemplate;
use App\Services\InternDocumentIssuanceService;
use App\Services\MailMerge\VariableRegistry;
use App\Services\MouDocumentRenderer;
use App\Services\MouService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GenerateDocController extends Controller
{
    public function __construct(
        protected InternDocumentIssuanceService $issuanceService,
        protected MouService $mouService
    ) {}

    /**
     * POST /admin/interns/generate-doc
     *
     * Universal generation endpoint untuk 6 jenis dokumen (MoU, LoA, SKL, Sertifikat, Penilaian, Rekomendasi).
     * Mendukung action: validate (preflight), preview (live render), issue (terbitkan resmi), revision.
     */
    public function generate(Request $request): JsonResponse|Response|\Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'jenis_surat'    => 'required|string',
            'intern_id'      => 'nullable|integer|exists:internship_registrations,id',
            'institution_id' => 'nullable|integer|exists:institutions,id',
            'target_id'      => 'nullable|integer',
            'action'         => 'nullable|string|in:issue,preview,revision,validate',
            'is_revision'    => 'nullable|boolean',
            'options'        => 'nullable|array',
            'template_id'    => 'nullable|integer',
        ]);

        $user = $request->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'super_admin', 'hr_staff'], true)) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => 'Hanya Admin atau HR Staff yang berwenang menerbitkan dokumen.'], 403);
            }
            abort(403, 'Hanya Admin atau HR Staff yang berwenang menerbitkan dokumen.');
        }

        $rawType = strtolower(trim($validated['jenis_surat']));
        $canonical = $rawType === 'mou' ? 'mou' : InternDocumentIssuanceService::normalizeType($rawType);
        $action = $validated['action'] ?? 'issue';
        $options = $validated['options'] ?? [];

        if ($action === 'revision' || !empty($validated['is_revision'])) {
            $options['is_revision'] = true;
        }
        if (!empty($validated['template_id'])) {
            $options['template_id'] = $validated['template_id'];
        }

        // Jalur khusus Dokumen MOU
        if ($canonical === 'mou') {
            return $this->handleMouGeneration($request, $validated, $action, $options, $user);
        }

        // Jalur Dokumen Pemagang (LoA, SKL, Sertifikat, Penilaian, Rekomendasi)
        $internId = $validated['intern_id'] ?? $validated['target_id'];
        if (!$internId) {
            return response()->json(['ok' => false, 'error' => 'ID Pemagang (intern_id) wajib disertakan untuk dokumen ini.'], 422);
        }

        $intern = IR::with(['brandRel', 'division', 'institution'])->findOrFail($internId);

        // Pre-Flight Validation Action
        if ($action === 'validate') {
            return $this->validateInternDocumentPreflight($intern, $canonical, $options);
        }

        // Validasi kelayakan status pemagang sebelum preview / issue
        if (!$this->issuanceService->canIssue($intern, $canonical)) {
            $statusLabel = match($canonical) {
                'loa'   => 'diterima (accepted), aktif, atau selesai',
                default => 'selesai (completed)',
            };
            $errMsg = "Dokumen '{$canonical}' hanya bisa diproses jika status pemagang sudah {$statusLabel}. Status saat ini: {$intern->internship_status}.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => $errMsg], 422);
            }
            return back()->with('error', $errMsg);
        }

        // Preview Dokumen (Tanpa menulis DB atau Cloud Sync)
        if ($action === 'preview') {
            try {
                $preview = $this->issuanceService->previewDocument($intern, $canonical, $options);
                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json([
                        'ok'              => true,
                        'action'          => 'preview',
                        'jenis'           => $canonical,
                        'document_number' => $preview['document_number'],
                        'html'            => $preview['html'],
                    ]);
                }
                return response($preview['html']);
            } catch (\Throwable $e) {
                if ($request->wantsJson() || $request->ajax()) {
                    $status = ($e instanceof \InvalidArgumentException || $e instanceof \Illuminate\Validation\ValidationException) ? 422 : 500;
                    return response()->json(['ok' => false, 'error' => 'Gagal preview dokumen: ' . $e->getMessage()], $status);
                }
                return back()->with('error', 'Gagal preview dokumen: ' . $e->getMessage());
            }
        }

        // Penerbitan Dokumen Resmi (Issue)
        try {
            $generatedDoc = $this->issuanceService->issueDocument(
                intern: $intern,
                documentType: $canonical,
                options: $options,
                actor: $request->user()
            );

            $downloadUrl = route('admin.generated-documents.download', $generatedDoc->public_uuid);
            $isRevision = !empty($options['is_revision']);
            $successMsg = $isRevision
                ? "Revisi dokumen {$canonical} untuk {$intern->fullname} berhasil diterbitkan (v{$generatedDoc->template_version}, Nomor: {$generatedDoc->document_number})."
                : "Dokumen {$canonical} untuk {$intern->fullname} berhasil diterbitkan (Nomor: {$generatedDoc->document_number}).";

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
                    'jenis'        => $canonical,
                    'intern_name'  => $intern->fullname,
                ]);
            }

            return back()->with('success', $successMsg);
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                $status = ($e instanceof \InvalidArgumentException || $e instanceof \Illuminate\Validation\ValidationException) ? 422 : 500;
                return response()->json([
                    'ok'    => false,
                    'error' => "Gagal menerbitkan dokumen: " . $e->getMessage(),
                ], $status);
            }
            return back()->with('error', "Gagal menerbitkan dokumen: " . $e->getMessage());
        }
    }

    /**
     * POST /admin/interns/generate-doc/bulk
     *
     * Penerbitan dokumen massal secara atomik & idempoten untuk daftar kandidat terpilih.
     */
    public function generateBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jenis_surat' => 'required|string',
            'targets'     => 'required|array|min:1',
            'targets.*'   => 'required',
            'options'     => 'nullable|array',
        ]);

        $user = $request->user();
        if (!$user || !in_array($user->role, ['admin', 'superadmin', 'super_admin', 'hr_staff'], true)) {
            return response()->json(['ok' => false, 'error' => 'Hanya Admin atau HR Staff yang berwenang menerbitkan dokumen.'], 403);
        }

        $canonical = InternDocumentIssuanceService::normalizeType($validated['jenis_surat']);
        $targets = $validated['targets'];
        $options = $validated['options'] ?? [];

        $results = [];
        $succeeded = 0;
        $failed = 0;

        foreach ($targets as $target) {
            $internId = is_array($target) ? ($target['intern_id'] ?? $target['id'] ?? null) : $target;
            if (!$internId) continue;

            $intern = IR::with(['brandRel', 'division', 'institution'])->find($internId);
            if (!$intern) {
                $failed++;
                $results[] = [
                    'intern_id' => $internId,
                    'ok'        => false,
                    'error'     => 'Data pemagang tidak ditemukan.'
                ];
                continue;
            }

            if (!$this->issuanceService->canIssue($intern, $canonical)) {
                $failed++;
                $results[] = [
                    'intern_id'   => $internId,
                    'intern_name' => $intern->fullname,
                    'ok'          => false,
                    'error'       => "Status pemagang belum memenuhi syarat untuk dokumen {$canonical} ({$intern->internship_status})."
                ];
                continue;
            }

            try {
                $generatedDoc = $this->issuanceService->issueDocument(
                    intern: $intern,
                    documentType: $canonical,
                    options: $options,
                    actor: $user
                );

                $succeeded++;
                $results[] = [
                    'intern_id'       => $internId,
                    'intern_name'     => $intern->fullname,
                    'ok'              => true,
                    'document_number' => $generatedDoc->document_number,
                    'download_url'    => route('admin.generated-documents.download', $generatedDoc->public_uuid),
                ];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'intern_id'   => $internId,
                    'intern_name' => $intern->fullname,
                    'ok'          => false,
                    'error'       => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'ok'        => true,
            'total'     => count($targets),
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'results'   => $results,
        ]);
    }

    /**
     * GET /admin/interns/generate-wizard/preflight
     *
     * Pre-flight diagnostic helper untuk wizard UI interaktif.
     */
    public function preflight(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jenis_surat' => 'required|string',
            'intern_id'   => 'nullable|integer',
            'target_id'   => 'nullable|integer',
        ]);

        $internId = $validated['intern_id'] ?? $validated['target_id'];
        $canonical = InternDocumentIssuanceService::normalizeType($validated['jenis_surat']);

        if (!$internId) {
            return response()->json(['ok' => false, 'error' => 'ID target wajib diisi.'], 422);
        }

        $intern = IR::with(['brandRel', 'division', 'institution'])->findOrFail($internId);
        return $this->validateInternDocumentPreflight($intern, $canonical, $request->all());
    }

    /**
     * Pre-flight validation logic untuk dokumen pemagang.
     */
    protected function validateInternDocumentPreflight(IR $intern, string $canonical, array $options = []): JsonResponse
    {
        $eligible = $this->issuanceService->canIssue($intern, $canonical);
        $template = $this->issuanceService->resolveActiveTemplate($canonical, false);
        $existingDocNumber = $this->issuanceService->resolveExistingDocumentNumber($intern, $canonical);

        $resolvedVariables = [];
        $missingVariables = [];

        try {
            $rawContext = $this->issuanceService->buildContext($intern, $canonical, $options);
            $resolvedVariables = is_array($rawContext) ? $rawContext : (method_exists($rawContext, 'toArray') ? $rawContext->toArray() : (array) $rawContext);
            $missingVariables = VariableRegistry::diagnoseMissing($canonical, $resolvedVariables);
        } catch (\Throwable $e) {
            $missingVariables[] = $e->getMessage();
        }

        return response()->json([
            'ok'                       => $eligible && empty($missingVariables) && ($template !== null),
            'action'                   => 'validate',
            'eligible'                 => $eligible,
            'status_current'           => $intern->internship_status,
            'jenis'                    => $canonical,
            'intern'                   => [
                'id'       => $intern->id,
                'name'     => $intern->fullname,
                'nim'      => $intern->school_id_number,
                'division' => $intern->division?->name,
                'brand'    => $intern->brandRel?->name,
            ],
            'template'                 => $template ? [
                'id'             => $template->id,
                'name'           => $template->name,
                'version_number' => $template->version_number,
                'editor_type'    => $template->editor_type,
            ] : null,
            'is_already_issued'        => !empty($existingDocNumber),
            'existing_document_number' => $existingDocNumber,
            'resolved_variables'       => $resolvedVariables,
            'missing_variables'        => $missingVariables,
        ]);
    }

    /**
     * Penanganan khusus dokumen MoU (Institution & Follow-Up Lifecycle).
     */
    protected function handleMouGeneration(Request $request, array $validated, string $action, array $options, $user): JsonResponse|Response|\Illuminate\Http\RedirectResponse
    {
        $institutionId = $validated['institution_id'] ?? $validated['target_id'] ?? null;
        if (!$institutionId) {
            return response()->json(['ok' => false, 'error' => 'ID Institusi (institution_id) wajib disertakan untuk dokumen MOU.'], 422);
        }

        $institution = Institution::with(['contacts', 'mous'])->findOrFail($institutionId);

        // Preflight validation for MoU
        if ($action === 'validate') {
            $hasActiveMou = Mou::where('institution_id', $institution->id)
                ->where('status', Mou::STATUS_ACTIVE)
                ->first();

            $activeTemplate = MouTemplate::active()->latest('version_number')->first();

            return response()->json([
                'ok'                => $institution->is_active && ($activeTemplate !== null),
                'action'            => 'validate',
                'eligible'          => (bool) $institution->is_active,
                'jenis'             => 'mou',
                'institution'       => [
                    'id'   => $institution->id,
                    'name' => $institution->name,
                    'type' => $institution->type,
                ],
                'template'          => $activeTemplate ? [
                    'id'             => $activeTemplate->id,
                    'name'           => $activeTemplate->name,
                    'version_number' => $activeTemplate->version_number,
                ] : null,
                'has_active_mou'    => !empty($hasActiveMou),
                'active_mou_number' => $hasActiveMou?->company_mou_number,
                'missing_variables' => !$institution->is_active ? ['institution.status_active'] : [],
            ]);
        }

        // Preview MoU
        if ($action === 'preview') {
            try {
                $template = !empty($options['mou_template_id'])
                    ? MouTemplate::findOrFail($options['mou_template_id'])
                    : MouTemplate::active()->latest('version_number')->firstOrFail();

                $previewHtml = MouDocumentRenderer::renderTemplate($template, array_merge([
                    'institution_id'   => $institution->id,
                    'institution_name' => $institution->name,
                ], $options));

                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json([
                        'ok'     => true,
                        'action' => 'preview',
                        'jenis'  => 'mou',
                        'html'   => $previewHtml,
                    ]);
                }
                return response($previewHtml);
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'error' => 'Gagal preview MOU: ' . $e->getMessage()], 500);
            }
        }

        // Official Mou Issuance
        try {
            $mou = $this->mouService->generateMou(array_merge([
                'institution_id' => $institution->id,
            ], $options), $user);

            $downloadUrl = route('admin.mous.download', $mou->id);
            $msg = "Dokumen MOU resmi untuk '{$institution->name}' berhasil diterbitkan (Nomor: {$mou->company_mou_number}).";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'ok'           => true,
                    'status'       => $mou->status,
                    'message'      => $msg,
                    'mou'          => [
                        'id'           => $mou->id,
                        'number'       => $mou->company_mou_number,
                        'status'       => $mou->status,
                        'download_url' => $downloadUrl,
                    ],
                    'download_url' => $downloadUrl,
                    'jenis'        => 'mou',
                ]);
            }
            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['ok' => false, 'error' => "Gagal menerbitkan MOU: " . $e->getMessage()], 422);
            }
            return back()->with('error', "Gagal menerbitkan MOU: " . $e->getMessage());
        }
    }
}
