<?php

namespace App\Services\DocumentTemplates;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\MouTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TemplateLifecycleService
{
    /**
     * Buat draf template universal baru (Working Draft).
     * JAMINAN: Tidak boleh mengikat brand_id, institution_id, atau internship_registration_id (INV-01).
     */
    public function createDraft(array $data, int $userId): DocumentTemplate
    {
        // 1. Guard ketat INV-01: Larangan mengikat pihak pada pembuatan template
        $forbiddenFields = ['brand_id', 'institution_id', 'internship_registration_id', 'brand', 'institution'];
        foreach ($forbiddenFields as $field) {
            if (!empty($data[$field])) {
                throw new InvalidArgumentException(
                    "Template universal tidak boleh terikat ke brand, institusi, atau pemagang saat dibuat. Field '{$field}' dilarang pada authoring."
                );
            }
        }

        $type = DocumentTemplate::normalizeType($data['type'] ?? 'loa');
        $editorType = $data['editor_type'] ?? ($type === DocumentTemplate::TYPE_CERTIFICATE ? 'visual_canvas' : 'visual_doc');

        // Konten awal
        $contentHtml = $data['content_html'] ?? ($editorType === 'visual_doc' ? DocumentTemplate::getDefaultStandardContent($type) : null);
        $canvasLayout = $data['canvas_layout'] ?? ($editorType === 'visual_canvas' ? DocumentTemplate::getDefaultCertificateCanvasLayout() : null);
        $pageConfig = $data['page_config'] ?? DocumentTemplate::getDefaultPageConfig($type);

        $template = DocumentTemplate::create([
            'uuid'              => (string) Str::uuid(),
            'type'              => $type,
            'name'              => trim((string) ($data['name'] ?? 'Template Baru ' . strtoupper($type))),
            'description'       => $data['description'] ?? null,
            'editor_type'       => $editorType,
            'content_html'      => $contentHtml,
            'editor_json'       => $data['editor_json'] ?? null,
            'canvas_layout'     => $canvasLayout,
            'page_config'       => $pageConfig,
            'state'             => DocumentTemplate::STATE_DRAFT,
            'is_active'         => false,
            'is_default'        => false,
            'version_number'    => 1,
            'published_version' => null,
            'lock_version'      => 1,
            'created_by'        => $userId,
            'updated_by'        => $userId,
        ]);

        return $template;
    }

    /**
     * Perbarui konten draf kerja (Working Draft).
     * Jika template saat ini sudah berstatus published, buat revisi draf kerja baru agar snapshot yang sudah terbit terlindungi (INV-07).
     */
    public function updateDraft(DocumentTemplate $template, array $data, int $userId): DocumentTemplate
    {
        // Guard ketat INV-01
        $forbiddenFields = ['brand_id', 'institution_id', 'internship_registration_id'];
        foreach ($forbiddenFields as $field) {
            if (!empty($data[$field])) {
                throw new InvalidArgumentException(
                    "Penyuntingan template dilarang mengikat '{$field}'."
                );
            }
        }

        $isCurrentlyPublished = ($template->state === DocumentTemplate::STATE_PUBLISHED);
        $newVersionNumber = $isCurrentlyPublished ? ($template->version_number + 1) : $template->version_number;

        $template->fill([
            'name'           => $data['name'] ?? $template->name,
            'description'    => array_key_exists('description', $data) ? $data['description'] : $template->description,
            'content_html'   => array_key_exists('content_html', $data) ? $data['content_html'] : $template->content_html,
            'editor_json'    => array_key_exists('editor_json', $data) ? $data['editor_json'] : $template->editor_json,
            'canvas_layout'  => array_key_exists('canvas_layout', $data) ? $data['canvas_layout'] : $template->canvas_layout,
            'page_config'    => array_key_exists('page_config', $data) ? $data['page_config'] : $template->page_config,
            'version_number' => $newVersionNumber,
            'state'          => DocumentTemplate::STATE_DRAFT, // Beralih ke working draft untuk revisi baru
            'lock_version'   => ($template->lock_version ?? 1) + 1,
            'updated_by'     => $userId,
        ]);

        $template->save();

        return $template;
    }

    /**
     * Publikasikan revisi template menjadi snapshot immutable (Published Revision).
     * Menghitung SHA-256 checksum dan mencatat snapshot ke document_template_versions.
     */
    public function publishRevision(
        DocumentTemplate $template,
        ?string $changeSummary = null,
        int $userId = 1
    ): DocumentTemplateVersion {
        // 1. Validasi kelengkapan struktur dokumen (INV-09)
        if ($template->editor_type === 'visual_canvas' || $template->type === DocumentTemplate::TYPE_CERTIFICATE) {
            if (empty($template->canvas_layout) || !is_array($template->canvas_layout)) {
                throw new InvalidArgumentException('Tata letak canvas sertifikat belum lengkap atau kosong.');
            }
        } else {
            if (empty(trim(strip_tags((string) $template->content_html))) && empty($template->content_html)) {
                throw new InvalidArgumentException('Isi naskah dokumen template tidak boleh kosong saat dipublikasikan.');
            }
        }

        // 2. Hitung hash integritas isi template
        $rawContent = $template->editor_type === 'visual_canvas'
            ? json_encode($template->canvas_layout)
            : (string) $template->content_html;
        $contentSha256 = hash('sha256', $rawContent);

        return DB::transaction(function () use ($template, $changeSummary, $userId, $contentSha256) {
            $summary = $changeSummary ?: "Publikasi Revisi Versi {$template->version_number}";

            // Buat snapshot immutable versi
            $versionSnapshot = $template->versions()->create([
                'version_number' => $template->version_number,
                'name'           => $template->name,
                'content_html'   => $template->content_html,
                'editor_json'    => $template->editor_json,
                'canvas_layout'  => $template->canvas_layout,
                'page_config'    => $template->page_config,
                'change_summary' => $summary,
                'content_sha256' => $contentSha256,
                'state'          => DocumentTemplate::STATE_PUBLISHED,
                'created_by'     => $userId,
            ]);

            // Kunci status template sebagai PUBLISHED
            $template->update([
                'state'             => DocumentTemplate::STATE_PUBLISHED,
                'published_version' => $template->version_number,
                'published_at'      => now(),
                'content_sha256'    => $contentSha256,
                'updated_by'        => $userId,
            ]);

            return $versionSnapshot;
        });
    }

    /**
     * Aktifkan template (hanya template yang sudah published yang boleh diaktifkan).
     */
    public function activate(DocumentTemplate $template, int $userId): void
    {
        if ($template->state === DocumentTemplate::STATE_ARCHIVED) {
            throw new InvalidArgumentException('Template yang telah diarsipkan tidak dapat langsung diaktifkan. Buka draf baru terlebih dahulu.');
        }

        if ($template->state === DocumentTemplate::STATE_DRAFT && empty($template->published_version)) {
            throw new InvalidArgumentException('Template berstatus draf belum dapat diaktifkan. Publikasikan revisi terlebih dahulu.');
        }

        $template->update([
            'is_active'  => true,
            'updated_by' => $userId,
        ]);
    }

    /**
     * Nonaktifkan template.
     * Guard: Template default tidak dapat dinonaktifkan langsung.
     */
    public function deactivate(DocumentTemplate $template, int $userId): void
    {
        if ($template->is_default) {
            throw new InvalidArgumentException(
                'Template default tidak dapat dinonaktifkan secara langsung. Tetapkan template lain sebagai default terlebih dahulu.'
            );
        }

        $template->update([
            'is_active'  => false,
            'updated_by' => $userId,
        ]);
    }

    /**
     * Tetapkan satu template sebagai default aktif secara atomik (INV-08).
     * Menggunakan transaksi DB dan penguncian baris (lockForUpdate) untuk mencegah race condition.
     */
    public function setDefault(DocumentTemplate $template, int $userId): void
    {
        if ($template->state === DocumentTemplate::STATE_ARCHIVED) {
            throw new InvalidArgumentException('Template yang diarsipkan tidak dapat dijadikan sebagai default.');
        }

        if ($template->state === DocumentTemplate::STATE_DRAFT && empty($template->published_version)) {
            // Publikasikan draf otomatis sebelum menjadikannya default
            $this->publishRevision($template, 'Publikasi otomatis saat penetapan template default', $userId);
            $template->refresh();
        }

        DB::transaction(function () use ($template, $userId) {
            // 1. Kunci seluruh baris template dengan tipe yang sama
            DocumentTemplate::where('type', $template->type)->lockForUpdate()->get();

            // 2. Cabut status default dari template lain pada tipe yang sama
            DocumentTemplate::where('type', $template->type)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);

            // 3. Tetapkan template ini sebagai default dan aktif
            $template->update([
                'is_default' => true,
                'is_active'  => true,
                'state'      => DocumentTemplate::STATE_PUBLISHED,
                'updated_by' => $userId,
            ]);
        });
    }

    /**
     * Arsipkan template (Archived).
     * Template diarsipkan tidak aktif dan tidak default, namun data historis dan versinya tetap utuh.
     */
    public function archive(DocumentTemplate $template, int $userId): void
    {
        if ($template->is_default) {
            throw new InvalidArgumentException(
                'Template default tidak dapat diarsipkan. Tetapkan template lain sebagai default terlebih dahulu.'
            );
        }

        $template->update([
            'state'      => DocumentTemplate::STATE_ARCHIVED,
            'is_active'  => false,
            'is_default' => false,
            'updated_by' => $userId,
        ]);
    }

    /**
     * Gandakan template menjadi draf salinan baru.
     */
    public function duplicate(DocumentTemplate $template, ?string $newName = null, int $userId = 1): DocumentTemplate
    {
        $copyName = $newName ?? ($template->name . ' (Salinan)');

        return DocumentTemplate::create([
            'uuid'              => (string) Str::uuid(),
            'type'              => $template->type,
            'name'              => $copyName,
            'description'       => $template->description,
            'editor_type'       => $template->editor_type,
            'content_html'      => $template->content_html,
            'editor_json'       => $template->editor_json,
            'canvas_layout'     => $template->canvas_layout,
            'page_config'       => $template->page_config,
            'state'             => DocumentTemplate::STATE_DRAFT,
            'is_active'         => false,
            'is_default'        => false,
            'version_number'    => 1,
            'published_version' => null,
            'created_by'        => $userId,
            'updated_by'        => $userId,
        ]);
    }

    /**
     * Pulihkan versi historis menjadi revisi baru (INV-07).
     * Tidak pernah menimpa riwayat versi lama.
     */
    public function restoreVersion(
        DocumentTemplate $template,
        DocumentTemplateVersion $version,
        int $userId = 1
    ): DocumentTemplate {
        if ($version->document_template_id !== $template->id) {
            throw new InvalidArgumentException('Versi historis tidak cocok dengan template ini.');
        }

        $nextVersion = $template->version_number + 1;

        $template->fill([
            'name'           => $version->name ?? $template->name,
            'content_html'   => $version->content_html,
            'editor_json'    => $version->editor_json,
            'canvas_layout'  => $version->canvas_layout,
            'page_config'    => $version->page_config,
            'version_number' => $nextVersion,
            'state'          => DocumentTemplate::STATE_DRAFT,
            'updated_by'     => $userId,
        ]);

        $template->save();

        // Publikasikan revisi hasil pemulihan sebagai versi baru
        $this->publishRevision($template, "Pemulihan dari versi historis {$version->version_number}", $userId);

        return $template->fresh();
    }

    // -------------------------------------------------------------------------
    // Adapter Operasi Lifecycle untuk MOU (mou_templates)
    // -------------------------------------------------------------------------

    public function setMouDefault(MouTemplate $mouTemplate, int $userId): void
    {
        DB::transaction(function () use ($mouTemplate, $userId) {
            MouTemplate::lockForUpdate()->get();
            MouTemplate::where('id', '!=', $mouTemplate->id)->update(['is_default' => false]);
            $mouTemplate->update([
                'is_default' => true,
                'is_active'  => true,
                'state'      => 'published',
                'updated_by' => $userId,
            ]);
        });
    }

    public function toggleMouActive(MouTemplate $mouTemplate, int $userId): bool
    {
        if ($mouTemplate->is_default && $mouTemplate->is_active) {
            throw new InvalidArgumentException(
                'Template MOU default tidak dapat dinonaktifkan secara langsung. Tetapkan template lain sebagai default terlebih dahulu.'
            );
        }

        $newState = !$mouTemplate->is_active;
        $mouTemplate->update([
            'is_active'  => $newState,
            'updated_by' => $userId,
        ]);

        return $newState;
    }

    public function archiveMou(MouTemplate $mouTemplate, int $userId): void
    {
        if ($mouTemplate->is_default) {
            throw new InvalidArgumentException(
                'Template MOU default tidak dapat diarsipkan. Tetapkan template lain sebagai default terlebih dahulu.'
            );
        }

        $mouTemplate->update([
            'state'      => 'archived',
            'is_active'  => false,
            'is_default' => false,
            'updated_by' => $userId,
        ]);
    }

    public function duplicateMou(MouTemplate $mouTemplate, ?string $newName = null, int $userId = 1): MouTemplate
    {
        $copyName = $newName ?? ($mouTemplate->name . ' (Salinan)');

        return MouTemplate::create([
            'public_uuid'          => (string) Str::uuid(),
            'name'                 => $copyName,
            'version_number'       => 1,
            'template_html'        => $mouTemplate->template_html,
            'allowed_placeholders' => $mouTemplate->allowed_placeholders,
            'default_values'       => $mouTemplate->default_values,
            'is_active'            => false,
            'is_default'           => false,
            'state'                => 'draft',
            'created_by'           => $userId,
            'updated_by'           => $userId,
        ]);
    }
}
