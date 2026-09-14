<?php

namespace App\Models;

use App\Document\Structure\StructuralDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentStructure extends Model
{
    protected $fillable = [
        'document_id',
        'structure',
        'structural_json',
        'schema_version',
        'pipeline',
        'ambiguities',
        'validated_corrections',
    ];

    protected $casts = [
        'structure' => 'array',
        'structural_json' => 'array',
        'schema_version' => 'integer',
        'ambiguities' => 'array',
        'validated_corrections' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Structure de la refonte, sous forme d'objet métier.
     *
     * Renvoie null si le document a été traité par l'ancien pipeline : la
     * conversion à la volée relève du `LegacyStructureBridge`, pas du modèle
     * (un modèle Eloquent ne doit pas porter de logique de conversion).
     */
    public function structuralDocument(): ?StructuralDocument
    {
        if (! is_array($this->structural_json) || $this->structural_json === []) {
            return null;
        }

        return StructuralDocument::fromArray($this->structural_json);
    }

    /**
     * La structure a-t-elle été produite par le nouveau pipeline ?
     */
    public function wasProcessedByNativePipeline(): bool
    {
        return $this->pipeline === 'native';
    }
}
