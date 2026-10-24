@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">

    {{-- Header --}}
    <div class="mb-6 flex items-center gap-3">
        <a href="{{ route('admin.users.index') }}"
            class="flex h-9 w-9 items-center justify-center rounded-[9px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-[#2D8659] hover:text-[#1F5F3F]">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <div>
            <p class="text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Manajemen Pengguna</p>
            <h1 class="text-xl font-extrabold tracking-tight text-[#1B3A34]">Detail Pengguna</h1>
        </div>
    </div>

    <div class="max-w-2xl space-y-5">

        {{-- Kartu Info Akun --}}
        <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-6 shadow-sm">
            <div class="flex items-center gap-4 mb-5">
                @php
                    $initials = collect(explode(' ', $user->name))->take(2)->map(fn($w)=>strtoupper($w[0]??''))->implode('');
                    $role = strtolower($user->role ?? 'user');
                    $roleMeta = match($role) {
                        'admin'    => ['Admin',    'bg-purple-50 text-purple-700 border border-purple-200'],
                        'pemagang' => ['Pemagang', 'bg-[#E8F5E9] text-[#1F5F3F] border border-[#A5D6A7]'],
                        default    => ['User',     'bg-blue-50 text-blue-700 border border-blue-200'],
                    };
                @endphp
                <div class="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[#E8F5E9] text-lg font-bold text-[#1F5F3F]">
                    {{ $initials }}
                    @if($user->is_online)
                    <span class="absolute bottom-0 right-0 h-3 w-3 rounded-full border-2 border-white bg-[#388E3C]"></span>
                    @endif
                </div>
                <div>
                    <p class="text-lg font-extrabold text-[#1B3A34]">{{ $user->name }}</p>
                    <p class="text-[13px] text-[#4B5F5A]">{{ $user->email }}</p>
                    <div class="mt-1.5 flex items-center gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $roleMeta[1] }}">
                            {{ $roleMeta[0] }}
                        </span>
                        @if($user->is_online)
                        <span class="inline-flex items-center gap-1 rounded-full bg-[#E8F5E9] px-2 py-0.5 text-[11px] font-semibold text-[#388E3C] border border-[#A5D6A7]">
                            <span class="h-1.5 w-1.5 rounded-full bg-[#388E3C] animate-pulse"></span>Online
                        </span>
                        @else
                        <span class="inline-flex items-center gap-1 rounded-full bg-[#F4F8F6] px-2 py-0.5 text-[11px] font-semibold text-[#4B5F5A] border border-[#DCE7E1]">
                            <span class="h-1.5 w-1.5 rounded-full bg-[#4B5F5A]"></span>Offline
                        </span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 border-t border-[#DCE7E1] pt-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">ID Pengguna</p>
                    <p class="text-[13px] font-semibold text-[#1B3A34]">#{{ $user->id }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">No. HP</p>
                    <p class="text-[13px] text-[#1B3A34]">{{ $user->phone_number ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Bergabung</p>
                    <p class="text-[13px] text-[#1B3A34]">{{ $user->created_at?->format('d M Y') ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Terakhir Aktif</p>
                    <p class="text-[13px] text-[#1B3A34]">{{ $user->updated_at?->format('d M Y H:i') ?? '-' }}</p>
                </div>
            </div>

            <div class="flex items-center gap-3 border-t border-[#DCE7E1] pt-4 mt-4">
                <a href="{{ route('admin.users.edit', $user->id) }}"
                    class="flex items-center gap-2 rounded-[9px] bg-[#2D8659] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#1F5F3F]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>
                    Edit Pengguna
                </a>
                <a href="{{ route('admin.users.index') }}"
                    class="rounded-[9px] border border-[#DCE7E1] bg-white px-4 py-2 text-sm font-semibold text-[#4B5F5A] transition hover:bg-[#F4F8F6]">
                    Kembali
                </a>
            </div>
        </div>

        {{-- Kartu Data Magang (jika ada) --}}
        @if($internship)
        <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-6 shadow-sm">
            <p class="mb-4 text-[11px] font-bold uppercase tracking-[0.08em] text-[#2D8659]">Data Pendaftaran Magang</p>

            @php
                $statusMeta = [
                    'waiting'   => ['Menunggu Review', 'bg-amber-50 text-amber-700 border border-amber-200'],
                    'accepted'  => ['Diterima',        'bg-[#E8F5E9] text-[#1F5F3F] border border-[#A5D6A7]'],
                    'active'    => ['Aktif',           'bg-blue-50 text-blue-700 border border-blue-200'],
                    'completed' => ['Selesai',         'bg-slate-100 text-slate-700 border border-slate-300'],
                    'rejected'  => ['Ditolak',         'bg-red-50 text-red-700 border border-red-200'],
                    'exited'    => ['Keluar',          'bg-red-50 text-red-700 border border-red-200'],
                    'pending'   => ['Pending',         'bg-amber-100 text-amber-800 border border-amber-300'],
                ];
                $sm = $statusMeta[$internship->internship_status] ?? [ucfirst($internship->internship_status), 'bg-gray-100 text-gray-700 border border-gray-200'];
            @endphp

            <div class="flex items-center justify-between mb-4">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $sm[1] }}">
                    {{ $sm[0] }}
                </span>
                <span class="text-[12px] text-[#4B5F5A]">Daftar: {{ $internship->created_at?->format('d M Y') }}</span>
            </div>

            <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-[13px]">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Nama Lengkap</p>
                    <p class="text-[#1B3A34]">{{ $internship->fullname ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Nomor Induk Mahasiswa / Nomor Induk Siswa Nasional</p>
                    <p class="text-[#1B3A34]">{{ $internship->student_id ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Universitas</p>
                    <p class="text-[#1B3A34]">{{ $internship->institution_name ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Program Studi</p>
                    <p class="text-[#1B3A34]">{{ $internship->study_program ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Divisi</p>
                    <p class="text-[#1B3A34]">{{ $internship->internship_interest ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">No. HP</p>
                    <p class="text-[#1B3A34]">{{ $internship->phone_number ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Tanggal Mulai</p>
                    <p class="text-[#1B3A34]">{{ $internship->start_date ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Tanggal Selesai</p>
                    <p class="text-[#1B3A34]">{{ $internship->end_date ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Jenis Magang</p>
                    <p class="text-[#1B3A34]">{{ $internship->internship_type ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Sistem</p>
                    <p class="text-[#1B3A34]">{{ $internship->internship_arrangement ?: '-' }}</p>
                </div>
                <div class="col-span-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Email Pendaftar</p>
                    <p class="text-[#1B3A34]">{{ $internship->email ?: '-' }}</p>
                </div>
            </div>

            {{-- Berkas --}}
            @if($internship->cv_ktp_portofolio_pdf || $internship->portofolio_visual)
            <div class="mt-4 border-t border-[#DCE7E1] pt-4">
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-[#4B5F5A]">Berkas Unggahan</p>
                <div class="flex flex-wrap gap-2">
                    @if($internship->cv_ktp_portofolio_pdf)
                    <a href="{{ asset('storage/'.$internship->cv_ktp_portofolio_pdf) }}" target="_blank"
                        class="inline-flex items-center gap-2 rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[12.5px] font-semibold text-[#1F5F3F] transition hover:border-[#2D8659]">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/></svg>
                        CV / KTP / Portofolio (PDF)
                    </a>
                    @endif
                    @if($internship->portofolio_visual)
                    <a href="{{ asset('storage/'.$internship->portofolio_visual) }}" target="_blank"
                        class="inline-flex items-center gap-2 rounded-[8px] border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2 text-[12.5px] font-semibold text-[#1F5F3F] transition hover:border-[#2D8659]">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                        Portofolio Visual
                    </a>
                    @endif
                </div>
            </div>
            @endif

            {{-- Tombol ke detail pendaftar --}}
            <div class="mt-4 border-t border-[#DCE7E1] pt-4">
                <a href="{{ route('admin.interns.pendaftar') }}"
                    class="text-[12.5px] font-semibold text-[#2D8659] hover:underline">
                    → Lihat di Data Pendaftar Magang
                </a>
            </div>
        </div>
        @else
        <div class="rounded-[12px] border border-[#DCE7E1] bg-white p-6 shadow-sm text-center text-[13px] text-[#4B5F5A]">
            Pengguna ini belum memiliki data pendaftaran magang.
        </div>
        @endif

    </div>
</div>
@endsection
