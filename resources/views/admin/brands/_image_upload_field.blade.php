@php
    $inputId = 'file_input_' . $name;
    $dropzoneId = 'dropzone_' . $name;
    $previewBoxId = 'preview_box_' . $name;
    $imgPreviewId = 'img_preview_' . $name;
    $filenameId = 'filename_' . $name;
    $filesizeId = 'filesize_' . $name;
    $currentBoxId = 'current_box_' . $name;
    $hasCurrent = !empty($current);
    $currentUrl = $hasCurrent ? Storage::url($current) : null;
    $isTransparent = $isTransparentSuggested ?? false;
@endphp

<div class="rounded-2xl border border-[#DCE7E1] bg-white p-4 shadow-2xs hover:border-[#2D8659]/60 hover:shadow-sm transition-all duration-200 flex flex-col justify-between" id="card_wrap_{{ $name }}">
    <div>
        {{-- Header Kartu Aset --}}
        <div class="flex items-start gap-2.5 mb-3">
            <div class="w-8 h-8 rounded-lg bg-[#E8F5E9] text-[#2D8659] border border-[#2D8659]/15 flex items-center justify-center shrink-0 mt-0.5">
                @if(($icon ?? '') === 'signature')
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                @elseif(($icon ?? '') === 'stamp')
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                    </svg>
                @elseif(($icon ?? '') === 'certificate')
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                    </svg>
                @elseif(($icon ?? '') === 'webinar')
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                @elseif(($icon ?? '') === 'card')
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2" />
                    </svg>
                @else
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <label for="{{ $inputId }}" class="block text-sm font-bold text-[#1B3A34] cursor-pointer hover:text-[#2D8659] transition">
                    {{ $label }}
                </label>
                <p class="text-[11px] text-[#4B5F5A] leading-tight mt-0.5">{{ $hint }}</p>
                <div class="mt-1.5 flex items-center gap-1.5">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-[#F4F8F6] text-[#4B5F5A] border border-[#DCE7E1]">
                        <svg class="w-3 h-3 text-[#2D8659]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ $badge }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Hidden Input Berkas Asli --}}
        <input type="file"
               name="{{ $name }}"
               id="{{ $inputId }}"
               accept="image/jpeg,image/png,image/jpg,image/webp"
               data-field="{{ $name }}"
               class="hidden brand-file-input">

        {{-- 1. Blok Aset Saat Ini (Edit Mode) --}}
        @if($hasCurrent)
            <input type="hidden" name="remove_{{ $name }}" id="remove_flag_{{ $name }}" value="0">
            <div id="{{ $currentBoxId }}" class="border border-[#DCE7E1] bg-[#F8FAF9] rounded-xl p-3 mb-1 transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-[#2D8659] bg-[#E8F5E9] px-2.5 py-0.5 rounded-full border border-[#2D8659]/20">
                        <span class="w-1.5 h-1.5 rounded-full bg-[#2D8659] animate-pulse"></span>
                        Aset Aktif
                    </span>
                    <a href="{{ $currentUrl }}" target="_blank" class="text-[11px] font-medium text-[#2D8659] hover:text-[#1F5F3F] hover:underline flex items-center gap-1 transition">
                        <span>Lihat Berkas</span>
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                    </a>
                </div>
                <div class="h-28 w-full bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden mb-2.5 relative {{ $isTransparent ? 'bg-[radial-gradient(#d1d5db_1px,transparent_1px)] [background-size:10px_10px]' : '' }}">
                    <img src="{{ $currentUrl }}" alt="{{ $label }}" class="max-h-full max-w-full object-contain">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button"
                            onclick="triggerBrandFileInput('{{ $name }}')"
                            class="py-2 px-2.5 rounded-lg border border-[#DCE7E1] bg-white text-xs font-semibold text-[#1B3A34] hover:bg-[#2D8659] hover:text-white hover:border-[#2D8659] transition flex items-center justify-center gap-1 shadow-2xs">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                        Ganti Berkas
                    </button>
                    <button type="button"
                            onclick="markBrandFileForRemoval('{{ $name }}')"
                            class="py-2 px-2.5 rounded-lg border border-red-200 bg-red-50/70 text-xs font-semibold text-red-600 hover:bg-red-600 hover:text-white hover:border-red-600 transition flex items-center justify-center gap-1 shadow-2xs">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                        Hapus (Bawaan)
                    </button>
                </div>
            </div>

            {{-- Kotak konfirmasi hapus / kembali ke bawaan sistem --}}
            <div id="removal_notice_{{ $name }}" class="hidden border border-amber-300 bg-amber-50/70 rounded-xl p-3 mb-1 transition text-center">
                <div class="flex items-center justify-center w-8 h-8 rounded-full bg-amber-100 text-amber-700 mx-auto mb-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </div>
                <p class="text-xs font-bold text-amber-900">Aset Ditandai untuk Dihapus</p>
                <p class="text-[11px] text-amber-700 mt-0.5 leading-snug">
                    @if(str_contains($name, 'certificate_bg'))
                        Sertifikat akan kembali menggunakan <strong>Border Emas Klasik (Bawaan)</strong> saat disimpan.
                    @else
                        Aset akan dihapus dan kembali menggunakan format bawaan sistem saat disimpan.
                    @endif
                </p>
                <div class="mt-2.5 flex items-center justify-center gap-2">
                    <button type="button"
                            onclick="cancelBrandFileRemoval('{{ $name }}')"
                            class="px-3 py-1 text-xs font-semibold rounded-md border border-amber-300 bg-white text-amber-800 hover:bg-amber-100 transition shadow-2xs">
                        Batalkan Penghapusan
                    </button>
                </div>
            </div>
        @endif

        {{-- 2. Dropzone Interaktif --}}
        <div id="{{ $dropzoneId }}"
             onclick="triggerBrandFileInput('{{ $name }}')"
             class="{{ $hasCurrent ? 'hidden' : '' }} group relative border-2 border-dashed border-[#DCE7E1] hover:border-[#2D8659] bg-[#F8FAF9] hover:bg-emerald-50/20 rounded-xl p-4 transition-all duration-200 cursor-pointer flex flex-col items-center justify-center text-center h-[138px]">
            <div class="w-9 h-9 rounded-full bg-white shadow-xs border border-[#DCE7E1] flex items-center justify-center text-[#2D8659] group-hover:scale-110 group-hover:bg-[#2D8659] group-hover:text-white transition-all duration-200 mb-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                </svg>
            </div>
            <p class="text-xs font-semibold text-[#1B3A34]">
                <span class="text-[#2D8659] underline underline-offset-2">Pilih berkas</span> atau seret ke sini
            </p>
            <p class="text-[10px] text-[#4B5F5A] mt-1">
                Format JPG, PNG, WEBP (Maks {{ $maxMb ?? 2 }}MB)
            </p>
        </div>

        {{-- 3. Pratinjau Berkas Baru Terpilih --}}
        <div id="{{ $previewBoxId }}" class="hidden border border-emerald-300 bg-emerald-50/30 rounded-xl p-3 transition">
            <div class="flex items-center justify-between mb-2">
                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-800 bg-emerald-100 px-2.5 py-0.5 rounded-full border border-emerald-300">
                    <svg class="w-3 h-3 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Berkas Baru Terpilih
                </span>
                <button type="button"
                        onclick="cancelBrandFileInput('{{ $name }}', {{ $hasCurrent ? 'true' : 'false' }})"
                        class="text-[11px] font-semibold text-red-600 hover:text-red-700 hover:underline flex items-center gap-1 transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    Batal
                </button>
            </div>
            <div class="h-28 w-full bg-white rounded-lg border border-[#DCE7E1] p-2 flex items-center justify-center overflow-hidden mb-2 relative {{ $isTransparent ? 'bg-[radial-gradient(#d1d5db_1px,transparent_1px)] [background-size:10px_10px]' : '' }}">
                <img id="{{ $imgPreviewId }}" src="" alt="Pratinjau Berkas Baru" class="max-h-full max-w-full object-contain">
            </div>
            <div class="flex items-center justify-between text-[11px] text-[#4B5F5A] pt-1 border-t border-emerald-100">
                <span id="{{ $filenameId }}" class="font-medium text-[#1B3A34] truncate max-w-[180px]">nama-berkas.png</span>
                <span id="{{ $filesizeId }}" class="font-mono text-xs text-[#2D8659] shrink-0 font-bold ml-2">0 KB</span>
            </div>
        </div>
    </div>

    {{-- Pesan Galat Sisi Klien (Ukuran / Tipe Berkas Ditolak) --}}
    <div id="client_error_{{ $name }}" class="hidden mt-2 p-2.5 rounded-lg bg-red-50 border border-red-200">
        <p class="text-xs text-red-600 font-medium flex items-start gap-1.5 leading-relaxed">
            <svg class="w-4 h-4 shrink-0 text-red-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
            <span id="client_error_msg_{{ $name }}"></span>
        </p>
    </div>

    @error($name)
        <div class="mt-2 p-2.5 rounded-lg bg-red-50 border border-red-200">
            <p class="text-xs text-red-600 font-medium flex items-start gap-1.5 leading-relaxed">
                <svg class="w-4 h-4 shrink-0 text-red-500 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
                <span>{{ $message }}</span>
            </p>
        </div>
    @enderror
</div>
