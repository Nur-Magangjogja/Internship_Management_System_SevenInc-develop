<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IntegrationOutboxEvent extends Model
{
    use HasFactory;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_VERIFIED   = 'verified';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_SKIPPED    = 'skipped';

    protected $table = 'integration_outbox_events';

    protected $fillable = [
        'public_uuid',
        'event_type',
        'target_connection_id',
        'aggregate_type',
        'aggregate_id',
        'idempotency_key',
        'expected_schema_hash',
        'status',
        'attempt_count',
        'max_attempts',
        'last_attempt_at',
        'last_error',
        'payload',
        'response_context',
    ];

    protected $casts = [
        'attempt_count'   => 'integer',
        'max_attempts'    => 'integer',
        'last_attempt_at' => 'datetime',
        'payload'         => 'array',
        'response_context'=> 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->public_uuid)) {
                $model->public_uuid = (string) Str::uuid();
            }
        });
    }

    public function targetConnection(): BelongsTo
    {
        return $this->belongsTo(SpreadsheetConnection::class, 'target_connection_id');
    }
}
