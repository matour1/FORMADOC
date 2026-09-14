<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * État antérieur d'un document, enregistré avant une action destructive (§9.7).
 *
 * Un snapshot contient le JSON structurel **complet** : restaurer ne demande
 * donc aucune reconstruction ni aucune heuristique, on réécrit simplement l'état
 * tel qu'il était. C'est ce qui rend l'annulation fiable — le contraire d'un
 * journal d'opérations à « rejouer à l'envers », qui se trompe dès qu'une
 * opération n'est pas parfaitement réversible.
 */
class DocumentSnapshot extends Model
{
    protected $fillable = [
        'document_id',
        'block_count',
        'reason',
        'metadata',
        'payload',
    ];

    protected $casts = [
        'block_count' => 'integer',
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Motif lisible, tel qu'affiché dans l'historique.
     */
    public function label(): string
    {
        return $this->reason !== '' && $this->reason !== null
            ? $this->reason
            : 'État enregistré';
    }

    /**
     * Outil à l'origine du snapshot, si connu.
     */
    public function tool(): ?string
    {
        $outil = $this->metadata['tool'] ?? null;

        return is_string($outil) ? $outil : null;
    }
}
