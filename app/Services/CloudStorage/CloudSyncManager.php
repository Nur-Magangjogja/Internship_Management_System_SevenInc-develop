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
            if ($googleDriver->isConfigured()) {
                $this->driver = $googleDriver;
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

        $relativeFilePath = $mou->signed_file_path ?: $mou->generated_file_path;
        if (empty($relativeFilePath)) {
            throw new InvalidArgumentException("MOU {$mou->company_mou_number} belum memiliki berkas fisik yang dapat disinkronkan.");
        }

        $localDisk = $mou->signed_file_path ? 'public' : 'local';
        if (!Storage::disk($localDisk)->exists($relativeFilePath)) {
            // Cek di disk sebaliknya sebagai fallback
            $fallbackDisk = $localDisk === 'public' ? 'local' : 'public';
            if (Storage::disk($fallbackDisk)->exists($relativeFilePath)) {
                $localDisk = $fallbackDisk;
            } else {
                throw new RuntimeException("Berkas fisik MOU tidak ditemukan di server: {$relativeFilePath}");
            }
        }

        $fullPath = Storage::disk($localDisk)->path($relativeFilePath);

        // Struktur folder hierarkis: Institusi/{institution-slug}/MOU
        $institutionSlug = Str::slug($mou->institution?->name ?? 'institusi-umum');
        $remoteFolder = "Institusi/{$institutionSlug}/MOU";

        $customFileName = Str::slug($mou->company_mou_number) . '-signed.pdf';

        $uploadResult = $this->driver->uploadFile($fullPath, $remoteFolder, $customFileName);

        $mou->update([
            'drive_file_id'       => $uploadResult['file_id'],
            'drive_web_view_link' => $uploadResult['web_view_link'],
            'drive_synced_at'     => now(),
        ]);

        ActivityLogger::log(
            action: 'mou_drive_synced',
            description: "Berkas MOU {$mou->company_mou_number} berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $mou,
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
     * Sinkronisasikan paket berkas arsip tahunan ZIP ke cloud storage.
     */
    public function syncArchiveExport(ArchiveExport $archive, ?User $actor = null): array
    {
        if (!Storage::disk('local')->exists($archive->file_path)) {
            throw new RuntimeException("Berkas arsip ZIP tidak ditemukan di server: {$archive->file_path}");
        }

        $fullPath = Storage::disk('local')->path($archive->file_path);

        // Struktur folder hierarkis: Arsip/{year}
        $remoteFolder = "Arsip/{$archive->archive_year}";

        $uploadResult = $this->driver->uploadFile($fullPath, $remoteFolder, $archive->file_name);

        $archive->update([
            'drive_file_id'       => $uploadResult['file_id'],
            'drive_web_view_link' => $uploadResult['web_view_link'],
            'drive_synced_at'     => now(),
        ]);

        ActivityLogger::log(
            action: 'archive_drive_synced',
            description: "Paket arsip tahun {$archive->archive_year} ({$archive->file_name}) berhasil disinkronkan ke Cloud Storage ({$uploadResult['driver']})",
            subject: $archive,
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
     * Sinkronisasikan berkas lampiran registrasi (CV, Portofolio, dll) ke cloud storage via Direct Drive API.
     */
    public function syncRegistrationAttachment(\App\Models\RegistrationAttachment $attachment, ?User $actor = null): array
    {
        $attachment->loadMissing('registration');

        $localDisk = 'private';
        if (!Storage::disk($localDisk)->exists($attachment->file_path)) {
            $localDisk = 'public';
            if (!Storage::disk($localDisk)->exists($attachment->file_path)) {
                throw new RuntimeException("Berkas fisik lampiran tidak ditemukan di server: {$attachment->file_path}");
            }
        }

        $fullPath = Storage::disk($localDisk)->path($attachment->file_path);
        $year = $attachment->registration?->created_at ? $attachment->registration->created_at->format('Y') : date('Y');
        $regId = $attachment->internship_registration_id;
        $remoteFolder = "Lampiran/{$year}/Reg-{$regId}";

        $customFileName = $attachment->original_name ?: basename($attachment->file_path);

        $uploadResult = $this->driver->uploadFile($fullPath, $remoteFolder, $customFileName);

        $attachment->update([
            'drive_file_id'       => $uploadResult['file_id'],
            'drive_web_view_link' => $uploadResult['web_view_link'],
            'drive_sync_status'   => \App\Models\RegistrationAttachment::STATUS_SYNCED,
            'drive_synced_at'     => now(),
            'drive_error_message' => null,
        ]);

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
}
