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
import Placeholder from '@tiptap/extension-placeholder';
import { Plugin, PluginKey, NodeSelection } from '@tiptap/pm/state';
import { importDocxFile, exportProseMirrorJsonToDocx, downloadDocxBlob } from './mou-docx-engine.js';

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
        this.table.style.maxWidth = '100%';
        this.table.style.boxSizing = 'border-box';
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
                default: '1',
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

        this.img.style.cssText = styles.join('; ');
        this.badge.textContent = `${wPx} px`;
    }

    update(node) {
        if (node.type.name !== 'image') return false;
        this.updateAppearance(node);
        return true;
    }

    selectNode() {
        this.dom.classList.add('is-selected');
        this.overlay.style.display = 'block';
    }

    deselectNode() {
        this.dom.classList.remove('is-selected');
        this.overlay.style.display = 'none';
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
            const wInput = document.getElementById('ribbonImageWidthPx');
            if (wInput) {
                const w = parseInt(String(this.node.attrs?.width || '110').replace(/[^0-9]/g, ''), 10) || 110;
                wInput.value = String(w);
            }
        });
    }

    attachHandleDrag(handleEl, handlePos) {
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let startW = 0;
        let startH = 0;

        handleEl.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            e.stopPropagation();
            isDragging = true;
            try { handleEl.setPointerCapture(e.pointerId); } catch (_) {}

            startX = e.clientX;
            startY = e.clientY;
            const rect = this.img.getBoundingClientRect();
            const scale = (parseFloat(window.MouWordProcessor?.state?.zoomPercent) || 100) / 100;
            startW = rect.width / scale;
            startH = rect.height / scale;
            this.selectNode();
        });

        handleEl.addEventListener('pointermove', (e) => {
            if (!isDragging) return;
            e.preventDefault();
            const scale = (parseFloat(window.MouWordProcessor?.state?.zoomPercent) || 100) / 100;
            const deltaX = (e.clientX - startX) / scale;
            const deltaY = (e.clientY - startY) / scale;

            let newW = startW;
            let newH = startH;
            const aspect = startW / (startH || 1);

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

            this.img.style.width = `${Math.round(newW)}px`;
            this.img.style.height = `${Math.round(newH)}px`;
            this.badge.textContent = `${Math.round(newW)} × ${Math.round(newH)} px`;
        });

        const stopDrag = (e) => {
            if (!isDragging) return;
            isDragging = false;
            try { handleEl.releasePointerCapture(e.pointerId); } catch (_) {}

            const finalW = Math.round(parseFloat(this.img.style.width) || startW);
            const finalH = Math.round(parseFloat(this.img.style.height) || startH);

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

        handleEl.addEventListener('pointerup', stopDrag);
        handleEl.addEventListener('pointercancel', stopDrag);
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
        WordParagraph,
        WordHeading.configure({ levels: [1, 2, 3, 4] }),
        WordBulletList,
        WordOrderedList,
        ListItem,
        ListKeymap,
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
        WordSignatureBlock,
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
 * Renders the watermark and fixed header logo HTML layer for a page.
 * - For MOU: renders institution logo (left) and company logo (right)
 * - For LOA and official letters: renders ONLY 1 company logo on the left
 * - Center watermark (company logo) is rendered if showWatermark is true
 */
function renderWatermarkLayerHtml(state, isFirstPage) {
    if (!state) return '';
    const isMou = (state.documentType === 'mou');
    const showHeaderLogos = state.showHeaderLogos !== false;
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

    // Logo Tetap Header: HANYA untuk MOU (institusi di kiri, brand di kanan)
    if (isMou && isFirstPage && showHeaderLogos) {
        const compLogoUrl = state.mediaAssets?.company_logo || state.mediaAssets?.company_logo_url || '/images/mou/seven-inc-logo.png';
        const topHeaderMm = Math.max(10, state.marginTopMm - 10);
        const instLogoUrl = state.mediaAssets?.institution_logo || state.mediaAssets?.institution_logo_url || '/images/mou/institution-logo-placeholder.png';
        watermarkHtml += `
            <div class="mou-watermark-fixed-header" style="top: ${topHeaderMm}mm; left: ${state.marginLeftMm}mm; right: ${state.marginRightMm}mm;">
                <img src="${instLogoUrl}" class="mou-watermark-logo-left" alt="Logo Institusi" title="Logo Institusi (Tetap / Watermark)" />
                <img src="${compLogoUrl}" class="mou-watermark-logo-right" alt="Logo Seven Inc" title="Logo Seven Inc (Tetap / Watermark)" />
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

        const activateHeader = (e) => {
            e.stopPropagation();
            const { setActiveZone } = this.mouContext;
            const pageNum = this.getPageIndex() + 1;
            if (setActiveZone) setActiveZone('header', pageNum);
        };
        this.headerEl.addEventListener('click', activateHeader);
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
        WordSignatureBlock,
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
     * Matches if hovering within 7px of bottom edge of row i, or top edge of row i+1.
     */
    function findRowResizeCandidate(tableEl, clientY) {
        if (!tableEl) return null;
        const trs = Array.from(tableEl.querySelectorAll('tr'));
        if (!trs.length) return null;
        const hitTolerancePx = 7;

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
     * Interactive Row Height & Whole-Table Width Drag Resizing
     * Works inside Body, Header, AND Footer editors, dispatching real ProseMirror transactions.
     */
    function attachInteractiveTableResizers(mountContainer, getEditorInstance) {
        if (!mountContainer) return;

        let dragMode = null; // 'row-height' | 'table-width'
        let targetRowEl = null;
        let targetTableEl = null;
        let startRowIndex = -1;
        let currentCalculatedHeightPt = null;
        let currentCalculatedWidthPt = null;
        let startY = 0;
        let startX = 0;
        let startHeightPx = 0;
        let startWidthPx = 0;
        let minContentHeightPx = 18;

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

            let table = e.target.closest('table');
            if (!table) {
                const el = document.elementFromPoint(e.clientX, e.clientY);
                table = el ? el.closest('table') : null;
            }
            if (table) {
                const resizeCandidate = findRowResizeCandidate(table, e.clientY);
                if (resizeCandidate) {
                    mountContainer.style.cursor = 'row-resize';
                    return;
                }
            }
            if (mountContainer.style.cursor === 'row-resize') {
                mountContainer.style.cursor = '';
            }
        }, { capture: true });

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
                    e.stopImmediatePropagation?.();
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

            // 2. Check row border drag across whole table
            let table = e.target.closest('table');
            if (!table) {
                const el = document.elementFromPoint(e.clientX, e.clientY);
                table = el ? el.closest('table') : null;
            }
            if (table) {
                const resizeCandidate = findRowResizeCandidate(table, e.clientY);
                if (resizeCandidate) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation?.();
                    dragMode = 'row-height';
                    targetRowEl = resizeCandidate.tr;
                    targetTableEl = table;
                    startRowIndex = resizeCandidate.rowIndex;
                    startY = e.clientY;
                    const scale = (state.zoomPercent || 100) / 100;
                    startHeightPx = resizeCandidate.rect.height / scale;
                    minContentHeightPx = computeRowMinContentHeightPx(targetRowEl, scale);
                    currentCalculatedHeightPt = Math.round((startHeightPx * 72) / 96);

                    guideLine.classList.remove('hidden');
                    guideLine.style.top = `${e.clientY}px`;
                    guideLine.style.left = `${resizeCandidate.rect.left}px`;
                    guideLine.style.width = `${resizeCandidate.rect.width}px`;

                    guideBadge.textContent = `Tinggi Baris: ${currentCalculatedHeightPt} pt (${(startHeightPx / MM_TO_PX).toFixed(1)} mm)`;
                    guideBadge.style.backgroundColor = '#1d4ed8';
                    document.body.style.cursor = 'row-resize';
                }
            }
        }, { capture: true });

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
            } else if (dragMode === 'table-width' && editor && currentCalculatedWidthPt !== null) {
                const finalTableWidth = `${currentCalculatedWidthPt}pt`;
                applyTableWidthTransaction(editor, targetTableEl, finalTableWidth);
            }

            dragMode = null;
            targetRowEl = null;
            targetTableEl = null;
            startRowIndex = -1;
            currentCalculatedHeightPt = null;
            currentCalculatedWidthPt = null;
            scheduleSyncAndPagination();
        }, { capture: true });
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

            watermarkEl.innerHTML = renderWatermarkLayerHtml(state, isFirstPage);

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

                // 12px tolerance buffer so minor padding/cursor adjustments never cause spurious page breaks
                if (childBottom > usableHeight + 12) {
                    const tableEl = childEl.querySelector('table') || (childEl.tagName === 'TABLE' ? childEl : null);
                    if (tableEl) {
                        const trs = Array.from(tableEl.querySelectorAll('tr'));
                        if (trs.length > 1) {
                            let splitRow = -1;
                            for (let r = 0; r < trs.length; r++) {
                                const rRect = trs[r].getBoundingClientRect();
                                const rBottom = (rRect.bottom - contentDomRect.top) / scale;
                                if (rBottom > usableHeight + 12) {
                                    splitRow = r;
                                    break;
                                }
                            }
                            if (splitRow > 0) {
                                splitAction = {
                                    type: 'split_table',
                                    pageIndex: pIdx,
                                    nodeIndex: cIdx,
                                    splitRowIndex: splitRow
                                };
                                needsReflow = true;
                                break;
                            }
                        }
                    }
                    overflowingIndex = cIdx;
                    break;
                }
            }

            if (overflowingIndex !== -1 && overflowingIndex < pageNode.childCount) {
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

                while (finalSplitIndex > 0 && isHeadingNode(pageNode.child(finalSplitIndex - 1))) {
                    finalSplitIndex--;
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

        if (action.type === 'split_table') {
            const { pageIndex, nodeIndex, splitRowIndex } = action;
            const pageNode = doc.child(pageIndex);
            if (nodeIndex >= pageNode.childCount) return;
            const tableNode = pageNode.child(nodeIndex);
            if (!tableNode || tableNode.type.name !== 'table' || splitRowIndex <= 0 || splitRowIndex >= tableNode.childCount) return;

            const table1Rows = [];
            const table2Rows = [];
            for (let r = 0; r < tableNode.childCount; r++) {
                if (r < splitRowIndex) table1Rows.push(tableNode.child(r));
                else table2Rows.push(tableNode.child(r));
            }
            if (table1Rows.length === 0 || table2Rows.length === 0) return;

            const table1Node = edState.schema.nodes.table.create(tableNode.attrs, table1Rows);
            const table2Node = edState.schema.nodes.table.create(tableNode.attrs, table2Rows);

            let pagePos = 0;
            for (let i = 0; i < pageIndex; i++) pagePos += doc.child(i).nodeSize;
            let nodeOffset = 0;
            for (let c = 0; c < nodeIndex; c++) nodeOffset += pageNode.child(c).nodeSize;

            const tableStartPos = pagePos + 1 + nodeOffset;
            const tableEndPos = tableStartPos + tableNode.nodeSize;

            const followingNodes = [];
            for (let c = nodeIndex + 1; c < pageNode.childCount; c++) {
                followingNodes.push(pageNode.child(c));
            }

            const pageEndPos = pagePos + 1 + pageNode.content.size;
            tr.delete(tableStartPos, pageEndPos);
            tr.insert(tableStartPos, table1Node);

            const nodesToMove = [table2Node, ...followingNodes];

            if (pageIndex + 1 < doc.childCount) {
                let nextPagePos = 0;
                for (let i = 0; i < pageIndex + 1; i++) nextPagePos += doc.child(i).nodeSize;
                const mappedNextPageStart = tr.mapping.map(nextPagePos + 1);
                tr.insert(mappedNextPageStart, nodesToMove);
            } else {
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
            return;
        }

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

        out = out.replace(/(src=["'])\{\{\s*(?:asset\.(?:brand\.)?tanda_tangan|asset\.signature)\s*\}\}(["'])/gi, `$1${sigSrc}$2`);
        out = out.replace(/(src=["'])\{\{\s*(?:asset\.(?:brand\.)?stempel|asset\.stamp)\s*\}\}(["'])/gi, `$1${stampSrc}$2`);

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

                // Watermark & Fixed Header Logo Layer
                const watermarkHtml = renderWatermarkLayerHtml(state, pageIndex === 1);
                if (watermarkHtml) {
                    const watermarkEl = document.createElement('div');
                    watermarkEl.className = 'mou-page-watermark-layer';
                    watermarkEl.innerHTML = watermarkHtml;
                    sheet.appendChild(watermarkEl);
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
        toggleBulletList: () => {
            const ed = getActiveEditor();
            if (!ed) return;
            ed.chain().focus().toggleBulletList().run();
            updateToolbarState();
        },
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
            updateToolbarState();
        },
        adjustIndent: (deltaMm) => {
            const ed = getActiveEditor();
            if (!ed) return;
            if (ed.isActive('listItem')) {
                if (deltaMm > 0) {
                    ed.chain().focus().sinkListItem('listItem').run();
                } else {
                    ed.chain().focus().liftListItem('listItem').run();
                }
                return;
            }
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
        setCurrentRowHeight,
        setCurrentTableWidth,
        setCurrentTableAlign,
        setCurrentColumnWidth,
        adjustRowHeightSpin,
        adjustColWidthSpin,
        adjustTableWidthSpin,
        adjustFontSizeSpin,
        setZoomPercent,
        async importDocx(fileOrBuffer, mode = 'replace') {
            const result = await importDocxFile(fileOrBuffer);
            if (mode === 'replace') {
                bodyEditor.commands.setContent(normalizeHtmlForBodyEditor(result.html));
                scheduleReflow(true);
                scheduleSyncAndPagination();
            } else {
                bodyEditor.chain().focus().insertContent(result.html).run();
                scheduleReflow(true);
                scheduleSyncAndPagination();
            }
            return result;
        },
        async exportDocx(filename = 'template.docx') {
            const pageConfig = {
                paper_size: state.paperSize,
                orientation: state.orientation,
                margin_top_mm: state.marginTopMm,
                margin_bottom_mm: state.marginBottomMm,
                margin_left_mm: state.marginLeftMm,
                margin_right_mm: state.marginRightMm,
            };
            const docxBlob = await exportProseMirrorJsonToDocx(bodyEditor.getJSON(), pageConfig);
            if (typeof window !== 'undefined' && window.document) {
                downloadDocxBlob(docxBlob, filename);
            }
            return docxBlob;
        },
        updateActiveTableCells,
        applyTablePreset(preset) {
            const editor = getActiveEditor();
            if (!editor) return;
            const { state: edState } = editor;
            const { $from } = edState.selection;

            let targetTable = null;
            let tablePos = null;
            for (let d = $from.depth; d >= 0; d--) {
                const n = $from.node(d);
                if (n.type.name === 'table') {
                    targetTable = n;
                    tablePos = $from.before(d);
                    break;
                }
            }
            if (!targetTable || tablePos === null) return;

            let tableClass = 'mou-table-borderless';
            let cellAttrs = { borderStyle: 'none', borderWidth: '0px', borderColor: 'transparent' };
            let newTableWidth = targetTable.attrs.tableWidth || '100%';

            if (preset === 'bordered' || preset === 'grid') {
                tableClass = 'mou-table-bordered';
                cellAttrs = { borderStyle: 'solid', borderWidth: '1px', borderColor: '#000000' };
            } else if (preset === 'borderless') {
                tableClass = 'mou-table-borderless';
                cellAttrs = { borderStyle: 'none', borderWidth: '0px', borderColor: 'transparent' };
            } else if (preset === 'paraf') {
                tableClass = 'mou-paraf-table';
                newTableWidth = '220pt';
                cellAttrs = { borderStyle: 'solid', borderWidth: '1px', borderColor: '#000000', padding: '2pt 4pt' };
            }

            const tr = edState.tr;
            tr.setNodeMarkup(tablePos, undefined, {
                ...targetTable.attrs,
                class: tableClass,
                tableWidth: newTableWidth,
            });

            let rowOffset = tablePos + 1;
            for (let r = 0; r < targetTable.childCount; r++) {
                const row = targetTable.child(r);
                let cellOffset = rowOffset + 1;
                for (let c = 0; c < row.childCount; c++) {
                    const cell = row.child(c);
                    tr.setNodeMarkup(cellOffset, undefined, {
                        ...cell.attrs,
                        ...cellAttrs,
                    });
                    cellOffset += cell.nodeSize;
                }
                rowOffset += row.nodeSize;
            }

            editor.view.dispatch(tr);
            scheduleSyncAndPagination();
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

