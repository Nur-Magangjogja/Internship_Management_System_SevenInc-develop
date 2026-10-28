<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BrandDomainResolverService
{
    /**
     * Resolusi Brand berdasarkan Host request saat ini.
     */
    public function resolveFromRequest(Request $request): ?Brand
    {
        $host = strtolower(trim($request->getHost()));
        return $this->resolveFromHost($host);
    }

    /**
     * Resolusi Brand berdasarkan nama Host string.
     */
    public function resolveFromHost(string $host): ?Brand
    {
        $host = strtolower(trim($host));
        $brandConfig = config("brand_domains.hosts.{$host}");

        if ($brandConfig && !empty($brandConfig['code'])) {
            return $this->getBrandByCode($brandConfig['code']);
        }

        // Cek apakah host berformat subdomain dari primary_domain
        $allConfig = config('brand_domains.hosts', []);
        foreach ($allConfig as $cfgHost => $meta) {
            if (!empty($meta['primary_domain']) && (str_ends_with($host, '.' . $meta['primary_domain']) || $host === $meta['primary_domain'])) {
                return $this->getBrandByCode($meta['code']);
            }
        }

        // Cek langsung dari database jika ada pendaftaran host baru via admin
        $dbBrand = Brand::whereRaw('LOWER(registration_host) = ?', [$host])
            ->orWhereRaw('LOWER(primary_domain) = ?', [$host])
            ->first();

        if ($dbBrand) {
            return $dbBrand;
        }

        // Fallback ke default brand sistem (SEVEN INC atau brand yang ditandai is_default = true)
        return Brand::getDefaultBrand()
            ?: ($this->getBrandByCode(config('brand_domains.default_brand_code', 'SI')) ?: $this->getBrandByCode('SVII'));
    }

    /**
     * Ambil Brand dari cache / DB berdasarkan kode.
     */
    public function getBrandByCode(string $code): ?Brand
    {
        $code = strtoupper(trim($code));

        return Brand::where('code', $code)
            ->orWhereRaw('UPPER(code) = ?', [$code])
            ->first();
    }
}
