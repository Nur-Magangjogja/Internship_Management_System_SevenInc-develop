@extends('layouts.dashboard', ['title' => 'Perpustakaan Aset & Dokumen'])

@section('content')
<div class="px-6 py-8" x-data="{ uploadModalOpen: false, replaceModalOpen: false, activeAsset: null, copyToast: false }">
    <!-- Header -->
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Perpustakaan Aset & Dokumen</h1>
            <p class="text-sm text-slate-500">Pusat repositori logo, kop surat, tanda tangan, stempel, dan background terpusat untuk dokumen SERTIF.</p>
        </div>
        <div class="flex items-center gap-3">
            <form action="{{ route('admin.system-assets.sync-all-drive') }}" method="POST" onsubmit="return confirm('Mulai sinkronisasi seluruh aset ke folder Google Drive?')">
                @csrf
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    Sinkronkan Semua ke Drive
                </button>
            </form>

            <button
                type="button"
                @click="uploadModalOpen = true"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Upload Aset Baru
            </button>

            <a
                href="{{ route('admin.cloud-storage.index') }}"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.12 2.12-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V20.3h-3v-.09a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.88.34l-.06.06-2.12-2.12.06-.06A1.7 1.7 0 0 0 7.08 15a1.7 1.7 0 0 0-1.55-1H5.4v-3h.13A1.7 1.7 0 0 0 7.08 10a1.7 1.7 0 0 0-.34-1.88l-.06-.06L8.8 5.94l.06.06A1.7 1.7 0 0 0 10.74 6.34a1.7 1.7 0 0 0 1-1.55V4.7h3v.09a1.7 1.7 0 0 0 1 1.55A1.7 1.7 0 0 0 17.62 6l.06-.06 2.12 2.12-.06.06A1.7 1.7 0 0 0 19.4 10a1.7 1.7 0 0 0 1.55 1h.13v3h-.13A1.7 1.7 0 0 0 19.4 15Z"/>
                </svg>
                Pengaturan Drive Storage
            </a>
        </div>
    </div>

    <!-- Alert Notifications -->
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

    <!-- Search & Filter Bar -->
    <div class="mb-6 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <form method="GET" action="{{ route('admin.system-assets.index') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Cari Nama / Kode</label>
                <input
                    type="text"
                    name="search"
                    value="{{ request('search') }}"
                    placeholder="Nama aset..."
                    class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                />
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Kategori</label>
                <select name="category" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none">
                    <option value="">Semua Kategori</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>
                            {{ ucfirst($cat) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Scope</label>
                <select name="scope" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none">
                    <option value="">Semua Scope</option>
                    @foreach ($scopes as $sc)
                        <option value="{{ $sc }}" {{ request('scope') === $sc ? 'selected' : '' }}>
                            {{ ucfirst($sc) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Brand</label>
                <select name="brand_id" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 focus:border-blue-500 focus:outline-none">
                    <option value="">Semua Brand</option>
                    @foreach ($brands as $b)
                        <option value="{{ $b->id }}" {{ request('brand_id') == $b->id ? 'selected' : '' }}>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button
                    type="submit"
                    class="w-full rounded-lg bg-slate-800 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-900"
                >
                    Terapkan Filter
                </button>
                <a
                    href="{{ route('admin.system-assets.index') }}"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
                >
                    Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Assets Grid / Table -->
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-8">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-600">
                <thead class="border-b border-slate-200 bg-slate-50 font-bold uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Preview</th>
                        <th class="px-4 py-3">Nama & Variabel Referensi</th>
                        <th class="px-4 py-3">Kategori & Scope</th>
                        <th class="px-4 py-3">Ukuran & Versi</th>
                        <th class="px-4 py-3">Status Sinkronisasi</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($assets as $asset)
                        <tr class="hover:bg-slate-50/75 transition">
                            <!-- Preview Col -->
                            <td class="px-4 py-3">
                                <div class="h-12 w-16 shrink-0 rounded-lg border border-slate-200 bg-slate-100 overflow-hidden flex items-center justify-center p-1">
                                    @if ($asset->is_image)
                                        <img src="{{ route('admin.system-assets.preview', $asset) }}" alt="{{ $asset->name }}" class="max-h-full max-w-full object-contain" />
                                    @else
                                        <svg class="h-6 w-6 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/>
                                            <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>
                                        </svg>
                                    @endif
                                </div>
                            </td>

                            <!-- Nama & Code -->
                            <td class="px-4 py-3">
                                <div class="font-bold text-slate-800 text-sm flex items-center gap-2">
                                    {{ $asset->name }}
                                    @if ($asset->is_default)
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800">DEFAULT</span>
                                    @endif
                                    @if (!$asset->is_active)
                                        <span class="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-800">NONAKTIF</span>
                                    @endif
                                </div>
                                <div class="mt-1 flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        @click="navigator.clipboard.writeText('{{ $asset->variable_placeholder }}'); copyToast = true; setTimeout(() => copyToast = false, 2000)"
                                        class="inline-flex items-center gap-1 font-mono text-[11px] text-blue-600 bg-blue-50 px-2 py-0.5 rounded border border-blue-200 hover:bg-blue-100 transition"
                                        title="Klik untuk menyalin variabel"
                                    >
                                        {{ $asset->variable_placeholder }}
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect width="14" height="14" x="8" y="8" rx="2" ry="2"/>
                                            <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>

                            <!-- Kategori & Scope -->
                            <td class="px-4 py-3">
                                <span class="inline-block rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-medium text-slate-700 capitalize">
                                    {{ $asset->category }}
                                </span>
                                <div class="mt-1 text-[11px] text-slate-500">
                                    @if ($asset->scope === 'brand')
                                        Brand: <strong class="text-slate-700">{{ $asset->brand?->name ?? 'Semua' }}</strong>
                                    @elseif ($asset->scope === 'institution')
                                        Institusi: <strong class="text-slate-700">{{ $asset->institution?->name ?? 'Semua' }}</strong>
                                    @else
                                        Scope: <strong class="text-slate-700">Global Default</strong>
                                    @endif
                                </div>
                            </td>

                            <!-- Ukuran & Versi -->
                            <td class="px-4 py-3">
                                <div class="font-semibold text-slate-700">v{{ $asset->version }}</div>
                                <div class="text-[11px] text-slate-400">{{ $asset->formatted_size }}</div>
                                <div class="text-[10px] text-slate-400 font-mono">{{ substr($asset->checksum_sha256, 0, 10) }}...</div>
                            </td>

                            <!-- Sync Status -->
                            <td class="px-4 py-3">
                                @if ($asset->sync_status === 'synced')
                                    <span class="inline-flex items-center gap-1 text-emerald-700 font-semibold text-xs">
                                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                        Tersinkronisasi
                                    </span>
                                    @if ($asset->drive_web_view_link)
                                        <a href="{{ $asset->drive_web_view_link }}" target="_blank" class="block text-[11px] text-blue-600 hover:underline mt-0.5">
                                            Lihat di Drive &rarr;
                                        </a>
                                    @endif
                                @elseif ($asset->sync_status === 'failed')
                                    <span class="inline-flex items-center gap-1 text-rose-700 font-semibold text-xs">
                                        <span class="h-2 w-2 rounded-full bg-rose-500"></span>
                                        Gagal Sync
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-slate-500 font-semibold text-xs">
                                        <span class="h-2 w-2 rounded-full bg-slate-300"></span>
                                        Menunggu Sync
                                    </span>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <!-- Replace Version Button -->
                                    <button
                                        type="button"
                                        @click="activeAsset = {{ json_encode(['id' => $asset->id, 'name' => $asset->name, 'version' => $asset->version]) }}; replaceModalOpen = true"
                                        class="rounded px-2 py-1 text-xs font-semibold text-blue-600 hover:bg-blue-50"
                                        title="Ganti Versi Berkas"
                                    >
                                        Ganti Versi
                                    </button>

                                    <!-- Set Default Button -->
                                    @if (!$asset->is_default)
                                        <form action="{{ route('admin.system-assets.set-default', $asset) }}" method="POST">
                                            @csrf
                                            <button type="submit" class="rounded px-2 py-1 text-xs text-slate-600 hover:bg-slate-100" title="Jadikan Default">
                                                Default
                                            </button>
                                        </form>
                                    @endif

                                    <!-- Retry Sync Button -->
                                    <form action="{{ route('admin.system-assets.sync-drive', $asset) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="rounded px-2 py-1 text-xs text-emerald-600 hover:bg-emerald-50" title="Sinkronisasikan ke Drive">
                                            Sync
                                        </button>
                                    </form>

                                    <!-- Archive / Delete -->
                                    <form action="{{ route('admin.system-assets.destroy', $asset) }}" method="POST" onsubmit="return confirm('Arsipkan aset ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded px-2 py-1 text-xs text-rose-600 hover:bg-rose-50" title="Arsipkan">
                                            Arsip
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-slate-400">
                                Belum ada aset yang terdaftar dalam pustaka. Klik "Upload Aset Baru" untuk menambahkan logo, tanda tangan, atau stempel.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4 border-t border-slate-100">
            {{ $assets->links() }}
        </div>
    </div>

    <!-- Variable Registry Reference Drawer -->
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-base font-bold text-slate-800 mb-2">Panduan Variabel Mail Merge Terdaftar</h2>
        <p class="text-xs text-slate-500 mb-6">Variabel di bawah ini dapat disalin dan ditempel langsung ke dalam editor naskah MoU, LOA, SKL, atau Sertifikat.</p>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($groupedVariables as $groupName => $vars)
                <div class="rounded-lg border border-slate-100 bg-slate-50/50 p-4">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700 mb-3">{{ $groupName }}</h3>
                    <div class="space-y-2">
                        @foreach ($vars as $v)
                            <div class="flex items-center justify-between gap-2 text-xs">
                                <span class="text-slate-600 truncate">{{ $v['name'] }}</span>
                                <button
                                    type="button"
                                    @click="navigator.clipboard.writeText('{{ $v['token'] }}'); copyToast = true; setTimeout(() => copyToast = false, 2000)"
                                    class="shrink-0 font-mono text-[11px] text-blue-600 hover:text-blue-800 bg-white px-2 py-0.5 rounded border border-slate-200"
                                    title="Klik untuk menyalin"
                                >
                                    {{ $v['token'] }}
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <!-- Upload Modal -->
    <div
        x-show="uploadModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
        style="display: none;"
    >
        <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" @click.outside="uploadModalOpen = false">
            <h3 class="text-lg font-bold text-slate-800 mb-4">Unggah Aset Baru</h3>
            <form action="{{ route('admin.system-assets.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Nama Aset *</label>
                    <input type="text" name="name" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none" placeholder="cth: Logo Resmi Seven Inc 2026" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Kategori *</label>
                        <select name="category" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                            @foreach ($categories as $cat)
                                <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Scope Aset *</label>
                        <select name="scope" x-data="{ sc: 'global' }" x-model="sc" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                            <option value="global">Global Default</option>
                            <option value="brand">Brand</option>
                            <option value="institution">Institusi</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Brand (Opsional)</label>
                        <select name="brand_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                            <option value="">-- Pilih Brand --</option>
                            @foreach ($brands as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">Institusi (Opsional)</label>
                        <select name="institution_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                            <option value="">-- Pilih Institusi --</option>
                            @foreach ($institutions as $inst)
                                <option value="{{ $inst->id }}">{{ $inst->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Berkas * (PNG, JPG, SVG, PDF - Max 10MB)</label>
                    <input type="file" name="file" required class="w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-blue-700 hover:file:bg-blue-100" />
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" name="is_default" value="1" id="is_default" class="h-4 w-4 rounded border-slate-300 text-blue-600" />
                    <label for="is_default" class="text-xs font-medium text-slate-700">Tetapkan sebagai aset default untuk kategori/scope ini</label>
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" @click="uploadModalOpen = false" class="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                        Batal
                    </button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700">
                        Unggah & Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Replace Version Modal -->
    <div
        x-show="replaceModalOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
        style="display: none;"
    >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl" @click.outside="replaceModalOpen = false">
            <h3 class="text-lg font-bold text-slate-800 mb-2">Ganti Versi Berkas</h3>
            <p class="text-xs text-slate-500 mb-4" x-text="'Unggah berkas baru untuk ' + (activeAsset ? activeAsset.name : '') + '. Versi akan otomatis bertambah ke v' + (activeAsset ? (activeAsset.version + 1) : '') + '.'"></p>

            <form :action="'{{ url('admin/system-assets') }}/' + (activeAsset ? activeAsset.id : '') + '/replace-version'" method="POST" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Pilih Berkas Pengganti *</label>
                    <input type="file" name="file" required class="w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-blue-700 hover:file:bg-blue-100" />
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" @click="replaceModalOpen = false" class="rounded-lg border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                        Batal
                    </button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white hover:bg-blue-700">
                        Perbarui Versi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Floating Copy Toast -->
    <div
        x-show="copyToast"
        x-transition
        class="fixed bottom-6 right-6 z-50 rounded-lg bg-slate-900 px-4 py-2.5 text-xs font-medium text-white shadow-lg"
        style="display: none;"
    >
        Variabel berhasil disalin ke clipboard!
    </div>
</div>
@endsection
