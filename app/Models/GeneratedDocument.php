<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedDocument extends Model
{
    /**
     * Colonnes `cover_template_id`, `cover_page_template_id` et `cover_values`
     * existent toujours en base (migrations conservées) mais ne sont plus
     * alimentées : le module « page de garde » est retiré du produit.
     */
    protected $fillable = [
        'document_id',
        'template_id',
        'output_path',
        'status',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
