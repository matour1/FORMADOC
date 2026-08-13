<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentStructure extends Model
{
    protected $fillable = [
        'document_id',
        'structure',
        'ambiguities',
        'validated_corrections'
    ];

    protected $casts = [
        'structure' => 'array',
        'ambiguities' => 'array',
        'validated_corrections' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
