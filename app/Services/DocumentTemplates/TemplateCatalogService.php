<?php

namespace App\Services\DocumentTemplates;

use App\Models\DocumentTemplate;
use App\Models\MouTemplate;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TemplateCatalogService
{
    /**
     * Seluruh 6 jenis dokumen kanonikal sistem SERTIF.
     */
    public const ALL_TYPES = [
        DocumentTemplate::TYPE_MOU            => 'Nota Kesepahaman (MOU)',
        DocumentTemplate::TYPE_LOA            => 'Letter of Acceptance (LOA)',
        DocumentTemplate::TYPE_SKL            => 'Surat Keterangan Selesai Magang (SKL)',
        DocumentTemplate::TYPE_CERTIFICATE    => 'Sertifikat Magang',
        DocumentTemplate::TYPE_ASSESSMENT     => 'Surat Penilaian Magang',
        DocumentTemplate::TYPE_RECOMMENDATION => 'Surat Rekomendasi Magang',
    ];

    /**
     * Ambil katalog gabungan untuk 6 jenis dokumen dengan pagination, filter, dan metadata.
     */
    public function getCatalog(array $filters = [], int $perPage = 12, int $page = 1): array
    {
        $typeFilter   = $filters['type'] ?? 'all';
        $statusFilter = $filters['status'] ?? 'all';
        $search       = trim((string) ($filters['search'] ?? ''));

        // 1. Kumpulkan template dokumen umum (LOA, SKL, Certificate, Assessment, Recommendation, dan MoU jika ada)
        $docQuery = DocumentTemplate::query()->with('creator');

        if (!empty($search)) {
            $docQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($typeFilter !== 'all') {
            $docQuery->where('type', $typeFilter);
        }

        $this->applyStatusFilter($docQuery, $statusFilter);

        $docTemplates = $docQuery->get()->map(function (DocumentTemplate $item) {
            return TemplateCatalogItem::fromDocumentTemplate($item);
        });

        // 2. Kumpulkan template MOU via adapter (mou_templates) jika relevan dengan filter
        $mouTemplates = collect();
        if ($typeFilter === 'all' || $typeFilter === DocumentTemplate::TYPE_MOU) {
            $mouQuery = MouTemplate::query()->with('creator');

            if (!empty($search)) {
                $mouQuery->where('name', 'like', "%{$search}%");
            }

            $this->applyMouStatusFilter($mouQuery, $statusFilter);

            $mouTemplates = $mouQuery->get()->map(function (MouTemplate $item) {
                return TemplateCatalogItem::fromMouTemplate($item);
            });
        }

        // 3. Gabungkan dan urutkan (Default paling atas, lalu updated_at terbaru)
        $allCollection = $docTemplates->concat($mouTemplates)->sort(function ($a, $b) {
            if ($a->is_default !== $b->is_default) {
                return $b->is_default ? 1 : -1;
            }
            if ($a->is_active !== $b->is_active) {
                return $b->is_active ? 1 : -1;
            }
            $timeA = $a->updated_at ? $a->updated_at->timestamp : 0;
            $timeB = $b->updated_at ? $b->updated_at->timestamp : 0;
            return $timeB <=> $timeA;
        })->values();

        // 4. Manual pagination untuk koleksi gabungan
        $totalItems = $allCollection->count();
        $slicedItems = $allCollection->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $slicedItems,
            $totalItems,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        // 5. Hitung statistik dan counters tanpa duplikasi
        $counts = $this->calculateUnifiedCounts();

        return [
            'paginator'             => $paginator,
            'items'                 => $slicedItems,
            'counts'                => $counts,
            'document_types'        => self::ALL_TYPES,
            'current_type'          => $typeFilter,
            'current_status'        => $statusFilter,
            'search_query'          => $search,
            'active_counts_by_type' => $counts['by_type_active'],
            'zero_active_types'     => array_keys(array_filter($counts['by_type_active'], fn($c) => $c === 0)),
        ];
    }

    /**
     * Hitung ringkasan jumlah template per tipe dan status secara akurat.
     */
    public function calculateUnifiedCounts(): array
    {
        $docCountsByType = DocumentTemplate::selectRaw('type, count(*) as count, sum(case when is_active = 1 then 1 else 0 end) as active_count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        $docActiveByType = DocumentTemplate::selectRaw('type, sum(case when is_active = 1 then 1 else 0 end) as active_count')
            ->groupBy('type')
            ->pluck('active_count', 'type')
            ->toArray();

        $mouCount       = MouTemplate::count();
        $mouActiveCount = MouTemplate::where('is_active', true)->count();
        $mouDefaultCount = MouTemplate::where('is_default', true)->count();

        $docTotal   = DocumentTemplate::count();
        $docActive  = DocumentTemplate::where('is_active', true)->count();
        $docDefault = DocumentTemplate::where('is_default', true)->count();

        $byType = [
            'mou'            => ($docCountsByType['mou'] ?? 0) + $mouCount,
            'loa'            => $docCountsByType['loa'] ?? 0,
            'skl'            => $docCountsByType['skl'] ?? 0,
            'certificate'    => $docCountsByType['certificate'] ?? 0,
            'assessment'     => $docCountsByType['assessment'] ?? 0,
            'recommendation' => $docCountsByType['recommendation'] ?? 0,
        ];

        $byTypeActive = [
            'mou'            => (int) (($docActiveByType['mou'] ?? 0) + $mouActiveCount),
            'loa'            => (int) ($docActiveByType['loa'] ?? 0),
            'skl'            => (int) ($docActiveByType['skl'] ?? 0),
            'certificate'    => (int) ($docActiveByType['certificate'] ?? 0),
            'assessment'     => (int) ($docActiveByType['assessment'] ?? 0),
            'recommendation' => (int) ($docActiveByType['recommendation'] ?? 0),
        ];

        return [
            'total'          => $docTotal + $mouCount,
            'active'         => $docActive + $mouActiveCount,
            'default'        => $docDefault + $mouDefaultCount,
            'draft'          => DocumentTemplate::where('state', 'draft')->count() + MouTemplate::where('state', 'draft')->count(),
            'published'      => DocumentTemplate::where('state', 'published')->count() + MouTemplate::where('state', 'published')->count(),
            'archived'       => DocumentTemplate::where('state', 'archived')->count() + MouTemplate::where('state', 'archived')->count(),
            'by_type'        => $byType,
            'by_type_active' => $byTypeActive,
        ];
    }

    /**
     * Resolusi template aktif (default atau latest active) untuk salah satu dari 6 tipe dokumen.
     */
    public function getActiveTemplate(string $documentType): ?object
    {
        $type = strtolower(trim($documentType));

        if ($type === DocumentTemplate::TYPE_MOU) {
            $defaultMou = MouTemplate::active()->where('is_default', true)->first();
            if ($defaultMou) {
                return $defaultMou;
            }
            return MouTemplate::active()->latest('version_number')->first();
        }

        $defaultDoc = DocumentTemplate::ofType($type)->active()->default()->first();
        if ($defaultDoc) {
            return $defaultDoc;
        }

        return DocumentTemplate::ofType($type)->active()->latest('id')->first();
    }

    /**
     * Cek apakah terdapat template aktif untuk jenis dokumen tertentu.
     */
    public function hasActiveTemplate(string $documentType): bool
    {
        $type = strtolower(trim($documentType));
        if ($type === DocumentTemplate::TYPE_MOU) {
            return MouTemplate::active()->exists() || DocumentTemplate::ofType($type)->active()->exists();
        }
        return DocumentTemplate::ofType($type)->active()->exists();
    }

    private function applyStatusFilter($query, string $statusFilter): void
    {
        match ($statusFilter) {
            'active'    => $query->where('is_active', true),
            'inactive'  => $query->where('is_active', false),
            'default'   => $query->where('is_default', true),
            'draft'     => $query->where('state', 'draft'),
            'published' => $query->where('state', 'published'),
            'archived'  => $query->where('state', 'archived'),
            default     => null,
        };
    }

    private function applyMouStatusFilter($query, string $statusFilter): void
    {
        match ($statusFilter) {
            'active'    => $query->where('is_active', true),
            'inactive'  => $query->where('is_active', false),
            'default'   => $query->where('is_default', true),
            'draft'     => $query->where('state', 'draft'),
            'published' => $query->where('state', 'published'),
            'archived'  => $query->where('state', 'archived'),
            default     => null,
        };
    }

    private function formatDocumentTemplateItem(DocumentTemplate $doc): array
    {
        $state = $doc->state ?? ($doc->is_active ? 'published' : 'draft');

        return [
            'id'                => $doc->id,
            'uuid'              => $doc->uuid,
            'type'              => $doc->type,
            'type_label'        => DocumentTemplate::getTypeLabel($doc->type),
            'type_badge_class'  => DocumentTemplate::getTypeBadgeClass($doc->type),
            'name'              => $doc->name,
            'description'       => $doc->description ?? 'Template standar ' . DocumentTemplate::getTypeLabel($doc->type),
            'editor_type'       => $doc->editor_type,
            'state'             => $state,
            'is_active'         => (bool) $doc->is_active,
            'is_default'        => (bool) $doc->is_default,
            'version_number'    => (int) $doc->version_number,
            'published_version' => $doc->published_version ?? ($doc->is_active ? $doc->version_number : null),
            'creator_name'      => $doc->creator?->name ?? 'Administrator',
            'created_at'        => $doc->created_at?->format('Y-m-d H:i:s'),
            'updated_at'        => $doc->updated_at?->format('Y-m-d H:i:s'),
            'updated_at_human'  => $doc->updated_at?->diffForHumans() ?? 'Baru saja',
            'edit_url'          => route('admin.document-templates.edit', $doc),
            'preview_url'       => route('admin.document-templates.preview', $doc),
            'preview_pdf_url'   => route('admin.document-templates.preview-pdf', $doc),
            'duplicate_url'     => route('admin.document-templates.duplicate', $doc),
            'toggle_active_url' => route('admin.document-templates.toggle-active', $doc),
            'set_default_url'   => route('admin.document-templates.set-default', $doc),
            'versions_url'      => route('admin.document-templates.versions', $doc),
            'delete_url'        => route('admin.document-templates.destroy', $doc),
            'is_legacy_mou'     => false,
            'model'             => $doc,
        ];
    }

    private function formatMouTemplateItem(MouTemplate $mou): array
    {
        $state = $mou->state ?? ($mou->is_active ? 'published' : 'draft');

        return [
            'id'                => $mou->id,
            'uuid'              => $mou->public_uuid,
            'type'              => DocumentTemplate::TYPE_MOU,
            'type_label'        => 'Nota Kesepahaman (MOU)',
            'type_badge_class'  => 'bg-cyan-100 text-cyan-800 border-cyan-200',
            'name'              => $mou->name,
            'description'       => 'Naskah template perjanjian kerja sama Tri Dharma Perguruan Tinggi',
            'editor_type'       => 'visual_doc',
            'state'             => $state,
            'is_active'         => (bool) $mou->is_active,
            'is_default'        => (bool) ($mou->is_default ?? false),
            'version_number'    => (int) $mou->version_number,
            'published_version' => $mou->published_version ?? ($mou->is_active ? $mou->version_number : null),
            'creator_name'      => $mou->creator?->name ?? 'Administrator',
            'created_at'        => $mou->created_at?->format('Y-m-d H:i:s'),
            'updated_at'        => $mou->updated_at?->format('Y-m-d H:i:s'),
            'updated_at_human'  => $mou->updated_at?->diffForHumans() ?? 'Baru saja',
            'edit_url'          => route('admin.mou-templates.edit', $mou),
            'preview_url'       => route('admin.mou-templates.preview', $mou),
            'preview_pdf_url'   => route('admin.mou-templates.preview-pdf', $mou),
            'duplicate_url'     => route('admin.mou-templates.duplicate', $mou),
            'toggle_active_url' => route('admin.mou-templates.toggle-active', $mou),
            'set_default_url'   => route('admin.mou-templates.set-default', $mou),
            'versions_url'      => route('admin.mou-templates.edit', $mou),
            'delete_url'        => route('admin.mou-templates.destroy', $mou),
            'is_legacy_mou'     => true,
            'model'             => $mou,
        ];
    }
}
