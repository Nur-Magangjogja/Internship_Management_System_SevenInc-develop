{{--
    Partial: form pendaftaran pemagang yang dirender dari konfigurasi form_fields DB.
    Variables yang dibutuhkan:
    - $mainFields  : Collection<FormField>  — field grup utama
    - $extraFields : Collection<FormField>  — field grup informasi_tambahan
    - $divisions   : Collection<string>     — daftar divisi aktif
    - $reg         : InternshipRegistration|null
    - $old         : Closure(string) => mixed
    - $input       : string  — CSS class input
    - $label       : string  — CSS class label
    - $radio       : string  — CSS class radio/checkbox
    - $toDateInput : Closure(string) => string
--}}

@php
    $renderField = function(\App\Models\FormField $field, bool $isHalf = false) use ($reg, $old, $input, $label, $radio, $divisions, $toDateInput, $cities, $institutions, $faculties, $studyPrograms) {
        $val = $old($field->field_key);
        $hasOptText = (bool) preg_match('/\(opsional\)/i', (string) $field->label);
        $req = $field->is_required
            ? '<span class="text-red-500">*</span>'
            : (!$hasOptText ? '<span class="text-xs text-gray-400 font-normal font-sans ml-1">(Opsional)</span>' : '');
        $ph  = e($field->placeholder ?? '');
        $ht  = $field->helper_text
                    ? '<p class="mt-1 text-xs text-gray-400">' . e($field->helper_text) . '</p>'
                    : '';
        $required = $field->is_required ? 'required' : '';

        $labelHtml = '<label class="' . $label . '">' . e($field->label) . ' ' . $req . '</label>';
        $controlHtml = '';
        $descHtml = '';

        switch ($field->field_type) {
            case 'text':
            case 'email':
            case 'tel':
            case 'number':
            case 'url':
                $extra = '';
                if ($field->field_type === 'tel') {
                    $extra = 'pattern="[0-9]{10,15}" inputmode="numeric"';
                }
                
                $listAttr = '';
                $extraClass = '';
                if ($field->field_type === 'text') {
                    $dlMap = [
                        'current_city' => ['cities', $cities ?? []],
                        'institution_name' => ['institutions', $institutions ?? []],
                        'study_program' => ['study-programs', $studyPrograms ?? []],
                        'faculty' => ['faculties', $faculties ?? []]
                    ];
                    if (isset($dlMap[$field->field_key])) {
                        $extraClass = ' tomselect-input';
                        $optionsArr = [];
                        foreach ($dlMap[$field->field_key][1] as $item) {
                            $optionsArr[] = $item->name;
                        }
                        $listAttr = " id=\"{$field->field_key}\" data-options='" . htmlspecialchars(json_encode($optionsArr), ENT_QUOTES) . "'";
                    }
                }
                
                if ($field->field_key === 'social_media_instagram') {
                    $igPh = $ph ?: 'username_kamu';
                    $controlHtml = '<div class="flex items-center gap-0 w-full max-w-full">'
                        . '<span class="inline-flex items-center px-3 py-2.5 rounded-l-lg border border-r-0 border-gray-200 bg-gray-50 text-sm text-gray-500 shrink-0">@</span>'
                        . '<input type="text" name="social_media_instagram" ' . $required
                        . ' placeholder="' . $igPh . '"'
                        . ' class="block flex-1 min-w-0 w-full rounded-r-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 px-3 py-2.5 text-sm"'
                        . ' value="' . e($val) . '">'
                        . '</div>';
                } else {
                    $controlHtml = '<input type="' . $field->field_type . '" name="' . e($field->field_key) . '" '
                        . $required . ' placeholder="' . $ph . '" class="' . $input . $extraClass . ' w-full max-w-full" '
                        . $extra . $listAttr . ' value="' . e($val) . '">';
                }
                break;

            case 'date':
                $dateVal = is_callable($toDateInput) ? $toDateInput($val) : (is_string($val) ? $val : '');
                $controlHtml = '<input type="date" name="' . e($field->field_key) . '" '
                    . $required . ' class="' . $input . ' w-full max-w-full" value="' . e($dateVal) . '">';
                break;

            case 'textarea':
                $controlHtml = '<textarea name="' . e($field->field_key) . '" ' . $required
                    . ' rows="3" placeholder="' . $ph . '" class="' . $input . ' w-full max-w-full resize-none">'
                    . e($val) . '</textarea>';
                break;

            case 'select':
                if ($field->field_key === 'internship_interest') {
                    // Khusus: opsi dari tabel divisions
                    $opts = '<option value="">-- Pilih Divisi --</option>';
                    foreach ($divisions as $div) {
                        $sel = ($val === $div) ? 'selected' : '';
                        $opts .= '<option value="' . e($div) . '" ' . $sel . '>' . e($div) . '</option>';
                    }
                } elseif ($field->field_key === 'religion') {
                    $placeholder = $field->placeholder ?: '-- Pilih Agama (Opsional) --';
                    $opts = '<option value="">' . e($placeholder) . '</option>';
                    $relOptions = !empty($field->options) ? $field->options : [
                        ['value' => 'Islam', 'label' => 'Islam'],
                        ['value' => 'Kristen Protestan', 'label' => 'Kristen Protestan'],
                        ['value' => 'Katolik', 'label' => 'Katolik'],
                        ['value' => 'Hindu', 'label' => 'Hindu'],
                        ['value' => 'Buddha', 'label' => 'Buddha'],
                        ['value' => 'Khonghucu', 'label' => 'Khonghucu'],
                    ];
                    $valRel = strtolower(trim((string)$val));
                    foreach ($relOptions as $opt) {
                        $optValLower = strtolower($opt['value']);
                        $isSelected = ($valRel === $optValLower)
                            || ($optValLower === 'kristen protestan' && in_array($valRel, ['kristen', 'protestan', 'kristen protestan'], true))
                            || ($optValLower === 'khonghucu' && in_array($valRel, ['khonghucu', 'konghucu'], true));
                        $sel = $isSelected ? 'selected' : '';
                        $opts .= '<option value="' . e($opt['value']) . '" ' . $sel . '>' . e($opt['label']) . '</option>';
                    }
                } else {
                    $placeholder = $field->placeholder ?: '-- Pilih --';
                    $opts = '<option value="">' . e($placeholder) . '</option>';
                    foreach ($field->options ?? [] as $opt) {
                        $sel = ($val === $opt['value']) ? 'selected' : '';
                        $opts .= '<option value="' . e($opt['value']) . '" ' . $sel . '>'
                               . e($opt['label']) . '</option>';
                    }
                }
                $controlHtml = '<select name="' . e($field->field_key) . '" ' . $required
                    . ' class="' . $input . ' w-full max-w-full">' . $opts . '</select>';

                // Tampilkan keterangan jika ada description di opsi
                $hasDesc = collect($field->options ?? [])->contains(fn($o) => !empty($o['description']));
                if ($hasDesc) {
                    $descHtml = '<div class="mt-2 space-y-1 rounded-lg bg-gray-50 border border-gray-100 p-3 max-w-full" id="desc-' . e($field->field_key) . '">';
                    foreach ($field->options ?? [] as $opt) {
                        if (!empty($opt['description'])) {
                            $descHtml .= '<p class="text-xs text-gray-500 leading-snug hidden desc-item" data-for-value="' . e($opt['value']) . '">'
                                . '<span class="font-semibold text-gray-700">' . e($opt['label']) . ':</span> '
                                . e($opt['description'])
                                . '</p>';
                        }
                    }
                    // Tampilkan semua keterangan saat pertama load jika belum dipilih
                    $descHtml .= '<p class="text-xs text-gray-400 italic desc-hint">Pilih jenis magang untuk melihat keterangan.</p>';
                    $descHtml .= '</div>';
                    // JS inline untuk show/hide desc
                    $descHtml .= '<script>
(function(){
    var sel = document.querySelector("select[name=\"' . e($field->field_key) . '\"]");
    var box = document.getElementById("desc-' . e($field->field_key) . '");
    if(!sel || !box) return;
    function update(){
        var v = sel.value;
        var items = box.querySelectorAll(".desc-item");
        var hint  = box.querySelector(".desc-hint");
        var shown = 0;
        items.forEach(function(el){
            if(v && el.dataset.forValue === v){ el.classList.remove("hidden"); shown++; }
            else { el.classList.add("hidden"); }
        });
        if(hint) hint.style.display = shown ? "none" : "";
        // tampilkan semua bila belum pilih
        if(!v){ items.forEach(function(el){ el.classList.remove("hidden"); }); if(hint) hint.style.display="none"; }
    }
    sel.addEventListener("change", update);
    update();
})();
</script>';
                }
                break;

            case 'radio':
                $radioHtml = '<div class="flex flex-wrap items-center gap-4 sm:gap-6 pt-1 min-h-[42px]">';
                $valLower = strtolower(trim((string)$val));
                foreach ($field->options ?? [] as $opt) {
                    $optValLower = strtolower((string) $opt['value']);
                    $isMatch = ((string) $val === (string) $opt['value'])
                        || ($field->field_key === 'gender' && (
                            (in_array($optValLower, ['laki-laki', 'l', 'male'], true) && in_array($valLower, ['laki-laki', 'l', 'male', 'pria'], true))
                            || (in_array($optValLower, ['perempuan', 'p', 'female'], true) && in_array($valLower, ['perempuan', 'p', 'female', 'wanita'], true))
                        ))
                        || ($field->field_key === 'has_laptop' && $val !== null && $val !== '' && (
                            (in_array($optValLower, ['1', 'ya', 'true'], true) && in_array($valLower, ['1', 'ya', 'true', 'ya, punya sendiri'], true))
                            || (in_array($optValLower, ['0', 'tidak', 'false'], true) && in_array($valLower, ['0', 'tidak', 'false'], true))
                        ));
                    $checked = $isMatch ? 'checked' : '';
                    $radioHtml .= '<label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer select-none">'
                        . '<input type="radio" name="' . e($field->field_key) . '" value="' . e($opt['value']) . '" '
                        . 'class="' . $radio . '" ' . $checked . ($field->is_required ? ' required' : '') . '>'
                        . '<span class="break-words">' . e($opt['label']) . '</span></label>';
                }
                $radioHtml .= '</div>';
                $controlHtml = $radioHtml;
                break;

            case 'checkbox':
                $rawArr = is_array($val)
                    ? $val
                    : array_map('trim', explode(',', (string) $val));
                $savedArr = array_map(fn($v) => strtolower(trim((string)$v)), $rawArr);
                $groupCls  = 'border border-gray-200 rounded-lg divide-y divide-gray-100 overflow-hidden w-full bg-white';
                $itemCls   = 'flex items-center gap-3 px-3.5 py-2.5 hover:bg-gray-50 transition cursor-pointer select-none';
                $checkHtml = '<div class="' . $groupCls . '">';
                foreach ($field->options ?? [] as $opt) {
                    $optValLower = strtolower((string) $opt['value']);
                    $isMatch = in_array($opt['value'], $rawArr)
                        || in_array($optValLower, $savedArr, true)
                        || ($field->field_key === 'gender' && (
                            (in_array($optValLower, ['laki-laki', 'l', 'male'], true) && count(array_intersect($savedArr, ['laki-laki', 'l', 'male', 'pria'])) > 0)
                            || (in_array($optValLower, ['perempuan', 'p', 'female'], true) && count(array_intersect($savedArr, ['perempuan', 'p', 'female', 'wanita'])) > 0)
                        ));
                    $checked = $isMatch ? 'checked' : '';
                    $checkHtml .= '<label class="' . $itemCls . '">'
                        . '<input type="checkbox" name="' . e($field->field_key) . '[]" value="' . e($opt['value']) . '" '
                        . 'class="' . $radio . '" ' . $checked . '>'
                        . '<span class="text-sm text-gray-700 break-words">' . e($opt['label']) . '</span>'
                        . '</label>';
                }
                $checkHtml .= '</div>';
                $controlHtml = $checkHtml;
                break;

            case 'file':
                $isMultiFile = in_array($field->field_key, ['portfolio', 'supporting_document', 'other_Portfolio'], true);
                $inputName = $isMultiFile ? ($field->field_key . '[]') : $field->field_key;
                $multiAttr = $isMultiFile ? 'multiple' : '';

                $acceptAttr = '';
                $exts = $field->validation_rules['allowed_extensions'] ?? null;
                if (is_array($exts) && !empty($exts)) {
                    $acceptList = array_map(fn($ext) => '.' . ltrim(strtolower((string) $ext), '.'), $exts);
                    $acceptAttr = 'accept="' . e(implode(',', $acceptList)) . '"';
                } elseif (in_array($field->field_key, ['cv_ktp_portofolio_pdf', 'cv', 'cover_letter', 'internship_letter'], true)) {
                    $acceptAttr = 'accept=".pdf,application/pdf"';
                } else {
                    $acceptAttr = 'accept=".pdf,.jpg,.jpeg,.png"';
                }

                // Jika berkas sudah pernah diunggah sebelumnya, jangan paksa required ulang saat update
                $hasExistingFile = false;
                if ($reg) {
                    if (!empty($reg->{$field->field_key}) || ($field->field_key === 'photo' && !empty($reg->profile_photo))) {
                        $hasExistingFile = true;
                    } elseif ($reg->relationLoaded('attachments') || method_exists($reg, 'attachments')) {
                        $aliasTypes = match ($field->field_key) {
                            'ktm_ktp', 'identity'                   => ['identity', 'ktm_ktp', 'ktm', 'ktp'],
                            'internship_letter', 'cover_letter'     => ['cover_letter', 'surat_pengantar', 'internship_letter'],
                            'photo', 'profile_photo'                => ['profile_photo', 'pasfoto', 'photo'],
                            'other_Portfolio', 'supporting_document'=> ['supporting_document', 'dokumen_pendukung', 'other_Portfolio'],
                            'portfolio'                             => ['portfolio', 'portofolio'],
                            default                                 => [$field->field_key],
                        };
                        $hasExistingFile = $reg->attachments->filter(function ($att) use ($aliasTypes) {
                            return in_array($att->attachment_type ?? '', $aliasTypes, true)
                                || in_array($att->category ?? '', $aliasTypes, true)
                                || in_array($att->document_category ?? '', $aliasTypes, true);
                        })->isNotEmpty();
                    }
                }
                $effectiveRequired = ($field->is_required && !$hasExistingFile) ? 'required' : '';

                $prevFileHtml = '';
                if ($reg && !empty($reg->{$field->field_key})) {
                    $prevFileHtml = '<p class="text-xs text-gray-400 mt-1.5">File sebelumnya: '
                           . e(basename($reg->{$field->field_key})) . '</p>';
                }

                $fileInputHtml = '<input type="file" name="' . e($inputName) . '" ' . $multiAttr . ' ' . $acceptAttr . ' '
                    . $effectiveRequired . ' class="' . $input . ' w-full max-w-full file:mr-3 file:py-1 file:px-2.5 file:rounded file:border-0 file:text-xs file:font-medium file:text-white file:bg-emerald-700 hover:file:bg-emerald-800 cursor-pointer">';

                if ($isHalf) {
                    return '<div class="form-half-col p-3.5 sm:p-4 rounded-lg border border-gray-200 bg-gray-50/50 hover:bg-gray-50 transition w-full h-full flex flex-col justify-between">'
                        . '<div class="form-label-wrap">' . $labelHtml . '</div>'
                        . '<div class="form-control-wrap mt-auto">' . $fileInputHtml . '</div>'
                        . (($prevFileHtml || $ht) ? '<div class="form-helper-wrap">' . $prevFileHtml . $ht . '</div>' : '')
                        . '</div>';
                }

                return '<div class="p-3.5 sm:p-4 rounded-lg border border-gray-200 bg-gray-50/50 hover:bg-gray-50 transition w-full">'
                    . $labelHtml
                    . $fileInputHtml
                    . $prevFileHtml
                    . $ht
                    . '</div>';
        }

        if ($isHalf) {
            $out = '<div class="form-half-col">';
            $out .= '<div class="form-label-wrap">' . $labelHtml . '</div>';
            $out .= '<div class="form-control-wrap">' . $controlHtml . '</div>';
            if ($ht || !empty($descHtml)) {
                $out .= '<div class="form-helper-wrap">' . $ht . ($descHtml ?? '') . '</div>';
            }
            $out .= '</div>';
            return $out;
        }

        return $labelHtml . $controlHtml . ($descHtml ?? '') . $ht;
    };

    // Render fields dalam responsive CSS grid (1 kolom di mobile, 2 kolom di web/desktop)
    // Jarak gap antar-kolom dan antar-baris proporsional serta terlindung dari overflow horizontal
    $renderGroup = function($fields) use ($renderField) {
        $fieldsList = $fields instanceof \Illuminate\Support\Collection ? $fields : collect($fields);
        if ($fieldsList->isEmpty()) return '';

        $out = '<div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-5">';
        foreach ($fieldsList->values() as $f) {
            // Field ber-tipe textarea, checkbox banyak pilihan, atau column_span === 1 span 2 kolom di layar desktop
            $isFullWidth = ($f->column_span !== 2)
                || $f->field_type === 'textarea'
                || ($f->field_type === 'checkbox' && count($f->options ?? []) > 2);

            $colSpan = $isFullWidth ? 'col-span-1 md:col-span-2' : 'col-span-1';

            $out .= '<div class="' . $colSpan . ' min-w-0 w-full">';
            $out .= $renderField($f, !$isFullWidth);
            $out .= '</div>';
        }
        $out .= '</div>';
        return $out;
    };

    if (empty($groupedFields)) {
        $all = collect($mainFields ?? [])->merge($extraFields ?? [])->merge($customFields ?? []);
        $groupedFields = [
            'data_diri_edukasi'  => $all->filter(fn($f) => $f->resolved_group === 'data_diri_edukasi')->values(),
            'pilihan_durasi'     => $all->filter(fn($f) => $f->resolved_group === 'pilihan_durasi')->values(),
            'berkas_portofolio'  => $all->filter(fn($f) => $f->resolved_group === 'berkas_portofolio')->values(),
            'informasi_tambahan' => $all->filter(fn($f) => $f->resolved_group === 'informasi_tambahan')->values(),
        ];
    }

    $gDataDiri = $groupedFields['data_diri_edukasi'] ?? collect();
    $gPilihan  = collect($groupedFields['pilihan_durasi'] ?? [])->values();
    $gBerkas   = $groupedFields['berkas_portofolio'] ?? collect();
    $gInfo     = $groupedFields['informasi_tambahan'] ?? collect();
    $hasActiveArrangementField = $gDataDiri->contains('field_key', 'internship_arrangement')
        || $gPilihan->contains('field_key', 'internship_arrangement')
        || $gBerkas->contains('field_key', 'internship_arrangement')
        || $gInfo->contains('field_key', 'internship_arrangement');
@endphp

{{-- Fallback default Onsite hanya jika field internship_arrangement dinonaktifkan pada pengaturan form --}}
@if(!$hasActiveArrangementField)
<input type="hidden" name="internship_arrangement" value="Onsite">
@endif

{{-- ==================== 1. DATA DIRI & EDUKASI ==================== --}}
@if($gDataDiri->isNotEmpty())
<div id="section-1-data-diri" class="border-b border-gray-100 pb-6 pt-2">
    <div class="mb-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">1</span>
            Data Diri & Edukasi <span class="text-xs font-normal text-red-500 normal-case">(Wajib)</span>
        </h3>
        <p class="text-xs text-gray-500 mt-1">Lengkapi data pribadi, kontak aktif, dan asal universitas / sekolah Anda.</p>
    </div>
    {!! $renderGroup($gDataDiri) !!}
</div>
@endif

{{-- ==================== 2. PILIHAN & DURASI ==================== --}}
@if($gPilihan->isNotEmpty())
<div id="section-2-pilihan-durasi" class="border-b border-gray-100 pb-6 pt-5">
    <div class="mb-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">2</span>
            Pilihan & Durasi <span class="text-xs font-normal text-red-500 normal-case">(Wajib)</span>
        </h3>
        <p class="text-xs text-gray-500 mt-1">Pilih program magang, estimasi durasi, kesiapan WFO di Yogyakarta, serta keahlian kerja.</p>
    </div>
    {!! $renderGroup($gPilihan) !!}
</div>
@endif

{{-- ==================== 3. BERKAS & PORTOFOLIO ==================== --}}
@if($gBerkas->isNotEmpty() || ($reg && $reg->attachments && $reg->attachments->count() > 0))
<div id="section-3-berkas-portofolio" class="border-b border-gray-100 pb-6 pt-5">
    <div class="mb-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">3</span>
            Berkas & Portofolio <span class="text-xs font-normal text-gray-500 normal-case">(Opsional)</span>
        </h3>
        <p class="text-xs text-gray-500 mt-1">Unggah berkas CV, KTM/KTP, portofolio karya, dan dokumen pengantar dari kampus / sekolah.</p>
    </div>

    @php
        $attachmentsList = ($reg && $reg->attachments)
            ? $reg->attachments->reject(fn($a) => $a->mime_type === 'application/vnd.google-apps.folder' || str_starts_with((string)$a->file_path, 'drive_root:') || $a->attachment_type === \App\Models\RegistrationAttachment::TYPE_LEGACY_BUNDLE)
            : collect();
    @endphp
    @if($attachmentsList->isNotEmpty() || $reg?->profile_photo)
    <div class="mb-5 p-3.5 sm:p-4 bg-gray-50 border border-gray-200 rounded-xl overflow-hidden">
        <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider mb-3 flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Berkas Tersimpan di Server ({{ $attachmentsList->count() }} file):
        </h4>
        <div class="space-y-2">
            @foreach($attachmentsList as $att)
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5 p-3 bg-white border border-gray-200 rounded-lg text-xs overflow-hidden">
                <div class="flex flex-wrap items-center gap-2 min-w-0 max-w-full">
                    <span class="px-2 py-0.5 rounded font-semibold text-[10px] bg-emerald-100 text-emerald-800 uppercase shrink-0">
                        {{ \App\Models\RegistrationAttachment::getCategoryLabel($att->attachment_type ?? '') }}
                    </span>
                    <a href="{{ route('pemagang.registration.attachment.download', $att->id) }}" target="_blank" class="text-blue-600 font-medium hover:underline truncate max-w-[200px] sm:max-w-xs md:max-w-md" title="{{ $att->original_name ?: basename($att->file_path) }}">
                        {{ $att->original_name ?: basename($att->file_path) }}
                    </a>
                    <span class="text-gray-400 shrink-0">({{ round(($att->file_size ?: 0) / 1024, 1) }} KB)</span>
                    @if(($att->drive_sync_status ?? '') === 'synced')
                        <span class="text-[10px] text-emerald-600 font-medium flex items-center gap-1 shrink-0"><i class="fas fa-cloud-check"></i> Google Drive</span>
                    @else
                        <span class="text-[10px] text-gray-400 font-medium flex items-center gap-1 shrink-0"><i class="fas fa-hard-drive"></i> Lokal Privat</span>
                    @endif
                </div>

                @if(!$reg || $reg->is_draft || in_array($reg->internship_status, ['new', 'waiting']))
                <button type="button" onclick="deleteAttachment('{{ route('pemagang.registration.attachment.delete', $att->id) }}', this)" class="btn-delete-attachment text-red-500 hover:text-red-700 font-medium flex items-center gap-1 px-2.5 py-1 rounded hover:bg-red-50 transition text-xs shrink-0 self-end sm:self-auto cursor-pointer">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Hapus
                </button>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {!! $renderGroup($gBerkas) !!}
</div>
@endif

{{-- ==================== 4. INFORMASI TAMBAHAN ==================== --}}
@php
    $renderedGroupKeys = $gDataDiri->pluck('field_key')
        ->merge($gPilihan->pluck('field_key'))
        ->merge($gBerkas->pluck('field_key'))
        ->merge($gInfo->pluck('field_key'))
        ->all();
    $unrenderedCustomFields = !empty($customFields)
        ? $customFields->reject(fn($cf) => in_array($cf->field_key, $renderedGroupKeys, true))->values()
        : collect();
    $combinedInfoFields = collect($gInfo)->merge($unrenderedCustomFields)->values();
@endphp
@if($combinedInfoFields->isNotEmpty())
<div id="section-4-informasi-tambahan" class="border-b border-gray-100 pb-6 pt-5">
    <div class="mb-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">4</span>
            Informasi Tambahan <span class="text-xs font-normal text-gray-500 normal-case">(Opsional)</span>
        </h3>
        <p class="text-xs text-gray-500 mt-1">Data dosen/guru pembimbing, akun sosial media, info kost, dan aktivitas harian lainnya.</p>
    </div>
    {!! $renderGroup($combinedInfoFields) !!}
</div>
@endif


