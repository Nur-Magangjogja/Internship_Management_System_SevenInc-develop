<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ $certificate->division === 'WBN' ? 'Sertifikat Webinar' : 'Sertifikat Magang' }}</title>
  @php
    use Carbon\Carbon;

    // Kalau division WBN → render template webinar inline
    $isWebinar = ($certificate->division === 'WBN');
  @endphp
</head>
<body>
@if($isWebinar)
  {{-- ===== PREVIEW WEBINAR ===== --}}
  @php
    $dataUri = function ($relPath) {
        if (empty($relPath)) return '';
        $abs = storage_path('app/public/' . $relPath);
        if (!file_exists($abs)) return '';
        $finfo  = finfo_open(FILEINFO_MIME_TYPE);
        $mime   = finfo_file($finfo, $abs) ?: 'image/png';
        finfo_close($finfo);
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($abs));
    };

    $bgUrl    = $dataUri($certificate->background_image ?? '');
    $logo1Url = $dataUri($certificate->logo1 ?? '');
    $ttd1Url  = $dataUri($certificate->signature_image1 ?? '');
    $ttd2Url  = $dataUri($certificate->signature_image2 ?? '');
    $hasRightSig = $ttd2Url || !empty($certificate->name_signatory2) || !empty($certificate->role2);

    Carbon::setLocale('id');
    $eventDate = Carbon::parse($certificate->start_date)->isoFormat('DD MMMM YYYY');

    $webinarTitle = null;
    try {
        $att = \App\Models\WebinarAttendance::where('certificate_id', $certificate->id)->first();
        $webinarTitle = $att?->webinar?->title;
    } catch (\Throwable $e) {}

    $company = $certificate->company ?? 'Seven Inc';
    $city    = $certificate->city ?? 'Yogyakarta';
    if (str_contains($company, '||')) {
        [$webinarTitle2, $company] = explode('||', $company, 2);
        $webinarTitle = $webinarTitle ?? $webinarTitle2;
    }
  @endphp

  <style>
    @page { size: A4 landscape; margin: 0; }
    * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @media screen {
      html, body {
        width: 100%;
        min-height: 100vh;
        overflow-x: auto;
        font-family: Arial, sans-serif;
        background: #0f172a;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 12px;
      }
      .page {
        position: relative;
        width: 297mm;
        height: 210mm;
        overflow: hidden;
        background: #ffffff;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6);
        border-radius: 6px;
        flex-shrink: 0;
      }
    }
    @media print {
      html, body { width: 297mm; height: 210mm; overflow: hidden; background: #fff; margin: 0; padding: 0; }
      .page { position: relative; width: 297mm; height: 210mm; overflow: hidden; background: #ffffff; }
    }
    .overlay { position: absolute; inset: 0; background: rgba(255,255,255,0.72); z-index: 1; }
    .content { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 12mm 25mm; text-align: center; z-index: 2; }
    .logo-wrap { margin-bottom: 5mm; } .logo-wrap img { max-height: 18mm; max-width: 50mm; object-fit: contain; }
    .cert-title { font-size: 28pt; font-weight: 900; letter-spacing: 4px; color: #1a1a1a; text-transform: uppercase; margin-bottom: 2mm; }
    .cert-number { font-size: 9pt; color: #444; margin-bottom: 5mm; }
    .given-to { font-size: 10pt; color: #555; margin-bottom: 2mm; }
    .recipient-name { font-size: 26pt; font-weight: 900; color: #1a1a1a; letter-spacing: 3px; text-transform: uppercase; margin-bottom: 1mm; }
    .name-underline { width: 75%; height: 1.5px; background: #1a1a1a; margin: 1mm auto 4mm; }
    .as-label { font-size: 10pt; color: #555; margin-bottom: 1mm; }
    .role-label { font-size: 18pt; font-weight: 900; letter-spacing: 3px; color: #1a1a1a; text-transform: uppercase; margin-bottom: 4mm; }
    .webinar-desc { font-size: 10pt; color: #333; line-height: 1.55; max-width: 160mm; margin-bottom: 4mm; }
    .webinar-desc strong { color: #1a1a1a; }
    .location-date { font-size: 10pt; color: #555; margin-bottom: 6mm; }
    .signatures { display: flex; justify-content: {{ $hasRightSig ? 'space-around' : 'center' }}; width: 100%; gap: 20mm; }
    .sig { display: flex; flex-direction: column; align-items: center; min-width: 45mm; }
    .sig-role { font-size: 9pt; color: #555; margin-bottom: 10mm; }
    .sig-ttd { width: 40mm; height: 14mm; display: flex; align-items: center; justify-content: center; margin-bottom: 1mm; }
    .sig-ttd img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .sig-line { width: 45mm; height: 1px; background: #1a1a1a; margin-bottom: 2mm; }
    .sig-name { font-size: 10pt; font-weight: 700; color: #1a1a1a; }
  </style>

  <div class="page">
    @if($bgUrl)
    <img src="{{ $bgUrl }}" alt=""
         style="position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover;z-index:0;display:block;">
    <div class="overlay"></div>
    @endif
    <div class="content">
      @if($logo1Url)<div class="logo-wrap"><img src="{{ $logo1Url }}" alt="Logo"></div>@endif
      <div class="cert-title">Sertifikat</div>
      <div class="cert-number">Nomor: {{ $certificate->serial_number }}</div>
      <div class="given-to">Diberikan kepada:</div>
      <div class="recipient-name">{{ $certificate->name }}</div>
      <div class="name-underline"></div>
      <div class="as-label">Sebagai</div>
      <div class="role-label">Peserta</div>
      <div class="webinar-desc">
        Dalam Kegiatan
        @if($webinarTitle)<strong>"{{ $webinarTitle }}"</strong>@else<strong>"Webinar"</strong>@endif
        yang diselenggarakan oleh <strong>{{ $company }}</strong>
        pada tanggal <strong>{{ $eventDate }}</strong>
      </div>
      <div class="location-date">{{ $city }}, {{ $eventDate }}</div>
      <div class="signatures">
        <div class="sig">
          @if(!empty($certificate->role1))<div class="sig-role">{{ $certificate->role1 }}</div>@else<div class="sig-role">&nbsp;</div>@endif
          <div class="sig-ttd">@if($ttd1Url)<img src="{{ $ttd1Url }}" alt="TTD">@endif</div>
          <div class="sig-line"></div>
          <div class="sig-name">{{ $certificate->name_signatory1 }}</div>
        </div>
        @if($hasRightSig)
        <div class="sig">
          @if(!empty($certificate->role2))<div class="sig-role">{{ $certificate->role2 }}</div>@else<div class="sig-role">&nbsp;</div>@endif
          <div class="sig-ttd">@if($ttd2Url)<img src="{{ $ttd2Url }}" alt="TTD 2">@endif</div>
          @if(!empty($certificate->name_signatory2))
          <div class="sig-line"></div>
          <div class="sig-name">{{ $certificate->name_signatory2 }}</div>
          @endif
        </div>
        @endif
      </div>
    </div>
  </div>

@else
  {{-- ===== PREVIEW MAGANG (template lama) ===== --}}
  @php
    $bgUrl    = $certificate->background_image  ? \Illuminate\Support\Facades\Storage::url($certificate->background_image)  : '';
    $logo1Url = $certificate->logo1             ? \Illuminate\Support\Facades\Storage::url($certificate->logo1)             : '';
    $logo2Url = $certificate->logo2             ? \Illuminate\Support\Facades\Storage::url($certificate->logo2)             : null;
    $ttd1Url  = $certificate->signature_image1  ? \Illuminate\Support\Facades\Storage::url($certificate->signature_image1)  : '';
    $ttd2Url  = $certificate->signature_image2  ? \Illuminate\Support\Facades\Storage::url($certificate->signature_image2)  : null;
    $hasRightSig = $ttd2Url || !empty($certificate->name_signatory2) || !empty($certificate->role2);

    $divisionLabels = [
      'ADM'=>'Administrasi','UIUX'=>'UI/UX Designer','PROG'=>'Programmer (Front end / Back end)','HR'=>'Human Resource',
      'SMM'=>'Social Media Specialist','PV'=>'Photographer','VID'=>'Videographer','CW'=>'Content Writer','MS'=>'Marketing & Sales',
      'CD'=>'Content Creative (Desain Grafis)','DM'=>'Digital Marketing','PR'=>'Marcom/Public Relations','TC'=>'Tik Tok Creator',
      'CP'=>'Content Planner','PM'=>'Project Manager','LAS'=>'Las','ANIM'=>'Animasi',
    ];
    $divisionLabel = $divisionLabels[$certificate->division] ?? $certificate->division;

    $start = Carbon::parse($certificate->start_date);
    $end   = Carbon::parse($certificate->end_date);
    $months = $start->diffInMonths($end);
    $pivot  = $start->copy()->addMonths($months);
    $days   = $pivot->diffInDays($end);
    $duration_text = trim(($months ? $months.' bulan ' : '').($days ? $days.' hari' : ''));
    if ($duration_text === '') $duration_text = '0 hari';
  @endphp

  <style>
    @page { size: 1123px 794px; margin: 0; }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    :root {
      --page-w: 1123px; --page-h: 794px; --text: #000; --dark: #293936;
      --serif: "Times New Roman", Times, serif;
      --script1: "Edwardian Script ITC", "Segoe Script", "Brush Script MT", cursive, serif;
      --script2: "Segoe Script", "Brush Script MT", cursive, serif;
    }
    @media screen {
      html, body {
        width: 100%;
        min-height: 100vh;
        margin: 0;
        padding: 24px 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #0f172a;
        overflow-x: auto;
      }
      .page {
        position: relative;
        width: var(--page-w);
        height: var(--page-h);
        overflow: hidden;
        background: url('{{ $bgUrl }}') center/cover no-repeat;
        box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6);
        border-radius: 6px;
        flex-shrink: 0;
      }
    }
    @media print {
      html, body { height: 100%; margin: 0; padding: 0; background: #fff; }
      .page { position: relative; width: var(--page-w); height: var(--page-h); overflow: hidden; background: url('{{ $bgUrl }}') center/cover no-repeat; }
    }
    .content { position: absolute; inset: 0; padding: 60px 72px; display: grid; grid-template-rows: auto auto 1fr auto; }
    .logos { position: relative; width: 100%; height: 120px; }
    .logo-left { position: absolute; top: -20px; left: 2%; height: 100%; display: flex; align-items: center; }
    @if(empty($logo2Url)) .logo-left { left: 50%; transform: translateX(-50%); } @endif
    .logo-right { position: absolute; top: 10px; right: 20px; height: 100%; display: flex; align-items: center; }
    .logo-left img { max-height: 90px; max-width: 250px; object-fit: contain; }
    .logo-right img { max-height: 120px; max-width: 300px; object-fit: contain; }
    .headings { text-align: center; margin-top: 0; }
    .title { font: italic 700 72px var(--script2); color: var(--dark); line-height: 1; margin: 0 0 8px; }
    .subtitle { font: 400 22px var(--serif); color: var(--text); margin: 0 0 10px; }
    .name-wrap { text-align: center; margin-top: 14px; }
    .name { display: inline-block; font: italic 72px var(--script1); color: var(--text); line-height: 1.1; white-space: nowrap; }
    .name-line { width: 80%; max-width: 780px; height: 2px; background: #000; margin: 1px auto 0; }
    .body { margin-top: 8px; text-align: center; font: 400 20px var(--serif); color: var(--text); display: grid; gap: 10px; justify-items: center; }
    .signatures { position: relative; width: 100%; height: 230px; margin-top: -40px; font: 400 18px var(--serif); color: var(--text); }
    .sig { position: absolute; bottom: 20px; width: 260px; text-align: center; }
    .sig-left { left: 50%; transform: translateX(-50%); }
    @if($hasRightSig) .sig-left { left: 7%; transform: none; } @endif
    .sig-right { right: 20%; transform: translateX(50%); }
    .sig .line { height: 2px; background: #000; margin: 0 0 6px; }
    .sig .name { font: 600 18px "Times New Roman", Times, serif; }
    .sig .role { margin-bottom: 65px; }
    .sig .image { position: absolute; z-index: 3; pointer-events: none; }
    .sig .image img { position: absolute; inset: 0; margin: auto; max-width: 100%; max-height: 100%; object-fit: contain; opacity: 0.95; }
    @media print { body { background: none; } .page { box-shadow: none; } }
  </style>

  <div class="page">
    <div class="content">
      <div class="logos">
        <div class="logo-left">@if($logo1Url)<img src="{{ $logo1Url }}" alt="Logo 1" />@endif</div>
        <div class="logo-right">@if($logo2Url)<img src="{{ $logo2Url }}" alt="Logo 2" />@endif</div>
      </div>
      <div class="headings">
        <div class="title">Sertifikat</div>
        <div><p><b>NO: {{ $certificate->serial_number ?? '000/SERT/—/—/—/—' }}</b></p></div>
        <div class="subtitle">Diberikan kepada:</div>
      </div>
      <div class="name-wrap">
        <span class="name">{{ $certificate->name }}</span>
        <div class="name-line"></div>
      </div>
      <div class="body">
        <div>Telah menyelesaikan magang bidang <strong>{{ $divisionLabel }}</strong> di {{ $certificate->company }} selama <strong>{{ $duration_text }}</strong>.</div>
        <div>Mulai dari <strong>{{ Carbon::parse($certificate->start_date)->locale('id')->translatedFormat('j F Y') }}</strong> sampai dengan <strong>{{ Carbon::parse($certificate->end_date)->locale('id')->translatedFormat('j F Y') }}</strong></div>
        <div><strong>{{ $certificate->city }}</strong>, <strong>{{ Carbon::parse($certificate->end_date)->locale('id')->translatedFormat('j F Y') }}</strong></div>
      </div>
      <div class="signatures">
        <div class="sig sig-left">
          @if(!empty($certificate->role1))<div class="role">{{ $certificate->role1 }}</div>@endif
          @if($ttd1Url)<div class="image" style="top:-10px;left:-24px;width:300px;height:140px;"><img src="{{ $ttd1Url }}" alt="TTD 1"/></div>@endif
          <div class="line"></div>
          <div class="name">{{ $certificate->name_signatory1 }}</div>
        </div>
        @if($hasRightSig)
        <div class="sig sig-right">
          @if(!empty($certificate->role2))<div class="role">{{ $certificate->role2 }}</div>@endif
          @if($ttd2Url)<div class="image" style="top:-16px;left:-27px;width:300px;height:160px;"><img src="{{ $ttd2Url }}" alt="TTD 2"/></div>@endif
          @if(!empty($certificate->name_signatory2))<div class="line"></div><div class="name">{{ $certificate->name_signatory2 }}</div>@endif
        </div>
        @endif
      </div>
    </div>
  </div>

@endif

<script>
  function autoScaleCert() {
    const page = document.querySelector('.page');
    if (!page) return;
    const certW = 1123;
    const availW = window.innerWidth - 32;
    if (availW < certW) {
      const scale = Math.max(0.35, availW / certW);
      page.style.transform = `scale(${scale})`;
      page.style.transformOrigin = 'center center';
    } else {
      page.style.transform = 'none';
    }
  }
  window.addEventListener('resize', autoScaleCert);
  window.addEventListener('DOMContentLoaded', autoScaleCert);
</script>
</body>
</html>