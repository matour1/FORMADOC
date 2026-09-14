<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clarification demandée à l'utilisateur sur un bloc ambigu.
 *
 * Une ligne porte **une question sur UN bloc** : c'est la garantie que
 * l'utilisateur n'est pas noyé sous un formulaire global (REFONTE §7, §8).
 */
class DocumentClarification extends Model
{
    protected $fillable = [
        'document_id',
        'block_id',
        'question',
        'input_type',
        'options',
        'excerpt',
        'confidence',
        'reason',
        'answer_type',
        'answer_raw',
        'answered_at',
    ];

    protected $casts = [
        'options' => 'array',
        'confidence' => 'float',
        'answered_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * La question a-t-elle reçu une réponse ?
     */
    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }

    /**
     * Enregistre la réponse de l'utilisateur.
     *
     * @param  string  $answerRaw  Option choisie telle qu'affichée (« Titre niveau 2 »)
     */
    public function answer(string $answerRaw): void
    {
        $this->update([
            'answer_raw' => $answerRaw,
            'answer_type' => $this->typeFromAnswer($answerRaw),
            'answered_at' => now(),
        ]);
    }

    /**
     * Traduit une réponse lisible en type de bloc du schéma commun.
     *
     * La traduction vit dans le modèle plutôt que dans la vue : plusieurs
     * écrans (formulaire, récapitulatif) doivent l'utiliser de façon identique.
     */
    public function typeFromAnswer(string $answer): ?string
    {
        $normalized = mb_strtolower(trim($answer));

        return match (true) {
            str_contains($normalized, 'titre niveau 1') => 'heading_1',
            str_contains($normalized, 'titre niveau 2') => 'heading_2',
            str_contains($normalized, 'titre niveau 3') => 'heading_3',
            str_contains($normalized, 'titre') => 'heading_1',
            str_contains($normalized, 'paragraphe') => 'paragraph',
            str_contains($normalized, 'figure') => 'figure',
            str_contains($normalized, 'tableau') => 'table',
            str_contains($normalized, 'annexe') => 'annexe',
            str_contains($normalized, 'planche') => 'planche',
            str_contains($normalized, 'image') => 'image',
            default => null,
        };
    }

    /**
     * Niveau hiérarchique déduit de la réponse, pour les titres.
     */
    public function headingLevelFromAnswer(): ?int
    {
        $type = $this->answer_type ?? ($this->answer_raw === null ? null : $this->typeFromAnswer($this->answer_raw));

        if ($type === null || preg_match('/^heading_(\d)$/', $type, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
