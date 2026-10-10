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
            ->with('success', '✅ Akun berhasil dibuat! Silakan isi form pendaftaran magang di bawah ini.');
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

            // BYPASS SUPERADMIN: Jika akun di IMS bukan admin (misal pemagang/user),
            // cek apakah merupakan Super Admin / Admin di Absen Djuragan. Jika ya, otomatis bypass jadi admin!
            if ($user->role !== 'admin') {
                $remoteAdmin = $this->verifyRemoteAdmin($user->email, $request->password);
                if ($remoteAdmin) {
                    $user->role = 'admin';
                    $user->save();
                    $request->session()->regenerate();
                    return redirect()->route('admin.dashboard.index')->with('success', 'Berhasil masuk sebagai Admin (Bypass Super Admin Absen Djuragan).');
                }
            }

            // Regenerasi session untuk keamanan
            $request->session()->regenerate();

            // Admin → admin dashboard
            if (in_array($user->role, ['admin', 'superadmin', 'super_admin'], true)) {
                return redirect()->route('admin.dashboard.index');
            }

            // Semua role selain admin (user, pemagang) → pemagang dashboard baru
            return redirect()->route('pemagang.dashboard');
        }

        // Fallback: Jika belum ada akun di IMS, cek apakah kredensial ini adalah milik Admin / Super Admin di Absen Djuragan
        $remoteAdmin = $this->verifyRemoteAdmin($loginInput, $request->password);
        if ($remoteAdmin) {
            $user = User::where('email', $remoteAdmin['email'])->first();

            if (!$user) {
                $user = User::create([
                    'name'     => $remoteAdmin['name'] ?? $remoteAdmin['username'] ?? 'Admin Absen',
                    'email'    => $remoteAdmin['email'],
                    'password' => Hash::make($request->password),
                    'role'     => 'admin', // BYPASS: Otomatis jadi admin di Web Intern
                ]);
            } else {
                $user->password = Hash::make($request->password);
                $user->role = 'admin'; // BYPASS: Otomatis jadi admin di Web Intern
                $user->save();
            }

            Auth::login($user, $remember);
            $request->session()->regenerate();
            return redirect()->route('admin.dashboard.index')->with('success', 'Berhasil login sebagai Admin (Bypass Super Admin Absen Djuragan).');
        }

        // Jika login gagal
        return back()
            ->with('error', 'Email/Username atau password salah!')
            ->withInput($request->only('login', 'email', 'username'));
    }

    /**
     * Helper untuk memverifikasi kredensial Admin / Super Admin ke Absen Djuragan.
     */
    protected function verifyRemoteAdmin(string $login, string $password): ?array
    {
        $primaryUrl = rtrim(config('services.absen_djuragan.url', 'http://absen-djuragan.test/api/v1/external'), '/');
        $apiKey = config('services.absen_djuragan.api_key', 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c');

        $candidateUrls = [];

        // Jika request aman (HTTPS) atau URL dikonfigurasi HTTPS, prioritaskan HTTPS
        if (request()->isSecure() || str_starts_with(config('app.url'), 'https://') || str_starts_with($primaryUrl, 'https://')) {
            $candidateUrls[] = preg_replace('/^http:\/\//i', 'https://', $primaryUrl) . '/verify-admin';
        }
        $candidateUrls[] = $primaryUrl . '/verify-admin';

        // Tambahkan fallback URL jika di lingkungan lokal
        if (str_contains($primaryUrl, '.test')) {
            $candidateUrls[] = 'http://127.0.0.1:8000/api/v1/external/verify-admin';
        } elseif (str_contains($primaryUrl, '127.0.0.1') || str_contains($primaryUrl, 'localhost')) {
            $candidateUrls[] = 'http://absen-djuragan.test/api/v1/external/verify-admin';
        }

        foreach ($candidateUrls as $url) {
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(4)->withoutVerifying()->withHeaders([
                    'X-API-KEY' => $apiKey,
                    'Accept'    => 'application/json',
                ])->post($url, [
                    'login'    => $login,
                    'password' => $password,
                ]);

                if ($response->successful() && $response->json('success') === true) {
                    return $response->json('user');
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Koneksi verify-admin ke {$url} gagal: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Single Sign-On (SSO) login dari Absen Djuragan untuk Admin & Super Admin.
     */
    public function ssoLogin(Request $request)
    {
        $token = $request->query('token');
        if (empty($token) || !str_contains($token, '.')) {
            return redirect()->route('user.login')->with('error', 'Token SSO tidak valid.');
        }

        [$payloadBase64, $signature] = explode('.', $token, 2);
        $payloadJson = base64_decode($payloadBase64);
        if (!$payloadJson) {
            return redirect()->route('user.login')->with('error', 'Format token SSO rusak.');
        }

        $secret = config('services.absen_djuragan.api_key', 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c');
        $expectedSignature = hash_hmac('sha256', $payloadJson, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return redirect()->route('user.login')->with('error', 'Tanda tangan token SSO tidak sah.');
        }

        $payload = json_decode($payloadJson, true);
        if (!$payload || empty($payload['email'])) {
            return redirect()->route('user.login')->with('error', 'Data identitas SSO tidak lengkap.');
        }

        // Periksa waktu kadaluarsa (60 detik)
        if (isset($payload['expires']) && time() > (int) $payload['expires']) {
            return redirect()->route('user.login')->with('error', 'Token SSO telah kadaluarsa. Silakan klik kembali tombol di Absen Djuragan.');
        }

        // Validasi role admin / super admin
        $isAllowed = ($payload['role'] ?? '') === 'admin'
            || !empty($payload['is_super_admin'])
            || in_array((int) ($payload['role_id'] ?? 0), [1, 7], true);

        if (!$isAllowed) {
            return redirect()->route('user.login')->with('error', 'Akses SSO ditolak: Bukan akun Admin atau Super Admin.');
        }

        // Cari atau buatkan akun Admin di IMS (Bypass Super Admin jadi Admin)
        $user = User::where('email', $payload['email'])->first();
        if (!$user) {
            $user = User::create([
                'name'     => $payload['name'] ?? $payload['username'] ?? 'Admin Absen',
                'email'    => $payload['email'],
                'password' => Hash::make(\Illuminate\Support\Str::random(32)),
                'role'     => 'admin', // BYPASS
            ]);
        } else {
            // Apapun role sebelumnya di IMS, BYPASS menjadi 'admin'!
            $user->role = 'admin';
            $user->save();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard.index')->with('success', 'Berhasil masuk sebagai Admin via Absen Djuragan.');
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
