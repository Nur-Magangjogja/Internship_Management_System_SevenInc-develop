<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InternExtra extends Model
{
    protected $table = 'intern_extras';

    protected $fillable = [
        'intern_id',
        'rekomendasi_path',
        'rekomendasi_url',
        'rekomendasi_granted_at',
        'alumni_group_url',
        'alumni_group_label',
        'alumni_group_granted_at',
        'job_info_url',
        'job_info_description',
        'job_info_granted_at',
        'letter_number',
        'brand_id',
        'company_name',
        'company_address',
        'company_logo_path',
        'signatory_name',
        'signatory_position',
        'signature_image_path',
    ];

    protected $casts = [
        'rekomendasi_granted_at'  => 'datetime',
        'alumni_group_granted_at' => 'datetime',
        'job_info_granted_at'     => 'datetime',
    ];

    public function registration()
    {
        return $this->belongsTo(InternshipRegistration::class, 'intern_id')->withTrashed();
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
}
