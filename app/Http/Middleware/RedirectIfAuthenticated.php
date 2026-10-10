<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Jika user sudah login dan mencoba mengakses route untuk guest,
     * arahkan sesuai role/guard.
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                $user = Auth::guard($guard)->user();

                // Jika user sudah login dan coba buka halaman login admin,
                // langsung arahkan ke dashboard admin.
                if (
                    $request->routeIs('admin.login') ||
                    $request->routeIs('admin.login.submit') ||
                    $request->is('admin/login')
                ) {
                    if ($user && ($user->role ?? null) === 'admin') {
                        return redirect()->route('admin.dashboard.index');
                    }
                    // Login tapi bukan admin → arahkan ke HOME default
                    return redirect(RouteServiceProvider::HOME);
                }

                // Fallback umum: user sudah login, ke halaman guest lain
                if ($user && ($user->role ?? null) === 'admin') {
                    return redirect()->route('admin.dashboard.index');
                }

                if ($user && ($user->role ?? null) === 'hr_staff') {
                    return redirect()->route('hr.dashboard');
                }

                return redirect(RouteServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}
