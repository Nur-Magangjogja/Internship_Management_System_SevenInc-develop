<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>{{ $pdfTitle ?? 'Sertifikat Webinar' }}</title>
  @php
    use Carbon\Carbon;

    // Helper: relative/absolute path or fallback → data URI
    $resolveDataUri = function ($path, $fallbacks = []) {
        $candidates = array_filter(array_merge([$path], (array)$fallbacks));
        foreach ($candidates as $cand) {
            if (empty($cand)) continue;
            if (str_starts_with($cand, 'data:image/')) return $cand;
            $cleanCand = ltrim($cand, '/');
            if (str_starts_with($cleanCand, 'storage/')) {
                $cleanCand = substr($cleanCand, 8);
            }
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($cleanCand)) {
                $content = \Illuminate\Support\Facades\Storage::disk('public')->get($cleanCand);
                $mime = \Illuminate\Support\Facades\Storage::disk('public')->mimeType($cleanCand) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode($content);
            }
            if (file_exists($cand) && is_file($cand)) {
                $mime = mime_content_type($cand) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($cand));
            }
            $inPub = public_path(ltrim($cand, '/'));
            if (file_exists($inPub) && is_file($inPub)) {
                $mime = mime_content_type($inPub) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($inPub));
            }
            $inStorage = storage_path('app/public/' . ltrim($cand, '/'));
            if (file_exists($inStorage) && is_file($inStorage)) {
                $mime = mime_content_type($inStorage) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($inStorage));
            }
        }
        return '';
    };

    $brandModel = $certificate->attendance?->webinar?->brand;
    if (!$brandModel && !empty($certificate->company_name)) {
        $brandModel = \App\Models\Brand::where('name', $certificate->company_name)->first();
    }

    $bgCandidate = $brandModel?->webinar_certificate_bg 
        ?: ($certificate->background_image_path ?? $certificate->background_image ?? '');

    $bgUrl    = $resolveDataUri($bgCandidate, [
        'images/certificate/certificate-bg.png',
        'images/backgrounds/cert_bg.png',
    ]);
    $logo1Url = $resolveDataUri($brandModel?->logo ?: ($certificate->company_logo_path ?? $certificate->logo1 ?? ''), [
        'images/mou/seven-inc-logo.png',
        'images/logos/logo_seveninc.png',
    ]);
    $logo2Url = $resolveDataUri($certificate->logo2 ?? '');
    $ttd1Url  = $resolveDataUri($brandModel?->signature ?: ($certificate->signature_image_path ?? $certificate->signature_image1 ?? ''), [
        'images/mou/signature-sample.png',
        'images/signature/ttd_arisetiahusbana.png',
        'images/signatures/ttd.png',
    ]);
    $ttd2Url  = $resolveDataUri($certificate->signature_image2 ?? '');
    $stampUrl = $resolveDataUri($brandModel?->stamp ?: '', []);

    $hasRightSig = $ttd2Url || !empty($certificate->name_signatory2) || !empty($certificate->role2);

    $startDate = $certificate->start_date ?? $certificate->attendance?->webinar?->event_date ?? $certificate->created_at;
    Carbon::setLocale('id');
    $eventDate = Carbon::parse($startDate)->isoFormat('DD MMMM YYYY');

    // Ambil judul webinar dari webinar_attendances
    $webinarTitle = $certificate->attendance?->webinar?->title;

    $company = $certificate->company_name ?? $certificate->company ?? 'Seven Inc';
    $city    = $certificate->city    ?? 'Yogyakarta';

    // Handle format "JudulWebinar||Company" untuk generate manual
    $webinarTitleFromCompany = null;
    if (str_contains($company, '||')) {
        [$webinarTitleFromCompany, $company] = explode('||', $company, 2);
    }

    // Final judul webinar
    $finalWebinarTitle = $webinarTitle ?? $webinarTitleFromCompany;

    $recipientName = $certificate->name ?? $certificate->attendance?->user?->name ?? 'Peserta';
    $serialNum = $certificate->certificate_number ?? $certificate->serial_number ?? '';
    $sigName = $certificate->signatory_name ?? $certificate->name_signatory1 ?? 'Penandatangan';
    $sigRole = $certificate->signatory_position ?? $certificate->role1 ?? 'Penyelenggara';
  @endphp

  <style>
    @page {
      size: A4 landscape;
      margin: 0;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    html, body {
      width: 297mm;
      height: 210mm;
      overflow: hidden;
      font-family: 'Arial', 'Helvetica Neue', Helvetica, sans-serif;
      background: #fff;
    }

    /* ── PAGE WRAPPER ── */
    .page {
      position: relative;
      width: 297mm;
      height: 210mm;
      overflow: hidden;
      background: #ffffff;
    }

    /* Overlay putih ringan supaya teks mudah dibaca */
    .overlay {
      position: absolute;
      inset: 0;
      background: rgba(255, 255, 255, 0.72);
      z-index: 1;
    }

    /* ── CONTENT WRAPPER ── */
    .content {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 12mm 25mm;
      text-align: center;
      gap: 0;
      z-index: 2;
    }

    /* ── LOGO ── */
    .logo-wrap {
      margin-bottom: 5mm;
    }
    .logo-wrap img {
      max-height: 18mm;
      max-width: 50mm;
      object-fit: contain;
    }

    /* ── JUDUL ── */
    .cert-title {
      font-size: 28pt;
      font-weight: 900;
      letter-spacing: 4px;
      color: #1a1a1a;
      text-transform: uppercase;
      margin-bottom: 2mm;
      line-height: 1;
    }

    /* ── NOMOR ── */
    .cert-number {
      font-size: 9pt;
      color: #444;
      margin-bottom: 5mm;
      letter-spacing: 0.3px;
    }

    /* ── DIBERIKAN KEPADA ── */
    .given-to {
      font-size: 10pt;
      color: #555;
      margin-bottom: 2mm;
    }

    /* ── NAMA PENERIMA ── */
    .recipient-name {
      font-size: 26pt;
      font-weight: 900;
      color: #1a1a1a;
      letter-spacing: 3px;
      text-transform: uppercase;
      line-height: 1.1;
      margin-bottom: 1mm;
    }
    .name-underline {
      width: 75%;
      height: 1.5px;
      background: #1a1a1a;
      margin: 1mm auto 4mm;
    }

    /* ── SEBAGAI PESERTA ── */
    .as-label {
      font-size: 10pt;
      color: #555;
      margin-bottom: 1mm;
    }
    .role-label {
      font-size: 18pt;
      font-weight: 900;
      letter-spacing: 3px;
      color: #1a1a1a;
      text-transform: uppercase;
      margin-bottom: 4mm;
    }

    /* ── DESKRIPSI WEBINAR ── */
    .webinar-desc {
      font-size: 10pt;
      color: #333;
      line-height: 1.55;
      max-width: 160mm;
      margin-bottom: 4mm;
    }
    .webinar-desc strong {
      color: #1a1a1a;
    }

    /* ── LOKASI & TANGGAL ── */
    .location-date {
      font-size: 10pt;
      color: #555;
      margin-bottom: 6mm;
    }

    /* ── TANDA TANGAN ── */
    .signatures {
      display: flex;
      justify-content: {{ $hasRightSig ? 'space-around' : 'center' }};
      width: 100%;
      gap: 20mm;
    }
    .sig {
      display: flex;
      flex-direction: column;
      align-items: center;
      min-width: 45mm;
    }
    .sig-role {
      font-size: 9pt;
      color: #555;
      margin-bottom: 10mm;
    }
    .sig-ttd {
      width: 40mm;
      height: 14mm;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1mm;
    }
    .sig-ttd img {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
    }
    .sig-line {
      width: 45mm;
      height: 1px;
      background: #1a1a1a;
      margin-bottom: 2mm;
    }
    .sig-name {
      font-size: 10pt;
      font-weight: 700;
      color: #1a1a1a;
    }

    @media print { body { background: none; } .page { box-shadow: none; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; } }
  </style>
</head>
<body>
<div class="page">
  @if($bgUrl)
  {{-- Background sebagai img tag (lebih reliable di Browsershot daripada CSS background) --}}
  <img src="{{ $bgUrl }}" alt=""
       style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;z-index:0;display:block;">
  <div class="overlay"></div>
  @endif

  <div class="content">

    {{-- LOGO --}}
    @if($logo1Url)
    <div class="logo-wrap">
      <img src="{{ $logo1Url }}" alt="Logo">
    </div>
    @endif

    {{-- JUDUL --}}
    <div class="cert-title">Sertifikat</div>

    {{-- NOMOR --}}
    <div class="cert-number">Nomor: {{ $serialNum }}</div>

    {{-- DIBERIKAN KEPADA --}}
    <div class="given-to">Diberikan kepada:</div>

    {{-- NAMA PESERTA --}}
    <div class="recipient-name">{{ $recipientName }}</div>
    <div class="name-underline"></div>

    {{-- SEBAGAI PESERTA --}}
    <div class="as-label">Sebagai</div>
    <div class="role-label">Peserta</div>

    {{-- DESKRIPSI --}}
    <div class="webinar-desc">
      @if(!empty($certificate->description))
        {!! nl2br(e($certificate->description)) !!}
      @else
        Dalam Kegiatan
        @if($finalWebinarTitle)
          <strong>"{{ $finalWebinarTitle }}"</strong>
        @else
          <strong>"Webinar"</strong>
        @endif
        yang diselenggarakan oleh <strong>{{ $company }}</strong>
        pada tanggal <strong>{{ $eventDate }}</strong>
      @endif
    </div>

    {{-- LOKASI & TANGGAL --}}
    <div class="location-date">{{ $city }}, {{ $eventDate }}</div>

    {{-- TANDA TANGAN --}}
    <div class="signatures">
      {{-- Penandatangan 1 (wajib) --}}
      <div class="sig">
        @if(!empty($sigRole))
          <div class="sig-role">{{ $sigRole }}</div>
        @else
          <div class="sig-role">&nbsp;</div>
        @endif
        <div class="sig-ttd" style="position: relative;">
          @if(!empty($stampUrl))
            <img src="{{ $stampUrl }}" alt="Stempel" style="position: absolute; left: -14mm; top: -8mm; height: 28mm; width: 28mm; opacity: 0.78; object-fit: contain; pointer-events: none; z-index: 1;">
          @endif
          @if($ttd1Url)
            <img src="{{ $ttd1Url }}" alt="TTD" style="position: relative; z-index: 2;">
          @endif
        </div>
        <div class="sig-line"></div>
        <div class="sig-name">{{ $sigName }}</div>
      </div>

      {{-- Penandatangan 2 (opsional) --}}
      @if($hasRightSig)
      <div class="sig">
        @if(!empty($certificate->role2))
          <div class="sig-role">{{ $certificate->role2 }}</div>
        @else
          <div class="sig-role">&nbsp;</div>
        @endif
        <div class="sig-ttd">
          @if($ttd2Url)
            <img src="{{ $ttd2Url }}" alt="TTD 2">
          @endif
        </div>
        @if(!empty($certificate->name_signatory2))
          <div class="sig-line"></div>
          <div class="sig-name">{{ $certificate->name_signatory2 }}</div>
        @endif
      </div>
      @endif
    </div>

  </div>
</div>
</body>
</html>
