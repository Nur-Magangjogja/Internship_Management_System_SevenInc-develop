<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\InternshipRegistration;
use App\Models\RegistrationAttachment;
use App\Models\SpreadsheetConnection;
use App\Models\SpreadsheetRowSync;
use App\Models\SpreadsheetSidecarSync;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncMonitoringQueryService
{
    /**
     * Mengambil statistik ringkas status sinkronisasi berdasarkan tiga kategori:
     * 1. Main Spreadsheet (SpreadsheetRowSync)
     * 2. Sidecar Extension (SpreadsheetSidecarSync)
     * 3. Drive Attachments (RegistrationAttachment)
     */
    public function getSummaryStatistics(?int $connectionId = null, ?int $year = null, ?User $actor = null): array
    {
        $now = now();
        $allowedConnectionIds = $actor ? $actor->getAllowedConnectionIds() : null;

        // Jika actor dibatasi dan connectionId di luar scope, paksa kosong (fail-closed)
        if ($allowedConnectionIds !== null && $connectionId !== null && !in_array($connectionId, $allowedConnectionIds, true)) {
            $connectionId = -999999;
        }

        // 1. Main Row Sync Statistics
        $mainQuery = SpreadsheetRowSync::query();
        if ($connectionId) {
            $mainQuery->where('spreadsheet_connection_id', $connectionId);
        } elseif ($allowedConnectionIds !== null) {
            $mainQuery->whereIn('spreadsheet_connection_id', $allowedConnectionIds);
        }
        if ($year) {
            $mainQuery->where('target_sheet_tab', (string) $year);
        }

        $mainStats = [
            'total'           => (clone $mainQuery)->count(),
            'pending'         => (clone $mainQuery)->where('sync_status', SpreadsheetRowSync::STATUS_PENDING)->count(),
            'processing'      => (clone $mainQuery)->where('sync_status', SpreadsheetRowSync::STATUS_PROCESSING)->count(),
            'retrying'        => (clone $mainQuery)->where('sync_status', SpreadsheetRowSync::STATUS_RETRYING)->count(),
            'failed'          => (clone $mainQuery)->where('sync_status', SpreadsheetRowSync::STATUS_FAILED)->count(),
            'synced'          => (clone $mainQuery)->where('sync_status', SpreadsheetRowSync::STATUS_SYNCED)->count(),
            'delayed_retries' => (clone $mainQuery)->where('sync_status', SpreadsheetRowSync::STATUS_RETRYING)
                ->where('next_retry_at', '<=', $now)->count(),
            'expired_leases'  => (clone $mainQuery)->where('sync_status', SpreadsheetRowSync::STATUS_PROCESSING)
                ->where('locked_at', '<', $now->copy()->subMinutes(5))->count(),
        ];

        // 2. Sidecar Extension Sync Statistics
        $sidecarQuery = SpreadsheetSidecarSync::query();
        if ($connectionId) {
            $sidecarQuery->where('spreadsheet_connection_id', $connectionId);
        } elseif ($allowedConnectionIds !== null) {
            $sidecarQuery->whereIn('spreadsheet_connection_id', $allowedConnectionIds);
        }
        if ($year) {
            $sidecarQuery->where(function ($q) use ($year) {
                $q->where('main_sheet_tab', (string) $year)
                  ->orWhere('extension_sheet_tab', 'like', "%{$year}%");
            });
        }

        $sidecarStats = [
            'total'           => (clone $sidecarQuery)->count(),
            'pending'         => (clone $sidecarQuery)->where('sync_status', SpreadsheetSidecarSync::STATUS_PENDING)->count(),
            'processing'      => (clone $sidecarQuery)->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)->count(),
            'retrying'        => (clone $sidecarQuery)->where('sync_status', SpreadsheetSidecarSync::STATUS_RETRYING)->count(),
            'failed'          => (clone $sidecarQuery)->where('sync_status', SpreadsheetSidecarSync::STATUS_FAILED)->count(),
            'synced'          => (clone $sidecarQuery)->where('sync_status', SpreadsheetSidecarSync::STATUS_SYNCED)->count(),
            'delayed_retries' => (clone $sidecarQuery)->where('sync_status', SpreadsheetSidecarSync::STATUS_RETRYING)
                ->where('next_retry_at', '<=', $now)->count(),
            'expired_leases'  => (clone $sidecarQuery)->where('sync_status', SpreadsheetSidecarSync::STATUS_PROCESSING)
                ->where('locked_at', '<', $now->copy()->subMinutes(5))->count(),
        ];

        // 3. Drive Attachments Statistics
        $regConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        $canAccessRegistration = $regConn && ($allowedConnectionIds === null || in_array($regConn->id, $allowedConnectionIds, true));
        $isRegistrationConnectionFilter = ($connectionId === null) || ($regConn && $connectionId === (int) $regConn->id);

        $attachmentStats = [
            'total'           => 0,
            'pending'         => 0,
            'processing'      => 0,
            'retrying'        => 0,
            'failed'          => 0,
            'synced'          => 0,
            'delayed_retries' => 0,
            'expired_leases'  => 0,
        ];

        if ($canAccessRegistration && $isRegistrationConnectionFilter && Schema::hasTable('registration_attachments')) {
            $attQuery = RegistrationAttachment::query();
            if ($year) {
                $attQuery->whereHas('registration', function ($rq) use ($year) {
                    $rq->whereYear('created_at', $year);
                });
            }

            $hasLeaseCols = Schema::hasColumn('registration_attachments', 'drive_locked_by');

            $attachmentStats = [
                'total'           => (clone $attQuery)->count(),
                'pending'         => (clone $attQuery)->where('drive_sync_status', RegistrationAttachment::STATUS_PENDING)->count(),
                'processing'      => (clone $attQuery)->where('drive_sync_status', RegistrationAttachment::STATUS_PROCESSING)->count(),
                'retrying'        => (clone $attQuery)->where('drive_sync_status', RegistrationAttachment::STATUS_RETRYING)->count(),
                'failed'          => (clone $attQuery)->where('drive_sync_status', RegistrationAttachment::STATUS_FAILED)->count(),
                'synced'          => (clone $attQuery)->where('drive_sync_status', RegistrationAttachment::STATUS_SYNCED)->count(),
                'delayed_retries' => (clone $attQuery)->where('drive_sync_status', RegistrationAttachment::STATUS_RETRYING)
                    ->where('drive_next_retry_at', '<=', $now)->count(),
                'expired_leases'  => $hasLeaseCols
                    ? (clone $attQuery)->where('drive_sync_status', RegistrationAttachment::STATUS_PROCESSING)
                        ->where('drive_lock_expires_at', '<', $now)->count()
                    : 0,
            ];
        }

        // 4. Aggregated Operational Overview
        $aggregated = [
            'total_operations' => $mainStats['total'] + $sidecarStats['total'] + $attachmentStats['total'],
            'total_pending'    => $mainStats['pending'] + $sidecarStats['pending'] + $attachmentStats['pending'],
            'total_processing' => $mainStats['processing'] + $sidecarStats['processing'] + $attachmentStats['processing'],
            'total_retrying'   => $mainStats['retrying'] + $sidecarStats['retrying'] + $attachmentStats['retrying'],
            'total_failed'     => $mainStats['failed'] + $sidecarStats['failed'] + $attachmentStats['failed'],
            'total_synced'     => $mainStats['synced'] + $sidecarStats['synced'] + $attachmentStats['synced'],
            'notice'           => 'Jumlah operasi agregat mencakup tiga dimensi sinkronisasi terpisah (baris utama, ekstensi sidecar, dan berkas lampiran Drive). Satu berkas pendaftaran dapat memiliki beberapa entitas sinkronisasi.',
        ];

        return [
            'main_row'   => $mainStats,
            'sidecar'    => $sidecarStats,
            'attachment' => $attachmentStats,
            'aggregated' => $aggregated,
        ];
    }

    /**
     * Mengambil daftar record pemantauan sinkronisasi dengan pagination server-side.
     */
    public function getMonitoringRecords(array $filters = [], int $perPage = 15, ?User $actor = null): LengthAwarePaginator
    {
        $category     = $filters['category'] ?? 'all';
        $status       = $filters['status'] ?? 'all';
        $connectionId = !empty($filters['connection_id']) && $filters['connection_id'] !== 'all' ? (int) $filters['connection_id'] : null;
        $year         = !empty($filters['year']) && $filters['year'] !== 'all' ? (int) $filters['year'] : null;
        $search       = !empty($filters['search']) ? trim($filters['search']) : null;

        $allowedConnectionIds = $actor ? $actor->getAllowedConnectionIds() : null;

        // Query berdasarkan kategori spesifik
        if ($category === 'main_row') {
            return $this->paginateMainRows($status, $connectionId, $year, $search, $perPage, $allowedConnectionIds);
        }

        if ($category === 'sidecar') {
            return $this->paginateSidecars($status, $connectionId, $year, $search, $perPage, $allowedConnectionIds);
        }

        if ($category === 'attachment') {
            return $this->paginateAttachments($status, $connectionId, $year, $search, $perPage, $allowedConnectionIds);
        }

        // Default 'all': Unified paginated stream
        return $this->paginateUnified($status, $connectionId, $year, $search, $perPage, $allowedConnectionIds);
    }

    /**
     * Pagination untuk Main Spreadsheet Rows.
     */
    protected function paginateMainRows(string $status, ?int $connectionId, ?int $year, ?string $search, int $perPage, ?array $allowedConnectionIds = null): LengthAwarePaginator
    {
        // Fail-closed jika connectionId diminta di luar scope yang diizinkan
        if ($allowedConnectionIds !== null && $connectionId !== null && !in_array($connectionId, $allowedConnectionIds, true)) {
            return new ConcretePaginator([], 0, $perPage);
        }

        $query = SpreadsheetRowSync::with(['spreadsheetConnection', 'syncable'])->latest('id');

        if ($status !== 'all') {
            $query->where('sync_status', $status);
        }
        if ($connectionId) {
            $query->where('spreadsheet_connection_id', $connectionId);
        } elseif ($allowedConnectionIds !== null) {
            $query->whereIn('spreadsheet_connection_id', $allowedConnectionIds);
        }
        if ($year) {
            $query->where('target_sheet_tab', (string) $year);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('external_sync_id', 'like', "%{$search}%")
                  ->orWhere('public_uuid', 'like', "%{$search}%")
                  ->orWhere('syncable_id', $search);
            });
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $paginator->getCollection()->transform(fn ($row) => $this->transformMainRowItem($row));

        return $paginator;
    }

    /**
     * Pagination untuk Sidecar Custom Fields Syncs.
     */
    protected function paginateSidecars(string $status, ?int $connectionId, ?int $year, ?string $search, int $perPage, ?array $allowedConnectionIds = null): LengthAwarePaginator
    {
        if ($allowedConnectionIds !== null && $connectionId !== null && !in_array($connectionId, $allowedConnectionIds, true)) {
            return new ConcretePaginator([], 0, $perPage);
        }

        $query = SpreadsheetSidecarSync::with(['spreadsheetConnection', 'registration'])->latest('id');

        if ($status !== 'all') {
            $query->where('sync_status', $status);
        }
        if ($connectionId) {
            $query->where('spreadsheet_connection_id', $connectionId);
        } elseif ($allowedConnectionIds !== null) {
            $query->whereIn('spreadsheet_connection_id', $allowedConnectionIds);
        }
        if ($year) {
            $query->where(function ($q) use ($year) {
                $q->where('main_sheet_tab', (string) $year)
                  ->orWhere('extension_sheet_tab', 'like', "%{$year}%");
            });
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('external_sync_id', 'like', "%{$search}%")
                  ->orWhere('public_uuid', 'like', "%{$search}%")
                  ->orWhere('internship_registration_id', $search);
            });
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $paginator->getCollection()->transform(fn ($row) => $this->transformSidecarItem($row));

        return $paginator;
    }

    /**
     * Pagination untuk Google Drive Attachments.
     */
    protected function paginateAttachments(string $status, ?int $connectionId, ?int $year, ?string $search, int $perPage, ?array $allowedConnectionIds = null): LengthAwarePaginator
    {
        if (!Schema::hasTable('registration_attachments')) {
            return new ConcretePaginator([], 0, $perPage);
        }

        $regConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        $canAccessRegistration = $regConn && ($allowedConnectionIds === null || in_array($regConn->id, $allowedConnectionIds, true));
        $isRegistrationConnectionFilter = ($connectionId === null) || ($regConn && $connectionId === (int) $regConn->id);

        if (!$canAccessRegistration || !$isRegistrationConnectionFilter) {
            return new ConcretePaginator([], 0, $perPage);
        }

        $query = RegistrationAttachment::with('registration')->latest('id');

        if ($status !== 'all') {
            $query->where('drive_sync_status', $status);
        }
        if ($year) {
            $query->whereHas('registration', function ($rq) use ($year) {
                $rq->whereYear('created_at', $year);
            });
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('original_name', 'like', "%{$search}%")
                  ->orWhere('drive_file_id', 'like', "%{$search}%")
                  ->orWhere('internship_registration_id', $search);
            });
        }

        $paginator = $query->paginate($perPage)->withQueryString();
        $paginator->getCollection()->transform(fn ($row) => $this->transformAttachmentItem($row));

        return $paginator;
    }

    /**
     * Unified stream pagination across all three sync categories using UNION ALL.
     */
    protected function paginateUnified(string $status, ?int $connectionId, ?int $year, ?string $search, int $perPage, ?array $allowedConnectionIds = null): LengthAwarePaginator
    {
        // 1. Subquery Main Rows
        $qMain = DB::table('spreadsheet_row_syncs')
            ->select([
                'id',
                'public_uuid',
                DB::raw("'main_row' as category"),
                'external_sync_id as sync_identifier',
                'spreadsheet_connection_id',
                'target_sheet_tab as target_tab',
                'sync_status',
                'attempt_count',
                'max_attempts',
                'last_attempt_at',
                'next_retry_at',
                'last_synced_at',
                'error_message',
                'locked_at',
                'locked_by',
                'syncable_id as registration_id',
                'created_at',
            ]);

        if ($status !== 'all') {
            $qMain->where('sync_status', $status);
        }
        if ($connectionId) {
            if ($allowedConnectionIds !== null && !in_array($connectionId, $allowedConnectionIds, true)) {
                $qMain->whereRaw('1 = 0');
            } else {
                $qMain->where('spreadsheet_connection_id', $connectionId);
            }
        } elseif ($allowedConnectionIds !== null) {
            $qMain->whereIn('spreadsheet_connection_id', $allowedConnectionIds);
        }
        if ($year) {
            $qMain->where('target_sheet_tab', (string) $year);
        }
        if ($search) {
            $qMain->where(function ($q) use ($search) {
                $q->where('external_sync_id', 'like', "%{$search}%")
                  ->orWhere('public_uuid', 'like', "%{$search}%")
                  ->orWhere('syncable_id', $search);
            });
        }

        // 2. Subquery Sidecars
        $qSidecar = DB::table('spreadsheet_sidecar_syncs')
            ->select([
                'id',
                'public_uuid',
                DB::raw("'sidecar' as category"),
                'external_sync_id as sync_identifier',
                'spreadsheet_connection_id',
                'extension_sheet_tab as target_tab',
                'sync_status',
                'attempt_count',
                'max_attempts',
                'last_attempt_at',
                'next_retry_at',
                'last_synced_at',
                'error_message',
                'locked_at',
                'locked_by',
                'internship_registration_id as registration_id',
                'created_at',
            ]);

        if ($status !== 'all') {
            $qSidecar->where('sync_status', $status);
        }
        if ($connectionId) {
            if ($allowedConnectionIds !== null && !in_array($connectionId, $allowedConnectionIds, true)) {
                $qSidecar->whereRaw('1 = 0');
            } else {
                $qSidecar->where('spreadsheet_connection_id', $connectionId);
            }
        } elseif ($allowedConnectionIds !== null) {
            $qSidecar->whereIn('spreadsheet_connection_id', $allowedConnectionIds);
        }
        if ($year) {
            $qSidecar->where(function ($q) use ($year) {
                $q->where('main_sheet_tab', (string) $year)
                  ->orWhere('extension_sheet_tab', 'like', "%{$year}%");
            });
        }
        if ($search) {
            $qSidecar->where(function ($q) use ($search) {
                $q->where('external_sync_id', 'like', "%{$search}%")
                  ->orWhere('public_uuid', 'like', "%{$search}%")
                  ->orWhere('internship_registration_id', $search);
            });
        }

        $unionQuery = $qMain->unionAll($qSidecar);

        // 3. Subquery Attachments (terikat izin registrasi dan pencocokan filter connectionId & year)
        $regConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
        $canAccessRegistration = $regConn && ($allowedConnectionIds === null || in_array($regConn->id, $allowedConnectionIds, true));
        $isRegistrationConnectionFilter = ($connectionId === null) || ($regConn && $connectionId === (int) $regConn->id);

        if ($canAccessRegistration && $isRegistrationConnectionFilter && Schema::hasTable('registration_attachments')) {
            $hasLease = Schema::hasColumn('registration_attachments', 'drive_locked_by');

            $qAtt = DB::table('registration_attachments')
                ->select([
                    'registration_attachments.id',
                    DB::raw("CAST(registration_attachments.id AS CHAR) as public_uuid"),
                    DB::raw("'attachment' as category"),
                    DB::raw("CONCAT('ATT-', registration_attachments.internship_registration_id, '-', registration_attachments.id) as sync_identifier"),
                    DB::raw("NULL as spreadsheet_connection_id"),
                    DB::raw("NULL as target_tab"),
                    'registration_attachments.drive_sync_status as sync_status',
                    'registration_attachments.drive_attempt_count as attempt_count',
                    DB::raw("5 as max_attempts"),
                    'registration_attachments.updated_at as last_attempt_at',
                    'registration_attachments.drive_next_retry_at as next_retry_at',
                    'registration_attachments.drive_synced_at as last_synced_at',
                    'registration_attachments.drive_error_message as error_message',
                    $hasLease ? 'registration_attachments.drive_lock_expires_at as locked_at' : DB::raw("NULL as locked_at"),
                    $hasLease ? 'registration_attachments.drive_locked_by as locked_by' : DB::raw("NULL as locked_by"),
                    'registration_attachments.internship_registration_id as registration_id',
                    'registration_attachments.created_at',
                ]);

            if ($status !== 'all') {
                $qAtt->where('registration_attachments.drive_sync_status', $status);
            }
            if ($year) {
                $qAtt->join('internship_registrations', 'internship_registrations.id', '=', 'registration_attachments.internship_registration_id')
                     ->whereYear('internship_registrations.created_at', $year);
            }
            if ($search) {
                $qAtt->where(function ($q) use ($search) {
                    $q->where('registration_attachments.original_name', 'like', "%{$search}%")
                      ->orWhere('registration_attachments.drive_file_id', 'like', "%{$search}%")
                      ->orWhere('registration_attachments.internship_registration_id', $search);
                });
            }

            $unionQuery = $unionQuery->unionAll($qAtt);
        }

        // Execute unified server-side pagination
        $paginator = DB::query()->fromSub($unionQuery, 'u_sync')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        // Eager load connections in memory for performance
        $connectionMap = SpreadsheetConnection::pluck('display_name', 'id')->all();

        $paginator->getCollection()->transform(function ($raw) use ($connectionMap) {
            return $this->transformRawItem($raw, $connectionMap);
        });

        return $paginator;
    }

    /**
     * Mengambil detail lengkap suatu record sinkronisasi untuk tampilan analisis kegagalan.
     */
    public function getRecordDetail(string $category, int|string $id, ?User $actor = null): ?array
    {
        $allowedConnectionIds = $actor ? $actor->getAllowedConnectionIds() : null;
        $record       = null;
        $registration = null;
        $categoryKey  = $category;

        if ($category === 'main_row') {
            $record = is_numeric($id)
                ? SpreadsheetRowSync::with(['spreadsheetConnection', 'syncable'])->find($id)
                : SpreadsheetRowSync::with(['spreadsheetConnection', 'syncable'])->where('public_uuid', $id)->first();

            if ($record && $record->syncable instanceof InternshipRegistration) {
                $registration = $record->syncable;
            }
        } elseif ($category === 'sidecar') {
            $record = is_numeric($id)
                ? SpreadsheetSidecarSync::with(['spreadsheetConnection', 'registration'])->find($id)
                : SpreadsheetSidecarSync::with(['spreadsheetConnection', 'registration'])->where('public_uuid', $id)->first();

            if ($record) {
                $registration = $record->registration;
            }
        } elseif ($category === 'attachment') {
            if (Schema::hasTable('registration_attachments')) {
                $record = RegistrationAttachment::with('registration')->find($id);
                if ($record) {
                    $registration = $record->registration;
                }
            }
        }

        if (!$record) {
            return null;
        }

        // Validasi batasan scope koneksi actor terhadap record utama
        if ($allowedConnectionIds !== null) {
            if ($category === 'main_row' || $category === 'sidecar') {
                if (!in_array($record->spreadsheet_connection_id, $allowedConnectionIds, true)) {
                    return null;
                }
            } elseif ($category === 'attachment') {
                $regConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
                if (!$regConn || !in_array($regConn->id, $allowedConnectionIds, true)) {
                    return null;
                }
            }
        }

        // Cross-category sync matrix for this registration (strictly connection scoped)
        $relatedSyncs = [
            'main_row'    => null,
            'sidecar'     => null,
            'attachments' => collect(),
        ];

        if ($registration) {
            $registration->load(['user', 'studyProgramRel', 'facultyRel', 'institution']);

            $mainRow = SpreadsheetRowSync::where('syncable_type', InternshipRegistration::class)
                ->where('syncable_id', $registration->id)
                ->latest('id')
                ->first();
            if ($mainRow && ($allowedConnectionIds === null || in_array($mainRow->spreadsheet_connection_id, $allowedConnectionIds, true))) {
                $relatedSyncs['main_row'] = $mainRow;
            }

            $sidecar = SpreadsheetSidecarSync::where('internship_registration_id', $registration->id)
                ->latest('id')
                ->first();
            if ($sidecar && ($allowedConnectionIds === null || in_array($sidecar->spreadsheet_connection_id, $allowedConnectionIds, true))) {
                $relatedSyncs['sidecar'] = $sidecar;
            }

            $regConn = SpreadsheetConnection::where('connection_key', SpreadsheetConnection::KEY_REGISTRATION)->first();
            $canAccessAttachments = $regConn && ($allowedConnectionIds === null || in_array($regConn->id, $allowedConnectionIds, true));
            if ($canAccessAttachments && Schema::hasTable('registration_attachments')) {
                $relatedSyncs['attachments'] = RegistrationAttachment::where('internship_registration_id', $registration->id)
                    ->get();
            }
        }

        // Sanitized and escaped error analysis
        $errorMessage = match ($category) {
            'attachment' => $record->drive_error_message,
            default      => $record->error_message,
        };

        $sanitizedError = $this->sanitizeErrorMessage($errorMessage);
        $errorCategory  = $this->categorizeError($record, $category, $errorMessage);

        // Lease diagnostics
        $leaseDiagnostics = $this->diagnoseLease($record, $category);

        // Audit Trail
        $subjectType = get_class($record);
        $auditLogs = ActivityLog::where('subject_type', $subjectType)
            ->where('subject_id', $record->id)
            ->latest('id')
            ->limit(20)
            ->get();

        return [
            'category'          => $categoryKey,
            'record'            => $record,
            'registration'      => $registration,
            'related_syncs'     => $relatedSyncs,
            'sanitized_error'   => $sanitizedError,
            'error_category'    => $errorCategory,
            'lease_diagnostics' => $leaseDiagnostics,
            'audit_logs'        => $auditLogs,
        ];
    }

    /**
     * Transformasi item Eloquent SpreadsheetRowSync menjadi format seragam.
     */
    protected function transformMainRowItem(SpreadsheetRowSync $row): object
    {
        $isProcessing = $row->sync_status === SpreadsheetRowSync::STATUS_PROCESSING;
        $isLocked     = !empty($row->locked_at);
        $isLeaseExpired = $isLocked && $row->locked_at->lt(now()->subMinutes(5));

        $canRetry = in_array($row->sync_status, [SpreadsheetRowSync::STATUS_FAILED, SpreadsheetRowSync::STATUS_RETRYING], true)
            || ($isProcessing && $isLeaseExpired);

        $sanitizedError = $row->error_message ? $this->sanitizeErrorMessage($row->error_message) : null;

        return (object) [
            'id'                   => $row->id,
            'uuid'                 => $row->public_uuid,
            'category'             => 'main_row',
            'category_label'       => 'Main Spreadsheet',
            'sync_identifier'      => $row->external_sync_id,
            'connection_id'        => $row->spreadsheet_connection_id,
            'connection_name'      => $row->spreadsheetConnection?->display_name ?? 'Registration Sheet',
            'target_tab'           => $row->target_sheet_tab,
            'status'               => $row->sync_status,
            'attempt_count'        => $row->attempt_count,
            'max_attempts'         => $row->max_attempts,
            'last_attempt_at'      => $row->last_attempt_at,
            'next_retry_at'        => $row->next_retry_at,
            'last_synced_at'       => $row->last_synced_at,
            'error_message'        => $sanitizedError,
            'is_locked'            => $isLocked && !$isLeaseExpired,
            'is_eligible_for_retry'=> $canRetry,
            'registration_id'      => $row->syncable_id,
            'created_at'           => $row->created_at,
            'year'                 => $row->created_at ? (int) $row->created_at->format('Y') : null,
            'model'                => $row,
        ];
    }

    /**
     * Transformasi item Eloquent SpreadsheetSidecarSync menjadi format seragam.
     */
    protected function transformSidecarItem(SpreadsheetSidecarSync $row): object
    {
        $isProcessing   = $row->sync_status === SpreadsheetSidecarSync::STATUS_PROCESSING;
        $isLocked       = !empty($row->locked_at);
        $isLeaseExpired = $isLocked && $row->locked_at->lt(now()->subMinutes(5));

        $canRetry = in_array($row->sync_status, [SpreadsheetSidecarSync::STATUS_FAILED, SpreadsheetSidecarSync::STATUS_RETRYING], true)
            || ($isProcessing && $isLeaseExpired);

        $sanitizedError = $row->error_message ? $this->sanitizeErrorMessage($row->error_message) : null;

        return (object) [
            'id'                   => $row->id,
            'uuid'                 => $row->public_uuid,
            'category'             => 'sidecar',
            'category_label'       => 'Sidecar Extension',
            'sync_identifier'      => $row->external_sync_id,
            'connection_id'        => $row->spreadsheet_connection_id,
            'connection_name'      => $row->spreadsheetConnection?->display_name ?? 'Registration Sidecar',
            'target_tab'           => $row->extension_sheet_tab,
            'status'               => $row->sync_status,
            'attempt_count'        => $row->attempt_count,
            'max_attempts'         => $row->max_attempts,
            'last_attempt_at'      => $row->last_attempt_at,
            'next_retry_at'        => $row->next_retry_at,
            'last_synced_at'       => $row->last_synced_at,
            'error_message'        => $sanitizedError,
            'is_locked'            => $isLocked && !$isLeaseExpired,
            'is_eligible_for_retry'=> $canRetry,
            'registration_id'      => $row->internship_registration_id,
            'created_at'           => $row->created_at,
            'year'                 => $row->created_at ? (int) $row->created_at->format('Y') : null,
            'model'                => $row,
        ];
    }

    /**
     * Transformasi item Eloquent RegistrationAttachment menjadi format seragam.
     */
    protected function transformAttachmentItem(RegistrationAttachment $row): object
    {
        $isProcessing   = $row->drive_sync_status === RegistrationAttachment::STATUS_PROCESSING;
        $isLocked       = !empty($row->drive_locked_by);
        $isLeaseExpired = $row->drive_lock_expires_at && $row->drive_lock_expires_at->lt(now());

        $canRetry = in_array($row->drive_sync_status, [RegistrationAttachment::STATUS_FAILED, RegistrationAttachment::STATUS_RETRYING, RegistrationAttachment::STATUS_PENDING], true)
            || ($isProcessing && $isLeaseExpired);

        $sanitizedError = $row->drive_error_message ? $this->sanitizeErrorMessage($row->drive_error_message) : null;

        return (object) [
            'id'                   => $row->id,
            'uuid'                 => (string) $row->id,
            'category'             => 'attachment',
            'category_label'       => 'Drive Attachment',
            'sync_identifier'      => "ATT-{$row->internship_registration_id}-{$row->id}",
            'connection_id'        => null,
            'connection_name'      => 'Google Drive Storage',
            'target_tab'           => $row->original_name,
            'status'               => $row->drive_sync_status,
            'attempt_count'        => $row->drive_attempt_count,
            'max_attempts'         => 5,
            'last_attempt_at'      => $row->updated_at,
            'next_retry_at'        => $row->drive_next_retry_at,
            'last_synced_at'       => $row->drive_synced_at,
            'error_message'        => $sanitizedError,
            'is_locked'            => $isLocked && !$isLeaseExpired,
            'is_eligible_for_retry'=> $canRetry,
            'registration_id'      => $row->internship_registration_id,
            'created_at'           => $row->created_at,
            'year'                 => $row->created_at ? (int) $row->created_at->format('Y') : null,
            'model'                => $row,
        ];
    }

    /**
     * Transformasi raw record dari UNION ALL query menjadi format seragam.
     */
    protected function transformRawItem(object $raw, array $connectionMap): object
    {
        $isProcessing   = $raw->sync_status === 'processing';
        $isLocked       = !empty($raw->locked_at);
        $lockedTime     = $raw->locked_at ? \Carbon\Carbon::parse($raw->locked_at) : null;
        $isLeaseExpired = $lockedTime && $lockedTime->lt(now()->subMinutes(5));

        $canRetry = in_array($raw->sync_status, ['failed', 'retrying'], true)
            || ($isProcessing && $isLeaseExpired);

        $categoryLabel = match ($raw->category) {
            'main_row'   => 'Main Spreadsheet',
            'sidecar'    => 'Sidecar Extension',
            'attachment' => 'Drive Attachment',
            default      => ucfirst($raw->category),
        };

        $connectionName = $raw->spreadsheet_connection_id
            ? ($connectionMap[$raw->spreadsheet_connection_id] ?? "Koneksi #{$raw->spreadsheet_connection_id}")
            : ($raw->category === 'attachment' ? 'Google Drive Storage' : '-');

        $sanitizedError = $raw->error_message ? $this->sanitizeErrorMessage($raw->error_message) : null;

        return (object) [
            'id'                   => $raw->id,
            'uuid'                 => $raw->public_uuid,
            'category'             => $raw->category,
            'category_label'       => $categoryLabel,
            'sync_identifier'      => $raw->sync_identifier,
            'connection_id'        => $raw->spreadsheet_connection_id,
            'connection_name'      => $connectionName,
            'target_tab'           => $raw->target_tab,
            'status'               => $raw->sync_status,
            'attempt_count'        => (int) $raw->attempt_count,
            'max_attempts'         => (int) $raw->max_attempts,
            'last_attempt_at'      => $raw->last_attempt_at ? \Carbon\Carbon::parse($raw->last_attempt_at) : null,
            'next_retry_at'        => $raw->next_retry_at ? \Carbon\Carbon::parse($raw->next_retry_at) : null,
            'last_synced_at'       => $raw->last_synced_at ? \Carbon\Carbon::parse($raw->last_synced_at) : null,
            'error_message'        => $sanitizedError,
            'is_locked'            => $isLocked && !$isLeaseExpired,
            'is_eligible_for_retry'=> $canRetry,
            'registration_id'      => $raw->registration_id,
            'created_at'           => $raw->created_at ? \Carbon\Carbon::parse($raw->created_at) : null,
            'year'                 => $raw->created_at ? (int) \Carbon\Carbon::parse($raw->created_at)->format('Y') : null,
            'model'                => null,
        ];
    }

    /**
     * Sanitasi pesan error untuk keamanan XSS dan pencegahan kebocoran secrets.
     */
    public function sanitizeErrorMessage(?string $message): string
    {
        if (empty($message)) {
            return '-';
        }

        // Sensor token / secret / path yang mungkin terbawa pada pesan error
        $sanitized = preg_replace('/(bearer\s+)[a-zA-Z0-9_\-\.]+/i', '$1[REDACTED_TOKEN]', $message);
        $sanitized = preg_replace('/(key=)[a-zA-Z0-9_\-\.]+/i', '$1[REDACTED_KEY]', $sanitized);
        $sanitized = preg_replace('/(secret=)[a-zA-Z0-9_\-\.]+/i', '$1[REDACTED_SECRET]', $sanitized);
        $sanitized = preg_replace('/(token=)[a-zA-Z0-9_\-\.]+/i', '$1[REDACTED_TOKEN]', $sanitized);
        $sanitized = preg_replace('/ya29\.[a-zA-Z0-9_\-]+/i', '[REDACTED_TOKEN]', $sanitized);
        $sanitized = preg_replace('/AIzaSy[a-zA-Z0-9_\-]+/i', '[REDACTED_KEY]', $sanitized);
        $sanitized = preg_replace('/[a-zA-Z]:[\\\\\/][a-zA-Z0-9_\-\.\\\\\/]+/i', '[REDACTED_PATH]', $sanitized);

        return $sanitized;
    }

    /**
     * Kategorisasi error menjadi terminal atau retryable.
     */
    protected function categorizeError(object $record, string $category, ?string $errorMessage): array
    {
        $status = match ($category) {
            'attachment' => $record->drive_sync_status,
            default      => $record->sync_status,
        };

        if ($status === 'synced') {
            return [
                'type'        => 'synced',
                'label'       => 'Tersinkronisasi',
                'badge_class' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                'description' => 'Operasi sinkronisasi telah berhasil diselesaikan dan terverifikasi.',
            ];
        }

        if (empty($errorMessage) && in_array($status, ['pending', 'processing'])) {
            return [
                'type'        => 'normal',
                'label'       => 'Dalam Proses Normal',
                'badge_class' => 'bg-blue-50 text-blue-700 border-blue-200',
                'description' => 'Belum ada kegagalan yang tercatat.',
            ];
        }

        // Cek terminal error markers
        $isTerminal = str_contains((string) $errorMessage, 'TERMINAL')
            || str_contains((string) $errorMessage, 'INVALID_PAYLOAD')
            || str_contains((string) $errorMessage, 'REMOTE_IDENTITY_CONFLICT')
            || str_contains((string) $errorMessage, 'REMOTE_IDENTITY_UNVERIFIABLE')
            || ($status === 'failed' && ($record->attempt_count ?? $record->drive_attempt_count ?? 0) >= 5);

        if ($isTerminal) {
            return [
                'type'        => 'terminal',
                'label'       => 'Kegagalan Permanen (Terminal)',
                'badge_class' => 'bg-rose-50 text-rose-700 border-rose-200',
                'description' => 'Memerlukan peninjauan atau tindakan koreksi manual admin. Tidak akan dicoba ulang secara otomatis.',
            ];
        }

        return [
            'type'        => 'retryable',
            'label'       => 'Dapat Dicoba Ulang (Retryable)',
            'badge_class' => 'bg-amber-50 text-amber-700 border-amber-200',
            'description' => 'Kegagalan bersifat sementara (network timeout, rate limit) dan dapat dicoba ulang secara otomatis atau manual.',
        ];
    }

    /**
     * Diagnosa status lease/worker locking.
     */
    protected function diagnoseLease(object $record, string $category): array
    {
        if ($category === 'attachment') {
            $isLocked = !empty($record->drive_locked_by);
            $expiresAt = $record->drive_lock_expires_at;
            $isExpired = $expiresAt && $expiresAt->lt(now());

            return [
                'is_locked'    => $isLocked,
                'locked_by'    => $record->drive_locked_by,
                'expires_at'   => $expiresAt,
                'is_expired'   => $isExpired,
                'status_label' => $isLocked ? ($isExpired ? 'Lease Kedaluwarsa (Siap Dipulihkan)' : 'Lease Aktif (Worker Berjalan)') : 'Tidak Terkunci',
                'badge_class'  => $isLocked ? ($isExpired ? 'bg-amber-50 text-amber-700' : 'bg-purple-50 text-purple-700') : 'bg-slate-100 text-slate-600',
            ];
        }

        $isLocked  = !empty($record->locked_at);
        $lockedAt  = $record->locked_at;
        $isExpired = $lockedAt && $lockedAt->lt(now()->subMinutes(5));

        return [
            'is_locked'    => $isLocked,
            'locked_by'    => $record->locked_by,
            'locked_at'    => $lockedAt,
            'is_expired'   => $isExpired,
            'status_label' => $isLocked ? ($isExpired ? 'Lock Kedaluwarsa (>5 mnt)' : 'Worker Aktif Mengunci') : 'Tidak Terkunci',
            'badge_class'  => $isLocked ? ($isExpired ? 'bg-amber-50 text-amber-700' : 'bg-purple-50 text-purple-700') : 'bg-slate-100 text-slate-600',
        ];
    }
}
