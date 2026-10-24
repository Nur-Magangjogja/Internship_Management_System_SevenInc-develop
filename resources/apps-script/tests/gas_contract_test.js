/**
 * Test Suite for SertifGoogleIntegration.js
 * Runs with Node.js to verify contracts and handlers.
 */
const assert = require('assert');
const gateway = require('../SertifGoogleIntegration.js');

console.log("=== Running Apps Script Contract & Handler Tests ===");

// 1. Verify Contract lengths
assert.strictEqual(gateway.REG_CONTRACT_2026.length, 38, "Canonical contract must have exactly 38 slots");
assert.strictEqual(gateway.FOLLOWUP_CONTRACT.length, 21, "Followup contract must have exactly 21 slots");
console.log("✔ S1: 38-column canonical registration & 21-column followup contract definition verified");

// 2. Test matchRegistrationSlot
const timestampSlot = gateway.matchRegistrationSlot("Timestamp");
assert.ok(timestampSlot, "Timestamp must match");
assert.strictEqual(timestampSlot.order, 1);

const fullnameSlot = gateway.matchRegistrationSlot("Nama Lengkap");
assert.ok(fullnameSlot, "Nama Lengkap must match");
assert.strictEqual(fullnameSlot.order, 2);

const lastSlot = gateway.matchRegistrationSlot("SERTIF_LAST_SYNC_AT");
assert.ok(lastSlot, "SERTIF_LAST_SYNC_AT must match");
assert.strictEqual(lastSlot.order, 38);
console.log("✔ S2: matchRegistrationSlot matches 1 to 38 canonical slots");

// 3. Test buildRowValuesForSheet with exactly 38 headers
const headers38 = gateway.REG_CONTRACT_2026.map(s => s.dbKey);
const sampleData = {
  "Timestamp": "2026-02-01 10:00:00",
  "Nama Lengkap": "Ahmad Dani",
  "Email": "ahmad@example.com",
  "KET": "Menunggu",
  "SERTIF_SYNC_ID": "sync-12345"
};
const res38 = gateway.buildRowValuesForSheet(headers38, sampleData, "registration", false);
assert.ok(!res38.error, "38 headers must succeed without error");
assert.strictEqual(res38.values.length, 38, "Must produce 38 row values");
assert.strictEqual(res38.values[0], "2026-02-01 10:00:00");
assert.strictEqual(res38.values[1], "Ahmad Dani");
assert.strictEqual(res38.values[4], "ahmad@example.com");
console.log("✔ S3: buildRowValuesForSheet with 38 headers passes");

// 4. Test buildRowValuesForSheet with 38+n (e.g. 40 headers)
const headers40 = [...headers38.slice(0, 33), "Custom Pertanyaan 1", "Custom Pertanyaan 2", ...headers38.slice(33)];
const sampleData40 = {
  ...sampleData,
  "Custom Pertanyaan 1": "Jawaban Custom 1",
  "Custom Pertanyaan 2": "Jawaban Custom 2"
};
const res40 = gateway.buildRowValuesForSheet(headers40, sampleData40, "registration", true);
assert.ok(!res40.error, "38+n headers must succeed without error");
assert.strictEqual(res40.values.length, 40, "Must produce 40 row values");
assert.strictEqual(res40.values[33], "Jawaban Custom 1");
assert.strictEqual(res40.values[34], "Jawaban Custom 2");
console.log("✔ S4: buildRowValuesForSheet with 38+n dynamic columns passes");

// 5. Test buildRowValuesForSheet with < 38 headers (must fail)
const headers37 = headers38.slice(0, 37);
const res37 = gateway.buildRowValuesForSheet(headers37, sampleData, "registration", false);
assert.ok(res37.error, "Less than 38 headers must produce error");
assert.strictEqual(res37.error_code, "SCHEMA_MISMATCH");
console.log("✔ S5: buildRowValuesForSheet with < 38 headers rejected with SCHEMA_MISMATCH");

// 6. Test Mock Script Properties and Action Handlers
global._mockScriptProperties = {
  _props: {},
  getProperty(k) { return this._props[k] || null; },
  setProperty(k, v) { this._props[k] = v; },
  getProperties() { return this._props; }
};

// 7. Test handleActivateAuthorizedTargetAction
const actRes = gateway.handleActivateAuthorizedTargetAction({
  target_type: "spreadsheet",
  target_id: "test-sheet-id-12345",
  permission: "registration"
});
assert.strictEqual(actRes.data.success, true);
assert.strictEqual(global._mockScriptProperties.getProperty("REGISTRATION_SPREADSHEET_ID"), "test-sheet-id-12345");
assert.ok(global._mockScriptProperties.getProperty("SPREADSHEET_ALLOWLIST").includes("test-sheet-id-12345"));
console.log("✔ S6: handleActivateAuthorizedTargetAction activates registration target in properties");

// 8. Test handleActivateAuthorizedTargetAction with legacy_source
const legRes = gateway.handleActivateAuthorizedTargetAction({
  target_type: "spreadsheet",
  target_id: "legacy-sheet-id-999",
  permission: "legacy_source"
});
assert.strictEqual(legRes.data.success, true);
assert.ok(global._mockScriptProperties.getProperty("LEGACY_SOURCES").includes("legacy-sheet-id-999"));
assert.ok(global._mockScriptProperties.getProperty("SPREADSHEET_ALLOWLIST").includes("legacy-sheet-id-999"));
console.log("✔ S7: handleActivateAuthorizedTargetAction activates legacy_source in allowlist and legacy registry");

// 9. HOTFIX 3: Activating legacy target as registration must be PERMANENTLY REJECTED with LEGACY_SOURCE_READONLY
const rejectedPromote = gateway.handleActivateAuthorizedTargetAction({
  target_type: "spreadsheet",
  target_id: "legacy-sheet-id-999",
  permission: "registration"
});
assert.strictEqual(rejectedPromote.data.success, false);
assert.strictEqual(rejectedPromote.data.error_code, "LEGACY_SOURCE_READONLY");
console.log("✔ S8: promoting legacy source to registration is strictly rejected with LEGACY_SOURCE_READONLY (403)");

// 10. HOTFIX 3: Even with confirm_promote_legacy: true, it must STILL be rejected fail-closed
const rejectedPromoteWithFlag = gateway.handleActivateAuthorizedTargetAction({
  target_type: "spreadsheet",
  target_id: "legacy-sheet-id-999",
  permission: "registration",
  confirm_promote_legacy: true
});
assert.strictEqual(rejectedPromoteWithFlag.data.success, false);
assert.strictEqual(rejectedPromoteWithFlag.data.error_code, "LEGACY_SOURCE_READONLY");
const curLegacy = global._mockScriptProperties.getProperty("LEGACY_SOURCES") || "";
assert.ok(curLegacy.includes("legacy-sheet-id-999"), "Legacy source must remain permanently in LEGACY_SOURCES");
console.log("✔ S9: legacy source remains strictly read-only and cannot be bypassed via confirm_promote_legacy");

console.log("\nALL APPS SCRIPT NODE TESTS PASSED SUCCESSFULLY! (9/9)");
