<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ArchiveExport extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_PENDING   = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';

    protected $table = 'archive_exports';

    protected $fillable = [
        'public_uuid',
        'archive_year',
        'file_name',
        'file_path',
        'file_size_bytes',
        'file_manifest',
        'status',
        'failure_message',
        'drive_file_id',
        'drive_web_view_link',
        'drive_synced_at',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'archive_year'    => 'integer',
        'file_size_bytes' => 'integer',
        'file_manifest'   => 'array',
        'generated_at'    => 'datetime',
        'drive_synced_at' => 'datetime',
    ];

    public function getIsDriveSyncedAttribute(): bool
    {
        return !empty($this->drive_file_id);
    }


    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->public_uuid)) {
                $model->public_uuid = (string) Str::uuid();
            }
            if (empty($model->generated_at)) {
                $model->generated_at = now();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where('public_uuid', $value)
            ->orWhere('id', $value)
            ->firstOrFail();
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = (int) $this->file_size_bytes;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::STATUS_PENDING   => 'bg-amber-50 text-amber-700 border-amber-200',
            self::STATUS_FAILED    => 'bg-rose-50 text-rose-700 border-rose-200',
            default                => 'bg-slate-50 text-slate-700 border-slate-200',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_PENDING   => 'Diproses',
            self::STATUS_FAILED    => 'Gagal',
            default                => ucfirst($this->status),
        };
    }
}
