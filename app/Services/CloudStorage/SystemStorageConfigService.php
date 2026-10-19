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

        $updatePayload = [
            'name'                          => $data['name'] ?? $setting->name,
            'system_assets_folder_url'      => $data['system_assets_folder_url'] ?? null,
            'document_templates_folder_url' => $data['document_templates_folder_url'] ?? null,
            'generated_docs_folder_url'     => $data['generated_docs_folder_url'] ?? null,
            'archives_folder_url'           => $data['archives_folder_url'] ?? null,
            'is_enabled'                    => isset($data['is_enabled']) ? (bool) $data['is_enabled'] : $setting->is_enabled,
            'updated_by'                    => $actor?->id,
        ];

        // Ekstraksi Folder ID jika user menginput URL atau ID langsung
        $updatePayload['system_assets_folder_id'] = CloudStorageSetting::extractDriveFolderId($data['system_assets_folder_url'] ?? null)
            ?: CloudStorageSetting::extractDriveFolderId($data['system_assets_folder_id'] ?? null);

        $updatePayload['document_templates_folder_id'] = CloudStorageSetting::extractDriveFolderId($data['document_templates_folder_url'] ?? null)
            ?: CloudStorageSetting::extractDriveFolderId($data['document_templates_folder_id'] ?? null);

        $updatePayload['generated_docs_folder_id'] = CloudStorageSetting::extractDriveFolderId($data['generated_docs_folder_url'] ?? null)
            ?: CloudStorageSetting::extractDriveFolderId($data['generated_docs_folder_id'] ?? null);

        $updatePayload['archives_folder_id'] = CloudStorageSetting::extractDriveFolderId($data['archives_folder_url'] ?? null)
            ?: CloudStorageSetting::extractDriveFolderId($data['archives_folder_id'] ?? null);

        $setting->update($updatePayload);

        ActivityLogger::log(
            action: 'cloud_storage_settings_updated',
            description: "Konfigurasi penyimpanan cloud '{$setting->name}' telah diperbarui.",
            subject: $setting,
            actor: $actor,
            metadata: [
                'system_assets_id'     => $setting->system_assets_folder_id,
                'document_templates_id'=> $setting->document_templates_folder_id,
                'generated_docs_id'    => $setting->generated_docs_folder_id,
                'archives_id'          => $setting->archives_folder_id,
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
        $driver = new GoogleDriveStorageDriver();

        if (!$driver->isConfigured()) {
            $errorSummary = "Kredensial Google Drive belum lengkap pada konfigurasi layanan server (Client ID / Secret / Refresh Token).";
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

            // 2. Periksa akses ke tiap folder yang terdaftar
            $foldersToCheck = [
                'System Assets'      => $setting->system_assets_folder_id,
                'Document Templates' => $setting->document_templates_folder_id,
                'Generated Documents'=> $setting->generated_docs_folder_id,
                'Archives'           => $setting->archives_folder_id,
            ];

            $folderDetails = [];
            $missingOrInaccessible = [];

            foreach ($foldersToCheck as $label => $folderId) {
                if (empty($folderId)) {
                    $folderDetails[$label] = ['status' => 'not_set', 'name' => null];
                    continue;
                }

                $res = Http::withToken($token)
                    ->get("https://www.googleapis.com/drive/v3/files/{$folderId}?fields=id,name,mimeType,trashed,capabilities");

                if ($res->successful()) {
                    $data = $res->json();
                    if (($data['trashed'] ?? false) === true) {
                        $folderDetails[$label] = ['status' => 'trashed', 'name' => $data['name'] ?? null];
                        $missingOrInaccessible[] = "{$label} (folder berada di tempat sampah)";
                    } elseif (($data['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
                        $folderDetails[$label] = ['status' => 'not_a_folder', 'name' => $data['name'] ?? null];
                        $missingOrInaccessible[] = "{$label} (ID yang dimasukkan bukan tipe folder)";
                    } else {
                        $canAddChildren = $data['capabilities']['canAddChildren'] ?? true;
                        $folderDetails[$label] = [
                            'status'           => 'accessible',
                            'name'             => $data['name'] ?? null,
                            'can_add_children' => $canAddChildren,
                        ];
                    }
                } else {
                    $folderDetails[$label] = ['status' => 'inaccessible', 'error' => $res->status()];
                    $missingOrInaccessible[] = "{$label} (HTTP {$res->status()} - tidak dapat diakses atau izin ditolak)";
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
