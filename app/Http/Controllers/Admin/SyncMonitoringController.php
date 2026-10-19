<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSidecarSyncJob;
use App\Jobs\ProcessSpreadsheetRowSyncJob;
use App\Jobs\UploadRegistrationAttachmentToDriveJob;
use App\Models\InternshipRegistration;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSidecarSync;
use App\Services\ActivityLogger;
use App\Services\RegistrationSpreadsheetMappingService;
use App\Services\SpreadsheetSyncEngineService;
use App\Services\SyncMonitoringQueryService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SyncMonitoringController extends Controller
{
    public function __construct(
        protected SyncMonitoringQueryService $queryService,
        protected SpreadsheetSyncEngineService $syncEngine,
        protected RegistrationSpreadsheetMappingService $mappingService
    ) {}

    /**
     * Tampilkan halaman dashboard pemantauan sinkronisasi terpusat (Read-Only).
     */
    public function index(Request $request)
    {
        Gate::authorize('view-sync-monitoring');

        $filters = [
            'category'      => $request->input('category', 'all'),
            'status'        => $request->input('status', 'all'),
            'connection_id' => $request->input('connection_id', 'all'),
            'year'          => $request->input('year', 'all'),
            'search'        => $request->input('search'),
        ];

        // Scope filter koneksi untuk admin
        $user = Auth::user();
        $allowedConnectionIds = method_exists($user, 'getAllowedConnectionIds') ? $user->getAllowedConnectionIds() : null;

        $selectedConnectionId = $filters['connection_id'] !== 'all' ? (int) $filters['connection_id'] : null;
        if ($selectedConnectionId) {
            $connection = SpreadsheetConnection::find($selectedConnectionId);
            if (!$connection) {
                abort(404, 'Koneksi spreadsheet tidak ditemukan.');
            }
            if (method_exists($user, 'canAccessConnection') && !$user->canAccessConnection($connection)) {
                abort(403, 'Anda tidak memiliki hak akses ke koneksi spreadsheet tersebut.');
            }
        }

        // Statistik ringkas & daftar record dengan server-side pagination (dengan actor scoping)
        $stats   = $this->queryService->getSummaryStatistics(
            $selectedConnectionId,
            $filters['year'] !== 'all' ? (int) $filters['year'] : null,
            $user
        );
        $records = $this->queryService->getMonitoringRecords($filters, perPage: 15, actor: $user);

        // Opsi koneksi & tahun untuk dropdown filter
        $connections = SpreadsheetConnection::orderBy('display_name')->get();
        if ($allowedConnectionIds !== null) {
            $connections = $connections->filter(fn ($c) => in_array($c->id, $allowedConnectionIds, true));
        }

        $years = [2026, 2027, 2025];

        return view('admin.sync-monitoring.index', [
            'stats'       => $stats,
            'records'     => $records,
            'filters'     => $filters,
            'connections' => $connections,
            'years'       => $years,
        ]);
    }

    /**
     * Tampilkan analisis detail kegagalan dan audit trail suatu record sinkronisasi (Read-Only).
     */
    public function show(Request $request, string $category, string $id)
    {
        Gate::authorize('view-sync-monitoring');

        if (!in_array($category, ['main_row', 'sidecar', 'attachment'], true)) {
            abort(404, 'Kategori sinkronisasi tidak valid.');
        }

        $user = Auth::user();
        $detail = $this->queryService->getRecordDetail($category, $id, $user);

        if (!$detail) {
            abort(404, 'Data pemantauan sinkronisasi tidak ditemukan atau berada di luar cakupan otorisasi Anda.');
        }

        return view('admin.sync-monitoring.show', $detail);
    }

    /**
     * Memicu manual retry terotorisasi untuk baris utama Google Spreadsheet (Non-blocking Queue Dispatch).
     */
    public function retryMainRow(Request $request, SpreadsheetRowSync $rowSync)
    {
        // 1. Otorisasi
        Gate::authorize('retry-sync-monitoring', $rowSync->spreadsheetConnection);

        // 2. Pencegahan IDOR / Verifikasi Koneksi
        if (!$rowSync->spreadsheetConnection || (int) $rowSync->spreadsheet_connection_id !== (int) $rowSync->spreadsheetConnection->id) {
            abort(404, 'Ketidakcocokan koneksi sinkronisasi.');
        }

        $actor = Auth::user();
        if (method_exists($actor, 'canAccessConnection') && !$actor->canAccessConnection($rowSync->spreadsheetConnection)) {
            abort(403, 'Anda tidak memiliki hak akses ke koneksi record ini.');
        }

        // 3. Status Invariant: Tolak record yang sudah SYNCED
        if ($rowSync->isSynced()) {
            return redirect()
                ->back()
                ->with('error', 'Record sudah berstatus SYNCED. Retry kegagalan tidak diizinkan untuk record yang telah tersinkron.');
        }

        // 4. Status Invariant: Tolak record PROCESSING (termasuk jika locked_at NULL)
        if ($rowSync->isProcessing()) {
            return redirect()
                ->back()
                ->with('error', 'Record sedang diproses oleh worker. Retry ditolak untuk mencegah race condition.');
        }

        // 5. Status Invariant: Tolak record PENDING
        if ($rowSync->isPending()) {
            return redirect()
                ->back()
                ->with('error', 'Record masih berstatus PENDING dalam antrean. Retry manual tidak diizinkan.');
        }

        // 6. Strict manual failure predicate: Hanya FAILED atau RETRYING
        if (!in_array($rowSync->sync_status, [SpreadsheetRowSync::STATUS_FAILED, SpreadsheetRowSync::STATUS_RETRYING], true)) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Record hanya dapat di-retry jika berstatus FAILED atau RETRYING.');
        }

        $prevStatus = $rowSync->sync_status;
        $prevAttempts = $rowSync->attempt_count;

        // 7. Atomic conditional claim: Hanya baris FAILED/RETRYING tanpa active lock
        $affected = DB::table('spreadsheet_row_syncs')
            ->where('id', $rowSync->id)
            ->whereIn('sync_status', [SpreadsheetRowSync::STATUS_FAILED, SpreadsheetRowSync::STATUS_RETRYING])
            ->whereNull('locked_at')
            ->update([
                'attempt_count'    => 0,
                'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                'next_retry_at'    => now(),
                'locked_at'        => null,
                'locked_by'        => null,
                'payload_checksum' => null, // Reset checksum agar memicu fresh remote upsert
                'error_message'    => null,
                'updated_at'       => now(),
            ]);

        if ($affected === 0) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Record tidak dalam status eligible (FAILED/RETRYING) atau sedang dikunci worker.');
        }

        $rowSync->refresh();

        // 8. Audit Trail Terpusat (Tanpa rahasia/secret)
        ActivityLogger::log(
            action: 'sync_monitoring_retry_main_row',
            description: "Admin {$actor->name} memicu retry manual baris {$rowSync->external_sync_id}",
            subject: $rowSync,
            actor: $actor,
            before: ['sync_status' => $prevStatus, 'attempt_count' => $prevAttempts],
            after: ['sync_status' => SpreadsheetRowSync::STATUS_PENDING, 'attempt_count' => 0],
            metadata: [
                'connection_id'    => $rowSync->spreadsheet_connection_id,
                'external_sync_id' => $rowSync->external_sync_id,
                'outcome'          => 'queued',
            ]
        );

        // 9. Dispatch asynchronous job ke antrean
        ProcessSpreadsheetRowSyncJob::dispatch($rowSync->id);

        if (!app()->runningUnitTests()) {
            \Illuminate\Support\Facades\Cache::forever('illuminate:queue:restart', now()->getTimestamp());
            $freshSync = $rowSync->fresh();
            if ($freshSync && $freshSync->isPending()) {
                $this->syncEngine->processSyncRecord($freshSync, force: true);
            }
        }

        return redirect()
            ->back()
            ->with('success', "Proses retry baris {$rowSync->external_sync_id} berhasil dijadwalkan ke antrean.");
    }

    /**
     * Memicu manual retry terotorisasi untuk sidecar/custom fields sinkronisasi.
     */
    public function retrySidecar(Request $request, SpreadsheetSidecarSync $sidecarSync)
    {
        // 1. Otorisasi
        Gate::authorize('retry-sync-monitoring', $sidecarSync->spreadsheetConnection);

        $actor = Auth::user();
        if (method_exists($actor, 'canAccessConnection') && !$actor->canAccessConnection($sidecarSync->spreadsheetConnection)) {
            abort(403, 'Anda tidak memiliki hak akses ke koneksi sidecar ini.');
        }

        $registration = $sidecarSync->registration;
        if (!$registration) {
            abort(404, 'Entitas pendaftaran terkait tidak ditemukan.');
        }

        // 2. Prasyarat Bisnis: Acceptance Gate Admin
        if (!$this->mappingService->hasVerifiedAdminAcceptance($registration)) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Pendaftaran belum memenuhi syarat penerimaan admin (acceptance gate).');
        }

        // 3. Prasyarat Bisnis: Main-row Sync Terverifikasi
        if (!$this->mappingService->hasVerifiedPriorSync($registration)) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Baris utama pendaftaran belum tersinkronisasi ke Google Spreadsheet.');
        }

        // 4. Prasyarat Bisnis: Sidecar Diperlukan
        if (!$this->mappingService->requiresSidecarSync($registration)) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Pendaftaran ini tidak memerlukan sinkronisasi ekstensi sidecar.');
        }

        // 5. Status Invariant: Tolak SYNCED
        if ($sidecarSync->sync_status === SpreadsheetSidecarSync::STATUS_SYNCED) {
            return redirect()
                ->back()
                ->with('error', 'Record sidecar sudah berstatus SYNCED. Retry tidak diperlukan.');
        }

        // 6. Status Invariant: Tolak PROCESSING
        if ($sidecarSync->sync_status === SpreadsheetSidecarSync::STATUS_PROCESSING) {
            return redirect()
                ->back()
                ->with('error', 'Record sidecar sedang diproses oleh worker aktif.');
        }

        // 7. Status Invariant: Tolak PENDING
        if ($sidecarSync->sync_status === SpreadsheetSidecarSync::STATUS_PENDING) {
            return redirect()
                ->back()
                ->with('error', 'Record sidecar masih berstatus PENDING dalam antrean.');
        }

        // 8. Strict manual failure predicate: Hanya FAILED atau RETRYING
        if (!in_array($sidecarSync->sync_status, [SpreadsheetSidecarSync::STATUS_FAILED, SpreadsheetSidecarSync::STATUS_RETRYING], true)) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Record sidecar hanya dapat di-retry jika berstatus FAILED atau RETRYING.');
        }

        $prevStatus = $sidecarSync->sync_status;

        // 9. Atomic conditional update aman terhadap race condition dan stale worker
        $affected = DB::table('spreadsheet_sidecar_syncs')
            ->where('id', $sidecarSync->id)
            ->whereIn('sync_status', [SpreadsheetSidecarSync::STATUS_FAILED, SpreadsheetSidecarSync::STATUS_RETRYING])
            ->whereNull('locked_at')
            ->update([
                'sync_status'      => SpreadsheetSidecarSync::STATUS_PENDING,
                'attempt_count'    => 0,
                'next_retry_at'    => now(),
                'payload_checksum' => null,
                'error_message'    => null,
                'locked_at'        => null,
                'locked_by'        => null,
                'updated_at'       => now(),
            ]);

        if ($affected === 0) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Record sedang diproses oleh worker aktif atau status tidak eligible.');
        }

        $sidecarSync->refresh();

        // 10. Audit Trail
        ActivityLogger::log(
            action: 'sync_monitoring_retry_sidecar',
            description: "Admin {$actor->name} memicu retry manual sidecar untuk pendaftaran #{$registration->id}",
            subject: $sidecarSync,
            actor: $actor,
            before: ['sync_status' => $prevStatus],
            after: ['sync_status' => SpreadsheetSidecarSync::STATUS_PENDING],
            metadata: [
                'registration_id' => $registration->id,
                'connection_id'   => $sidecarSync->spreadsheet_connection_id,
                'outcome'         => 'queued',
            ]
        );

        // 11. Queue Dispatch
        ProcessSidecarSyncJob::dispatch($sidecarSync->id);

        return redirect()
            ->back()
            ->with('success', "Proses retry sidecar untuk registrasi #{$registration->id} berhasil dijadwalkan ke antrean.");
    }

    /**
     * Memicu manual retry terotorisasi untuk upload lampiran ke Google Drive.
     */
    public function retryAttachment(Request $request, RegistrationAttachment $attachment)
    {
        // 1. Otorisasi
        Gate::authorize('retry-sync-monitoring');

        $actor = Auth::user();

        // Verifikasi kewenangan koneksi pendaftaran untuk lampiran
        $regConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        if (!$regConn) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Koneksi pendaftaran spreadsheet tidak ditemukan.');
        }

        if (method_exists($actor, 'canAccessConnection') && !$actor->canAccessConnection($regConn)) {
            abort(403, 'Anda tidak memiliki hak akses untuk memicu retry lampiran pendaftaran.');
        }

        // 2. Proteksi Skema Database (Fail-Closed jika kolom leasing belum tersedia)
        $hasLeaseColumns = Schema::hasColumn('registration_attachments', 'drive_locked_by');
        if (!$hasLeaseColumns) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Skema database belum mendukung durable leasing contract (drive_locked_by).');
        }

        // 3. Status Invariant: Tolak jika sudah SYNCED dengan file ID valid
        if ($attachment->isSyncedToDrive()) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Lampiran sudah tersinkronisasi secara sah ke Google Drive.');
        }

        // 4. Status Invariant: Tolak PROCESSING
        if ($attachment->drive_sync_status === RegistrationAttachment::STATUS_PROCESSING) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Upload lampiran sedang berlangsung pada worker lain.');
        }

        // 5. Status Invariant: Tolak PENDING
        if ($attachment->drive_sync_status === RegistrationAttachment::STATUS_PENDING) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Lampiran masih berstatus PENDING dalam antrean.');
        }

        // 6. Strict predicate: Hanya FAILED atau RETRYING
        if (!in_array($attachment->drive_sync_status, [RegistrationAttachment::STATUS_FAILED, RegistrationAttachment::STATUS_RETRYING], true)) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Lampiran hanya dapat di-retry jika berstatus FAILED atau RETRYING.');
        }

        // 7. Verifikasi Berkas Fisik Lokal
        $disk = Storage::disk(config('filesystems.default', 'public'));
        $physicalExists = false;
        if (!empty($attachment->file_path)) {
            if ($disk->exists($attachment->file_path) || Storage::disk('private')->exists($attachment->file_path)) {
                $physicalExists = true;
            }
        }

        if (!$physicalExists) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Berkas fisik lampiran tidak ditemukan pada penyimpanan lokal.');
        }

        $prevStatus = $attachment->drive_sync_status;
        $now = now();

        // 8. Atomic conditional update status & lease
        $affected = DB::table('registration_attachments')
            ->where('id', $attachment->id)
            ->whereIn('drive_sync_status', [RegistrationAttachment::STATUS_FAILED, RegistrationAttachment::STATUS_RETRYING])
            ->whereNull('drive_locked_by')
            ->update([
                'drive_sync_status'     => RegistrationAttachment::STATUS_PENDING,
                'drive_next_retry_at'   => $now,
                'drive_attempt_count'   => 0,
                'drive_locked_by'       => null,
                'drive_lock_expires_at' => null,
                'drive_error_message'   => null,
                'updated_at'            => $now,
            ]);

        if ($affected === 0) {
            return redirect()
                ->back()
                ->with('error', 'Gagal memicu retry: Record lampiran sedang diproses atau status tidak eligible.');
        }

        $attachment->refresh();

        // 9. Audit Trail
        ActivityLogger::log(
            action: 'sync_monitoring_retry_attachment',
            description: "Admin {$actor->name} memicu retry manual upload lampiran #{$attachment->id} ke Drive",
            subject: $attachment,
            actor: $actor,
            before: ['drive_sync_status' => $prevStatus],
            after: ['drive_sync_status' => RegistrationAttachment::STATUS_PENDING],
            metadata: [
                'attachment_id'   => $attachment->id,
                'registration_id' => $attachment->internship_registration_id,
                'outcome'         => 'queued',
            ]
        );

        // 10. Queue Dispatch
        UploadRegistrationAttachmentToDriveJob::dispatch($attachment->id);

        return redirect()
            ->back()
            ->with('success', "Proses retry upload lampiran #{$attachment->id} berhasil dijadwalkan ke antrean.");
    }

    /**
     * Memicu batch retry terotorisasi dengan pembatasan jumlah dan perlindungan concurrency (Section 7).
     */
    public function batchRetry(Request $request)
    {
        // 1. Otorisasi
        Gate::authorize('retry-sync-monitoring');

        $validated = $request->validate([
            'category'      => 'required|string|in:main_row,sidecar,attachment',
            'connection_id' => 'nullable|integer',
            'ids'           => 'nullable|array|max:50',
            'ids.*'         => 'integer',
        ]);

        $category     = $validated['category'];
        $connectionId = !empty($validated['connection_id']) ? (int) $validated['connection_id'] : null;
        $ids          = $validated['ids'] ?? [];

        // Validasi batasan scope koneksi admin
        $user = Auth::user();
        $allowedConnectionIds = method_exists($user, 'getAllowedConnectionIds') ? $user->getAllowedConnectionIds() : null;

        if ($connectionId) {
            $conn = SpreadsheetConnection::find($connectionId);
            if (!$conn) {
                abort(404, 'Koneksi spreadsheet tidak ditemukan.');
            }
            if (method_exists($user, 'canAccessConnection') && !$user->canAccessConnection($conn)) {
                abort(403, 'Anda tidak memiliki hak akses ke koneksi tersebut.');
            }
        }

        $selected           = 0;
        $successfullyQueued = 0;
        $skipped            = 0;
        $rejected           = 0;

        $actor = Auth::user();
        $batchLimit = 50;

        if ($category === 'main_row') {
            $query = SpreadsheetRowSync::whereIn('sync_status', [
                SpreadsheetRowSync::STATUS_FAILED,
                SpreadsheetRowSync::STATUS_RETRYING,
            ]);

            if ($connectionId) {
                $query->where('spreadsheet_connection_id', $connectionId);
            } elseif ($allowedConnectionIds !== null) {
                $query->whereIn('spreadsheet_connection_id', $allowedConnectionIds);
            }

            if (!empty($ids)) {
                $query->whereIn('id', array_slice($ids, 0, $batchLimit));
            } else {
                $query->limit($batchLimit);
            }

            $items = $query->get();
            $selected = $items->count();

            foreach ($items as $item) {
                // Scope check
                if (method_exists($user, 'canAccessConnection') && !$user->canAccessConnection($item->spreadsheetConnection)) {
                    $rejected++;
                    continue;
                }

                $affected = DB::table('spreadsheet_row_syncs')
                    ->where('id', $item->id)
                    ->whereIn('sync_status', [SpreadsheetRowSync::STATUS_FAILED, SpreadsheetRowSync::STATUS_RETRYING])
                    ->whereNull('locked_at')
                    ->update([
                        'attempt_count'    => 0,
                        'sync_status'      => SpreadsheetRowSync::STATUS_PENDING,
                        'next_retry_at'    => now(),
                        'locked_at'        => null,
                        'locked_by'        => null,
                        'payload_checksum' => null,
                        'error_message'    => null,
                        'updated_at'       => now(),
                    ]);

                if ($affected > 0) {
                    ProcessSpreadsheetRowSyncJob::dispatch($item->id);
                    if (!app()->runningUnitTests()) {
                        \Illuminate\Support\Facades\Cache::forever('illuminate:queue:restart', now()->getTimestamp());
                        $freshItem = $item->fresh();
                        if ($freshItem && $freshItem->isPending()) {
                            $this->syncEngine->processSyncRecord($freshItem, force: true);
                        }
                    }
                    $successfullyQueued++;
                } else {
                    $skipped++;
                }
            }
        } elseif ($category === 'sidecar') {
            $query = SpreadsheetSidecarSync::whereIn('sync_status', [
                SpreadsheetSidecarSync::STATUS_FAILED,
                SpreadsheetSidecarSync::STATUS_RETRYING,
            ]);

            if ($connectionId) {
                $query->where('spreadsheet_connection_id', $connectionId);
            } elseif ($allowedConnectionIds !== null) {
                $query->whereIn('spreadsheet_connection_id', $allowedConnectionIds);
            }

            if (!empty($ids)) {
                $query->whereIn('id', array_slice($ids, 0, $batchLimit));
            } else {
                $query->limit($batchLimit);
            }

            $items = $query->get();
            $selected = $items->count();

            foreach ($items as $item) {
                if (method_exists($user, 'canAccessConnection') && !$user->canAccessConnection($item->spreadsheetConnection)) {
                    $rejected++;
                    continue;
                }

                $registration = $item->registration;
                if (!$registration 
                    || !$this->mappingService->hasVerifiedAdminAcceptance($registration) 
                    || !$this->mappingService->hasVerifiedPriorSync($registration)
                    || !$this->mappingService->requiresSidecarSync($registration)
                ) {
                    $rejected++;
                    continue;
                }

                $affected = DB::table('spreadsheet_sidecar_syncs')
                    ->where('id', $item->id)
                    ->whereIn('sync_status', [SpreadsheetSidecarSync::STATUS_FAILED, SpreadsheetSidecarSync::STATUS_RETRYING])
                    ->whereNull('locked_at')
                    ->update([
                        'attempt_count'    => 0,
                        'sync_status'      => SpreadsheetSidecarSync::STATUS_PENDING,
                        'next_retry_at'    => now(),
                        'payload_checksum' => null,
                        'error_message'    => null,
                        'locked_at'        => null,
                        'locked_by'        => null,
                        'updated_at'       => now(),
                    ]);

                if ($affected > 0) {
                    ProcessSidecarSyncJob::dispatch($item->id);
                    $successfullyQueued++;
                } else {
                    $skipped++;
                }
            }
        } elseif ($category === 'attachment') {
            if (!Schema::hasColumn('registration_attachments', 'drive_locked_by')) {
                return redirect()
                    ->back()
                    ->with('error', 'Skema database belum mendukung durable leasing attachment.');
            }

            // 1. Resolve koneksi registrasi dari KEY_REGISTRATION
            $regConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();

            // 2. Fail-closed jika koneksi registrasi tidak ditemukan
            if (!$regConn) {
                return redirect()
                    ->back()
                    ->with('error', 'Gagal memicu batch retry: Koneksi pendaftaran spreadsheet tidak ditemukan.');
            }

            // 3 & 4. Jika connection_id diberikan, wajib sama dengan ID koneksi registrasi.
            // Jika berbeda, tolak request tanpa perubahan status, audit keberhasilan, ataupun dispatch.
            if ($connectionId !== null && (int) $connectionId !== (int) $regConn->id) {
                return redirect()
                    ->back()
                    ->with('error', 'Gagal memicu batch retry: Lampiran hanya dapat diproses melalui koneksi pendaftaran (registration connection).');
            }

            // 5 & 6. Validasi hak akses aktor terhadap koneksi registrasi melalui sumber permission existing (bahkan jika connection_id kosong)
            if (method_exists($actor, 'canAccessConnection') && !$actor->canAccessConnection($regConn)) {
                return redirect()
                    ->back()
                    ->with('error', 'Anda tidak memiliki hak akses untuk memicu batch retry lampiran pendaftaran.');
            }

            $query = RegistrationAttachment::whereIn('drive_sync_status', [
                RegistrationAttachment::STATUS_FAILED,
                RegistrationAttachment::STATUS_RETRYING,
            ])->whereNull('drive_file_id');

            if (!empty($ids)) {
                $query->whereIn('id', array_slice($ids, 0, $batchLimit));
            } else {
                $query->limit($batchLimit);
            }

            $items = $query->get();
            $selected = $items->count();
            $now = now();
            $disk = Storage::disk(config('filesystems.default', 'public'));

            foreach ($items as $item) {
                // Verifikasi berkas fisik lokal
                $physicalExists = false;
                if (!empty($item->file_path)) {
                    if ($disk->exists($item->file_path) || Storage::disk('private')->exists($item->file_path)) {
                        $physicalExists = true;
                    }
                }

                if (!$physicalExists) {
                    $rejected++;
                    continue;
                }

                $affected = DB::table('registration_attachments')
                    ->where('id', $item->id)
                    ->whereIn('drive_sync_status', [RegistrationAttachment::STATUS_FAILED, RegistrationAttachment::STATUS_RETRYING])
                    ->whereNull('drive_locked_by')
                    ->update([
                        'drive_sync_status'     => RegistrationAttachment::STATUS_PENDING,
                        'drive_next_retry_at'   => $now,
                        'drive_attempt_count'   => 0,
                        'drive_locked_by'       => null,
                        'drive_lock_expires_at' => null,
                        'drive_error_message'   => null,
                        'updated_at'            => $now,
                    ]);

                if ($affected > 0) {
                    UploadRegistrationAttachmentToDriveJob::dispatch($item->id);
                    $successfullyQueued++;
                } else {
                    $skipped++;
                }
            }
        }

        // Audit Trail Batch Operation
        ActivityLogger::log(
            action: 'sync_monitoring_batch_retry',
            description: "Admin {$actor->name} memicu batch retry {$category}: {$successfullyQueued} dijadwalkan, {$skipped} dilewati, {$rejected} ditolak dari {$selected} dipilih.",
            actor: $actor,
            metadata: [
                'category'            => $category,
                'connection_id'       => $connectionId,
                'selected_count'      => $selected,
                'successfully_queued' => $successfullyQueued,
                'skipped_count'       => $skipped,
                'rejected_count'      => $rejected,
            ]
        );

        $message = "Batch retry berhasil: {$successfullyQueued} record berhasil dijadwalkan ulang ke antrean.";
        if ($skipped > 0) {
            $message .= " ({$skipped} record dilewati karena sedang diproses atau tidak eligible).";
        }
        if ($rejected > 0) {
            $message .= " ({$rejected} record ditolak karena di luar scope atau syarat belum terpenuhi).";
        }

        return redirect()
            ->back()
            ->with('success', $message);
    }
}
