<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedDocument extends Model
{
    protected $fillable = [
        'document_id',
        'template_id',
        'cover_template_id',
        'output_path',
        'cover_values',
        'status'
    ];

    protected $casts = [
        'cover_values' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function coverTemplate(): BelongsTo
    {
        return $this->belongsTo(CoverTemplate::class);
    }
}
