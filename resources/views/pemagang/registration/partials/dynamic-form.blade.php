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
    $renderField = function(\App\Models\FormField $field) use ($reg, $old, $input, $label, $radio, $divisions, $toDateInput, $cities, $institutions, $faculties, $studyPrograms) {
        $val = $old($field->field_key);
        $req = $field->is_required ? '<span class="text-red-500">*</span>' : '';
        $ph  = e($field->placeholder ?? '');
        $ht  = $field->helper_text
                    ? '<p class="mt-1 text-xs text-gray-400">' . e($field->helper_text) . '</p>'
                    : '';
        $required = $field->is_required ? 'required' : '';

        $html = '<label class="' . $label . '">' . e($field->label) . ' ' . $req . '</label>';

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
                
                $html .= '<input type="' . $field->field_type . '" name="' . e($field->field_key) . '" '
                    . $required . ' placeholder="' . $ph . '" class="' . $input . $extraClass . '" '
                    . $extra . $listAttr . ' value="' . e($val) . '">';
                break;

            case 'date':
                $dateVal = is_callable($toDateInput) ? $toDateInput($val) : (is_string($val) ? $val : '');
                $html .= '<input type="date" name="' . e($field->field_key) . '" '
                    . $required . ' class="' . $input . '" value="' . e($dateVal) . '">';
                break;

            case 'textarea':
                $html .= '<textarea name="' . e($field->field_key) . '" ' . $required
                    . ' rows="3" placeholder="' . $ph . '" class="' . $input . ' resize-none">'
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
                $html .= '<select name="' . e($field->field_key) . '" ' . $required
                    . ' class="' . $input . '">' . $opts . '</select>';

                // Tampilkan keterangan jika ada description di opsi
                $hasDesc = collect($field->options ?? [])->contains(fn($o) => !empty($o['description']));
                if ($hasDesc) {
                    $html .= '<div class="mt-2 space-y-1 rounded-lg bg-gray-50 border border-gray-100 px-3 py-2" id="desc-' . e($field->field_key) . '">';
                    foreach ($field->options ?? [] as $opt) {
                        if (!empty($opt['description'])) {
                            $html .= '<p class="text-xs text-gray-500 leading-snug hidden desc-item" data-for-value="' . e($opt['value']) . '">'
                                . '<span class="font-semibold text-gray-700">' . e($opt['label']) . ':</span> '
                                . e($opt['description'])
                                . '</p>';
                        }
                    }
                    // Tampilkan semua keterangan saat pertama load jika belum dipilih
                    $html .= '<p class="text-xs text-gray-400 italic desc-hint">Pilih jenis magang untuk melihat keterangan.</p>';
                    $html .= '</div>';
                    // JS inline untuk show/hide desc
                    $html .= '<script>
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
                $radioHtml = '<div class="flex gap-4">';
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
                    $radioHtml .= '<label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">'
                        . '<input type="radio" name="' . e($field->field_key) . '" value="' . e($opt['value']) . '" '
                        . 'class="' . $radio . '" ' . $checked . ($field->is_required ? ' required' : '') . '>'
                        . e($opt['label']) . '</label>';
                }
                $radioHtml .= '</div>';
                $html .= $radioHtml;
                break;

            case 'checkbox':
                $rawArr = is_array($val)
                    ? $val
                    : array_map('trim', explode(',', (string) $val));
                $savedArr = array_map(fn($v) => strtolower(trim((string)$v)), $rawArr);
                $groupCls  = 'border border-gray-200 rounded-lg divide-y divide-gray-100 overflow-hidden';
                $itemCls   = 'flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 transition cursor-pointer';
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
                        . '<span class="text-sm text-gray-700">' . e($opt['label']) . '</span>'
                        . '</label>';
                }
                $checkHtml .= '</div>';
                $html .= $checkHtml;
                break;

            case 'file':
                $isMultiFile = in_array($field->field_key, ['portfolio', 'supporting_document'], true);
                $inputName = $isMultiFile ? ($field->field_key . '[]') : $field->field_key;
                $multiAttr = $isMultiFile ? 'multiple' : '';

                $acceptAttr = '';
                $exts = $field->validation_rules['allowed_extensions'] ?? null;
                if (is_array($exts) && !empty($exts)) {
                    $acceptList = array_map(fn($ext) => '.' . ltrim(strtolower((string) $ext), '.'), $exts);
                    $acceptAttr = 'accept="' . e(implode(',', $acceptList)) . '"';
                } elseif ($field->field_key === 'profile_photo') {
                    $acceptAttr = 'accept=".jpg,.jpeg,.png,image/jpeg,image/png"';
                } elseif (in_array($field->field_key, ['cv_ktp_portofolio_pdf', 'cv', 'cover_letter', 'proposal'], true)) {
                    $acceptAttr = 'accept=".pdf,application/pdf"';
                } else {
                    $acceptAttr = 'accept=".pdf,.jpg,.jpeg,.png"';
                }

                // Jika berkas sudah pernah diunggah sebelumnya, jangan paksa required ulang saat update
                $hasExistingFile = false;
                if ($reg) {
                    if (!empty($reg->{$field->field_key})) {
                        $hasExistingFile = true;
                    } elseif ($reg->relationLoaded('attachments') || method_exists($reg, 'attachments')) {
                        $hasExistingFile = $reg->attachments->where('document_category', $field->field_key)->isNotEmpty();
                    }
                }
                $effectiveRequired = ($field->is_required && !$hasExistingFile) ? 'required' : '';

                $html .= '<input type="file" name="' . e($inputName) . '" ' . $multiAttr . ' ' . $acceptAttr . ' '
                    . $effectiveRequired . ' class="' . $input . ' file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer">';
                if ($reg && !empty($reg->{$field->field_key})) {
                    $html .= '<p class="text-xs text-gray-400 mt-1">File sebelumnya: '
                           . e(basename($reg->{$field->field_key})) . '</p>';
                }
                break;
        }

        $html .= $ht;
        return $html;
    };

    // Kelompokkan field berdasarkan column_span untuk grid rendering
    $renderGroup = function($fields) use ($renderField) {
        $out = '';
        $arr = $fields->values()->all();
        $len = count($arr);
        $i   = 0;
        while ($i < $len) {
            $f = $arr[$i];
            if ($f->column_span === 2 && ($i + 1) < $len && $arr[$i + 1]->column_span === 2) {
                // Pasangkan dua half-width dalam grid
                $out .= '<div class="grid grid-cols-2 gap-4">';
                $out .= '<div>' . $renderField($f) . '</div>';
                $out .= '<div>' . $renderField($arr[$i + 1]) . '</div>';
                $out .= '</div>';
                $i += 2;
            } else {
                $out .= '<div>' . $renderField($f) . '</div>';
                $i++;
            }
        }
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
    $gPilihan  = $groupedFields['pilihan_durasi'] ?? collect();
    $gBerkas   = $groupedFields['berkas_portofolio'] ?? collect();
    $gInfo     = $groupedFields['informasi_tambahan'] ?? collect();
@endphp

{{-- Sistem Magang selalu Onsite, tidak ditampilkan ke pengguna --}}
<input type="hidden" name="internship_arrangement" value="Onsite">

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
    <div class="space-y-4">
        {!! $renderGroup($gDataDiri) !!}
    </div>
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
    <div class="space-y-4">
        {!! $renderGroup($gPilihan) !!}
    </div>
</div>
@endif

{{-- ==================== 3. BERKAS & PORTOFOLIO ==================== --}}
@if($gBerkas->isNotEmpty() || ($reg && $reg->attachments && $reg->attachments->count() > 0))
<div id="section-3-berkas-portofolio" class="border-b border-gray-100 pb-6 pt-5">
    <div class="mb-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">3</span>
            Berkas & Portofolio
        </h3>
        <p class="text-xs text-gray-500 mt-1">Unggah berkas CV, KTM/KTP, portofolio karya, dan dokumen pengantar dari kampus / sekolah.</p>
    </div>

    @if($reg && $reg->attachments && $reg->attachments->count() > 0)
    <div class="mb-4 p-4 rounded-xl bg-gray-50 border border-gray-200">
        <p class="text-xs font-semibold text-gray-700 mb-2">Berkas Tersimpan di Server ({{ $reg->attachments->count() }} file):</p>
        <ul class="space-y-1.5 text-xs text-gray-600">
            @foreach($reg->attachments as $att)
            <li class="flex items-center justify-between bg-white px-3 py-1.5 rounded border border-gray-100">
                <span class="truncate max-w-xs">
                    <strong class="uppercase text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-700 mr-1.5">{{ str_replace('_', ' ', $att->document_category) }}</strong>
                    {{ $att->original_filename }}
                </span>
                <span class="text-gray-400 text-[11px]">{{ number_format($att->file_size_bytes / 1024, 1) }} KB</span>
            </li>
            @endforeach
        </ul>
    </div>
    @endif

    <div class="space-y-4">
        {!! $renderGroup($gBerkas) !!}
    </div>
</div>
@endif

{{-- ==================== 4. INFORMASI TAMBAHAN ==================== --}}
@if($gInfo->isNotEmpty() || (!empty($customFields) && $customFields->isNotEmpty()))
<div id="section-4-informasi-tambahan" class="border-b border-gray-100 pb-6 pt-5">
    <div class="mb-4">
        <h3 class="text-sm font-bold text-gray-800 uppercase tracking-wider flex items-center gap-2">
            <span class="w-6 h-6 rounded-full bg-emerald-100 text-emerald-800 text-xs flex items-center justify-center font-bold">4</span>
            Informasi Tambahan <span class="text-xs font-normal text-gray-500 normal-case">(Opsional)</span>
        </h3>
        <p class="text-xs text-gray-500 mt-1">Data dosen/guru pembimbing, akun sosial media, info kost, dan aktivitas harian lainnya.</p>
    </div>
    <div class="space-y-4">
        @foreach($gInfo as $field)
            @if($field->field_key === 'social_media_instagram')
            <div>
                <label class="{{ $label }}">{{ $field->label }}@if($field->is_required) <span class="text-red-500">*</span>@endif</label>
                <div class="flex items-center gap-0">
                    <span class="inline-flex items-center px-3 py-2.5 rounded-l-lg border border-r-0 border-gray-200 bg-gray-50 text-sm text-gray-500">@</span>
                    <input type="text" name="social_media_instagram"
                        placeholder="{{ $field->placeholder ?? 'username_kamu' }}"
                        class="block flex-1 rounded-r-lg border border-gray-200 bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 px-3 py-2.5 text-sm"
                        value="{{ old('social_media_instagram', ($reg?->social_media_instagram !== '-' ? $reg?->social_media_instagram : '')) }}">
                </div>
                @if($field->helper_text)
                <p class="mt-1 text-xs text-gray-400">{{ $field->helper_text }}</p>
                @endif
            </div>
            @else
            <div>{!! $renderField($field) !!}</div>
            @endif
        @endforeach

        {{-- Custom fields yang belum termasuk dalam 4 grup --}}
        @if(!empty($customFields) && $customFields->isNotEmpty())
            {!! $renderGroup($customFields) !!}
        @endif
    </div>
</div>
@endif


