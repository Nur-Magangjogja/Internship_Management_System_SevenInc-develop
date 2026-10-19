@extends('layouts.dashboard', ['title' => 'Editor Dokumen: ' . $template->name])

@section('content')
<div class="px-6 py-6" x-data="documentEditorApp()">
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
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $template->getTypeBadgeClass($template->type) }}">
                        {{ $template->getTypeLabel($template->type) }}
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
                <h1 class="text-xl font-bold text-slate-800 mt-1 flex items-center gap-2">
                    <span>{{ $template->name }}</span>
                </h1>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Versions History -->
            <a
                href="{{ route('admin.document-templates.versions', $template) }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 14 14"/>
                </svg>
                <span>Histori Versi ({{ $template->versions_count ?? 1 }})</span>
            </a>

            <!-- Live Browser Preview Modal Button -->
            <button
                type="button"
                @click="openPreviewModal()"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
            >
                <svg class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <span>Preview Browser</span>
            </button>

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

    <!-- Main Workspace Grid: Editor + Sidebar Drawer -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        
        <!-- Left: Formatting Toolbar + A4 Sheet (8 Cols) -->
        <div class="lg:col-span-8 space-y-4">
            
            <!-- Floating / Sticky Formatting Toolbar -->
            <div class="sticky top-20 z-10 rounded-xl border border-slate-200 bg-white p-2.5 shadow-sm">
                <div class="flex flex-wrap items-center gap-1 text-slate-700">
                    <!-- History -->
                    <button type="button" @click="execCmd('undo')" title="Undo (Ctrl+Z)" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
                    </button>
                    <button type="button" @click="execCmd('redo')" title="Redo (Ctrl+Y)" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3l3 2.7"/></svg>
                    </button>

                    <div class="h-4 w-px bg-slate-200 mx-1"></div>

                    <!-- Font Family -->
                    <select @change="execCmd('fontName', $event.target.value)" class="rounded border border-slate-200 py-1 px-2 text-xs bg-white text-slate-700 focus:outline-none focus:border-blue-500">
                        <option value="Times New Roman">Times New Roman</option>
                        <option value="Arial">Arial</option>
                        <option value="Calibri">Calibri</option>
                        <option value="Georgia">Georgia</option>
                        <option value="Courier New">Courier New</option>
                    </select>

                    <!-- Font Size / Heading -->
                    <select @change="formatBlockOrSize($event.target.value)" class="rounded border border-slate-200 py-1 px-2 text-xs bg-white text-slate-700 focus:outline-none focus:border-blue-500">
                        <option value="p">Paragraf Normal (11pt)</option>
                        <option value="h1">Judul Utama (H1)</option>
                        <option value="h2">Sub Judul (H2)</option>
                        <option value="h3">Bagian (H3)</option>
                    </select>

                    <div class="h-4 w-px bg-slate-200 mx-1"></div>

                    <!-- Text Styles -->
                    <button type="button" @click="execCmd('bold')" title="Tebal (Ctrl+B)" class="rounded p-1.5 hover:bg-slate-100 font-bold text-xs px-2">B</button>
                    <button type="button" @click="execCmd('italic')" title="Miring (Ctrl+I)" class="rounded p-1.5 hover:bg-slate-100 italic text-xs px-2">I</button>
                    <button type="button" @click="execCmd('underline')" title="Garis Bawah (Ctrl+U)" class="rounded p-1.5 hover:bg-slate-100 underline text-xs px-2">U</button>
                    <button type="button" @click="execCmd('strikeThrough')" title="Coretan" class="rounded p-1.5 hover:bg-slate-100 line-through text-xs px-2">S</button>

                    <div class="h-4 w-px bg-slate-200 mx-1"></div>

                    <!-- Alignment -->
                    <button type="button" @click="execCmd('justifyLeft')" title="Rata Kiri" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg>
                    </button>
                    <button type="button" @click="execCmd('justifyCenter')" title="Rata Tengah" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="10" x2="6" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="18" y1="18" x2="6" y2="18"/></svg>
                    </button>
                    <button type="button" @click="execCmd('justifyRight')" title="Rata Kanan" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="7" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="7" y2="18"/></svg>
                    </button>
                    <button type="button" @click="execCmd('justifyFull')" title="Rata Kanan Kiri (Justify)" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="21" y1="18" x2="3" y2="18"/></svg>
                    </button>

                    <div class="h-4 w-px bg-slate-200 mx-1"></div>

                    <!-- Lists -->
                    <button type="button" @click="execCmd('insertUnorderedList')" title="Daftar Poin (Bullet)" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    </button>
                    <button type="button" @click="execCmd('insertOrderedList')" title="Daftar Angka (Numbering)" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
                    </button>

                    <div class="h-4 w-px bg-slate-200 mx-1"></div>

                    <!-- Table & Dividers -->
                    <button type="button" @click="insertTable(3, 2)" title="Sisipkan Tabel 2 Kolom" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M12 3v18"/></svg>
                    </button>
                    <button type="button" @click="insertHorizontalRule()" title="Sisipkan Garis Pembatas Kop" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/></svg>
                    </button>
                    <button type="button" @click="execCmd('removeFormat')" title="Hapus Pemformatan" class="rounded p-1.5 hover:bg-slate-100 text-slate-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
                    </button>
                </div>
            </div>

            <!-- Realistic A4 Paper Simulator Wrapper -->
            <div class="flex justify-center bg-slate-200/70 p-6 rounded-2xl overflow-x-auto min-h-[850px] shadow-inner">
                <div
                    id="a4-paper-sheet"
                    contenteditable="true"
                    spellcheck="false"
                    class="bg-white text-black shadow-2xl transition-all focus:outline-none"
                    :style="`width: ${pageConfig.width_mm || 210}mm; min-height: ${pageConfig.height_mm || 297}mm; padding: ${pageConfig.margin_top_mm || 20}mm ${pageConfig.margin_right_mm || 20}mm ${pageConfig.margin_bottom_mm || 20}mm ${pageConfig.margin_left_mm || 20}mm; font-family: ${pageConfig.font_family || 'Times New Roman'}, serif; font-size: ${pageConfig.font_size_pt || 11}pt; line-height: ${pageConfig.line_height || 1.3};`"
                    x-ref="editorCanvas"
                    @input="onEditorInput()"
                >{!! $template->content_html !!}</div>
            </div>
            
            <div class="flex items-center justify-between text-xs text-slate-500 px-2">
                <span>Ukuran Lembar: <strong>A4 Portrait (210 x 297 mm)</strong></span>
                <span>Margin: <strong x-text="`${pageConfig.margin_top_mm || 20}mm T / ${pageConfig.margin_bottom_mm || 20}mm B / ${pageConfig.margin_left_mm || 20}mm L / ${pageConfig.margin_right_mm || 20}mm R`"></strong></span>
            </div>
        </div>

        <!-- Right: Control Sidebar Drawer (4 Cols) -->
        <div class="lg:col-span-4 space-y-4">
            
            <!-- Tab Switcher -->
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="flex border-b border-slate-200 bg-slate-50/70">
                    <button
                        type="button"
                        @click="activeTab = 'variables'"
                        class="flex-1 py-2.5 text-xs font-semibold text-center border-b-2 transition"
                        :class="activeTab === 'variables' ? 'border-blue-600 text-blue-600 bg-white' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    >
                        Mail Merge
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'assets'"
                        class="flex-1 py-2.5 text-xs font-semibold text-center border-b-2 transition"
                        :class="activeTab === 'assets' ? 'border-blue-600 text-blue-600 bg-white' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    >
                        Aset Media
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'page'"
                        class="flex-1 py-2.5 text-xs font-semibold text-center border-b-2 transition"
                        :class="activeTab === 'page' ? 'border-blue-600 text-blue-600 bg-white' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    >
                        Halaman
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'settings'"
                        class="flex-1 py-2.5 text-xs font-semibold text-center border-b-2 transition"
                        :class="activeTab === 'settings' ? 'border-blue-600 text-blue-600 bg-white' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    >
                        Pengaturan
                    </button>
                </div>

                <!-- Tab 1: Mail Merge Variables -->
                <div x-show="activeTab === 'variables'" class="p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Variabel Mail Merge</span>
                        <span class="text-[11px] text-slate-400">Klik untuk menyisipkan</span>
                    </div>

                    <!-- Search filter -->
                    <input
                        type="text"
                        x-model="placeholderSearch"
                        placeholder="Cari variabel (mis: nama, nomor)..."
                        class="w-full rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-700 placeholder-slate-400 focus:outline-none focus:border-blue-500"
                    />

                    <!-- Categorized Variable List -->
                    <div class="space-y-4 max-h-[500px] overflow-y-auto pr-1">
                        @php
                            $grouped = [];
                            foreach ($placeholders as $tag => $meta) {
                                $group = $meta['group'] ?? 'Lainnya';
                                $grouped[$group][$tag] = $meta;
                            }
                        @endphp

                        @foreach ($grouped as $groupName => $items)
                            <div>
                                <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">{{ $groupName }}</div>
                                <div class="space-y-1.5">
                                    @foreach ($items as $tag => $meta)
                                        <div
                                            x-show="matchesSearch('{{ $tag }} {{ $meta['label'] }} {{ $meta['example'] }}')"
                                            class="group flex items-center justify-between rounded-lg border border-slate-100 bg-slate-50 p-2 hover:border-blue-300 hover:bg-blue-50/50 transition cursor-pointer"
                                            @click="insertPlaceholder('{{ $tag }}')"
                                        >
                                            <div class="min-w-0 pr-2">
                                                <div class="font-mono text-xs font-bold text-blue-700 truncate">{{ $tag }}</div>
                                                <div class="text-[11px] text-slate-600">{{ $meta['label'] }}</div>
                                                <div class="text-[10px] text-slate-400 italic">Contoh: {{ $meta['example'] }}</div>
                                            </div>
                                            <button
                                                type="button"
                                                class="shrink-0 rounded bg-blue-100 px-2 py-1 text-[10px] font-semibold text-blue-700 opacity-0 group-hover:opacity-100 transition"
                                            >
                                                Sisip
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Tab 2: Asset Library -->
                <div x-show="activeTab === 'assets'" class="p-4 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Perpustakaan Aset Resmi</span>
                        <span class="text-[11px] text-slate-400">Pilih aset</span>
                    </div>

                    <div class="space-y-4 max-h-[500px] overflow-y-auto pr-1">
                        <!-- Logos -->
                        <div>
                            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Logo & Identitas</div>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($assetCatalog['logos'] ?? [] as $logo)
                                    <div
                                        class="rounded-lg border border-slate-200 p-2.5 text-center hover:border-blue-400 hover:bg-blue-50/30 transition cursor-pointer"
                                        @click="insertImage('{{ $logo['tag'] }}', '{{ $logo['name'] }}', 100)"
                                    >
                                        <img src="{{ $logo['thumb'] }}" alt="{{ $logo['name'] }}" class="h-10 mx-auto object-contain mb-1.5" />
                                        <div class="text-[11px] font-medium text-slate-700 truncate">{{ $logo['name'] }}</div>
                                        <span class="text-[10px] font-mono text-blue-600">{{ $logo['tag'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <!-- Signatures & Stamps -->
                        <div>
                            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-2">Tanda Tangan & Stempel</div>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($assetCatalog['signatures'] ?? [] as $sig)
                                    <div
                                        class="rounded-lg border border-slate-200 p-2.5 text-center hover:border-blue-400 hover:bg-blue-50/30 transition cursor-pointer"
                                        @click="insertImage('{{ $sig['tag'] }}', '{{ $sig['name'] }}', 120)"
                                    >
                                        <img src="{{ $sig['thumb'] }}" alt="{{ $sig['name'] }}" class="h-10 mx-auto object-contain mb-1.5" />
                                        <div class="text-[11px] font-medium text-slate-700 truncate">{{ $sig['name'] }}</div>
                                        <span class="text-[10px] font-mono text-blue-600">{{ $sig['tag'] }}</span>
                                    </div>
                                @endforeach

                                @foreach ($assetCatalog['stamps'] ?? [] as $stamp)
                                    <div
                                        class="rounded-lg border border-slate-200 p-2.5 text-center hover:border-blue-400 hover:bg-blue-50/30 transition cursor-pointer"
                                        @click="insertImage('{{ $stamp['tag'] }}', '{{ $stamp['name'] }}', 100)"
                                    >
                                        <img src="{{ $stamp['thumb'] }}" alt="{{ $stamp['name'] }}" class="h-10 mx-auto object-contain mb-1.5" />
                                        <div class="text-[11px] font-medium text-slate-700 truncate">{{ $stamp['name'] }}</div>
                                        <span class="text-[10px] font-mono text-blue-600">{{ $stamp['tag'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Page Setup -->
                <div x-show="activeTab === 'page'" class="p-4 space-y-4">
                    <div class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2">Konfigurasi Margin & Kertas</div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Margin Atas (mm)</label>
                            <input type="number" x-model.number="pageConfig.margin_top_mm" min="0" max="50" class="w-full rounded border border-slate-300 p-1.5 text-xs text-slate-700" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Margin Bawah (mm)</label>
                            <input type="number" x-model.number="pageConfig.margin_bottom_mm" min="0" max="50" class="w-full rounded border border-slate-300 p-1.5 text-xs text-slate-700" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Margin Kiri (mm)</label>
                            <input type="number" x-model.number="pageConfig.margin_left_mm" min="0" max="50" class="w-full rounded border border-slate-300 p-1.5 text-xs text-slate-700" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Margin Kanan (mm)</label>
                            <input type="number" x-model.number="pageConfig.margin_right_mm" min="0" max="50" class="w-full rounded border border-slate-300 p-1.5 text-xs text-slate-700" />
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-3 space-y-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Font Default Dokumen</label>
                            <select x-model="pageConfig.font_family" class="w-full rounded border border-slate-300 p-1.5 text-xs text-slate-700">
                                <option value="Times New Roman">Times New Roman</option>
                                <option value="Arial">Arial</option>
                                <option value="Calibri">Calibri</option>
                                <option value="Georgia">Georgia</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Ukuran Font Dasar (pt)</label>
                            <input type="number" x-model.number="pageConfig.font_size_pt" min="8" max="18" class="w-full rounded border border-slate-300 p-1.5 text-xs text-slate-700" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Jarak Baris (Line Height)</label>
                            <input type="number" step="0.1" x-model.number="pageConfig.line_height" min="1.0" max="2.5" class="w-full rounded border border-slate-300 p-1.5 text-xs text-slate-700" />
                        </div>
                    </div>
                </div>

                <!-- Tab 4: Template Settings -->
                <div x-show="activeTab === 'settings'" class="p-4 space-y-4">
                    <div class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2">Metadata & Status Template</div>

                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Nama Template</label>
                        <input type="text" x-model="templateName" class="w-full rounded border border-slate-300 p-2 text-xs text-slate-700" />
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Deskripsi</label>
                        <textarea x-model="templateDescription" rows="2" class="w-full rounded border border-slate-300 p-2 text-xs text-slate-700"></textarea>
                    </div>

                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Catatan Revisi Versi Baru</label>
                        <input type="text" x-model="changeSummary" placeholder="Contoh: Menyesuaikan alamat kantor & nomor telepon..." class="w-full rounded border border-slate-300 p-2 text-xs text-slate-700 placeholder-slate-400" />
                    </div>

                    <div class="border-t border-slate-100 pt-3 space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="isActive" class="rounded text-blue-600 focus:ring-blue-500">
                            <span class="text-xs font-medium text-slate-700">Aktifkan Template Ini</span>
                        </label>

                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="isDefault" class="rounded text-amber-500 focus:ring-amber-400">
                            <span class="text-xs font-medium text-slate-700">Jadikan Template Default untuk {{ $template->getTypeLabel($template->type) }}</span>
                        </label>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- Live Preview Modal -->
    <div x-show="isPreviewModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <div class="relative w-full max-w-4xl max-h-[90vh] bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-slate-800">Preview Dokumen (Data Sampel Pemagang)</span>
                    <span class="text-xs bg-blue-100 text-blue-800 font-semibold px-2 py-0.5 rounded">{{ $template->name }}</span>
                </div>
                <button type="button" @click="isPreviewModalOpen = false" class="text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto bg-slate-100 flex-1 flex justify-center">
                <div id="modal-preview-container" class="bg-white shadow p-8 max-w-[210mm] min-h-[297mm]">
                    <!-- Injected Preview HTML -->
                </div>
            </div>
            <div class="px-6 py-3 border-t border-slate-200 bg-slate-50 flex items-center justify-between">
                <span class="text-xs text-slate-500">Variabel Mail Merge telah disubstitusi dengan data contoh pemagang Seven Inc.</span>
                <button type="button" @click="isPreviewModalOpen = false" class="rounded-lg bg-slate-800 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-900">Tutup Preview</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function documentEditorApp() {
    return {
        activeTab: 'variables',
        placeholderSearch: '',
        templateName: @json($template->name),
        templateDescription: @json($template->description ?? ''),
        changeSummary: '',
        isActive: {{ $template->is_active ? 'true' : 'false' }},
        isDefault: {{ $template->is_default ? 'true' : 'false' }},
        pageConfig: @json(array_merge(\App\Models\DocumentTemplate::getDefaultPageConfig($template->type), $template->page_config ?? [])),
        isSaving: false,
        saveAlert: { message: '', type: 'success' },
        isPreviewModalOpen: false,

        matchesSearch(text) {
            if (!this.placeholderSearch.trim()) return true;
            return text.toLowerCase().includes(this.placeholderSearch.toLowerCase());
        },

        execCmd(command, value = null) {
            document.execCommand(command, false, value);
            this.$refs.editorCanvas.focus();
        },

        formatBlockOrSize(tag) {
            if (tag === 'p') {
                document.execCommand('formatBlock', false, '<p>');
            } else {
                document.execCommand('formatBlock', false, '<' + tag + '>');
            }
            this.$refs.editorCanvas.focus();
        },

        insertPlaceholder(token) {
            this.insertHtmlAtCursor('<span style="background-color: #e0f2fe; color: #0369a1; padding: 1px 4px; border-radius: 3px; font-weight: 500;">' + token + '</span>&nbsp;');
        },

        insertImage(tagOrUrl, altText, widthPx) {
            this.insertHtmlAtCursor('<img src="' + tagOrUrl + '" alt="' + altText + '" style="width: ' + widthPx + 'px; height: auto; display: inline-block; vertical-align: middle;" />&nbsp;');
        },

        insertHorizontalRule() {
            this.insertHtmlAtCursor('<div style="border-top: 3px solid #000; border-bottom: 1px solid #000; height: 2px; margin: 10px 0 16px 0;"></div><p></p>');
        },

        insertTable(rows, cols) {
            let html = '<table style="width: 100%; border-collapse: collapse; margin: 10px 0;">';
            for (let r = 0; r < rows; r++) {
                html += '<tr>';
                for (let c = 0; c < cols; c++) {
                    html += '<td style="padding: 6px 8px; border: 1px solid #cbd5e1;">Kolom ' + (c + 1) + '</td>';
                }
                html += '</tr>';
            }
            html += '</table><p></p>';
            this.insertHtmlAtCursor(html);
        },

        insertHtmlAtCursor(html) {
            this.$refs.editorCanvas.focus();
            const sel = window.getSelection();
            if (sel && sel.rangeCount > 0) {
                const range = sel.getRangeAt(0);
                range.deleteContents();
                const el = document.createElement("div");
                el.innerHTML = html;
                const frag = document.createDocumentFragment();
                let node, lastNode;
                while ((node = el.firstChild)) {
                    lastNode = frag.appendChild(node);
                }
                range.insertNode(frag);
                if (lastNode) {
                    const newRange = range.cloneRange();
                    newRange.setStartAfter(lastNode);
                    newRange.collapse(true);
                    sel.removeAllRanges();
                    sel.addRange(newRange);
                }
            } else {
                this.$refs.editorCanvas.innerHTML += html;
            }
        },

        onEditorInput() {
            // autosave buffer or dirty flag if desired
        },

        async saveTemplate() {
            this.isSaving = true;
            this.saveAlert.message = '';

            const contentHtml = this.$refs.editorCanvas.innerHTML;
            const payload = {
                _token: '{{ csrf_token() }}',
                _method: 'PUT',
                name: this.templateName,
                description: this.templateDescription,
                content_html: contentHtml,
                page_config: this.pageConfig,
                change_summary: this.changeSummary || 'Pembaruan isi dan tata letak dokumen',
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
                    this.saveAlert = { message: data.message || 'Gagal menyimpan template.', type: 'error' };
                }
            } catch (err) {
                this.saveAlert = { message: 'Terjadi kesalahan jaringan saat menyimpan template: ' + err.message, type: 'error' };
            } finally {
                this.isSaving = false;
            }
        },

        async openPreviewModal() {
            this.isPreviewModalOpen = true;
            const container = document.getElementById('modal-preview-container');
            container.innerHTML = '<div class="text-center p-8 text-slate-500">Memuat preview...</div>';

            try {
                const res = await fetch('{{ route("admin.document-templates.preview", $template) }}?raw=1');
                const html = await res.text();
                container.innerHTML = html;
            } catch (e) {
                container.innerHTML = '<div class="text-rose-500">Gagal memuat preview: ' + e.message + '</div>';
            }
        }
    };
}
</script>
@endpush
@endsection
