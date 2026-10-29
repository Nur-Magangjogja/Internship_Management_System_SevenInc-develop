<?php

namespace App\Http\Middleware;

use App\Services\BrandHostRegistryService;
use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Trust only the exact application origin and exact hostnames of verified active brands.
     * No unrestricted subdomain wildcard is used.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $patterns = app(BrandHostRegistryService::class)->trustedHostPatterns();

        // An empty trusted-host list may effectively disable host validation in some
        // Symfony/Laravel request paths. Fail closed if neither APP_URL nor the dynamic
        // registry provides a valid hostname.
        return $patterns !== [] ? $patterns : ['^$'];
    }
}
