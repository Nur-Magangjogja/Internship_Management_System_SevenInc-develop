<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebinarCertificate extends Model
{
    use HasFactory;

    protected $table = 'webinar_certificates';
    public $timestamps = false;

    protected $fillable = [
        'attendance_id',
        'document_template_id',
        'gdrive_link',
        'certificate_number',
        'company_name',
        'background_image_path',
        'company_logo_path',
        'signatory_name',
        'signatory_position',
        'signature_image_path',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function attendance()
    {
        return $this->belongsTo(WebinarAttendance::class, 'attendance_id');
    }

    public function documentTemplate()
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }
}
