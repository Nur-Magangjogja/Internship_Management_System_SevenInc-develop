<?php

namespace App\Http\Middleware;

use App\Services\BrandDomainResolverService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DetectBrandFromHost
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolver = app(BrandDomainResolverService::class);
        $brand = $resolver->resolveFromRequest($request);

        if ($brand) {
            $request->attributes->set('current_brand', $brand);
            $request->attributes->set('registration_host', $request->getHost());

            if ($request->hasSession()) {
                $request->session()->put('registration_brand_id', $brand->id);
                $request->session()->put('registration_brand_code', $brand->code);
                $request->session()->put('registration_host', $request->getHost());
            }

            if (function_exists('view')) {
                view()->share('currentBrand', $brand);
            }
        }

        return $next($request);
    }
}
