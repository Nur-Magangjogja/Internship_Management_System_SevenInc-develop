@php
  use Carbon\Carbon;
  $interns = $rows ?? [];
@endphp

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>Surat Keterangan Ijin Magang</title>
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

    /* KEPADA */
    .kepada-block {
      margin-bottom: 15px;
      font-weight: bold;
      line-height: 1.4;
    }

    /* CONTENT */
    .content p {
      text-align: justify;
      margin: 10px 0;
      line-height: 1.5;
    }

    /* TABLE SISWA */
    table.siswa-table {
      width: 100%;
      border-collapse: collapse;
      margin: 15px 0;
      font-size: 11pt;
    }
    table.siswa-table th, table.siswa-table td {
      border: 1px solid #000;
      padding: 6px;
      text-align: center;
      vertical-align: middle;
    }
    table.siswa-table th {
      background-color: #00FFFF; /* Cyan header as per image */
      font-weight: bold;
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
      max-height: 90px;
      opacity: 0.3;
      filter: blur(0.5px);
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
          <img src="{{ $logoData ?? asset('storage/images/logos/logo_seveninc.png') }}" alt="Logo">
        </td>
        <td class="header-text-container" style="width: 60%; text-align: center;">
          <h1>{{ $loaSettings->company_name ?? 'SEVEN INC.' }}</h1>
          <p>
            {!! nl2br(e($loaSettings->company_address ?? "Jl. Raya Janti, Gang Arjuna No. 59, Karangjambe,\nBanguntapan, Bantul, Yogyakarta\nKode Pos: 55198 | Telp: 0274-4534571")) !!}
          </p>
        </td>
        <td style="width: 20%;"></td>
      </tr>
    </table>
    <div class="header-line"></div>

    <!-- TITLE -->
    <div class="title-surat">SURAT KETERANGAN IJIN MAGANG</div>

    <!-- META -->
    <table class="meta-table">
      <tr>
        <td class="meta-label">Nomor</td>
        <td class="meta-colon">:</td>
        <td>{{ $loaNumber ?? ($intern->loa_number ?? ('19/S1-Magang/HRD/SEVEN/'.\Carbon\Carbon::now()->format('VI/Y'))) }}</td>
      </tr>
      <tr>
        <td class="meta-label">Lamp.</td>
        <td class="meta-colon">:</td>
        <td>-</td>
      </tr>
      <tr>
        <td class="meta-label">Hal</td>
        <td class="meta-colon">:</td>
        <td>Konfirmasi Izin Kerja Praktik/Magang</td>
      </tr>
    </table>

    <!-- KEPADA -->
    <div class="kepada-block">
      Kepada Yth.<br>
      Bapak/Ibu Kepala Prodi {{ $intern->study_program ?? 'Program Studi' }}<br>
      {{ $intern->institution_name ?? 'Universitas / Instansi' }}
    </div>

    <!-- CONTENT -->
    <div class="content">
      <p>
        Menanggapi permohonan izin melakukan Kerja Praktik/ Magang mahasiswa/i jurusan {{ $intern->study_program ?? 'Program Studi' }} dengan nama berikut ini :
      </p>

      <table class="siswa-table">
        <thead>
          <tr>
            <th style="width: 5%;">No</th>
            <th style="width: 25%;">Nama</th>
            <th style="width: 15%;">NIM</th>
            <th style="width: 25%;">Program Studi</th>
            <th style="width: 30%;">Divisi</th>
          </tr>
        </thead>
        <tbody>
          @foreach($interns as $index => $row)
            <tr>
              <td>{{ $index + 1 }}.</td>
              <td>{{ $row['nama_siswa'] ?? 'Nama Tidak Diketahui' }}</td>
              <td>{{ $row['nim_nis'] ?? '-' }}</td>
              <td>{{ $row['jurusan'] ?? '-' }}</td>
              <td>{{ $intern->internship_interest ?? '-' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>

      @php
        $start = \Carbon\Carbon::parse($intern->start_date);
        $end = \Carbon\Carbon::parse($intern->end_date);
        $diffMonths = $start->diffInMonths($end);
        if ($diffMonths == 0) $diffMonths = 1;
        
        $startStr = $start->translatedFormat('d F Y');
        $endStr = $end->translatedFormat('d F Y');
      @endphp

      <p>
        Dengan surat ini, kami IZINKAN mahasiswa/i tersebut melaksanakan Kerja Praktik/ Magang di perusahaan / instansi {{ $loaSettings->company_name ?? 'SEVEN INC' }} mulai dari <strong>{{ $startStr }} - {{ $endStr }} ({{ $diffMonths }} bulan)</strong> secara <strong>Work From Office (WFO)</strong>.
      </p>

      <p>
        Atas perhatian dan kerjasamanya kami ucapkan terimakasih.
      </p>
    </div>

    <!-- SIGNATURE -->
    <div class="signature-wrapper clearfix">
      <div class="signature-box">
        <p>Yogyakarta, {{ \Carbon\Carbon::now()->translatedFormat('d F Y') }}</p>
        <p>Hormat kami,</p>
        <div class="signature-img-container">
          @if(!empty($stampData))
          <img src="{{ $stampData }}" class="stamp" alt="Cap Perusahaan">
          @endif
          @if(!empty($sigData))
          <img src="{{ $sigData }}" class="ttd" alt="Tanda Tangan">
          @elseif(!empty($stampData))
          {{-- Jika hanya stempel tanpa ttd --}}
          @else
          <img src="{{ asset('storage/images/signature/ttd_arisetiahusbana.png') }}" class="ttd" alt="Tanda Tangan">
          @endif
        </div>
        <p class="signatory-name">{{ $loaSettings->signatory_name ?? 'Ari Setia Husbana' }}</p>
        <p>{{ $loaSettings->signatory_position ?? 'HRD' }} {{ $loaSettings->company_name ?? 'SEVEN INC.' }}</p>
      </div>
    </div>

  </div>
</body>
</html>
