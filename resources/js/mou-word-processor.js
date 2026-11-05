/**
 * SERTIF MoU Word Processor V3
 * Built on Tiptap Open-Source Core (^3.32.0) + ProseMirror MIT
 *
 * Provides a Microsoft Word-style free-form document editor with:
 * - Full rich-text formatting (Font Family, Size, Color, Highlight, Sub/Superscript, Align, Line Height, Indents, Spacing)
 * - Resizable Tables (Column width drag & input, Row height drag & input, Whole-table width drag & input, Cell padding, Borders, Merge/Split)
 * - Double-Click Editable Header & Footer sub-editors (with resizable Kotak Paraf table & Different First Page support)
 * - Dynamic ResizeObserver height synchronization between Header/Footer and A4 Body pagination guides
 * - Round-trip HTML & ProseMirror JSON serialization for accurate save -> reopen -> save and DomPDF rendering
 */

import { Editor, Node, Extension, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Paragraph from '@tiptap/extension-paragraph';
import Heading from '@tiptap/extension-heading';
import { BulletList, ListItem, ListKeymap, OrderedList } from '@tiptap/extension-list';
import { Table, TableRow, TableCell, TableHeader, TableView } from '@tiptap/extension-table';
import { TextStyle, FontFamily, FontSize, Color, BackgroundColor, LineHeight } from '@tiptap/extension-text-style';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import Image from '@tiptap/extension-image';
import Underline from '@tiptap/extension-underline';
import Placeholder from '@tiptap/extension-placeholder';
import { Plugin, PluginKey, NodeSelection } from '@tiptap/pm/state';
import { importDocxFile, exportProseMirrorJsonToDocx, downloadDocxBlob } from './mou-docx-engine.js';

const MM_TO_PX = 96 / 25.4; // ~3.779527559 px per mm

/**
 * Standard Physical Paper Dimensions Helper (Shared Across Editors & NodeViews)
 */
function calcPaperDimensionsMm(paperSize = 'A4', orientation = 'portrait') {
    const baseMap = {
        A4: { width: 210, height: 297 },
        LETTER: { width: 215.9, height: 279.4 },
        LEGAL: { width: 215.9, height: 355.6 },
    };
    const base = baseMap[(paperSize || 'A4').toUpperCase()] || baseMap.A4;
    return (orientation || 'portrait').toLowerCase() === 'landscape'
        ? { width: base.height, height: base.width }
        : { width: base.width, height: base.height };
}

/**
 * Helper: Extract a specific CSS property value from an inline style string.
 */
function extractStyleProperty(styleStr, propName) {
    if (!styleStr || typeof styleStr !== 'string') return null;
    const regex = new RegExp(`(?:^|;)\\s*${propName}\\s*:\\s*([^;]+)`, 'i');
    const match = styleStr.match(regex);
    return match ? match[1].trim() : null;
}

/**
 * Helper: Merge an array of 'prop: value' pairs into a clean inline style string.
 */
function buildStyleString(pairs) {
    const valid = pairs.filter(Boolean).map(s => s.replace(/;+\s*$/, '').trim()).filter(Boolean);
    return valid.length > 0 ? valid.join('; ') + ';' : null;
}

/**
 * Custom TableView that preserves custom tableWidth, tableAlign, border collapse,
 * and attaches a bottom-right corner handle for whole-table width resizing.
 */
class WordTableView extends TableView {
    constructor(node, defaultCellMinWidth, editor) {
        super(node, defaultCellMinWidth);
        this.editor = editor;
        this.applyCustomTableAttributes(node);
        this.setupTableResizeHandle();
    }

    update(node) {
        const ok = super.update(node);
        if (ok) {
            this.applyCustomTableAttributes(node);
        }
        return ok;
    }

    applyCustomTableAttributes(node) {
        if (!this.table) return;
        this.table.style.maxWidth = '100%';
        this.table.style.boxSizing = 'border-box';
        this.table.style.tableLayout = 'fixed';
        this.table.style.minWidth = ''; // Clear ProseMirror's minWidth so maxWidth: 100% is strictly honored!

        if (this.dom) {
            this.dom.style.maxWidth = '100%';
            this.dom.style.boxSizing = 'border-box';
            this.dom.style.position = 'relative';
            this.dom.style.overflowX = 'visible';
        }

        const attrs = node.attrs || {};
        if (attrs.class) {
            this.table.className = attrs.class;
        }
        if (attrs.class && (attrs.class.includes('mou-table-bordered') || attrs.class.includes('mou-correspondence-table') || attrs.class.includes('mou-paraf-table'))) {
            this.table.style.border = '1px solid #000000';
            this.table.style.borderCollapse = 'collapse';
        }
        if (attrs.tableWidth) {
            this.table.style.width = attrs.tableWidth;
            this.table.setAttribute('data-table-width', attrs.tableWidth);
        } else {
            this.table.style.width = '100%';
            this.table.removeAttribute('data-table-width');
        }

        if (attrs.tableAlign) {
            this.table.setAttribute('data-table-align', attrs.tableAlign);
            if (attrs.tableAlign === 'center') {
                this.table.style.marginLeft = 'auto';
                this.table.style.marginRight = 'auto';
            } else if (attrs.tableAlign === 'right') {
                this.table.style.marginLeft = 'auto';
                this.table.style.marginRight = '0';
            } else {
                this.table.style.marginLeft = '0';
                this.table.style.marginRight = 'auto';
            }
        }

        // Clamp & scale colgroup column widths if they exceed printable paper width
        if (this.colgroup) {
            const cols = Array.from(this.colgroup.children);
            if (cols.length > 0) {
                let totalColW = 0;
                const widths = cols.map(c => {
                    const w = parseFloat(c.style.width || c.getAttribute('width')) || 0;
                    totalColW += w;
                    return w;
                });
                let maxW = 601;
                try {
                    const pageBody = this.dom.closest('.mou-page-body-content') || document.querySelector('.mou-page-body-content');
                    if (pageBody && pageBody.clientWidth > 100) {
                        maxW = Math.max(100, pageBody.clientWidth - 2);
                    }
                } catch (_) {}

                if (totalColW > maxW + 2 && totalColW > 0) {
                    const scaleFactor = maxW / totalColW;
                    cols.forEach((c, idx) => {
                        const scaled = Math.max(14, Math.floor(widths[idx] * scaleFactor));
                        c.style.width = `${scaled}px`;
                        c.setAttribute('width', String(scaled));
                    });
                }
            }
        }

        // Synchronize row heights from node model directly to DOM tr and td elements
        const trElements = this.table.querySelectorAll('tr');
        let rIdx = 0;
        node.forEach((rowNode) => {
            if (rowNode.type.name === 'tableRow') {
                const trEl = trElements[rIdx];
                if (trEl) {
                    const rh = rowNode.attrs.rowHeight;
                    if (rh) {
                        trEl.style.height = rh;
                        trEl.setAttribute('data-row-height', rh);
                        Array.from(trEl.children).forEach(cellEl => {
                            cellEl.style.height = rh;
                            cellEl.setAttribute('data-row-height', rh);
                        });
                    } else {
                        trEl.style.height = '';
                        trEl.removeAttribute('data-row-height');
                        Array.from(trEl.children).forEach(cellEl => {
                            cellEl.style.height = '';
                            cellEl.removeAttribute('data-row-height');
                        });
                    }
                }
                rIdx++;
            }
        });
    }

    setupTableResizeHandle() {
        if (!this.dom) return;
        this.dom.style.position = 'relative';
        if (this.dom.querySelector('.mou-table-width-handle')) return;
        const handle = document.createElement('div');
        handle.className = 'mou-table-width-handle no-print';
        handle.title = 'Tarik untuk mengubah lebar tabel keseluruhan';
        this.dom.appendChild(handle);
    }
}

/**
 * Extended Paragraph with Word-like indentation and spacing attributes.
 */
const WordParagraph = Paragraph.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: el => el.getAttribute('class') || null,
                renderHTML: attrs => (attrs.class ? { class: attrs.class } : {}),
            },
            marginLeft: {
                default: null,
                parseHTML: el => el.style.marginLeft || extractStyleProperty(el.getAttribute('style'), 'margin-left') || null,
                renderHTML: attrs => (attrs.marginLeft ? { style: `margin-left: ${attrs.marginLeft} !important;` } : {}),
            },
            marginRight: {
                default: null,
                parseHTML: el => el.style.marginRight || extractStyleProperty(el.getAttribute('style'), 'margin-right') || null,
            },
            textIndent: {
                default: null,
                parseHTML: el => el.style.textIndent || extractStyleProperty(el.getAttribute('style'), 'text-indent') || null,
            },
            marginTop: {
                default: null,
                parseHTML: el => el.style.marginTop || extractStyleProperty(el.getAttribute('style'), 'margin-top') || null,
            },
            marginBottom: {
                default: null,
                parseHTML: el => el.style.marginBottom || extractStyleProperty(el.getAttribute('style'), 'margin-bottom') || null,
            },
            lineHeight: {
                default: null,
                parseHTML: el => el.style.lineHeight || extractStyleProperty(el.getAttribute('style'), 'line-height') || null,
            },
        };
    },
    renderHTML({ node, HTMLAttributes }) {
        const styles = [];
        if (HTMLAttributes.style) styles.push(HTMLAttributes.style);
        if (node.attrs.marginLeft) styles.push(`margin-left: ${node.attrs.marginLeft} !important`);
        if (node.attrs.marginRight) styles.push(`margin-right: ${node.attrs.marginRight} !important`);
        if (node.attrs.textIndent) styles.push(`text-indent: ${node.attrs.textIndent} !important`);
        if (node.attrs.marginTop) styles.push(`margin-top: ${node.attrs.marginTop}`);
        if (node.attrs.marginBottom) styles.push(`margin-bottom: ${node.attrs.marginBottom}`);
        if (node.attrs.lineHeight) styles.push(`line-height: ${node.attrs.lineHeight}`);

        const finalAttrs = { ...HTMLAttributes };
        delete finalAttrs.marginLeft;
        delete finalAttrs.marginRight;
        delete finalAttrs.textIndent;
        delete finalAttrs.marginTop;
        delete finalAttrs.marginBottom;
        delete finalAttrs.lineHeight;

        const styleStr = buildStyleString(styles);
        if (styleStr) finalAttrs.style = styleStr;

        return ['p', mergeAttributes(this.options.HTMLAttributes, finalAttrs), 0];
    },
});

/**
 * Extended Heading with Word-like spacing and indentation attributes.
 */
const WordHeading = Heading.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: el => el.getAttribute('class') || null,
                renderHTML: attrs => (attrs.class ? { class: attrs.class } : {}),
            },
            marginLeft: {
                default: null,
                parseHTML: el => el.style.marginLeft || extractStyleProperty(el.getAttribute('style'), 'margin-left') || null,
                renderHTML: attrs => (attrs.marginLeft ? { style: `margin-left: ${attrs.marginLeft} !important;` } : {}),
            },
            textIndent: {
                default: null,
                parseHTML: el => el.style.textIndent || extractStyleProperty(el.getAttribute('style'), 'text-indent') || null,
            },
            marginTop: {
                default: null,
                parseHTML: el => el.style.marginTop || extractStyleProperty(el.getAttribute('style'), 'margin-top') || null,
            },
            marginBottom: {
                default: null,
                parseHTML: el => el.style.marginBottom || extractStyleProperty(el.getAttribute('style'), 'margin-bottom') || null,
            },
            lineHeight: {
                default: null,
                parseHTML: el => el.style.lineHeight || extractStyleProperty(el.getAttribute('style'), 'line-height') || null,
            },
        };
    },
    renderHTML({ node, HTMLAttributes }) {
        const hasLevel = this.options.levels.includes(node.attrs.level);
        const level = hasLevel ? node.attrs.level : this.options.levels[0];

        const styles = [];
        if (HTMLAttributes.style) styles.push(HTMLAttributes.style);
        if (node.attrs.marginLeft) styles.push(`margin-left: ${node.attrs.marginLeft} !important`);
        if (node.attrs.textIndent) styles.push(`text-indent: ${node.attrs.textIndent} !important`);
        if (node.attrs.marginTop) styles.push(`margin-top: ${node.attrs.marginTop}`);
        if (node.attrs.marginBottom) styles.push(`margin-bottom: ${node.attrs.marginBottom}`);
        if (node.attrs.lineHeight) styles.push(`line-height: ${node.attrs.lineHeight}`);

        const finalAttrs = { ...HTMLAttributes };
        delete finalAttrs.level;
        delete finalAttrs.marginLeft;
        delete finalAttrs.textIndent;
        delete finalAttrs.marginTop;
        delete finalAttrs.marginBottom;
        delete finalAttrs.lineHeight;

        const styleStr = buildStyleString(styles);
        if (styleStr) finalAttrs.style = styleStr;

        return [`h${level}`, mergeAttributes(this.options.HTMLAttributes, finalAttrs), 0];
    },
});

/**
 * Helper: Extract list numbering type (1, a, A, i, I) from inline CSS list-style-type.
 */
function extractListStyleType(styleStr) {
    const raw = extractStyleProperty(styleStr, 'list-style-type');
    if (!raw) return null;
    const lower = raw.toLowerCase().trim();
    if (lower === 'lower-alpha' || lower === 'lower-latin') return 'a';
    if (lower === 'upper-alpha' || lower === 'upper-latin') return 'A';
    if (lower === 'lower-roman') return 'i';
    if (lower === 'upper-roman') return 'I';
    if (lower === 'decimal') return '1';
    return null;
}

/**
 * Extended OrderedList supporting list numbering style (1, a, A, i, I), indentation, and class.
 */
const WordOrderedList = OrderedList.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: el => el.getAttribute('class') || null,
            },
            type: {
                default: '1',
                parseHTML: el => {
                    const cls = el.getAttribute('class') || '';
                    if (cls.includes('mou-list-lower-alpha')) return 'a';
                    if (cls.includes('mou-list-upper-alpha')) return 'A';
                    if (cls.includes('mou-list-lower-roman')) return 'i';
                    if (cls.includes('mou-list-upper-roman')) return 'I';
                    if (cls.includes('mou-list-decimal')) return '1';
                    return el.getAttribute('data-list-type') || el.getAttribute('type') || extractListStyleType(el.getAttribute('style')) || '1';
                },
            },
            listType: {
                default: '1',
                parseHTML: el => {
                    const cls = el.getAttribute('class') || '';
                    if (cls.includes('mou-list-lower-alpha')) return 'a';
                    if (cls.includes('mou-list-upper-alpha')) return 'A';
                    if (cls.includes('mou-list-lower-roman')) return 'i';
                    if (cls.includes('mou-list-upper-roman')) return 'I';
                    if (cls.includes('mou-list-decimal')) return '1';
                    return el.getAttribute('data-list-type') || el.getAttribute('type') || extractListStyleType(el.getAttribute('style')) || '1';
                },
            },
            marginLeft: {
                default: null,
                parseHTML: el => el.style.marginLeft || extractStyleProperty(el.getAttribute('style'), 'margin-left') || null,
                renderHTML: attrs => (attrs.marginLeft ? { style: `margin-left: ${attrs.marginLeft} !important;` } : {}),
            },
        };
    },
    renderHTML({ node, HTMLAttributes }) {
        const type = node.attrs.listType || node.attrs.type || '1';
        const start = node.attrs.start || 1;
        const styles = [];
        if (HTMLAttributes.style) {
            const clean = HTMLAttributes.style
                .split(';')
                .map(s => s.trim())
                .filter(s => s && !s.toLowerCase().startsWith('list-style-type'))
                .join('; ');
            if (clean) styles.push(clean);
        }

        const listStyleMap = {
            '1': 'decimal',
            'a': 'lower-alpha',
            'A': 'upper-alpha',
            'i': 'lower-roman',
            'I': 'upper-roman',
        };
        const classMap = {
            '1': 'mou-list-decimal',
            'a': 'mou-list-lower-alpha',
            'A': 'mou-list-upper-alpha',
            'i': 'mou-list-lower-roman',
            'I': 'mou-list-upper-roman',
        };
        const cssType = listStyleMap[type] || 'decimal';
        const typeClass = classMap[type] || 'mou-list-decimal';
        styles.push(`list-style-type: ${cssType} !important`);

        if (node.attrs.marginLeft) {
            styles.push(`margin-left: ${node.attrs.marginLeft} !important`);
        }

        const finalAttrs = { ...HTMLAttributes };
        finalAttrs.type = type;
        finalAttrs['data-list-type'] = type;
        if (start !== 1) {
            finalAttrs.start = start;
        }

        delete finalAttrs.listType;
        delete finalAttrs.marginLeft;

        const existingClasses = (finalAttrs.class || '').split(/\s+/).filter(c => c && !c.startsWith('mou-list-'));
        existingClasses.push(typeClass);
        finalAttrs.class = existingClasses.join(' ');

        const styleStr = buildStyleString(styles);
        if (styleStr) finalAttrs.style = styleStr;

        return ['ol', mergeAttributes(this.options.HTMLAttributes, finalAttrs), 0];
    },
});

/**
 * Extended BulletList supporting class and styling preservation.
 */
const WordBulletList = BulletList.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: el => el.getAttribute('class') || null,
                renderHTML: attrs => (attrs.class ? { class: attrs.class } : {}),
            },
            marginLeft: {
                default: null,
                parseHTML: el => el.style.marginLeft || extractStyleProperty(el.getAttribute('style'), 'margin-left') || null,
                renderHTML: attrs => (attrs.marginLeft ? { style: `margin-left: ${attrs.marginLeft};` } : {}),
            },
        };
    },
});

/**
 * Extended Table supporting tableWidth, tableAlign, and class preservation.
 */
const WordTable = Table.extend({
    addOptions() {
        return {
            ...this.parent?.(),
            resizable: false, // Disables raw unconstrained PM col-resizer; we use interactive Word-style column resizer below!
            View: WordTableView,
            cellMinWidth: 14,
        };
    },
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: el => el.getAttribute('class') || null,
                renderHTML: attrs => (attrs.class ? { class: attrs.class } : {}),
            },
            tableWidth: {
                default: null,
                parseHTML: el => {
                    return el.getAttribute('data-table-width')
                        || el.style.width
                        || extractStyleProperty(el.getAttribute('style'), 'width')
                        || null;
                },
                renderHTML: attrs => {
                    if (!attrs.tableWidth) return {};
                    return {
                        'data-table-width': attrs.tableWidth,
                    };
                },
            },
            tableAlign: {
                default: null,
                parseHTML: el => el.getAttribute('data-table-align') || null,
                renderHTML: attrs => (attrs.tableAlign ? { 'data-table-align': attrs.tableAlign } : {}),
            },
            rawStyle: {
                default: null,
                parseHTML: el => el.getAttribute('style') || null,
            },
        };
    },
    renderHTML({ node, HTMLAttributes }) {
        const styles = [];
        if (node.attrs.rawStyle) {
            // Keep existing inline styles except width if overridden by tableWidth
            const cleaned = node.attrs.rawStyle
                .split(';')
                .map(s => s.trim())
                .filter(s => s && !/^width\s*:/i.test(s));
            styles.push(...cleaned);
        }
        const widthVal = node.attrs.tableWidth || '100%';
        styles.push(`width: ${widthVal}`);
        styles.push('border-collapse: collapse');
        styles.push('table-layout: fixed');

        if (node.attrs.tableAlign === 'center') {
            styles.push('margin-left: auto', 'margin-right: auto');
        } else if (node.attrs.tableAlign === 'right') {
            styles.push('margin-left: auto', 'margin-right: 0');
        }

        const attrs = mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
            'data-table-width': widthVal,
            style: buildStyleString(styles),
        });
        delete attrs.rawStyle;
        delete attrs.tableWidth;
        delete attrs.tableAlign;

        // Also output colgroup from first row colwidths for round-trip & DomPDF
        const colElements = [];
        const firstRow = node.firstChild;
        if (firstRow) {
            for (let i = 0; i < firstRow.childCount; i++) {
                const cell = firstRow.child(i);
                const colspan = cell.attrs.colspan || 1;
                const colwidth = Array.isArray(cell.attrs.colwidth) ? cell.attrs.colwidth : null;
                for (let j = 0; j < colspan; j++) {
                    const w = colwidth && colwidth[j] ? Number(colwidth[j]) : null;
                    if (w && w > 0) {
                        colElements.push(['col', { width: String(Math.round(w)), style: `width: ${Math.round(w)}px;` }]);
                    } else {
                        colElements.push(['col', {}]);
                    }
                }
            }
        }

        if (colElements.length > 0) {
            return ['table', attrs, ['colgroup', {}, ...colElements], ['tbody', 0]];
        }
        return ['table', attrs, ['tbody', 0]];
    },
});

/**
 * Extended TableRow supporting rowHeight (drag-resizable & numeric input).
 */
const WordTableRow = TableRow.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            rowHeight: {
                default: null,
                parseHTML: el => {
                    return el.getAttribute('data-row-height')
                        || el.style.height
                        || extractStyleProperty(el.getAttribute('style'), 'height')
                        || null;
                },
                renderHTML: attrs => {
                    if (!attrs.rowHeight) return {};
                    return {
                        'data-row-height': attrs.rowHeight,
                        style: `height: ${attrs.rowHeight};`,
                    };
                },
            },
        };
    },
});

/**
 * Shared attribute definitions for TableCell and TableHeader.
 */
function buildTableCellAttributes(parentAttrs) {
    return {
        ...parentAttrs,
        colwidth: {
            default: null,
            parseHTML: el => {
                const cw = el.getAttribute('colwidth') || el.getAttribute('data-colwidth');
                if (cw) {
                    const nums = cw.split(',').map(s => parseInt(s.trim(), 10)).filter(n => !isNaN(n) && n > 0);
                    if (nums.length > 0) return nums;
                }
                // Check data-cell-width or inline style width in px/pt
                const dcw = el.getAttribute('data-cell-width') || el.style.width || extractStyleProperty(el.getAttribute('style'), 'width');
                if (dcw && /^\d+(\.\d+)?px$/i.test(dcw.trim())) {
                    return [Math.round(parseFloat(dcw))];
                }
                // Check matching <colgroup><col> in parent table
                const tr = el.parentElement;
                const table = el.closest('table');
                if (tr && table) {
                    let colIndex = 0;
                    for (const sibling of Array.from(tr.children)) {
                        if (sibling === el) break;
                        colIndex += parseInt(sibling.getAttribute('colspan') || '1', 10) || 1;
                    }
                    const cols = table.querySelectorAll('colgroup > col');
                    if (cols[colIndex]) {
                        const colW = cols[colIndex].getAttribute('width') || cols[colIndex].style.width;
                        if (colW && /^\d+(\.\d+)?(px)?$/i.test(colW.trim())) {
                            return [Math.round(parseFloat(colW))];
                        }
                    }
                }
                return null;
            },
        },
        cellWidth: {
            default: null,
            parseHTML: el => {
                return el.getAttribute('data-cell-width')
                    || el.style.width
                    || extractStyleProperty(el.getAttribute('style'), 'width')
                    || null;
            },
        },
        rowHeight: {
            default: null,
            parseHTML: el => {
                return el.getAttribute('data-row-height')
                    || el.style.height
                    || extractStyleProperty(el.getAttribute('style'), 'height')
                    || (el.parentElement ? (el.parentElement.getAttribute('data-row-height') || el.parentElement.style.height) : null)
                    || null;
            },
        },
        backgroundColor: {
            default: null,
            parseHTML: el => el.getAttribute('data-bg-color') || el.style.backgroundColor || extractStyleProperty(el.getAttribute('style'), 'background-color') || null,
        },
        borderStyle: {
            default: null,
            parseHTML: el => el.getAttribute('data-border-style') || extractStyleProperty(el.getAttribute('style'), 'border-style') || null,
        },
        borderWidth: {
            default: null,
            parseHTML: el => el.getAttribute('data-border-width') || extractStyleProperty(el.getAttribute('style'), 'border-width') || null,
        },
        borderColor: {
            default: null,
            parseHTML: el => el.getAttribute('data-border-color') || extractStyleProperty(el.getAttribute('style'), 'border-color') || null,
        },
        padding: {
            default: null,
            parseHTML: el => el.getAttribute('data-cell-padding') || el.style.padding || extractStyleProperty(el.getAttribute('style'), 'padding') || null,
        },
        verticalAlign: {
            default: null,
            parseHTML: el => el.style.verticalAlign || extractStyleProperty(el.getAttribute('style'), 'vertical-align') || null,
        },
        textAlign: {
            default: null,
            parseHTML: el => el.style.textAlign || extractStyleProperty(el.getAttribute('style'), 'text-align') || null,
        },
        rawBorder: {
            default: null,
            parseHTML: el => {
                const style = el.getAttribute('style') || '';
                const borderParts = style.split(';')
                    .map(s => s.trim())
                    .filter(s => /^border(-[a-z]+)?\s*:/i.test(s));
                if (borderParts.length > 0) {
                    return borderParts.join('; ');
                }
                return extractStyleProperty(style, 'border') || null;
            },
        },
        class: {
            default: null,
            parseHTML: el => el.getAttribute('class') || null,
        },
    };
}

/**
 * Merge or update individual border side declarations cleanly.
 */
function updateBorderProperties(existingRawBorder, updates) {
    if (updates.border !== undefined) {
        return updates.border;
    }
    const currentProps = {
        'border-top': 'none',
        'border-right': 'none',
        'border-bottom': 'none',
        'border-left': 'none',
    };
    if (existingRawBorder && existingRawBorder !== 'none') {
        if (/^border(-[a-z]+)?\s*:/i.test(existingRawBorder)) {
            const parts = existingRawBorder.split(';');
            parts.forEach(p => {
                const [k, v] = p.split(':').map(s => s?.trim());
                if (k && v) {
                    if (k === 'border') {
                        currentProps['border-top'] = v;
                        currentProps['border-right'] = v;
                        currentProps['border-bottom'] = v;
                        currentProps['border-left'] = v;
                    } else if (currentProps[k] !== undefined) {
                        currentProps[k] = v;
                    }
                }
            });
        } else {
            currentProps['border-top'] = existingRawBorder;
            currentProps['border-right'] = existingRawBorder;
            currentProps['border-bottom'] = existingRawBorder;
            currentProps['border-left'] = existingRawBorder;
        }
    }

    Object.assign(currentProps, updates);

    const { 'border-top': t, 'border-right': r, 'border-bottom': b, 'border-left': l } = currentProps;
    if (t === r && r === b && b === l) {
        return t || 'none';
    }
    return `border-top: ${t || 'none'}; border-right: ${r || 'none'}; border-bottom: ${b || 'none'}; border-left: ${l || 'none'}`;
}

function renderTableCellHTML(tag, node, HTMLAttributes, baseHTMLAttributes) {
    const attrs = {};
    const styles = [];

    if (node.attrs.colspan && node.attrs.colspan !== 1) attrs.colspan = node.attrs.colspan;
    if (node.attrs.rowspan && node.attrs.rowspan !== 1) attrs.rowspan = node.attrs.rowspan;
    if (node.attrs.class) attrs.class = node.attrs.class;

    let widthCss = node.attrs.cellWidth || null;
    if (Array.isArray(node.attrs.colwidth) && node.attrs.colwidth.length > 0) {
        const cleanWidths = node.attrs.colwidth.map(w => Math.round(Number(w))).filter(w => w > 0);
        if (cleanWidths.length > 0) {
            attrs.colwidth = cleanWidths.join(',');
            const totalPx = cleanWidths.reduce((a, b) => a + b, 0);
            widthCss = `${totalPx}px`;
        }
    }

    if (widthCss) {
        attrs['data-cell-width'] = widthCss;
        styles.push(`width: ${widthCss}`);
    }

    if (node.attrs.rowHeight) {
        attrs['data-row-height'] = node.attrs.rowHeight;
        styles.push(`height: ${node.attrs.rowHeight}`);
    }

    if (node.attrs.backgroundColor) {
        attrs['data-bg-color'] = node.attrs.backgroundColor;
        styles.push(`background-color: ${node.attrs.backgroundColor}`);
    }

    if (node.attrs.rawBorder) {
        if (node.attrs.rawBorder === 'none') {
            styles.push('border: none');
        } else if (/^border(-[a-z]+)?\s*:/i.test(node.attrs.rawBorder)) {
            styles.push(node.attrs.rawBorder);
        } else {
            styles.push(`border: ${node.attrs.rawBorder}`);
        }
    } else if (node.attrs.borderStyle || node.attrs.borderWidth || node.attrs.borderColor) {
        const bStyle = node.attrs.borderStyle || 'solid';
        const bWidth = node.attrs.borderWidth || (bStyle === 'none' ? '0' : '1px');
        const bColor = node.attrs.borderColor || '#000000';
        attrs['data-border-style'] = bStyle;
        attrs['data-border-width'] = bWidth;
        attrs['data-border-color'] = bColor;
        if (bStyle === 'none' || bWidth === '0' || bWidth === '0px') {
            styles.push('border: none');
        } else {
            styles.push(`border: ${bWidth} ${bStyle} ${bColor}`);
        }
    }

    if (node.attrs.padding) {
        attrs['data-cell-padding'] = node.attrs.padding;
        styles.push(`padding: ${node.attrs.padding}`);
    }

    if (node.attrs.verticalAlign) {
        styles.push(`vertical-align: ${node.attrs.verticalAlign}`);
    }

    if (node.attrs.textAlign) {
        styles.push(`text-align: ${node.attrs.textAlign}`);
    }

    styles.push('word-break: break-word', 'overflow-wrap: break-word');

    const styleStr = buildStyleString(styles);
    if (styleStr) attrs.style = styleStr;

    return [tag, mergeAttributes(baseHTMLAttributes, attrs), 0];
}

const WordTableCell = TableCell.extend({
    addAttributes() {
        return buildTableCellAttributes(this.parent?.() || {});
    },
    renderHTML({ node, HTMLAttributes }) {
        return renderTableCellHTML('td', node, HTMLAttributes, this.options.HTMLAttributes);
    },
});

const WordTableHeader = TableHeader.extend({
    addAttributes() {
        return buildTableCellAttributes(this.parent?.() || {});
    },
    renderHTML({ node, HTMLAttributes }) {
        return renderTableCellHTML('th', node, HTMLAttributes, this.options.HTMLAttributes);
    },
});

/**
 * Interactive WordImageView with 8 Sizing Handles (4 corners + 4 edges)
 * Supports aspect ratio locked corner resizing, printable width containment, and Undo/Redo.
 */
class WordImageView {
    constructor(node, view, getPos) {
        this.node = node;
        this.view = view;
        this.getPos = getPos;

        this.dom = document.createElement('span');
        this.dom.className = 'mou-image-wrapper';
        this.dom.setAttribute('data-tiptap-image-wrapper', 'true');

        this.img = document.createElement('img');
        this.img.setAttribute('draggable', 'false');
        this.img.draggable = false;
        this.img.onerror = () => {
            if (this.img.src && !this.img.src.startsWith('data:image/svg+xml')) {
                this.img.src = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" viewBox="0 0 120 60"><rect width="100%" height="100%" fill="%23f8fafc" stroke="%23cbd5e1" stroke-dasharray="4 2"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-size="10" fill="%2394a3b8">Asset Preview</text></svg>';
            }
        };
        this.dom.appendChild(this.img);

        this.overlay = document.createElement('div');
        this.overlay.className = 'mou-image-handle-overlay no-print';
        this.overlay.style.display = 'none';
        this.dom.appendChild(this.overlay);

        this.badge = document.createElement('span');
        this.badge.className = 'mou-image-size-badge';
        this.overlay.appendChild(this.badge);

        // Interactive Rotator Handle at Top Center (Word/Canva standard)
        this.rotatorWrapper = document.createElement('div');
        this.rotatorWrapper.className = 'mou-image-rotator-wrapper';
        this.rotatorLine = document.createElement('div');
        this.rotatorLine.className = 'mou-image-rotator-line';
        this.rotatorHandle = document.createElement('div');
        this.rotatorHandle.className = 'mou-image-rotator-handle';
        this.rotatorHandle.title = 'Tarik untuk memutar gambar (Rotasi) • Klik ganda untuk kembali ke 0°';
        this.rotatorWrapper.appendChild(this.rotatorLine);
        this.rotatorWrapper.appendChild(this.rotatorHandle);
        this.overlay.appendChild(this.rotatorWrapper);
        this.attachRotatorDrag(this.rotatorHandle);

        this.handles = {};
        const positions = ['nw', 'ne', 'se', 'sw', 'n', 's', 'e', 'w'];
        positions.forEach(pos => {
            const h = document.createElement('div');
            h.className = `mou-image-handle ${pos}`;
            h.setAttribute('data-handle-pos', pos);
            this.overlay.appendChild(h);
            this.handles[pos] = h;
            this.attachHandleDrag(h, pos);
        });

        this.updateAppearance(node);
        this.attachSelectionListeners();
    }

    updateAppearance(node) {
        this.node = node;
        const attrs = node.attrs || {};
        this.img.src = attrs.src || '';
        this.img.alt = attrs.alt || '';
        this.img.title = attrs.title || '';
        this.img.className = attrs.class || 'mou-logo-img';

        const styles = ['object-fit: contain', 'display: block'];
        let wPx = 120;
        let rawW = '120px';
        if (attrs.width) {
            rawW = /^\d+$/.test(String(attrs.width)) ? `${attrs.width}px` : attrs.width;
            styles.push(`width: ${rawW}`);
            wPx = parseInt(rawW, 10) || 120;
        } else {
            styles.push('width: 120px');
        }

        let rawH = 'auto';
        if (attrs.height && attrs.height !== 'auto') {
            rawH = /^\d+$/.test(String(attrs.height)) ? `${attrs.height}px` : attrs.height;
            styles.push(`height: ${rawH}`);
        } else {
            styles.push('height: auto');
        }

        if (attrs.position === 'absolute') {
            this.dom.style.setProperty('position', 'absolute', 'important');
            this.dom.style.left = attrs.left || '12%';
            this.dom.style.top = attrs.top || '-10px';
            if (attrs.right) this.dom.style.right = attrs.right;
            if (attrs.bottom) this.dom.style.bottom = attrs.bottom;
            this.dom.style.zIndex = attrs.zIndex || '20';
            this.dom.style.width = rawW || '125px';
            this.dom.style.height = (rawH && rawH !== 'auto') ? rawH : (rawW || '125px');
            this.dom.style.display = 'block';
            this.dom.style.lineHeight = '0';
            this.dom.classList.add('is-stamp-seal');
            if (attrs.pointerEvents) {
                this.dom.style.pointerEvents = attrs.pointerEvents;
            }
            if (attrs.opacity) {
                this.dom.style.opacity = attrs.opacity;
            }
            styles.length = 0;
            styles.push('object-fit: contain', 'display: block', 'width: 100%', 'height: 100%');
        } else {
            this.dom.classList.remove('is-stamp-seal');
            this.dom.style.position = attrs.position || '';
            if (attrs.zIndex) this.dom.style.zIndex = attrs.zIndex;
            if (attrs.pointerEvents) this.dom.style.pointerEvents = attrs.pointerEvents;
            if (attrs.opacity) {
                this.dom.style.opacity = attrs.opacity;
            }
            if (attrs.align === 'center') {
                this.dom.style.display = 'block';
                this.dom.style.marginLeft = 'auto';
                this.dom.style.marginRight = 'auto';
                this.dom.style.textAlign = 'center';
            } else if (attrs.align === 'right') {
                this.dom.style.display = 'block';
                this.dom.style.marginLeft = 'auto';
                this.dom.style.marginRight = '0';
                this.dom.style.textAlign = 'right';
            } else {
                this.dom.style.display = 'inline-block';
                this.dom.style.marginLeft = '0';
                this.dom.style.marginRight = 'auto';
                this.dom.style.textAlign = 'left';
            }
        }

        const rot = parseInt(attrs.rotation || 0, 10);
        if (rot !== 0) {
            styles.push(`transform: rotate(${rot}deg)`);
            styles.push('transform-origin: center center');
        }

        this.img.style.cssText = styles.join('; ');
        let hPx = Math.round(this.img.offsetHeight) || wPx;
        this.badge.textContent = `${wPx} × ${hPx} px` + (rot !== 0 ? ` • ${rot}°` : '');
    }

    update(node) {
        if (node.type.name !== 'image') return false;
        this.updateAppearance(node);
        return true;
    }

    selectNode() {
        this.dom.classList.add('is-selected');
        this.overlay.style.display = 'block';
        window._mouActiveSelectedAsset = {
            type: 'body-image',
            nodeView: this,
        };
        const wInput = document.getElementById('ribbonImageWidthPx');
        if (wInput) {
            const w = parseInt(String(this.node.attrs?.width || '110').replace(/[^0-9]/g, ''), 10) || 110;
            wInput.value = String(w);
        }
        const hInput = document.getElementById('ribbonImageHeightPx');
        if (hInput) {
            const h = parseInt(String(this.node.attrs?.height || '').replace(/[^0-9]/g, ''), 10);
            hInput.value = h ? String(h) : '';
        }
        const rotInput = document.getElementById('ribbonImageRotationDeg');
        if (rotInput) {
            rotInput.value = String(this.node.attrs?.rotation || 0);
        }
    }

    deselectNode() {
        this.dom.classList.remove('is-selected');
        this.overlay.style.display = 'none';
        if (window._mouActiveSelectedAsset?.nodeView === this) {
            window._mouActiveSelectedAsset = null;
        }
    }

    deleteNode() {
        if (typeof this.getPos === 'function' && this.view?.state?.tr) {
            const pos = this.getPos();
            if (pos !== undefined && typeof pos === 'number' && pos >= 0) {
                const tr = this.view.state.tr.delete(pos, pos + this.node.nodeSize);
                this.view.dispatch(tr);
                window._mouActiveSelectedAsset = null;
                return true;
            }
        }
        return false;
    }

    stopEvent(event) {
        if (event.target.closest('.mou-image-handle') ||
            event.target.closest('.mou-image-rotator-handle') ||
            event.target.closest('.mou-image-rotator-wrapper') ||
            event.target.closest('.mou-image-handle-overlay')) {
            return true;
        }
        return false;
    }

    attachRotatorDrag(rotatorEl) {
        rotatorEl.setAttribute('draggable', 'false');
        rotatorEl.style.touchAction = 'none';

        rotatorEl.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const rect = this.img.getBoundingClientRect();
            const centerX = rect.left + rect.width / 2;
            const centerY = rect.top + rect.height / 2;
            let currentDeg = parseInt(this.node.attrs?.rotation || 0, 10);
            this.selectNode();

            const onRotateMove = (moveEv) => {
                moveEv.preventDefault();
                const rad = Math.atan2(moveEv.clientY - centerY, moveEv.clientX - centerX);
                let deg = Math.round(rad * (180 / Math.PI)) + 90;
                deg = ((deg % 360) + 360) % 360;
                if (Math.abs(deg - 0) <= 4 || Math.abs(deg - 360) <= 4) deg = 0;
                else if (Math.abs(deg - 90) <= 4) deg = 90;
                else if (Math.abs(deg - 180) <= 4) deg = 180;
                else if (Math.abs(deg - 270) <= 4) deg = 270;

                currentDeg = deg;
                this.img.style.transform = `rotate(${deg}deg)`;
                this.img.style.transformOrigin = 'center center';
                const w = parseInt(String(this.node.attrs?.width || '110').replace(/[^0-9]/g, ''), 10) || 110;
                const h = Math.round(this.img.offsetHeight) || w;
                this.badge.textContent = `${w} × ${h} px • ${deg}°`;

                const rotInput = document.getElementById('ribbonImageRotationDeg');
                if (rotInput) rotInput.value = String(deg);
            };

            const onRotateUp = () => {
                window.removeEventListener('pointermove', onRotateMove);
                window.removeEventListener('pointerup', onRotateUp);
                window.removeEventListener('pointercancel', onRotateUp);

                if (typeof this.getPos === 'function' && this.view?.state?.tr) {
                    const pos = this.getPos();
                    if (pos !== undefined) {
                        const tr = this.view.state.tr.setNodeMarkup(pos, undefined, {
                            ...this.node.attrs,
                            rotation: currentDeg,
                        });
                        this.view.dispatch(tr);
                    }
                }
            };

            window.addEventListener('pointermove', onRotateMove, { passive: false });
            window.addEventListener('pointerup', onRotateUp);
            window.addEventListener('pointercancel', onRotateUp);
        });

        rotatorEl.addEventListener('dblclick', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (typeof this.getPos === 'function' && this.view?.state?.tr) {
                const pos = this.getPos();
                if (pos !== undefined) {
                    const tr = this.view.state.tr.setNodeMarkup(pos, undefined, {
                        ...this.node.attrs,
                        rotation: 0,
                    });
                    this.view.dispatch(tr);
                }
            }
            const rotInput = document.getElementById('ribbonImageRotationDeg');
            if (rotInput) rotInput.value = '0';
        });
    }

    attachSelectionListeners() {
        this.dom.addEventListener('click', (e) => {
            e.stopPropagation();
            this.selectNode();
            try {
                if (typeof this.getPos === 'function' && this.view) {
                    const pos = this.getPos();
                    if (typeof pos === 'number' && pos >= 0) {
                        const { state } = this.view;
                        const nodeSel = NodeSelection.create(state.doc, pos);
                        this.view.dispatch(state.tr.setSelection(nodeSel));
                    }
                }
            } catch (selErr) {
                // Ignore selection boundary edge case
            }
        });
    }

    attachHandleDrag(handleEl, handlePos) {
        handleEl.setAttribute('draggable', 'false');
        handleEl.style.touchAction = 'none';

        handleEl.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            e.stopPropagation();

            const startX = e.clientX;
            const startY = e.clientY;
            const rect = this.img.getBoundingClientRect();
            const scale = (parseFloat(window.MouWordProcessor?.state?.zoomPercent) || 100) / 100;
            const startW = rect.width / scale;
            const startH = rect.height / scale;
            const aspect = startW / (startH || 1);
            this.selectNode();

            let finalW = startW;
            let finalH = startH;

            const onDragMove = (moveEv) => {
                moveEv.preventDefault();
                const deltaX = (moveEv.clientX - startX) / scale;
                const deltaY = (moveEv.clientY - startY) / scale;

                let newW = startW;
                let newH = startH;

                if (handlePos === 'se') {
                    newW = Math.max(20, startW + deltaX);
                    newH = newW / aspect;
                } else if (handlePos === 'sw') {
                    newW = Math.max(20, startW - deltaX);
                    newH = newW / aspect;
                } else if (handlePos === 'ne') {
                    newW = Math.max(20, startW + deltaX);
                    newH = newW / aspect;
                } else if (handlePos === 'nw') {
                    newW = Math.max(20, startW - deltaX);
                    newH = newW / aspect;
                } else if (handlePos === 'e') {
                    newW = Math.max(20, startW + deltaX);
                } else if (handlePos === 'w') {
                    newW = Math.max(20, startW - deltaX);
                } else if (handlePos === 's') {
                    newH = Math.max(20, startH + deltaY);
                } else if (handlePos === 'n') {
                    newH = Math.max(20, startH - deltaY);
                }

                // Clamp to max printable width (~640px)
                const maxW = 640;
                if (newW > maxW) {
                    newW = maxW;
                    if (['se', 'sw', 'ne', 'nw'].includes(handlePos)) newH = newW / aspect;
                }

                finalW = Math.round(newW);
                finalH = Math.round(newH);
                this.img.style.width = `${finalW}px`;
                this.img.style.height = `${finalH}px`;
                this.badge.textContent = `${finalW} × ${finalH} px`;
            };

            const onDragUp = () => {
                window.removeEventListener('pointermove', onDragMove);
                window.removeEventListener('pointerup', onDragUp);
                window.removeEventListener('pointercancel', onDragUp);

                if (typeof this.getPos === 'function') {
                    const pos = this.getPos();
                    if (pos !== undefined && this.view?.state?.tr) {
                        const tr = this.view.state.tr.setNodeMarkup(pos, undefined, {
                            ...this.node.attrs,
                            width: `${finalW}px`,
                            height: `${finalH}px`,
                        });
                        this.view.dispatch(tr);
                    }
                }

                const wInput = document.getElementById('ribbonImageWidthPx');
                if (wInput) wInput.value = String(finalW);
            };

            window.addEventListener('pointermove', onDragMove, { passive: false });
            window.addEventListener('pointerup', onDragUp);
            window.addEventListener('pointercancel', onDragUp);
        });
    }

    destroy() {
        this.dom.remove();
    }
}

/**
 * Extended Image supporting width, height, alignment, class, and interactive handles NodeView.
 */
const WordImage = Image.extend({
    inline: true,
    group: 'inline',
    addAttributes() {
        return {
            ...this.parent?.(),
            width: {
                default: null,
                parseHTML: el => el.getAttribute('width') || el.style.width || extractStyleProperty(el.getAttribute('style'), 'width') || null,
            },
            height: {
                default: null,
                parseHTML: el => el.getAttribute('height') || el.style.height || extractStyleProperty(el.getAttribute('style'), 'height') || null,
            },
            align: {
                default: null,
                parseHTML: el => el.getAttribute('data-align') || null,
            },
            class: {
                default: 'mou-logo-img',
                parseHTML: el => el.getAttribute('class') || 'mou-logo-img',
            },
            assetId: {
                default: null,
                parseHTML: el => el.getAttribute('data-asset-id') || null,
            },
            assetCode: {
                default: null,
                parseHTML: el => el.getAttribute('data-asset-code') || null,
            },
            token: {
                default: null,
                parseHTML: el => el.getAttribute('data-token') || null,
            },
            position: {
                default: null,
                parseHTML: el => el.style.position || extractStyleProperty(el.getAttribute('style'), 'position') || null,
            },
            left: {
                default: null,
                parseHTML: el => el.style.left || extractStyleProperty(el.getAttribute('style'), 'left') || null,
            },
            top: {
                default: null,
                parseHTML: el => el.style.top || extractStyleProperty(el.getAttribute('style'), 'top') || null,
            },
            right: {
                default: null,
                parseHTML: el => el.style.right || extractStyleProperty(el.getAttribute('style'), 'right') || null,
            },
            bottom: {
                default: null,
                parseHTML: el => el.style.bottom || extractStyleProperty(el.getAttribute('style'), 'bottom') || null,
            },
            opacity: {
                default: null,
                parseHTML: el => el.style.opacity || extractStyleProperty(el.getAttribute('style'), 'opacity') || null,
            },
            zIndex: {
                default: null,
                parseHTML: el => el.style.zIndex || extractStyleProperty(el.getAttribute('style'), 'z-index') || null,
            },
            pointerEvents: {
                default: null,
                parseHTML: el => el.style.pointerEvents || extractStyleProperty(el.getAttribute('style'), 'pointer-events') || null,
            },
            rotation: {
                default: 0,
                parseHTML: el => {
                    const r = el.getAttribute('data-rotation');
                    if (r !== null && r !== '') return parseInt(r, 10) || 0;
                    const transform = el.style.transform || extractStyleProperty(el.getAttribute('style'), 'transform') || '';
                    const match = transform.match(/rotate\(([-]?\d+(?:\.\d+)?)\s*deg\)/i);
                    return match ? parseInt(match[1], 10) : 0;
                },
                renderHTML: attrs => attrs.rotation ? { 'data-rotation': attrs.rotation } : {},
            },
        };
    },
    addNodeView() {
        return (props) => new WordImageView(props.node, props.view, props.getPos);
    },
    renderHTML({ node, HTMLAttributes }) {
        const styles = ['object-fit: contain'];
        const attrs = {
            src: node.attrs.src,
            alt: node.attrs.alt || '',
            title: node.attrs.title || '',
            class: node.attrs.class || 'mou-logo-img',
        };

        if (node.attrs.assetId) {
            attrs['data-asset-id'] = node.attrs.assetId;
        }
        if (node.attrs.assetCode) {
            attrs['data-asset-code'] = node.attrs.assetCode;
        }
        if (node.attrs.token) {
            attrs['data-token'] = node.attrs.token;
        }

        if (node.attrs.width) {
            const w = /^\d+$/.test(String(node.attrs.width)) ? `${node.attrs.width}px` : node.attrs.width;
            styles.push(`width: ${w}`);
            styles.push(`max-width: 100%`);
        }
        if (node.attrs.height && node.attrs.height !== 'auto') {
            const h = /^\d+$/.test(String(node.attrs.height)) ? `${node.attrs.height}px` : node.attrs.height;
            styles.push(`height: ${h}`);
        } else {
            styles.push('height: auto');
        }

        if (node.attrs.position) styles.push(`position: ${node.attrs.position}`);
        if (node.attrs.left) styles.push(`left: ${node.attrs.left}`);
        if (node.attrs.top) styles.push(`top: ${node.attrs.top}`);
        if (node.attrs.right) styles.push(`right: ${node.attrs.right}`);
        if (node.attrs.bottom) styles.push(`bottom: ${node.attrs.bottom}`);
        if (node.attrs.opacity) styles.push(`opacity: ${node.attrs.opacity}`);
        if (node.attrs.zIndex) styles.push(`z-index: ${node.attrs.zIndex}`);
        if (node.attrs.pointerEvents) styles.push(`pointer-events: ${node.attrs.pointerEvents}`);
        if (node.attrs.rotation) {
            styles.push(`transform: rotate(${node.attrs.rotation}deg)`);
            styles.push('transform-origin: center center');
            attrs['data-rotation'] = node.attrs.rotation;
        }

        if (node.attrs.position !== 'absolute') {
            if (node.attrs.align === 'center') {
                attrs['data-align'] = 'center';
                styles.push('display: block', 'margin-left: auto', 'margin-right: auto');
            } else if (node.attrs.align === 'right') {
                attrs['data-align'] = 'right';
                styles.push('display: block', 'margin-left: auto', 'margin-right: 0');
            } else if (node.attrs.align === 'left') {
                attrs['data-align'] = 'left';
                styles.push('display: inline-block');
            } else {
                styles.push('display: inline-block');
            }
        }

        attrs.style = buildStyleString(styles);
        return ['img', mergeAttributes(this.options.HTMLAttributes, attrs)];
    },
});

/**
 * Custom PageBreak Node (<div class="mou-page-break" data-page-break="true"></div>)
 */
const WordPageBreak = Node.create({
    name: 'pageBreak',
    group: 'block',
    atom: true,
    selectable: true,
    draggable: true,

    parseHTML() {
        return [
            { tag: 'div.mou-page-break' },
            { tag: 'hr.mou-page-break' },
            { tag: '[data-page-break="true"]' },
        ];
    },

    renderHTML() {
        return [
            'div',
            {
                class: 'mou-page-break',
                'data-page-break': 'true',
                style: 'page-break-after: always; break-after: page;',
            },
        ];
    },

    addCommands() {
        return {
            insertPageBreak: () => ({ commands }) => {
                return commands.insertContent([
                    { type: this.name },
                    { type: 'paragraph' },
                ]);
            },
        };
    },
});

/**
 * Custom TextBox Node (<div class="mou-textbox" data-textbox="true">...</div>)
 */
const WordTextBox = Node.create({
    name: 'textBox',
    group: 'block',
    content: 'block+',
    defining: true,

    addAttributes() {
        return {
            width: {
                default: '100%',
                parseHTML: el => el.getAttribute('data-width') || el.style.width || '100%',
            },
            align: {
                default: 'left',
                parseHTML: el => el.getAttribute('data-align') || 'left',
            },
            borderStyle: {
                default: 'solid',
                parseHTML: el => el.getAttribute('data-border-style') || 'solid',
            },
            backgroundColor: {
                default: '#ffffff',
                parseHTML: el => el.getAttribute('data-bg-color') || el.style.backgroundColor || '#ffffff',
            },
        };
    },

    parseHTML() {
        return [
            { tag: 'div.mou-textbox' },
            { tag: 'div[data-textbox="true"]' },
        ];
    },

    renderHTML({ node }) {
        const w = node.attrs.width || '100%';
        const align = node.attrs.align || 'left';
        const bStyle = node.attrs.borderStyle || 'solid';
        const bg = node.attrs.backgroundColor || '#ffffff';

        const styles = [
            `width: ${w}`,
            `border: ${bStyle === 'none' ? 'none' : `1px ${bStyle} #000000`}`,
            `background-color: ${bg}`,
            'padding: 8pt 10pt',
            'margin: 6pt 0',
            'box-sizing: border-box',
        ];
        if (align === 'center') {
            styles.push('margin-left: auto', 'margin-right: auto');
        } else if (align === 'right') {
            styles.push('margin-left: auto', 'margin-right: 0');
        }

        return [
            'div',
            {
                class: 'mou-textbox',
                'data-textbox': 'true',
                'data-width': w,
                'data-align': align,
                'data-border-style': bStyle,
                'data-bg-color': bg,
                style: styles.join('; ') + ';',
            },
            0,
        ];
    },
});

/**
 * Signature & Stamp Overlapping Block (<div class="signature-block" data-signature-block="true" style="...">)
 * Provides the relative positioning container for wet-ink seal stamps overlapping signatures and signer names.
 */
const WordSignatureBlock = Node.create({
    name: 'signatureBlock',
    group: 'block',
    content: 'inline*',
    defining: true,
    isolating: true,

    addAttributes() {
        return {
            style: {
                default: 'height: 75px; margin: 8px 0; position: relative;',
                parseHTML: el => el.getAttribute('style') || 'height: 75px; margin: 8px 0; position: relative;',
            },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'div',
                getAttrs: (el) => {
                    if (
                        el.hasAttribute('data-mou-page') || 
                        el.classList.contains('mou-a4-page-node') || 
                        el.classList.contains('mou-a4-paper-sheet') || 
                        el.classList.contains('mou-document') ||
                        !el.closest('table')
                    ) {
                        return false;
                    }
                    const isSigBlock = el.classList.contains('signature-block') || el.hasAttribute('data-signature-block');
                    if (isSigBlock) {
                        return { style: el.getAttribute('style') || 'height: 75px; margin: 8px 0; position: relative;' };
                    }
                    const style = el.getAttribute('style') || '';
                    const isRelative = style.includes('position: relative') || style.includes('position:relative');
                    if (isRelative) {
                        const directImgs = Array.from(el.querySelectorAll(':scope > img, :scope > span > img'));
                        const hasDirectStamp = directImgs.some(img => /stamp/i.test(img.getAttribute('src') || '') || /stempel|stamp/i.test(img.getAttribute('alt') || ''));
                        const hasDirectSig = directImgs.some(img => /signature/i.test(img.getAttribute('src') || '') || /tanda.?tangan|signature/i.test(img.getAttribute('alt') || ''));
                        if (hasDirectStamp || hasDirectSig) {
                            return { style: el.getAttribute('style') || 'height: 75px; margin: 8px 0; position: relative;' };
                        }
                    }
                    return false;
                },
            },
            {
                tag: 'div[data-signature-block="true"]',
                getAttrs: el => {
                    if (el.hasAttribute('data-mou-page') || el.classList.contains('mou-a4-page-node')) return false;
                    return { style: el.getAttribute('style') || 'height: 75px; margin: 8px 0; position: relative;' };
                },
            },
            {
                tag: 'div.signature-block',
                getAttrs: el => {
                    if (el.hasAttribute('data-mou-page') || el.classList.contains('mou-a4-page-node')) return false;
                    return { style: el.getAttribute('style') || 'height: 75px; margin: 8px 0; position: relative;' };
                },
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }) {
        const style = node.attrs.style || 'height: 75px; margin: 8px 0; position: relative;';
        return [
            'div',
            mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
                style,
                class: 'signature-block',
                'data-signature-block': 'true',
            }),
            0,
        ];
    },
});

/**
 * Normalize legacy V1/V2 HTML (such as .mou-pasal wrappers) into clean free-form HTML
 * before feeding into Tiptap so all historical templates open cleanly.
 */
function normalizeLegacyHtmlForTiptap(rawHtml) {
    if (!rawHtml || typeof rawHtml !== 'string') return '<p></p>';
    const parser = new DOMParser();
    const doc = parser.parseFromString(`<div id="root">${rawHtml}</div>`, 'text/html');
    const root = doc.getElementById('root');
    if (!root) return rawHtml;

    // Unwrap outer .mou-document wrapper if present
    const outerDoc = root.querySelector(':scope > .mou-document');
    if (outerDoc && root.children.length === 1) {
        root.innerHTML = outerDoc.innerHTML;
    }

    // Unwrap legacy .mou-pasal, .mou-pasal-heading, .mou-pasal-body containers
    root.querySelectorAll('.mou-pasal, .mou-pasal-body, .mou-pasal-heading, .mou-group, .mou-card, .mou-box, .mou-section, .mou-content-block, .mou-textbox').forEach(wrapper => {
        const parent = wrapper.parentNode;
        if (!parent) return;
        while (wrapper.firstChild) {
            parent.insertBefore(wrapper.firstChild, wrapper);
        }
        parent.removeChild(wrapper);
    });

    // Ensure tables with colgroup or cell widths have explicit attributes for Tiptap
    root.querySelectorAll('table').forEach(table => {
        // Elevate bare / imported tables to standard bordered correspondence tables
        if (!table.className || (!table.classList.contains('mou-table-bordered') && !table.classList.contains('mou-table-borderless') && !table.classList.contains('mou-correspondence-table') && !table.classList.contains('mou-paraf-table'))) {
            table.classList.add('mou-table-bordered');
            table.classList.add('mou-correspondence-table');
        }

        const tWidth = table.getAttribute('data-table-width') || table.style.width || '100%';
        table.setAttribute('data-table-width', tWidth);
        table.style.width = tWidth;
        table.style.borderCollapse = 'collapse';
        table.style.tableLayout = 'fixed';

        const tAlign = table.getAttribute('data-table-align') || 'center';
        table.setAttribute('data-table-align', tAlign);
        if (tAlign === 'center') {
            table.style.marginLeft = 'auto';
            table.style.marginRight = 'auto';
        }

        table.querySelectorAll('tr').forEach(tr => {
            const rHeight = tr.getAttribute('data-row-height') || tr.style.height;
            if (rHeight) {
                tr.setAttribute('data-row-height', rHeight);
                tr.style.height = rHeight;
            }
        });

        table.querySelectorAll('td, th').forEach(cell => {
            if (!cell.style.padding) {
                cell.style.padding = '5pt 7pt';
            }
            if (!cell.style.border) {
                cell.style.border = '1px solid #000000';
            }
        });
    });

    // Auto-balance 2-column kop surat table (logo kiri + teks tengah) by adding matching 3rd empty column
    // so the middle column text (company name, address) is geometrically and visually perfectly centered on the A4 sheet.
    root.querySelectorAll('table').forEach(table => {
        const rows = Array.from(table.querySelectorAll('tr'));
        if (rows.length === 1) {
            const cells = Array.from(rows[0].children);
            if (cells.length === 2 && cells[0].querySelector('img')) {
                const w1 = cells[0].style.width || cells[0].getAttribute('width') || '110px';
                cells[0].style.width = w1;
                cells[0].style.textAlign = 'left';
                const thirdCell = doc.createElement('td');
                thirdCell.style.width = w1;
                thirdCell.style.verticalAlign = 'middle';
                thirdCell.innerHTML = '<p></p>';
                rows[0].appendChild(thirdCell);
            }
        }
    });

    // Convert any paragraph inside a table cell containing both signature and stamp into a .signature-block div
    root.querySelectorAll('td p, th p').forEach(p => {
        const directImgs = Array.from(p.querySelectorAll(':scope > img, :scope > span > img'));
        const hasStamp = directImgs.some(img => /stamp/i.test(img.getAttribute('src') || '') || /stempel|stamp/i.test(img.getAttribute('alt') || ''));
        const hasSig = directImgs.some(img => /signature/i.test(img.getAttribute('src') || '') || /tanda.?tangan|signature/i.test(img.getAttribute('alt') || ''));
        if (hasStamp && hasSig) {
            const sigDiv = doc.createElement('div');
            sigDiv.className = 'signature-block';
            sigDiv.setAttribute('data-signature-block', 'true');
            sigDiv.setAttribute('style', 'height: 75px; margin: 8px 0; position: relative;');
            while (p.firstChild) {
                sigDiv.appendChild(p.firstChild);
            }
            p.parentNode.replaceChild(sigDiv, p);
        }
    });

    // Ensure signature container divs inside table cells have standard class and relative positioning
    root.querySelectorAll('td div, th div').forEach(div => {
        const style = div.getAttribute('style') || '';
        const isRelative = style.includes('position: relative') || style.includes('position:relative');
        const directImgs = Array.from(div.querySelectorAll(':scope > img, :scope > span > img'));
        const hasStamp = directImgs.some(img => /stamp/i.test(img.getAttribute('src') || '') || /stempel|stamp/i.test(img.getAttribute('alt') || ''));
        const hasSig = directImgs.some(img => /signature/i.test(img.getAttribute('src') || '') || /tanda.?tangan|signature/i.test(img.getAttribute('alt') || ''));
        if (div.classList.contains('signature-block') || (isRelative && (hasStamp || hasSig))) {
            div.classList.add('signature-block');
            div.setAttribute('data-signature-block', 'true');
            div.setAttribute('style', 'height: 75px; margin: 8px 0; position: relative;');
        }
    });

    // Substitute asset placeholder URLs for img elements so they render natively in Tiptap
    root.querySelectorAll('img').forEach(img => {
        const src = img.getAttribute('src') || '';
        const alt = img.getAttribute('alt') || '';
        if (/\{\{\s*asset\.(?:brand|company)\.logo\s*\}\}|\[\s*(?:asset\.(?:brand|company)\.logo|company_logo)\s*\]|\{\{\s*company_logo\s*\}\}/i.test(src)) {
            img.setAttribute('src', '/images/mou/seven-inc-logo.png');
            if (!img.style.width && !img.getAttribute('width')) {
                img.style.width = '95px';
            }
        } else if (/\{\{\s*asset\.(?:brand\.)?tanda_tangan\s*\}\}|\[\s*signature\s*\]|\{\{\s*asset\.signature\s*\}\}/i.test(src) || /tanda.?tangan/i.test(alt)) {
            img.setAttribute('src', '/images/mou/signature-sample.png');
            if (!img.style.height && !img.getAttribute('height')) {
                img.style.height = '65px';
            }
            if (!img.style.width && !img.getAttribute('width')) {
                img.style.width = 'auto';
            }
            img.style.position = 'relative';
            img.style.zIndex = '10';
        } else if (/\{\{\s*asset\.(?:brand\.)?stempel\s*\}\}|\[\s*stamp\s*\]|\{\{\s*asset\.stamp\s*\}\}/i.test(src) || /stempel|stamp/i.test(alt)) {
            img.setAttribute('src', '/images/mou/stamp-sample.png');
            img.style.position = 'absolute';
            img.style.left = '12%';
            img.style.top = '-10px';
            img.style.width = '125px';
            img.style.height = '125px';
            img.style.opacity = '0.78';
            img.style.zIndex = '20';
            img.style.pointerEvents = 'none';
        }
    });

    const res = root.innerHTML.trim();
    return res !== '' ? res : '<p></p>';
}

/**
 * Ensure raw HTML is wrapped in a mouPage container (<div data-mou-page="true">)
 * so ProseMirror parses it directly into top-level mouPage nodes.
 */
function normalizeHtmlForBodyEditor(rawHtml) {
    const normalized = normalizeLegacyHtmlForTiptap(rawHtml);
    if (!normalized || normalized.trim() === '' || normalized === '<p></p>') {
        return '<div data-mou-page="true" class="mou-a4-page-node"><p></p></div>';
    }
    if (normalized.includes('data-mou-page') || normalized.includes('mou-a4-page-node') || normalized.includes('mou-a4-page')) {
        return normalized;
    }
    return `<div data-mou-page="true" class="mou-a4-page-node">${normalized}</div>`;
}

/**
 * Ensure JSON content has mouPage nodes as its direct children under 'doc'.
 */
function ensureJsonHasMouPage(json) {
    if (!json || typeof json !== 'object') return json;
    if (json.type === 'doc' && Array.isArray(json.content)) {
        if (json.content.length > 0 && json.content[0].type === 'mouPage') {
            return json;
        }
        return {
            type: 'doc',
            content: [
                {
                    type: 'mouPage',
                    attrs: { pageNumber: 1 },
                    content: json.content,
                },
            ],
        };
    }
    return json;
}

/**
 * Post-process serialized HTML from Tiptap to guarantee 100% synchronization
 * between ProseMirror attributes (colwidth, data-row-height, data-table-width)
 * and inline CSS styles for round-trip persistence and DomPDF compatibility.
 */
function synchronizeSerializedHtml(rawHtml) {
    if (!rawHtml || typeof rawHtml !== 'string') return '';
    const parser = new DOMParser();
    const doc = parser.parseFromString(`<div id="sync-root">${rawHtml}</div>`, 'text/html');
    const root = doc.getElementById('sync-root');
    if (!root) return rawHtml;

    root.querySelectorAll('table').forEach(table => {
        const tWidth = table.getAttribute('data-table-width') || table.style.width || '100%';
        table.setAttribute('data-table-width', tWidth);
        table.style.width = tWidth;
        table.style.borderCollapse = 'collapse';
        table.style.tableLayout = 'fixed';

        // Synchronize colgroup & first row cells
        const firstRow = table.querySelector('tr');
        const cols = Array.from(table.querySelectorAll('colgroup > col'));

        table.querySelectorAll('tr').forEach(tr => {
            let rowH = tr.getAttribute('data-row-height') || tr.style.height || '';
            if (!rowH) {
                // Check if any cell in this row has data-row-height
                const cellWithH = tr.querySelector('[data-row-height]');
                if (cellWithH) rowH = cellWithH.getAttribute('data-row-height') || '';
            }
            if (rowH) {
                tr.setAttribute('data-row-height', rowH);
                tr.style.height = rowH;
            }

            let colCursor = 0;
            Array.from(tr.children).forEach(cell => {
                if (cell.tagName !== 'TD' && cell.tagName !== 'TH') return;
                cell.style.wordBreak = 'break-word';
                cell.style.overflowWrap = 'break-word';
                const colspan = parseInt(cell.getAttribute('colspan') || '1', 10) || 1;

                if (rowH) {
                    cell.setAttribute('data-row-height', rowH);
                    if (!cell.style.height) {
                        cell.style.height = rowH;
                    }
                }

                const cwAttr = cell.getAttribute('colwidth');
                if (cwAttr) {
                    const widths = cwAttr.split(',').map(s => parseInt(s.trim(), 10)).filter(n => !isNaN(n) && n > 0);
                    if (widths.length > 0) {
                        const totalPx = widths.reduce((a, b) => a + b, 0);
                        cell.setAttribute('data-cell-width', `${totalPx}px`);
                        cell.style.width = `${totalPx}px`;
                        widths.forEach((w, idx) => {
                            const targetCol = cols[colCursor + idx];
                            if (targetCol) {
                                targetCol.setAttribute('width', String(w));
                                targetCol.style.width = `${w}px`;
                            }
                        });
                    }
                } else if (cell.getAttribute('data-cell-width') || cell.style.width) {
                    const wVal = cell.getAttribute('data-cell-width') || cell.style.width;
                    cell.setAttribute('data-cell-width', wVal);
                    cell.style.width = wVal;
                    if (/^\d+(\.\d+)?px$/i.test(wVal.trim()) && colspan === 1) {
                        const pxInt = Math.round(parseFloat(wVal));
                        cell.setAttribute('colwidth', String(pxInt));
                        if (cols[colCursor]) {
                            cols[colCursor].setAttribute('width', String(pxInt));
                            cols[colCursor].style.width = `${pxInt}px`;
                        }
                    }
                }
                colCursor += colspan;
            });
        });

        // If table has no colgroup yet but firstRow cells have colwidth, create colgroup
        if (cols.length === 0 && firstRow) {
            const newColgroup = doc.createElement('colgroup');
            let hasAnyWidth = false;
            Array.from(firstRow.children).forEach(cell => {
                const colspan = parseInt(cell.getAttribute('colspan') || '1', 10) || 1;
                const cw = cell.getAttribute('colwidth');
                const widths = cw ? cw.split(',').map(s => parseInt(s.trim(), 10)) : [];
                for (let i = 0; i < colspan; i++) {
                    const col = doc.createElement('col');
                    const w = widths[i];
                    if (w && !isNaN(w) && w > 0) {
                        col.setAttribute('width', String(w));
                        col.style.width = `${w}px`;
                        hasAnyWidth = true;
                    }
                    newColgroup.appendChild(col);
                }
            });
            if (hasAnyWidth) {
                table.insertBefore(newColgroup, table.firstChild);
            }
        }
    });

    // Strip temporary pagination artifacts
    root.querySelectorAll('[data-pagination-artifact="true"], .mou-a4-page-header, .mou-a4-page-footer, .mou-table-width-handle, .no-print').forEach(el => el.remove());

    // Unwrap page containers so output is pure canonical HTML (preserving explicit user page breaks)
    root.querySelectorAll('.mou-a4-paper-sheet, .mou-a4-page-node, [data-mou-page], .mou-page-body-content').forEach(wrap => {
        const parent = wrap.parentNode;
        if (!parent) return;
        while (wrap.firstChild) {
            parent.insertBefore(wrap.firstChild, wrap);
        }
        parent.removeChild(wrap);
    });

    return root.innerHTML.trim();
}

/**
 * Shared indentation logic for both UI Indent/Outdent buttons and Tab/Shift-Tab keyboard events.
 * Handles:
 * - Selected text across multiple paragraphs or headings (increments/decrements each block's marginLeft).
 * - Single paragraph/heading (collapsed cursor or single block selection).
 * - Ordered and bullet lists (sinks nested sub-items with automatic numbering style progression e.g. I -> 1 -> a,
 *   or shifts top-level list marginLeft without destroying the list structure).
 */
function executeEditorIndent(ed, deltaMm) {
    if (!ed) return false;
    const { state: editorState, view } = ed;
    const { selection, doc } = editorState;
    const { $from, from, to } = selection;

    // 1. Inside a list item (orderedList, bulletList, or listItem)
    if (ed.isActive('listItem')) {
        let listItemDepthCount = 0;
        let nearestListDepth = -1;
        let parentOlType = '1';

        for (let d = $from.depth; d > 0; d--) {
            const n = $from.node(d);
            if (n.type.name === 'listItem') {
                listItemDepthCount++;
            }
            if ((n.type.name === 'orderedList' || n.type.name === 'bulletList') && nearestListDepth === -1) {
                nearestListDepth = d;
                if (n.type.name === 'orderedList') {
                    parentOlType = n.attrs.listType || n.attrs.type || '1';
                }
            }
        }

        if (deltaMm > 0) {
            // INCREASE INDENT:
            const canSink = ed.can().sinkListItem('listItem');
            if (canSink) {
                ed.chain().focus().sinkListItem('listItem').run();
                try {
                    const newEdState = ed.state;
                    const cur$from = newEdState.selection.$from;

                    // Standard list progression: 1 -> a -> i -> 1 / I -> 1 -> a
                    let subType = 'a';
                    if (parentOlType === '1') subType = 'a';
                    else if (parentOlType === 'a') subType = 'i';
                    else if (parentOlType === 'A') subType = '1';
                    else if (parentOlType === 'i') subType = '1';
                    else if (parentOlType === 'I') subType = '1';

                    for (let d = cur$from.depth; d > 0; d--) {
                        const n = cur$from.node(d);
                        if (n.type.name === 'orderedList') {
                            const tr = newEdState.tr;
                            tr.setNodeMarkup(cur$from.before(d), undefined, {
                                ...n.attrs,
                                listType: subType,
                                type: subType,
                            });
                            ed.view.dispatch(tr);
                            break;
                        }
                    }
                } catch (_) {}
            } else if (nearestListDepth > 0) {
                // Cannot sink (first item of list): shift the whole list right via marginLeft
                const listNode = $from.node(nearestListDepth);
                const curMm = parseFloat(String(listNode.attrs.marginLeft || '0').replace('mm', '')) || 0;
                const nextMm = Math.min(80, curMm + deltaMm);
                const tr = editorState.tr;
                tr.setNodeMarkup($from.before(nearestListDepth), undefined, {
                    ...listNode.attrs,
                    marginLeft: nextMm > 0 ? `${nextMm}mm` : null,
                });
                view.dispatch(tr);
            }
            return true;
        } else {
            // DECREASE INDENT (deltaMm < 0):
            if (listItemDepthCount > 1) {
                // Nested sub-item: lift back to parent level
                ed.chain().focus().liftListItem('listItem').run();
                return true;
            } else if (nearestListDepth > 0) {
                // Top-level list item: DO NOT call liftListItem because that destroys the numbering!
                // Instead, reduce the list's marginLeft if it was indented
                const listNode = $from.node(nearestListDepth);
                const curMm = parseFloat(String(listNode.attrs.marginLeft || '0').replace('mm', '')) || 0;
                if (curMm > 0) {
                    const nextMm = Math.max(0, curMm + deltaMm);
                    const tr = editorState.tr;
                    tr.setNodeMarkup($from.before(nearestListDepth), undefined, {
                        ...listNode.attrs,
                        marginLeft: nextMm > 0 ? `${nextMm}mm` : null,
                    });
                    view.dispatch(tr);
                    return true;
                }
            }
            return false;
        }
    }

    // 2. Standard textblocks (paragraphs & headings outside lists)
    const tr = editorState.tr;
    let modified = false;

    doc.nodesBetween(from, to, (node, pos) => {
        if (node.isTextblock) {
            const $pos = doc.resolve(pos);
            let insideList = false;
            for (let d = $pos.depth; d > 0; d--) {
                if ($pos.node(d).type.name === 'listItem') {
                    insideList = true;
                    break;
                }
            }
            if (!insideList) {
                const curMm = parseFloat(String(node.attrs.marginLeft || '0').replace('mm', '')) || 0;
                const nextMm = Math.max(0, Math.min(80, curMm + deltaMm));
                const newMargin = nextMm > 0 ? `${nextMm}mm` : null;
                if ((node.attrs.marginLeft || null) !== newMargin) {
                    tr.setNodeMarkup(pos, undefined, {
                        ...node.attrs,
                        marginLeft: newMargin,
                    });
                    modified = true;
                }
            }
        }
    });

    if (modified) {
        tr.setSelection(selection.map(tr.doc, tr.mapping));
        view.dispatch(tr);
        return true;
    }

    return false;
}

/**
 * Extension providing Microsoft Word-style Tab key and Tabulation (tab stop) support.
 * - If text is selected (range selection across one or more paragraphs):
 *   Pressing Tab indents the selected text (+6mm); Shift-Tab outdents (-6mm).
 * - If inside a bullet or ordered list:
 *   Pressing Tab sinks/indents the list item; Shift-Tab lifts/outdents it.
 * - If cursor is inside a table cell without selection:
 *   Inserts a tab stop character '\t'.
 * - If collapsed cursor in regular paragraph:
 *   Inserts a tab stop character '\t' (Word tab stop).
 */
const WordTabExtension = Extension.create({
    name: 'wordTab',
    addKeyboardShortcuts() {
        return {
            'Tab': ({ editor }) => {
                if (editor.isActive('table') && editor.state.selection.empty) {
                    return editor.commands.insertContent('\t');
                }
                if (!editor.state.selection.empty) {
                    executeEditorIndent(editor, 6);
                    if (typeof window !== 'undefined' && window.MouWordProcessor) {
                        window.MouWordProcessor.scheduleSyncAndPagination?.();
                        window.MouWordProcessor.updateRulerMarkersFromEditor?.();
                        window.MouWordProcessor.scheduleReflow?.(true);
                    }
                    return true;
                }
                if (editor.isActive('listItem') || editor.isActive('bulletList') || editor.isActive('orderedList')) {
                    executeEditorIndent(editor, 6);
                    if (typeof window !== 'undefined' && window.MouWordProcessor) {
                        window.MouWordProcessor.scheduleSyncAndPagination?.();
                        window.MouWordProcessor.updateRulerMarkersFromEditor?.();
                        window.MouWordProcessor.scheduleReflow?.(true);
                    }
                    return true;
                }
                return editor.commands.insertContent('\t');
            },
            'Shift-Tab': ({ editor }) => {
                if (!editor.state.selection.empty) {
                    executeEditorIndent(editor, -6);
                    if (typeof window !== 'undefined' && window.MouWordProcessor) {
                        window.MouWordProcessor.scheduleSyncAndPagination?.();
                        window.MouWordProcessor.updateRulerMarkersFromEditor?.();
                        window.MouWordProcessor.scheduleReflow?.(true);
                    }
                    return true;
                }
                if (editor.isActive('listItem') || editor.isActive('bulletList') || editor.isActive('orderedList')) {
                    executeEditorIndent(editor, -6);
                    if (typeof window !== 'undefined' && window.MouWordProcessor) {
                        window.MouWordProcessor.scheduleSyncAndPagination?.();
                        window.MouWordProcessor.updateRulerMarkersFromEditor?.();
                        window.MouWordProcessor.scheduleReflow?.(true);
                    }
                    return true;
                }
                const attrs = editor.getAttributes('paragraph') || editor.getAttributes('heading') || {};
                const curMm = parseFloat(String(attrs.marginLeft || '0').replace('mm', '')) || 0;
                if (curMm > 0) {
                    executeEditorIndent(editor, -6);
                    if (typeof window !== 'undefined' && window.MouWordProcessor) {
                        window.MouWordProcessor.scheduleSyncAndPagination?.();
                        window.MouWordProcessor.updateRulerMarkersFromEditor?.();
                        window.MouWordProcessor.scheduleReflow?.(true);
                    }
                    return true;
                }
                return false;
            },
        };
    },
});

/**
 * Unified Keyboard Undo/Redo Keymap Extension for all Word editors:
 * Seamlessly integrates asset transformations (logo resize, move, rotate)
 * with document text editing into standard Ctrl+Z and Ctrl+Y shortcuts.
 */
const UnifiedUndoKeymap = Extension.create({
    name: 'unifiedUndoKeymap',
    addKeyboardShortcuts() {
        return {
            'Mod-z': () => {
                if (typeof window._mouGlobalUndoHandler === 'function') {
                    return window._mouGlobalUndoHandler();
                }
                return false;
            },
            'Mod-y': () => {
                if (typeof window._mouGlobalRedoHandler === 'function') {
                    return window._mouGlobalRedoHandler();
                }
                return false;
            },
            'Mod-Shift-z': () => {
                if (typeof window._mouGlobalRedoHandler === 'function') {
                    return window._mouGlobalRedoHandler();
                }
                return false;
            },
        };
    },
});

/**
 * Create the standard Tiptap extension list for Body, Header, and Footer editors.
 */
function createWordExtensions(placeholderText = 'Ketik isi dokumen di sini...') {
    return [
        StarterKit.configure({
            paragraph: false,
            heading: false,
            orderedList: false,
            bulletList: false,
            listItem: false,
            listKeymap: false,
            link: {
                openOnClick: false,
                HTMLAttributes: {
                    rel: 'noopener noreferrer',
                },
            },
        }),
        UnifiedUndoKeymap,
        WordParagraph,
        WordHeading.configure({ levels: [1, 2, 3, 4] }),
        WordBulletList,
        WordOrderedList,
        ListItem,
        ListKeymap,
        WordTabExtension,
        TextStyle,
        FontFamily,
        FontSize,
        Color,
        BackgroundColor,
        LineHeight,
        TextAlign.configure({
            types: ['heading', 'paragraph', 'tableCell', 'tableHeader'],
            alignments: ['left', 'center', 'right', 'justify'],
        }),
        Highlight.configure({ multicolor: true }),
        Underline,
        Subscript,
        Superscript,
        WordImage,
        WordTable,
        WordTableRow,
        WordTableCell,
        WordTableHeader,
        WordPageBreak,
        WordTextBox,
        WordSignatureBlock,
        Placeholder.configure({
            placeholder: placeholderText,
        }),
        UnifiedUndoKeymap,
    ];
}

/**
 * Top-level Document Node for Body Editor:
 * In a true A4 multi-page document model, 'doc' contains one or more 'mouPage' nodes.
 */
const WordDocument = Node.create({
    name: 'doc',
    topNode: true,
    content: 'mouPage+',
});

/**
 * Renders the watermark and fixed header logo HTML layer for a page.
 * - For MOU: renders institution logo (left) and company logo (right)
 * - For LOA and official letters: renders ONLY 1 company logo on the left
 * - Center watermark (company logo) is rendered if showWatermark is true
 */
function renderWatermarkLayerHtml(state, isFirstPage) {
    if (!state) return '';
    const showWatermark = state.showWatermark !== undefined ? Boolean(state.showWatermark) : true;
    let watermarkHtml = '';

    // Watermark Tengah: tepat di tengah lembar kertas, tidak menggeser teks naskah
    if (showWatermark) {
        const wmUrl = state.mediaAssets?.company_logo || state.mediaAssets?.company_logo_url || '/images/mou/seven-inc-logo.png';
        const wmOpacity = state.watermarkOpacity ?? 0.05;
        watermarkHtml += `
            <div class="mou-watermark-center-wrapper">
                <img src="${wmUrl}" class="mou-watermark-center" style="opacity: ${wmOpacity};" alt="Watermark" />
            </div>
        `;
    }

    return watermarkHtml;
}

/**
 * Custom PageNode View representing a single physical A4 paper sheet in Print Layout mode.
 * - Sized exactly to A4 (210mm x 297mm)
 * - Renders live Header slot, ProseMirror contentDOM (editable body), and Footer slot (Kotak Paraf & page number)
 * - Separated by 24px desk gap on the workspace
 */
class MouPageView {
    constructor(node, editor, getPos) {
        this.node = node;
        this.editor = editor;
        this.getPos = getPos;

        this.dom = document.createElement('div');
        this.dom.className = 'mou-a4-paper-sheet mou-a4-page-node';
        this.dom.setAttribute('data-mou-page', 'true');

        this.watermarkEl = document.createElement('div');
        this.watermarkEl.className = 'mou-page-watermark-layer';
        this.watermarkEl.setAttribute('data-pagination-artifact', 'true');
        this.watermarkEl.setAttribute('contenteditable', 'false');
        this.dom.appendChild(this.watermarkEl);

        this.headerEl = document.createElement('div');
        this.headerEl.className = 'mou-a4-page-header';
        this.headerEl.setAttribute('data-pagination-artifact', 'true');
        this.headerEl.setAttribute('contenteditable', 'false');
        this.headerEl.title = 'Klik ganda (Double-Click) untuk mengedit Header dokumen';
        this.dom.appendChild(this.headerEl);

        this.contentDOM = document.createElement('div');
        this.contentDOM.className = 'mou-page-body-content mou-doc-canvas';
        this.dom.appendChild(this.contentDOM);

        this.footerEl = document.createElement('div');
        this.footerEl.className = 'mou-a4-page-footer';
        this.footerEl.setAttribute('data-pagination-artifact', 'true');
        this.footerEl.setAttribute('contenteditable', 'false');
        this.footerEl.title = 'Klik ganda (Double-Click) untuk mengedit Footer / Kotak Paraf';
        this.dom.appendChild(this.footerEl);

        this.headerLogosLayer = document.createElement('div');
        this.headerLogosLayer.className = 'mou-header-logos-interactive-stage no-print';
        this.headerLogosLayer.setAttribute('data-pagination-artifact', 'true');
        this.headerLogosLayer.setAttribute('contenteditable', 'false');
        this.dom.appendChild(this.headerLogosLayer);

        this.attachEventListeners();
        this.updateAppearance();
    }

    get mouContext() {
        return this.editor?.mouContext || {};
    }

    getPageIndex() {
        try {
            if (typeof this.getPos === 'function') {
                const pos = this.getPos();
                if (pos !== undefined && this.editor?.state?.doc) {
                    const $pos = this.editor.state.doc.resolve(pos);
                    return $pos.index(0);
                }
            }
        } catch (e) {
            // fallback
        }
        return (this.node.attrs?.pageNumber || 1) - 1;
    }

    getTotalPages() {
        return this.editor?.state?.doc?.childCount || 1;
    }

    updateAppearance() {
        const { state, getPaperDimensionsMm } = this.mouContext;
        if (!state) return;

        const pageIdx = this.getPageIndex();
        const pageNum = pageIdx + 1;
        const totalPages = this.getTotalPages();

        this.dom.setAttribute('data-page-number', String(pageNum));

        const dims = getPaperDimensionsMm ? getPaperDimensionsMm() : { width: 210, height: 297 };
        this.dom.style.width = `${dims.width}mm`;
        this.dom.style.minWidth = `${dims.width}mm`;
        this.dom.style.maxWidth = `${dims.width}mm`;
        this.dom.style.height = `${dims.height}mm`;
        this.dom.style.minHeight = `${dims.height}mm`;
        this.dom.style.maxHeight = `${dims.height}mm`;
        this.dom.style.boxSizing = 'border-box';
        this.dom.style.paddingLeft = `${state.marginLeftMm}mm`;
        this.dom.style.paddingRight = `${state.marginRightMm}mm`;
        this.dom.style.paddingTop = `${Math.min(state.headerDistanceMm, state.marginTopMm)}mm`;
        this.dom.style.paddingBottom = `${Math.min(state.footerDistanceMm, state.marginBottomMm)}mm`;
        this.dom.style.fontFamily = `'${state.fontFamily}', Times, serif`;
        this.dom.style.fontSize = `${state.fontSizePt}pt`;
        this.dom.style.lineHeight = String(state.lineHeight);
        this.dom.style.position = 'relative';

        const isFirstPage = pageNum === 1;

        // Render Watermark & Fixed Header Logo Layer (stays pinned, does not flow with text)
        if (!this.watermarkEl) {
            this.watermarkEl = document.createElement('div');
            this.watermarkEl.className = 'mou-page-watermark-layer';
            this.watermarkEl.setAttribute('data-pagination-artifact', 'true');
            this.watermarkEl.setAttribute('contenteditable', 'false');
            this.dom.insertBefore(this.watermarkEl, this.dom.firstChild);
        }

        this.watermarkEl.innerHTML = renderWatermarkLayerHtml(state, isFirstPage);
        this.renderInteractiveHeaderLogos(isFirstPage);

        const headerHtml = (state.differentFirstPage && isFirstPage)
            ? (state.firstPageHeaderHtmlStore || '')
            : (state.headerHtmlStore || '');
        const footerHtml = (state.differentFirstPage && isFirstPage)
            ? (state.firstPageFooterHtmlStore || '')
            : (state.footerHtmlStore || '');

        const isMou = (state.documentType === 'mou');
        const showLogos = isMou && isFirstPage && (state.showHeaderLogos !== false);

        // Bounded header space (MS Word standard Kop Surat boundary: 28mm, max 35mm)
        const requiredHeaderHeightMm = showLogos ? 28.0 : 14.0;

        this.headerEl.style.minHeight = `${requiredHeaderHeightMm}mm`;
        this.headerEl.style.maxHeight = '35mm';
        const bodyTopGapMm = Math.max(2, state.marginTopMm - state.headerDistanceMm);
        this.headerEl.style.marginBottom = `${bodyTopGapMm}mm`;

        const headerWrapper = document.getElementById('mouHeaderZoneWrapper');
        if (headerWrapper) {
            headerWrapper.style.minHeight = `${requiredHeaderHeightMm}mm`;
            headerWrapper.style.maxHeight = '35mm';
        }
        if (!this.headerEl.contains(headerWrapper)) {
            if (isMou && showLogos) {
                // Dokumen MoU: Area Header murni untuk wadah aset logo kop surat (bersih tanpa teks placeholder)
                this.headerEl.innerHTML = headerHtml || '';
            } else {
                this.headerEl.innerHTML = headerHtml || `
                    <div class="mou-header-empty-indicator text-slate-400 italic text-[10px] py-0.5 select-none text-center">
                        <span class="inline-block px-2.5 py-0.5 rounded border border-dashed border-slate-300 bg-slate-50/80 text-slate-400 font-sans">
                            (Area Header Kosong &bull; Klik Ganda untuk Menulis Kop)
                        </span>
                    </div>
                `;
            }
        }

        const footerWrapper = document.getElementById('mouFooterZoneWrapper');
        if (!this.footerEl.contains(footerWrapper)) {
            const pageNumberHtml = state.showPageNumber
                ? `<div class="font-serif text-[9pt] text-black pb-0.5 shrink-0 select-none text-right whitespace-nowrap">Halaman ${pageNum} dari ${totalPages}</div>`
                : '';
            this.footerEl.innerHTML = `
                <div class="mou-footer-content-row flex items-end justify-between gap-4 w-full">
                    <div class="mou-footer-paraf-area flex-1 min-w-0" style="visibility: ${state.showParafBox ? 'visible' : 'hidden'}">
                        ${footerHtml}
                    </div>
                    ${pageNumberHtml}
                </div>
            `;
            const bodyBtmGapMm = Math.max(2, state.marginBottomMm - state.footerDistanceMm);
            this.footerEl.style.marginTop = `${bodyBtmGapMm}mm`;
        }

        if (state.activeZone === 'header' || state.activeZone === 'footer') {
            this.contentDOM.classList.add('mou-zone-dimmed');
        } else {
            this.contentDOM.classList.remove('mou-zone-dimmed');
        }

        if (this.headerLogosLayer) {
            if (state.activeZone === 'header') {
                this.headerLogosLayer.classList.add('is-header-active');
            } else {
                this.headerLogosLayer.classList.remove('is-header-active');
            }
        }
    }

    attachEventListeners() {
        const activateFooter = (e) => {
            e.stopPropagation();
            const { setActiveZone } = this.mouContext;
            const pageNum = this.getPageIndex() + 1;
            if (setActiveZone) setActiveZone('footer', pageNum);
        };
        this.footerEl.addEventListener('click', activateFooter);
        this.footerEl.addEventListener('dblclick', activateFooter);

        const activateHeader = (e) => {
            e.stopPropagation();
            const { setActiveZone } = this.mouContext;
            const pageNum = this.getPageIndex() + 1;
            if (setActiveZone) setActiveZone('header', pageNum);
        };
        this.headerEl.addEventListener('dblclick', activateHeader);

        const returnToBody = (e) => {
            const { state, setActiveZone } = this.mouContext;
            if (state && state.activeZone !== 'body' && setActiveZone) {
                e.stopPropagation();
                setActiveZone('body');
            }
        };
        this.contentDOM.addEventListener('click', returnToBody);
        this.contentDOM.addEventListener('dblclick', returnToBody);
    }

    renderInteractiveHeaderLogos(isFirstPage) {
        if (!this.headerLogosLayer) return;
        const {
            state,
            getPaperDimensionsMm,
            syncAllToHiddenInputs,
            pushAssetHistory,
            evaluateSmartGuides,
            showSmartGuides,
            hideSmartGuides,
            showRulerObjectRange,
            hideRulerObjectRange,
        } = this.mouContext;
        const isMou = (state?.documentType === 'mou');
        const showLogos = isMou && isFirstPage && (state?.showHeaderLogos !== false);

        if (!showLogos) {
            this.headerLogosLayer.innerHTML = '';
            this.headerLogosLayer.style.display = 'none';
            return;
        }

        this.headerLogosLayer.style.display = 'block';

        if (!state.headerLogosConfig) {
            state.headerLogosConfig = {
                institution: { width: 66, height: 66, rotation: 0, topOffsetMm: 0, leftOffsetMm: 0 },
                company: { width: 66, height: 66, rotation: 0, topOffsetMm: 0, rightOffsetMm: 0 },
            };
        }

        if (this.headerLogosLayer.dataset.mounted === 'true') {
            if (state?.activeZone === 'header') {
                this.headerLogosLayer.classList.add('is-header-active');
            } else {
                this.headerLogosLayer.classList.remove('is-header-active');
            }
            return;
        }

        this.headerLogosLayer.innerHTML = '';
        this.headerLogosLayer.dataset.mounted = 'true';
        if (state?.activeZone === 'header') {
            this.headerLogosLayer.classList.add('is-header-active');
        } else {
            this.headerLogosLayer.classList.remove('is-header-active');
        }

        const topHeaderMm = Math.max(8, (state.marginTopMm || 25.4) - 10);
        const compLogoUrl = state.mediaAssets?.company_logo || state.mediaAssets?.company_logo_url || '/images/mou/seven-inc-logo.png';
        const instLogoUrl = state.mediaAssets?.institution_logo || state.mediaAssets?.institution_logo_url || '/images/mou/institution-logo-placeholder.png';

        const createLogoBox = (slot, title, defaultSide, initialSrc) => {
            const cfg = state.headerLogosConfig[slot] = state.headerLogosConfig[slot] || {
                width: 66, height: 66, rotation: 0, topOffsetMm: 0,
                ...(defaultSide === 'left' ? { leftOffsetMm: 0 } : { rightOffsetMm: 0 })
            };

            const box = document.createElement('div');
            box.className = `mou-header-logo-interactive-box mou-logo-box-${defaultSide}`;
            box.setAttribute('data-logo-slot', slot);
            box.setAttribute('draggable', 'false');
            box.style.touchAction = 'none';
            box.style.userSelect = 'none';
            box.title = `${title} • Klik untuk mengedit, drag untuk menggeser, putar, atau ubah ukuran`;

            const img = document.createElement('img');
            img.src = initialSrc;
            img.alt = title;
            img.className = 'mou-logo-img-el';
            img.setAttribute('draggable', 'false');
            img.draggable = false;
            img.ondragstart = (e) => e.preventDefault();
            box.ondragstart = (e) => e.preventDefault();
            box.appendChild(img);

            const overlay = document.createElement('div');
            overlay.className = 'mou-image-handle-overlay no-print';
            overlay.style.display = 'none';
            box.appendChild(overlay);

            // Rotator handle
            const rotWrap = document.createElement('div');
            rotWrap.className = 'mou-image-rotator-wrapper';
            const rotLine = document.createElement('div');
            rotLine.className = 'mou-image-rotator-line';
            const rotKnob = document.createElement('div');
            rotKnob.className = 'mou-image-rotator-handle';
            rotKnob.title = 'Tarik untuk memutar logo • Klik ganda untuk 0°';
            rotWrap.appendChild(rotLine);
            rotWrap.appendChild(rotKnob);
            overlay.appendChild(rotWrap);

            // Sizing handles (8 directions)
            const handles = {};
            ['nw', 'ne', 'se', 'sw', 'n', 's', 'e', 'w'].forEach(pos => {
                const h = document.createElement('div');
                h.className = `mou-image-handle ${pos}`;
                h.setAttribute('data-handle-pos', pos);
                overlay.appendChild(h);
                handles[pos] = h;
            });

            // Quick floating bar
            const quickBar = document.createElement('div');
            quickBar.className = 'mou-header-logo-quick-bar';
            quickBar.innerHTML = `
                <span class="font-bold text-[9px] mr-1 text-slate-300">${title}</span>
                <span class="mou-header-logo-stat text-amber-300 font-mono text-[9px] mr-1"></span>
                <button type="button" class="btn-quick-rot" title="Putar 90 Derajat">&#8635; 90&deg;</button>
                <button type="button" class="btn-quick-reset" title="Kembalikan ukuran dan posisi default">Reset</button>
            `;
            box.appendChild(quickBar);

            const updateBoxGeometry = () => {
                cfg.width = Math.max(30, Math.min(105, cfg.width || 66));
                cfg.height = Math.max(30, Math.min(105, cfg.height || 66));
                cfg.topOffsetMm = Math.max(-4, Math.min(6, cfg.topOffsetMm || 0));
                if (defaultSide === 'left') {
                    cfg.leftOffsetMm = Math.max(-10, Math.min(45, cfg.leftOffsetMm || 0));
                } else {
                    cfg.rightOffsetMm = Math.max(-10, Math.min(45, cfg.rightOffsetMm || 0));
                }

                const w = cfg.width;
                const h = cfg.height;
                const r = cfg.rotation || 0;
                const baseTopMm = Math.max(8, (state.marginTopMm || 25.4) - 10);
                box.style.width = `${w}px`;
                box.style.height = `${h}px`;
                box.style.top = `${baseTopMm + cfg.topOffsetMm}mm`;
                if (defaultSide === 'left') {
                    box.style.left = `${state.marginLeftMm + cfg.leftOffsetMm}mm`;
                    box.style.right = 'auto';
                } else {
                    box.style.right = `${state.marginRightMm + cfg.rightOffsetMm}mm`;
                    box.style.left = 'auto';
                }
                img.style.transform = r ? `rotate(${r}deg)` : '';
                img.style.transformOrigin = 'center center';

                const statEl = quickBar.querySelector('.mou-header-logo-stat');
                if (statEl) statEl.textContent = `${w}×${h}px • ${r}°`;

                // Update hidden input
                const inp = document.getElementById('inputHeaderLogosConfig');
                if (inp) inp.value = JSON.stringify(state.headerLogosConfig);
            };

            updateBoxGeometry();

            // Select & Selection management
            const selectBox = () => {
                if (state?.activeZone !== 'header') return;
                document.querySelectorAll('.mou-header-logo-interactive-box').forEach(b => {
                    b.classList.remove('is-selected');
                    const o = b.querySelector('.mou-image-handle-overlay');
                    if (o) o.style.display = 'none';
                });
                box.classList.add('is-selected');
                overlay.style.display = 'block';

                window._mouActiveSelectedAsset = {
                    type: 'header-logo',
                    slot: slot,
                    boxEl: box,
                    cfg: cfg,
                    updateFn: updateBoxGeometry,
                };

                const wInput = document.getElementById('ribbonImageWidthPx');
                if (wInput) wInput.value = String(cfg.width || 66);
                const hInput = document.getElementById('ribbonImageHeightPx');
                if (hInput) hInput.value = String(cfg.height || 66);
                const rotInput = document.getElementById('ribbonImageRotationDeg');
                if (rotInput) rotInput.value = String(cfg.rotation || 0);

                // Highlight range on ruler
                const pageDims = typeof getPaperDimensionsMm === 'function' ? getPaperDimensionsMm() : calcPaperDimensionsMm(state?.paperSize, state?.orientation);
                const boxWidthMm = (cfg.width || 66) / MM_TO_PX;
                const isLeft = slot === 'institution';
                const absLeftMm = isLeft
                    ? ((state?.marginLeftMm || 25.4) + (cfg.leftOffsetMm || 0))
                    : (pageDims.width - (state?.marginRightMm || 25.4) - (cfg.rightOffsetMm || 0) - boxWidthMm);
                if (typeof showRulerObjectRange === 'function') {
                    showRulerObjectRange(absLeftMm, boxWidthMm);
                }
            };

            box.addEventListener('click', (e) => {
                if (state?.activeZone !== 'header') return;
                e.stopPropagation();
                selectBox();
            });

            box.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                if (state?.activeZone !== 'header') {
                    const pageNum = (typeof this.getPageIndex === 'function' ? this.getPageIndex() : 0) + 1;
                    const { setActiveZone } = this.mouContext;
                    if (typeof setActiveZone === 'function') {
                        setActiveZone('header', pageNum);
                        setTimeout(() => {
                            selectBox();
                        }, 60);
                    }
                }
            });

            // Quick actions
            quickBar.querySelector('.btn-quick-rot')?.addEventListener('click', (e) => {
                if (state?.activeZone !== 'header') return;
                e.stopPropagation();
                cfg.rotation = ((cfg.rotation || 0) + 90) % 360;
                updateBoxGeometry();
                const rotInput = document.getElementById('ribbonImageRotationDeg');
                if (rotInput) rotInput.value = String(cfg.rotation);
                if (typeof syncAllToHiddenInputs === 'function') syncAllToHiddenInputs();
                if (typeof pushAssetHistory === 'function') pushAssetHistory('Putar Logo 90°');
            });
            quickBar.querySelector('.btn-quick-reset')?.addEventListener('click', (e) => {
                if (state?.activeZone !== 'header') return;
                e.stopPropagation();
                cfg.width = 66;
                cfg.height = 66;
                cfg.rotation = 0;
                cfg.topOffsetMm = 0;
                if (defaultSide === 'left') cfg.leftOffsetMm = 0;
                else cfg.rightOffsetMm = 0;
                updateBoxGeometry();
                const rotInput = document.getElementById('ribbonImageRotationDeg');
                if (rotInput) rotInput.value = '0';
                const wInput = document.getElementById('ribbonImageWidthPx');
                if (wInput) wInput.value = '66';
                const hInput = document.getElementById('ribbonImageHeightPx');
                if (hInput) hInput.value = '66';
                if (typeof syncAllToHiddenInputs === 'function') syncAllToHiddenInputs();
                if (typeof pushAssetHistory === 'function') pushAssetHistory('Reset Logo Default');
            });

            // Handle Drag Movement of the whole box
            box.addEventListener('pointerdown', (e) => {
                if (state?.activeZone !== 'header') return;
                if (e.target.closest('.mou-image-handle') || e.target.closest('.mou-image-rotator-handle') || e.target.closest('button')) {
                    return;
                }
                e.preventDefault();
                e.stopPropagation();

                try {
                    box.setPointerCapture(e.pointerId);
                } catch (_) {}

                const startClientX = e.clientX;
                const startClientY = e.clientY;
                const baseTopMm = Math.max(8, (state?.marginTopMm || 25.4) - 10);
                const startTopOffsetMm = cfg.topOffsetMm || 0;
                const startOffsetMm = defaultSide === 'left' ? (cfg.leftOffsetMm || 0) : (cfg.rightOffsetMm || 0);
                const pageDims = typeof getPaperDimensionsMm === 'function' ? getPaperDimensionsMm() : calcPaperDimensionsMm(state?.paperSize, state?.orientation);
                const boxWidthMm = (cfg.width || 66) / MM_TO_PX;
                const boxHeightMm = (cfg.height || 66) / MM_TO_PX;
                const siblingSlot = slot === 'institution' ? 'company' : 'institution';
                const siblingCfg = state?.headerLogosConfig ? state.headerLogosConfig[siblingSlot] : null;

                selectBox();
                box.classList.add('is-dragging');
                let hasMoved = false;

                const onPointerMove = (moveEv) => {
                    moveEv.preventDefault();
                    hasMoved = true;
                    const scale = (parseFloat(state?.zoomPercent) || 100) / 100;
                    const deltaXpx = (moveEv.clientX - startClientX) / scale;
                    const deltaYpx = (moveEv.clientY - startClientY) / scale;
                    const deltaXmm = deltaXpx / MM_TO_PX;
                    const deltaYmm = deltaYpx / MM_TO_PX;

                    const rawTopOffsetMm = startTopOffsetMm + deltaYmm;
                    const rawLeftOffsetMm = defaultSide === 'left' ? (startOffsetMm + deltaXmm) : 0;
                    const rawRightOffsetMm = defaultSide === 'right' ? (startOffsetMm - deltaXmm) : 0;

                    const rawAbsLeftMm = defaultSide === 'left'
                        ? ((state?.marginLeftMm || 25.4) + rawLeftOffsetMm)
                        : (pageDims.width - (state?.marginRightMm || 25.4) - rawRightOffsetMm - boxWidthMm);
                    const rawAbsTopMm = baseTopMm + rawTopOffsetMm;

                    // Evaluate Smart Guides with Magnetic Snapping
                    const smart = typeof evaluateSmartGuides === 'function'
                        ? evaluateSmartGuides({
                            leftMm: rawAbsLeftMm,
                            topMm: rawAbsTopMm,
                            widthMm: boxWidthMm,
                            heightMm: boxHeightMm,
                        }, true, siblingCfg, defaultSide, baseTopMm)
                        : { snapX: null, snapY: null, vGuide: null, hGuide: null };

                    let displayLeftOffsetMm = rawLeftOffsetMm;
                    let displayRightOffsetMm = rawRightOffsetMm;
                    let displayTopOffsetMm = rawTopOffsetMm;
                    let displayAbsLeftMm = rawAbsLeftMm;

                    if (smart.snapX !== null) {
                        if (defaultSide === 'left') {
                            displayLeftOffsetMm = smart.snapX - (state?.marginLeftMm || 25.4);
                        } else {
                            displayRightOffsetMm = (pageDims.width - (state?.marginRightMm || 25.4)) - (smart.snapX + boxWidthMm);
                        }
                        displayAbsLeftMm = smart.snapX;
                    }

                    if (smart.snapY !== null) {
                        displayTopOffsetMm = smart.snapY - baseTopMm;
                    }

                    if (defaultSide === 'left') {
                        cfg.leftOffsetMm = Math.round(Math.max(-10, Math.min(45, displayLeftOffsetMm)) * 10) / 10;
                    } else {
                        cfg.rightOffsetMm = Math.round(Math.max(-10, Math.min(45, displayRightOffsetMm)) * 10) / 10;
                    }
                    cfg.topOffsetMm = Math.round(Math.max(-4, Math.min(6, displayTopOffsetMm)) * 10) / 10;

                    updateBoxGeometry();
                    if (typeof showSmartGuides === 'function') {
                        showSmartGuides(smart.vGuide, smart.hGuide);
                    }
                    if (typeof showRulerObjectRange === 'function') {
                        showRulerObjectRange(displayAbsLeftMm, boxWidthMm);
                    }
                };

                const onPointerUp = () => {
                    try {
                        box.releasePointerCapture(e.pointerId);
                    } catch (_) {}
                    window.removeEventListener('pointermove', onPointerMove);
                    window.removeEventListener('pointerup', onPointerUp);
                    window.removeEventListener('pointercancel', onPointerUp);
                    box.classList.remove('is-dragging');
                    if (typeof hideSmartGuides === 'function') hideSmartGuides();
                    updateBoxGeometry();
                    if (typeof syncAllToHiddenInputs === 'function') syncAllToHiddenInputs();
                    if (hasMoved && typeof pushAssetHistory === 'function') {
                        pushAssetHistory('Geser Logo Header');
                    }
                };

                window.addEventListener('pointermove', onPointerMove, { passive: false });
                window.addEventListener('pointerup', onPointerUp);
                window.addEventListener('pointercancel', onPointerUp);
            });

            // Handle Resizing (8 handles)
            Object.entries(handles).forEach(([pos, handleEl]) => {
                handleEl.setAttribute('draggable', 'false');
                handleEl.style.touchAction = 'none';

                handleEl.addEventListener('pointerdown', (e) => {
                    if (state?.activeZone !== 'header') return;
                    e.preventDefault();
                    e.stopPropagation();
                    try {
                        handleEl.setPointerCapture(e.pointerId);
                    } catch (_) {}

                    const startX = e.clientX;
                    const startY = e.clientY;
                    const startW = cfg.width || 66;
                    const startH = cfg.height || 66;
                    const rect = box.getBoundingClientRect();
                    const centerX = rect.left + rect.width / 2;
                    const centerY = rect.top + rect.height / 2;
                    const startDist = Math.hypot(startX - centerX, startY - centerY) || 1;
                    const scale = (parseFloat(state?.zoomPercent) || 100) / 100;
                    const aspect = startW / (startH || 1);
                    selectBox();
                    let hasResized = false;

                    const onResizeMove = (moveEv) => {
                        moveEv.preventDefault();
                        hasResized = true;
                        const deltaX = (moveEv.clientX - startX) / scale;
                        const deltaY = (moveEv.clientY - startY) / scale;

                        let newW = startW;
                        let newH = startH;

                        // Corner handles: uniform proportional scaling based on distance from box center.
                        if (['se', 'sw', 'ne', 'nw'].includes(pos)) {
                            const currentDist = Math.hypot(moveEv.clientX - centerX, moveEv.clientY - centerY);
                            const ratio = currentDist / startDist;
                            newW = Math.max(30, Math.min(105, startW * ratio));
                            newH = newW / aspect;
                        } else if (pos === 'e') {
                            newW = Math.max(30, Math.min(105, startW + deltaX));
                            newH = newW / aspect;
                        } else if (pos === 'w') {
                            newW = Math.max(30, Math.min(105, startW - deltaX));
                            newH = newW / aspect;
                        } else if (pos === 's') {
                            newH = Math.max(30, Math.min(105, startH + deltaY));
                            newW = newH * aspect;
                        } else if (pos === 'n') {
                            newH = Math.max(30, Math.min(105, startH - deltaY));
                            newW = newH * aspect;
                        }

                        cfg.width = Math.round(Math.max(30, Math.min(105, newW)));
                        cfg.height = Math.round(Math.max(30, Math.min(105, newH)));
                        updateBoxGeometry();

                        // Sync ribbon inputs live
                        const wInput = document.getElementById('ribbonImageWidthPx');
                        if (wInput) wInput.value = String(cfg.width);
                        const hInput = document.getElementById('ribbonImageHeightPx');
                        if (hInput) hInput.value = String(cfg.height);

                        // Show ruler range
                        const pageDims = typeof getPaperDimensionsMm === 'function' ? getPaperDimensionsMm() : calcPaperDimensionsMm(state?.paperSize, state?.orientation);
                        const boxWidthMm = cfg.width / MM_TO_PX;
                        const isLeft = slot === 'institution';
                        const absLeftMm = isLeft
                            ? ((state?.marginLeftMm || 25.4) + (cfg.leftOffsetMm || 0))
                            : (pageDims.width - (state?.marginRightMm || 25.4) - (cfg.rightOffsetMm || 0) - boxWidthMm);
                        if (typeof showRulerObjectRange === 'function') {
                            showRulerObjectRange(absLeftMm, boxWidthMm);
                        }
                    };

                    const onResizeUp = () => {
                        try {
                            handleEl.releasePointerCapture(e.pointerId);
                        } catch (_) {}
                        window.removeEventListener('pointermove', onResizeMove);
                        window.removeEventListener('pointerup', onResizeUp);
                        window.removeEventListener('pointercancel', onResizeUp);
                        if (typeof hideSmartGuides === 'function') hideSmartGuides();
                        updateBoxGeometry();
                        if (typeof syncAllToHiddenInputs === 'function') syncAllToHiddenInputs();
                        if (hasResized && typeof pushAssetHistory === 'function') {
                            pushAssetHistory('Ubah Ukuran Logo');
                        }
                    };

                    window.addEventListener('pointermove', onResizeMove, { passive: false });
                    window.addEventListener('pointerup', onResizeUp);
                    window.addEventListener('pointercancel', onResizeUp);
                });
            });

            // Handle Rotator knob
            rotKnob.setAttribute('draggable', 'false');
            rotKnob.style.touchAction = 'none';

            rotKnob.addEventListener('pointerdown', (e) => {
                if (state?.activeZone !== 'header') return;
                e.preventDefault();
                e.stopPropagation();
                try {
                    rotKnob.setPointerCapture(e.pointerId);
                } catch (_) {}

                const rect = box.getBoundingClientRect();
                const centerX = rect.left + rect.width / 2;
                const centerY = rect.top + rect.height / 2;
                selectBox();
                let hasRotated = false;

                const onRotateMove = (moveEv) => {
                    moveEv.preventDefault();
                    hasRotated = true;
                    const rad = Math.atan2(moveEv.clientY - centerY, moveEv.clientX - centerX);
                    let deg = Math.round(rad * (180 / Math.PI)) + 90;
                    deg = ((deg % 360) + 360) % 360;
                    if (Math.abs(deg - 0) <= 4 || Math.abs(deg - 360) <= 4) deg = 0;
                    else if (Math.abs(deg - 90) <= 4) deg = 90;
                    else if (Math.abs(deg - 180) <= 4) deg = 180;
                    else if (Math.abs(deg - 270) <= 4) deg = 270;

                    cfg.rotation = deg;
                    updateBoxGeometry();
                    const rotInput = document.getElementById('ribbonImageRotationDeg');
                    if (rotInput) rotInput.value = String(deg);
                };

                const onRotateUp = () => {
                    try {
                        rotKnob.releasePointerCapture(e.pointerId);
                    } catch (_) {}
                    window.removeEventListener('pointermove', onRotateMove);
                    window.removeEventListener('pointerup', onRotateUp);
                    window.removeEventListener('pointercancel', onRotateUp);
                    updateBoxGeometry();
                    if (typeof syncAllToHiddenInputs === 'function') syncAllToHiddenInputs();
                    if (hasRotated && typeof pushAssetHistory === 'function') {
                        pushAssetHistory('Putar Logo');
                    }
                };

                window.addEventListener('pointermove', onRotateMove, { passive: false });
                window.addEventListener('pointerup', onRotateUp);
                window.addEventListener('pointercancel', onRotateUp);
            });

            rotKnob.addEventListener('dblclick', (e) => {
                e.stopPropagation();
                cfg.rotation = 0;
                updateBoxGeometry();
                const rotInput = document.getElementById('ribbonImageRotationDeg');
                if (rotInput) rotInput.value = '0';
                if (typeof syncAllToHiddenInputs === 'function') syncAllToHiddenInputs();
                if (typeof pushAssetHistory === 'function') pushAssetHistory('Reset Rotasi 0°');
            });

            return box;
        };

        const leftBox = createLogoBox('institution', 'Logo Institusi Mitra', 'left', instLogoUrl);
        const rightBox = createLogoBox('company', 'Logo Seven Inc', 'right', compLogoUrl);

        this.headerLogosLayer.appendChild(leftBox);
        this.headerLogosLayer.appendChild(rightBox);
    }

    update(updatedNode) {
        if (updatedNode.type.name !== 'mouPage') return false;
        this.node = updatedNode;
        // Do not thrash DOM and reset scroll by re-rendering headers/watermark on every typing stroke!
        return true;
    }

    destroy() {
        // cleanup
    }
}

/**
 * Custom Page Node for Tiptap / ProseMirror.
 */
const MouPageNode = Node.create({
    name: 'mouPage',
    group: 'page',
    content: 'block+',
    defining: true,
    isolating: false,
    selectable: false,
    draggable: false,

    addAttributes() {
        return {
            pageNumber: {
                default: 1,
                parseHTML: el => parseInt(el.getAttribute('data-page-number') || '1', 10),
                renderHTML: attrs => ({
                    'data-page-number': attrs.pageNumber,
                }),
            },
        };
    },

    parseHTML() {
        return [
            { tag: 'div[data-mou-page]' },
            { tag: 'div.mou-a4-page-node' },
            { tag: 'div.mou-a4-page' },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'div',
            mergeAttributes(HTMLAttributes, {
                class: 'mou-a4-paper-sheet mou-a4-page-node',
                'data-mou-page': 'true',
            }),
            0,
        ];
    },

    addNodeView() {
        return ({ node, HTMLAttributes, getPos, editor }) => {
            return new MouPageView(node, editor, getPos);
        };
    },
});

/**
 * Create Tiptap extensions specifically for the multi-page Body editor.
 */
function createWordBodyExtensions(placeholderText = 'Mulai ketik naskah dokumen bebas di sini...') {
    return [
        StarterKit.configure({
            document: false,
            paragraph: false,
            heading: false,
            orderedList: false,
            bulletList: false,
            listItem: false,
            listKeymap: false,
            link: {
                openOnClick: false,
                HTMLAttributes: {
                    rel: 'noopener noreferrer',
                },
            },
        }),
        WordDocument,
        MouPageNode,
        WordParagraph,
        WordHeading.configure({ levels: [1, 2, 3, 4] }),
        WordBulletList,
        WordOrderedList,
        ListItem,
        ListKeymap,
        WordTabExtension,
        TextStyle,
        FontFamily,
        FontSize,
        Color,
        BackgroundColor,
        LineHeight,
        TextAlign.configure({
            types: ['heading', 'paragraph', 'tableCell', 'tableHeader'],
            alignments: ['left', 'center', 'right', 'justify'],
        }),
        Highlight.configure({ multicolor: true }),
        Underline,
        Subscript,
        Superscript,
        WordImage,
        WordTable,
        WordTableRow,
        WordTableCell,
        WordTableHeader,
        WordPageBreak,
        WordTextBox,
        WordSignatureBlock,
        Placeholder.configure({
            placeholder: placeholderText,
        }),
        UnifiedUndoKeymap,
    ];
}

/**
 * Sanitizes and strictly clamps header logo configuration so logos cannot exceed
 * the bounded top Header zone or blow up into oversized dimensions.
 */
function sanitizeHeaderLogosConfig(raw) {
    let parsed = raw;
    if (typeof raw === 'string') {
        try {
            parsed = JSON.parse(raw || '{}');
        } catch (_) {
            parsed = {};
        }
    }
    parsed = parsed || {};

    const clamp = (val, min, max, dflt) => {
        const num = parseFloat(val);
        if (isNaN(num)) return dflt;
        return Math.max(min, Math.min(max, num));
    };

    const sanitizeSlot = (slotData, defaultSide) => {
        const d = slotData || {};
        const w = Math.round(clamp(d.width, 30, 105, 66));
        const h = Math.round(clamp(d.height, 30, 105, 66));
        const rot = ((parseInt(d.rotation || 0, 10) % 360) + 360) % 360;
        const topOff = Math.round(clamp(d.topOffsetMm, -4, 6, 0) * 10) / 10;
        return {
            width: w,
            height: h,
            rotation: rot,
            topOffsetMm: topOff,
            ...(defaultSide === 'left'
                ? { leftOffsetMm: Math.round(clamp(d.leftOffsetMm, -10, 45, 0) * 10) / 10 }
                : { rightOffsetMm: Math.round(clamp(d.rightOffsetMm, -10, 45, 0) * 10) / 10 }),
        };
    };

    return {
        institution: sanitizeSlot(parsed.institution, 'left'),
        company: sanitizeSlot(parsed.company, 'right'),
    };
}

/**
 * Initialize the SERTIF MoU Word Processor V3 studio.
 */
export function initMouWordProcessor(config) {
    const {
        bodyMountEl,
        headerMountEl,
        footerMountEl,
        initialBodyHtml = '<p></p>',
        initialEditorJson = null,
        initialHeaderHtml = '',
        initialFooterHtml = '',
        initialFirstPageHeaderHtml = '',
        initialFirstPageFooterHtml = '',
        defaultSampleHtml = '',
        defaultParafHtml = '',
        pageConfig = {},
        mediaAssets = {},
        uploadMediaUrl = '',
        csrfToken = '',
    } = config;

    if (!bodyMountEl) return null;

    const docTypeStr = String(config.documentType || 'mou').toLowerCase();
    const isMouDocument = (docTypeStr === 'mou');
    const resolvedCompanyLogo = mediaAssets.company_logo || mediaAssets.company_logo_url || '/images/mou/seven-inc-logo.png';
    const resolvedInstitutionLogo = isMouDocument
        ? (mediaAssets.institution_logo || mediaAssets.institution_logo_url || '/images/mou/institution-logo-placeholder.png')
        : '';

    const state = {
        documentType: docTypeStr,
        activeZone: 'body', // 'body' | 'header' | 'footer'
        editingFirstPageVariant: false,
        differentFirstPage: Boolean(pageConfig.different_first_page),
        showParafBox: pageConfig.show_paraf_box !== undefined ? Boolean(pageConfig.show_paraf_box) : true,
        showPageNumber: pageConfig.show_page_number !== undefined ? Boolean(pageConfig.show_page_number) : true,
        showHeaderLogos: isMouDocument ? (pageConfig.show_header_logos !== undefined ? Boolean(pageConfig.show_header_logos) : true) : false,
        showWatermark: pageConfig.show_watermark !== undefined ? Boolean(pageConfig.show_watermark) : true,
        watermarkOpacity: parseFloat(pageConfig.watermark_opacity ?? 0.05),
        paperSize: (pageConfig.paper_size || 'A4').toUpperCase(),
        orientation: (pageConfig.orientation || 'portrait').toLowerCase(),
        marginTopMm: parseFloat(pageConfig.margin_top_mm ?? 25.4),
        marginRightMm: parseFloat(pageConfig.margin_right_mm ?? 25.4),
        marginBottomMm: parseFloat(pageConfig.margin_bottom_mm ?? 25.4),
        marginLeftMm: parseFloat(pageConfig.margin_left_mm ?? 25.4),
        headerDistanceMm: parseFloat(pageConfig.header_distance_mm ?? 12.0),
        footerDistanceMm: parseFloat(pageConfig.footer_distance_mm ?? 10.0),
        fontFamily: pageConfig.font_family || 'Times New Roman',
        fontSizePt: parseInt(pageConfig.font_size_pt ?? 12, 10),
        lineHeight: parseFloat(pageConfig.line_height ?? 1.15),
        zoomPercent: 100,
        viewMode: 'edit', // 'edit' | 'preview'
        allowBlankTemplate: false,
        headerHtmlStore: normalizeLegacyHtmlForTiptap(initialHeaderHtml || '<p></p>'),
        footerHtmlStore: normalizeLegacyHtmlForTiptap(initialFooterHtml || defaultParafHtml || '<p></p>'),
        firstPageHeaderHtmlStore: normalizeLegacyHtmlForTiptap(initialFirstPageHeaderHtml || '<p></p>'),
        firstPageFooterHtmlStore: normalizeLegacyHtmlForTiptap(initialFirstPageFooterHtml || defaultParafHtml || '<p></p>'),
        mediaAssets: {
            institution_logo: resolvedInstitutionLogo,
            company_logo: resolvedCompanyLogo,
            institution_logo_url: resolvedInstitutionLogo,
            company_logo_url: resolvedCompanyLogo,
        },
        headerLogosConfig: sanitizeHeaderLogosConfig(pageConfig.header_logos_config),
        showTableGridlines: true,
        isBorderPainterActive: false,
        selectedImageNodePos: null,
    };

    const rawFallbackHtml = (initialBodyHtml && initialBodyHtml.trim() !== '' && initialBodyHtml.trim() !== '<p></p>')
        ? initialBodyHtml
        : (defaultSampleHtml || initialBodyHtml || '<p></p>');
    const normalizedFallbackHtml = normalizeLegacyHtmlForTiptap(rawFallbackHtml);

    let parsedInitialJson = null;
    if (initialEditorJson) {
        try {
            const candidate = typeof initialEditorJson === 'string'
                ? JSON.parse(initialEditorJson)
                : initialEditorJson;
            if (candidate && candidate.type === 'doc' && Array.isArray(candidate.content) && candidate.content.length > 0) {
                parsedInitialJson = candidate;
            }
        } catch (err) {
            console.warn('Invalid initialEditorJson, falling back to HTML:', err);
        }
    }

    // Reflow tracking plugin key
    const reflowPluginKey = new PluginKey('mouA4ReflowPlugin');

    const initialContentToLoad = parsedInitialJson
        ? ensureJsonHasMouPage(parsedInitialJson)
        : normalizeHtmlForBodyEditor(normalizedFallbackHtml);

    // Asset History Stack for Header Logos and Transformations (Instant Ctrl+Z / Ctrl+Y)
    const assetHistory = [];
    let assetHistoryIndex = -1;
    const MAX_ASSET_HISTORY = 40;

    function pushAssetHistory(actionName = 'Transform Asset') {
        const currentCfgJson = JSON.stringify(state.headerLogosConfig || {});
        if (assetHistoryIndex >= 0 && assetHistory[assetHistoryIndex]?.configJson === currentCfgJson) {
            return;
        }
        if (assetHistoryIndex < assetHistory.length - 1) {
            assetHistory.splice(assetHistoryIndex + 1);
        }
        assetHistory.push({
            action: actionName,
            configJson: currentCfgJson,
            config: JSON.parse(currentCfgJson),
            timestamp: Date.now(),
        });
        if (assetHistory.length > MAX_ASSET_HISTORY) {
            assetHistory.shift();
        }
        assetHistoryIndex = assetHistory.length - 1;
        updateToolbarState();
    }

    let lastEditorDocChangeTimestamp = 0;

    function undoAssetHistory() {
        if (assetHistoryIndex > 0) {
            assetHistoryIndex--;
            const snap = assetHistory[assetHistoryIndex];
            state.headerLogosConfig = sanitizeHeaderLogosConfig(snap.config);
            updateAllMountedLogoBoxes();
            syncAllToHiddenInputs();
            refreshAllPageViews();
            if (typeof scheduleReflow === 'function') {
                scheduleReflow(true);
            }
            updateToolbarState();
            return true;
        }
        return false;
    }

    function redoAssetHistory() {
        if (assetHistoryIndex < assetHistory.length - 1) {
            assetHistoryIndex++;
            const snap = assetHistory[assetHistoryIndex];
            state.headerLogosConfig = sanitizeHeaderLogosConfig(snap.config);
            updateAllMountedLogoBoxes();
            syncAllToHiddenInputs();
            refreshAllPageViews();
            if (typeof scheduleReflow === 'function') {
                scheduleReflow(true);
            }
            updateToolbarState();
            return true;
        }
        return false;
    }

    function updateAllMountedLogoBoxes() {
        state.headerLogosConfig = sanitizeHeaderLogosConfig(state.headerLogosConfig);
        document.querySelectorAll('.mou-header-logo-interactive-box').forEach(box => {
            const slot = box.getAttribute('data-logo-slot');
            if (!slot || !state.headerLogosConfig?.[slot]) return;
            const cfg = state.headerLogosConfig[slot];
            const defaultSide = box.classList.contains('mou-logo-box-left') ? 'left' : 'right';
            const w = cfg.width || 66;
            const h = cfg.height || 66;
            const r = cfg.rotation || 0;
            const baseTopMm = Math.max(8, (state.marginTopMm || 25.4) - 10);
            box.style.width = `${w}px`;
            box.style.height = `${h}px`;
            box.style.top = `${baseTopMm + (cfg.topOffsetMm || 0)}mm`;
            if (defaultSide === 'left') {
                box.style.left = `${state.marginLeftMm + (cfg.leftOffsetMm || 0)}mm`;
                box.style.right = 'auto';
            } else {
                box.style.right = `${state.marginRightMm + (cfg.rightOffsetMm || 0)}mm`;
                box.style.left = 'auto';
            }
            const img = box.querySelector('.mou-logo-img-el');
            if (img) {
                img.style.transform = r ? `rotate(${r}deg)` : '';
                img.style.transformOrigin = 'center center';
            }
            const statEl = box.querySelector('.mou-header-logo-stat');
            if (statEl) statEl.textContent = `${w}×${h}px • ${r}°`;
        });

        // Sync hidden input
        const inp = document.getElementById('inputHeaderLogosConfig');
        if (inp) inp.value = JSON.stringify(state.headerLogosConfig);

        // Sync ribbon inputs if an asset is currently selected
        if (window._mouActiveSelectedAsset?.type === 'header-logo') {
            const slot = window._mouActiveSelectedAsset.slot;
            const cfg = state.headerLogosConfig[slot];
            if (cfg) {
                window._mouActiveSelectedAsset.cfg = cfg;
                const wInput = document.getElementById('ribbonImageWidthPx');
                if (wInput) wInput.value = String(cfg.width || 66);
                const hInput = document.getElementById('ribbonImageHeightPx');
                if (hInput) hInput.value = String(cfg.height || 66);
                const rotInput = document.getElementById('ribbonImageRotationDeg');
                if (rotInput) rotInput.value = String(cfg.rotation || 0);
            }
        }
    }

    const sharedMouContext = {
        state,
        getPaperDimensionsMm: () => getPaperDimensionsMm(),
        setActiveZone: (z, p) => setActiveZone(z, p),
        syncAllToHiddenInputs: () => syncAllToHiddenInputs(),
        pushAssetHistory: (action) => pushAssetHistory(action),
        undoAssetHistory: () => undoAssetHistory(),
        redoAssetHistory: () => redoAssetHistory(),
        evaluateSmartGuides: (...args) => evaluateSmartGuides(...args),
        showSmartGuides: (...args) => showSmartGuides(...args),
        hideSmartGuides: () => hideSmartGuides(),
        showRulerObjectRange: (...args) => showRulerObjectRange(...args),
        hideRulerObjectRange: () => hideRulerObjectRange(),
        updateAllMountedLogoBoxes: () => updateAllMountedLogoBoxes(),
    };

    // Global Keymap Handlers for Tiptap / ProseMirror to handle unified Undo/Redo
    window._mouGlobalUndoHandler = () => {
        const lastAssetTime = (assetHistoryIndex > 0 && assetHistory[assetHistoryIndex])
            ? assetHistory[assetHistoryIndex].timestamp
            : 0;
        if (lastAssetTime > 0 && lastAssetTime >= lastEditorDocChangeTimestamp) {
            return undoAssetHistory();
        }
        return false; // let ProseMirror history handle text undo
    };

    window._mouGlobalRedoHandler = () => {
        const nextAssetTime = (assetHistoryIndex < assetHistory.length - 1 && assetHistory[assetHistoryIndex + 1])
            ? assetHistory[assetHistoryIndex + 1].timestamp
            : 0;
        if (nextAssetTime > 0 && nextAssetTime >= lastEditorDocChangeTimestamp) {
            return redoAssetHistory();
        }
        return false; // let ProseMirror history handle text redo
    };

    // Instantiate Paginated Body Editor with safe fallback from JSON to HTML if schema validation fails
    let bodyEditor;
    try {
        bodyEditor = new Editor({
            element: bodyMountEl,
            extensions: createWordBodyExtensions('Mulai ketik naskah dokumen bebas di sini, sisipkan tabel, atau gunakan tombol Contoh Naskah MoU...'),
            content: initialContentToLoad,
            autofocus: false,
            onFocus: () => {
                if (state.activeZone !== 'body') {
                    setActiveZone('body');
                }
                updateToolbarState();
            },
            onSelectionUpdate: () => {
                updateToolbarState();
                updateDocumentMetrics();
            },
            onTransaction: ({ transaction }) => {
                updateToolbarState();
                updateDocumentMetrics();
                // Hanya picu reflow dan sinkronisasi jika isi dokumen berubah (docChanged).
                // Mencegah selection teks lepas / hilang saat kursor bergerak atau saat mouse drag memilih teks!
                if (transaction.docChanged) {
                    lastEditorDocChangeTimestamp = Date.now();
                    scheduleSyncAndPagination();
                    if (!transaction.getMeta(reflowPluginKey)) {
                        reflowPassCount = 0;
                        scheduleReflow(false);
                    }
                }
            },
        });
        bodyEditor.mouContext = sharedMouContext;
    } catch (initErr) {
        console.warn('Paginated Body editor JSON load failed, retrying with normalized HTML:', initErr);
        bodyMountEl.innerHTML = '';
        bodyEditor = new Editor({
            element: bodyMountEl,
            extensions: createWordBodyExtensions('Mulai ketik naskah dokumen bebas di sini, sisipkan tabel, atau gunakan tombol Contoh Naskah MoU...'),
            content: normalizeHtmlForBodyEditor(normalizedFallbackHtml),
            autofocus: false,
            onFocus: () => {
                if (state.activeZone !== 'body') {
                    setActiveZone('body');
                }
                updateToolbarState();
            },
            onSelectionUpdate: () => {
                updateToolbarState();
                updateDocumentMetrics();
            },
            onTransaction: ({ transaction }) => {
                updateToolbarState();
                updateDocumentMetrics();
                if (transaction.docChanged) {
                    lastEditorDocChangeTimestamp = Date.now();
                    scheduleSyncAndPagination();
                    if (!transaction.getMeta(reflowPluginKey)) {
                        reflowPassCount = 0;
                        scheduleReflow(false);
                    }
                }
            },
        });
        bodyEditor.mouContext = sharedMouContext;
    }

    // Capture initial asset state into history
    pushAssetHistory('Initial State');

    // Guarantee non-empty initial render when fallback HTML had content
    if (bodyEditor.isEmpty && normalizedFallbackHtml !== '<p></p>' && !normalizedFallbackHtml.includes('is-editor-empty')) {
        bodyEditor.commands.setContent(normalizeHtmlForBodyEditor(normalizedFallbackHtml), { emitUpdate: false });
    }

    // Instantiate Header Sub-Editor
    const headerEditor = headerMountEl
        ? new Editor({
              element: headerMountEl,
              extensions: createWordExtensions(isMouDocument ? '' : 'Klik ganda untuk mengisi Header dokumen...'),
              content: state.headerHtmlStore,
              editable: false,
              onFocus: () => {
                  updateToolbarState();
              },
              onSelectionUpdate: () => {
                  updateToolbarState();
              },
              onTransaction: ({ transaction }) => {
                  updateToolbarState();
                  if (transaction.docChanged) {
                      lastEditorDocChangeTimestamp = Date.now();
                      saveActiveHeaderFooterToStore();
                      scheduleSyncAndPagination();
                  }
              },
          })
        : null;

    // Instantiate Footer Sub-Editor (Houses the editable/resizable Kotak Paraf table)
    const footerEditor = footerMountEl
        ? new Editor({
              element: footerMountEl,
              extensions: createWordExtensions('Klik ganda untuk menyunting Footer / Tabel Kotak Paraf...'),
              content: state.footerHtmlStore,
              editable: false,
              onFocus: () => {
                  updateToolbarState();
              },
              onSelectionUpdate: () => {
                  updateToolbarState();
              },
              onTransaction: ({ transaction }) => {
                  updateToolbarState();
                  if (transaction.docChanged) {
                      lastEditorDocChangeTimestamp = Date.now();
                      saveActiveHeaderFooterToStore();
                      scheduleSyncAndPagination();
                  }
              },
          })
        : null;

    function getActiveEditor() {
        if (state.activeZone === 'header' && headerEditor) return headerEditor;
        if (state.activeZone === 'footer' && footerEditor) return footerEditor;
        return bodyEditor;
    }

    function saveActiveHeaderFooterToStore() {
        if (headerEditor) {
            const hHtml = synchronizeSerializedHtml(headerEditor.getHTML());
            const cleanH = headerEditor.isEmpty && !hHtml.includes('<table') && !hHtml.includes('<img') ? '' : hHtml;
            if (state.differentFirstPage && state.editingFirstPageVariant) {
                state.firstPageHeaderHtmlStore = cleanH;
            } else {
                state.headerHtmlStore = cleanH;
            }
        }
        if (footerEditor) {
            const fHtml = synchronizeSerializedHtml(footerEditor.getHTML());
            const cleanF = footerEditor.isEmpty && !fHtml.includes('<table') && !fHtml.includes('<img') ? '' : fHtml;
            if (state.differentFirstPage && state.editingFirstPageVariant) {
                state.firstPageFooterHtmlStore = cleanF;
            } else {
                state.footerHtmlStore = cleanF;
            }
        }
    }

    function loadHeaderFooterVariantIntoEditors() {
        if (headerEditor) {
            const targetH = (state.differentFirstPage && state.editingFirstPageVariant)
                ? state.firstPageHeaderHtmlStore
                : state.headerHtmlStore;
            headerEditor.commands.setContent(targetH || '<p></p>', { emitUpdate: false });
        }
        if (footerEditor) {
            const targetF = (state.differentFirstPage && state.editingFirstPageVariant)
                ? state.firstPageFooterHtmlStore
                : state.footerHtmlStore;
            footerEditor.commands.setContent(targetF || '<p></p>', { emitUpdate: false });
        }
        scheduleSyncAndPagination();
    }

    function switchRibbonTab(tabName) {
        const tabBtns = document.querySelectorAll('[data-ribbon-tab]');
        const tabPanels = document.querySelectorAll('[data-ribbon-panel]');
        tabBtns.forEach(b => b.classList.toggle('active', b.getAttribute('data-ribbon-tab') === tabName));
        tabPanels.forEach(p => {
            const isMatch = p.getAttribute('data-ribbon-panel') === tabName;
            p.classList.toggle('hidden', !isMatch);
            p.classList.toggle('flex', isMatch);
        });
        requestAnimationFrame(() => {
            syncRulerToPageSheet();
            updateRulerMarkersFromEditor();
        });
    }

    function setActiveZone(zone, targetPageNum = 1) {
        if (state.activeZone === zone && state.activePageNum === targetPageNum) return;
        saveActiveHeaderFooterToStore();
        const prevZone = state.activeZone;
        state.activeZone = zone;
        state.activePageNum = targetPageNum;

        const headerWrapper = document.getElementById('mouHeaderZoneWrapper');
        const footerWrapper = document.getElementById('mouFooterZoneWrapper');
        const hfStaging = document.getElementById('mouHfStagingContainer');
        const zoneBadge = document.getElementById('activeEditorZoneBadge');
        const closeHfBtn = document.getElementById('btnCloseHeaderFooter');

        // Variant determination: if different first page is checked
        if (state.differentFirstPage && targetPageNum === 1) {
            state.editingFirstPageVariant = true;
        } else {
            state.editingFirstPageVariant = false;
        }

        // Return wrappers to staging first
        if (headerWrapper && hfStaging && headerWrapper.parentNode !== hfStaging) {
            hfStaging.appendChild(headerWrapper);
        }
        if (footerWrapper && hfStaging && footerWrapper.parentNode !== hfStaging) {
            hfStaging.appendChild(footerWrapper);
        }

        if (zone === 'header' && headerWrapper) {
            const pageDoms = document.querySelectorAll('.mou-a4-page-node');
            const targetPageDom = pageDoms[targetPageNum - 1] || pageDoms[0];
            const headerSlot = targetPageDom ? targetPageDom.querySelector('.mou-a4-page-header') : null;
            if (headerSlot) {
                headerSlot.removeAttribute('contenteditable');
                headerSlot.innerHTML = '';
                headerSlot.appendChild(headerWrapper);
                headerWrapper.classList.remove('hidden');
                headerWrapper.classList.add('mou-zone-active');
            }
            loadHeaderFooterVariantIntoEditors();
            headerEditor?.setEditable(true);
            setTimeout(() => {
                headerEditor?.commands.focus('end');
            }, 40);
            bodyEditor?.setEditable(false);
            footerEditor?.setEditable(false);
            switchRibbonTab('headerfooter');
        } else if (zone === 'footer' && footerWrapper) {
            const pageDoms = document.querySelectorAll('.mou-a4-page-node');
            const targetPageDom = pageDoms[targetPageNum - 1] || pageDoms[0];
            const footerSlot = targetPageDom ? targetPageDom.querySelector('.mou-a4-page-footer') : null;
            if (footerSlot) {
                footerSlot.removeAttribute('contenteditable');
                footerSlot.innerHTML = '';
                footerSlot.appendChild(footerWrapper);
                footerWrapper.classList.remove('hidden');
                footerWrapper.classList.add('mou-zone-active');
            }
            loadHeaderFooterVariantIntoEditors();
            footerEditor?.setEditable(true);
            setTimeout(() => {
                footerEditor?.commands.focus('end');
            }, 40);
            bodyEditor?.setEditable(false);
            headerEditor?.setEditable(false);
            switchRibbonTab('headerfooter');
        } else {
            // Body mode
            headerEditor?.setEditable(false);
            footerEditor?.setEditable(false);
            bodyEditor?.setEditable(true);

            document.querySelectorAll('.mou-a4-page-header, .mou-a4-page-footer').forEach(el => {
                el.setAttribute('contenteditable', 'false');
            });

            refreshAllPageViews();
            if (prevZone === 'header' || prevZone === 'footer') {
                const activeTabBtn = document.querySelector('[data-ribbon-tab].active');
                if (activeTabBtn && activeTabBtn.getAttribute('data-ribbon-tab') === 'headerfooter') {
                    switchRibbonTab('home');
                }
                bodyEditor?.commands.focus();
                scheduleReflow(true);
            }
        }

        // Synchronize all Header Logos Interactive Stages across all pages
        document.querySelectorAll('.mou-header-logos-interactive-stage').forEach(stage => {
            if (zone === 'header') {
                stage.classList.add('is-header-active');
            } else {
                stage.classList.remove('is-header-active');
            }
        });

        // When leaving header mode, deselect any active header logo asset
        if (zone !== 'header') {
            if (window._mouActiveSelectedAsset?.type === 'header-logo') {
                window._mouActiveSelectedAsset = null;
            }
            document.querySelectorAll('.mou-header-logo-interactive-box').forEach(b => {
                b.classList.remove('is-selected', 'is-dragging');
                const o = b.querySelector('.mou-image-handle-overlay');
                if (o) o.style.display = 'none';
            });
            if (typeof hideSmartGuides === 'function') hideSmartGuides();
            if (typeof hideRulerObjectRange === 'function') hideRulerObjectRange();
        }

        if (closeHfBtn) {
            closeHfBtn.classList.toggle('hidden', zone === 'body');
        }

        if (zoneBadge) {
            if (zone === 'header') {
                const label = state.differentFirstPage && state.editingFirstPageVariant
                    ? `Edit: Header Hal. ${targetPageNum} (Khusus Pertama)`
                    : `Edit: Header Dokumen (Hal. ${targetPageNum})`;
                zoneBadge.innerHTML = `
                    <svg class="h-3.5 w-3.5 text-amber-700 animate-pulse shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    <span>${label}</span>
                `;
                zoneBadge.className = 'inline-flex items-center gap-1.5 rounded-full bg-amber-100 border border-amber-300 px-2.5 py-0.5 text-[11px] font-bold text-amber-900 whitespace-nowrap shadow-2xs';
            } else if (zone === 'footer') {
                const label = state.differentFirstPage && state.editingFirstPageVariant
                    ? `Edit: Footer Hal. ${targetPageNum} (Khusus Pertama)`
                    : `Edit: Footer & Kotak Paraf (Hal. ${targetPageNum})`;
                zoneBadge.innerHTML = `
                    <svg class="h-3.5 w-3.5 text-amber-700 animate-pulse shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                    <span>${label}</span>
                `;
                zoneBadge.className = 'inline-flex items-center gap-1.5 rounded-full bg-amber-100 border border-amber-300 px-2.5 py-0.5 text-[11px] font-bold text-amber-900 whitespace-nowrap shadow-2xs';
            } else {
                zoneBadge.innerHTML = `
                    <svg class="h-3.5 w-3.5 text-blue-600 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    <span>Mode: Lembar Dokumen A4</span>
                `;
                zoneBadge.className = 'inline-flex items-center gap-1.5 rounded-full bg-blue-50 border border-blue-200 px-2.5 py-0.5 text-[11px] font-semibold text-blue-700 whitespace-nowrap';
            }
        }
        updateToolbarState();
        requestAnimationFrame(() => {
            syncRulerToPageSheet();
        });
    }

    // Attach Double-Click listeners on Header, Footer, and Body zones (Word-style UX)
    const headerZoneWrapper = document.getElementById('mouHeaderZoneWrapper');
    const footerZoneWrapper = document.getElementById('mouFooterZoneWrapper');
    const bodyZoneWrapper = document.getElementById('mouBodyZoneWrapper');
    const closeHfBtn = document.getElementById('btnCloseHeaderFooter');

    if (closeHfBtn) {
        closeHfBtn.addEventListener('click', (e) => {
            e.preventDefault();
            setActiveZone('body');
        });
    }

    // Global Keyboard Shortcuts (Microsoft Word & Office Standard)
    window.addEventListener('keydown', (e) => {
        const isCtrl = e.ctrlKey || e.metaKey;
        const target = e.target;
        const isTextInput = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') && !target.closest('.ProseMirror');

        // Enter Key in Editor: Lock viewport scroll position and trigger immediate reflow so new page is created promptly
        if (e.key === 'Enter' && !isTextInput) {
            setTimeout(() => {
                if (typeof scheduleReflow === 'function') {
                    scheduleReflow(true);
                }
            }, 10);
            const workspaceViewport = document.getElementById('mouEditorWorkspaceView');
            if (workspaceViewport) {
                const lockScrollTop = workspaceViewport.scrollTop;
                const lockWinY = window.scrollY;
                const lockWinX = window.scrollX;
                const restoreLock = () => {
                    if (Math.abs(workspaceViewport.scrollTop - lockScrollTop) > 2) {
                        workspaceViewport.scrollTop = lockScrollTop;
                    }
                    if (Math.abs(window.scrollY - lockWinY) > 2) {
                        window.scrollTo(lockWinX, lockWinY);
                    }
                };
                requestAnimationFrame(restoreLock);
                setTimeout(restoreLock, 15);
                setTimeout(restoreLock, 50);
                setTimeout(restoreLock, 120);
            }
        }

        // Escape: exit header/footer edit mode or deselect asset
        if (e.key === 'Escape' || e.keyCode === 27) {
            if (state.activeZone === 'header' || state.activeZone === 'footer') {
                e.preventDefault();
                setActiveZone('body');
                return;
            }
            if (window._mouActiveSelectedAsset) {
                e.preventDefault();
                document.querySelectorAll('.mou-header-logo-interactive-box, .mou-image-wrapper').forEach(b => {
                    b.classList.remove('is-selected');
                    const o = b.querySelector('.mou-image-handle-overlay');
                    if (o) o.style.display = 'none';
                });
                window._mouActiveSelectedAsset = null;
                return;
            }
        }

        // Arrow key nudging for active selected header logo
        if (window._mouActiveSelectedAsset?.type === 'header-logo') {
            if (state.activeZone !== 'header') return;
            const isArrow = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key);
            if (isArrow && !isTextInput) {
                e.preventDefault();
                e.stopPropagation();
                const step = e.shiftKey ? 2.5 : 0.5; // mm
                const asset = window._mouActiveSelectedAsset;
                const cfg = asset.cfg;
                const isLeft = asset.slot === 'institution';

                if (e.key === 'ArrowLeft') {
                    if (isLeft) cfg.leftOffsetMm = Math.round(((cfg.leftOffsetMm || 0) - step) * 10) / 10;
                    else cfg.rightOffsetMm = Math.round(((cfg.rightOffsetMm || 0) + step) * 10) / 10;
                } else if (e.key === 'ArrowRight') {
                    if (isLeft) cfg.leftOffsetMm = Math.round(((cfg.leftOffsetMm || 0) + step) * 10) / 10;
                    else cfg.rightOffsetMm = Math.round(((cfg.rightOffsetMm || 0) - step) * 10) / 10;
                } else if (e.key === 'ArrowUp') {
                    cfg.topOffsetMm = Math.round(((cfg.topOffsetMm || 0) - step) * 10) / 10;
                } else if (e.key === 'ArrowDown') {
                    cfg.topOffsetMm = Math.round(((cfg.topOffsetMm || 0) + step) * 10) / 10;
                }

                asset.updateFn?.();
                syncAllToHiddenInputs();

                // Show ruler object range highlight
                const dims = typeof getPaperDimensionsMm === 'function' ? getPaperDimensionsMm() : calcPaperDimensionsMm(state?.paperSize, state?.orientation);
                const boxWidthMm = (cfg.width || 66) / MM_TO_PX;
                const absLeftMm = isLeft
                    ? ((state?.marginLeftMm || 25.4) + (cfg.leftOffsetMm || 0))
                    : (dims.width - (state?.marginRightMm || 25.4) - (cfg.rightOffsetMm || 0) - boxWidthMm);
                showRulerObjectRange(absLeftMm, boxWidthMm);

                // Debounce push to asset history
                clearTimeout(window._mouNudgeTimer);
                window._mouNudgeTimer = setTimeout(() => {
                    pushAssetHistory('Nudge Logo Keyboard');
                    hideRulerObjectRange();
                }, 400);
                return;
            }
        }

        // Ctrl + S -> Save Template Document
        if (isCtrl && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            e.stopPropagation();
            api.saveDocument();
            return;
        }

        if (!isTextInput) {
            // Ctrl + B -> Bold
            if (isCtrl && (e.key === 'b' || e.key === 'B')) {
                e.preventDefault();
                api.toggleBold();
                return;
            }
            // Ctrl + I -> Italic
            if (isCtrl && (e.key === 'i' || e.key === 'I')) {
                e.preventDefault();
                api.toggleItalic();
                return;
            }
            // Ctrl + U -> Underline
            if (isCtrl && (e.key === 'u' || e.key === 'U')) {
                e.preventDefault();
                api.toggleUnderline();
                return;
            }
            // Ctrl + E -> Center Align
            if (isCtrl && (e.key === 'e' || e.key === 'E')) {
                e.preventDefault();
                api.setTextAlign('center');
                return;
            }
            // Ctrl + L -> Left Align
            if (isCtrl && (e.key === 'l' || e.key === 'L')) {
                e.preventDefault();
                api.setTextAlign('left');
                return;
            }
            // Ctrl + R -> Right Align
            if (isCtrl && (e.key === 'r' || e.key === 'R')) {
                e.preventDefault();
                api.setTextAlign('right');
                return;
            }
            // Ctrl + J -> Justify Align
            if (isCtrl && (e.key === 'j' || e.key === 'J')) {
                e.preventDefault();
                api.setTextAlign('justify');
                return;
            }
            // Ctrl + Z -> Undo, Ctrl + Y / Ctrl+Shift+Z -> Redo
            if (isCtrl && (e.key === 'z' || e.key === 'Z')) {
                e.preventDefault();
                e.stopPropagation();
                if (e.shiftKey) api.redo();
                else api.undo();
                return;
            }
            if (isCtrl && (e.key === 'y' || e.key === 'Y')) {
                e.preventDefault();
                e.stopPropagation();
                api.redo();
                return;
            }

            // Ctrl + Space or Ctrl + \ -> Clear Formatting (Microsoft Word & Google Docs Standard)
            if (isCtrl && (e.code === 'Space' || e.key === ' ' || e.key === '\\')) {
                e.preventDefault();
                api.clearFormatting();
                return;
            }

            // Delete or Backspace for selected asset
            if (e.key === 'Delete' || e.key === 'Backspace') {
                if (window._mouActiveSelectedAsset) {
                    e.preventDefault();
                    api.deleteSelectedAsset();
                    return;
                }
            }
        }
    });

    // Click outside to deselect active asset box
    window.addEventListener('click', (e) => {
        if (!e.target.closest('.mou-header-logo-interactive-box') &&
            !e.target.closest('.mou-image-wrapper') &&
            !e.target.closest('.mou-word-ribbon-bar') &&
            !e.target.closest('#mouWordRibbonBar') &&
            !e.target.closest('.mou-ribbon-group') &&
            !e.target.closest('[data-ribbon-tab-target]') &&
            !e.target.closest('.mou-header-logo-quick-bar')) {
            document.querySelectorAll('.mou-header-logo-interactive-box').forEach(b => {
                b.classList.remove('is-selected');
                const o = b.querySelector('.mou-image-handle-overlay');
                if (o) o.style.display = 'none';
            });
            if (window._mouActiveSelectedAsset?.type === 'header-logo') {
                window._mouActiveSelectedAsset = null;
                hideRulerObjectRange();
                hideSmartGuides();
            }
        }
    });

    if (headerZoneWrapper) {
        headerZoneWrapper.addEventListener('dblclick', (e) => {
            e.stopPropagation();
            setActiveZone('header');
        });
    }
    if (footerZoneWrapper) {
        footerZoneWrapper.addEventListener('dblclick', (e) => {
            e.stopPropagation();
            setActiveZone('footer');
        });
    }
    if (bodyZoneWrapper) {
        bodyZoneWrapper.addEventListener('dblclick', () => {
            if (state.activeZone !== 'body') {
                setActiveZone('body');
            }
        });
    }

    // Ensure clicking ribbon action buttons does not steal/blur editor focus or corrupt active selection
    document.querySelectorAll('.mou-ribbon-btn, #dropdownInsertTable button').forEach(btn => {
        btn.addEventListener('mousedown', (e) => {
            if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'SELECT' && e.target.type !== 'file') {
                e.preventDefault();
            }
        });
    });

    /**
     * Locate row resize candidate by scanning all tr elements in the table.
     * Matches only if hovering precisely within 3px of bottom edge of row i, or top edge of row i+1.
     */
    function findRowResizeCandidate(tableEl, clientY) {
        if (!tableEl) return null;
        const trs = Array.from(tableEl.querySelectorAll('tr'));
        if (!trs.length) return null;
        const hitTolerancePx = 3;

        for (let i = 0; i < trs.length; i++) {
            const tr = trs[i];
            const rect = tr.getBoundingClientRect();
            // 1. Hover near bottom border of row i
            if (Math.abs(clientY - rect.bottom) <= hitTolerancePx) {
                return { tr, rect, rowIndex: i, edge: 'bottom' };
            }
            // 2. Hover near top border of row i (if i > 0, that's bottom of row i-1)
            if (i > 0 && Math.abs(clientY - rect.top) <= hitTolerancePx) {
                return { tr: trs[i - 1], rect: trs[i - 1].getBoundingClientRect(), rowIndex: i - 1, edge: 'bottom' };
            }
        }
        return null;
    }

    /**
     * Locate column resize candidate by scanning first row of cells in table.
     * Matches only if hovering precisely within 3px of divider border.
     */
    function findColumnResizeCandidate(tableEl, clientX) {
        if (!tableEl) return null;
        const firstTr = tableEl.querySelector('tr');
        if (!firstTr) return null;
        const cells = Array.from(firstTr.querySelectorAll('td, th'));
        if (cells.length === 0) return null;
        const hitTolerancePx = 3;

        for (let i = 0; i < cells.length; i++) {
            const cell = cells[i];
            const rect = cell.getBoundingClientRect();
            // Hover near right border of cell i
            if (Math.abs(clientX - rect.right) <= hitTolerancePx) {
                if (i < cells.length - 1) {
                    return { tableEl, colIndex: i, dividerX: rect.right, edge: 'column-divider' };
                } else {
                    return { tableEl, colIndex: i, dividerX: rect.right, edge: 'table-right-edge' };
                }
            }
            // Hover near left border of cell i (if i > 0, that's divider between i-1 and i)
            if (i > 0 && Math.abs(clientX - rect.left) <= hitTolerancePx) {
                return { tableEl, colIndex: i - 1, dividerX: rect.left, edge: 'column-divider' };
            }
        }
        return null;
    }

    /**
     * Compute cell min-content height for a row so upward drag never shrinks below content.
     */
    function computeRowMinContentHeightPx(trEl, scale = 1.0) {
        if (!trEl) return 18;
        const cells = Array.from(trEl.querySelectorAll('td, th'));
        let maxContentH = 18;
        cells.forEach(cell => {
            let innerH = 0;
            Array.from(cell.children).forEach(ch => {
                innerH += ch.offsetHeight || ch.scrollHeight || 0;
            });
            if (innerH > maxContentH) maxContentH = innerH;
        });
        return Math.max(16, Math.min(maxContentH / scale, 28));
    }

    /**
     * Interactive Row Height, Column Width & Whole-Table Width Drag Resizing
     * Adjacent column width compensation ensures total table width never overflows the printable margins.
     * Works inside Body, Header, AND Footer editors, dispatching real ProseMirror transactions.
     */
    function attachInteractiveTableResizers(mountContainer, getEditorInstance) {
        if (!mountContainer) return;

        let dragMode = null; // 'row-height' | 'table-width' | 'col-width'
        let targetRowEl = null;
        let targetTableEl = null;
        let startRowIndex = -1;
        let targetColIndex = -1;
        let currentCalculatedHeightPt = null;
        let currentCalculatedWidthPt = null;
        let currentCalculatedLeftColPx = null;
        let currentCalculatedRightColPx = null;
        let startY = 0;
        let startX = 0;
        let startDividerX = 0;
        let startHeightPx = 0;
        let startWidthPx = 0;
        let startLeftColWidthPx = 0;
        let startRightColWidthPx = 0;
        let totalPairWidthPx = 0;
        let allColWidthsPx = [];
        let minContentHeightPx = 18;

        const guideLine = document.createElement('div');
        guideLine.className = 'mou-resize-guide-line hidden no-print';
        const guideBadge = document.createElement('span');
        guideBadge.className = 'mou-resize-guide-badge';
        guideLine.appendChild(guideBadge);
        document.body.appendChild(guideLine);

        mountContainer.addEventListener('mousemove', (e) => {
            if (dragMode) return;
            // Jika user sedang drag memilih teks (tombol kiri ditekan tanpa mode drag tabel), jangan ganggu seleksi teks!
            if (e.buttons === 1) return;

            const editor = getEditorInstance();
            if (!editor || !editor.isEditable) return;

            // Jika kursor berada tepat di atas konten teks / paragraf / span, prioritaskan seleksi teks biasa
            if (e.target && e.target.closest('p, span, [data-token], a, strong, em, code, mark, [data-placeholder]')) {
                if (mountContainer.style.cursor === 'row-resize' || mountContainer.style.cursor === 'col-resize' || mountContainer.style.cursor === 'ew-resize') {
                    mountContainer.style.cursor = '';
                }
                return;
            }

            let table = e.target.closest('table');
            if (!table) {
                const el = document.elementFromPoint(e.clientX, e.clientY);
                table = el ? el.closest('table') : null;
            }
            if (table) {
                const rowCandidate = findRowResizeCandidate(table, e.clientY);
                if (rowCandidate) {
                    mountContainer.style.cursor = 'row-resize';
                    return;
                }
                const colCandidate = findColumnResizeCandidate(table, e.clientX);
                if (colCandidate) {
                    mountContainer.style.cursor = colCandidate.edge === 'column-divider' ? 'col-resize' : 'ew-resize';
                    return;
                }
            }
            if (mountContainer.style.cursor === 'row-resize' || mountContainer.style.cursor === 'col-resize' || mountContainer.style.cursor === 'ew-resize') {
                mountContainer.style.cursor = '';
            }
        });

        mountContainer.addEventListener('mousedown', (e) => {
            const editor = getEditorInstance();
            if (!editor || !editor.isEditable) return;

            // 1. Check whole-table width handle
            const widthHandle = e.target.closest('.mou-table-width-handle');
            if (widthHandle) {
                const wrapper = widthHandle.closest('.tableWrapper') || widthHandle.parentElement;
                const table = wrapper ? wrapper.querySelector('table') : null;
                if (table) {
                    e.preventDefault();
                    e.stopPropagation();
                    dragMode = 'table-width';
                    targetTableEl = table;
                    startX = e.clientX;
                    const scale = (state.zoomPercent || 100) / 100;
                    startWidthPx = table.getBoundingClientRect().width / scale;
                    currentCalculatedWidthPt = Math.round((startWidthPx * 72) / 96);
                    document.body.style.cursor = 'ew-resize';
                    return;
                }
            }

            // Jika pengguna mengklik di atas teks, JANGAN intercept mousedown agar ProseMirror dapat melakukan seleksi teks bebas!
            const isClickingOnText = !!(e.target && e.target.closest('p, span, [data-token], a, strong, em, code, mark, [data-placeholder]'));
            if (isClickingOnText) {
                return;
            }

            // Hanya aktifkan resizer jika kursor memang berada pada divider garis (row-resize atau col-resize)
            const isResizeCursor = mountContainer.style.cursor === 'row-resize' || mountContainer.style.cursor === 'col-resize' || mountContainer.style.cursor === 'ew-resize';
            if (!isResizeCursor) {
                return;
            }

            // 2. Check row border or column border drag across whole table
            let table = e.target.closest('table');
            if (!table) {
                const el = document.elementFromPoint(e.clientX, e.clientY);
                table = el ? el.closest('table') : null;
            }
            if (table) {
                const rowCandidate = findRowResizeCandidate(table, e.clientY);
                if (rowCandidate) {
                    e.preventDefault();
                    e.stopPropagation();
                    dragMode = 'row-height';
                    targetRowEl = rowCandidate.tr;
                    targetTableEl = table;
                    startRowIndex = rowCandidate.rowIndex;
                    startY = e.clientY;
                    const scale = (state.zoomPercent || 100) / 100;
                    startHeightPx = rowCandidate.rect.height / scale;
                    minContentHeightPx = computeRowMinContentHeightPx(targetRowEl, scale);
                    currentCalculatedHeightPt = Math.round((startHeightPx * 72) / 96);

                    guideLine.classList.remove('hidden');
                    guideLine.style.top = `${e.clientY}px`;
                    guideLine.style.left = `${rowCandidate.rect.left}px`;
                    guideLine.style.width = `${rowCandidate.rect.width}px`;
                    guideLine.style.height = '0px';
                    guideLine.style.borderTop = '2px dashed #1d4ed8';
                    guideLine.style.borderLeft = 'none';

                    guideBadge.textContent = `Tinggi Baris: ${currentCalculatedHeightPt} pt (${(startHeightPx / MM_TO_PX).toFixed(1)} mm)`;
                    guideBadge.style.backgroundColor = '#1d4ed8';
                    document.body.style.cursor = 'row-resize';
                    return;
                }

                const colCandidate = findColumnResizeCandidate(table, e.clientX);
                if (colCandidate) {
                    e.preventDefault();
                    e.stopPropagation();
                    const scale = (state.zoomPercent || 100) / 100;

                    if (colCandidate.edge === 'column-divider') {
                        dragMode = 'col-width';
                        targetTableEl = table;
                        targetColIndex = colCandidate.colIndex;
                        startX = e.clientX;
                        startDividerX = colCandidate.dividerX;

                        const firstTr = targetTableEl.querySelector('tr');
                        const cells = firstTr ? Array.from(firstTr.querySelectorAll('td, th')) : [];
                        allColWidthsPx = cells.map(c => Math.round(c.getBoundingClientRect().width / scale));
                        startLeftColWidthPx = allColWidthsPx[targetColIndex] || 50;
                        startRightColWidthPx = allColWidthsPx[targetColIndex + 1] || 50;
                        totalPairWidthPx = startLeftColWidthPx + startRightColWidthPx;
                        currentCalculatedLeftColPx = startLeftColWidthPx;
                        currentCalculatedRightColPx = startRightColWidthPx;

                        const tableRect = targetTableEl.getBoundingClientRect();
                        guideLine.classList.remove('hidden');
                        guideLine.style.top = `${tableRect.top}px`;
                        guideLine.style.height = `${tableRect.height}px`;
                        guideLine.style.left = `${colCandidate.dividerX}px`;
                        guideLine.style.width = '0px';
                        guideLine.style.borderLeft = '2px dashed #2563eb';
                        guideLine.style.borderTop = 'none';

                        const leftMm = (startLeftColWidthPx / MM_TO_PX).toFixed(1);
                        const leftPt = Math.round((startLeftColWidthPx * 72) / 96);
                        const rightMm = (startRightColWidthPx / MM_TO_PX).toFixed(1);
                        const rightPt = Math.round((startRightColWidthPx * 72) / 96);
                        guideBadge.textContent = `Kol ${targetColIndex + 1}: ${leftMm} mm (${leftPt} pt) • Kol ${targetColIndex + 2}: ${rightMm} mm (${rightPt} pt)`;
                        guideBadge.style.backgroundColor = '#2563eb';
                        document.body.style.cursor = 'col-resize';
                        return;
                    } else if (colCandidate.edge === 'table-right-edge') {
                        dragMode = 'table-width';
                        targetTableEl = table;
                        startX = e.clientX;
                        startWidthPx = targetTableEl.getBoundingClientRect().width / scale;
                        currentCalculatedWidthPt = Math.round((startWidthPx * 72) / 96);
                        document.body.style.cursor = 'ew-resize';
                        return;
                    }
                }
            }
        });

        window.addEventListener('mousemove', (e) => {
            if (!dragMode) return;
            const scale = (state.zoomPercent || 100) / 100;

            if (dragMode === 'row-height') {
                const deltaY = (e.clientY - startY) / scale;
                const rawHeightPx = startHeightPx + deltaY;
                const newHeightPx = Math.max(minContentHeightPx, rawHeightPx);
                const isAtMin = rawHeightPx <= minContentHeightPx;
                const ptVal = Math.max(12, Math.round((newHeightPx * 72) / 96));
                const mmVal = (newHeightPx / MM_TO_PX).toFixed(1);
                currentCalculatedHeightPt = ptVal;

                guideLine.style.top = `${e.clientY}px`;
                if (isAtMin) {
                    guideBadge.textContent = `Tinggi Baris: ${ptVal} pt (${mmVal} mm) • Min. Isi Sel`;
                    guideBadge.style.backgroundColor = '#d97706';
                } else {
                    guideBadge.textContent = `Tinggi Baris: ${ptVal} pt (${mmVal} mm)`;
                    guideBadge.style.backgroundColor = '#1d4ed8';
                }
            } else if (dragMode === 'col-width') {
                const deltaX = (e.clientX - startX) / scale;
                const minColW = 14;
                const newLeftW = Math.max(minColW, Math.min(totalPairWidthPx - minColW, Math.round(startLeftColWidthPx + deltaX)));
                const newRightW = totalPairWidthPx - newLeftW;
                currentCalculatedLeftColPx = newLeftW;
                currentCalculatedRightColPx = newRightW;

                const actualDeltaPx = (newLeftW - startLeftColWidthPx) * scale;
                guideLine.style.left = `${startDividerX + actualDeltaPx}px`;

                const leftMm = (newLeftW / MM_TO_PX).toFixed(1);
                const leftPt = Math.round((newLeftW * 72) / 96);
                const rightMm = (newRightW / MM_TO_PX).toFixed(1);
                const rightPt = Math.round((newRightW * 72) / 96);
                guideBadge.textContent = `Kol ${targetColIndex + 1}: ${leftMm} mm (${leftPt} pt) • Kol ${targetColIndex + 2}: ${rightMm} mm (${rightPt} pt)`;
            } else if (dragMode === 'table-width') {
                const deltaX = (e.clientX - startX) / scale;
                const maxPrintableW = getPrintableWidthPx();
                const rawWidthPx = startWidthPx + deltaX;
                const newWidthPx = Math.max(120, Math.min(maxPrintableW, Math.round(rawWidthPx)));
                const ptVal = Math.round((newWidthPx * 72) / 96);
                currentCalculatedWidthPt = ptVal;
            }
        }, { capture: true });

        window.addEventListener('mouseup', () => {
            if (!dragMode) return;
            const editor = getEditorInstance();
            guideLine.classList.add('hidden');
            document.body.style.cursor = '';

            if (dragMode === 'row-height' && editor && currentCalculatedHeightPt !== null) {
                const finalRowHeight = `${currentCalculatedHeightPt}pt`;
                applyRowHeightTransaction(editor, targetRowEl, finalRowHeight, targetTableEl, startRowIndex);
            } else if (dragMode === 'col-width' && editor && targetTableEl && targetColIndex >= 0 && currentCalculatedLeftColPx !== null && currentCalculatedRightColPx !== null) {
                applyColumnPairWidthTransaction(editor, targetTableEl, targetColIndex, currentCalculatedLeftColPx, currentCalculatedRightColPx, allColWidthsPx);
            } else if (dragMode === 'table-width' && editor && currentCalculatedWidthPt !== null) {
                const finalTableWidth = `${currentCalculatedWidthPt}pt`;
                applyTableWidthTransaction(editor, targetTableEl, finalTableWidth);
            }

            dragMode = null;
            targetRowEl = null;
            targetTableEl = null;
            startRowIndex = -1;
            targetColIndex = -1;
            currentCalculatedHeightPt = null;
            currentCalculatedWidthPt = null;
            currentCalculatedLeftColPx = null;
            currentCalculatedRightColPx = null;
            scheduleSyncAndPagination();
        }, { capture: true });
    }

    /**
     * Dispatch a ProseMirror transaction to adjust adjacent column widths compensating each other.
     * Guaranteed to maintain exact table width and prevent overflowing outside page margins.
     */
    function applyColumnPairWidthTransaction(editor, tableDom, colIdx, newLeftW, newRightW, allColWidths = []) {
        if (!editor || !editor.view || !tableDom) return;
        try {
            const cell = tableDom.querySelector('td, th');
            if (!cell) return;
            const cellPos = editor.view.posAtDOM(cell, 0);
            if (cellPos < 0) return;
            const $pos = editor.state.doc.resolve(cellPos);
            let tablePos = null;
            let tableNode = null;
            for (let d = $pos.depth; d >= 0; d--) {
                if ($pos.node(d).type.name === 'table') {
                    tablePos = $pos.before(d);
                    tableNode = $pos.node(d);
                    break;
                }
            }
            if (tablePos === null || !tableNode) return;

            const tr = editor.state.tr;
            let rowOffset = tablePos + 1;
            for (let r = 0; r < tableNode.childCount; r++) {
                const row = tableNode.child(r);
                let cellOffset = rowOffset + 1;
                let colAcc = 0;
                for (let c = 0; c < row.childCount; c++) {
                    const cNode = row.child(c);
                    const colspan = cNode.attrs.colspan || 1;
                    if (colspan === 1) {
                        if (colAcc === colIdx) {
                            tr.setNodeMarkup(cellOffset, undefined, {
                                ...cNode.attrs,
                                colwidth: [newLeftW],
                                cellWidth: `${newLeftW}px`,
                            });
                        } else if (colAcc === colIdx + 1) {
                            tr.setNodeMarkup(cellOffset, undefined, {
                                ...cNode.attrs,
                                colwidth: [newRightW],
                                cellWidth: `${newRightW}px`,
                            });
                        } else if (allColWidths[colAcc] && (!cNode.attrs.colwidth || !cNode.attrs.colwidth[0])) {
                            tr.setNodeMarkup(cellOffset, undefined, {
                                ...cNode.attrs,
                                colwidth: [allColWidths[colAcc]],
                                cellWidth: `${allColWidths[colAcc]}px`,
                            });
                        }
                    }
                    colAcc += colspan;
                    cellOffset += cNode.nodeSize;
                }
                rowOffset += row.nodeSize;
            }

            editor.view.dispatch(tr);
            const cwInput = document.getElementById('ribbonColWidthInput');
            if (cwInput) cwInput.value = String(newLeftW);
        } catch (err) {
            console.warn('Column width transaction error:', err);
        }
    }

    /**
     * Dispatch a deterministic ProseMirror transaction to persist rowHeight on a DOM <tr> element's node and child cells.
     */
    function applyRowHeightTransaction(editor, trDom, rowHeightValue, tableDomFallback = null, rowIndexFallback = -1) {
        if (!editor || !editor.view) return;
        try {
            const tableDom = trDom?.closest?.('table') || tableDomFallback;
            if (!tableDom) return;
            const allTrs = Array.from(tableDom.querySelectorAll('tr'));
            let rowIndex = trDom ? allTrs.indexOf(trDom) : -1;
            if (rowIndex === -1 && typeof rowIndexFallback === 'number') {
                rowIndex = rowIndexFallback;
            }
            if (rowIndex === -1) return;

            let tablePos = null;
            let tableNode = null;
            const targetTr = allTrs[rowIndex] || trDom;
            const td = targetTr ? targetTr.querySelector('td, th') : null;
            if (td) {
                try {
                    const cellPos = editor.view.posAtDOM(td, 0);
                    if (cellPos >= 0) {
                        const $pos = editor.state.doc.resolve(cellPos);
                        for (let d = $pos.depth; d >= 0; d--) {
                            if ($pos.node(d).type.name === 'table') {
                                tablePos = $pos.before(d);
                                tableNode = $pos.node(d);
                                break;
                            }
                        }
                    }
                } catch (_) {}
            }

            if (tablePos === null || !tableNode) {
                editor.state.doc.descendants((node, pos) => {
                    if (node.type.name === 'table' && tablePos === null) {
                        tablePos = pos;
                        tableNode = node;
                        return false;
                    }
                });
            }

            if (tablePos === null || !tableNode || rowIndex >= tableNode.childCount) return;

            let trPos = tablePos + 1;
            for (let i = 0; i < rowIndex; i++) {
                trPos += tableNode.child(i).nodeSize;
            }
            const trNode = tableNode.child(rowIndex);

            const tr = editor.state.tr;
            tr.setNodeMarkup(trPos, undefined, {
                ...trNode.attrs,
                rowHeight: rowHeightValue,
            });

            let offset = trPos + 1;
            for (let i = 0; i < trNode.childCount; i++) {
                const cell = trNode.child(i);
                tr.setNodeMarkup(offset, undefined, {
                    ...cell.attrs,
                    rowHeight: rowHeightValue,
                });
                offset += cell.nodeSize;
            }

            editor.view.dispatch(tr);

            const rowHeightInput = document.getElementById('ribbonRowHeightInput');
            if (rowHeightInput) {
                rowHeightInput.value = String(rowHeightValue).replace(/[^0-9.]/g, '');
            }
        } catch (err) {
            console.warn('Row height transaction error:', err);
        }
    }

    /**
     * Dispatch a ProseMirror transaction to persist tableWidth on a DOM <table> element's node.
     * Clamped to printable width so table never exceeds physical page boundary.
     */
    function applyTableWidthTransaction(editor, tableDom, tableWidthValue) {
        if (!editor || !editor.view || !tableDom) return;
        try {
            const maxPrintableW = getPrintableWidthPx();
            let safeWidth = tableWidthValue;
            if (typeof tableWidthValue === 'string' && tableWidthValue.endsWith('pt')) {
                const pt = parseFloat(tableWidthValue) || 0;
                const px = (pt * 96) / 72;
                if (px > maxPrintableW) {
                    safeWidth = `${Math.round((maxPrintableW * 72) / 96)}pt`;
                }
            }

            const cell = tableDom.querySelector('td, th');
            let tablePos = null;
            let tableNode = null;
            if (cell) {
                const cellPos = editor.view.posAtDOM(cell, 0);
                if (cellPos >= 0) {
                    const $pos = editor.state.doc.resolve(cellPos);
                    for (let d = $pos.depth; d >= 0; d--) {
                        if ($pos.node(d).type.name === 'table') {
                            tablePos = $pos.before(d);
                            tableNode = $pos.node(d);
                            break;
                        }
                    }
                }
            }

            if (tablePos !== null && tableNode) {
                const tr = editor.state.tr.setNodeMarkup(tablePos, undefined, {
                    ...tableNode.attrs,
                    tableWidth: safeWidth,
                });

                // Measure old total width and new total width to scale child columns proportionally
                let newWidthPx = maxPrintableW;
                if (typeof safeWidth === 'string' && safeWidth.endsWith('pt')) {
                    newWidthPx = (parseFloat(safeWidth) * 96) / 72;
                } else if (typeof safeWidth === 'string' && safeWidth.endsWith('%')) {
                    newWidthPx = Math.round((parseFloat(safeWidth) / 100) * maxPrintableW);
                }

                if (tableNode.childCount > 0) {
                    const firstRow = tableNode.child(0);
                    let oldTotal = 0;
                    const oldWidths = [];
                    for (let c = 0; c < firstRow.childCount; c++) {
                        const cellNode = firstRow.child(c);
                        const w = (Array.isArray(cellNode.attrs.colwidth) && cellNode.attrs.colwidth[0])
                            ? Number(cellNode.attrs.colwidth[0])
                            : (parseInt(cellNode.attrs.cellWidth, 10) || 50);
                        oldWidths.push(w);
                        oldTotal += w;
                    }
                    if (oldTotal > 0 && Math.abs(oldTotal - newWidthPx) > 5) {
                        const ratio = newWidthPx / oldTotal;
                        let rowOffset = tablePos + 1;
                        for (let r = 0; r < tableNode.childCount; r++) {
                            const row = tableNode.child(r);
                            let cellOffset = rowOffset + 1;
                            for (let c = 0; c < row.childCount; c++) {
                                const cNode = row.child(c);
                                const newW = Math.max(14, Math.floor((oldWidths[c] || 50) * ratio));
                                tr.setNodeMarkup(cellOffset, undefined, {
                                    ...cNode.attrs,
                                    colwidth: [newW],
                                    cellWidth: `${newW}px`,
                                });
                                cellOffset += cNode.nodeSize;
                            }
                            rowOffset += row.nodeSize;
                        }
                    }
                }

                editor.view.dispatch(tr);
                const tableWidthInput = document.getElementById('ribbonTableWidthInput');
                if (tableWidthInput) tableWidthInput.value = safeWidth;
            }
        } catch (err) {
            console.warn('Table width transaction fallback:', err);
        }
    }

    attachInteractiveTableResizers(bodyMountEl, () => bodyEditor);
    attachInteractiveTableResizers(headerMountEl, () => headerEditor);
    attachInteractiveTableResizers(footerMountEl, () => footerEditor);

    /**
     * Update active table attributes for the currently focused table/row/cell via Ribbon controls.
     */
    function setCurrentRowHeight(heightValue) {
        const editor = getActiveEditor();
        if (!editor) return;
        const { state: edState } = editor;
        const { $from } = edState.selection;
        for (let d = $from.depth; d >= 0; d--) {
            const node = $from.node(d);
            if (node.type.name === 'tableRow') {
                const trPos = $from.before(d);
                const tr = edState.tr.setNodeMarkup(trPos, undefined, {
                    ...node.attrs,
                    rowHeight: heightValue || null,
                });
                let offset = trPos + 1;
                for (let i = 0; i < node.childCount; i++) {
                    const cell = node.child(i);
                    tr.setNodeMarkup(offset, undefined, {
                        ...cell.attrs,
                        rowHeight: heightValue || null,
                    });
                    offset += cell.nodeSize;
                }
                editor.view.dispatch(tr);
                scheduleSyncAndPagination();
                return;
            }
        }
    }

    function setCurrentTableWidth(widthValue) {
        const editor = getActiveEditor();
        if (!editor) return;
        const { state: edState } = editor;
        const { $from } = edState.selection;
        for (let d = $from.depth; d >= 0; d--) {
            const node = $from.node(d);
            if (node.type.name === 'table') {
                const tablePos = $from.before(d);
                const tr = edState.tr.setNodeMarkup(tablePos, undefined, {
                    ...node.attrs,
                    tableWidth: widthValue || '100%',
                });
                editor.view.dispatch(tr);
                scheduleSyncAndPagination();
                return;
            }
        }
    }

    function setCurrentTableAlign(alignValue) {
        const editor = getActiveEditor();
        if (!editor) return;
        const { state: edState } = editor;
        const { $from } = edState.selection;
        for (let d = $from.depth; d >= 0; d--) {
            const node = $from.node(d);
            if (node.type.name === 'table') {
                const tablePos = $from.before(d);
                const tr = edState.tr.setNodeMarkup(tablePos, undefined, {
                    ...node.attrs,
                    tableAlign: alignValue || null,
                });
                editor.view.dispatch(tr);
                scheduleSyncAndPagination();
                return;
            }
        }
    }

    function setCurrentColumnWidth(widthPxValue) {
        const editor = getActiveEditor();
        if (!editor) return;
        const pxInt = parseInt(widthPxValue, 10);
        if (isNaN(pxInt) || pxInt < 24) return;

        // Update all cells in the same column index of the active table
        const { state: edState } = editor;
        const { $from } = edState.selection;
        let tableNode = null;
        let tablePos = null;
        let targetColIndex = 0;

        for (let d = $from.depth; d >= 0; d--) {
            const n = $from.node(d);
            if (n.type.name === 'tableRow') {
                const cellIndex = $from.index(d);
                let colAcc = 0;
                for (let i = 0; i < cellIndex; i++) {
                    colAcc += n.child(i).attrs.colspan || 1;
                }
                targetColIndex = colAcc;
            }
            if (n.type.name === 'table') {
                tableNode = n;
                tablePos = $from.before(d);
                break;
            }
        }

        if (tableNode && tablePos !== null) {
            const tr = edState.tr;
            let rowOffset = tablePos + 1;
            for (let r = 0; r < tableNode.childCount; r++) {
                const row = tableNode.child(r);
                let cellOffset = rowOffset + 1;
                let colAcc = 0;
                for (let c = 0; c < row.childCount; c++) {
                    const cell = row.child(c);
                    const colspan = cell.attrs.colspan || 1;
                    if (colAcc === targetColIndex && colspan === 1) {
                        tr.setNodeMarkup(cellOffset, undefined, {
                            ...cell.attrs,
                            colwidth: [pxInt],
                            cellWidth: `${pxInt}px`,
                        });
                    }
                    colAcc += colspan;
                    cellOffset += cell.nodeSize;
                }
                rowOffset += row.nodeSize;
            }
            editor.view.dispatch(tr);
            scheduleSyncAndPagination();
        }
    }

    function adjustRowHeightSpin(deltaPt) {
        const editor = getActiveEditor();
        if (!editor) return;
        const { $from } = editor.state.selection;
        let currentPt = 18;
        for (let d = $from.depth; d >= 0; d--) {
            const node = $from.node(d);
            if (node.type.name === 'tableRow') {
                if (node.attrs.rowHeight) {
                    currentPt = parseFloat(String(node.attrs.rowHeight).replace(/[^0-9.]/g, '')) || 18;
                }
                break;
            }
        }
        const newPt = Math.max(12, Math.round(currentPt + deltaPt));
        setCurrentRowHeight(`${newPt}pt`);
        const input = document.getElementById('ribbonRowHeightInput');
        if (input) input.value = String(newPt);
    }

    function adjustColWidthSpin(deltaPx) {
        const editor = getActiveEditor();
        if (!editor) return;
        const input = document.getElementById('ribbonColWidthInput');
        let currentPx = input && input.value ? (parseInt(input.value, 10) || 120) : 120;
        const maxPrintableW = getPrintableWidthPx();
        const newPx = Math.max(30, Math.min(maxPrintableW - 40, currentPx + deltaPx));
        setCurrentColumnWidth(newPx);
        if (input) input.value = String(newPx);
    }

    function adjustTableWidthSpin(deltaPercent) {
        const input = document.getElementById('ribbonTableWidthInput');
        let cur = input && input.value ? (parseInt(input.value.replace(/[^0-9]/g, ''), 10) || 100) : 100;
        const newPct = Math.max(30, Math.min(100, cur + deltaPercent));
        setCurrentTableWidth(`${newPct}%`);
        if (input) input.value = `${newPct}%`;
    }

    function distributeColumnsEvenly() {
        const editor = getActiveEditor();
        if (!editor) return;
        const { state: edState } = editor;
        const { $from } = edState.selection;
        let tableNode = null;
        let tablePos = null;
        for (let d = $from.depth; d >= 0; d--) {
            if ($from.node(d).type.name === 'table') {
                tableNode = $from.node(d);
                tablePos = $from.before(d);
                break;
            }
        }
        if (!tableNode || tablePos === null || tableNode.childCount === 0) return;

        const firstRow = tableNode.child(0);
        let colCount = 0;
        for (let i = 0; i < firstRow.childCount; i++) {
            colCount += firstRow.child(i).attrs.colspan || 1;
        }
        if (colCount <= 0) return;

        const maxPrintableW = getPrintableWidthPx();
        const colWidthPx = Math.floor(maxPrintableW / colCount);
        const remainderPx = maxPrintableW - (colWidthPx * colCount);

        const tr = edState.tr;
        tr.setNodeMarkup(tablePos, undefined, {
            ...tableNode.attrs,
            tableWidth: '100%',
        });

        let rowOffset = tablePos + 1;
        for (let r = 0; r < tableNode.childCount; r++) {
            const row = tableNode.child(r);
            let cellOffset = rowOffset + 1;
            let colAcc = 0;
            for (let c = 0; c < row.childCount; c++) {
                const cell = row.child(c);
                const colspan = cell.attrs.colspan || 1;
                let cellW = colWidthPx * colspan;
                if (colAcc + colspan === colCount) {
                    cellW += remainderPx;
                }
                tr.setNodeMarkup(cellOffset, undefined, {
                    ...cell.attrs,
                    colwidth: [cellW],
                    cellWidth: `${cellW}px`,
                });
                colAcc += colspan;
                cellOffset += cell.nodeSize;
            }
            rowOffset += row.nodeSize;
        }

        editor.view.dispatch(tr);
        scheduleSyncAndPagination();
    }

    function fitTableToMargins() {
        const editor = getActiveEditor();
        if (!editor) return;
        const { state: edState } = editor;
        const { $from } = edState.selection;
        let tableNode = null;
        let tablePos = null;
        for (let d = $from.depth; d >= 0; d--) {
            if ($from.node(d).type.name === 'table') {
                tableNode = $from.node(d);
                tablePos = $from.before(d);
                break;
            }
        }
        if (!tableNode || tablePos === null || tableNode.childCount === 0) return;

        // Choose representative row with highest cell count so merged header rows don't truncate column widths
        let representativeRow = tableNode.child(0);
        for (let r = 0; r < tableNode.childCount; r++) {
            const row = tableNode.child(r);
            if (row.childCount > representativeRow.childCount) {
                representativeRow = row;
            }
        }

        let colWidths = [];
        let totalCurrentW = 0;
        for (let i = 0; i < representativeRow.childCount; i++) {
            const cell = representativeRow.child(i);
            const w = Array.isArray(cell.attrs.colwidth) && cell.attrs.colwidth[0]
                ? Number(cell.attrs.colwidth[0])
                : (parseInt(cell.attrs.cellWidth, 10) || 100);
            colWidths.push(w);
            totalCurrentW += w;
        }

        const maxPrintableW = getPrintableWidthPx();
        if (totalCurrentW <= 0) {
            distributeColumnsEvenly();
            return;
        }

        const ratio = maxPrintableW / totalCurrentW;
        let scaledWidths = colWidths.map(w => Math.max(14, Math.floor(w * ratio)));
        const scaledSum = scaledWidths.reduce((a, b) => a + b, 0);
        const diff = maxPrintableW - scaledSum;
        if (scaledWidths.length > 0) {
            scaledWidths[scaledWidths.length - 1] += diff;
        }

        const tr = edState.tr;
        tr.setNodeMarkup(tablePos, undefined, {
            ...tableNode.attrs,
            tableWidth: '100%',
        });

        let rowOffset = tablePos + 1;
        for (let r = 0; r < tableNode.childCount; r++) {
            const row = tableNode.child(r);
            let cellOffset = rowOffset + 1;
            let colIndex = 0;
            for (let c = 0; c < row.childCount; c++) {
                const cell = row.child(c);
                const colspan = Number(cell.attrs.colspan) || 1;
                let cellW = 0;
                if (colspan >= scaledWidths.length) {
                    cellW = maxPrintableW;
                } else {
                    for (let span = 0; span < colspan; span++) {
                        cellW += scaledWidths[colIndex + span] || Math.floor(maxPrintableW / scaledWidths.length);
                    }
                }
                tr.setNodeMarkup(cellOffset, undefined, {
                    ...cell.attrs,
                    colwidth: [cellW],
                    cellWidth: `${cellW}px`,
                });
                colIndex += colspan;
                cellOffset += cell.nodeSize;
            }
            rowOffset += row.nodeSize;
        }

        editor.view.dispatch(tr);
        scheduleSyncAndPagination();
    }

    function adjustFontSizeSpin(deltaPt) {
        const editor = getActiveEditor();
        if (!editor) return;
        const input = document.getElementById('ribbonFontSizeInput') || document.getElementById('ribbonFontSize');
        let cur = state.fontSizePt || 12;
        if (input && input.value) {
            cur = parseFloat(String(input.value).replace(/[^0-9.]/g, '')) || cur;
        }
        const newPt = Math.max(6, Math.min(72, cur + deltaPt));
        state.fontSizePt = newPt;
        editor.chain().focus().setFontSize(`${newPt}pt`).run();
        if (input) input.value = `${newPt}pt`;
        scheduleSyncAndPagination();
    }

    function setZoomPercent(zoomVal) {
        const scale = (parseFloat(zoomVal) || 100) / 100;
        state.zoomPercent = Math.round(scale * 100);
        const deskStage = document.getElementById('mouPagesDeskStage');
        if (deskStage) {
            deskStage.style.transform = `scale(${scale})`;
            deskStage.style.transformOrigin = 'top center';
            const totalSheets = deskStage.querySelectorAll('.mou-a4-paper-sheet').length || 1;
            const a4H = 297 * MM_TO_PX;
            const naturalH = totalSheets * a4H + (totalSheets - 1) * 24;
            const scaledH = naturalH * scale;
            deskStage.style.marginBottom = `${Math.max(48, scaledH - naturalH + 48)}px`;
        }
        requestAnimationFrame(() => {
            syncRulerToPageSheet();
        });
    }

    function updateActiveTableCells(attrUpdates, applyToWholeTable = false) {
        const editor = getActiveEditor();
        if (!editor) return;
        const { state: edState } = editor;
        const { selection } = edState;
        const { $from } = selection;

        let tableDepth = -1;
        let tableNode = null;
        for (let d = $from.depth; d >= 0; d--) {
            const n = $from.node(d);
            if (n.type.name === 'table') {
                tableDepth = d;
                tableNode = n;
                break;
            }
        }

        if (!tableNode || tableDepth === -1) {
            return;
        }

        const tableStart = $from.before(tableDepth);
        const tableEnd = $from.after(tableDepth);
        const tr = edState.tr;

        if (applyToWholeTable) {
            let rowOffset = tableStart + 1;
            for (let r = 0; r < tableNode.childCount; r++) {
                const row = tableNode.child(r);
                let cellOffset = rowOffset + 1;
                for (let c = 0; c < row.childCount; c++) {
                    const cell = row.child(c);
                    tr.setNodeMarkup(cellOffset, undefined, {
                        ...cell.attrs,
                        ...attrUpdates,
                    });
                    cellOffset += cell.nodeSize;
                }
                rowOffset += row.nodeSize;
            }
            editor.view.dispatch(tr);
            scheduleSyncAndPagination();
            return;
        }

        // Apply only to selected cells, strictly constrained within this table [tableStart, tableEnd]
        let updatedAny = false;
        if (selection.forEachCell) {
            selection.forEachCell((node, pos) => {
                if (pos >= tableStart && pos <= tableEnd) {
                    tr.setNodeMarkup(pos, undefined, {
                        ...node.attrs,
                        ...attrUpdates,
                    });
                    updatedAny = true;
                }
            });
        } else {
            for (let d = $from.depth; d > tableDepth; d--) {
                const n = $from.node(d);
                if (n.type.name === 'tableCell' || n.type.name === 'tableHeader') {
                    const cellPos = $from.before(d);
                    tr.setNodeMarkup(cellPos, undefined, {
                        ...n.attrs,
                        ...attrUpdates,
                    });
                    updatedAny = true;
                    break;
                }
            }
        }

        if (updatedAny) {
            editor.view.dispatch(tr);
            scheduleSyncAndPagination();
        }
    }

    /**
     * Safely delete strictly the active table at cursor depth.
     * Guaranteed to never affect or delete any other table in the document.
     */
    function deleteCurrentTable() {
        const editor = getActiveEditor();
        if (!editor) return;
        const { state: edState } = editor;
        const { selection } = edState;
        const { $from } = selection;

        let tableDepth = -1;
        let tableNode = null;
        for (let d = $from.depth; d >= 0; d--) {
            const n = $from.node(d);
            if (n.type.name === 'table') {
                tableDepth = d;
                tableNode = n;
                break;
            }
        }

        if (!tableNode || tableDepth === -1) {
            if (selection.node && selection.node.type.name === 'table') {
                const tr = edState.tr.delete(selection.from, selection.to);
                if (tr.doc.content.size === 0 || tr.doc.childCount === 0) {
                    tr.insert(0, editor.schema.nodes.paragraph.create());
                }
                editor.view.dispatch(tr);
                scheduleSyncAndPagination();
                scheduleReflow(true);
            }
            return;
        }

        const tablePos = $from.before(tableDepth);
        const tableEnd = $from.after(tableDepth);
        const tr = edState.tr;

        // Delete ONLY the exact range of this active table
        tr.delete(tablePos, tableEnd);

        // Check neighboring nodes at the deletion boundary
        const safePos = Math.min(tablePos, tr.doc.content.size);
        const $resolved = tr.doc.resolve(safePos);
        const nodeBefore = $resolved.nodeBefore;
        const nodeAfter = $resolved.nodeAfter;

        // If deletion leaves adjacent tables touching or page/doc becomes empty,
        // insert an empty paragraph placeholder so tables are safely isolated.
        const isTouchingTable = (nodeBefore && nodeBefore.type.name === 'table') || (nodeAfter && nodeAfter.type.name === 'table');
        const isParentPageEmpty = $resolved.parent && $resolved.parent.type.name === 'mouPage' && $resolved.parent.childCount === 0;
        const isDocEmpty = tr.doc.childCount === 0;

        if (isTouchingTable || isParentPageEmpty || isDocEmpty) {
            const pNode = editor.schema.nodes.paragraph.create();
            tr.insert(tablePos, pNode);
            try {
                tr.setSelection(editor.state.selection.constructor.near(tr.doc.resolve(tablePos + 1)));
            } catch (e) {}
        }

        editor.view.dispatch(tr);
        scheduleSyncAndPagination();
        scheduleReflow(true);
    }

    /**
     * Reflow visual page breaks and layout guides across sheets.
     */
    function updateVisualPageBreakGuides() {
        if (typeof scheduleReflow === 'function') {
            scheduleReflow(false);
        }
    }

    /**
     * Synchronize all editors and page configuration to hidden form inputs.
     */
    let syncFrame = null;
    function scheduleSyncAndPagination() {
        if (syncFrame) cancelAnimationFrame(syncFrame);
        syncFrame = requestAnimationFrame(() => {
            syncAllToHiddenInputs();
            updateVisualPageBreakGuides();
            updateDocumentMetrics();
        });
    }

    function getPaperDimensionsMm() {
        const baseMap = {
            A4: { width: 210, height: 297 },
            LETTER: { width: 215.9, height: 279.4 },
            LEGAL: { width: 215.9, height: 355.6 },
        };
        const base = baseMap[state.paperSize] || baseMap.A4;
        if (state.orientation === 'landscape') {
            return { width: base.height, height: base.width };
        }
        return { width: base.width, height: base.height };
    }

    function getPrintableWidthMm() {
        const dims = getPaperDimensionsMm();
        const lMargin = parseFloat(state.marginLeftMm) || 20;
        const rMargin = parseFloat(state.marginRightMm) || 20;
        return Math.max(80, dims.width - lMargin - rMargin);
    }

    function getPrintableWidthPx() {
        return Math.round(getPrintableWidthMm() * MM_TO_PX);
    }

    function syncAllToHiddenInputs() {
        saveActiveHeaderFooterToStore();

        const bodyHtml = synchronizeSerializedHtml(bodyEditor.getHTML());
        const bodyJson = JSON.stringify(bodyEditor.getJSON());

        const setInputVal = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.value = val;
        };

        const compLogo = state.mediaAssets.company_logo || state.mediaAssets.company_logo_url || '/images/mou/seven-inc-logo.png';
        const isMouDoc = (state.documentType === 'mou');
        const instLogo = isMouDoc
            ? (state.mediaAssets.institution_logo || state.mediaAssets.institution_logo_url || '/images/mou/institution-logo-placeholder.png')
            : '';

        setInputVal('inputTemplateHtml', bodyHtml);
        setInputVal('inputContentHtml', bodyHtml);
        setInputVal('inputEditorJson', bodyJson);
        setInputVal('inputHeaderHtml', state.headerHtmlStore || '');
        setInputVal('inputFooterHtml', state.footerHtmlStore || '');
        setInputVal('inputFirstPageHeaderHtml', state.firstPageHeaderHtmlStore || '');
        setInputVal('inputFirstPageFooterHtml', state.firstPageFooterHtmlStore || '');
        setInputVal('inputDifferentFirstPage', state.differentFirstPage ? '1' : '0');
        setInputVal('inputShowParafBox', state.showParafBox ? '1' : '0');
        setInputVal('inputShowPageNumber', state.showPageNumber ? '1' : '0');
        setInputVal('inputPaperSize', state.paperSize || 'A4');
        setInputVal('inputOrientation', state.orientation || 'portrait');
        setInputVal('inputAllowBlankTemplate', state.allowBlankTemplate ? '1' : '0');
        setInputVal('inputMarginTopMm', String(state.marginTopMm));
        setInputVal('inputMarginRightMm', String(state.marginRightMm));
        setInputVal('inputMarginBottomMm', String(state.marginBottomMm));
        setInputVal('inputMarginLeftMm', String(state.marginLeftMm));
        setInputVal('inputHeaderDistanceMm', String(state.headerDistanceMm));
        setInputVal('inputFooterDistanceMm', String(state.footerDistanceMm));
        setInputVal('inputFontFamily', state.fontFamily);
        setInputVal('inputFontSizePt', String(state.fontSizePt));
        setInputVal('inputLineHeight', String(state.lineHeight));
        if (isMouDoc) {
            setInputVal('inputInstitutionLogo', instLogo);
            setInputVal('inputInstitutionLogoUrl', instLogo);
        }
        setInputVal('inputCompanyLogo', compLogo);
        setInputVal('inputCompanyLogoUrl', compLogo);
        setInputVal('inputShowHeaderLogos', state.showHeaderLogos ? '1' : '0');
        setInputVal('inputHeaderLogosConfig', JSON.stringify(state.headerLogosConfig || {}));
        setInputVal('inputShowWatermark', state.showWatermark ? '1' : '0');
        setInputVal('inputWatermarkOpacity', String(state.watermarkOpacity ?? 0.05));
    }

    /**
     * Apply paper styling (size, orientation, margins, font, line-height, header/footer visibility, ruler) to canvas.
     */
    function applyPageLayoutToCanvas() {
        const dims = getPaperDimensionsMm();
        refreshAllPageViews();

        const rulerTrack = document.getElementById('mouHorizontalRulerTrack');
        if (rulerTrack) rulerTrack.style.width = `${dims.width}mm`;

        const leftMarginMask = document.getElementById('mouRulerLeftMargin');
        if (leftMarginMask) leftMarginMask.style.width = `${state.marginLeftMm}mm`;

        const rightMarginMask = document.getElementById('mouRulerRightMargin');
        if (rightMarginMask) rightMarginMask.style.width = `${state.marginRightMm}mm`;

        const statusBarPaperLbl = document.getElementById('statusBarPaperSizeLabel');
        if (statusBarPaperLbl) {
            const orientLbl = state.orientation === 'landscape' ? 'Landscape' : 'Portrait';
            statusBarPaperLbl.textContent = `${state.paperSize} ${orientLbl} (${dims.width} × ${dims.height} mm)`;
        }

        renderHorizontalRuler();
        scheduleSyncAndPagination();
        scheduleReflow(true);
    }

    /**
     * Synchronize Ruler position and scale directly to match the active A4 paper sheet.
     */
    function syncRulerToPageSheet() {
        const rulerTrack = document.getElementById('mouHorizontalRulerTrack');
        const rulerShelf = document.getElementById('mouRulerStickyHeader');
        const deskStage = document.getElementById('mouPagesDeskStage');
        if (!rulerTrack || !rulerShelf || !deskStage) return;

        const firstPage = deskStage.querySelector('.mou-a4-page-node') || deskStage.querySelector('.mou-a4-paper-sheet');
        if (!firstPage) return;

        const shelfRect = rulerShelf.getBoundingClientRect();
        const pageRect = firstPage.getBoundingClientRect();
        const leftOffset = pageRect.left - shelfRect.left;

        const scale = (parseFloat(state.zoomPercent) || 100) / 100;
        const dims = getPaperDimensionsMm();

        rulerTrack.style.width = `${dims.width}mm`;
        rulerTrack.style.transformOrigin = '0 0';
        rulerTrack.style.transform = `translateX(${leftOffset}px) scale(${scale})`;
    }

    /**
     * Show vertical alignment guide line with real-time measurement badge.
     * @param {number} posMm - Position in mm from the left edge of the paper sheet.
     * @param {string} label - Formatted text to display in tooltip badge.
     */
    function showRulerGuideline(posMm, label) {
        const guideLine = document.getElementById('mouRulerGuideLine');
        const badge = document.getElementById('mouRulerTooltipBadge');
        const deskStage = document.getElementById('mouPagesDeskStage');
        if (!guideLine || !deskStage) return;

        const firstPage = deskStage.querySelector('.mou-a4-page-node') || deskStage.querySelector('.mou-a4-paper-sheet');
        if (!firstPage) return;

        const leftPx = firstPage.offsetLeft + (posMm * MM_TO_PX);
        guideLine.style.left = `${leftPx}px`;
        guideLine.classList.remove('hidden');

        if (badge) {
            badge.textContent = label || `${(posMm / 10).toFixed(2)} cm (${posMm.toFixed(1)} mm)`;
        }
    }

    /**
     * Hide vertical alignment guide line.
     */
    function hideRulerGuideline() {
        const guideLine = document.getElementById('mouRulerGuideLine');
        if (guideLine) guideLine.classList.add('hidden');
    }

    /**
     * Show/Hide Active Object Horizontal Range Highlight on the Ruler (MS Word Style).
     */
    function showRulerObjectRange(leftMm, widthMm) {
        const rangeEl = document.getElementById('mouRulerObjectRange');
        if (!rangeEl) return;
        rangeEl.style.left = `${Math.max(0, leftMm)}mm`;
        rangeEl.style.width = `${Math.max(1, widthMm)}mm`;
        rangeEl.classList.remove('hidden');
    }

    function hideRulerObjectRange() {
        const rangeEl = document.getElementById('mouRulerObjectRange');
        if (rangeEl) rangeEl.classList.add('hidden');
    }

    /**
     * Smart Guides / Alignment Engine:
     * Evaluates an object's boundary against document targets (Left Margin, Page Center,
     * Body Center, Right Margin, Sibling Object Y-alignment, and Header Boundary).
     */
    function evaluateSmartGuides(targetRect, isHeaderLogo = false, siblingLogoCfg = null, defaultSide = 'left', baseTopMm = null) {
        const dims = getPaperDimensionsMm();
        const snapMm = 2.0; // Snap threshold in mm (~7.5px)

        let snapX = null;
        let snapY = null;
        let vGuide = null;
        let hGuide = null;

        const leftMarginMm = state.marginLeftMm;
        const rightMarginMm = dims.width - state.marginRightMm;
        const centerPaperMm = dims.width / 2;
        const centerPrintableMm = leftMarginMm + (rightMarginMm - leftMarginMm) / 2;

        const currentLeftMm = targetRect.leftMm;
        const currentRightMm = targetRect.leftMm + targetRect.widthMm;
        const currentCenterXMm = targetRect.leftMm + (targetRect.widthMm / 2);
        const currentTopMm = targetRect.topMm;

        // 1. Horizontal Snapping (Vertical Guide Lines)
        // A. Left Margin Snap (Left edge aligns with Left Margin)
        if (Math.abs(currentLeftMm - leftMarginMm) <= snapMm) {
            snapX = leftMarginMm;
            vGuide = { posMm: leftMarginMm, label: `⇥ Rata Margin Kiri (${(leftMarginMm / 10).toFixed(2)} cm)` };
        }
        // B. Page Center Snap (Center aligns with middle of A4 sheet)
        else if (Math.abs(currentCenterXMm - centerPaperMm) <= snapMm) {
            snapX = centerPaperMm - (targetRect.widthMm / 2);
            vGuide = { posMm: centerPaperMm, label: `⇹ Tengah Lembar A4 (${(centerPaperMm / 10).toFixed(2)} cm)` };
        }
        // C. Printable Body Center Snap (Center aligns with middle of text margins)
        else if (Math.abs(currentCenterXMm - centerPrintableMm) <= snapMm) {
            snapX = centerPrintableMm - (targetRect.widthMm / 2);
            vGuide = { posMm: centerPrintableMm, label: `⇹ Tengah Area Margin (${(centerPrintableMm / 10).toFixed(2)} cm)` };
        }
        // D. Right Margin Snap (Right edge aligns with Right Margin)
        else if (Math.abs(currentRightMm - rightMarginMm) <= snapMm) {
            snapX = rightMarginMm - targetRect.widthMm;
            vGuide = { posMm: rightMarginMm, label: `⇤ Rata Margin Kanan (${(rightMarginMm / 10).toFixed(2)} cm)` };
        }

        // 2. Vertical Snapping (Horizontal Guide Lines)
        if (isHeaderLogo) {
            const topHeaderBaselineMm = baseTopMm !== null ? baseTopMm : Math.max(8, (state.marginTopMm || 25.4) - 10);
            // A. Align with Sibling Logo Y position
            if (siblingLogoCfg) {
                const siblingTopMm = topHeaderBaselineMm + (siblingLogoCfg.topOffsetMm || 0);
                if (Math.abs(currentTopMm - siblingTopMm) <= snapMm) {
                    snapY = siblingTopMm;
                    hGuide = { posMm: siblingTopMm, label: `⟺ Sejajar Logo Lain (Y: ${(siblingTopMm / 10).toFixed(2)} cm)` };
                }
            }
            // B. Align with Top Header baseline distance
            if (snapY === null && Math.abs(currentTopMm - topHeaderBaselineMm) <= snapMm) {
                snapY = topHeaderBaselineMm;
                hGuide = { posMm: topHeaderBaselineMm, label: `Batas Atas Header (${(topHeaderBaselineMm / 10).toFixed(2)} cm)` };
            }
        }

        return { snapX, snapY, vGuide, hGuide };
    }

    /**
     * Display vertical and/or horizontal Smart Guides across the document desk stage.
     */
    function showSmartGuides(vGuide, hGuide) {
        const vLine = document.getElementById('mouSmartGuideV');
        const hLine = document.getElementById('mouSmartGuideH');
        const vLabel = document.getElementById('mouSmartGuideVLabel');
        const hLabel = document.getElementById('mouSmartGuideHLabel');
        const deskStage = document.getElementById('mouPagesDeskStage');
        if (!deskStage) return;

        const firstPage = deskStage.querySelector('.mou-a4-page-node') || deskStage.querySelector('.mou-a4-paper-sheet');
        if (!firstPage) return;

        if (vGuide && vLine) {
            const leftPx = firstPage.offsetLeft + (vGuide.posMm * MM_TO_PX);
            vLine.style.left = `${leftPx}px`;
            if (vLabel) vLabel.textContent = vGuide.label;
            vLine.classList.remove('hidden');
        } else if (vLine) {
            vLine.classList.add('hidden');
        }

        if (hGuide && hLine) {
            const topPx = firstPage.offsetTop + (hGuide.posMm * MM_TO_PX);
            hLine.style.top = `${topPx}px`;
            if (hLabel) hLabel.textContent = hGuide.label;
            hLine.classList.remove('hidden');
        } else if (hLine) {
            hLine.classList.add('hidden');
        }
    }

    /**
     * Hide all active Smart Guides and object range highlight.
     */
    function hideSmartGuides() {
        const vLine = document.getElementById('mouSmartGuideV');
        const hLine = document.getElementById('mouSmartGuideH');
        if (vLine) vLine.classList.add('hidden');
        if (hLine) hLine.classList.add('hidden');
        hideRulerObjectRange();
    }

    /**
     * Helper to safely parse any CSS measurement to millimeters.
     */
    function parseMeasurementToMm(val) {
        if (!val) return 0;
        if (typeof val === 'number') return isNaN(val) ? 0 : val;
        val = String(val).trim();
        if (val.endsWith('mm')) return parseFloat(val) || 0;
        if (val.endsWith('cm')) return (parseFloat(val) || 0) * 10;
        if (val.endsWith('pt')) return (parseFloat(val) || 0) * (25.4 / 72);
        if (val.endsWith('in')) return (parseFloat(val) || 0) * 25.4;
        if (val.endsWith('px')) return (parseFloat(val) || 0) / MM_TO_PX;
        return parseFloat(val) || 0;
    }

    /**
     * Render interactive-style Horizontal Millimeter Ruler showing active left/right margins,
     * cm/half-cm/mm ticks, numbers, and interactive handles.
     */
    function renderHorizontalRuler() {
        const rulerTrack = document.getElementById('mouHorizontalRulerTrack');
        const leftMarginMask = document.getElementById('mouRulerLeftMargin');
        const rightMarginMask = document.getElementById('mouRulerRightMargin');
        const leftMarginHandle = document.getElementById('mouRulerLeftMarginHandle');
        const rightMarginHandle = document.getElementById('mouRulerRightMarginHandle');
        const ticksContainer = document.getElementById('mouHorizontalRulerTicksContainer');
        const dims = getPaperDimensionsMm();

        if (rulerTrack) {
            rulerTrack.style.width = `${dims.width}mm`;
        }
        if (leftMarginMask) leftMarginMask.style.width = `${state.marginLeftMm}mm`;
        if (rightMarginMask) rightMarginMask.style.width = `${state.marginRightMm}mm`;
        if (leftMarginHandle) leftMarginHandle.style.left = `${state.marginLeftMm}mm`;
        if (rightMarginHandle) rightMarginHandle.style.right = `${state.marginRightMm}mm`;

        if (ticksContainer) {
            ticksContainer.innerHTML = '';
            const frag = document.createDocumentFragment();
            const totalWidthMm = Math.round(dims.width);
            const maxCm = Math.floor(totalWidthMm / 10);

            // 1. Millimeter ticks (every 1 mm)
            for (let mm = 1; mm < totalWidthMm; mm++) {
                if (mm % 10 === 0 || mm % 5 === 0) continue;
                const mmTick = document.createElement('div');
                mmTick.className = 'mou-ruler-mm';
                mmTick.style.left = `${mm}mm`;
                frag.appendChild(mmTick);
            }

            // 2. Half-centimeter ticks (every 5 mm)
            for (let half = 5; half < totalWidthMm; half += 10) {
                const halfTick = document.createElement('div');
                halfTick.className = 'mou-ruler-half-cm';
                halfTick.style.left = `${half}mm`;
                frag.appendChild(halfTick);
            }

            // 3. Full centimeter ticks with bold number labels (every 10 mm)
            for (let cm = 0; cm <= maxCm; cm++) {
                const cmTick = document.createElement('div');
                cmTick.className = 'mou-ruler-cm';
                cmTick.style.left = `${cm * 10}mm`;
                if (cm > 0 && cm < maxCm) {
                    const lbl = document.createElement('span');
                    lbl.textContent = String(cm);
                    cmTick.appendChild(lbl);
                }
                frag.appendChild(cmTick);
            }

            ticksContainer.appendChild(frag);
        }

        updateRulerMarkersFromEditor();
        syncRulerToPageSheet();
    }

    /**
     * Synchronize Ruler Indent Markers & Table Column Dividers with the active cursor selection.
     */
    function updateRulerMarkersFromEditor() {
        const editor = getActiveEditor();
        if (!editor) return;

        const dims = getPaperDimensionsMm();
        const firstLineEl = document.getElementById('mouRulerFirstLineIndent');
        const hangingEl = document.getElementById('mouRulerHangingIndent');
        const leftBoxEl = document.getElementById('mouRulerLeftIndentBox');
        const rightIndentEl = document.getElementById('mouRulerRightIndent');
        const tableColsLayer = document.getElementById('mouRulerTableColumnsLayer');

        // Check active node attributes (paragraph, heading, or list)
        const pAttrs = editor.getAttributes('paragraph') || {};
        const hAttrs = editor.getAttributes('heading') || {};
        const olAttrs = editor.getAttributes('orderedList') || {};
        const blAttrs = editor.getAttributes('bulletList') || {};
        const activeAttrs = editor.isActive('heading') ? hAttrs : pAttrs;

        let paraMarginLeft = 0;
        let paraTextIndent = 0;
        let paraMarginRight = 0;

        if (editor.isActive('listItem')) {
            const listAttrs = editor.isActive('orderedList') ? olAttrs : blAttrs;
            paraMarginLeft = parseMeasurementToMm(listAttrs.marginLeft);
            paraTextIndent = 0;
            paraMarginRight = 0;
        } else {
            paraMarginLeft = parseMeasurementToMm(activeAttrs.marginLeft);
            paraTextIndent = parseMeasurementToMm(activeAttrs.textIndent);
            paraMarginRight = parseMeasurementToMm(activeAttrs.marginRight);
        }

        // Calculate positions in mm from paper left edge
        const baseLeftMm = state.marginLeftMm + paraMarginLeft;
        const firstLineMm = Math.max(0, Math.min(dims.width, baseLeftMm + paraTextIndent));
        const hangingMm = Math.max(0, Math.min(dims.width, baseLeftMm));
        const rightMm = Math.max(0, Math.min(dims.width, dims.width - state.marginRightMm - paraMarginRight));

        if (firstLineEl) firstLineEl.style.left = `${firstLineMm}mm`;
        if (hangingEl) hangingEl.style.left = `${hangingMm}mm`;
        if (leftBoxEl) leftBoxEl.style.left = `${hangingMm}mm`;
        if (rightIndentEl) rightIndentEl.style.left = `${rightMm}mm`;

        // If inside a table, display column boundary dividers on ruler
        if (tableColsLayer) {
            tableColsLayer.innerHTML = '';
            const isInTable = editor.isActive('table');
            if (isInTable) {
                const domSel = window.getSelection();
                if (domSel && domSel.anchorNode) {
                    let cellEl = domSel.anchorNode;
                    while (cellEl && !['TD', 'TH'].includes(cellEl.nodeName)) {
                        cellEl = cellEl.parentElement;
                    }
                    if (cellEl) {
                        const tableEl = cellEl.closest('table');
                        if (tableEl) {
                            const firstRow = tableEl.querySelector('tr');
                            if (firstRow) {
                                const cells = Array.from(firstRow.children);
                                const tableRect = tableEl.getBoundingClientRect();
                                const rulerTrack = document.getElementById('mouHorizontalRulerTrack');
                                if (rulerTrack) {
                                    const rulerRect = rulerTrack.getBoundingClientRect();
                                    const pxPerMm = rulerRect.width / dims.width;
                                    
                                    // Table start in mm from paper left
                                    const tableStartMm = (tableRect.left - rulerRect.left) / pxPerMm;
                                    let accumWidthPx = 0;
                                    
                                    cells.forEach((c, idx) => {
                                        accumWidthPx += c.getBoundingClientRect().width;
                                        if (idx < cells.length - 1) {
                                            const colBoundaryMm = tableStartMm + (accumWidthPx / pxPerMm);
                                            const colMarker = document.createElement('div');
                                            colMarker.className = 'mou-ruler-col-marker';
                                            colMarker.style.left = `${colBoundaryMm}mm`;
                                            colMarker.title = `Batas Kolom ${idx + 1} & ${idx + 2} (${(c.getBoundingClientRect().width / MM_TO_PX / 10).toFixed(2)} cm)`;
                                            
                                            const line = document.createElement('div');
                                            line.className = 'col-line';
                                            colMarker.appendChild(line);
                                            
                                            tableColsLayer.appendChild(colMarker);
                                        }
                                    });
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Attach interactive drag handlers to Ruler margin handles, indent markers, and ruler track.
     */
    function attachInteractiveRulerEvents() {
        const rulerTrack = document.getElementById('mouHorizontalRulerTrack');
        const leftMarginHandle = document.getElementById('mouRulerLeftMarginHandle');
        const rightMarginHandle = document.getElementById('mouRulerRightMarginHandle');
        const leftMarginMask = document.getElementById('mouRulerLeftMargin');
        const rightMarginMask = document.getElementById('mouRulerRightMargin');
        const firstLineEl = document.getElementById('mouRulerFirstLineIndent');
        const hangingEl = document.getElementById('mouRulerHangingIndent');
        const leftBoxEl = document.getElementById('mouRulerLeftIndentBox');
        const rightIndentEl = document.getElementById('mouRulerRightIndent');
        if (!rulerTrack) return;

        // Helper to get active scale
        const getMetrics = () => {
            const dims = getPaperDimensionsMm();
            const rulerRect = rulerTrack.getBoundingClientRect();
            const pxPerMm = rulerRect.width / dims.width;
            return { dims, rulerRect, pxPerMm };
        };

        // 1. Drag Left Margin Handle
        if (leftMarginHandle) {
            leftMarginHandle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                leftMarginHandle.classList.add('is-dragging');
                const { dims, rulerRect, pxPerMm } = getMetrics();
                let currentNewMarginMm = state.marginLeftMm;

                const onMouseMove = (moveEv) => {
                    const currentX = moveEv.clientX - rulerRect.left;
                    let newMargin = Math.max(5, Math.min(dims.width - state.marginRightMm - 20, currentX / pxPerMm));
                    newMargin = Math.round(newMargin * 10) / 10;
                    currentNewMarginMm = newMargin;

                    if (leftMarginMask) leftMarginMask.style.width = `${newMargin}mm`;
                    leftMarginHandle.style.left = `${newMargin}mm`;
                    showRulerGuideline(newMargin, `Margin Kiri: ${(newMargin / 10).toFixed(2)} cm (${newMargin.toFixed(1)} mm)`);
                };

                const onMouseUp = () => {
                    window.removeEventListener('mousemove', onMouseMove);
                    window.removeEventListener('mouseup', onMouseUp);
                    leftMarginHandle.classList.remove('is-dragging');
                    hideRulerGuideline();

                    state.marginLeftMm = currentNewMarginMm;
                    const inp = document.getElementById('layoutMarginLeft');
                    if (inp) inp.value = String(currentNewMarginMm);
                    applyPageLayoutToCanvas();
                };

                window.addEventListener('mousemove', onMouseMove);
                window.addEventListener('mouseup', onMouseUp);
            });
        }

        // 2. Drag Right Margin Handle
        if (rightMarginHandle) {
            rightMarginHandle.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                rightMarginHandle.classList.add('is-dragging');
                const { dims, rulerRect, pxPerMm } = getMetrics();
                let currentNewMarginMm = state.marginRightMm;

                const onMouseMove = (moveEv) => {
                    const currentX = moveEv.clientX - rulerRect.left;
                    let fromRightMm = dims.width - (currentX / pxPerMm);
                    let newMargin = Math.max(5, Math.min(dims.width - state.marginLeftMm - 20, fromRightMm));
                    newMargin = Math.round(newMargin * 10) / 10;
                    currentNewMarginMm = newMargin;

                    if (rightMarginMask) rightMarginMask.style.width = `${newMargin}mm`;
                    rightMarginHandle.style.right = `${newMargin}mm`;
                    const posFromLeftMm = dims.width - newMargin;
                    showRulerGuideline(posFromLeftMm, `Margin Kanan: ${(newMargin / 10).toFixed(2)} cm (${newMargin.toFixed(1)} mm)`);
                };

                const onMouseUp = () => {
                    window.removeEventListener('mousemove', onMouseMove);
                    window.removeEventListener('mouseup', onMouseUp);
                    rightMarginHandle.classList.remove('is-dragging');
                    hideRulerGuideline();

                    state.marginRightMm = currentNewMarginMm;
                    const inp = document.getElementById('layoutMarginRight');
                    if (inp) inp.value = String(currentNewMarginMm);
                    applyPageLayoutToCanvas();
                };

                window.addEventListener('mousemove', onMouseMove);
                window.addEventListener('mouseup', onMouseUp);
            });
        }

        // 3. Drag First Line Indent (Top Downward Triangle ▼)
        if (firstLineEl) {
            firstLineEl.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                firstLineEl.classList.add('is-dragging');
                const { dims, rulerRect, pxPerMm } = getMetrics();
                const editor = getActiveEditor();
                const pAttrs = editor ? (editor.getAttributes('paragraph') || editor.getAttributes('heading') || {}) : {};
                const paraMarginLeft = parseMeasurementToMm(pAttrs.marginLeft);
                const baseLeftMm = state.marginLeftMm + paraMarginLeft;
                let finalIndentMm = parseMeasurementToMm(pAttrs.textIndent);

                const onMouseMove = (moveEv) => {
                    const currentX = moveEv.clientX - rulerRect.left;
                    const targetMm = Math.max(0, Math.min(dims.width, currentX / pxPerMm));
                    const deltaFromParaLeftMm = targetMm - baseLeftMm;
                    finalIndentMm = Math.round(deltaFromParaLeftMm * 10) / 10;

                    firstLineEl.style.left = `${targetMm}mm`;
                    showRulerGuideline(targetMm, `Indentasi Baris Pertama: ${(finalIndentMm / 10).toFixed(2)} cm (${finalIndentMm > 0 ? '+' : ''}${finalIndentMm.toFixed(1)} mm)`);
                };

                const onMouseUp = () => {
                    window.removeEventListener('mousemove', onMouseMove);
                    window.removeEventListener('mouseup', onMouseUp);
                    firstLineEl.classList.remove('is-dragging');
                    hideRulerGuideline();

                    if (editor) {
                        const tr = editor.state.tr;
                        const { from, to } = editor.state.selection;
                        let modified = false;
                        editor.state.doc.nodesBetween(from, to, (node, pos) => {
                            if (node.isTextblock) {
                                tr.setNodeMarkup(pos, undefined, {
                                    ...node.attrs,
                                    textIndent: finalIndentMm !== 0 ? `${finalIndentMm}mm` : null
                                });
                                modified = true;
                            }
                        });
                        if (modified) {
                            editor.view.dispatch(tr);
                        } else {
                            editor.chain().focus().updateAttributes('paragraph', {
                                textIndent: finalIndentMm !== 0 ? `${finalIndentMm}mm` : null
                            }).run();
                        }
                        const indInput = document.getElementById('layoutFirstLineIndent');
                        if (indInput) indInput.value = String(Math.max(0, Math.round(finalIndentMm)));
                    }
                    updateRulerMarkersFromEditor();
                    scheduleSyncAndPagination();
                    scheduleReflow(true);
                };

                window.addEventListener('mousemove', onMouseMove);
                window.addEventListener('mouseup', onMouseUp);
            });
        }

        // 4. Drag Left Indent Box (Bottom Rectangle ▄ - Moves Both First Line & Left Indent)
        if (leftBoxEl) {
            leftBoxEl.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                leftBoxEl.classList.add('is-dragging');
                const { dims, rulerRect, pxPerMm } = getMetrics();
                const editor = getActiveEditor();
                const pAttrs = editor ? (editor.getAttributes('paragraph') || editor.getAttributes('heading') || {}) : {};
                const olAttrs = editor ? (editor.getAttributes('orderedList') || {}) : {};
                const blAttrs = editor ? (editor.getAttributes('bulletList') || {}) : {};
                const isList = editor && editor.isActive('listItem');
                const startParaMarginLeft = isList ? parseMeasurementToMm((editor.isActive('orderedList') ? olAttrs : blAttrs).marginLeft) : parseMeasurementToMm(pAttrs.marginLeft);
                const startParaTextIndent = parseMeasurementToMm(pAttrs.textIndent);
                const startClientX = e.clientX;
                let finalMarginLeftMm = startParaMarginLeft;

                const onMouseMove = (moveEv) => {
                    const deltaMm = (moveEv.clientX - startClientX) / pxPerMm;
                    let newParaMarginLeft = Math.max(0, Math.min(dims.width - state.marginLeftMm - state.marginRightMm - 20, startParaMarginLeft + deltaMm));
                    newParaMarginLeft = Math.round(newParaMarginLeft * 10) / 10;
                    finalMarginLeftMm = newParaMarginLeft;

                    const totalPosMm = state.marginLeftMm + newParaMarginLeft;
                    leftBoxEl.style.left = `${totalPosMm}mm`;
                    if (hangingEl) hangingEl.style.left = `${totalPosMm}mm`;
                    if (firstLineEl) firstLineEl.style.left = `${totalPosMm + startParaTextIndent}mm`;

                    showRulerGuideline(totalPosMm, `Indentasi Kiri: ${(newParaMarginLeft / 10).toFixed(2)} cm (${newParaMarginLeft.toFixed(1)} mm)`);
                };

                const onMouseUp = () => {
                    window.removeEventListener('mousemove', onMouseMove);
                    window.removeEventListener('mouseup', onMouseUp);
                    leftBoxEl.classList.remove('is-dragging');
                    hideRulerGuideline();

                    if (editor) {
                        if (editor.isActive('listItem')) {
                            const listType = editor.isActive('orderedList') ? 'orderedList' : 'bulletList';
                            editor.chain().focus().updateAttributes(listType, {
                                marginLeft: finalMarginLeftMm > 0 ? `${finalMarginLeftMm}mm` : null
                            }).run();
                        } else {
                            const tr = editor.state.tr;
                            const { from, to } = editor.state.selection;
                            let modified = false;
                            editor.state.doc.nodesBetween(from, to, (node, pos) => {
                                if (node.isTextblock) {
                                    tr.setNodeMarkup(pos, undefined, {
                                        ...node.attrs,
                                        marginLeft: finalMarginLeftMm > 0 ? `${finalMarginLeftMm}mm` : null
                                    });
                                    modified = true;
                                }
                            });
                            if (modified) {
                                editor.view.dispatch(tr);
                            } else {
                                editor.chain().focus().updateAttributes('paragraph', {
                                    marginLeft: finalMarginLeftMm > 0 ? `${finalMarginLeftMm}mm` : null
                                }).run();
                            }
                        }
                    }
                    updateRulerMarkersFromEditor();
                    scheduleSyncAndPagination();
                    scheduleReflow(true);
                };

                window.addEventListener('mousemove', onMouseMove);
                window.addEventListener('mouseup', onMouseUp);
            });
        }

        // 5. Drag Hanging Indent Marker (Middle Upward Triangle ▲)
        if (hangingEl) {
            hangingEl.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                hangingEl.classList.add('is-dragging');
                const { dims, rulerRect, pxPerMm } = getMetrics();
                const editor = getActiveEditor();
                const pAttrs = editor ? (editor.getAttributes('paragraph') || editor.getAttributes('heading') || {}) : {};
                const startParaMarginLeft = parseMeasurementToMm(pAttrs.marginLeft);
                const startParaTextIndent = parseMeasurementToMm(pAttrs.textIndent);
                const initialFirstLinePosMm = state.marginLeftMm + startParaMarginLeft + startParaTextIndent;
                const startClientX = e.clientX;
                let finalMarginLeftMm = startParaMarginLeft;
                let finalTextIndentMm = startParaTextIndent;

                const onMouseMove = (moveEv) => {
                    const deltaMm = (moveEv.clientX - startClientX) / pxPerMm;
                    let newParaMarginLeft = Math.max(0, Math.min(dims.width - state.marginLeftMm - state.marginRightMm - 20, startParaMarginLeft + deltaMm));
                    newParaMarginLeft = Math.round(newParaMarginLeft * 10) / 10;
                    finalMarginLeftMm = newParaMarginLeft;
                    finalTextIndentMm = Math.round((initialFirstLinePosMm - (state.marginLeftMm + newParaMarginLeft)) * 10) / 10;

                    const totalPosMm = state.marginLeftMm + newParaMarginLeft;
                    hangingEl.style.left = `${totalPosMm}mm`;
                    if (leftBoxEl) leftBoxEl.style.left = `${totalPosMm}mm`;

                    showRulerGuideline(totalPosMm, `Indentasi Menggantung: ${(newParaMarginLeft / 10).toFixed(2)} cm (${newParaMarginLeft.toFixed(1)} mm)`);
                };

                const onMouseUp = () => {
                    window.removeEventListener('mousemove', onMouseMove);
                    window.removeEventListener('mouseup', onMouseUp);
                    hangingEl.classList.remove('is-dragging');
                    hideRulerGuideline();

                    if (editor) {
                        const tr = editor.state.tr;
                        const { from, to } = editor.state.selection;
                        let modified = false;
                        editor.state.doc.nodesBetween(from, to, (node, pos) => {
                            if (node.isTextblock) {
                                tr.setNodeMarkup(pos, undefined, {
                                    ...node.attrs,
                                    marginLeft: finalMarginLeftMm > 0 ? `${finalMarginLeftMm}mm` : null,
                                    textIndent: finalTextIndentMm !== 0 ? `${finalTextIndentMm}mm` : null
                                });
                                modified = true;
                            }
                        });
                        if (modified) {
                            editor.view.dispatch(tr);
                        } else {
                            editor.chain().focus().updateAttributes('paragraph', {
                                marginLeft: finalMarginLeftMm > 0 ? `${finalMarginLeftMm}mm` : null,
                                textIndent: finalTextIndentMm !== 0 ? `${finalTextIndentMm}mm` : null
                            }).run();
                        }
                    }
                    updateRulerMarkersFromEditor();
                    scheduleSyncAndPagination();
                    scheduleReflow(true);
                };

                window.addEventListener('mousemove', onMouseMove);
                window.addEventListener('mouseup', onMouseUp);
            });
        }

        // 6. Drag Right Indent Marker (Bottom Upward Triangle ▲ on Right)
        if (rightIndentEl) {
            rightIndentEl.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                rightIndentEl.classList.add('is-dragging');
                const { dims, rulerRect, pxPerMm } = getMetrics();
                const editor = getActiveEditor();
                const pAttrs = editor ? (editor.getAttributes('paragraph') || editor.getAttributes('heading') || {}) : {};
                const startParaMarginRight = parseMeasurementToMm(pAttrs.marginRight);
                const startClientX = e.clientX;
                let finalMarginRightMm = startParaMarginRight;

                const onMouseMove = (moveEv) => {
                    const deltaMm = (startClientX - moveEv.clientX) / pxPerMm;
                    let newParaMarginRight = Math.max(0, Math.min(dims.width - state.marginLeftMm - state.marginRightMm - 20, startParaMarginRight + deltaMm));
                    newParaMarginRight = Math.round(newParaMarginRight * 10) / 10;
                    finalMarginRightMm = newParaMarginRight;

                    const totalPosFromLeftMm = dims.width - state.marginRightMm - newParaMarginRight;
                    rightIndentEl.style.left = `${totalPosFromLeftMm}mm`;

                    showRulerGuideline(totalPosFromLeftMm, `Indentasi Kanan: ${(newParaMarginRight / 10).toFixed(2)} cm (${newParaMarginRight.toFixed(1)} mm)`);
                };

                const onMouseUp = () => {
                    window.removeEventListener('mousemove', onMouseMove);
                    window.removeEventListener('mouseup', onMouseUp);
                    rightIndentEl.classList.remove('is-dragging');
                    hideRulerGuideline();

                    if (editor) {
                        const tr = editor.state.tr;
                        const { from, to } = editor.state.selection;
                        let modified = false;
                        editor.state.doc.nodesBetween(from, to, (node, pos) => {
                            if (node.isTextblock) {
                                tr.setNodeMarkup(pos, undefined, {
                                    ...node.attrs,
                                    marginRight: finalMarginRightMm > 0 ? `${finalMarginRightMm}mm` : null
                                });
                                modified = true;
                            }
                        });
                        if (modified) {
                            editor.view.dispatch(tr);
                        } else {
                            editor.chain().focus().updateAttributes('paragraph', {
                                marginRight: finalMarginRightMm > 0 ? `${finalMarginRightMm}mm` : null
                            }).run();
                        }
                    }
                    updateRulerMarkersFromEditor();
                    scheduleSyncAndPagination();
                    scheduleReflow(true);
                };

                window.addEventListener('mousemove', onMouseMove);
                window.addEventListener('mouseup', onMouseUp);
            });
        }

        // 7. Click & Drag on Ruler Bar (Precision Digital Alignment Caliper)
        rulerTrack.addEventListener('mousedown', (e) => {
            // Ignore if clicked on a marker or handle
            if (e.target.closest('.mou-ruler-marker') || e.target.closest('.mou-ruler-margin-handle') || e.target.closest('.mou-ruler-col-marker')) {
                return;
            }
            e.preventDefault();
            const { dims, rulerRect, pxPerMm } = getMetrics();

            const updateAlignment = (clientEv) => {
                const currentX = clientEv.clientX - rulerRect.left;
                const posMm = Math.max(0, Math.min(dims.width, currentX / pxPerMm));
                const distFromMarginMm = posMm - state.marginLeftMm;
                const distStr = distFromMarginMm >= 0
                    ? `+${(distFromMarginMm / 10).toFixed(2)} cm dari margin kiri`
                    : `${(distFromMarginMm / 10).toFixed(2)} cm di area margin`;
                showRulerGuideline(posMm, `Penyelarasan: ${distStr} (${(posMm / 10).toFixed(2)} cm / ${posMm.toFixed(1)} mm)`);
            };

            updateAlignment(e);

            const onMouseMove = (moveEv) => updateAlignment(moveEv);
            const onMouseUp = () => {
                window.removeEventListener('mousemove', onMouseMove);
                window.removeEventListener('mouseup', onMouseUp);
                hideRulerGuideline();
            };

            window.addEventListener('mousemove', onMouseMove);
            window.addEventListener('mouseup', onMouseUp);
        });

        // 8. Synchronize Horizontal Ruler with Document Viewport Scroll & Page Position
        const workspaceViewport = document.getElementById('mouEditorWorkspaceView');
        if (workspaceViewport && rulerTrack) {
            workspaceViewport.addEventListener('scroll', syncRulerToPageSheet, { passive: true });
        }
    }

    /**
     * Refresh all rendered page sheet views (headers, footers, paraf box, dimensions).
     */
    function refreshAllPageViews() {
        if (!bodyMountEl) return;
        const pageNodes = bodyMountEl.querySelectorAll('.mou-a4-page-node');
        const totalPages = pageNodes.length || 1;
        const dims = getPaperDimensionsMm();

        pageNodes.forEach((pageDom, idx) => {
            const pageNum = idx + 1;
            pageDom.setAttribute('data-page-number', String(pageNum));
            pageDom.style.width = `${dims.width}mm`;
            pageDom.style.minWidth = `${dims.width}mm`;
            pageDom.style.maxWidth = `${dims.width}mm`;
            pageDom.style.height = `${dims.height}mm`;
            pageDom.style.minHeight = `${dims.height}mm`;
            pageDom.style.maxHeight = `${dims.height}mm`;
            pageDom.style.paddingLeft = `${state.marginLeftMm}mm`;
            pageDom.style.paddingRight = `${state.marginRightMm}mm`;
            pageDom.style.paddingTop = `${Math.min(state.headerDistanceMm, state.marginTopMm)}mm`;
            pageDom.style.paddingBottom = `${Math.min(state.footerDistanceMm, state.marginBottomMm)}mm`;
            pageDom.style.fontFamily = `'${state.fontFamily}', Times, serif`;
            pageDom.style.fontSize = `${state.fontSizePt}pt`;
            pageDom.style.lineHeight = String(state.lineHeight);
            pageDom.style.position = 'relative';

            const isFirstPage = pageNum === 1;

            // Render Watermark & Fixed Header Logo Layer (pinned to page, independent of text stream)
            let watermarkEl = pageDom.querySelector('.mou-page-watermark-layer');
            if (!watermarkEl) {
                watermarkEl = document.createElement('div');
                watermarkEl.className = 'mou-page-watermark-layer';
                watermarkEl.setAttribute('data-pagination-artifact', 'true');
                watermarkEl.setAttribute('contenteditable', 'false');
                pageDom.insertBefore(watermarkEl, pageDom.firstChild);
            }

            const targetWm = renderWatermarkLayerHtml(state, isFirstPage);
            if (watermarkEl.innerHTML !== targetWm) {
                watermarkEl.innerHTML = targetWm;
            }

            const headerHtml = (state.differentFirstPage && isFirstPage)
                ? (state.firstPageHeaderHtmlStore || '')
                : (state.headerHtmlStore || '');
            const footerHtml = (state.differentFirstPage && isFirstPage)
                ? (state.firstPageFooterHtmlStore || '')
                : (state.footerHtmlStore || '');

            const headerEl = pageDom.querySelector('.mou-a4-page-header');
            const headerWrapper = document.getElementById('mouHeaderZoneWrapper');

            const isMou = (state.documentType === 'mou');
            const showLogos = isMou && isFirstPage && (state.showHeaderLogos !== false);
            const requiredHeaderHeightMm = showLogos ? 28.0 : 14.0;

            if (headerEl) {
                headerEl.style.minHeight = `${requiredHeaderHeightMm}mm`;
                headerEl.style.maxHeight = '35mm';
                const bodyTopGapMm = Math.max(2, state.marginTopMm - state.headerDistanceMm);
                headerEl.style.marginBottom = `${bodyTopGapMm}mm`;

                if (!headerWrapper || !headerEl.contains(headerWrapper)) {
                    if (isMou && showLogos) {
                        if (headerEl.innerHTML !== (headerHtml || '')) {
                            headerEl.innerHTML = headerHtml || '';
                        }
                    } else {
                        const targetHeader = headerHtml || `
                            <div class="mou-header-empty-indicator text-slate-300 italic text-[10px] py-0.5 select-none text-center border-b border-dashed border-slate-200">
                                (Header Kosong &bull; Klik Ganda untuk Mengisi)
                            </div>
                        `;
                        if (headerEl.innerHTML !== targetHeader) {
                            headerEl.innerHTML = targetHeader;
                        }
                    }
                }
            }

            const footerEl = pageDom.querySelector('.mou-a4-page-footer');
            const footerWrapper = document.getElementById('mouFooterZoneWrapper');
            if (footerEl && (!footerWrapper || !footerEl.contains(footerWrapper))) {
                const pageNumberHtml = state.showPageNumber
                    ? `<div class="font-serif text-[9pt] text-black pb-0.5 shrink-0 select-none text-right whitespace-nowrap">Halaman ${pageNum} dari ${totalPages}</div>`
                    : '';
                const targetFooter = `
                    <div class="mou-footer-content-row flex items-end justify-between gap-4 w-full">
                        <div class="mou-footer-paraf-area flex-1 min-w-0" style="visibility: ${state.showParafBox ? 'visible' : 'hidden'}">
                            ${footerHtml}
                        </div>
                        ${pageNumberHtml}
                    </div>
                `;
                if (footerEl.innerHTML !== targetFooter) {
                    footerEl.innerHTML = targetFooter;
                }
                const bodyBtmGapMm = Math.max(2, state.marginBottomMm - state.footerDistanceMm);
                footerEl.style.marginTop = `${bodyBtmGapMm}mm`;
            }

            const contentDom = pageDom.querySelector('.mou-page-body-content');
            if (contentDom) {
                if (state.activeZone === 'header' || state.activeZone === 'footer') {
                    contentDom.classList.add('mou-zone-dimmed');
                } else {
                    contentDom.classList.remove('mou-zone-dimmed');
                }
            }

            pageDom.querySelectorAll('.mou-header-logos-interactive-stage').forEach(stage => {
                if (state.activeZone === 'header') {
                    stage.classList.add('is-header-active');
                } else {
                    stage.classList.remove('is-header-active');
                }
            });
        });
    }

    /**
     * Automatic True Multi-Page A4 Reflow Engine.
     */
    let reflowTimer = null;
    let reflowPassCount = 0;
    const MAX_REFLOW_PASSES = 150; // Allow seamless pagination for documents up to 100+ pages
    let lastSplitAction = null;
    let oscillationCounter = 0;

    function scheduleReflow(immediate = false) {
        if (reflowTimer) clearTimeout(reflowTimer);
        const delay = immediate ? 0 : 50; // Fast 50ms cascade for multi-page imports and responsive typing
        reflowTimer = setTimeout(() => {
            requestAnimationFrame(() => {
                if (!bodyEditor || bodyEditor.isDestroyed) return;
                if (reflowPassCount > MAX_REFLOW_PASSES) {
                    console.warn('[WordProcessor] Reached max reflow passes limit (150), stopping cascade.');
                    reflowPassCount = 0;
                    oscillationCounter = 0;
                    lastSplitAction = null;
                    return;
                }
                reflowPassCount++;
                checkAndReflowPages(bodyEditor);
            });
        }, delay);
    }

    function checkAndReflowPages(editor) {
        if (!editor || !editor.view || editor.isDestroyed) return;
        // Never reflow body pages while user is editing Header or Footer
        if (state.activeZone !== 'body') return;

        const { state: edState, view } = editor;
        const doc = edState.doc;
        if (doc.type.name !== 'doc') return;

        const pageCount = doc.childCount;
        if (pageCount === 0) return;

        if (pageCount === 1 && doc.child(0).childCount === 0) {
            const tr = edState.tr;
            tr.insert(1, edState.schema.nodes.paragraph.create());
            tr.setMeta('addToHistory', false);
            tr.setMeta(reflowPluginKey, true);
            view.dispatch(tr);
            return;
        }

        // STEP 0: Clean up any trailing blank pages immediately (never delete the page where user's cursor is currently located!)
        let activePageIdx = -1;
        try {
            activePageIdx = edState.selection.$from.index(0);
        } catch (_) {}

        for (let pIdx = pageCount - 1; pIdx > 0; pIdx--) {
            if (pIdx === activePageIdx) {
                // User is actively focused/typing in this page (e.g. just pressed Enter to spawn a new page)
                continue;
            }
            const pNode = doc.child(pIdx);
            const isEmptyPage = pNode.childCount === 0 ||
                (pNode.childCount === 1 && pNode.child(0).type.name === 'paragraph' && pNode.child(0).textContent.trim() === '');
            if (isEmptyPage) {
                executeReflowTransaction(editor, { type: 'remove_empty_page', pageIndex: pIdx });
                return;
            }
        }

        const dims = getPaperDimensionsMm();
        const paperHeightPx = dims.height * MM_TO_PX;

        const pageDoms = view.dom.querySelectorAll('.mou-a4-page-node');
        if (!pageDoms || pageDoms.length !== pageCount) {
            scheduleReflow(false);
            return;
        }

        let needsReflow = false;
        let splitAction = null;

        for (let pIdx = 0; pIdx < pageCount; pIdx++) {
            const pageNode = doc.child(pIdx);
            const pageDom = pageDoms[pIdx];
            const contentDom = pageDom.querySelector('.mou-page-body-content');
            const headerEl = pageDom.querySelector('.mou-a4-page-header');
            const footerEl = pageDom.querySelector('.mou-a4-page-footer');

            if (!contentDom) continue;

            const headerH = headerEl ? headerEl.offsetHeight : 0;
            const footerH = footerEl ? footerEl.offsetHeight : 0;
            const bodyTopGapPx = Math.max(2, state.marginTopMm - state.headerDistanceMm) * MM_TO_PX;
            const bodyBtmGapPx = Math.max(2, state.marginBottomMm - state.footerDistanceMm) * MM_TO_PX;

            const usableHeight = paperHeightPx
                - (Math.min(state.headerDistanceMm, state.marginTopMm) * MM_TO_PX)
                - (Math.min(state.footerDistanceMm, state.marginBottomMm) * MM_TO_PX)
                - headerH - footerH - bodyTopGapPx - bodyBtmGapPx - 14;

            const children = contentDom.children;
            const contentDomRect = contentDom.getBoundingClientRect();
            const scale = (state.zoomPercent || 100) / 100;

            let overflowingIndex = -1;
            for (let cIdx = 0; cIdx < children.length; cIdx++) {
                const childEl = children[cIdx];

                const isExplicitBreak = childEl.classList.contains('mou-page-break') || childEl.getAttribute('data-page-break') === 'true';
                if (isExplicitBreak) {
                    if (cIdx < children.length - 1) {
                        overflowingIndex = cIdx + 1;
                        break;
                    }
                }

                const childRect = childEl.getBoundingClientRect();
                const scale = (state.zoomPercent || 100) / 100;
                const childBottom = (childRect.bottom - contentDomRect.top) / scale;

                // Strict 4px tolerance buffer so content never collides with or overlaps into the footer paraf table
                if (childBottom > usableHeight + 4) {
                    overflowingIndex = cIdx;
                    break;
                }
            }

            if (overflowingIndex !== -1 && overflowingIndex < pageNode.childCount) {
                // If a single node on this page exceeds usable height, keep it intact to avoid infinite split loops
                if (overflowingIndex === 0 && pageNode.childCount === 1) {
                    continue;
                }

                let finalSplitIndex = overflowingIndex;
                function isHeadingNode(n) {
                    if (!n) return false;
                    if (n.type.name === 'heading') return true;
                    const txt = (n.textContent || '').trim();
                    if (/^Pasal\s+\d+/i.test(txt)) return true;
                    if (n.type.name === 'paragraph' && txt.length >= 3 && txt.length <= 60 && txt === txt.toUpperCase() && !txt.includes(':')) {
                        return true;
                    }
                    return false;
                }

                // If moving a block leaves its preceding heading orphaned at the bottom, pull it over,
                // but NEVER pull index 0 (which would leave the current page empty).
                while (finalSplitIndex > 1 && isHeadingNode(pageNode.child(finalSplitIndex - 1))) {
                    finalSplitIndex--;
                }

                if (finalSplitIndex < 1) {
                    finalSplitIndex = Math.max(1, overflowingIndex);
                }
                if (finalSplitIndex >= pageNode.childCount) {
                    continue;
                }

                splitAction = {
                    type: 'overflow',
                    pageIndex: pIdx,
                    splitIndex: finalSplitIndex,
                };
                needsReflow = true;
                break;
            }

            // UNDERFLOW:
            const availableSpace = usableHeight - contentDom.scrollHeight;
            if (availableSpace > 20 && pIdx < pageCount - 1) {
                const nextPageNode = doc.child(pIdx + 1);
                const nextPageDom = pageDoms[pIdx + 1];
                const nextContentDom = nextPageDom ? nextPageDom.querySelector('.mou-page-body-content') : null;

                if (nextPageNode.childCount === 0 || (nextPageNode.childCount === 1 && nextPageNode.child(0).textContent.trim() === '' && nextPageNode.child(0).type.name === 'paragraph')) {
                    splitAction = {
                        type: 'remove_empty_page',
                        pageIndex: pIdx + 1,
                    };
                    needsReflow = true;
                    break;
                } else if (nextContentDom && nextContentDom.firstElementChild) {
                    const firstNextEl = nextContentDom.firstElementChild;
                    const isExplicitBreak = firstNextEl.classList.contains('mou-page-break') || firstNextEl.getAttribute('data-page-break') === 'true';

                    if (!isExplicitBreak) {
                        const firstNextH = firstNextEl.offsetHeight + 6;
                        if (firstNextH <= availableSpace) {
                            splitAction = {
                                type: 'underflow',
                                fromPageIndex: pIdx + 1,
                                toPageIndex: pIdx,
                            };
                            needsReflow = true;
                            break;
                        }
                    }
                }
            }
        }

        if (needsReflow && splitAction) {
            // Ping-pong oscillation prevention: if underflow immediately reverses an overflow on the exact same page
            if (lastSplitAction &&
                lastSplitAction.type === 'overflow' &&
                splitAction.type === 'underflow' &&
                lastSplitAction.pageIndex === splitAction.toPageIndex) {
                oscillationCounter++;
                if (oscillationCounter >= 2) {
                    console.warn('[WordProcessor] Page oscillation prevented between pages', splitAction.toPageIndex, 'and', splitAction.fromPageIndex);
                    needsReflow = false;
                    splitAction = null;
                    reflowPassCount = 0;
                    oscillationCounter = 0;
                    lastSplitAction = null;
                    updateDocumentMetrics();
                    refreshAllPageViews();
                    return;
                }
            } else {
                oscillationCounter = 0;
            }

            lastSplitAction = splitAction;
            executeReflowTransaction(editor, splitAction);
        } else {
            // Document reached stable multi-page layout
            reflowPassCount = 0;
            oscillationCounter = 0;
            lastSplitAction = null;
            updateDocumentMetrics();
            refreshAllPageViews();
        }
    }

    function executeReflowTransaction(editor, action) {
        const { state: edState, view } = editor;
        const doc = edState.doc;
        const tr = edState.tr;

        // Preserve viewport scroll position strictly so editor position never jumps
        const scrollContainer = document.getElementById('mouEditorWorkspaceView');
        const prevScrollTop = scrollContainer ? scrollContainer.scrollTop : 0;
        const prevScrollLeft = scrollContainer ? scrollContainer.scrollLeft : 0;
        const prevWinY = window.scrollY;
        const prevWinX = window.scrollX;
        const currentSel = edState.selection;

        const restoreScrollState = () => {
            if (scrollContainer && Math.abs(scrollContainer.scrollTop - prevScrollTop) > 2) {
                scrollContainer.scrollTop = prevScrollTop;
                scrollContainer.scrollLeft = prevScrollLeft;
            }
            if (Math.abs(window.scrollY - prevWinY) > 2) {
                window.scrollTo(prevWinX, prevWinY);
            }
        };

        if (action.type === 'overflow') {
            const { pageIndex, splitIndex } = action;
            const pageNode = doc.child(pageIndex);

            let pagePos = 0;
            for (let i = 0; i < pageIndex; i++) {
                pagePos += doc.child(i).nodeSize;
            }

            const insidePagePos = pagePos + 1;
            let splitOffset = 0;
            for (let c = 0; c < splitIndex; c++) {
                splitOffset += pageNode.child(c).nodeSize;
            }

            const fromPos = insidePagePos + splitOffset;
            const toPos = insidePagePos + pageNode.content.size;

            if (fromPos >= toPos) return;

            const nodesToMove = [];
            for (let c = splitIndex; c < pageNode.childCount; c++) {
                nodesToMove.push(pageNode.child(c));
            }
            if (nodesToMove.length === 0) return;

            if (pageIndex + 1 < doc.childCount) {
                tr.delete(fromPos, toPos);

                let nextPagePos = 0;
                for (let i = 0; i < pageIndex + 1; i++) {
                    nextPagePos += doc.child(i).nodeSize;
                }
                const mappedNextPageStart = tr.mapping.map(nextPagePos + 1);
                tr.insert(mappedNextPageStart, nodesToMove);
            } else {
                tr.delete(fromPos, toPos);

                const newPageNode = edState.schema.nodes.mouPage.create(
                    { pageNumber: pageIndex + 2 },
                    nodesToMove
                );

                const mappedInsertPos = tr.mapping.map(pagePos + pageNode.nodeSize);
                tr.insert(mappedInsertPos, newPageNode);
            }

            if (currentSel) {
                try {
                    tr.setSelection(currentSel.map(tr.doc, tr.mapping));
                } catch (e) {}
            }

            tr.setMeta('addToHistory', false);
            tr.setMeta(reflowPluginKey, true);
            try {
                view.dispatch(tr);
            } catch (dispatchErr) {
                console.error('[WordProcessor] Reflow dispatch error:', dispatchErr);
            }

            restoreScrollState();
            requestAnimationFrame(restoreScrollState);
            setTimeout(restoreScrollState, 20);
            setTimeout(restoreScrollState, 60);

            scheduleReflow(true);
        } else if (action.type === 'underflow') {
            const { fromPageIndex, toPageIndex } = action;
            const fromPageNode = doc.child(fromPageIndex);
            const toPageNode = doc.child(toPageIndex);

            if (fromPageNode.childCount === 0) return;

            const nodeToMove = fromPageNode.child(0);

            let fromPagePos = 0;
            for (let i = 0; i < fromPageIndex; i++) {
                fromPagePos += doc.child(i).nodeSize;
            }
            const fromInside = fromPagePos + 1;
            const fromEnd = fromInside + nodeToMove.nodeSize;

            let toPagePos = 0;
            for (let i = 0; i < toPageIndex; i++) {
                toPagePos += doc.child(i).nodeSize;
            }
            const toInsideEnd = toPagePos + 1 + toPageNode.content.size;

            tr.delete(fromInside, fromEnd);

            const mappedToInsideEnd = tr.mapping.map(toInsideEnd);
            tr.insert(mappedToInsideEnd, nodeToMove);

            if (fromPageNode.childCount === 1) {
                const mappedFromPagePos = tr.mapping.map(fromPagePos);
                tr.delete(mappedFromPagePos, mappedFromPagePos + 2);
            }

            if (currentSel) {
                try {
                    tr.setSelection(currentSel.map(tr.doc, tr.mapping));
                } catch (e) {}
            }

            tr.setMeta('addToHistory', false);
            tr.setMeta(reflowPluginKey, true);
            view.dispatch(tr);

            restoreScrollState();
            requestAnimationFrame(restoreScrollState);
            setTimeout(restoreScrollState, 20);
            setTimeout(restoreScrollState, 60);

            scheduleReflow(false);
        } else if (action.type === 'remove_empty_page') {
            const { pageIndex } = action;
            if (pageIndex === 0) return;

            let pagePos = 0;
            for (let i = 0; i < pageIndex; i++) {
                pagePos += doc.child(i).nodeSize;
            }
            const pageEnd = pagePos + doc.child(pageIndex).nodeSize;

            tr.delete(pagePos, pageEnd);

            if (currentSel) {
                try {
                    tr.setSelection(currentSel.map(tr.doc, tr.mapping));
                } catch (e) {}
            }

            tr.setMeta('addToHistory', false);
            tr.setMeta(reflowPluginKey, true);
            view.dispatch(tr);

            restoreScrollState();
            requestAnimationFrame(restoreScrollState);
            setTimeout(restoreScrollState, 20);
            setTimeout(restoreScrollState, 60);

            scheduleReflow(false);
        }
    }

    /**
     * Update word count, character count, and active selection info in Status Bar.
     */
    function updateDocumentMetrics() {
        if (!bodyEditor || bodyEditor.isDestroyed) return;
        const { state: edState } = bodyEditor;
        const doc = edState.doc;
        const totalPages = doc.childCount || 1;

        let currentPage = 1;
        try {
            const { $from } = edState.selection;
            currentPage = $from.index(0) + 1;
        } catch (e) {
            currentPage = 1;
        }

        const pageBadge = document.getElementById('statusBarPageCount');
        if (pageBadge) {
            pageBadge.textContent = `Halaman ${currentPage} dari ${totalPages} (${state.paperSize})`;
        }

        const text = bodyEditor.getText() || '';
        const words = text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
        const chars = text.length;

        const wordBadge = document.getElementById('statusBarWordCount');
        if (wordBadge) {
            wordBadge.textContent = `${words} Kata (${chars} Karakter)`;
        }
    }

    /**
     * Reflect current cursor/selection formatting in the Ribbon toolbar controls.
     */
    function updateToolbarState() {
        const editor = getActiveEditor();
        if (!editor) return;

        // Toggle active button states
        document.querySelectorAll('[data-tiptap-mark]').forEach(btn => {
            const mark = btn.getAttribute('data-tiptap-mark');
            btn.classList.toggle('mou-ribbon-btn-active', editor.isActive(mark));
        });

        // 2. Alignment Buttons (Left, Center, Right, Justify) with full block/cell fallback
        let currentAlign = null;
        if (editor.isActive({ textAlign: 'center' })) currentAlign = 'center';
        else if (editor.isActive({ textAlign: 'right' })) currentAlign = 'right';
        else if (editor.isActive({ textAlign: 'justify' })) currentAlign = 'justify';
        else if (editor.isActive({ textAlign: 'left' })) currentAlign = 'left';

        if (!currentAlign) {
            try {
                const { $from } = editor.state.selection;
                for (let d = $from.depth; d > 0; d--) {
                    const n = $from.node(d);
                    if (n.attrs && n.attrs.textAlign) {
                        currentAlign = n.attrs.textAlign;
                        break;
                    }
                }
            } catch (_) {}
        }
        if (!currentAlign) currentAlign = 'left';

        document.querySelectorAll('[data-tiptap-align]').forEach(btn => {
            const align = btn.getAttribute('data-tiptap-align');
            btn.classList.toggle('mou-ribbon-btn-active', align === currentAlign);
        });

        // 3. Undo / Redo ribbon button states
        const undoBtn = document.getElementById('btnRibbonUndo');
        if (undoBtn) {
            const canEdUndo = typeof editor.can === 'function' && editor.can().undo();
            const canAssetUndo = assetHistoryIndex > 0;
            const canUndo = canEdUndo || canAssetUndo;
            undoBtn.classList.toggle('opacity-40', !canUndo);
            undoBtn.classList.toggle('cursor-not-allowed', !canUndo);
        }
        const redoBtn = document.getElementById('btnRibbonRedo');
        if (redoBtn) {
            const canEdRedo = typeof editor.can === 'function' && editor.can().redo();
            const canAssetRedo = assetHistoryIndex < assetHistory.length - 1;
            const canRedo = canEdRedo || canAssetRedo;
            redoBtn.classList.toggle('opacity-40', !canRedo);
            redoBtn.classList.toggle('cursor-not-allowed', !canRedo);
        }

        const bulletBtn = document.getElementById('btnToggleBulletList');
        if (bulletBtn) bulletBtn.classList.toggle('mou-ribbon-btn-active', editor.isActive('bulletList'));

        const orderedBtn = document.getElementById('btnToggleOrderedList');
        if (orderedBtn) orderedBtn.classList.toggle('mou-ribbon-btn-active', editor.isActive('orderedList'));

        // Synchronize Active Ordered List Numbering Style Dropdown & Split Button Label
        let currentOrderedListType = '1';
        if (editor.isActive('orderedList')) {
            try {
                const { $from } = editor.state.selection;
                for (let d = $from.depth; d > 0; d--) {
                    const n = $from.node(d);
                    if (n.type.name === 'orderedList') {
                        currentOrderedListType = n.attrs.listType || '1';
                        break;
                    }
                }
            } catch (_) {}
        }

        const orderedListSelect = document.getElementById('ribbonOrderedListStyle');
        if (orderedListSelect && document.activeElement !== orderedListSelect) {
            orderedListSelect.value = currentOrderedListType;
        }

        const activeListLabel = document.getElementById('ribbonOrderedListActiveLabel');
        if (activeListLabel) {
            const labelMap = {
                '1': '1. Nomor',
                'a': 'a. Nomor',
                'A': 'A. Nomor',
                'i': 'i. Nomor',
                'I': 'I. Nomor',
            };
            activeListLabel.textContent = editor.isActive('orderedList') ? (labelMap[currentOrderedListType] || '1. Nomor') : '1. Nomor';
        }

        // Highlight active format in custom dropdown menu
        document.querySelectorAll('#orderedListStyleDropdown button[data-list-type]').forEach(btn => {
            const isCur = editor.isActive('orderedList') && btn.getAttribute('data-list-type') === currentOrderedListType;
            btn.classList.toggle('bg-blue-50', isCur);
            btn.classList.toggle('text-blue-700', isCur);
            btn.classList.toggle('font-semibold', isCur);
        });

        // Reflect Active Font Family, Size & Heading in Home Ribbon
        const fontSelect = document.getElementById('ribbonFontFamily');
        if (fontSelect && document.activeElement !== fontSelect) {
            let ff = editor.getAttributes('textStyle')?.fontFamily;
            if (!ff) {
                try {
                    const { $from } = editor.state.selection;
                    for (let d = $from.depth; d > 0; d--) {
                        const n = $from.node(d);
                        if (n.attrs && n.attrs.fontFamily) { ff = n.attrs.fontFamily; break; }
                    }
                } catch (_) {}
            }
            if (!ff) ff = state.fontFamily || 'Times New Roman';
            fontSelect.value = ff;
        }
        const fsSelect = document.getElementById('ribbonFontSize');
        if (fsSelect && document.activeElement !== fsSelect) {
            let fs = editor.getAttributes('textStyle')?.fontSize;
            if (!fs) {
                try {
                    const { $from } = editor.state.selection;
                    for (let d = $from.depth; d > 0; d--) {
                        const n = $from.node(d);
                        if (n.attrs && n.attrs.fontSize) { fs = n.attrs.fontSize; break; }
                    }
                } catch (_) {}
            }
            if (fs) {
                const cleanFs = String(fs).replace('pt', '').trim();
                fsSelect.value = cleanFs;
            } else {
                fsSelect.value = String(state.fontSize || 12);
            }
        }
        const headingSelect = document.getElementById('ribbonHeadingStyle');
        if (headingSelect && document.activeElement !== headingSelect) {
            if (editor.isActive('heading', { level: 1 })) headingSelect.value = 'h1';
            else if (editor.isActive('heading', { level: 2 })) headingSelect.value = 'h2';
            else if (editor.isActive('heading', { level: 3 })) headingSelect.value = 'h3';
            else if (editor.isActive('heading', { level: 4 })) headingSelect.value = 'h4';
            else headingSelect.value = 'p';
        }

        // Reflect Active Line Height in Home Ribbon
        let activeLh = null;
        try {
            const { $from } = editor.state.selection;
            for (let d = $from.depth; d > 0; d--) {
                const n = $from.node(d);
                if (n.isTextblock) {
                    if (n.attrs && n.attrs.lineHeight) {
                        activeLh = n.attrs.lineHeight;
                    }
                    break;
                }
            }
        } catch (_) {}

        if (!activeLh) {
            try {
                const domNode = editor.view?.domAtPos(editor.state.selection.from)?.node;
                const el = domNode?.nodeType === 1 ? domNode : domNode?.parentElement;
                const blockEl = el?.closest('p, h1, h2, h3, h4, li');
                if (blockEl) {
                    const styleLh = blockEl.style.lineHeight || extractStyleProperty(blockEl.getAttribute('style'), 'line-height');
                    if (styleLh) activeLh = styleLh;
                }
            } catch (_) {}
        }

        if (!activeLh) {
            activeLh = editor.getAttributes('paragraph')?.lineHeight || editor.getAttributes('heading')?.lineHeight;
        }

        const rawLh = activeLh ? String(activeLh).trim() : '1';
        const num = parseFloat(rawLh);
        let matched = '1';
        if (isNaN(num)) matched = '1';
        else if (Math.abs(num - 1.0) < 0.1) matched = '1';
        else if (Math.abs(num - 1.5) < 0.1) matched = '1.5';
        else if (Math.abs(num - 2.0) < 0.1) matched = '2';
        else if (Math.abs(num - 2.5) < 0.1) matched = '2.5';
        else if (Math.abs(num - 3.0) < 0.1) matched = '3';
        else if (Math.abs(num - 1.15) < 0.1) matched = '1';
        else matched = String(num);

        const lhSelect = document.getElementById('ribbonParagraphLineHeight');
        if (lhSelect && document.activeElement !== lhSelect) {
            lhSelect.value = matched;
        }

        const lhActiveLabel = document.getElementById('ribbonLineHeightActiveLabel');
        if (lhActiveLabel) {
            const labelMap = {
                '1': 'Spasi 1.0',
                '1.5': 'Spasi 1.5',
                '2': 'Spasi 2.0',
                '2.5': 'Spasi 2.5',
                '3': 'Spasi 3.0',
            };
            lhActiveLabel.textContent = labelMap[matched] || `Spasi ${matched}`;
        }

        const lhToggleBtn = document.getElementById('btnParagraphLineHeightMenuToggle');
        if (lhToggleBtn) {
            const isCustom = matched !== '1';
            lhToggleBtn.classList.toggle('border-blue-400', isCustom);
            lhToggleBtn.classList.toggle('bg-blue-50/50', isCustom);
        }

        document.querySelectorAll('#paragraphLineHeightDropdown button[data-line-height]').forEach(btn => {
            const isCur = btn.getAttribute('data-line-height') === matched;
            btn.classList.toggle('bg-blue-50', isCur);
            btn.classList.toggle('text-blue-700', isCur);
            btn.classList.toggle('font-semibold', isCur);
            btn.classList.toggle('is-active', isCur);
            const checkIcon = btn.querySelector('svg');
            if (checkIcon) {
                checkIcon.classList.toggle('opacity-100', isCur);
                checkIcon.classList.toggle('opacity-0', !isCur);
            }
        });

        // Update Table Context Indicator & inputs if inside a table
        const isInTable = editor.isActive('table');
        const tableTabBadge = document.getElementById('ribbonTableContextBadge');
        if (tableTabBadge) {
            tableTabBadge.classList.toggle('hidden', !isInTable);
        }

        const activeTabBtn = document.querySelector('[data-ribbon-tab].active');
        const activeTab = activeTabBtn ? activeTabBtn.getAttribute('data-ribbon-tab') : 'home';

        if (isInTable && !state.wasInTable) {
            if (activeTab === 'home' || activeTab === 'table') {
                switchRibbonTab('table');
            }
        } else if (!isInTable && state.wasInTable) {
            if (activeTab === 'table') {
                switchRibbonTab('home');
            }
        }
        state.wasInTable = isInTable;

        if (isInTable) {
            const tableAttrs = editor.getAttributes('table');
            const rowAttrs = editor.getAttributes('tableRow');
            const cellAttrs = editor.isActive('tableHeader')
                ? editor.getAttributes('tableHeader')
                : editor.getAttributes('tableCell');

            const twInput = document.getElementById('ribbonTableWidthInput');
            if (twInput && document.activeElement !== twInput) {
                twInput.value = tableAttrs.tableWidth || '100%';
            }
            const rhInput = document.getElementById('ribbonRowHeightInput');
            if (rhInput && document.activeElement !== rhInput) {
                rhInput.value = rowAttrs.rowHeight || cellAttrs.rowHeight || '';
            }
            const cwInput = document.getElementById('ribbonColWidthInput');
            if (cwInput && document.activeElement !== cwInput) {
                if (Array.isArray(cellAttrs.colwidth) && cellAttrs.colwidth[0]) {
                    cwInput.value = String(cellAttrs.colwidth[0]);
                } else if (cellAttrs.cellWidth) {
                    cwInput.value = String(parseInt(cellAttrs.cellWidth, 10) || '');
                } else {
                    cwInput.value = '';
                }
            }

            const padSelect = document.getElementById('ribbonCellPaddingSelect');
            if (padSelect && document.activeElement !== padSelect) {
                padSelect.value = cellAttrs.padding || '';
            }
            const valignSelect = document.getElementById('ribbonCellValignSelect');
            if (valignSelect && document.activeElement !== valignSelect) {
                valignSelect.value = cellAttrs.verticalAlign || 'top';
            }
            const cellBgInput = document.getElementById('ribbonCellBgColor');
            if (cellBgInput && document.activeElement !== cellBgInput && cellAttrs.backgroundColor) {
                cellBgInput.value = cellAttrs.backgroundColor;
            }
        }

        // Reflect Active Text Color & Highlight Color in ribbon
        const textColorInput = document.getElementById('ribbonTextColor');
        if (textColorInput && document.activeElement !== textColorInput) {
            const currentColor = editor.getAttributes('textStyle')?.color;
            if (currentColor && /^#[0-9a-fA-F]{6}$/i.test(currentColor)) {
                textColorInput.value = currentColor;
            }
        }
        const highlightColorInput = document.getElementById('ribbonHighlightColor');
        if (highlightColorInput && document.activeElement !== highlightColorInput) {
            const currentHl = editor.getAttributes('highlight')?.color;
            if (currentHl && /^#[0-9a-fA-F]{6}$/i.test(currentHl)) {
                highlightColorInput.value = currentHl;
            }
        }
        const removeHlBtn = document.getElementById('btnRemoveHighlight');
        if (removeHlBtn) {
            removeHlBtn.classList.toggle('mou-ribbon-btn-active', editor.isActive('highlight'));
        }

        // Synchronize Horizontal Ruler Indent Markers & Table Column Dividers
        updateRulerMarkersFromEditor();
        syncRulerToPageSheet();
    }

    /**
     * Substitute dynamic placeholders in HTML with live form values for Preview mode.
     */
    function replaceLivePreviewPlaceholders(rawHtml) {
        if (!rawHtml) return '';
        const getField = (id, fallback) => {
            const el = document.getElementById(id);
            return (el && el.value && el.value.trim() !== '') ? el.value.trim() : fallback;
        };

        const isMou = (state.documentType || 'mou') === 'mou';
        const compLogo = state.mediaAssets.company_logo || state.mediaAssets.company_logo_url || '/images/mou/seven-inc-logo.png';
        const instLogo = isMou ? (state.mediaAssets.institution_logo || state.mediaAssets.institution_logo_url || '/images/mou/institution-logo-placeholder.png') : '';

        const map = {
            company_name: getField('company_name', 'SEVEN INC'),
            company_signatory_name: getField('company_signatory_name', 'Rekario Danny Sanjaya, S.Kom.'),
            company_signatory_title: getField('company_signatory_title', 'Pimpinan'),
            company_correspondence_unit: getField('company_correspondence_unit', 'CEO'),
            company_address: getField('company_address', 'Jl. Janti Gg. Arjuna No.59, Karangjambe, Banguntapan, Bantul, Yogyakarta 55198'),
            company_phone: getField('company_phone', '089529002944'),
            company_fax: getField('company_fax', '-'),
            company_email: getField('company_email', 'magangjogjadotcom@gmail.com'),
            agreement_place: getField('agreement_place', 'Yogyakarta'),
            agreement_duration: getField('agreement_duration', '5 (lima) tahun'),
            company_mou_number: '004/SPK.MAGANG/CEO/SEVEN/V/' + new Date().getFullYear(),
            institution_name: isMou ? 'UNIVERSITAS CONTOH INDONESIA' : '',
            institution_mou_number: isMou ? ('045/UNIV-CI/MOU/XI/' + new Date().getFullYear()) : '',
            institution_signatory_name: isMou ? 'Dr. Rido Kurnianto, M.Ag.' : '',
            institution_signatory_title: isMou ? 'Rektor' : '',
            institution_address: isMou ? 'Jl. Budi Utomo No.10, Ronowijayan, Kec. Ponorogo, Kabupaten Ponorogo, Jawa Timur 63471' : '',
            institution_correspondence_unit: isMou ? 'Kepala Bidang Kerjasama Dalam Negeri' : '',
            institution_phone: isMou ? '0352-481124' : '',
            institution_fax: isMou ? '461796' : '',
            institution_email: isMou ? 'kerjasama@univ-contoh.ac.id' : '',
            agreement_day: 'Sabtu',
            agreement_date: '24 Mei ' + new Date().getFullYear(),
            agreement_date_short: '24-05-' + new Date().getFullYear(),
            agreement_date_words: 'tanggal dua puluh empat bulan Mei tahun ' + new Date().getFullYear(),
            effective_start: '24 Mei ' + new Date().getFullYear(),
            effective_end: '24 Mei ' + (new Date().getFullYear() + 5),
        };

        let out = rawHtml;
        // Replace logo placeholders first
        const compLogoImg = `<img src="${compLogo}" alt="Logo Perusahaan" class="mou-logo-img" style="width: 95px; height: auto; display: block; margin: 0 auto;" />`;
        const instLogoImg = (isMou && instLogo && instLogo !== 'none')
            ? `<img src="${instLogo}" alt="Logo Institusi" class="mou-logo-img" style="width: 95px; height: auto; display: block; margin: 0 auto;" />`
            : '';

        out = out.replace(/(src=["'])\{\{\s*asset\.(?:brand|company)\.logo\s*\}\}(["'])/gi, `$1${compLogo}$2`);
        out = out.replace(/(src=["'])\{\{\s*(?:company_logo|brand_logo)\s*\}\}(["'])/gi, `$1${compLogo}$2`);
        out = out.replace(/(src=["'])\[(?:company_logo|brand_logo)\](["'])/gi, `$1${compLogo}$2`);
        out = out.replace(/\[company_logo\]|\{\{\s*company_logo\s*\}\}/g, compLogoImg);
        out = out.replace(/\[asset\.(?:brand|company)\.logo\]|\{\{\s*asset\.(?:brand|company)\.logo\s*\}\}/g, compLogoImg);

        out = out.replace(/(src=["'])\{\{\s*asset\.(?:institusi|institution)\.logo\s*\}\}(["'])/gi, isMou ? `$1${instLogo}$2` : '');
        out = out.replace(/\[institution_logo\]|\{\{\s*institution_logo\s*\}\}/g, instLogoImg);
        out = out.replace(/\[asset\.(?:institusi|institution)\.logo\]|\{\{\s*asset\.(?:institusi|institution)\.logo\s*\}\}/g, instLogoImg);

        const sigSrc = state.mediaAssets?.signature || state.mediaAssets?.tanda_tangan || '/images/mou/signature-sample.png';
        const stampSrc = state.mediaAssets?.stamp || state.mediaAssets?.stempel || '/images/mou/stamp-sample.png';

        const sigImg = `<img src="${sigSrc}" alt="Tanda Tangan" class="mou-preview-signature-img" style="height: 60px; max-width: 140px; object-fit: contain; display: inline-block; vertical-align: middle;" />`;
        const stampImg = `<img src="${stampSrc}" alt="Stempel Resmi" class="mou-preview-stamp-img" style="height: 60px; max-width: 140px; object-fit: contain; display: inline-block; vertical-align: middle;" />`;

        out = out.replace(/(src=["'])\{\{\s*(?:asset\.(?:brand\.)?tanda_tangan|asset\.signature|company_signature|signature|tanda_tangan)\s*\}\}(["'])/gi, `$1${sigSrc}$2`);
        out = out.replace(/(src=["'])\{\{\s*(?:asset\.(?:brand\.)?stempel|asset\.stamp|company_stamp|stamp|stempel)\s*\}\}(["'])/gi, `$1${stampSrc}$2`);
        out = out.replace(/(src=["'])\[(?:asset\.(?:brand\.)?tanda_tangan|asset\.signature|company_signature|signature|tanda_tangan)\](["'])/gi, `$1${sigSrc}$2`);
        out = out.replace(/(src=["'])\[(?:asset\.(?:brand\.)?stempel|asset\.stamp|company_stamp|stamp|stempel)\](["'])/gi, `$1${stampSrc}$2`);

        out = out.replace(/\[(?:asset\.(?:brand\.)?tanda_tangan|asset\.signature|company_signature|signature|tanda_tangan)\]|\{\{\s*(?:asset\.(?:brand\.)?tanda_tangan|asset\.signature|company_signature|signature|tanda_tangan)\s*\}\}/gi, sigImg);
        out = out.replace(/\[(?:asset\.(?:brand\.)?stempel|asset\.stamp|company_stamp|stamp|stempel)\]|\{\{\s*(?:asset\.(?:brand\.)?stempel|asset\.stamp|company_stamp|stamp|stempel)\s*\}\}/gi, stampImg);

        Object.entries(map).forEach(([key, val]) => {
            const safeVal = String(val)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            const reg = new RegExp(`\\[${key}\\]|\\{\\{\\s*${key}\\s*\\}\\}`, 'g');
            out = out.replace(reg, safeVal);
        });

        return out;
    }

    /**
     * Multi-Sheet A4 Preview Renderer (Mode Pratinjau Lembar A4 inside the editor studio or modal)
     */
    /**
     * Multi-Sheet A4 Preview Renderer (Mode Pratinjau Lembar A4 inside the editor studio or modal)
     * Maps 1-to-1 to the pages composed in the editor (saat menyusun).
     */
    function renderMultiSheetPreview(targetStageId = null, badgeId = null) {
        saveActiveHeaderFooterToStore();

        const targets = [];
        if (targetStageId) {
            const el = typeof targetStageId === 'string' ? document.getElementById(targetStageId) : targetStageId;
            if (el) targets.push({ stage: el, badge: badgeId });
        } else {
            const modalStage = document.getElementById('modalPreviewPagesStage');
            if (modalStage) targets.push({ stage: modalStage, badge: 'mouModalPageCountBadge' });
            const inlineStage = document.getElementById('mouMultiSheetPreviewStage');
            if (inlineStage) targets.push({ stage: inlineStage, badge: 'inlinePreviewPageCountBadge' });
        }

        if (targets.length === 0) return 0;

        const dims = getPaperDimensionsMm();

        // 1. Ambil halaman langsung dari struktur dokumen editor saat menyusun (1-to-1)
        let pagesHtmlList = [];

        // Prioritas 1: Ambil dari elemen live DOM editor (view.dom)
        if (bodyEditor?.view?.dom) {
            const editorPageEls = Array.from(bodyEditor.view.dom.querySelectorAll('.mou-a4-page-node'));
            if (editorPageEls.length > 0) {
                pagesHtmlList = editorPageEls.map(pageEl => {
                    const contentEl = pageEl.querySelector('.mou-page-body-content');
                    return contentEl ? contentEl.innerHTML : pageEl.innerHTML;
                });
            }
        }

        // Prioritas 2: Fallback dari HTML serialisasi Tiptap
        if (pagesHtmlList.length === 0 && bodyEditor) {
            const rawEditorHtml = bodyEditor.getHTML();
            const parser = new DOMParser();
            const doc = parser.parseFromString(`<div id="preview-src">${rawEditorHtml}</div>`, 'text/html');
            const root = doc.getElementById('preview-src');
            const serializedPageNodes = root ? Array.from(root.querySelectorAll('[data-mou-page="true"], .mou-a4-page-node')) : [];
            if (serializedPageNodes.length > 0) {
                pagesHtmlList = serializedPageNodes.map(p => p.innerHTML);
            } else if (root && root.innerHTML.trim()) {
                pagesHtmlList = [root.innerHTML];
            }
        }

        if (pagesHtmlList.length === 0) {
            pagesHtmlList = ['<p></p>'];
        }

        const totalPages = pagesHtmlList.length;

        // 2. Render setiap halaman ke dalam lembar kertas A4 pratinjau
        targets.forEach(({ stage, badge }) => {
            stage.innerHTML = '';

            pagesHtmlList.forEach((rawPageBodyHtml, idx) => {
                const pageIndex = idx + 1;

                const sheet = document.createElement('div');
                sheet.className = 'mou-preview-a4-sheet';
                sheet.style.width = `${dims.width}mm`;
                sheet.style.minWidth = `${dims.width}mm`;
                sheet.style.maxWidth = `${dims.width}mm`;
                sheet.style.height = `${dims.height}mm`;
                sheet.style.minHeight = `${dims.height}mm`;
                sheet.style.maxHeight = `${dims.height}mm`;
                sheet.style.boxSizing = 'border-box';
                sheet.style.paddingLeft = `${state.marginLeftMm}mm`;
                sheet.style.paddingRight = `${state.marginRightMm}mm`;
                sheet.style.paddingTop = `${Math.min(state.headerDistanceMm, state.marginTopMm)}mm`;
                sheet.style.paddingBottom = `${Math.min(state.footerDistanceMm, state.marginBottomMm)}mm`;
                sheet.style.fontFamily = `'${state.fontFamily}', Times, serif`;
                sheet.style.fontSize = `${state.fontSizePt}pt`;
                sheet.style.lineHeight = String(state.lineHeight);

                // Watermark Layer
                const watermarkHtml = renderWatermarkLayerHtml(state, pageIndex === 1);
                if (watermarkHtml) {
                    const watermarkEl = document.createElement('div');
                    watermarkEl.className = 'mou-page-watermark-layer';
                    watermarkEl.innerHTML = watermarkHtml;
                    sheet.appendChild(watermarkEl);
                }

                // Render Floating Header Logos on Page 1 from headerLogosConfig
                if (pageIndex === 1 && state.showHeaderLogos !== false) {
                    const isMou = (state?.documentType === 'mou');
                    if (isMou) {
                        const cfg = state.headerLogosConfig || {
                            institution: { width: 66, height: 66, rotation: 0, topOffsetMm: 0, leftOffsetMm: 0 },
                            company: { width: 66, height: 66, rotation: 0, topOffsetMm: 0, rightOffsetMm: 0 },
                        };
                        const topHeaderMm = Math.max(10, state.marginTopMm - 10);
                        const compLogoUrl = state.mediaAssets?.company_logo || state.mediaAssets?.company_logo_url || '/images/mou/seven-inc-logo.png';
                        const instLogoUrl = state.mediaAssets?.institution_logo || state.mediaAssets?.institution_logo_url || '/images/mou/institution-logo-placeholder.png';

                        const inst = cfg.institution || {};
                        const comp = cfg.company || {};
                        const instW = inst.width || 66;
                        const instH = inst.height || 66;
                        const instRot = inst.rotation || 0;
                        const instTop = topHeaderMm + (inst.topOffsetMm || 0);
                        const instLeft = state.marginLeftMm + (inst.leftOffsetMm || 0);

                        const compW = comp.width || 66;
                        const compH = comp.height || 66;
                        const compRot = comp.rotation || 0;
                        const compTop = topHeaderMm + (comp.topOffsetMm || 0);
                        const compRight = state.marginRightMm + (comp.rightOffsetMm || 0);

                        const logosLayer = document.createElement('div');
                        logosLayer.className = 'mou-preview-sheet-logos-stage';
                        logosLayer.style.cssText = 'position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 20; box-sizing: border-box;';
                        logosLayer.innerHTML = `
                            <div style="position: absolute; top: ${instTop}mm; left: ${instLeft}mm; width: ${instW}px; height: ${instH}px;">
                                <img src="${instLogoUrl}" alt="Logo Institusi" style="width: 100%; height: 100%; object-fit: contain; ${instRot ? `transform: rotate(${instRot}deg); transform-origin: center center;` : ''}" />
                            </div>
                            <div style="position: absolute; top: ${compTop}mm; right: ${compRight}mm; width: ${compW}px; height: ${compH}px;">
                                <img src="${compLogoUrl}" alt="Logo Seven Inc" style="width: 100%; height: 100%; object-fit: contain; ${compRot ? `transform: rotate(${compRot}deg); transform-origin: center center;` : ''}" />
                            </div>
                        `;
                        sheet.appendChild(logosLayer);
                    }
                }

                // Header
                const useFirstPage = state.differentFirstPage && pageIndex === 1;
                const hStore = useFirstPage ? state.firstPageHeaderHtmlStore : state.headerHtmlStore;
                const hHtml = replaceLivePreviewPlaceholders(hStore);

                if (hHtml && hHtml.trim() !== '' && hHtml.trim() !== '<p></p>') {
                    const headerEl = document.createElement('div');
                    headerEl.className = 'mou-preview-sheet-header mou-doc-canvas';
                    headerEl.innerHTML = hHtml;
                    const bodyTopGapMm = Math.max(2, state.marginTopMm - state.headerDistanceMm);
                    headerEl.style.marginBottom = `${bodyTopGapMm}mm`;
                    sheet.appendChild(headerEl);
                }

                // Body dokumen persis seperti saat menyusun di editor
                const bodyEl = document.createElement('div');
                bodyEl.className = 'mou-preview-sheet-body mou-doc-canvas';
                const cleanBodyHtml = synchronizeSerializedHtml(rawPageBodyHtml);
                bodyEl.innerHTML = replaceLivePreviewPlaceholders(cleanBodyHtml);
                sheet.appendChild(bodyEl);

                // Footer (Kotak Paraf di kiri, Penomoran Halaman di kanan)
                const fStore = useFirstPage ? state.firstPageFooterHtmlStore : state.footerHtmlStore;
                const fHtml = replaceLivePreviewPlaceholders(fStore);

                const footerEl = document.createElement('div');
                footerEl.className = 'mou-preview-sheet-footer';
                const bodyBtmGapMm = Math.max(2, state.marginBottomMm - state.footerDistanceMm);
                footerEl.style.marginTop = `${bodyBtmGapMm}mm`;
                footerEl.innerHTML = `
                    <div class="mou-footer-content-row flex items-end justify-between gap-4 w-full">
                        <div class="mou-preview-sheet-footer-left flex-1 min-w-0" style="visibility: ${state.showParafBox ? 'visible' : 'hidden'};">
                            ${state.showParafBox ? fHtml : ''}
                        </div>
                        <div class="mou-preview-sheet-pagenum font-serif text-[9pt] text-black pb-0.5 shrink-0 select-none text-right whitespace-nowrap" style="visibility: ${state.showPageNumber ? 'visible' : 'hidden'};">
                            Halaman ${pageIndex} dari ${totalPages}
                        </div>
                    </div>
                `;
                sheet.appendChild(footerEl);

                stage.appendChild(sheet);
            });

            if (badge) {
                const bEl = typeof badge === 'string' ? document.getElementById(badge) : badge;
                if (bEl) bEl.textContent = `${totalPages} Halaman A4`;
            }
        });

        return totalPages;
    }

    // Observe Header, Footer, and Workspace Viewport changes for complete responsive layout
    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(() => {
            updateVisualPageBreakGuides();
        });
        if (headerZoneWrapper) ro.observe(headerZoneWrapper);
        if (footerZoneWrapper) ro.observe(footerZoneWrapper);
        if (bodyMountEl) ro.observe(bodyMountEl);

        const workspaceViewport = document.getElementById('mouEditorWorkspaceView');
        if (workspaceViewport) {
            const vpRo = new ResizeObserver(() => {
                applyPageLayoutToCanvas();
                updateVisualPageBreakGuides();
                syncRulerToPageSheet();
            });
            vpRo.observe(workspaceViewport);
        }
    }

    // Debounced window resize event for screen changes (sidebar toggle, screen orientation, zoom)
    let windowResizeDebounceTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(windowResizeDebounceTimer);
        windowResizeDebounceTimer = setTimeout(() => {
            applyPageLayoutToCanvas();
            updateVisualPageBreakGuides();
            syncRulerToPageSheet();
        }, 80);
    });

    // Initial layout & sync
    applyPageLayoutToCanvas();
    syncAllToHiddenInputs();

    // Form submit hook to guarantee latest HTML & JSON are synced
    const candidateForms = [
        document.getElementById('mouTemplateForm'),
        document.getElementById('documentTemplateForm'),
        bodyMountEl ? bodyMountEl.closest('form') : null,
    ].filter(Boolean);
    const attachedForms = new Set();
    candidateForms.forEach(f => {
        if (!attachedForms.has(f)) {
            attachedForms.add(f);
            f.addEventListener('submit', () => {
                syncAllToHiddenInputs();
            });
        }
    });

    // Public controller API exposed to Ribbon UI and window
    const api = {
        state,
        bodyEditor,
        headerEditor,
        footerEditor,
        getActiveEditor,
        setActiveZone,
        syncAllToHiddenInputs,
        applyPageLayoutToCanvas,
        renderMultiSheetPreview,
        updateToolbarState,

        // Document-level actions
        newBlankDocument() {
            setActiveZone('body');
            state.allowBlankTemplate = true;
            bodyEditor.commands.setContent(normalizeHtmlForBodyEditor('<p></p>'));
            scheduleReflow(true);
            scheduleSyncAndPagination();
        },
        loadSampleMouDocument() {
            setActiveZone('body');
            state.allowBlankTemplate = false;
            bodyEditor.commands.setContent(normalizeHtmlForBodyEditor(defaultSampleHtml || '<p></p>'));
            scheduleReflow(true);
            scheduleSyncAndPagination();
        },
        loadExistingTemplateData(tplData) {
            if (!tplData || typeof tplData !== 'object') return false;
            setActiveZone('body');
            state.allowBlankTemplate = false;

            const normalizedHtml = normalizeLegacyHtmlForTiptap(tplData.template_html || defaultSampleHtml || '<p></p>');
            let loadedViaJson = false;
            if (tplData.editor_json && tplData.editor_json.type === 'doc' && Array.isArray(tplData.editor_json.content) && tplData.editor_json.content.length > 0) {
                try {
                    const safeJson = ensureJsonHasMouPage(tplData.editor_json);
                    bodyEditor.commands.setContent(safeJson);
                    loadedViaJson = !bodyEditor.isEmpty;
                } catch (err) {
                    console.warn('Fallback to template_html when loading saved template:', err);
                }
            }
            if (!loadedViaJson) {
                bodyEditor.commands.setContent(normalizeHtmlForBodyEditor(normalizedHtml));
            }
            scheduleReflow(true);

            const pCfg = tplData.page_config || {};
            state.paperSize = (pCfg.paper_size || 'A4').toUpperCase();
            state.orientation = (pCfg.orientation || 'portrait').toLowerCase();
            state.marginTopMm = parseFloat(pCfg.margin_top_mm ?? 25.4);
            state.marginRightMm = parseFloat(pCfg.margin_right_mm ?? 25.4);
            state.marginBottomMm = parseFloat(pCfg.margin_bottom_mm ?? 25.4);
            state.marginLeftMm = parseFloat(pCfg.margin_left_mm ?? 25.4);
            state.headerDistanceMm = parseFloat(pCfg.header_distance_mm ?? 12.0);
            state.footerDistanceMm = parseFloat(pCfg.footer_distance_mm ?? 10.0);
            state.differentFirstPage = Boolean(pCfg.different_first_page);
            state.showParafBox = pCfg.show_paraf_box !== undefined ? Boolean(pCfg.show_paraf_box) : true;
            state.showPageNumber = pCfg.show_page_number !== undefined ? Boolean(pCfg.show_page_number) : true;
            state.fontFamily = pCfg.font_family || 'Times New Roman';
            state.fontSizePt = parseInt(pCfg.font_size_pt ?? 12, 10);
            state.lineHeight = parseFloat(pCfg.line_height ?? 1.15);

            state.headerHtmlStore = normalizeLegacyHtmlForTiptap(pCfg.header_html || '<p></p>');
            state.footerHtmlStore = normalizeLegacyHtmlForTiptap(pCfg.footer_html || defaultParafHtml || '<p></p>');
            state.firstPageHeaderHtmlStore = normalizeLegacyHtmlForTiptap(pCfg.first_page_header_html || '<p></p>');
            state.firstPageFooterHtmlStore = normalizeLegacyHtmlForTiptap(pCfg.first_page_footer_html || defaultParafHtml || '<p></p>');
            loadHeaderFooterVariantIntoEditors();

            if (tplData.media_assets) {
                const cLogo = tplData.media_assets.company_logo || tplData.media_assets.company_logo_url || '/images/mou/seven-inc-logo.png';
                const iLogo = (state.documentType === 'mou')
                    ? (tplData.media_assets.institution_logo || tplData.media_assets.institution_logo_url || '/images/mou/institution-logo-placeholder.png')
                    : '';
                state.mediaAssets.company_logo = cLogo;
                state.mediaAssets.company_logo_url = cLogo;
                state.mediaAssets.institution_logo = iLogo;
                state.mediaAssets.institution_logo_url = iLogo;
            }

            // Populate Pihak Kedua form inputs if present
            if (tplData.company_defaults && typeof tplData.company_defaults === 'object') {
                Object.entries(tplData.company_defaults).forEach(([key, val]) => {
                    const inputEl = document.getElementById(key);
                    if (inputEl && val !== undefined && val !== null) {
                        inputEl.value = String(val);
                    }
                });
            }

            // Update Layout Ribbon inputs to match loaded pageConfig
            const setRibbonVal = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.value = String(val);
            };
            setRibbonVal('layoutMarginTop', state.marginTopMm);
            setRibbonVal('layoutMarginRight', state.marginRightMm);
            setRibbonVal('layoutMarginBottom', state.marginBottomMm);
            setRibbonVal('layoutMarginLeft', state.marginLeftMm);
            setRibbonVal('layoutPaperSize', state.paperSize);
            setRibbonVal('layoutOrientation', state.orientation);
            const chkDiff = document.getElementById('chkDifferentFirstPage');
            if (chkDiff) chkDiff.checked = state.differentFirstPage;
            const chkParaf = document.getElementById('chkShowParafBox');
            if (chkParaf) chkParaf.checked = state.showParafBox;
            const chkPageNum = document.getElementById('chkShowPageNumber');
            if (chkPageNum) chkPageNum.checked = state.showPageNumber;

            applyPageLayoutToCanvas();
            return true;
        },
        resetFooterParafTable() {
            setActiveZone('footer');
            if (footerEditor && defaultParafHtml) {
                footerEditor.commands.setContent(normalizeLegacyHtmlForTiptap(defaultParafHtml));
                saveActiveHeaderFooterToStore();
                scheduleSyncAndPagination();
            }
        },
        setHeaderFooterVariant(useFirstPage) {
            saveActiveHeaderFooterToStore();
            state.editingFirstPageVariant = Boolean(useFirstPage);
            loadHeaderFooterVariantIntoEditors();
            setActiveZone(state.activeZone === 'body' ? 'header' : state.activeZone);
        },
        setPaperSize(sizeVal) {
            const clean = String(sizeVal || 'A4').toUpperCase();
            if (['A4', 'LETTER', 'LEGAL'].includes(clean)) {
                state.paperSize = clean;
                const rulerTrack = document.getElementById('mouHorizontalRulerTrack');
                if (rulerTrack) {
                    rulerTrack.querySelectorAll('.mou-ruler-cm, .mou-ruler-half-cm').forEach(el => el.remove());
                }
                applyPageLayoutToCanvas();
            }
        },
        setOrientation(orientVal) {
            const clean = String(orientVal || 'portrait').toLowerCase();
            if (['portrait', 'landscape'].includes(clean)) {
                state.orientation = clean;
                const rulerTrack = document.getElementById('mouHorizontalRulerTrack');
                if (rulerTrack) {
                    rulerTrack.querySelectorAll('.mou-ruler-cm, .mou-ruler-half-cm').forEach(el => el.remove());
                }
                applyPageLayoutToCanvas();
            }
        },

        // Formatting commands on active editor with unified Asset & Document History
        undo: () => {
            const lastAssetTime = (assetHistoryIndex > 0 && assetHistory[assetHistoryIndex])
                ? assetHistory[assetHistoryIndex].timestamp
                : 0;
            const ed = getActiveEditor();
            const canEdUndo = ed && typeof ed.can === 'function' && ed.can().undo();

            if (lastAssetTime > 0 && (lastAssetTime >= lastEditorDocChangeTimestamp || !canEdUndo)) {
                if (undoAssetHistory()) return;
            }
            if (canEdUndo) {
                ed.chain().focus().undo().run();
                return;
            }
            if (lastAssetTime > 0) {
                undoAssetHistory();
            }
        },
        redo: () => {
            const nextAssetTime = (assetHistoryIndex < assetHistory.length - 1 && assetHistory[assetHistoryIndex + 1])
                ? assetHistory[assetHistoryIndex + 1].timestamp
                : 0;
            const ed = getActiveEditor();
            const canEdRedo = ed && typeof ed.can === 'function' && ed.can().redo();

            if (nextAssetTime > 0 && (nextAssetTime >= lastEditorDocChangeTimestamp || !canEdRedo)) {
                if (redoAssetHistory()) return;
            }
            if (canEdRedo) {
                ed.chain().focus().redo().run();
                return;
            }
            if (nextAssetTime > 0) {
                redoAssetHistory();
            }
        },
        clearFormatting: () => {
            const editor = getActiveEditor();
            if (!editor) return;

            const { state, view } = editor;
            const { selection } = state;
            let { from, to, empty, $from } = selection;

            // 1. If selection is collapsed (cursor blinking without highlight), expand to the current paragraph block
            if (empty && $from.parent && $from.parent.isTextblock) {
                if ($from.parent.nodeSize > 2) {
                    from = $from.start();
                    to = $from.end();
                }
            }

            if (from < to) {
                const tr = state.tr;

                // 2. Remove all registered marks in range (bold, italic, underline, strike, textStyle, highlight, sub/superscript, colors)
                Object.values(state.schema.marks).forEach((markType) => {
                    tr.removeMark(from, to, markType);
                });

                // 3. Clear stored marks on cursor
                tr.setStoredMarks([]);

                // 4. Reset headings to standard paragraph & reset paragraph attributes (alignment, indents, classes)
                state.doc.nodesBetween(from, to, (node, pos) => {
                    if (node.isTextblock) {
                        const defaultType = state.schema.nodes.paragraph || node.type;
                        tr.setNodeMarkup(pos, defaultType, {
                            ...defaultType.defaultAttrs,
                            textAlign: null,
                            marginLeft: null,
                            marginRight: null,
                            textIndent: null,
                            class: null,
                        });
                    }
                });

                view.dispatch(tr);
            } else {
                // Completely empty block: reset stored marks & reset block attributes
                const tr = state.tr;
                tr.setStoredMarks([]);
                if ($from.parent && $from.parent.isTextblock) {
                    const defaultType = state.schema.nodes.paragraph || $from.parent.type;
                    tr.setNodeMarkup($from.before(), defaultType, {
                        ...defaultType.defaultAttrs,
                        textAlign: null,
                        marginLeft: null,
                        marginRight: null,
                        textIndent: null,
                        class: null,
                    });
                }
                view.dispatch(tr);
            }

            // Also invoke TipTap chain unsetAllMarks().clearNodes() for safety
            try {
                editor.chain().focus().unsetAllMarks().clearNodes().run();
            } catch (_) {}

            updateToolbarState();
            scheduleSyncAndPagination();
        },
        toggleBold: () => getActiveEditor()?.chain().focus().toggleBold().run(),
        toggleItalic: () => getActiveEditor()?.chain().focus().toggleItalic().run(),
        toggleUnderline: () => getActiveEditor()?.chain().focus().toggleUnderline().run(),
        toggleStrike: () => getActiveEditor()?.chain().focus().toggleStrike().run(),
        toggleSubscript: () => getActiveEditor()?.chain().focus().toggleSubscript().run(),
        toggleSuperscript: () => getActiveEditor()?.chain().focus().toggleSuperscript().run(),
        toggleUppercaseSelection: () => {
            const ed = getActiveEditor();
            if (!ed) return;
            const { from, to, empty } = ed.state.selection;
            if (empty) return;
            const selectedText = ed.state.doc.textBetween(from, to, ' ');
            if (!selectedText) return;
            const isAlreadyUpper = selectedText === selectedText.toUpperCase() && selectedText !== selectedText.toLowerCase();
            const targetCase = isAlreadyUpper ? 'lower' : 'upper';

            const tr = ed.state.tr;
            ed.state.doc.nodesBetween(from, to, (node, pos) => {
                if (!node.isText || !node.text) return;
                const start = Math.max(from, pos);
                const end = Math.min(to, pos + node.nodeSize);
                if (start >= end) return;
                const slice = node.text.slice(start - pos, end - pos);
                const replaced = targetCase === 'upper' ? slice.toUpperCase() : slice.toLowerCase();
                tr.insertText(replaced, start, end);
            });
            ed.view.dispatch(tr);
            ed.commands.focus();
            scheduleSyncAndPagination();
        },
        setFontFamily: (font) => getActiveEditor()?.chain().focus().setFontFamily(font).run(),
        setFontSize: (sizePt) => getActiveEditor()?.chain().focus().setFontSize(sizePt).run(),
        setTextColor: (color) => {
            const ed = getActiveEditor();
            if (!ed) return;
            const { state } = ed;
            const { selection } = state;
            let { from, to, empty, $from } = selection;

            if (empty && $from.parent && $from.parent.isTextblock) {
                const text = $from.parent.textBetween(0, $from.parent.content.size, undefined, '\ufffc');
                const offset = $from.parentOffset;
                let start = offset;
                let end = offset;
                while (start > 0 && /\S/.test(text[start - 1])) start--;
                while (end < text.length && /\S/.test(text[end])) end++;
                if (start < end) {
                    from = $from.start() + start;
                    to = $from.start() + end;
                    ed.chain().focus().setTextSelection({ from, to }).setColor(color).run();
                    updateToolbarState();
                    scheduleSyncAndPagination();
                    return;
                }
            }

            ed.chain().focus().setColor(color).run();
            updateToolbarState();
            scheduleSyncAndPagination();
        },
        removeHighlight: () => {
            const ed = getActiveEditor();
            if (!ed) return;
            const { state, view } = ed;
            const { selection } = state;
            let { from, to, empty, $from } = selection;

            if (empty && $from.parent && $from.parent.isTextblock) {
                const highlightMarkType = state.schema.marks.highlight;
                if (highlightMarkType) {
                    const marks = $from.marks();
                    const hasHighlight = marks.some(m => m.type === highlightMarkType);

                    if (hasHighlight) {
                        const parentStart = $from.start();
                        const parentEnd = $from.end();
                        let start = $from.pos;
                        let end = $from.pos;

                        while (start > parentStart) {
                            const $pos = state.doc.resolve(start - 1);
                            if ($pos.marks().some(m => m.type === highlightMarkType)) {
                                start--;
                            } else {
                                break;
                            }
                        }
                        while (end < parentEnd) {
                            const $pos = state.doc.resolve(end + 1);
                            if ($pos.marks().some(m => m.type === highlightMarkType)) {
                                end++;
                            } else {
                                break;
                            }
                        }

                        if (start < end) {
                            const tr = state.tr;
                            tr.removeMark(start, end, highlightMarkType);
                            tr.removeStoredMark(highlightMarkType);
                            view.dispatch(tr);
                            ed.commands.focus();
                            updateToolbarState();
                            scheduleSyncAndPagination();
                            return;
                        }
                    } else {
                        const text = $from.parent.textBetween(0, $from.parent.content.size, undefined, '\ufffc');
                        const offset = $from.parentOffset;
                        let start = offset;
                        let end = offset;
                        while (start > 0 && /\S/.test(text[start - 1])) start--;
                        while (end < text.length && /\S/.test(text[end])) end++;
                        if (start < end) {
                            const wordFrom = $from.start() + start;
                            const wordTo = $from.start() + end;
                            const tr = state.tr;
                            tr.removeMark(wordFrom, wordTo, highlightMarkType);
                            tr.removeStoredMark(highlightMarkType);
                            view.dispatch(tr);
                            ed.commands.focus();
                            updateToolbarState();
                            scheduleSyncAndPagination();
                            return;
                        }
                    }
                }
            }

            ed.chain().focus().unsetHighlight().run();
            updateToolbarState();
            scheduleSyncAndPagination();
        },
        setHighlightColor: (color) => {
            const ed = getActiveEditor();
            if (!ed) return;
            if (!color || color === 'transparent') {
                api.removeHighlight();
                return;
            }
            const { state } = ed;
            const { selection } = state;
            let { from, to, empty, $from } = selection;

            if (empty && $from.parent && $from.parent.isTextblock) {
                const text = $from.parent.textBetween(0, $from.parent.content.size, undefined, '\ufffc');
                const offset = $from.parentOffset;
                let start = offset;
                let end = offset;
                while (start > 0 && /\S/.test(text[start - 1])) start--;
                while (end < text.length && /\S/.test(text[end])) end++;
                if (start < end) {
                    from = $from.start() + start;
                    to = $from.start() + end;
                    ed.chain().focus().setTextSelection({ from, to }).setHighlight({ color }).run();
                    updateToolbarState();
                    scheduleSyncAndPagination();
                    return;
                }
            }

            ed.chain().focus().setHighlight({ color }).run();
            updateToolbarState();
            scheduleSyncAndPagination();
        },
        setTextAlign: (align) => {
            const ed = getActiveEditor();
            if (!ed) return;
            ed.chain().focus().setTextAlign(align).run();
            updateToolbarState();
            scheduleSyncAndPagination();
            scheduleReflow();
        },
        setLineHeight: (lh) => {
            const ed = getActiveEditor();
            if (!ed) return;
            const { state: editorState, view } = ed;
            const { selection } = editorState;
            const { from, to, empty, $from } = selection;
            const tr = editorState.tr;
            let count = 0;
            const targetLh = (lh !== null && lh !== undefined && lh !== '') ? String(lh) : null;

            if (empty) {
                let depth = $from.depth;
                while (depth > 0 && !$from.node(depth).isTextblock) {
                    depth--;
                }
                if (depth > 0 && $from.node(depth).isTextblock) {
                    const pos = $from.before(depth);
                    const node = $from.node(depth);
                    tr.setNodeMarkup(pos, undefined, { ...node.attrs, lineHeight: targetLh });
                    count++;
                }
            } else {
                editorState.doc.nodesBetween(from, to, (node, pos) => {
                    // Only modify textblocks that strictly overlap the selected range:
                    if (node.isTextblock && pos < to && (pos + node.nodeSize) > from) {
                        count++;
                        tr.setNodeMarkup(pos, undefined, { ...node.attrs, lineHeight: targetLh });
                        return false;
                    }
                });
            }

            if (count > 0) {
                tr.setSelection(selection.map(tr.doc, tr.mapping));
                view.dispatch(tr);
                view.focus();
                updateToolbarState();
                scheduleSyncAndPagination();
                scheduleReflow();
            } else {
                ed.chain().focus()
                    .updateAttributes('paragraph', { lineHeight: targetLh })
                    .run();
                updateToolbarState();
                scheduleSyncAndPagination();
                scheduleReflow();
            }
        },
        setBlockStyle: (styleVal) => {
            const ed = getActiveEditor();
            if (!ed) return;
            if (styleVal === 'p') {
                ed.chain().focus().setParagraph().run();
            } else if (styleVal.startsWith('h')) {
                const level = parseInt(styleVal.replace('h', ''), 10) || 1;
                ed.chain().focus().toggleHeading({ level }).run();
            }
        },
        toggleBulletList: () => {
            const ed = getActiveEditor();
            if (!ed) return;
            ed.chain().focus().toggleBulletList().run();
            updateToolbarState();
        },
        setOrderedListType: (targetType = '1') => {
            const ed = getActiveEditor();
            if (!ed) return;
            const { state: editorState, view } = ed;
            const { selection } = editorState;
            const { $from, from, to } = selection;

            // 1. If currently inside an orderedList:
            if (ed.isActive('orderedList')) {
                const tr = editorState.tr;
                let found = false;

                // Find innermost orderedList containing $from
                let innerOlDepth = -1;
                let innerOlPos = -1;
                let innerOlNode = null;
                for (let d = $from.depth; d > 0; d--) {
                    const n = $from.node(d);
                    if (n.type.name === 'orderedList') {
                        innerOlDepth = d;
                        innerOlPos = $from.before(d);
                        innerOlNode = n;
                        break;
                    }
                }

                if (innerOlNode && innerOlPos !== -1) {
                    // Update innermost list's type directly without splitting
                    tr.setNodeMarkup(innerOlPos, undefined, {
                        ...innerOlNode.attrs,
                        listType: targetType,
                        type: targetType,
                    });
                    found = true;

                    // Also update any other ordered lists strictly within selection
                    editorState.doc.nodesBetween(from, to, (node, pos) => {
                        if (node.type.name === 'orderedList' && pos >= from && pos < to && pos !== innerOlPos) {
                            tr.setNodeMarkup(pos, undefined, {
                                ...node.attrs,
                                listType: targetType,
                                type: targetType,
                            });
                            found = true;
                        }
                    });

                    // Auto-join adjacent orderedList nodes having the same listType so consecutive items form one list
                    try {
                        let joinPos = -1;
                        let maxJoins = 10;
                        do {
                            joinPos = -1;
                            tr.doc.descendants((node, pos) => {
                                if (node.type.name === 'orderedList') {
                                    const afterPos = pos + node.nodeSize;
                                    if (afterPos < tr.doc.content.size) {
                                        const nextNode = tr.doc.nodeAt(afterPos);
                                        if (nextNode && nextNode.type.name === 'orderedList') {
                                            const t1 = node.attrs.listType || node.attrs.type || '1';
                                            const t2 = nextNode.attrs.listType || nextNode.attrs.type || '1';
                                            if (t1 === t2) {
                                                joinPos = afterPos;
                                                return false;
                                            }
                                        }
                                    }
                                }
                            });
                            if (joinPos !== -1) {
                                tr.join(joinPos);
                            }
                            maxJoins--;
                        } while (joinPos !== -1 && maxJoins > 0);
                    } catch (_) {}
                }

                if (found) {
                    tr.setSelection(selection.map(tr.doc, tr.mapping));
                    view.dispatch(tr);
                    view.focus();
                    updateToolbarState();
                    scheduleSyncAndPagination();
                    scheduleReflow();
                    return;
                }
            }

            // 2. If not yet in orderedList (e.g. unnumbered paragraphs or bulletList):
            if (ed.isActive('bulletList')) {
                ed.chain().focus().toggleBulletList().run();
            }

            // Wrap selection in orderedList with target attributes
            try {
                ed.chain().focus().toggleList('orderedList', 'listItem', false, {
                    listType: targetType,
                    type: targetType,
                }).run();
            } catch (_) {
                ed.chain().focus().toggleOrderedList().run();
            }

            // Set listType and type on newly created orderedList
            try {
                const newEdState = ed.state;
                const newSel = newEdState.selection;
                const cur$from = newSel.$from;
                const tr = newEdState.tr;
                let applied = false;
                for (let d = cur$from.depth; d > 0; d--) {
                    const n = cur$from.node(d);
                    if (n.type.name === 'orderedList') {
                        tr.setNodeMarkup(cur$from.before(d), undefined, {
                            ...n.attrs,
                            listType: targetType,
                            type: targetType,
                        });
                        applied = true;
                        break;
                    }
                }

                // Auto-join adjacent orderedList nodes having the same listType so consecutive items form one list
                let joinPos = -1;
                let maxJoins = 10;
                do {
                    joinPos = -1;
                    tr.doc.descendants((node, pos) => {
                        if (node.type.name === 'orderedList') {
                            const afterPos = pos + node.nodeSize;
                            if (afterPos < tr.doc.content.size) {
                                const nextNode = tr.doc.nodeAt(afterPos);
                                if (nextNode && nextNode.type.name === 'orderedList') {
                                    const t1 = node.attrs.listType || node.attrs.type || '1';
                                    const t2 = nextNode.attrs.listType || nextNode.attrs.type || '1';
                                    if (t1 === t2) {
                                        joinPos = afterPos;
                                        return false;
                                    }
                                }
                            }
                        }
                    });
                    if (joinPos !== -1) {
                        try {
                            tr.join(joinPos);
                            applied = true;
                        } catch (_) {
                            break;
                        }
                    }
                    maxJoins--;
                } while (joinPos !== -1 && maxJoins > 0);

                if (applied) {
                    tr.setSelection(newSel.map(tr.doc, tr.mapping));
                    ed.view.dispatch(tr);
                }
            } catch (_) {}

            view.focus();
            updateToolbarState();
            scheduleSyncAndPagination();
            scheduleReflow();
        },
        toggleOrderedList: (listType = null) => {
            if (listType) {
                api.setOrderedListType(listType);
                return;
            }
            const ed = getActiveEditor();
            if (!ed) return;
            if (ed.isActive('orderedList')) {
                ed.chain().focus().toggleOrderedList().run();
                updateToolbarState();
                scheduleSyncAndPagination();
            } else {
                api.setOrderedListType('1');
            }
        },
        adjustIndent: (deltaMm) => {
            const ed = getActiveEditor();
            if (!ed) return;
            executeEditorIndent(ed, deltaMm);
            ed.commands.focus();
            updateToolbarState();
            updateRulerMarkersFromEditor();
            scheduleSyncAndPagination();
            scheduleReflow(true);
        },
        setFirstLineIndent: (mmVal) => {
            const ed = getActiveEditor();
            if (!ed) return;
            const mm = parseFloat(mmVal);
            ed.chain().focus().updateAttributes('paragraph', {
                textIndent: !isNaN(mm) && mm !== 0 ? `${mm}mm` : null,
            }).run();
        },
        setParagraphSpacing: (beforePt, afterPt) => {
            const ed = getActiveEditor();
            if (!ed) return;
            const updates = {};
            if (beforePt !== undefined && beforePt !== null && beforePt !== '') {
                updates.marginTop = `${parseFloat(beforePt) || 0}pt`;
            }
            if (afterPt !== undefined && afterPt !== null && afterPt !== '') {
                updates.marginBottom = `${parseFloat(afterPt) || 0}pt`;
            }
            ed.chain().focus()
                .updateAttributes('paragraph', updates)
                .updateAttributes('heading', updates)
                .run();
        },

        // Insertions
        insertTable: (rows = 3, cols = 3, withHeaderRow = false) => {
            const ed = getActiveEditor();
            if (!ed) return;
            const { state: edState } = ed;
            const { $from } = edState.selection;

            // If cursor is currently inside a table, move cursor after that table first
            for (let d = $from.depth; d >= 0; d--) {
                if ($from.node(d).type.name === 'table') {
                    const afterPos = $from.after(d);
                    ed.commands.setTextSelection(afterPos);
                    break;
                }
            }

            // Ensure there is a paragraph before the table if directly following another table
            const curPos = ed.state.selection.$from;
            const nodeBefore = curPos.nodeBefore;
            if (nodeBefore && nodeBefore.type.name === 'table') {
                ed.commands.insertContent('<p></p>');
            }

            ed.chain().focus().insertTable({
                rows: Math.max(1, parseInt(rows, 10) || 3),
                cols: Math.max(1, parseInt(cols, 10) || 3),
                withHeaderRow: Boolean(withHeaderRow),
            }).run();

            // Insert paragraph after table if it is adjacent to another table or at document end
            const afterInsertPos = ed.state.selection.$from;
            for (let d = afterInsertPos.depth; d >= 0; d--) {
                if (afterInsertPos.node(d).type.name === 'table') {
                    const endPos = afterInsertPos.after(d);
                    const docEnd = ed.state.doc.content.size;
                    if (endPos >= docEnd || (ed.state.doc.resolve(endPos).nodeAfter && ed.state.doc.resolve(endPos).nodeAfter.type.name === 'table')) {
                        const tr = ed.state.tr.insert(endPos, ed.schema.nodes.paragraph.create());
                        ed.view.dispatch(tr);
                    }
                    break;
                }
            }
            scheduleSyncAndPagination();
        },
        insertPageBreak: () => bodyEditor.chain().focus().insertPageBreak().run(),
        insertHorizontalRule: () => getActiveEditor()?.chain().focus().setHorizontalRule().run(),
        insertTextBox: () => {
            const ed = getActiveEditor();
            if (!ed) return;
            ed.chain().focus().insertContent({
                type: 'textBox',
                attrs: { width: '100%', align: 'left', borderStyle: 'solid', backgroundColor: '#ffffff' },
                content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Ketik isi kotak teks di sini...' }] }],
            }).run();
        },
        insertPlaceholder: (token) => {
            const ed = getActiveEditor();
            if (!ed || !token) return;
            ed.chain().focus().insertContent(token).run();
            scheduleSyncAndPagination();
        },
        insertSymbol: (sym) => {
            const ed = getActiveEditor();
            if (!ed || !sym) return;
            ed.chain().focus().insertContent(sym).run();
            scheduleSyncAndPagination();
        },
        insertLink: (url, text) => {
            const ed = getActiveEditor();
            if (!ed || !url) return;
            if (text) {
                ed.chain().focus().insertContent(`<a href="${url}">${text}</a>`).run();
            } else {
                ed.chain().focus().setLink({ href: url }).run();
            }
            scheduleSyncAndPagination();
        },
        insertImageFromUrl: (src, widthPx = 110, align = 'center') => {
            const ed = getActiveEditor();
            if (!ed || !src) return;
            ed.chain().focus().setImage({
                src,
                width: `${widthPx}px`,
                align,
            }).run();
            scheduleSyncAndPagination();
        },
        updateSelectedImage: (widthVal, alignVal) => {
            const ed = getActiveEditor();
            if (!ed) return;
            const updates = {};
            if (widthVal) updates.width = /^\d+$/.test(String(widthVal)) ? `${widthVal}px` : widthVal;
            if (alignVal) updates.align = alignVal;

            const { state, view } = ed;
            if (state.selection.node && state.selection.node.type.name === 'image') {
                ed.chain().focus().updateAttributes('image', updates).run();
            } else {
                let imgPos = null;
                const selectedWrap = document.querySelector('.mou-image-wrapper.is-selected');
                if (selectedWrap && view) {
                    const imgEl = selectedWrap.querySelector('img');
                    if (imgEl) {
                        try {
                            const p = view.posAtDOM(imgEl, 0);
                            if (p >= 0) imgPos = p;
                        } catch (_) {}
                    }
                }
                if (imgPos === null) {
                    state.doc.descendants((node, pos) => {
                        if (node.type.name === 'image' && imgPos === null) {
                            imgPos = pos;
                            return false;
                        }
                    });
                }
                if (imgPos !== null) {
                    try {
                        const nodeSel = NodeSelection.create(state.doc, imgPos);
                        const tr = state.tr.setSelection(nodeSel);
                        const node = state.doc.nodeAt(imgPos);
                        if (node) {
                            tr.setNodeMarkup(imgPos, undefined, { ...node.attrs, ...updates });
                        }
                        view.dispatch(tr);
                    } catch (_) {
                        ed.chain().focus().updateAttributes('image', updates).run();
                    }
                } else {
                    ed.chain().focus().updateAttributes('image', updates).run();
                }
            }
            scheduleSyncAndPagination();
        },
        insertSystemAsset: (asset) => {
            const ed = getActiveEditor();
            if (!ed || !asset) return;
            const src = asset.preview_url || `/admin/system-assets/${asset.id}/preview`;
            let width = '110px';
            if (asset.category === 'letterhead') {
                width = '100%';
            } else if (asset.category === 'signature') {
                width = '120px';
            } else if (asset.category === 'stamp') {
                width = '125px';
            } else if (asset.category === 'logo') {
                width = '110px';
            }

            ed.chain().focus().setImage({
                src,
                alt: asset.name || 'Aset Sistem',
                title: asset.name || '',
                assetId: String(asset.id),
                assetCode: asset.code || '',
                token: asset.code ? `asset.${asset.code}` : '',
                width,
                align: 'center',
            }).run();
            scheduleSyncAndPagination();
        },
        toggleHeaderLogos(enable) {
            state.showHeaderLogos = enable !== undefined ? Boolean(enable) : !state.showHeaderLogos;
            applyPageLayoutToCanvas();
            syncAllToHiddenInputs();
            return state.showHeaderLogos;
        },
        toggleWatermark(enable) {
            state.showWatermark = enable !== undefined ? Boolean(enable) : !state.showWatermark;
            applyPageLayoutToCanvas();
            syncAllToHiddenInputs();
            return state.showWatermark;
        },
        updateSelectedImage(w, h, rot) {
            const asset = window._mouActiveSelectedAsset;
            if (asset?.type === 'header-logo') {
                if (w) asset.cfg.width = parseInt(String(w).replace(/[^0-9]/g, ''), 10) || asset.cfg.width;
                if (h) asset.cfg.height = parseInt(String(h).replace(/[^0-9]/g, ''), 10) || asset.cfg.height;
                if (rot !== null && rot !== undefined) asset.cfg.rotation = parseInt(rot, 10) || 0;
                asset.updateFn?.();
                syncAllToHiddenInputs();
                pushAssetHistory('Ubah Ukuran Logo Ribbon');
                return;
            }
            if (asset?.type === 'body-image') {
                const nv = asset.nodeView;
                if (nv && typeof nv.getPos === 'function' && nv.view?.state?.tr) {
                    const pos = nv.getPos();
                    if (pos !== undefined) {
                        const attrs = { ...nv.node.attrs };
                        if (w) attrs.width = /^\d+$/.test(String(w)) ? `${w}px` : w;
                        if (h) attrs.height = /^\d+$/.test(String(h)) ? `${h}px` : h;
                        if (rot !== null && rot !== undefined) attrs.rotation = parseInt(rot, 10) || 0;
                        const tr = nv.view.state.tr.setNodeMarkup(pos, undefined, attrs);
                        nv.view.dispatch(tr);
                        scheduleSyncAndPagination();
                    }
                }
            }
        },
        rotateSelectedImage(deltaDeg = 90) {
            const asset = window._mouActiveSelectedAsset;
            if (asset?.type === 'header-logo') {
                asset.cfg.rotation = ((asset.cfg.rotation || 0) + deltaDeg) % 360;
                asset.updateFn?.();
                const rotInput = document.getElementById('ribbonImageRotationDeg');
                if (rotInput) rotInput.value = String(asset.cfg.rotation);
                syncAllToHiddenInputs();
                pushAssetHistory(`Putar Logo ${deltaDeg}°`);
                return;
            }
            if (asset?.type === 'body-image') {
                const nv = asset.nodeView;
                if (nv && typeof nv.getPos === 'function' && nv.view?.state?.tr) {
                    const pos = nv.getPos();
                    if (pos !== undefined) {
                        const currentRot = parseInt(nv.node.attrs?.rotation || 0, 10);
                        const newRot = (currentRot + deltaDeg) % 360;
                        const tr = nv.view.state.tr.setNodeMarkup(pos, undefined, {
                            ...nv.node.attrs,
                            rotation: newRot,
                        });
                        nv.view.dispatch(tr);
                        const rotInput = document.getElementById('ribbonImageRotationDeg');
                        if (rotInput) rotInput.value = String(newRot);
                        scheduleSyncAndPagination();
                    }
                }
            }
        },
        setSelectedImageRotation(deg = 0) {
            const norm = ((parseInt(deg || 0, 10) % 360) + 360) % 360;
            const asset = window._mouActiveSelectedAsset;
            if (asset?.type === 'header-logo') {
                asset.cfg.rotation = norm;
                asset.updateFn?.();
                syncAllToHiddenInputs();
                pushAssetHistory(`Set Rotasi Logo ${norm}°`);
                return;
            }
            if (asset?.type === 'body-image') {
                const nv = asset.nodeView;
                if (nv && typeof nv.getPos === 'function' && nv.view?.state?.tr) {
                    const pos = nv.getPos();
                    if (pos !== undefined) {
                        const tr = nv.view.state.tr.setNodeMarkup(pos, undefined, {
                            ...nv.node.attrs,
                            rotation: norm,
                        });
                        nv.view.dispatch(tr);
                        scheduleSyncAndPagination();
                    }
                }
            }
        },
        resetSelectedImage() {
            const asset = window._mouActiveSelectedAsset;
            if (asset?.type === 'header-logo') {
                asset.cfg.width = 66;
                asset.cfg.height = 66;
                asset.cfg.rotation = 0;
                asset.cfg.topOffsetMm = 0;
                if (asset.slot === 'institution') asset.cfg.leftOffsetMm = 0;
                else asset.cfg.rightOffsetMm = 0;
                asset.updateFn?.();
                const rotInput = document.getElementById('ribbonImageRotationDeg');
                if (rotInput) rotInput.value = '0';
                const wInput = document.getElementById('ribbonImageWidthPx');
                if (wInput) wInput.value = '66';
                const hInput = document.getElementById('ribbonImageHeightPx');
                if (hInput) hInput.value = '66';
                syncAllToHiddenInputs();
                pushAssetHistory('Reset Logo Default');
                return;
            }
            if (asset?.type === 'body-image') {
                const nv = asset.nodeView;
                if (nv && typeof nv.getPos === 'function' && nv.view?.state?.tr) {
                    const pos = nv.getPos();
                    if (pos !== undefined) {
                        const tr = nv.view.state.tr.setNodeMarkup(pos, undefined, {
                            ...nv.node.attrs,
                            width: '120px',
                            height: 'auto',
                            rotation: 0,
                        });
                        nv.view.dispatch(tr);
                        const rotInput = document.getElementById('ribbonImageRotationDeg');
                        if (rotInput) rotInput.value = '0';
                        const wInput = document.getElementById('ribbonImageWidthPx');
                        if (wInput) wInput.value = '120';
                        const hInput = document.getElementById('ribbonImageHeightPx');
                        if (hInput) hInput.value = '';
                        scheduleSyncAndPagination();
                    }
                }
            }
        },
        deleteSelectedAsset() {
            const asset = window._mouActiveSelectedAsset;
            if (asset?.type === 'header-logo') {
                if (confirm('Sembunyikan logo header dari naskah? Anda dapat mengaktifkannya kembali lewat tombol Logo Header.')) {
                    state.showHeaderLogos = false;
                    applyPageLayoutToCanvas();
                    syncAllToHiddenInputs();
                    const btn = document.getElementById('btnToggleHeaderLogos');
                    if (btn) btn.classList.remove('mou-ribbon-btn-active');
                    window._mouActiveSelectedAsset = null;
                }
                return true;
            }
            if (asset?.type === 'body-image') {
                return asset.nodeView?.deleteNode() || false;
            }
            return false;
        },
        saveDocument() {
            syncAllToHiddenInputs();
            const form = document.getElementById('mouTemplateForm') || document.getElementById('documentTemplateForm') || document.querySelector('form');
            if (form) {
                let toast = document.getElementById('mouSaveToast');
                if (!toast) {
                    toast = document.createElement('div');
                    toast.id = 'mouSaveToast';
                    toast.className = 'fixed bottom-6 right-6 z-[9999] flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-3 text-xs font-semibold text-white shadow-xl';
                    document.body.appendChild(toast);
                }
                toast.innerHTML = `
                    <svg class="h-4 w-4 animate-spin text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" stroke-opacity="0.25"/>
                        <path d="M12 2a10 10 0 0 1 10 10" />
                    </svg>
                    <span>Menyimpan template dokumen (Ctrl+S)...</span>
                `;
                toast.style.display = 'flex';
                setTimeout(() => {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }, 120);
            }
        },
        async uploadImageFile(file, slot = 'custom_image') {
            if (!file || !uploadMediaUrl) return null;
            const formData = new FormData();
            formData.append('image', file);
            formData.append('slot', slot);

            const resp = await fetch(uploadMediaUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: formData,
            });
            const data = await resp.json();
            if (resp.ok && data.url) {
                if (slot === 'institution_logo') {
                    state.mediaAssets.institution_logo = data.url;
                    state.mediaAssets.institution_logo_url = data.url;
                    state.showHeaderLogos = true;
                    applyPageLayoutToCanvas();
                    syncAllToHiddenInputs();
                    return data.url;
                }
                if (slot === 'company_logo') {
                    state.mediaAssets.company_logo = data.url;
                    state.mediaAssets.company_logo_url = data.url;
                    state.showHeaderLogos = true;
                    applyPageLayoutToCanvas();
                    syncAllToHiddenInputs();
                    return data.url;
                }
                api.insertImageFromUrl(data.url, 100, 'center');
                syncAllToHiddenInputs();
                return data.url;
            }
            throw new Error(data.message || 'Gagal mengunggah gambar.');
        },

        // Table Structure & Styling API
        addRowBefore: () => getActiveEditor()?.chain().focus().addRowBefore().run(),
        addRowAfter: () => getActiveEditor()?.chain().focus().addRowAfter().run(),
        deleteRow: () => getActiveEditor()?.chain().focus().deleteRow().run(),
        addColumnBefore: () => getActiveEditor()?.chain().focus().addColumnBefore().run(),
        addColumnAfter: () => getActiveEditor()?.chain().focus().addColumnAfter().run(),
        deleteColumn: () => getActiveEditor()?.chain().focus().deleteColumn().run(),
        mergeCells: () => getActiveEditor()?.chain().focus().mergeCells().run(),
        splitCell: () => getActiveEditor()?.chain().focus().splitCell().run(),
        deleteTable: deleteCurrentTable,
        insertTab: () => {
            const ed = getActiveEditor();
            if (ed) {
                ed.chain().focus().insertContent('\t').run();
            }
        },
        setTabSize: (size) => {
            state.tabSize = size;
            document.querySelectorAll('.ProseMirror, .mou-doc-canvas').forEach(el => {
                el.style.tabSize = size;
                el.style.setProperty('-moz-tab-size', size);
            });
        },
        setCurrentRowHeight,
        setCurrentTableWidth,
        setCurrentTableAlign,
        setCurrentColumnWidth,
        adjustRowHeightSpin,
        adjustColWidthSpin,
        adjustTableWidthSpin,
        adjustFontSizeSpin,
        setZoomPercent,
        distributeColumnsEvenly,
        fitTableToMargins,
        async importDocx(fileOrBuffer, mode = 'replace') {
            if (state._isImportingDocx) {
                console.warn('importDocx is already running, skipping duplicate invocation.');
                return null;
            }
            state._isImportingDocx = true;
            try {
                const result = await importDocxFile(fileOrBuffer);
                if (mode === 'replace') {
                    bodyEditor.commands.setContent(normalizeHtmlForBodyEditor(result.html));
                    if (state.documentType === 'mou') {
                        state.showHeaderLogos = true;
                    }
                    if (!state.footerHtmlStore && defaultParafHtml) {
                        state.footerHtmlStore = defaultParafHtml;
                    }
                    reflowPassCount = 0;
                    oscillationCounter = 0;
                    lastSplitAction = null;
                    scheduleReflow(true);
                    scheduleSyncAndPagination();
                    refreshPageSheets();
                } else {
                    bodyEditor.chain().focus().insertContent(result.html).run();
                    reflowPassCount = 0;
                    oscillationCounter = 0;
                    lastSplitAction = null;
                    scheduleReflow(true);
                    scheduleSyncAndPagination();
                    refreshPageSheets();
                }
                return result;
            } finally {
                setTimeout(() => {
                    state._isImportingDocx = false;
                }, 1000);
            }
        },
        async exportDocx(filename = 'template.docx') {
            if (state._isExportingDocx) {
                console.warn('exportDocx is already in progress, skipping duplicate call.');
                return null;
            }
            state._isExportingDocx = true;
            try {
                const pageConfig = {
                    paper_size: state.paperSize,
                    orientation: state.orientation,
                    margin_top_mm: state.marginTopMm,
                    margin_bottom_mm: state.marginBottomMm,
                    margin_left_mm: state.marginLeftMm,
                    margin_right_mm: state.marginRightMm,
                    header_distance_mm: state.headerDistanceMm,
                    footer_distance_mm: state.footerDistanceMm,
                    show_header_logos: state.showHeaderLogos,
                    show_paraf_box: state.showParafBox,
                    show_page_number: state.showPageNumber,
                    document_type: state.documentType,
                    media_assets: state.mediaAssets,
                    header_logos_config: state.headerLogosConfig,
                    header_json: headerEditor ? headerEditor.getJSON() : null,
                    footer_json: footerEditor ? footerEditor.getJSON() : null,
                };
                const docxBlob = await exportProseMirrorJsonToDocx(bodyEditor.getJSON(), pageConfig);
                if (typeof window !== 'undefined' && window.document) {
                    downloadDocxBlob(docxBlob, filename);
                }
                return docxBlob;
            } finally {
                setTimeout(() => {
                    state._isExportingDocx = false;
                }, 1200);
            }
        },
        updateActiveTableCells,
        setTableBorders(borderType, options = {}) {
            if (borderType === 'gridlines') {
                this.toggleTableGridlines();
                return;
            }

            const editor = getActiveEditor();
            if (!editor) return;
            const { state: edState } = editor;
            const { selection } = edState;
            const { $from } = selection;

            let targetTable = null;
            let tablePos = null;
            let tableDepth = -1;
            for (let d = $from.depth; d >= 0; d--) {
                const n = $from.node(d);
                if (n.type.name === 'table') {
                    targetTable = n;
                    tablePos = $from.before(d);
                    tableDepth = d;
                    break;
                }
            }
            if (!targetTable || tablePos === null) return;

            const bWidth = options.borderWidth || document.getElementById('ribbonTableBorderWidth')?.value || '1px';
            const bColor = options.borderColor || document.getElementById('ribbonTableBorderColor')?.value || '#000000';
            const solidBorder = `${bWidth} solid ${bColor}`;

            const totalRows = targetTable.childCount;
            const tr = edState.tr;

            // Handle MoU Paraf Box Preset
            if (borderType === 'paraf') {
                tr.setNodeMarkup(tablePos, undefined, {
                    ...targetTable.attrs,
                    class: 'mou-paraf-table',
                    tableWidth: '220pt',
                    tableStyle: 'paraf',
                    'data-table-style': 'paraf',
                });
                let rOffset = tablePos + 1;
                for (let r = 0; r < totalRows; r++) {
                    const row = targetTable.child(r);
                    let cOffset = rOffset + 1;
                    for (let c = 0; c < row.childCount; c++) {
                        const cell = row.child(c);
                        tr.setNodeMarkup(cOffset, undefined, {
                            ...cell.attrs,
                            borderStyle: 'solid',
                            borderWidth: '1px',
                            borderColor: '#000000',
                            rawBorder: '1px solid #000000',
                            padding: '2pt 4pt',
                        });
                        cOffset += cell.nodeSize;
                    }
                    rOffset += row.nodeSize;
                }
                editor.view.dispatch(tr);
                scheduleSyncAndPagination();
                return;
            }

            // Check for CellSelection (multi-cell range)
            let hasMultiCellSelection = false;
            let minRow = 9999, maxRow = -1, minCol = 9999, maxCol = -1;
            const cellMap = new Map();
            const selectedPositions = [];

            let rOffsetMap = tablePos + 1;
            for (let r = 0; r < totalRows; r++) {
                const row = targetTable.child(r);
                let cOffsetMap = rOffsetMap + 1;
                for (let c = 0; c < row.childCount; c++) {
                    cellMap.set(cOffsetMap, { r, c });
                    cOffsetMap += row.child(c).nodeSize;
                }
                rOffsetMap += row.nodeSize;
            }

            if (selection.forEachCell) {
                selection.forEachCell((node, pos) => {
                    selectedPositions.push(pos);
                    const info = cellMap.get(pos);
                    if (info) {
                        if (info.r < minRow) minRow = info.r;
                        if (info.r > maxRow) maxRow = info.r;
                        if (info.c < minCol) minCol = info.c;
                        if (info.c > maxCol) maxCol = info.c;
                    }
                });
                if (selectedPositions.length > 1) {
                    hasMultiCellSelection = true;
                }
            }

            // Multi-cell selection branch: applies specifically to selected cell range
            if (hasMultiCellSelection) {
                selectedPositions.forEach(pos => {
                    const info = cellMap.get(pos);
                    const cellNode = edState.doc.nodeAt(pos);
                    if (!cellNode) return;
                    const prevRaw = cellNode.attrs.rawBorder || '';

                    if (borderType === 'none' || borderType === 'borderless') {
                        tr.setNodeMarkup(pos, undefined, {
                            ...cellNode.attrs,
                            borderStyle: 'none',
                            borderWidth: '0px',
                            borderColor: 'transparent',
                            rawBorder: 'none',
                        });
                    } else if (borderType === 'all') {
                        tr.setNodeMarkup(pos, undefined, {
                            ...cellNode.attrs,
                            borderStyle: 'solid',
                            borderWidth: bWidth,
                            borderColor: bColor,
                            rawBorder: solidBorder,
                        });
                    } else if (borderType === 'top') {
                        if (info && info.r === minRow) {
                            tr.setNodeMarkup(pos, undefined, {
                                ...cellNode.attrs,
                                rawBorder: updateBorderProperties(prevRaw, { 'border-top': solidBorder }),
                            });
                        }
                    } else if (borderType === 'bottom') {
                        if (info && info.r === maxRow) {
                            tr.setNodeMarkup(pos, undefined, {
                                ...cellNode.attrs,
                                rawBorder: updateBorderProperties(prevRaw, { 'border-bottom': solidBorder }),
                            });
                        }
                    } else if (borderType === 'left') {
                        if (info && info.c === minCol) {
                            tr.setNodeMarkup(pos, undefined, {
                                ...cellNode.attrs,
                                rawBorder: updateBorderProperties(prevRaw, { 'border-left': solidBorder }),
                            });
                        }
                    } else if (borderType === 'right') {
                        if (info && info.c === maxCol) {
                            tr.setNodeMarkup(pos, undefined, {
                                ...cellNode.attrs,
                                rawBorder: updateBorderProperties(prevRaw, { 'border-right': solidBorder }),
                            });
                        }
                    } else if (borderType === 'outside') {
                        const isTop = (info && info.r === minRow);
                        const isBottom = (info && info.r === maxRow);
                        const isLeft = (info && info.c === minCol);
                        const isRight = (info && info.c === maxCol);
                        tr.setNodeMarkup(pos, undefined, {
                            ...cellNode.attrs,
                            rawBorder: updateBorderProperties(prevRaw, {
                                'border-top': isTop ? solidBorder : undefined,
                                'border-bottom': isBottom ? solidBorder : undefined,
                                'border-left': isLeft ? solidBorder : undefined,
                                'border-right': isRight ? solidBorder : undefined,
                            }),
                        });
                    } else if (borderType === 'inside') {
                        const isTop = (info && info.r === minRow);
                        const isBottom = (info && info.r === maxRow);
                        const isLeft = (info && info.c === minCol);
                        const isRight = (info && info.c === maxCol);
                        tr.setNodeMarkup(pos, undefined, {
                            ...cellNode.attrs,
                            rawBorder: updateBorderProperties(prevRaw, {
                                'border-top': !isTop ? solidBorder : undefined,
                                'border-bottom': !isBottom ? solidBorder : undefined,
                                'border-left': !isLeft ? solidBorder : undefined,
                                'border-right': !isRight ? solidBorder : undefined,
                            }),
                        });
                    } else if (borderType === 'inside-horizontal') {
                        const isBottom = (info && info.r === maxRow);
                        if (!isBottom) {
                            tr.setNodeMarkup(pos, undefined, {
                                ...cellNode.attrs,
                                rawBorder: updateBorderProperties(prevRaw, { 'border-bottom': solidBorder }),
                            });
                        }
                    } else if (borderType === 'inside-vertical') {
                        const isRight = (info && info.c === maxCol);
                        if (!isRight) {
                            tr.setNodeMarkup(pos, undefined, {
                                ...cellNode.attrs,
                                rawBorder: updateBorderProperties(prevRaw, { 'border-right': solidBorder }),
                            });
                        }
                    }
                });

                editor.view.dispatch(tr);
                scheduleSyncAndPagination();
                return;
            }

            // Single cell or whole table targeting
            let activeCellPos = null;
            for (let d = $from.depth; d > tableDepth; d--) {
                const n = $from.node(d);
                if (n.type.name === 'tableCell' || n.type.name === 'tableHeader') {
                    activeCellPos = $from.before(d);
                    break;
                }
            }

            if (borderType === 'none' || borderType === 'borderless') {
                tr.setNodeMarkup(tablePos, undefined, {
                    ...targetTable.attrs,
                    class: 'mou-table-borderless',
                    tableStyle: 'borderless',
                    'data-table-style': 'borderless',
                });
                let rOffset = tablePos + 1;
                for (let r = 0; r < totalRows; r++) {
                    const row = targetTable.child(r);
                    let cOffset = rOffset + 1;
                    for (let c = 0; c < row.childCount; c++) {
                        const cell = row.child(c);
                        tr.setNodeMarkup(cOffset, undefined, {
                            ...cell.attrs,
                            borderStyle: 'none',
                            borderWidth: '0px',
                            borderColor: 'transparent',
                            rawBorder: 'none',
                        });
                        cOffset += cell.nodeSize;
                    }
                    rOffset += row.nodeSize;
                }
            } else if (borderType === 'all') {
                tr.setNodeMarkup(tablePos, undefined, {
                    ...targetTable.attrs,
                    class: 'mou-table-bordered',
                    tableStyle: 'bordered',
                    'data-table-style': 'bordered',
                });
                let rOffset = tablePos + 1;
                for (let r = 0; r < totalRows; r++) {
                    const row = targetTable.child(r);
                    let cOffset = rOffset + 1;
                    for (let c = 0; c < row.childCount; c++) {
                        const cell = row.child(c);
                        tr.setNodeMarkup(cOffset, undefined, {
                            ...cell.attrs,
                            borderStyle: 'solid',
                            borderWidth: bWidth,
                            borderColor: bColor,
                            rawBorder: solidBorder,
                        });
                        cOffset += cell.nodeSize;
                    }
                    rOffset += row.nodeSize;
                }
            } else if (borderType === 'outside') {
                tr.setNodeMarkup(tablePos, undefined, {
                    ...targetTable.attrs,
                    class: 'mou-table-outside-border',
                    tableStyle: 'outside',
                    'data-table-style': 'outside',
                });
                let rOffset = tablePos + 1;
                for (let r = 0; r < totalRows; r++) {
                    const row = targetTable.child(r);
                    const totalCols = row.childCount;
                    let cOffset = rOffset + 1;
                    for (let c = 0; c < totalCols; c++) {
                        const cell = row.child(c);
                        const isTop = (r === 0);
                        const isBottom = (r === totalRows - 1);
                        const isLeft = (c === 0);
                        const isRight = (c === totalCols - 1);

                        const topB = isTop ? solidBorder : 'none';
                        const bottomB = isBottom ? solidBorder : 'none';
                        const leftB = isLeft ? solidBorder : 'none';
                        const rightB = isRight ? solidBorder : 'none';

                        const raw = `border-top: ${topB}; border-bottom: ${bottomB}; border-left: ${leftB}; border-right: ${rightB}`;
                        tr.setNodeMarkup(cOffset, undefined, {
                            ...cell.attrs,
                            borderStyle: (isTop || isBottom || isLeft || isRight) ? 'solid' : 'none',
                            borderWidth: (isTop || isBottom || isLeft || isRight) ? bWidth : '0px',
                            borderColor: (isTop || isBottom || isLeft || isRight) ? bColor : 'transparent',
                            rawBorder: raw,
                        });
                        cOffset += cell.nodeSize;
                    }
                    rOffset += row.nodeSize;
                }
            } else if (borderType === 'inside') {
                tr.setNodeMarkup(tablePos, undefined, {
                    ...targetTable.attrs,
                    class: 'mou-table-inside-border',
                    tableStyle: 'inside',
                    'data-table-style': 'inside',
                });
                let rOffset = tablePos + 1;
                for (let r = 0; r < totalRows; r++) {
                    const row = targetTable.child(r);
                    const totalCols = row.childCount;
                    let cOffset = rOffset + 1;
                    for (let c = 0; c < totalCols; c++) {
                        const cell = row.child(c);
                        const isTop = (r === 0);
                        const isBottom = (r === totalRows - 1);
                        const isLeft = (c === 0);
                        const isRight = (c === totalCols - 1);

                        const topB = isTop ? 'none' : solidBorder;
                        const bottomB = isBottom ? 'none' : solidBorder;
                        const leftB = isLeft ? 'none' : solidBorder;
                        const rightB = isRight ? 'none' : solidBorder;

                        const raw = `border-top: ${topB}; border-bottom: ${bottomB}; border-left: ${leftB}; border-right: ${rightB}`;
                        tr.setNodeMarkup(cOffset, undefined, {
                            ...cell.attrs,
                            borderStyle: (!isTop || !isBottom || !isLeft || !isRight) ? 'solid' : 'none',
                            borderWidth: (!isTop || !isBottom || !isLeft || !isRight) ? bWidth : '0px',
                            borderColor: (!isTop || !isBottom || !isLeft || !isRight) ? bColor : 'transparent',
                            rawBorder: raw,
                        });
                        cOffset += cell.nodeSize;
                    }
                    rOffset += row.nodeSize;
                }
            } else if (borderType === 'inside-horizontal') {
                let rOffset = tablePos + 1;
                for (let r = 0; r < totalRows; r++) {
                    const row = targetTable.child(r);
                    let cOffset = rOffset + 1;
                    for (let c = 0; c < row.childCount; c++) {
                        const cell = row.child(c);
                        if (r < totalRows - 1) {
                            const prevRaw = cell.attrs.rawBorder || '';
                            const updated = updateBorderProperties(prevRaw, { 'border-bottom': solidBorder });
                            tr.setNodeMarkup(cOffset, undefined, {
                                ...cell.attrs,
                                rawBorder: updated,
                            });
                        }
                        cOffset += cell.nodeSize;
                    }
                    rOffset += row.nodeSize;
                }
            } else if (borderType === 'inside-vertical') {
                let rOffset = tablePos + 1;
                for (let r = 0; r < totalRows; r++) {
                    const row = targetTable.child(r);
                    const totalCols = row.childCount;
                    let cOffset = rOffset + 1;
                    for (let c = 0; c < totalCols; c++) {
                        const cell = row.child(c);
                        if (c < totalCols - 1) {
                            const prevRaw = cell.attrs.rawBorder || '';
                            const updated = updateBorderProperties(prevRaw, { 'border-right': solidBorder });
                            tr.setNodeMarkup(cOffset, undefined, {
                                ...cell.attrs,
                                rawBorder: updated,
                            });
                        }
                        cOffset += cell.nodeSize;
                    }
                    rOffset += row.nodeSize;
                }
            } else if (borderType === 'top' || borderType === 'bottom' || borderType === 'left' || borderType === 'right') {
                let rOffset = tablePos + 1;
                for (let r = 0; r < totalRows; r++) {
                    const row = targetTable.child(r);
                    const totalCols = row.childCount;
                    let cOffset = rOffset + 1;
                    for (let c = 0; c < totalCols; c++) {
                        const cell = row.child(c);
                        let applyThisCell = false;

                        if (activeCellPos && cOffset === activeCellPos) {
                            applyThisCell = true;
                        } else if (!activeCellPos) {
                            if (borderType === 'top' && r === 0) applyThisCell = true;
                            if (borderType === 'bottom' && r === totalRows - 1) applyThisCell = true;
                            if (borderType === 'left' && c === 0) applyThisCell = true;
                            if (borderType === 'right' && c === totalCols - 1) applyThisCell = true;
                        }

                        if (applyThisCell) {
                            const prevRaw = cell.attrs.rawBorder || '';
                            const edgeKey = `border-${borderType}`;
                            const updated = updateBorderProperties(prevRaw, { [edgeKey]: solidBorder });
                            tr.setNodeMarkup(cOffset, undefined, {
                                ...cell.attrs,
                                rawBorder: updated,
                            });
                        }
                        cOffset += cell.nodeSize;
                    }
                    rOffset += row.nodeSize;
                }
            }

            editor.view.dispatch(tr);
            scheduleSyncAndPagination();
        },
        toggleBorderPainter(force) {
            state.isBorderPainterActive = force !== undefined ? Boolean(force) : !state.isBorderPainterActive;
            const isActive = state.isBorderPainterActive;

            const btn = document.getElementById('btnBorderPainterToggle');
            if (btn) {
                btn.classList.toggle('bg-blue-100', isActive);
                btn.classList.toggle('border-blue-400', isActive);
                btn.classList.toggle('text-blue-700', isActive);
                btn.classList.toggle('ring-2', isActive);
                btn.classList.toggle('ring-blue-300', isActive);
            }

            const shell = document.querySelector('.mou-word-studio-shell');
            if (shell) {
                shell.classList.toggle('mou-border-painter-mode', isActive);
            }
            document.getElementById('mouBodyMount')?.classList.toggle('mou-border-painter-mode', isActive);
            document.getElementById('mouHeaderCanvas')?.classList.toggle('mou-border-painter-mode', isActive);
            document.getElementById('mouFooterCanvas')?.classList.toggle('mou-border-painter-mode', isActive);
        },
        applyTablePreset(preset) {
            this.setTableBorders(preset);
        },
        toggleTableGridlines(show) {
            const shell = document.querySelector('.mou-word-studio-shell');
            const isCurrentlyActive = shell ? shell.classList.contains('mou-table-gridlines-active') : true;
            const nextState = show !== undefined ? Boolean(show) : !isCurrentlyActive;

            state.showTableGridlines = nextState;

            // Toggle active class on shell, editor mount, and preview stages
            if (shell) shell.classList.toggle('mou-table-gridlines-active', nextState);
            document.getElementById('mouBodyMount')?.classList.toggle('mou-table-gridlines-active', nextState);
            document.getElementById('mouHeaderCanvas')?.classList.toggle('mou-table-gridlines-active', nextState);
            document.getElementById('mouFooterCanvas')?.classList.toggle('mou-table-gridlines-active', nextState);

            // Update UI buttons and checkboxes
            const btnQuick = document.getElementById('btnQuickToggleGridlines');
            if (btnQuick) {
                btnQuick.classList.toggle('border-blue-500', nextState);
                btnQuick.classList.toggle('bg-blue-50', nextState);
                btnQuick.classList.toggle('text-blue-700', nextState);
                btnQuick.classList.toggle('border-slate-300', !nextState);
                btnQuick.classList.toggle('bg-white', !nextState);
                btnQuick.classList.toggle('text-slate-700', !nextState);
            }

            const btnRibbon = document.getElementById('btnRibbonViewGridlines');
            if (btnRibbon) {
                btnRibbon.classList.toggle('border-blue-400', nextState);
                btnRibbon.classList.toggle('bg-blue-50', nextState);
                btnRibbon.classList.toggle('text-blue-700', nextState);
                btnRibbon.classList.toggle('border-slate-300', !nextState);
                btnRibbon.classList.toggle('bg-white', !nextState);
                btnRibbon.classList.toggle('text-slate-700', !nextState);
            }

            const statusText = document.getElementById('ribbonGridlinesStatusText');
            if (statusText) {
                statusText.textContent = nextState ? 'Garis Kisi: Aktif' : 'Garis Kisi: Nonaktif';
            }

            const chkLayout = document.getElementById('ribbonToggleGridlinesLayout');
            if (chkLayout) {
                chkLayout.checked = nextState;
            }

            return nextState;
        },

        // Find & Replace in active editor
        findAndReplace(findStr, replaceStr) {
            const ed = getActiveEditor();
            if (!ed || !findStr) return 0;
            const html = ed.getHTML();
            const escaped = findStr.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp(`(?![^<]*>)${escaped}`, 'g');
            let count = 0;
            const updated = html.replace(regex, () => {
                count++;
                return replaceStr;
            });
            if (count > 0) {
                ed.commands.setContent(updated);
                scheduleSyncAndPagination();
                if (ed === bodyEditor) {
                    scheduleReflow(true);
                }
            }
            return count;
        },

        replaceLivePreviewPlaceholders,

        // Ruler and precision alignment methods
        toggleRuler(show) {
            const stickyHeader = document.getElementById('mouRulerStickyHeader');
            if (stickyHeader) {
                const isHidden = show !== undefined ? !show : !stickyHeader.classList.contains('hidden');
                stickyHeader.classList.toggle('hidden', isHidden);
                const chk = document.getElementById('ribbonToggleRuler');
                if (chk) chk.checked = !isHidden;
                const btnQuick = document.getElementById('btnQuickToggleRuler');
                if (btnQuick) {
                    btnQuick.classList.toggle('border-blue-500', !isHidden);
                    btnQuick.classList.toggle('bg-blue-50', !isHidden);
                    btnQuick.classList.toggle('text-blue-700', !isHidden);
                }
                if (!isHidden) {
                    requestAnimationFrame(() => {
                        syncRulerToPageSheet();
                        updateRulerMarkersFromEditor();
                    });
                }
            }
        },
        showRulerGuideline,
        hideRulerGuideline,
        showSmartGuides,
        hideSmartGuides,
        showRulerObjectRange,
        hideRulerObjectRange,
        updateRuler: renderHorizontalRuler,
        syncRuler: syncRulerToPageSheet,
    };

    function attachRibbonEventListeners() {
        // 1. Font Family & Size
        const fontFamilySelect = document.getElementById('ribbonFontFamily');
        if (fontFamilySelect) {
            fontFamilySelect.addEventListener('change', (e) => {
                api.setFontFamily(e.target.value);
            });
        }
        const fontSizeSelect = document.getElementById('ribbonFontSize');
        if (fontSizeSelect) {
            fontSizeSelect.addEventListener('change', (e) => {
                api.setFontSize(e.target.value);
            });
        }
        const headingSelect = document.getElementById('ribbonHeadingStyle');
        if (headingSelect) {
            headingSelect.addEventListener('change', (e) => {
                api.setBlockStyle(e.target.value);
            });
        }

        // 2. Text Color & Highlight Color
        const textColorInput = document.getElementById('ribbonTextColor');
        if (textColorInput) {
            textColorInput.addEventListener('input', (e) => {
                api.setTextColor(e.target.value);
            });
        }
        const highlightColorInput = document.getElementById('ribbonHighlightColor');
        if (highlightColorInput) {
            highlightColorInput.addEventListener('input', (e) => {
                api.setHighlightColor(e.target.value);
            });
        }
        const btnRemoveHl = document.getElementById('btnRemoveHighlight');
        if (btnRemoveHl) {
            btnRemoveHl.addEventListener('click', () => {
                api.removeHighlight();
            });
        }

        // 3. Line Height, List Styles & Tab Stop
        const lineHeightSelect = document.getElementById('ribbonParagraphLineHeight');
        if (lineHeightSelect && !lineHeightSelect.dataset.boundLh) {
            lineHeightSelect.dataset.boundLh = '1';
            lineHeightSelect.addEventListener('change', (e) => {
                api.setLineHeight(e.target.value);
            });
        }
        const listStyleSelect = document.getElementById('ribbonOrderedListStyle');
        if (listStyleSelect && !listStyleSelect.dataset.boundLs) {
            listStyleSelect.dataset.boundLs = '1';
            listStyleSelect.addEventListener('change', (e) => {
                if (typeof api.setOrderedListType === 'function') {
                    api.setOrderedListType(e.target.value);
                } else {
                    api.toggleOrderedList(e.target.value);
                }
            });
        }
        const btnInsertTab = document.getElementById('btnInsertTabStop');
        if (btnInsertTab) {
            btnInsertTab.addEventListener('click', () => {
                api.insertTab();
            });
        }
        const tabSizeSelect = document.getElementById('ribbonTabSizeSelect');
        if (tabSizeSelect) {
            tabSizeSelect.addEventListener('change', (e) => {
                api.setTabSize(e.target.value);
            });
        }

        // 3b. Indent & Outdent buttons
        const btnIndent = document.getElementById('btnRibbonIndent');
        if (btnIndent) {
            btnIndent.addEventListener('click', (e) => {
                e.preventDefault();
                api.adjustIndent(6);
            });
        }
        const btnOutdent = document.getElementById('btnRibbonOutdent');
        if (btnOutdent) {
            btnOutdent.addEventListener('click', (e) => {
                e.preventDefault();
                api.adjustIndent(-6);
            });
        }

        // 4. Find & Replace
        const btnExecuteReplace = document.getElementById('btnExecuteReplaceAll');
        if (btnExecuteReplace) {
            btnExecuteReplace.addEventListener('click', () => {
                const findVal = document.getElementById('ribbonFindInput')?.value || '';
                const replaceVal = document.getElementById('ribbonReplaceInput')?.value || '';
                if (!findVal) {
                    alert('Silakan masukkan teks yang ingin dicari.');
                    return;
                }
                const count = api.findAndReplace(findVal, replaceVal);
                alert(`Berhasil mengganti ${count} kecocokan di area aktif.`);
            });
        }

        // 5. Table Width, Column Width, Row Height (Set buttons & Enter keys)
        const btnApplyTableW = document.getElementById('btnApplyTableWidth');
        const tableWInput = document.getElementById('ribbonTableWidthInput');
        const applyTableW = () => {
            if (tableWInput && tableWInput.value) {
                api.setCurrentTableWidth(tableWInput.value);
            }
        };
        if (btnApplyTableW) btnApplyTableW.addEventListener('click', applyTableW);
        if (tableWInput) {
            tableWInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applyTableW();
                }
            });
        }

        const btnApplyColW = document.getElementById('btnApplyColWidth');
        const colWInput = document.getElementById('ribbonColWidthInput');
        const applyColW = () => {
            if (colWInput && colWInput.value) {
                api.setCurrentColumnWidth(colWInput.value);
            }
        };
        if (btnApplyColW) btnApplyColW.addEventListener('click', applyColW);
        if (colWInput) {
            colWInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applyColW();
                }
            });
        }

        const btnApplyRowH = document.getElementById('btnApplyRowHeight');
        const rowHInput = document.getElementById('ribbonRowHeightInput');
        const applyRowH = () => {
            if (rowHInput && rowHInput.value) {
                const val = rowHInput.value.trim();
                const safeVal = /^[0-9.]+$/.test(val) ? `${val}pt` : val;
                api.setCurrentRowHeight(safeVal);
            }
        };
        if (btnApplyRowH) btnApplyRowH.addEventListener('click', applyRowH);
        if (rowHInput) {
            rowHInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applyRowH();
                }
            });
        }

        // 6. Cell Padding, Valign, Background Color
        const cellPaddingSelect = document.getElementById('ribbonCellPaddingSelect');
        if (cellPaddingSelect) {
            cellPaddingSelect.addEventListener('change', (e) => {
                if (e.target.value) {
                    api.updateActiveTableCells({ padding: e.target.value }, false);
                }
            });
        }
        const cellValignSelect = document.getElementById('ribbonCellValignSelect');
        if (cellValignSelect) {
            cellValignSelect.addEventListener('change', (e) => {
                if (e.target.value) {
                    api.updateActiveTableCells({ verticalAlign: e.target.value }, false);
                }
            });
        }
        const cellBgColorInput = document.getElementById('ribbonCellBgColor');
        if (cellBgColorInput) {
            cellBgColorInput.addEventListener('input', (e) => {
                api.updateActiveTableCells({ backgroundColor: e.target.value }, false);
            });
        }

        // 7. Image Asset Controls in Ribbon
        const btnRot90 = document.getElementById('btnRotateImage90');
        if (btnRot90) btnRot90.addEventListener('click', () => api.rotateSelectedImage(90));
        const btnResetImg = document.getElementById('btnResetImageAsset');
        if (btnResetImg) btnResetImg.addEventListener('click', () => api.resetSelectedImage());
        const btnDelImg = document.getElementById('btnDeleteSelectedImage');
        if (btnDelImg) btnDelImg.addEventListener('click', () => api.deleteSelectedAsset());
        const btnApplyImgSize = document.getElementById('btnApplyImageSize');
        const applyImgSize = () => {
            const w = document.getElementById('ribbonImageWidthPx')?.value;
            const h = document.getElementById('ribbonImageHeightPx')?.value;
            const rot = document.getElementById('ribbonImageRotationDeg')?.value;
            api.updateSelectedImage(w, h, rot);
        };
        if (btnApplyImgSize) btnApplyImgSize.addEventListener('click', applyImgSize);

        // 8. Quick Placeholder Insert
        const quickPlaceholderSelect = document.getElementById('ribbonQuickPlaceholderSelect');
        if (quickPlaceholderSelect) {
            quickPlaceholderSelect.addEventListener('change', (e) => {
                if (e.target.value) {
                    getActiveEditor()?.chain().focus().insertContent(`[${e.target.value}]`).run();
                    e.target.value = '';
                }
            });
        }

        // 9. Quick Symbol Insert
        const quickSymbolSelect = document.getElementById('ribbonQuickSymbolSelect');
        if (quickSymbolSelect) {
            quickSymbolSelect.addEventListener('change', (e) => {
                if (e.target.value) {
                    getActiveEditor()?.chain().focus().insertContent(e.target.value).run();
                    e.target.value = '';
                }
            });
        }

        // Note: Lifecycle actions (Export DOCX, Import DOCX, New Blank Document, Load Saved Template)
        // are explicitly managed by the host view template (_word-editor.blade.php) to ensure proper
        // template naming, confirmation handling, and to prevent duplicate download triggers.

        // 10. Watermark toggle button
        const btnWatermark = document.getElementById('btnToggleWatermark');
        if (btnWatermark) {
            btnWatermark.addEventListener('click', () => {
                const enabled = api.toggleWatermark();
                btnWatermark.classList.toggle('mou-ribbon-btn-active', enabled);
            });
        }

        // 14. Interactive Horizontal Ruler & Alignment Controls
        attachInteractiveRulerEvents();

        const chkToggleRuler = document.getElementById('ribbonToggleRuler');
        if (chkToggleRuler) {
            chkToggleRuler.addEventListener('change', (e) => {
                api.toggleRuler(e.target.checked);
            });
        }

        const btnQuickRuler = document.getElementById('btnQuickToggleRuler');
        if (btnQuickRuler) {
            btnQuickRuler.addEventListener('click', () => {
                const stickyHeader = document.getElementById('mouRulerStickyHeader');
                const willShow = stickyHeader ? stickyHeader.classList.contains('hidden') : true;
                api.toggleRuler(willShow);
            });
        }

        // 15. Table Gridlines (Lihat Garis Kisi Tabel ala MS Word)
        const btnQuickGridlines = document.getElementById('btnQuickToggleGridlines');
        if (btnQuickGridlines) {
            btnQuickGridlines.addEventListener('click', () => {
                api.toggleTableGridlines();
            });
        }
        const btnRibbonGridlines = document.getElementById('btnRibbonViewGridlines');
        if (btnRibbonGridlines) {
            btnRibbonGridlines.addEventListener('click', () => {
                api.toggleTableGridlines();
            });
        }
        const chkToggleGridlinesLayout = document.getElementById('ribbonToggleGridlinesLayout');
        if (chkToggleGridlinesLayout) {
            chkToggleGridlinesLayout.addEventListener('change', (e) => {
                api.toggleTableGridlines(e.target.checked);
            });
        }

        // 16. MS Word Floating Borders Dropdown Menu & Border Painter
        const bordersDropdown = document.getElementById('wordBordersFloatingDropdown');
        const bordersTriggers = [
            document.getElementById('btnTableBordersDropdownToggle'),
            document.getElementById('btnHomeBordersDropdownToggle'),
        ].filter(Boolean);

        function positionAndOpenBordersDropdown(triggerBtn) {
            if (!bordersDropdown || !triggerBtn) return;
            const isCurrentlyHidden = bordersDropdown.classList.contains('hidden');
            if (isCurrentlyHidden) {
                const rect = triggerBtn.getBoundingClientRect();
                bordersDropdown.style.position = 'fixed';
                bordersDropdown.style.top = `${rect.bottom + 4}px`;
                bordersDropdown.style.left = `${Math.min(rect.left, window.innerWidth - 270)}px`;
                bordersDropdown.style.zIndex = '99999';

                // Synchronize View Gridlines checkmark
                const gridCheck = document.getElementById('bordersDropdownGridlinesCheck');
                if (gridCheck) {
                    const shell = document.querySelector('.mou-word-studio-shell');
                    const isGridActive = shell ? shell.classList.contains('mou-table-gridlines-active') : true;
                    gridCheck.style.display = isGridActive ? 'inline' : 'none';
                }

                bordersDropdown.classList.remove('hidden');
            } else {
                bordersDropdown.classList.add('hidden');
            }
        }

        bordersTriggers.forEach(btn => {
            btn.addEventListener('mousedown', (e) => e.preventDefault());
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                positionAndOpenBordersDropdown(btn);
            });
        });

        if (bordersDropdown) {
            bordersDropdown.addEventListener('mousedown', (e) => e.preventDefault());
            bordersDropdown.addEventListener('click', (e) => {
                const actionBtn = e.target.closest('button[data-border-action]');
                if (!actionBtn) return;
                const action = actionBtn.getAttribute('data-border-action');
                if (action) {
                    api.setTableBorders(action);
                }
                bordersDropdown.classList.add('hidden');
            });
        }

        document.addEventListener('click', (e) => {
            if (bordersDropdown && !bordersDropdown.classList.contains('hidden')) {
                if (!e.target.closest('#wordBordersFloatingDropdown, #btnTableBordersDropdownToggle, #btnHomeBordersDropdownToggle')) {
                    bordersDropdown.classList.add('hidden');
                }
            }
        });

        // Border Color Hex Label Sync
        const bColorInput = document.getElementById('ribbonTableBorderColor');
        const bColorHex = document.getElementById('ribbonTableBorderColorHex');
        if (bColorInput && bColorHex) {
            bColorInput.addEventListener('input', (e) => {
                bColorHex.textContent = e.target.value.toUpperCase();
            });
        }

        // Border Painter Cell Click & Keyboard Listeners
        document.addEventListener('click', (e) => {
            if (!state.isBorderPainterActive) return;
            if (e.target.closest('.mou-ribbon-bar, .mou-ribbon-panels-container, #wordBordersFloatingDropdown')) return;

            const cellEl = e.target.closest('td, th');
            if (!cellEl) return;

            e.preventDefault();
            e.stopPropagation();

            const bWidth = document.getElementById('ribbonTableBorderWidth')?.value || '1px';
            const bColor = document.getElementById('ribbonTableBorderColor')?.value || '#000000';
            const solidBorder = `${bWidth} solid ${bColor}`;

            const editor = getActiveEditor();
            if (!editor) return;

            const pos = editor.view.posAtDOM(cellEl, 0);
            if (pos >= 0) {
                const { state: edState } = editor;
                const $pos = edState.doc.resolve(pos);
                for (let d = $pos.depth; d >= 0; d--) {
                    const n = $pos.node(d);
                    if (n.type.name === 'tableCell' || n.type.name === 'tableHeader') {
                        const cellPos = $pos.before(d);
                        const tr = edState.tr;
                        tr.setNodeMarkup(cellPos, undefined, {
                            ...n.attrs,
                            borderStyle: 'solid',
                            borderWidth: bWidth,
                            borderColor: bColor,
                            rawBorder: solidBorder,
                        });
                        editor.view.dispatch(tr);
                        scheduleSyncAndPagination();
                        break;
                    }
                }
            }
        }, true);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && state.isBorderPainterActive) {
                api.toggleBorderPainter(false);
            }
        });

        const btnResetMargins = document.getElementById('btnResetRulerMargins');
        if (btnResetMargins) {
            btnResetMargins.addEventListener('click', () => {
                if (confirm('Kembalikan ukuran margin ke standar Normal (25.4 mm / 1 inci di semua sisi)?')) {
                    state.marginTopMm = 25.4;
                    state.marginBottomMm = 25.4;
                    state.marginLeftMm = 25.4;
                    state.marginRightMm = 25.4;

                    const setVal = (id, val) => {
                        const el = document.getElementById(id);
                        if (el) el.value = String(val);
                    };
                    setVal('layoutMarginTop', 25.4);
                    setVal('layoutMarginBottom', 25.4);
                    setVal('layoutMarginLeft', 25.4);
                    setVal('layoutMarginRight', 25.4);

                    applyPageLayoutToCanvas();
                }
            });
        }

        const btnApplyParaLayout = document.getElementById('btnApplyParagraphLayout');
        if (btnApplyParaLayout) {
            btnApplyParaLayout.addEventListener('click', () => {
                const firstLine = parseFloat(document.getElementById('layoutFirstLineIndent')?.value || 0);
                const spaceBefore = parseFloat(document.getElementById('layoutSpaceBefore')?.value || 0);
                const spaceAfter = parseFloat(document.getElementById('layoutSpaceAfter')?.value || 0);

                const ed = getActiveEditor();
                if (ed) {
                    ed.chain().focus().updateAttributes('paragraph', {
                        textIndent: firstLine > 0 ? `${firstLine}mm` : null,
                        marginTop: spaceBefore > 0 ? `${spaceBefore}pt` : null,
                        marginBottom: spaceAfter > 0 ? `${spaceAfter}pt` : null,
                    }).run();
                    updateRulerMarkersFromEditor();
                }
            });
        }
    }

    attachRibbonEventListeners();

    // Default aktifkan Garis Kisi Tabel (View Gridlines) ala MS Word agar tabel borderless langsung terlihat batas selnya
    api.toggleTableGridlines(true);

    window.MouWordProcessor = api;
    window.UnifiedWordProcessor = api;
    return api;
}

export const initUnifiedWordProcessor = initMouWordProcessor;
window.initMouWordProcessor = initMouWordProcessor;
window.initUnifiedWordProcessor = initMouWordProcessor;
if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('mou-word-processor:ready'));
    window.dispatchEvent(new CustomEvent('unified-word-processor:ready'));
}

