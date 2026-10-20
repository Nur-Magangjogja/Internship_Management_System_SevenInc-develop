@props([
    'title'  => 'Form Utama',
    'fields' => collect(),
    'types'  => [],
    'group'  => 'main',
])

<div class="rounded-[12px] border border-[#DCE7E1] bg-white shadow-sm overflow-hidden">
    <div class="flex items-center justify-between border-b border-[#DCE7E1] px-5 py-3.5">
        <div>
            <h2 class="text-[14px] font-bold text-[#1B3A34]">{{ $title }}</h2>
            <p class="text-[11.5px] text-[#4B5F5A] mt-0.5">
                {{ $fields->where('is_active', true)->count() }} aktif,
                {{ $fields->where('is_active', false)->count() }} nonaktif
                · {{ $fields->count() }} total
            </p>
        </div>
        <span class="text-[11px] text-[#4B5F5A]">Geser <span class="font-bold">☰</span> untuk urut ulang</span>
    </div>

    @if($fields->isEmpty())
    <div class="px-5 py-8 text-center text-[13px] text-[#4B5F5A]">
        Belum ada field di grup ini.
    </div>
    @else
    <ul id="field-list-{{ $group }}" class="divide-y divide-[#F4F8F6]">
        @foreach($fields as $field)
        @php
            $isDeletable = $field->isDeletable();
        @endphp
        <li id="field-row-{{ $field->id }}"
            data-field-id="{{ $field->id }}"
            data-is-active="{{ $field->is_active ? '1' : '0' }}"
            class="flex items-center gap-3 px-4 py-3 transition hover:bg-[#F4F8F6] {{ !$field->is_active ? 'opacity-50' : '' }}">

            {{-- Drag handle --}}
            <span class="drag-handle cursor-grab text-[#DCE7E1] hover:text-[#2D8659] shrink-0 select-none" title="Geser untuk urut ulang">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/>
                    <line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
                </svg>
            </span>

            {{-- Info field --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-[13.5px] font-semibold {{ $field->is_active ? 'text-[#1B3A34]' : 'text-[#4B5F5A] line-through' }}">
                        {{ $field->label }}
                    </span>
                    @if($field->is_system)
                    <span class="inline-flex items-center rounded-full bg-blue-50 border border-blue-100 px-1.5 py-0.5 text-[10px] font-semibold text-blue-600">
                        Sistem
                    </span>
                    @endif
                    @if($isDeletable)
                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-[10px] font-semibold text-amber-700" title="Pertanyaan ini masih bersifat sementara (belum dipublikasikan) sehingga masih dapat dihapus">
                        Sementara (Belum Publish)
                    </span>
                    @else
                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 border border-slate-200 px-2 py-0.5 text-[10px] font-medium text-slate-600" title="Pertanyaan ini sudah dipublikasikan dan tidak dapat dihapus (hanya dapat diedit)">
                        Terpublikasi
                    </span>
                    @endif
                </div>
                <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                    <span class="text-[11px] text-[#4B5F5A]">
                        {{ $types[$field->field_type] ?? $field->field_type }}
                    </span>
                    <span class="text-[#DCE7E1]">·</span>
                    <span class="text-[11px] text-[#4B5F5A]">
                        {{ $field->column_span === 2 ? 'Setengah kolom' : 'Penuh' }}
                    </span>
                    @if($field->field_key)
                    <span class="text-[#DCE7E1]">·</span>
                    <span class="font-mono text-[10px] text-[#4B5F5A] bg-[#F4F8F6] px-1.5 py-0.5 rounded">
                        {{ $field->field_key }}
                    </span>
                    @endif
                </div>
            </div>

            {{-- Status badge --}}
            <div class="shrink-0 hidden sm:block">
                @if($field->is_active)
                <span class="inline-flex items-center gap-1 rounded-full bg-[#E8F5E9] px-2 py-0.5 text-[10px] font-semibold text-[#1F5F3F] border border-[#A5D6A7]">
                    <span class="h-1.5 w-1.5 rounded-full bg-[#388E3C]"></span>Aktif
                </span>
                @else
                <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500 border border-gray-200">
                    <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>Nonaktif
                </span>
                @endif
            </div>

            {{-- Aksi --}}
            <div class="flex items-center gap-1 shrink-0">
                {{-- Edit --}}
                <button type="button" title="Edit Pertanyaan"
                    onclick="openEditModal({{ $field->id }})"
                    class="flex h-7 w-7 items-center justify-center rounded-[7px] border border-[#DCE7E1] bg-white text-[#4B5F5A] transition hover:border-amber-400 hover:text-amber-600">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>

                {{-- Toggle Wajib / Opsional --}}
                <button type="button"
                    id="btn-req-{{ $field->id }}"
                    title="{{ $field->is_required ? 'Jadikan Opsional' : 'Jadikan Wajib' }}"
                    onclick="toggleRequired({{ $field->id }})"
                    class="flex h-7 items-center gap-1 rounded-[7px] border px-1.5 transition text-[10px] font-bold
                        {{ $field->is_required
                            ? 'border-red-200 bg-red-50 text-red-600 hover:bg-red-500 hover:text-white'
                            : 'border-gray-200 bg-gray-50 text-gray-400 hover:border-[#2D8659] hover:text-[#2D8659]' }}">
                    <span id="req-label-{{ $field->id }}">{{ $field->is_required ? '*wajib' : 'opsional' }}</span>
                </button>

                {{-- Toggle Aktif --}}
                <button type="button" title="{{ $field->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                    onclick="toggleField({{ $field->id }})"
                    class="flex h-7 w-7 items-center justify-center rounded-[7px] border transition
                        {{ $field->is_active
                            ? 'border-orange-200 bg-orange-50 text-orange-600 hover:bg-orange-500 hover:text-white'
                            : 'border-[#A5D6A7] bg-[#E8F5E9] text-[#1F5F3F] hover:bg-[#2D8659] hover:text-white' }}">
                    @if($field->is_active)
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                    @else
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                    @endif
                </button>

                {{-- Hapus: HANYA bisa menghapus field yang belum pernah dipublikasikan (masih sementara/draft) --}}
                @if($isDeletable)
                <button type="button" title="Hapus pertanyaan sementara (belum dipublikasikan)"
                    onclick="deleteField({{ $field->id }}, {{ json_encode($field->label) }})"
                    class="flex h-7 w-7 items-center justify-center rounded-[7px] border border-red-200 bg-red-50 text-[#D32F2F] transition hover:bg-red-500 hover:text-white">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                </button>
                @else
                <button type="button" disabled title="Pertanyaan yang sudah dipublikasikan tidak dapat dihapus (hanya dapat diedit)"
                    class="flex h-7 w-7 items-center justify-center rounded-[7px] border border-gray-200 bg-gray-100 text-gray-300 cursor-not-allowed">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </button>
                @endif
            </div>
        </li>
        @endforeach
    </ul>
    @endif
</div>
