<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternInfoSource extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'intern_info_sources';
    protected $fillable = ['internship_registration_id', 'source_name'];

    public function internshipRegistration()
    {
        return $this->belongsTo(InternshipRegistration::class, 'internship_registration_id')->withTrashed();
    }
}
