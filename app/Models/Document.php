<?php

namespace App\Models;

use App\Models\Concerns\HasHashId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Document extends Model
{
    use HasHashId;

    protected $fillable = [
        'filename',
        'path',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function structure(): HasOne
    {
        return $this->hasOne(DocumentStructure::class);
    }

    /**
     * Questions de clarification posées sur les blocs ambigus (R2).
     *
     * Chaque ligne porte une question sur UN bloc. L'interface les liste, et
     * `ClarificationService::applyAnswers()` applique les réponses à la
     * structure — une réponse ne corrigeant que le bloc visé.
     */
    public function clarifications(): HasMany
    {
        return $this->hasMany(DocumentClarification::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }
}
