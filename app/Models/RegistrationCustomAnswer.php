<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationCustomAnswer extends Model
{
    use HasFactory;

    protected $table = 'registration_custom_answers';

    protected $fillable = [
        'registration_id',
        'field_key',
        'answer_value',
        'answer_label',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(InternshipRegistration::class, 'registration_id');
    }

    public function registry(): BelongsTo
    {
        return $this->belongsTo(FormFieldRegistry::class, 'field_key', 'field_key');
    }

    protected static function booted(): void
    {
        static::updating(function (RegistrationCustomAnswer $answer) {
            if ($answer->isDirty('field_key')) {
                throw new \DomainException("field_key pada jawaban pendaftaran tidak dapat diubah.");
            }
            if ($answer->isDirty('registration_id')) {
                throw new \DomainException("registration_id pada jawaban pendaftaran tidak dapat diubah.");
            }
        });
    }
}
