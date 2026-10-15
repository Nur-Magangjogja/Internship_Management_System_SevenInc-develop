/**
 * Node.js Isolated Unit & Integration Test for SertifGoogleIntegration.js (S17.18D.3)
 * Tests sidecar tab (2026_Custom_Fields) contracts and identity verification without live HTTP.
 */

const assert = require('assert');
const path = require('path');

// 1. Mock Google Apps Script Runtime Environment
class MockRange {
  constructor(sheet, row, col, numRows = 1, numCols = 1) {
    this.sheet = sheet;
    this.row = row;
    this.col = col;
    this.numRows = numRows;
    this.numCols = numCols;
  }

  getValues() {
    const result = [];
    for (let r = 0; r < this.numRows; r++) {
      const rowArr = [];
      for (let c = 0; c < this.numCols; c++) {
        const val = this.sheet._getCell(this.row + r, this.col + c);
        rowArr.push(val);
      }
      result.push(rowArr);
    }
    return result;
  }

  getValue() {
    return this.sheet._getCell(this.row, this.col);
  }

  setValues(values) {
    for (let r = 0; r < values.length; r++) {
      for (let c = 0; c < values[r].length; c++) {
        this.sheet._setCell(this.row + r, this.col + c, values[r][c]);
      }
    }
    return this;
  }

  setValue(value) {
    this.sheet._setCell(this.row, this.col, value);
    return this;
  }
}

class MockSheet {
  constructor(name) {
    this.name = name;
    this.grid = {}; // 'r,c' => val
    this.maxRow = 0;
    this.maxCol = 0;
  }

  getName() { return this.name; }

  getLastRow() { return this.maxRow; }

  getLastColumn() { return this.maxCol; }

  _getCell(r, c) {
    const k = `${r},${c}`;
    return this.grid.hasOwnProperty(k) ? this.grid[k] : '';
  }

  _setCell(r, c, val) {
    this.grid[`${r},${c}`] = val;
    if (r > this.maxRow) this.maxRow = r;
    if (c > this.maxCol) this.maxCol = c;
  }

  getRange(row, col, numRows = 1, numCols = 1) {
    return new MockRange(this, row, col, numRows, numCols);
  }
}

class MockSpreadsheet {
  constructor(id) {
    this.id = id;
    this.sheets = {};
  }

  getSheetByName(name) {
    return this.sheets[name] || null;
  }

  insertSheet(name) {
    const s = new MockSheet(name);
    this.sheets[name] = s;
    return s;
  }
}

const mockSpreadsheets = {};

global.SpreadsheetApp = {
  openById: function(id) {
    if (!mockSpreadsheets[id]) {
      mockSpreadsheets[id] = new MockSpreadsheet(id);
    }
    return mockSpreadsheets[id];
  }
};

const crypto = require('crypto');

let mockLockAcquireSuccess = true;
let mockLockHasLock = true;

global.LockService = {
  getScriptLock: function() {
    return {
      tryLock: function(ms) { return mockLockAcquireSuccess; },
      waitLock: function(ms) {
        if (!mockLockAcquireSuccess) throw new Error("Could not obtain lock in " + ms + "ms");
        return true;
      },
      hasLock: function() { return mockLockHasLock; },
      releaseLock: function() {
        if (!mockLockHasLock) throw new Error("You do not hold the lock.");
        return true;
      }
    };
  }
};

const mockCacheStore = {};
global.CacheService = {
  getScriptCache: function() {
    return {
      get: function(key) { return mockCacheStore.hasOwnProperty(key) ? mockCacheStore[key] : null; },
      put: function(key, val, ttl) { mockCacheStore[key] = val; }
    };
  }
};

global.Utilities = {
  Charset: { UTF_8: 'UTF-8' },
  computeHmacSha256Signature: function(value, key, charset) {
    const hmac = crypto.createHmac('sha256', key);
    hmac.update(value, 'utf8');
    const digest = hmac.digest();
    const bytes = [];
    for (let i = 0; i < digest.length; i++) {
      let b = digest[i];
      if (b > 127) b -= 256;
      bytes.push(b);
    }
    return bytes;
  }
};

global.PropertiesService = {
  getScriptProperties: function() {
    return {
      getProperty: function(key) {
        if (key === 'SHARED_API_KEY') return 'test-shared-secret-key-12345';
        if (key === 'REGISTRATION_SPREADSHEET_ID') return 'mock-reg-sheet-id';
        if (key === 'FOLLOWUP_SPREADSHEET_ID') return 'mock-fwu-sheet-id';
        return null;
      }
    };
  }
};

global.ContentService = {
  MimeType: { JSON: 'application/json' },
  createTextOutput: function(text) {
    return {
      setMimeType: function(mime) { return this; },
      getContent: () => text,
      getData: () => JSON.parse(text)
    };
  }
};

function signRequest(payload, secret = 'test-shared-secret-key-12345', overrideTimestamp = null, overrideNonce = null) {
  const timestamp = overrideTimestamp !== null ? overrideTimestamp : Math.floor(Date.now() / 1000);
  const nonce = overrideNonce !== null ? overrideNonce : 'nonce_' + Math.random().toString(36).substring(2, 12);
  const canonicalData = timestamp + '.' + nonce + '.' + JSON.stringify(payload);
  const signature = crypto.createHmac('sha256', secret).update(canonicalData, 'utf8').digest('hex');
  return {
    timestamp: timestamp.toString(),
    nonce: nonce,
    signature: signature,
    payload: payload
  };
}

// Require SertifGoogleIntegration module
const gateway = require('../resources/apps-script/SertifGoogleIntegration.js');

console.log('--- STARTING SIDECAR (2026_Custom_Fields) GATEWAY TESTS ---');

function runTest(name, fn) {
  try {
    fn();
    console.log(`[PASS] ${name}`);
  } catch (err) {
    console.error(`[FAIL] ${name}:`, err.message);
    process.exit(1);
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1: ensure_extension_columns creates sheet & base columns A-D + custom E+
// ─────────────────────────────────────────────────────────────────────────────
runTest('ensure_extension_columns initializes base columns and appends new fields', () => {
  const ssId = 'test-sheet-1';
  const payload = {
    spreadsheet_id: ssId,
    sheet_name: '2026_Custom_Fields',
    columns: ['portfolio_url', 'custom_bio']
  };

  const res = gateway.handleEnsureExtensionColumnsAction(payload).getData();
  assert.strictEqual(res.success, true);
  assert.strictEqual(res.sheet_name, '2026_Custom_Fields');
  assert.strictEqual(res.total_columns, 6); // 4 base + 2 custom
  assert.deepStrictEqual(res.headers, [
    'SERTIF_SYNC_ID',
    'SERTIF_REGISTRATION_ID',
    'FORM_SCHEMA_VERSION',
    'SERTIF_LAST_SYNC_AT',
    'portfolio_url',
    'custom_bio'
  ]);
  assert.deepStrictEqual(res.added_columns, ['portfolio_url', 'custom_bio']);

  // Call again with an existing and a new column -> idempotency
  const res2 = gateway.handleEnsureExtensionColumnsAction({
    spreadsheet_id: ssId,
    sheet_name: '2026_Custom_Fields',
    columns: ['portfolio_url', 'github_profile']
  }).getData();

  assert.strictEqual(res2.success, true);
  assert.strictEqual(res2.total_columns, 7);
  assert.deepStrictEqual(res2.added_columns, ['github_profile']);
  assert.strictEqual(res2.headers[6], 'github_profile');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2: upsert_extension_row fails closed if Main row is missing
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row fails closed when main row is missing', () => {
  const ssId = 'test-sheet-2';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  // Only header in main
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');

  const payload = {
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-100-abc12345',
    registration_id: '100',
    form_schema_version: '2026.01',
    last_sync_at: '2026-10-13T10:00:00Z',
    answers: { portfolio_url: 'https://example.com' }
  };

  const res = gateway.handleUpsertExtensionRowAction(payload).getData();
  assert.strictEqual(res.success, false);
  assert.strictEqual(res.error_code, 'MAIN_ROW_NOT_FOUND');

  // Verify extension sheet did not insert any row
  const extSheet = ss.getSheetByName('2026_Custom_Fields');
  if (extSheet) {
    assert.strictEqual(extSheet.getLastRow(), 1); // Only header
  }
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3: upsert_extension_row fails closed on Main duplicate sync ID
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row fails closed on duplicate main sync ID', () => {
  const ssId = 'test-sheet-3';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');

  // Duplicate rows in main
  mainSheet.getRange(2, 26).setValue('REG-200-dup');
  mainSheet.getRange(2, 27).setValue('200');
  mainSheet.getRange(3, 26).setValue('REG-200-dup');
  mainSheet.getRange(3, 27).setValue('200');

  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    sync_id: 'REG-200-dup',
    registration_id: '200',
    answers: {}
  }).getData();

  assert.strictEqual(res.success, false);
  assert.strictEqual(res.error_code, 'DUPLICATE_MAIN_SYNC_ID');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4: upsert_extension_row fails closed on Identity Mismatch
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row fails closed on registration ID mismatch', () => {
  const ssId = 'test-sheet-4';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');

  mainSheet.getRange(2, 26).setValue('REG-300-sync');
  mainSheet.getRange(2, 27).setValue('300'); // Main says 300

  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    sync_id: 'REG-300-sync',
    registration_id: '999', // Mismatched payload says 999
    answers: {}
  }).getData();

  assert.strictEqual(res.success, false);
  assert.strictEqual(res.error_code, 'IDENTITY_MISMATCH');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5: Exact one-match insert on Extension sheet
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row inserts new sidecar row when exact one main row matches', () => {
  const ssId = 'test-sheet-5';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');

  mainSheet.getRange(2, 26).setValue('REG-500-sync');
  mainSheet.getRange(2, 27).setValue('500');

  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-500-sync',
    registration_id: '500',
    form_schema_version: '2026.01',
    last_sync_at: '2026-10-13T12:00:00Z',
    answers: {
      portfolio_url: 'https://github.com/test500',
      custom_bio: 'Mahasiswa Informatika'
    }
  }).getData();

  assert.strictEqual(res.success, true);
  assert.strictEqual(res.action, 'inserted');
  assert.strictEqual(res.row_index, 2);

  const extSheet = ss.getSheetByName('2026_Custom_Fields');
  assert.strictEqual(extSheet.getRange(2, 1).getValue(), 'REG-500-sync');
  assert.strictEqual(extSheet.getRange(2, 2).getValue(), '500');
  assert.strictEqual(extSheet.getRange(2, 3).getValue(), '2026.01');
  assert.strictEqual(extSheet.getRange(2, 4).getValue(), '2026-10-13T12:00:00Z');
  assert.strictEqual(extSheet.getRange(2, 5).getValue(), 'https://github.com/test500');
  assert.strictEqual(extSheet.getRange(2, 6).getValue(), 'Mahasiswa Informatika');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6: Exact one-match update on Extension sheet
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row updates existing sidecar row without duplicating', () => {
  const ssId = 'test-sheet-5'; // Reuse sheet 5 where row 2 is already inserted
  const ss = SpreadsheetApp.openById(ssId);

  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-500-sync',
    registration_id: '500',
    form_schema_version: '2026.02',
    last_sync_at: '2026-10-13T14:30:00Z',
    answers: {
      portfolio_url: 'https://updated.com/500',
      custom_bio: 'Profil Diperbarui'
    }
  }).getData();

  assert.strictEqual(res.success, true);
  assert.strictEqual(res.action, 'updated');
  assert.strictEqual(res.row_index, 2);

  const extSheet = ss.getSheetByName('2026_Custom_Fields');
  assert.strictEqual(extSheet.getLastRow(), 2); // Row count must still be 2 (header + 1 row)
  assert.strictEqual(extSheet.getRange(2, 3).getValue(), '2026.02');
  assert.strictEqual(extSheet.getRange(2, 4).getValue(), '2026-10-13T14:30:00Z');
  assert.strictEqual(extSheet.getRange(2, 5).getValue(), 'https://updated.com/500');
  assert.strictEqual(extSheet.getRange(2, 6).getValue(), 'Profil Diperbarui');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7: inspect_sidecar_alignment accurately detects status
// ─────────────────────────────────────────────────────────────────────────────
runTest('inspect_sidecar_alignment accurately detects aligned, missing, and orphaned status', () => {
  const ssId = 'test-sheet-7';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');

  mainSheet.getRange(2, 26).setValue('REG-701-aligned');
  mainSheet.getRange(2, 27).setValue('701');
  mainSheet.getRange(3, 26).setValue('REG-702-missing-sidecar');
  mainSheet.getRange(3, 27).setValue('702');

  const extSheet = ss.insertSheet('2026_Custom_Fields');
  extSheet.getRange(1, 1).setValue('SERTIF_SYNC_ID');
  extSheet.getRange(1, 2).setValue('SERTIF_REGISTRATION_ID');

  extSheet.getRange(2, 1).setValue('REG-701-aligned');
  extSheet.getRange(2, 2).setValue('701');
  extSheet.getRange(3, 1).setValue('REG-703-orphan');
  extSheet.getRange(3, 2).setValue('703');

  const rAligned = gateway.handleInspectSidecarAlignmentAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-701-aligned'
  }).getData();
  assert.strictEqual(rAligned.status, 'aligned');

  const rMissing = gateway.handleInspectSidecarAlignmentAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-702-missing-sidecar'
  }).getData();
  assert.strictEqual(rMissing.status, 'missing_sidecar');

  const rOrphan = gateway.handleInspectSidecarAlignmentAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-703-orphan'
  }).getData();
  assert.strictEqual(rOrphan.status, 'orphaned_sidecar');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8: Duplicate sidecar SERTIF_SYNC_ID triggers 422 DUPLICATE_SIDECAR_SYNC_ID
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row fails closed with DUPLICATE_SIDECAR_SYNC_ID when sidecar sheet has duplicates', () => {
  const ssId = 'test-sheet-8';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');
  mainSheet.getRange(2, 26).setValue('REG-800-dup-sidecar');
  mainSheet.getRange(2, 27).setValue('800');

  const extSheet = ss.insertSheet('2026_Custom_Fields');
  extSheet.getRange(1, 1).setValue('SERTIF_SYNC_ID');
  extSheet.getRange(1, 2).setValue('SERTIF_REGISTRATION_ID');
  // Two duplicate rows in extension
  extSheet.getRange(2, 1).setValue('REG-800-dup-sidecar');
  extSheet.getRange(2, 2).setValue('800');
  extSheet.getRange(3, 1).setValue('REG-800-dup-sidecar');
  extSheet.getRange(3, 2).setValue('800');

  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-800-dup-sidecar',
    registration_id: '800',
    answers: {}
  }).getData();

  assert.strictEqual(res.success, false);
  assert.strictEqual(res.error_code, 'DUPLICATE_SIDECAR_SYNC_ID');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 9: Full doPost HMAC signature validation & replay attack protection
// ─────────────────────────────────────────────────────────────────────────────
runTest('doPost enforces valid HMAC signature, rejects expired timestamps and replayed nonces', () => {
  const ssId = 'test-sheet-9';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');
  mainSheet.getRange(2, 26).setValue('REG-900-hmac');
  mainSheet.getRange(2, 27).setValue('900');

  const actionPayload = {
    action: 'upsert_extension_row',
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-900-hmac',
    registration_id: '900',
    form_schema_version: '2026.01',
    answers: { portfolio_url: 'https://valid-hmac.example.com' }
  };

  // 1. Valid signed request via doPost
  const signedReq = signRequest(actionPayload);
  const out1 = gateway.doPost({ postData: { contents: JSON.stringify(signedReq) } });
  const res1 = out1.getData();
  assert.strictEqual(res1.success, true);
  assert.strictEqual(res1.action, 'inserted');

  // 2. Replay attack: resending the EXACT same signed payload (same nonce)
  const out2 = gateway.doPost({ postData: { contents: JSON.stringify(signedReq) } });
  const res2 = out2.getData();
  assert.strictEqual(res2.success, false);
  assert.strictEqual(res2.error_code, 'REPLAYED_NONCE');

  // 3. Invalid signature: altered payload or tampered signature
  const tamperedReq = signRequest(actionPayload);
  tamperedReq.signature = 'badbeef' + tamperedReq.signature.substring(7);
  const out3 = gateway.doPost({ postData: { contents: JSON.stringify(tamperedReq) } });
  const res3 = out3.getData();
  assert.strictEqual(res3.success, false);
  assert.strictEqual(res3.error_code, 'INVALID_SIGNATURE');

  // 4. Expired request: timestamp drift > 300 seconds
  const expiredReq = signRequest(actionPayload, 'test-shared-secret-key-12345', Math.floor(Date.now() / 1000) - 400);
  const out4 = gateway.doPost({ postData: { contents: JSON.stringify(expiredReq) } });
  const res4 = out4.getData();
  assert.strictEqual(res4.success, false);
  assert.strictEqual(res4.error_code, 'EXPIRED_REQUEST');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 10: Reserved header collision & Cols A-D tamper protection
// ─────────────────────────────────────────────────────────────────────────────
runTest('ensure_extension_columns and upsert_extension_row protect Cols A-D from header collisions and overwrites', () => {
  const ssId = 'test-sheet-10';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');
  mainSheet.getRange(2, 26).setValue('REG-1000-reserved');
  mainSheet.getRange(2, 27).setValue('1000');

  // Try to inject reserved base columns in ensure_extension_columns
  const resEnsure = gateway.handleEnsureExtensionColumnsAction({
    spreadsheet_id: ssId,
    sheet_name: '2026_Custom_Fields',
    columns: ['sertif_sync_id', 'SERTIF_REGISTRATION_ID', 'FORM_SCHEMA_VERSION', 'SERTIF_LAST_SYNC_AT', 'custom_allowed_key']
  }).getData();

  assert.strictEqual(resEnsure.success, true);
  assert.strictEqual(resEnsure.total_columns, 5); // 4 base + 1 valid custom, NOT 4 + 4 + 1
  assert.deepStrictEqual(resEnsure.added_columns, ['custom_allowed_key']);

  // Try to overwrite reserved Cols A-D via custom answers in upsert_extension_row
  const resUpsert = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-1000-reserved',
    registration_id: '1000',
    form_schema_version: '2026.01',
    last_sync_at: '2026-10-13T12:00:00Z',
    answers: {
      'SERTIF_SYNC_ID': 'ATTACK_SYNC_ID',
      'SERTIF_REGISTRATION_ID': 'ATTACK_REG_ID',
      'FORM_SCHEMA_VERSION': 'ATTACK_VERSION',
      'SERTIF_LAST_SYNC_AT': 'ATTACK_TIME',
      'custom_allowed_key': 'Safe Value'
    }
  }).getData();

  assert.strictEqual(resUpsert.success, true);

  const extSheet = ss.getSheetByName('2026_Custom_Fields');
  // Verify Cols A-D are NOT overwritten by malicious answers
  assert.strictEqual(extSheet.getRange(2, 1).getValue(), 'REG-1000-reserved');
  assert.strictEqual(extSheet.getRange(2, 2).getValue(), '1000');
  assert.strictEqual(extSheet.getRange(2, 3).getValue(), '2026.01');
  assert.strictEqual(extSheet.getRange(2, 4).getValue(), '2026-10-13T12:00:00Z');
  assert.strictEqual(extSheet.getRange(2, 5).getValue(), 'Safe Value');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 11: Lock unavailable / lock timeout fail-closed (HTTP 429 LOCK_TIMEOUT)
// ─────────────────────────────────────────────────────────────────────────────
runTest('handleEnsureExtensionColumnsAction and handleUpsertExtensionRowAction fail closed on lock timeout', () => {
  mockLockAcquireSuccess = false;

  const resEnsure = gateway.handleEnsureExtensionColumnsAction({
    spreadsheet_id: 'test-sheet-11',
    columns: ['custom_key']
  }).getData();
  assert.strictEqual(resEnsure.success, false);
  assert.strictEqual(resEnsure.error_code, 'LOCK_TIMEOUT');

  const resUpsert = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: 'test-sheet-11',
    sync_id: 'REG-1100',
    registration_id: '1100',
    answers: {}
  }).getData();
  assert.strictEqual(resUpsert.success, false);
  assert.strictEqual(resUpsert.error_code, 'LOCK_TIMEOUT');

  // Restore lock acquisition
  mockLockAcquireSuccess = true;
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 12: Idempotent retry after simulated response timeout
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row allows safe idempotent retry without duplicating rows', () => {
  const ssId = 'test-sheet-12';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');
  mainSheet.getRange(2, 26).setValue('REG-1200-retry');
  mainSheet.getRange(2, 27).setValue('1200');

  const payload = {
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-1200-retry',
    registration_id: '1200',
    form_schema_version: '2026.01',
    last_sync_at: '2026-10-13T12:00:00Z',
    answers: { key_a: 'Val A' }
  };

  // First execution: inserted
  const res1 = gateway.handleUpsertExtensionRowAction(payload).getData();
  assert.strictEqual(res1.success, true);
  assert.strictEqual(res1.action, 'inserted');
  assert.strictEqual(res1.row_index, 2);

  // Simulated retry (caller didn't receive ACK): updated
  const res2 = gateway.handleUpsertExtensionRowAction(payload).getData();
  assert.strictEqual(res2.success, true);
  assert.strictEqual(res2.action, 'updated');
  assert.strictEqual(res2.row_index, 2);

  const extSheet = ss.getSheetByName('2026_Custom_Fields');
  assert.strictEqual(extSheet.getLastRow(), 2); // Still strictly 1 data row!
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 13: Identity Mismatch on existing sidecar row (422 IDENTITY_MISMATCH)
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row detects identity mismatch when existing sidecar row registration ID differs', () => {
  const ssId = 'test-sheet-13';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');
  mainSheet.getRange(2, 26).setValue('REG-1300-mismatch');
  mainSheet.getRange(2, 27).setValue('1300');

  const extSheet = ss.insertSheet('2026_Custom_Fields');
  extSheet.getRange(1, 1).setValue('SERTIF_SYNC_ID');
  extSheet.getRange(1, 2).setValue('SERTIF_REGISTRATION_ID');
  // Existing row in extension has registration ID 9999
  extSheet.getRange(2, 1).setValue('REG-1300-mismatch');
  extSheet.getRange(2, 2).setValue('9999');

  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-1300-mismatch',
    registration_id: '1300', // matches main, but clashes with existing sidecar row
    answers: {}
  }).getData();

  assert.strictEqual(res.success, false);
  assert.strictEqual(res.error_code, 'IDENTITY_MISMATCH');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 14: Zero overwrite of unmanaged rows
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row preserves unmanaged rows and unrelated rows untouched', () => {
  const ssId = 'test-sheet-14';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');
  mainSheet.getRange(2, 26).setValue('REG-1401');
  mainSheet.getRange(2, 27).setValue('1401');
  mainSheet.getRange(3, 26).setValue('REG-1402');
  mainSheet.getRange(3, 27).setValue('1402');

  const extSheet = ss.insertSheet('2026_Custom_Fields');
  extSheet.getRange(1, 1).setValue('SERTIF_SYNC_ID');
  extSheet.getRange(1, 2).setValue('SERTIF_REGISTRATION_ID');
  extSheet.getRange(1, 3).setValue('FORM_SCHEMA_VERSION');
  extSheet.getRange(1, 4).setValue('SERTIF_LAST_SYNC_AT');
  extSheet.getRange(1, 5).setValue('custom_notes');

  // Row 2 is unmanaged row
  extSheet.getRange(2, 1).setValue('REG-1401');
  extSheet.getRange(2, 2).setValue('1401');
  extSheet.getRange(2, 5).setValue('DO NOT TOUCH THIS NOTE');

  // Mutate Row 3
  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-1402',
    registration_id: '1402',
    form_schema_version: '2026.01',
    last_sync_at: '2026-10-13T12:00:00Z',
    answers: { custom_notes: 'New note for 1402' }
  }).getData();

  assert.strictEqual(res.success, true);
  assert.strictEqual(res.action, 'inserted');
  assert.strictEqual(res.row_index, 3);

  // Assert Row 2 was zero-overwritten
  assert.strictEqual(extSheet.getRange(2, 1).getValue(), 'REG-1401');
  assert.strictEqual(extSheet.getRange(2, 2).getValue(), '1401');
  assert.strictEqual(extSheet.getRange(2, 5).getValue(), 'DO NOT TOUCH THIS NOTE');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 15: Legacy placeholder row creation & alignment detection
// ─────────────────────────────────────────────────────────────────────────────
runTest('legacy registration creates 1:1 placeholder row with blank version and empty answers and reports aligned', () => {
  const ssId = 'test-sheet-15';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');
  mainSheet.getRange(2, 26).setValue('REG-LEGACY-1500');
  mainSheet.getRange(2, 27).setValue('1500');

  // Upsert placeholder row
  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-LEGACY-1500',
    registration_id: '1500',
    form_schema_version: '',
    last_sync_at: '2026-10-13T15:00:00Z',
    answers: {}
  }).getData();

  assert.strictEqual(res.success, true);
  assert.strictEqual(res.action, 'inserted');
  assert.strictEqual(res.row_index, 2);

  const extSheet = ss.getSheetByName('2026_Custom_Fields');
  assert.strictEqual(extSheet.getRange(2, 1).getValue(), 'REG-LEGACY-1500');
  assert.strictEqual(extSheet.getRange(2, 2).getValue(), '1500');
  assert.strictEqual(extSheet.getRange(2, 3).getValue(), ''); // Blank version
  assert.strictEqual(extSheet.getRange(2, 4).getValue(), '2026-10-13T15:00:00Z');

  // Verify alignment report
  const rAlign = gateway.handleInspectSidecarAlignmentAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-LEGACY-1500'
  }).getData();
  assert.strictEqual(rAlign.status, 'aligned');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 16: Missing SERTIF_REGISTRATION_ID Column (422 MAIN_REG_ID_COLUMN_MISSING)
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row fails closed with MAIN_REG_ID_COLUMN_MISSING when main sheet lacks registration id column', () => {
  const ssId = 'test-sheet-16';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  // Only 25 columns, no column 26 or 27
  mainSheet.getRange(1, 1).setValue('nama_lengkap');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  // Column 27 (registration ID) is NOT set, and mainLastCol is 26
  mainSheet.getRange(2, 26).setValue('REG-1600');

  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-1600',
    registration_id: '1600',
    answers: {}
  }).getData();

  assert.strictEqual(res.success, false);
  assert.strictEqual(res.error_code, 'MAIN_REG_ID_COLUMN_MISSING');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 17: Empty Main.AA Registration ID (422 REMOTE_IDENTITY_INCOMPLETE)
// ─────────────────────────────────────────────────────────────────────────────
runTest('upsert_extension_row fails closed with REMOTE_IDENTITY_INCOMPLETE when main sheet registration id cell is empty', () => {
  const ssId = 'test-sheet-17';
  const ss = SpreadsheetApp.openById(ssId);
  const mainSheet = ss.insertSheet('2026');
  mainSheet.getRange(1, 26).setValue('SERTIF_SYNC_ID');
  mainSheet.getRange(1, 27).setValue('SERTIF_REGISTRATION_ID');
  mainSheet.getRange(2, 26).setValue('REG-1700');
  mainSheet.getRange(2, 27).setValue(''); // Empty cell!

  const res = gateway.handleUpsertExtensionRowAction({
    spreadsheet_id: ssId,
    main_sheet_name: '2026',
    extension_sheet_name: '2026_Custom_Fields',
    sync_id: 'REG-1700',
    registration_id: '1700',
    answers: {}
  }).getData();

  assert.strictEqual(res.success, false);
  assert.strictEqual(res.error_code, 'REMOTE_IDENTITY_INCOMPLETE');
});

console.log('--- ALL NODE.JS SIDECAR GATEWAY TESTS COMPLETED SUCCESSFULLY ---');

