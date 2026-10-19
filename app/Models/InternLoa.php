<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternLoa extends Model
{
    use HasFactory;

    protected $table = 'intern_loas';
    public $timestamps = false;

    protected $fillable = [
        'intern_id',
        'loa_number',
        'accepted_start_date',
        'accepted_end_date',
        'company_name',
        'company_logo_path',
        'signatory_name',
        'signatory_position',
        'signature_image_path',
        'created_at',
    ];

    protected $casts = [
        'accepted_start_date' => 'date',
        'accepted_end_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function intern()
    {
        return $this->belongsTo(InternshipRegistration::class, 'intern_id')->withTrashed();
    }

    public function generatedDocument()
    {
        return $this->hasOne(GeneratedDocument::class, 'internship_registration_id', 'intern_id')
            ->where('document_type', GeneratedDocument::TYPE_LOA);
    }
}
