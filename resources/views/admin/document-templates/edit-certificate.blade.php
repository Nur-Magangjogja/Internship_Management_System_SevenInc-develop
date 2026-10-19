@extends('layouts.dashboard', ['title' => 'Editor Sertifikat Visual: ' . $template->name])

@section('content')
<div class="px-6 py-6" x-data="certificateCanvasApp()">
    <!-- Top Action Bar -->
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between border-b border-slate-200 pb-4">
        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.document-templates.index') }}"
                class="rounded-lg border border-slate-200 p-2 text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition"
                title="Kembali ke Daftar"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 border-amber-200">
                        Sertifikat Magang (Visual Canvas)
                    </span>
                    <span class="text-xs font-mono bg-slate-100 text-slate-600 px-2 py-0.5 rounded">
                        Versi {{ $template->version_number }}
                    </span>
                    @if ($template->is_default)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800">
                            DEFAULT
                        </span>
                    @endif
                </div>
                <h1 class="text-xl font-bold text-slate-800 mt-1">
                    {{ $template->name }}
                </h1>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Toggle Sample / Placeholder -->
            <button
                type="button"
                @click="showMockData = !showMockData"
                class="inline-flex items-center gap-1.5 rounded-lg border px-3.5 py-2 text-xs font-semibold transition"
                :class="showMockData ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50'"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <span x-text="showMockData ? 'Mode: Data Contoh Aktif' : 'Mode: Variabel Raw'"></span>
            </button>

            <!-- Histori Versi -->
            <a
                href="{{ route('admin.document-templates.versions', $template) }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 14 14"/>
                </svg>
                <span>Versi ({{ $template->versions_count ?? 1 }})</span>
            </a>

            <!-- Real DomPDF Preview Button -->
            <a
                href="{{ route('admin.document-templates.preview-pdf', $template) }}"
                target="_blank"
                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-2 text-xs font-semibold text-rose-700 transition hover:bg-rose-100"
            >
                <svg class="h-4 w-4 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <span>Preview PDF Asli</span>
            </a>

            <!-- Save Changes Button -->
            <button
                type="button"
                @click="saveTemplate()"
                :disabled="isSaving"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50"
            >
                <svg x-show="!isSaving" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                <span x-show="isSaving" class="inline-block animate-spin h-3.5 w-3.5 border-2 border-white border-t-transparent rounded-full"></span>
                <span x-text="isSaving ? 'Menyimpan...' : 'Simpan Revisi'"></span>
            </button>
        </div>
    </div>

    <!-- Status Alerts -->
    <div x-show="saveAlert.message" x-cloak class="mb-4 rounded-lg p-3 text-xs font-medium" :class="saveAlert.type === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'">
        <span x-text="saveAlert.message"></span>
    </div>

    <!-- Workspace Grid: Canvas Area (8 cols) + Element Inspector (4 cols) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        
        <!-- Left: Interactive Visual Canvas (8 Cols) -->
        <div class="lg:col-span-8 space-y-4">
            <!-- Canvas Controls Bar -->
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm flex items-center justify-between text-xs">
                <div class="flex items-center gap-4 text-slate-600">
                    <span class="font-semibold text-slate-800">Kanvas Desain Sertifikat:</span>
                    <span x-text="`${layout.canvas.orientation === 'portrait' ? 'A4 Portrait (210 x 297 mm)' : 'A4 Landscape (297 x 210 mm)'}`"></span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="addTextElement()"
                        class="inline-flex items-center gap-1 rounded bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1 font-semibold"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                        <span>Tambah Teks</span>
                    </button>
                </div>
            </div>

            <!-- Canvas Container -->
            <div class="flex justify-center bg-slate-800/90 p-6 rounded-2xl overflow-x-auto shadow-inner min-h-[600px] items-center">
                <div
                    id="certificate-viewport"
                    class="relative bg-white shadow-2xl transition-all select-none overflow-hidden"
                    :style="canvasStyle()"
                    @click.self="selectedElementId = null"
                >
                    <!-- Background Image Layer -->
                    <template x-if="layout.canvas.background_image">
                        <img
                            :src="layout.canvas.background_image"
                            alt="Background Sertifikat"
                            class="absolute inset-0 w-full h-full object-fill pointer-events-none"
                            :style="`opacity: ${layout.canvas.background_opacity || 1.0};`"
                        />
                    </template>

                    <!-- Render Canvas Elements -->
                    <template x-for="(elem, index) in layout.elements" :key="elem.id">
                        <!-- Text Layer -->
                        <div
                            x-if="elem.type === 'text'"
                            class="absolute cursor-move transition-shadow"
                            :class="selectedElementId === elem.id ? 'ring-2 ring-blue-500 ring-offset-2 shadow-lg bg-blue-50/20' : 'hover:ring-1 hover:ring-blue-300'"
                            :style="elementStyle(elem)"
                            @mousedown="startDrag($event, elem)"
                            @click.stop="selectedElementId = elem.id"
                        >
                            <span x-html="resolveText(elem.content)"></span>
                        </div>
                    </template>

                    <!-- Signature Group Layer -->
                    <template x-for="(elem, index) in layout.elements" :key="elem.id + '_sig'">
                        <div
                            x-if="elem.type === 'signature_group'"
                            class="absolute cursor-move transition-shadow p-2 text-center"
                            :class="selectedElementId === elem.id ? 'ring-2 ring-blue-500 ring-offset-2 shadow-lg bg-blue-50/20' : 'hover:ring-1 hover:ring-blue-300'"
                            :style="elementStyle(elem)"
                            @mousedown="startDrag($event, elem)"
                            @click.stop="selectedElementId = elem.id"
                        >
                            <div class="text-[12px] text-slate-700 font-medium" x-html="resolveText(elem.date_text)"></div>
                            <div class="text-[11px] text-slate-500 mt-0.5" x-html="resolveText(elem.role_text)"></div>
                            
                            <!-- Sig & Stamp Stack -->
                            <div class="relative inline-block my-2 h-16 w-44">
                                <template x-if="elem.sig_image">
                                    <img :src="resolveImage(elem.sig_image)" class="absolute inset-0 h-full w-full object-contain z-10" />
                                </template>
                                <template x-if="elem.stamp_image">
                                    <img :src="resolveImage(elem.stamp_image)" class="absolute -right-2 -top-2 h-16 w-16 object-contain opacity-85 z-20 pointer-events-none" />
                                </template>
                            </div>

                            <div class="text-[12px] font-bold text-slate-800 underline" x-html="resolveText(elem.name_text)"></div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Canvas Info Helper -->
            <div class="flex items-center justify-between text-xs text-slate-500 px-2">
                <span>Tips: <strong>Klik dan seret (drag)</strong> elemen langsung pada kanvas untuk menggeser posisi koordinat.</span>
                <span x-text="`Total Layer: ${layout.elements.length} Elemen`"></span>
            </div>
        </div>

        <!-- Right: Inspector Drawer & Controls (4 Cols) -->
        <div class="lg:col-span-4 space-y-4">
            
            <!-- Inspector Tabs: Element Properties vs Canvas Setup -->
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="flex border-b border-slate-200 bg-slate-50">
                    <button
                        type="button"
                        @click="activeDrawerTab = 'element'"
                        class="flex-1 py-2.5 text-xs font-semibold text-center border-b-2 transition"
                        :class="activeDrawerTab === 'element' ? 'border-blue-600 text-blue-600 bg-white' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    >
                        Properti Elemen
                    </button>
                    <button
                        type="button"
                        @click="activeDrawerTab = 'layers'"
                        class="flex-1 py-2.5 text-xs font-semibold text-center border-b-2 transition"
                        :class="activeDrawerTab === 'layers' ? 'border-blue-600 text-blue-600 bg-white' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    >
                        Daftar Layer
                    </button>
                    <button
                        type="button"
                        @click="activeDrawerTab = 'canvas'"
                        class="flex-1 py-2.5 text-xs font-semibold text-center border-b-2 transition"
                        :class="activeDrawerTab === 'canvas' ? 'border-blue-600 text-blue-600 bg-white' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    >
                        Kanvas & Bg
                    </button>
                </div>

                <!-- Tab 1: Element Properties -->
                <div x-show="activeDrawerTab === 'element'" class="p-4 space-y-4">
                    <template x-if="selectedElement">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                                <span class="text-xs font-bold text-slate-800 uppercase" x-text="`Edit: ${selectedElement.id}`"></span>
                                <button
                                    type="button"
                                    @click="deleteElement(selectedElement.id)"
                                    class="text-xs text-rose-600 hover:text-rose-800 font-semibold"
                                >
                                    Hapus Elemen
                                </button>
                            </div>

                            <!-- Text Content (If text element) -->
                            <template x-if="selectedElement.type === 'text'">
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Teks / Variabel</label>
                                    <textarea
                                        x-model="selectedElement.content"
                                        rows="2"
                                        class="w-full rounded border border-slate-300 p-2 text-xs font-mono text-slate-800 focus:outline-none focus:border-blue-500"
                                    ></textarea>

                                    <!-- Quick Tags helper -->
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <button type="button" @click="insertTagToSelected('@{{pemagang.nama}}')" class="text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200">+ Nama</button>
                                        <button type="button" @click="insertTagToSelected('@{{dokumen.nomor}}')" class="text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200">+ No. Surat</button>
                                        <button type="button" @click="insertTagToSelected('@{{magang.divisi}}')" class="text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200">+ Divisi</button>
                                        <button type="button" @click="insertTagToSelected('@{{magang.tanggal_mulai}} s/d @{{magang.tanggal_selesai}}')" class="text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200">+ Periode</button>
                                    </div>
                                </div>
                            </template>

                            <!-- Signature Group Properties -->
                            <template x-if="selectedElement.type === 'signature_group'">
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Teks Tanggal & Tempat</label>
                                        <input type="text" x-model="selectedElement.date_text" class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Jabatan & Perusahaan</label>
                                        <input type="text" x-model="selectedElement.role_text" class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Nama Penandatangan</label>
                                        <input type="text" x-model="selectedElement.name_text" class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                                    </div>
                                </div>
                            </template>

                            <!-- Coordinates X and Y -->
                            <div class="grid grid-cols-2 gap-3 border-t border-slate-100 pt-3">
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 mb-1" x-text="`Posisi X: ${selectedElement.x_percent}%`"></label>
                                    <input type="range" min="0" max="100" step="0.5" x-model.number="selectedElement.x_percent" class="w-full" />
                                </div>
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-600 mb-1" x-text="`Posisi Y: ${selectedElement.y_percent}%`"></label>
                                    <input type="range" min="0" max="100" step="0.5" x-model.number="selectedElement.y_percent" class="w-full" />
                                </div>
                            </div>

                            <!-- Typography (for text elements) -->
                            <template x-if="selectedElement.type === 'text'">
                                <div class="space-y-3 border-t border-slate-100 pt-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Jenis Font</label>
                                        <select x-model="selectedElement.font_family" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                                            <option value="Times New Roman">Times New Roman (Klasik Resmi)</option>
                                            <option value="'Great Vibes', cursive">Great Vibes (Kaligrafi / Script)</option>
                                            <option value="'Playfair Display', serif">Playfair Display (Elegan Modern)</option>
                                            <option value="'Cinzel', serif">Cinzel (Roman Formal)</option>
                                            <option value="'Montserrat', sans-serif">Montserrat (Modern Sans)</option>
                                            <option value="Courier New">Courier New (Monospace)</option>
                                        </select>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1" x-text="`Ukuran: ${selectedElement.font_size_pt}pt`"></label>
                                            <input type="number" min="8" max="72" x-model.number="selectedElement.font_size_pt" class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Warna Teks</label>
                                            <div class="flex items-center gap-1.5">
                                                <input type="color" x-model="selectedElement.color" class="h-7 w-8 rounded border p-0.5 cursor-pointer" />
                                                <input type="text" x-model="selectedElement.color" class="w-full rounded border border-slate-300 p-1 text-xs font-mono" />
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Ketebalan</label>
                                            <select x-model="selectedElement.font_weight" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                                                <option value="normal">Normal</option>
                                                <option value="600">Semi Bold (600)</option>
                                                <option value="bold">Bold (Tebal)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Perataan</label>
                                            <select x-model="selectedElement.align" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                                                <option value="center">Tengah (Center)</option>
                                                <option value="left">Rata Kiri (Left)</option>
                                                <option value="right">Rata Kanan (Right)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1" x-text="`Lebar Maksimal: ${selectedElement.width_percent}%`"></label>
                                        <input type="range" min="10" max="100" step="1" x-model.number="selectedElement.width_percent" class="w-full" />
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    <template x-if="!selectedElement">
                        <div class="p-6 text-center text-slate-400 text-xs">
                            <p>Klik salah satu elemen teks atau tanda tangan pada kanvas untuk mengedit propertinya.</p>
                        </div>
                    </template>
                </div>

                <!-- Tab 2: Layer List -->
                <div x-show="activeDrawerTab === 'layers'" class="p-4 space-y-2">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Susunan Layer</span>
                        <button type="button" @click="addTextElement()" class="text-xs text-blue-600 font-semibold hover:underline">+ Teks Baru</button>
                    </div>

                    <div class="space-y-1 max-h-[400px] overflow-y-auto">
                        <template x-for="(elem, idx) in layout.elements" :key="elem.id">
                            <div
                                class="flex items-center justify-between p-2 rounded border transition cursor-pointer text-xs"
                                :class="selectedElementId === elem.id ? 'border-blue-500 bg-blue-50/50 font-bold text-blue-900' : 'border-slate-100 hover:bg-slate-50 text-slate-700'"
                                @click="selectedElementId = elem.id"
                            >
                                <div class="truncate max-w-[170px]">
                                    <span class="font-mono text-[10px] text-slate-400" x-text="`#${idx+1}`"></span>
                                    <span x-text="elem.id"></span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        @click.stop="moveLayer(idx, -1)"
                                        title="Naikkan Layer"
                                        class="p-1 hover:text-blue-600"
                                        :disabled="idx === 0"
                                    >&uarr;</button>
                                    <button
                                        type="button"
                                        @click.stop="moveLayer(idx, 1)"
                                        title="Turunkan Layer"
                                        class="p-1 hover:text-blue-600"
                                        :disabled="idx === layout.elements.length - 1"
                                    >&darr;</button>
                                    <button
                                        type="button"
                                        @click.stop="deleteElement(elem.id)"
                                        title="Hapus Layer"
                                        class="p-1 text-rose-500 hover:text-rose-700"
                                    >&times;</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Tab 3: Canvas Setup -->
                <div x-show="activeDrawerTab === 'canvas'" class="p-4 space-y-4">
                    <div class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2">Orientasi & Latar Belakang</div>

                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Orientasi Kertas</label>
                        <select x-model="layout.canvas.orientation" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                            <option value="landscape">Landscape (297 x 210 mm)</option>
                            <option value="portrait">Portrait (210 x 297 mm)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Background Sertifikat</label>
                        <div class="space-y-2">
                            @foreach ($assetCatalog['backgrounds'] ?? [] as $bg)
                                <label class="flex items-center gap-2 p-2 rounded border border-slate-200 cursor-pointer hover:bg-slate-50">
                                    <input type="radio" name="bg_select" :checked="layout.canvas.background_image === '{{ $bg['url'] }}'" @change="layout.canvas.background_image = '{{ $bg['url'] }}'" />
                                    <img src="{{ $bg['thumb'] }}" class="h-8 w-12 object-cover rounded border" />
                                    <span class="text-xs text-slate-700 truncate">{{ $bg['name'] }}</span>
                                </label>
                            @endforeach
                            <label class="flex items-center gap-2 p-2 rounded border border-slate-200 cursor-pointer hover:bg-slate-50">
                                <input type="radio" name="bg_select" :checked="!layout.canvas.background_image" @change="layout.canvas.background_image = ''" />
                                <span class="text-xs text-slate-500">Tanpa Gambar Background (Polos)</span>
                            </label>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-3 space-y-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Nama Template</label>
                            <input type="text" x-model="templateName" class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Catatan Revisi</label>
                            <input type="text" x-model="changeSummary" placeholder="Contoh: Mengubah posisi tanda tangan pimpinan..." class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="isActive" class="rounded text-blue-600" />
                            <span class="text-xs font-medium text-slate-700">Aktifkan Template Ini</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="isDefault" class="rounded text-amber-500" />
                            <span class="text-xs font-medium text-slate-700">Jadikan Template Default Sertifikat</span>
                        </label>
                    </div>
                </div>

            </div>

        </div>

    </div>
</div>

@push('scripts')
<script>
function certificateCanvasApp() {
    return {
        activeDrawerTab: 'element',
        templateName: @json($template->name),
        templateDescription: @json($template->description ?? ''),
        changeSummary: '',
        isActive: {{ $template->is_active ? 'true' : 'false' }},
        isDefault: {{ $template->is_default ? 'true' : 'false' }},
        showMockData: true,
        selectedElementId: 'elem_intern_name',
        isSaving: false,
        saveAlert: { message: '', type: 'success' },
        draggingElement: null,

        layout: @json(array_merge(\App\Models\DocumentTemplate::getDefaultCertificateCanvasLayout(), $template->canvas_layout ?? [])),
        mockData: @json($mockData),

        get selectedElement() {
            return this.layout.elements.find(e => e.id === this.selectedElementId);
        },

        canvasStyle() {
            const isLandscape = (this.layout.canvas.orientation !== 'portrait');
            const width = isLandscape ? 840 : 594;
            const height = isLandscape ? 594 : 840;
            return `width: ${width}px; height: ${height}px; background-color: ${this.layout.canvas.background_color || '#ffffff'};`;
        },

        elementStyle(elem) {
            const x = elem.x_percent ?? 50;
            const y = elem.y_percent ?? 50;
            const width = elem.width_percent ?? 50;
            const align = elem.align ?? 'center';

            let transform = 'translate(-50%, -50%)';
            if (align === 'left') transform = 'translate(0, -50%)';
            if (align === 'right') transform = 'translate(-100%, -50%)';

            return `
                left: ${x}%;
                top: ${y}%;
                width: ${width}%;
                transform: ${transform};
                text-align: ${align};
                font-family: ${elem.font_family || 'Times New Roman'}, serif;
                font-size: ${elem.font_size_pt || 14}pt;
                font-weight: ${elem.font_weight || 'normal'};
                font-style: ${elem.font_style || 'normal'};
                color: ${elem.color || '#000000'};
                z-index: ${elem.z_index || 10};
            `;
        },

        resolveText(text) {
            if (!text) return '';
            if (!this.showMockData) return text;

            let out = text;
            out = out.replace(/\{\{pemagang\.nama\}\}/g, this.mockData.pemagang.nama);
            out = out.replace(/\{\{pemagang\.nim\}\}/g, this.mockData.pemagang.nim);
            out = out.replace(/\{\{pemagang\.institusi\}\}/g, this.mockData.pemagang.institusi);
            out = out.replace(/\{\{magang\.divisi\}\}/g, this.mockData.magang.divisi);
            out = out.replace(/\{\{magang\.tanggal_mulai\}\}/g, this.mockData.magang.tanggal_mulai);
            out = out.replace(/\{\{magang\.tanggal_selesai\}\}/g, this.mockData.magang.tanggal_selesai);
            out = out.replace(/\{\{magang\.durasi\}\}/g, this.mockData.magang.durasi);
            out = out.replace(/\{\{dokumen\.nomor\}\}/g, this.mockData.dokumen.nomor);
            out = out.replace(/\{\{dokumen\.tanggal\}\}/g, this.mockData.dokumen.tanggal);
            out = out.replace(/\{\{dokumen\.tempat\}\}/g, this.mockData.dokumen.tempat);
            out = out.replace(/\{\{perusahaan\.nama\}\}/g, this.mockData.perusahaan.nama);
            out = out.replace(/\{\{perusahaan\.pimpinan\}\}/g, this.mockData.perusahaan.pimpinan);
            out = out.replace(/\{\{perusahaan\.jabatan\}\}/g, this.mockData.perusahaan.jabatan);
            return out;
        },

        resolveImage(tagOrUrl) {
            if (!tagOrUrl) return '';
            if (tagOrUrl === '@{{asset.signature}}') return this.mockData.asset.signature;
            if (tagOrUrl === '@{{asset.stamp}}') return this.mockData.asset.stamp;
            if (tagOrUrl === '@{{asset.company.logo}}') return this.mockData.asset.company.logo;
            return tagOrUrl;
        },

        insertTagToSelected(tag) {
            if (this.selectedElement && this.selectedElement.type === 'text') {
                this.selectedElement.content += ' ' + tag;
            }
        },

        addTextElement() {
            const newId = 'elem_custom_' + Date.now().toString().slice(-4);
            const newElem = {
                id: newId,
                type: 'text',
                content: 'Teks Baru @{{pemagang.nama}}',
                x_percent: 50.0,
                y_percent: 50.0,
                width_percent: 50.0,
                font_family: 'Times New Roman',
                font_size_pt: 14,
                font_weight: 'normal',
                font_style: 'normal',
                color: '#1e293b',
                align: 'center',
                z_index: 10 + this.layout.elements.length,
            };
            this.layout.elements.push(newElem);
            this.selectedElementId = newId;
            this.activeDrawerTab = 'element';
        },

        deleteElement(id) {
            if (confirm('Apakah Anda yakin ingin menghapus layer ini?')) {
                this.layout.elements = this.layout.elements.filter(e => e.id !== id);
                this.selectedElementId = this.layout.elements[0]?.id || null;
            }
        },

        moveLayer(index, direction) {
            const newIndex = index + direction;
            if (newIndex < 0 || newIndex >= this.layout.elements.length) return;
            const item = this.layout.elements.splice(index, 1)[0];
            this.layout.elements.splice(newIndex, 0, item);
        },

        startDrag(event, elem) {
            this.selectedElementId = elem.id;
            const viewport = document.getElementById('certificate-viewport');
            const rect = viewport.getBoundingClientRect();

            const onMouseMove = (e) => {
                const clientX = e.clientX;
                const clientY = e.clientY;
                let xPercent = ((clientX - rect.left) / rect.width) * 100;
                let yPercent = ((clientY - rect.top) / rect.height) * 100;

                xPercent = Math.max(0, Math.min(100, Math.round(xPercent * 10) / 10));
                yPercent = Math.max(0, Math.min(100, Math.round(yPercent * 10) / 10));

                elem.x_percent = xPercent;
                elem.y_percent = yPercent;
            };

            const onMouseUp = () => {
                window.removeEventListener('mousemove', onMouseMove);
                window.removeEventListener('mouseup', onMouseUp);
            };

            window.addEventListener('mousemove', onMouseMove);
            window.addEventListener('mouseup', onMouseUp);
        },

        async saveTemplate() {
            this.isSaving = true;
            this.saveAlert.message = '';

            const payload = {
                _token: '{{ csrf_token() }}',
                _method: 'PUT',
                name: this.templateName,
                description: this.templateDescription,
                canvas_layout: this.layout,
                page_config: {
                    orientation: this.layout.canvas.orientation || 'landscape',
                    paper_size: 'A4',
                    background_image: this.layout.canvas.background_image,
                },
                change_summary: this.changeSummary || 'Pembaruan tata letak kanvas sertifikat',
                is_active: this.isActive ? 1 : 0,
                is_default: this.isDefault ? 1 : 0,
            };

            try {
                const response = await fetch('{{ route("admin.document-templates.update", $template) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    },
                    body: JSON.stringify(payload)
                });

                const data = await response.json();
                if (response.ok && data.success) {
                    this.saveAlert = { message: data.message, type: 'success' };
                    this.changeSummary = '';
                    setTimeout(() => { this.saveAlert.message = ''; }, 4000);
                } else {
                    this.saveAlert = { message: data.message || 'Gagal menyimpan tata letak sertifikat.', type: 'error' };
                }
            } catch (err) {
                this.saveAlert = { message: 'Terjadi kesalahan saat menyimpan: ' + err.message, type: 'error' };
            } finally {
                this.isSaving = false;
            }
        }
    };
}
</script>
@endpush
@endsection
