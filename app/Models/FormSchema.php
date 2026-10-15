<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormSchema extends Model
{
    use HasFactory;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    protected $table = 'form_schemas';

    protected $fillable = [
        'version_code',
        'year',
        'title',
        'status',
        'schema_hash',
        'grace_period_days',
        'is_active',
        'published_at',
        'published_by',
        'created_by',
    ];

    protected $casts = [
        'year'              => 'integer',
        'grace_period_days' => 'integer',
        'is_active'         => 'boolean',
        'published_at'      => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (FormSchema $schema) {
            $originalStatus = $schema->getOriginal('status');

            if ($originalStatus === self::STATUS_PUBLISHED) {
                // Skema published hanya boleh beralih status ke archived (misalnya saat versi baru terbit) dengan is_active = false
                // Mutasi seluruh metadata historis (version_code, year, title, schema_hash, grace_period_days, published_at, published_by, created_by)
                // atau pengembalian status ke draft secara langsung dilarang keras (S17.18G.5 Wave 2A Fix 3).
                $immutablePublishedAttributes = [
                    'version_code', 'year', 'title', 'schema_hash',
                    'grace_period_days', 'published_at', 'published_by', 'created_by'
                ];

                if ($schema->isDirty($immutablePublishedAttributes) || $schema->status === self::STATUS_DRAFT) {
                    throw new \DomainException("Skema formulir yang telah dipublikasikan bersifat imutabel. Metadata historis tidak dapat dimodifikasi atau dikembalikan ke draf.");
                }
            }

            if ($originalStatus === self::STATUS_ARCHIVED) {
                // Skema archived adalah rekaman historis permanen; dilarang keras mengubah atribut kebijakan apapun!
                $immutableArchivedAttributes = [
                    'status', 'is_active', 'version_code', 'year', 'title',
                    'schema_hash', 'grace_period_days', 'published_at', 'published_by', 'created_by'
                ];

                if ($schema->isDirty($immutableArchivedAttributes)) {
                    throw new \DomainException("Skema formulir yang telah diarsipkan bersifat imutabel permanen dan tidak dapat dimodifikasi atau dikembalikan ke draf.");
                }
            }
        });

        static::deleting(function (FormSchema $schema) {
            if ($schema->status === self::STATUS_PUBLISHED || $schema->status === self::STATUS_ARCHIVED) {
                throw new \DomainException("Skema formulir yang telah atau pernah dipublikasikan dilarang dihapus dari sistem.");
            }
        });
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class, 'form_schema_id')->orderBy('sort_order');
    }

    public function activeFields(): HasMany
    {
        return $this->fields()->where('is_active', true)->where('is_archived', false);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(RegistrationFormSnapshot::class, 'form_schema_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActivePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED)->where('is_active', true);
    }

    public function scopeDrafts($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
