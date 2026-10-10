<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\InternshipRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // Menampilkan semua pengguna
    public function index(Request $request)
    {
        $latestRegSub = InternshipRegistration::select('user_id', \Illuminate\Support\Facades\DB::raw('MAX(id) as latest_reg_id'))
            ->groupBy('user_id');

        $query = User::query()
            ->select(
                'users.*',
                \Illuminate\Support\Facades\DB::raw('COALESCE(latest_reg.fullname, users.name) as display_name'),
                'latest_reg.fullname as latest_registration_fullname'
            )
            ->leftJoinSub($latestRegSub, 'latest_reg_map', function ($join) {
                $join->on('users.id', '=', 'latest_reg_map.user_id');
            })
            ->leftJoin('internship_registrations as latest_reg', 'latest_reg_map.latest_reg_id', '=', 'latest_reg.id');

        // filter per kolom: mendukung pencarian username (users.name), nama registrasi terbaru,
        // maupun riwayat registrasi terdahulu tanpa menduplikasi baris hasil pagination.
        if ($name = $request->get('name')) {
            $query->where(function ($q) use ($name) {
                $q->where('users.name', 'like', "%$name%")
                  ->orWhere('latest_reg.fullname', 'like', "%$name%")
                  ->orWhereExists(function ($sub) use ($name) {
                      $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                          ->from('internship_registrations')
                          ->whereColumn('internship_registrations.user_id', 'users.id')
                          ->where('internship_registrations.fullname', 'like', "%$name%");
                  });
            });
        }

        if ($email = $request->get('email')) {
            $query->where('users.email', 'like', "%$email%");
        }

        if ($role = $request->get('role')) {
            $query->where('users.role', $role);
        }

        // Filter berdasarkan status ban / trashed
        if ($status = $request->get('status')) {
            if ($status === 'banned') {
                $query->where('users.is_banned', true);
            } elseif ($status === 'active') {
                $query->where('users.is_banned', false);
            } elseif ($status === 'trashed') {
                $query->onlyTrashed();
            }
        }

        // sorting
        switch ($request->get('sort')) {
            case 'name_asc':   $query->orderBy('latest_reg.fullname', 'asc')->orderBy('users.name', 'asc'); break;
            case 'name_desc':  $query->orderBy('latest_reg.fullname', 'desc')->orderBy('users.name', 'desc'); break;
            case 'email_asc':  $query->orderBy('users.email', 'asc'); break;
            case 'email_desc': $query->orderBy('users.email', 'desc'); break;
            case 'role_asc':   $query->orderBy('users.role', 'asc'); break;
            case 'role_desc':  $query->orderBy('users.role', 'desc'); break;
            case 'status_asc': $query->orderBy('users.is_online', 'asc'); break;
            case 'status_desc':$query->orderBy('users.is_online', 'desc'); break;
            default:
                $query->orderBy('users.is_online', 'desc')
                      ->orderBy('latest_reg.fullname', 'asc')
                      ->orderBy('users.name', 'asc');
        }

        $users = $query->paginate(10)->appends($request->query());

        return view('admin.users.index', compact('users'));
    }

    // Menampilkan form tambah pengguna
    public function create()
    {
        return view('admin.users.create');
    }

    // Menyimpan pengguna baru
    public function store(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            return redirect()->route('admin.dashboard.index')->with('error', 'Unauthorized access');
        }

        $username = $request->input('name') ?? $request->input('username');
        $request->merge([
            'name' => $username,
            'role' => 'admin',
        ]);

        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:150', 'unique:users,name'],
            'email'                 => ['required', 'email', 'max:150', 'unique:users,email'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'role'                  => ['nullable', 'string', 'in:admin'],
        ], [
            'name.required'         => 'Username wajib diisi.',
            'name.unique'           => 'Username sudah digunakan, silakan pilih username lain.',
            'name.max'              => 'Username maksimal 150 karakter.',
            'email.required'        => 'Email wajib diisi.',
            'email.email'           => 'Format email tidak valid.',
            'email.unique'          => 'Email sudah terdaftar.',
            'password.required'     => 'Password wajib diisi.',
            'password.min'          => 'Password minimal 8 karakter.',
            'password.confirmed'    => 'Konfirmasi password tidak cocok.',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role'     => 'admin',
        ]);

        \App\Services\ActivityLogger::log(
            action: 'user_created',
            description: "Admin menambahkan akun baru: {$user->name} ({$user->email})",
            subject: $user,
            after: ['name' => $user->name, 'email' => $user->email, 'role' => $user->role]
        );

        return redirect()->route('admin.users.index')
            ->with('success', "Admin baru {$user->name} berhasil ditambahkan.");
    }

    // Menampilkan detail pengguna
    public function show($id)
    {
        $user = User::findOrFail($id);
        $internship = $user->internshipRegistration;
        return view('admin.users.show', compact('user', 'internship'));
    }

    // Mengedit data pengguna
    public function edit($id)
    {
        $user = User::findOrFail($id);

        if ($user->role === User::ROLE_HR_STAFF) {
            return redirect()->route('admin.hr-staff.edit', $user->id)
                ->with('info', 'Akun HR Staff dikelola secara terpusat melalui modul Manajemen HR Staff.');
        }

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->role === User::ROLE_HR_STAFF) {
            return redirect()->route('admin.hr-staff.index')
                ->with('error', 'Pengubahan akun HR Staff harus dilakukan melalui modul Manajemen HR Staff.');
        }

        if (auth()->user()->role !== 'admin') {
            return redirect()->route('admin.dashboard.index')->with('error', 'Unauthorized access');
        }

        $username = $request->input('name') ?? $request->input('username');
        if ($username !== null) {
            $request->merge(['name' => $username]);
        }

        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:150', 'unique:users,name,' . $user->id],
            'role'         => ['required', 'string', 'in:admin,user,pemagang'],
            'fullname'     => ['nullable', 'string', 'max:150'],
            'phone_number' => ['nullable', 'string', 'max:20'],
        ], [
            'name.required'         => 'Nama user wajib diisi.',
            'name.unique'           => 'Nama user sudah digunakan, silakan pilih nama user lain.',
            'name.max'              => 'Nama user maksimal 150 karakter.',
            'role.required'         => 'Role wajib dipilih.',
            'role.in'               => 'Role tidak valid.',
            'phone_number.max'      => 'Nomor HP maksimal 20 digit.',
        ]);

        $before = [
            'name' => $user->name,
            'role' => $user->role,
        ];

        $user->name = $validated['name'];
        $user->role = $validated['role'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'phone_number') && isset($validated['phone_number'])) {
            $user->phone_number = $validated['phone_number'];
        }
        $user->save();

        // Jika user punya data registrasi, update nama lengkap & no hp
        $registration = $user->internshipRegistration ?? $user->registration;
        if ($registration) {
            if (array_key_exists('fullname', $validated) && $validated['fullname'] !== null) {
                $registration->fullname = $validated['fullname'];
            }
            if (array_key_exists('phone_number', $validated) && $validated['phone_number'] !== null) {
                $registration->phone_number = $validated['phone_number'];
            }
            $registration->save();
        }

        \App\Services\ActivityLogger::log(
            action: 'user_updated',
            description: "Admin memperbarui data pengguna: {$user->name}",
            subject: $user,
            before: $before,
            after: ['name' => $user->name, 'role' => $user->role]
        );

        return redirect()->route('admin.users.index')
            ->with('success', 'Data pengguna berhasil diperbarui.');
    }

    // ─── Edit Profil Sendiri (admin yang sedang login) ───────────────────────

    public function editProfile()
    {
        $user = auth()->user();
        return view('admin.profile.edit', compact('user'));
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $username = $request->input('username') ?? $request->input('fullname');
        $request->merge(['username' => $username]);

        $validated = $request->validate([
            'username'              => 'required|string|max:150|unique:users,name,' . $user->id,
            'email'                 => 'required|email|max:150|unique:users,email,' . $user->id,
            'current_password'      => 'nullable|string',
            'password'              => 'nullable|string|min:8|confirmed',
        ], [
            'username.required'     => 'Username wajib diisi.',
            'username.unique'       => 'Username sudah digunakan, silakan pilih username lain.',
            'username.max'          => 'Username maksimal 150 karakter.',
            'email.required'        => 'Email wajib diisi.',
            'email.email'           => 'Format email tidak valid.',
            'email.unique'          => 'Email sudah terdaftar.',
            'password.min'          => 'Password minimal 8 karakter.',
            'password.confirmed'    => 'Konfirmasi password tidak cocok.',
        ]);

        // Validasi password lama jika ingin ganti password
        if (!empty($validated['password'])) {
            if (empty($validated['current_password'])) {
                return back()
                    ->withErrors(['current_password' => 'Password saat ini wajib diisi untuk mengganti password.'])
                    ->withInput();
            }

            if (!Hash::check($validated['current_password'], $user->password)) {
                return back()
                    ->withErrors(['current_password' => 'Password saat ini tidak sesuai.'])
                    ->withInput();
            }

            $user->password = Hash::make($validated['password']);
        }

        $user->name = $validated['username'];
        $user->email = $validated['email'];

        $user->save();

        return back()->with('success', 'Profil berhasil diperbarui.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    // Menghapus data pengguna (Soft delete dengan re-autentikasi password admin)
    public function destroy(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return redirect()->route('admin.users.index')->with('error', 'User not found.');
        }

        if ($user->id === auth()->id()) {
            return back()->with('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        }

        if ($user->role === User::ROLE_HR_STAFF) {
            return redirect()->route('admin.hr-staff.index')
                ->with('error', 'Penghapusan akun HR Staff harus dilakukan melalui modul Manajemen HR Staff.');
        }

        $request->validate([
            'admin_password' => ['required', 'string'],
        ], [
            'admin_password.required' => 'Konfirmasi kata sandi admin wajib diisi.',
        ]);

        if (!Hash::check($request->admin_password, auth()->user()->password)) {
            return back()->with('error', 'Konfirmasi kata sandi admin salah. Aksi penghapusan dibatalkan.');
        }

        $userName = $user->name;
        $user->delete(); // Soft delete

        \App\Services\ActivityLogger::log(
            action: 'user_deleted',
            description: "Admin melakukan soft delete akun pengguna: {$userName}",
            subject: $user,
            metadata: ['auth_verified' => true]
        );

        return redirect()->route('admin.users.index')->with('success', "Akun {$userName} berhasil dihapus (soft delete).");
    }

    // Pulihkan pengguna yang telah di-soft-delete (dengan re-autentikasi password admin)
    public function restore(Request $request, $id)
    {
        $request->validate([
            'admin_password' => ['required', 'string'],
        ], [
            'admin_password.required' => 'Konfirmasi kata sandi admin wajib diisi.',
        ]);

        if (!Hash::check($request->admin_password, auth()->user()->password)) {
            return back()->with('error', 'Konfirmasi kata sandi admin salah. Aksi pemulihan dibatalkan.');
        }

        $user = User::onlyTrashed()->findOrFail($id);

        if ($user->role === User::ROLE_HR_STAFF) {
            return redirect()->route('admin.hr-staff.index')
                ->with('error', 'Pemulihan akun HR Staff harus dilakukan melalui modul Manajemen HR Staff.');
        }

        $userName = $user->name;
        $user->restore();

        \App\Services\ActivityLogger::log(
            action: 'user_restored',
            description: "Admin memulihkan akun pengguna: {$userName}",
            subject: $user,
            metadata: ['auth_verified' => true]
        );

        return redirect()->route('admin.users.index')->with('success', "Akun {$userName} berhasil dipulihkan dari arsip.");
    }

    // Ban / nonaktifkan akun
    public function ban(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        if ($user->role === 'admin') {
            return redirect()->route('admin.users.index')
                ->with('error', 'Tidak dapat menonaktifkan akun admin.');
        }

        if ($user->role === User::ROLE_HR_STAFF) {
            return redirect()->route('admin.hr-staff.index')
                ->with('error', 'Pengubahan status akun HR Staff harus dilakukan melalui modul Manajemen HR Staff.');
        }

        $validated = $request->validate([
            'ban_reason' => 'nullable|string|max:255',
        ]);

        $banReason = $validated['ban_reason'] ?? 'Dinonaktifkan oleh admin.';

        $user->update([
            'is_banned'  => true,
            'banned_at'  => now(),
            'ban_reason' => $banReason,
        ]);

        if ($user->is_online) {
            $user->update(['is_online' => false]);
        }

        \App\Services\ActivityLogger::log(
            action: 'user_banned',
            description: "Admin menonaktifkan akun pengguna: {$user->name} (Alasan: {$banReason})",
            subject: $user,
            after: [
                'is_banned'  => true,
                'ban_reason' => $banReason,
            ]
        );

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'message' => "Akun {$user->name} berhasil dinonaktifkan."]);
        }

        return redirect()->route('admin.users.index')
            ->with('success', "Akun {$user->name} berhasil dinonaktifkan.");
    }

    // Unban / aktifkan kembali akun
    public function unban($id)
    {
        $user = User::findOrFail($id);

        if ($user->role === User::ROLE_HR_STAFF) {
            return redirect()->route('admin.hr-staff.index')
                ->with('error', 'Pengubahan status akun HR Staff harus dilakukan melalui modul Manajemen HR Staff.');
        }

        $user->update([
            'is_banned'  => false,
            'banned_at'  => null,
            'ban_reason' => null,
        ]);

        \App\Services\ActivityLogger::log(
            action: 'user_unbanned',
            description: "Admin mengaktifkan kembali akun pengguna: {$user->name}",
            subject: $user,
            after: [
                'is_banned'  => false,
            ]
        );

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json(['ok' => true, 'message' => "Akun {$user->name} berhasil diaktifkan kembali."]);
        }

        return redirect()->route('admin.users.index')
            ->with('success', "Akun {$user->name} berhasil diaktifkan kembali.");
    }
}
