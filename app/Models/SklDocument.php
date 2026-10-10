<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SklDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'intern_id',
        'skl_number',
        'company_name',
        'company_logo_path',
        'signatory_name',
        'signatory_position',
        'signature_image_path',
    ];

    public function intern()
    {
        return $this->belongsTo(InternshipRegistration::class, 'intern_id')->withTrashed();
    }
}
