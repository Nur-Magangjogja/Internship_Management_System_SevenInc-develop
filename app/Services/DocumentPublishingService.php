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
        ?string $htmlContent = null,
        array $variableSnapshot = [],
        array $assetReferences = [],
        ?string $customDocNumber = null,
        ?User $actor = null,
        ?string $pdfBinary = null,
        ?\App\Models\DocumentTemplate $template = null
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

        // Kunci idempotensi stabil berbasis identitas penerbitan pemagang dan jenis dokumen
        $idempotencyKey = 'PUB-DOC-' . md5("intern-{$intern->id}-{$documentType}");

        // Cek jika dokumen dengan idempotency key ini sudah pernah dibuat/dipublish
        $existing = GeneratedDocument::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $hasLocalFile = Storage::disk('local')->exists($existing->file_path);
            if (in_array($existing->status, [GeneratedDocument::STATUS_GENERATED, GeneratedDocument::STATUS_SYNC_PENDING, GeneratedDocument::STATUS_SYNCED]) && $hasLocalFile) {
                return $existing;
            }
        }

        $year = date('Y');
        $safeDocNumber = Str::slug(str_replace('/', '-', $docNumber));
        $safeName = Str::slug($intern->fullname ?? 'intern', '_');
        $fileName = "{$safeDocNumber}_{$safeName}.pdf";
        $relativeLocalDir = "generated_documents/{$documentType}/{$year}";
        $relativeLocalPath = "{$relativeLocalDir}/{$fileName}";

        // 3. Render atau simpan PDF ke disk lokal privat
        if (!Storage::disk('local')->exists($relativeLocalDir)) {
            Storage::disk('local')->makeDirectory($relativeLocalDir);
        }

        $fullLocalPath = Storage::disk('local')->path($relativeLocalPath);

        if ($pdfBinary !== null && strlen($pdfBinary) > 0) {
            Storage::disk('local')->put($relativeLocalPath, $pdfBinary);
        } else {
            try {
                $paperSize = 'a4';
                $orientation = ($documentType === 'certificate' || $template?->type === 'certificate') ? 'landscape' : 'portrait';
                $pdf = Pdf::loadHTML($htmlContent ?? '')->setPaper($paperSize, $orientation);
                $pdf->save($fullLocalPath);
            } catch (\Throwable $e) {
                Log::error("Gagal render PDF untuk {$documentType} (Reg #{$intern->id}): " . $e->getMessage());
                throw new RuntimeException("Gagal melakukan render PDF: " . $e->getMessage(), 0, $e);
            }
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
                'template_type'              => $template?->type ?? $documentType,
                'template_id'                => $template?->id,
                'template_version'           => $template?->version_number ?? 1,
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
     * Terbitkan berkas PDF MOU final secara idempotent dan catat sebagai GeneratedDocument.
     */
    public function publishMouDocument(\App\Models\Mou $mou, ?User $actor = null): GeneratedDocument
    {
        $mou->loadMissing(['institution', 'template']);

        $idempotencyKey = 'PUB-MOU-' . md5("mou-{$mou->id}-{$mou->company_mou_number}");

        $existing = GeneratedDocument::where('idempotency_key', $idempotencyKey)->first();
        if ($existing && in_array($existing->status, [GeneratedDocument::STATUS_GENERATED, GeneratedDocument::STATUS_SYNC_PENDING, GeneratedDocument::STATUS_SYNCED]) && Storage::disk('local')->exists($existing->file_path)) {
            return $existing;
        }

        $template = $mou->template;
        $pageConfig = $mou->render_data_snapshot['_page_config'] ?? \App\Models\MouTemplate::getDefaultPageConfig();

        // 1. Render PDF binary menggunakan MouDocumentRenderer
        $pdfBinary = \App\Services\MouDocumentRenderer::renderPdfBinary(
            $mou->rendered_document_html,
            $pageConfig,
            $mou->company_mou_number . ' - ' . $mou->first_party_institution_name
        );

        $year = $mou->agreement_date ? date('Y', strtotime($mou->agreement_date)) : date('Y');
        $safeNumber = Str::slug(str_replace('/', '-', $mou->company_mou_number), '-');
        $instSlug = Str::slug($mou->first_party_institution_name ?? ($mou->institution?->name ?? 'institusi'), '_');
        $fileName = "MOU_{$safeNumber}_{$instSlug}.pdf";
        $relativeLocalDir = "generated_documents/mou/{$year}";
        $relativeLocalPath = "{$relativeLocalDir}/{$fileName}";

        if (!Storage::disk('local')->exists($relativeLocalDir)) {
            Storage::disk('local')->makeDirectory($relativeLocalDir);
        }

        Storage::disk('local')->put($relativeLocalPath, $pdfBinary);
        $fullLocalPath = Storage::disk('local')->path($relativeLocalPath);

        $fileSizeBytes = strlen($pdfBinary);
        $checksum = hash('sha256', $pdfBinary);

        $snapshot = array_merge($mou->render_data_snapshot ?? [], [
            'template_id'      => $template?->id,
            'template_name'    => $template?->name ?? 'Default MoU Template',
            'template_version' => $template?->version_number ?? 1,
            'mou_id'           => $mou->id,
        ]);

        $assetReferences = $mou->render_data_snapshot['_media_assets'] ?? [];

        $doc = GeneratedDocument::updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'public_uuid'                => (string) Str::uuid(),
                'document_type'              => GeneratedDocument::TYPE_MOU,
                'document_number'            => $mou->company_mou_number,
                'institution_id'             => $mou->institution_id,
                'internship_registration_id' => null,
                'template_type'              => 'mou',
                'template_id'                => $mou->mou_template_id,
                'template_version'           => $template?->version_number ?? 1,
                'variable_snapshot'          => $snapshot,
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

        $doc->recordStatusChange(GeneratedDocument::STATUS_GENERATED, "Dokumen fisik PDF MOU berhasil dibuat.");

        // 2. Coba sinkronisasi ke Google Drive
        try {
            $this->syncDocumentToDrive($doc, $actor);
        } catch (\Throwable $syncEx) {
            Log::warning("Sinkronisasi otomatis MOU ke Google Drive ditunda: " . $syncEx->getMessage());
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
        $existingFolderId = null;

        if ($intern) {
            // 1. Cek apakah dokumen ini atau dokumen pemagang sejenis sudah memiliki drive_folder_id permanen
            $existingFolderId = $doc->drive_folder_id;
            if (empty($existingFolderId)) {
                $existingFolderId = GeneratedDocument::where('internship_registration_id', $intern->id)
                    ->where('document_type', $doc->document_type)
                    ->whereNotNull('drive_folder_id')
                    ->latest('id')
                    ->value('drive_folder_id');
            }

            if (!empty($existingFolderId)) {
                // Folder sebenarnya sudah tercatat permanen: gunakan ID langsung tanpa membangun path string
                $remoteFolder = $existingFolderId;
            } else {
                // Gunakan struktur subfolder pemagang jika belum ada folder tercatat
                $year = $intern->created_at ? $intern->created_at->format('Y') : date('Y');
                $applicantName = Str::slug($intern->fullname ?? 'pemagang', '_');
                $regId = $intern->id;
                $typeFolder = strtoupper($doc->document_type);
                $remoteFolder = "Registrasi/{$year}/{$applicantName}_REG{$regId}/Dokumen_Diterbitkan/{$typeFolder}";
            }

            // Gunakan configured drive folder ID dari koneksi registrasi pemagang jika menggunakan path
            if (empty($existingFolderId) && $this->cloudDriver instanceof GoogleDriveStorageDriver) {
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

            $targetFolderId = $uploadResult['folder_id'] ?? $existingFolderId ?? $doc->drive_folder_id;

            $doc->update([
                'drive_file_id'       => $uploadResult['file_id'],
                'drive_folder_id'     => $targetFolderId,
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

    /**
     * Ulangi sinkronisasi cloud storage untuk dokumen yang gagal/pending secara aman dan idempotent.
     */
    public function retrySync(GeneratedDocument $doc, ?User $actor = null): array
    {
        return $this->syncDocumentToDrive($doc, $actor);
    }
}
