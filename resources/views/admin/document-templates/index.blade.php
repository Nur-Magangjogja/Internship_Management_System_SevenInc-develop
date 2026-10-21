@extends('layouts.dashboard', ['title' => 'Manajemen Template Dokumen & Sertifikat'])

@section('content')
<div class="px-6 py-8">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-blue-600 mb-1">
                <span>Modul Dokumen Resmi</span>
                <span>&bull;</span>
                <span>SERTIF Seven Inc</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Manajemen Template Dokumen & Sertifikat</h1>
            <p class="text-sm text-slate-500">Kelola dan kustomisasi visual format LOA, SKL, Sertifikat Magang, Penilaian, dan Rekomendasi tanpa mengubah kode Blade.</p>
        </div>
        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.document-templates.create') }}"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Buat Template Baru
            </a>
        </div>
    </div>

    <!-- Flash Messages -->
    @if (session('success'))
        <div class="mb-6 flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-800">&times;</button>
        </div>
    @endif
    @if (session('error'))
        <div class="mb-6 flex items-center justify-between rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>{{ session('error') }}</span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-800">&times;</button>
        </div>
    @endif

    <!-- Stat Cards Overview (6 Jenis Dokumen Kanonikal + Tab Semua) -->
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
        <a href="{{ route('admin.document-templates.index') }}" class="group block rounded-xl border {{ $currentType === 'all' ? 'border-blue-500 ring-2 ring-blue-100 bg-blue-50/30' : 'border-slate-200 bg-white' }} p-4 shadow-sm transition hover:border-blue-300">
            <div class="text-xs font-semibold text-slate-500">Semua Template</div>
            <div class="mt-2 text-2xl font-bold text-slate-800 group-hover:text-blue-600">{{ $counts['total'] }}</div>
            <div class="mt-1 text-xs text-slate-400">{{ $counts['active'] }} template aktif</div>
        </a>
        <a href="{{ route('admin.document-templates.index', ['type' => 'mou']) }}" class="group block rounded-xl border {{ $currentType === 'mou' ? 'border-cyan-500 ring-2 ring-cyan-100 bg-cyan-50/30' : 'border-slate-200 bg-white' }} p-4 shadow-sm transition hover:border-cyan-300">
            <div class="text-xs font-semibold text-cyan-700">MOU</div>
            <div class="mt-2 text-2xl font-bold text-slate-800 group-hover:text-cyan-600">{{ $counts['by_type']['mou'] ?? ($counts['mou'] ?? 0) }}</div>
            <div class="mt-1 text-xs text-slate-400">Nota Kesepahaman</div>
        </a>
        <a href="{{ route('admin.document-templates.index', ['type' => 'loa']) }}" class="group block rounded-xl border {{ $currentType === 'loa' ? 'border-blue-500 ring-2 ring-blue-100 bg-blue-50/30' : 'border-slate-200 bg-white' }} p-4 shadow-sm transition hover:border-blue-300">
            <div class="text-xs font-semibold text-blue-700">LOA</div>
            <div class="mt-2 text-2xl font-bold text-slate-800 group-hover:text-blue-600">{{ $counts['by_type']['loa'] ?? ($counts['loa'] ?? 0) }}</div>
            <div class="mt-1 text-xs text-slate-400">Surat Penerimaan</div>
        </a>
        <a href="{{ route('admin.document-templates.index', ['type' => 'skl']) }}" class="group block rounded-xl border {{ $currentType === 'skl' ? 'border-emerald-500 ring-2 ring-emerald-100 bg-emerald-50/30' : 'border-slate-200 bg-white' }} p-4 shadow-sm transition hover:border-emerald-300">
            <div class="text-xs font-semibold text-emerald-700">SKL</div>
            <div class="mt-2 text-2xl font-bold text-slate-800 group-hover:text-emerald-600">{{ $counts['by_type']['skl'] ?? ($counts['skl'] ?? 0) }}</div>
            <div class="mt-1 text-xs text-slate-400">Ket. Lulus/Selesai</div>
        </a>
        <a href="{{ route('admin.document-templates.index', ['type' => 'certificate']) }}" class="group block rounded-xl border {{ $currentType === 'certificate' ? 'border-amber-500 ring-2 ring-amber-100 bg-amber-50/30' : 'border-slate-200 bg-white' }} p-4 shadow-sm transition hover:border-amber-300">
            <div class="text-xs font-semibold text-amber-700">Sertifikat</div>
            <div class="mt-2 text-2xl font-bold text-slate-800 group-hover:text-amber-600">{{ $counts['by_type']['certificate'] ?? ($counts['certificate'] ?? 0) }}</div>
            <div class="mt-1 text-xs text-slate-400">Visual Canvas</div>
        </a>
        <a href="{{ route('admin.document-templates.index', ['type' => 'assessment']) }}" class="group block rounded-xl border {{ $currentType === 'assessment' ? 'border-purple-500 ring-2 ring-purple-100 bg-purple-50/30' : 'border-slate-200 bg-white' }} p-4 shadow-sm transition hover:border-purple-300">
            <div class="text-xs font-semibold text-purple-700">Penilaian</div>
            <div class="mt-2 text-2xl font-bold text-slate-800 group-hover:text-purple-600">{{ $counts['by_type']['assessment'] ?? ($counts['assessment'] ?? 0) }}</div>
            <div class="mt-1 text-xs text-slate-400">Transkrip Evaluasi</div>
        </a>
        <a href="{{ route('admin.document-templates.index', ['type' => 'recommendation']) }}" class="group block rounded-xl border {{ $currentType === 'recommendation' ? 'border-indigo-500 ring-2 ring-indigo-100 bg-indigo-50/30' : 'border-slate-200 bg-white' }} p-4 shadow-sm transition hover:border-indigo-300">
            <div class="text-xs font-semibold text-indigo-700">Rekomendasi</div>
            <div class="mt-2 text-2xl font-bold text-slate-800 group-hover:text-indigo-600">{{ $counts['by_type']['recommendation'] ?? ($counts['recommendation'] ?? 0) }}</div>
            <div class="mt-1 text-xs text-slate-400">Surat Referensi</div>
        </a>
    </div>

    <!-- Peringatan Ketiadaan Template Aktif (Fail-Closed Visibility Guard) -->
    @if (!empty($zeroActiveTypes) && ($currentType === 'all' || in_array($currentType, $zeroActiveTypes, true)))
        <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 shadow-sm">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <div>
                    <p class="font-bold">Peringatan: Belum ada template aktif untuk jenis dokumen {{ implode(', ', array_map(fn($t) => strtoupper($t), $zeroActiveTypes)) }}</p>
                    <p class="mt-0.5 text-xs text-amber-800">
                        Penerbitan resmi dokumen untuk jenis tersebut <strong>tidak tersedia dan ditolak oleh sistem</strong> sampai minimal satu template berstatus aktif ditetapkan sebagai default.
                    </p>
                </div>
            </div>
        </div>
    @endif

    <!-- Filter & Search Toolbar -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <form method="GET" action="{{ route('admin.document-templates.index') }}" class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div class="flex flex-1 flex-wrap items-center gap-3">
                <!-- Search Box -->
                <div class="relative min-w-[220px] flex-1 max-w-md">
                    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </span>
                    <input
                        type="text"
                        name="search"
                        value="{{ $searchQuery }}"
                        placeholder="Cari nama atau deskripsi template..."
                        class="w-full rounded-lg border border-slate-300 py-2 pl-9 pr-3 text-sm text-slate-700 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                </div>

                <!-- Tipe Filter -->
                <select
                    name="type"
                    onchange="this.form.submit()"
                    class="rounded-lg border border-slate-300 py-2 pl-3 pr-8 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                    <option value="all" {{ $currentType === 'all' ? 'selected' : '' }}>Semua Tipe Dokumen</option>
                    @foreach ($documentTypes as $key => $label)
                        <option value="{{ $key }}" {{ $currentType === $key ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <!-- Status Filter -->
                <select
                    name="status"
                    onchange="this.form.submit()"
                    class="rounded-lg border border-slate-300 py-2 pl-3 pr-8 text-sm text-slate-700 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                    <option value="all" {{ $currentStatus === 'all' ? 'selected' : '' }}>Semua Status</option>
                    <option value="active" {{ $currentStatus === 'active' ? 'selected' : '' }}>Hanya Aktif</option>
                    <option value="draft" {{ $currentStatus === 'draft' ? 'selected' : '' }}>Hanya Draf</option>
                    <option value="default" {{ $currentStatus === 'default' ? 'selected' : '' }}>Hanya Default</option>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="submit"
                    class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-900"
                >
                    Filter
                </button>
                @if ($currentType !== 'all' || $currentStatus !== 'all' || !empty($searchQuery))
                    <a
                        href="{{ route('admin.document-templates.index') }}"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    >
                        Reset
                    </a>
                @endif
            </div>
        </form>
    </div>

    <!-- Template Cards Grid -->
    @if ($templates->count() > 0)
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($templates as $item)
                <div class="flex flex-col justify-between rounded-xl border {{ $item->is_default ? 'border-amber-300 ring-2 ring-amber-100 bg-gradient-to-b from-amber-50/20 to-white' : 'border-slate-200 bg-white' }} p-5 shadow-sm transition hover:shadow-md">
                    <div>
                        <!-- Top Badges -->
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $item->getTypeBadgeClass($item->type) }}">
                                {{ $item->getTypeLabel($item->type) }}
                            </span>

                            <div class="flex items-center gap-1.5">
                                @if ($item->is_default)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800">
                                        <svg class="h-3 w-3 fill-amber-500" viewBox="0 0 24 24">
                                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                        </svg>
                                        DEFAULT
                                    </span>
                                @endif

                                @if ($item->is_active)
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">
                                        Aktif
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">
                                        Draf
                                    </span>
                                @endif

                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-mono font-medium text-slate-600">
                                    v{{ $item->version_number }}
                                </span>
                            </div>
                        </div>

                        <!-- Title & Description -->
                        <h3 class="text-base font-bold text-slate-800">
                            <a href="{{ $item->edit_url ?? route('admin.document-templates.edit', $item) }}" class="hover:text-blue-600 transition">
                                {{ $item->name }}
                            </a>
                        </h3>
                        <p class="mt-1 line-clamp-2 text-xs text-slate-500">
                            {{ $item->description ?: 'Tidak ada keterangan tambahan untuk template ini.' }}
                        </p>

                        <!-- Meta Info -->
                        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-400">
                            <span class="inline-flex items-center gap-1">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="16" y1="2" x2="16" y2="6"/>
                                    <line x1="8" y1="2" x2="8" y2="6"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                Diperbarui {{ is_object($item->updated_at) ? $item->updated_at->diffForHumans() : ($item->updated_at_human ?? 'Baru saja') }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                                {{ $item->updater_name ?? ($item->updater?->name ?? 'Admin') }}
                            </span>
                            <span>
                                Editor: <strong class="text-slate-600">{{ $item->editor_type === 'visual_canvas' ? 'Visual Canvas' : 'Word A4' }}</strong>
                            </span>
                        </div>
                    </div>

                    <!-- Card Actions -->
                    <div class="mt-5 border-t border-slate-100 pt-3">
                        <div class="flex items-center justify-between gap-2">
                            <!-- Main action: Edit -->
                            <a
                                href="{{ $item->edit_url ?? route('admin.document-templates.edit', $item) }}"
                                class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-100"
                            >
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 20h9"/>
                                    <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>
                                </svg>
                                Edit Visual
                            </a>

                            <!-- Preview Dropdown / Trigger -->
                            <a
                                href="{{ $item->preview_url ?? route('admin.document-templates.preview', $item) }}"
                                target="_blank"
                                title="Lihat Preview Dokumen"
                                class="rounded-lg border border-slate-200 p-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-blue-600"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </a>

                            <!-- Real DomPDF preview -->
                            <a
                                href="{{ $item->preview_pdf_url ?? route('admin.document-templates.preview-pdf', $item) }}"
                                target="_blank"
                                title="Download / Preview PDF Asli"
                                class="rounded-lg border border-slate-200 p-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-rose-600"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="16" y1="13" x2="8" y2="13"/>
                                    <line x1="16" y1="17" x2="8" y2="17"/>
                                    <polyline points="10 9 9 9 8 9"/>
                                </svg>
                            </a>

                            <!-- Versions History -->
                            <a
                                href="{{ $item->versions_url ?? route('admin.document-templates.versions', $item) }}"
                                title="Riwayat Versi & Audit Log"
                                class="rounded-lg border border-slate-200 p-1.5 text-slate-600 transition hover:bg-slate-50 hover:text-purple-600"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <polyline points="12 6 12 12 14 14"/>
                                </svg>
                            </a>

                            <!-- More Actions Menu -->
                            <div class="relative" x-data="{ open: false }">
                                <button
                                    type="button"
                                    @click="open = !open"
                                    class="rounded-lg border border-slate-200 p-1.5 text-slate-600 transition hover:bg-slate-50"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="1"/>
                                        <circle cx="12" cy="5" r="1"/>
                                        <circle cx="12" cy="19" r="1"/>
                                    </svg>
                                </button>

                                <div
                                    x-show="open"
                                    @click.away="open = false"
                                    x-cloak
                                    class="absolute right-0 bottom-full mb-1 z-20 w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                                >
                                    <!-- Duplikasi -->
                                    <form method="POST" action="{{ $item->duplicate_url ?? route('admin.document-templates.duplicate', $item) }}">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                                        >
                                            <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                            </svg>
                                            Gandakan (Salin)
                                        </button>
                                    </form>

                                    <!-- Tetapkan Default -->
                                    @if (!$item->is_default)
                                        <form method="POST" action="{{ $item->set_default_url ?? route('admin.document-templates.set-default', $item) }}">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-amber-700 hover:bg-amber-50"
                                            >
                                                <svg class="h-3.5 w-3.5 text-amber-500 fill-amber-500" viewBox="0 0 24 24">
                                                    <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                                                </svg>
                                                Jadikan Default
                                            </button>
                                        </form>
                                    @endif

                                    <!-- Toggle Active -->
                                    @if (!$item->is_default)
                                        <form method="POST" action="{{ $item->toggle_active_url ?? route('admin.document-templates.toggle-active', $item) }}">
                                            @csrf
                                            <button
                                                type="submit"
                                                class="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50"
                                            >
                                                <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <path d="m4.93 4.93 14.14 14.14"/>
                                                </svg>
                                                {{ $item->is_active ? 'Nonaktifkan (Draf)' : 'Aktifkan' }}
                                            </button>
                                        </form>
                                    @endif

                                    <!-- Delete (Hanya jika bukan default) -->
                                    @if (!$item->is_default)
                                        <div class="my-1 border-t border-slate-100"></div>
                                        <form
                                            method="POST"
                                            action="{{ $item->delete_url ?? route('admin.document-templates.destroy', $item) }}"
                                            onsubmit="return confirm('Apakah Anda yakin ingin menghapus template ini?')"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                class="flex w-full items-center gap-2 px-3 py-1.5 text-xs text-rose-600 hover:bg-rose-50"
                                            >
                                                <svg class="h-3.5 w-3.5 text-rose-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="3 6 5 6 21 6"/>
                                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                                </svg>
                                                Hapus
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-8">
            {{ $templates->links() }}
        </div>
    @else
        <!-- Empty State -->
        <div class="rounded-2xl border-2 border-dashed border-slate-200 bg-white p-12 text-center">
            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-blue-50 text-blue-600 mb-4">
                <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>
            </div>
            <h3 class="text-base font-bold text-slate-800">Belum Ada Template yang Sesuai</h3>
            <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">
                Belum ditemukan template dengan kriteria filter saat ini. Anda dapat membuat template baru atau mereset filter.
            </p>
            <div class="mt-6 flex justify-center gap-3">
                <a
                    href="{{ route('admin.document-templates.create') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 5v14M5 12h14"/>
                    </svg>
                    Buat Template Sekarang
                </a>
                @if ($currentType !== 'all' || $currentStatus !== 'all' || !empty($searchQuery))
                    <a
                        href="{{ route('admin.document-templates.index') }}"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        Hapus Filter
                    </a>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
