<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ $pdfTitle ?? 'Sertifikat Magang' }}</title>
  @php
    use Carbon\Carbon;

    // helper: rel path (di DB) atau public path -> data URI
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

    $brandModel = null;
    if (isset($certificate->intern->brand)) {
        $brandModel = \App\Models\Brand::where('code', $certificate->intern->brand)->first();
    }
    if (!$brandModel && isset($certificate->intern->brand_id)) {
        $brandModel = \App\Models\Brand::find($certificate->intern->brand_id);
    }
    if (!$brandModel && !empty($certificate->company_name)) {
        $brandModel = \App\Models\Brand::where('name', $certificate->company_name)->first();
    }

    $bgCandidate = $brandModel?->internship_certificate_bg 
        ?: ($certificate->background_image_path ?: 'images/certificate/certificate-bg.png');
    $bgUrl    = $resolveDataUri($bgCandidate, ['images/certificate/certificate-bg.png']);
    $logo1Url = $resolveDataUri($brandModel?->logo ?: ($certificate->company_logo_path ?? ''), ['images/mou/seven-inc-logo.png']);
    $logo2Url = ''; // Not used in InternCertificate
    $ttd1Url  = $resolveDataUri($brandModel?->signature ?: ($certificate->signature_image_path ?? ''), ['images/mou/signature-sample.png']);
    $stampUrl = $resolveDataUri($brandModel?->stamp ?: '', []);
    $ttd2Url  = ''; // Not used in InternCertificate

    // Apakah kolom kanan (penandatangan 2) perlu ditampilkan
    $hasRightSig = $ttd2Url || !empty($certificate->name_signatory2) || !empty($certificate->role2);

    // Map kode divisi -> label
    $divisionLabels = [
      'ADM'=>'Administrasi','UIUX'=>'UI/UX Designer','PROG'=>'Programmer (Front end / Back end)','HR'=>'Human Resource',
      'SMM'=>'Social Media Specialist','PV'=>'Photographer / Videographer','CW'=>'Content Writer','MS'=>'Marketing & Sales',
      'CD'=>'Content Creative (Desain Grafis)','DM'=>'Digital Marketing','PR'=>'Marcom/Public Relations','TC'=>'Tik Tok Creator',
      'CP'=>'Content Planner','PM'=>'Project Manager','LAS'=>'Las','ANIM'=>'Animasi',
    ];
    $division = $certificate->intern->internship_interest ?? 'ADM';
    $divisionLabel = $divisionLabels[$division] ?? $division;

    // Durasi (X bulan Y hari)
    $start = Carbon::parse($certificate->intern->start_date ?? now());
    $end   = Carbon::parse($certificate->intern->end_date ?? now());
    $months = $start->diffInMonths($end);
    $pivot  = $start->copy()->addMonths($months);
    $days   = $pivot->diffInDays($end);
    $duration_text = trim(($months ? $months.' bulan ' : '').($days ? $days.' hari' : ''));
    if ($duration_text === '') $duration_text = '0 hari';
    
    $internName = $certificate->intern->fullname ?? ($certificate->intern->user->name ?? '-');
    $cityName = 'Yogyakarta'; // default
    if (isset($certificate->intern->brand_id)) {
        $brand = \App\Models\Brand::find($certificate->intern->brand_id);
        if ($brand && $brand->city) {
            $cityName = $brand->city;
        }
    }
  @endphp

  <style>
    @page { size: 1123px 794px; margin: 0; }
    html, body { height: 100%; }
    * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    :root {
      --page-w: 1123px; --page-h: 794px; --text: #000; --dark: #293936; --accent: #BE5640;
      --serif: "Times New Roman", Times, serif; --script1: "Edwardian Script ITC", "Segoe Script", "Brush Script MT", "Lucida Handwriting", cursive, serif;
      --script2: "Segoe Script", "Brush Script MT", "Lucida Handwriting", cursive, serif;
    }
    body { margin: 0; display: flex; align-items: center; justify-content: center; background: #f0f0f0; }
    .page { position: relative; width: var(--page-w); height: var(--page-h); overflow: hidden; background: url('{{ $bgUrl }}') center/cover no-repeat; }
    .content { position: absolute; inset: 0; padding: 60px 72px; display: grid; grid-template-rows: auto auto 1fr auto; }

    .logos { position: relative; width: 100%; height: 120px; }
    .logo-left { position: absolute; top: -20px; left: 2%; height: 100%; display: flex; align-items: center; }
    @if(empty($logo2Url))
      .logo-left { left: 50%; transform: translateX(-50%); }
    @endif
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
    .body > div:last-child { margin-top: 16px; }

    .serial { position: relative; margin: 12px auto 0; padding: 6px 12px; font: 600 16px var(--serif); color: var(--dark);
              background: rgba(255,255,255,0.72); border: 1px solid rgba(0,0,0,0.15); border-radius: 8px; letter-spacing: .2px;
              box-shadow: 0 2px 6px rgba(0,0,0,0.06); display: inline-block; }

    .signatures { position: relative; width: 100%; height: 230px; margin-top: -40px; font: 400 18px var(--serif); color: var(--text); }
    .sig { position: absolute; bottom: 20px; width: 260px; text-align: center; }
    .sig-left { left: 50%; transform: translateX(-50%); }
    @if($hasRightSig)
      .sig-left { left: 7%; transform: none; }
    @endif
    .sig-right { right: 20%; transform: translateX(50%); }
    .sig .line { height: 2px; background: #000; margin: 0 0 6px; }
    .sig .name { font: 600 18px "Times New Roman", Times, serif; letter-spacing: .2px; }
    .sig .role { margin-bottom: 65px; }
    .sig .image { position: absolute; z-index: 3; pointer-events: none; }
    .sig .image img { position: absolute; inset: 0; margin: auto; max-width: 100%; max-height: 100%; object-fit: contain; opacity: 0.95; }

    @media print { body { background: none; } .page { box-shadow: none; }}
  </style>
</head>
<body>
  <div class="page">
    <div class="content">

      <!-- LOGOS -->
      <div class="logos">
        <div class="logo-left">
          @if($logo1Url)
            <img src="{{ $logo1Url }}" alt="Logo 1" />
          @endif
        </div>
        <div class="logo-right">
          @if($logo2Url)
            <img src="{{ $logo2Url }}" alt="Logo 2" />
          @endif
        </div>
      </div>

      <!-- TITLES & SERIAL -->
      <div class="headings">
        <div class="title">Sertifikat</div>
        <div><p><b>NO: {{ $certificate->certificate_number ?? '000/SERT/—/—/—/—' }}</b></p></div>
        <div class="subtitle">Diberikan kepada:</div>
      </div>

      <!-- RECIPIENT NAME -->
      <div class="name-wrap">
        <span class="name">{{ $internName }}</span>
        <div class="name-line" aria-hidden="true"></div>
      </div>

      <!-- BODY TEXT -->
      <div class="body">
        <div>
          Telah menyelesaikan magang bidang <strong>{{ $divisionLabel }}</strong>
          di {{ $certificate->company_name }} selama <strong>{{ $duration_text }}</strong>.
        </div>
        <div>
          Mulai dari
          <strong>{{ Carbon::parse($certificate->intern->start_date ?? now())->locale('id')->translatedFormat('j F Y') }}</strong>
          sampai dengan
          <strong>{{ Carbon::parse($certificate->intern->end_date ?? now())->locale('id')->translatedFormat('j F Y') }}</strong>
        </div>
        <div>
          <strong>{{ $cityName }}</strong>,
          <strong>{{ now()->locale('id')->translatedFormat('j F Y') }}</strong>
        </div>
      </div>

      <!-- SIGNATURES -->
      <div class="signatures">
        <!-- Left (wajib) -->
        <div class="sig sig-left" style="position: relative;">
          @if(!empty($certificate->signatory_position))
            <div class="role">{{ $certificate->signatory_position }}</div>
          @endif
          @if($stampUrl)
            <img src="{{ $stampUrl }}" alt="Stempel" style="position: absolute; left: 15px; top: 0px; width: 95px; height: 95px; opacity: 0.78; object-fit: contain; pointer-events: none; z-index: 1;" />
          @endif
          @if($ttd1Url)
            <div class="image" style="top:-10px; left:-24px; width:300px; height:140px; position: relative; z-index: 2;">
              <img src="{{ $ttd1Url }}" alt="Tanda tangan 1" />
            </div>
          @endif
          <div class="line" aria-hidden="true"></div>
          <div class="name">{{ $certificate->signatory_name }}</div>
        </div>

        <!-- Right (opsional) -->
        @if($hasRightSig)
          <div class="sig sig-right">
            @if(!empty($certificate->role2))
              <div class="role">{{ $certificate->role2 }}</div>
            @endif

            @if($ttd2Url)
              <div class="image" style="top:-16px; left:-27px; width:300px; height:160px;">
                <img src="{{ $ttd2Url }}" alt="Tanda tangan 2" />
              </div>
            @endif

            @if(!empty($certificate->name_signatory2))
              <div class="line" aria-hidden="true"></div>
              <div class="name">{{ $certificate->name_signatory2 }}</div>
            @endif
          </div>
        @endif
      </div>
    </div>
  </div>
</body>
</html>
