<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternSkill extends Model
{
    use HasFactory;

    protected $table = 'intern_skills';
    public $timestamps = false;

    protected $fillable = [
        'internship_registration_id',
        'skill_category',
        'skill_name'
    ];

    public function internshipRegistration()
    {
        return $this->belongsTo(InternshipRegistration::class)->withTrashed();
    }
}
