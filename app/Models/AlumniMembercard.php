<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlumniMembercard extends Model
{
    use HasFactory;

    protected $table = 'alumni_membercards';

    protected $fillable = [
        'intern_id',
        'member_code',
        'batch_year',
        'model_url',
        'has_downloaded',
        'downloaded_at',
    ];

    protected $casts = [
        'has_downloaded' => 'boolean',
        'downloaded_at' => 'datetime',
    ];

    public function intern()
    {
        return $this->belongsTo(InternshipRegistration::class, 'intern_id')->withTrashed();
    }

    public function getNameAttribute(): ?string
    {
        return $this->intern?->fullname;
    }

    public function getCodeAttribute(): ?string
    {
        return $this->member_code;
    }

    public function getBrandAttribute(): ?string
    {
        return $this->intern?->brand;
    }

    public function getInstansiAttribute(): ?string
    {
        return $this->intern?->institution_name;
    }

    public function getAngkatanAttribute(): ?string
    {
        return $this->batch_year;
    }
}
