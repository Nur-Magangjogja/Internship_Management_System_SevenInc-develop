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
import { OrderedList } from '@tiptap/extension-list';
import { Table, TableRow, TableCell, TableHeader, TableView } from '@tiptap/extension-table';
import { TextStyle, FontFamily, FontSize, Color, BackgroundColor, LineHeight } from '@tiptap/extension-text-style';
import TextAlign from '@tiptap/extension-text-align';
import Highlight from '@tiptap/extension-highlight';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import Image from '@tiptap/extension-image';
import Placeholder from '@tiptap/extension-placeholder';
import { Plugin, PluginKey } from '@tiptap/pm/state';

const MM_TO_PX = 96 / 25.4; // ~3.779527559 px per mm

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
        const attrs = node.attrs || {};
        if (attrs.class) {
            this.table.className = attrs.class;
        }
        if (attrs.tableWidth) {
            this.table.style.width = attrs.tableWidth;
            this.table.style.minWidth = '';
            this.table.setAttribute('data-table-width', attrs.tableWidth);
        } else {
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
    }

    setupTableResizeHandle() {
        if (!this.dom) return;
        this.dom.style.position = 'relative';
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
        if (node.attrs.marginLeft) styles.push(`margin-left: ${node.attrs.marginLeft}`);
        if (node.attrs.marginRight) styles.push(`margin-right: ${node.attrs.marginRight}`);
        if (node.attrs.textIndent) styles.push(`text-indent: ${node.attrs.textIndent}`);
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
        if (node.attrs.marginTop) styles.push(`margin-top: ${node.attrs.marginTop}`);
        if (node.attrs.marginBottom) styles.push(`margin-bottom: ${node.attrs.marginBottom}`);
        if (node.attrs.lineHeight) styles.push(`line-height: ${node.attrs.lineHeight}`);

        const finalAttrs = { ...HTMLAttributes };
        delete finalAttrs.level;
        delete finalAttrs.marginTop;
        delete finalAttrs.marginBottom;
        delete finalAttrs.lineHeight;

        const styleStr = buildStyleString(styles);
        if (styleStr) finalAttrs.style = styleStr;

        return [`h${level}`, mergeAttributes(this.options.HTMLAttributes, finalAttrs), 0];
    },
});

/**
 * Extended OrderedList supporting list numbering style (1, a, A, i, I) and class.
 */
const WordOrderedList = OrderedList.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: el => el.getAttribute('class') || null,
                renderHTML: attrs => (attrs.class ? { class: attrs.class } : {}),
            },
            listType: {
                default: null,
                parseHTML: el => el.getAttribute('type') || null,
                renderHTML: attrs => (attrs.listType ? { type: attrs.listType } : {}),
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
            resizable: true,
            View: WordTableView,
            cellMinWidth: 36,
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
            parseHTML: el => extractStyleProperty(el.getAttribute('style'), 'border') || null,
        },
        class: {
            default: null,
            parseHTML: el => el.getAttribute('class') || null,
        },
    };
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

    if (node.attrs.borderStyle || node.attrs.borderWidth || node.attrs.borderColor) {
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
    } else if (node.attrs.rawBorder) {
        styles.push(`border: ${node.attrs.rawBorder}`);
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
 * Extended Image supporting width, height, alignment, and class.
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
        };
    },
    renderHTML({ node, HTMLAttributes }) {
        const styles = ['object-fit: contain'];
        const attrs = {
            src: node.attrs.src,
            alt: node.attrs.alt || '',
            title: node.attrs.title || '',
            class: node.attrs.class || 'mou-logo-img',
        };

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
    root.querySelectorAll('.mou-pasal, .mou-pasal-body, .mou-pasal-heading').forEach(wrapper => {
        const parent = wrapper.parentNode;
        if (!parent) return;
        while (wrapper.firstChild) {
            parent.insertBefore(wrapper.firstChild, wrapper);
        }
        parent.removeChild(wrapper);
    });

    // Ensure tables with colgroup or cell widths have explicit attributes for Tiptap
    root.querySelectorAll('table').forEach(table => {
        const tWidth = table.getAttribute('data-table-width') || table.style.width;
        if (tWidth) {
            table.setAttribute('data-table-width', tWidth);
            table.style.width = tWidth;
        }
        table.querySelectorAll('tr').forEach(tr => {
            const rHeight = tr.getAttribute('data-row-height') || tr.style.height;
            if (rHeight) {
                tr.setAttribute('data-row-height', rHeight);
                tr.style.height = rHeight;
            }
        });
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

    // Preserve physical page breaks between multiple editor pages as composed in the studio
    const pageNodes = Array.from(root.querySelectorAll('.mou-a4-page-node, [data-mou-page="true"], .mou-a4-paper-sheet'));
    if (pageNodes.length > 1) {
        pageNodes.forEach((pageEl, idx) => {
            if (idx > 0) {
                const prev = pageEl.previousElementSibling;
                const hasBreak = prev && (prev.classList.contains('mou-page-break') || prev.getAttribute('data-page-break') === 'true');
                if (!hasBreak) {
                    const pb = doc.createElement('div');
                    pb.className = 'mou-page-break';
                    pb.setAttribute('data-page-break', 'true');
                    pb.style.cssText = 'page-break-before: always; break-before: page;';
                    pageEl.parentNode.insertBefore(pb, pageEl);
                }
            }
        });
    }

    // Unwrap page containers so output is pure canonical HTML with page breaks
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
 * Create the standard Tiptap extension list for Body, Header, and Footer editors.
 */
function createWordExtensions(placeholderText = 'Ketik isi dokumen di sini...') {
    return [
        StarterKit.configure({
            paragraph: false,
            heading: false,
            orderedList: false,
            link: {
                openOnClick: false,
                HTMLAttributes: {
                    rel: 'noopener noreferrer',
                },
            },
        }),
        WordParagraph,
        WordHeading.configure({ levels: [1, 2, 3, 4] }),
        WordOrderedList,
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
        Subscript,
        Superscript,
        WordImage,
        WordTable,
        WordTableRow,
        WordTableCell,
        WordTableHeader,
        WordPageBreak,
        WordTextBox,
        Placeholder.configure({
            placeholder: placeholderText,
        }),
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

        const isFirstPage = pageNum === 1;
        const headerHtml = (state.differentFirstPage && isFirstPage)
            ? (state.firstPageHeaderHtmlStore || '')
            : (state.headerHtmlStore || '');
        const footerHtml = (state.differentFirstPage && isFirstPage)
            ? (state.firstPageFooterHtmlStore || '')
            : (state.footerHtmlStore || '');

        const headerWrapper = document.getElementById('mouHeaderZoneWrapper');
        if (!this.headerEl.contains(headerWrapper)) {
            this.headerEl.innerHTML = headerHtml || `
                <div class="mou-header-empty-indicator text-slate-300 italic text-[10px] py-0.5 select-none text-center border-b border-dashed border-slate-200">
                    (Header Kosong &bull; Klik Ganda untuk Mengisi)
                </div>
            `;
            const bodyTopGapMm = Math.max(2, state.marginTopMm - state.headerDistanceMm);
            this.headerEl.style.marginBottom = `${bodyTopGapMm}mm`;
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

        this.headerEl.addEventListener('dblclick', (e) => {
            e.stopPropagation();
            const { setActiveZone } = this.mouContext;
            const pageNum = this.getPageIndex() + 1;
            if (setActiveZone) setActiveZone('header', pageNum);
        });

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

    update(updatedNode) {
        if (updatedNode.type.name !== 'mouPage') return false;
        this.node = updatedNode;
        this.updateAppearance();
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
        WordOrderedList,
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
        Subscript,
        Superscript,
        WordImage,
        WordTable,
        WordTableRow,
        WordTableCell,
        WordTableHeader,
        WordPageBreak,
        WordTextBox,
        Placeholder.configure({
            placeholder: placeholderText,
        }),
    ];
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

    const resolvedCompanyLogo = mediaAssets.company_logo || mediaAssets.company_logo_url || '/images/mou/seven-inc-logo.png';
    const resolvedInstitutionLogo = mediaAssets.institution_logo || mediaAssets.institution_logo_url || '/images/mou/institution-logo-placeholder.png';

    const state = {
        activeZone: 'body', // 'body' | 'header' | 'footer'
        editingFirstPageVariant: false,
        differentFirstPage: Boolean(pageConfig.different_first_page),
        showParafBox: pageConfig.show_paraf_box !== undefined ? Boolean(pageConfig.show_paraf_box) : true,
        showPageNumber: pageConfig.show_page_number !== undefined ? Boolean(pageConfig.show_page_number) : true,
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
                scheduleSyncAndPagination();
                if (!transaction.getMeta(reflowPluginKey)) {
                    reflowPassCount = 0;
                    scheduleReflow(false);
                }
            },
        });
        bodyEditor.mouContext = { state, getPaperDimensionsMm, setActiveZone };
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
                scheduleSyncAndPagination();
                if (!transaction.getMeta(reflowPluginKey)) {
                    reflowPassCount = 0;
                    scheduleReflow(false);
                }
            },
        });
        bodyEditor.mouContext = { state, getPaperDimensionsMm, setActiveZone };
    }

    // Guarantee non-empty initial render when fallback HTML had content
    if (bodyEditor.isEmpty && normalizedFallbackHtml !== '<p></p>' && !normalizedFallbackHtml.includes('is-editor-empty')) {
        bodyEditor.commands.setContent(normalizeHtmlForBodyEditor(normalizedFallbackHtml), { emitUpdate: false });
    }

    // Instantiate Header Sub-Editor
    const headerEditor = headerMountEl
        ? new Editor({
              element: headerMountEl,
              extensions: createWordExtensions('Klik ganda untuk mengisi Header dokumen (mis. Kop Surat / Logo)...'),
              content: state.headerHtmlStore,
              editable: false,
              onFocus: () => {
                  updateToolbarState();
              },
              onSelectionUpdate: () => {
                  updateToolbarState();
              },
              onTransaction: () => {
                  saveActiveHeaderFooterToStore();
                  updateToolbarState();
                  scheduleSyncAndPagination();
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
              onTransaction: () => {
                  saveActiveHeaderFooterToStore();
                  updateToolbarState();
                  scheduleSyncAndPagination();
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
                headerSlot.innerHTML = '';
                headerSlot.appendChild(headerWrapper);
                headerWrapper.classList.remove('hidden');
                headerWrapper.classList.add('mou-zone-active');
            }
            loadHeaderFooterVariantIntoEditors();
            headerEditor?.setEditable(true);
            headerEditor?.commands.focus();
            bodyEditor?.setEditable(false);
            footerEditor?.setEditable(false);
            switchRibbonTab('headerfooter');
        } else if (zone === 'footer' && footerWrapper) {
            const pageDoms = document.querySelectorAll('.mou-a4-page-node');
            const targetPageDom = pageDoms[targetPageNum - 1] || pageDoms[0];
            const footerSlot = targetPageDom ? targetPageDom.querySelector('.mou-a4-page-footer') : null;
            if (footerSlot) {
                footerSlot.innerHTML = '';
                footerSlot.appendChild(footerWrapper);
                footerWrapper.classList.remove('hidden');
                footerWrapper.classList.add('mou-zone-active');
            }
            loadHeaderFooterVariantIntoEditors();
            footerEditor?.setEditable(true);
            footerEditor?.commands.focus();
            bodyEditor?.setEditable(false);
            headerEditor?.setEditable(false);
            switchRibbonTab('headerfooter');
        } else {
            // Body mode
            headerEditor?.setEditable(false);
            footerEditor?.setEditable(false);
            bodyEditor?.setEditable(true);

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

        if (closeHfBtn) {
            closeHfBtn.classList.toggle('hidden', zone === 'body');
        }

        if (zoneBadge) {
            if (zone === 'header') {
                zoneBadge.textContent = state.differentFirstPage && state.editingFirstPageVariant
                    ? `Mode Edit: Header Hal. ${targetPageNum} (Khusus Pertama)`
                    : `Mode Edit: Header Dokumen (Hal. ${targetPageNum})`;
                zoneBadge.className = 'inline-flex items-center gap-1.5 rounded-full bg-amber-100 border border-amber-300 px-3 py-1 text-xs font-bold text-amber-800';
            } else if (zone === 'footer') {
                zoneBadge.textContent = state.differentFirstPage && state.editingFirstPageVariant
                    ? `Mode Edit: Footer / Kotak Paraf Hal. ${targetPageNum} (Khusus Pertama)`
                    : `Mode Edit: Footer & Kotak Paraf (Hal. ${targetPageNum})`;
                zoneBadge.className = 'inline-flex items-center gap-1.5 rounded-full bg-amber-100 border border-amber-300 px-3 py-1 text-xs font-bold text-amber-800';
            } else {
                zoneBadge.textContent = 'Mode Edit: Lembar Dokumen A4';
                zoneBadge.className = 'inline-flex items-center gap-1.5 rounded-full bg-blue-50 border border-blue-200 px-3 py-1 text-xs font-semibold text-blue-700';
            }
        }
        updateToolbarState();
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

    // Keydown listener for Esc key to exit Header/Footer edit mode
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' || e.keyCode === 27) {
            if (state.activeZone === 'header' || state.activeZone === 'footer') {
                e.preventDefault();
                setActiveZone('body');
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

    /**
     * Interactive Row Height & Whole-Table Width Drag Resizing
     * Works inside Body, Header, AND Footer editors, dispatching real ProseMirror transactions.
     */
    function attachInteractiveTableResizers(mountContainer, getEditorInstance) {
        if (!mountContainer) return;

        let dragMode = null; // 'row-height' | 'table-width'
        let targetRowEl = null;
        let targetTableEl = null;
        let startY = 0;
        let startX = 0;
        let startHeightPx = 0;
        let startWidthPx = 0;

        const guideLine = document.createElement('div');
        guideLine.className = 'mou-resize-guide-line hidden no-print';
        const guideBadge = document.createElement('span');
        guideBadge.className = 'mou-resize-guide-badge';
        guideLine.appendChild(guideBadge);
        document.body.appendChild(guideLine);

        mountContainer.addEventListener('mousemove', (e) => {
            if (dragMode) return;
            const editor = getEditorInstance();
            if (!editor || !editor.isEditable) return;

            const cell = e.target.closest('td, th');
            if (cell) {
                const tr = cell.parentElement;
                const rect = tr.getBoundingClientRect();
                if (Math.abs(e.clientY - rect.bottom) <= 5) {
                    mountContainer.style.cursor = 'row-resize';
                    return;
                }
            }
            if (mountContainer.style.cursor === 'row-resize') {
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
                    startWidthPx = table.getBoundingClientRect().width;
                    document.body.style.cursor = 'ew-resize';
                    return;
                }
            }

            // 2. Check row bottom edge drag
            const cell = e.target.closest('td, th');
            if (cell) {
                const tr = cell.parentElement;
                const rect = tr.getBoundingClientRect();
                if (Math.abs(e.clientY - rect.bottom) <= 5) {
                    e.preventDefault();
                    e.stopPropagation();
                    dragMode = 'row-height';
                    targetRowEl = tr;
                    startY = e.clientY;
                    startHeightPx = rect.height;
                    guideLine.classList.remove('hidden');
                    guideLine.style.top = `${e.clientY}px`;
                    guideLine.style.left = `${rect.left}px`;
                    guideLine.style.width = `${rect.width}px`;
                    const ptVal = Math.round((startHeightPx * 72) / 96);
                    guideBadge.textContent = `Tinggi Baris: ${ptVal} pt (${(startHeightPx / MM_TO_PX).toFixed(1)} mm)`;
                    document.body.style.cursor = 'row-resize';
                }
            }
        });

        window.addEventListener('mousemove', (e) => {
            if (!dragMode) return;
            const scale = state.zoomPercent / 100;

            if (dragMode === 'row-height' && targetRowEl) {
                const deltaY = (e.clientY - startY) / scale;
                const newHeightPx = Math.max(18, Math.round(startHeightPx + deltaY));
                const ptVal = Math.max(12, Math.round((newHeightPx * 72) / 96));
                targetRowEl.style.height = `${ptVal}pt`;
                targetRowEl.setAttribute('data-row-height', `${ptVal}pt`);
                Array.from(targetRowEl.children).forEach(td => {
                    td.style.height = `${ptVal}pt`;
                    td.setAttribute('data-row-height', `${ptVal}pt`);
                });
                guideLine.style.top = `${e.clientY}px`;
                guideBadge.textContent = `Tinggi Baris: ${ptVal} pt (${(newHeightPx / MM_TO_PX).toFixed(1)} mm)`;
            } else if (dragMode === 'table-width' && targetTableEl) {
                const deltaX = (e.clientX - startX) / scale;
                const newWidthPx = Math.max(120, Math.round(startWidthPx + deltaX));
                const ptVal = Math.round((newWidthPx * 72) / 96);
                targetTableEl.style.width = `${ptVal}pt`;
                targetTableEl.setAttribute('data-table-width', `${ptVal}pt`);
            }
        });

        window.addEventListener('mouseup', () => {
            if (!dragMode) return;
            const editor = getEditorInstance();
            guideLine.classList.add('hidden');
            document.body.style.cursor = '';

            if (dragMode === 'row-height' && targetRowEl && editor) {
                const finalRowHeight = targetRowEl.getAttribute('data-row-height') || targetRowEl.style.height;
                if (finalRowHeight) {
                    applyRowHeightTransaction(editor, targetRowEl, finalRowHeight);
                }
            } else if (dragMode === 'table-width' && targetTableEl && editor) {
                const finalTableWidth = targetTableEl.getAttribute('data-table-width') || targetTableEl.style.width;
                if (finalTableWidth) {
                    applyTableWidthTransaction(editor, targetTableEl, finalTableWidth);
                }
            }

            dragMode = null;
            targetRowEl = null;
            targetTableEl = null;
            scheduleSyncAndPagination();
        });
    }

    /**
     * Dispatch a ProseMirror transaction to persist rowHeight on a DOM <tr> element's node and child cells.
     */
    function applyRowHeightTransaction(editor, trDom, rowHeightValue) {
        try {
            const pos = editor.view.posAtDOM(trDom, 0);
            const $pos = editor.state.doc.resolve(pos);
            let trPos = null;
            let trNode = null;
            for (let d = $pos.depth; d >= 0; d--) {
                const node = $pos.node(d);
                if (node.type.name === 'tableRow') {
                    trPos = $pos.before(d);
                    trNode = node;
                    break;
                }
            }
            if (trPos !== null && trNode) {
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
                if (rowHeightInput) rowHeightInput.value = rowHeightValue;
            }
        } catch (err) {
            console.warn('Row height transaction fallback:', err);
        }
    }

    /**
     * Dispatch a ProseMirror transaction to persist tableWidth on a DOM <table> element's node.
     */
    function applyTableWidthTransaction(editor, tableDom, tableWidthValue) {
        try {
            const pos = editor.view.posAtDOM(tableDom, 0);
            const $pos = editor.state.doc.resolve(pos);
            for (let d = $pos.depth; d >= 0; d--) {
                const node = $pos.node(d);
                if (node.type.name === 'table') {
                    const tablePos = $pos.before(d);
                    const tr = editor.state.tr.setNodeMarkup(tablePos, undefined, {
                        ...node.attrs,
                        tableWidth: tableWidthValue,
                    });
                    editor.view.dispatch(tr);
                    const tableWidthInput = document.getElementById('ribbonTableWidthInput');
                    if (tableWidthInput) tableWidthInput.value = tableWidthValue;
                    break;
                }
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
        editor.chain().focus().updateAttributes('table', { tableWidth: widthValue || '100%' }).run();
        scheduleSyncAndPagination();
    }

    function setCurrentTableAlign(alignValue) {
        const editor = getActiveEditor();
        if (!editor) return;
        editor.chain().focus().updateAttributes('table', { tableAlign: alignValue }).run();
        scheduleSyncAndPagination();
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

    function updateActiveTableCells(attrUpdates, applyToWholeTable = false) {
        const editor = getActiveEditor();
        if (!editor) return;
        const { state: edState } = editor;
        const { $from } = edState.selection;

        if (applyToWholeTable) {
            for (let d = $from.depth; d >= 0; d--) {
                const n = $from.node(d);
                if (n.type.name === 'table') {
                    const tablePos = $from.before(d);
                    const tr = edState.tr;
                    let rowOffset = tablePos + 1;
                    for (let r = 0; r < n.childCount; r++) {
                        const row = n.child(r);
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
            }
        }

        editor.chain().focus()
            .updateAttributes('tableCell', attrUpdates)
            .updateAttributes('tableHeader', attrUpdates)
            .run();
        scheduleSyncAndPagination();
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

    function syncAllToHiddenInputs() {
        saveActiveHeaderFooterToStore();

        const bodyHtml = synchronizeSerializedHtml(bodyEditor.getHTML());
        const bodyJson = JSON.stringify(bodyEditor.getJSON());

        const setInputVal = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.value = val;
        };

        const compLogo = state.mediaAssets.company_logo || state.mediaAssets.company_logo_url || '/images/mou/seven-inc-logo.png';
        const instLogo = state.mediaAssets.institution_logo || state.mediaAssets.institution_logo_url || '/images/mou/institution-logo-placeholder.png';

        setInputVal('inputTemplateHtml', bodyHtml);
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
        setInputVal('inputInstitutionLogo', instLogo);
        setInputVal('inputCompanyLogo', compLogo);
        setInputVal('inputInstitutionLogoUrl', instLogo);
        setInputVal('inputCompanyLogoUrl', compLogo);
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
     * Render interactive-style Horizontal Millimeter Ruler showing active left/right margins.
     */
    function renderHorizontalRuler() {
        const rulerTrack = document.getElementById('mouHorizontalRulerTrack');
        const leftMarginMask = document.getElementById('mouRulerLeftMargin');
        const rightMarginMask = document.getElementById('mouRulerRightMargin');
        const dims = getPaperDimensionsMm();

        if (rulerTrack) {
            rulerTrack.style.width = `${dims.width}mm`;
        }
        if (leftMarginMask) leftMarginMask.style.width = `${state.marginLeftMm}mm`;
        if (rightMarginMask) rightMarginMask.style.width = `${state.marginRightMm}mm`;

        if (rulerTrack && rulerTrack.children.length <= 2) {
            const maxCm = Math.ceil(dims.width / 10);
            for (let cm = 0; cm <= maxCm; cm++) {
                const tick = document.createElement('div');
                tick.className = 'mou-ruler-cm';
                tick.style.left = `${cm * 10}mm`;
                if (cm > 0 && cm < maxCm) {
                    const lbl = document.createElement('span');
                    lbl.textContent = String(cm);
                    tick.appendChild(lbl);
                }
                rulerTrack.appendChild(tick);

                if (cm < maxCm) {
                    const halfTick = document.createElement('div');
                    halfTick.className = 'mou-ruler-half-cm';
                    halfTick.style.left = `${cm * 10 + 5}mm`;
                    rulerTrack.appendChild(halfTick);
                }
            }
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

            const isFirstPage = pageNum === 1;
            const headerHtml = (state.differentFirstPage && isFirstPage)
                ? (state.firstPageHeaderHtmlStore || '')
                : (state.headerHtmlStore || '');
            const footerHtml = (state.differentFirstPage && isFirstPage)
                ? (state.firstPageFooterHtmlStore || '')
                : (state.footerHtmlStore || '');

            const headerEl = pageDom.querySelector('.mou-a4-page-header');
            const headerWrapper = document.getElementById('mouHeaderZoneWrapper');
            if (headerEl && (!headerWrapper || !headerEl.contains(headerWrapper))) {
                headerEl.innerHTML = headerHtml || `
                    <div class="mou-header-empty-indicator text-slate-300 italic text-[10px] py-0.5 select-none text-center border-b border-dashed border-slate-200">
                        (Header Kosong &bull; Klik Ganda untuk Mengisi)
                    </div>
                `;
                const bodyTopGapMm = Math.max(2, state.marginTopMm - state.headerDistanceMm);
                headerEl.style.marginBottom = `${bodyTopGapMm}mm`;
            }

            const footerEl = pageDom.querySelector('.mou-a4-page-footer');
            const footerWrapper = document.getElementById('mouFooterZoneWrapper');
            if (footerEl && (!footerWrapper || !footerEl.contains(footerWrapper))) {
                const pageNumberHtml = state.showPageNumber
                    ? `<div class="font-serif text-[9pt] text-black pb-0.5 shrink-0 select-none text-right whitespace-nowrap">Halaman ${pageNum} dari ${totalPages}</div>`
                    : '';
                footerEl.innerHTML = `
                    <div class="mou-footer-content-row flex items-end justify-between gap-4 w-full">
                        <div class="mou-footer-paraf-area flex-1 min-w-0" style="visibility: ${state.showParafBox ? 'visible' : 'hidden'}">
                            ${footerHtml}
                        </div>
                        ${pageNumberHtml}
                    </div>
                `;
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
        });
    }

    /**
     * Automatic True Multi-Page A4 Reflow Engine.
     */
    let reflowTimer = null;
    let reflowPassCount = 0;

    function scheduleReflow(immediate = false) {
        if (reflowTimer) clearTimeout(reflowTimer);
        const delay = immediate ? 0 : 25;
        reflowTimer = setTimeout(() => {
            requestAnimationFrame(() => {
                if (!bodyEditor || bodyEditor.isDestroyed) return;
                if (reflowPassCount > 6) {
                    reflowPassCount = 0;
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

        // STEP 0: Clean up any trailing blank pages immediately
        for (let pIdx = pageCount - 1; pIdx > 0; pIdx--) {
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
                - headerH - footerH - bodyTopGapPx - bodyBtmGapPx - 8;

            const children = contentDom.children;
            const contentDomRect = contentDom.getBoundingClientRect();

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
                const childBottom = childRect.bottom - contentDomRect.top;

                // 12px tolerance buffer so minor padding/cursor adjustments never cause spurious page breaks
                if (childBottom > usableHeight + 12) {
                    if (cIdx === 0 && children.length === 1) {
                        continue;
                    }
                    overflowingIndex = cIdx;
                    break;
                }
            }

            if (overflowingIndex !== -1 && overflowingIndex < pageNode.childCount) {
                let finalSplitIndex = overflowingIndex;
                if (finalSplitIndex > 0) {
                    const prevNode = pageNode.child(finalSplitIndex - 1);
                    const isHeading = prevNode.type.name === 'heading' || prevNode.attrs?.isPasalHeading || false;
                    if (isHeading && (finalSplitIndex - 1 > 0)) {
                        finalSplitIndex = finalSplitIndex - 1;
                    }
                }

                // If remaining nodes on the last page are merely empty paragraphs, do not spawn a new blank page!
                let hasRealContent = false;
                for (let c = finalSplitIndex; c < pageNode.childCount; c++) {
                    const node = pageNode.child(c);
                    if (node.type.name !== 'paragraph' || node.textContent.trim() !== '' || node.attrs?.isPageBreak) {
                        hasRealContent = true;
                        break;
                    }
                }
                if (!hasRealContent && pIdx === pageCount - 1) {
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
            executeReflowTransaction(editor, splitAction);
        } else {
            updateDocumentMetrics();
            refreshAllPageViews();
        }
    }

    function executeReflowTransaction(editor, action) {
        const { state: edState, view } = editor;
        const doc = edState.doc;
        const tr = edState.tr;

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

            // Never spawn a new blank page if nodes to move are only empty paragraphs
            if (pageIndex + 1 >= doc.childCount) {
                const hasSubstance = nodesToMove.some(n => n.type.name !== 'paragraph' || n.textContent.trim() !== '');
                if (!hasSubstance) return;
            }

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

            tr.setMeta('addToHistory', false);
            tr.setMeta(reflowPluginKey, true);
            view.dispatch(tr);
            scheduleReflow(false);
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

            tr.setMeta('addToHistory', false);
            tr.setMeta(reflowPluginKey, true);
            view.dispatch(tr);
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
            tr.setMeta('addToHistory', false);
            tr.setMeta(reflowPluginKey, true);
            view.dispatch(tr);
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

        document.querySelectorAll('[data-tiptap-align]').forEach(btn => {
            const align = btn.getAttribute('data-tiptap-align');
            btn.classList.toggle('mou-ribbon-btn-active', editor.isActive({ textAlign: align }));
        });

        const bulletBtn = document.getElementById('btnToggleBulletList');
        if (bulletBtn) bulletBtn.classList.toggle('mou-ribbon-btn-active', editor.isActive('bulletList'));

        const orderedBtn = document.getElementById('btnToggleOrderedList');
        if (orderedBtn) orderedBtn.classList.toggle('mou-ribbon-btn-active', editor.isActive('orderedList'));

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
        }
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

        const compLogo = state.mediaAssets.company_logo || state.mediaAssets.company_logo_url || '/images/mou/seven-inc-logo.png';
        const instLogo = state.mediaAssets.institution_logo || state.mediaAssets.institution_logo_url || '/images/mou/institution-logo-placeholder.png';

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
            institution_name: 'UNIVERSITAS CONTOH INDONESIA',
            institution_mou_number: '045/UNIV-CI/MOU/XI/' + new Date().getFullYear(),
            institution_signatory_name: 'Dr. Rido Kurnianto, M.Ag.',
            institution_signatory_title: 'Rektor',
            institution_address: 'Jl. Budi Utomo No.10, Ronowijayan, Kec. Ponorogo, Kabupaten Ponorogo, Jawa Timur 63471',
            institution_correspondence_unit: 'Kepala Bidang Kerjasama Dalam Negeri',
            institution_phone: '0352-481124',
            institution_fax: '461796',
            institution_email: 'kerjasama@univ-contoh.ac.id',
            agreement_day: 'Sabtu',
            agreement_date: '24 Mei ' + new Date().getFullYear(),
            agreement_date_short: '24-05-' + new Date().getFullYear(),
            agreement_date_words: 'tanggal dua puluh empat bulan Mei tahun ' + new Date().getFullYear(),
            effective_start: '24 Mei ' + new Date().getFullYear(),
            effective_end: '24 Mei ' + (new Date().getFullYear() + 5),
        };

        let out = rawHtml;
        // Replace logo placeholders first
        const compLogoImg = `<img src="${compLogo}" alt="Logo Seven Inc" class="mou-logo-img" style="width: 88px; height: auto; display: block; margin: 0 auto;" />`;
        const instLogoImg = instLogo && instLogo !== 'none'
            ? `<img src="${instLogo}" alt="Logo Institusi" class="mou-logo-img" style="width: 88px; height: auto; display: block; margin: 0 auto;" />`
            : '';

        out = out.replace(/\[company_logo\]|\{\{\s*company_logo\s*\}\}/g, compLogoImg);
        out = out.replace(/\[institution_logo\]|\{\{\s*institution_logo\s*\}\}/g, instLogoImg);

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

    // Observe Header and Footer height changes (e.g. when resizing Kotak Paraf table)
    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(() => {
            updateVisualPageBreakGuides();
        });
        if (headerZoneWrapper) ro.observe(headerZoneWrapper);
        if (footerZoneWrapper) ro.observe(footerZoneWrapper);
        if (bodyMountEl) ro.observe(bodyMountEl);
    }

    // Initial layout & sync
    applyPageLayoutToCanvas();
    syncAllToHiddenInputs();

    // Form submit hook to guarantee latest HTML & JSON are synced
    const form = document.getElementById('mouTemplateForm');
    if (form) {
        form.addEventListener('submit', () => {
            syncAllToHiddenInputs();
        });
    }

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
                const iLogo = tplData.media_assets.institution_logo || tplData.media_assets.institution_logo_url || '/images/mou/institution-logo-placeholder.png';
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

        // Formatting commands on active editor
        undo: () => getActiveEditor()?.chain().focus().undo().run(),
        redo: () => getActiveEditor()?.chain().focus().redo().run(),
        clearFormatting: () => getActiveEditor()?.chain().focus().unsetAllMarks().clearNodes().run(),
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
        setTextColor: (color) => getActiveEditor()?.chain().focus().setColor(color).run(),
        setHighlightColor: (color) => {
            const ed = getActiveEditor();
            if (!ed) return;
            if (!color || color === 'transparent') {
                ed.chain().focus().unsetHighlight().run();
            } else {
                ed.chain().focus().setHighlight({ color }).run();
            }
        },
        setTextAlign: (align) => getActiveEditor()?.chain().focus().setTextAlign(align).run(),
        setLineHeight: (lh) => {
            const ed = getActiveEditor();
            if (!ed) return;
            ed.chain().focus()
                .updateAttributes('paragraph', { lineHeight: String(lh) })
                .updateAttributes('heading', { lineHeight: String(lh) })
                .run();
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
        toggleBulletList: () => getActiveEditor()?.chain().focus().toggleBulletList().run(),
        toggleOrderedList: (listType = null) => {
            const ed = getActiveEditor();
            if (!ed) return;
            if (listType) {
                if (!ed.isActive('orderedList')) {
                    ed.chain().focus().toggleOrderedList().updateAttributes('orderedList', { listType }).run();
                } else {
                    ed.chain().focus().updateAttributes('orderedList', { listType }).run();
                }
            } else {
                ed.chain().focus().toggleOrderedList().run();
            }
        },
        adjustIndent: (deltaMm) => {
            const ed = getActiveEditor();
            if (!ed) return;
            const attrs = ed.getAttributes('paragraph');
            const curMm = parseFloat(String(attrs.marginLeft || '0').replace('mm', '')) || 0;
            const nextMm = Math.max(0, Math.min(80, curMm + deltaMm));
            ed.chain().focus().updateAttributes('paragraph', {
                marginLeft: nextMm > 0 ? `${nextMm}mm` : null,
            }).run();
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
            ed.chain().focus().insertTable({
                rows: Math.max(1, parseInt(rows, 10) || 3),
                cols: Math.max(1, parseInt(cols, 10) || 3),
                withHeaderRow: Boolean(withHeaderRow),
            }).run();
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
            ed.chain().focus().updateAttributes('image', updates).run();
            scheduleSyncAndPagination();
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
                }
                if (slot === 'company_logo') {
                    state.mediaAssets.company_logo = data.url;
                    state.mediaAssets.company_logo_url = data.url;
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
        deleteTable: () => getActiveEditor()?.chain().focus().deleteTable().run(),
        setCurrentRowHeight,
        setCurrentTableWidth,
        setCurrentTableAlign,
        setCurrentColumnWidth,
        updateActiveTableCells,
        applyTablePreset(preset) {
            if (preset === 'borderless') {
                updateActiveTableCells({ borderStyle: 'none', borderWidth: '0px' }, true);
            } else if (preset === 'grid') {
                updateActiveTableCells({ borderStyle: 'solid', borderWidth: '1px', borderColor: '#000000' }, true);
            } else if (preset === 'paraf') {
                setCurrentTableWidth('220pt');
                updateActiveTableCells({ borderStyle: 'solid', borderWidth: '1px', borderColor: '#000000', padding: '2pt 4pt' }, true);
            }
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
    };

    window.MouWordProcessor = api;
    return api;
}

window.initMouWordProcessor = initMouWordProcessor;
if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('mou-word-processor:ready'));
}

