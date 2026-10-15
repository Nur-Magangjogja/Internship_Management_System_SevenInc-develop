<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormFieldRegistry extends Model
{
    use HasFactory;

    protected $table = 'form_field_registries';

    protected $fillable = [
        'field_key',
        'field_type',
        'canonical_label',
        'first_published_version',
        'is_locked',
        'allowed_options',
    ];

    protected $casts = [
        'is_locked'       => 'boolean',
        'allowed_options' => 'array',
    ];

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
