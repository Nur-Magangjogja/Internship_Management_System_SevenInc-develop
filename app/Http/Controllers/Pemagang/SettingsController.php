<?php

namespace App\Http\Controllers\Pemagang;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        return view('pemagang.settings', ['user' => auth()->user()]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();

        $username = $request->input('username') ?? $request->input('name');
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

        // Validasi password saat ini jika mengisi password baru
        if (!empty($validated['password'])) {
            if (!empty($validated['current_password']) && !Hash::check($validated['current_password'], $user->password)) {
                return back()
                    ->withErrors(['current_password' => 'Password saat ini tidak sesuai.'])
                    ->withInput();
            }

            $user->password = Hash::make($validated['password']);
        }

        // Halaman ini hanya menangani data tabel users
        $user->name = $validated['username'];
        $user->email = $validated['email'];
        $user->save();

        $activeReg = \App\Models\InternshipRegistration::where('user_id', $user->id)
            ->whereIn('internship_status', ['accepted', 'active'])
            ->latest('id')
            ->first();
        if ($activeReg) {
            $activeReg->setRelation('user', $user);
            \App\Services\AbsenDjuraganSyncService::syncIntern($activeReg, null, $activeReg->internship_status);
        }

        return back()->with('success', 'Pengaturan akun berhasil disimpan.');
    }
}

