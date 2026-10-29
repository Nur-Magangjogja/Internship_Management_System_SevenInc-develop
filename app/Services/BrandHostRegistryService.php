<?php

namespace App\Services;

use App\Models\Brand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Runtime registry for brand hosts that are both verified and active.
 * The registry contains exact hosts only; it never trusts arbitrary subdomains.
 */
class BrandHostRegistryService
{
    public const CACHE_KEY = 'brands.host_registry.v1';
    private const CACHE_TTL_MINUTES = 10;

    /** @return array<string, int> normalized hostname => brand ID */
    public function registry(): array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Continue to the database if the configured cache backend is temporarily unavailable.
        }

        try {
            if (!Schema::hasTable('brands')
                || !Schema::hasColumn('brands', 'lifecycle_status')
                || !Schema::hasColumn('brands', 'domain_verified_at')) {
                return [];
            }

            $brands = Brand::query()
                ->where('lifecycle_status', Brand::STATUS_ACTIVE)
                ->whereNotNull('domain_verified_at')
                ->get(['id', 'registration_host', 'primary_domain']);

            $registry = [];
            $conflicts = [];
            foreach ($brands as $brand) {
                foreach ([$brand->registration_host, $brand->primary_domain] as $rawHost) {
                    $host = $this->normalizeHostname((string) $rawHost);
                    if ($host === null) {
                        continue;
                    }

                    if (isset($conflicts[$host])) {
                        continue;
                    }

                    if (isset($registry[$host]) && $registry[$host] !== (int) $brand->id) {
                        $previousBrandId = $registry[$host];
                        unset($registry[$host]);
                        $conflicts[$host] = true;
                        Log::critical('Host brand ambigu tidak dimasukkan ke registry tepercaya.', [
                            'host' => $host,
                            'brand_ids' => [$previousBrandId, (int) $brand->id],
                        ]);
                        continue;
                    }

                    $registry[$host] = (int) $brand->id;
                }
            }

            foreach (array_keys($conflicts) as $conflictingHost) {
                unset($registry[$conflictingHost]);
            }

            try {
                Cache::put(self::CACHE_KEY, $registry, now()->addMinutes(self::CACHE_TTL_MINUTES));
            } catch (Throwable $cacheException) {
                Log::warning('Registry host brand berhasil dibaca tetapi gagal disimpan ke cache.', [
                    'exception' => get_class($cacheException),
                ]);
            }
            return $registry;
        } catch (Throwable $e) {
            // Fail closed. Do not trust a database-derived host list when its integrity is unknown.
            Log::warning('Registry host brand tidak dapat dimuat; hanya host aplikasi tetap yang akan dipercaya.', [
                'exception' => get_class($e),
            ]);

            return [];
        }
    }

    /** @return array<int, string> */
    public function trustedHostnames(): array
    {
        $hosts = array_keys($this->registry());
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = strtolower(rtrim($appHost, '.'));
        }

        if (app()->environment('local', 'testing')) {
            $hosts[] = 'localhost';
            $hosts[] = '127.0.0.1';
            $hosts[] = '::1';
        }

        $normalizedHosts = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));
            if (app()->environment('local', 'testing') && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                $normalizedHosts[] = $host;
                continue;
            }

            $normalized = $this->normalizeHostname($host);
            if ($normalized !== null) {
                $normalizedHosts[] = $normalized;
            }
        }

        return array_values(array_unique($normalizedHosts));
    }

    /** @return array<int, string> Laravel TrustHosts regular-expression patterns */
    public function trustedHostPatterns(): array
    {
        return array_map(
            fn (string $host): string => '^' . preg_quote($host, '/') . '$',
            $this->trustedHostnames()
        );
    }

    public function brandIdForHost(string $host): ?int
    {
        $normalized = $this->normalizeHostname($host);
        if ($normalized === null) {
            return null;
        }

        $id = $this->registry()[$normalized] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function normalizeHostname(string $host): ?string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');
        if ($host === '' || str_contains($host, '/') || str_contains($host, ':')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host)) {
            return null;
        }

        return $host;
    }
}
