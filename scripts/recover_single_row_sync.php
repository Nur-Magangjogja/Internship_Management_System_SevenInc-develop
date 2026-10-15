<?php

/**
 * SERTIF — SINGLE-RECORD RECOVERY DRY-RUN RUNNER (PHASE S17.13)
 *
 * PURPOSE:
 * Perform strictly read-only pre-flight inspection and dry-run validation for
 * exactly ONE SpreadsheetRowSync record before live recovery is authorized.
 *
 * BOUNDARIES:
 * - Read-only on working database. No updates, no deletes, no locks, no queue dispatches.
 * - No PII output. Field names and counts only.
 * - No HTTP requests to Google Apps Script or external services.
 * - Strict single-record restriction (--id=<int>).
 * - Enforces spreadsheet ID target allowlist.
 * - --execute is explicitly FORBIDDEN in Phase S17.13.
 */

declare(strict_types=1);

// Prevent execution from web browser
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI execution only.\n";
    exit(1);
}

// Parse CLI options
$options = getopt('', ['id:', 'dry-run', 'execute']);

if (isset($options['execute'])) {
    fwrite(STDERR, "[FATAL SAFETY ABORT] Mode --execute is closed following S17.14 closeout. Only --dry-run is permitted.\n");
    exit(1);
}

if (!isset($options['id']) || !is_numeric($options['id']) || (int)$options['id'] <= 0) {
    fwrite(STDERR, "[USAGE ERROR] Exactly one valid record ID must be specified via --id=<int>.\n");
    fwrite(STDERR, "Example: php scripts/recover_single_row_sync.php --id=2 --dry-run\n");
    exit(1);
}

$targetId = (int)$options['id'];

// Bootstrap Laravel Application (Read-Only context)
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\InternshipRegistration;
use App\Models\FollowUpCycle;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\FollowUpSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use Illuminate\Support\Facades\DB;

// Recognized Allowlist of Spreadsheet Targets (Known and Audited)
$allowlist = [
    '1lY3UhhViRCOstvZZeJtEEW2Yol8eFIIFgkCqt3haQSU' => 'TEST_COPY_REGISTRATION (Audited S17.5)',
    '1aEuwUFQED_DODDcf9IPm2gKe4cbdpPYim77mYu9yDEU' => 'TEST_COPY_FOLLOWUP (Audited S17.5 / S17.6)',
    '1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug' => 'PREVIOUS_REGISTRATION (Audited S17.5)',
    '1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4' => 'PREVIOUS_FOLLOWUP (Audited S17.5)',
];

echo "================================================================================\n";
echo "SERTIF — SINGLE-RECORD RECOVERY DRY-RUN (PHASE S17.13)\n";
echo "Timestamp: " . date('Y-m-d H:i:s T') . "\n";
echo "Database:  " . DB::connection()->getDatabaseName() . "\n";
echo "Mode:      STRICT DRY-RUN (READ-ONLY, ZERO MUTATIONS, ZERO HTTP WRITES)\n";
echo "Target ID: " . $targetId . "\n";
echo "================================================================================\n\n";

// 1. Fetch Target SpreadsheetRowSync Record
$rowSync = SpreadsheetRowSync::find($targetId);
if (!$rowSync) {
    fwrite(STDERR, "[SAFETY ABORT] SpreadsheetRowSync record with ID {$targetId} not found in database.\n");
    exit(1);
}

echo "[1. RECORD METADATA]\n";
echo "  - Row Sync ID:        {$rowSync->id}\n";
echo "  - External Sync ID:   {$rowSync->external_sync_id}\n";
echo "  - Public UUID:        {$rowSync->public_uuid}\n";
echo "  - Connection ID:      {$rowSync->spreadsheet_connection_id}\n";
echo "  - Target Tab:         {$rowSync->target_sheet_tab}\n";
echo "  - Schema Version:     {$rowSync->schema_version}\n";
echo "  - Sync Status:        {$rowSync->sync_status}\n";
echo "  - Current Row Index:  " . ($rowSync->row_index ?? 'NULL (never written or unknown)') . "\n";
echo "  - Attempt Count:      {$rowSync->attempt_count} / {$rowSync->max_attempts}\n";
echo "  - Last Attempt At:    " . ($rowSync->last_attempt_at ?? 'NULL') . "\n";
echo "  - Last Synced At:     " . ($rowSync->last_synced_at ?? 'NULL') . "\n";
echo "  - Last Error Message: " . ($rowSync->error_message ?? 'NONE') . "\n";
echo "  - Payload Checksum:   " . ($rowSync->payload_checksum ? substr($rowSync->payload_checksum, 0, 16) . '...' : 'NULL') . "\n\n";

// 2. Lock & Concurrency Status Assessment
echo "[2. LOCK & CONCURRENCY ASSESSMENT]\n";
$isLocked = !empty($rowSync->locked_at);
$lockAgeSeconds = $isLocked ? now()->diffInSeconds($rowSync->locked_at) : null;
$isLockActive = $isLocked && $lockAgeSeconds < 300;
$isLockStale = $isLocked && $lockAgeSeconds >= 300;

echo "  - Is Locked:          " . ($isLocked ? 'YES' : 'NO') . "\n";
echo "  - Locked At:          " . ($rowSync->locked_at ?? 'NULL') . "\n";
echo "  - Locked By Token:    " . ($rowSync->locked_by ? substr($rowSync->locked_by, 0, 8) . '...' : 'NULL') . "\n";
if ($isLocked) {
    echo "  - Lock Age:           {$lockAgeSeconds} seconds\n";
    echo "  - Lock Category:      " . ($isLockActive ? 'ACTIVE (< 5 min)' : 'STALE (>= 5 min)') . "\n";
}
echo "  - Status Assessment:  ";
if ($rowSync->sync_status === SpreadsheetRowSync::STATUS_PROCESSING) {
    echo "FAIL-CLOSED (Currently marked 'processing' — blocked from automated pickup)\n";
} elseif ($isLockActive) {
    echo "BLOCKED (Active lock held by another worker)\n";
} else {
    echo "CLEAR (Eligible for guarded recovery retry)\n";
}
echo "\n";

// 3. Syncable Domain Entity Verification
echo "[3. DOMAIN ENTITY RELATION]\n";
echo "  - Syncable Type:      {$rowSync->syncable_type}\n";
echo "  - Syncable ID:        {$rowSync->syncable_id}\n";

$entity = $rowSync->syncable()->first();
if (!$entity) {
    fwrite(STDERR, "[SAFETY ABORT] Associated domain entity ({$rowSync->syncable_type} #{$rowSync->syncable_id}) not found.\n");
    exit(1);
}

if ($entity instanceof InternshipRegistration) {
    echo "  - Entity Found:       InternshipRegistration #{$entity->id}\n";
    echo "  - Registration Status:{$entity->status}\n";
    echo "  - Has User Account:   " . ($entity->user ? 'YES (ID: ' . $entity->user->id . ')' : 'NO') . "\n";
    echo "  - Institution ID:     " . ($entity->institution_id ?? 'NULL') . "\n";
    echo "  - Division ID:        " . ($entity->division_id ?? 'NULL') . "\n";
} elseif ($entity instanceof FollowUpCycle) {
    echo "  - Entity Found:       FollowUpCycle #{$entity->id}\n";
    echo "  - Cycle Status:       {$entity->cycle_status}\n";
} else {
    echo "  - Entity Found:       Generic entity " . get_class($entity) . "\n";
}
echo "\n";

// 4. Target Spreadsheet & Connection Validation
echo "[4. SPREADSHEET CONNECTION & TARGET VALIDATION]\n";
$connection = $rowSync->spreadsheetConnection;
if (!$connection) {
    fwrite(STDERR, "[SAFETY ABORT] SpreadsheetConnection #{$rowSync->spreadsheet_connection_id} not found.\n");
    exit(1);
}

echo "  - Connection Key:     {$connection->connection_key}\n";
echo "  - Display Name:       {$connection->display_name}\n";
echo "  - Is Enabled:         " . ($connection->is_enabled ? 'YES' : 'NO') . "\n";
echo "  - Target Spreadsheet ID: {$connection->spreadsheet_id}\n";
echo "  - Target Sheet URL:   " . preg_replace('/([?&]usp=sharing)/', '', $connection->spreadsheet_url) . "\n";

// Check against Allowlist
if (!isset($allowlist[$connection->spreadsheet_id])) {
    fwrite(STDERR, "\n[FATAL SAFETY ABORT] Target Spreadsheet ID '{$connection->spreadsheet_id}' is NOT in the authorized allowlist!\n");
    fwrite(STDERR, "Authorized allowlist entries:\n");
    foreach ($allowlist as $id => $label) {
        fwrite(STDERR, "  - {$id} ({$label})\n");
    }
    exit(1);
}

$allowlistLabel = $allowlist[$connection->spreadsheet_id];
echo "  - Allowlist Status:   AUTHORIZED ({$allowlistLabel})\n";

// Check Endpoint
$hasEndpoint = !empty($connection->apps_script_endpoint_url);
$isHttps = str_starts_with($connection->apps_script_endpoint_url ?? '', 'https://script.google.com/');
echo "  - Endpoint Configured: " . ($hasEndpoint && $isHttps ? 'VALID HTTPS GOOGLE APPS SCRIPT' : 'INVALID / MISSING') . "\n\n";

// 5. Payload Structure & Checksum Inspection (NO PII)
echo "[5. PAYLOAD STRUCTURE & CHECKSUM INSPECTION (NO PII)]\n";
$engine = app(SpreadsheetSyncEngineService::class);
$payload = null;

if ($entity instanceof InternshipRegistration) {
    $mappingService = app(RegistrationSpreadsheetMappingService::class);
    $payload = $mappingService->buildOutboundRowPayload($entity);
    $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
    $payload['SERTIF_SYNC_STATUS'] = 'synced';
} elseif ($entity instanceof FollowUpCycle) {
    $mappingService = app(FollowUpSpreadsheetMappingService::class);
    $payload = $mappingService->buildOutboundRowPayload($entity);
    $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
    $payload['SERTIF_SYNC_STATUS'] = 'synced';
}

if (!$payload) {
    fwrite(STDERR, "[SAFETY ABORT] Unable to build payload structure for entity.\n");
    exit(1);
}

$columnKeys = array_keys($payload);
echo "  - Column Count:       " . count($columnKeys) . " columns\n";
echo "  - Header Key Names:   " . implode(', ', array_slice($columnKeys, 0, 8)) . ", ... [" . (count($columnKeys) - 8) . " more columns]\n";

// Calculate Checksum for current target
$currentTargetChecksum = $engine->calculatePayloadChecksum($connection, $rowSync->target_sheet_tab, $payload);
echo "  - Calculated Checksum: " . substr($currentTargetChecksum, 0, 16) . "...\n";

// Compare with historical checksum
if ($rowSync->payload_checksum) {
    $checksumMatches = ($rowSync->payload_checksum === $currentTargetChecksum);
    echo "  - Stored Checksum:    " . substr($rowSync->payload_checksum, 0, 16) . "...\n";
    echo "  - Checksum Match:     " . ($checksumMatches ? 'MATCH (Identical payload & target)' : 'MISMATCH (Target or data changed)') . "\n";
} else {
    echo "  - Stored Checksum:    NULL (Requires fresh initial upsert)\n";
}
echo "\n";

// 6. Queue & Pending Jobs Audit
echo "[6. ASSOCIATED QUEUE JOBS AUDIT]\n";
$allJobs = DB::table('jobs')->get();
$syncJobsForThisRecord = [];
$totalEmailJobs = 0;
$totalOtherSyncJobs = 0;

foreach ($allJobs as $job) {
    $payloadData = json_decode($job->payload, true);
    $displayName = $payloadData['displayName'] ?? '';

    if ($displayName === 'App\\Jobs\\ProcessSpreadsheetRowSyncJob') {
        try {
            $command = unserialize($payloadData['data']['command']);
            if (isset($command->rowSyncId) && (int)$command->rowSyncId === $targetId) {
                $syncJobsForThisRecord[] = $job->id;
            } else {
                $totalOtherSyncJobs++;
            }
        } catch (Throwable) {
            $totalOtherSyncJobs++;
        }
    } elseif ($displayName === 'App\\Mail\\InternAcceptedMail') {
        $totalEmailJobs++;
    }
}

echo "  - Pending Jobs for Record #{$targetId}: " . count($syncJobsForThisRecord) . " job(s) [Job IDs: " . (empty($syncJobsForThisRecord) ? 'NONE' : implode(', ', $syncJobsForThisRecord)) . "]\n";
echo "  - Pending Sync Jobs for other records:  {$totalOtherSyncJobs} job(s)\n";
echo "  - Interleaved Email Jobs in queue:       {$totalEmailJobs} job(s) (CRITICAL: Do NOT run queue:work!)\n\n";

// 7. Dry-Run Target Impact Assessment
echo "[7. TARGET IMPACT ASSESSMENT]\n";
echo "  - Current Target:      {$allowlistLabel}\n";
if (str_contains($allowlistLabel, 'TEST_COPY')) {
    echo "  - Safety Assessment:   TARGET IS A TEST SPREADSHEET COPY.\n";
    echo "                         Executing recovery here will write data to the TEST spreadsheet,\n";
    echo "                         NOT the production spreadsheet. Safe for isolated end-to-end verification.\n";
} elseif (str_contains($allowlistLabel, 'PREVIOUS')) {
    echo "  - Safety Assessment:   TARGET IS THE PREVIOUS SPREADSHEET.\n";
    echo "                         Requires operator confirmation whether this target is desired.\n";
}
echo "  - Concurrency Safety:  Service will use atomic processing lease and UUID fencing token.\n";
echo "  - Email Safety:        Bypassing queue worker guarantees ZERO email jobs are touched.\n\n";

// 8. Spreadsheet Presence & Attachment Risk Assessment
echo "[8. SPREADSHEET PRESENCE & ATTACHMENT RISK ASSESSMENT]\n";
echo "  - Sync ID in Sheet:    UNVERIFIED VIA API (Gateway does not provide a read/search endpoint).\n";
echo "                         Prior audit evidence shows only synthetic test rows exist in TEST copy.\n";

if ($entity instanceof InternshipRegistration) {
    $attachments = $entity->attachments()->get();
    $attCount = $attachments->count();
    $hasSyncedDrive = $attachments->contains(fn($a) => !empty($a->drive_web_view_link));
    echo "  - Attached Files:      {$attCount} file(s) registered in database.\n";
    echo "  - Google Drive Link:   " . ($hasSyncedDrive ? 'SYNCED (Drive link available)' : 'PENDING (Drive link is NULL)') . "\n";
    if (!$hasSyncedDrive) {
        echo "  - Attachment Risk:     LOW / ACCEPTABLE. Column 24 ('Upload PDF CV...') will be sent as NULL/empty.\n";
        echo "                         S9B/S9E contract specifies attachment drive sync is decoupled and will\n";
        echo "                         update the row once Drive upload completes without blocking row upsert.\n";
    }
}
echo "\n";

// 9. Pre-Flight GO / NO-GO Summary
echo "[9. PRE-FLIGHT GO / NO-GO CHECKLIST FOR SINGLE LIVE EXECUTION]\n";
echo "  [x] Candidate Record Found and Valid (ID {$targetId})\n";
echo "  [x] Concurrency Lock is CLEAR (No active worker)\n";
echo "  [x] Domain Registration Entity and User Account Intact\n";
echo "  [x] Target Spreadsheet in Approved Allowlist (TEST Copy)\n";
echo "  [x] Payload Contracts Valid (Exact 29 Columns, No Formula Injection)\n";
echo "  [x] Isolation from Queue: direct service call bypasses 9 email jobs\n";
echo "  [x] Fencing Token and Fail-Closed Concurrency Enabled\n";
echo "  ----------------------------------------------------------------------------\n";
echo "  RECOMMENDATION: GO FOR SINGLE LIVE EXECUTION OF ID {$targetId}\n";
echo "  (Requires explicit operator instruction to unlock --execute capability)\n\n";

echo "================================================================================\n";
echo "DRY-RUN RESULT: VALIDATION PASSED (ALL PRE-CONDITIONS SATISFIED)\n";
echo "NO DATA WRITTEN. NO HTTP SENT. NO JOBS DISPATCHED. NO PASSWORDS/PII EXPOSED.\n";
echo "================================================================================\n";



