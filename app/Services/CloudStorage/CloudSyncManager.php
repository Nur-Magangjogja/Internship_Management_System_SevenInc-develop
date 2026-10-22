<?php

namespace App\Services\CloudStorage;

use App\Models\ActivityLog;
use App\Models\ArchiveExport;
use App\Models\Mou;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CloudSyncManager
{
    protected CloudStorageInterface $driver;

    public function __construct(?CloudStorageInterface $driver = null)
    {
        if ($driver) {
            $this->driver = $driver;
        } else {
            $googleDriver = new GoogleDriveStorageDriver();
            $gasDriver = new GoogleAppsScriptDriveDriver();

            if ($googleDriver->isConfigured()) {
                $this->driver = $googleDriver;
            } elseif ($gasDriver->isConfigured()) {
                $this->driver = $gasDriver;
            } else {
                $this->driver = new LocalStorageFallbackDriver();
            }
        }
    }

    public function setDriver(CloudStorageInterface $driver): self
    {
        $this->driver = $driver;
        return $this;
    }

    public function getDriver(): CloudStorageInterface
    {
        return $this->driver;
    }

    /**
     * Sinkronisasikan berkas naskah MOU (signed atau generated) ke cloud storage.
     */
    public function syncMou(Mou $mou, ?User $actor = null): array
    {
        $mou->loadMissing('institution');

        $institutionSlug = Str::slug($mou->institution?->name ?? 'institusi-umum', '-');
        $safeNumber = Str::slug(str_replace('/', '-', $mou->company_mou_number), '-');
        $remoteFolder = "Institusi/{$institutionSlug}/MOU";

        // Pastikan juga logo institusi mitra disinkronkan ke folder khusus institusi (Institusi/{slug}/Logo)
        if ($mou->institution && $mou->institution->logo) {
            try {
                app(\App\Services\SystemAssetService::class)->syncInstitutionLogo($mou->institution, $actor);
            } catch (\Throwable $logoEx) {
                \Illuminate\Support\Facades\Log::warning("Gagal auto-sync logo institusi {$mou->institution->name}: " . $logoEx->getMessage());
            }
        }

        $fullPath = null;
        $customFileName = null;

        if (!empty($mou->signed_file_path)) {
            if (Storage::disk('public')->exists($mou->signed_file_path)) {
                $fullPath = Storage::disk('public')->path($mou->signed_file_path);
            } elseif (Storage::disk('local')->exists($mou->signed_file_path)) {
                $fullPath = Storage::disk('local')->path($mou->signed_file_path);
            }
            $customFileName = "MOU-{$safeNumber}-signed.pdf";
        }

        if (!$fullPath) {
            // Cek atau buat draf PDF dari rendered_document_html
            $draftPdfRelative = "documents/mou/generated/MOU-DRAFT-{$safeNumber}.pdf";

            if (!Storage::disk('public')->exists($draftPdfRelative) && !empty($mou->rendered_document_html)) {
                try {
                    $pageConfig = $mou->render_data_snapshot['_page_config'] ?? \App\Models\MouTemplate::getDefaultPageConfig();
                    $pageConfig['institution_logo'] = $mou->render_data_snapshot['institution_logo_path']
                        ?? ($mou->institution?->logo_url ?: ($mou->institution?->logo ? ('/storage/' . $mou->institution->logo) : '/images/mou/institution-logo-placeholder.png'));
                    $pageConfig['company_logo'] = $mou->render_data_snapshot['company_logo_path']
                        ?? ($mou->render_data_snapshot['company_logo']
                        ?? ($mou->render_data_snapshot['_media_assets']['company_logo']
                        ?? '/images/mou/seven-inc-logo.png'));

                    $pdfBinary = \App\Services\MouDocumentRenderer::renderPdfBinary(
                        $mou->rendered_document_html,
                        $pageConfig,
                        $mou->company_mou_number . ' - ' . $mou->first_party_institution_name
                    );
                    Storage::disk('public')->put($draftPdfRelative, $pdfBinary);
                } catch (\Throwable $pdfEx) {
                    \Illuminate\Support\Facades\Log::warning("Gagal generate draf PDF untuk MOU {$mou->company_mou_number}: " . $pdfEx->getMessage());
                }
            }

            if (Storage::disk('public')->exists($draftPdfRelative)) {
                $fullPath = Storage::disk('public')->path($draftPdfRelative);
                $customFileName = "MOU-{$safeNumber}-draf.pdf";
            } elseif (!empty($mou->generated_file_path)) {
                if (Storage::disk('public')->exists($mou->generated_file_path)) {
                    $fullPath = Storage::disk('public')->path($mou->generated_file_path);
                } elseif (Storage::disk('local')->exists($mou->generated_file_path)) {
                    $fullPath = Storage::disk('local')->path($mou->generated_file_path);
                }
                $customFileName = "MOU-{$safeNumber}-draf.html";
            }
        }

        if (!$fullPath || !file_exists($fullPath)) {
            throw new RuntimeException("Berkas fisik MOU tidak ditemukan di server: " . ($mou->signed_file_path ?: $mou->generated_file_path));
        }

        $uploadResult = $this->driver->uploadFile($fullPath, $remoteFolder, $customFileName);

        $isRealDrive = in_array($this->driver->getDriverName(), ['google_drive', 'google_apps_script_drive'], true)
            && !str_starts_with($uploadResult['file_id'], 'local_sim_')
            && !str_starts_with($uploadResult['file_id'], 'DRIVE-MOCK-');

        $mou->update([
            'drive_file_id'       => $uploadResult['file_id'],
            'drive_web_view_link' => $uploadResult['web_view_link'],
            'drive_synced_at'     => $isRealDrive ? now() : null,
        ]);

        $genDoc = \App\Models\GeneratedDocument::where('document_type', \App\Models\GeneratedDocument::TYPE_MOU)
            ->where('document_number', $mou->company_mou_number)
            ->first();

        if ($genDoc) {
            $genDoc->update([
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'status'              => $isRealDrive ? \App\Models\GeneratedDocument::STATUS_SYNCED : \App\Models\GeneratedDocument::STATUS_SYNC_PENDING,
                'synced_at'           => $isRealDrive ? now() : null,
                'sync_error_message'  => $isRealDrive ? null : 'Simulasi lokal: Google Drive belum terkonfigurasi aktif.',
            ]);
        }

        ActivityLogger::log(
            action: 'mou_drive_synced',
            description: "Berkas MOU {$mou->company_mou_number} berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $mou,
            actor: $actor,
            metadata: [
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'folder'              => $remoteFolder,
                'file_name'           => $customFileName,
                'driver'              => $uploadResult['driver'],
            ]
        );

        return $uploadResult;
    }

    /**
     * Sinkronisasikan paket berkas arsip tahunan ZIP ke cloud storage.
     * CATATAN ADDENDUM FINAL V4: Pembuatan arsip tahunan baru ke Google Drive dinonaktifkan.
     * Berkas historis yang sudah ada tetap aman dan dipertahankan.
     */
    public function syncArchiveExport(ArchiveExport $archive, ?User $actor = null): array
    {
        Log::info("syncArchiveExport: Pembuatan arsip tahunan baru ke Cloud Storage dinonaktifkan per Addendum Final V4. Berkas arsip historis tetap utuh.");

        return [
            'success'       => true,
            'driver'        => 'deactivated',
            'file_id'       => $archive->drive_file_id,
            'web_view_link' => $archive->drive_web_view_link,
            'message'       => 'Penyimpanan paket arsip tahunan baru ke cloud storage telah dinonaktifkan. Arsip historis dipertahankan.',
        ];
    }

    /**
     * Sinkronisasikan berkas lampiran registrasi (CV, Portofolio, dll) ke cloud storage via Direct Drive API.
     */
    /**
     * Lakukan operasi upload berkas lampiran ke remote storage tanpa mutasi database (khusus worker jalur fencing).
     */
    public function uploadRegistrationAttachment(\App\Models\RegistrationAttachment $attachment): array
    {
        $attachment->loadMissing('registration');

        // S17.18G.5 Fix B: Resolusi berkas lampiran dari disk 'local' (privat), fallback ke 'public' (legacy)
        $localDisk = 'local';
        if (!Storage::disk($localDisk)->exists($attachment->file_path)) {
            $localDisk = 'public';
            if (!Storage::disk($localDisk)->exists($attachment->file_path)) {
                if (array_key_exists('private', config('filesystems.disks', [])) && Storage::disk('private')->exists($attachment->file_path)) {
                    $localDisk = 'private';
                } else {
                    throw new RuntimeException("Berkas fisik lampiran tidak ditemukan di server: {$attachment->file_path}");
                }
            }
        }

        $fullPath = Storage::disk($localDisk)->path($attachment->file_path);
        $year = $attachment->registration?->created_at ? $attachment->registration->created_at->format('Y') : date('Y');
        $regId = $attachment->internship_registration_id;
        $applicantName = $attachment->registration?->fullname ?? ($attachment->registration?->user?->name ?? ($attachment->registration?->name ?? 'Pemagang'));
        $cleanName = Str::slug($applicantName, '_');
        $remoteFolder = "Registrasi/{$year}/{$cleanName}_REG{$regId}";

        $fileChecksum = hash_file('sha256', $fullPath);
        $contentVersion = substr($fileChecksum, 0, 16);
        $baseFileName = $attachment->original_name ?: basename($attachment->file_path);
        $customFileName = "ATT-{$regId}-{$attachment->id}-v{$contentVersion}_{$baseFileName}";

        if ($this->driver instanceof GoogleDriveStorageDriver) {
            try {
                \App\Models\SpreadsheetConnection::ensureDriveFolderColumns();
                $configuredFolderId = \App\Models\SpreadsheetConnection::where('connection_key', \App\Models\SpreadsheetConnection::KEY_REGISTRATION)
                    ->value('drive_folder_id');
                if (!empty($configuredFolderId)) {
                    $this->driver->setRootFolderId((string) $configuredFolderId);
                }
            } catch (\Throwable) {
                // Abaikan jika tabel belum tersedia di unit test ringan
            }
        }

        $uploadResult = $this->driver->uploadFile($fullPath, $remoteFolder, $customFileName);
        $uploadResult['file_checksum'] = $fileChecksum;
        $uploadResult['content_version'] = $contentVersion;
        $uploadResult['remote_folder'] = $remoteFolder;

        return $uploadResult;
    }

    /**
     * Sinkronisasikan berkas lampiran registrasi (CV, Portofolio, dll) ke cloud storage via Direct Drive API.
     */
    public function syncRegistrationAttachment(\App\Models\RegistrationAttachment $attachment, ?User $actor = null): array
    {
        $uploadResult = $this->uploadRegistrationAttachment($attachment);

        $isRealDrive = in_array($this->driver->getDriverName(), ['google_drive', 'google_apps_script_drive'], true)
            && !str_starts_with($uploadResult['file_id'], 'local_sim_')
            && !str_starts_with($uploadResult['file_id'], 'DRIVE-MOCK-');

        $attachment->update([
            'drive_file_id'       => $uploadResult['file_id'],
            'drive_web_view_link' => $uploadResult['web_view_link'],
            'drive_sync_status'   => $isRealDrive ? \App\Models\RegistrationAttachment::STATUS_SYNCED : \App\Models\RegistrationAttachment::STATUS_PENDING,
            'drive_synced_at'     => $isRealDrive ? now() : null,
            'drive_file_checksum' => $uploadResult['file_checksum'] ?? null,
            'drive_error_message' => $isRealDrive ? null : 'Simulated local storage fallback: Google Drive not configured.',
        ]);

        $regId = $attachment->internship_registration_id;
        $remoteFolder = $uploadResult['remote_folder'] ?? "Pemagang/Reg-{$regId}";

        ActivityLogger::log(
            action: 'attachment_drive_synced',
            description: "Berkas lampiran '{$attachment->original_name}' (Reg #{$regId}) berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $attachment,
            actor: $actor,
            metadata: [
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'folder'              => $remoteFolder,
                'driver'              => $uploadResult['driver'],
            ]
        );

        return $uploadResult;
    }

    /**
     * Sinkronisasikan dokumen LOA ke Google Drive.
     */
    public function syncLoaFile(\App\Models\InternLoa $loa, string $localFilePath, ?User $actor = null): array
    {
        if (!file_exists($localFilePath)) {
            throw new RuntimeException("Berkas LOA fisik tidak ditemukan: {$localFilePath}");
        }

        $year = $loa->created_at ? $loa->created_at->format('Y') : date('Y');
        $remoteFolder = "Dokumen/{$year}/LOA";
        $fileName = Str::slug($loa->loa_number) . '.pdf';

        $uploadResult = $this->driver->uploadFile($localFilePath, $remoteFolder, $fileName);

        ActivityLogger::log(
            action: 'loa_drive_synced',
            description: "Dokumen LOA {$loa->loa_number} berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $loa,
            actor: $actor,
            metadata: [
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'folder'              => $remoteFolder,
            ]
        );

        return $uploadResult;
    }

    /**
     * Sinkronisasikan dokumen SKL ke Google Drive.
     */
    public function syncSklFile(\App\Models\SklDocument $skl, string $localFilePath, ?User $actor = null): array
    {
        if (!file_exists($localFilePath)) {
            throw new RuntimeException("Berkas SKL fisik tidak ditemukan: {$localFilePath}");
        }

        $year = $skl->created_at ? $skl->created_at->format('Y') : date('Y');
        $remoteFolder = "Dokumen/{$year}/SKL";
        $fileName = Str::slug($skl->skl_number) . '.pdf';

        $uploadResult = $this->driver->uploadFile($localFilePath, $remoteFolder, $fileName);

        ActivityLogger::log(
            action: 'skl_drive_synced',
            description: "Dokumen SKL {$skl->skl_number} berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $skl,
            actor: $actor,
            metadata: [
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'folder'              => $remoteFolder,
            ]
        );

        return $uploadResult;
    }

    /**
     * Sinkronisasikan sertifikat ke Google Drive.
     */
    public function syncCertificateFile(\Illuminate\Database\Eloquent\Model $cert, string $localFilePath, string $certNumber, ?User $actor = null): array
    {
        if (!file_exists($localFilePath)) {
            throw new RuntimeException("Berkas Sertifikat fisik tidak ditemukan: {$localFilePath}");
        }

        $year = date('Y');
        $remoteFolder = "Dokumen/{$year}/Sertifikat";
        $fileName = Str::slug($certNumber) . '.pdf';

        $uploadResult = $this->driver->uploadFile($localFilePath, $remoteFolder, $fileName);

        ActivityLogger::log(
            action: 'certificate_drive_synced',
            description: "Sertifikat {$certNumber} berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $cert,
            actor: $actor,
            metadata: [
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'folder'              => $remoteFolder,
            ]
        );

        return $uploadResult;
    }

    /**
     * Sinkronisasikan materi/berkas webinar ke Google Drive.
     */
    public function syncWebinarFile(\App\Models\Webinar $webinar, string $localFilePath, string $fileName, ?User $actor = null): array
    {
        if (!file_exists($localFilePath)) {
            throw new RuntimeException("Berkas Webinar fisik tidak ditemukan: {$localFilePath}");
        }

        $year = $webinar->created_at ? $webinar->created_at->format('Y') : date('Y');
        $webinarSlug = Str::slug($webinar->title ?? 'webinar-' . $webinar->id);
        $remoteFolder = "Webinar/{$year}/{$webinarSlug}";

        $uploadResult = $this->driver->uploadFile($localFilePath, $remoteFolder, $fileName);

        ActivityLogger::log(
            action: 'webinar_drive_synced',
            description: "Berkas Webinar '{$webinar->title}' ({$fileName}) berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $webinar,
            actor: $actor,
            metadata: [
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'folder'              => $remoteFolder,
            ]
        );

        return $uploadResult;
    }

    /**
     * Sinkronisasikan berkas aset sistem ke cloud storage (Google Drive).
     */
    public function syncSystemAsset(\App\Models\SystemAsset $asset, ?User $actor = null): array
    {
        if (!Storage::disk('local')->exists($asset->local_path)) {
            throw new RuntimeException("Berkas fisik lokal aset tidak ditemukan: {$asset->local_path}");
        }

        $fullPath = Storage::disk('local')->path($asset->local_path);
        $settings = \App\Models\CloudStorageSetting::getEffectiveSettings();

        // Tentukan remote folder hierarkis
        $categoryDir = Str::plural(Str::studly($asset->category));
        $remoteFolder = match ($asset->scope) {
            \App\Models\SystemAsset::SCOPE_BRAND       => "System_Assets/Brands/" . Str::slug($asset->brand?->name ?? 'brand', '_'),
            \App\Models\SystemAsset::SCOPE_INSTITUTION => "System_Assets/Institutions/" . Str::slug($asset->institution?->name ?? 'institution', '_'),
            default                                    => "System_Assets/Global/{$categoryDir}",
        };

        if (!empty($settings->system_assets_folder_id) && $this->driver instanceof GoogleDriveStorageDriver) {
            $this->driver->setRootFolderId($settings->system_assets_folder_id);
        }

        $fileName = Str::slug($asset->name, '_') . "_v{$asset->version}." . pathinfo($asset->local_path, PATHINFO_EXTENSION);
        $uploadResult = $this->driver->uploadFile($fullPath, $remoteFolder, $fileName);

        $isRealDrive = in_array($this->driver->getDriverName(), ['google_drive', 'google_apps_script_drive'], true)
            && !str_starts_with($uploadResult['file_id'], 'local_sim_')
            && !str_starts_with($uploadResult['file_id'], 'DRIVE-MOCK-');

        $asset->update([
            'drive_file_id'       => $uploadResult['file_id'],
            'drive_web_view_link' => $uploadResult['web_view_link'],
            'sync_status'         => $isRealDrive ? \App\Models\SystemAsset::SYNC_STATUS_SYNCED : \App\Models\SystemAsset::SYNC_STATUS_PENDING,
            'drive_synced_at'     => $isRealDrive ? now() : null,
            'sync_error_message'  => $isRealDrive ? null : 'Simulasi lokal: Google Drive belum terkonfigurasi aktif.',
        ]);

        ActivityLogger::log(
            action: 'system_asset_drive_synced',
            description: "Aset sistem '{$asset->name}' berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $asset,
            actor: $actor,
            metadata: [
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'folder'              => $remoteFolder,
                'driver'              => $uploadResult['driver'],
            ]
        );

        return $uploadResult;
    }

    /**
     * Sinkronisasikan berkas dokumen hasil generate ke cloud storage (Google Drive).
     */
    public function syncGeneratedDocument(\App\Models\GeneratedDocument $doc, ?User $actor = null): array
    {
        if (!Storage::disk('local')->exists($doc->file_path)) {
            throw new RuntimeException("Berkas dokumen fisik lokal tidak ditemukan: {$doc->file_path}");
        }

        $fullPath = Storage::disk('local')->path($doc->file_path);
        $doc->loadMissing(['internshipRegistration', 'institution']);

        $intern = $doc->internshipRegistration;
        $remoteFolder = "Generated_Documents/General/" . Str::studly($doc->document_type);

        if ($intern) {
            $year = $intern->created_at ? $intern->created_at->format('Y') : date('Y');
            $applicantName = Str::slug($intern->fullname ?? 'pemagang', '_');
            $regId = $intern->id;
            $typeFolder = strtoupper($doc->document_type);
            $remoteFolder = "Registrasi/{$year}/{$applicantName}_REG{$regId}/Dokumen_Diterbitkan/{$typeFolder}";

            if ($this->driver instanceof GoogleDriveStorageDriver) {
                try {
                    \App\Models\SpreadsheetConnection::ensureDriveFolderColumns();
                    $regFolderId = \App\Models\SpreadsheetConnection::where('connection_key', \App\Models\SpreadsheetConnection::KEY_REGISTRATION)
                        ->value('drive_folder_id');
                    if (!empty($regFolderId)) {
                        $this->driver->setRootFolderId((string) $regFolderId);
                    }
                } catch (\Throwable) {
                    // Ignore
                }
            }
        } elseif ($doc->institution) {
            $institutionSlug = Str::slug($doc->institution->name, '_');
            $remoteFolder = "Generated_Documents/Institutions/{$institutionSlug}/" . strtoupper($doc->document_type);

            $settings = \App\Models\CloudStorageSetting::getEffectiveSettings();
            if (!empty($settings->generated_docs_folder_id) && $this->driver instanceof GoogleDriveStorageDriver) {
                $this->driver->setRootFolderId($settings->generated_docs_folder_id);
            }
        }

        $uploadResult = $this->driver->uploadFile($fullPath, $remoteFolder, $doc->file_name);

        $isRealDrive = in_array($this->driver->getDriverName(), ['google_drive', 'google_apps_script_drive'], true)
            && !str_starts_with($uploadResult['file_id'], 'local_sim_')
            && !str_starts_with($uploadResult['file_id'], 'DRIVE-MOCK-');

        $doc->update([
            'drive_file_id'       => $uploadResult['file_id'],
            'drive_web_view_link' => $uploadResult['web_view_link'],
            'status'              => $isRealDrive ? \App\Models\GeneratedDocument::STATUS_SYNCED : \App\Models\GeneratedDocument::STATUS_SYNC_PENDING,
            'synced_at'           => $isRealDrive ? now() : null,
            'sync_error_message'  => $isRealDrive ? null : 'Simulasi lokal: Google Drive belum terkonfigurasi aktif.',
        ]);

        ActivityLogger::log(
            action: 'document_drive_synced',
            description: "Dokumen {$doc->document_number} ({$doc->document_type}) berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $doc,
            actor: $actor,
            metadata: [
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'folder'              => $remoteFolder,
                'driver'              => $uploadResult['driver'],
            ]
        );

        return $uploadResult;
    }
}
