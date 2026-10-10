<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'intern_id', 
        'leave_type', 
        'leave_date', 
        'reason',
        'status',
    ];

    public function internshipRegistration()
    {
        return $this->belongsTo(InternshipRegistration::class, 'intern_id')->withTrashed();
    }
}
