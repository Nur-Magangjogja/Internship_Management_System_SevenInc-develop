<?php

namespace App\Services\CloudStorage;

use App\Models\ActivityLog;
use App\Models\CloudStorageSetting;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\ActivityLogger;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SystemStorageConfigService
{
    /**
     * Dapatkan instance pengaturan canonical saat ini.
     */
    public function getSettings(): CloudStorageSetting
    {
        return CloudStorageSetting::getEffectiveSettings();
    }

    /**
     * Dapatkan referensi folder registrasi pemagang (Read-Only) dari Spreadsheet Connection.
     */
    public function getRegistrationFolderReference(): array
    {
        SpreadsheetConnection::ensureDriveFolderColumns();
        $conn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();

        return [
            'folder_id'   => $conn?->drive_folder_id,
            'folder_url'  => $conn?->drive_folder_url,
            'source_name' => $conn?->display_name ?? 'Koneksi Pendaftaran Pemagang',
        ];
    }

    /**
     * Simpan pembaruan pengaturan folder Google Drive sistem.
     */
    public function updateSettings(array $data, ?User $actor = null): CloudStorageSetting
    {
        $setting = $this->getSettings();

        $canonicalUrl = $data['canonical_parent_folder_url'] ?? $data['parent_folder_url'] ?? null;
        $canonicalId = CloudStorageSetting::extractDriveFolderId($canonicalUrl)
            ?: CloudStorageSetting::extractDriveFolderId($data['canonical_parent_folder_id'] ?? null);

        $hasCanonicalChanged = ($canonicalId !== $setting->canonical_parent_folder_id);
        $newProvider = $data['provider'] ?? $setting->provider ?? CloudStorageSetting::PROVIDER_APPS_SCRIPT_DRIVE;
        $hasProviderChanged = ($newProvider !== $setting->provider);

        $updatePayload = [
            'name'                          => $data['name'] ?? $setting->name,
            'provider'                      => $newProvider,
            'canonical_parent_folder_url'   => $canonicalUrl,
            'canonical_parent_folder_id'    => $canonicalId,
            'system_assets_folder_url'      => $data['system_assets_folder_url'] ?? $setting->system_assets_folder_url,
            'document_templates_folder_url' => $data['document_templates_folder_url'] ?? $setting->document_templates_folder_url,
            'generated_docs_folder_url'     => $data['generated_docs_folder_url'] ?? $setting->generated_docs_folder_url,
            'archives_folder_url'           => $data['archives_folder_url'] ?? $setting->archives_folder_url,
            'is_enabled'                    => isset($data['is_enabled']) ? (bool) $data['is_enabled'] : $setting->is_enabled,
            'updated_by'                    => $actor?->id,
        ];

        if ($hasCanonicalChanged || $hasProviderChanged) {
            $updatePayload['is_verified'] = false;
            $updatePayload['verified_at'] = null;
            $updatePayload['validation_error'] = null;
            $updatePayload['connection_status'] = CloudStorageSetting::STATUS_UNTESTED;
        }

        // Ekstraksi Folder ID jika user menginput URL atau ID langsung untuk legacy fields
        if (isset($data['system_assets_folder_url']) || isset($data['system_assets_folder_id'])) {
            $updatePayload['system_assets_folder_id'] = CloudStorageSetting::extractDriveFolderId($data['system_assets_folder_url'] ?? null)
                ?: CloudStorageSetting::extractDriveFolderId($data['system_assets_folder_id'] ?? null);
        }

        if (isset($data['document_templates_folder_url']) || isset($data['document_templates_folder_id'])) {
            $updatePayload['document_templates_folder_id'] = CloudStorageSetting::extractDriveFolderId($data['document_templates_folder_url'] ?? null)
                ?: CloudStorageSetting::extractDriveFolderId($data['document_templates_folder_id'] ?? null);
        }

        if (isset($data['generated_docs_folder_url']) || isset($data['generated_docs_folder_id'])) {
            $updatePayload['generated_docs_folder_id'] = CloudStorageSetting::extractDriveFolderId($data['generated_docs_folder_url'] ?? null)
                ?: CloudStorageSetting::extractDriveFolderId($data['generated_docs_folder_id'] ?? null);
        }

        if (isset($data['archives_folder_url']) || isset($data['archives_folder_id'])) {
            $updatePayload['archives_folder_id'] = CloudStorageSetting::extractDriveFolderId($data['archives_folder_url'] ?? null)
                ?: CloudStorageSetting::extractDriveFolderId($data['archives_folder_id'] ?? null);
        }

        $setting->update($updatePayload);

        ActivityLogger::log(
            action: 'cloud_storage_settings_updated',
            description: "Konfigurasi penyimpanan cloud '{$setting->name}' telah diperbarui.",
            subject: $setting,
            actor: $actor,
            metadata: [
                'canonical_parent_folder_id' => $setting->canonical_parent_folder_id,
                'system_assets_id'           => $setting->system_assets_folder_id,
                'document_templates_id'      => $setting->document_templates_folder_id,
                'generated_docs_id'          => $setting->generated_docs_folder_id,
                'archives_id'                => $setting->archives_folder_id,
            ]
        );

        return $setting->fresh();
    }

    /**
     * Uji koneksi Google Drive dan akses ke seluruh folder root yang terkonfigurasi.
     */
    public function testConnection(?CloudStorageSetting $setting = null, ?User $actor = null): array
    {
        $setting = $setting ?? $this->getSettings();

        // 1. Periksa apakah Folder Induk Kanonikal Google Drive sudah dikonfigurasi (Fail-Closed)
        $canonicalFolderId = $setting->getEffectiveParentFolderId();
        if (empty($canonicalFolderId)) {
            $errorMsg = "Folder Induk Google Drive kanonikal belum dikonfigurasi pada antarmuka admin maupun konfigurasi server.";
            $setting->update([
                'connection_status' => CloudStorageSetting::STATUS_ERROR,
                'is_verified'       => false,
                'validation_error'  => $errorMsg,
                'last_tested_at'    => now(),
                'last_error_summary'=> $errorMsg,
            ]);

            return [
                'success' => false,
                'message' => $errorMsg,
                'details' => ['canonical_root' => ['status' => 'not_configured']],
            ];
        }

        // 2. Evaluasi provider penyimpanan yang aktif
        $activeProvider = $setting->getActiveProvider();

        // 2A. Provider Local Fallback (Simulasi lokal untuk pengujian terisolasi)
        if ($activeProvider === CloudStorageSetting::PROVIDER_LOCAL_FALLBACK) {
            $setting->update([
                'connection_status'       => CloudStorageSetting::STATUS_CONNECTED,
                'is_verified'             => true,
                'verified_at'             => now(),
                'validation_error'        => null,
                'last_tested_at'          => now(),
                'last_successful_test_at' => now(),
                'last_error_summary'      => null,
            ]);

            return [
                'success' => true,
                'message' => 'Penyimpanan simulasi lokal (Local Fallback) aktif dan siap digunakan.',
                'details' => ['gateway' => 'local_fallback', 'canonical_root' => ['status' => 'local']],
            ];
        }

        // 2B. Provider Google Apps Script Drive (Implementasi Tunggal & Resmi GAS-06)
        $gasDriver = new GoogleAppsScriptDriveDriver();
        if (!$gasDriver->isConfigured()) {
            $errorSummary = "Google Apps Script Drive Gateway belum lengkap dikonfigurasi: pastikan URL Web App (/exec) dan Shared Secret (HMAC) terpasang di database atau environment.";
            $setting->update([
                'connection_status' => CloudStorageSetting::STATUS_ERROR,
                'is_verified'       => false,
                'last_tested_at'    => now(),
                'last_error_summary'=> $errorSummary,
                'validation_error'  => $errorSummary,
            ]);

            return [
                'success' => false,
                'message' => $errorSummary,
                'details' => ['gateway' => 'google_apps_script', 'configured' => false],
            ];
        }

            $spreadsheetConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first()
                ?: SpreadsheetConnection::whereNotNull('apps_script_endpoint_url')->first();

            $appsScriptEndpoint = $spreadsheetConn?->apps_script_endpoint_url
                ?: config('services.google_apps_script.endpoint_url');

            try {
                $transport = app(\App\Services\SpreadsheetTransport\GoogleAppsScriptTransport::class);

                // Coba aksi drive_ping terlebih dahulu (read-only verification)
                $response = $transport->dispatchSignedRequest(
                    $appsScriptEndpoint,
                    [
                        'action'          => 'drive_ping',
                        'drive_folder_id' => $canonicalFolderId,
                    ],
                    'DRIVE_GATEWAY_PING'
                );

                // Fallback ke aksi ping standar jika drive_ping belum dikenal oleh Apps Script versi lama
                if (!$response->success && (($response->context['error_code'] ?? '') === 'UNKNOWN_ACTION' || str_contains($response->errorMessage ?? '', 'tidak didukung'))) {
                    $response = $transport->dispatchSignedRequest(
                        $appsScriptEndpoint,
                        [
                            'action'          => 'ping',
                            'connection_key'  => $spreadsheetConn?->connection_key ?? 'registration',
                            'spreadsheet_id'  => $spreadsheetConn?->spreadsheet_id,
                            'drive_folder_id' => $canonicalFolderId,
                        ],
                        'DRIVE_GATEWAY_PING_FB'
                    );
                }

                $driveAccessible = ($response->context['accessible'] ?? false) === true
                    || ($response->context['status']['drive_folder'] ?? false) === true;

                if ($response->success && $driveAccessible === true) {
                    $rootName = $response->context['root_folder_name'] ?? 'Google Drive';
                    $successMsg = "Koneksi Google Drive berhasil terhubung dan diverifikasi via Google Apps Script Gateway (Folder: {$rootName}, ID: {$canonicalFolderId}).";

                    $setting->update([
                        'connection_status'       => CloudStorageSetting::STATUS_CONNECTED,
                        'is_verified'             => true,
                        'verified_at'             => now(),
                        'validation_error'        => null,
                        'last_tested_at'          => now(),
                        'last_successful_test_at' => now(),
                        'last_error_summary'      => null,
                    ]);

                    ActivityLogger::log(
                        action: 'cloud_storage_connection_tested',
                        description: $successMsg,
                        subject: $setting,
                        actor: $actor,
                        metadata: [
                            'gateway'          => 'google_apps_script',
                            'canonical_folder' => $canonicalFolderId,
                            'folder_name'      => $rootName,
                            'storage'          => $response->context['storage'] ?? null,
                        ]
                    );

                    return [
                        'success' => true,
                        'message' => $successMsg,
                        'details' => [
                            'gateway'          => 'google_apps_script',
                            'canonical_root'   => ['status' => 'accessible', 'id' => $canonicalFolderId, 'name' => $rootName],
                            'drive_folder'     => true,
                            'storage'          => $response->context['storage'] ?? null,
                        ],
                    ];
                }

                $errorReason = $response->errorMessage
                    ?: ($response->context['message'] ?? 'Folder Google Drive kanonikal tidak ditemukan atau akun Apps Script tidak memiliki izin akses.');
                $errorSummary = "Koneksi Google Drive gagal via Apps Script Gateway: {$errorReason}";

                $setting->update([
                    'connection_status' => CloudStorageSetting::STATUS_ERROR,
                    'is_verified'       => false,
                    'last_tested_at'    => now(),
                    'last_error_summary'=> $errorSummary,
                    'validation_error'  => $errorReason,
                ]);

                return [
                    'success' => false,
                    'message' => $errorSummary,
                    'details' => [
                        'gateway' => 'google_apps_script',
                        'error'   => $errorReason,
                        'context' => $response->context,
                    ],
                ];

            } catch (\Throwable $e) {
                $errorSummary = "Gagal menghubungi Apps Script Gateway: " . $e->getMessage();
                Log::warning("Gagal verifikasi Google Drive via Apps Script Gateway: " . $e->getMessage());

                $setting->update([
                    'connection_status' => CloudStorageSetting::STATUS_ERROR,
                    'is_verified'       => false,
                    'last_tested_at'    => now(),
                    'last_error_summary'=> $errorSummary,
                    'validation_error'  => $e->getMessage(),
                ]);

                return [
                    'success' => false,
                    'message' => $errorSummary,
                    'details' => ['gateway' => 'google_apps_script', 'error' => $e->getMessage()],
                ];
            }
        }

    /**
     * Audit kompatibilitas pembacaan berkas lampiran historis Google Drive.
     * Mengklasifikasikan berkas secara objektif berdasarkan bukti:
     * - DEMO_PLACEHOLDER : Data dummy seeder (DummyPemagangSeeder / InternshipRegistrationSeeder)
     * - FILE_NOT_FOUND   : Berkas non-dummy tidak ditemukan di Google Drive
     * - ACCESS_DENIED    : Akun Apps Script tidak memiliki izin membaca berkas
     * - OUTSIDE_ROOT     : Berkas berada di luar folder induk kanonikal
     * - GATEWAY_ERROR    : Gangguan teknis pada gateway Apps Script / deployment lama
     * - ACCESSIBLE       : Berkas berhasil diakses dan metadata valid
     *
     * Tidak memutasi database dan tidak menghapus berkas apapun.
     */
    public function auditHistoricalAttachment(\App\Models\RegistrationAttachment $attachment): array
    {
        if (empty($attachment->drive_file_id)) {
            return [
                'attachment_id'  => $attachment->id,
                'file_id'        => null,
                'status'         => 'NO_DRIVE_ID',
                'classification' => 'NO_DRIVE_ID',
                'accessible'     => false,
                'error_code'     => 'NO_DRIVE_ID',
                'message'        => 'Lampiran tidak memiliki drive_file_id.',
            ];
        }

        $fileId = (string) $attachment->drive_file_id;
        $isSeederPattern = str_starts_with($fileId, '1demo_') || str_starts_with($fileId, '1folder_reg_');
        $isSeederCharacteristics = in_array((int) $attachment->file_size, [0, 524288], true)
            || str_contains((string) $attachment->file_path, 'attachments/');
        $isLikelyPlaceholder = $isSeederPattern && $isSeederCharacteristics;

        try {
            $driver = app(\App\Services\CloudStorage\CloudStorageProviderInterface::class);
            if (!$driver->isConfigured()) {
                return [
                    'attachment_id'  => $attachment->id,
                    'file_id'        => $fileId,
                    'status'         => 'PROVIDER_NOT_CONFIGURED',
                    'classification' => 'GATEWAY_ERROR',
                    'accessible'     => false,
                    'error_code'     => 'PROVIDER_NOT_CONFIGURED',
                    'message'        => 'Storage driver belum terkonfigurasi.',
                ];
            }

            $meta = $driver->getFileMetadata($fileId);
            $isTrashed = $meta['trashed'] ?? false;
            $status = $isTrashed ? 'TRASHED' : 'ACCESSIBLE';

            return [
                'attachment_id'   => $attachment->id,
                'file_id'         => $fileId,
                'status'          => $status,
                'classification'  => $status,
                'accessible'      => !$isTrashed,
                'error_code'      => null,
                'remote_filename' => $meta['name'] ?? null,
                'remote_size'     => $meta['size'] ?? ($meta['size_bytes'] ?? null),
            ];
        } catch (\Throwable $e) {
            $err = $e->getMessage();

            // Analisis bukti kegagalan berdasarkan respons Google Drive / Apps Script
            // Catatan: Google Drive menghasilkan pesan dwibahasa: "Item dengan ID yang ditetapkan tidak dapat ditemukan.
            // Mungkin karena Anda belum mengedit item ini atau Anda tidak memiliki izin untuk mengaksesnya."
            // Oleh karena itu, jika berkas memiliki karakteristik seeder (isLikelyPlaceholder) dan tidak ditemukan,
            // harus diklasifikasikan sebagai DEMO_PLACEHOLDER, bukan ACCESS_DENIED.
            if (str_contains($err, 'ACCESS_DENIED_OUTSIDE_ROOT') || str_contains($err, 'di luar folder induk')) {
                $classification = 'OUTSIDE_ROOT';
                $code = 'ACCESS_DENIED_OUTSIDE_ROOT';
            } elseif (str_contains($err, 'UNKNOWN_ACTION') || str_contains($err, 'tidak didukung') || str_contains($err, 'Koneksi jaringan') || str_contains($err, 'NETWORK_TIMEOUT')) {
                $classification = 'GATEWAY_ERROR';
                $code = 'GATEWAY_ERROR';
            } elseif ($isLikelyPlaceholder && (str_contains($err, 'tidak dapat ditemukan') || str_contains($err, 'tidak ditemukan') || str_contains($err, 'could be found') || str_contains($err, 'FILE_NOT_FOUND'))) {
                // Terbukti fixture seeder (kombinasi pola seeder + karakteristik skema + respon remote 404/not found)
                $classification = 'DEMO_PLACEHOLDER';
                $code = 'DEMO_PLACEHOLDER';
            } elseif (str_contains($err, 'ACCESS_DENIED') || str_contains($err, 'permission') || str_contains($err, 'tidak memiliki izin')) {
                $classification = 'ACCESS_DENIED';
                $code = 'ACCESS_DENIED';
            } elseif (str_contains($err, 'FILE_NOT_FOUND') || str_contains($err, 'tidak ditemukan') || str_contains($err, 'could be found')) {
                $classification = 'FILE_NOT_FOUND';
                $code = 'FILE_NOT_FOUND';
            } elseif ($isLikelyPlaceholder) {
                $classification = 'DEMO_PLACEHOLDER';
                $code = 'DEMO_PLACEHOLDER';
            } else {
                $classification = 'GATEWAY_ERROR';
                $code = 'UNKNOWN_ERROR';
            }

            return [
                'attachment_id'  => $attachment->id,
                'file_id'        => $fileId,
                'status'         => $classification,
                'classification' => $classification,
                'accessible'     => false,
                'error_code'     => $code,
                'error'          => $err,
            ];
        }
    }

    /**
     * Audit ringkasan kompatibilitas sekumpulan lampiran historis.
     */
    public function auditHistoricalAttachmentsSummary(int $limit = 100): array
    {
        $attachments = \App\Models\RegistrationAttachment::whereNotNull('drive_file_id')
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        $totalChecked = 0;
        $accessible = 0;
        $inaccessible = 0;
        $results = [];

        foreach ($attachments as $att) {
            $res = $this->auditHistoricalAttachment($att);
            $totalChecked++;
            if ($res['accessible'] ?? false) {
                $accessible++;
            } else {
                $inaccessible++;
            }
            $results[] = $res;
        }

        return [
            'total_checked' => $totalChecked,
            'accessible'    => $accessible,
            'inaccessible'  => $inaccessible,
            'details'       => $results,
        ];
    }

    /**
     * Otorisasi dan aktifkan folder induk Google Drive secara resmi pada Google Apps Script Gateway.
     */
    public function activateAuthorizedDriveRoot(CloudStorageSetting $setting, ?User $actor = null): array
    {
        $canonicalFolderId = $setting->canonical_parent_folder_id ?: CloudStorageSetting::extractDriveFolderId($setting->canonical_parent_folder_url);
        if (empty($canonicalFolderId)) {
            return [
                'success' => false,
                'message' => 'Folder Induk Google Drive kanonikal belum dikonfigurasi.',
            ];
        }

        $spreadsheetConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first()
            ?: SpreadsheetConnection::whereNotNull('apps_script_endpoint_url')->first();

        if (!$spreadsheetConn || empty($spreadsheetConn->apps_script_endpoint_url)) {
            return [
                'success' => false,
                'message' => 'Koneksi Apps Script Endpoint belum tersedia untuk aktivasi Google Drive.',
            ];
        }

        $transport = app(\App\Services\SpreadsheetTransport\GoogleAppsScriptTransport::class);

        // 1. Inspect target (Read-Only)
        $inspectRes = $transport->inspectTarget($spreadsheetConn, 'drive_folder', $canonicalFolderId, 'drive_root');
        if (!$inspectRes->isSuccess()) {
            return [
                'success' => false,
                'message' => 'Inspeksi folder Drive gagal: ' . ($inspectRes->getErrorMessage() ?? 'Folder tidak dapat diakses.'),
            ];
        }

        // 2. Aktivasi target (Mutasi dengan Persetujuan Admin)
        $activateRes = $transport->activateAuthorizedTarget($spreadsheetConn, 'drive_folder', $canonicalFolderId, 'drive_root');
        if (!$activateRes->isSuccess()) {
            return [
                'success' => false,
                'message' => 'Aktivasi root folder Drive gagal: ' . ($activateRes->getErrorMessage() ?? 'Gagal otorisasi.'),
            ];
        }

        $setting->update([
            'is_verified'             => true,
            'verified_at'             => now(),
            'connection_status'       => CloudStorageSetting::STATUS_CONNECTED,
            'last_successful_test_at' => now(),
            'validation_error'        => null,
            'last_error_summary'      => null,
        ]);

        ActivityLogger::log(
            action: 'cloud_storage_root_activated',
            description: "Folder root Google Drive ({$canonicalFolderId}) berhasil diotorisasi dan diaktifkan di Script Properties.",
            subject: $setting,
            actor: $actor
        );

        return [
            'success' => true,
            'message' => "Folder root Google Drive ({$canonicalFolderId}) berhasil diotorisasi dan diaktifkan.",
            'details' => $activateRes->getData(),
        ];
    }
}
