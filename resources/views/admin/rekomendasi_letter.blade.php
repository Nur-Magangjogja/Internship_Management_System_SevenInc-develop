<!DOCTYPE html>
<html lang="id">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Surat Rekomendasi - {{ $participantName ?? 'Pemagang' }}</title>
<style>
    @page {
        size: a4 portrait;
        margin: 20mm 20mm 20mm 20mm;
    }
    * {
        box-sizing: border-box;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    html {
        background-color: #f1f5f9;
        margin: 0;
        padding: 24px 12px;
        min-height: 100%;
    }
    body {
        margin: 0 auto;
        padding: 0;
        font-family: 'Times New Roman', Times, serif;
        font-size: 11pt;
        line-height: 1.3;
        color: #000000;
        background: transparent;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    .letter-paper-sheet {
        width: 210mm;
        min-height: 297mm;
        max-width: 100%;
        background-color: #ffffff;
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.15), 0 2px 6px rgba(15, 23, 42, 0.08);
        border: 1px solid #cbd5e1;
        padding: 20mm 20mm 20mm 20mm;
        box-sizing: border-box;
        position: relative;
        word-wrap: break-word;
    }
    .letter-body-content {
        position: relative;
        z-index: 2;
        width: 100%;
    }
    img {
        max-width: 100%;
        height: auto;
    }
    table {
        border-collapse: collapse;
        width: 100%;
    }
    table td, table th {
        vertical-align: top;
    }
    table td p, table th p {
        margin: 0 !important;
        padding: 0 !important;
        line-height: 1.25 !important;
    }
    @media print {
        @page {
            size: a4 portrait;
            margin: 20mm 20mm 20mm 20mm;
        }
        html, body {
            background: #ffffff !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            height: auto !important;
            display: block !important;
        }
        .letter-paper-sheet {
            width: 100% !important;
            max-width: none !important;
            min-height: auto !important;
            height: auto !important;
            box-shadow: none !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }
    }
</style>
</head>
<body>
<div class="letter-paper-sheet">
    @if(!empty($logoData))
    <div class="letter-watermark-center" style="position: absolute; top: 32%; left: 0; right: 0; width: 100%; text-align: center; z-index: 1; pointer-events: none;">
        <img src="{{ $logoData }}" style="width: 300px; height: auto; opacity: 0.05; margin: 0 auto; display: inline-block;" alt="Watermark"/>
    </div>
    @endif

    <div class="letter-body-content">
        {{-- KOP SURAT --}}
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
            <tr>
                <td style="width: 110px; vertical-align: middle; text-align: left;">
                    @if(!empty($logoData))
                        <img src="{{ $logoData }}" alt="Logo Perusahaan" style="width: 95px; height: auto;" />
                    @else
                        &nbsp;
                    @endif
                </td>
                <td style="text-align: center; vertical-align: middle;">
                    <h1 style="margin: 0; font-size: 16pt; font-weight: bold; text-transform: uppercase;">{{ $companyName ?? 'SEVEN INC' }}</h1>
                    <p style="margin: 4px 0 0 0; font-size: 10pt; line-height: 1.2;">{{ $companyAddress ?? 'Jl. Raya Janti, Gang Arjuna No. 59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198' }}<br>Telp: 089529002944 | Email: magangjogjadotcom@gmail.com</p>
                </td>
                <td style="width: 110px; vertical-align: middle;">&nbsp;</td>
            </tr>
        </table>
        <div style="border-top: 3px solid #000; border-bottom: 1px solid #000; height: 2px; margin-top: 5px; margin-bottom: 16px;"></div>

        {{-- JUDUL DOKUMEN --}}
        <h2 style="text-align: center; font-size: 13pt; font-weight: bold; text-decoration: underline; margin-bottom: 4px;">SURAT REKOMENDASI KERJA</h2>
        <p style="text-align: center; font-size: 10.5pt; margin-top: 0; margin-bottom: 24px;">Nomor: {{ $letterNumber }}</p>

        {{-- PIHAK PENANDATANGAN --}}
        <p style="text-align: justify;">Yang bertanda tangan di bawah ini:</p>

        <table style="width: 100%; border-collapse: collapse; margin: 8px 0 16px 28px;">
            <tr>
                <td style="width: 140px; padding: 2px 0;"><strong>Nama</strong></td>
                <td style="width: 15px; padding: 2px 0;">:</td>
                <td style="padding: 2px 0;"><strong>{{ $leaderName ?? 'Rekario Danny Sanjaya, S.Kom.' }}</strong></td>
            </tr>
            <tr>
                <td style="padding: 2px 0;"><strong>Jabatan</strong></td>
                <td style="padding: 2px 0;">:</td>
                <td style="padding: 2px 0;">{{ $leaderTitle ?? 'Pimpinan' }}</td>
            </tr>
            <tr>
                <td style="padding: 2px 0;"><strong>Perusahaan</strong></td>
                <td style="padding: 2px 0;">:</td>
                <td style="padding: 2px 0;">{{ $companyName ?? 'SEVEN INC' }}</td>
            </tr>
        </table>

        {{-- PIHAK PEMAGANG --}}
        <p style="text-align: justify;">Dengan ini memberikan rekomendasi penuh kepada:</p>

        <table style="width: 100%; border-collapse: collapse; margin: 8px 0 16px 28px;">
            <tr>
                <td style="width: 140px; padding: 2px 0;"><strong>Nama Lengkap</strong></td>
                <td style="width: 15px; padding: 2px 0;">:</td>
                <td style="padding: 2px 0;"><strong>{{ $participantName }}</strong></td>
            </tr>
            <tr>
                <td style="padding: 2px 0;"><strong>Nomor Induk Mahasiswa / Nomor Induk Siswa Nasional</strong></td>
                <td style="padding: 2px 0;">:</td>
                <td style="padding: 2px 0;">{{ $participantId ?? '-' }}</td>
            </tr>
            <tr>
                <td style="padding: 2px 0;"><strong>Institusi Asal</strong></td>
                <td style="padding: 2px 0;">:</td>
                <td style="padding: 2px 0;">{{ $participantInstitute ?? '-' }}</td>
            </tr>
            <tr>
                <td style="padding: 2px 0;"><strong>Divisi</strong></td>
                <td style="padding: 2px 0;">:</td>
                <td style="padding: 2px 0;">{{ $divisionFullName ?? $participantMajor ?? '-' }}</td>
            </tr>
        </table>

        {{-- NARASI REKOMENDASI --}}
        <p style="text-align: justify; text-indent: 28px;">{{ $bodyText }}</p>

        <p style="text-align: justify; text-indent: 28px;">Berdasarkan kinerja dan kepribadian profesional tersebut, kami dengan senang hati dan tanpa keraguan merekomendasikan yang bersangkutan untuk dapat berkarir maupun melanjutkan studi di institusi/perusahaan Bapak/Ibu.</p>

        <p style="text-align: justify; text-indent: 28px;">Demikian surat rekomendasi ini dibuat dengan sesungguhnya untuk dapat digunakan sebagaimana mestinya.</p>

        {{-- TANDA TANGAN & STEMPEL --}}
        <table style="width: 100%; border-collapse: collapse; margin-top: 35px;">
            <tr>
                <td style="width: 55%;"></td>
                <td style="text-align: center;">
                    <p style="margin: 0;">{{ $companyCity ?? 'Yogyakarta' }}, {{ $letterDateStr }}<br>{{ $leaderTitle ?? 'Pimpinan' }} {{ $companyName ?? 'SEVEN INC' }}</p>
                    <div style="height: 75px; margin: 8px 0; position: relative;">
                        @if(!empty($stampData))
                            <img src="{{ $stampData }}" alt="Tanda Tangan" style="height: 65px; width: auto; position: relative; z-index: 10;" />
                        @endif
                        @if(!empty($logoData))
                            <img src="{{ $logoData }}" alt="Stempel" style="height: 125px; width: 125px; position: absolute; left: 12%; top: -10px; opacity: 0.78; z-index: 20; pointer-events: none;" />
                        @endif
                    </div>
                    <p style="margin: 0; font-weight: bold; text-decoration: underline; position: relative; z-index: 5;">{{ $leaderName ?? 'Rekario Danny Sanjaya, S.Kom.' }}</p>
                </td>
            </tr>
        </table>
    </div>
</div>

<script>
    function autoScaleSheet() {
        var sheet = document.querySelector('.letter-paper-sheet');
        if (!sheet) return;
        var availableW = window.innerWidth - 24;
        var targetW = 794;
        if (availableW < targetW) {
            var scale = availableW / targetW;
            sheet.style.transform = 'scale(' + scale + ')';
            sheet.style.transformOrigin = 'top center';
            var diff = (sheet.getBoundingClientRect().height) - sheet.offsetHeight;
            sheet.style.marginBottom = diff + 'px';
        } else {
            sheet.style.transform = '';
            sheet.style.transformOrigin = '';
            sheet.style.marginBottom = '';
        }
    }
    window.addEventListener('resize', autoScaleSheet);
    window.addEventListener('DOMContentLoaded', autoScaleSheet);
    setTimeout(autoScaleSheet, 50);
</script>
</body>
</html>
