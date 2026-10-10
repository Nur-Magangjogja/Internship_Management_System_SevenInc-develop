<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Memeriksa apakah pengguna yang sedang login berstatus aktif.
     * Jika akun dinonaktifkan (is_banned atau soft-deleted) di tengah sesi aktif,
     * segera batalkan sesi (invalidate), logout, dan tolak permintaan berikutnya.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();

            if (!$user->isActive()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Akun Anda telah dinonaktifkan atau dihapus. Hubungi admin untuk informasi lebih lanjut.'
                    ], 403);
                }

                return redirect()->route('user.login')
                    ->with('error', 'Akun Anda telah dinonaktifkan atau dihapus. Hubungi admin untuk informasi lebih lanjut.');
            }
        }

        return $next($request);
    }
}
