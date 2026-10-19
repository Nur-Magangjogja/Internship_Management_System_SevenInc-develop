<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CloudStorageSetting;
use App\Models\GeneratedDocument;
use App\Models\InternshipRegistration;
use App\Models\SpreadsheetConnection;
use App\Models\User;
use App\Services\CloudStorage\CloudStorageInterface;
use App\Services\CloudStorage\GoogleDriveStorageDriver;
use App\Services\CloudStorage\LocalStorageFallbackDriver;
use App\Services\CloudStorage\SystemStorageConfigService;
use App\Services\MailMerge\VariableResolverService;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class DocumentPublishingService
{
    protected CloudStorageInterface $cloudDriver;

    public function __construct(
        protected SystemStorageConfigService $storageConfigService,
        protected DocumentGeneratorService $docNumberService,
        protected VariableResolverService $resolverService,
        ?CloudStorageInterface $cloudDriver = null
    ) {
        if ($cloudDriver) {
            $this->cloudDriver = $cloudDriver;
        } else {
            $googleDriver = new GoogleDriveStorageDriver();
            $this->cloudDriver = $googleDriver->isConfigured() ? $googleDriver : new LocalStorageFallbackDriver();
        }
    }

    /**
     * Daftarkan dokumen baru, lakukan render PDF, dan unggah ke cloud storage secara idempotent.
     */
    public function publishInternDocument(
        InternshipRegistration $intern,
        string $documentType,
        string $htmlContent,
        array $variableSnapshot = [],
        array $assetReferences = [],
        ?string $customDocNumber = null,
        ?User $actor = null
    ): GeneratedDocument {
        // 1. Validasi Kelayakan Bisnis
        if (!$this->docNumberService->canGenerate($intern, $documentType)) {
            throw new InvalidArgumentException("Status pemagang '{$intern->internship_status}' belum memenuhi syarat untuk penerbitan dokumen {$documentType}.");
        }

        // 2. Alokasi Nomor Dokumen Unik
        $docNumber = $customDocNumber;
        if (empty($docNumber)) {
            $docNumber = match (strtolower($documentType)) {
                GeneratedDocument::TYPE_LOA => $this->docNumberService->generateUniqueLoaNumber($intern),
                GeneratedDocument::TYPE_SKL => $this->docNumberService->generateUniqueSklNumber($intern),
                default                     => strtoupper($documentType) . '/' . date('Ymd') . '/' . $intern->id,
            };
        }

        $idempotencyKey = 'PUB-DOC-' . md5("{$documentType}-{$intern->id}-{$docNumber}");

        // Cek jika dokumen dengan idempotency key ini sudah pernah dipublish
        $existing = GeneratedDocument::where('idempotency_key', $idempotencyKey)->first();
        if ($existing && $existing->status === GeneratedDocument::STATUS_SYNCED && Storage::disk('local')->exists($existing->file_path)) {
            return $existing;
        }

        $year = date('Y');
        $safeDocNumber = Str::slug(str_replace('/', '-', $docNumber));
        $safeName = Str::slug($intern->fullname ?? 'intern', '_');
        $fileName = "{$safeDocNumber}_{$safeName}.pdf";
        $relativeLocalDir = "generated_documents/{$documentType}/{$year}";
        $relativeLocalPath = "{$relativeLocalDir}/{$fileName}";

        // 3. Render PDF ke disk lokal privat
        if (!Storage::disk('local')->exists($relativeLocalDir)) {
            Storage::disk('local')->makeDirectory($relativeLocalDir);
        }

        $fullLocalPath = Storage::disk('local')->path($relativeLocalPath);

        try {
            $pdf = Pdf::loadHTML($htmlContent)->setPaper('a4', 'portrait');
            $pdf->save($fullLocalPath);
        } catch (\Throwable $e) {
            Log::error("Gagal render PDF untuk {$documentType} (Reg #{$intern->id}): " . $e->getMessage());
            throw new RuntimeException("Gagal melakukan render PDF: " . $e->getMessage(), 0, $e);
        }

        if (!file_exists($fullLocalPath) || filesize($fullLocalPath) === 0) {
            throw new RuntimeException("Proses render PDF tidak menghasilkan berkas fisik yang valid.");
        }

        $fileSizeBytes = filesize($fullLocalPath);
        $checksum = hash_file('sha256', $fullLocalPath);

        // 4. Catat record GeneratedDocument dengan status GENERATED
        $doc = GeneratedDocument::updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'public_uuid'                => (string) Str::uuid(),
                'document_type'              => $documentType,
                'document_number'            => $docNumber,
                'internship_registration_id' => $intern->id,
                'institution_id'             => $intern->institution_id,
                'variable_snapshot'          => $variableSnapshot,
                'asset_references'           => $assetReferences,
                'file_path'                  => $relativeLocalPath,
                'file_name'                  => $fileName,
                'file_size_bytes'            => $fileSizeBytes,
                'checksum_sha256'            => $checksum,
                'status'                     => GeneratedDocument::STATUS_GENERATED,
                'published_at'               => now(),
                'generated_by'               => $actor?->id,
            ]
        );

        $doc->recordStatusChange(GeneratedDocument::STATUS_GENERATED, "Dokumen fisik PDF berhasil dibuat.");

        // 5. Coba sinkronisasi ke Google Drive Folder Pemagang Existing
        try {
            $this->syncDocumentToDrive($doc, $actor);
        } catch (\Throwable $syncEx) {
            Log::warning("Sinkronisasi otomatis ke Google Drive ditunda: " . $syncEx->getMessage());
        }

        return $doc->fresh();
    }

    /**
     * Sinkronisasikan berkas dokumen ke Google Drive pada subfolder pemagang existing.
     */
    public function syncDocumentToDrive(GeneratedDocument $doc, ?User $actor = null): array
    {
        if (!Storage::disk('local')->exists($doc->file_path)) {
            throw new RuntimeException("Berkas dokumen fisik lokal tidak ditemukan: {$doc->file_path}");
        }

        $fullPath = Storage::disk('local')->path($doc->file_path);
        $doc->loadMissing(['internshipRegistration', 'institution']);

        $intern = $doc->internshipRegistration;
        $remoteFolder = "Generated_Documents/General/" . Str::studly($doc->document_type);

        if ($intern) {
            // Gunakan struktur subfolder pemagang existing
            $year = $intern->created_at ? $intern->created_at->format('Y') : date('Y');
            $applicantName = Str::slug($intern->fullname ?? 'pemagang', '_');
            $regId = $intern->id;
            $typeFolder = strtoupper($doc->document_type);
            $remoteFolder = "Registrasi/{$year}/{$applicantName}_REG{$regId}/Dokumen_Diterbitkan/{$typeFolder}";

            // Gunakan configured drive folder ID dari koneksi registrasi pemagang
            if ($this->cloudDriver instanceof GoogleDriveStorageDriver) {
                try {
                    SpreadsheetConnection::ensureDriveFolderColumns();
                    $regFolderId = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)
                        ->value('drive_folder_id');
                    if (!empty($regFolderId)) {
                        $this->cloudDriver->setRootFolderId((string) $regFolderId);
                    }
                } catch (\Throwable) {
                    // Ignore
                }
            }
        } elseif ($doc->institution) {
            $institutionSlug = Str::slug($doc->institution->name, '_');
            $remoteFolder = "Generated_Documents/Institutions/{$institutionSlug}/" . strtoupper($doc->document_type);

            $settings = $this->storageConfigService->getSettings();
            if (!empty($settings->generated_docs_folder_id) && $this->cloudDriver instanceof GoogleDriveStorageDriver) {
                $this->cloudDriver->setRootFolderId($settings->generated_docs_folder_id);
            }
        }

        $doc->recordStatusChange(GeneratedDocument::STATUS_SYNC_PENDING, "Memulai proses sinkronisasi ke folder cloud: {$remoteFolder}");

        try {
            $uploadResult = $this->cloudDriver->uploadFile($fullPath, $remoteFolder, $doc->file_name);

            $isRealDrive = $this->cloudDriver->getDriverName() === 'google_drive'
                && !str_starts_with($uploadResult['file_id'], 'local_sim_')
                && !str_starts_with($uploadResult['file_id'], 'DRIVE-MOCK-');

            $doc->update([
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_web_view_link' => $uploadResult['web_view_link'],
                'status'              => $isRealDrive ? GeneratedDocument::STATUS_SYNCED : GeneratedDocument::STATUS_SYNC_PENDING,
                'synced_at'           => $isRealDrive ? now() : null,
                'sync_error_message'  => $isRealDrive ? null : 'Simulasi lokal: Google Drive belum terkonfigurasi aktif.',
            ]);

            $statusFinal = $isRealDrive ? GeneratedDocument::STATUS_SYNCED : GeneratedDocument::STATUS_SYNC_PENDING;
            $doc->recordStatusChange($statusFinal, "Sinkronisasi selesai dengan driver: {$uploadResult['driver']}");

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
        } catch (Exception $e) {
            $doc->update([
                'status'             => GeneratedDocument::STATUS_FAILED,
                'retry_count'        => $doc->retry_count + 1,
                'sync_error_message' => Str::limit($e->getMessage(), 500),
            ]);

            $doc->recordStatusChange(GeneratedDocument::STATUS_FAILED, "Gagal sinkronisasi: " . $e->getMessage());
            throw $e;
        }
    }
}
