<?php

namespace App\Services;

use App\Models\SpreadsheetColumnMapping;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetSchemaVersion;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SpreadsheetConnectionService
{
    /**
     * Ambil semua koneksi spreadsheet terdaftar.
     */
    public function getAllConnections(): Collection
    {
        return SpreadsheetConnection::orderBy('id', 'asc')->get();
    }

    /**
     * Ambil koneksi berdasarkan connection_key ('registration' | 'followup').
     */
    public function getConnectionByKey(string $key): SpreadsheetConnection
    {
        if (!in_array($key, SpreadsheetConnection::VALID_KEYS, true)) {
            throw new InvalidArgumentException("Tipe koneksi '{$key}' tidak valid.");
        }

        return SpreadsheetConnection::firstOrCreate(
            ['connection_key' => $key],
            [
                'display_name' => $key === SpreadsheetConnection::KEY_REGISTRATION
                    ? 'Spreadsheet Pendaftaran Magang'
                    : 'Spreadsheet Follow-Up Guru/Dosen',
                'is_enabled' => false,
                'connection_status' => SpreadsheetConnection::STATUS_UNTESTED,
            ]
        );
    }

    /**
     * Update konfigurasi koneksi spreadsheet.
     */
    public function updateConnection(SpreadsheetConnection $connection, array $data, ?User $actor = null): SpreadsheetConnection
    {
        $before = $connection->only([
            'display_name',
            'spreadsheet_url',
            'spreadsheet_id',
            'apps_script_endpoint_url',
            'is_enabled',
            'connection_status',
        ]);

        $spreadsheetUrl = isset($data['spreadsheet_url']) ? trim($data['spreadsheet_url']) : null;
        $spreadsheetId = null;

        if (!empty($spreadsheetUrl)) {
            $spreadsheetId = SpreadsheetConnection::extractSpreadsheetId($spreadsheetUrl);
            if (empty($spreadsheetId)) {
                throw new DomainException('Format URL Spreadsheet tidak valid. Pastikan menyertakan URL dokumen Google Sheets yang benar (contoh: https://docs.google.com/spreadsheets/d/ID/edit).');
            }
        }

        $appsScriptUrl = isset($data['apps_script_endpoint_url']) ? trim($data['apps_script_endpoint_url']) : null;
        if (!empty($appsScriptUrl)) {
            if (!filter_var($appsScriptUrl, FILTER_VALIDATE_URL)) {
                throw new DomainException('Format URL Apps Script Endpoint tidak valid.');
            }

            $parsedUrl = parse_url($appsScriptUrl);
            $scheme = strtolower($parsedUrl['scheme'] ?? '');
            $host = strtolower($parsedUrl['host'] ?? '');
            $path = $parsedUrl['path'] ?? '';

            if ($scheme !== 'https' || $host !== 'script.google.com' || !preg_match('#^/macros/s/([a-zA-Z0-9-_]+)/exec$#', $path)) {
                throw new DomainException('Format URL Apps Script Endpoint tidak valid. Harus berupa URL HTTPS resmi Google Apps Script (contoh: https://script.google.com/macros/s/.../exec).');
            }

            // Pastikan tidak ada credential/secret/token di query parameter
            if (!empty($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
                foreach (['key', 'token', 'secret', 'password', 'auth', 'bearer'] as $forbiddenKey) {
                    if (array_key_exists($forbiddenKey, $queryParams)) {
                        throw new DomainException("Dilarang menyertakan token/secret '{$forbiddenKey}' di dalam query parameter URL.");
                    }
                }
            }
        }

        $connection->display_name = $data['display_name'] ?? $connection->display_name;
        $connection->spreadsheet_url = $spreadsheetUrl;
        $connection->spreadsheet_id = $spreadsheetId;
        $connection->apps_script_endpoint_url = $appsScriptUrl;
        $connection->updated_by = $actor?->id;

        // Reset status to untested / not_configured if URL or endpoint changed
        if ($connection->isDirty('spreadsheet_url') || $connection->isDirty('apps_script_endpoint_url')) {
            $connection->connection_status = (empty($spreadsheetUrl) || empty($appsScriptUrl))
                ? SpreadsheetConnection::STATUS_NOT_CONFIGURED
                : SpreadsheetConnection::STATUS_UNTESTED;
            $connection->last_error_summary = null;
        }

        $connection->save();

        $after = $connection->only([
            'display_name',
            'spreadsheet_url',
            'spreadsheet_id',
            'apps_script_endpoint_url',
            'is_enabled',
            'connection_status',
        ]);

        ActivityLogger::log(
            action: 'spreadsheet_connection_updated',
            description: "Konfigurasi koneksi spreadsheet '{$connection->display_name}' ({$connection->connection_key}) diperbarui.",
            subject: $connection,
            before: $before,
            after: $after,
            actor: $actor
        );

        return $connection;
    }

    /**
     * Aktifkan atau nonaktifkan koneksi spreadsheet.
     */
    public function toggleStatus(SpreadsheetConnection $connection, ?User $actor = null): SpreadsheetConnection
    {
        $newStatus = !$connection->is_enabled;
        $connection->is_enabled = $newStatus;
        $connection->updated_by = $actor?->id;
        $connection->save();

        $action = $newStatus ? 'spreadsheet_connection_enabled' : 'spreadsheet_connection_disabled';
        $desc = "Koneksi spreadsheet '{$connection->display_name}' " . ($newStatus ? 'diaktifkan' : 'dinonaktifkan') . '.';

        ActivityLogger::log(
            action: $action,
            description: $desc,
            subject: $connection,
            before: ['is_enabled' => !$newStatus],
            after: ['is_enabled' => $newStatus],
            actor: $actor
        );

        return $connection;
    }

    /**
     * Uji koneksi spreadsheet secara aman (read-only, tanpa mutasi data bisnis).
     */
    public function testConnection(SpreadsheetConnection $connection, ?User $actor = null): array
    {
        $testedAt = now();
        $connection->last_tested_at = $testedAt;
        $connection->updated_by = $actor?->id;

        // 1. Validasi keberadaan URL Spreadsheet
        if (empty($connection->spreadsheet_url)) {
            $status = SpreadsheetConnection::STATUS_NOT_CONFIGURED;
            $summary = 'URL Spreadsheet belum dikonfigurasi.';

            $connection->connection_status = $status;
            $connection->last_error_summary = $summary;
            $connection->save();

            ActivityLogger::log(
                action: 'spreadsheet_connection_tested',
                description: "Uji koneksi '{$connection->display_name}' gagal: {$summary}",
                subject: $connection,
                after: ['connection_status' => $status, 'last_error_summary' => $summary],
                actor: $actor
            );

            return [
                'success' => false,
                'status' => $status,
                'message' => $summary,
            ];
        }

        // 2. Validasi format URL dan Spreadsheet ID
        $spreadsheetId = SpreadsheetConnection::extractSpreadsheetId($connection->spreadsheet_url);
        if (empty($spreadsheetId)) {
            $status = SpreadsheetConnection::STATUS_ERROR;
            $summary = 'Format URL Spreadsheet tidak valid atau ID dokumen tidak valid (ID dokumen minimal 25 karakter).';

            $connection->connection_status = $status;
            $connection->last_error_summary = $summary;
            $connection->save();

            ActivityLogger::log(
                action: 'spreadsheet_connection_tested',
                description: "Uji koneksi '{$connection->display_name}' gagal: {$summary}",
                subject: $connection,
                after: ['connection_status' => $status, 'last_error_summary' => $summary],
                actor: $actor
            );

            return [
                'success' => false,
                'status' => $status,
                'message' => $summary,
            ];
        }

        // Pastikan spreadsheet_id di database tersimpan akurat
        if ($connection->spreadsheet_id !== $spreadsheetId) {
            $connection->spreadsheet_id = $spreadsheetId;
        }

        // 3. Validasi Apps Script Endpoint URL eksplisit pada model koneksi (tanpa fallback diam-diam)
        $endpoint = $connection->apps_script_endpoint_url;
        if (empty($endpoint)) {
            $status = SpreadsheetConnection::STATUS_NOT_CONFIGURED;
            $summary = 'Apps Script Endpoint URL belum dikonfigurasi pada koneksi ini.';

            $connection->connection_status = $status;
            $connection->last_error_summary = $summary;
            $connection->save();

            ActivityLogger::log(
                action: 'spreadsheet_connection_tested',
                description: "Uji koneksi '{$connection->display_name}' gagal: {$summary}",
                subject: $connection,
                after: ['connection_status' => $status, 'last_error_summary' => $summary],
                actor: $actor
            );

            return [
                'success' => false,
                'status'  => $status,
                'message' => $summary,
            ];
        }

        // Validasi format Endpoint HTTPS resmi Google Apps Script
        $parsedEndpoint = parse_url($endpoint);
        $epScheme = strtolower($parsedEndpoint['scheme'] ?? '');
        $epHost = strtolower($parsedEndpoint['host'] ?? '');
        $epPath = $parsedEndpoint['path'] ?? '';

        if ($epScheme !== 'https' || $epHost !== 'script.google.com' || !preg_match('#^/macros/s/([a-zA-Z0-9-_]+)/exec$#', $epPath)) {
            $status = SpreadsheetConnection::STATUS_ERROR;
            $summary = 'Format Apps Script Endpoint URL tidak valid. Harus berupa URL HTTPS resmi Google Apps Script (contoh: https://script.google.com/macros/s/.../exec).';

            $connection->connection_status = $status;
            $connection->last_error_summary = $summary;
            $connection->save();

            ActivityLogger::log(
                action: 'spreadsheet_connection_tested',
                description: "Uji koneksi '{$connection->display_name}' gagal: {$summary}",
                subject: $connection,
                after: ['connection_status' => $status, 'last_error_summary' => $summary],
                actor: $actor
            );

            return [
                'success' => false,
                'status'  => $status,
                'message' => $summary,
            ];
        }

        // 4. Validasi Shared Secret
        $sharedSecret = config('services.google_apps_script.shared_secret');
        if (empty($sharedSecret)) {
            $status = SpreadsheetConnection::STATUS_NOT_CONFIGURED;
            $summary = 'Shared Secret Apps Script belum dikonfigurasi pada environment server (.env).';

            $connection->connection_status = $status;
            $connection->last_error_summary = $summary;
            $connection->save();

            ActivityLogger::log(
                action: 'spreadsheet_connection_tested',
                description: "Uji koneksi '{$connection->display_name}' gagal: {$summary}",
                subject: $connection,
                after: ['connection_status' => $status, 'last_error_summary' => $summary],
                actor: $actor
            );

            return [
                'success' => false,
                'status'  => $status,
                'message' => $summary,
            ];
        }

        // 5. Jalankan HTTP Ping HMAC-SHA256 ke Google Apps Script Gateway
        $transport = new \App\Services\SpreadsheetTransport\GoogleAppsScriptTransport([
            'enabled'         => true,
            'endpoint_url'    => $endpoint,
            'shared_secret'   => $sharedSecret,
            'timeout_seconds' => 15,
        ]);

        $innerPayload = [
            'action'         => 'ping',
            'connection_key' => $connection->connection_key,
            'spreadsheet_id' => $spreadsheetId,
        ];

        $response = $transport->dispatchSignedRequest($endpoint, $innerPayload, 'PING');

        if ($response->success) {
            $rawResponse = $response->rawResponse ?? [];
            $rawStatus = $rawResponse['status'] ?? [];

            // Verifikasi integritas respons ping
            $idMatched = $rawResponse['id_matched'] ?? true;
            $targetAccessible = $rawResponse['target_accessible'] ?? true;
            $verifiedId = $rawResponse['verified_spreadsheet_id'] ?? null;

            if ($idMatched === false) {
                $status = SpreadsheetConnection::STATUS_ERROR;
                $summary = 'Spreadsheet ID pada koneksi (' . $spreadsheetId . ') tidak sesuai dengan Spreadsheet target pada konfigurasi Apps Script Gateway.';
            } elseif ($targetAccessible === false) {
                $status = SpreadsheetConnection::STATUS_ERROR;
                $summary = 'Gateway Apps Script aktif, namun Spreadsheet target (' . $spreadsheetId . ') tidak dapat dibuka. Pastikan dokumen sudah dishare ke akun pemilik Apps Script dengan akses Editor.';
            } elseif ($connection->connection_key === SpreadsheetConnection::KEY_REGISTRATION && isset($rawStatus['registration_spreadsheet']) && !$rawStatus['registration_spreadsheet']) {
                $status = SpreadsheetConnection::STATUS_ERROR;
                $summary = 'Gateway Apps Script aktif, namun Spreadsheet pendaftaran target tidak dapat dibuka.';
            } elseif ($connection->connection_key === SpreadsheetConnection::KEY_FOLLOWUP && isset($rawStatus['followup_spreadsheet']) && !$rawStatus['followup_spreadsheet']) {
                $status = SpreadsheetConnection::STATUS_ERROR;
                $summary = 'Gateway Apps Script aktif, namun Spreadsheet follow-up target tidak dapat dibuka.';
            } elseif (!empty($verifiedId) && $verifiedId !== $spreadsheetId) {
                $status = SpreadsheetConnection::STATUS_ERROR;
                $summary = 'Spreadsheet ID yang diverifikasi (' . $verifiedId . ') berbeda dengan ID yang dikonfigurasi (' . $spreadsheetId . ').';
            } else {
                $status = SpreadsheetConnection::STATUS_CONNECTED;
                $summary = 'Koneksi ke Google Apps Script Gateway dan Google Spreadsheet berhasil diverifikasi (Ping OK).';
                $connection->last_successful_test_at = $testedAt;
            }
        } else {
            $status = SpreadsheetConnection::STATUS_ERROR;
            $summary = 'Uji koneksi Apps Script gagal: ' . ($response->errorMessage ?? 'Terjadi kesalahan komunikasi.');
        }

        $connection->connection_status = $status;
        $connection->last_error_summary = ($status === SpreadsheetConnection::STATUS_CONNECTED) ? null : $summary;
        $connection->save();

        ActivityLogger::log(
            action: 'spreadsheet_connection_tested',
            description: "Uji koneksi '{$connection->display_name}' selesai dengan status '{$status}': {$summary}",
            subject: $connection,
            after: ['connection_status' => $status, 'last_error_summary' => $connection->last_error_summary],
            actor: $actor
        );

        return [
            'success' => ($status === SpreadsheetConnection::STATUS_CONNECTED),
            'status'  => $status,
            'message' => $summary,
            'details' => $response->rawResponse ?? [],
        ];
    }

    /**
     * Daftarkan versi schema baru untuk tahun tertentu secara aman dan tervalidasi.
     */
    public function registerSchemaVersion(
        SpreadsheetConnection $connection,
        int $year,
        string $sheetTabName,
        string $schemaVersion,
        array $columnMappings,
        ?string $notes = null,
        bool $activate = true,
        ?User $actor = null
    ): SpreadsheetSchemaVersion {
        if ($year < 2000 || $year > 2100) {
            throw new DomainException('Tahun schema tidak valid.');
        }

        if (empty(trim($sheetTabName))) {
            throw new DomainException('Nama tab spreadsheet tidak boleh kosong.');
        }

        if (empty(trim($schemaVersion))) {
            throw new DomainException('Versi schema tidak boleh kosong.');
        }

        if (empty($columnMappings)) {
            throw new DomainException('Daftar pemetaan kolom tidak boleh kosong.');
        }

        return DB::transaction(function () use (
            $connection,
            $year,
            $sheetTabName,
            $schemaVersion,
            $columnMappings,
            $notes,
            $activate,
            $actor
        ) {
            // Jika diaktifkan, nonaktifkan versi lain untuk tahun yang sama pada koneksi ini
            if ($activate) {
                SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $connection->id)
                    ->where('year', $year)
                    ->update(['is_active' => false]);
            }

            $version = SpreadsheetSchemaVersion::create([
                'public_uuid'               => (string) Str::uuid(),
                'spreadsheet_connection_id' => $connection->id,
                'year'                      => $year,
                'sheet_tab_name'            => trim($sheetTabName),
                'schema_version'            => trim($schemaVersion),
                'is_active'                 => $activate,
                'notes'                     => $notes,
                'created_by'                => $actor?->id,
                'updated_by'                => $actor?->id,
            ]);

            $seenOrders = [];
            $seenHeaders = [];

            foreach ($columnMappings as $mapping) {
                $order = (int) ($mapping['column_order'] ?? 0);
                $header = trim((string) ($mapping['sheet_header'] ?? ''));

                if ($order <= 0 || in_array($order, $seenOrders, true)) {
                    throw new DomainException("Urutan kolom '{$order}' tidak valid atau duplikat.");
                }

                if (empty($header) || in_array($header, $seenHeaders, true)) {
                    throw new DomainException("Header kolom '{$header}' tidak boleh kosong atau duplikat.");
                }

                $seenOrders[] = $order;
                $seenHeaders[] = $header;

                SpreadsheetColumnMapping::create([
                    'public_uuid'                    => (string) Str::uuid(),
                    'spreadsheet_schema_version_id' => $version->id,
                    'column_order'                  => $order,
                    'sheet_header'                  => $header,
                    'target_entity'                 => $mapping['target_entity'] ?? null,
                    'target_field'                  => $mapping['target_field'] ?? null,
                    'data_type'                     => $mapping['data_type'] ?? 'string',
                    'direction'                     => $mapping['direction'] ?? 'outbound',
                    'transform_key'                 => $mapping['transform_key'] ?? null,
                    'is_required'                   => (bool) ($mapping['is_required'] ?? false),
                ]);
            }

            ActivityLogger::log(
                action: 'spreadsheet_schema_registered',
                description: "Schema versi '{$version->schema_version}' tahun {$version->year} (tab: '{$version->sheet_tab_name}') berhasil didaftarkan untuk koneksi '{$connection->display_name}'.",
                subject: $version,
                actor: $actor,
                metadata: [
                    'connection_key' => $connection->connection_key,
                    'year'           => $version->year,
                    'sheet_tab_name' => $version->sheet_tab_name,
                    'schema_version' => $version->schema_version,
                    'columns_count'  => count($columnMappings),
                ]
            );

            return $version;
        });
    }

    /**
     * Aktifkan versi schema tertentu untuk tahun yang sesuai.
     */
    public function activateSchemaVersion(SpreadsheetSchemaVersion $schemaVersion, ?User $actor = null): SpreadsheetSchemaVersion
    {
        return DB::transaction(function () use ($schemaVersion, $actor) {
            SpreadsheetSchemaVersion::where('spreadsheet_connection_id', $schemaVersion->spreadsheet_connection_id)
                ->where('year', $schemaVersion->year)
                ->update(['is_active' => false]);

            $schemaVersion->is_active = true;
            $schemaVersion->updated_by = $actor?->id;
            $schemaVersion->save();

            ActivityLogger::log(
                action: 'spreadsheet_schema_activated',
                description: "Schema versi '{$schemaVersion->schema_version}' tahun {$schemaVersion->year} diaktifkan.",
                subject: $schemaVersion,
                actor: $actor
            );

            return $schemaVersion;
        });
    }

    /**
     * Nonaktifkan versi schema.
     */
    public function deactivateSchemaVersion(SpreadsheetSchemaVersion $schemaVersion, ?User $actor = null): SpreadsheetSchemaVersion
    {
        $schemaVersion->is_active = false;
        $schemaVersion->updated_by = $actor?->id;
        $schemaVersion->save();

        ActivityLogger::log(
            action: 'spreadsheet_schema_deactivated',
            description: "Schema versi '{$schemaVersion->schema_version}' tahun {$schemaVersion->year} dinonaktifkan.",
            subject: $schemaVersion,
            actor: $actor
        );

        return $schemaVersion;
    }
}
