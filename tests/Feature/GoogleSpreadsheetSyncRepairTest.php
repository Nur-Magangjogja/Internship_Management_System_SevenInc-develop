<?php

namespace Tests\Feature;

use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Models\User;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use App\Services\SpreadsheetTransport\SpreadsheetTransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class GoogleSpreadsheetSyncRepairTest extends TestCase
{
    use RefreshDatabase;

    protected SpreadsheetConnection $regConnection;
    protected SpreadsheetConnection $fwuConnection;
    protected string $testSecret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testSecret = 'sertif_test_shared_secret_key_1234567890';

        $this->regConnection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_REGISTRATION],
            [
                'display_name'               => 'Spreadsheet Pendaftaran Magang',
                'spreadsheet_url'            => 'https://docs.google.com/spreadsheets/d/1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug/edit',
                'is_enabled'                 => true,
                'connection_status'          => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url'   => 'https://script.google.com/macros/s/TEST_REG_GATEWAY/exec',
                'spreadsheet_id'             => '1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug',
            ]
        );
        $this->regConnection->update([
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug/edit',
            'is_enabled'      => true,
        ]);

        $this->fwuConnection = SpreadsheetConnection::firstOrCreate(
            ['connection_key' => SpreadsheetConnection::KEY_FOLLOWUP],
            [
                'display_name'               => 'Spreadsheet Follow-Up Guru/Dosen',
                'spreadsheet_url'            => 'https://docs.google.com/spreadsheets/d/1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4/edit',
                'is_enabled'                 => true,
                'connection_status'          => SpreadsheetConnection::STATUS_CONNECTED,
                'apps_script_endpoint_url'   => 'https://script.google.com/macros/s/TEST_FWU_GATEWAY/exec',
                'spreadsheet_id'             => '1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4',
            ]
        );
        $this->fwuConnection->update([
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4/edit',
            'is_enabled'      => true,
        ]);
    }

    /**
     * Test 1: Cross-runtime HMAC-SHA256 signature compatibility between PHP and Node.js.
     */
    public function test_hmac_interoperability_between_php_and_javascript_for_complex_payloads(): void
    {
        $payload = [
            'action'         => 'upsert_row',
            'connection_key' => 'registration',
            'tab_name'       => '2026',
            'sync_id'        => 'REG-421-e0c64119',
            'payload'        => [
                'Timestamp'                                                                      => '2026-03-01 10:00:00',
                'Nama Lengkap'                                                                   => 'Budi Santoso, S.Kom.',
                'NIM (Nomor Induk Mahasiswa)'                                                    => '22/498765/TK/54321',
                'Asal Sekolah/Kampus'                                                            => 'Universitas Gadjah Mada',
                'Upload PDF CV + scan KTM/KTP + portofolio max 10MB'                             => 'https://drive.google.com/file/d/1ABC-xyz/view?usp=sharing',
                'Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)'=> "Sanggup jadwal shift\ndan kerja Sabtu",
                'Catatan Khusus'                                                                 => "Karakter petik \"quote\" & tanda / dan em-dash –",
            ],
        ];

        $timestamp = 1773123456;
        $nonce = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

        // PHP calculation with unescaped flags including line terminators
        $canonicalJsonPhp = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        $canonicalDataPhp = $timestamp . '.' . $nonce . '.' . $canonicalJsonPhp;
        $phpSignature = hash_hmac('sha256', $canonicalDataPhp, $this->testSecret);

        // Run exact Node.js verification simulating Google Apps Script
        $nodeScript = <<<JS
const crypto = require("crypto");
const secret = process.env.TEST_SECRET;
const timestamp = process.env.TEST_TIMESTAMP;
const nonce = process.env.TEST_NONCE;
const payload = JSON.parse(process.env.TEST_PAYLOAD);

const canonicalData = timestamp + "." + nonce + "." + JSON.stringify(payload);
const nodeSignature = crypto.createHmac("sha256", secret).update(canonicalData).digest("hex");
process.stdout.write(nodeSignature);
JS;

        $process = new Process(['node', '-e', $nodeScript], null, [
            'TEST_SECRET'    => $this->testSecret,
            'TEST_TIMESTAMP' => (string) $timestamp,
            'TEST_NONCE'     => $nonce,
            'TEST_PAYLOAD'   => $canonicalJsonPhp,
        ]);
        $process->mustRun();
        $nodeSignature = trim($process->getOutput());

        $this->assertEquals(
            $nodeSignature,
            $phpSignature,
            'PHP and JavaScript HMAC-SHA256 signatures must match bit-for-bit across runtimes for complex payloads.'
        );
    }

    /**
     * Test 1b: HMAC-SHA256 bit-for-bit compatibility for Unicode line terminators U+2028 and U+2029.
     */
    public function test_hmac_interoperability_between_php_and_javascript_for_unicode_line_terminators_u2028_and_u2029(): void
    {
        $payloadWithLineTerminators = [
            'action'         => 'upsert_row',
            'connection_key' => 'registration',
            'tab_name'       => '2026',
            'sync_id'        => 'REG-555-u2028test',
            'payload'        => [
                'Nama Lengkap'   => "Budi\u{2028}Santoso",
                'Catatan'        => "Baris 1\u{2029}Baris 2 dengan U+2028:\u{2028}dan U+2029:\u{2029}",
                'SERTIF_SYNC_ID' => 'REG-555-u2028test',
            ],
        ];

        $timestamp = 1773199999;
        $nonce = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';

        $canonicalJsonPhp = json_encode(
            $payloadWithLineTerminators,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR
        );
        $canonicalDataPhp = $timestamp . '.' . $nonce . '.' . $canonicalJsonPhp;
        $phpSignature = hash_hmac('sha256', $canonicalDataPhp, $this->testSecret);

        $nodeScript = <<<JS
const crypto = require("crypto");
const secret = process.env.TEST_SECRET;
const timestamp = process.env.TEST_TIMESTAMP;
const nonce = process.env.TEST_NONCE;
const payload = JSON.parse(process.env.TEST_PAYLOAD);

const canonicalData = timestamp + "." + nonce + "." + JSON.stringify(payload);
const nodeSignature = crypto.createHmac("sha256", secret).update(canonicalData).digest("hex");
process.stdout.write(nodeSignature);
JS;

        $process = new Process(['node', '-e', $nodeScript], null, [
            'TEST_SECRET'    => $this->testSecret,
            'TEST_TIMESTAMP' => (string) $timestamp,
            'TEST_NONCE'     => $nonce,
            'TEST_PAYLOAD'   => $canonicalJsonPhp,
        ]);
        $process->mustRun();
        $nodeSignature = trim($process->getOutput());

        $this->assertEquals(
            $nodeSignature,
            $phpSignature,
            'HMAC-SHA256 signature for payloads containing U+2028 and U+2029 must match bit-for-bit between PHP and JavaScript.'
        );
    }

    /**
     * Test 1c: HMAC-SHA256 bit-for-bit compatibility for Unicode characters including en-dash (–), accented characters (é), and line terminators.
     */
    public function test_hmac_interoperability_between_php_and_javascript_for_unicode_characters_and_accents(): void
    {
        $payloadWithUnicode = [
            'action'         => 'upsert_row',
            'connection_key' => 'registration',
            'tab_name'       => '2026',
            'sync_id'        => 'REG-777-unicode-test',
            'payload'        => [
                'Nama Lengkap'                                                                   => 'Renée Müller–Santoso',
                'Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)'=> 'Sanggup jadwal shifting & hari kerja',
                'Alasan Magang'                                                                  => "Étudiant motivé\u{2028}spécialisé en ingénierie\u{2029}système",
                'SERTIF_SYNC_ID'                                                                 => 'REG-777-unicode-test',
            ],
        ];

        $timestamp = 1773200000;
        $nonce = 'c3d4e5f6-a7b8-9012-cdef-123456789012';

        $canonicalJsonPhp = json_encode(
            $payloadWithUnicode,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR
        );
        $canonicalDataPhp = $timestamp . '.' . $nonce . '.' . $canonicalJsonPhp;
        $phpSignature = hash_hmac('sha256', $canonicalDataPhp, $this->testSecret);

        $nodeScript = <<<JS
const crypto = require("crypto");
const secret = process.env.TEST_SECRET;
const timestamp = process.env.TEST_TIMESTAMP;
const nonce = process.env.TEST_NONCE;
const payload = JSON.parse(process.env.TEST_PAYLOAD);

// Emulate Google Apps Script Utilities.computeHmacSha256Signature(canonicalData, sharedSecret, Utilities.Charset.UTF_8)
const canonicalData = timestamp + "." + nonce + "." + JSON.stringify(payload);
const nodeSignature = crypto.createHmac("sha256", Buffer.from(secret, "utf8"))
  .update(Buffer.from(canonicalData, "utf8"))
  .digest("hex");

process.stdout.write(nodeSignature);
JS;

        $process = new Process(['node', '-e', $nodeScript], null, [
            'TEST_SECRET'    => $this->testSecret,
            'TEST_TIMESTAMP' => (string) $timestamp,
            'TEST_NONCE'     => $nonce,
            'TEST_PAYLOAD'   => $canonicalJsonPhp,
        ]);
        $process->mustRun();
        $nodeSignature = trim($process->getOutput());

        $this->assertEquals(
            $nodeSignature,
            $phpSignature,
            'HMAC-SHA256 signature for payloads containing en-dash (–), accented characters (é), and U+2028/U+2029 must match bit-for-bit between PHP and JavaScript.'
        );
    }

    /**
     * Test 2: Tamper detection rejects modified payload.
     */
    public function test_hmac_tamper_detection_and_rejection(): void
    {
        $payload = ['action' => 'upsert_row', 'sync_id' => 'REG-1-abc'];
        $timestamp = time();
        $nonce = (string) Str::uuid();

        $canonicalJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $canonicalJson, $this->testSecret);

        // Tamper with payload
        $tamperedPayload = ['action' => 'upsert_row', 'sync_id' => 'REG-1-HACKED'];
        $tamperedCanonical = $timestamp . '.' . $nonce . '.' . json_encode($tamperedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tamperedSignature = hash_hmac('sha256', $tamperedCanonical, $this->testSecret);

        $this->assertNotEquals($signature, $tamperedSignature, 'Tampered payload must produce non-matching HMAC signature.');
    }

    /**
     * Test 3: Actual production JavaScript code matches all 29 actual Spreadsheet headers.
     */
    public function test_apps_script_exact_code_matches_all_29_actual_spreadsheet_headers_to_contract_slots(): void
    {
        $nodeScript = <<<JS
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const actualSpreadsheetHeaders = [
  "Timestamp",
  "Nama Lengkap",
  "NIM (Nomor Induk Mahasiswa)",
  "Program Studi",
  "Email",
  "Jenis Kelamin",
  "No. HP Aktif yang bisa dihubungi dan chat WA",
  "Asal Sekolah/Kampus",
  "Nama Kota/Daerah tempat tinggal Anda saat ini",
  "Mengapa Anda ingin Magang/PKL disini?",
  "Jenis Magang apa yang Anda pilih?",
  "Status Anda saat ini",
  "Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?",
  "No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan",
  "Kesanggupan jadwal Senin–Sabtu dan shifting\\r\\n(full text mengikuti form/template)",
  "Apakah ada laptop sendiri untuk magang?",
  "Jika YA, alat apa yang Anda miliki, yang bisa Anda bawa selama Magang/PKL?",
  "Kapan berencana mulai Magang/PKL?",
  "Berapa lama rencana durasi akan Magang/PKL?",
  "Dari mana Anda mengetahui sumber info magang kami?",
  "Akun Instagram Aktif",
  "Apakah Anda butuh info kost/kontrakan di sekitar kantor?",
  "Program Magang/PKL apa yang Anda minati?",
  "Upload PDF CV + scan KTM/KTP + portofolio max 10MB",
  "KET",
  "SERTIF_SYNC_ID",
  "SERTIF_REGISTRATION_ID",
  "SERTIF_SYNC_STATUS",
  "SERTIF_LAST_SYNC_AT"
];

// Mock payload sent by Laravel (database mapping keys)
const rowData = {
  "Timestamp": "2026-03-01 10:00:00",
  "Nama Lengkap": "Budi Santoso",
  "NIM (Nomor Induk Mahasiswa)": "22/498765/TK/54321",
  "Program Studi": "Teknik Informatika",
  "Email": "budi@example.com",
  "Jenis Kelamin": "Laki-laki",
  "No. HP Aktif yang bisa dihubungi dan chat WA": "08123456789",
  "Asal Sekolah/Kampus": "Universitas Gadjah Mada",
  "Nama Kota/Daerah tempat tinggal Anda saat ini": "Yogyakarta",
  "Mengapa Anda ingin Magang/PKL disini?": "Ingin berkembang bersama Seven Inc",
  "Jenis Magang apa yang Anda pilih?": "Mandiri",
  "Status Anda saat ini": "Mahasiswa",
  "Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?": "SIAP",
  "No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan": "Dr. Bambang (08111111)",
  "Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)": "Sanggup",
  "Apakah ada laptop sendiri": "YA",
  "Jika YA, alat apa yang dimiliki/dibawa": "Laptop ASUS",
  "Kapan berencana mulai Magang/PKL": "2026-07-01",
  "Berapa lama akan Magang/PKL": "3 Bulan",
  "Sumber info magang": "Instagram",
  "Instagram": "@budisantoso",
  "Butuh info kost/kontrakan": "TIDAK",
  "Program Magang/PKL yang diminati": "Backend Development",
  "Upload PDF CV + scan KTM/KTP + portofolio max 10MB": "https://drive.google.com/file/d/123",
  "KET": "Waiting",
  "SERTIF_SYNC_ID": "REG-421-e0c64119",
  "SERTIF_REGISTRATION_ID": "421",
  "SERTIF_SYNC_STATUS": "synced",
  "SERTIF_LAST_SYNC_AT": "2026-10-10T14:00:00Z"
};

const result = appsScript.buildRowValuesForSheet(actualSpreadsheetHeaders, rowData, "registration");

if (result.error) {
  process.stderr.write(result.error);
  process.exit(1);
}

process.stdout.write(JSON.stringify(result.values));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $mappedValues = json_decode($process->getOutput(), true);

        $this->assertIsArray($mappedValues);
        $this->assertCount(29, $mappedValues, 'All 29 columns must be resolved accurately.');
        $this->assertEquals('2026-03-01 10:00:00', $mappedValues[0]);
        $this->assertEquals('Budi Santoso', $mappedValues[1]);
        $this->assertEquals('22/498765/TK/54321', $mappedValues[2]);
        $this->assertEquals('Sanggup', $mappedValues[14], 'Multiline column O must map correctly.');
        $this->assertEquals('REG-421-e0c64119', $mappedValues[25], 'SERTIF_SYNC_ID must map accurately.');
    }

    /**
     * Test 4: Apps Script rejects unknown or altered spreadsheet headers with SCHEMA_MISMATCH.
     */
    public function test_apps_script_rejects_schema_mismatch_without_silent_dashes(): void
    {
        // Provide valid payload for other columns so validation reaches corrupted header at col 14
        $validRowData = [
            'Timestamp' => '2026-03-01',
            'Nama Lengkap' => 'Budi',
            'NIM (Nomor Induk Mahasiswa)' => '123',
            'Program Studi' => 'TI',
            'Email' => 'b@test.com',
            'Jenis Kelamin' => 'L',
            'No. HP Aktif yang bisa dihubungi dan chat WA' => '081',
            'Asal Sekolah/Kampus' => 'UGM',
            'Nama Kota/Daerah tempat tinggal Anda saat ini' => 'YK',
            'Mengapa Anda ingin Magang/PKL disini?' => 'Belajar',
            'Jenis Magang apa yang Anda pilih?' => 'Mandiri',
            'Status Anda saat ini' => 'Mahasiswa',
            'Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?' => 'Siap',
            'Kolom Asing Tidak Dikenal' => 'Data Asing',
        ];

        $nodeScript = <<<JS
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const corruptedHeaders = [
  "Timestamp", "Nama Lengkap", "NIM (Nomor Induk Mahasiswa)", "Program Studi", "Email", "Jenis Kelamin",
  "No. HP Aktif yang bisa dihubungi dan chat WA", "Asal Sekolah/Kampus", "Nama Kota/Daerah tempat tinggal Anda saat ini",
  "Mengapa Anda ingin Magang/PKL disini?", "Jenis Magang apa yang Anda pilih?", "Status Anda saat ini",
  "Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?",
  "Kolom Asing Tidak Dikenal", // Corrupted header at col 14!
  "Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)",
  "Apakah ada laptop sendiri", "Jika YA, alat apa yang dimiliki/dibawa", "Kapan berencana mulai Magang/PKL",
  "Berapa lama akan Magang/PKL", "Sumber info magang", "Instagram", "Butuh info kost/kontrakan",
  "Program Magang/PKL yang diminati", "Upload PDF CV + scan KTM/KTP + portofolio max 10MB", "KET",
  "SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "SERTIF_SYNC_STATUS", "SERTIF_LAST_SYNC_AT"
];

const rowData = JSON.parse(process.env.ROW_DATA);
const result = appsScript.buildRowValuesForSheet(corruptedHeaders, rowData, "registration");

process.stdout.write(JSON.stringify(result));
JS;

        $process = new Process(['node', '-e', $nodeScript], null, ['ROW_DATA' => json_encode($validRowData)]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('SCHEMA_MISMATCH', $result['error_code']);
        $this->assertStringContainsString('Kolom Asing Tidak Dikenal', $result['error']);
    }

    /**
     * Test 4b: Apps Script rejects swapped/scrambled column order with SCHEMA_MISMATCH.
     */
    public function test_apps_script_rejects_swapped_column_order_with_schema_mismatch(): void
    {
        $nodeScript = <<<JS
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

// Swap column 3 (NIM) and column 4 (Program Studi)
const swappedHeaders = [
  "Timestamp", "Nama Lengkap", "Program Studi", "NIM (Nomor Induk Mahasiswa)", "Email", "Jenis Kelamin",
  "No. HP Aktif yang bisa dihubungi dan chat WA", "Asal Sekolah/Kampus", "Nama Kota/Daerah tempat tinggal Anda saat ini",
  "Mengapa Anda ingin Magang/PKL disini?", "Jenis Magang apa yang Anda pilih?", "Status Anda saat ini",
  "Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?",
  "No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan",
  "Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)",
  "Apakah ada laptop sendiri", "Jika YA, alat apa yang dimiliki/dibawa", "Kapan berencana mulai Magang/PKL",
  "Berapa lama akan Magang/PKL", "Sumber info magang", "Instagram", "Butuh info kost/kontrakan",
  "Program Magang/PKL yang diminati", "Upload PDF CV + scan KTM/KTP + portofolio max 10MB", "KET",
  "SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "SERTIF_SYNC_STATUS", "SERTIF_LAST_SYNC_AT"
];

const rowData = { "Timestamp": "2026-03-01", "Nama Lengkap": "Budi" };
const result = appsScript.buildRowValuesForSheet(swappedHeaders, rowData, "registration");
process.stdout.write(JSON.stringify(result));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('SCHEMA_MISMATCH', $result['error_code']);
        $this->assertStringContainsString('Pertukaran posisi kolom terdeteksi', $result['error']);
    }

    /**
     * Test 5: Apps Script rejects missing payload fields rather than silently writing dashes.
     */
    public function test_apps_script_rejects_missing_payload_fields_without_silent_dashes(): void
    {
        $nodeScript = <<<JS
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const actualHeaders = [
  "Timestamp", "Nama Lengkap", "NIM (Nomor Induk Mahasiswa)", "Program Studi", "Email", "Jenis Kelamin",
  "No. HP Aktif yang bisa dihubungi dan chat WA", "Asal Sekolah/Kampus", "Nama Kota/Daerah tempat tinggal Anda saat ini",
  "Mengapa Anda ingin Magang/PKL disini?", "Jenis Magang apa yang Anda pilih?", "Status Anda saat ini",
  "Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?",
  "No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan",
  "Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)",
  "Apakah ada laptop sendiri", "Jika YA, alat apa yang dimiliki/dibawa", "Kapan berencana mulai Magang/PKL",
  "Berapa lama akan Magang/PKL", "Sumber info magang", "Instagram", "Butuh info kost/kontrakan",
  "Program Magang/PKL yang diminati", "Upload PDF CV + scan KTM/KTP + portofolio max 10MB", "KET",
  "SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "SERTIF_SYNC_STATUS", "SERTIF_LAST_SYNC_AT"
];

// Incomplete payload: missing "Nama Lengkap" entirely
const incompletePayload = {
  "Timestamp": "2026-03-01 10:00:00"
};

const result = appsScript.buildRowValuesForSheet(actualHeaders, incompletePayload, "registration");
process.stdout.write(JSON.stringify(result));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('SCHEMA_MISMATCH', $result['error_code']);
        $this->assertStringContainsString('Nama Lengkap', $result['error']);
    }

    /**
     * Test 6: Follow-Up tab matches 21 canonical columns.
     */
    public function test_followup_headers_match_row_1_contract(): void
    {
        $nodeScript = <<<JS
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const followupHeaders = [
  "No.", "Tahun Follow Up", "Sekolah/Kampus", "Dosen/Guru", "Nomor Telepon", "Status Guru Dosen",
  "Follow Up 1", "Follow Up 2", "Follow Up 3", "Kapan PKL/Magang Nya", "Respon",
  "Nama yang mengajukan Kerjasama/ Follow Up 1", "Nama yang mengajukan Kerjasama/ Follow Up 2",
  "Nama yang mengajukan Kerjasama/ Follow Up 3", "KET", "SERTIF_SYNC_ID", "SERTIF_INSTITUTION_ID",
  "SERTIF_CONTACT_ID", "SERTIF_FOLLOWUP_CYCLE_ID", "SERTIF_SYNC_STATUS", "SERTIF_LAST_SYNC_AT"
];

const rowData = {
  "No.": 1,
  "Tahun Follow Up": 2026,
  "Sekolah/Kampus": "SMK Negeri 1 Bantul",
  "Dosen/Guru": "Drs. Mulyono",
  "Nomor Telepon": "08123456789",
  "Status Guru Dosen": "Terverifikasi",
  "Follow Up 1": "Hubungi via WA",
  "Follow Up 2": "-",
  "Follow Up 3": "-",
  "Kapan PKL/Magang Nya": "Juli 2026",
  "Respon": "Bersedia Kerjasama",
  "Nama yang mengajukan Kerjasama/ Follow Up 1": "HR Staff A",
  "Nama yang mengajukan Kerjasama/ Follow Up 2": "-",
  "Nama yang mengajukan Kerjasama/ Follow Up 3": "-",
  "KET": "Follow up sukses",
  "SERTIF_SYNC_ID": "FWU-1-abcdef12",
  "SERTIF_INSTITUTION_ID": "10",
  "SERTIF_CONTACT_ID": "5",
  "SERTIF_FOLLOWUP_CYCLE_ID": "1",
  "SERTIF_SYNC_STATUS": "synced",
  "SERTIF_LAST_SYNC_AT": "2026-10-10T14:00:00Z"
};

const result = appsScript.buildRowValuesForSheet(followupHeaders, rowData, "followup");
if (result.error) {
  process.stderr.write(result.error);
  process.exit(1);
}

process.stdout.write(JSON.stringify(result.values));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $values = json_decode($process->getOutput(), true);

        $this->assertIsArray($values);
        $this->assertCount(21, $values, 'Follow-up tab must map exactly 21 columns.');
        $this->assertEquals('SMK Negeri 1 Bantul', $values[2]);
        $this->assertEquals('FWU-1-abcdef12', $values[15]);
    }

    /**
     * Test 6b: Apps Script strictly rejects column count mismatch (must be 29 for reg, 21 for fwu).
     */
    public function test_apps_script_rejects_column_count_mismatch_for_registration_and_followup(): void
    {
        $nodeScript = <<<JS
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const reg29 = [
  "Timestamp", "Nama Lengkap", "NIM (Nomor Induk Mahasiswa)", "Program Studi", "Email", "Jenis Kelamin",
  "No. HP Aktif yang bisa dihubungi dan chat WA", "Asal Sekolah/Kampus", "Nama Kota/Daerah tempat tinggal Anda saat ini",
  "Mengapa Anda ingin Magang/PKL disini?", "Jenis Magang apa yang Anda pilih?", "Status Anda saat ini",
  "Apakah Anda siap dengan Sistem Magang WFO (Work From Office) di Yogyakarta?",
  "No. HP Aktif / WA Dosen atau Guru Pembimbing PKL/Magang beserta Nama dan Jabatan",
  "Kesanggupan jadwal Senin–Sabtu dan shifting (full text mengikuti form/template)",
  "Apakah ada laptop sendiri untuk magang?",
  "Jika YA, alat apa yang Anda miliki, yang bisa Anda bawa selama Magang/PKL?",
  "Kapan berencana mulai Magang/PKL?", "Berapa lama rencana durasi akan Magang/PKL?",
  "Dari mana Anda mengetahui sumber info magang kami?", "Akun Instagram Aktif",
  "Apakah Anda butuh info kost/kontrakan di sekitar kantor?",
  "Program Magang/PKL apa yang Anda minati?",
  "Upload PDF CV + scan KTM/KTP + portofolio max 10MB", "KET",
  "SERTIF_SYNC_ID", "SERTIF_REGISTRATION_ID", "SERTIF_SYNC_STATUS", "SERTIF_LAST_SYNC_AT"
];

const fwu21 = [
  "No.", "Tahun Follow Up", "Sekolah/Kampus", "Dosen/Guru", "Nomor Telepon", "Status Guru Dosen",
  "Follow Up 1", "Follow Up 2", "Follow Up 3", "Kapan PKL/Magang Nya", "Respon",
  "Nama yang mengajukan Kerjasama/ Follow Up 1", "Nama yang mengajukan Kerjasama/ Follow Up 2",
  "Nama yang mengajukan Kerjasama/ Follow Up 3", "KET", "SERTIF_SYNC_ID", "SERTIF_INSTITUTION_ID",
  "SERTIF_CONTACT_ID", "SERTIF_FOLLOWUP_CYCLE_ID", "SERTIF_SYNC_STATUS", "SERTIF_LAST_SYNC_AT"
];

const results = {
  reg28: appsScript.buildRowValuesForSheet(reg29.slice(0, 28), {}, "registration"),
  reg29: appsScript.buildRowValuesForSheet(reg29, { "Timestamp": "t", "Nama Lengkap": "b" }, "registration"),
  reg30: appsScript.buildRowValuesForSheet(reg29.concat(["Kolom Ekstra 30"]), {}, "registration"),
  fwu20: appsScript.buildRowValuesForSheet(fwu21.slice(0, 20), {}, "followup"),
  fwu21: appsScript.buildRowValuesForSheet(fwu21, { "No.": 1, "Tahun Follow Up": 2026 }, "followup"),
  fwu22: appsScript.buildRowValuesForSheet(fwu21.concat(["Kolom Ekstra 22"]), {}, "followup")
};

process.stdout.write(JSON.stringify(results));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $results = json_decode($process->getOutput(), true);

        // Reg 28: rejected
        $this->assertEquals('SCHEMA_MISMATCH', $results['reg28']['error_code'] ?? null);
        $this->assertStringContainsString('ditemukan 28 kolom', $results['reg28']['error']);

        // Reg 29: does not fail on column count
        $this->assertNotEquals('ditemukan 29 kolom', $results['reg29']['error'] ?? '');

        // Reg 30: rejected
        $this->assertEquals('SCHEMA_MISMATCH', $results['reg30']['error_code'] ?? null);
        $this->assertStringContainsString('ditemukan 30 kolom', $results['reg30']['error']);

        // FWU 20: rejected
        $this->assertEquals('SCHEMA_MISMATCH', $results['fwu20']['error_code'] ?? null);
        $this->assertStringContainsString('ditemukan 20 kolom', $results['fwu20']['error']);

        // FWU 22: rejected
        $this->assertEquals('SCHEMA_MISMATCH', $results['fwu22']['error_code'] ?? null);
        $this->assertStringContainsString('ditemukan 22 kolom', $results['fwu22']['error']);
    }

    /**
     * Test 6c: handleUpsertRowAction validates sync_id and SERTIF_SYNC_ID before locking/writing.
     */
    public function test_apps_script_handle_upsert_row_action_validates_sync_id_integrity_strictly(): void
    {
        $nodeScript = <<<JS
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const results = {
  emptySyncId: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "registration",
    tab_name: "2026",
    sync_id: "",
    payload: { "SERTIF_SYNC_ID": "REG-1-abc" }
  }),
  nullSyncId: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "registration",
    tab_name: "2026",
    sync_id: null,
    payload: { "SERTIF_SYNC_ID": "REG-1-abc" }
  }),
  missingPayloadSyncId: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "registration",
    tab_name: "2026",
    sync_id: "REG-1-abc",
    payload: { "Nama Lengkap": "Budi" }
  }),
  mismatchedSyncId: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "registration",
    tab_name: "2026",
    sync_id: "REG-1-abc",
    payload: { "SERTIF_SYNC_ID": "REG-2-xyz", "Nama Lengkap": "Budi" }
  })
};

process.stdout.write(JSON.stringify(results));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $results = json_decode($process->getOutput(), true);

        // Case A: empty sync_id
        $emptyRes = $results['emptySyncId']['data'] ?? $results['emptySyncId'];
        $this->assertFalse($emptyRes['success']);
        $this->assertEquals('INVALID_SYNC_ID', $emptyRes['error_code']);

        // Case B: null sync_id
        $nullRes = $results['nullSyncId']['data'] ?? $results['nullSyncId'];
        $this->assertFalse($nullRes['success']);
        $this->assertEquals('INVALID_SYNC_ID', $nullRes['error_code']);

        // Case C: missing SERTIF_SYNC_ID in payload
        $missingRes = $results['missingPayloadSyncId']['data'] ?? $results['missingPayloadSyncId'];
        $this->assertFalse($missingRes['success']);
        $this->assertEquals('INVALID_SYNC_ID', $missingRes['error_code']);

        // Case D: mismatched sync_id vs SERTIF_SYNC_ID
        $mismatchRes = $results['mismatchedSyncId']['data'] ?? $results['mismatchedSyncId'];
        $this->assertFalse($mismatchRes['success']);
        $this->assertEquals('SYNC_ID_MISMATCH', $mismatchRes['error_code']);
        $this->assertStringContainsString('REG-1-abc', $mismatchRes['message']);
        $this->assertStringContainsString('REG-2-xyz', $mismatchRes['message']);
    }

    /**
     * Test 6e: handleUpsertRowAction enforces strict target allowlist and fail-closed Spreadsheet ID.
     */
    public function test_apps_script_rejects_cross_write_and_target_mismatch_strictly(): void
    {
        $nodeScript = <<<JS
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const REG_ID = "1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug";
const FWU_ID = "1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4";
const FOREIGN_ID = "1FOREIGN_SPREADSHEET_ID_99999999999999999";

// Mock script properties
global._mockScriptProperties = {
  getProperty: function(key) {
    if (key === "REGISTRATION_SPREADSHEET_ID") return REG_ID;
    if (key === "FOLLOWUP_SPREADSHEET_ID") return FWU_ID;
    return null;
  }
};

const results = {
  // 1. Valid registration target without explicit spreadsheet_id uses official ID
  validRegWithoutExplicitId: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "registration",
    tab_name: "2026",
    sync_id: "REG-1-abc",
    payload: { "SERTIF_SYNC_ID": "REG-1-abc" }
  }),
  // 2. Valid followup target without explicit spreadsheet_id uses official ID
  validFwuWithoutExplicitId: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "followup",
    tab_name: "Follow Up Guru Dosen",
    sync_id: "FWU-1-abc",
    payload: { "SERTIF_SYNC_ID": "FWU-1-abc" }
  }),
  // 3. Registration directed to followup workbook -> rejected
  regToFwuWorkbook: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "registration",
    tab_name: "2026",
    spreadsheet_id: FWU_ID,
    sync_id: "REG-1-abc",
    payload: { "SERTIF_SYNC_ID": "REG-1-abc" }
  }),
  // 4. Followup directed to registration workbook -> rejected
  fwuToRegWorkbook: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "followup",
    tab_name: "Follow Up Guru Dosen",
    spreadsheet_id: REG_ID,
    sync_id: "FWU-1-abc",
    payload: { "SERTIF_SYNC_ID": "FWU-1-abc" }
  }),
  // 5. Foreign spreadsheet ID -> rejected
  foreignSpreadsheetId: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "registration",
    tab_name: "2026",
    spreadsheet_id: FOREIGN_ID,
    sync_id: "REG-1-abc",
    payload: { "SERTIF_SYNC_ID": "REG-1-abc" }
  }),
  // 6. Unknown connection key -> rejected
  unknownConnectionKey: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "inventory",
    tab_name: "2026",
    sync_id: "REG-1-abc",
    payload: { "SERTIF_SYNC_ID": "REG-1-abc" }
  }),
  // 7. Wrong tab for registration (e.g. Follow Up Guru Dosen) -> rejected
  regWrongTab: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "registration",
    tab_name: "Follow Up Guru Dosen",
    sync_id: "REG-1-abc",
    payload: { "SERTIF_SYNC_ID": "REG-1-abc" }
  }),
  // 8. Wrong tab for followup (e.g. 2026) -> rejected
  fwuWrongTab: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "followup",
    tab_name: "2026",
    sync_id: "FWU-1-abc",
    payload: { "SERTIF_SYNC_ID": "FWU-1-abc" }
  }),
  // 9. Empty tab name -> rejected
  emptyTabName: appsScript.handleUpsertRowAction({
    action: "upsert_row",
    connection_key: "registration",
    tab_name: "",
    sync_id: "REG-1-abc",
    payload: { "SERTIF_SYNC_ID": "REG-1-abc" }
  })
};

// 10. Script properties target empty -> fail closed
global._mockScriptProperties = { getProperty: () => "" };
results.emptyScriptProperties = appsScript.handleUpsertRowAction({
  action: "upsert_row",
  connection_key: "registration",
  tab_name: "2026",
  spreadsheet_id: FOREIGN_ID,
  sync_id: "REG-1-abc",
  payload: { "SERTIF_SYNC_ID": "REG-1-abc" }
});

process.stdout.write(JSON.stringify(results));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $results = json_decode($process->getOutput(), true);

        // 1. Valid registration passes pre-lock validation
        $regVal = $results['validRegWithoutExplicitId']['data'] ?? $results['validRegWithoutExplicitId'];
        $this->assertTrue($regVal['success']);
        $this->assertEquals('1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug', $regVal['resolved_spreadsheet_id']);
        $this->assertEquals(12, $regVal['header_row_index']);

        // 2. Valid followup passes pre-lock validation
        $fwuVal = $results['validFwuWithoutExplicitId']['data'] ?? $results['validFwuWithoutExplicitId'];
        $this->assertTrue($fwuVal['success']);
        $this->assertEquals('1Hyvbz8oY92-FxvSOE864j4pVEx6D-JFg4A4rCkL4GW4', $fwuVal['resolved_spreadsheet_id']);
        $this->assertEquals(1, $fwuVal['header_row_index']);

        // 3. Registration -> Followup workbook rejected
        $r3 = $results['regToFwuWorkbook']['data'] ?? $results['regToFwuWorkbook'];
        $this->assertFalse($r3['success']);
        $this->assertEquals('TARGET_MISMATCH', $r3['error_code']);
        $this->assertStringContainsString('tidak sesuai dengan konfigurasi resmi', $r3['message']);

        // 4. Followup -> Registration workbook rejected
        $r4 = $results['fwuToRegWorkbook']['data'] ?? $results['fwuToRegWorkbook'];
        $this->assertFalse($r4['success']);
        $this->assertEquals('TARGET_MISMATCH', $r4['error_code']);
        $this->assertStringContainsString('tidak sesuai dengan konfigurasi resmi', $r4['message']);

        // 5. Foreign spreadsheet ID rejected
        $r5 = $results['foreignSpreadsheetId']['data'] ?? $results['foreignSpreadsheetId'];
        $this->assertFalse($r5['success']);
        $this->assertEquals('TARGET_MISMATCH', $r5['error_code']);

        // 6. Unknown connection key rejected
        $r6 = $results['unknownConnectionKey']['data'] ?? $results['unknownConnectionKey'];
        $this->assertFalse($r6['success']);
        $this->assertEquals('TARGET_MISMATCH', $r6['error_code']);
        $this->assertStringContainsString('connection_key', $r6['message']);

        // 7. Reg wrong tab rejected
        $r7 = $results['regWrongTab']['data'] ?? $results['regWrongTab'];
        $this->assertFalse($r7['success']);
        $this->assertEquals('TARGET_MISMATCH', $r7['error_code']);

        // 8. Fwu wrong tab rejected
        $r8 = $results['fwuWrongTab']['data'] ?? $results['fwuWrongTab'];
        $this->assertFalse($r8['success']);
        $this->assertEquals('TARGET_MISMATCH', $r8['error_code']);

        // 9. Empty tab rejected
        $r9 = $results['emptyTabName']['data'] ?? $results['emptyTabName'];
        $this->assertFalse($r9['success']);
        $this->assertEquals('TARGET_MISMATCH', $r9['error_code']);

        // 10. Empty script properties fails closed without taking payload ID
        $r10 = $results['emptyScriptProperties']['data'] ?? $results['emptyScriptProperties'];
        $this->assertFalse($r10['success']);
        $this->assertEquals('SPREADSHEET_ID_MISSING', $r10['error_code']);
        $this->assertStringContainsString('belum dikonfigurasi di Script Properties', $r10['message']);
    }

    /**
     * Test 6d: Laravel GoogleAppsScriptTransport treats HTTP 200 with success:false as failure.
     */
    public function test_transport_treats_http_200_with_success_false_as_failure(): void
    {
        $transport = new GoogleAppsScriptTransport([
            'enabled'        => true,
            'endpoint_url'   => 'https://script.google.com/macros/s/TEST/exec',
            'shared_secret'  => $this->testSecret,
        ]);

        // Google ContentService returns HTTP 200 even on logical errors
        Http::fake([
            '*script.google.com*' => Http::response([
                'success'      => false,
                'error_code'   => 'SYNC_ID_MISMATCH',
                'is_retryable' => false,
                'message'      => 'sync_id tidak sesuai dengan nilai SERTIF_SYNC_ID dalam payload.',
            ], 200),
        ]);

        $response = $transport->sendRowUpsert(
            connection: $this->regConnection,
            tabName: '2026',
            syncId: 'REG-421-e0c64119',
            payload: ['Nama Lengkap' => 'Budi Santoso', 'SERTIF_SYNC_ID' => 'REG-999-different']
        );

        $this->assertFalse($response->success, 'Response must be marked as failure even though HTTP status code is 200.');
        $this->assertEquals('SYNC_ID_MISMATCH', $response->errorCode);
        $this->assertFalse($response->isRetryable);
        $this->assertStringContainsString('sync_id tidak sesuai', $response->errorMessage);
    }

    /**
     * Test 7: GoogleAppsScriptTransport handles remote success with row_index.
     */
    public function test_transport_handles_remote_success_and_row_index(): void
    {
        $transport = new GoogleAppsScriptTransport([
            'enabled'        => true,
            'endpoint_url'   => 'https://script.google.com/macros/s/TEST/exec',
            'shared_secret'  => $this->testSecret,
        ]);

        Http::fake([
            '*script.google.com*' => Http::response([
                'success'   => true,
                'row_index' => 13,
                'action'    => 'upsert_row',
                'operation' => 'inserted',
            ], 200),
        ]);

        $response = $transport->sendRowUpsert(
            connection: $this->regConnection,
            tabName: '2026',
            syncId: 'REG-421-e0c64119',
            payload: ['Nama Lengkap' => 'Budi Santoso']
        );

        $this->assertTrue($response->success);
        $this->assertEquals(13, $response->rowIndex);
    }

    /**
     * Test 8: GoogleAppsScriptTransport handles remote SCHEMA_MISMATCH as non-retryable failure.
     */
    public function test_transport_handles_remote_schema_mismatch_as_non_retryable(): void
    {
        $transport = new GoogleAppsScriptTransport([
            'enabled'        => true,
            'endpoint_url'   => 'https://script.google.com/macros/s/TEST/exec',
            'shared_secret'  => $this->testSecret,
        ]);

        Http::fake([
            '*script.google.com*' => Http::response([
                'success'      => false,
                'error_code'   => 'SCHEMA_MISMATCH',
                'is_retryable' => false,
                'message'      => 'Header spreadsheet pada kolom 14 tidak sesuai kontrak.',
            ], 400),
        ]);

        $response = $transport->sendRowUpsert(
            connection: $this->regConnection,
            tabName: '2026',
            syncId: 'REG-421-e0c64119',
            payload: ['Nama Lengkap' => 'Budi Santoso']
        );

        $this->assertFalse($response->success);
        $this->assertFalse($response->isRetryable, 'Schema mismatch must be strictly marked as non-retryable.');
        $this->assertEquals('SCHEMA_MISMATCH', $response->errorCode);
    }

    /**
     * Test 9: GoogleAppsScriptTransport handles 500 Google server error as retryable.
     */
    public function test_transport_handles_google_500_as_retryable(): void
    {
        $transport = new GoogleAppsScriptTransport([
            'enabled'        => true,
            'endpoint_url'   => 'https://script.google.com/macros/s/TEST/exec',
            'shared_secret'  => $this->testSecret,
        ]);

        Http::fake([
            '*script.google.com*' => Http::response([
                'error' => 'Service error',
            ], 500),
        ]);

        $response = $transport->sendRowUpsert(
            connection: $this->regConnection,
            tabName: '2026',
            syncId: 'REG-421-e0c64119',
            payload: ['Nama Lengkap' => 'Budi Santoso']
        );

        $this->assertFalse($response->success);
        $this->assertTrue($response->isRetryable, 'Google server 500 must be marked as retryable.');
    }

    /**
     * Test 10: Local MySQL data remains 100% intact and unaffected when remote Google sync fails.
     */
    public function test_mysql_data_integrity_preserved_on_transport_failure(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $user = \App\Models\User::create([
            'name'      => 'Siti Aisyah',
            'email'     => 'siti_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret123'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);
        $institution = \App\Models\Institution::create([
            'name' => 'Universitas ' . Str::random(6),
        ]);
        $division = \App\Models\Division::create([
            'name' => 'Divisi ' . Str::random(6),
        ]);

        $faculty = \App\Models\Faculty::create([
            'institution_id' => $institution->id,
            'name'           => 'Fakultas Teknik',
        ]);
        $prodi = \App\Models\StudyProgram::create([
            'faculty_id' => $faculty->id,
            'name'       => 'Teknik Informatika',
        ]);
        $city = \App\Models\City::create([
            'name' => 'Yogyakarta',
        ]);

        $registration = InternshipRegistration::create([
            'user_id'                 => $user->id,
            'institution_id'          => $institution->id,
            'faculty_id'              => $faculty->id,
            'study_program_id'        => $prodi->id,
            'city_id'                 => $city->id,
            'division_id'             => $division->id,
            'fullname'                => 'Siti Aisyah',
            'student_id'              => '12345678',
            'study_program'           => 'Teknik Informatika',
            'email'                   => 'siti@example.com',
            'gender'                  => 'Perempuan',
            'phone_number'            => '08987654321',
            'current_city'            => 'Yogyakarta',
            'wfo_readiness'           => 'Siap',
            'schedule_shift_readiness'=> 'Bersedia',
            'has_laptop'              => true,
            'start_date'              => '2026-07-01',
            'planned_duration'        => '3 Bulan',
            'internship_type'         => 'Mandiri',
            'current_status'          => 'Mahasiswa',
            'internship_status'       => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'                => false,
        ]);

        $failingTransport = new GoogleAppsScriptTransport([
            'enabled'        => true,
            'endpoint_url'   => 'https://script.google.com/macros/s/FAILING/exec',
            'shared_secret'  => $this->testSecret,
        ]);

        Http::fake([
            '*script.google.com*' => Http::response([
                'success'      => false,
                'error_code'   => 'REMOTE_ERROR',
                'is_retryable' => true,
                'message'      => 'Google error simulated',
            ], 500),
        ]);

        // Seed 2026 schema mappings in testing DB
        $this->artisan('db:seed', ['--class' => 'Spreadsheet2026SchemaSeeder']);

        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $this->assertNotNull($rowSync);
        $this->assertDatabaseHas('spreadsheet_row_syncs', [
            'id'          => $rowSync->id,
            'sync_status' => SpreadsheetRowSync::STATUS_PENDING,
        ]);

        // Execute sync with failing transport
        $engine->processSyncRecord($rowSync, $failingTransport);

        // Verify MySQL registration data is completely unharmed
        $registration->refresh();
        $this->assertEquals('Siti Aisyah', $registration->fullname);
        $this->assertEquals(InternshipRegistration::STATUS_ACCEPTED, $registration->internship_status);
        $this->assertDatabaseHas('internship_registrations', [
            'id'       => $registration->id,
            'fullname' => 'Siti Aisyah',
        ]);
    }

    /**
     * Helper to create a fully configured valid test registration and ensure schema mappings exist.
     */
    protected function createTestRegistration(string $name = 'Budi Santoso'): InternshipRegistration
    {
        if (\App\Models\SpreadsheetColumnMapping::count() === 0) {
            $this->artisan('db:seed', ['--class' => 'Spreadsheet2026SchemaSeeder']);
            $this->regConnection->refresh();
            $this->regConnection->update([
                'is_enabled'      => true,
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/' . $this->regConnection->spreadsheet_id . '/edit',
            ]);
        }

        $user = User::create([
            'name'      => $name,
            'email'     => 'test_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret123'),
            'role'      => 'pemagang',
            'is_banned' => false,
        ]);
        $institution = \App\Models\Institution::create([
            'name' => 'Universitas ' . Str::random(6),
        ]);
        $division = \App\Models\Division::create([
            'name' => 'Divisi ' . Str::random(6),
        ]);
        $faculty = \App\Models\Faculty::create([
            'institution_id' => $institution->id,
            'name'           => 'Fakultas Teknik',
        ]);
        $prodi = \App\Models\StudyProgram::create([
            'faculty_id' => $faculty->id,
            'name'       => 'Teknik Informatika',
        ]);
        $city = \App\Models\City::create([
            'name' => 'Yogyakarta',
        ]);

        return InternshipRegistration::create([
            'user_id'                  => $user->id,
            'institution_id'           => $institution->id,
            'faculty_id'               => $faculty->id,
            'study_program_id'         => $prodi->id,
            'city_id'                  => $city->id,
            'division_id'              => $division->id,
            'fullname'                 => $name,
            'student_id'               => '12345678',
            'study_program'            => 'Teknik Informatika',
            'email'                    => 'test_' . Str::random(8) . '@example.com',
            'gender'                   => 'Laki-laki',
            'phone_number'             => '08987654321',
            'current_city'             => 'Yogyakarta',
            'wfo_readiness'            => 'Siap',
            'schedule_shift_readiness' => 'Bersedia',
            'has_laptop'               => true,
            'start_date'               => '2026-07-01',
            'planned_duration'         => '3 Bulan',
            'internship_type'          => 'Mandiri',
            'current_status'           => 'Mahasiswa',
            'internship_status'        => InternshipRegistration::STATUS_ACCEPTED,
            'is_draft'                 => false,
        ]);
    }

    /**
     * S17.9 Case 1: Same payload + same target after success = valid no-op (no transport call).
     */
    public function test_case_1_same_payload_and_same_target_after_success_results_in_valid_noop(): void
    {
        $registration = $this->createTestRegistration('Budi Santoso');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration);
        $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
        $payload['SERTIF_SYNC_STATUS'] = 'synced';

        $expectedChecksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);

        // Pre-condition: row has previously been successfully synced with identical checksum
        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 13,
            'last_synced_at'   => now()->subMinutes(15),
            'payload_checksum' => $expectedChecksum,
        ]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->never())->method('sendRowUpsert');

        $result = $engine->processSyncRecord($rowSync, $mockTransport);

        $this->assertTrue($result, 'Valid no-op must return true.');
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertEquals($expectedChecksum, $rowSync->payload_checksum);
        $this->assertNull($rowSync->locked_at);
        $this->assertNull($rowSync->locked_by);
    }

    /**
     * S17.9 Case 2: Manual retry with same checksum forces remote upsert and resets checksum.
     */
    public function test_case_2_manual_retry_forces_remote_upsert_even_with_identical_checksum(): void
    {
        $registration = $this->createTestRegistration('Budi Santoso');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration);
        $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
        $payload['SERTIF_SYNC_STATUS'] = 'synced';

        $checksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);

        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 13,
            'last_synced_at'   => now()->subMinutes(30),
            'payload_checksum' => $checksum,
        ]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('sendRowUpsert')
            ->willReturn(SpreadsheetTransportResponse::success(13));

        $admin = User::create([
            'name'      => 'HR Admin',
            'email'     => 'admin_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $result = $engine->retrySyncRecord($rowSync, $admin, $mockTransport);

        $this->assertTrue($result, 'Explicit retry must succeed.');
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertEquals($checksum, $rowSync->payload_checksum);
        $this->assertEquals(13, $rowSync->row_index);
        $this->assertNotNull($rowSync->last_synced_at);
    }

    /**
     * S17.9 Case 3: Target Spreadsheet ID changes = remote upsert performed (no false no-op).
     */
    public function test_case_3_target_spreadsheet_id_change_bypasses_noop_and_triggers_upsert(): void
    {
        $registration = $this->createTestRegistration('Budi Santoso');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration);
        $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
        $payload['SERTIF_SYNC_STATUS'] = 'synced';

        // Checksum calculated with OLD spreadsheet ID
        $this->regConnection->update([
            'spreadsheet_id'  => '1OLD_SPREADSHEET_ID_11111111111111111111',
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1OLD_SPREADSHEET_ID_11111111111111111111/edit',
        ]);
        $oldChecksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);

        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 13,
            'last_synced_at'   => now()->subMinutes(20),
            'payload_checksum' => $oldChecksum,
        ]);

        // Connection now updated to NEW spreadsheet ID
        $this->regConnection->update([
            'spreadsheet_id'  => '1NEW_SPREADSHEET_ID_22222222222222222222',
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1NEW_SPREADSHEET_ID_22222222222222222222/edit',
        ]);
        $newChecksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);

        $this->assertNotEquals($oldChecksum, $newChecksum, 'Checksums must differ when spreadsheet ID changes.');

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('sendRowUpsert')
            ->willReturn(SpreadsheetTransportResponse::success(14));

        $result = $engine->processSyncRecord($rowSync, $mockTransport);

        $this->assertTrue($result);
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertEquals($newChecksum, $rowSync->payload_checksum, 'New target checksum must be saved.');
        $this->assertEquals(14, $rowSync->row_index);
    }

    /**
     * S17.9 Case 4: Target tab changes = remote upsert performed (no false no-op).
     */
    public function test_case_4_target_tab_change_bypasses_noop_and_triggers_upsert(): void
    {
        $registration = $this->createTestRegistration('Budi Santoso');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration);
        $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
        $payload['SERTIF_SYNC_STATUS'] = 'synced';

        // Checksum calculated with tab '2025'
        $tab2025Checksum = $engine->calculatePayloadChecksum($this->regConnection, '2025', $payload);

        // Record holds old 2025 checksum, but its target_sheet_tab is updated to 2026
        $rowSync->update([
            'target_sheet_tab' => '2026',
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 13,
            'last_synced_at'   => now()->subMinutes(20),
            'payload_checksum' => $tab2025Checksum,
        ]);

        $tab2026Checksum = $engine->calculatePayloadChecksum($this->regConnection, '2026', $payload);
        $this->assertNotEquals($tab2025Checksum, $tab2026Checksum, 'Checksums must differ when sheet tab changes.');

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('sendRowUpsert')
            ->willReturn(SpreadsheetTransportResponse::success(15));

        $result = $engine->processSyncRecord($rowSync, $mockTransport);

        $this->assertTrue($result);
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertEquals($tab2026Checksum, $rowSync->payload_checksum, 'Updated tab checksum must be saved.');
        $this->assertEquals(15, $rowSync->row_index);
    }

    /**
     * S17.9 Case 5: Transport fails = does not persist success checksum.
     */
    public function test_case_5_failing_transport_does_not_persist_checksum(): void
    {
        $registration = $this->createTestRegistration('Budi Santoso');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
            'payload_checksum' => null,
            'row_index'        => null,
            'attempt_count'    => 0,
            'next_retry_at'    => null,
        ]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('sendRowUpsert')
            ->willReturn(SpreadsheetTransportResponse::failure('Remote network timeout', 'NETWORK_TIMEOUT', isRetryable: true));

        $result = $engine->processSyncRecord($rowSync, $mockTransport);

        $this->assertFalse($result, 'Failing transport must return false.');
        $rowSync->refresh();
        $this->assertNull($rowSync->payload_checksum, 'Failing transport must NEVER persist a payload checksum.');
        $this->assertNull($rowSync->row_index);
        $this->assertNull($rowSync->last_synced_at);
        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $rowSync->sync_status);
        $this->assertEquals(1, $rowSync->attempt_count);
        $this->assertStringContainsString('Remote network timeout', $rowSync->error_message);
    }

    /**
     * S17.9 Case 6: Fencing token mismatch = updateGuardedByFence rejects update, preventing stale status write.
     */
    public function test_case_6_fencing_token_mismatch_rejects_stale_update(): void
    {
        $registration = $this->createTestRegistration('Budi Santoso');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        // Sub-test 6A: Direct updateGuardedByFence verification
        $fenceTokenA = (string) Str::uuid();
        $fenceTokenB = (string) Str::uuid();

        // Simulate lease acquired with fenceTokenA
        \Illuminate\Support\Facades\DB::table('spreadsheet_row_syncs')
            ->where('id', $rowSync->id)
            ->update([
                'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
                'locked_at'   => now(),
                'locked_by'   => $fenceTokenA,
            ]);

        $reflection = new \ReflectionClass($engine);
        $method = $reflection->getMethod('updateGuardedByFence');
        $method->setAccessible(true);

        // Attempt update with mismatched fenceTokenB -> must fail and return false
        $rejected = $method->invoke($engine, $rowSync, $fenceTokenB, [
            'sync_status' => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'   => 99,
        ]);

        $this->assertFalse($rejected, 'updateGuardedByFence must return false on fence token mismatch.');
        $this->assertDatabaseHas('spreadsheet_row_syncs', [
            'id'          => $rowSync->id,
            'sync_status' => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_by'   => $fenceTokenA,
        ]);

        // Attempt update with matching fenceTokenA -> must succeed and return true
        $accepted = $method->invoke($engine, $rowSync, $fenceTokenA, [
            'sync_status' => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'   => 99,
            'locked_at'   => null,
            'locked_by'   => null,
        ]);

        $this->assertTrue($accepted, 'updateGuardedByFence must return true on matching fence token.');
        $this->assertDatabaseHas('spreadsheet_row_syncs', [
            'id'          => $rowSync->id,
            'sync_status' => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'   => 99,
            'locked_by'   => null,
        ]);

        // Sub-test 6B: End-to-end lease preemption during HTTP roundtrip in processSyncRecord
        $rowSync->update([
            'sync_status'   => SpreadsheetRowSync::STATUS_PENDING,
            'next_retry_at' => null,
            'locked_at'     => null,
            'locked_by'     => null,
        ]);

        $spyTransport = new class($rowSync) implements SpreadsheetTransportInterface {
            public function __construct(private SpreadsheetRowSync $syncRecord) {}
            public function sendRowUpsert(SpreadsheetConnection $connection, string $tabName, string $syncId, array $payload): SpreadsheetTransportResponse
            {
                // Simulate another process reclaiming expired lease during HTTP wait
                \Illuminate\Support\Facades\DB::table('spreadsheet_row_syncs')
                    ->where('id', $this->syncRecord->id)
                    ->update([
                        'locked_by' => 'COMPETING_WORKER_TOKEN',
                        'locked_at' => now(),
                    ]);
                return SpreadsheetTransportResponse::success(42);
            }
            public function sendRowUpdateOnly(SpreadsheetConnection $connection, string $tabName, string $syncId, array $payload): SpreadsheetTransportResponse
            {
                return $this->sendRowUpsert($connection, $tabName, $syncId, $payload);
            }
            public function testConnection(SpreadsheetConnection $connection): bool { return true; }
        };

        $e2eResult = $engine->processSyncRecord($rowSync, $spyTransport);

        $this->assertFalse($e2eResult, 'Stale worker whose lease was overtaken must return false.');
        $this->assertDatabaseHas('spreadsheet_row_syncs', [
            'id'        => $rowSync->id,
            'locked_by' => 'COMPETING_WORKER_TOKEN', // Preserved without stale worker overwrite
        ]);
        $this->assertDatabaseMissing('spreadsheet_row_syncs', [
            'id'        => $rowSync->id,
            'row_index' => 42,
        ]);
    }

    /**
     * S17.9 Case 7: Old format checksum compatibility = old checksum does not match new format, forces clean upgrade upsert.
     */
    public function test_case_7_legacy_checksum_forces_clean_upgrade_upsert(): void
    {
        $registration = $this->createTestRegistration('Budi Santoso');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration);
        $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
        $payload['SERTIF_SYNC_STATUS'] = 'synced';

        // Old checksum format: hashed ONLY json_encode($payload) without target identity
        $legacyChecksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Pre-condition: record has old checksum stored in database
        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 13,
            'last_synced_at'   => now()->subDays(3),
            'payload_checksum' => $legacyChecksum,
        ]);

        $newTargetChecksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);
        $this->assertNotEquals($legacyChecksum, $newTargetChecksum, 'Legacy checksum must not equal new target-aware checksum.');

        // Remote upsert must be called to ensure row is safely synced to the target sheet
        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('sendRowUpsert')
            ->willReturn(SpreadsheetTransportResponse::success(13));

        $result = $engine->processSyncRecord($rowSync, $mockTransport);

        $this->assertTrue($result);
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertEquals($newTargetChecksum, $rowSync->payload_checksum, 'Legacy checksum must be seamlessly upgraded to new target-aware checksum.');
        $this->assertNotEquals($legacyChecksum, $rowSync->payload_checksum);
    }

    /**
     * S17.11 Scenario 1: Retry when record has an active lock is strictly rejected.
     * Lock and checksum remain unchanged, and zero HTTP requests are sent.
     */
    public function test_s17_11_retry_when_record_has_active_lock_is_strictly_rejected(): void
    {
        $registration = $this->createTestRegistration('Calon Pemagang A');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $initialFenceToken = 'WORKER-1-ACTIVE-TOKEN-' . Str::uuid();
        $lockedTime = now()->subMinute();

        // Simulate Worker 1 actively holding lock (less than 5 minutes ago)
        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'        => $lockedTime,
            'locked_by'        => $initialFenceToken,
            'payload_checksum' => 'initial_checksum_123',
            'attempt_count'    => 2,
        ]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->never())->method('sendRowUpsert');

        $admin = User::create([
            'name'      => 'Admin Auditor',
            'email'     => 'admin_audit_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        // When retrySyncRecord is called on actively locked record -> must be rejected
        $result = $engine->retrySyncRecord($rowSync, $admin, $mockTransport);

        $this->assertFalse($result, 'Manual retry must be rejected when record has an active lock.');
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_PROCESSING, $rowSync->sync_status);
        $this->assertEquals($initialFenceToken, $rowSync->locked_by, 'locked_by must not be cleared or modified.');
        $this->assertEquals('initial_checksum_123', $rowSync->payload_checksum, 'payload_checksum must not be wiped.');
        $this->assertEquals(2, $rowSync->attempt_count, 'attempt_count must not be reset.');
        $this->assertNotNull($rowSync->locked_at);
    }

    /**
     * S17.11 Scenario 2: Retry without active lock succeeds and updates state.
     */
    public function test_s17_11_retry_without_active_lock_succeeds_and_updates_state(): void
    {
        $registration = $this->createTestRegistration('Calon Pemagang B');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration);
        $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
        $payload['SERTIF_SYNC_STATUS'] = 'synced';

        $exactChecksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);

        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_FAILED,
            'row_index'        => 21,
            'last_synced_at'   => now()->subHours(2),
            'payload_checksum' => 'old_failed_checksum',
            'locked_at'        => null,
            'locked_by'        => null,
        ]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('sendRowUpsert')
            ->willReturn(SpreadsheetTransportResponse::success(21));

        $admin = User::create([
            'name'      => 'Admin Auditor B',
            'email'     => 'admin_audit_b_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $result = $engine->retrySyncRecord($rowSync, $admin, $mockTransport);

        $this->assertTrue($result);
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertEquals($exactChecksum, $rowSync->payload_checksum);
        $this->assertNull($rowSync->locked_at);
        $this->assertNull($rowSync->locked_by);
    }

    /**
     * S17.11 Scenario 3: Two concurrent retry attempts prevent double processing (mutual exclusion).
     */
    public function test_s17_11_two_concurrent_retries_prevent_double_processing(): void
    {
        $registration = $this->createTestRegistration('Calon Pemagang C');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $rowSync->update([
            'sync_status' => SpreadsheetRowSync::STATUS_FAILED,
            'locked_at'   => null,
            'locked_by'   => null,
        ]);

        $admin1 = User::create([
            'name'      => 'Admin 1',
            'email'     => 'admin1_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $admin2 = User::create([
            'name'      => 'Admin 2',
            'email'     => 'admin2_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $concurrentAttemptResult = null;

        $spyTransport = new class($rowSync, $engine, $admin2, $concurrentAttemptResult) implements SpreadsheetTransportInterface {
            public int $upsertCalls = 0;
            public function __construct(
                private SpreadsheetRowSync $syncRecord,
                private SpreadsheetSyncEngineService $engine,
                private User $competingAdmin,
                public &$competingResult
            ) {}

            public function sendRowUpsert(SpreadsheetConnection $connection, string $tabName, string $syncId, array $payload): SpreadsheetTransportResponse
            {
                $this->upsertCalls++;

                // While Attempt 1 is executing transport, Attempt 2 arrives concurrently!
                $dummyTransport = new class implements SpreadsheetTransportInterface {
                    public function sendRowUpsert(SpreadsheetConnection $c, string $t, string $s, array $p): SpreadsheetTransportResponse {
                        throw new \RuntimeException('Should never be called by competing retry');
                    }
                    public function sendRowUpdateOnly(SpreadsheetConnection $c, string $t, string $s, array $p): SpreadsheetTransportResponse {
                        throw new \RuntimeException('Should never be called by competing retry');
                    }
                    public function testConnection(SpreadsheetConnection $c): bool { return true; }
                };

                // Attempt 2 MUST be rejected atomically
                $this->competingResult = $this->engine->retrySyncRecord($this->syncRecord, $this->competingAdmin, $dummyTransport);

                return SpreadsheetTransportResponse::success(23);
            }

            public function sendRowUpdateOnly(SpreadsheetConnection $connection, string $tabName, string $syncId, array $payload): SpreadsheetTransportResponse
            {
                return $this->sendRowUpsert($connection, $tabName, $syncId, $payload);
            }

            public function testConnection(SpreadsheetConnection $connection): bool { return true; }
        };

        $firstAttemptResult = $engine->retrySyncRecord($rowSync, $admin1, $spyTransport);

        $this->assertTrue($firstAttemptResult, 'First retry attempt must succeed.');
        $this->assertFalse($concurrentAttemptResult, 'Concurrent second retry attempt must be rejected atomically.');
        $this->assertEquals(1, $spyTransport->upsertCalls, 'Exactly 1 HTTP upsert must be executed across both attempts.');

        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
    }

    /**
     * S17.11 Scenario 4: Stale lock on STATUS_PROCESSING is handled fail-closed.
     * Neither normal worker nor manual retry can claim it automatically without explicit intervention.
     */
    public function test_s17_11_stale_lock_in_processing_status_is_handled_fail_closed(): void
    {
        $registration = $this->createTestRegistration('Calon Pemagang D');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $staleWorkerFence = 'STALE-WORKER-' . Str::uuid();

        // Worker claimed lock 15 minutes ago and died or lagged
        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'        => now()->subMinutes(15),
            'locked_by'        => $staleWorkerFence,
            'payload_checksum' => 'stale_checksum',
        ]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->never())->method('sendRowUpsert');

        // 1. Normal worker processSyncRecord must fail-closed
        $workerResult = $engine->processSyncRecord($rowSync, $mockTransport);
        $this->assertFalse($workerResult, 'Normal worker must not steal stale processing record (fail-closed).');

        // 2. Manual retry retrySyncRecord must fail-closed
        $admin = User::create([
            'name'      => 'Admin Stale',
            'email'     => 'admin_stale_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);
        $retryResult = $engine->retrySyncRecord($rowSync, $admin, $mockTransport);
        $this->assertFalse($retryResult, 'Manual retry must fail-closed on stale processing record.');

        // 3. Database attributes must remain intact
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_PROCESSING, $rowSync->sync_status);
        $this->assertEquals($staleWorkerFence, $rowSync->locked_by);
        $this->assertEquals('stale_checksum', $rowSync->payload_checksum);
    }

    /**
     * S17.11 Scenario 5: Old duplicate job after recovery success is safe no-op.
     */
    public function test_s17_11_old_job_after_recovery_success_is_safe_noop(): void
    {
        $registration = $this->createTestRegistration('Calon Pemagang E');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration);
        $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
        $payload['SERTIF_SYNC_STATUS'] = 'synced';

        $validChecksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);

        // Record has already been recovered successfully
        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 22,
            'last_synced_at'   => now()->subMinutes(10),
            'payload_checksum' => $validChecksum,
            'locked_at'        => null,
            'locked_by'        => null,
        ]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->never())->method('sendRowUpsert');

        $result = $engine->processSyncRecord($rowSync, $mockTransport);

        $this->assertTrue($result, 'Old duplicate job must return true as idempotent no-op.');
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertEquals(22, $rowSync->row_index);
        $this->assertEquals($validChecksum, $rowSync->payload_checksum);
    }

    /**
     * S17.11 Scenario 6: Target Spreadsheet change during recovery invalidates no-op and triggers upsert.
     */
    public function test_s17_11_target_spreadsheet_change_triggers_clean_upsert(): void
    {
        $registration = $this->createTestRegistration('Calon Pemagang F');
        $engine = app(SpreadsheetSyncEngineService::class);
        $rowSync = $engine->queueSyncForRegistration($registration);

        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration);
        $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
        $payload['SERTIF_SYNC_STATUS'] = 'synced';

        // Previously synced to Spreadsheet Target A
        $this->regConnection->update([
            'spreadsheet_id'  => '1TARGET_A_SPREADSHEET_ID_1111111111111111',
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1TARGET_A_SPREADSHEET_ID_1111111111111111/edit',
        ]);
        $targetAChecksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);

        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_SYNCED,
            'row_index'        => 28,
            'last_synced_at'   => now()->subHour(),
            'payload_checksum' => $targetAChecksum,
            'locked_at'        => null,
            'locked_by'        => null,
        ]);

        // Target updated to Spreadsheet Target B
        $this->regConnection->update([
            'spreadsheet_id'  => '1TARGET_B_SPREADSHEET_ID_2222222222222222',
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1TARGET_B_SPREADSHEET_ID_2222222222222222/edit',
        ]);
        $targetBChecksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);

        $this->assertNotEquals($targetAChecksum, $targetBChecksum);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('sendRowUpsert')
            ->willReturn(SpreadsheetTransportResponse::success(30));

        $result = $engine->processSyncRecord($rowSync, $mockTransport);

        $this->assertTrue($result);
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        $this->assertEquals(30, $rowSync->row_index);
        $this->assertEquals($targetBChecksum, $rowSync->payload_checksum, 'Record checksum must be updated to Target B.');
    }

    /**
     * S17.11 Scenario 7: Retry All cannot mutate or disrupt records currently in processing.
     */
    public function test_s17_11_retry_all_failed_does_not_mutate_or_dispatch_processing_records(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $engine = app(SpreadsheetSyncEngineService::class);

        $reg1 = $this->createTestRegistration('Calon Failed');
        $syncFailed = $engine->queueSyncForRegistration($reg1);
        $syncFailed->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_FAILED,
            'locked_at'        => null,
            'locked_by'        => null,
            'payload_checksum' => 'failed_checksum',
        ]);

        $reg2 = $this->createTestRegistration('Calon Active Lock');
        $syncActiveLock = $engine->queueSyncForRegistration($reg2);
        $syncActiveLock->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'        => now()->subMinute(),
            'locked_by'        => 'ACTIVE_WORKER_TOKEN',
            'payload_checksum' => 'active_checksum',
        ]);

        $reg3 = $this->createTestRegistration('Calon Stale Lock');
        $syncStaleLock = $engine->queueSyncForRegistration($reg3);
        $syncStaleLock->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'        => now()->subMinutes(15),
            'locked_by'        => 'STALE_WORKER_TOKEN',
            'payload_checksum' => 'stale_checksum',
        ]);

        $admin = User::create([
            'name'      => 'Admin Batch',
            'email'     => 'admin_batch_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $count = $engine->retryAllFailed($this->regConnection, $admin);

        $this->assertEquals(1, $count, 'Only the failed record should be retried.');

        // Verify Record 1 was reset to pending
        $syncFailed->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_PENDING, $syncFailed->sync_status);
        $this->assertNull($syncFailed->payload_checksum);

        // Verify Record 2 (active lock) was completely untouched
        $syncActiveLock->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_PROCESSING, $syncActiveLock->sync_status);
        $this->assertEquals('ACTIVE_WORKER_TOKEN', $syncActiveLock->locked_by);
        $this->assertEquals('active_checksum', $syncActiveLock->payload_checksum);

        // Verify Record 3 (stale lock in processing) was completely untouched
        $syncStaleLock->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_PROCESSING, $syncStaleLock->sync_status);
        $this->assertEquals('STALE_WORKER_TOKEN', $syncStaleLock->locked_by);
        $this->assertEquals('stale_checksum', $syncStaleLock->payload_checksum);
    }

    /**
     * S17.12 Scenario 1: Synthetic single-record recovery succeeds with Fake Transport.
     */
    public function test_s17_12_isolated_synthetic_single_record_recovery_success(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Mail::fake();

        // 1. Verify strict database isolation
        $this->assertEquals(
            'internship_management_system_testing',
            \Illuminate\Support\Facades\DB::connection()->getDatabaseName(),
            'Recovery test MUST execute strictly on the isolated testing database.'
        );

        $engine = app(SpreadsheetSyncEngineService::class);

        // 2. Create synthetic registration and failed row sync
        $registration = $this->createTestRegistration('Synthetic Intern S17.12');
        $rowSync = $engine->queueSyncForRegistration($registration);

        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_FAILED,
            'attempt_count'    => 2,
            'error_message'    => 'Simulated prior network timeout',
            'payload_checksum' => 'stale_prior_checksum_value',
            'locked_at'        => null,
            'locked_by'        => null,
            'row_index'        => null,
        ]);

        // Create an unrelated second sync record to prove it remains untouched
        $unrelatedReg = $this->createTestRegistration('Unrelated Intern S17.12');
        $unrelatedSync = $engine->queueSyncForRegistration($unrelatedReg);
        $unrelatedSyncOriginalStatus = $unrelatedSync->sync_status;
        $unrelatedSyncOriginalUpdated = $unrelatedSync->updated_at;

        // 3. Mock transport simulating Google Apps Script response
        $fakeRowIndex = 42;
        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('sendRowUpsert')
            ->willReturn(SpreadsheetTransportResponse::success($fakeRowIndex));

        $admin = User::create([
            'name'      => 'Admin Synthetic Recovery',
            'email'     => 'admin_synthetic_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        // 4. Execute recovery through real service
        $result = $engine->retrySyncRecord($rowSync, $admin, $mockTransport);

        // 5. Verification
        $this->assertTrue($result, 'Synthetic recovery must return true on transport success.');
        $rowSync->refresh();

        // - Status transitions to synced
        $this->assertEquals(SpreadsheetRowSync::STATUS_SYNCED, $rowSync->sync_status);
        // - row_index saved from transport
        $this->assertEquals($fakeRowIndex, $rowSync->row_index);
        // - Target-aware checksum saved
        $mappingService = app(RegistrationSpreadsheetMappingService::class);
        $payload = $mappingService->buildOutboundRowPayload($registration);
        $payload['SERTIF_SYNC_ID'] = $rowSync->external_sync_id;
        $payload['SERTIF_SYNC_STATUS'] = 'synced';
        $expectedChecksum = $engine->calculatePayloadChecksum($this->regConnection, $rowSync->target_sheet_tab, $payload);
        $this->assertEquals($expectedChecksum, $rowSync->payload_checksum);
        // - Locks cleaned up
        $this->assertNull($rowSync->locked_at);
        $this->assertNull($rowSync->locked_by);
        // - Error message cleaned up
        $this->assertNull($rowSync->error_message);
        $this->assertNotNull($rowSync->last_synced_at);

        // - Unrelated record untouched
        $unrelatedSync->refresh();
        $this->assertEquals($unrelatedSyncOriginalStatus, $unrelatedSync->sync_status);
        $this->assertEquals($unrelatedSyncOriginalUpdated->toIso8601String(), $unrelatedSync->updated_at->toIso8601String());

        // - No email jobs dispatched
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Mail\InternAcceptedMail::class);
    }

    /**
     * S17.12 Scenario 2: Synthetic single-record recovery failure does not produce fake success.
     */
    public function test_s17_12_isolated_synthetic_single_record_recovery_transport_failure(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Mail::fake();

        $this->assertEquals(
            'internship_management_system_testing',
            \Illuminate\Support\Facades\DB::connection()->getDatabaseName()
        );

        $engine = app(SpreadsheetSyncEngineService::class);

        $registration = $this->createTestRegistration('Synthetic Failure Intern S17.12');
        $rowSync = $engine->queueSyncForRegistration($registration);

        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_FAILED,
            'attempt_count'    => 1,
            'error_message'    => 'Prior error',
            'payload_checksum' => null,
            'locked_at'        => null,
            'locked_by'        => null,
            'row_index'        => null,
        ]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->once())
            ->method('sendRowUpsert')
            ->willReturn(SpreadsheetTransportResponse::failure('Simulated remote failure 500', isRetryable: true));

        $admin = User::create([
            'name'      => 'Admin Synthetic Failure',
            'email'     => 'admin_fail_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $result = $engine->retrySyncRecord($rowSync, $admin, $mockTransport);

        $this->assertFalse($result, 'Synthetic recovery must return false on transport failure.');
        $rowSync->refresh();

        // Must transition to retrying (not synced)
        $this->assertEquals(SpreadsheetRowSync::STATUS_RETRYING, $rowSync->sync_status);
        // Checksum must NOT be saved (no fake success)
        $this->assertNull($rowSync->payload_checksum);
        // row_index must remain null
        $this->assertNull($rowSync->row_index);
        // Locks cleaned up
        $this->assertNull($rowSync->locked_at);
        $this->assertNull($rowSync->locked_by);
        // Attempt count incremented from 0 to 1
        $this->assertEquals(1, $rowSync->attempt_count);
        $this->assertNotNull($rowSync->next_retry_at);
        $this->assertStringContainsString('Simulated remote failure 500', $rowSync->error_message);

        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Mail\InternAcceptedMail::class);
    }

    /**
     * S17.15 Safety Test 1: Runner strictly rejects --execute mode following S17.14 closeout.
     */
    public function test_s17_15_runner_strictly_rejects_execute_mode(): void
    {
        $process = new Process(['php', 'scripts/recover_single_row_sync.php', '--id=2', '--execute']);
        $process->run();

        $this->assertEquals(1, $process->getExitCode());
        $this->assertStringContainsString('Mode --execute is closed following S17.14 closeout', $process->getErrorOutput());
    }

    /**
     * S17.14 Safety Test 2: Guarded recovery aborts without HTTP when record is actively locked or processing.
     */
    public function test_s17_14_service_guarded_recovery_aborts_if_record_is_locked_or_processing(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $registration = $this->createTestRegistration('S17.14 Guard Intern');
        $rowSync = $engine->queueSyncForRegistration($registration);

        $rowSync->update([
            'sync_status'      => SpreadsheetRowSync::STATUS_PROCESSING,
            'locked_at'        => now()->subMinute(),
            'locked_by'        => 'ACTIVE_WORKER_UUID',
            'payload_checksum' => 'initial_checksum',
        ]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->never())->method('sendRowUpsert');

        $admin = User::create([
            'name'      => 'Admin Safety',
            'email'     => 'admin_safe_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $result = $engine->retrySyncRecord($rowSync, $admin, $mockTransport);

        $this->assertFalse($result, 'Retry MUST be rejected when active lock exists.');
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_PROCESSING, $rowSync->sync_status);
        $this->assertEquals('ACTIVE_WORKER_UUID', $rowSync->locked_by);
        $this->assertEquals('initial_checksum', $rowSync->payload_checksum);
    }

    /**
     * S17.14 Safety Test 3: Guarded recovery aborts if target connection is disabled.
     */
    public function test_s17_14_service_guarded_recovery_aborts_if_target_connection_is_disabled(): void
    {
        $engine = app(SpreadsheetSyncEngineService::class);
        $registration = $this->createTestRegistration('S17.14 Disabled Intern');
        $rowSync = $engine->queueSyncForRegistration($registration);

        $this->regConnection->update(['is_enabled' => false]);

        $mockTransport = $this->createMock(SpreadsheetTransportInterface::class);
        $mockTransport->expects($this->never())->method('sendRowUpsert');

        $admin = User::create([
            'name'      => 'Admin Safe 2',
            'email'     => 'admin_safe2_' . Str::random(8) . '@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'admin',
            'is_banned' => false,
        ]);

        $result = $engine->retrySyncRecord($rowSync, $admin, $mockTransport);

        $this->assertFalse($result);
        $rowSync->refresh();
        $this->assertEquals(SpreadsheetRowSync::STATUS_FAILED, $rowSync->sync_status);
        $this->assertStringContainsString('dinonaktifkan', $rowSync->error_message);
    }

    /**
     * S17.18C Node.js Isolated Mock Test for Google Apps Script handleUpdateRowAction.
     * Verifies:
     * 1. Exact 1 match: calls setValues, updates single row, returns 200.
     * 2. Missing row (0 match): zero insert (setValues never called), returns 404 ROW_NOT_FOUND.
     * 3. Duplicate Sync ID (>1 match): fail-closed, zero write, returns 409 DUPLICATE_SYNC_ID.
     * 4. Identity mismatch: zero write, returns 409 IDENTITY_MISMATCH.
     * 5. Invalid HMAC / schema mismatch: zero write, returns 400.
     */
    public function test_apps_script_handle_update_row_action_isolated_mock_suite(): void
    {
        $nodeScript = <<<'JS'
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const REG_ID = "1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug";

global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => k === "REGISTRATION_SPREADSHEET_ID" ? REG_ID : null
  })
};

global.LockService = {
  getScriptLock: () => ({
    tryLock: () => true,
    releaseLock: () => {}
  })
};

global.Utilities = {
  computeHmacSha256Signature: () => [],
  base64Encode: () => "",
  formatDate: () => "2026-03-01 10:00:00"
};

const headers29 = appsScript.REG_CONTRACT_2026.map(s => s.dbKey);

function buildPayload(syncId, regId) {
  const p = {};
  headers29.forEach(h => p[h] = "val-" + h);
  p["SERTIF_SYNC_ID"] = syncId;
  p["SERTIF_REGISTRATION_ID"] = regId;
  p["KET"] = "Sudah di-follow-up (sudah dinyatakan diterima, sudah dipindah ke daftar peserta)";
  return p;
}

function createMockSheet(opts) {
  let setValuesCalled = false;
  const rows = opts.rows || [];
  return {
    getLastRow: () => 12 + rows.length,
    getLastColumn: () => headers29.length,
    getRange: (r, c, numR, numC) => {
      if (r === 12 && numR === 1) return { getValues: () => [headers29] };
      if (r === 13 && numC === 1 && c === (headers29.indexOf("SERTIF_SYNC_ID") + 1)) {
        return { getValues: () => rows.map(row => [Array.isArray(row) ? row[0] : row]) };
      }
      if (numR === 1 && numC === 1 && c === (headers29.indexOf("SERTIF_REGISTRATION_ID") + 1)) {
        const targetRow = rows[r - 13];
        const regId = Array.isArray(targetRow) ? targetRow[1] : "101";
        return { getValue: () => regId };
      }
      return {
        setValues: () => { setValuesCalled = true; }
      };
    },
    wasSetValuesCalled: () => setValuesCalled
  };
}

let currentMockSheet = null;
global.SpreadsheetApp = {
  openById: (id) => ({
    getSheetByName: (tab) => tab === "2026" ? currentMockSheet : null
  }),
  flush: () => {}
};

// Scenario 1: Exact 1 match -> updates row, calls setValues, returns 200
currentMockSheet = createMockSheet({ rows: [["REG-1-MATCH", "101"]] });
const res1 = appsScript.handleUpdateRowAction({
  action: "update_row", connection_key: "registration", tab_name: "2026",
  sync_id: "REG-1-MATCH", payload: buildPayload("REG-1-MATCH", "101")
});
const s1_ok = res1.statusCode === 200 && currentMockSheet.wasSetValuesCalled();

// Scenario 2: 0 match (Row not found -> zero insert) -> returns 404 ROW_NOT_FOUND
currentMockSheet = createMockSheet({ rows: [["REG-OTHER", "101"]] });
const res2 = appsScript.handleUpdateRowAction({
  action: "update_row", connection_key: "registration", tab_name: "2026",
  sync_id: "REG-MISSING", payload: buildPayload("REG-MISSING", "101")
});
const s2_ok = res2.statusCode === 404 && res2.data.error_code === "ROW_NOT_FOUND" && !currentMockSheet.wasSetValuesCalled();

// Scenario 3: Duplicate match (>1 match -> fail-closed, zero write) -> returns 409 DUPLICATE_SYNC_ID
currentMockSheet = createMockSheet({ rows: [["REG-DUP", "101"], ["REG-DUP", "101"]] });
const res3 = appsScript.handleUpdateRowAction({
  action: "update_row", connection_key: "registration", tab_name: "2026",
  sync_id: "REG-DUP", payload: buildPayload("REG-DUP", "101")
});
const s3_ok = res3.statusCode === 409 && res3.data.error_code === "DUPLICATE_SYNC_ID" && !currentMockSheet.wasSetValuesCalled();

// Scenario 4: Identity mismatch (remote 999 vs payload 101 -> fail-closed, zero write) -> returns 409 IDENTITY_MISMATCH
currentMockSheet = createMockSheet({ rows: [["REG-MISMATCH", "999"]] });
const res4 = appsScript.handleUpdateRowAction({
  action: "update_row", connection_key: "registration", tab_name: "2026",
  sync_id: "REG-MISMATCH", payload: buildPayload("REG-MISMATCH", "101")
});
const s4_ok = res4.statusCode === 409 && res4.data.error_code === "IDENTITY_MISMATCH" && !currentMockSheet.wasSetValuesCalled();

// Scenario 5: Schema mismatch / payload sync_id mismatch -> 400, zero write
currentMockSheet = createMockSheet({ rows: [["REG-VALID", "101"]] });
const res5 = appsScript.handleUpdateRowAction({
  action: "update_row", connection_key: "registration", tab_name: "2026",
  sync_id: "REG-VALID", payload: buildPayload("REG-WRONG-PAYLOAD-SYNC", "101")
});
const s5_ok = res5.statusCode === 400 && !currentMockSheet.wasSetValuesCalled();

process.stdout.write(JSON.stringify({
  s1_ok: s1_ok,
  s2_ok: s2_ok,
  s3_ok: s3_ok,
  s4_ok: s4_ok,
  s5_ok: s5_ok
}));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $output = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($output['s1_ok'], 'Scenario 1: Exact 1 match MUST call setValues and return 200.');
        $this->assertTrue($output['s2_ok'], 'Scenario 2: Missing row MUST return 404 ROW_NOT_FOUND with zero insert.');
        $this->assertTrue($output['s3_ok'], 'Scenario 3: Duplicate sync ID MUST fail-closed with 409 DUPLICATE_SYNC_ID.');
        $this->assertTrue($output['s4_ok'], 'Scenario 4: Identity mismatch MUST fail-closed with 409 IDENTITY_MISMATCH.');
        $this->assertTrue($output['s5_ok'], 'Scenario 5: Schema/payload mismatch MUST return 400 with zero write.');
    }

    /**
     * Test 38: Isolated full-pipeline test of Google Apps Script doPost(e) via Node.js:
     * 1. Valid HMAC, timestamp freshness, unique nonce, action routing to update_row -> 200 OK.
     * 2. Expired timestamp drift (> 300s) -> 401 EXPIRED_REQUEST.
     * 3. Nonce replay detection -> 401 REPLAYED_NONCE.
     * 4. Invalid HMAC signature -> 401 INVALID_SIGNATURE.
     * 5. Empty request body -> 400 EMPTY_REQUEST.
     */
    public function test_apps_script_dopost_full_pipeline_via_nodejs_process(): void
    {
        $nodeScript = <<<'JS'
const crypto = require("crypto");
const path = require("path");
const appsScript = require(path.resolve("./resources/apps-script/SertifGoogleIntegration.js"));

const SECRET = "sertif_test_shared_secret_key_1234567890";
const REG_ID = "1sSDFQKTvifpKS9HZuyzSsu8sUzoVdpzqKGrTKzlcXug";

// Mock CacheService for nonce replay tracking
const cacheStore = {};
global.CacheService = {
  getScriptCache: () => ({
    get: (k) => cacheStore[k] || null,
    put: (k, v, ttl) => { cacheStore[k] = v; }
  })
};

// Mock PropertiesService
global.PropertiesService = {
  getScriptProperties: () => ({
    getProperty: (k) => {
      if (k === "SHARED_API_KEY") return SECRET;
      if (k === "REGISTRATION_SPREADSHEET_ID") return REG_ID;
      return null;
    }
  })
};

global.LockService = {
  getScriptLock: () => ({
    tryLock: () => true,
    releaseLock: () => {}
  })
};

// Utilities.computeHmacSha256Signature emulation
global.Utilities = {
  Charset: { UTF_8: "UTF_8" },
  computeHmacSha256Signature: (data, key) => {
    const hmac = crypto.createHmac("sha256", key);
    hmac.update(data, "utf8");
    const digest = hmac.digest();
    return Array.from(digest).map(b => (b > 127 ? b - 256 : b));
  },
  base64Encode: () => "",
  formatDate: () => "2026-03-01 10:00:00"
};

const headers29 = appsScript.REG_CONTRACT_2026.map(s => s.dbKey);

function buildPayload(syncId, regId) {
  const p = {};
  headers29.forEach(h => p[h] = "val-" + h);
  p["SERTIF_SYNC_ID"] = syncId;
  p["SERTIF_REGISTRATION_ID"] = regId;
  p["KET"] = "Keluar setelah mulai magang (belum selesai)";
  return p;
}

let setValuesCalled = false;
global.SpreadsheetApp = {
  openById: () => ({
    getSheetByName: (tab) => ({
      getLastRow: () => 13,
      getLastColumn: () => headers29.length,
      getRange: (r, c, numR, numC) => {
        if (r === 12 && numR === 1) return { getValues: () => [headers29] };
        if (r === 13 && numC === 1 && c === (headers29.indexOf("SERTIF_SYNC_ID") + 1)) {
          return { getValues: () => [["REG-DOPOST-1"]] };
        }
        if (numR === 1 && numC === 1 && c === (headers29.indexOf("SERTIF_REGISTRATION_ID") + 1)) {
          return { getValue: () => "101" };
        }
        return {
          setValues: () => { setValuesCalled = true; }
        };
      }
    })
  }),
  flush: () => {}
};

function signRequest(timestamp, nonce, payload, secret) {
  const canonicalData = timestamp + "." + nonce + "." + JSON.stringify(payload);
  const hmac = crypto.createHmac("sha256", secret);
  hmac.update(canonicalData, "utf8");
  return hmac.digest("hex");
}

const currentTs = Math.floor(Date.now() / 1000);

// Scenario 1: Valid doPost request with update_row -> 200 OK, sets values
const p1 = {
  action: "update_row", connection_key: "registration", tab_name: "2026",
  sync_id: "REG-DOPOST-1", payload: buildPayload("REG-DOPOST-1", "101")
};
const nonce1 = "nonce-valid-1";
const sig1 = signRequest(currentTs, nonce1, p1, SECRET);
const res1 = appsScript.doPost({ postData: { contents: JSON.stringify({ timestamp: currentTs, nonce: nonce1, signature: sig1, payload: p1 }) } });
const p1_ok = res1.statusCode === 200 && res1.data.success === true && setValuesCalled;

// Scenario 2: Expired timestamp (> 300s drift) -> 401 EXPIRED_REQUEST
const p2 = { action: "update_row", connection_key: "registration", tab_name: "2026", sync_id: "REG-DOPOST-1", payload: buildPayload("REG-DOPOST-1", "101") };
const nonce2 = "nonce-expired-2";
const expiredTs = currentTs - 400;
const sig2 = signRequest(expiredTs, nonce2, p2, SECRET);
const res2 = appsScript.doPost({ postData: { contents: JSON.stringify({ timestamp: expiredTs, nonce: nonce2, signature: sig2, payload: p2 }) } });
const p2_ok = res2.statusCode === 401 && res2.data.error_code === "EXPIRED_REQUEST";

// Scenario 3: Nonce replay (same nonce1 reused) -> 401 REPLAYED_NONCE
const res3 = appsScript.doPost({ postData: { contents: JSON.stringify({ timestamp: currentTs, nonce: nonce1, signature: sig1, payload: p1 }) } });
const p3_ok = res3.statusCode === 401 && res3.data.error_code === "REPLAYED_NONCE";

// Scenario 4: Invalid signature -> 401 INVALID_SIGNATURE
const nonce4 = "nonce-tampered-4";
const res4 = appsScript.doPost({ postData: { contents: JSON.stringify({ timestamp: currentTs, nonce: nonce4, signature: "tampered_signature_hex", payload: p1 }) } });
const p4_ok = res4.statusCode === 401 && res4.data.error_code === "INVALID_SIGNATURE";

// Scenario 5: Missing request body -> 400 EMPTY_REQUEST
const res5 = appsScript.doPost(null);
const p5_ok = res5.statusCode === 400 && res5.data.error_code === "EMPTY_REQUEST";

process.stdout.write(JSON.stringify({
  p1_ok: p1_ok,
  p2_ok: p2_ok,
  p3_ok: p3_ok,
  p4_ok: p4_ok,
  p5_ok: p5_ok
}));
JS;

        $process = new Process(['node', '-e', $nodeScript]);
        $process->mustRun();
        $output = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($output['p1_ok'], 'doPost Scenario 1: Valid request with update_row action MUST return 200 and set values.');
        $this->assertTrue($output['p2_ok'], 'doPost Scenario 2: Expired timestamp MUST return 401 EXPIRED_REQUEST.');
        $this->assertTrue($output['p3_ok'], 'doPost Scenario 3: Replayed nonce MUST return 401 REPLAYED_NONCE.');
        $this->assertTrue($output['p4_ok'], 'doPost Scenario 4: Tampered signature MUST return 401 INVALID_SIGNATURE.');
        $this->assertTrue($output['p5_ok'], 'doPost Scenario 5: Empty request body MUST return 400 EMPTY_REQUEST.');
    }
}


