<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Division;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BrandDivisionService
{
    /** Return the active division names available to a brand's registration form. */
    public function registrationDivisionNames(Brand $brand): Collection
    {
        // Mode global eksplisit menggunakan seluruh divisi aktif global.
        if ($brand->division_mode === Brand::DIVISION_MODE_GLOBAL) {
            return Division::active()->pluck('name');
        }

        // Mode brand-specific hanya mengambil divisi aktif yang dipetakan ke brand terkait.
        if ($brand->division_mode === Brand::DIVISION_MODE_BRAND_SPECIFIC) {
            return $brand->divisions()
                ->where('divisions.is_active', true)
                ->orderBy('divisions.name')
                ->pluck('divisions.name');
        }

        // Self-healing / Kompatibilitas: Jika mode belum diset eksplisit (null) tetapi brand memiliki divisi di tabel pivot.
        if ($brand->division_mode === null) {
            $mapped = $brand->divisions()
                ->where('divisions.is_active', true)
                ->orderBy('divisions.name')
                ->pluck('divisions.name');

            if ($mapped->isNotEmpty()) {
                return $mapped;
            }

            // Kompatibilitas legacy: Hanya brand default (induk) dengan mode null tanpa mapping khusus yang mewarisi divisi global.
            // Brand non-default yang belum terkonfigurasi wajib fail-closed (menutup registrasi).
            if ($brand->is_default) {
                return Division::active()->pluck('name');
            }
        }

        return collect();
    }

    /** A brand with no available divisions must not silently fall back to another list. */
    public function isRegistrationClosed(Brand $brand): bool
    {
        return $this->registrationDivisionNames($brand)->isEmpty();
    }

    /** Validate a resolved division ID against both active status and the brand contract. */
    public function isDivisionAllowed(Brand $brand, int $divisionId): bool
    {
        $isActive = Division::query()
            ->whereKey($divisionId)
            ->where('is_active', true)
            ->exists();

        if (!$isActive) {
            return false;
        }

        // Mode global eksplisit mengizinkan seluruh divisi aktif.
        if ($brand->division_mode === Brand::DIVISION_MODE_GLOBAL) {
            return true;
        }

        // Mode brand-specific memverifikasi keberadaan divisi di tabel pivot brand_divisions.
        if ($brand->division_mode === Brand::DIVISION_MODE_BRAND_SPECIFIC) {
            return DB::table('brand_divisions')
                ->where('brand_id', $brand->id)
                ->where('division_id', $divisionId)
                ->exists();
        }

        // Self-healing / Kompatibilitas: Jika mode belum diset eksplisit (null) tetapi brand memiliki divisi di tabel pivot.
        if ($brand->division_mode === null) {
            $hasMappings = DB::table('brand_divisions')
                ->where('brand_id', $brand->id)
                ->exists();

            if ($hasMappings) {
                return DB::table('brand_divisions')
                    ->where('brand_id', $brand->id)
                    ->where('division_id', $divisionId)
                    ->exists();
            }

            // Kompatibilitas legacy: Brand default dengan mode null mengizinkan seluruh divisi aktif.
            if ($brand->is_default) {
                return true;
            }
        }

        return false;
    }
}
