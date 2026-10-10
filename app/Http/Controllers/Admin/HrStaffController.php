<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class HrStaffController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:admin']);
    }

    /**
     * Menampilkan daftar HR Staff dengan filter status dan pencarian.
     */
    public function index(Request $request)
    {
        $status = $request->get('status', 'active');
        $search = $request->get('search');

        $query = User::query()->where('role', User::ROLE_HR_STAFF);

        if ($status === 'trashed') {
            $query->onlyTrashed();
        } elseif ($status === 'banned') {
            $query->where('is_banned', 1);
        } elseif ($status === 'active') {
            $query->where('is_banned', 0);
        }
        // status === 'all' mencakup aktif & banned (tanpa trashed)

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $hrStaffMembers = $query->orderBy('name')->paginate(15)->withQueryString();

        // Hitung total untuk tab navigasi
        $countActive  = User::where('role', User::ROLE_HR_STAFF)->where('is_banned', 0)->count();
        $countBanned  = User::where('role', User::ROLE_HR_STAFF)->where('is_banned', 1)->count();
        $countTrashed = User::onlyTrashed()->where('role', User::ROLE_HR_STAFF)->count();

        return view('admin.hr-staff.index', compact(
            'hrStaffMembers',
            'status',
            'search',
            'countActive',
            'countBanned',
            'countTrashed'
        ));
    }

    /**
     * Form tambah HR Staff baru.
     */
    public function create()
    {
        return view('admin.hr-staff.create');
    }

    /**
     * Simpan akun HR Staff baru.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:150', 'unique:users,name'],
            'email'    => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required'     => 'Nama/Username wajib diisi.',
            'name.unique'       => 'Username sudah digunakan, pilih username lain.',
            'email.required'    => 'Email wajib diisi.',
            'email.email'       => 'Format email tidak valid.',
            'email.unique'      => 'Email sudah terdaftar.',
            'password.required' => 'Password wajib diisi.',
            'password.min'      => 'Password minimal 8 karakter.',
            'password.confirmed'=> 'Konfirmasi password tidak cocok.',
        ]);

        $user = User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'role'      => User::ROLE_HR_STAFF,
            'is_banned' => 0,
        ]);

        ActivityLogger::log(
            action: 'hr_staff_created',
            description: "Admin membuat akun HR Staff baru: {$user->name} ({$user->email})",
            subject: $user,
            after: ['name' => $user->name, 'email' => $user->email, 'role' => $user->role]
        );

        return redirect()->route('admin.hr-staff.index')
            ->with('success', 'Akun HR Staff berhasil dibuat.');
    }

    /**
     * Form ubah data HR Staff.
     */
    public function edit(User $hr_staff)
    {
        if ($hr_staff->role !== User::ROLE_HR_STAFF) {
            abort(404);
        }

        return view('admin.hr-staff.edit', ['hrStaff' => $hr_staff]);
    }

    /**
     * Simpan perubahan data HR Staff.
     */
    public function update(Request $request, User $hr_staff)
    {
        if ($hr_staff->role !== User::ROLE_HR_STAFF) {
            abort(404);
        }

        $request->validate([
            'name'     => ['required', 'string', 'max:150', Rule::unique('users', 'name')->ignore($hr_staff->id)],
            'email'    => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($hr_staff->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required'     => 'Nama/Username wajib diisi.',
            'name.unique'       => 'Username sudah digunakan.',
            'email.required'    => 'Email wajib diisi.',
            'email.email'       => 'Format email tidak valid.',
            'email.unique'      => 'Email sudah terdaftar.',
            'password.min'      => 'Password minimal 8 karakter.',
            'password.confirmed'=> 'Konfirmasi password tidak cocok.',
        ]);

        $before = [
            'name'  => $hr_staff->name,
            'email' => $hr_staff->email,
        ];

        $hr_staff->name  = $request->name;
        $hr_staff->email = $request->email;

        if ($request->filled('password')) {
            $hr_staff->password = Hash::make($request->password);
        }

        $hr_staff->save();

        ActivityLogger::log(
            action: 'hr_staff_updated',
            description: "Admin memperbarui data HR Staff: {$hr_staff->name}",
            subject: $hr_staff,
            before: $before,
            after: ['name' => $hr_staff->name, 'email' => $hr_staff->email]
        );

        return redirect()->route('admin.hr-staff.index')
            ->with('success', 'Data HR Staff berhasil diperbarui.');
    }

    /**
     * Toggle status aktif / nonaktif (is_banned).
     */
    public function toggleStatus(User $user)
    {
        if ($user->role !== User::ROLE_HR_STAFF) {
            abort(404);
        }

        if ($user->id === auth()->id()) {
            return back()->with('error', 'Anda tidak dapat mengubah status akun Anda sendiri.');
        }

        $oldStatus = $user->is_banned;
        $user->is_banned  = !$oldStatus;
        $user->save();

        $actionText = $user->is_banned ? 'dinonaktifkan' : 'diaktifkan kembali';

        ActivityLogger::log(
            action: 'hr_staff_status_toggled',
            description: "Admin mengubah status HR Staff {$user->name} menjadi {$actionText}",
            subject: $user,
            before: ['is_banned' => $oldStatus],
            after: ['is_banned' => $user->is_banned]
        );

        return back()->with('success', "Akun HR Staff {$user->name} berhasil {$actionText}.");
    }

    /**
     * Soft delete akun HR Staff dengan re-autentikasi password admin.
     */
    public function destroy(Request $request, User $hr_staff)
    {
        if ($hr_staff->role !== User::ROLE_HR_STAFF) {
            abort(404);
        }

        if ($hr_staff->id === auth()->id()) {
            return back()->with('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        }

        $request->validate([
            'admin_password' => ['required', 'string'],
        ], [
            'admin_password.required' => 'Konfirmasi kata sandi admin wajib diisi.',
        ]);

        if (!Hash::check($request->admin_password, auth()->user()->password)) {
            return back()->with('error', 'Konfirmasi kata sandi admin salah. Aksi penghapusan dibatalkan.');
        }

        $hrName = $hr_staff->name;
        $hr_staff->delete(); // Soft delete

        ActivityLogger::log(
            action: 'hr_staff_deleted',
            description: "Admin melakukan soft delete akun HR Staff: {$hrName}",
            subject: $hr_staff,
            metadata: ['auth_verified' => true]
        );

        return redirect()->route('admin.hr-staff.index')
            ->with('success', "Akun HR Staff {$hrName} berhasil dipindahkan ke arsip (soft delete).");
    }

    /**
     * Pulihkan akun HR Staff yang telah di-soft-delete dengan re-autentikasi password admin.
     */
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

        $hrStaff = User::onlyTrashed()->where('id', $id)->where('role', User::ROLE_HR_STAFF)->firstOrFail();
        $hrStaff->restore();

        ActivityLogger::log(
            action: 'hr_staff_restored',
            description: "Admin memulihkan akun HR Staff: {$hrStaff->name}",
            subject: $hrStaff,
            metadata: ['auth_verified' => true]
        );

        return redirect()->route('admin.hr-staff.index')
            ->with('success', "Akun HR Staff {$hrStaff->name} berhasil dipulihkan.");
    }
}
