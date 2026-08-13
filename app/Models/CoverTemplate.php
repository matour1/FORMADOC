<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoverTemplate extends Model
{
    protected $fillable = [
        'name',
        'example_docx_path',
        'detected_zones',
        'zone_mapping'
    ];

    protected $casts = [
        'detected_zones' => 'array',
        'zone_mapping' => 'array',
    ];

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }
}
