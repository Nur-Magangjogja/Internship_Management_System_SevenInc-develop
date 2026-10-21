<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <title>Surat Penilaian Magang - {{ $assessment->intern->fullname ?? '-' }}</title>
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

    @if(!isset($isDomPdf) && !isset($autoPrint))
    @media screen {
      html {
        background-color: #f1f5f9;
        padding: 24px 12px;
      }
      body {
        max-width: 210mm;
        min-height: 297mm;
        margin: 0 auto !important;
        padding: 15mm 20mm !important;
        box-sizing: border-box !important;
        background: #ffffff;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12), 0 2px 8px rgba(0, 0, 0, 0.06);
        border: 1px solid #cbd5e1;
        border-radius: 4px;
      }
    }
    @media print {
      body {
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
        border: none !important;
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
    
    .info-list {
        margin-bottom: 15px;
    }
    .info-label {
        display: inline-block;
        width: 150px;
    }

    /* TABLE ASPEK */
    table.aspek-table {
      width: 100%;
      border-collapse: collapse;
      margin: 15px 0;
      font-size: 11pt;
    }
    table.aspek-table th, table.aspek-table td {
      border: 1px solid #000;
      padding: 6px;
      text-align: center;
      vertical-align: middle;
    }
    table.aspek-table th {
      background-color: #f3f3f3;
      font-weight: bold;
    }
    table.aspek-table td.text-left {
      text-align: left;
    }
    table.aspek-table td.text-bold {
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
          <img src="{{ $logoSrc ?? asset('storage/images/logos/logo_seveninc.png') }}" alt="Logo">
        </td>
        <td class="header-text-container" style="width: 60%; text-align: center;">
          <h1>{{ $assessment->company_name ?? 'SEVEN INC.' }}</h1>
          <p>
            {!! nl2br(e($assessment->company_address ?? "Jl. Raya Janti, Gang Arjuna No. 59, Karangjambe,\nBanguntapan, Bantul, Yogyakarta\nKode Pos: 55198 | Telp: 0274-4534571")) !!}
          </p>
        </td>
        <td style="width: 20%;"></td>
      </tr>
    </table>
    <div class="header-line"></div>

    <!-- TITLE -->
    <div class="title-surat">FORM PENILAIAN MAGANG</div>

    <!-- META -->
    <table class="meta-table">
      <tr>
        <td class="meta-label">Nomor</td>
        <td class="meta-colon">:</td>
        <td>{{ $assessment->assessment_number ?? '-' }}</td>
      </tr>
      <tr>
        <td class="meta-label">Lamp.</td>
        <td class="meta-colon">:</td>
        <td>-</td>
      </tr>
      <tr>
        <td class="meta-label">Hal</td>
        <td class="meta-colon">:</td>
        <td>Pemberitahuan Nilai Magang/Kerja Praktik</td>
      </tr>
    </table>

    <!-- CONTENT -->
    <div class="content">
      <p>
        Dengan ini pihak <b>{{ $assessment->company_name ?? 'SEVEN INC.' }}</b> memberikan penilaian selama pelaksanaan magang kepada:
      </p>

      <div class="info-list">
        <div><span class="info-label">Nama</span>: {{ $assessment->intern->fullname ?? '-' }}</div>
        <div><span class="info-label">NIM/NIS</span>: {{ $assessment->intern->student_id ?? $assessment->intern->nim_nis ?? '-' }}</div>
        <div><span class="info-label">Program Studi</span>: {{ $assessment->intern->study_program ?? '-' }}</div>
        <div><span class="info-label">Divisi/Keahlian</span>: {{ $assessment->intern->internship_interest ?? '-' }}</div>
      </div>

      <table class="aspek-table">
        <thead>
          <tr>
            <th style="width: 10%;">No</th>
            <th style="width: 60%;">Aspek Penilaian</th>
            <th style="width: 30%;">Nilai</th>
          </tr>
        </thead>
        <tbody>
            @if(is_array($assessment->aspek_penilaian))
                @foreach($assessment->aspek_penilaian as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="text-left">{{ $item['aspek'] }}</td>
                    <td>{{ $item['nilai'] }}</td>
                </tr>
                @endforeach
            @endif
            <tr>
                <td colspan="2" class="text-bold">RATA-RATA</td>
                <td class="text-bold">{{ $assessment->rata_rata ?? '-' }}</td>
            </tr>
        </tbody>
      </table>

      <p style="font-size: 10pt; margin-top: 5px;">
        <em>*Keterangan Range Nilai: 90-100 (Sangat Baik), 80-89 (Baik), 70-79 (Cukup), &lt; 70 (Kurang)</em>
      </p>
    </div>

    <!-- SIGNATURE -->
    <div class="signature-wrapper clearfix">
      <div class="signature-box">
        <p>Yogyakarta, {{ \Carbon\Carbon::now()->translatedFormat('d F Y') }}</p>
        <p>Hormat kami,</p>
        <div class="signature-img-container">
          @if(!empty($stampSrc))
          <img src="{{ $stampSrc }}" class="stamp" alt="Cap Perusahaan">
          @elseif(!empty($logoSrc))
          <img src="{{ $logoSrc }}" class="stamp" alt="Cap Perusahaan">
          @endif
          @if(!empty($sigSrc))
          <img src="{{ $sigSrc }}" class="ttd" alt="Tanda Tangan">
          @endif
        </div>
        <p class="signatory-name">{{ $assessment->signatory_name ?? 'Ari Setia Husbana' }}</p>
        <p>{{ $assessment->signatory_position ?? 'HRD' }}</p>
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
