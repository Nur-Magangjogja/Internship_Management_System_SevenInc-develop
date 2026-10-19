<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AbsenApiKeyMiddleware
{
    /**
     * Handle an incoming request from Absen Djuragan.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $validKeys = array_values(array_filter([
            config('services.absen_djuragan.inbound_api_key'),
            config('services.absen_djuragan.api_key'),
        ]));

        if (empty($validKeys)) {
            return response()->json([
                'success' => false,
                'message' => 'Layanan API eksternal belum dikonfigurasi pada server.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Baca kunci dari header X-API-KEY, Authorization Bearer, atau query string api_key
        $providedKey = (string) ($request->header('X-API-KEY') 
            ?: $request->bearerToken() 
            ?: $request->query('api_key'));

        $isAuthorized = false;
        if (!empty($providedKey)) {
            foreach ($validKeys as $key) {
                if (!empty($key) && hash_equals((string) $key, $providedKey)) {
                    $isAuthorized = true;
                    break;
                }
            }
        }

        if (!$isAuthorized) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Kunci API (X-API-KEY) tidak valid atau tidak disertakan.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
