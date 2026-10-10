<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // Show registration form
    public function showRegisterForm()
    {
        if (auth()->check()) {
            $role = auth()->user()->role;
            if ($role === 'admin') {
                return redirect()->route('admin.dashboard.index');
            }
            if ($role === 'hr_staff') {
                return redirect()->route('hr.dashboard');
            }
            return redirect()->route('pemagang.dashboard');
        }
        return view('auth.admin-register');
    }

    // Handle registration
    public function register(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string', 'max:150', 'unique:users,name'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'username.required' => 'Username wajib diisi.',
            'username.unique' => 'Username sudah digunakan, silakan pilih username lain.',
            'username.max' => 'Username maksimal 150 karakter.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'email.unique' => 'Email sudah terdaftar.',
            'password.required' => 'Password wajib diisi.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ]);

        $user = User::create([
            'name' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'user',
        ]);

        // Auto-login setelah register
        Auth::login($user);
        $request->session()->regenerate();

        // Langsung ke form magang
        return redirect()->route('pemagang.registration.form')
            ->with('success', 'Akun berhasil dibuat! Silakan isi form pendaftaran magang di bawah ini.');
    }

    /**
     * Tampilkan form login admin
     */
    public function showLoginForm()
    {
        if (auth()->check()) {
            $role = auth()->user()->role;
            if ($role === 'admin') {
                return redirect()->route('admin.dashboard.index');
            }
            if ($role === 'hr_staff') {
                return redirect()->route('hr.dashboard');
            }
            return redirect()->route('pemagang.dashboard');
        }
        return view('auth.admin-login');
    }


    /**
     * Proses login (admin/user/pemagang via email atau username)
     */
    public function login(Request $request)
    {
        // Mendukung input 'login', 'email', atau 'username'
        $loginInput = $request->input('login') ?? $request->input('email') ?? $request->input('username');
        $request->merge(['login' => $loginInput]);

        // Validasi kredensial login
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required'],
        ], [
            'login.required' => 'Email atau username wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        // Cek apakah input berupa format email atau username (kolom 'name')
        $fieldType = filter_var($loginInput, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

        $credentials = [
            $fieldType => $loginInput,
            'password' => $request->password,
        ];

        $remember = $request->boolean('remember');
        $attempt = auth()->attempt($credentials, $remember);

        // Fallback jika tidak ditemukan pada fieldType pertama (misal username berformat mirip email atau sebaliknya)
        if (!$attempt) {
            $fallbackField = ($fieldType === 'email') ? 'name' : 'email';
            $attempt = auth()->attempt([
                $fallbackField => $loginInput,
                'password' => $request->password,
            ], $remember);
        }

        // Proses jika login berhasil
        if ($attempt) {
            // Regenerasi session untuk keamanan
            $request->session()->regenerate();

            $user = auth()->user();

            // Cek apakah akun dibanned
            if ($user->is_banned) {
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return back()
                    ->with('error', 'Akun Anda telah dinonaktifkan. Hubungi admin untuk informasi lebih lanjut.')
                    ->withInput($request->only('login', 'email', 'username'));
            }

            // Admin → admin dashboard
            if ($user->role === 'admin') {
                return redirect()->route('admin.dashboard.index');
            }

            // HR Staff → HR dashboard
            if ($user->role === 'hr_staff') {
                return redirect()->route('hr.dashboard');
            }

            // Semua role selain admin & hr_staff (user, pemagang) → pemagang dashboard baru
            return redirect()->route('pemagang.dashboard');
        }

        // Jika login gagal
        return back()
            ->with('error', 'Email/Username atau password salah!')
            ->withInput($request->only('login', 'email', 'username'));
    }





    /**
     * Logout admin
     */
    public function logout(Request $request)
    {
        Auth::logout();

        // Invalidate session to prevent session hijacking
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Redirect back to login page after logout
        return redirect()->route('user.login')->with('success', 'Berhasil logout.');
    }
}
