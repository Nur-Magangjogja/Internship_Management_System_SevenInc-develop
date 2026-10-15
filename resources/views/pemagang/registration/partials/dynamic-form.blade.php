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
    $renderField = function(\App\Models\FormField $field) use ($reg, $old, $input, $label, $radio, $divisions, $toDateInput) {
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
                foreach ($field->options ?? [] as $opt) {
                    $checked = ($val === $opt['value']) ? 'checked' : '';
                    $radioHtml .= '<label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">'
                        . '<input type="radio" name="' . e($field->field_key) . '" value="' . e($opt['value']) . '" '
                        . 'class="' . $radio . '" ' . $checked . ($field->is_required ? ' required' : '') . '>'
                        . e($opt['label']) . '</label>';
                }
                $radioHtml .= '</div>';
                $html .= $radioHtml;
                break;

            case 'checkbox':
                $savedArr = is_array($val)
                    ? $val
                    : array_map('trim', explode(',', (string) $val));
                $groupCls  = 'border border-gray-200 rounded-lg divide-y divide-gray-100 overflow-hidden';
                $itemCls   = 'flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 transition cursor-pointer';
                $checkHtml = '<div class="' . $groupCls . '">';
                foreach ($field->options ?? [] as $opt) {
                    $checked = in_array($opt['value'], $savedArr) ? 'checked' : '';
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
                $html .= '<input type="file" name="' . e($field->field_key) . '" '
                    . $required . ' class="' . $input . ' file:mr-3 file:py-1.5 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:text-white cursor-pointer">';
                if ($reg && $reg->{$field->field_key}) {
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
@endphp

{{-- Sistem Magang selalu Onsite, tidak ditampilkan ke pengguna --}}
<input type="hidden" name="internship_arrangement" value="Onsite">

{{-- Render field grup utama --}}
{!! $renderGroup($mainFields) !!}

{{-- Render field grup informasi tambahan (jika ada) --}}
@if($extraFields->isNotEmpty())
<div class="border-t border-gray-100 pt-5">
    <h3 class="text-sm font-semibold text-gray-700 mb-4">Informasi Tambahan</h3>
    <div class="space-y-4">
        {{-- Instagram perlu prefix @ — handle khusus --}}
        @foreach($extraFields as $field)
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
    </div>
</div>
@endif

{{-- Render field kustom dari Form Builder (jika ada) --}}
@if(!empty($customFields) && $customFields->isNotEmpty())
<div class="border-t border-gray-100 pt-5">
    <h3 class="text-sm font-semibold text-gray-700 mb-4">Pertanyaan Tambahan</h3>
    <div class="space-y-4">
        {!! $renderGroup($customFields) !!}
    </div>
</div>
@endif

