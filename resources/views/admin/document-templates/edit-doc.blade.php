@extends('layouts.dashboard', ['title' => 'Editor Dokumen: ' . $template->name])

@php
    $typeLabel = $template->getTypeLabel($template->type);
    $typeBadgeClass = $template->getTypeBadgeClass($template->type);
    $backUrl = route('admin.document-templates.index', ['type' => $template->type]);
@endphp

@section('content')
<div class="px-6 py-6 max-w-[1600px] mx-auto">
    <!-- Top Action Bar -->
    <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between border-b border-slate-200 pb-4">
        <div class="flex items-center gap-3">
            <a
                href="{{ $backUrl }}"
                class="rounded-lg border border-slate-200 p-2 text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition"
                title="Kembali ke Katalog Dokumen"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $typeBadgeClass }}">
                        {{ $typeLabel }}
                    </span>
                    <span id="docVersionBadge" class="text-xs font-mono bg-slate-100 text-slate-600 px-2 py-0.5 rounded font-semibold">
                        Versi {{ $template->version_number }}
                    </span>
                    @if ($template->is_default)
                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-800">
                            DEFAULT
                        </span>
                    @endif
                </div>
                <h1 class="text-xl font-bold text-slate-800 mt-1 flex items-center gap-2">
                    <span id="docHeadingName">{{ $template->name }}</span>
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
                id="btnOpenBrowserPreview"
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
                id="btnSaveDocumentTemplate"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:opacity-50"
            >
                <svg id="iconSaveNormal" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                <span id="spinnerSaving" class="hidden h-3.5 w-3.5 border-2 border-white border-t-transparent rounded-full animate-spin"></span>
                <span id="labelSaveBtn">Simpan Revisi</span>
            </button>
        </div>
    </div>

    <!-- Status Alert Banner -->
    <div id="docSaveAlert" class="hidden mb-4 rounded-lg p-3 text-xs font-medium"></div>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">
            <ul class="list-disc pl-5 space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Form Container -->
    <form id="documentTemplateForm" method="POST" action="{{ route('admin.document-templates.update', $template) }}" class="space-y-4">
        @csrf
        @method('PUT')

        <!-- Metadata Configuration Bar (Collapsible / Compact) -->
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">Informasi Template &amp; Catatan Revisi</span>
                <span class="text-[11px] text-slate-400">Pengaturan ini akan tersimpan otomatis bersama revisi naskah dokumen</span>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-3.5">
                <div class="md:col-span-4">
                    <label for="templateNameInput" class="block text-xs font-semibold text-slate-700">Nama Template <span class="text-rose-500">*</span></label>
                    <input
                        type="text"
                        id="templateNameInput"
                        name="name"
                        value="{{ old('name', $template->name) }}"
                        required
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div class="md:col-span-4">
                    <label for="templateDescInput" class="block text-xs font-semibold text-slate-700">Deskripsi / Peruntukan</label>
                    <input
                        type="text"
                        id="templateDescInput"
                        name="description"
                        value="{{ old('description', $template->description) }}"
                        placeholder="Contoh: Format resmi penerimaan pemagang aktif"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div class="md:col-span-4">
                    <label for="changeSummaryInput" class="block text-xs font-semibold text-slate-700">Catatan Perubahan (Revisi)</label>
                    <input
                        type="text"
                        id="changeSummaryInput"
                        name="change_summary"
                        placeholder="Contoh: Perapian tabel nilai dan penyesuaian margin A4"
                        class="mt-1 w-full rounded-lg border border-slate-300 p-2 text-xs text-slate-800 focus:border-blue-500 focus:outline-none"
                    >
                </div>

                <div class="md:col-span-12 flex flex-wrap items-center gap-6 pt-1 border-t border-slate-100 text-xs">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            name="is_active"
                            value="1"
                            {{ old('is_active', $template->is_active) ? 'checked' : '' }}
                            class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        >
                        <span class="font-medium text-slate-700">Template Aktif (Dapat Dipilih untuk Penerbitan Dokumen)</span>
                    </label>

                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input
                            type="checkbox"
                            name="is_default"
                            value="1"
                            {{ old('is_default', $template->is_default) ? 'checked' : '' }}
                            class="rounded border-slate-300 text-amber-600 focus:ring-amber-500"
                        >
                        <span class="font-medium text-slate-700">Jadikan Template Utama (Default {{ $typeLabel }})</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- Shared Unified Word Studio (Tiptap / ProseMirror A4 Engine) -->
        @include('admin.document-templates._word-editor', [
            'documentType'    => $template->type,
            'formId'          => 'documentTemplateForm',
            'initialHtml'     => $template->content_html,
            'editorJson'      => $template->editor_json,
            'pageConfig'      => $template->page_config ?? [],
            'mediaAssets'     => $mediaAssets ?? [],
            'placeholderMeta' => $placeholders ?? [],
            'sampleWordHtml'  => \App\Models\DocumentTemplate::getDefaultStandardContent($template->type),
            'defaultParafHtml' => $template->page_config['footer_html'] ?? '',
            'uploadMediaUrl'  => route('admin.document-templates.api-assets'),
        ])
    </form>
</div>

<!-- Live Browser Preview Modal -->
<div id="modalLiveBrowserPreview" class="fixed inset-0 z-50 hidden bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                <svg class="h-4 w-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <span>Pratinjau Dokumen Browser (Data Simulasi Resmi)</span>
            </h3>
            <button type="button" id="btnCloseBrowserPreview" class="text-slate-400 hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-200 transition">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div id="browserPreviewContainer" class="p-6 overflow-y-auto flex-1 bg-slate-100/60 min-h-[400px]">
            <div class="text-center p-12 text-slate-400 text-xs">Memuat pratinjau dokumen...</div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function() {
    'use strict';

    const saveBtn = document.getElementById('btnSaveDocumentTemplate');
    const form = document.getElementById('documentTemplateForm');
    const alertBox = document.getElementById('docSaveAlert');
    const iconNormal = document.getElementById('iconSaveNormal');
    const spinner = document.getElementById('spinnerSaving');
    const labelSave = document.getElementById('labelSaveBtn');

    function showAlert(msg, type = 'success') {
        if (!alertBox) return;
        alertBox.className = type === 'success'
            ? 'mb-4 rounded-lg p-3 text-xs font-medium bg-emerald-50 text-emerald-800 border border-emerald-200'
            : 'mb-4 rounded-lg p-3 text-xs font-medium bg-rose-50 text-rose-800 border border-rose-200';
        alertBox.textContent = msg;
        alertBox.classList.remove('hidden');
        if (type === 'success') {
            setTimeout(() => { alertBox.classList.add('hidden'); }, 5000);
        }
    }

    // Save Template Handler
    async function handleSaveTemplate() {
        if (!saveBtn || saveBtn.disabled) return;

        // Guarantee active editor changes are synchronized to hidden inputs
        if (window.MouWordProcessor && typeof window.MouWordProcessor.syncAllToHiddenInputs === 'function') {
            window.MouWordProcessor.syncAllToHiddenInputs();
        }

        const nameInput = document.getElementById('templateNameInput');
        if (!nameInput || !nameInput.value.trim()) {
            showAlert('Nama template wajib diisi.', 'error');
            nameInput?.focus();
            return;
        }

        saveBtn.disabled = true;
        iconNormal?.classList.add('hidden');
        spinner?.classList.remove('hidden');
        labelSave.textContent = 'Menyimpan...';

        const formData = new FormData(form);
        const payload = {};
        formData.forEach((value, key) => {
            payload[key] = value;
        });

        // Ensure content_html has latest HTML
        const htmlInput = document.getElementById('inputTemplateHtml') || document.getElementById('inputContentHtml');
        if (htmlInput) {
            payload.content_html = htmlInput.value;
            payload.template_html = htmlInput.value;
        }

        // Ensure editor_json has latest JSON
        const jsonInput = document.getElementById('inputEditorJson');
        if (jsonInput && jsonInput.value) {
            payload.editor_json = jsonInput.value;
        }

        try {
            const res = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });

            const data = await res.json();
            if (res.ok && data.success) {
                showAlert(data.message || 'Template berhasil disimpan.', 'success');

                if (data.version) {
                    const badge = document.getElementById('docVersionBadge');
                    if (badge) badge.textContent = `Versi ${data.version}`;
                }

                const heading = document.getElementById('docHeadingName');
                if (heading && nameInput) heading.textContent = nameInput.value;

                // Reset change summary input
                const summaryInput = document.getElementById('changeSummaryInput');
                if (summaryInput) summaryInput.value = '';
            } else {
                showAlert(data.message || 'Gagal menyimpan template.', 'error');
            }
        } catch (err) {
            console.error('Save template error:', err);
            showAlert('Terjadi kesalahan jaringan saat menyimpan. Silakan coba lagi.', 'error');
        } finally {
            saveBtn.disabled = false;
            iconNormal?.classList.remove('hidden');
            spinner?.classList.add('hidden');
            labelSave.textContent = 'Simpan Revisi';
        }
    }

    saveBtn?.addEventListener('click', handleSaveTemplate);

    // Keyboard shortcut: Ctrl+S / Cmd+S
    window.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            handleSaveTemplate();
        }
    });

    // Live Browser Preview Modal
    const btnPreview = document.getElementById('btnOpenBrowserPreview');
    const modalPreview = document.getElementById('modalLiveBrowserPreview');
    const btnClosePreview = document.getElementById('btnCloseBrowserPreview');
    const previewContainer = document.getElementById('browserPreviewContainer');

    btnPreview?.addEventListener('click', async () => {
        modalPreview?.classList.remove('hidden');
        if (!previewContainer) return;
        previewContainer.innerHTML = '<div class="text-center p-12 text-slate-500 text-xs">Memuat pratinjau dokumen...</div>';

        try {
            const res = await fetch('{{ route("admin.document-templates.preview", $template) }}?raw=1');
            const html = await res.text();
            previewContainer.innerHTML = html;
        } catch (err) {
            previewContainer.innerHTML = `<div class="p-6 text-rose-600 text-xs">Gagal memuat pratinjau: ${err.message}</div>`;
        }
    });

    btnClosePreview?.addEventListener('click', () => {
        modalPreview?.classList.add('hidden');
    });

    modalPreview?.addEventListener('click', (e) => {
        if (e.target === modalPreview) {
            modalPreview.classList.add('hidden');
        }
    });
})();
</script>
@endpush
@endsection
