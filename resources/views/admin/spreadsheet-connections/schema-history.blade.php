@extends('layouts.dashboard', ['title' => 'Riwayat & Log Versi Schema Spreadsheet'])

@section('content')
<div class="px-6 py-8">
    <!-- Breadcrumb & Top Action -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-sm text-slate-500 mb-1">
                <a href="{{ route('admin.spreadsheet-connections.index') }}" class="hover:text-blue-600 transition">Integrasi Spreadsheet</a>
                <span>&rarr;</span>
                <a href="{{ route('admin.spreadsheet-connections.show', $connection) }}" class="hover:text-blue-600 transition">{{ $connection->display_name }}</a>
                <span>&rarr;</span>
                <span class="text-slate-800 font-medium">Riwayat Versi Schema</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Riwayat & Log Versi Schema</h1>
            <p class="text-xs text-slate-500 mt-1">Audit log seluruh versi kontrak kolom outbound (aktif dan arsip terdahulu) untuk koneksi ini.</p>
        </div>
        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.spreadsheet-connections.show', $connection) }}"
                class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"/>
                    <polyline points="12 19 5 12 12 5"/>
                </svg>
                Kembali ke Detail Koneksi
            </a>
        </div>
    </div>

    <!-- Alert / Information Box -->
    @if (session('success'))
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-800 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-emerald-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-rose-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        </div>
    @endif

    <!-- Connection Summary Card -->
    <div class="mb-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 text-xs">
            <div>
                <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Koneksi Spreadsheet</span>
                <span class="inline-block mt-1 font-semibold text-slate-800 text-sm">
                    {{ $connection->display_name }}
                </span>
                <span class="block text-slate-500 font-mono mt-0.5">Key: {{ $connection->connection_key }}</span>
            </div>
            <div>
                <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Versi Aktif Saat Ini</span>
                <div class="mt-1 flex items-center gap-2">
                    @php
                        $activeVer = $schemaVersions->firstWhere('is_active', true);
                    @endphp
                    @if ($activeVer)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-md bg-emerald-100 text-emerald-800">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ $activeVer->schema_version }} (Tahun {{ $activeVer->year }})
                        </span>
                    @else
                        <span class="text-slate-400 italic">Belum ada versi aktif</span>
                    @endif
                </div>
            </div>
            <div>
                <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Total Log Versi</span>
                <div class="mt-1 flex items-center gap-1.5">
                    <span class="text-lg font-bold text-slate-800">{{ $schemaVersions->count() }}</span>
                    <span class="text-slate-500">versi tercatat</span>
                </div>
            </div>
            <div>
                <span class="font-medium text-slate-500 block uppercase tracking-wider text-[10px]">Target Google Sheets</span>
                @if ($connection->spreadsheet_url)
                    <a href="{{ $connection->spreadsheet_url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline font-mono truncate block mt-1">
                        Buka Spreadsheet &nearr;
                    </a>
                @else
                    <span class="text-slate-400 mt-1 block">-</span>
                @endif
            </div>
        </div>
        <div class="mt-4 pt-4 border-t border-slate-100 flex items-start gap-2 text-xs text-slate-500">
            <svg class="h-4 w-4 text-blue-500 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="16" x2="12" y2="12"/>
                <line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <p>
                <strong>Catatan Audit:</strong> Setiap kali formulir pendaftaran dipublikasikan dengan penyesuaian kolom baru, sistem secara otomatis mencatat versi baru dan mengarsipkan versi terdahulu. Riwayat ini dijaga agar Anda selalu dapat menelusuri kontrak kolom yang pernah digunakan pada waktu tertentu.
            </p>
        </div>
    </div>

    <!-- Daftar Log Versi Schema -->
    <div class="space-y-8">
        @forelse ($schemaVersions as $schema)
            <div class="rounded-xl border {{ $schema->is_active ? 'border-emerald-300 ring-1 ring-emerald-200' : 'border-slate-200' }} bg-white shadow-sm overflow-hidden">
                <!-- Schema Header Bar -->
                <div class="{{ $schema->is_active ? 'bg-emerald-50/70 border-emerald-200' : 'bg-slate-50/80 border-slate-200' }} px-6 py-4 border-b flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-lg {{ $schema->is_active ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700' }} font-bold text-sm">
                            {{ $schema->year }}
                        </span>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-bold text-slate-800 text-sm">Tab: [{{ $schema->sheet_tab_name }}]</h3>
                                <span class="px-2 py-0.5 text-[11px] font-mono rounded bg-slate-200 text-slate-800 font-semibold">
                                    {{ $schema->schema_version }}
                                </span>
                                @if ($schema->is_active)
                                    <span class="px-2.5 py-0.5 text-[11px] font-semibold rounded-full bg-emerald-100 text-emerald-800 border border-emerald-300 flex items-center gap-1">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                                        Aktif (Sedang Digunakan)
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 text-[11px] font-medium rounded-full bg-slate-100 text-slate-600 border border-slate-200">
                                        Arsip / Nonaktif
                                    </span>
                                @endif
                            </div>
                            @if ($schema->notes)
                                <p class="text-xs text-slate-500 mt-1">{{ $schema->notes }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-4 text-xs">
                        <div class="text-slate-500">
                            Waktu Tercatat:
                            <span class="font-mono font-medium text-slate-700">
                                {{ $schema->created_at ? $schema->created_at->format('d M Y H:i:s') : '-' }}
                            </span>
                        </div>
                        <div class="text-slate-500">
                            Total: <span class="font-bold text-slate-800">{{ $schema->columnMappings->count() }} Kolom</span>
                        </div>
                        @if (!$schema->is_active)
                            <form action="{{ route('admin.spreadsheet-connections.schemas.activate', [$connection, $schema]) }}" method="POST" onsubmit="return confirm('Aktifkan versi schema {{ $schema->schema_version }} untuk tahun {{ $schema->year }}? Versi lain pada tahun yang sama akan dinonaktifkan.');">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-white px-2.5 py-1 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-50">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="20 6 9 17 4 12"/>
                                    </svg>
                                    Aktifkan Versi Ini
                                </button>
                            </form>
                        @endif
                    </div>
                </div>

                <!-- Column Mappings Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-100/50 text-slate-600 font-semibold uppercase tracking-wider text-[10px] border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3 w-12 text-center">#</th>
                                <th class="px-4 py-3">Nama Header Spreadsheet (Teks Persis)</th>
                                <th class="px-4 py-3">Sumber Entitas / Field DB</th>
                                <th class="px-4 py-3">Tipe Data</th>
                                <th class="px-4 py-3">Arah</th>
                                <th class="px-4 py-3">Transformer</th>
                                <th class="px-4 py-3 text-center">Wajib</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700 font-sans">
                            @forelse ($schema->columnMappings as $mapping)
                                <tr class="hover:bg-slate-50/60 transition">
                                    <td class="px-4 py-2.5 text-center font-mono font-medium text-slate-400">
                                        {{ $mapping->column_order }}
                                    </td>
                                    <td class="px-4 py-2.5 font-medium text-slate-900">
                                        <code class="bg-slate-100 text-slate-800 px-1.5 py-0.5 rounded font-mono text-[11px] border border-slate-200">
                                            {{ $mapping->sheet_header }}
                                        </code>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span class="text-slate-500">{{ $mapping->target_entity }}</span> &rarr;
                                        <span class="font-mono font-semibold text-slate-800">{{ $mapping->target_field ?? '-' }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 font-mono text-slate-600">
                                        {{ $mapping->data_type }}
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @php
                                            $dirBadge = match($mapping->direction) {
                                                'system' => 'bg-purple-50 text-purple-700 border-purple-200',
                                                'display_only' => 'bg-amber-50 text-amber-700 border-amber-200',
                                                default => 'bg-blue-50 text-blue-700 border-blue-200',
                                            };
                                        @endphp
                                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold border {{ $dirBadge }}">
                                            {{ $mapping->direction }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 font-mono text-slate-500">
                                        {{ $mapping->transform_key ?? '-' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        @if ($mapping->is_required)
                                            <span class="inline-block h-2 w-2 rounded-full bg-emerald-500" title="Wajib Diisi"></span>
                                        @else
                                            <span class="inline-block h-2 w-2 rounded-full bg-slate-300" title="Opsional"></span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-6 text-center text-slate-400 italic">
                                        Belum ada pemetaan kolom untuk versi schema ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50/50 p-12 text-center">
                <svg class="mx-auto h-8 w-8 text-slate-400 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect width="18" height="18" x="3" y="3" rx="2"/>
                    <path d="M3 9h18M3 15h18M9 3v18"/>
                </svg>
                <p class="font-medium text-slate-700 text-sm">Belum Ada Riwayat Versi Schema</p>
                <p class="text-xs text-slate-500 mt-1">Versi schema akan tercatat otomatis saat form pendaftaran disinkronkan.</p>
            </div>
        @endforelse
    </div>
</div>
@endsection
