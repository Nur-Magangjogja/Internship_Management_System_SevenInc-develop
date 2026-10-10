<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class PendingTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'intern_id', 
        'title', 
        'description'
    ];

    public function internshipRegistration()
    {
        return $this->belongsTo(InternshipRegistration::class, 'intern_id')->withTrashed();
    }
}
