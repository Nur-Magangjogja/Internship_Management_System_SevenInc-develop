@extends('layouts.dashboard', ['title' => 'Editor Sertifikat Visual: ' . $template->name])

@section('content')
<!-- Google Fonts for Certificate Typography -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Alex+Brush&family=Allura&family=Cinzel:wght@400;600;700;800&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Dancing+Script:wght@500;700&family=Great+Vibes&family=Inter:wght@300;400;500;600;700&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Montserrat:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Oswald:wght@400;600;700&family=Parisienne&family=Pinyon+Script&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Poppins:wght@400;500;600;700&family=Prata&family=Raleway:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap" rel="stylesheet">
<style>
    @import url('https://fonts.googleapis.com/css2?family=Alex+Brush&family=Allura&family=Cinzel:wght@400;600;700;800&family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=Dancing+Script:wght@500;700&family=Great+Vibes&family=Inter:wght@300;400;500;600;700&family=Lora:ital,wght@0,400;0,600;0,700;1,400&family=Merriweather:ital,wght@0,400;0,700;1,400&family=Montserrat:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Oswald:wght@400;600;700&family=Parisienne&family=Pinyon+Script&family=Playfair+Display:ital,wght@0,400;0,600;0,700;0,800;1,400&family=Poppins:wght@400;500;600;700&family=Prata&family=Raleway:wght@400;600;700;800&family=Space+Mono:ital,wght@0,400;0,700;1,400&display=swap');
</style>
<div class="px-6 py-6" x-data="certificateCanvasApp()" @keydown.window="handleKeyboard($event)">
    <!-- Top Action & Navigation Bar -->
    <div class="mb-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between border-b border-slate-200 pb-4">
        <div class="flex items-center gap-3">
            <a
                href="{{ route('admin.document-templates.index') }}"
                class="rounded-lg border border-slate-200 p-2 text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition shadow-sm"
                title="Kembali ke Daftar Template"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 border-amber-200">
                        Kanvas Desain Sertifikat (Visual Studio)
                    </span>
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-bold"
                          :class="certificateType === 'webinar' ? 'bg-teal-100 text-teal-800 border-teal-200' : 'bg-amber-100 text-amber-800 border-amber-200'"
                          x-text="certificateType === 'webinar' ? 'Sertifikat Webinar' : 'Sertifikat Magang'">
                    </span>
                    <span class="text-xs font-mono bg-slate-100 text-slate-600 px-2 py-0.5 rounded border border-slate-200">
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
            <!-- Undo / Redo Toolbar -->
            <div class="inline-flex rounded-lg border border-slate-300 bg-white p-0.5 shadow-sm">
                <button
                    type="button"
                    @click="undo()"
                    :disabled="!canUndo"
                    class="p-1.5 text-slate-600 hover:text-blue-600 hover:bg-slate-50 rounded disabled:opacity-30 disabled:hover:text-slate-600 disabled:hover:bg-transparent transition"
                    title="Urungkan Perubahan (Ctrl+Z)"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 7v6h6"/>
                        <path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>
                    </svg>
                </button>
                <button
                    type="button"
                    @click="redo()"
                    :disabled="!canRedo"
                    class="p-1.5 text-slate-600 hover:text-blue-600 hover:bg-slate-50 rounded disabled:opacity-30 disabled:hover:text-slate-600 disabled:hover:bg-transparent transition"
                    title="Ulangi Perubahan (Ctrl+Y)"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 7v6h-6"/>
                        <path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/>
                    </svg>
                </button>
            </div>

            <!-- Zoom Controls -->
            <div class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-2 py-1 shadow-sm text-xs font-semibold text-slate-600 gap-1">
                <span class="text-slate-400 text-[10px] mr-1">ZOOM:</span>
                <button type="button" @click="zoomLevel = 0.75" class="px-1.5 py-0.5 rounded transition" :class="zoomLevel === 0.75 ? 'bg-blue-100 text-blue-700' : 'hover:bg-slate-100'">75%</button>
                <button type="button" @click="zoomLevel = 1.0" class="px-1.5 py-0.5 rounded transition" :class="zoomLevel === 1.0 ? 'bg-blue-100 text-blue-700' : 'hover:bg-slate-100'">100%</button>
                <button type="button" @click="zoomLevel = 1.25" class="px-1.5 py-0.5 rounded transition" :class="zoomLevel === 1.25 ? 'bg-blue-100 text-blue-700' : 'hover:bg-slate-100'">125%</button>
            </div>

            <!-- Toggle Sample / Placeholder -->
            <button
                type="button"
                @click="showMockData = !showMockData"
                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition shadow-sm"
                :class="showMockData ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50'"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <span x-text="showMockData ? 'Data Simulasi' : 'Token Mentah'"></span>
            </button>

            <!-- Histori Versi -->
            <a
                href="{{ route('admin.document-templates.versions', $template) }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 shadow-sm"
            >
                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 14 14"/>
                </svg>
                <span>Versi ({{ $template->versions_count ?? 1 }})</span>
            </a>

            <!-- Halaman Preview Resmi (Tab Sama) -->
            <a
                href="{{ route('admin.document-templates.preview', $template) }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 shadow-sm"
                title="Buka Halaman Pratinjau Resmi"
            >
                <svg class="h-4 w-4 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <span>Halaman Preview</span>
            </a>

            <!-- Real DomPDF Preview Button -->
            <a
                href="{{ route('admin.document-templates.preview-pdf', $template) }}"
                target="_blank"
                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3.5 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 shadow-sm"
            >
                <svg class="h-4 w-4 text-rose-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                </svg>
                <span>Preview PDF</span>
            </a>

            <!-- Save Changes Button -->
            <button
                type="button"
                @click="saveTemplate()"
                :disabled="isSaving"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50"
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
    <div x-show="saveAlert.message" x-cloak class="mb-4 rounded-lg p-3 text-xs font-medium shadow-sm transition-all" :class="saveAlert.type === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'">
        <div class="flex items-center justify-between">
            <span x-text="saveAlert.message"></span>
            <button type="button" @click="saveAlert.message = ''" class="text-slate-400 hover:text-slate-600 font-bold ml-2">&times;</button>
        </div>
    </div>

    <!-- Main Workspace Grid: Interactive Canvas (8 cols) + Inspector Controls (4 cols) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        
        <!-- Left: Interactive Visual Canvas (8 Cols) -->
        <div class="lg:col-span-8 space-y-3">
            
            <!-- Canvas Object Creation & Quick Action Toolbar -->
            <div class="rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm flex flex-wrap items-center justify-between gap-2 text-xs">
                <!-- Add Element Buttons -->
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-[11px] font-bold text-slate-500 uppercase mr-1">Tambah:</span>
                    <button
                        type="button"
                        @click="addTextElement()"
                        class="inline-flex items-center gap-1 rounded bg-slate-100 hover:bg-blue-50 hover:text-blue-700 text-slate-700 px-2.5 py-1 font-semibold transition"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7V4h16v3M9 20h6M12 4v16"/></svg>
                        <span>Teks</span>
                    </button>
                    <button
                        type="button"
                        @click="addImageElement()"
                        class="inline-flex items-center gap-1 rounded bg-slate-100 hover:bg-blue-50 hover:text-blue-700 text-slate-700 px-2.5 py-1 font-semibold transition"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <span>Gambar / Logo</span>
                    </button>
                    <button
                        type="button"
                        @click="addShapeElement()"
                        class="inline-flex items-center gap-1 rounded bg-slate-100 hover:bg-blue-50 hover:text-blue-700 text-slate-700 px-2.5 py-1 font-semibold transition"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                        <span>Bentuk / Box</span>
                    </button>
                    <button
                        type="button"
                        @click="addLineElement()"
                        class="inline-flex items-center gap-1 rounded bg-slate-100 hover:bg-blue-50 hover:text-blue-700 text-slate-700 px-2.5 py-1 font-semibold transition"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span>Garis</span>
                    </button>
                    <button
                        type="button"
                        @click="addQrElement()"
                        class="inline-flex items-center gap-1 rounded bg-slate-100 hover:bg-blue-50 hover:text-blue-700 text-slate-700 px-2.5 py-1 font-semibold transition"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        <span>QR Code</span>
                    </button>
                    <button
                        type="button"
                        @click="addSignatureGroup()"
                        class="inline-flex items-center gap-1 rounded bg-slate-100 hover:bg-blue-50 hover:text-blue-700 text-slate-700 px-2.5 py-1 font-semibold transition"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 19v-4a2 2 0 0 0-2-2h-4"/><path d="M4 19v-4a2 2 0 0 1 2-2h4"/><line x1="12" y1="15" x2="12" y2="3"/><polyline points="8 7 12 3 16 7"/></svg>
                        <span>Tanda Tangan</span>
                    </button>
                    <button
                        type="button"
                        @click="activeDrawerTab = 'canvas'"
                        class="inline-flex items-center gap-1 rounded bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200 px-2.5 py-1 font-semibold transition"
                        title="Buka Pilihan Background Kertas & Desain"
                    >
                        <svg class="h-3.5 w-3.5 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <span>Ganti Background</span>
                    </button>
                </div>

                <!-- Layer Quick Actions for Selected Element -->
                <div class="flex items-center gap-1 border-l border-slate-200 pl-2">
                    <button
                        type="button"
                        @click="duplicateSelected()"
                        :disabled="!selectedElement"
                        title="Duplikat Elemen (Ctrl+D)"
                        class="p-1 rounded text-slate-600 hover:text-blue-600 hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    <button
                        type="button"
                        @click="bringToFront()"
                        :disabled="!selectedElement"
                        title="Bawa ke Paling Depan"
                        class="p-1 rounded text-slate-600 hover:text-blue-600 hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/></svg>
                    </button>
                    <button
                        type="button"
                        @click="sendToBack()"
                        :disabled="!selectedElement"
                        title="Kirim ke Paling Belakang"
                        class="p-1 rounded text-slate-600 hover:text-blue-600 hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 12 2 17 12 22 22 17 12 12"/><polyline points="2 7 12 2 22 7"/></svg>
                    </button>
                    <button
                        type="button"
                        @click="deleteSelected()"
                        :disabled="!selectedElement"
                        title="Hapus Elemen Terpilih (Del)"
                        class="p-1 rounded text-rose-500 hover:text-rose-700 hover:bg-rose-50 disabled:opacity-30 disabled:hover:bg-transparent"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </button>
                </div>
            </div>

            <!-- Canvas Viewport Outer Scroller -->
            <div class="flex justify-center bg-slate-900/90 p-6 rounded-2xl overflow-auto shadow-inner min-h-[640px] items-center relative">
                
                <!-- Scaled Canvas Container -->
                <div :style="`transform: scale(${zoomLevel}); transform-origin: center center; transition: transform 0.15s ease-out;`">
                    
                    <div
                        id="certificate-viewport"
                        class="relative bg-white shadow-2xl select-none overflow-hidden"
                        style="width: 840px; height: 594px; background-color: #ffffff;"
                        :style="canvasStyle()"
                        @click.self="selectedElementId = null"
                    >
                        <!-- Background Image Layer -->
                        <template x-if="layout && layout.canvas && layout.canvas.background_image">
                            <img
                                :src="resolveImage(layout.canvas.background_image)"
                                alt="Background Sertifikat"
                                class="absolute inset-0 w-full h-full object-fill pointer-events-none"
                                :style="`opacity: ${layout.canvas.background_opacity ?? 1.0};`"
                                x-on:error="$event.target.style.display = 'none'"
                            />
                        </template>

                        <!-- Elements Loop -->
                        <template x-for="(elem, index) in layout.elements" :key="elem.id">
                            <div
                                :id="'canvas-element-' + elem.id"
                                class="absolute cursor-move transition-shadow"
                                :class="selectedElementId === elem.id ? 'ring-2 ring-blue-500 ring-offset-1 shadow-xl bg-blue-50/10' : 'hover:ring-1 hover:ring-blue-300'"
                                :style="elementOuterStyle(elem)"
                                @mousedown="startDrag($event, elem)"
                                @click.stop="selectElement(elem.id)"
                            >
                                <!-- Inner Content per Type -->
                                
                                <!-- 1. Text Type -->
                                <template x-if="elem.type === 'text'">
                                    <div
                                        class="w-full h-full"
                                        :style="elementTextStyle(elem)"
                                        x-html="resolveText(elem.content)"
                                    ></div>
                                </template>

                                <!-- 2. Image Type -->
                                <template x-if="elem.type === 'image'">
                                    <div class="w-full h-full flex items-center justify-center pointer-events-none">
                                        <img
                                            :src="resolveImage(elem.src)"
                                            class="max-w-full max-h-full w-auto h-auto object-contain pointer-events-none"
                                            :style="`opacity: ${elem.opacity ?? 1.0};`"
                                            alt="Gambar Sertifikat"
                                            x-on:error="if ($event.target.src.includes('/storage/images/')) { $event.target.src = $event.target.src.replace('/storage/images/', '/images/'); } else { $event.target.style.display = 'none'; }"
                                        />
                                    </div>
                                </template>

                                <!-- 3. Shape Type -->
                                <template x-if="elem.type === 'shape'">
                                    <div
                                        class="w-full h-full pointer-events-none box-border"
                                        :style="elementShapeStyle(elem)"
                                    ></div>
                                </template>

                                <!-- 4. Line Type -->
                                <template x-if="elem.type === 'line'">
                                    <div
                                        class="w-full pointer-events-none"
                                        :style="elementLineStyle(elem)"
                                    ></div>
                                </template>

                                <!-- 5. QR Code Type -->
                                <template x-if="elem.type === 'qr_code'">
                                    <div
                                        class="w-full h-full flex flex-col items-center justify-center pointer-events-none p-1 rounded box-border"
                                        :style="`
                                            background-color: ${elem.bg_color && elem.bg_color !== 'transparent' ? elem.bg_color : '#ffffff'};
                                            border: ${elem.bg_color && elem.bg_color !== 'transparent' ? '1px solid #cbd5e1' : 'none'};
                                        `"
                                    >
                                        <svg viewBox="0 0 29 29" class="w-full h-full" shape-rendering="crispEdges">
                                            <!-- Finder Top-Left -->
                                            <rect x="2" y="2" width="7" height="7" :fill="elem.fg_color || '#000'"/>
                                            <rect x="3" y="3" width="5" height="5" :fill="elem.bg_color || '#fff'"/>
                                            <rect x="4" y="4" width="3" height="3" :fill="elem.fg_color || '#000'"/>
                                            <!-- Finder Top-Right -->
                                            <rect x="20" y="2" width="7" height="7" :fill="elem.fg_color || '#000'"/>
                                            <rect x="21" y="3" width="5" height="5" :fill="elem.bg_color || '#fff'"/>
                                            <rect x="22" y="4" width="3" height="3" :fill="elem.fg_color || '#000'"/>
                                            <!-- Finder Bottom-Left -->
                                            <rect x="2" y="20" width="7" height="7" :fill="elem.fg_color || '#000'"/>
                                            <rect x="3" y="21" width="5" height="5" :fill="elem.bg_color || '#fff'"/>
                                            <rect x="4" y="22" width="3" height="3" :fill="elem.fg_color || '#000'"/>
                                            <!-- Data simulation bits -->
                                            <rect x="11" y="4" width="2" height="2" :fill="elem.fg_color || '#000'"/>
                                            <rect x="15" y="4" width="2" height="2" :fill="elem.fg_color || '#000'"/>
                                            <rect x="11" y="8" width="2" height="2" :fill="elem.fg_color || '#000'"/>
                                            <rect x="15" y="8" width="2" height="2" :fill="elem.fg_color || '#000'"/>
                                            <rect x="11" y="14" width="7" height="2" :fill="elem.fg_color || '#000'"/>
                                            <rect x="11" y="20" width="3" height="3" :fill="elem.fg_color || '#000'"/>
                                            <rect x="16" y="22" width="4" height="2" :fill="elem.fg_color || '#000'"/>
                                            <rect x="22" y="14" width="3" height="3" :fill="elem.fg_color || '#000'"/>
                                        </svg>
                                    </div>
                                </template>

                                <!-- 6. Signature Group Type (Diselaraskan 1:1 dengan Pratinjau & DomPDF) -->
                                <template x-if="elem.type === 'signature_group'">
                                    <div
                                        class="w-full pointer-events-none select-none box-border"
                                        :style="`
                                            opacity: ${elem.opacity ?? 1.0};
                                            text-align: ${elem.align ?? 'center'};
                                            font-family: ${formatFontFamily(elem.font_family)};
                                            color: ${elem.color || '#000000'};
                                        `"
                                    >
                                        <template x-if="elem.cert_no_text">
                                            <p class="m-0 leading-normal" style="margin-bottom: 2px; font-size: 11pt;" x-html="resolveText(elem.cert_no_text)"></p>
                                        </template>
                                        <p class="m-0 leading-normal" style="font-size: 11pt;" x-html="resolveText(elem.date_text)"></p>
                                        <p class="m-0 leading-normal" style="margin-top: 2px; font-size: 11pt;" x-html="resolveText(elem.role_text)"></p>
                                        
                                        <!-- Sig & Stamp Stack (Presisi tengah 1:1 dengan pratinjau & PDF) -->
                                        <div
                                            :style="`
                                                height: ${(elem.sig_height_px || 50)}px;
                                                position: relative;
                                                width: 220px;
                                                margin: ${elem.align === 'left' ? '4px 0' : (elem.align === 'right' ? '4px 0 4px auto' : '4px auto')};
                                            `"
                                        >
                                            <template x-if="elem.stamp_image">
                                                <img
                                                    :src="resolveImage(elem.stamp_image)"
                                                    :style="`
                                                        height: ${(elem.stamp_size_px || 120)}px;
                                                        width: ${(elem.stamp_size_px || 120)}px;
                                                        position: absolute;
                                                        opacity: 0.70;
                                                        mix-blend-mode: multiply;
                                                        z-index: 5;
                                                        pointer-events: none;
                                                        top: ${(elem.stamp_offset_y !== undefined && elem.stamp_offset_y !== null ? elem.stamp_offset_y : -35)}px;
                                                        left: ${elem.align === 'left' ? ((elem.stamp_offset_x !== undefined && elem.stamp_offset_x !== null ? elem.stamp_offset_x : 10) + 'px') : (elem.align === 'right' ? 'auto' : ((elem.stamp_offset_x !== undefined && elem.stamp_offset_x !== null ? elem.stamp_offset_x : 10) + 'px'))};
                                                        right: ${elem.align === 'right' ? ((elem.stamp_offset_x !== undefined && elem.stamp_offset_x !== null ? elem.stamp_offset_x : 10) + 'px') : 'auto'};
                                                    `"
                                                    alt="Stempel"
                                                    x-on:error="$event.target.style.display = 'none'"
                                                />
                                            </template>
                                            <template x-if="elem.sig_image">
                                                <img
                                                    :src="resolveImage(elem.sig_image)"
                                                    :style="`
                                                        height: ${(elem.sig_height_px || 50)}px;
                                                        width: auto;
                                                        position: relative;
                                                        z-index: 10;
                                                        display: block;
                                                        margin: ${elem.align === 'left' ? '0' : (elem.align === 'right' ? '0 0 0 auto' : '0 auto')};
                                                    `"
                                                    alt="Tanda Tangan"
                                                    x-on:error="$event.target.style.display = 'none'"
                                                />
                                            </template>
                                        </div>

                                        <p class="m-0 font-bold underline relative z-10 leading-normal" style="margin-top: 2px; font-size: 12pt;" x-html="resolveText(elem.name_text)"></p>
                                    </div>
                                </template>

                                <!-- 8 Sizing Handles & Rotation Handle Overlay (Rendered Only for Selected Element) -->
                                <template x-if="selectedElementId === elem.id">
                                    <div class="absolute inset-0 pointer-events-auto">
                                        
                                        <!-- Rotation Stem & Handle -->
                                        <div class="absolute -top-7 left-1/2 -translate-x-1/2 flex flex-col items-center cursor-grab" @mousedown.stop="startRotate($event)">
                                            <div class="w-3.5 h-3.5 rounded-full bg-blue-600 border-2 border-white shadow flex items-center justify-center hover:scale-125 transition-transform" title="Putar Elemen (Drag untuk Rotasi)">
                                                <svg class="h-2 w-2 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
                                            </div>
                                            <div class="w-0.5 h-3.5 bg-blue-500"></div>
                                        </div>

                                        <!-- 8 Sizing Handles -->
                                        <!-- Top-Left (NW) -->
                                        <div class="canvas-handle absolute -top-1.5 -left-1.5 w-3 h-3 bg-white border-2 border-blue-600 rounded-sm cursor-nwse-resize shadow hover:bg-blue-100" @mousedown.stop="startResize($event, 'nw')"></div>
                                        <!-- Top-Center (N) -->
                                        <div class="canvas-handle absolute -top-1.5 left-1/2 -translate-x-1/2 w-3 h-3 bg-white border-2 border-blue-600 rounded-sm cursor-ns-resize shadow hover:bg-blue-100" @mousedown.stop="startResize($event, 'n')"></div>
                                        <!-- Top-Right (NE) -->
                                        <div class="canvas-handle absolute -top-1.5 -right-1.5 w-3 h-3 bg-white border-2 border-blue-600 rounded-sm cursor-nesw-resize shadow hover:bg-blue-100" @mousedown.stop="startResize($event, 'ne')"></div>
                                        <!-- Middle-Right (E) -->
                                        <div class="canvas-handle absolute top-1/2 -right-1.5 -translate-y-1/2 w-3 h-3 bg-white border-2 border-blue-600 rounded-sm cursor-ew-resize shadow hover:bg-blue-100" @mousedown.stop="startResize($event, 'e')"></div>
                                        <!-- Bottom-Right (SE) -->
                                        <div class="canvas-handle absolute -bottom-1.5 -right-1.5 w-3 h-3 bg-white border-2 border-blue-600 rounded-sm cursor-nwse-resize shadow hover:bg-blue-100" @mousedown.stop="startResize($event, 'se')"></div>
                                        <!-- Bottom-Center (S) -->
                                        <div class="canvas-handle absolute -bottom-1.5 left-1/2 -translate-x-1/2 w-3 h-3 bg-white border-2 border-blue-600 rounded-sm cursor-ns-resize shadow hover:bg-blue-100" @mousedown.stop="startResize($event, 's')"></div>
                                        <!-- Bottom-Left (SW) -->
                                        <div class="canvas-handle absolute -bottom-1.5 -left-1.5 w-3 h-3 bg-white border-2 border-blue-600 rounded-sm cursor-nesw-resize shadow hover:bg-blue-100" @mousedown.stop="startResize($event, 'sw')"></div>
                                        <!-- Middle-Left (W) -->
                                        <div class="canvas-handle absolute top-1/2 -left-1.5 -translate-y-1/2 w-3 h-3 bg-white border-2 border-blue-600 rounded-sm cursor-ew-resize shadow hover:bg-blue-100" @mousedown.stop="startResize($event, 'w')"></div>

                                    </div>
                                </template>

                            </div>
                        </template>

                    </div>

                </div>

            </div>

            <!-- Canvas Info Helper Bar -->
            <div class="flex items-center justify-between text-xs text-slate-500 px-2 py-1">
                <span>Panduan: Klik elemen untuk menampilkan <strong>8 Titik Ukuran & Rotasi</strong>. Seret mouse untuk menggeser / mengubah ukuran.</span>
                <span class="font-mono text-[11px]" x-text="`Total Layer: ${layout.elements.length} | Terpilih: ${selectedElementId || 'None'}`"></span>
            </div>

        </div>

        <!-- Right: Inspector Drawer & Controls (4 Cols) -->
        <div class="lg:col-span-4 space-y-4">
            
            <!-- Inspector Tabs -->
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
                        Susunan Layer
                    </button>
                    <button
                        type="button"
                        @click="activeDrawerTab = 'canvas'"
                        class="flex-1 py-2.5 text-xs font-semibold text-center border-b-2 transition"
                        :class="activeDrawerTab === 'canvas' ? 'border-blue-600 text-blue-600 bg-white' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    >
                        Kertas & Desain
                    </button>
                </div>

                <!-- Tab 1: Element Properties Inspector -->
                <div x-show="activeDrawerTab === 'element'" class="p-4 space-y-4 max-h-[750px] overflow-y-auto">
                    <template x-if="selectedElement">
                        <div class="space-y-4">
                            
                            <!-- Header & Type Badge -->
                            <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                                <div class="flex items-center gap-1.5">
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-mono font-bold text-slate-700 uppercase" x-text="selectedElement.type"></span>
                                    <span class="text-xs font-bold text-slate-800 truncate max-w-[130px]" x-text="selectedElement.id"></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        @click="duplicateSelected()"
                                        class="text-xs text-blue-600 hover:text-blue-800 font-semibold"
                                    >
                                        Duplikat
                                    </button>
                                    <button
                                        type="button"
                                        @click="deleteElement(selectedElement.id)"
                                        class="text-xs text-rose-600 hover:text-rose-800 font-semibold"
                                    >
                                        Hapus
                                    </button>
                                </div>
                            </div>

                            <!-- Geometry & Position Spinners (X, Y, W, H, Rotation, Z-Index) -->
                            <div class="rounded-lg bg-slate-50 border border-slate-200 p-3 space-y-2.5">
                                <div class="flex items-center justify-between">
                                    <span class="text-[11px] font-bold text-slate-700 uppercase tracking-wider">Geometri & Koordinat</span>
                                    <button
                                        type="button"
                                        @click="selectedElement.aspect_locked = !selectedElement.aspect_locked"
                                        class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded border transition"
                                        :class="selectedElement.aspect_locked ? 'bg-amber-100 text-amber-800 border-amber-300' : 'bg-white text-slate-600 border-slate-200'"
                                        title="Kunci Proporsi Aspek Rasio saat Resize"
                                    >
                                        <span x-text="selectedElement.aspect_locked ? '🔒 Terkunci' : '🔓 Bebas'"></span>
                                    </button>
                                </div>

                                <!-- Grid X & Y Spinners -->
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Posisi X (%)</label>
                                        <div class="flex rounded border border-slate-300 bg-white shadow-sm overflow-hidden">
                                            <input type="number" step="0.5" min="0" max="100" x-model.number="selectedElement.x_percent" @change="pushHistory()" class="w-full border-none p-1 text-xs text-slate-800 focus:ring-0" />
                                            <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                <button type="button" @click="stepProp('x_percent', 0.5, 0, 100)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▲</button>
                                                <button type="button" @click="stepProp('x_percent', -0.5, 0, 100)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▼</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Posisi Y (%)</label>
                                        <div class="flex rounded border border-slate-300 bg-white shadow-sm overflow-hidden">
                                            <input type="number" step="0.5" min="0" max="100" x-model.number="selectedElement.y_percent" @change="pushHistory()" class="w-full border-none p-1 text-xs text-slate-800 focus:ring-0" />
                                            <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                <button type="button" @click="stepProp('y_percent', 0.5, 0, 100)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▲</button>
                                                <button type="button" @click="stepProp('y_percent', -0.5, 0, 100)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▼</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Grid Width & Height Spinners -->
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Lebar (%)</label>
                                        <div class="flex rounded border border-slate-300 bg-white shadow-sm overflow-hidden">
                                            <input type="number" step="1" min="5" max="100" x-model.number="selectedElement.width_percent" @change="pushHistory()" class="w-full border-none p-1 text-xs text-slate-800 focus:ring-0" />
                                            <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                <button type="button" @click="stepProp('width_percent', 1, 5, 100)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▲</button>
                                                <button type="button" @click="stepProp('width_percent', -1, 5, 100)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▼</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Tinggi (% / px)</label>
                                        <div class="flex rounded border border-slate-300 bg-white shadow-sm overflow-hidden">
                                            <input type="number" step="1" min="2" max="100" x-model.number="selectedElement.height_percent" @change="pushHistory()" class="w-full border-none p-1 text-xs text-slate-800 focus:ring-0" />
                                            <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                <button type="button" @click="stepProp('height_percent', 1, 2, 100)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▲</button>
                                                <button type="button" @click="stepProp('height_percent', -1, 2, 100)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▼</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Rotation & Z-Index Spinners -->
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Rotasi (Derajat °)</label>
                                        <div class="flex rounded border border-slate-300 bg-white shadow-sm overflow-hidden">
                                            <input type="number" step="1" min="-180" max="180" x-model.number="selectedElement.rotation" @change="pushHistory()" class="w-full border-none p-1 text-xs text-slate-800 focus:ring-0" />
                                            <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                <button type="button" @click="stepProp('rotation', 5, -180, 180)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▲</button>
                                                <button type="button" @click="stepProp('rotation', -5, -180, 180)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▼</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Tingkat Layer (Z-Index)</label>
                                        <div class="flex rounded border border-slate-300 bg-white shadow-sm overflow-hidden">
                                            <input type="number" step="1" min="1" max="99" x-model.number="selectedElement.z_index" @change="pushHistory()" class="w-full border-none p-1 text-xs text-slate-800 focus:ring-0" />
                                            <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                <button type="button" @click="stepProp('z_index', 1, 1, 99)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▲</button>
                                                <button type="button" @click="stepProp('z_index', -1, 1, 99)" class="px-1 text-[9px] hover:bg-slate-200 text-slate-600">▼</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Type Specific Property Controls -->

                            <!-- 1. Text Properties -->
                            <template x-if="selectedElement.type === 'text'">
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Konten Teks / Variabel</label>
                                        <textarea
                                            x-model="selectedElement.content"
                                            rows="3"
                                            @change="pushHistory()"
                                            class="w-full rounded border border-slate-300 p-2 text-xs font-mono text-slate-800 focus:outline-none focus:border-blue-500"
                                        ></textarea>

                                        <!-- Quick Variable Tags -->
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            <button type="button" @click="insertTagToSelected('@{{pemagang.nama}}')" class="text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200 hover:bg-blue-100">+ Nama</button>
                                            <button type="button" @click="insertTagToSelected('@{{dokumen.nomor}}')" class="text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200 hover:bg-blue-100">+ No. Surat</button>
                                            <button type="button" @click="insertTagToSelected('@{{magang.divisi}}')" class="text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200 hover:bg-blue-100">+ Divisi</button>
                                            <button type="button" @click="insertTagToSelected('@{{magang.tanggal_mulai}}')" class="text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200 hover:bg-blue-100">+ Mulai</button>
                                            <button type="button" @click="insertTagToSelected('@{{magang.tanggal_selesai}}')" class="text-[10px] bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded border border-blue-200 hover:bg-blue-100">+ Selesai</button>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Font Family (Tipografi)</label>
                                        <select x-model="selectedElement.font_family" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                                            <optgroup label="✍️ Kaligrafi & Script (Nama Penerima)">
                                                <option value="'Great Vibes', cursive" style="font-family: 'Great Vibes', cursive; font-size: 15px;">Great Vibes (Kaligrafi Klasik Mewah)</option>
                                                <option value="'Alex Brush', cursive" style="font-family: 'Alex Brush', cursive; font-size: 15px;">Alex Brush (Elegan Halus & Resmi)</option>
                                                <option value="'Pinyon Script', cursive" style="font-family: 'Pinyon Script', cursive; font-size: 15px;">Pinyon Script (Royal & Prestisius)</option>
                                                <option value="'Allura', cursive" style="font-family: 'Allura', cursive; font-size: 15px;">Allura (Luwes & Bersih)</option>
                                                <option value="'Dancing Script', cursive" style="font-family: 'Dancing Script', cursive; font-size: 15px;">Dancing Script (Modern Kasual)</option>
                                                <option value="'Parisienne', cursive" style="font-family: 'Parisienne', cursive; font-size: 15px;">Parisienne (Artistik Romantis)</option>
                                            </optgroup>
                                            <optgroup label="🏛️ Serif Klasik & Formal (Judul & Legal)">
                                                <option value="Times New Roman" style="font-family: 'Times New Roman', serif;">Times New Roman (Standar Resmi)</option>
                                                <option value="'Playfair Display', serif" style="font-family: 'Playfair Display', serif;">Playfair Display (Mewah Modern)</option>
                                                <option value="'Cinzel', serif" style="font-family: 'Cinzel', serif;">Cinzel (Roman / Huruf Kapital Klasik)</option>
                                                <option value="'Cormorant Garamond', serif" style="font-family: 'Cormorant Garamond', serif;">Cormorant Garamond (Elegansi Kerajaan)</option>
                                                <option value="'Lora', serif" style="font-family: 'Lora', serif;">Lora (Serif Kontemporer)</option>
                                                <option value="'Merriweather', serif" style="font-family: 'Merriweather', serif;">Merriweather (Kokoh & Berwibawa)</option>
                                                <option value="'Prata', serif" style="font-family: 'Prata', serif;">Prata (Klasik Teardrop Elegan)</option>
                                                <option value="'Georgia', serif" style="font-family: Georgia, serif;">Georgia (Serif Tradisional)</option>
                                            </optgroup>
                                            <optgroup label="✨ Modern Sans-Serif (Webinar, Subtitle, Divisi)">
                                                <option value="'Montserrat', sans-serif" style="font-family: 'Montserrat', sans-serif;">Montserrat (Geometrik Profesional)</option>
                                                <option value="'Poppins', sans-serif" style="font-family: 'Poppins', sans-serif;">Poppins (Bulat Modern Populer)</option>
                                                <option value="'Inter', sans-serif" style="font-family: Inter, sans-serif;">Inter (Kompak & Netral)</option>
                                                <option value="'Raleway', sans-serif" style="font-family: 'Raleway', sans-serif;">Raleway (Tipis & Elegan)</option>
                                                <option value="'Oswald', sans-serif" style="font-family: 'Oswald', sans-serif;">Oswald (Tegas & Berani)</option>
                                                <option value="Arial" style="font-family: Arial, sans-serif;">Arial (Standar Bersih)</option>
                                            </optgroup>
                                            <optgroup label="🔢 Monospace (Nomor Seri / Kode Dokumen)">
                                                <option value="Courier New" style="font-family: 'Courier New', monospace;">Courier New (Mesin Tik Klasik)</option>
                                                <option value="'Space Mono', monospace" style="font-family: 'Space Mono', monospace;">Space Mono (Teknologi Modern)</option>
                                            </optgroup>
                                        </select>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Ukuran Font (pt)</label>
                                            <div class="flex rounded border border-slate-300 bg-white overflow-hidden">
                                                <input type="number" min="8" max="96" x-model.number="selectedElement.font_size_pt" @change="pushHistory()" class="w-full border-none p-1 text-xs" />
                                                <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                    <button type="button" @click="stepProp('font_size_pt', 1, 8, 96)" class="px-1 text-[9px] hover:bg-slate-200">▲</button>
                                                    <button type="button" @click="stepProp('font_size_pt', -1, 8, 96)" class="px-1 text-[9px] hover:bg-slate-200">▼</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Warna Teks</label>
                                            <div class="flex items-center gap-1.5">
                                                <input type="color" x-model="selectedElement.color" @change="pushHistory()" class="h-7 w-8 rounded border p-0.5 cursor-pointer" />
                                                <input type="text" x-model="selectedElement.color" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs font-mono" />
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Gaya Huruf</label>
                                            <select x-model="selectedElement.font_weight" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs mb-1">
                                                <option value="normal">Normal</option>
                                                <option value="600">Semi Bold (600)</option>
                                                <option value="bold">Bold (Tebal)</option>
                                            </select>
                                            <div class="flex gap-2">
                                                <label class="inline-flex items-center gap-1 text-[11px] text-slate-700 cursor-pointer">
                                                    <input type="checkbox" :checked="selectedElement.font_style === 'italic'" @change="selectedElement.font_style = $event.target.checked ? 'italic' : 'normal'; pushHistory()" class="rounded" />
                                                    <span>Italic</span>
                                                </label>
                                                <label class="inline-flex items-center gap-1 text-[11px] text-slate-700 cursor-pointer">
                                                    <input type="checkbox" x-model="selectedElement.underline" @change="pushHistory()" class="rounded" />
                                                    <span>Underline</span>
                                                </label>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Perataan</label>
                                            <select x-model="selectedElement.align" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                                                <option value="center">Tengah (Center)</option>
                                                <option value="left">Rata Kiri (Left)</option>
                                                <option value="right">Rata Kanan (Right)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <!-- 2. Image Properties (Mendukung Upload Bebas & Sinkron Google Drive) -->
                            <template x-if="selectedElement.type === 'image'">
                                <div class="space-y-3">
                                    <!-- Live Preview Thumbnail of Selected Image -->
                                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-2 flex items-center gap-3">
                                        <div class="h-14 w-14 rounded border border-slate-300 bg-white flex items-center justify-center p-1 shrink-0 overflow-hidden shadow-2xs">
                                            <img
                                                :src="resolveImage(selectedElement.src)"
                                                class="max-h-full max-w-full object-contain"
                                                alt="Preview Aset"
                                                x-on:error="$event.target.style.display = 'none'"
                                            />
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-[11px] font-bold text-slate-800 truncate" x-text="selectedElement.src?.startsWith('http') || selectedElement.src?.startsWith('/') ? 'Gambar Kustom / Upload' : (selectedElement.src || 'Aset Gambar')"></span>
                                            </div>
                                            <p class="text-[10px] text-slate-500 font-mono truncate mt-0.5" x-text="selectedElement.src || '-'"></p>
                                            <div class="mt-1 flex items-center gap-1 text-[10px]">
                                                <span class="inline-flex items-center gap-1 text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200 font-semibold" title="Tersinkronisasi dengan penyimpanan awan Google Drive">
                                                    <svg class="h-3 w-3 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                                                    Tersinkron Google Drive
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Unggah Berkas Gambar Bebas Langsung -->
                                    <div>
                                        <input
                                            type="file"
                                            id="upload-image-input"
                                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                            class="hidden"
                                            @change="uploadCustomImage($event)"
                                        />
                                        <label
                                            for="upload-image-input"
                                            class="w-full flex items-center justify-center gap-2 py-2 px-3 rounded-lg border-2 border-dashed border-blue-400 bg-blue-50/60 hover:bg-blue-100 text-blue-700 text-xs font-bold cursor-pointer transition shadow-xs"
                                            :class="isUploadingImage ? 'opacity-50 pointer-events-none' : ''"
                                        >
                                            <svg x-show="!isUploadingImage" class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                                                <polyline points="17 8 12 3 7 8"/>
                                                <line x1="12" y1="3" x2="12" y2="15"/>
                                            </svg>
                                            <span x-show="isUploadingImage" class="inline-block animate-spin h-3.5 w-3.5 border-2 border-blue-600 border-t-transparent rounded-full"></span>
                                            <span x-text="isUploadingImage ? 'Mengunggah & Menyinkronkan ke GDrive...' : '📁 Unggah Gambar Bebas (Sinkron GDrive)'"></span>
                                        </label>
                                        <p x-show="imageUploadError" x-text="imageUploadError" class="text-[10px] text-rose-600 mt-1 font-medium"></p>
                                        <p class="text-[10px] text-slate-400 mt-1">Mendukung format PNG transparan, JPG, WEBP, atau SVG (Maks. 10MB). Berkas otomatis diunggah & dicadangkan ke Google Drive.</p>
                                    </div>

                                    <!-- Sumber Gambar Manual / Tag -->
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Tag Variabel atau URL Gambar</label>
                                        <input type="text" x-model="selectedElement.src" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs font-mono" placeholder="@{{asset.company.logo}} atau https://..." />
                                        
                                        <!-- Quick Asset Presets -->
                                        <div class="mt-1.5 flex flex-wrap gap-1">
                                            <button type="button" @click="selectedElement.src = '@{{asset.company.logo}}'; pushHistory()" class="text-[10px] bg-slate-100 hover:bg-slate-200 px-1.5 py-0.5 rounded border text-slate-700">+ Logo Utama</button>
                                            <button type="button" @click="selectedElement.src = '@{{asset.stamp}}'; pushHistory()" class="text-[10px] bg-slate-100 hover:bg-slate-200 px-1.5 py-0.5 rounded border text-slate-700">+ Stempel</button>
                                            <button type="button" @click="selectedElement.src = '@{{asset.signature}}'; pushHistory()" class="text-[10px] bg-slate-100 hover:bg-slate-200 px-1.5 py-0.5 rounded border text-slate-700">+ Tanda Tangan</button>
                                        </div>
                                    </div>

                                    <!-- Pilihan Gambar Cepat dari Katalog Aset Sistem / Brand yang Tersedia -->
                                    <template x-if="imageCatalog && imageCatalog.length > 0">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Pilih Cepat dari Aset Sistem / Brand</label>
                                            <div class="grid grid-cols-4 gap-1.5 max-h-36 overflow-y-auto p-1 rounded border border-slate-200 bg-slate-50">
                                                <template x-for="img in imageCatalog" :key="img.id">
                                                    <button
                                                        type="button"
                                                        @click="selectedElement.src = img.url; pushHistory()"
                                                        class="flex flex-col items-center justify-center p-1 rounded border bg-white hover:border-blue-500 hover:shadow-xs transition"
                                                        :class="selectedElement.src === img.url ? 'ring-2 ring-blue-500 border-blue-500' : 'border-slate-200'"
                                                        :title="img.name"
                                                    >
                                                        <img :src="img.thumb || img.url" class="h-8 w-8 object-contain" :alt="img.name" />
                                                        <span class="text-[9px] text-slate-600 truncate w-full text-center mt-0.5" x-text="img.name"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>

                                    <!-- Slider Opasitas / Transparansi -->
                                    <div>
                                        <div class="flex items-center justify-between mb-1">
                                            <label class="text-[11px] font-semibold text-slate-600">Opasitas / Transparansi</label>
                                            <span class="text-[10px] font-mono text-slate-500" x-text="`${Math.round((selectedElement.opacity ?? 1.0) * 100)}%`"></span>
                                        </div>
                                        <input type="range" min="0.05" max="1" step="0.05" x-model.number="selectedElement.opacity" @change="pushHistory()" class="w-full accent-blue-600" />
                                    </div>
                                </div>
                            </template>

                            <!-- 3. Shape Properties -->
                            <template x-if="selectedElement.type === 'shape'">
                                <div class="space-y-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Warna Isi (Fill)</label>
                                            <div class="flex items-center gap-1.5">
                                                <input type="color" x-model="selectedElement.bg_color" @change="pushHistory()" class="h-7 w-8 rounded border p-0.5 cursor-pointer" />
                                                <input type="text" x-model="selectedElement.bg_color" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs font-mono" />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Warna Garis Tepi</label>
                                            <div class="flex items-center gap-1.5">
                                                <input type="color" x-model="selectedElement.border_color" @change="pushHistory()" class="h-7 w-8 rounded border p-0.5 cursor-pointer" />
                                                <input type="text" x-model="selectedElement.border_color" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs font-mono" />
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Ketebalan Garis (px)</label>
                                            <div class="flex rounded border border-slate-300 bg-white overflow-hidden">
                                                <input type="number" min="0" max="20" x-model.number="selectedElement.border_width_px" @change="pushHistory()" class="w-full border-none p-1 text-xs" />
                                                <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                    <button type="button" @click="stepProp('border_width_px', 1, 0, 20)" class="px-1 text-[9px] hover:bg-slate-200">▲</button>
                                                    <button type="button" @click="stepProp('border_width_px', -1, 0, 20)" class="px-1 text-[9px] hover:bg-slate-200">▼</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Sudut Melengkung (px)</label>
                                            <div class="flex rounded border border-slate-300 bg-white overflow-hidden">
                                                <input type="number" min="0" max="50" x-model.number="selectedElement.border_radius_px" @change="pushHistory()" class="w-full border-none p-1 text-xs" />
                                                <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                    <button type="button" @click="stepProp('border_radius_px', 2, 0, 50)" class="px-1 text-[9px] hover:bg-slate-200">▲</button>
                                                    <button type="button" @click="stepProp('border_radius_px', -2, 0, 50)" class="px-1 text-[9px] hover:bg-slate-200">▼</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Gaya Garis Tepi</label>
                                        <select x-model="selectedElement.border_style" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                                            <option value="solid">Solid (Garis Penuh)</option>
                                            <option value="dashed">Dashed (Putus-putus)</option>
                                            <option value="dotted">Dotted (Titik-titik)</option>
                                            <option value="double">Double (Ganda)</option>
                                        </select>
                                    </div>
                                </div>
                            </template>

                            <!-- 4. Line Properties -->
                            <template x-if="selectedElement.type === 'line'">
                                <div class="space-y-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Warna Garis</label>
                                            <div class="flex items-center gap-1.5">
                                                <input type="color" x-model="selectedElement.line_color" @change="pushHistory()" class="h-7 w-8 rounded border p-0.5 cursor-pointer" />
                                                <input type="text" x-model="selectedElement.line_color" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs font-mono" />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Ketebalan (px)</label>
                                            <div class="flex rounded border border-slate-300 bg-white overflow-hidden">
                                                <input type="number" min="1" max="20" x-model.number="selectedElement.thickness_px" @change="pushHistory()" class="w-full border-none p-1 text-xs" />
                                                <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                    <button type="button" @click="stepProp('thickness_px', 1, 1, 20)" class="px-1 text-[9px] hover:bg-slate-200">▲</button>
                                                    <button type="button" @click="stepProp('thickness_px', -1, 1, 20)" class="px-1 text-[9px] hover:bg-slate-200">▼</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Gaya Garis</label>
                                        <select x-model="selectedElement.line_style" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                                            <option value="solid">Solid (Garis Lurus)</option>
                                            <option value="dashed">Dashed (Putus-putus)</option>
                                            <option value="dotted">Dotted (Titik-titik)</option>
                                        </select>
                                    </div>
                                </div>
                            </template>

                            <!-- 5. QR Code Properties -->
                            <template x-if="selectedElement.type === 'qr_code'">
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Token Data / URL Verifikasi</label>
                                        <input type="text" x-model="selectedElement.qr_data" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs font-mono" placeholder="@{{dokumen.nomor}}" />
                                        <p class="text-[10px] text-slate-500 mt-1">Sistem secara otomatis meng-encode nomor sertifikat atau link verifikasi resmi ke matriks QR Code.</p>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Ukuran QR (px)</label>
                                            <div class="flex rounded border border-slate-300 bg-white overflow-hidden">
                                                <input type="number" min="40" max="250" step="5" x-model.number="selectedElement.size_px" @change="pushHistory()" class="w-full border-none p-1 text-xs" />
                                                <div class="flex flex-col border-l border-slate-200 bg-slate-100">
                                                    <button type="button" @click="stepProp('size_px', 5, 40, 250)" class="px-1 text-[9px] hover:bg-slate-200">▲</button>
                                                    <button type="button" @click="stepProp('size_px', -5, 40, 250)" class="px-1 text-[9px] hover:bg-slate-200">▼</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Warna Kode</label>
                                            <div class="flex items-center gap-1.5">
                                                <input type="color" x-model="selectedElement.fg_color" @change="pushHistory()" class="h-7 w-8 rounded border p-0.5 cursor-pointer" />
                                                <input type="text" x-model="selectedElement.fg_color" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs font-mono" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <!-- 6. Signature Group Properties -->
                            <template x-if="selectedElement.type === 'signature_group'">
                                <div class="space-y-3">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Perataan</label>
                                            <select x-model="selectedElement.align" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                                                <option value="center">Tengah (Center)</option>
                                                <option value="left">Rata Kiri (Left)</option>
                                                <option value="right">Rata Kanan (Right)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Font Family</label>
                                            <select x-model="selectedElement.font_family" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                                                <optgroup label="🏛️ Serif Klasik & Formal">
                                                    <option value="Times New Roman" style="font-family: 'Times New Roman', serif;">Times New Roman (Standar Resmi)</option>
                                                    <option value="'Playfair Display', serif" style="font-family: 'Playfair Display', serif;">Playfair Display (Mewah Modern)</option>
                                                    <option value="'Cinzel', serif" style="font-family: 'Cinzel', serif;">Cinzel (Roman / Huruf Kapital Klasik)</option>
                                                    <option value="'Cormorant Garamond', serif" style="font-family: 'Cormorant Garamond', serif;">Cormorant Garamond (Elegansi Kerajaan)</option>
                                                    <option value="'Lora', serif" style="font-family: 'Lora', serif;">Lora (Serif Kontemporer)</option>
                                                    <option value="'Merriweather', serif" style="font-family: 'Merriweather', serif;">Merriweather (Kokoh & Berwibawa)</option>
                                                    <option value="'Prata', serif" style="font-family: 'Prata', serif;">Prata (Klasik Teardrop Elegan)</option>
                                                    <option value="'Georgia', serif" style="font-family: Georgia, serif;">Georgia (Serif Tradisional)</option>
                                                </optgroup>
                                                <optgroup label="✨ Modern Sans-Serif">
                                                    <option value="'Montserrat', sans-serif" style="font-family: 'Montserrat', sans-serif;">Montserrat (Geometrik Profesional)</option>
                                                    <option value="'Poppins', sans-serif" style="font-family: 'Poppins', sans-serif;">Poppins (Bulat Modern Populer)</option>
                                                    <option value="'Inter', sans-serif" style="font-family: Inter, sans-serif;">Inter (Kompak & Netral)</option>
                                                    <option value="'Raleway', sans-serif" style="font-family: 'Raleway', sans-serif;">Raleway (Tipis & Elegan)</option>
                                                    <option value="'Oswald', sans-serif" style="font-family: 'Oswald', sans-serif;">Oswald (Tegas & Berani)</option>
                                                    <option value="Arial" style="font-family: Arial, sans-serif;">Arial (Standar Bersih)</option>
                                                </optgroup>
                                                <optgroup label="✍️ Kaligrafi & Script">
                                                    <option value="'Great Vibes', cursive" style="font-family: 'Great Vibes', cursive; font-size: 15px;">Great Vibes (Kaligrafi Klasik)</option>
                                                    <option value="'Alex Brush', cursive" style="font-family: 'Alex Brush', cursive; font-size: 15px;">Alex Brush (Elegan Halus)</option>
                                                    <option value="'Pinyon Script', cursive" style="font-family: 'Pinyon Script', cursive; font-size: 15px;">Pinyon Script (Royal Prestisius)</option>
                                                    <option value="'Allura', cursive" style="font-family: 'Allura', cursive; font-size: 15px;">Allura (Luwes & Bersih)</option>
                                                    <option value="'Dancing Script', cursive" style="font-family: 'Dancing Script', cursive; font-size: 15px;">Dancing Script (Modern Kasual)</option>
                                                    <option value="'Parisienne', cursive" style="font-family: 'Parisienne', cursive; font-size: 15px;">Parisienne (Artistik Romantis)</option>
                                                </optgroup>
                                                <optgroup label="🔢 Monospace">
                                                    <option value="Courier New" style="font-family: 'Courier New', monospace;">Courier New (Mesin Tik Klasik)</option>
                                                    <option value="'Space Mono', monospace" style="font-family: 'Space Mono', monospace;">Space Mono (Teknologi Modern)</option>
                                                </optgroup>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Nomor Sertifikat (Opsional)</label>
                                        <input type="text" x-model="selectedElement.cert_no_text" placeholder="Contoh: Nomor Sertifikat: @{{dokumen.nomor}}" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs font-mono" />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Teks Tanggal & Tempat</label>
                                        <input type="text" x-model="selectedElement.date_text" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Jabatan & Perusahaan</label>
                                        <input type="text" x-model="selectedElement.role_text" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Nama Penandatangan</label>
                                        <input type="text" x-model="selectedElement.name_text" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Tag Tanda Tangan</label>
                                            <input type="text" x-model="selectedElement.sig_image" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs font-mono mb-1" />
                                            <input type="file" id="sig-upload-input" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="hidden" @change="uploadCustomSignature($event)" />
                                            <label for="sig-upload-input" class="w-full inline-flex items-center justify-center gap-1 py-1 px-1.5 rounded border border-dashed border-blue-400 bg-blue-50/50 hover:bg-blue-100 text-[10px] text-blue-700 font-semibold cursor-pointer transition">
                                                <span x-text="isUploadingSig ? 'Mengunggah...' : '📁 Upload TTD (GDrive)'"></span>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Tag Stempel</label>
                                            <input type="text" x-model="selectedElement.stamp_image" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs font-mono mb-1" />
                                            <input type="file" id="stamp-upload-input" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="hidden" @change="uploadCustomStamp($event)" />
                                            <label for="stamp-upload-input" class="w-full inline-flex items-center justify-center gap-1 py-1 px-1.5 rounded border border-dashed border-blue-400 bg-blue-50/50 hover:bg-blue-100 text-[10px] text-blue-700 font-semibold cursor-pointer transition">
                                                <span x-text="isUploadingStamp ? 'Mengunggah...' : '📁 Upload Stempel (GDrive)'"></span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="block text-[10px] font-semibold text-slate-500 mb-0.5" title="Posisi horizontal stempel relatif terhadap kotak tanda tangan (negatif = geser kiri, positif = geser kanan)">Offset Stempel X (px)</label>
                                            <input type="number" step="5" x-model.number="selectedElement.stamp_offset_x" placeholder="10" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs" />
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-semibold text-slate-500 mb-0.5" title="Posisi vertikal stempel relatif terhadap kotak tanda tangan (negatif = naik ke atas, positif = turun ke bawah)">Offset Stempel Y (px)</label>
                                            <input type="number" step="5" x-model.number="selectedElement.stamp_offset_y" placeholder="-35" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs" />
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="block text-[10px] font-semibold text-slate-500 mb-0.5" title="Ukuran diameter stempel (default: 120px)">Ukuran Stempel (px)</label>
                                            <input type="number" min="40" max="250" step="5" x-model.number="selectedElement.stamp_size_px" placeholder="120" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs" />
                                        </div>
                                        <div>
                                            <label class="block text-[10px] font-semibold text-slate-500 mb-0.5" title="Ukuran tinggi tanda tangan (default: 50px, disarankan 40 - 120px)">Ukuran TTD / Tinggi (px)</label>
                                            <input type="number" min="25" max="180" step="5" x-model.number="selectedElement.sig_height_px" placeholder="50" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1 text-xs" />
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-1 pt-0.5">
                                        <button type="button" @click="selectedElement.sig_image = '@{{asset.signature}}'; pushHistory()" class="text-[10px] bg-slate-100 hover:bg-slate-200 px-1.5 py-0.5 rounded border text-slate-700">+ TTD Sistem</button>
                                        <button type="button" @click="selectedElement.stamp_image = '@{{asset.stamp}}'; pushHistory()" class="text-[10px] bg-slate-100 hover:bg-slate-200 px-1.5 py-0.5 rounded border text-slate-700">+ Stempel Sistem</button>
                                    </div>
                                </div>
                            </template>

                        </div>
                    </template>

                    <template x-if="!selectedElement">
                        <div class="p-8 text-center text-slate-400 text-xs">
                            <svg class="h-8 w-8 mx-auto text-slate-300 mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                            <p class="font-medium text-slate-600">Belum Ada Elemen Terpilih</p>
                            <p class="mt-1 text-slate-400">Klik salah satu objek pada kanvas atau pilih dari tab <strong>Susunan Layer</strong> untuk mengedit koordinat dan gayanya.</p>
                        </div>
                    </template>
                </div>

                <!-- Tab 2: Layer Stack Reorder & Management -->
                <div x-show="activeDrawerTab === 'layers'" class="p-4 space-y-3">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Susunan Layer</span>
                        <span class="text-xs text-slate-500 font-mono" x-text="`${layout.elements.length} Elemen`"></span>
                    </div>

                    <div class="space-y-1.5 max-h-[420px] overflow-y-auto pr-1">
                        <template x-for="(elem, idx) in layout.elements" :key="elem.id">
                            <div
                                class="flex items-center justify-between p-2 rounded-lg border transition cursor-pointer text-xs"
                                :class="selectedElementId === elem.id ? 'border-blue-500 bg-blue-50/70 font-semibold text-blue-900 shadow-sm' : 'border-slate-200 hover:bg-slate-50 text-slate-700'"
                                @click="selectElement(elem.id)"
                            >
                                <div class="flex items-center gap-2 truncate max-w-[180px]">
                                    <span class="font-mono text-[10px] text-slate-400" x-text="`#${idx+1}`"></span>
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase bg-slate-100 text-slate-600" x-text="elem.type"></span>
                                    <span class="truncate" x-text="elem.id"></span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button
                                        type="button"
                                        @click.stop="moveLayer(idx, -1)"
                                        title="Naikkan Layer"
                                        class="p-1 rounded hover:bg-slate-200 text-slate-600"
                                        :disabled="idx === 0"
                                    >&uarr;</button>
                                    <button
                                        type="button"
                                        @click.stop="moveLayer(idx, 1)"
                                        title="Turunkan Layer"
                                        class="p-1 rounded hover:bg-slate-200 text-slate-600"
                                        :disabled="idx === layout.elements.length - 1"
                                    >&darr;</button>
                                    <button
                                        type="button"
                                        @click.stop="deleteElement(elem.id)"
                                        title="Hapus Layer"
                                        class="p-1 rounded text-rose-500 hover:bg-rose-50 hover:text-rose-700"
                                    >&times;</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Tab 3: Paper Setup & Canvas Background -->
                <div x-show="activeDrawerTab === 'canvas'" class="p-4 space-y-4 max-h-[750px] overflow-y-auto">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                        <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Kertas & Latar Belakang</span>
                        <a href="{{ route('admin.system-assets.index') }}" target="_blank" class="text-[11px] font-semibold text-blue-600 hover:underline flex items-center gap-1">
                            <span>Perpustakaan Drive</span>
                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/></svg>
                        </a>
                    </div>

                    <!-- Orientasi Kertas -->
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Orientasi Kertas Cetak</label>
                        <select x-model="layout.canvas.orientation" @change="pushHistory()" class="w-full rounded border border-slate-300 p-1.5 text-xs">
                            <option value="landscape">A4 Landscape (297 x 210 mm) — Standar Sertifikat</option>
                            <option value="portrait">A4 Portrait (210 x 297 mm)</option>
                        </select>
                    </div>

                    <!-- Peruntukan / Jenis Sertifikat -->
                    <div class="rounded-lg border border-slate-200 bg-slate-50/70 p-2.5">
                        <label class="block text-[11px] font-semibold text-slate-700 mb-1">Peruntukan Sertifikat</label>
                        <select x-model="certificateType" class="w-full rounded border border-slate-300 p-1.5 text-xs bg-white">
                            <option value="magang">Sertifikat Magang (Kelulusan Pemagang)</option>
                            <option value="webinar">Sertifikat Webinar (Event / Workshop)</option>
                        </select>
                        <p class="text-[10px] text-slate-500 mt-1">Pilih "Sertifikat Webinar" jika template ini ingin digunakan pada modul Webinar.</p>
                    </div>

                    <!-- Pilihan Background Gallery (Drive & Sistem Assets) -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="block text-[11px] font-semibold text-slate-700 uppercase">Pilih Gambar Background</label>
                            <span class="text-[10px] text-slate-400" x-text="`${backgroundCatalog.length} Aset Tersedia`"></span>
                        </div>
                        <p class="text-[11px] text-slate-500 mb-2.5">Klik thumbnail untuk langsung mengganti latar belakang sertifikat di kanvas.</p>

                        <!-- Grid Cards -->
                        <div class="grid grid-cols-2 gap-2 max-h-[280px] overflow-y-auto pr-1 p-0.5">
                            <template x-for="(bg, idx) in backgroundCatalog" :key="bg.id || idx">
                                <div
                                    @click="selectBackground(bg.url)"
                                    class="relative rounded-lg border-2 p-1.5 cursor-pointer transition flex flex-col justify-between group"
                                    :class="isSelectedBg(bg.url) ? 'border-blue-600 ring-2 ring-blue-100 bg-blue-50/30' : 'border-slate-200 hover:border-blue-300 bg-white'"
                                >
                                    <div class="relative w-full h-16 rounded overflow-hidden bg-slate-100 border border-slate-200 mb-1">
                                        <img :src="bg.thumb || bg.url" :alt="bg.name" class="w-full h-full object-cover" x-on:error="$event.target.src='/images/certificate/certificate-bg.png'" />
                                        
                                        <!-- Active Checkmark Indicator -->
                                        <template x-if="isSelectedBg(bg.url)">
                                            <div class="absolute top-1 left-1 w-4 h-4 rounded-full bg-blue-600 text-white flex items-center justify-center shadow">
                                                <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                            </div>
                                        </template>

                                        <!-- Source Badge -->
                                        <template x-if="bg.is_drive">
                                            <span class="absolute top-1 right-1 inline-flex items-center gap-0.5 rounded bg-emerald-600 text-white text-[8px] font-bold px-1 py-0.5 shadow">
                                                <svg class="h-2 w-2" viewBox="0 0 24 24" fill="currentColor"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/></svg>
                                                Drive
                                            </span>
                                        </template>
                                        <template x-if="!bg.is_drive && bg.source && bg.source.startsWith('Brand')">
                                            <span class="absolute top-1 right-1 rounded bg-amber-600 text-white text-[8px] font-bold px-1 py-0.5 shadow">
                                                Brand
                                            </span>
                                        </template>
                                        <template x-if="!bg.is_drive && bg.is_real && (!bg.source || !bg.source.startsWith('Brand'))">
                                            <span class="absolute top-1 right-1 rounded bg-indigo-600 text-white text-[8px] font-bold px-1 py-0.5 shadow">
                                                Sistem
                                            </span>
                                        </template>
                                    </div>
                                    <div class="text-[10px] font-semibold text-slate-800 truncate" :title="bg.name" x-text="bg.name"></div>
                                    <div class="text-[9px] text-slate-400 mt-0.5" x-text="bg.source || 'Bawaan'"></div>
                                </div>
                            </template>

                            <!-- Option: Tanpa Background (Polos) -->
                            <div
                                @click="selectBackground('')"
                                class="relative rounded-lg border-2 p-2 cursor-pointer transition flex flex-col justify-center items-center text-center group"
                                :class="!layout?.canvas?.background_image ? 'border-blue-600 ring-2 ring-blue-100 bg-blue-50/30' : 'border-dashed border-slate-300 hover:border-blue-300 bg-white'"
                            >
                                <template x-if="!layout?.canvas?.background_image">
                                    <div class="absolute top-1 left-1 w-4 h-4 rounded-full bg-blue-600 text-white flex items-center justify-center shadow">
                                        <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                    </div>
                                </template>
                                <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-400 mb-1">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="1" y1="1" x2="23" y2="23"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                                </div>
                                <span class="text-[10px] font-bold text-slate-700">Latar Polos</span>
                                <span class="text-[8px] text-slate-400">Tanpa Gambar</span>
                            </div>
                        </div>

                        <!-- Upload Background Langsung dari Komputer / Drive -->
                        <div class="mt-2.5 pt-2.5 border-t border-slate-100">
                            <input
                                type="file"
                                id="upload-bg-input"
                                accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                class="hidden"
                                @change="uploadNewBackground($event)"
                            />
                            <label
                                for="upload-bg-input"
                                class="w-full flex items-center justify-center gap-1.5 py-1.5 px-3 rounded-lg border border-dashed border-blue-400 bg-blue-50/50 hover:bg-blue-100/60 text-blue-700 text-xs font-semibold cursor-pointer transition shadow-sm"
                                :class="isUploadingBg ? 'opacity-50 pointer-events-none' : ''"
                            >
                                <svg x-show="!isUploadingBg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <span x-show="isUploadingBg" class="inline-block animate-spin h-3.5 w-3.5 border-2 border-blue-600 border-t-transparent rounded-full"></span>
                                <span x-text="isUploadingBg ? 'Mengunggah Background...' : '+ Unggah Gambar Background Baru'"></span>
                            </label>
                            <p x-show="bgUploadError" x-text="bgUploadError" class="text-[10px] text-rose-600 mt-1 font-medium"></p>
                        </div>

                        <!-- Input URL / Link Eksternal -->
                        <div class="mt-2">
                            <label class="block text-[10px] font-semibold text-slate-500 mb-0.5">Atau Masukkan URL / Path Gambar:</label>
                            <div class="flex gap-1.5">
                                <input
                                    type="text"
                                    x-model="customBgUrl"
                                    placeholder="Contoh: /images/certificate/bg-custom.png"
                                    class="flex-1 rounded border border-slate-300 p-1 text-xs font-mono text-slate-800"
                                    @keydown.enter.prevent="applyCustomBgUrl()"
                                />
                                <button
                                    type="button"
                                    @click="applyCustomBgUrl()"
                                    class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded border"
                                >
                                    Terapkan
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Pengaturan Opasitas & Warna Dasar -->
                    <div class="rounded-lg bg-slate-50 border border-slate-200 p-2.5 space-y-2">
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-[10px] font-semibold text-slate-600">Opasitas Background</label>
                                <span class="text-[10px] font-mono text-slate-500" x-text="`${Math.round((layout.canvas.background_opacity ?? 1.0) * 100)}%`"></span>
                            </div>
                            <input
                                type="range"
                                min="0.1"
                                max="1.0"
                                step="0.05"
                                x-model.number="layout.canvas.background_opacity"
                                @change="pushHistory()"
                                class="w-full h-1.5 bg-slate-200 rounded-lg appearance-none cursor-pointer"
                            />
                        </div>
                        <div class="flex items-center justify-between pt-1 border-t border-slate-200">
                            <label class="text-[10px] font-semibold text-slate-600">Warna Dasar Kertas</label>
                            <div class="flex items-center gap-1.5">
                                <input type="color" x-model="layout.canvas.background_color" @change="pushHistory()" class="h-6 w-7 rounded border p-0.5 cursor-pointer" />
                                <span class="text-[10px] font-mono text-slate-600" x-text="layout.canvas.background_color || '#ffffff'"></span>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-3 space-y-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Nama Template</label>
                            <input type="text" x-model="templateName" class="w-full rounded border border-slate-300 p-1.5 text-xs" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Catatan Revisi</label>
                            <input type="text" x-model="changeSummary" placeholder="Contoh: Mengatur ulang posisi tanda tangan dan QR code..." class="w-full rounded border border-slate-300 p-1.5 text-xs" />
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
@php
    $defaultLayout = \App\Models\DocumentTemplate::getDefaultCertificateCanvasLayout();
    $dbLayout = $template->canvas_layout ?? [];
    $mergedCanvas = array_merge($defaultLayout['canvas'] ?? [], $dbLayout['canvas'] ?? []);
    $mergedElements = !empty($dbLayout['elements']) ? $dbLayout['elements'] : ($defaultLayout['elements'] ?? []);
    $initialLayout = [
        'canvas'   => $mergedCanvas,
        'elements' => $mergedElements,
    ];
@endphp
function certificateCanvasApp() {
    return {
        activeDrawerTab: 'element',
        templateName: @json($template->name),
        certificateType: @json($template->certificate_type ?? 'magang'),
        templateDescription: @json($template->description ?? ''),
        changeSummary: '',
        isActive: {{ $template->is_active ? 'true' : 'false' }},
        isDefault: {{ $template->is_default ? 'true' : 'false' }},
        showMockData: true,
        selectedElementId: 'elem_intern_name',
        isSaving: false,
        zoomLevel: 1.0,
        saveAlert: { message: '', type: 'success' },

        layout: @json($initialLayout),
        mockData: @json($mockData),
        backgroundCatalog: @json($assetCatalog['backgrounds'] ?? []),
        isUploadingBg: false,
        customBgUrl: '',
        bgUploadError: '',

        // Katalog Gambar Bebas & State Upload Gambar ke Google Drive
        imageCatalog: @json(array_values(array_merge($assetCatalog['logos'] ?? [], $assetCatalog['stamps'] ?? [], $assetCatalog['signatures'] ?? []))),
        isUploadingImage: false,
        imageUploadError: '',
        isUploadingSig: false,
        isUploadingStamp: false,

        // Undo / Redo history state stack
        historyStack: [],
        historyIndex: -1,

        init() {
            if (!this.layout) {
                this.layout = { canvas: { orientation: 'landscape', background_color: '#ffffff', background_image: '/images/certificate/certificate-bg.png' }, elements: [] };
            }
            if (!this.layout.canvas) {
                this.layout.canvas = { orientation: 'landscape', background_color: '#ffffff', background_image: '/images/certificate/certificate-bg.png' };
            }
            if (!Array.isArray(this.layout.elements)) {
                this.layout.elements = [];
            }
            // Ensure every element has normalized default fields and sizes
            this.layout.elements.forEach(elem => {
                if (elem.rotation === undefined) elem.rotation = 0;
                if (elem.aspect_locked === undefined) elem.aspect_locked = (elem.type === 'image' || elem.type === 'qr_code');
                if (elem.opacity === undefined) elem.opacity = 1.0;

                // Normalisasi font family untuk keselarasan dropdown & render
                if (elem.font_family) {
                    elem.font_family = this.normalizeFontFamily(elem.font_family);
                }

                // Normalisasi proporsi ukuran gambar/logo agar seimbang antara kanvas visual studio dan output PDF
                if (elem.type === 'image') {
                    if (elem.width_percent === undefined || elem.width_percent === null) {
                        elem.width_percent = elem.width_px ? Math.round((elem.width_px / 840) * 1000) / 10 : 16.5;
                    }
                    if (elem.height_percent === undefined || elem.height_percent === null) {
                        elem.height_percent = elem.height_px ? Math.round((elem.height_px / 594) * 1000) / 10 : 10.0;
                    }
                }

                // Normalisasi blok tanda tangan & stempel
                if (elem.type === 'signature_group') {
                    if (elem.width_percent === undefined || elem.width_percent === null) {
                        elem.width_percent = 45.0;
                    }
                    if (!elem.align) elem.align = 'center';
                    if (!elem.font_family) elem.font_family = 'Times New Roman';
                    if (elem.stamp_offset_x === undefined || elem.stamp_offset_x === null) elem.stamp_offset_x = 10;
                    if (elem.stamp_offset_y === undefined || elem.stamp_offset_y === null) elem.stamp_offset_y = -35;
                    if (elem.stamp_size_px === undefined || elem.stamp_size_px === null) elem.stamp_size_px = 120;
                    if (elem.sig_height_px === undefined || elem.sig_height_px === null) elem.sig_height_px = 50;
                }
            });
            if (!this.selectedElement && this.layout.elements.length > 0) {
                this.selectedElementId = this.layout.elements[0].id;
            }
            this.pushHistory();
        },

        selectBackground(url) {
            if (!this.layout.canvas) this.layout.canvas = {};
            this.layout.canvas.background_image = url;
            this.pushHistory();
        },

        isSelectedBg(bgUrl) {
            const current = this.layout?.canvas?.background_image || '';
            if (!bgUrl && !current) return true;
            if (!bgUrl || !current) return false;
            return current === bgUrl || current.includes(bgUrl) || bgUrl.includes(current);
        },

        applyCustomBgUrl() {
            if (!this.customBgUrl) return;
            this.selectBackground(this.customBgUrl.trim());
            this.customBgUrl = '';
        },

        async uploadNewBackground(event) {
            const file = event.target.files?.[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('file', file);
            formData.append('name', file.name.replace(/\.[^/.]+$/, ''));
            formData.append('_token', '{{ csrf_token() }}');

            this.isUploadingBg = true;
            this.bgUploadError = '';

            try {
                const res = await fetch('{{ route("admin.document-templates.api-upload-background") }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });

                const data = await res.json();
                if (res.ok && data.success && data.asset) {
                    this.backgroundCatalog.unshift(data.asset);
                    this.selectBackground(data.asset.url);
                    this.saveAlert = { message: data.message || 'Background berhasil diunggah.', type: 'success' };
                } else {
                    this.bgUploadError = data.message || 'Gagal mengunggah file background.';
                }
            } catch (err) {
                this.bgUploadError = 'Terjadi kesalahan jaringan: ' + err.message;
            } finally {
                this.isUploadingBg = false;
                event.target.value = '';
            }
        },

        async uploadCustomImage(event) {
            const file = event.target.files?.[0];
            if (!file) return;

            // Jika tidak ada elemen gambar yang aktif atau tipenya bukan gambar, buat baru
            if (!this.selectedElement || this.selectedElement.type !== 'image') {
                this.addImageElement();
            }

            const formData = new FormData();
            formData.append('file', file);
            formData.append('name', file.name.replace(/\.[^/.]+$/, ''));
            formData.append('category', 'logo');
            formData.append('_token', '{{ csrf_token() }}');

            this.isUploadingImage = true;
            this.imageUploadError = '';

            try {
                const res = await fetch('{{ route("admin.document-templates.api-upload-image") }}', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });

                const data = await res.json();
                if (res.ok && data.success && data.asset) {
                    if (this.selectedElement && this.selectedElement.type === 'image') {
                        this.selectedElement.src = data.asset.url;
                        this.pushHistory();
                    }
                    if (!Array.isArray(this.imageCatalog)) {
                        this.imageCatalog = [];
                    }
                    this.imageCatalog.unshift(data.asset);
                    this.saveAlert = { 
                        message: data.message || 'Gambar berhasil diunggah dan disinkronkan ke Google Drive.', 
                        type: 'success' 
                    };
                } else {
                    this.imageUploadError = data.message || 'Gagal mengunggah file gambar.';
                }
            } catch (err) {
                this.imageUploadError = 'Terjadi kesalahan jaringan: ' + err.message;
            } finally {
                this.isUploadingImage = false;
                event.target.value = '';
            }
        },

        async uploadCustomSignature(event) {
            const file = event.target.files?.[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('file', file);
            formData.append('name', 'ttd_' + file.name.replace(/\.[^/.]+$/, ''));
            formData.append('category', 'signature');
            formData.append('_token', '{{ csrf_token() }}');

            this.isUploadingSig = true;
            try {
                const res = await fetch('{{ route("admin.document-templates.api-upload-image") }}', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });
                const data = await res.json();
                if (res.ok && data.success && data.asset) {
                    if (this.selectedElement && this.selectedElement.type === 'signature_group') {
                        this.selectedElement.sig_image = data.asset.url;
                        this.pushHistory();
                    }
                    this.saveAlert = { message: data.message || 'Tanda tangan berhasil diunggah dan disinkronkan ke Google Drive.', type: 'success' };
                } else {
                    alert(data.message || 'Gagal mengunggah tanda tangan.');
                }
            } catch (err) {
                alert('Kesalahan jaringan: ' + err.message);
            } finally {
                this.isUploadingSig = false;
                event.target.value = '';
            }
        },

        async uploadCustomStamp(event) {
            const file = event.target.files?.[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('file', file);
            formData.append('name', 'stempel_' + file.name.replace(/\.[^/.]+$/, ''));
            formData.append('category', 'stamp');
            formData.append('_token', '{{ csrf_token() }}');

            this.isUploadingStamp = true;
            try {
                const res = await fetch('{{ route("admin.document-templates.api-upload-image") }}', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });
                const data = await res.json();
                if (res.ok && data.success && data.asset) {
                    if (this.selectedElement && this.selectedElement.type === 'signature_group') {
                        this.selectedElement.stamp_image = data.asset.url;
                        this.pushHistory();
                    }
                    this.saveAlert = { message: data.message || 'Stempel berhasil diunggah dan disinkronkan ke Google Drive.', type: 'success' };
                } else {
                    alert(data.message || 'Gagal mengunggah stempel.');
                }
            } catch (err) {
                alert('Kesalahan jaringan: ' + err.message);
            } finally {
                this.isUploadingStamp = false;
                event.target.value = '';
            }
        },

        get selectedElement() {
            if (!this.layout || !Array.isArray(this.layout.elements)) return null;
            return this.layout.elements.find(e => e.id === this.selectedElementId) || null;
        },

        get canUndo() {
            return this.historyIndex > 0;
        },

        get canRedo() {
            return this.historyIndex < this.historyStack.length - 1;
        },

        pushHistory() {
            const snap = JSON.stringify(this.layout);
            // Drop forward history if we mutated after undo
            if (this.historyIndex < this.historyStack.length - 1) {
                this.historyStack = this.historyStack.slice(0, this.historyIndex + 1);
            }
            this.historyStack.push(snap);
            if (this.historyStack.length > 30) {
                this.historyStack.shift();
            } else {
                this.historyIndex++;
            }
        },

        undo() {
            if (!this.canUndo) return;
            this.historyIndex--;
            this.layout = JSON.parse(this.historyStack[this.historyIndex]);
        },

        redo() {
            if (!this.canRedo) return;
            this.historyIndex++;
            this.layout = JSON.parse(this.historyStack[this.historyIndex]);
        },

        selectElement(id) {
            this.selectedElementId = id;
            this.activeDrawerTab = 'element';
        },

        stepProp(prop, delta, minVal, maxVal) {
            if (!this.selectedElement) return;
            let current = parseFloat(this.selectedElement[prop] ?? 0);
            let next = Math.round((current + delta) * 10) / 10;
            if (minVal !== undefined && next < minVal) next = minVal;
            if (maxVal !== undefined && next > maxVal) next = maxVal;
            this.selectedElement[prop] = next;
            this.pushHistory();
        },

        canvasStyle() {
            const orientation = this.layout?.canvas?.orientation || 'landscape';
            const isLandscape = (orientation !== 'portrait');
            const width = isLandscape ? 840 : 594;
            const height = isLandscape ? 594 : 840;
            const bgColor = this.layout?.canvas?.background_color || '#ffffff';
            return `width: ${width}px; height: ${height}px; background-color: ${bgColor};`;
        },

        elementOuterStyle(elem) {
            const x = elem.x_percent ?? 50;
            const y = elem.y_percent ?? 50;

            // Resolusi lebar proporsional: prioritaskan width_percent, fallback konversi width_px ke % kanvas 840px
            let width;
            if (elem.width_percent !== undefined && elem.width_percent !== null) {
                width = `${elem.width_percent}%`;
            } else if (elem.width_px) {
                width = `${Math.round((elem.width_px / 840) * 1000) / 10}%`;
            } else if (elem.type === 'image') {
                width = '16.5%';
            } else if (elem.type === 'qr_code') {
                width = '14%';
            } else if (elem.type === 'signature_group') {
                width = '45%';
            } else {
                width = '50%';
            }

            // Resolusi tinggi: prioritaskan height_percent, fallback konversi height_px ke % kanvas 594px
            let height = 'auto';
            if (elem.height_percent !== undefined && elem.height_percent !== null) {
                height = `${elem.height_percent}%`;
            } else if (elem.height_px) {
                height = `${Math.round((elem.height_px / 594) * 1000) / 10}%`;
            } else if (elem.type === 'image') {
                height = '10%';
            }

            const align = elem.align ?? 'center';
            const rot = elem.rotation ?? 0;

            let alignTranslate = 'translate(-50%, -50%)';
            if (align === 'left') alignTranslate = 'translate(0, -50%)';
            if (align === 'right') alignTranslate = 'translate(-100%, -50%)';

            const transform = `${alignTranslate} rotate(${rot}deg)`;

            return `
                left: ${x}%;
                top: ${y}%;
                width: ${width};
                height: ${height};
                transform: ${transform};
                z-index: ${elem.z_index || 10};
                box-sizing: border-box;
            `;
        },

        formatFontFamily(font) {
            if (!font) return "'Times New Roman', serif";
            font = (font + '').trim();
            if (font.includes(',')) {
                return font;
            }
            const clean = font.replace(/^['"]|['"]$/g, '');
            const scripts = ['Great Vibes', 'Alex Brush', 'Pinyon Script', 'Allura', 'Dancing Script', 'Parisienne'];
            const sans = ['Montserrat', 'Poppins', 'Inter', 'Raleway', 'Oswald', 'Arial', 'Helvetica'];
            const monos = ['Space Mono', 'Courier New', 'Courier'];
            if (scripts.includes(clean)) return `'${clean}', cursive`;
            if (sans.includes(clean)) return `'${clean}', sans-serif`;
            if (monos.includes(clean)) return `'${clean}', monospace`;
            return `'${clean}', serif`;
        },

        normalizeFontFamily(font) {
            if (!font) return 'Times New Roman';
            const fLower = (font + '').toLowerCase();
            if (fLower.includes('great vibes')) return "'Great Vibes', cursive";
            if (fLower.includes('alex brush')) return "'Alex Brush', cursive";
            if (fLower.includes('pinyon script')) return "'Pinyon Script', cursive";
            if (fLower.includes('allura')) return "'Allura', cursive";
            if (fLower.includes('dancing script')) return "'Dancing Script', cursive";
            if (fLower.includes('parisienne')) return "'Parisienne', cursive";
            if (fLower.includes('playfair display')) return "'Playfair Display', serif";
            if (fLower.includes('cinzel')) return "'Cinzel', serif";
            if (fLower.includes('cormorant garamond')) return "'Cormorant Garamond', serif";
            if (fLower.includes('lora')) return "'Lora', serif";
            if (fLower.includes('merriweather')) return "'Merriweather', serif";
            if (fLower.includes('prata')) return "'Prata', serif";
            if (fLower.includes('georgia')) return "'Georgia', serif";
            if (fLower.includes('montserrat')) return "'Montserrat', sans-serif";
            if (fLower.includes('poppins')) return "'Poppins', sans-serif";
            if (fLower.includes('inter')) return "'Inter', sans-serif";
            if (fLower.includes('raleway')) return "'Raleway', sans-serif";
            if (fLower.includes('oswald')) return "'Oswald', sans-serif";
            if (fLower.includes('space mono')) return "'Space Mono', monospace";
            if (fLower.includes('courier new')) return 'Courier New';
            if (fLower.includes('arial')) return 'Arial';
            if (fLower.includes('times new roman') || fLower.includes('times')) return 'Times New Roman';
            return font;
        },

        elementTextStyle(elem) {
            const align = elem.align ?? 'center';
            const underline = elem.underline ? 'text-decoration: underline;' : '';
            return `
                text-align: ${align};
                font-family: ${this.formatFontFamily(elem.font_family)};
                font-size: ${elem.font_size_pt || 14}pt;
                font-weight: ${elem.font_weight || 'normal'};
                font-style: ${elem.font_style || 'normal'};
                color: ${elem.color || '#000000'};
                line-height: ${elem.line_height || 1.3};
                letter-spacing: ${elem.letter_spacing_px ? elem.letter_spacing_px + 'px' : 'normal'};
                opacity: ${elem.opacity ?? 1.0};
                ${underline}
                word-wrap: break-word;
            `;
        },

        elementShapeStyle(elem) {
            return `
                background-color: ${elem.bg_color || '#f1f5f9'};
                border: ${elem.border_width_px || 1}px ${elem.border_style || 'solid'} ${elem.border_color || '#cbd5e1'};
                border-radius: ${elem.border_radius_px || 4}px;
                opacity: ${elem.opacity ?? 1.0};
            `;
        },

        elementLineStyle(elem) {
            return `
                border-top: ${elem.thickness_px || 2}px ${elem.line_style || 'solid'} ${elem.line_color || '#94a3b8'};
                opacity: ${elem.opacity ?? 1.0};
                margin-top: 5px;
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
            let resolved = tagOrUrl;
            if (tagOrUrl.includes('asset.background')) {
                resolved = this.mockData?.asset?.background || '/images/certificate/certificate-bg.png';
            } else if (tagOrUrl.includes('asset.signature') || tagOrUrl.includes('signature')) {
                resolved = this.mockData?.asset?.signature || '/images/mou/signature-sample.png';
            } else if (tagOrUrl.includes('asset.stamp') || tagOrUrl.includes('stamp') || tagOrUrl.includes('stempel')) {
                resolved = this.mockData?.asset?.stamp || '/images/mou/stamp-sample.png';
            } else if (tagOrUrl.includes('company.logo') || tagOrUrl.includes('company_logo') || tagOrUrl.includes('asset.logo') || tagOrUrl.includes('brand.logo') || tagOrUrl.includes('logo')) {
                resolved = this.mockData?.asset?.company?.logo || '/images/mou/seven-inc-logo.png';
            } else if (tagOrUrl.includes('institution.logo') || tagOrUrl.includes('institusi.logo') || tagOrUrl.includes('institution_logo')) {
                resolved = this.mockData?.asset?.institution?.logo || '/images/mou/institution-logo-placeholder.png';
            }
            if (typeof resolved === 'string' && resolved.startsWith('/storage/images/')) {
                resolved = resolved.replace('/storage/images/', '/images/');
            }
            return resolved;
        },

        insertTagToSelected(tag) {
            if (this.selectedElement && this.selectedElement.type === 'text') {
                this.selectedElement.content += ' ' + tag;
                this.pushHistory();
            }
        },

        // --- Element Creation Helpers ---
        addTextElement() {
            const newId = 'elem_text_' + Date.now().toString().slice(-4);
            const newElem = {
                id: newId,
                type: 'text',
                content: 'Teks Baru @{{pemagang.nama}}',
                x_percent: 50.0,
                y_percent: 50.0,
                width_percent: 45.0,
                height_percent: null,
                font_family: 'Times New Roman',
                font_size_pt: 16,
                font_weight: 'normal',
                font_style: 'normal',
                color: '#1e293b',
                align: 'center',
                rotation: 0,
                z_index: 10 + this.layout.elements.length,
                opacity: 1.0,
            };
            this.layout.elements.push(newElem);
            this.selectElement(newId);
            this.pushHistory();
        },

        addImageElement() {
            const newId = 'elem_img_' + Date.now().toString().slice(-4);
            const newElem = {
                id: newId,
                type: 'image',
                src: '@{{asset.company.logo}}',
                x_percent: 50.0,
                y_percent: 10.0,
                width_percent: 16.5,
                height_percent: 10.0,
                width_px: 140,
                height_px: 60,
                align: 'center',
                rotation: 0,
                aspect_locked: true,
                z_index: 10 + this.layout.elements.length,
                opacity: 1.0,
            };
            this.layout.elements.push(newElem);
            this.selectElement(newId);
            this.pushHistory();
        },

        addShapeElement() {
            const newId = 'elem_box_' + Date.now().toString().slice(-4);
            const newElem = {
                id: newId,
                type: 'shape',
                x_percent: 50.0,
                y_percent: 50.0,
                width_percent: 30.0,
                height_percent: 15.0,
                bg_color: '#f8fafc',
                border_color: '#cbd5e1',
                border_width_px: 2,
                border_radius_px: 6,
                border_style: 'solid',
                align: 'center',
                rotation: 0,
                z_index: 5,
                opacity: 0.9,
            };
            this.layout.elements.push(newElem);
            this.selectElement(newId);
            this.pushHistory();
        },

        addLineElement() {
            const newId = 'elem_line_' + Date.now().toString().slice(-4);
            const newElem = {
                id: newId,
                type: 'line',
                x_percent: 50.0,
                y_percent: 45.0,
                width_percent: 60.0,
                height_percent: null,
                line_color: '#94a3b8',
                thickness_px: 2,
                line_style: 'solid',
                align: 'center',
                rotation: 0,
                z_index: 8,
                opacity: 1.0,
            };
            this.layout.elements.push(newElem);
            this.selectElement(newId);
            this.pushHistory();
        },

        addQrElement() {
            const newId = 'elem_qr_' + Date.now().toString().slice(-4);
            const newElem = {
                id: newId,
                type: 'qr_code',
                qr_data: '@{{dokumen.nomor}}',
                x_percent: 85.0,
                y_percent: 80.0,
                width_percent: 14.0,
                height_percent: 16.0,
                size_px: 90,
                fg_color: '#000000',
                bg_color: '#ffffff',
                align: 'center',
                rotation: 0,
                aspect_locked: true,
                z_index: 15,
                opacity: 1.0,
            };
            this.layout.elements.push(newElem);
            this.selectElement(newId);
            this.pushHistory();
        },

        addSignatureGroup() {
            const newId = 'elem_sig_' + Date.now().toString().slice(-4);
            const newElem = {
                id: newId,
                type: 'signature_group',
                date_text: '@{{dokumen.tempat}}, @{{dokumen.tanggal}}',
                role_text: '@{{perusahaan.jabatan}} @{{perusahaan.nama}}',
                name_text: '@{{perusahaan.pimpinan}}',
                sig_image: '@{{asset.signature}}',
                stamp_image: '@{{asset.stamp}}',
                stamp_offset_x: 10,
                stamp_offset_y: -35,
                stamp_size_px: 120,
                sig_height_px: 50,
                x_percent: 50.0,
                y_percent: 72.0,
                width_percent: 45.0,
                height_percent: null,
                font_family: 'Times New Roman',
                align: 'center',
                rotation: 0,
                z_index: 20,
                opacity: 1.0,
            };
            this.layout.elements.push(newElem);
            this.selectElement(newId);
            this.pushHistory();
        },

        duplicateSelected() {
            if (!this.selectedElement) return;
            const clone = JSON.parse(JSON.stringify(this.selectedElement));
            clone.id = 'elem_copy_' + Date.now().toString().slice(-4);
            clone.x_percent = Math.min(95, (clone.x_percent || 50) + 2);
            clone.y_percent = Math.min(95, (clone.y_percent || 50) + 2);
            clone.z_index = (clone.z_index || 10) + 1;
            this.layout.elements.push(clone);
            this.selectElement(clone.id);
            this.pushHistory();
        },

        deleteElement(id) {
            this.layout.elements = this.layout.elements.filter(e => e.id !== id);
            this.selectedElementId = this.layout.elements[0]?.id || null;
            this.pushHistory();
        },

        deleteSelected() {
            if (this.selectedElementId) {
                this.deleteElement(this.selectedElementId);
            }
        },

        moveLayer(index, direction) {
            const newIndex = index + direction;
            if (newIndex < 0 || newIndex >= this.layout.elements.length) return;
            const item = this.layout.elements.splice(index, 1)[0];
            this.layout.elements.splice(newIndex, 0, item);
            this.pushHistory();
        },

        bringToFront() {
            if (!this.selectedElement) return;
            const maxZ = Math.max(...this.layout.elements.map(e => e.z_index || 10), 10);
            this.selectedElement.z_index = maxZ + 1;
            this.pushHistory();
        },

        sendToBack() {
            if (!this.selectedElement) return;
            const minZ = Math.min(...this.layout.elements.map(e => e.z_index || 10), 10);
            this.selectedElement.z_index = Math.max(1, minZ - 1);
            this.pushHistory();
        },

        handleKeyboard(e) {
            // Ignore if active input or textarea
            const targetTag = (e.target.tagName || '').toLowerCase();
            if (targetTag === 'input' || targetTag === 'textarea' || targetTag === 'select') return;

            // Undo: Ctrl+Z
            if (e.ctrlKey && e.key.toLowerCase() === 'z' && !e.shiftKey) {
                e.preventDefault();
                this.undo();
                return;
            }
            // Redo: Ctrl+Y or Ctrl+Shift+Z
            if ((e.ctrlKey && e.key.toLowerCase() === 'y') || (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'z')) {
                e.preventDefault();
                this.redo();
                return;
            }
            // Duplicate: Ctrl+D
            if (e.ctrlKey && e.key.toLowerCase() === 'd') {
                e.preventDefault();
                this.duplicateSelected();
                return;
            }
            // Delete: Delete or Backspace
            if (e.key === 'Delete' || e.key === 'Backspace') {
                e.preventDefault();
                this.deleteSelected();
                return;
            }
        },

        // --- Pointer Drag & Interaction ---
        startDrag(event, elem) {
            this.selectElement(elem.id);
            const viewport = document.getElementById('certificate-viewport');
            const rect = viewport.getBoundingClientRect();
            const startX = event.clientX;
            const startY = event.clientY;
            const initX = elem.x_percent ?? 50;
            const initY = elem.y_percent ?? 50;
            const zoom = this.zoomLevel || 1.0;

            const onMouseMove = (e) => {
                const dxPx = (e.clientX - startX) / zoom;
                const dyPx = (e.clientY - startY) / zoom;
                const dxPercent = (dxPx / (rect.width / zoom)) * 100;
                const dyPercent = (dyPx / (rect.height / zoom)) * 100;

                let nextX = initX + dxPercent;
                let nextY = initY + dyPercent;

                nextX = Math.max(0, Math.min(100, Math.round(nextX * 10) / 10));
                nextY = Math.max(0, Math.min(100, Math.round(nextY * 10) / 10));

                elem.x_percent = nextX;
                elem.y_percent = nextY;
            };

            const onMouseUp = () => {
                window.removeEventListener('mousemove', onMouseMove);
                window.removeEventListener('mouseup', onMouseUp);
                this.pushHistory();
            };

            window.addEventListener('mousemove', onMouseMove);
            window.addEventListener('mouseup', onMouseUp);
        },

        // --- 8 Sizing Handles Interaction ---
        startResize(event, handle) {
            event.stopPropagation();
            const elem = this.selectedElement;
            if (!elem) return;
            const viewport = document.getElementById('certificate-viewport');
            const rect = viewport.getBoundingClientRect();
            const startX = event.clientX;
            const startY = event.clientY;
            const zoom = this.zoomLevel || 1.0;

            const initW = elem.width_percent ?? (elem.width_px ? Math.round((elem.width_px / 840) * 1000) / 10 : 16.5);
            const initH = elem.height_percent ?? (elem.height_px ? Math.round((elem.height_px / 594) * 1000) / 10 : 10.0);
            const initX = elem.x_percent ?? 50;
            const initY = elem.y_percent ?? 50;
            const initFontSize = elem.font_size_pt ?? 16;
            const isAspectLocked = elem.aspect_locked || event.shiftKey;
            const aspectRatio = initW / Math.max(1, initH);

            const onMouseMove = (e) => {
                const dxPx = (e.clientX - startX) / zoom;
                const dyPx = (e.clientY - startY) / zoom;
                const dxPercent = (dxPx / (rect.width / zoom)) * 100;
                const dyPercent = (dyPx / (rect.height / zoom)) * 100;

                let nextW = initW;
                let nextH = initH;
                let nextX = initX;
                let nextY = initY;

                if (handle.includes('e')) {
                    nextW = Math.max(5, initW + dxPercent);
                } else if (handle.includes('w')) {
                    nextW = Math.max(5, initW - dxPercent);
                    nextX = initX + (dxPercent / 2);
                }

                if (handle.includes('s')) {
                    nextH = Math.max(2, initH + dyPercent);
                } else if (handle.includes('n')) {
                    nextH = Math.max(2, initH - dyPercent);
                    nextY = initY + (dyPercent / 2);
                }

                if (isAspectLocked && (handle === 'nw' || handle === 'ne' || handle === 'se' || handle === 'sw')) {
                    nextH = nextW / aspectRatio;
                }

                elem.width_percent = Math.round(nextW * 10) / 10;
                elem.width_px = Math.round((elem.width_percent / 100) * 840);
                if (elem.type !== 'text') {
                    elem.height_percent = Math.round(nextH * 10) / 10;
                    elem.height_px = Math.round((elem.height_percent / 100) * 594);
                }
                if (elem.type === 'qr_code') {
                    elem.size_px = Math.round((elem.width_percent / 100) * 840);
                } else {
                    // Scale font size proportionally on diagonal drag for text
                    const fontRatio = nextW / initW;
                    elem.font_size_pt = Math.max(8, Math.min(96, Math.round(initFontSize * fontRatio)));
                }

                elem.x_percent = Math.max(0, Math.min(100, Math.round(nextX * 10) / 10));
                elem.y_percent = Math.max(0, Math.min(100, Math.round(nextY * 10) / 10));
            };

            const onMouseUp = () => {
                window.removeEventListener('mousemove', onMouseMove);
                window.removeEventListener('mouseup', onMouseUp);
                this.pushHistory();
            };

            window.addEventListener('mousemove', onMouseMove);
            window.addEventListener('mouseup', onMouseUp);
        },

        // --- Rotation Handle Interaction ---
        startRotate(event) {
            event.stopPropagation();
            const elem = this.selectedElement;
            if (!elem) return;
            const elemNode = document.getElementById('canvas-element-' + elem.id);
            if (!elemNode) return;
            const nodeRect = elemNode.getBoundingClientRect();
            const centerX = nodeRect.left + (nodeRect.width / 2);
            const centerY = nodeRect.top + (nodeRect.height / 2);

            const onMouseMove = (e) => {
                const rad = Math.atan2(e.clientY - centerY, e.clientX - centerX);
                let deg = Math.round((rad * (180 / Math.PI)) + 90);
                if (deg > 180) deg -= 360;
                if (deg < -180) deg += 360;

                // Snap to 0, 90, 180, -90 if close
                if (Math.abs(deg) <= 3) deg = 0;
                if (Math.abs(deg - 90) <= 3) deg = 90;
                if (Math.abs(deg + 90) <= 3) deg = -90;

                elem.rotation = deg;
            };

            const onMouseUp = () => {
                window.removeEventListener('mousemove', onMouseMove);
                window.removeEventListener('mouseup', onMouseUp);
                this.pushHistory();
            };

            window.addEventListener('mousemove', onMouseMove);
            window.addEventListener('mouseup', onMouseUp);
        },

        // --- Persistence / Save ---
        async saveTemplate() {
            this.isSaving = true;
            this.saveAlert.message = '';

            const payload = {
                _token: '{{ csrf_token() }}',
                _method: 'PUT',
                name: this.templateName,
                certificate_type: this.certificateType,
                description: this.templateDescription,
                canvas_layout: this.layout,
                page_config: {
                    orientation: this.layout.canvas.orientation || 'landscape',
                    paper_size: 'A4',
                    background_image: this.layout.canvas.background_image,
                },
                change_summary: this.changeSummary || 'Pembaruan tata letak visual canvas sertifikat',
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