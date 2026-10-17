<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
        // Normalisasi input awal
        if ($request->has('email')) {
            $request->merge(['email' => strtolower(trim((string) $request->email))]);
        }
        if ($request->has('username')) {
            $request->merge(['username' => trim((string) $request->username)]);
        }

        $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:150', 'unique:users,name'],
            'email'    => ['required', 'string', 'email:rfc,filter', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'username.required'  => 'Username wajib diisi.',
            'username.min'       => 'Username minimal 3 karakter.',
            'username.max'       => 'Username maksimal 150 karakter.',
            'username.unique'    => 'Username sudah digunakan, silakan pilih username lain.',
            'email.required'     => 'Email wajib diisi.',
            'email.email'        => 'Format email tidak valid.',
            'email.max'          => 'Email maksimal 255 karakter.',
            'email.unique'       => 'Email sudah terdaftar. Silakan login atau gunakan email lain.',
            'password.required'  => 'Password wajib diisi.',
            'password.min'       => 'Password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ]);

        // Proteksi eskalasi privilege: selalu paksa role = 'user' (calon pemagang)
        $user = User::create([
            'name'     => $request->username,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => 'user',
        ]);

        // Auto-login setelah register
        Auth::login($user);
        $request->session()->regenerate();

        // Langsung ke form pendaftaran magang
        return redirect()->route('pemagang.registration.form')
            ->with('success', 'Akun berhasil dibuat! Silakan lengkapi formulir pendaftaran magang berikut.');
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

            // Cek apakah akun baru masih dalam status pending aktivasi
            if ($user->isActivationPending()) {
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return back()
                    ->with('error', 'Akun Anda belum diaktivasi. Silakan periksa email Anda untuk mengaktifkan akun sebelum masuk.')
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

            // HR Staff → HR dashboard
            if ($user->role === 'hr_staff') {
                return redirect()->route('hr.dashboard');
            }

            // Semua role selain admin & hr_staff (user, pemagang) → pemagang dashboard baru
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

        // Fallback 2: Cek apakah kredensial ini adalah milik Pemagang yang terdaftar di Absen Djuragan
        $remoteIntern = $this->verifyRemoteIntern($loginInput, $request->password);
        if ($remoteIntern) {
            $user = $this->syncOrProvisionRemoteIntern(
                $remoteIntern['email'],
                $remoteIntern,
                $request->password
            );

            Auth::login($user, $remember);
            $request->session()->regenerate();
            return redirect()->route('pemagang.dashboard')->with('success', 'Berhasil masuk ke InternHub.');
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
     * Helper untuk memverifikasi kredensial Pemagang ke Absen Djuragan.
     */
    protected function verifyRemoteIntern(string $login, string $password): ?array
    {
        $primaryUrl = rtrim(config('services.absen_djuragan.url', 'http://absen-djuragan.test/api/v1/external'), '/');
        $apiKey = config('services.absen_djuragan.api_key', 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c');

        $candidateUrls = [];

        // Jika request aman (HTTPS) atau URL dikonfigurasi HTTPS, prioritaskan HTTPS
        if (request()->isSecure() || str_starts_with(config('app.url'), 'https://') || str_starts_with($primaryUrl, 'https://')) {
            $candidateUrls[] = preg_replace('/^http:\/\//i', 'https://', $primaryUrl) . '/verify-intern';
        }
        $candidateUrls[] = $primaryUrl . '/verify-intern';

        // Tambahkan fallback URL jika di lingkungan lokal
        if (str_contains($primaryUrl, '.test')) {
            $candidateUrls[] = 'http://127.0.0.1:8000/api/v1/external/verify-intern';
        } elseif (str_contains($primaryUrl, '127.0.0.1') || str_contains($primaryUrl, 'localhost')) {
            $candidateUrls[] = 'http://absen-djuragan.test/api/v1/external/verify-intern';
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
                \Illuminate\Support\Facades\Log::warning("Koneksi verify-intern ke {$url} gagal: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Helper untuk membuat atau memperbarui akun Pemagang dan data InternshipRegistration dari Absen Djuragan.
     */
    protected function syncOrProvisionRemoteIntern(string $email, array $data, ?string $rawPassword = null): User
    {
        $user = User::where('email', $email)->first();

        $name = !empty($data['full_name'])
            ? $data['full_name']
            : (!empty($data['name']) ? $data['name'] : ($data['username'] ?? 'Pemagang'));

        if (!$user) {
            $user = User::create([
                'name'     => $name,
                'email'    => $email,
                'password' => Hash::make($rawPassword ?: Str::random(32)),
                'role'     => 'pemagang',
            ]);
        } else {
            if ($rawPassword) {
                $user->password = Hash::make($rawPassword);
            }
            if (!in_array($user->role, ['admin', 'hr_staff'], true)) {
                $user->role = 'pemagang';
            }
            if (!empty($name) && $name !== 'Pemagang') {
                $user->name = $name;
            }
            $user->save();
        }

        // Cek apakah sudah ada entri InternshipRegistration di IMS
        $registration = \App\Models\InternshipRegistration::where('user_id', $user->id)
            ->latest('id')
            ->first();

        // Resolusi Division di IMS
        $divisionId = null;
        $divName = $data['division_name'] ?? null;
        if (!empty($divName)) {
            $divisionId = \App\Http\Controllers\Pemagang\RegistrationController::resolveDivisionId($divName);
        }

        // Resolusi Brand di IMS (Prioritaskan brand_name lintas sistem sebelum fallback ke brand_id)
        $brandId = null;
        $brandName = $data['brand_name'] ?? null;
        if (!empty($brandName)) {
            $cleanBrand = strtolower(str_replace([' ', '-', '_'], '', trim($brandName)));
            $brand = \App\Models\Brand::whereRaw('LOWER(name) = ?', [strtolower(trim($brandName))])
                ->orWhereRaw("LOWER(REPLACE(REPLACE(name, ' ', ''), '-', '')) = ?", [$cleanBrand])
                ->first();
            if (!$brand) {
                $brand = \App\Models\Brand::create([
                    'code' => Str::slug($brandName),
                    'name' => trim($brandName),
                ]);
            }
            $brandId = $brand?->id;
        } elseif (!empty($data['brand_id']) && \App\Models\Brand::where('id', $data['brand_id'])->exists()) {
            $brandId = (int) $data['brand_id'];
        }

        // Resolusi Institution / Sekolah di IMS
        $institutionId = null;
        $schoolName = $data['school_name'] ?? null;
        if (!empty($schoolName)) {
            $cleanSchool = trim($schoolName);
            $normName = \App\Models\Institution::normalizeName($cleanSchool);
            $institution = \App\Models\Institution::whereRaw('LOWER(name) = ?', [strtolower($cleanSchool)])
                ->orWhere('normalized_name', $normName)
                ->orWhere('name', 'like', '%' . $cleanSchool . '%')
                ->first();
            if (!$institution) {
                $institution = \App\Models\Institution::create([
                    'name'            => ucwords(strtolower($cleanSchool)),
                    'normalized_name' => $normName,
                    'is_active'       => true,
                ]);
            }
            $institutionId = $institution?->id;
        }
        if (!$institutionId && $registration?->institution_id) {
            $institutionId = $registration->institution_id;
        }
        if (!$institutionId) {
            $institution = \App\Models\Institution::firstOrCreate(
                ['name' => 'Umum'],
                ['is_active' => true]
            );
            $institutionId = $institution->id;
        }

        // Resolusi Faculty di IMS (pertahankan fakultas asli jika sudah ada pada institusi yang sama)
        $facultyId = ($registration && (int) $registration->institution_id === (int) $institutionId && !empty($registration->faculty_id))
            ? $registration->faculty_id
            : null;
        if (!$facultyId) {
            $faculty = \App\Models\Faculty::where('institution_id', $institutionId)->first();
            if (!$faculty) {
                $faculty = \App\Models\Faculty::create([
                    'institution_id' => $institutionId,
                    'name'           => 'Fakultas Umum',
                ]);
            }
            $facultyId = $faculty->id;
        }

        // Resolusi Study Program di IMS (pertahankan prodi asli jika sudah ada pada fakultas yang sama)
        $studyProgramId = ($registration && (int) $registration->faculty_id === (int) $facultyId && !empty($registration->study_program_id))
            ? $registration->study_program_id
            : null;
        if (!$studyProgramId) {
            $studyProgram = \App\Models\StudyProgram::where('faculty_id', $facultyId)->first();
            if (!$studyProgram) {
                $studyProgram = \App\Models\StudyProgram::create([
                    'faculty_id' => $facultyId,
                    'name'       => 'Program Studi Umum',
                ]);
            }
            $studyProgramId = $studyProgram->id;
        }

        // Tentukan status magang (pastikan enum valid & jangan menimpa status akhir jika akun non-aktif di Absen)
        $allowedStatuses = ['new', 'waiting', 'active', 'completed', 'exited', 'pending', 'accepted', 'rejected'];
        $status = $data['status'] ?? null;
        if (!empty($data['is_active'])) {
            $status = ($registration && $registration->internship_status === 'completed') ? 'completed' : 'active';
        } elseif ($registration && in_array($registration->internship_status, ['completed', 'exited', 'rejected', 'accepted', 'waiting', 'pending'], true)) {
            $status = $registration->internship_status;
        } elseif (empty($status) || !in_array($status, $allowedStatuses, true)) {
            $status = 'accepted';
        }

        // Normalisasi format Gender
        $gender = null;
        if (!empty($data['gender'])) {
            $gLower = strtolower(trim((string) $data['gender']));
            if (in_array($gLower, ['p', 'perempuan', 'female', 'wanita'], true)) {
                $gender = 'Perempuan';
            } elseif (in_array($gLower, ['l', 'laki-laki', 'male', 'pria'], true)) {
                $gender = 'Laki-laki';
            } else {
                $gender = trim((string) $data['gender']);
            }
        }

        // Normalisasi format Agama
        $religion = null;
        if (!empty($data['religion'])) {
            $rLower = strtolower(trim((string) $data['religion']));
            if (in_array($rLower, ['kristen', 'kristen protestan', 'protestan'], true)) {
                $religion = 'Kristen Protestan';
            } elseif (in_array($rLower, ['khonghucu', 'konghucu'], true)) {
                $religion = 'Khonghucu';
            } elseif ($rLower === 'islam') {
                $religion = 'Islam';
            } elseif ($rLower === 'katolik') {
                $religion = 'Katolik';
            } elseif ($rLower === 'hindu') {
                $religion = 'Hindu';
            } elseif (in_array($rLower, ['buddha', 'budha'], true)) {
                $religion = 'Buddha';
            } else {
                $religion = trim((string) $data['religion']);
            }
        }

        $regData = [
            'user_id'            => $user->id,
            'fullname'           => $name,
            'gender'             => $gender ?: ($registration?->gender ?? null),
            'phone_number'       => !empty($data['phone']) ? $data['phone'] : ($registration?->phone_number ?? null),
            'supervisor_contact' => !empty($data['parent_whatsapp_number']) ? $data['parent_whatsapp_number'] : ($registration?->supervisor_contact ?? null),
            'student_id'         => !empty($data['student_id']) ? $data['student_id'] : ($registration?->student_id ?? null),
            'birth_place'        => !empty($data['birth_place']) ? $data['birth_place'] : ($registration?->birth_place ?? null),
            'born_date'          => !empty($data['born_date']) ? $data['born_date'] : ($registration?->born_date ?? null),
            'religion'           => $religion ?: ($registration?->religion ?? null),
            'division_id'        => $divisionId ?: ($registration?->division_id ?? null),
            'brand_id'           => $brandId ?: ($registration?->brand_id ?? null),
            'institution_id'     => $institutionId,
            'faculty_id'         => $facultyId,
            'study_program_id'   => $studyProgramId,
            'start_date'         => !empty($data['start_date']) ? $data['start_date'] : ($registration?->start_date ?? null),
            'end_date'           => !empty($data['end_date']) ? $data['end_date'] : ($registration?->end_date ?? null),
            'internship_status'  => $status,
        ];

        if ($registration) {
            $registration->update(array_filter($regData, fn($val) => $val !== null));
        } else {
            \App\Models\InternshipRegistration::create($regData);
        }

        return $user;
    }

    /**
     * Single Sign-On (SSO) login dari Absen Djuragan untuk Admin, Super Admin, dan Pemagang.
     */
    public function ssoLogin(Request $request)
    {
        $token = $request->query('token');
        if (empty($token) || !str_contains($token, '.')) {
            return redirect()->route('user.login')->with('error', 'Token SSO tidak valid.');
        }

        [$payloadBase64, $signature] = explode('.', $token, 2);

        // Dekode Base64 yang toleran terhadap format standard, Base64URL, dan spasi
        $normalizedBase64 = str_replace(' ', '+', $payloadBase64);
        $normalizedBase64 = strtr($normalizedBase64, '-_', '+/');
        $padLen = strlen($normalizedBase64) % 4;
        if ($padLen > 0) {
            $normalizedBase64 .= str_repeat('=', 4 - $padLen);
        }
        $payloadJson = base64_decode($normalizedBase64);

        if (!$payloadJson) {
            return redirect()->route('user.login')->with('error', 'Format token SSO rusak.');
        }

        $secret = config('services.absen_djuragan.api_key')
            ?: config('services.absen_djuragan.inbound_api_key')
            ?: config('services.absen_djuragan.outbound_api_key')
            ?: 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c';

        $expectedSignature = hash_hmac('sha256', $payloadJson, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            // Fallback second-chance jika secret kustom berbeda dengan secret default Absen Djuragan
            $fallbackSecret = 'djuragan_ext_140633f34edfaf504a3d6812c8c2485c';
            $fallbackExpectedSignature = hash_hmac('sha256', $payloadJson, $fallbackSecret);

            if (!hash_equals($fallbackExpectedSignature, $signature)) {
                return redirect()->route('user.login')->with('error', 'Tanda tangan token SSO tidak sah.');
            }
        }

        $payload = json_decode($payloadJson, true);
        if (!$payload || empty($payload['email'])) {
            return redirect()->route('user.login')->with('error', 'Data identitas SSO tidak lengkap.');
        }

        // Periksa waktu kadaluarsa (toleransi clock drift 60 detik)
        if (isset($payload['expires']) && (time() - 60) > (int) $payload['expires']) {
            return redirect()->route('user.login')->with('error', 'Token SSO telah kadaluarsa. Silakan klik kembali tombol di Absen Djuragan.');
        }

        // Validasi role admin / super admin / pemagang
        $roleId = (int) ($payload['role_id'] ?? 0);
        $role = $payload['role'] ?? '';
        $isAdmin = $role === 'admin'
            || !empty($payload['is_super_admin'])
            || in_array($roleId, [1, 7], true);
        $isPemagang = $role === 'pemagang' || $roleId === 3;

        if (!$isAdmin && !$isPemagang) {
            return redirect()->route('user.login')->with('error', 'Akses SSO ditolak: Peran akun tidak diizinkan.');
        }

        if ($isAdmin) {
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

        // Role Pemagang
        $internData = $payload['intern_data'] ?? $payload;
        $user = $this->syncOrProvisionRemoteIntern($payload['email'], $internData, null);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('pemagang.dashboard')->with('success', 'Berhasil masuk ke InternHub via Absen Djuragan.');
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

    /**
     * Menampilkan formulir permintaan link reset password
     */
    public function showForgotPasswordForm()
    {
        return view('auth.forgot-password');
    }

    /**
     * Mengirim tautan pemulihan kata sandi ke email pengguna
     */
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email'    => 'Format email tidak valid.',
        ]);

        Password::broker()->sendResetLink(
            $request->only('email')
        );

        // Keamanan anti-enumerasi: Email terdaftar dan tidak terdaftar menerima respons user-facing yang identik
        return back()->with('status', 'Jika alamat email terdaftar pada sistem kami, tautan pemulihan password telah dikirimkan.');
    }

    /**
     * Menampilkan formulir atur ulang password dengan token
     */
    public function showResetPasswordForm(Request $request, $token = null)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->email ?? old('email'),
        ]);
    }

    /**
     * Menyimpan password baru pengguna setelah reset divalidasi oleh broker
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'token.required'     => 'Token reset password tidak valid atau tidak ditemukan.',
            'email.required'     => 'Email wajib diisi.',
            'email.email'        => 'Format email tidak valid.',
            'password.required'  => 'Password baru wajib diisi.',
            'password.min'       => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('user.login')
                ->with('status', 'Password Anda berhasil diperbarui! Silakan masuk menggunakan password baru.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }

    /**
     * Menampilkan formulir aktivasi akun dan setel password pertama kali
     */
    public function showActivationForm(Request $request, $token = null)
    {
        if (!Schema::hasTable('user_account_activations')) {
            return redirect()->route('user.login')
                ->with('error', 'Layanan aktivasi akun belum tersedia.');
        }

        return view('auth.activate-account', [
            'token' => $token,
            'email' => $request->email ?? old('email'),
        ]);
    }

    /**
     * Menyimpan password aktivasi akun dan menandai aktivasi selesai secara atomik
     */
    public function activateAccount(Request $request)
    {
        if (!Schema::hasTable('user_account_activations')) {
            return redirect()->route('user.login')
                ->with('error', 'Layanan aktivasi akun belum tersedia.');
        }

        $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'token.required'     => 'Token aktivasi tidak valid atau tidak ditemukan.',
            'email.required'     => 'Email wajib diisi.',
            'email.email'        => 'Format email tidak valid.',
            'password.required'  => 'Password baru wajib diisi.',
            'password.min'       => 'Password baru minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Tandai record aktivasi sebagai aktif secara persisten
                if ($user->accountActivation) {
                    $user->accountActivation->markAsActivated();
                }
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('user.login')
                ->with('status', 'Akun Anda berhasil diaktivasi! Silakan login menggunakan password baru Anda.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'Token aktivasi tidak valid, telah kedaluwarsa, atau sudah pernah digunakan.']);
    }

    /**
     * Mengirim ulang tautan aktivasi akun dengan rate-limiting
     */
    public function resendActivation(Request $request)
    {
        if (!Schema::hasTable('user_account_activations')) {
            return back()->with('error', 'Layanan aktivasi akun belum tersedia.');
        }

        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email'    => 'Format email tidak valid.',
        ]);

        $user = User::where('email', strtolower(trim($request->email)))->first();

        // Hanya layani akun yang benar-benar ada, role pemagang/user, dan status aktivasi pending
        if ($user && $user->isActivationPending()) {
            $activation = $user->accountActivation;
            if ($activation && !$activation->canResendNotification(60)) {
                return back()->with('error', 'Mohon tunggu beberapa saat sebelum meminta pengiriman ulang email aktivasi.');
            }

            $token = Password::broker()->createToken($user);
            $user->notify(new \App\Notifications\AccountActivationNotification($token));
            $activation?->recordNotificationSent();
        }

        // Anti-enumerasi: berikan respons seragam
        return back()->with('status', 'Jika akun Anda terdaftar dan belum diaktivasi, tautan aktivasi baru telah dikirimkan ke email Anda.');
    }
}
