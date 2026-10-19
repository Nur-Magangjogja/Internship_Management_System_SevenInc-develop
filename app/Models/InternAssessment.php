<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternAssessment extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    // Menambahkan properti $fillable untuk semua kolom yang bisa diisi
    protected $fillable = [
        'intern_id',
        'assessment_number',
        'aspek_penilaian',
        'rata_rata',
        'company_name',
        'company_address',
        'company_logo_path',
        'signatory_name',
        'signatory_position',
        'signature_image_path',
    ];

    protected $casts = [
        'aspek_penilaian' => 'array',
        'rata_rata' => 'float',
    ];

    public function intern()
    {
        return $this->belongsTo(InternshipRegistration::class, 'intern_id')->withTrashed();
    }

    public function generatedDocument()
    {
        return $this->hasOne(GeneratedDocument::class, 'internship_registration_id', 'intern_id')
            ->where('document_type', GeneratedDocument::TYPE_ASSESSMENT);
    }
}
