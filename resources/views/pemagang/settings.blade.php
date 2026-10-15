@extends('pemagang.layouts.app')

@section('title', 'Pengaturan Akun')
@section('breadcrumb', 'Pengaturan')

@section('content')

<div class="max-w-2xl mx-auto">
  <div class="mb-6">
    <h2 class="text-xl font-bold text-gray-800">Pengaturan Akun</h2>
    <p class="text-sm text-gray-500">Kelola informasi akun dan keamanan login Anda</p>
  </div>

  @if(session('success'))
    <div class="mb-5 flex items-center gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
      <i class="fas fa-check-circle text-green-600"></i>
      <span>{{ session('success') }}</span>
    </div>
  @endif

  @if($errors->any())
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
      <ul class="list-inside list-disc space-y-1">
        @foreach($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  {{-- Pemberitahuan Pemisahan Data Pendaftaran vs Akun --}}
  @if($user->internshipRegistration)
    <div class="mb-5 flex items-start gap-3 rounded-xl border border-blue-100 bg-blue-50/80 p-4 text-xs text-blue-800">
      <i class="fas fa-info-circle text-blue-500 text-sm mt-0.5 shrink-0"></i>
      <div>
        <p class="font-semibold text-blue-900 mb-0.5">Informasi Biodata Pemagang</p>
        <p class="text-blue-700 leading-relaxed">
          Halaman ini khusus untuk pengaturan kredensial akun pengguna (tabel users). Data biodata seperti nama lengkap, nomor HP, asal instansi, dan foto profil dikelola melalui form pendaftaran pemagang.
        </p>
      </div>
    </div>
  @endif

  <form action="{{ route('pemagang.settings.update') }}" method="POST">
    @csrf
    @method('PUT')

    {{-- ===== INFORMASI AKUN (TABEL USERS) ===== --}}
    <div class="bg-white rounded-xl border border-gray-100 p-6 mb-5 shadow-sm">
      <div class="flex items-center gap-4 mb-6 pb-5 border-b border-gray-100">
        <div class="w-14 h-14 rounded-full flex items-center justify-center text-white text-lg font-bold flex-shrink-0"
             style="background-color:#1a5c38;">
          {{ strtoupper(substr($user->attributes['name'] ?? $user->name ?? 'P', 0, 2)) }}
        </div>
        <div>
          <h3 class="font-semibold text-gray-800 text-base">{{ $user->attributes['name'] ?? $user->name ?? 'Pemagang' }}</h3>
          <p class="text-xs text-gray-500">{{ $user->email }}</p>
          <span class="inline-block mt-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold tracking-wide bg-green-50 text-green-700 border border-green-200 uppercase">
            {{ $user->role }}
          </span>
        </div>
      </div>

      <p class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-4">Kredensial Akun</p>

      <div class="space-y-4">
        {{-- Username --}}
        <div>
          <label for="username" class="block mb-1.5 text-sm font-medium text-gray-700">
            Username <span class="text-red-500">*</span>
          </label>
          <input type="text" id="username" name="username" required
            value="{{ old('username', $user->attributes['name'] ?? $user->name) }}"
            placeholder="Masukkan username Anda"
            class="block w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500">
          @error('username')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
          <p class="mt-1 text-xs text-gray-400">Username digunakan untuk login ke sistem.</p>
        </div>

        {{-- Email --}}
        <div>
          <label for="email" class="block mb-1.5 text-sm font-medium text-gray-700">
            Email <span class="text-red-500">*</span>
          </label>
          <input type="email" id="email" name="email" required
            value="{{ old('email', $user->email) }}"
            placeholder="email@contoh.com"
            class="block w-full rounded-lg border border-gray-200 px-3 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500">
          @error('email')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
        </div>
      </div>
    </div>

    {{-- ===== GANTI PASSWORD ===== --}}
    <div class="bg-white rounded-xl border border-gray-100 p-6 mb-6 shadow-sm">
      <p class="text-xs font-bold uppercase tracking-wider text-gray-500 mb-1">Keamanan Password</p>
      <p class="text-xs text-gray-400 mb-4">Kosongkan jika tidak ingin mengganti password</p>

      <div class="space-y-4">
        <div>
          <label for="current_password" class="block mb-1.5 text-sm font-medium text-gray-700">Password Saat Ini</label>
          <div class="relative">
            <input type="password" id="current_password" name="current_password"
              placeholder="Masukkan password saat ini"
              class="block w-full rounded-lg border border-gray-200 px-3 pr-10 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500">
            <button type="button" onclick="togglePasswordVisibility('current_password', this)" aria-label="Lihat password"
              class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none transition">
              <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
          @error('current_password')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label for="password" class="block mb-1.5 text-sm font-medium text-gray-700">Password Baru</label>
            <div class="relative">
              <input type="password" id="password" name="password"
                placeholder="Min. 8 karakter"
                class="block w-full rounded-lg border border-gray-200 px-3 pr-10 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500">
              <button type="button" onclick="togglePasswordVisibility('password', this)" aria-label="Lihat password"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none transition">
                <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
            @error('password')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
          </div>
          <div>
            <label for="password_confirmation" class="block mb-1.5 text-sm font-medium text-gray-700">Konfirmasi Password Baru</label>
            <div class="relative">
              <input type="password" id="password_confirmation" name="password_confirmation"
                placeholder="Ulangi password baru"
                class="block w-full rounded-lg border border-gray-200 px-3 pr-10 py-2.5 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500">
              <button type="button" onclick="togglePasswordVisibility('password_confirmation', this)" aria-label="Lihat konfirmasi password"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 focus:outline-none transition">
                <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="flex justify-end gap-3">
      <a href="{{ route('pemagang.dashboard') }}"
         class="px-5 py-2.5 text-sm font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
        Batal
      </a>
      <button type="submit"
        class="px-6 py-2.5 text-sm font-semibold text-white rounded-lg transition hover:opacity-90"
        style="background-color:#1a5c38;">
        Simpan Perubahan
      </button>
    </div>
  </form>
</div>

@push('scripts')
<script>
function togglePasswordVisibility(fieldId, btn) {
  const input = document.getElementById(fieldId);
  if (!input) return;
  const isHidden = input.type === 'password';
  input.type = isHidden ? 'text' : 'password';
  btn.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Lihat password');
  const icon = btn.querySelector('.eye-icon');
  if (icon) {
    if (isHidden) {
      icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
      icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>';
    }
  }
}
</script>
@endpush
@endsection
