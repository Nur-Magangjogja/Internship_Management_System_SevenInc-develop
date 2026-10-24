<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalImportRow extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    protected $table = 'historical_import_rows';

    protected $fillable = [
        'batch_id',
        'row_index',
        'event_fingerprint',
        'raw_data',
        'mapped_data',
        'submission_timestamp',
        'target_year',
        'lifecycle_status',
        'validation_status',
        'validation_messages',
        'is_duplicate_event',
        'is_existing_applicant',
        'internship_registration_id',
    ];

    protected $casts = [
        'raw_data'              => 'array',
        'mapped_data'           => 'array',
        'validation_messages'   => 'array',
        'submission_timestamp'  => 'datetime',
        'row_index'             => 'integer',
        'target_year'           => 'integer',
        'is_duplicate_event'    => 'boolean',
        'is_existing_applicant' => 'boolean',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HistoricalImportBatch::class, 'batch_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'internship_registration_id');
    }
}
