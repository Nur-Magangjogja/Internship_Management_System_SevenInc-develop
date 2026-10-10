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
        $expectedKey = config('services.absen_djuragan.api_key') 
            ?: env('ABSEN_DJURAGAN_API_KEY') 
            ?: 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c';

        if (empty($expectedKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Layanan API eksternal belum dikonfigurasi pada server.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Cek header X-API-KEY, Authorization Bearer, atau parameter query api_key
        $providedKey = $request->header('X-API-KEY') 
            ?: $request->bearerToken() 
            ?: $request->query('api_key');

        if (empty($providedKey) || !hash_equals((string) $expectedKey, (string) $providedKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Kunci API (X-API-KEY) tidak valid atau tidak disertakan.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
