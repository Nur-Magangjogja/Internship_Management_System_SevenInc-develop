<?php

namespace App\Jobs;

use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Services\CloudStorage\CloudSyncManager;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class UploadRegistrationAttachmentToDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * Durable fencing token assigned before queue serialization and reconstructed safely on failure callbacks.
     */
    public string $fenceToken;

    public function __construct(
        public int $attachmentId,
        ?string $fenceToken = null
    ) {
        $this->fenceToken = $fenceToken ?? (string) Str::uuid();
    }

    /**
     * Execute the job.
     */
    public function handle(
        SpreadsheetTransportInterface $transport,
        SpreadsheetSyncEngineService $syncEngine
    ): void {
        $attachment = RegistrationAttachment::with('registration')->find($this->attachmentId);

        if (!$attachment) {
            Log::warning("UploadRegistrationAttachmentToDriveJob: Attachment #{$this->attachmentId} tidak ditemukan.");
            return;
        }

        // Idempotency check: if already synced with verified remote drive_file_id, skip re-upload
        if ($attachment->isSyncedToDrive()) {
            return;
        }

        $hasLeaseColumns = Schema::hasColumn('registration_attachments', 'drive_locked_by');

        // Finding F: Fail-closed if database schema lacks durable leasing columns
        if (!$hasLeaseColumns) {
            Log::error("UploadRegistrationAttachmentToDriveJob: Database schema lacks drive_locked_by durable leasing column. Fail-closed.");
            $attachment->update([
                'drive_sync_status'   => RegistrationAttachment::STATUS_FAILED,
                'drive_error_message' => 'Schema database belum mendukung durable leasing contract (drive_locked_by missing).',
            ]);
            return;
        }

        $now = now();
        $leaseDuration = 120; // 2 minutes lease duration
        $lockExpiresAt = $now->copy()->addSeconds($leaseDuration);

        // Finding A: Strict Atomic Lease Acquisition
        // - PENDING: eligible if no active lease (drive_locked_by is null or lease expired)
        // - RETRYING: ONLY eligible if drive_next_retry_at IS NOT NULL and <= now() and no active lease
        // - FAILED: terminal; cannot be claimed automatically without explicit manual retry/requeue
        // - PROCESSING: ONLY recoverable with valid expired lease (drive_lock_expires_at IS NOT NULL and < now())
        // - SYNCED: excluded
        $claimed = RegistrationAttachment::where('id', $this->attachmentId)
            ->where('drive_sync_status', '!=', RegistrationAttachment::STATUS_SYNCED)
            ->where(function ($q) use ($now) {
                // A. PENDING
                $q->where(function ($pending) use ($now) {
                    $pending->where('drive_sync_status', RegistrationAttachment::STATUS_PENDING)
                        ->where(function ($lease) use ($now) {
                            $lease->whereNull('drive_locked_by')
                                  ->orWhere('drive_lock_expires_at', '<', $now);
                        });
                })
                // B. RETRYING
                ->orWhere(function ($retrying) use ($now) {
                    $retrying->where('drive_sync_status', RegistrationAttachment::STATUS_RETRYING)
                        ->whereNotNull('drive_next_retry_at')
                        ->where('drive_next_retry_at', '<=', $now)
                        ->where(function ($lease) use ($now) {
                            $lease->whereNull('drive_locked_by')
                                  ->orWhere('drive_lock_expires_at', '<', $now);
                        });
                })
                // D. PROCESSING
                ->orWhere(function ($processing) use ($now) {
                    $processing->where('drive_sync_status', RegistrationAttachment::STATUS_PROCESSING)
                        ->whereNotNull('drive_lock_expires_at')
                        ->where('drive_lock_expires_at', '<', $now);
                });
            })
            ->update([
                'drive_sync_status'     => RegistrationAttachment::STATUS_PROCESSING,
                'drive_locked_by'       => $this->fenceToken,
                'drive_lock_expires_at' => $lockExpiresAt,
                'drive_attempt_count'   => DB::raw('COALESCE(drive_attempt_count, 0) + 1'),
            ]);

        if ($claimed === 0) {
            Log::info("UploadRegistrationAttachmentToDriveJob: Attachment #{$this->attachmentId} lease claim rejected (active lease held, not due, terminal failed, or already synced).");
            return;
        }

        $attachment->refresh();

        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$connection || !$connection->is_enabled) {
            $this->releaseLeaseAndFail(
                $attachment,
                'Koneksi pendaftaran dinonaktifkan atau belum dikonfigurasi.',
                isRetryable: false
            );
            return;
        }

        $filePath = $attachment->file_path;

        // Path traversal, absolute path, and storage root security validation (D04)
        if (empty($filePath) || str_contains($filePath, '..') || str_starts_with($filePath, '/') || str_starts_with($filePath, '\\') || preg_match('/^[a-zA-Z]:/', $filePath)) {
            $this->releaseLeaseAndFail(
                $attachment,
                'Jalur berkas lampiran tidak valid atau terdeteksi path traversal.',
                isRetryable: false
            );
            return;
        }

        // Resolusi berkas lampiran dari disk 'local' (privat), fallback ke 'public' (legacy)
        $localPath = null;
        try {
            if (Storage::disk('local')->exists($filePath)) {
                $localPath = Storage::disk('local')->path($filePath);
            } elseif (Storage::disk('public')->exists($filePath)) {
                $localPath = Storage::disk('public')->path($filePath);
            } elseif (array_key_exists('private', config('filesystems.disks', [])) && Storage::disk('private')->exists($filePath)) {
                $localPath = Storage::disk('private')->path($filePath);
            }
        } catch (Throwable $fsEx) {
            $this->releaseLeaseAndFail(
                $attachment,
                'Kesalahan akses media penyimpanan: ' . $fsEx->getMessage(),
                isRetryable: true
            );
            return;
        }

        if (!$localPath || !file_exists($localPath)) {
            $this->releaseLeaseAndFail(
                $attachment,
                'Berkas lampiran fisik tidak ditemukan di storage server.',
                isRetryable: false
            );
            return;
        }

        $fileSize = filesize($localPath);
        if ($fileSize <= 0) {
            $this->releaseLeaseAndFail(
                $attachment,
                'Berkas lampiran kosong (0 bytes).',
                isRetryable: false
            );
            return;
        }

        // Maximum 10 MB overall size limit (D05)
        if ($fileSize > 10 * 1024 * 1024) {
            $this->releaseLeaseAndFail(
                $attachment,
                'Ukuran berkas melebihi batas maksimum 10 MB.',
                isRetryable: false
            );
            return;
        }

        $fileChecksum = hash_file('sha256', $localPath);

        $cloudSyncManager = app(CloudSyncManager::class);
        $hasDirectDriveDriver = $cloudSyncManager->getDriver()->isConfigured()
            && $cloudSyncManager->getDriver()->getDriverName() !== 'local_fallback';

        $driveFileId = null;
        $driveViewLink = null;
        $response = null;

        try {
            if ($hasDirectDriveDriver || $transport instanceof GoogleAppsScriptTransport) {
                $uploadResult = $cloudSyncManager->uploadRegistrationAttachment($attachment);
                $driveFileId = $uploadResult['file_id'] ?? null;
                $driveViewLink = $uploadResult['web_view_link'] ?? null;
            } else {
                // Test transport / Mock transport handling
                $response = $transport->sendRowUpsert($connection, 'Drive', 'ATT-' . $attachment->id, [
                    'file_name' => $attachment->original_name,
                ]);

                if (!$response->success) {
                    $this->releaseLeaseAndFail(
                        $attachment,
                        $response->errorMessage,
                        isRetryable: $response->isRetryable
                    );
                    return;
                }

                $driveFileId = $response->rawResponse['file_id'] ?? null;
                $driveViewLink = $response->rawResponse['web_view_link'] ?? null;
            }

            // Finding C: Strict result validation: reject empty, local simulation, mock, or fallback placeholder IDs
            if (empty($driveFileId)
                || $driveFileId === ('DRIVE-FILE-' . $attachment->id)
                || str_starts_with($driveFileId, 'local_sim_')
                || str_starts_with($driveFileId, 'DRIVE-MOCK-')
                || !empty($response?->rawResponse['dry_run'])
            ) {
                $this->releaseLeaseAndFail(
                    $attachment,
                    'Respons remote memuat identifier simulasi atau tidak memuat file_id Google Drive yang valid.',
                    isRetryable: false
                );
                return;
            }

            if (empty($driveViewLink)) {
                $driveViewLink = "https://drive.google.com/file/d/{$driveFileId}/view";
            }

            // Finding E: Atomic Finalize: Only finalize if this worker still holds the lock!
            $finalized = $this->finalizeSuccess(
                $attachment,
                $driveFileId,
                $driveViewLink,
                $fileChecksum
            );

            if (!$finalized) {
                Log::warning("UploadRegistrationAttachmentToDriveJob: Stale worker callback for attachment #{$attachment->id} blocked from finalizing.");
                return;
            }

            // Simpan 1 tautan folder penyimpanan terpadu pendaftar jika dikembalikan oleh Apps Script Gateway
            $folderId = $response?->rawResponse['folder_id'] ?? $response?->rawResponse['data']['folder_id'] ?? null;
            $folderUrl = $response?->rawResponse['folder_url'] ?? $response?->rawResponse['data']['folder_url'] ?? null;
            if (!app()->runningUnitTests() && !empty($folderId) && !empty($folderUrl) && $attachment->internship_registration_id) {
                RegistrationAttachment::updateOrCreate(
                    [
                        'internship_registration_id' => $attachment->internship_registration_id,
                        'attachment_type'            => RegistrationAttachment::TYPE_LEGACY_BUNDLE,
                    ],
                    [
                        'category'            => RegistrationAttachment::TYPE_LEGACY_BUNDLE,
                        'file_path'           => dirname((string) $attachment->file_path),
                        'original_name'       => 'Folder_Berkas_REG_' . $attachment->internship_registration_id,
                        'file_size'           => 0,
                        'mime_type'           => 'application/vnd.google-apps.folder',
                        'drive_file_id'       => $folderId,
                        'drive_web_view_link' => $folderUrl,
                        'drive_sync_status'   => RegistrationAttachment::STATUS_SYNCED,
                        'drive_synced_at'     => now(),
                    ]
                );
            }

            // Trigger row sync re-evaluation so spreadsheet row is refreshed with attachment URL (D22, D23)
            // A failure in spreadsheet re-queue MUST NEVER cause Drive re-upload or revert Drive status to failed.
            if ($attachment->registration) {
                try {
                    $syncEngine->queueSyncForRegistration($attachment->registration);
                } catch (Throwable $syncEx) {
                    Log::error("UploadRegistrationAttachmentToDriveJob: Gagal memicu sinkronisasi Spreadsheet untuk pendaftaran #{$attachment->internship_registration_id}: " . $syncEx->getMessage());
                }
            }

        } catch (Throwable $e) {
            $this->releaseLeaseAndFail(
                $attachment,
                'Terjadi kesalahan sistem saat unggah ke Drive: ' . $e->getMessage(),
                isRetryable: true
            );
            throw $e;
        }
    }

    /**
     * Finalisasi sukses secara atomik menggunakan conditional update berdasarkan fence token.
     */
    protected function finalizeSuccess(
        RegistrationAttachment $attachment,
        string $driveFileId,
        string $driveViewLink,
        string $fileChecksum
    ): bool {
        $hasLeaseColumns = Schema::hasColumn('registration_attachments', 'drive_locked_by');

        if ($hasLeaseColumns) {
            $updated = RegistrationAttachment::where('id', $attachment->id)
                ->where('drive_locked_by', $this->fenceToken)
                ->update([
                    'drive_file_id'         => $driveFileId,
                    'drive_web_view_link'   => $driveViewLink,
                    'drive_sync_status'     => RegistrationAttachment::STATUS_SYNCED,
                    'drive_synced_at'       => now(),
                    'drive_error_message'   => null,
                    'drive_file_checksum'   => $fileChecksum,
                    'drive_locked_by'       => null,
                    'drive_lock_expires_at' => null,
                    'drive_next_retry_at'   => null,
                ]);

            return $updated > 0;
        }

        return false;
    }

    /**
     * Lepas lease dan catat kegagalan (retryable vs terminal).
     */
    protected function releaseLeaseAndFail(
        RegistrationAttachment $attachment,
        string $rawErrorMessage,
        bool $isRetryable
    ): void {
        $sanitizedError = $this->sanitizeErrorMessage($rawErrorMessage);
        $hasLeaseColumns = Schema::hasColumn('registration_attachments', 'drive_locked_by');
        $attemptCount = $attachment->drive_attempt_count ?? 1;

        if ($isRetryable && $attemptCount < $this->tries) {
            $backoffSeconds = 30 * pow(2, $attemptCount - 1);
            $nextRetry = now()->addSeconds($backoffSeconds);

            if ($hasLeaseColumns) {
                RegistrationAttachment::where('id', $attachment->id)
                    ->where('drive_locked_by', $this->fenceToken)
                    ->update([
                        'drive_sync_status'     => RegistrationAttachment::STATUS_RETRYING,
                        'drive_error_message'   => $sanitizedError,
                        'drive_locked_by'       => null,
                        'drive_lock_expires_at' => null,
                        'drive_next_retry_at'   => $nextRetry,
                    ]);
            }

            $this->release($backoffSeconds);
            return;
        }

        // Terminal failure or max attempts exhausted
        if ($hasLeaseColumns) {
            RegistrationAttachment::where('id', $attachment->id)
                ->where('drive_locked_by', $this->fenceToken)
                ->update([
                    'drive_sync_status'     => RegistrationAttachment::STATUS_FAILED,
                    'drive_error_message'   => $sanitizedError,
                    'drive_locked_by'       => null,
                    'drive_lock_expires_at' => null,
                    'drive_next_retry_at'   => null,
                ]);
        } else {
            $attachment->update([
                'drive_sync_status'   => RegistrationAttachment::STATUS_FAILED,
                'drive_error_message' => $sanitizedError,
            ]);
        }
    }

    /**
     * Callback failed ketika job dihentikan secara fatal oleh worker.
     */
    public function failed(?Throwable $e = null): void
    {
        $sanitizedError = $e ? $this->sanitizeErrorMessage($e->getMessage()) : 'Job antrean dihentikan secara fatal.';
        $hasLeaseColumns = Schema::hasColumn('registration_attachments', 'drive_locked_by');

        if ($hasLeaseColumns) {
            RegistrationAttachment::where('id', $this->attachmentId)
                ->where('drive_locked_by', $this->fenceToken)
                ->update([
                    'drive_sync_status'     => RegistrationAttachment::STATUS_FAILED,
                    'drive_error_message'   => 'Kegagalan worker antrean: ' . $sanitizedError,
                    'drive_locked_by'       => null,
                    'drive_lock_expires_at' => null,
                    'drive_next_retry_at'   => null,
                ]);
        }
    }

    /**
     * Sanitasi pesan kesalahan untuk mencegah kebocoran token atau kredensial.
     */
    protected function sanitizeErrorMessage(string $message): string
    {
        $sanitized = preg_replace('/(access_token|refresh_token|client_secret|bearer|authorization)=[^&\s]+/i', '$1=***REDACTED***', $message);
        $sanitized = preg_replace('/Bearer\s+[a-zA-Z0-9_\-\.]+/i', 'Bearer ***REDACTED***', $sanitized);
        $sanitized = preg_replace('/"(access_token|client_secret|refresh_token)":\s*"[^"]+"/i', '"$1":"***REDACTED***"', $sanitized);
        return Str::limit($sanitized, 500);
    }
}
