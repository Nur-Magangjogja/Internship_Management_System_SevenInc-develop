<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplateVersion extends Model
{
    use HasFactory;

    protected $table = 'document_template_versions';

    protected $fillable = [
        'document_template_id',
        'certificate_type',
        'version_number',
        'name',
        'content_html',
        'editor_json',
        'canvas_layout',
        'page_config',
        'change_summary',
        'content_sha256',
        'state',
        'created_by',
    ];

    protected $casts = [
        'editor_json'    => 'array',
        'canvas_layout'  => 'array',
        'page_config'    => 'array',
        'version_number' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
