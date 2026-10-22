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

        $updatePayload = [
            'name'                          => $data['name'] ?? $setting->name,
            'canonical_parent_folder_url'   => $canonicalUrl,
            'canonical_parent_folder_id'    => $canonicalId,
            'system_assets_folder_url'      => $data['system_assets_folder_url'] ?? $setting->system_assets_folder_url,
            'document_templates_folder_url' => $data['document_templates_folder_url'] ?? $setting->document_templates_folder_url,
            'generated_docs_folder_url'     => $data['generated_docs_folder_url'] ?? $setting->generated_docs_folder_url,
            'archives_folder_url'           => $data['archives_folder_url'] ?? $setting->archives_folder_url,
            'is_enabled'                    => isset($data['is_enabled']) ? (bool) $data['is_enabled'] : $setting->is_enabled,
            'updated_by'                    => $actor?->id,
        ];

        if ($hasCanonicalChanged) {
            $updatePayload['is_verified'] = false;
            $updatePayload['verified_at'] = null;
            $updatePayload['validation_error'] = null;
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

        $driver = new GoogleDriveStorageDriver();

        if (!$driver->isConfigured()) {
            // Cek apakah Google Apps Script Gateway tersedia sebagai verifikator folder Google Drive
            $spreadsheetConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first()
                ?: SpreadsheetConnection::whereNotNull('apps_script_endpoint_url')->first();

            $appsScriptEndpoint = $spreadsheetConn?->apps_script_endpoint_url
                ?: config('services.google_apps_script.endpoint_url');

            if (!empty($appsScriptEndpoint)) {
                try {
                    $transport = app(\App\Services\SpreadsheetTransport\GoogleAppsScriptTransport::class);
                    $reflection = new \ReflectionClass($transport);
                    $dispatchMethod = $reflection->getMethod('dispatchSignedRequest');
                    $dispatchMethod->setAccessible(true);

                    $response = $dispatchMethod->invoke(
                        $transport,
                        $appsScriptEndpoint,
                        [
                            'action'          => 'ping',
                            'connection_key'  => $spreadsheetConn?->connection_key ?? 'registration',
                            'spreadsheet_id'  => $spreadsheetConn?->spreadsheet_id,
                            'drive_folder_id' => $canonicalFolderId,
                        ],
                        'DRIVE_GATEWAY_PING'
                    );

                    $driveAccessible = $response->context['status']['drive_folder'] ?? false;

                    if ($response->success && $driveAccessible === true) {
                        $successMsg = "Koneksi Google Drive berhasil terhubung dan diverifikasi via Google Apps Script Gateway (Folder ID: {$canonicalFolderId}).";
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
                            ]
                        );

                        return [
                            'success' => true,
                            'message' => $successMsg,
                            'details' => [
                                'gateway'        => 'google_apps_script',
                                'canonical_root' => ['status' => 'accessible', 'id' => $canonicalFolderId],
                                'drive_folder'   => true,
                            ],
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::warning("Gagal verifikasi Google Drive via Apps Script Gateway: " . $e->getMessage());
                }
            }

            $errorSummary = "Koneksi Google Drive gagal: Tidak dapat memverifikasi akses folder Google Drive melalui Google Apps Script Gateway maupun konfigurasi server.";
            $setting->update([
                'connection_status' => CloudStorageSetting::STATUS_ERROR,
                'last_tested_at'    => now(),
                'last_error_summary'=> $errorSummary,
            ]);

            return [
                'success' => false,
                'message' => $errorSummary,
                'details' => ['credentials_ready' => false],
            ];
        }

        try {
            // 1. Dapatkan token OAuth2
            $reflection = new \ReflectionClass($driver);
            $getTokenMethod = $reflection->getMethod('getAccessToken');
            $getTokenMethod->setAccessible(true);
            $token = $getTokenMethod->invoke($driver);

            if (empty($token)) {
                throw new Exception("Gagal memperoleh access token Google Drive dari refresh token.");
            }

            // 2. Periksa akses ke Folder Induk Kanonikal Google Drive
            $canonicalFolderId = $setting->getEffectiveParentFolderId();
            $folderDetails = [];
            $missingOrInaccessible = [];

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

            // Periksa konflik konfigurasi root (ROOT_CONFIG_CONFLICT)
            $hasConflict = $setting->hasRootConfigConflict();
            if ($hasConflict) {
                $conflictNote = "Peringatan: Terdeteksi perbedaan folder induk antara UI ({$setting->canonical_parent_folder_id}) dan ENV server (" . config('services.google.drive_folder_id') . "). UI Root digunakan sebagai kanonikal.";
                $folderDetails['root_conflict'] = [
                    'status'  => 'ROOT_CONFIG_CONFLICT',
                    'message' => $conflictNote,
                ];
            }

            // Uji Folder Induk Kanonikal
            $res = Http::withToken($token)
                ->get("https://www.googleapis.com/drive/v3/files/{$canonicalFolderId}?fields=id,name,mimeType,trashed,capabilities");

            if ($res->successful()) {
                $data = $res->json();
                if (($data['trashed'] ?? false) === true) {
                    $folderDetails['Canonical Parent Root'] = ['status' => 'trashed', 'name' => $data['name'] ?? null];
                    $missingOrInaccessible[] = "Folder Induk Google Drive (folder berada di tempat sampah)";
                    $setting->update([
                        'is_verified'      => false,
                        'validation_error' => 'Folder induk berada di tempat sampah (trashed).',
                    ]);
                } elseif (($data['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
                    $folderDetails['Canonical Parent Root'] = ['status' => 'not_a_folder', 'name' => $data['name'] ?? null];
                    $missingOrInaccessible[] = "Folder Induk Google Drive (ID yang dimasukkan bukan tipe folder)";
                    $setting->update([
                        'is_verified'      => false,
                        'validation_error' => 'ID yang dimasukkan bukan tipe folder Google Drive.',
                    ]);
                } else {
                    $canAddChildren = $data['capabilities']['canAddChildren'] ?? true;
                    if (!$canAddChildren) {
                        $folderDetails['Canonical Parent Root'] = ['status' => 'read_only', 'name' => $data['name'] ?? null];
                        $missingOrInaccessible[] = "Folder Induk Google Drive (tidak memiliki izin menulis / membuat folder anak)";
                        $setting->update([
                            'is_verified'      => false,
                            'validation_error' => 'Akun OAuth tidak memiliki izin membuat folder anak di folder induk ini.',
                        ]);
                    } else {
                        $folderDetails['Canonical Parent Root'] = [
                            'status'           => 'accessible',
                            'name'             => $data['name'] ?? null,
                            'can_add_children' => true,
                        ];
                        $setting->update([
                            'is_verified'      => true,
                            'verified_at'      => now(),
                            'validation_error' => null,
                        ]);
                    }
                }
            } else {
                $folderDetails['Canonical Parent Root'] = ['status' => 'inaccessible', 'error' => $res->status()];
                $missingOrInaccessible[] = "Folder Induk Google Drive (HTTP {$res->status()} - tidak dapat diakses atau izin ditolak)";
                $setting->update([
                    'is_verified'      => false,
                    'validation_error' => "HTTP {$res->status()}: Folder induk tidak dapat diakses atau izin ditolak.",
                ]);
            }

            // 3. Periksa folder legacy opsional jika ada
            $legacyFolders = [
                'System Assets'      => $setting->system_assets_folder_id,
                'Document Templates' => $setting->document_templates_folder_id,
                'Generated Documents'=> $setting->generated_docs_folder_id,
                'Archives'           => $setting->archives_folder_id,
            ];

            foreach ($legacyFolders as $label => $folderId) {
                if (empty($folderId)) {
                    continue;
                }

                $lRes = Http::withToken($token)
                    ->get("https://www.googleapis.com/drive/v3/files/{$folderId}?fields=id,name,mimeType,trashed,capabilities");

                if ($lRes->successful()) {
                    $lData = $lRes->json();
                    $folderDetails[$label] = [
                        'status' => (($lData['trashed'] ?? false) === true) ? 'trashed' : 'accessible',
                        'name'   => $lData['name'] ?? null,
                    ];
                } else {
                    $folderDetails[$label] = ['status' => 'inaccessible', 'error' => $lRes->status()];
                }
            }

            if (!empty($missingOrInaccessible)) {
                $errorMsg = "Otentikasi berhasil, namun terdapat kendala pada folder: " . implode(', ', $missingOrInaccessible);
                $setting->update([
                    'connection_status' => CloudStorageSetting::STATUS_ERROR,
                    'last_tested_at'    => now(),
                    'last_error_summary'=> $errorMsg,
                ]);

                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'details' => $folderDetails,
                ];
            }

            $successMsg = "Koneksi Google Drive terautentikasi dan seluruh folder root sistem dapat diakses secara penuh.";
            $setting->update([
                'connection_status'       => CloudStorageSetting::STATUS_CONNECTED,
                'last_tested_at'          => now(),
                'last_successful_test_at' => now(),
                'last_error_summary'      => null,
            ]);

            ActivityLogger::log(
                action: 'cloud_storage_connection_tested',
                description: $successMsg,
                subject: $setting,
                actor: $actor,
                metadata: $folderDetails
            );

            return [
                'success' => true,
                'message' => $successMsg,
                'details' => $folderDetails,
            ];
        } catch (Exception $e) {
            $err = "Pengujian koneksi Google Drive gagal: " . $e->getMessage();
            Log::warning($err);

            $setting->update([
                'connection_status' => CloudStorageSetting::STATUS_ERROR,
                'last_tested_at'    => now(),
                'last_error_summary'=> $err,
            ]);

            return [
                'success' => false,
                'message' => $err,
                'details' => [],
            ];
        }
    }
}
