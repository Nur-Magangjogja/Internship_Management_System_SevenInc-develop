<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        // Belum login → arahkan ke login
        if (!Auth::check()) {
            return redirect()->route('user.login')
                ->with('error', 'Silakan login terlebih dahulu.');
        }

        // Normalisasi daftar role yang diizinkan (mendukung format variadic maupun koma: 'admin,hr_staff')
        $allowedRoles = [];
        foreach ($roles as $role) {
            foreach (explode(',', $role) as $r) {
                $trimmed = trim($r);
                if ($trimmed !== '') {
                    $allowedRoles[] = $trimmed;
                }
            }
        }

        $userRole = Auth::user()->role;

        // Bypass: superadmin otomatis memiliki akses penuh ke area admin
        if (in_array('admin', $allowedRoles, true) && in_array($userRole, ['admin', 'superadmin', 'super_admin'], true)) {
            return $next($request);
        }

        // Sudah login tapi role tidak cocok → arahkan ke dashboard masing-masing secara aman
        if (!in_array($userRole, $allowedRoles, true)) {
            if ($request->expectsJson()) {
                abort(403, 'Anda tidak memiliki akses ke halaman tersebut.');
            }

            // Cegah circular redirection loop jika request saat ini sudah berada di endpoint dashboard
            if (
                $request->routeIs('pemagang.dashboard') ||
                $request->routeIs('user.dashboard') ||
                $request->is('pemagang/dashboard') ||
                $request->is('user/dashboard')
            ) {
                abort(403, 'Anda tidak memiliki akses ke halaman tersebut.');
            }

            if ($userRole === 'admin') {
                return redirect()->route('admin.dashboard.index')
                    ->with('error', 'Anda tidak memiliki akses ke halaman tersebut.');
            }

            if ($userRole === 'hr_staff') {
                return redirect()->route('hr.dashboard')
                    ->with('error', 'Anda tidak memiliki akses ke halaman tersebut.');
            }

            return redirect()->route('user.dashboard')
                ->with('error', 'Anda tidak memiliki akses ke halaman tersebut.');
        }

        return $next($request);
    }
}
