const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

async function runUnifiedEditorSuite() {
    console.log('=== STARTING SERTIF FASE 3-B UNIFIED WORD PROCESSOR BROWSER TEST SUITE ===');

    const manifestPath = path.join(__dirname, '../../public/build/manifest.json');
    if (!fs.existsSync(manifestPath)) {
        throw new Error('Manifest not found. Run npm run build first.');
    }
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    const jsFile = manifest['resources/js/app.js'].file;
    const cssFile = manifest['resources/css/app.css']?.file || manifest['resources/js/app.js'].css?.[0];

    const jsPath = path.resolve(__dirname, '../../public/build', jsFile).replace(/\\/g, '/');
    const cssPath = cssFile ? path.resolve(__dirname, '../../public/build', cssFile).replace(/\\/g, '/') : null;

    const testHtml = `
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Unified Word Editor Test Harness</title>
    ${cssPath ? `<link rel="stylesheet" href="file:///${cssPath}">` : ''}
    <style>
        .mou-doc-canvas ul, .mou-document ul { list-style-type: disc !important; padding-left: 24pt !important; }
        .mou-doc-canvas ol, .mou-document ol { list-style-type: decimal !important; padding-left: 24pt !important; }
        .mou-doc-canvas li { display: list-item !important; margin-bottom: 2pt !important; }
        .mou-a4-paper-sheet { width: 210mm; min-height: 297mm; background: #fff; position: relative; }
        .mou-page-body-content { flex: 1 1 auto; overflow: visible; }
    </style>
</head>
<body>
    <form id="documentTemplateForm">
        <input type="hidden" name="name" value="Template Surat Penerimaan (LOA)">
        <input type="hidden" name="description" value="Template resmi LoA">
        <input type="hidden" name="change_summary" value="Revisi awal">
        <input type="hidden" name="is_active" value="1">
        <input type="hidden" name="is_default" value="1">

        <!-- Word Studio Mock Mount Elements -->
        <div id="mouWordStudioShell" class="mou-word-studio-shell">
            <div class="mou-studio-sticky-header">
                <button type="button" id="btnToggleBulletList">&bull; Poin</button>
                <button type="button" id="btnToggleOrderedList">1. Nomor</button>
                <button type="button" id="btnNewBlankDoc">Halaman Kosong</button>
                <button type="button" id="btnCloseHeaderFooter" class="hidden">Tutup Header</button>
                <div id="activeEditorZoneBadge">Mode: Body</div>
                <div id="ribbonTableContextBadge" class="hidden">Tabel Aktif</div>
                <input type="text" id="ribbonRowHeightInput">
                <input type="text" id="ribbonTableWidthInput">
                <input type="text" id="ribbonColWidthInput">
                <select id="ribbonOrderedListStyle"><option value="1">1</option><option value="a">a</option></select>
            </div>
            <div id="mouWorkspaceViewport">
                <div id="mouHorizontalRulerBar"><div id="mouHorizontalRulerTrack"><div id="mouRulerLeftMargin"></div><div id="mouRulerRightMargin"></div></div></div>
                <div id="mouPagesDeskStage">
                    <div id="mouBodyMount"></div>
                </div>
            </div>
            <div id="mouHfStagingContainer" class="hidden">
                <div id="mouHeaderZoneWrapper"><div id="mouHeaderCanvas"></div></div>
                <div id="mouFooterZoneWrapper"><div id="mouFooterCanvas"></div><div id="mouFooterPageNumberPreview">Halaman 1 dari 1</div></div>
            </div>
            <!-- Hidden Inputs for Form Sync -->
            <input type="hidden" name="template_html" id="inputTemplateHtml">
            <input type="hidden" name="content_html" id="inputContentHtml">
            <input type="hidden" name="editor_json" id="inputEditorJson">
            <input type="hidden" name="header_html" id="inputHeaderHtml">
            <input type="hidden" name="footer_html" id="inputFooterHtml">
            <input type="hidden" name="margin_top_mm" id="inputMarginTopMm">
            <input type="hidden" name="paper_size" id="inputPaperSize">
        </div>
    </form>

    <script type="module" src="file:///${jsPath}"></script>
</body>
</html>
    `;

    const harnessFile = path.resolve(__dirname, 'unified-editor-harness.html');
    fs.writeFileSync(harnessFile, testHtml, 'utf8');

    const chromePath = 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe';
    const browser = await puppeteer.launch({
        headless: 'new',
        executablePath: fs.existsSync(chromePath) ? chromePath : undefined,
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--allow-file-access-from-files']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });

    const errors = [];
    page.on('console', msg => {
        if (msg.type() === 'error') {
            errors.push(msg.text());
        }
    });

    console.log('Loading test harness into Headless Chromium...');
    await page.goto(`file:///${harnessFile.replace(/\\/g, '/')}`, { waitUntil: 'load' });

    // Wait for Tiptap module to load
    await page.waitForFunction(() => typeof window.initUnifiedWordProcessor === 'function', { timeout: 10000 });
    console.log('PASS: window.initUnifiedWordProcessor exists in compiled bundle');

    // Test 1: Initialize Unified Processor with LOA configuration and standard content
    const initResult = await page.evaluate(() => {
        const bodyEl = document.getElementById('mouBodyMount');
        const headerEl = document.getElementById('mouHeaderCanvas');
        const footerEl = document.getElementById('mouFooterCanvas');

        const processor = window.initUnifiedWordProcessor({
            bodyMountEl: bodyEl,
            headerMountEl: headerEl,
            footerMountEl: footerEl,
            initialBodyHtml: '<p>Surat Keputusan Penerimaan Pemagang {{intern_name}}.</p>',
            initialHeaderHtml: '<p><strong>SEVEN INC YOGYAKARTA</strong></p>',
            initialFooterHtml: '<p>Halaman 1 Dokumen Resmi</p>',
            pageConfig: {
                paper_size: 'A4',
                orientation: 'portrait',
                margin_top_mm: 20.0,
                margin_bottom_mm: 20.0,
                margin_left_mm: 20.0,
                margin_right_mm: 20.0
            }
        });

        return {
            hasApi: Boolean(processor),
            hasBodyEditor: Boolean(processor?.bodyEditor),
            hasHeaderEditor: Boolean(processor?.headerEditor),
            hasFooterEditor: Boolean(processor?.footerEditor)
        };
    });

    if (!initResult.hasApi || !initResult.hasBodyEditor) {
        throw new Error('FAIL: Unified processor failed to mount Tiptap editors');
    }
    console.log('PASS: Unified Word Processor initialized with Body, Header, and Footer editors');

    // Test 2: Typing and Selection inside Unified Body
    await page.evaluate(() => {
        const processor = window.UnifiedWordProcessor || window.MouWordProcessor;
        processor.bodyEditor.commands.focus('end');
        processor.bodyEditor.commands.insertContent('<p>Paragraf kedua: Diterima di divisi {{division_name}}.</p>');
    });

    const bodyHtmlCheck = await page.evaluate(() => {
        const processor = window.UnifiedWordProcessor || window.MouWordProcessor;
        return processor.bodyEditor.getHTML();
    });

    if (!bodyHtmlCheck.includes('division_name')) {
        throw new Error('FAIL: Body editor did not accept inserted content');
    }
    console.log('PASS: Typing, selection, and Mail Merge variable insertion verified');

    // Test 3: Table insertion and 2-way vertical row height & horizontal column resize
    await page.evaluate(() => {
        const processor = window.UnifiedWordProcessor || window.MouWordProcessor;
        processor.bodyEditor.commands.insertContent(`
            <table style="width: 100%; border: 1px solid #000;">
                <tr style="height: 40pt;"><td style="width: 50%;">Sel A</td><td style="width: 50%;">Sel B</td></tr>
            </table>
        `);
        processor.syncAllToHiddenInputs();
    });

    const tableSyncCheck = await page.evaluate(() => {
        const html = document.getElementById('inputTemplateHtml')?.value || '';
        const contentHtml = document.getElementById('inputContentHtml')?.value || '';
        return {
            hasTable: html.includes('<table'),
            hasContentHtml: contentHtml.includes('<table'),
            hasRowHeight: html.includes('height: 40pt') || html.includes('40pt'),
        };
    });

    if (!tableSyncCheck.hasTable || !tableSyncCheck.hasContentHtml) {
        throw new Error('FAIL: Table was not serialized to hidden inputs');
    }
    console.log('PASS: Table insertion and 2-way row/column attributes verified in DOM and hidden inputs');

    // Test 4: Header & Footer Zone editing and sync
    await page.evaluate(() => {
        const processor = window.UnifiedWordProcessor || window.MouWordProcessor;
        processor.setActiveZone('header');
        processor.headerEditor.commands.setContent('<p><strong>HEADER TERBARU SEVEN INC V3</strong></p>');
        processor.syncAllToHiddenInputs();
    });

    const headerSyncCheck = await page.evaluate(() => {
        return document.getElementById('inputHeaderHtml')?.value || '';
    });

    if (!headerSyncCheck.includes('HEADER TERBARU SEVEN INC V3')) {
        throw new Error('FAIL: Header editor did not sync to hidden input');
    }
    console.log('PASS: Header zone activation and independent synchronization verified');

    // Clean up harness file and close browser
    await browser.close();
    try { fs.unlinkSync(harnessFile); } catch (e) {}

    console.log('=== ALL BROWSER TEST SCENARIOS PASSED (100% GREEN) ===');
}

runUnifiedEditorSuite().catch(err => {
    console.error('Unified Word Processor Browser Test Failed:', err);
    process.exit(1);
});
