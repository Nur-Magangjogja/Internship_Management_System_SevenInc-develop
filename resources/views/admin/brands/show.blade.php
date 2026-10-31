@extends('layouts.dashboard')

@section('content')
<div class="min-h-screen bg-[#F4F8F6] p-4 sm:p-6 lg:p-7">
    <div class="max-w-5xl mx-auto mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <a href="{{ route('admin.brands.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-[#4B5F5A] hover:text-[#2D8659] transition mb-3">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg>
                Kembali ke Daftar Brand
            </a>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#1B3A34]">Detail Brand: {{ $brand->name }}</h1>
            <p class="text-xs text-[#4B5F5A] mt-0.5">Ringkasan profil perusahaan, pejabat penandatangan, dan aset visual aktif.</p>
        </div>
        <a href="{{ route('admin.brands.edit', $brand->id) }}" class="flex items-center gap-2 rounded-xl bg-[#2D8659] px-5 py-2.5 text-sm font-bold text-white hover:bg-[#1F5F3F] transition shadow-xs">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Edit Brand Ini
        </a>
    </div>

    <div class="max-w-5xl mx-auto space-y-6">
        @if(session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">{{ session('error') }}</div>
        @endif

        @php
            $lifecycleLabels = [
                'draft' => 'Draft',
                'configuration_pending' => 'Configuration Pending',
                'ready' => 'Ready for Activation',
                'active' => 'Active',
                'suspended' => 'Suspended',
                'archived' => 'Archived',
            ];
            $lifecycleColors = [
                'active' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                'ready' => 'bg-blue-100 text-blue-800 border-blue-200',
                'suspended' => 'bg-amber-100 text-amber-800 border-amber-200',
                'archived' => 'bg-slate-100 text-slate-700 border-slate-200',
            ];
        @endphp

        {{-- Domain registry dan Readiness Gate --}}
        <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3 pb-4 border-b border-[#DCE7E1]">
                <div>
                    <h2 class="text-base font-bold text-[#1B3A34]">Domain & Siklus Hidup Brand</h2>
                    <p class="mt-1 text-xs text-[#4B5F5A]">Hanya hostname yang terverifikasi dan berstatus Active yang masuk ke registry TrustHosts.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @php($opBadge = $brand->operational_status_badge)
                    <span class="inline-flex rounded-full border px-3 py-1 text-xs font-bold {{ $opBadge['class'] }}">
                        {{ $opBadge['label'] }}
                    </span>
                    <span class="inline-flex rounded-full border px-3 py-1 text-xs font-bold {{ $lifecycleColors[$brand->lifecycle_status] ?? 'bg-slate-50 text-slate-700 border-slate-200' }}">
                        {{ $lifecycleLabels[$brand->lifecycle_status] ?? 'Status Tidak Dikenal' }}
                    </span>
                </div>
            </div>

            @if(!$brand->isProfileCompleteForDocuments())
                <div class="rounded-xl border border-amber-200 bg-amber-50/70 p-3.5 text-xs text-amber-900">
                    <p class="font-bold flex items-center gap-1.5 text-amber-800">
                        <i class="fa-solid fa-triangle-exclamation text-amber-600"></i> Profil Operasional Belum Lengkap
                    </p>
                    <p class="mt-1 text-slate-600">Lengkapi data operasional berikut melalui <strong>Edit Brand</strong> sebelum menerbitkan dokumen resmi atau mengaktifkan pendaftaran:</p>
                    <ul class="mt-1.5 list-disc list-inside space-y-0.5 text-amber-800 font-medium">
                        @foreach($brand->getMissingOperationalFields() as $fieldKey => $fieldName)
                            <li>{{ $fieldName }} masih kosong</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Host Pendaftaran</p>
                    <p class="font-mono text-[#1B3A34] break-all">{{ $brand->registration_host ?: '-' }}</p>
                </div>
                <div>
                    <p class="mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Domain Utama</p>
                    <p class="font-mono text-[#1B3A34] break-all">{{ $brand->primary_domain ?: '-' }}</p>
                </div>
                <div class="md:col-span-2 rounded-xl border px-4 py-3 {{ $brand->domain_verified_at ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' }}">
                    @if($brand->domain_verified_at)
                        <p class="text-sm font-bold text-emerald-800">Domain terverifikasi</p>
                        <p class="mt-1 text-xs text-emerald-800">{{ $brand->domain_verified_at->format('d M Y H:i') }} · Metode: {{ $brand->domain_verification_method ?: 'tidak tercatat' }}</p>
                    @else
                        <p class="text-sm font-bold text-amber-900">Domain belum terverifikasi</p>
                        <p class="mt-1 text-xs text-amber-900">Tambahkan TXT record berikut ke DNS domain utama, tunggu propagasi, kemudian klik Verifikasi Domain.</p>
                        @if($brand->primary_domain && $brand->domain_verification_token)
                            <div class="mt-3 space-y-2 text-xs text-amber-950">
                                <p><span class="font-semibold">Jenis:</span> TXT</p>
                                <p><span class="font-semibold">Nama/Host:</span> <code class="break-all">_seveninc-verification.{{ $brand->primary_domain }}</code></p>
                                <p><span class="font-semibold">Nilai:</span> <code class="break-all">seveninc-domain-verification={{ $brand->domain_verification_token }}</code></p>
                            </div>
                            <form action="{{ route('admin.brands.verify-domain', $brand) }}" method="POST" class="mt-3">
                                @csrf
                                <button type="submit" class="rounded-lg bg-amber-700 px-3 py-2 text-xs font-bold text-white hover:bg-amber-800">Verifikasi Domain via DNS</button>
                            </form>
                        @else
                            <p class="mt-2 text-xs text-amber-900">Isi host pendaftaran dan domain utama melalui Edit Brand untuk membuat token verifikasi.</p>
                        @endif
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-[#DCE7E1] pt-4">
                <a href="{{ route('admin.spreadsheet-connections.index') }}" class="rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100">
                    Kelola Koneksi Spreadsheet
                </a>
                @if($brand->lifecycle_status !== 'archived')
                    <form action="{{ route('admin.brands.check-readiness', $brand) }}" method="POST">
                        @csrf
                        <button type="submit" class="rounded-lg border border-[#2D8659] bg-white px-3 py-2 text-xs font-bold text-[#2D8659] hover:bg-emerald-50">Periksa Readiness Gate</button>
                    </form>
                @endif
                @if($brand->lifecycle_status === 'ready')
                    <form action="{{ route('admin.brands.activate', $brand) }}" method="POST" onsubmit="return confirm('Aktifkan brand ini setelah seluruh konfigurasi dinyatakan siap?')">
                        @csrf
                        <button type="submit" class="rounded-lg bg-[#2D8659] px-3 py-2 text-xs font-bold text-white hover:bg-[#1F5F3F]">Aktifkan Brand</button>
                    </form>
                @endif
                @if($brand->lifecycle_status === 'active' && !$brand->is_default)
                    <form action="{{ route('admin.brands.suspend', $brand) }}" method="POST" onsubmit="return confirm('Suspend brand ini? Host akan dikeluarkan dari registry tepercaya.')">
                        @csrf
                        <button type="submit" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">Suspend</button>
                    </form>
                @endif
                @if($brand->lifecycle_status === 'suspended')
                    <form action="{{ route('admin.brands.unsuspend', $brand) }}" method="POST" onsubmit="return confirm('Cabut suspend dan aktifkan kembali brand ini?')">
                        @csrf
                        <button type="submit" class="rounded-lg bg-[#2D8659] px-3 py-2 text-xs font-bold text-white hover:bg-[#1F5F3F]">Cabut Suspend (Reaktivasi)</button>
                    </form>
                @endif
                @if(!$brand->is_default && $brand->lifecycle_status !== 'archived')
                    <form action="{{ route('admin.brands.archive', $brand) }}" method="POST" onsubmit="return confirm('Arsipkan brand ini tanpa menghapus riwayat data?')">
                        @csrf
                        <button type="submit" class="rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">Arsipkan</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- 1. INFORMASI UTAMA BRAND --}}
        <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs">
            <div class="flex items-center gap-3 pb-4 mb-5 border-b border-[#DCE7E1]">
                <div class="w-9 h-9 rounded-xl bg-emerald-50 text-[#2D8659] border border-emerald-100 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-[#1B3A34]">1. Informasi Dasar Perusahaan</h2>
                    <p class="text-xs text-[#4B5F5A]">Identitas brand perusahaan penyelenggara program magang dan webinar.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Kode Brand</label>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-mono font-bold bg-[#F4F8F6] text-[#1B3A34] border border-[#DCE7E1]">
                        {{ $brand->code }}
                    </span>
                </div>
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Nama Brand / Entitas</label>
                    <p class="text-sm font-bold text-[#1B3A34]">{{ $brand->name }}</p>
                </div>
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Alamat Kantor</label>
                    <p class="text-sm text-[#1B3A34] leading-relaxed">{{ $brand->company_address ?: '-' }}</p>
                </div>
            </div>
        </div>

        {{-- 2. PENANDATANGAN DOKUMEN --}}
        <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs">
            <div class="flex items-center gap-3 pb-4 mb-5 border-b border-[#DCE7E1]">
                <div class="w-9 h-9 rounded-xl bg-emerald-50 text-[#2D8659] border border-emerald-100 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-[#1B3A34]">2. Pejabat Penandatangan Dokumen</h2>
                    <p class="text-xs text-[#4B5F5A]">Pimpinan atau perwakilan resmi pada lembar tanda tangan dokumen.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Nama Pimpinan</label>
                    <p class="text-sm font-semibold text-[#1B3A34]">{{ $brand->signatory_name ?: '-' }}</p>
                </div>
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Email Korespondensi</label>
                    <p class="text-sm text-[#1B3A34]">{{ $brand->signatory_email ?: '-' }}</p>
                </div>
                <div>
                    <label class="block mb-1 text-[11px] font-bold uppercase tracking-wider text-[#4B5F5A]">Jabatan Resmi</label>
                    <p class="text-sm text-[#1B3A34]">{{ $brand->signatory_position ?: '-' }}</p>
                </div>
            </div>
        </div>

        {{-- 3. ASET VISUAL --}}
        <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs space-y-6">
            <div class="flex items-center gap-3 pb-4 border-b border-[#DCE7E1]">
                <div class="w-9 h-9 rounded-xl bg-emerald-50 text-[#2D8659] border border-emerald-100 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-[#1B3A34]">3. Aset Visual & Dokumen Resmi</h2>
                    <p class="text-xs text-[#4B5F5A]">Berkas visual resmi brand yang aktif pada sistem penerbitan dokumen.</p>
                </div>
            </div>

            {{-- Sub-grup 1: Cap & Identitas Resmi --}}
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-[#2D8659] flex items-center gap-1.5 mb-3">
                    <span class="w-2 h-2 rounded-full bg-[#2D8659]"></span>
                    Bagian A: Identitas & Cap Resmi Perusahaan
                </span>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- Logo --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Logo Brand</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden bg-[radial-gradient(#d1d5db_1px,transparent_1px)] [background-size:10px_10px]">
                                @if($brand->logo)
                                    <img src="{{ Storage::url($brand->logo) }}" alt="Logo" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Belum ada logo</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->logo)
                            <a href="{{ Storage::url($brand->logo) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>

                    {{-- Tanda Tangan --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Tanda Tangan Pimpinan</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden bg-[radial-gradient(#d1d5db_1px,transparent_1px)] [background-size:10px_10px]">
                                @if($brand->signature)
                                    <img src="{{ Storage::url($brand->signature) }}" alt="Signature" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Belum ada tanda tangan</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->signature)
                            <a href="{{ Storage::url($brand->signature) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>

                    {{-- Stempel --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Stempel Perusahaan / Cap Resmi</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden bg-[radial-gradient(#d1d5db_1px,transparent_1px)] [background-size:10px_10px]">
                                @if($brand->stamp)
                                    <img src="{{ Storage::url($brand->stamp) }}" alt="Stamp" class="max-h-full max-w-full object-contain">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Belum ada stempel</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->stamp)
                            <a href="{{ Storage::url($brand->stamp) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Sub-grup 2: Latar Belakang --}}
            <div class="pt-4 border-t border-[#DCE7E1]/80">
                <span class="text-xs font-bold uppercase tracking-wider text-[#2D8659] flex items-center gap-1.5 mb-3">
                    <span class="w-2 h-2 rounded-full bg-[#2D8659]"></span>
                    Bagian B: Latar Belakang Dokumen & Kartu Digital
                </span>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {{-- BG Magang --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Background Sertifikat Magang</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden">
                                @if($brand->internship_certificate_bg)
                                    <img src="{{ Storage::url($brand->internship_certificate_bg) }}" alt="BG Magang" class="max-h-full max-w-full object-contain">
                                @else
                                    <div class="text-center">
                                        <span class="inline-block text-xs font-semibold text-[#2D8659]">Default Sistem</span>
                                        <p class="text-[10px] text-[#4B5F5A] mt-0.5">Border Emas Klasik</p>
                                    </div>
                                @endif
                            </div>
                        </div>
                        @if($brand->internship_certificate_bg)
                            <a href="{{ Storage::url($brand->internship_certificate_bg) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>

                    {{-- BG Webinar --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Background Sertifikat Webinar</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden">
                                @if($brand->webinar_certificate_bg)
                                    <img src="{{ Storage::url($brand->webinar_certificate_bg) }}" alt="BG Webinar" class="max-h-full max-w-full object-contain">
                                @else
                                    <div class="text-center">
                                        <span class="inline-block text-xs font-semibold text-[#2D8659]">Default Sistem</span>
                                        <p class="text-[10px] text-[#4B5F5A] mt-0.5">Border Emas Klasik</p>
                                    </div>
                                @endif
                            </div>
                        </div>
                        @if($brand->webinar_certificate_bg)
                            <a href="{{ Storage::url($brand->webinar_certificate_bg) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>

                    {{-- Membercard BG --}}
                    <div class="rounded-xl border border-[#DCE7E1] bg-[#F8FAF9] p-4 flex flex-col justify-between">
                        <div>
                            <span class="block text-xs font-bold text-[#1B3A34] mb-2">Background Membercard Digital</span>
                            <div class="h-28 bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden">
                                @if($brand->membercard_bg)
                                    <img src="{{ Storage::url($brand->membercard_bg) }}" alt="BG Membercard" class="max-h-full max-w-full object-cover rounded shadow-2xs">
                                @else
                                    <span class="text-xs text-[#4B5F5A] italic">Bawaan tema brand</span>
                                @endif
                            </div>
                        </div>
                        @if($brand->membercard_bg)
                            <a href="{{ Storage::url($brand->membercard_bg) }}" target="_blank" class="mt-2 text-center text-xs font-medium text-[#2D8659] hover:underline">Lihat Berkas Asli</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- 4. PREVIEW MEMBERCARD DIGITAL --}}
        <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs">
            <div class="mb-4">
                <h3 class="text-base font-bold text-[#1B3A34]">4. Pratinjau Membercard Digital Alumni</h3>
                <p class="text-xs text-[#4B5F5A]">Simulasi kartu anggota digital pemagang dengan identitas dan tema warna brand <strong>{{ $brand->name }}</strong>.</p>
            </div>
            <div class="p-6 rounded-xl bg-[#0f172a]/5 border border-[#DCE7E1] flex justify-center">
                <x-membercard :brand="$brand"
                              name="CONTOH NAMA LENGKAP"
                              :code="$brand->code . '24001'"
                              :angkatan="date('Y')"
                              instansi="Universitas / Institut Contoh"
                              divisi="Divisi Magang" />
            </div>
        </div>

        {{-- 5. MODE DAN DIVISI PENDAFTARAN BRAND --}}
        <div class="rounded-2xl border border-[#DCE7E1] bg-white p-6 shadow-xs">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-[#DCE7E1]">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-emerald-50 text-[#2D8659] border border-emerald-100 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-[#1B3A34]">5. Konfigurasi Divisi Pendaftaran</h2>
                        @if(($brand->division_mode ?? 'global') === 'global')
                            <p class="text-xs text-[#4B5F5A]">Mode Global: formulir menggunakan seluruh divisi umum yang aktif.</p>
                        @else
                            <p class="text-xs text-[#4B5F5A]">Mode Khusus Brand: hanya divisi aktif yang ditautkan ke brand ini yang tersedia.</p>
                        @endif
                    </div>
                </div>
                <span class="text-xs font-bold text-[#2D8659] bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200">
                    {{ ($brand->division_mode ?? 'global') === 'global' ? 'GLOBAL' : $brand->divisions->where('is_active', true)->count() . ' DIVISI AKTIF' }}
                </span>
            </div>

            @if(($brand->division_mode ?? 'global') === 'global')
                <p class="text-xs text-[#4B5F5A]">Brand ini tidak dibatasi oleh mapping khusus. Hanya divisi umum yang masih aktif yang ditampilkan pada formulir.</p>
            @else
                @php($activeBrandDivisions = $brand->divisions->where('is_active', true))
                @if($activeBrandDivisions->isNotEmpty())
                    <div class="flex flex-wrap gap-2">
                        @foreach($activeBrandDivisions as $div)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-[#F4F8F6] text-[#1B3A34] border border-[#DCE7E1]">
                                <span>{{ $div->name }}</span>
                                @if($div->code)
                                    <span class="text-[10px] font-mono text-[#2D8659] bg-white px-1.5 py-0.5 rounded border border-[#DCE7E1]">{{ $div->code }}</span>
                                @endif
                            </span>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-amber-700 italic">Tidak ada divisi aktif yang dibuka. Pendaftaran untuk brand ini ditutup sampai Admin mengaktifkan mapping divisi.</p>
                @endif
            @endif
        </div>
    </div>
</div>
@endsection
