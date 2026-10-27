@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    {{-- Header --}}
    <div class="mb-6">
        <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Akun</p>
        <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34] sm:text-[28px]">Edit Profil</h1>
        <p class="mt-1 text-sm text-[#4B5F5A]">Perbarui informasi akun dan foto profil kamu.</p>
    </div>

    @if(session('success'))
    <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-[#A5D6A7] bg-[#E8F5E9] px-4 py-3 text-sm font-semibold text-[#1F5F3F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
        {{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="mb-4 flex items-center gap-3 rounded-[10px] border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-[#D32F2F]">
        <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        {{ session('error') }}
    </div>
    @endif

    @if($errors->any())
    <div class="mb-4 rounded-[10px] border border-red-200 bg-red-50 px-4 py-3">
        <ul class="space-y-1 text-[13px] text-[#D32F2F]">
            @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
        </ul>
    </div>
    @endif

    <div class="max-w-2xl">

        <form action="{{ route('user.profile.update') }}" method="POST" enctype="multipart/form-data">
        @csrf @method('PUT')
        <div class="space-y-5">

            {{-- Card: Info Profil --}}
            <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-5 shadow-sm">
                <p class="mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Foto & Identitas</p>

                {{-- Avatar + upload --}}
                <div class="flex items-center gap-5 mb-5">
                    <div class="relative">
                        @php
                            $pic = auth()->user()->profile_picture;
                            $hasImg = $pic && Storage::disk('public')->exists($pic);
                            $initials = collect(explode(' ', auth()->user()->name))->take(2)->map(fn($w)=>strtoupper($w[0]??''))->implode('');
                        @endphp
                        @if($hasImg)
                        <img id="avatarPreview"
                            src="{{ asset('storage/'.auth()->user()->profile_picture) }}"
                            alt="Foto Profil"
                            class="h-20 w-20 rounded-full object-cover border-4 border-[#E8F5E9]">
                        @else
                        <div id="avatarFallback" class="flex h-20 w-20 items-center justify-center rounded-full bg-[#E8F5E9] text-xl font-bold text-[#1F5F3F] border-4 border-[#E8F5E9]">
                            {{ $initials }}
                        </div>
                        <img id="avatarPreview" class="hidden h-20 w-20 rounded-full object-cover border-4 border-[#E8F5E9]" alt="Foto Profil">
                        @endif
                    </div>
                    <div class="flex-1">
                        <label class="mb-1 block text-[12.5px] font-semibold text-[#1B3A34]">Ganti Foto Profil</label>
                        <input type="file" name="profile_picture" accept="image/*"
                            onchange="previewAvatar(event)"
                            class="block w-full text-[12.5px] text-[#4B5F5A]">
                        <p class="mt-1 text-[11px] text-[#4B5F5A]">Format JPG/PNG, maks. 10 MB.</p>
                    </div>
                </div>

                {{-- Badge role & status --}}
                <div class="flex flex-wrap gap-2">
                    @php
                        $role = auth()->user()->role ?? 'user';
                        $roleCls = match($role) {
                            'admin'    => 'bg-purple-50 text-purple-700 border border-purple-200',
                            'pemagang' => 'bg-[#E8F5E9] text-[#1F5F3F] border border-[#A5D6A7]',
                            default    => 'bg-blue-50 text-blue-700 border border-blue-200',
                        };
                        $internStatus = optional(auth()->user()->internshipRegistration)->internship_status ?? 'Belum terdaftar';
                        $statusCls = match($internStatus) {
                            'active'    => 'bg-blue-50 text-blue-700 border border-blue-200',
                            'completed' => 'bg-slate-100 text-slate-700 border border-slate-300',
                            'accepted'  => 'bg-[#E8F5E9] text-[#1F5F3F] border border-[#A5D6A7]',
                            default     => 'bg-[#F4F8F6] text-[#4B5F5A] border border-[#DCE7E1]',
                        };
                    @endphp
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $roleCls }}">
                        Role: {{ ucfirst($role) }}
                    </span>
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $statusCls }}">
                        Status: {{ ucfirst($internStatus) }}
                    </span>
                </div>
            </div>

            {{-- Card: Data Diri --}}
            <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-5 shadow-sm">
                <p class="mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Data Diri</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Nama Lengkap</label>
                        <input type="text" name="name" value="{{ old('name', auth()->user()->name) }}"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Email</label>
                        <input type="email" name="email" value="{{ old('email', auth()->user()->email) }}"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    </div>

                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Nomor Telepon</label>
                        <input type="text" name="phone_number" value="{{ old('phone_number', auth()->user()->phone_number) }}"
                            placeholder="Contoh: 0812 3456 7890"
                            class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                    </div>
                </div>
            </div>

            {{-- Card: Ubah Password --}}
            <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-5 shadow-sm">
                <p class="mb-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Ubah Password</p>
                <p class="mb-4 text-[12px] text-[#4B5F5A]">Kosongkan jika tidak ingin mengganti password.</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Password Baru</label>
                        <div class="relative">
                            <input type="password" id="user_password" name="password"
                                autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 pr-10 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                            <button type="button" class="toggle-user-password-btn absolute right-3 top-1/2 -translate-y-1/2 text-[#4B5F5A] transition hover:text-[#1B3A34] focus:outline-none"
                                onclick="toggleUserProfilePasswords()" aria-label="Lihat password">
                                <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[12.5px] font-semibold text-[#1B3A34]">Konfirmasi Password</label>
                        <div class="relative">
                            <input type="password" id="user_password_confirmation" name="password_confirmation"
                                autocomplete="new-password" autocorrect="off" autocapitalize="none" spellcheck="false"
                                class="w-full rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 pr-10 text-[13px] text-[#1B3A34] outline-none focus:border-[#2D8659] transition">
                            <button type="button" class="toggle-user-password-btn absolute right-3 top-1/2 -translate-y-1/2 text-[#4B5F5A] transition hover:text-[#1B3A34] focus:outline-none"
                                onclick="toggleUserProfilePasswords()" aria-label="Lihat password">
                                <svg class="h-4 w-4 eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex items-center justify-end gap-3">
                <button type="submit"
                    class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-[#1F5F3F]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                    Simpan Perubahan
                </button>
            </div>

        </div>
        </form>
    </div>
</div>

<script>
function previewAvatar(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = ev => {
        const preview  = document.getElementById('avatarPreview');
        const fallback = document.getElementById('avatarFallback');
        if (preview) {
            preview.src = ev.target.result;
            preview.classList.remove('hidden');
        }
        if (fallback) fallback.classList.add('hidden');
    };
    reader.readAsDataURL(file);
}

function toggleUserProfilePasswords() {
    const p1 = document.getElementById('user_password');
    const p2 = document.getElementById('user_password_confirmation');
    const isHidden = (p1 || p2)?.type === 'password';
    const newType = isHidden ? 'text' : 'password';
    const newLabel = isHidden ? 'Sembunyikan password' : 'Lihat password';
    const newIcon = isHidden
        ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
        : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/>';

    if (p1) p1.type = newType;
    if (p2) p2.type = newType;

    document.querySelectorAll('.toggle-user-password-btn').forEach(b => {
        b.setAttribute('aria-label', newLabel);
        const icon = b.querySelector('.eye-icon');
        if (icon) icon.innerHTML = newIcon;
    });
}
</script>
@endsection
