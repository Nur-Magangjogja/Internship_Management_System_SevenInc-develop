<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormFieldRegistry extends Model
{
    use HasFactory;

    public const STATE_FORM_FIELD = 'form_field';
    public const STATE_HISTORICAL_ONLY = 'historical_only';
    public const STATE_ARCHIVED = 'archived';

    protected $table = 'form_field_registries';

    protected $fillable = [
        'field_key',
        'field_type',
        'field_state',
        'canonical_label',
        'form_enabled',
        'history_storage_enabled',
        'spreadsheet_sync_enabled',
        'source_header_alias',
        'first_published_version',
        'is_locked',
        'allowed_options',
        'notes',
    ];

    protected $casts = [
        'is_locked'                => 'boolean',
        'form_enabled'             => 'boolean',
        'history_storage_enabled'  => 'boolean',
        'spreadsheet_sync_enabled' => 'boolean',
        'allowed_options'          => 'array',
    ];

    public function isHistoricalOnly(): bool
    {
        return $this->field_state === self::STATE_HISTORICAL_ONLY;
    }

    public function isFormActive(): bool
    {
        return $this->field_state === self::STATE_FORM_FIELD && $this->form_enabled;
    }

    protected static function booted(): void
    {
        static::updating(function (FormFieldRegistry $registry) {
            $wasLocked = (bool) $registry->getOriginal('is_locked');
            if ($wasLocked) {
                if ($registry->isDirty('field_key')) {
                    throw new \DomainException("field_key '{$registry->getOriginal('field_key')}' telah dikunci secara permanen dan tidak dapat diubah.");
                }
                if ($registry->isDirty('field_type') && $registry->field_type !== $registry->getOriginal('field_type')) {
                    throw new \DomainException("Tipe field untuk '{$registry->field_key}' telah dikunci permanen sebagai '{$registry->getOriginal('field_type')}'.");
                }
                if ($registry->getOriginal('first_published_version') !== null && $registry->isDirty('first_published_version')) {
                    throw new \DomainException("first_published_version untuk '{$registry->field_key}' bersifat permanen dan tidak dapat diubah.");
                }
            }
        });

        static::deleting(function (FormFieldRegistry $registry) {
            if ($registry->is_locked) {
                throw new \DomainException("Registry field_key '{$registry->field_key}' yang telah dikunci dilarang dihapus.");
            }
        });
    }
}
