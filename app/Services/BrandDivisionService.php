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
        // NULL is a legacy contract and preserves the historical global behavior.
        if (in_array($brand->division_mode, [null, Brand::DIVISION_MODE_GLOBAL], true)) {
            return Division::active()->pluck('name');
        }

        // Invalid persisted mode must fail closed instead of exposing all global divisions.
        if ($brand->division_mode !== Brand::DIVISION_MODE_BRAND_SPECIFIC) {
            return collect();
        }

        return $brand->divisions()
            ->where('divisions.is_active', true)
            ->orderBy('divisions.name')
            ->pluck('divisions.name');
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

        // NULL is legacy global behavior. Unknown explicit values fail closed.
        if (in_array($brand->division_mode, [null, Brand::DIVISION_MODE_GLOBAL], true)) {
            return true;
        }
        if ($brand->division_mode !== Brand::DIVISION_MODE_BRAND_SPECIFIC) {
            return false;
        }

        return DB::table('brand_divisions')
            ->where('brand_id', $brand->id)
            ->where('division_id', $divisionId)
            ->exists();
    }
}
