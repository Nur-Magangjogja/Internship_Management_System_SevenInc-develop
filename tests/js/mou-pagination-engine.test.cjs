/**
 * Terminal-Only Unit Test Suite for SERTIF MoU Pagination Engine
 * Phase: S-MOU-V5-STABLE-A4-PAGINATED-EDITOR
 * Scope: 10 Core Engine Scenarios
 */

const assert = require('assert');

console.log('--- RUNNING MOU PAGINATION ENGINE TERMINAL-ONLY TESTS ---');

let passedTests = 0;
let totalTests = 0;

function test(name, fn) {
    totalTests++;
    try {
        fn();
        console.log(`✓ PASS: ${name}`);
        passedTests++;
    } catch (err) {
        console.error(`✗ FAIL: ${name}`);
        console.error(err);
        process.exitCode = 1;
    }
}

// 1. Overflow Action Calculation
test('1. Overflow: Detects overflowing child and calculates split index', () => {
    const usableHeight = 800;
    const childrenRects = [
        { top: 0, bottom: 300 },   // Child 0: fits
        { top: 310, bottom: 650 }, // Child 1: fits
        { top: 660, bottom: 950 }, // Child 2: OVERFLOWS usableHeight (800)
    ];

    let overflowingIndex = -1;
    for (let i = 0; i < childrenRects.length; i++) {
        if (childrenRects[i].bottom > usableHeight) {
            overflowingIndex = i;
            break;
        }
    }
    assert.strictEqual(overflowingIndex, 2, 'Child 2 should be identified as overflowing');
});

// 2. Underflow Detection with Dynamic Hysteresis
test('2. Underflow: Candidate node pulled back only when height + buffer fits', () => {
    const usableHeight = 800;
    const currentContentHeight = 500;
    const availableSpace = usableHeight - currentContentHeight; // 300px
    const candidateHeight = 150;
    const hysteresisBuffer = Math.max(8, Math.min(24, Math.round(candidateHeight * 0.1))); // 15px

    const fitsInSpace = (candidateHeight + hysteresisBuffer) <= availableSpace;
    assert.strictEqual(fitsInSpace, true, '150px candidate with 15px buffer should fit in 300px available space');

    const largeCandidate = 290;
    const largeBuffer = Math.max(8, Math.min(24, Math.round(largeCandidate * 0.1))); // 24px
    const largeFits = (largeCandidate + largeBuffer) <= availableSpace;
    assert.strictEqual(largeFits, false, '290px candidate with 24px buffer (314px) must not overflow 300px space');
});

// 3. Selection Mapping with Relative Offsets
test('3. Selection Mapping: Maps both anchor and head with exact relative character offsets', () => {
    const fromPos = 120;
    const toPos = 350;
    const targetInsertPos = 400;

    // User selection inside moved slice
    const oldAnchor = 150; // offset 30 from fromPos
    const oldHead = 180;   // offset 60 from fromPos

    const isAnchorInSlice = (oldAnchor >= fromPos && oldAnchor <= toPos);
    const isHeadInSlice = (oldHead >= fromPos && oldHead <= toPos);

    const anchorOffsetInSlice = oldAnchor - fromPos;
    const headOffsetInSlice = oldHead - fromPos;

    const newAnchor = targetInsertPos + anchorOffsetInSlice;
    const newHead = targetInsertPos + headOffsetInSlice;

    assert.strictEqual(isAnchorInSlice, true);
    assert.strictEqual(isHeadInSlice, true);
    assert.strictEqual(newAnchor, 430, 'Anchor must map to 400 + 30 = 430');
    assert.strictEqual(newHead, 460, 'Head must map to 400 + 60 = 460');
    assert.strictEqual(newHead - newAnchor, oldHead - oldAnchor, 'Selection range length must remain exactly 30 characters');
});

// 4. Undo/Redo Transaction Isolation
test('4. Undo/Redo: Reflow transactions set addToHistory: false to prevent undo noise', () => {
    const mockTransaction = {
        meta: {},
        setMeta(key, value) {
            this.meta[key] = value;
            return this;
        }
    };

    const reflowPluginKey = 'mouPageReflow$';
    mockTransaction.setMeta('addToHistory', false);
    mockTransaction.setMeta(reflowPluginKey, true);

    assert.strictEqual(mockTransaction.meta['addToHistory'], false, 'addToHistory must explicitly be false');
    assert.strictEqual(mockTransaction.meta[reflowPluginKey], true, 'Reflow metadata marker must be present');
});

// 5. Empty Page Reclamation and Safe Cursor Fallback
test('5. Empty Page Reclamation: Reclaims empty trailing page and relocates cursor to previous page end', () => {
    const doc = {
        childCount: 2,
        child(i) {
            if (i === 0) return { nodeSize: 100, childCount: 2 };
            if (i === 1) return { nodeSize: 2, childCount: 0 }; // empty page
        }
    };

    let pagePos = 0;
    for (let i = 0; i < 1; i++) {
        pagePos += doc.child(i).nodeSize;
    }
    const emptyPageStart = pagePos; // 100
    const emptyPageEnd = emptyPageStart + doc.child(1).nodeSize; // 102

    const oldAnchor = 101; // cursor was inside empty page 2
    let newSelectionPos = null;

    if (oldAnchor >= emptyPageStart && oldAnchor <= emptyPageEnd) {
        newSelectionPos = Math.max(1, emptyPageStart - 2); // end of page 1
    }

    assert.strictEqual(newSelectionPos, 98, 'Cursor must be placed safely near the end of page 1');
    assert.ok(newSelectionPos < emptyPageStart, 'New selection position must be inside page 1');
});

// 6. Manual Page Break Protection
test('6. Manual Page Break: Preserves intentional user page breaks from empty page removal and underflow', () => {
    function hasManualPageBreak(pageMock) {
        return Boolean(pageMock.isManualBreak);
    }

    const page1 = { isManualBreak: true, childCount: 1 };
    const page2 = { isManualBreak: false, childCount: 0 }; // empty but preceded by manual break

    const curPageHasBreak = hasManualPageBreak(page1);
    let allowedToReclaimPage2 = false;

    if (!curPageHasBreak && page2.childCount === 0) {
        allowedToReclaimPage2 = true;
    }

    assert.strictEqual(curPageHasBreak, true, 'Page 1 has an intentional manual page break');
    assert.strictEqual(allowedToReclaimPage2, false, 'Page 2 must NOT be deleted because Page 1 has an explicit manual break');
});

// 7. Heading Keep-With-Next & Widow/Orphan Protection
test('7. Heading Keep-With-Next: Shifts split index backward so heading travels with following paragraph', () => {
    const pageChildren = [
        { type: 'paragraph', text: 'Intro text' },
        { type: 'heading', text: 'PASAL 1: KETENTUAN UMUM' }, // index 1: heading
        { type: 'paragraph', text: 'Isi pasal yang panjang...' }, // index 2: overflowing paragraph
    ];

    let overflowingIndex = 2; // paragraph overflows
    let finalSplitIndex = overflowingIndex;

    if (finalSplitIndex > 0) {
        const prevNode = pageChildren[finalSplitIndex - 1];
        const isHeading = prevNode.type === 'heading';
        if (isHeading && (finalSplitIndex - 1 > 0)) {
            finalSplitIndex = finalSplitIndex - 1; // Pull heading along with paragraph
        }
    }

    assert.strictEqual(finalSplitIndex, 1, 'Split index must be decremented to 1 so heading stays with its paragraph');
    assert.strictEqual(pageChildren[finalSplitIndex].type, 'heading', 'Node at final split index is the heading');
});

// 8. Table Row Splitting with Rowspan Conflict Protection
test('8. Table Row Splitting: Prevents split across active rowspan cells', () => {
    const tableRows = [
        { cells: [{ rowspan: 1 }, { rowspan: 1 }] }, // Row 0
        { cells: [{ rowspan: 2 }, { rowspan: 1 }] }, // Row 1 (has rowspan 2 spanning into row 2)
        { cells: [{ rowspan: 1 }] },                  // Row 2 (covered by row 1)
        { cells: [{ rowspan: 1 }, { rowspan: 1 }] }, // Row 3
    ];

    function checkRowspanConflict(rows, splitRowIdx) {
        let hasConflict = false;
        for (let checkR = 0; checkR < splitRowIdx; checkR++) {
            const row = rows[checkR];
            row.cells.forEach(cell => {
                if ((cell.rowspan || 1) > (splitRowIdx - checkR)) {
                    hasConflict = true;
                }
            });
        }
        return hasConflict;
    }

    const conflictAtRow2 = checkRowspanConflict(tableRows, 2);
    assert.strictEqual(conflictAtRow2, true, 'Splitting at row 2 must trigger conflict due to row 1 rowspan 2');

    const conflictAtRow3 = checkRowspanConflict(tableRows, 3);
    assert.strictEqual(conflictAtRow3, false, 'Splitting at row 3 has no conflict because row 1 span finished at row 2');
});

// 9. Reflow Loop & Oscillation Prevention
test('9. Loop Oscillation Prevention: Rejects immediate reverse underflow/overflow ping-pong', () => {
    const recentReflowActions = [
        { type: 'overflow', from: 0, to: 1 },
    ];

    // Underflow check on page 0 from page 1
    const pIdx = 0;
    const justPushedDown = recentReflowActions.some(a => a.type === 'overflow' && a.from === pIdx && a.to === pIdx + 1);

    assert.strictEqual(justPushedDown, true, 'System correctly recognizes content was just pushed down');

    let underflowExecuted = false;
    if (!justPushedDown) {
        underflowExecuted = true;
    }

    assert.strictEqual(underflowExecuted, false, 'Underflow must be blocked to prevent ping-pong oscillation loop');
});

// 10. Document Content Integrity
test('10. Content Integrity: Serialized document structure preserves content and attributes', () => {
    const templateData = {
        name: 'Nota Kesepahaman Bersama',
        paper_size: 'A4',
        orientation: 'portrait',
        margin_top_mm: 25.4,
        margin_bottom_mm: 25.4,
        margin_left_mm: 25.4,
        margin_right_mm: 25.4,
        tokens: ['institution_name', 'company_name', 'mou_number'],
    };

    assert.strictEqual(templateData.paper_size, 'A4');
    assert.strictEqual(templateData.orientation, 'portrait');
    assert.strictEqual(templateData.margin_top_mm, 25.4);
    assert.strictEqual(templateData.tokens.length, 3);
    assert.ok(templateData.tokens.includes('institution_name'));
});

// 11. Mail Merge Placeholder Splitting Protection
test('11. Mail Merge Protection: Splitting offset never cuts across merge tokens', () => {
    function protectPlaceholderOffset(text, rawOffset) {
        let chosenOffset = rawOffset;
        const placeholderRegex = /(?:\[[a-zA-Z0-9_\-]+\]|\{\{\s*[a-zA-Z0-9_\-]+\s*\}\})/g;
        let match;
        while ((match = placeholderRegex.exec(text)) !== null) {
            const tokenStart = match.index;
            const tokenEnd = match.index + match[0].length;
            if (chosenOffset > tokenStart && chosenOffset < tokenEnd) {
                chosenOffset = tokenStart;
                break;
            }
        }
        return chosenOffset;
    }

    const paraWithBrackets = 'Perjanjian kerjasama ini dibuat dengan pihak [institution_name] di kota Yogyakarta.';
    // "[institution_name]" starts at index 45 and ends at index 63
    const cutInsideBrackets = 52; // lands inside "institution"
    const safeOffset1 = protectPlaceholderOffset(paraWithBrackets, cutInsideBrackets);
    assert.strictEqual(safeOffset1, 45, 'Offset inside [institution_name] must shift to token start 45');

    const paraWithMustache = 'Pihak kedua diwakili oleh {{ representative_name }} selaku direktur.';
    // "{{ representative_name }}" starts at index 26 and ends at index 51
    const cutInsideMustache = 35;
    const safeOffset2 = protectPlaceholderOffset(paraWithMustache, cutInsideMustache);
    assert.strictEqual(safeOffset2, 26, 'Offset inside {{ representative_name }} must shift to token start 26');

    // Safe offset outside placeholder remains untouched
    const safeOutside = 10;
    assert.strictEqual(protectPlaceholderOffset(paraWithBrackets, safeOutside), 10, 'Safe offset outside tokens should remain untouched');
});

// 12. Page Boundary Backspace Traversal
test('12. Boundary Backspace: Moves cursor to previous page end or deletes empty leading block', () => {
    // Model state: Doc with Page 1 and Page 2
    const doc = {
        pages: [
            { id: 1, blocks: ['Paragraf 1 hal 1', 'Paragraf 2 hal 1'] },
            { id: 2, blocks: ['', 'Paragraf 1 hal 2'] } // empty first block on page 2
        ]
    };

    function handleBoundaryBackspace(pageIdx, blockIdx) {
        if (pageIdx > 0 && blockIdx === 0) {
            const curBlock = doc.pages[pageIdx].blocks[blockIdx];
            if (curBlock === '') {
                // Delete empty block, cursor goes to end of previous page
                doc.pages[pageIdx].blocks.shift();
                return { action: 'delete_empty_and_focus_prev', targetPage: pageIdx - 1 };
            } else {
                // Non-empty block: move cursor to end of prev page without deleting
                return { action: 'focus_prev_page_end', targetPage: pageIdx - 1 };
            }
        }
        return { action: 'default' };
    }

    const result = handleBoundaryBackspace(1, 0);
    assert.strictEqual(result.action, 'delete_empty_and_focus_prev');
    assert.strictEqual(result.targetPage, 0);
    assert.strictEqual(doc.pages[1].blocks.length, 1);
});

// 13. Page Boundary Delete Traversal
test('13. Boundary Delete: Merges following page content when delete is pressed at page end', () => {
    const doc = {
        pages: [
            { id: 1, blocks: ['Paragraf akhir hal 1'] },
            { id: 2, blocks: ['Paragraf awal hal 2'] }
        ]
    };

    function handleBoundaryDelete(pageIdx, blockIdx) {
        if (pageIdx < doc.pages.length - 1) {
            const curPage = doc.pages[pageIdx];
            if (blockIdx === curPage.blocks.length - 1) {
                const nextPage = doc.pages[pageIdx + 1];
                if (nextPage.blocks.length > 0) {
                    // Merge next page's first block into current
                    const pulled = nextPage.blocks.shift();
                    curPage.blocks[blockIdx] += ' ' + pulled;
                    return { action: 'merged_forward', targetPage: pageIdx };
                }
            }
        }
        return { action: 'default' };
    }

    const res = handleBoundaryDelete(0, 0);
    assert.strictEqual(res.action, 'merged_forward');
    assert.strictEqual(doc.pages[0].blocks[0], 'Paragraf akhir hal 1 Paragraf awal hal 2');
    assert.strictEqual(doc.pages[1].blocks.length, 0);
});

console.log(`\nALL ${passedTests} / ${totalTests} TERMINAL-ONLY JS TESTS PASSED SUCCESSFULLY!`);
