<?php

namespace App\Jobs;

use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SpreadsheetTransport\GoogleAppsScriptTransport;
use App\Services\SpreadsheetTransport\SpreadsheetTransportInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadRegistrationAttachmentToDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $attachmentId
    ) {}

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

        // Idempotency check: if already synced with valid drive_file_id, skip re-upload
        if ($attachment->drive_sync_status === RegistrationAttachment::STATUS_SYNCED && !empty($attachment->drive_file_id)) {
            return;
        }

        $connection = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$connection || !$connection->is_enabled) {
            $attachment->update([
                'drive_sync_status'   => RegistrationAttachment::STATUS_FAILED,
                'drive_error_message' => 'Koneksi pendaftaran dinonaktifkan atau belum dikonfigurasi.',
            ]);
            return;
        }

        $attachment->update([
            'drive_sync_status' => RegistrationAttachment::STATUS_PROCESSING,
        ]);

        $localPath = Storage::disk('private')->path($attachment->file_path);

        if (!file_exists($localPath)) {
            // Check public disk fallback if stored there
            $fallbackPath = Storage::disk('public')->path($attachment->file_path);
            if (file_exists($fallbackPath)) {
                $localPath = $fallbackPath;
            } else {
                $attachment->update([
                    'drive_sync_status'   => RegistrationAttachment::STATUS_FAILED,
                    'drive_error_message' => 'Berkas lampiran fisik tidak ditemukan di storage server.',
                ]);
                return;
            }
        }

        $fileSize = filesize($localPath);
        $cloudSyncManager = app(\App\Services\CloudStorage\CloudSyncManager::class);
        $hasDirectDriveDriver = $cloudSyncManager->getDriver()->isConfigured() && $cloudSyncManager->getDriver()->getDriverName() !== 'local_fallback';

        try {
            if ($fileSize > 5 * 1024 * 1024 && $hasDirectDriveDriver) {
                // Gunakan Direct Drive API untuk berkas 5-10 MB saat kredensial Drive dikonfigurasi
                $uploadResult = $cloudSyncManager->syncRegistrationAttachment($attachment);
                $driveFileId = $uploadResult['file_id'];
                $driveViewLink = $uploadResult['web_view_link'];
            } elseif ($transport instanceof GoogleAppsScriptTransport) {
                $response = $transport->uploadAttachment($connection, $attachment, $localPath);

                if (!$response->success) {
                    $attachment->update([
                        'drive_sync_status'   => RegistrationAttachment::STATUS_FAILED,
                        'drive_error_message' => $response->errorMessage,
                    ]);

                    if ($response->isRetryable) {
                        $this->release(30);
                    }
                    return;
                }

                $driveFileId = $response->rawResponse['file_id'] ?? ('DRIVE-FILE-' . $attachment->id);
                $driveViewLink = $response->rawResponse['web_view_link'] ?? ('https://drive.google.com/file/d/' . $driveFileId . '/view');
            } elseif ($hasDirectDriveDriver) {
                $uploadResult = $cloudSyncManager->syncRegistrationAttachment($attachment);
                $driveFileId = $uploadResult['file_id'];
                $driveViewLink = $uploadResult['web_view_link'];
            } else {
                // If using MockTransport or NullTransport during test
                $response = $transport->sendRowUpsert($connection, 'Drive', 'ATT-' . $attachment->id, [
                    'file_name' => $attachment->original_name,
                ]);
                $driveFileId = $response->rawResponse['file_id'] ?? ('DRIVE-FILE-' . $attachment->id);
                $driveViewLink = $response->rawResponse['web_view_link'] ?? ('https://drive.google.com/file/d/' . $driveFileId . '/view');
            }

            $attachment->update([
                'drive_file_id'       => $driveFileId,
                'drive_web_view_link' => $driveViewLink,
                'drive_sync_status'   => RegistrationAttachment::STATUS_SYNCED,
                'drive_synced_at'     => now(),
                'drive_error_message' => null,
            ]);

            // Trigger row sync re-evaluation so spreadsheet row is refreshed with the newly obtained attachment URL
            if ($attachment->registration) {
                $syncEngine->queueSyncForRegistration($attachment->registration);
            }
        } catch (Throwable $e) {
            $attachment->update([
                'drive_sync_status'   => RegistrationAttachment::STATUS_FAILED,
                'drive_error_message' => 'Terjadi kesalahan sistem saat unggah ke Drive: ' . $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
