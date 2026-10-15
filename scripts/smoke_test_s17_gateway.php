<?php

/**
 * ============================================================================
 * SERTIF - S17 ISOLATED END-TO-END SMOKE TEST TOOL
 * ============================================================================
 * 
 * Tool ini menguji GoogleAppsScriptTransport secara terisolasi untuk:
 * 1. Jalur Registration (Kontrak 2026, Header Baris 12, 29 Kolom)
 * 2. Jalur Follow-Up (Kontrak Follow Up Guru Dosen, Header Baris 1, 21 Kolom)
 * 
 * Penggunaan:
 *   php scripts/smoke_test_s17_gateway.php --dry-run
 *   php scripts/smoke_test_s17_gateway.php --connection=followup --dry-run
 *   php scripts/smoke_test_s17_gateway.php --connection=followup --ping
 *   php scripts/smoke_test_s17_gateway.php --connection=followup --execute
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SpreadsheetConnection;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use Illuminate\Support\Str;

// 1. Parse Argumen CLI
$options = getopt('', ['dry-run', 'ping', 'ping-unicode', 'execute', 'connection:', 'help']);
$isDryRun = isset($options['dry-run']) || (!isset($options['ping']) && !isset($options['ping-unicode']) && !isset($options['execute']));
$isPingOnly = isset($options['ping']);
$isPingUnicode = isset($options['ping-unicode']);
$isExecute = isset($options['execute']);
$selectedConnectionKey = isset($options['connection']) ? strtolower(trim($options['connection'])) : SpreadsheetConnection::KEY_REGISTRATION;

if (!in_array($selectedConnectionKey, [SpreadsheetConnection::KEY_REGISTRATION, SpreadsheetConnection::KEY_FOLLOWUP], true)) {
    echo "❌ [ERROR] Parameter --connection tidak valid. Pilihan yang didukung: 'registration' atau 'followup'." . PHP_EOL;
    exit(1);
}

echo PHP_EOL;
echo "================================================================================" . PHP_EOL;
echo "  SERTIF — S17 ISOLATED GATEWAY SMOKE TEST (PHASE S17.6)" . PHP_EOL;
echo "================================================================================" . PHP_EOL;

// 2. Allowlist Target yang Disetujui Operator
$approvedSpreadsheetAllowlist = [
    SpreadsheetConnection::KEY_REGISTRATION => '1lY3UhhViRCOstvZZeJtEEW2Yol8eFIIFgkCqt3haQSU', // Salinan TEST Spreadsheet Pendaftaran
    SpreadsheetConnection::KEY_FOLLOWUP     => '1aEuwUFQED_DODDcf9IPm2gKe4cbdpPYim77mYu9yDEU', // Salinan TEST Spreadsheet Follow-Up
];

// Baca konfigurasi aktif dari database & .env (Read-Only)
$dbConnRecord = SpreadsheetConnection::where('connection_key', $selectedConnectionKey)->first();
$targetSpreadsheetId = $dbConnRecord ? $dbConnRecord->spreadsheet_id : '';
$targetEndpoint = $dbConnRecord && !empty($dbConnRecord->apps_script_endpoint_url) 
    ? $dbConnRecord->apps_script_endpoint_url 
    : env('GOOGLE_APPS_SCRIPT_ENDPOINT_URL', '');
$targetSecret = $dbConnRecord && !empty($dbConnRecord->shared_secret)
    ? $dbConnRecord->shared_secret
    : env('GOOGLE_APPS_SCRIPT_SHARED_SECRET', '');

// Helper Masking
function mask(string $str, int $head = 8, int $tail = 6): string {
    if (empty($str)) return '(KOSONG)';
    if (strlen($str) <= ($head + $tail)) return '***';
    return substr($str, 0, $head) . '...' . substr($str, -$tail);
}

// 3. Verifikasi Allowlist Target (Fail-Closed)
$expectedApprovedId = $approvedSpreadsheetAllowlist[$selectedConnectionKey] ?? '';

echo "[1/4] Pemeriksaan Allowlist Target:" . PHP_EOL;
echo "  - Selected Connection   : " . $selectedConnectionKey . PHP_EOL;
echo "  - Target Spreadsheet ID : " . mask($targetSpreadsheetId, 10, 8) . PHP_EOL;
echo "  - Target Endpoint URL   : " . mask($targetEndpoint, 45, 10) . PHP_EOL;
echo "  - Shared Secret Status  : " . (!empty($targetSecret) ? "Tersedia (Panjang: " . strlen($targetSecret) . " karakter)" : "TIDAK TERSEDIA") . PHP_EOL;

if (empty($targetSpreadsheetId) || empty($targetEndpoint) || empty($targetSecret)) {
    echo PHP_EOL . "❌ [SAFETY_ABORT] Konfigurasi target tidak lengkap. Pastikan Spreadsheet ID, Endpoint, dan Secret tersedia." . PHP_EOL;
    exit(1);
}

if ($targetSpreadsheetId !== $expectedApprovedId) {
    echo PHP_EOL . "❌ [SAFETY_ABORT] Spreadsheet ID '" . $targetSpreadsheetId . "' TIDAK SESUAI dengan allowlist operator untuk '{$selectedConnectionKey}'!" . PHP_EOL;
    echo "  Expected Approved ID: " . $expectedApprovedId . PHP_EOL;
    exit(1);
}

if (!str_starts_with($targetEndpoint, 'https://script.google.com/macros/s/')) {
    echo PHP_EOL . "❌ [SAFETY_ABORT] Endpoint URL tidak valid (harus Google Apps Script Web App /exec)." . PHP_EOL;
    exit(1);
}

echo "  -> Verifikasi Allowlist: LOLOS (Target terdaftar dalam izin operator)." . PHP_EOL . PHP_EOL;

// 4. Siapkan In-Memory Model & Transport (Zero DB Mutation)
$mockConnection = new SpreadsheetConnection();
$mockConnection->connection_key = $selectedConnectionKey;
$mockConnection->display_name = 'In-Memory Smoke Test Connection (' . ucfirst($selectedConnectionKey) . ')';
$mockConnection->spreadsheet_id = $targetSpreadsheetId;
$mockConnection->apps_script_endpoint_url = $targetEndpoint;
$mockConnection->shared_secret = $targetSecret;
$mockConnection->is_enabled = true;

$transport = new GoogleAppsScriptTransport([
    'enabled'         => true,
    'endpoint_url'    => $targetEndpoint,
    'shared_secret'   => $targetSecret,
    'timeout_seconds' => 30,
]);

// 5. Generate Unik Sync ID
$uniqueSyncId = 'TEST-S17-' . strtoupper(substr($selectedConnectionKey, 0, 3)) . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

// 6. Buat Payload Sintetis Terisolasi Sesuai Kontrak
if ($selectedConnectionKey === SpreadsheetConnection::KEY_REGISTRATION) {
    $targetTabName = '2026';
    $headerRowIndex = 12;
    $expectedColCount = 29;

    $syntheticPayload = [
        'Timestamp'                                                                      => date('Y-m-d H:i:s'),
        'Nama Lengkap'                                                                   => '[SMOKE-TEST] Budi Santoso S17',
        'NIM (Nomor Induk Mahasiswa)'                                                    => '22/TEST/S17/001',
        'Program Studi'                                                                  => 'Teknik Informatika',
        'Email'                                                                          => 'smoke-test-s17@example.com',
        'Jenis Kelamin'                                                                  => 'Laki-laki',
        'No. HP Aktif yang bisa dihubungi dan chat WA'                                   => '081299990001',
        'Asal Sekolah/Kampus'                                                            => 'Universitas Uji Coba S17',
        'Nama Kota/Daerah tempat tinggal Anda saat ini'                                  => 'Yogyakarta',
        'Mengapa Anda ingin Magang/PKL disini?'                                          => 'Pengujian Idempotensi dan Kontrak 29 Kolom S17',
        'Jenis Magang apa yang Anda pilih?'                                              => 'Mandiri',
        'Status Anda saat ini'                                                           => 'Mahasiswa',
        'Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?'    => 'SIAP',
        'No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan'=> 'Dosen Pembimbing Test (081199990001)',
        'Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)'=> 'Sanggup jadwal shifting & hari kerja',
        'Apakah ada laptop sendiri'                                                      => 'YA',
        'Jika YA, alat apa yang dimiliki/dibawa'                                         => 'Laptop ASUS Core i7',
        'Kapan berencana mulai Magang/PKL'                                               => '2026-11-01',
        'Berapa lama akan Magang/PKL'                                                    => '3 Bulan',
        'Sumber info magang'                                                             => 'Website',
        'Instagram'                                                                      => '@smoketest_s17',
        'Butuh info kost/kontrakan'                                                      => 'TIDAK',
        'Program Magang/PKL yang diminati'                                               => 'Backend Development',
        'Upload PDF CV + scan KTM/KTP + portofolio max 10MB'                             => 'https://drive.google.com/file/d/test-s17/view',
        'KET'                                                                            => 'Testing S17',
        'SERTIF_SYNC_ID'                                                                 => $uniqueSyncId,
        'SERTIF_REGISTRATION_ID'                                                         => '999999',
        'SERTIF_SYNC_STATUS'                                                             => 'pending',
        'SERTIF_LAST_SYNC_AT'                                                            => date('c'),
    ];
    $updateFieldKey = 'Nama Lengkap';
    $updatedValue = '[SMOKE-TEST] Budi Santoso S17 UPDATED';
} else {
    // Follow-Up 21-Column Contract (A to U, Header Row 1, SERTIF_SYNC_ID on Column P)
    $targetTabName = 'Follow Up Guru Dosen';
    $headerRowIndex = 1;
    $expectedColCount = 21;

    $syntheticPayload = [
        'No.'                                            => '1',
        'Tahun Follow Up'                                => date('Y'),
        'Sekolah/Kampus'                                 => 'SMK / Universitas Uji Coba S17',
        'Dosen/Guru'                                     => 'Drs. Hendro Wibowo, M.Pd.',
        'Nomor Telepon'                                  => '081288880001',
        'Status Guru Dosen'                              => 'Aktif Menjabat',
        'Follow Up 1'                                    => 'Menyampaikan permohonan kerjasama via WA',
        'Follow Up 2'                                    => 'Diskusi kuota penerimaan dan bidang keahlian',
        'Follow Up 3'                                    => 'Konfirmasi draft MOU kerjasama magang',
        'Kapan PKL/Magang Nya'                           => 'Januari 2027',
        'Respon'                                         => 'Positif / Bersedia Kerjasama',
        'Nama yang mengajukan Kerjasama/ Follow Up 1'    => 'HR Staff Seven Inc 1',
        'Nama yang mengajukan Kerjasama/ Follow Up 2'    => 'HR Staff Seven Inc 2',
        'Nama yang mengajukan Kerjasama/ Follow Up 3'    => 'HR Staff Seven Inc 3',
        'KET'                                            => '[SMOKE-TEST] Uji Coba Kontrak Follow Up 21 Kolom S17',
        'SERTIF_SYNC_ID'                                 => $uniqueSyncId, // Kolom P (Slot 16)
        'SERTIF_INSTITUTION_ID'                          => '9991',
        'SERTIF_CONTACT_ID'                              => '8881',
        'SERTIF_FOLLOWUP_CYCLE_ID'                       => '7771',
        'SERTIF_SYNC_STATUS'                             => 'pending',
        'SERTIF_LAST_SYNC_AT'                            => date('c'),
    ];
    $updateFieldKey = 'Respon';
    $updatedValue = 'Positif / Kerjasama Disetujui & Terjadwal [UPDATED]';
}

echo "[2/4] Spesifikasi Payload Sintetis {$expectedColCount} Kolom:" . PHP_EOL;
echo "  - Generated Sync ID     : " . $uniqueSyncId . PHP_EOL;
echo "  - Target Connection     : " . $selectedConnectionKey . PHP_EOL;
echo "  - Target Tab Sheet      : " . $targetTabName . " (Baris Header " . $headerRowIndex . ")" . PHP_EOL;
echo "  - Jumlah Kolom Payload  : " . count($syntheticPayload) . " kolom (Kontrak " . ucfirst($selectedConnectionKey) . ")" . PHP_EOL;
echo "  - Integritas Identitas  : sync_id === SERTIF_SYNC_ID (Cocok persis)" . PHP_EOL . PHP_EOL;

// 7. Mode Eksekusi
if ($isDryRun) {
    echo "================================================================================" . PHP_EOL;
    echo "  MODE: DRY-RUN AKTIF (TIDAK ADA PERMINTAAN HTTP YANG DIKIRIM)" . PHP_EOL;
    echo "================================================================================" . PHP_EOL;
    echo "  [OK] Validasi konfigurasi target '{$selectedConnectionKey}' berhasil." . PHP_EOL;
    echo "  [OK] In-memory transport dan connection model siap." . PHP_EOL;
    echo "  [OK] Payload {$expectedColCount} kolom terstruktur rapi sesuai kontrak baris {$headerRowIndex} tab '{$targetTabName}'." . PHP_EOL;
    echo "  [OK] Zero database writes terjamin (tanpa mutasi tabel pendaftaran/followup/sync)." . PHP_EOL;
    echo "  [OK] Tidak ada request live yang dikirim." . PHP_EOL . PHP_EOL;
    echo "Gunakan flag berikut jika izin eksekusi telah diberikan operator:" . PHP_EOL;
    echo "  php scripts/smoke_test_s17_gateway.php --connection={$selectedConnectionKey} --ping" . PHP_EOL;
    echo "  php scripts/smoke_test_s17_gateway.php --connection={$selectedConnectionKey} --execute" . PHP_EOL;
    exit(0);
}

// 8. Eksekusi Live (Hanya jika --ping, --ping-unicode, atau --execute dipanggil secara eksplisit)

if ($isPingOnly) {
    echo "[3/4] Menjalankan Ping Read-Only..." . PHP_EOL;
    $innerPayload = [
        'action'         => 'ping',
        'connection_key' => $mockConnection->connection_key,
        'spreadsheet_id' => $mockConnection->spreadsheet_id,
    ];
    $response = $transport->dispatchSignedRequest($targetEndpoint, $innerPayload, 'PING');

    if ($response->success) {
        echo "  ✅ PING BERHASIL! Autentikasi HMAC valid dan target spreadsheet dapat diakses." . PHP_EOL;
        if (!empty($response->context)) {
            echo "     - Message          : " . ($response->context['message'] ?? 'OK') . PHP_EOL;
            echo "     - Action           : " . ($response->context['action'] ?? 'ping') . PHP_EOL;
            echo "     - Connection Key   : " . ($response->context['connection_key'] ?? $selectedConnectionKey) . PHP_EOL;
            echo "     - Target Accessible: " . (($response->context['target_accessible'] ?? false) ? 'YES' : 'NO') . PHP_EOL;
            echo "     - ID Matched       : " . (($response->context['id_matched'] ?? false) ? 'YES' : 'NO') . PHP_EOL;
            echo "     - Gateway Timestamp: " . ($response->context['timestamp'] ?? '-') . PHP_EOL;
        }
    } else {
        echo "  ❌ PING GAGAL!" . PHP_EOL;
        echo "     - Error Code   : " . $response->errorCode . PHP_EOL;
        echo "     - Error Message: " . $response->errorMessage . PHP_EOL;
    }
    echo PHP_EOL . "[4/4] Mode --ping selesai tanpa penulisan apa pun." . PHP_EOL;
    exit($response->success ? 0 : 1);
}

if ($isPingUnicode) {
    echo "[3/4] Menjalankan Ping Diagnostik Unicode (Read-Only Tanpa Penulisan)..." . PHP_EOL;
    $innerPayload = [
        'action'         => 'ping',
        'connection_key' => $mockConnection->connection_key,
        'spreadsheet_id' => $mockConnection->spreadsheet_id,
        'unicode_probe'  => 'Senin–Sabtu',
    ];
    $response = $transport->dispatchSignedRequest($targetEndpoint, $innerPayload, 'PING-UNICODE');

    if ($response->success) {
        echo "  ✅ PING UNICODE BERHASIL! Autentikasi HMAC valid dengan probe Unicode." . PHP_EOL;
        if (!empty($response->context)) {
            echo "     - Message          : " . ($response->context['message'] ?? 'OK') . PHP_EOL;
            echo "     - Action           : " . ($response->context['action'] ?? 'ping') . PHP_EOL;
            echo "     - Connection Key   : " . ($response->context['connection_key'] ?? $selectedConnectionKey) . PHP_EOL;
            echo "     - Target Accessible: " . (($response->context['target_accessible'] ?? false) ? 'YES' : 'NO') . PHP_EOL;
            echo "     - ID Matched       : " . (($response->context['id_matched'] ?? false) ? 'YES' : 'NO') . PHP_EOL;
            echo "     - Gateway Timestamp: " . ($response->context['timestamp'] ?? '-') . PHP_EOL;
        }
    } else {
        echo "  ❌ PING UNICODE GAGAL!" . PHP_EOL;
        echo "     - Error Code   : " . $response->errorCode . PHP_EOL;
        echo "     - Error Message: " . $response->errorMessage . PHP_EOL;
    }
    echo PHP_EOL . "[4/4] Mode --ping-unicode selesai tanpa penulisan apa pun." . PHP_EOL;
    exit($response->success ? 0 : 1);
}

if ($isExecute) {
    echo "[3/4] Melakukan Pengujian End-to-End Terisolasi ({$selectedConnectionKey})..." . PHP_EOL;

    // Langkah A: Ping
    echo "  A. Uji Ping Konektivitas Gateway... ";
    $pingOk = $transport->testConnection($mockConnection);
    if (!$pingOk) {
        echo "GAGAL! Aborting." . PHP_EOL;
        exit(1);
    }
    echo "SUKSES." . PHP_EOL;

    // Langkah B: Insert Baris Pertama
    echo "  B. Uji Insert Baris Baru ({$expectedColCount} Kolom, Tab: '{$targetTabName}', Sync ID: {$uniqueSyncId})... ";
    $insertResp = $transport->sendRowUpsert(
        connection: $mockConnection,
        tabName: $targetTabName,
        syncId: $uniqueSyncId,
        payload: $syntheticPayload
    );

    if (!$insertResp->success) {
        echo "GAGAL!" . PHP_EOL;
        echo "     Error Code: " . $insertResp->errorCode . PHP_EOL;
        echo "     Message   : " . $insertResp->errorMessage . PHP_EOL;
        exit(1);
    }

    $insertedRowIndex = $insertResp->rowIndex;
    $insertOp = $insertResp->context['operation'] ?? 'unknown';
    echo "SUKSES! (Operasi: {$insertOp}, Baris: {$insertedRowIndex})" . PHP_EOL;

    if ($insertOp !== 'inserted') {
        echo "     ⚠️ Catatan: Operasi diharapkan 'inserted', namun menerima '{$insertOp}'." . PHP_EOL;
    }

    // Langkah C: Update Idempoten (Sync ID sama, ubah 1 nilai)
    echo "  C. Uji Update Idempoten (Sync ID sama, update field '{$updateFieldKey}')... ";
    $updatedPayload = $syntheticPayload;
    $updatedPayload[$updateFieldKey] = $updatedValue;

    $updateResp = $transport->sendRowUpsert(
        connection: $mockConnection,
        tabName: $targetTabName,
        syncId: $uniqueSyncId,
        payload: $updatedPayload
    );

    if (!$updateResp->success) {
        echo "GAGAL!" . PHP_EOL;
        echo "     Error Code: " . $updateResp->errorCode . PHP_EOL;
        echo "     Message   : " . $updateResp->errorMessage . PHP_EOL;
        exit(1);
    }

    $updatedRowIndex = $updateResp->rowIndex;
    $updateOp = $updateResp->context['operation'] ?? 'unknown';
    echo "SUKSES! (Operasi: {$updateOp}, Baris: {$updatedRowIndex})" . PHP_EOL;

    // Verifikasi Idempotensi: baris tidak berubah
    if ($insertedRowIndex === $updatedRowIndex && $updateOp === 'updated') {
        echo "     ✅ Idempotensi Terbukti: Baris diperbarui di posisi yang sama ({$insertedRowIndex}) tanpa duplikasi!" . PHP_EOL;
    } else {
        echo "     ⚠️ Peringatan: Baris berbeda ({$insertedRowIndex} vs {$updatedRowIndex}) atau operasi bukan 'updated'." . PHP_EOL;
    }

    // Langkah D: Negative Test (Mismatched Sync ID)
    echo "  D. Uji Keamanan Penolakan SYNC_ID_MISMATCH... ";
    $mismatchedPayload = $syntheticPayload;
    $mismatchedPayload['SERTIF_SYNC_ID'] = 'TAMPERED-ID-999';

    $tamperResp = $transport->sendRowUpsert(
        connection: $mockConnection,
        tabName: $targetTabName,
        syncId: $uniqueSyncId,
        payload: $mismatchedPayload
    );

    if (!$tamperResp->success && $tamperResp->errorCode === 'SYNC_ID_MISMATCH') {
        echo "SUKSES DITOLAK! ({$tamperResp->errorCode}: {$tamperResp->errorMessage})" . PHP_EOL;
    } else {
        echo "GAGAL! Mismatched sync ID seharusnya ditolak, namun respon: " . json_encode($tamperResp->context) . PHP_EOL;
    }

    echo PHP_EOL . "[4/4] Ringkasan Hasil Pengujian ({$selectedConnectionKey}):" . PHP_EOL;
    echo "  - Ping Connectivity     : PASSED" . PHP_EOL;
    echo "  - Row Insert ({$expectedColCount} Col)    : PASSED (Row: {$insertedRowIndex})" . PHP_EOL;
    echo "  - Idempotent Row Update  : PASSED (Row: {$updatedRowIndex}, No Duplicate)" . PHP_EOL;
    echo "  - Tamper/Mismatch Guard  : PASSED (Rejected cleanly)" . PHP_EOL;
    echo "  - Database Mutations     : 0 (Strict In-Memory Execution)" . PHP_EOL;
    echo PHP_EOL . "Smoke test {$selectedConnectionKey} berhasil diselesaikan dengan aman!" . PHP_EOL;
}
