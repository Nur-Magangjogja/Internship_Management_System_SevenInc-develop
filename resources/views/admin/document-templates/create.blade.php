@extends('layouts.dashboard', ['title' => 'Buat Template Dokumen Baru'])

@section('content')
<div class="px-6 py-8 max-w-4xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <a href="{{ route('admin.document-templates.index') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-blue-600 transition mb-2">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Kembali ke Daftar Template
        </a>
        <h1 class="text-2xl font-bold text-slate-800">Buat Template Dokumen & Sertifikat Baru</h1>
        <p class="text-sm text-slate-500">Pilih jenis dokumen resmi dan tentukan draf awal yang akan dipersonalisasi di editor visual.</p>
    </div>

    <!-- Error Summary -->
    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800">
            <div class="font-bold mb-1">Mohon perbaiki kesalahan berikut:</div>
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.document-templates.store') }}" class="space-y-6">
        @csrf

        <!-- Card Jenis Dokumen -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-bold text-slate-800 mb-1">1. Pilih Jenis Dokumen</h2>
            <p class="text-xs text-slate-500 mb-4">Setiap jenis dokumen memiliki struktur, editor, dan placeholder variabel tersendiri.</p>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3">
                <!-- LOA -->
                <label class="relative flex flex-col justify-between rounded-xl border-2 p-4 cursor-pointer transition hover:border-blue-400 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/20">
                    <input type="radio" name="type" value="loa" class="sr-only" {{ old('type', $selectedType) === 'loa' ? 'checked' : '' }} onchange="updateEditorHint('loa')">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">
                                LOA
                            </span>
                            <span class="text-xs font-medium text-slate-400">A4 Portrait</span>
                        </div>
                        <div class="text-sm font-bold text-slate-800">Letter of Acceptance</div>
                        <p class="mt-1 text-xs text-slate-500">Surat penerimaan resmi peserta magang dengan kop surat dan tanda tangan.</p>
                    </div>
                    <div class="mt-3 text-[11px] font-semibold text-blue-600">Editor Dokumen A4 &rarr;</div>
                </label>

                <!-- SKL -->
                <label class="relative flex flex-col justify-between rounded-xl border-2 p-4 cursor-pointer transition hover:border-emerald-400 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50/20">
                    <input type="radio" name="type" value="skl" class="sr-only" {{ old('type', $selectedType) === 'skl' ? 'checked' : '' }} onchange="updateEditorHint('skl')">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">
                                SKL
                            </span>
                            <span class="text-xs font-medium text-slate-400">A4 Portrait</span>
                        </div>
                        <div class="text-sm font-bold text-slate-800">Surat Selesai Magang</div>
                        <p class="mt-1 text-xs text-slate-500">Keterangan resmi kelulusan / berakhirnya periode magang.</p>
                    </div>
                    <div class="mt-3 text-[11px] font-semibold text-emerald-600">Editor Dokumen A4 &rarr;</div>
                </label>

                <!-- Certificate -->
                <label class="relative flex flex-col justify-between rounded-xl border-2 p-4 cursor-pointer transition hover:border-amber-400 has-[:checked]:border-amber-600 has-[:checked]:bg-amber-50/20">
                    <input type="radio" name="type" value="certificate" class="sr-only" {{ old('type', $selectedType) === 'certificate' ? 'checked' : '' }} onchange="updateEditorHint('certificate')">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">
                                Sertifikat
                            </span>
                            <span class="text-xs font-medium text-slate-400">A4 Landscape</span>
                        </div>
                        <div class="text-sm font-bold text-slate-800">Sertifikat Kelulusan</div>
                        <p class="mt-1 text-xs text-slate-500">Desain visual piagam dengan latar belakang border emas & tanda tangan.</p>
                    </div>
                    <div class="mt-3 text-[11px] font-semibold text-amber-600">Visual Canvas Editor &rarr;</div>
                </label>

                <!-- Assessment -->
                <label class="relative flex flex-col justify-between rounded-xl border-2 p-4 cursor-pointer transition hover:border-purple-400 has-[:checked]:border-purple-600 has-[:checked]:bg-purple-50/20">
                    <input type="radio" name="type" value="assessment" class="sr-only" {{ old('type', $selectedType) === 'assessment' ? 'checked' : '' }} onchange="updateEditorHint('assessment')">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-semibold text-purple-800">
                                Penilaian
                            </span>
                            <span class="text-xs font-medium text-slate-400">A4 Portrait</span>
                        </div>
                        <div class="text-sm font-bold text-slate-800">Surat Penilaian Magang</div>
                        <p class="mt-1 text-xs text-slate-500">Transkrip evaluasi kompetensi teknis, kedisiplinan, dan etos kerja.</p>
                    </div>
                    <div class="mt-3 text-[11px] font-semibold text-purple-600">Editor Dokumen A4 &rarr;</div>
                </label>

                <!-- Recommendation -->
                <label class="relative flex flex-col justify-between rounded-xl border-2 p-4 cursor-pointer transition hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50/20">
                    <input type="radio" name="type" value="recommendation" class="sr-only" {{ old('type', $selectedType) === 'recommendation' ? 'checked' : '' }} onchange="updateEditorHint('recommendation')">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800">
                                Rekomendasi
                            </span>
                            <span class="text-xs font-medium text-slate-400">A4 Portrait</span>
                        </div>
                        <div class="text-sm font-bold text-slate-800">Surat Rekomendasi</div>
                        <p class="mt-1 text-xs text-slate-500">Surat referensi kerja / profesional untuk alumni peserta magang terbaik.</p>
                    </div>
                    <div class="mt-3 text-[11px] font-semibold text-indigo-600">Editor Dokumen A4 &rarr;</div>
                </label>
            </div>

            <!-- Pilihan Kategori Sertifikat (Magang vs Webinar) -->
            <div id="certificate-subtype-container" class="mt-5 rounded-xl border border-amber-200 bg-amber-50/40 p-4 {{ old('type', $selectedType) === 'certificate' ? '' : 'hidden' }}">
                <label class="block text-xs font-bold uppercase tracking-wider text-amber-900 mb-2">
                    Peruntukan / Kategori Sertifikat <span class="text-rose-500">*</span>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <label class="flex items-start gap-3 p-3.5 rounded-lg border-2 cursor-pointer transition bg-white border-slate-200 has-[:checked]:border-amber-600 has-[:checked]:bg-amber-50/40">
                        <input type="radio" name="certificate_type" value="magang" class="mt-1 accent-amber-600" {{ old('certificate_type', 'magang') === 'magang' ? 'checked' : '' }} onchange="onCertTypeChanged('magang')">
                        <div>
                            <span class="block text-xs font-bold text-slate-800">Sertifikat Magang (Internship)</span>
                            <span class="block text-[11px] text-slate-500 mt-0.5">Untuk kelulusan peserta magang pada divisi kerja tertentu. Menggunakan placeholder pemagang & divisi magang.</span>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3.5 rounded-lg border-2 cursor-pointer transition bg-white border-slate-200 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50/40">
                        <input type="radio" name="certificate_type" value="webinar" class="mt-1 accent-teal-600" {{ old('certificate_type') === 'webinar' ? 'checked' : '' }} onchange="onCertTypeChanged('webinar')">
                        <div>
                            <span class="block text-xs font-bold text-slate-800">Sertifikat Webinar (Workshop/Event)</span>
                            <span class="block text-[11px] text-slate-500 mt-0.5">Untuk peserta kegiatan webinar online. Menggunakan placeholder judul webinar & tanggal kegiatan.</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Dynamic Editor Notice -->
            <div id="editor-hint-container" class="mt-5 rounded-lg border border-blue-100 bg-blue-50/60 p-4 text-xs text-blue-900 flex items-start gap-3">
                <svg class="h-5 w-5 text-blue-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <div id="editor-hint-text">
                    Untuk <strong>Surat Resmi (LOA, SKL, Penilaian, Rekomendasi)</strong>, sistem menyediakan <strong>Editor Dokumen A4 Visual</strong> dengan dukungan tabel, margin, font standar surat resmi, dan Mail Merge tags.
                </div>
            </div>
        </div>

        <!-- Card Informasi Template -->
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-bold text-slate-800 mb-1">2. Informasi & Draf Awal</h2>
            <p class="text-xs text-slate-500 mb-4">Beri nama unik untuk mengenali template ini di dashboard administrasi.</p>

            <div class="space-y-4">
                <div>
                    <label for="template-name" class="block text-xs font-semibold text-slate-700 uppercase mb-1">Nama Template <span class="text-rose-500">*</span></label>
                    <input
                        type="text"
                        id="template-name"
                        name="name"
                        value="{{ old('name') }}"
                        placeholder="Contoh: Template Resmi LOA 2026 - Kemendikbud & Kampus Merdeka"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    />
                </div>

                <div>
                    <label for="template-desc" class="block text-xs font-semibold text-slate-700 uppercase mb-1">Deskripsi / Catatan Internal</label>
                    <textarea
                        id="template-desc"
                        name="description"
                        rows="2"
                        placeholder="Tuliskan catatan peruntukan template ini (mis: Digunakan untuk seluruh pemagang divisi Software & Multimedia)..."
                        class="w-full rounded-lg border border-slate-300 px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    >{{ old('description') }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 uppercase mb-2">Preset Konten Awal</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-3.5 cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/20">
                            <input type="radio" name="preset_type" value="standard" checked class="text-blue-600 focus:ring-blue-500">
                            <div>
                                <div class="text-sm font-semibold text-slate-800">Standar Resmi Seven Inc (Direkomendasikan)</div>
                                <div class="text-xs text-slate-500">Memuat teks baku resmi, kop surat, tabel terstruktur, dan penempatan Mail Merge siap pakai.</div>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 p-3.5 cursor-pointer hover:bg-slate-50 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/20">
                            <input type="radio" name="preset_type" value="blank" class="text-blue-600 focus:ring-blue-500">
                            <div>
                                <div class="text-sm font-semibold text-slate-800">Mulai dari Lembar Kosong</div>
                                <div class="text-xs text-slate-500">Kanvas atau lembar kosong bersih untuk perancangan format dari awal.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Pilihan Latar Belakang Sertifikat (Khusus Sertifikat Magang) -->
                <div id="certificate-bg-picker-container" class="{{ old('type', $selectedType) === 'certificate' ? '' : 'hidden' }} border-t border-slate-100 pt-4">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 uppercase">Pilih Gambar Background Sertifikat Awal</label>
                            <p class="text-xs text-slate-500">Tersedia aset dari Google Drive, Penyimpanan Sistem, dan desain bawaan. Anda juga dapat menggantinya kapan saja di editor visual.</p>
                        </div>
                        <a href="{{ route('admin.system-assets.index') }}" target="_blank" class="text-xs font-semibold text-blue-600 hover:underline inline-flex items-center gap-1">
                            <span>Buka Perpustakaan Aset / Drive</span>
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/></svg>
                        </a>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 max-h-[300px] overflow-y-auto p-1">
                        @foreach ($assetCatalog['backgrounds'] ?? [] as $idx => $bg)
                            <label class="relative flex flex-col rounded-xl border border-slate-200 bg-white p-2.5 cursor-pointer hover:border-amber-400 hover:shadow-sm transition has-[:checked]:border-amber-500 has-[:checked]:ring-2 has-[:checked]:ring-amber-200 has-[:checked]:bg-amber-50/20">
                                <input type="radio" name="certificate_background" value="{{ $bg['url'] }}" {{ $idx === 0 ? 'checked' : '' }} class="sr-only">
                                <div class="relative w-full h-24 rounded-lg overflow-hidden bg-slate-100 border border-slate-200 mb-2">
                                    <img src="{{ $bg['thumb'] }}" alt="{{ $bg['name'] }}" class="w-full h-full object-cover" onerror="this.onerror=null; this.src='/images/certificate/certificate-bg.png';">
                                    @if (!empty($bg['is_drive']))
                                        <span class="absolute top-1.5 right-1.5 inline-flex items-center gap-1 rounded bg-emerald-600 text-white text-[9px] font-bold px-1.5 py-0.5 shadow">
                                            <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="currentColor"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/></svg>
                                            Drive
                                        </span>
                                    @elseif (!empty($bg['is_real']))
                                        <span class="absolute top-1.5 right-1.5 inline-flex items-center gap-1 rounded bg-indigo-600 text-white text-[9px] font-bold px-1.5 py-0.5 shadow">
                                            Sistem
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-bold text-slate-800 truncate" title="{{ $bg['name'] }}">{{ $bg['name'] }}</span>
                                    <span class="text-[10px] text-slate-400 shrink-0 ml-1">{{ $bg['source'] ?? 'Bawaan' }}</span>
                                </div>
                            </label>
                        @endforeach

                        <!-- Pilihan Tanpa Background -->
                        <label class="relative flex flex-col justify-center items-center rounded-xl border border-dashed border-slate-300 bg-white p-4 cursor-pointer hover:border-amber-400 hover:bg-slate-50 transition has-[:checked]:border-amber-500 has-[:checked]:ring-2 has-[:checked]:ring-amber-200 has-[:checked]:bg-amber-50/20 text-center">
                            <input type="radio" name="certificate_background" value="none" class="sr-only">
                            <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 mb-1">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="1" y1="1" x2="23" y2="23"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                            </div>
                            <span class="text-xs font-bold text-slate-700">Latar Polos (Tanpa Gambar)</span>
                            <span class="text-[10px] text-slate-400 mt-0.5">Warna dasar putih atau box custom</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="flex items-center justify-end gap-3 pt-4">
            <a
                href="{{ route('admin.document-templates.index') }}"
                class="rounded-lg border border-slate-300 px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            >
                Batal
            </a>
            <button
                type="submit"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
            >
                <span>Lanjutkan ke Editor Visual</span>
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="5" y1="12" x2="19" y2="12"/>
                    <polyline points="12 5 19 12 12 19"/>
                </svg>
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
    function updateEditorHint(type) {
        const hintText = document.getElementById('editor-hint-text');
        const container = document.getElementById('editor-hint-container');
        const bgPickerContainer = document.getElementById('certificate-bg-picker-container');
        const certSubtypeContainer = document.getElementById('certificate-subtype-container');

        if (type === 'certificate') {
            container.className = 'mt-5 rounded-lg border border-amber-100 bg-amber-50/60 p-4 text-xs text-amber-900 flex items-start gap-3';
            hintText.innerHTML = 'Untuk <strong>Sertifikat (Magang & Webinar)</strong>, Anda akan menggunakan <strong>Visual Canvas Editor</strong> (A4 Landscape) dengan posisi koordinat layer (X%, Y%), pilihan border/background piagam, tipografi sertifikat, serta tanda tangan & stempel digital.';
            if (bgPickerContainer) bgPickerContainer.classList.remove('hidden');
            if (certSubtypeContainer) certSubtypeContainer.classList.remove('hidden');
        } else {
            container.className = 'mt-5 rounded-lg border border-blue-100 bg-blue-50/60 p-4 text-xs text-blue-900 flex items-start gap-3';
            hintText.innerHTML = 'Untuk <strong>Surat Resmi (' + type.toUpperCase() + ')</strong>, sistem menyediakan <strong>Editor Dokumen A4 Visual</strong> dengan pengaturan paragraf, margin kertas, tabel, dan variabel Mail Merge.';
            if (bgPickerContainer) bgPickerContainer.classList.add('hidden');
            if (certSubtypeContainer) certSubtypeContainer.classList.add('hidden');
        }
    }

    function onCertTypeChanged(certType) {
        const nameInput = document.querySelector('input[name="name"]');
        if (nameInput && (!nameInput.value || nameInput.value.startsWith('Template Standar') || nameInput.value.startsWith('Template Sertifikat'))) {
            if (certType === 'webinar') {
                nameInput.placeholder = 'Contoh: Template Sertifikat Webinar Desain Grafis';
            } else {
                nameInput.placeholder = 'Contoh: Template Sertifikat Kelulusan Magang 2026';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const checkedType = document.querySelector('input[name="type"]:checked')?.value || 'loa';
        updateEditorHint(checkedType);
    });
</script>
@endpush
@endsection
