<?php

namespace App\Services\DocumentTemplates;

use App\Models\DocumentTemplate;
use App\Models\MouTemplate;
use Carbon\Carbon;

class TemplateCatalogItem
{
    public int|string $id;
    public string $uuid;
    public string $type;
    public ?string $certificate_type = null;
    public string $name;
    public ?string $description;
    public string $editor_type;
    public string $state;
    public bool $is_active;
    public bool $is_default;
    public int $version_number;
    public ?int $published_version;
    public ?string $creator_name;
    public ?string $updater_name;
    public ?Carbon $created_at;
    public ?Carbon $updated_at;
    public string $edit_url;
    public string $preview_url;
    public string $preview_pdf_url;
    public string $duplicate_url;
    public string $toggle_active_url;
    public string $set_default_url;
    public string $archive_url;
    public string $versions_url;
    public string $delete_url;
    public bool $is_legacy_mou;
    public object $rawModel;

    public static function fromDocumentTemplate(DocumentTemplate $doc): self
    {
        $item = new self();
        $item->id                = $doc->id;
        $item->uuid              = (string) $doc->uuid;
        $item->type              = $doc->type;
        $item->certificate_type  = $doc->certificate_type ?? 'magang';
        $item->name              = $doc->name;
        $item->description       = $doc->description;
        $item->editor_type       = $doc->editor_type ?? 'visual_doc';
        $item->state             = $doc->state ?? ($doc->is_active ? 'published' : 'draft');
        $item->is_active         = (bool) $doc->is_active;
        $item->is_default        = (bool) $doc->is_default;
        $item->version_number    = (int) $doc->version_number;
        $item->published_version = $doc->published_version ?? ($doc->is_active ? $doc->version_number : null);
        $item->creator_name      = $doc->creator?->name ?? 'Administrator';
        $item->updater_name      = $doc->updater?->name ?? ($doc->creator?->name ?? 'Admin');
        $item->created_at        = $doc->created_at ? Carbon::parse($doc->created_at) : null;
        $item->updated_at        = $doc->updated_at ? Carbon::parse($doc->updated_at) : null;
        $item->edit_url          = route('admin.document-templates.edit', $doc);
        $item->preview_url       = route('admin.document-templates.preview', $doc);
        $item->preview_pdf_url   = route('admin.document-templates.preview-pdf', $doc);
        $item->duplicate_url     = route('admin.document-templates.duplicate', $doc);
        $item->toggle_active_url = route('admin.document-templates.toggle-active', $doc);
        $item->set_default_url   = route('admin.document-templates.set-default', $doc);
        $item->archive_url       = route('admin.document-templates.archive', $doc);
        $item->versions_url      = route('admin.document-templates.versions', $doc);
        $item->delete_url        = route('admin.document-templates.destroy', $doc);
        $item->is_legacy_mou     = false;
        $item->rawModel          = $doc;

        return $item;
    }

    public static function fromMouTemplate(MouTemplate $mou): self
    {
        $item = new self();
        $item->id                = $mou->id;
        $item->uuid              = (string) $mou->public_uuid;
        $item->type              = DocumentTemplate::TYPE_MOU;
        $item->name              = $mou->name;
        $item->description       = 'Naskah template perjanjian kerja sama Tri Dharma Perguruan Tinggi';
        $item->editor_type       = 'visual_doc';
        $item->state             = $mou->state ?? ($mou->is_active ? 'published' : 'draft');
        $item->is_active         = (bool) $mou->is_active;
        $item->is_default        = (bool) ($mou->is_default ?? false);
        $item->version_number    = (int) $mou->version_number;
        $item->published_version = $mou->published_version ?? ($mou->is_active ? $mou->version_number : null);
        $item->creator_name      = $mou->createdBy?->name ?? 'Administrator';
        $item->updater_name      = $mou->updatedBy?->name ?? 'Admin';
        $item->created_at        = $mou->created_at ? Carbon::parse($mou->created_at) : null;
        $item->updated_at        = $mou->updated_at ? Carbon::parse($mou->updated_at) : null;
        $item->edit_url          = route('admin.mou-templates.edit', $mou);
        $item->preview_url       = route('admin.mou-templates.preview', $mou);
        $item->preview_pdf_url   = route('admin.mou-templates.preview-pdf', $mou);
        $item->duplicate_url     = route('admin.mou-templates.duplicate', $mou);
        $item->toggle_active_url = route('admin.mou-templates.toggle-active', $mou);
        $item->set_default_url   = route('admin.mou-templates.set-default', $mou);
        $item->archive_url       = route('admin.mou-templates.archive', $mou);
        $item->versions_url      = route('admin.mou-templates.edit', $mou);
        $item->delete_url        = route('admin.mou-templates.destroy', $mou);
        $item->is_legacy_mou     = true;
        $item->rawModel          = $mou;

        return $item;
    }

    public function getTypeLabel(?string $type = null): string
    {
        return DocumentTemplate::getTypeLabel($type ?? $this->type, $this->certificate_type);
    }

    public function getTypeBadgeClass(?string $type = null): string
    {
        return DocumentTemplate::getTypeBadgeClass($type ?? $this->type, $this->certificate_type);
    }

    public function getSubtypeBadge(): ?string
    {
        if ($this->type === 'certificate') {
            return $this->certificate_type === 'webinar' ? 'Webinar' : 'Magang';
        }
        return null;
    }

    public function __get(string $name)
    {
        return $this->rawModel->{$name} ?? null;
    }
}
