@php
  $val = fn($key, $default = '') => old($key, $webinar?->$key ?? $default);
  $inp = 'w-full rounded-lg border border-[#DCE7E1] bg-[#F4F8F6] px-3 py-2.5 text-sm text-[#1B3A34] focus:outline-none focus:ring-2 focus:ring-[#2D8659] focus:border-[#2D8659]';

  // Brand yang boleh ikut: dari old() atau model (JSON array), null = semua
  $savedAllowedBrands = old('allowed_brands', $webinar?->allowed_brands ?? null);
  $isAllBrandsAllowed = empty($savedAllowedBrands); // null/kosong = semua

  // Brand sertifikat terpilih
  $selectedCertBrandId = old('brand_id', $webinar?->brand_id ?? '');

  // Metode Sertifikat (Template vs GDrive)
  $certSource = old('certificate_source', $webinar?->certificate_source ?? 'template');
  $selectedTemplateId = old('certificate_template_id', $webinar?->certificate_template_id ?? '');
  $gdriveLinkVal = old('gdrive_certificate_link', $webinar?->gdrive_certificate_link ?? '');
@endphp

{{-- ===== INFO WEBINAR ===== --}}
<div class="bg-white rounded-xl border border-[#DCE7E1] p-6 shadow-sm mb-5">
  <p class="text-xs font-bold uppercase tracking-widest text-[#2D8659] mb-4">Informasi Webinar</p>

  <div class="space-y-4">
    <div>
      <label class="block text-sm font-semibold text-[#1B3A34] mb-1.5">
        Judul Webinar <span class="text-red-500">*</span>
      </label>
      <input type="text" name="title" required value="{{ $val('title') }}"
             placeholder="Contoh: Workshop UI/UX Design for Beginners"
             class="{{ $inp }}">
    </div>

    <div>
      <label class="block text-sm font-semibold text-[#1B3A34] mb-1.5">Deskripsi</label>
      <textarea name="description" rows="3" placeholder="Deskripsi singkat webinar..."
                class="{{ $inp }} resize-none">{{ $val('description') }}</textarea>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-semibold text-[#1B3A34] mb-1.5">
          Tanggal & Waktu <span class="text-red-500">*</span>
        </label>
        <input type="datetime-local" name="event_date" required
               value="{{ old('event_date', $webinar ? $webinar->event_date->format('Y-m-d\TH:i') : '') }}"
               class="{{ $inp }}">
      </div>
      <div>
        <label class="block text-sm font-semibold text-[#1B3A34] mb-1.5">Link Meeting / Zoom</label>
        <input type="url" name="zoom_link" value="{{ $val('zoom_link') }}"
               placeholder="https://zoom.us/j/..."
               class="{{ $inp }}">
      </div>
    </div>

    {{-- Brand yang boleh ikut webinar --}}
    <div>
      <label class="block text-sm font-semibold text-[#1B3A34] mb-1.5">
        Brand yang Boleh Ikut Webinar
        <span class="ml-1 text-[11px] font-normal text-[#4B5F5A]">(kosongkan = semua brand)</span>
      </label>

      {{-- Toggle: semua atau pilih --}}
      <div class="mb-3 flex items-center gap-4">
        <label class="flex cursor-pointer items-center gap-2 text-sm text-[#1B3A34]">
          <input type="radio" name="_allowed_brands_mode" value="all" id="brands_mode_all"
                 @checked($isAllBrandsAllowed) class="accent-[#2D8659]">
          Semua brand boleh ikut
        </label>
        <label class="flex cursor-pointer items-center gap-2 text-sm text-[#1B3A34]">
          <input type="radio" name="_allowed_brands_mode" value="specific" id="brands_mode_specific"
                 @checked(!$isAllBrandsAllowed) class="accent-[#2D8659]">
          Pilih brand tertentu
        </label>
      </div>

      {{-- Grid checkbox brand --}}
      <div id="brands_checkbox_wrap"
           class="{{ $isAllBrandsAllowed ? 'hidden' : '' }} rounded-lg border border-[#DCE7E1] bg-[#F4F8F6] p-4">
        <div class="mb-2 flex items-center justify-between">
          <span class="text-xs font-semibold text-[#4B5F5A] uppercase tracking-wide">Pilih brand:</span>
          <button type="button" id="selectAllBrandsBtn"
                  class="text-xs font-semibold text-[#2D8659] hover:underline">Pilih Semua</button>
        </div>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
          @foreach($brands as $b)
          <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-[#DCE7E1] bg-white px-3 py-2 text-sm transition hover:border-[#2D8659]">
            <input type="checkbox" name="allowed_brands[]" value="{{ $b->code }}"
                   @checked(is_array($savedAllowedBrands) && in_array($b->code, $savedAllowedBrands))
                   class="brand-checkbox h-4 w-4 rounded accent-[#2D8659]">
            <span class="text-[#1B3A34]">{{ $b->name }}</span>
            <span class="ml-auto text-[10px] text-[#4B5F5A] font-mono">{{ $b->code }}</span>
          </label>
          @endforeach
        </div>
      </div>
    </div>


  </div>
</div>

{{-- ===== PENGATURAN SERTIFIKAT ===== --}}
<div class="bg-white rounded-xl border border-[#DCE7E1] p-6 shadow-sm mb-5">
  <p class="text-xs font-bold uppercase tracking-widest text-[#2D8659] mb-1">Pengaturan Sertifikat</p>
  <p class="text-xs text-[#4B5F5A] mb-5">Tentukan metode penyediaan sertifikat webinar untuk peserta yang disetujui kehadirannya.</p>

  {{-- Pilihan Sumber Sertifikat --}}
  <div class="mb-5">
    <label class="block text-sm font-semibold text-[#1B3A34] mb-2">Metode Sertifikat Webinar <span class="text-red-500">*</span></label>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <!-- Option 1: Template Sistem -->
      <label class="relative flex items-start gap-3 p-4 rounded-xl border-2 cursor-pointer transition bg-white border-[#DCE7E1] has-[:checked]:border-[#2D8659] has-[:checked]:bg-[#2D8659]/5 hover:border-[#2D8659]/60">
        <input type="radio" name="certificate_source" value="template" id="cert_source_template"
               @checked($certSource === 'template') class="mt-1 accent-[#2D8659]">
        <div>
          <span class="block text-sm font-bold text-[#1B3A34] flex items-center gap-1.5">
            <svg class="w-4 h-4 text-[#2D8659]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
              <polyline points="14 2 14 8 20 8"/>
            </svg>
            Template Sertifikat Sistem
          </span>
          <span class="block text-xs text-[#4B5F5A] mt-1 leading-relaxed">
            Pilih template visual kanvas resmi dari sistem. Berkas PDF sertifikat akan digenerate otomatis dengan nama peserta dan nomor seri resmi.
          </span>
        </div>
      </label>

      <!-- Option 2: Google Drive -->
      <label class="relative flex items-start gap-3 p-4 rounded-xl border-2 cursor-pointer transition bg-white border-[#DCE7E1] has-[:checked]:border-[#2D8659] has-[:checked]:bg-[#2D8659]/5 hover:border-[#2D8659]/60">
        <input type="radio" name="certificate_source" value="gdrive" id="cert_source_gdrive"
               @checked($certSource === 'gdrive') class="mt-1 accent-[#2D8659]">
        <div>
          <span class="block text-sm font-bold text-[#1B3A34] flex items-center gap-1.5">
            <svg class="w-4 h-4 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
              <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/>
            </svg>
            Tautan Google Drive
          </span>
          <span class="block text-xs text-[#4B5F5A] mt-1 leading-relaxed">
            Hanya perlu mengisi tautan Google Drive. Peserta yang disetujui akan langsung diarahkan ke tautan Google Drive tersebut.
          </span>
        </div>
      </label>
    </div>
  </div>

  {{-- Container Opsi 1: Template Sertifikat --}}
  <div id="cert_template_container" class="{{ $certSource === 'gdrive' ? 'hidden' : '' }} mb-5 p-4 rounded-xl border border-emerald-100 bg-emerald-50/30">
    <div class="flex items-center justify-between mb-2">
      <label class="block text-sm font-semibold text-[#1B3A34]">
        Pilih Template Sertifikat <span class="text-red-500">*</span>
      </label>
      <a href="{{ route('admin.document-templates.create', ['type' => 'certificate']) }}" target="_blank"
         class="text-xs font-semibold text-[#2D8659] hover:underline flex items-center gap-1">
        <i class="fas fa-plus text-[10px]"></i> Buat Template Baru
      </a>
    </div>

    <select name="certificate_template_id" id="certificate_template_id" class="{{ $inp }}">
      <option value="">-- Pilih Template Sertifikat --</option>
      @if(isset($certificateTemplates))
        @php
          $webinarTemplates = $certificateTemplates->filter(fn($t) => $t->certificate_type === 'webinar');
          $otherTemplates = $certificateTemplates->filter(fn($t) => $t->certificate_type !== 'webinar');
        @endphp

        @if($webinarTemplates->isNotEmpty())
          <optgroup label="Template Khusus Webinar">
            @foreach($webinarTemplates as $t)
              <option value="{{ $t->id }}" @selected($selectedTemplateId == $t->id || (!$selectedTemplateId && $t->is_default))>
                {{ $t->name }} {{ $t->is_default ? '(Default)' : '' }} [Versi {{ $t->version_number }}]
              </option>
            @endforeach
          </optgroup>
        @endif

        @if($otherTemplates->isNotEmpty())
          <optgroup label="Template Sertifikat Lainnya">
            @foreach($otherTemplates as $t)
              <option value="{{ $t->id }}" @selected($selectedTemplateId == $t->id)>
                {{ $t->name }} [{{ ucfirst($t->certificate_type ?? 'magang') }}]
              </option>
            @endforeach
          </optgroup>
        @endif
      @endif
    </select>
    <p class="text-xs text-[#4B5F5A] mt-1.5">Template visual yang dipilih akan otomatis digenerate menjadi berkas sertifikat PDF resmi bagi peserta.</p>
  </div>

  {{-- Container Opsi 2: Link Google Drive --}}
  <div id="cert_gdrive_container" class="{{ $certSource === 'gdrive' ? '' : 'hidden' }} mb-5 p-4 rounded-xl border border-blue-100 bg-blue-50/30">
    <label class="block text-sm font-semibold text-[#1B3A34] mb-1.5">
      Link Google Drive Sertifikat <span class="text-red-500">*</span>
    </label>
    <div class="relative">
      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-blue-500">
        <i class="fab fa-google-drive text-sm"></i>
      </div>
      <input type="url" name="gdrive_certificate_link" id="gdrive_certificate_link"
             value="{{ $gdriveLinkVal }}"
             placeholder="https://drive.google.com/drive/folders/..."
             class="{{ $inp }} pl-9">
    </div>
    <p class="text-xs text-[#4B5F5A] mt-1.5">Masukkan tautan folder atau file Google Drive. Pastikan izin akses link dapat dibuka oleh publik (siapa saja yang memiliki link).</p>
  </div>

  {{-- Brand Penyelenggara --}}
  <div class="mb-6">
    <label class="block text-sm font-semibold text-[#1B3A34] mb-1.5">Brand Penyelenggara & Aset Sertifikat <span class="text-red-500">*</span></label>
    <select id="certBrandSelect" name="brand_id" class="{{ $inp }} lg:w-1/2" required>
      <option value="">-- Pilih Brand --</option>
      @foreach($brands as $b)
        <option value="{{ $b->id }}" @selected($selectedCertBrandId == $b->id)>
          {{ $b->name }} ({{ $b->code }})
        </option>
      @endforeach
    </select>
    <p class="text-xs text-[#4B5F5A] mt-1">Logo, stempel, dan tanda tangan digital sertifikat akan mengikuti data dari brand ini.</p>
  </div>

  {{-- Preview Aset --}}
  <div id="brandPreviewContainer" class="hidden border border-[#DCE7E1] rounded-lg p-4 bg-[#F4F8F6]">
    <h3 class="text-sm font-semibold text-[#1B3A34] mb-4">Preview Aset Sertifikat Brand: <span id="previewBrandName" class="text-[#2D8659]"></span></h3>
    
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
      {{-- Background Preview --}}
      <div>
        <p class="text-xs font-semibold text-[#4B5F5A] mb-2 uppercase">Background</p>
        <div class="bg-white rounded-lg border border-[#DCE7E1] p-2 h-32 flex items-center justify-center relative overflow-hidden">
          <img id="previewBg" src="" alt="Background" class="max-h-full max-w-full object-contain hidden z-10">
          <p id="previewBgMissing" class="text-xs text-red-500 text-center italic hidden">Belum ada background untuk brand ini</p>
        </div>
      </div>

      {{-- Logo Preview --}}
      <div>
        <p class="text-xs font-semibold text-[#4B5F5A] mb-2 uppercase">Logo</p>
        <div class="bg-white rounded-lg border border-[#DCE7E1] p-2 h-32 flex items-center justify-center">
          <img id="previewLogo" src="" alt="Logo" class="max-h-full max-w-full object-contain hidden">
          <p id="previewLogoMissing" class="text-xs text-red-500 text-center italic hidden">Belum ada logo untuk brand ini</p>
        </div>
      </div>

      {{-- Stempel Preview --}}
      <div>
        <p class="text-xs font-semibold text-[#4B5F5A] mb-2 uppercase">Stempel Resmi</p>
        <div class="bg-white rounded-lg border border-[#DCE7E1] p-2 h-32 flex items-center justify-center">
          <img id="previewStamp" src="" alt="Stempel" class="max-h-full max-w-full object-contain hidden">
          <p id="previewStampMissing" class="text-xs text-red-500 text-center italic hidden">Belum ada stempel untuk brand ini</p>
        </div>
      </div>

      {{-- Signature Preview --}}
      <div>
        <p class="text-xs font-semibold text-[#4B5F5A] mb-2 uppercase">Tanda Tangan</p>
        <div class="bg-white rounded-lg border border-[#DCE7E1] p-3 h-32 flex flex-col items-center justify-center">
          <img id="previewSig" src="" alt="Signature" class="max-h-16 max-w-full object-contain hidden mb-2">
          <p id="previewSigMissing" class="text-xs text-red-500 text-center italic hidden mb-2">Belum ada TTD</p>
        </div>
      </div>
    </div>
    
    {{-- Signatory Name & Position --}}
    <div class="grid grid-cols-2 gap-4 mt-6">
      <div>
        <label class="block text-sm font-semibold text-[#1B3A34] mb-1.5">Nama Penandatangan</label>
        <input type="text" id="previewSigNameInput" class="w-full rounded-lg border border-[#DCE7E1] bg-gray-100 px-3 py-2.5 text-sm text-[#1B3A34]" readonly placeholder="Akan terisi otomatis">
      </div>
      <div>
        <label class="block text-sm font-semibold text-[#1B3A34] mb-1.5">Jabatan Penandatangan</label>
        <input type="text" id="previewSigPosInput" class="w-full rounded-lg border border-[#DCE7E1] bg-gray-100 px-3 py-2.5 text-sm text-[#1B3A34]" readonly placeholder="Akan terisi otomatis">
      </div>
    </div>
  </div>
</div>

<script>
// Data brands dari server
const brandsData = @json($brands);

function updateBrandPreview() {
    const sel = document.getElementById('certBrandSelect');
    const container = document.getElementById('brandPreviewContainer');
    
    if (!sel.value) {
        container.classList.add('hidden');
        return;
    }

    const brand = brandsData.find(b => b.id == sel.value);
    if (!brand) return;

    container.classList.remove('hidden');
    document.getElementById('previewBrandName').textContent = brand.name;

    // Helper untuk update image preview
    const updateImg = (imgId, missingId, path) => {
        const imgEl = document.getElementById(imgId);
        const missingEl = document.getElementById(missingId);
        if (path) {
            imgEl.src = '/storage/' + path;
            imgEl.classList.remove('hidden');
            missingEl.classList.add('hidden');
        } else {
            imgEl.classList.add('hidden');
            missingEl.classList.remove('hidden');
        }
    };

    updateImg('previewBg', 'previewBgMissing', brand.webinar_certificate_bg);
    updateImg('previewLogo', 'previewLogoMissing', brand.logo);
    updateImg('previewStamp', 'previewStampMissing', brand.stamp);
    updateImg('previewSig', 'previewSigMissing', brand.signature);

    // Update Signatory Inputs
    document.getElementById('previewSigNameInput').value = brand.signatory_name || 'Tidak ada data nama';
    document.getElementById('previewSigPosInput').value = brand.signatory_position || 'Tidak ada data jabatan';
}

document.getElementById('certBrandSelect').addEventListener('change', updateBrandPreview);

// Toggle sumber sertifikat (Template vs GDrive)
const radioTemplate = document.getElementById('cert_source_template');
const radioGdrive = document.getElementById('cert_source_gdrive');
const containerTemplate = document.getElementById('cert_template_container');
const containerGdrive = document.getElementById('cert_gdrive_container');
const inputTemplate = document.getElementById('certificate_template_id');
const inputGdrive = document.getElementById('gdrive_certificate_link');

function updateCertSourceToggle() {
    if (radioGdrive && radioGdrive.checked) {
        if (containerGdrive) containerGdrive.classList.remove('hidden');
        if (containerTemplate) containerTemplate.classList.add('hidden');
        if (inputGdrive) inputGdrive.setAttribute('required', 'required');
        if (inputTemplate) inputTemplate.removeAttribute('required');
    } else {
        if (containerTemplate) containerTemplate.classList.remove('hidden');
        if (containerGdrive) containerGdrive.classList.add('hidden');
        if (inputTemplate) inputTemplate.setAttribute('required', 'required');
        if (inputGdrive) inputGdrive.removeAttribute('required');
    }
}

radioTemplate?.addEventListener('change', updateCertSourceToggle);
radioGdrive?.addEventListener('change', updateCertSourceToggle);

// Trigger on load
document.addEventListener('DOMContentLoaded', () => {
    updateBrandPreview();
    updateCertSourceToggle();
});

// Toggle brand checkbox panel
document.querySelectorAll('input[name="_allowed_brands_mode"]').forEach(radio => {
    radio.addEventListener('change', () => {
        const wrap = document.getElementById('brands_checkbox_wrap');
        if (radio.value === 'specific' && radio.checked) {
            wrap.classList.remove('hidden');
        } else if (radio.value === 'all' && radio.checked) {
            wrap.classList.add('hidden');
            // Uncheck semua checkbox agar tidak terkirim
            wrap.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
        }
    });
});

// Select all brands button
document.getElementById('selectAllBrandsBtn')?.addEventListener('click', () => {
    const allChecked = [...document.querySelectorAll('.brand-checkbox')].every(cb => cb.checked);
    document.querySelectorAll('.brand-checkbox').forEach(cb => cb.checked = !allChecked);
});
</script>
