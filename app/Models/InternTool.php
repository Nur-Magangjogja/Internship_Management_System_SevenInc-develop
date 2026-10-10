<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InternTool extends Model
{
    use HasFactory;

    protected $table = 'intern_tools';
    public $timestamps = false;

    protected $fillable = [
        'internship_registration_id',
        'tool_name'
    ];

    public function internshipRegistration()
    {
        return $this->belongsTo(InternshipRegistration::class)->withTrashed();
    }
}
