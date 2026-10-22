<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<style>
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
    letter-spacing: 1.5px;
    text-transform: uppercase;
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
  .letter-title {
    text-align: center;
    font-size: 12pt;
    font-weight: bold;
    text-decoration: underline;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  .letter-number {
    text-align: center;
    font-size: 11pt;
    margin-bottom: 25px;
    color: #000;
  }

  /* ── Paragraf intro ── */
  .intro {
    font-size: 11pt;
    margin-bottom: 10px;
  }

  /* ── Tabel info penandatangan & pemagang ── */
  table.info-table {
    width: 100%;
    border-collapse: collapse;
    margin: 6px 0 15px 15px;
  }
  table.info-table td {
    padding: 2px 0;
    vertical-align: top;
    font-size: 11pt;
    line-height: 1.4;
  }
  table.info-table td:first-child {
    width: 150px;
    font-weight: normal;
  }
  table.info-table td:nth-child(2) {
    width: 15px;
    text-align: left;
  }

  /* ── Body paragraf ── */
  .body-text {
    text-align: justify;
    margin: 10px 0;
    font-size: 11pt;
    line-height: 1.5;
  }

  /* ── TTD ── */
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
</style>
</head>
<body>

{{-- KOP SURAT --}}
<table class="header-table">
  <tr>
    @if(!empty($logoData))
    <td class="header-logo">
      <img src="{{ $logoData }}" alt="Logo" />
    </td>
    @endif
    <td class="header-text-container">
      <h1>{{ $companyName }}</h1>
      <p>
        {{ $companyAddress }}
      </p>
    </td>
  </tr>
</table>
<div class="header-line"></div>

{{-- JUDUL --}}
<div class="letter-title">Surat Rekomendasi</div>
<div class="letter-number">Nomor : {{ $letterNumber }}</div>

{{-- YANG BERTANDA TANGAN --}}
<p class="intro">Saya yang bertanda tangan di bawah ini :</p>

<table class="info-table">
  <tr>
    <td>Nama Lengkap</td>
    <td>:</td>
    <td><strong>{{ $leaderName }}</strong></td>
  </tr>
  <tr>
    <td>Alamat</td>
    <td>:</td>
    <td>{{ $companyAddress }}</td>
  </tr>
  <tr>
    <td>Jabatan</td>
    <td>:</td>
    <td>{{ $leaderTitle }}</td>
  </tr>
</table>

<p class="intro">Dengan ini menerangkan bahwa :</p>

{{-- DATA PEMAGANG --}}
<table class="info-table">
  <tr>
    <td>Nama Lengkap</td>
    <td>:</td>
    <td><strong>{{ $participantName }}</strong></td>
  </tr>
  <tr>
    <td>NIM</td>
    <td>:</td>
    <td>{{ $participantId }}</td>
  </tr>
  <tr>
    <td>Program Studi</td>
    <td>:</td>
    <td>{{ $participantMajor }}</td>
  </tr>
  <tr>
    <td>Asal Sekolah/Kampus</td>
    <td>:</td>
    <td>{{ $participantInstitute }}</td>
  </tr>
</table>

{{-- BODY SURAT --}}
<p class="body-text">{{ $bodyText }}</p>

{{-- PENUTUP --}}
<p class="body-text">Demikian surat rekomendasi ini dibuat dengan penuh kesadaran dan tanpa paksaan dari pihak manapun dan untuk dipergunakan sebagaimana mestinya.</p>

{{-- TTD --}}
<div class="signature-wrapper">
  <div class="signature-box">
    <p>Yogyakarta, {{ $letterDateStr }}</p>
    <p>Hormat kami,</p>
    <div class="signature-img-container">
      @if(!empty($logoData))
      <img src="{{ $logoData }}" class="stamp" alt="Cap Perusahaan">
      @endif
      @if(!empty($stampData))
      <img src="{{ $stampData }}" class="ttd" alt="Tanda Tangan">
      @endif
    </div>
    <p class="signatory-name">{{ $leaderName }}</p>
    <p>{{ $leaderTitle }} {{ $companyName }}</p>
  </div>
</div>

</body>
</html>
