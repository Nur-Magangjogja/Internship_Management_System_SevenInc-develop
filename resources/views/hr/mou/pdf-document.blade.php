<!DOCTYPE html>
<html lang="id">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $documentTitle ?? ($title ?? 'Nota Kesepahaman (MOU)') }}</title>
    @php
        $topMm    = (float) ($pageConfig['margin_top_mm'] ?? 25.4);
        $rightMm  = (float) ($pageConfig['margin_right_mm'] ?? 25.4);
        $bottomMm = (float) ($pageConfig['margin_bottom_mm'] ?? 25.4);
        $leftMm   = (float) ($pageConfig['margin_left_mm'] ?? 25.4);

        $headerDistanceMm   = (float) ($pageConfig['header_distance_mm'] ?? 12.0);
        $footerDistanceMm   = (float) ($pageConfig['footer_distance_mm'] ?? 10.0);
        $differentFirstPage = (bool) ($pageConfig['different_first_page'] ?? false);

        $showParaf      = (bool) ($pageConfig['show_paraf_footer'] ?? ($pageConfig['show_paraf_box'] ?? true));
        $showPageNumber = (bool) ($pageConfig['show_page_numbers'] ?? ($pageConfig['show_page_number'] ?? true));

        $parafFirstLabel  = $pageConfig['paraf_first_party_label'] ?? 'Paraf PIHAK KESATU';
        $parafSecondLabel = $pageConfig['paraf_second_party_label'] ?? 'Paraf PIHAK KEDUA';

        $headerHtml          = trim((string) ($pageConfig['header_html'] ?? ''));
        $firstPageHeaderHtml = trim((string) ($pageConfig['first_page_header_html'] ?? ''));
        $firstPageFooterHtml = trim((string) ($pageConfig['first_page_footer_html'] ?? ''));

        $rawFooterHtml = array_key_exists('footer_html', $pageConfig ?? [])
            ? trim((string) ($pageConfig['footer_html'] ?? ''))
            : ($showParaf ? \App\Models\MouTemplate::getDefaultFooterParafHtml($parafFirstLabel, $parafSecondLabel) : '');

        $footerHtml = ($showParaf || !str_contains($rawFooterHtml, 'mou-paraf-table'))
            ? $rawFooterHtml
            : '';

        $estHeaderMm = $headerHtml !== ''
            ? \App\Services\MouDocumentRenderer::estimateBlockHeightMm($headerHtml, 12.0)
            : 0.0;

        $estFooterMm = $footerHtml !== ''
            ? \App\Services\MouDocumentRenderer::estimateBlockHeightMm($footerHtml, 14.5)
            : 0.0;

        $estFirstHeaderMm = ($differentFirstPage && $firstPageHeaderHtml !== '')
            ? \App\Services\MouDocumentRenderer::estimateBlockHeightMm($firstPageHeaderHtml, 12.0)
            : 0.0;

        $estFirstFooterMm = ($differentFirstPage && $firstPageFooterHtml !== '')
            ? \App\Services\MouDocumentRenderer::estimateBlockHeightMm($firstPageFooterHtml, 14.5)
            : 0.0;

        $effectiveHeaderMm = max($estHeaderMm, $estFirstHeaderMm);
        $effectiveFooterMm = max($estFooterMm, $estFirstFooterMm);

        $reservedTopMm = $effectiveHeaderMm > 0
            ? max($topMm, round($headerDistanceMm + $effectiveHeaderMm + 4.0, 2))
            : $topMm;

        // Ruang khusus footer (Kotak Paraf / Footer Kustom & Nomor Halaman) yang otomatis membesar jika tabel Paraf diperbesar
        $reservedBottomMm = ($showParaf || $showPageNumber || $footerHtml !== '' || $firstPageFooterHtml !== '')
            ? max(44.0, $bottomMm + 18.0, round($bottomMm + $effectiveFooterMm + 4.0, 2))
            : $bottomMm;

        $footerBoxHeightMm = max(14.5, $effectiveFooterMm);
        $footerBottomOffsetMm = round($footerBoxHeightMm + 1.0, 2);
        $headerBoxHeightMm = max(12.0, $effectiveHeaderMm);
        $headerTopOffsetMm = round($headerBoxHeightMm + 2.0, 2);
        $paperSize = strtolower((string) ($pageConfig['paper_size'] ?? 'a4'));
        $orientation = strtolower((string) ($pageConfig['orientation'] ?? 'portrait'));
    @endphp
    <style>
        @page {
            size: {{ $paperSize }} {{ $orientation }};
            margin: {{ $reservedTopMm }}mm {{ $rightMm }}mm {{ $reservedBottomMm }}mm {{ $leftMm }}mm;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: '{{ $pageConfig['font_family'] ?? 'Times New Roman' }}', Times, serif;
            font-size: {{ (int) ($pageConfig['font_size_pt'] ?? 12) }}pt;
            line-height: {{ (float) ($pageConfig['line_height'] ?? 1.15) }};
            color: #000000;
        }
        {!! $canonicalCss ?? '' !!}

        .mou-published-snapshot {
            margin: 0;
            padding: 0;
        }
        .mou-pdf-running-header {
            position: fixed;
            left: 0;
            right: 0;
            top: -{{ $headerTopOffsetMm }}mm;
            min-height: {{ $headerBoxHeightMm }}mm;
        }
        .mou-pdf-running-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -{{ $footerBottomOffsetMm }}mm;
            min-height: {{ $footerBoxHeightMm }}mm;
        }
        .mou-pdf-first-page-header-overlay {
            position: absolute;
            left: 0;
            right: 0;
            top: -{{ $headerTopOffsetMm }}mm;
            min-height: {{ $headerBoxHeightMm }}mm;
            background-color: #ffffff;
            z-index: 500;
        }
        .mou-pdf-first-page-footer-overlay {
            position: absolute;
            left: 0;
            right: 0;
            bottom: -{{ $footerBottomOffsetMm }}mm;
            min-height: {{ $footerBoxHeightMm }}mm;
            background-color: #ffffff;
            z-index: 500;
        }
        .mou-pdf-paraf-box {
            width: 220pt;
            border-collapse: collapse;
            border: 1px solid #000000;
            font-size: 8.5pt;
        }
        .mou-pdf-paraf-box td {
            border: 1px solid #000000;
            padding: 2pt 6pt;
            text-align: center;
            width: 50%;
        }
        .mou-pdf-paraf-header td {
            font-weight: bold;
        }
        .mou-pdf-paraf-space {
            height: 16pt;
        }
    </style>
</head>
<body>
    @if($headerHtml !== '')
        <div class="mou-pdf-running-header" data-header-zone="running">
            {!! $headerHtml !!}
        </div>
    @endif

    @if($showParaf || $showPageNumber || $footerHtml !== '')
        <div
            class="mou-pdf-running-footer"
            data-footer-zone="running"
            @if($showPageNumber) data-page-number-format="Halaman {PAGE_NUM} dari {PAGE_COUNT}" @endif
        >
            @if($footerHtml !== '')
                {!! $footerHtml !!}
            @elseif($showParaf)
                <table class="mou-pdf-paraf-box">
                    <tbody>
                        <tr class="mou-pdf-paraf-header">
                            <td>{{ $parafFirstLabel }}</td>
                            <td>{{ $parafSecondLabel }}</td>
                        </tr>
                        <tr>
                            <td class="mou-pdf-paraf-space">&nbsp;</td>
                            <td class="mou-pdf-paraf-space">&nbsp;</td>
                        </tr>
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    @if($differentFirstPage)
        <div class="mou-pdf-first-page-header-overlay" data-header-zone="first-page">
            {!! $firstPageHeaderHtml !!}
        </div>
        <div class="mou-pdf-first-page-footer-overlay" data-footer-zone="first-page">
            {!! $firstPageFooterHtml !!}
        </div>
    @endif

    <main class="mou-doc-canvas">
        {!! $renderedHtml !!}
    </main>
</body>
</html>
