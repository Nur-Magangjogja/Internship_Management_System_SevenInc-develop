/**
 * SERTIF DOCX Import / Export Engine
 * Pure open-source, privacy-safe, client-side & Node.js compatible.
 * Uses mammoth (^1.8+) for OOXML parsing and docx (^9.0+) for document packaging.
 * Strictly free: No cloud API, no external telemetry, no paid converter.
 */

let mammoth = null;
async function loadMammoth() {
    if (!mammoth) {
        const mod = await import('mammoth');
        mammoth = mod.default || mod;
    }
    return mammoth;
}

let docx = null;
async function loadDocx() {
    if (!docx) {
        docx = await import('docx');
    }
    return docx;
}

/**
 * Reconstruct Mail Merge placeholder tokens split across Word text runs.
 * E.g. "{{ intern " + "_name }}" -> "{{intern_name}}"
 */
export function reconstructMailMergePlaceholders(textOrHtml) {
    if (!textOrHtml || typeof textOrHtml !== 'string') return textOrHtml;

    let result = textOrHtml;

    // Pattern 1: Stitch tokens split across HTML tags (e.g. <strong>{{</strong>pemagang.nama<em>}}</em>)
    result = result.replace(/(?:<[^>]+>)*\{\{(?:<[^>]+>)*\s*([a-zA-Z0-9_.]+)\s*(?:<[^>]+>)*\}\}(?:<[^>]+>)*/gi, '{{$1}}');

    // Pattern 2: Stitch tokens where inner part contains tags (e.g. {{<strong>pemagang</strong>.nama}})
    result = result.replace(/\{\{([^{}]+)\}\}/g, (match, inner) => {
        const cleaned = inner.replace(/<[^>]+>/g, '').trim();
        return /^[a-zA-Z0-9_.]+$/.test(cleaned) ? '{{' + cleaned + '}}' : match;
    });

    // Pattern 3: Normalize whitespace inside mustache {{ ... }}
    result = result.replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, '{{$1}}');

    // Pattern 4: Normalize square brackets [ ... ]
    result = result.replace(/\[\s*([a-zA-Z0-9_.]+)\s*\]/g, '[$1]');

    return result;
}

/**
 * Validate that an ArrayBuffer, Uint8Array, Buffer, or Blob is a genuine ZIP / OOXML DOCX package (PK\x03\x04).
 */
export function validateDocxBinary(buffer) {
    if (!buffer) return false;
    let view;
    if (buffer instanceof Uint8Array) {
        view = buffer;
    } else if (buffer instanceof ArrayBuffer) {
        view = new Uint8Array(buffer);
    } else if (buffer && buffer.buffer instanceof ArrayBuffer) {
        view = new Uint8Array(buffer.buffer, buffer.byteOffset || 0, buffer.byteLength || buffer.length);
    } else {
        return false;
    }
    if (view.length < 4) return false;
    // Magic bytes for ZIP: 0x50, 0x4B, 0x03, 0x04
    return view[0] === 0x50 && view[1] === 0x4B && view[2] === 0x03 && view[3] === 0x04;
}

/**
 * Import a DOCX file from an ArrayBuffer, Blob, File, or Buffer into clean semantic HTML.
 */
export async function importDocxFile(fileOrBuffer) {
    await loadMammoth();
    let arrayBuffer = null;
    let rawBuffer = null;

    if (fileOrBuffer instanceof ArrayBuffer) {
        arrayBuffer = fileOrBuffer;
    } else if (typeof Buffer !== 'undefined' && Buffer.isBuffer(fileOrBuffer)) {
        rawBuffer = fileOrBuffer;
        arrayBuffer = fileOrBuffer.buffer.slice(fileOrBuffer.byteOffset, fileOrBuffer.byteOffset + fileOrBuffer.byteLength);
    } else if (fileOrBuffer instanceof Uint8Array) {
        arrayBuffer = fileOrBuffer.buffer.slice(fileOrBuffer.byteOffset, fileOrBuffer.byteOffset + fileOrBuffer.byteLength);
    } else if (fileOrBuffer && typeof fileOrBuffer.arrayBuffer === 'function') {
        arrayBuffer = await fileOrBuffer.arrayBuffer();
    } else if (fileOrBuffer && fileOrBuffer.buffer instanceof ArrayBuffer) {
        arrayBuffer = fileOrBuffer.buffer.slice(fileOrBuffer.byteOffset || 0, (fileOrBuffer.byteOffset || 0) + (fileOrBuffer.byteLength || fileOrBuffer.length));
    } else {
        throw new Error('Format file tidak dikenali. Masukkan file DOCX yang valid.');
    }

    if (!validateDocxBinary(arrayBuffer)) {
        throw new Error('File bukan dokumen Microsoft Word (.docx) yang valid. Format .doc klasik atau file biner lain tidak didukung.');
    }

    const options = {
        styleMap: [
            "p[style-name='Heading 1'] => h1:fresh",
            "p[style-name='Heading 2'] => h2:fresh",
            "p[style-name='Heading 3'] => h3:fresh",
            "p[style-name='Heading 4'] => h4:fresh",
            "p[style-name='Title'] => h1:fresh",
            "p[style-name='Subtitle'] => h2:fresh",
            "r[style-name='Strong'] => strong",
            "r[style-name='Emphasis'] => em",
            "table => table:fresh",
            "tr => tr:fresh",
            "td => td:fresh",
            "th => th:fresh"
        ]
    };

    let result;
    if (typeof window === 'undefined') {
        // Node environment: pass Buffer
        const nodeBuffer = Buffer.from(arrayBuffer);
        result = await mammoth.convertToHtml({ buffer: nodeBuffer }, options);
    } else {
        // Browser environment: pass arrayBuffer
        result = await mammoth.convertToHtml({ arrayBuffer }, options);
    }

    const rawHtml = result.value || '';
    let cleanHtml = reconstructMailMergePlaceholders(rawHtml);

    // Normalize imported table structures from Word: assign standard bordered class and center alignment
    cleanHtml = cleanHtml.replace(/<table(?![^>]*\bclass=)([^>]*)>/gi, (match, rest) => {
        return `<table class="mou-table-bordered mou-correspondence-table" data-table-align="center"${rest}>`;
    });

    // Scan detected placeholders
    const placeholderMatches = cleanHtml.match(/\{\{([a-zA-Z0-9_.]+)\}\}|\[([a-zA-Z0-9_.]+)\]/g) || [];
    const uniquePlaceholders = Array.from(new Set(placeholderMatches));

    // Count tables and images
    const tablesCount = (cleanHtml.match(/<table/gi) || []).length;
    const imagesCount = (cleanHtml.match(/<img/gi) || []).length;
    const paragraphsCount = (cleanHtml.match(/<p/gi) || []).length;

    return {
        html: cleanHtml,
        messages: result.messages || [],
        summary: {
            tablesCount,
            imagesCount,
            paragraphsCount,
            placeholders: uniquePlaceholders,
            placeholdersCount: uniquePlaceholders.length
        }
    };
}

/**
 * Convert a ProseMirror JSON Document into a genuine Microsoft Word (.docx) document.
 */
export async function exportProseMirrorJsonToDocx(docJson, pageConfig = {}) {
    await loadDocx();
    if (!docJson || typeof docJson !== 'object') {
        throw new Error('Model dokumen JSON tidak valid.');
    }

    const paperSize = (pageConfig.paper_size || 'A4').toUpperCase();
    const orientation = (pageConfig.orientation || 'portrait').toLowerCase();
    const isLandscape = orientation === 'landscape';

    const baseW = paperSize === 'LETTER' ? 215.9 : (paperSize === 'LEGAL' ? 215.9 : 210.0);
    const baseH = paperSize === 'LETTER' ? 279.4 : (paperSize === 'LEGAL' ? 355.6 : 297.0);

    const paperWidthMm = isLandscape ? baseH : baseW;
    const paperHeightMm = isLandscape ? baseW : baseH;

    const marginTopMm = parseFloat(pageConfig.margin_top_mm ?? 20);
    const marginBottomMm = parseFloat(pageConfig.margin_bottom_mm ?? 20);
    const marginLeftMm = parseFloat(pageConfig.margin_left_mm ?? 20);
    const marginRightMm = parseFloat(pageConfig.margin_right_mm ?? 20);
    const headerDistanceMm = parseFloat(pageConfig.header_distance_mm ?? 12);
    const footerDistanceMm = parseFloat(pageConfig.footer_distance_mm ?? 10);
    const showHeaderLogos = pageConfig.show_header_logos !== false;
    const isMou = (pageConfig.document_type === 'mou' || pageConfig.is_mou);

    const hlCfg = pageConfig.header_logos_config || {};
    const instH = Math.min(105, Math.max(30, parseFloat(hlCfg.institution?.height ?? 66))) / 3.779527559;
    const compH = Math.min(105, Math.max(30, parseFloat(hlCfg.company?.height ?? 66))) / 3.779527559;
    const maxLogoHMm = Math.max(instH, compH);
    const effectiveTopMarginMm = (isMou && showHeaderLogos)
        ? Math.max(marginTopMm, Math.min(35.0, Math.ceil(headerDistanceMm + maxLogoHMm + 4.0)))
        : marginTopMm;

    const docxChildren = [];

    // Traverse root children
    const rootNodes = Array.isArray(docJson.content) ? docJson.content : [];

    // Helper to unwrap pages if present
    const contentNodes = [];
    rootNodes.forEach(node => {
        if (node.type === 'mouPage' && Array.isArray(node.content)) {
            contentNodes.push(...node.content);
        } else {
            contentNodes.push(node);
        }
    });

    for (let i = 0; i < contentNodes.length; i++) {
        const node = contentNodes[i];
        const docxElement = convertNodeToDocx(node, i === 0);
        if (docxElement) {
            if (Array.isArray(docxElement)) {
                docxChildren.push(...docxElement);
            } else {
                docxChildren.push(docxElement);
            }
        }
    }

    if (docxChildren.length === 0) {
        docxChildren.push(new docx.Paragraph({ text: '' }));
    }

    // Build Header elements (Kop Surat & Logo/Header text)
    const headerChildren = [];
    if (pageConfig.header_json && Array.isArray(pageConfig.header_json.content)) {
        pageConfig.header_json.content.forEach(hNode => {
            const converted = convertNodeToDocx(hNode);
            if (converted) {
                if (Array.isArray(converted)) headerChildren.push(...converted);
                else headerChildren.push(converted);
            }
        });
    }
    if (headerChildren.length === 0 && (pageConfig.show_header_logos !== false && (isMou || pageConfig.document_type === 'mou'))) {
        headerChildren.push(new docx.Paragraph({
            children: [
                new docx.TextRun({ text: '[KOP SURAT DOKUMEN RESMI]', bold: true, size: 18, color: '666666' })
            ],
            alignment: docx.AlignmentType.CENTER,
            spacing: { after: 120 }
        }));
    }

    // Build Footer elements (Tabel Paraf & Nomor Halaman)
    const footerChildren = [];
    if (pageConfig.footer_json && Array.isArray(pageConfig.footer_json.content)) {
        pageConfig.footer_json.content.forEach(fNode => {
            const converted = convertNodeToDocx(fNode);
            if (converted) {
                if (Array.isArray(converted)) footerChildren.push(...converted);
                else footerChildren.push(converted);
            }
        });
    }

    // If paraf box is enabled and not already provided in footer_json, build standard 2-column paraf table
    if (pageConfig.show_paraf_box !== false && footerChildren.length === 0) {
        footerChildren.push(new docx.Table({
            width: { size: 100, type: docx.WidthType.PERCENTAGE },
            alignment: docx.AlignmentType.CENTER,
            borders: {
                top: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                bottom: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                left: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                right: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                insideHorizontal: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                insideVertical: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
            },
            rows: [
                new docx.TableRow({
                    children: [
                        new docx.TableCell({
                            children: [
                                new docx.Paragraph({
                                    children: [new docx.TextRun({ text: 'PIHAK PERTAMA (MITRA)', bold: true, size: 16 })],
                                    alignment: docx.AlignmentType.CENTER
                                }),
                                new docx.Paragraph({
                                    children: [new docx.TextRun({ text: 'Paraf: [ ___________ ]', italics: true, size: 14 })],
                                    alignment: docx.AlignmentType.CENTER,
                                    spacing: { before: 80, after: 60 }
                                })
                            ],
                            width: { size: 50, type: docx.WidthType.PERCENTAGE },
                            margins: { top: 80, bottom: 80, left: 100, right: 100 }
                        }),
                        new docx.TableCell({
                            children: [
                                new docx.Paragraph({
                                    children: [new docx.TextRun({ text: 'PIHAK KEDUA (SEVEN INC)', bold: true, size: 16 })],
                                    alignment: docx.AlignmentType.CENTER
                                }),
                                new docx.Paragraph({
                                    children: [new docx.TextRun({ text: 'Paraf: [ ___________ ]', italics: true, size: 14 })],
                                    alignment: docx.AlignmentType.CENTER,
                                    spacing: { before: 80, after: 60 }
                                })
                            ],
                            width: { size: 50, type: docx.WidthType.PERCENTAGE },
                            margins: { top: 80, bottom: 80, left: 100, right: 100 }
                        })
                    ]
                })
            ]
        }));
    }

    // Add page number at bottom right if enabled
    if (pageConfig.show_page_number !== false) {
        footerChildren.push(new docx.Paragraph({
            children: [
                new docx.TextRun({ text: 'Halaman ', size: 16 }),
                docx.PageNumber.CURRENT,
                new docx.TextRun({ text: ' dari ', size: 16 }),
                docx.PageNumber.TOTAL_PAGES
            ],
            alignment: docx.AlignmentType.RIGHT,
            spacing: { before: 80 }
        }));
    }

    const sectionConfig = {
        properties: {
            page: {
                size: {
                    width: docx.convertMillimetersToTwip(paperWidthMm),
                    height: docx.convertMillimetersToTwip(paperHeightMm),
                    orientation: isLandscape ? docx.PageOrientation.LANDSCAPE : docx.PageOrientation.PORTRAIT,
                },
                margin: {
                    top: docx.convertMillimetersToTwip(effectiveTopMarginMm),
                    bottom: docx.convertMillimetersToTwip(marginBottomMm),
                    left: docx.convertMillimetersToTwip(marginLeftMm),
                    right: docx.convertMillimetersToTwip(marginRightMm),
                    header: docx.convertMillimetersToTwip(headerDistanceMm),
                    footer: docx.convertMillimetersToTwip(footerDistanceMm),
                }
            }
        },
        children: docxChildren
    };

    if (headerChildren.length > 0) {
        sectionConfig.headers = {
            default: new docx.Header({ children: headerChildren })
        };
    }
    if (footerChildren.length > 0) {
        sectionConfig.footers = {
            default: new docx.Footer({ children: footerChildren })
        };
    }

    const doc = new docx.Document({
        sections: [sectionConfig]
    });

    if (typeof window === 'undefined') {
        return await docx.Packer.toBuffer(doc);
    } else {
        return await docx.Packer.toBlob(doc);
    }
}

/**
 * Convert a single ProseMirror node into docx element(s).
 */
function convertNodeToDocx(node) {
    if (!node || !node.type) return null;

    switch (node.type) {
        case 'paragraph': {
            const runs = extractTextRunsFromNode(node);
            const align = mapAlignment(node.attrs?.textAlign || node.attrs?.align);
            return new docx.Paragraph({
                children: runs,
                alignment: align,
                spacing: { after: 120, line: 276 } // 1.15 line height default
            });
        }
        case 'heading': {
            const runs = extractTextRunsFromNode(node);
            const level = parseInt(node.attrs?.level || 1, 10);
            const align = mapAlignment(node.attrs?.textAlign);
            return new docx.Paragraph({
                children: runs,
                heading: level === 1 ? docx.HeadingLevel.HEADING_1 : (level === 2 ? docx.HeadingLevel.HEADING_2 : docx.HeadingLevel.HEADING_3),
                alignment: align,
                spacing: { before: 200, after: 120 }
            });
        }
        case 'bulletList': {
            const items = [];
            const listNodes = Array.isArray(node.content) ? node.content : [];
            listNodes.forEach(li => {
                const liChildren = Array.isArray(li.content) ? li.content : [];
                liChildren.forEach(child => {
                    const runs = extractTextRunsFromNode(child);
                    items.push(new docx.Paragraph({
                        children: runs,
                        bullet: { level: 0 },
                        spacing: { after: 60 }
                    }));
                });
            });
            return items;
        }
        case 'orderedList': {
            const items = [];
            const listNodes = Array.isArray(node.content) ? node.content : [];
            let numIdx = 1;
            listNodes.forEach(li => {
                const liChildren = Array.isArray(li.content) ? li.content : [];
                liChildren.forEach(child => {
                    const runs = extractTextRunsFromNode(child);
                    // Prepend numbered prefix
                    const prefixRun = new docx.TextRun({ text: `${numIdx}. `, bold: true });
                    items.push(new docx.Paragraph({
                        children: [prefixRun, ...runs],
                        spacing: { after: 60 }
                    }));
                });
                numIdx++;
            });
            return items;
        }
        case 'table': {
            const rows = [];
            const rowNodes = Array.isArray(node.content) ? node.content : [];
            rowNodes.forEach(rowNode => {
                if (rowNode.type === 'tableRow' || rowNode.type === 'table_row') {
                    const cells = [];
                    const cellNodes = Array.isArray(rowNode.content) ? rowNode.content : [];
                    cellNodes.forEach(cellNode => {
                        const cellChildren = [];
                        const innerNodes = Array.isArray(cellNode.content) ? cellNode.content : [];
                        innerNodes.forEach(inner => {
                            const converted = convertNodeToDocx(inner);
                            if (converted) {
                                if (Array.isArray(converted)) cellChildren.push(...converted);
                                else cellChildren.push(converted);
                            }
                        });
                        if (cellChildren.length === 0) {
                            cellChildren.push(new docx.Paragraph({ text: '' }));
                        }

                        // Determine cell width
                        let colWidthDxa = 2800; // default ~2 inches
                        if (cellNode.attrs?.colwidth && Array.isArray(cellNode.attrs.colwidth) && cellNode.attrs.colwidth[0]) {
                            colWidthDxa = Math.round(cellNode.attrs.colwidth[0] * 15);
                        }

                        cells.push(new docx.TableCell({
                            children: cellChildren,
                            width: { size: colWidthDxa, type: docx.WidthType.DXA },
                            margins: { top: 120, bottom: 120, left: 140, right: 140 }
                        }));
                    });

                    // Row height
                    let trHeight = undefined;
                    if (rowNode.attrs?.rowHeight) {
                        const pt = parseFloat(String(rowNode.attrs.rowHeight).replace(/[^0-9.]/g, '')) || 0;
                        if (pt > 0) {
                            trHeight = { value: Math.round(pt * 20), rule: docx.HeightRule.ATLEAST }; // 1pt = 20 dxa
                        }
                    }

                    rows.push(new docx.TableRow({
                        children: cells,
                        height: trHeight
                    }));
                }
            });

            const isBorderless = (node.attrs && (
                (typeof node.attrs.class === 'string' && node.attrs.class.includes('borderless')) ||
                node.attrs.tableStyle === 'borderless' ||
                node.attrs.borderStyle === 'none'
            ));

            const bordersConfig = isBorderless ? {
                top: { style: docx.BorderStyle.NONE, size: 0, color: 'auto' },
                bottom: { style: docx.BorderStyle.NONE, size: 0, color: 'auto' },
                left: { style: docx.BorderStyle.NONE, size: 0, color: 'auto' },
                right: { style: docx.BorderStyle.NONE, size: 0, color: 'auto' },
                insideHorizontal: { style: docx.BorderStyle.NONE, size: 0, color: 'auto' },
                insideVertical: { style: docx.BorderStyle.NONE, size: 0, color: 'auto' },
            } : {
                top: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                bottom: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                left: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                right: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                insideHorizontal: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
                insideVertical: { style: docx.BorderStyle.SINGLE, size: 4, color: '000000' },
            };

            return new docx.Table({
                rows,
                width: { size: 100, type: docx.WidthType.PERCENTAGE },
                alignment: docx.AlignmentType.CENTER,
                borders: bordersConfig,
            });
        }
        case 'mouPageBreak':
        case 'pageBreak': {
            return new docx.Paragraph({
                children: [new docx.TextRun({ text: '' })],
                pageBreakBefore: true
            });
        }
        default:
            return null;
    }
}

/**
 * Extract an array of docx.TextRun from a paragraph or inline node.
 */
function extractTextRunsFromNode(node) {
    const runs = [];
    if (!node || !Array.isArray(node.content)) {
        return runs;
    }

    node.content.forEach(inlineNode => {
        if (inlineNode.type === 'text') {
            const marks = inlineNode.marks || [];
            let isBold = false;
            let isItalic = false;
            let isUnderline = false;
            let isStrike = false;
            let isSub = false;
            let isSuper = false;
            let font = undefined;
            let sizePt = undefined;
            let color = undefined;

            marks.forEach(m => {
                if (m.type === 'bold') isBold = true;
                if (m.type === 'italic') isItalic = true;
                if (m.type === 'underline') isUnderline = true;
                if (m.type === 'strike') isStrike = true;
                if (m.type === 'subscript') isSub = true;
                if (m.type === 'superscript') isSuper = true;
                if (m.type === 'textStyle') {
                    if (m.attrs?.fontFamily) font = m.attrs.fontFamily;
                    if (m.attrs?.fontSize) {
                        sizePt = parseFloat(String(m.attrs.fontSize).replace(/[^0-9.]/g, ''));
                    }
                    if (m.attrs?.color) {
                        color = m.attrs.color.replace('#', '');
                    }
                }
            });

            runs.push(new docx.TextRun({
                text: inlineNode.text || '',
                bold: isBold,
                italics: isItalic,
                underline: isUnderline ? {} : undefined,
                strike: isStrike,
                subScript: isSub,
                superScript: isSuper,
                font: font ? { name: font } : undefined,
                size: sizePt ? Math.round(sizePt * 2) : undefined, // docx uses half-points
                color: color || undefined
            }));
        }
    });

    if (runs.length === 0) {
        runs.push(new docx.TextRun({ text: '' }));
    }

    return runs;
}

/**
 * Map CSS alignment to docx AlignmentType.
 */
function mapAlignment(alignStr) {
    if (!alignStr) return docx.AlignmentType.LEFT;
    const clean = String(alignStr).toLowerCase();
    if (clean === 'center') return docx.AlignmentType.CENTER;
    if (clean === 'right') return docx.AlignmentType.RIGHT;
    if (clean === 'justify') return docx.AlignmentType.JUSTIFIED;
    return docx.AlignmentType.LEFT;
}

/**
 * Trigger immediate browser download of a DOCX Blob.
 */
export function downloadDocxBlob(blob, filename = 'template.docx') {
    if (typeof window === 'undefined' || !window.document) return;
    const safeFilename = filename.endsWith('.docx') ? filename : `${filename}.docx`;
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = safeFilename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(() => URL.revokeObjectURL(url), 15000);
}
