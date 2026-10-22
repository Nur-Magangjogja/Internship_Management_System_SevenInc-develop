<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>Surat Keterangan Selesai Magang (SKL) - {{ $participantName ?? '-' }}</title>
  <style>
    /* ===== A4 & Margin ===== */
    @page {
      size: A4 portrait;
      margin: 10mm 15mm;
    }

    body {
      font-family: 'Times New Roman', serif;
      font-size: 11pt;
      color: #000;
      line-height: 1.3;
      margin: 0;
      padding: 0;
      background-color: #ffffff;
    }

    @if(request()->routeIs('*.raw') || request()->is('*/raw/*') || request()->has('preview') || ($isScreenPreview ?? false))
    @media screen {
      html {
        background-color: #f8fafc;
      }
      body {
        padding: 12mm 18mm !important;
        box-sizing: border-box !important;
        background-color: #ffffff;
      }
    }
    @endif

    .wrap {
      width: 100%;
      margin: 0 auto;
    }

    /* HEADER */
    table.header-table {
      width: 100%;
      border-collapse: collapse;
      border: none;
      margin-bottom: 5px;
    }
    table.header-table td {
      border: none;
      padding: 0;
      vertical-align: middle;
    }
    .header-logo {
      width: 120px;
    }
    .header-logo img {
      width: 100px;
      height: auto;
    }
    .header-text-container {
      text-align: center;
    }
    .header-text-container h1 {
      margin: 0;
      font-size: 16pt;
      font-weight: bold;
      color: #000;
    }
    .header-text-container p {
      margin: 4px 0 0 0;
      font-size: 10pt;
      color: #000;
      line-height: 1.2;
    }

    .header-line {
      border-top: 3px solid #000;
      border-bottom: 1px solid #000;
      height: 2px;
      margin-top: 5px;
      margin-bottom: 15px;
    }

    /* TITLE */
    .title-surat {
      text-align: center;
      font-size: 12pt;
      font-weight: bold;
      text-decoration: underline;
      margin-bottom: 15px;
    }

    /* META */
    table.meta-table {
      width: 100%;
      border-collapse: collapse;
      border: none;
      margin-bottom: 15px;
      font-size: 11pt;
    }
    table.meta-table td {
      border: none;
      padding: 2px 0;
      vertical-align: top;
    }
    .meta-label {
      width: 80px;
    }
    .meta-colon {
      width: 15px;
    }

    /* CONTENT */
    .content p {
      text-align: justify;
      margin: 10px 0;
      line-height: 1.5;
    }
    
    .info-list {
        margin-bottom: 15px;
        margin-left: 20px;
    }
    .info-label {
        display: inline-block;
        width: 150px;
    }

    /* SIGNATURE */
    .signature-wrapper {
      width: 100%;
      margin-top: 30px;
    }
    .signature-box {
      width: 300px;
      float: right;
      text-align: center;
    }
    .signature-box p {
      margin: 2px 0;
      line-height: 1.2;
    }
    .signature-img-container {
      position: relative;
      height: 80px;
      margin: 10px 0;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    .signature-img-container img.ttd {
      max-height: 80px;
      max-width: 200px;
      position: relative;
      z-index: 2;
    }
    .signature-img-container img.stamp {
      position: absolute;
      max-height: 125px;
      max-width: 125px;
      opacity: 0.45;
      filter: blur(0.3px);
      z-index: 1;
      left: 50%;
      top: 50%;
      transform: translate(-50%, -50%);
    }
    .signatory-name {
      font-weight: bold;
      text-decoration: underline;
    }

    .clearfix::after {
      content: "";
      clear: both;
      display: table;
    }

  </style>
</head>
<body>
  <div class="wrap">
    
    <!-- HEADER -->
    <table class="header-table">
      <tr>
        <td class="header-logo" style="width: 20%; text-align: left;">
          <img src="{{ $logoData ?: asset('images/mou/seven-inc-logo.png') }}" alt="Logo">
        </td>
        <td class="header-text-container" style="width: 60%; text-align: center;">
          <h1>{{ $companyName ?? 'SEVEN INC.' }}</h1>
          <p>
            {!! nl2br(e($companyAddress ?? "Jl. Raya Janti, Gang Arjuna No. 59, Karangjambe,\nBanguntapan, Bantul, Yogyakarta\nKode Pos: 55198 | Telp: 0274-4534571")) !!}
          </p>
        </td>
        <td style="width: 20%;"></td>
      </tr>
    </table>
    <div class="header-line"></div>

    <!-- TITLE -->
    <div class="title-surat">SURAT KETERANGAN SELESAI MAGANG</div>

    <!-- META -->
    <table class="meta-table">
      <tr>
        <td class="meta-label">Nomor</td>
        <td class="meta-colon">:</td>
        <td>{{ $letterNumber ?? '-' }}</td>
      </tr>
      <tr>
        <td class="meta-label">Lamp.</td>
        <td class="meta-colon">:</td>
        <td>-</td>
      </tr>
      <tr>
        <td class="meta-label">Hal</td>
        <td class="meta-colon">:</td>
        <td>Surat Keterangan Selesai Magang</td>
      </tr>
    </table>

    <!-- CONTENT -->
    <div class="content">
      <p>
        Yang bertanda tangan di bawah ini, perwakilan dari <b>{{ $companyName ?? 'SEVEN INC.' }}</b> menerangkan bahwa:
      </p>

      <div class="info-list">
        <div><span class="info-label">Nama</span>: {{ $participantName ?? '-' }}</div>
        <div><span class="info-label">NIM/NIS</span>: {{ $participantId ?? '-' }}</div>
        <div><span class="info-label">Program Studi</span>: {{ $participantMajor ?? '-' }}</div>
        <div><span class="info-label">Asal Sekolah/Kampus</span>: {{ $participantInstitute ?? '-' }}</div>
      </div>

      <p>
        Adalah benar nama tersebut di atas telah melaksanakan <b>Program Kerja Praktek / Magang</b> di perusahaan kami pada divisi <b>{{ $divisionName ?? '-' }}</b>,
        yang dilaksanakan pada tanggal <b>{{ $startStr ?? '-' }}</b> sampai dengan tanggal <b>{{ $endStr ?? '-' }}</b>.
      </p>
      
      @if(!empty($activityDescription))
      <p>
        Selama magang, peserta bertugas untuk: {{ $activityDescription }}
      </p>
      @endif

      @if(!empty($participantAchievement))
      <p>
        Pencapaian utama peserta selama di perusahaan: {{ $participantAchievement }}
      </p>
      @endif

      <p>
        Selama masa magang, yang bersangkutan telah menunjukkan kedisiplinan dan tanggung jawab yang baik serta berkontribusi positif bagi perusahaan. 
        Kami berterima kasih atas dedikasi yang diberikan dan berharap pengalaman ini bermanfaat bagi masa depannya.
      </p>

      <p>
        Demikian Surat Keterangan Selesai Magang ini dibuat dengan sebenar-benarnya untuk dapat dipergunakan sebagaimana mestinya.
      </p>
    </div>

    <!-- SIGNATURE -->
    <div class="signature-wrapper clearfix">
      <div class="signature-box">
        <p>Yogyakarta, {{ $letterDateStr ?? \Carbon\Carbon::now()->translatedFormat('d F Y') }}</p>
        <p>Hormat kami,</p>
        <div class="signature-img-container">
          <img src="{{ $stampData ?: asset('images/mou/stamp-sample.png') }}" class="stamp" alt="Cap Perusahaan">
          <img src="{{ $sigData ?? $stampData ?: asset('images/mou/signature-sample.png') }}" class="ttd" alt="Tanda Tangan">
        </div>
        <p class="signatory-name">{{ $leaderName ?? 'Ari Setia Husbana' }}</p>
        <p>{{ $leaderTitle ?? 'HRD' }}</p>
      </div>
    </div>

  </div>
  
  @if(isset($autoPrint) && $autoPrint)
  <script>
    window.onload = function() {
        window.print();
    }
  </script>
  @endif
</body>
</html>
