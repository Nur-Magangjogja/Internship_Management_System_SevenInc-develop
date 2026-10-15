<?php

namespace App\Services\AbsenIntegration;

use App\Services\AbsenIntegration\Exceptions\UnresolvedMasterDataException;

class AbsenMasterDataResolver
{
    /**
     * Resolusi IMS institution_id ke Absen school_id.
     * Tidak pernah meneruskan primary key IMS secara langsung tanpa pemetaan eksplisit.
     *
     * @throws UnresolvedMasterDataException
     */
    public function resolveSchoolId(?int $institutionId, bool $required = true): ?int
    {
        if (empty($institutionId)) {
            if ($required) {
                throw new UnresolvedMasterDataException('institution', 0, 'Institution ID kosong pada pendaftaran magang.');
            }
            return null;
        }

        $mappings = config('services.absen_djuragan.mappings.institutions', []);

        if (array_key_exists($institutionId, $mappings)) {
            return (int) $mappings[$institutionId];
        }

        if ($required) {
            throw new UnresolvedMasterDataException('institution', $institutionId);
        }

        return null;
    }

    /**
     * Resolusi IMS division_id ke Absen division_id.
     *
     * @throws UnresolvedMasterDataException
     */
    public function resolveDivisionId(?int $divisionId, bool $required = true): ?int
    {
        if (empty($divisionId)) {
            if ($required) {
                throw new UnresolvedMasterDataException('division', 0, 'Division ID kosong pada pendaftaran magang.');
            }
            return null;
        }

        $mappings = config('services.absen_djuragan.mappings.divisions', []);

        if (array_key_exists($divisionId, $mappings)) {
            return (int) $mappings[$divisionId];
        }

        if ($required) {
            throw new UnresolvedMasterDataException('division', $divisionId);
        }

        return null;
    }

    /**
     * Resolusi IMS brand_id ke Absen brand_id.
     *
     * @throws UnresolvedMasterDataException
     */
    public function resolveBrandId(?int $brandId, bool $required = false): ?int
    {
        if (empty($brandId)) {
            return null;
        }

        $mappings = config('services.absen_djuragan.mappings.brands', []);

        if (array_key_exists($brandId, $mappings)) {
            return (int) $mappings[$brandId];
        }

        if ($required) {
            throw new UnresolvedMasterDataException('brand', $brandId);
        }

        return null;
    }

    /**
     * Memeriksa apakah pemetaan institusi dan divisi tersedia secara lengkap.
     */
    public function hasCompleteMapping(?int $institutionId, ?int $divisionId): bool
    {
        if (empty($institutionId) || empty($divisionId)) {
            return false;
        }

        $institutions = config('services.absen_djuragan.mappings.institutions', []);
        $divisions    = config('services.absen_djuragan.mappings.divisions', []);

        return array_key_exists($institutionId, $institutions)
            && array_key_exists($divisionId, $divisions);
    }
}
