<?php

namespace App\Services\MailMerge;

use ArrayAccess;
use JsonSerializable;

/**
 * Typed Data Transfer Object (DTO) untuk konteks dokumen dan resolusi Mail Merge.
 * Memisahkan secara ketat mode authoring template (tanpa DB query dan tanpa pengikatan pihak)
 * dari mode generate / penerbitan nyata.
 */
class DocumentContext implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public string $documentType,
        public array $variables = [],
        public array $assetReferences = [],
        public array $resolvedImages = [],
        public array $missingAssets = [],
        public ?string $documentNumber = null,
        public ?int $internshipRegistrationId = null,
        public ?int $institutionId = null,
        public ?int $brandId = null,
        public ?int $mouId = null,
        public bool $isLiveIssuance = false,
        public bool $isPreviewMock = false,
        public array $diagnostics = []
    ) {}

    /**
     * Konstruktor khusus untuk mode Authoring / Editing Template Universal (INV-01).
     * JAMINAN KONTRAK: 0 database queries ke brand/institution/intern, tidak mengikat pihak sasaran.
     */
    public static function forAuthoring(string $documentType): self
    {
        $canonicalType = VariableRegistry::normalizeToken($documentType);
        $tokens = VariableRegistry::getTokensForDocumentType($documentType);

        $mockVariables = [];
        $mockAssets = [];
        $resolvedImages = [];

        foreach ($tokens as $key => $def) {
            $mockVariables[$key] = $def['sample'] ?? '';
            foreach ($def['aliases'] ?? [] as $alias) {
                if (!isset($mockVariables[$alias])) {
                    $mockVariables[$alias] = $def['sample'] ?? '';
                }
            }

            if (($def['data_type'] ?? '') === VariableRegistry::TYPE_IMAGE) {
                $mockAssets[$key] = [
                    'asset_id'   => null,
                    'name'       => '[Slot Dinamis] ' . $def['label'],
                    'version'    => 1,
                    'checksum'   => null,
                    'category'   => $def['asset_type'] ?? 'logo',
                    'scope'      => $def['source_owner_policy'] ?? 'brand',
                    'is_slot'    => true,
                ];
                $resolvedImages[$key] = $def['sample'] ?? null;
            }
        }

        return new self(
            documentType: $canonicalType,
            variables: $mockVariables,
            assetReferences: $mockAssets,
            resolvedImages: $resolvedImages,
            missingAssets: [],
            documentNumber: 'DRAFT/PREVIEW/YYYY/000',
            internshipRegistrationId: null, // Universal template must NOT bind intern
            institutionId: null,            // Universal template must NOT bind institution
            brandId: null,                  // Universal template must NOT bind brand
            mouId: null,
            isLiveIssuance: false,
            isPreviewMock: true,
            diagnostics: ['authoring_mode' => true, 'bound_entities' => false]
        );
    }

    /**
     * Buat DocumentContext dari array resolusi legacy.
     */
    public static function fromArray(array $data, string $documentType): self
    {
        return new self(
            documentType: $documentType,
            variables: $data['variables'] ?? [],
            assetReferences: $data['asset_references'] ?? [],
            resolvedImages: $data['resolved_images'] ?? [],
            missingAssets: $data['missing_assets'] ?? [],
            documentNumber: $data['document_number'] ?? null,
            internshipRegistrationId: $data['internship_registration_id'] ?? null,
            institutionId: $data['institution_id'] ?? null,
            brandId: $data['brand_id'] ?? null,
            mouId: $data['mou_id'] ?? null,
            isLiveIssuance: (bool) ($data['is_live_issuance'] ?? false),
            isPreviewMock: (bool) ($data['is_preview_mock'] ?? false),
            diagnostics: $data['diagnostics'] ?? []
        );
    }

    /**
     * Periksa apakah konteks ini berada dalam mode authoring (tanpa pengikatan pihak).
     */
    public function isAuthoring(): bool
    {
        return $this->internshipRegistrationId === null
            && $this->institutionId === null
            && $this->brandId === null
            && $this->mouId === null
            && !$this->isLiveIssuance;
    }

    /**
     * Dapatkan nilai variabel berdasarkan kunci kanonis atau alias.
     */
    public function getVariable(string $key, mixed $default = null): mixed
    {
        $canonical = VariableRegistry::normalizeToken($key, $this->documentType);
        return $this->variables[$canonical] ?? ($this->variables[$key] ?? $default);
    }

    /**
     * Periksa apakah variabel memiliki nilai terisi dan bukan null/strip.
     */
    public function hasVariable(string $key): bool
    {
        $val = $this->getVariable($key);
        return $val !== null && trim((string) $val) !== '' && trim((string) $val) !== '-';
    }

    /**
     * Dapatkan Data URI gambar yang telah diselesaikan untuk slot aset.
     */
    public function getAssetDataUri(string $tokenKey): ?string
    {
        $canonical = VariableRegistry::normalizeToken($tokenKey, $this->documentType);
        return $this->resolvedImages[$canonical] ?? ($this->resolvedImages[$tokenKey] ?? null);
    }

    /**
     * Konversi ke representasi array lengkap untuk kompatibilitas ke renderer lama.
     */
    public function toArray(): array
    {
        return [
            'document_type'              => $this->documentType,
            'variables'                  => $this->variables,
            'asset_references'           => $this->assetReferences,
            'resolved_images'            => $this->resolvedImages,
            'missing_assets'             => $this->missingAssets,
            'document_number'            => $this->documentNumber,
            'internship_registration_id' => $this->internshipRegistrationId,
            'institution_id'             => $this->institutionId,
            'brand_id'                   => $this->brandId,
            'mou_id'                     => $this->mouId,
            'is_live_issuance'           => $this->isLiveIssuance,
            'is_preview_mock'            => $this->isPreviewMock,
            'diagnostics'                => $this->diagnostics,
        ];
    }

    // ==================== ARRAYACCESS IMPLEMENTATION ====================

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function &offsetGet(mixed $offset): mixed
    {
        if ($offset === 'variables') {
            return $this->variables;
        }
        if ($offset === 'asset_references') {
            return $this->assetReferences;
        }
        if ($offset === 'resolved_images') {
            return $this->resolvedImages;
        }
        if ($offset === 'missing_assets') {
            return $this->missingAssets;
        }
        if ($offset === 'diagnostics') {
            return $this->diagnostics;
        }

        $default = null;
        return $default;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === 'variables') {
            $this->variables = (array) $value;
        } elseif ($offset === 'asset_references') {
            $this->assetReferences = (array) $value;
        } elseif ($offset === 'resolved_images') {
            $this->resolvedImages = (array) $value;
        } elseif ($offset === 'missing_assets') {
            $this->missingAssets = (array) $value;
        } elseif ($offset === 'document_number') {
            $this->documentNumber = $value;
        } elseif ($offset === 'diagnostics') {
            $this->diagnostics = (array) $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->offsetSet($offset, null);
    }

    // ==================== JSONSERIALIZABLE IMPLEMENTATION ====================

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
