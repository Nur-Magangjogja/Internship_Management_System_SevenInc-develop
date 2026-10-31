<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Http\Request;

class BrandDomainResolverService
{
    public function resolveFromRequest(Request $request): ?Brand
    {
        return $this->resolveFromHost($request->getHost());
    }

    public function resolveFromHost(string $host): ?Brand
    {
        $host = strtolower(rtrim(trim($host), '.'));
        $registry = app(BrandHostRegistryService::class);
        $brandId = $registry->brandIdForHost($host);

        if ($brandId !== null) {
            return Brand::query()
                ->whereKey($brandId)
                ->where('lifecycle_status', Brand::STATUS_ACTIVE)
                ->whereNotNull('domain_verified_at')
                ->first();
        }

        // The main application origin and local development hosts are not tenant aliases.
        // Retain the legacy default-brand context only for these explicitly known origins.
        $appHost = strtolower(rtrim((string) parse_url((string) config('app.url'), PHP_URL_HOST), '.'));
        $isLocalHost = app()->environment('local', 'testing')
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        if (($appHost !== '' && $host === $appHost) || $isLocalHost) {
            $default = Brand::getDefaultBrand();
            if ($default && $default->isRegistrationHostActive()) {
                return $default;
            }

            // The main application origin strictly belongs to the default corporate tenant.
            // Never silently fall back to an arbitrary active tenant brand. Retain the default
            // brand context if present, or null if no default brand exists.
            return $default;
        }

        // Unknown hosts must not be silently assigned to the default tenant.
        return null;
    }

    public function getBrandByCode(string $code): ?Brand
    {
        $code = strtoupper(trim($code));
        return Brand::whereRaw('UPPER(code) = ?', [$code])->first();
    }
}
