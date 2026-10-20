@extends('layouts.dashboard', ['title' => 'Detail Siklus Follow-Up'])

@php
    $isHr = request()->routeIs('hr.*');
    $routePrefix = $isHr ? 'hr.follow-up' : 'admin.follow-up';
@endphp

@section('content')
<div class="px-6 py-8">
    <!-- Breadcrumb & Header -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <a
                href="{{ route($routePrefix . '.index') }}"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600 shadow-sm transition hover:bg-slate-50"
                title="Kembali ke Daftar Siklus"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold text-slate-800">{{ $cycle->institution->name ?? 'Institusi Mitra' }}</h1>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold border {{ $cycle->statusBadgeClass() }}">
                        {{ $cycle->statusLabel() }}
                    </span>
                    @if ($cycle->final_result)
                        <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700 border border-slate-200">
                            Hasil: {{ $cycle->finalResultLabel() }}
                        </span>
                    @endif
                </div>
                <p class="text-sm text-slate-500 break-words [overflow-wrap:anywhere]">
                    Siklus Penjangkauan Kemitraan Tahun {{ $cycle->cycle_year }} &bull; Kontak: {{ $cycle->contact->name ?? '-' }}
                </p>
            </div>
        </div>
        <div>
            @if (request()->routeIs('admin.*'))
                <a
                    href="{{ route('admin.institutions.show', $cycle->institution_id) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    Master Institusi
                </a>
            @else
                <a
                    href="{{ route('hr.institutions.show', $cycle->institution_id) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    Detail Institusi
                </a>
            @endif
        </div>
    </div>

    <!-- Alert Messages -->
    @if (session('success'))
        <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Task Lock & Collision Prevention Status Banner -->
    @if (!$cycle->isClosed())
        <div class="mb-6 rounded-xl border p-4 shadow-sm {{ $cycle->isLockedBy(auth()->user()) ? 'border-blue-200 bg-blue-50/70' : ($cycle->isLockedByOther(auth()->user()) ? 'border-amber-200 bg-amber-50/80' : 'border-slate-200 bg-white') }}">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <div class="rounded-lg p-2 {{ $cycle->isLockedBy(auth()->user()) ? 'bg-blue-100 text-blue-700' : ($cycle->isLockedByOther(auth()->user()) ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500') }}">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                    </div>
                    <div>
                        @if ($cycle->isLockedBy(auth()->user()))
                            <h3 class="text-xs font-bold text-blue-900">Anda Sedang Mengunci Tugas Ini</h3>
                            <p class="text-xs text-blue-700">
                                Kunci aktif hingga pukul {{ $cycle->lock_expires_at?->format('H:i') }} (tersisa ~{{ $cycle->lockExpiresInMinutes() }} menit). Staf lain tidak dapat mencatat follow-up selama Anda memegang kunci ini.
                            </p>
                        @elseif ($cycle->isLockedByOther(auth()->user()))
                            <h3 class="text-xs font-bold text-amber-900">Tugas Sedang Dikerjakan oleh Staf Lain</h3>
                            <p class="text-xs text-amber-700">
                                Kontak ini sedang dihubungi oleh <span class="font-bold text-amber-950">{{ $cycle->lockedByUser->name ?? 'Staf HR' }}</span> hingga pukul {{ $cycle->lock_expires_at?->format('H:i') }} (tersisa ~{{ $cycle->lockExpiresInMinutes() }} menit). Formulir dinonaktifkan sementara untuk menghindari kontak ganda.
                            </p>
                        @else
                            <h3 class="text-xs font-bold text-slate-800">Tugas Belum Dikunci</h3>
                            <p class="text-xs text-slate-500">
                                Ambil dan kunci tugas ini sebelum menghubungi pihak kampus agar staf lain mengetahui bahwa follow-up sedang Anda tangani.
                            </p>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-2 self-end sm:self-center">
                    @if ($cycle->isLockedBy(auth()->user()))
                        <form method="POST" action="{{ route($routePrefix . '.release', $cycle) }}">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50 transition"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 9.9-1"/>
                                </svg>
                                Lepaskan Kunci
                            </button>
                        </form>
                    @elseif ($cycle->isLockedByOther(auth()->user()))
                        @if (auth()->user()->role === 'admin')
                            <form method="POST" action="{{ route('admin.follow-up.force-release', $cycle) }}" onsubmit="return confirm('Apakah Anda yakin ingin melepaskan paksa kunci tugas staf ini?')">
                                @csrf
                                <button
                                    type="submit"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 transition"
                                >
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M18.36 6.64a9 9 0 1 1-12.73 0"/>
                                        <line x1="12" y1="2" x2="12" y2="12"/>
                                    </svg>
                                    Lepas Paksa Kunci (Admin)
                                </button>
                            </form>
                        @endif
                    @elseif ($cycle->canAttempt())
                        <form method="POST" action="{{ route($routePrefix . '.claim', $cycle) }}">
                            @csrf
                            <input type="hidden" name="duration" value="15">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                                Klaim & Kunci Tugas (15 Menit)
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Top Info Grid -->
    <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Card 1: Profil Kontak Pembimbing -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm min-w-0">
            <div class="flex items-center justify-between gap-2 pb-3 border-b border-slate-100">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400 shrink-0">Kontak Pembimbing (PIC)</span>
                <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700 truncate max-w-[50%]" title="{{ $cycle->contact->position ?? 'Pembimbing' }}">
                    {{ $cycle->contact->position ?? 'Pembimbing' }}
                </span>
            </div>
            <div class="mt-4 space-y-3 min-w-0">
                <div class="min-w-0">
                    <h4 class="text-base font-bold text-slate-800 break-words [overflow-wrap:anywhere]">{{ $cycle->contact->name ?? '-' }}</h4>
                    @if ($cycle->contact?->email)
                        <div class="flex items-center gap-1.5 mt-1 text-xs text-slate-600 min-w-0">
                            <svg class="h-3.5 w-3.5 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                <polyline points="22,6 12,13 2,6"/>
                            </svg>
                            <span class="break-all">{{ $cycle->contact->email }}</span>
                        </div>
                    @endif
                </div>

                <div class="pt-2 border-t border-slate-100 min-w-0">
                    <span class="text-[11px] font-medium text-slate-400">Nomor Telepon / WhatsApp:</span>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <span class="text-xs font-semibold text-slate-800 break-all">{{ $cycle->contact->phone ?? 'Tidak ada nomor' }}</span>
                        @if ($cycle->contact?->phone)
                            @php
                                $cleanPhone = preg_replace('/[^0-9]/', '', $cycle->contact->phone);
                                if (str_starts_with($cleanPhone, '0')) {
                                    $cleanPhone = '62' . substr($cleanPhone, 1);
                                }
                            @endphp
                            <a
                                href="https://wa.me/{{ $cleanPhone }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex shrink-0 items-center gap-1 rounded bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-100 transition"
                            >
                                <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                                </svg>
                                Hubungi WA
                            </a>
                        @endif
                    </div>
                </div>

                @if ($cycle->contact?->notes)
                    <div class="rounded-lg bg-slate-50 p-2.5 text-[11px] text-slate-600 break-words [overflow-wrap:anywhere]">
                        <span class="font-semibold text-slate-700">Catatan PIC:</span> {{ $cycle->contact->notes }}
                    </div>
                @endif
            </div>
        </div>

        <!-- Card 2: Informasi Institusi -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm min-w-0">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Institusi Mitra</span>
                @if ($cycle->institution?->is_active)
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                        Aktif
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                        Nonaktif
                    </span>
                @endif
            </div>
            <div class="mt-4 space-y-2 text-xs min-w-0">
                <div class="min-w-0">
                    <span class="text-[11px] text-slate-400">Nama Kampus / Sekolah:</span>
                    <p class="font-bold text-slate-800 break-words [overflow-wrap:anywhere]">{{ $cycle->institution->name ?? '-' }}</p>
                </div>
                <div class="min-w-0">
                    <span class="text-[11px] text-slate-400">Unit Korespondensi:</span>
                    <p class="font-medium text-slate-700 break-words [overflow-wrap:anywhere]">{{ $cycle->institution->correspondence_unit ?? '-' }}</p>
                </div>
                @if ($cycle->institution?->address)
                    <div class="min-w-0">
                        <span class="text-[11px] text-slate-400">Alamat:</span>
                        <p class="text-slate-600 break-words [overflow-wrap:anywhere]">{{ $cycle->institution->address }}</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Card 3: Status Siklus & Progres Attempt -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm min-w-0">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Progres Follow-Up</span>
                <span class="text-xs font-bold text-slate-700">Tahun {{ $cycle->cycle_year }}</span>
            </div>
            <div class="mt-4 space-y-4">
                <div>
                    <div class="flex items-center justify-between text-xs font-medium text-slate-600 mb-1.5">
                        <span>Batas Percobaan</span>
                        <span class="font-bold text-slate-800">{{ $cycle->current_attempt_count }} dari 3 Percobaan</span>
                    </div>
                    <!-- Step Progress Bars -->
                    <div class="grid grid-cols-3 gap-2">
                        @for ($i = 1; $i <= 3; $i++)
                            <div class="text-center">
                                <div class="h-2 w-full rounded-full {{ $i <= $cycle->current_attempt_count ? 'bg-blue-600' : 'bg-slate-200' }}"></div>
                                <span class="mt-1 block text-[10px] font-semibold {{ $i <= $cycle->current_attempt_count ? 'text-blue-700' : 'text-slate-400' }}">
                                    Percobaan {{ $i }}
                                </span>
                            </div>
                        @endfor
                    </div>
                </div>

                <div class="border-t border-slate-100 pt-3 text-xs space-y-1.5">
                    <div class="flex items-center justify-between text-slate-600">
                        <span class="text-[11px] text-slate-400">Mulai Siklus:</span>
                        <span class="font-medium text-slate-700">{{ $cycle->started_at ? $cycle->started_at->format('d M Y H:i') : 'Belum Dimulai' }}</span>
                    </div>
                    @if ($cycle->isClosed())
                        <div class="flex items-center justify-between text-slate-600">
                            <span class="text-[11px] text-slate-400">Selesai Pada:</span>
                            <span class="font-medium text-slate-700">{{ $cycle->completed_at ? $cycle->completed_at->format('d M Y H:i') : '-' }}</span>
                        </div>
                        <div class="flex items-center justify-between text-slate-600">
                            <span class="text-[11px] text-slate-400">Dikonfirmasi Oleh:</span>
                            <span class="font-medium text-slate-700">{{ $cycle->confirmer->name ?? 'Sistem' }}</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content: Left (Attempt Timeline & Action Forms) | Right (Interns List) -->
    <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
        <!-- Col Left (2 Cols): Timeline & Forms -->
        <div class="space-y-6 lg:col-span-2">
            <!-- Riwayat Percobaan Follow-Up -->
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                    <h2 class="text-base font-bold text-slate-800">Riwayat Percobaan Follow-Up</h2>
                    <span class="text-xs font-semibold text-slate-500">{{ $cycle->attempts->count() }} Percobaan Dicatat</span>
                </div>

                @if ($cycle->attempts->isEmpty())
                    <div class="rounded-lg border border-dashed border-slate-200 p-8 text-center">
                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                        </div>
                        <p class="mt-2 text-xs font-semibold text-slate-700">Belum ada riwayat percobaan follow-up.</p>
                        <p class="text-[11px] text-slate-400">Gunakan formulir di bawah untuk mencatat percobaan pertama kepada kontak pembimbing.</p>
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach ($cycle->attempts as $attempt)
                            <div class="rounded-lg border border-slate-200 bg-slate-50/50 p-4 transition hover:bg-slate-50">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">
                                            {{ $attempt->attempt_number }}
                                        </span>
                                        <span class="text-xs font-bold text-slate-800">Percobaan ke-{{ $attempt->attempt_number }}</span>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold border {{ $attempt->resultBadgeClass() }}">
                                            {{ $attempt->resultLabel() }}
                                        </span>
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ $attempt->followed_up_at ? $attempt->followed_up_at->format('d M Y, H:i') : '-' }} &bull; Oleh <span class="font-semibold text-slate-700">{{ $attempt->performer->name ?? '-' }}</span>
                                    </div>
                                </div>

                                <div class="mt-3 grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                    <div>
                                        <span class="text-[11px] font-medium text-slate-400">Saluran Komunikasi:</span>
                                        <div class="mt-0.5 font-semibold text-slate-700">
                                            {{ $attempt->channelLabel() }}
                                        </div>
                                    </div>
                                    @if ($attempt->next_followup_at)
                                        <div>
                                            <span class="text-[11px] font-medium text-slate-400">Rencana Follow-Up Berikutnya:</span>
                                            <div class="mt-0.5 font-semibold text-blue-700">
                                                {{ $attempt->next_followup_at->format('d M Y') }}
                                            </div>
                                        </div>
                                    @endif
                                </div>

                                @if ($attempt->response_text)
                                    <div class="mt-3 rounded bg-white p-3 border border-slate-200 text-xs">
                                        <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider block mb-1">Rangkuman Respons:</span>
                                        <p class="text-slate-800 whitespace-pre-line">{{ $attempt->response_text }}</p>
                                    </div>
                                @endif

                                @if ($attempt->notes)
                                    <div class="mt-2 text-[11px] text-slate-500 italic">
                                        <span class="font-semibold not-italic text-slate-600">Catatan Internal:</span> {{ $attempt->notes }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Form Pencatatan Attempt Baru -->
            @if ($cycle->canAttempt())
                @if ($cycle->isLockedByOther(auth()->user()))
                    <div class="rounded-xl border border-amber-200 bg-amber-50/70 p-6 shadow-sm">
                        <div class="flex items-start gap-3">
                            <div class="rounded-lg bg-amber-100 p-2 text-amber-700">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-amber-900">Formulir Terkunci Sementara</h3>
                                <p class="mt-1 text-xs text-amber-700">
                                    Tugas ini sedang dikunci dan dikerjakan oleh <strong>{{ $cycle->lockedByUser->name ?? 'Staf lain' }}</strong> hingga pukul {{ $cycle->lock_expires_at?->format('H:i') }}.
                                </p>
                                <p class="mt-1 text-[11px] text-amber-600">
                                    Untuk mencegah tabrakan kontak ganda, formulir ini tidak dapat diisi hingga staf tersebut menyelesaikan follow-up atau waktu kunci kedaluwarsa (~{{ $cycle->lockExpiresInMinutes() }} menit lagi).
                                </p>
                            </div>
                        </div>
                    </div>
                @elseif (!$cycle->isLockedBy(auth()->user()))
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-6 text-center shadow-sm">
                        <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-blue-100 text-blue-700 mb-3">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </div>
                        <h3 class="text-sm font-bold text-slate-800">Klaim Kunci untuk Mengaktifkan Formulir</h3>
                        <p class="mt-1 text-xs text-slate-500 max-w-md mx-auto">
                            Untuk menjaga integritas data dan mencegah tabrakan kerja, Anda wajib mengklaim kunci tugas follow-up ini sebelum dapat mencatat percobaan kontak.
                        </p>
                        <form method="POST" action="{{ route($routePrefix . '.claim', $cycle) }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="duration" value="15">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                                Klaim & Kunci Tugas Sekarang (15 Menit)
                            </button>
                        </form>
                    </div>
                @else
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 border-b border-slate-100 pb-3">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-800">
                                {{ $cycle->current_attempt_count + 1 }}
                            </span>
                            <h2 class="text-base font-bold text-slate-800">
                                Catat Percobaan ke-{{ $cycle->current_attempt_count + 1 }}
                            </h2>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            Masukkan hasil interaksi follow-up dengan kontak pembimbing. Maksimal 3 kali percobaan per siklus tahunan.
                        </p>
                    </div>

                    <form method="POST" action="{{ route($routePrefix . '.attempts.store', $cycle) }}" class="space-y-4" onsubmit="this.querySelector('button[type=submit]').disabled=true;">
                        @csrf

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <!-- Waktu Follow Up -->
                            <div>
                                <label for="followed_up_at" class="block text-xs font-semibold text-slate-700">
                                    Waktu Follow-Up <span class="text-rose-500">*</span>
                                </label>
                                <input
                                    type="datetime-local"
                                    id="followed_up_at"
                                    name="followed_up_at"
                                    value="{{ old('followed_up_at', now()->format('Y-m-d\TH:i')) }}"
                                    required
                                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                                >
                            </div>

                            <!-- Saluran Komunikasi -->
                            <div>
                                <label for="channel" class="block text-xs font-semibold text-slate-700">
                                    Saluran Komunikasi <span class="text-rose-500">*</span>
                                </label>
                                <select
                                    id="channel"
                                    name="channel"
                                    required
                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                                >
                                    <option value="whatsapp" {{ old('channel') == 'whatsapp' ? 'selected' : '' }}>WhatsApp</option>
                                    <option value="phone" {{ old('channel') == 'phone' ? 'selected' : '' }}>Panggilan Telepon</option>
                                    <option value="email" {{ old('channel') == 'email' ? 'selected' : '' }}>Email</option>
                                    <option value="onsite" {{ old('channel') == 'onsite' ? 'selected' : '' }}>Kunjungan Langsung (On-Site)</option>
                                    <option value="other" {{ old('channel') == 'other' ? 'selected' : '' }}>Lainnya</option>
                                </select>
                            </div>
                        </div>

                        <!-- Hasil Percobaan -->
                        <div>
                            <label for="result" class="block text-xs font-semibold text-slate-700">
                                Hasil / Respons Kontak <span class="text-rose-500">*</span>
                            </label>
                            <select
                                id="result"
                                name="result"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >
                                <option value="">-- Pilih Hasil Interaksi --</option>
                                <option value="responded" {{ old('result') == 'responded' ? 'selected' : '' }}>Direspons Positif / Diskusi Berjalan</option>
                                <option value="call_back" {{ old('result') == 'call_back' ? 'selected' : '' }}>Minta Dihubungi Kembali Nanti</option>
                                <option value="no_answer" {{ old('result') == 'no_answer' ? 'selected' : '' }}>Tidak Menjawab / Panggilan Tidak Diangkat</option>
                                <option value="busy" {{ old('result') == 'busy' ? 'selected' : '' }}>Sedang Sibuk / Tidak Dapat Diganggu</option>
                                <option value="wrong_number" {{ old('result') == 'wrong_number' ? 'selected' : '' }}>Nomor Tidak Valid / Salah Sambung</option>
                                <option value="declined" {{ old('result') == 'declined' ? 'selected' : '' }}>Menolak / Tidak Bersedia Bekerja Sama</option>
                            </select>
                        </div>

                        <!-- Rangkuman Respons -->
                        <div>
                            <label for="response_text" class="block text-xs font-semibold text-slate-700">
                                Rangkuman Respons / Inti Pembicaraan
                            </label>
                            <textarea
                                id="response_text"
                                name="response_text"
                                rows="3"
                                placeholder="Tuliskan poin penting dari jawaban Guru/Dosen pembimbing..."
                                class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                            >{{ old('response_text') }}</textarea>
                        </div>

                        <!-- Rencana Follow-Up Berikutnya & Catatan Internal -->
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="next_followup_at" class="block text-xs font-semibold text-slate-700">
                                    Jadwal Follow-Up Berikutnya (Opsional)
                                </label>
                                <input
                                    type="date"
                                    id="next_followup_at"
                                    name="next_followup_at"
                                    value="{{ old('next_followup_at') }}"
                                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                                >
                            </div>
                            <div>
                                <label for="notes" class="block text-xs font-semibold text-slate-700">
                                    Catatan Internal HR (Opsional)
                                </label>
                                <input
                                    type="text"
                                    id="notes"
                                    name="notes"
                                    value="{{ old('notes') }}"
                                    placeholder="Misal: Perlu kirim draft proposal MOU dulu"
                                    class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                                >
                            </div>
                        </div>

                        <div class="flex items-center justify-end pt-3 border-t border-slate-100">
                            <button
                                type="submit"
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                    <polyline points="17 21 17 13 7 13 7 21"/>
                                    <polyline points="7 3 7 8 15 8"/>
                                </svg>
                                Simpan Percobaan Follow-Up
                            </button>
                        </div>
                    </form>
                </div>
                @endif
            @elseif (!$cycle->isClosed() && $cycle->current_attempt_count >= 3)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                    <div class="flex items-start gap-3">
                        <div class="rounded-lg bg-amber-100 p-2 text-amber-700">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-xs font-bold text-amber-900">Batas Maksimal 3 Percobaan Tercapai</h3>
                            <p class="mt-0.5 text-xs text-amber-700">
                                Seluruh kuota 3 kali follow-up untuk siklus tahun {{ $cycle->cycle_year }} telah dilakukan. Silakan tentukan keputusan akhir untuk menutup atau menyelesaikan siklus ini pada panel penyelesaian di bawah.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Panel Penyelesaian Siklus (Jika Belum Ditutup) -->
            @if (!$cycle->isClosed())
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="mb-4 border-b border-slate-100 pb-3">
                        <h2 class="text-base font-bold text-slate-800">Selesaikan / Tutup Siklus Follow-Up</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Pilih keputusan akhir dari hasil penjajakan kerja sama dengan institusi mitra ini.
                        </p>
                    </div>

                    @php
                        $attemptCount = $cycle->attempts->count();
                        $isAdmin = auth()->user()->role === 'admin';
                    @endphp

                    @if (!$cycle->isLockedBy(auth()->user()))
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <span class="font-bold text-slate-800">Kunci Tugas Diperlukan:</span>
                                    Siklus hanya dapat diselesaikan oleh pemegang kunci aktif. Klaim kunci tugas terlebih dahulu untuk mengaktifkan formulir penyelesaian.
                                </div>
                                @if (!$cycle->isLocked())
                                    <form method="POST" action="{{ route($routePrefix . '.claim', $cycle) }}" class="shrink-0">
                                        @csrf
                                        <input type="hidden" name="duration" value="15">
                                        <button
                                            type="submit"
                                            class="inline-flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition"
                                        >
                                            Klaim Kunci
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @elseif ($attemptCount === 0 && !$isAdmin)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-xs text-amber-800">
                            <div class="flex items-start gap-2">
                                <svg class="h-4 w-4 flex-shrink-0 text-amber-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="12"/>
                                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                                </svg>
                                <div>
                                    <span class="font-bold">Percobaan Belum Dilakukan:</span>
                                    Siklus harus memiliki minimal 1 kali percobaan kontak sebelum dapat diselesaikan oleh staf operasional. Silakan catat percobaan follow-up terlebih dahulu.
                                </div>
                            </div>
                        </div>
                    @else
                        @if ($attemptCount === 0 && $isAdmin)
                            <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
                                <div class="flex items-start gap-2">
                                    <svg class="h-4 w-4 flex-shrink-0 text-blue-600 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <line x1="12" y1="16" x2="12" y2="12"/>
                                        <line x1="12" y1="8" x2="12.01" y2="8"/>
                                    </svg>
                                    <div>
                                        <span class="font-bold">Override Administrator:</span>
                                        Siklus belum memiliki riwayat percobaan kontak. Menyelesaikan siklus ini memerlukan alasan atau catatan keputusan yang jelas.
                                    </div>
                                </div>
                            </div>
                        @endif

                        <form method="POST" action="{{ route($routePrefix . '.complete', $cycle) }}" class="space-y-4">
                            @csrf

                            <div>
                                <label for="final_result" class="block text-xs font-semibold text-slate-700">
                                    Keputusan / Hasil Akhir <span class="text-rose-500">*</span>
                                </label>
                                <select
                                    id="final_result"
                                    name="final_result"
                                    required
                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2 text-xs text-slate-800 focus:border-emerald-500 focus:outline-none"
                                >
                                    <option value="">-- Pilih Keputusan Akhir --</option>
                                    <option value="willing">Bersedia Bekerja Sama / Siap Kirim Pemagang</option>
                                    <option value="declined">Menolak Bekerja Sama</option>
                                    <option value="cooperation_stopped">Kerja Sama Dihentikan</option>
                                    @if ($attemptCount >= 3)
                                        <option value="no_response">Tidak Ada Respons (Tutup Siklus Setelah 3 Percobaan)</option>
                                    @else
                                        <option value="no_response" disabled class="text-slate-400">Tidak Ada Respons (Hanya setelah 3 kali percobaan kontak)</option>
                                    @endif
                                </select>
                            </div>

                            <div>
                                <label for="final_notes" class="block text-xs font-semibold text-slate-700">
                                    Catatan Keputusan Akhir {{ ($attemptCount === 0 && $isAdmin) ? '(Wajib Diisi untuk Override)' : '(Opsional)' }}
                                    @if ($attemptCount === 0 && $isAdmin)
                                        <span class="text-rose-500">*</span>
                                    @endif
                                </label>
                                <textarea
                                    id="final_notes"
                                    name="final_notes"
                                    rows="3"
                                    {{ ($attemptCount === 0 && $isAdmin) ? 'required' : '' }}
                                    placeholder="Rangkuman kesepakatan, jadwal pengiriman siswa, alasan penolakan, atau alasan override admin..."
                                    class="mt-1 w-full rounded-lg border border-slate-300 p-2.5 text-xs text-slate-800 focus:border-emerald-500 focus:outline-none"
                                ></textarea>
                            </div>

                            <div class="flex items-center justify-end pt-3 border-t border-slate-100">
                                <button
                                    type="submit"
                                    onclick="return confirm('Apakah Anda yakin ingin menyelesaikan siklus follow-up ini?')"
                                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                    Selesaikan & Tutup Siklus
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @else
                <!-- Banner Siklus Selesai / Closed -->
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-start gap-3">
                        <div class="rounded-lg bg-emerald-100 p-2 text-emerald-700">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                                <polyline points="22 4 12 14.01 9 11.01"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-sm font-bold text-slate-800">Siklus Ini Telah Selesai</h3>
                            <p class="mt-1 text-xs text-slate-600">
                                Keputusan Akhir: <span class="font-bold text-slate-800">{{ $cycle->finalResultLabel() }}</span>
                            </p>
                            @if ($cycle->final_notes)
                                <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-700">
                                    <span class="font-semibold text-slate-500 block mb-0.5">Catatan Akhir:</span>
                                    {{ $cycle->final_notes }}
                                </div>
                            @endif
                            <div class="mt-3 text-[11px] text-slate-400">
                                Dikonfirmasi pada {{ $cycle->confirmed_at ? $cycle->confirmed_at->format('d M Y H:i') : '-' }} oleh {{ $cycle->confirmer->name ?? 'Sistem' }}
                            </div>
                        </div>
                    </div>

                    @if ($cycle->final_result === \App\Models\FollowUpCycle::RESULT_WILLING)
                        <div class="mt-4 rounded-xl border border-purple-200 bg-white p-4 shadow-xs">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="h-2 w-2 rounded-full bg-purple-600"></span>
                                        <h4 class="text-xs font-bold text-slate-800">Mitra Menyatakan Kesediaan Kerja Sama</h4>
                                    </div>
                                    <p class="text-xs text-slate-600 mt-1">Siklus ini telah memenuhi syarat untuk penerbitan naskah dokumen MOU kemitraan resmi.</p>
                                </div>
                                <a
                                    href="{{ route('hr.mous.create', ['institution_id' => $cycle->institution_id, 'cycle_id' => $cycle->id]) }}"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-purple-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-purple-700 transition"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                    Buat Draft Dokumen MOU
                                </a>
                            </div>
                        </div>

                        @if ($cycle->mous->isNotEmpty())
                            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-4">
                                <h4 class="text-xs font-bold text-slate-800 mb-2">Dokumen MOU yang Dihasilkan dari Siklus Ini:</h4>
                                <div class="divide-y divide-slate-100">
                                    @foreach ($cycle->mous as $mou)
                                        <div class="py-2.5 first:pt-0 last:pb-0 flex items-center justify-between gap-2">
                                            <div>
                                                <a href="{{ route('hr.mous.show', $mou) }}" class="font-bold text-xs text-blue-600 hover:underline">
                                                    {{ $mou->company_mou_number }}
                                                </a>
                                                <p class="text-[11px] text-slate-500">Dibuat: {{ $mou->created_at?->format('d M Y') }}</p>
                                            </div>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold border {{ $mou->status_badge_classes }}">
                                                {{ $mou->status_label }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                </div>
            @endif
        </div>

        <!-- Col Right (1 Col): Linked Interns -->
        <div class="space-y-6">
            <!-- Pemagang Terkait -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-sm font-bold text-slate-800">Pemagang Terkait</h3>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                        {{ $interns->count() }} Data
                    </span>
                </div>

                @if ($interns->isEmpty())
                    <p class="py-6 text-center text-xs text-slate-400">
                        Belum ada pemagang yang terhubung langsung dengan kontak pembimbing ini.
                    </p>
                @else
                    <div class="divide-y divide-slate-100">
                        @foreach ($interns as $intern)
                            <div class="py-3 first:pt-0 last:pb-0">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-slate-800">{{ $intern->fullname }}</span>
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600">
                                        Tahun {{ $intern->pivot->relation_year ?? $cycle->cycle_year }}
                                    </span>
                                </div>
                                <div class="mt-1 flex items-center gap-2 text-[11px] text-slate-500">
                                    <span>{{ $intern->division->name ?? 'Divisi Umum' }}</span>
                                    <span>&bull;</span>
                                    <span>{{ $intern->internship_status ?? 'Pendaftar' }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@if ($cycle->isLockedBy(auth()->user()))
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const heartbeatUrl = "{{ route($routePrefix . '.heartbeat', $cycle) }}";
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') 
            || "{{ csrf_token() }}";

        // Jalankan heartbeat setiap 4 menit untuk memperpanjang kunci 15 menit
        const heartbeatInterval = setInterval(function () {
            fetch(heartbeatUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ duration: 15 })
            })
            .then(function (response) {
                if (response.status === 409) {
                    clearInterval(heartbeatInterval);
                    alert('Kunci tugas Anda telah kedaluwarsa atau dilepaskan oleh Administrator. Halaman akan dimuat ulang.');
                    window.location.reload();
                }
                return response.json();
            })
            .catch(function (err) {
                console.warn('Heartbeat lock renew failed:', err);
            });
        }, 240000); // 4 menit
    });
</script>
@endif
@endsection
