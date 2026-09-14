<?php

declare(strict_types=1);

namespace App\Document\Structure;

/**
 * Renvoi croisé détecté dans le texte (« voir Figure 3 », « cf. Annexe B »).
 *
 * Un renvoi croisé est créé sur un bloc `paragraph` contenant un motif
 * `(Figure|Tableau|Annexe|Planche)\s+([A-Z0-9]+)`.
 *
 * Points clés de la résolution (REFONTE_ARCHITECTURE.md §10) :
 *  - `targetOriginalNumber` n'est JAMAIS fiable : le document d'origine peut
 *    contenir des trous et des doublons, c'est précisément ce qui déclenche la
 *    renumérotation.
 *  - La résolution se fait par PROXIMITÉ DANS L'ORDRE DU DOCUMENT (et non par
 *    une distance en caractères) quand le numéro d'origine est ambigu.
 *  - Un renvoi ambigu NE DOIT JAMAIS bloquer l'utilisateur : la confiance est
 *    signalée dans un rapport discret de fin de traitement.
 */
final readonly class CrossRef
{
    /**
     * Seuil sous lequel la résolution est signalée comme incertaine.
     *
     * Un renvoi résolu avec une confiance inférieure à ce seuil est listé dans
     * le rapport de fin de traitement — sans formulaire bloquant.
     */
    public const LOW_CONFIDENCE_THRESHOLD = 0.7;

    /**
     * @param  string  $matchedText  Texte exact tel qu'écrit dans le document (« voir Figure 3 »)
     * @param  BlockCategory  $targetCategory  Catégorie visée (figure, table, annexe, planche)
     * @param  string  $targetOriginalNumber  Numéro d'origine visé, tel qu'écrit (jamais fiable)
     * @param  null|string  $resolvedBlockId  Bloc effectivement visé après résolution
     * @param  null|float  $resolutionConfidence  1.0 si résolution directe, < 0.7 si ambiguë, null si non résolu
     */
    public function __construct(
        public string $matchedText,
        public BlockCategory $targetCategory,
        public string $targetOriginalNumber,
        public ?string $resolvedBlockId = null,
        public ?float $resolutionConfidence = null,
    ) {
        if ($resolutionConfidence !== null
            && ($resolutionConfidence < 0.0 || $resolutionConfidence > 1.0)) {
            throw new \InvalidArgumentException(
                'La confiance de résolution doit être comprise entre 0 et 1.'
            );
        }
    }

    /**
     * Le renvoi a-t-il été résolu vers un bloc concret ?
     */
    public function isResolved(): bool
    {
        return $this->resolvedBlockId !== null;
    }

    /**
     * La résolution est-elle incertaine (à signaler dans le rapport) ?
     *
     * Ne bloque JAMAIS l'utilisateur : sert uniquement au signalement discret.
     */
    public function isLowConfidence(): bool
    {
        return $this->isResolved()
            && $this->resolutionConfidence !== null
            && $this->resolutionConfidence < self::LOW_CONFIDENCE_THRESHOLD;
    }

    /**
     * Résolution directe : un seul bloc portait ce numéro d'origine.
     */
    public function resolveTo(string $blockId): self
    {
        return new self(
            matchedText: $this->matchedText,
            targetCategory: $this->targetCategory,
            targetOriginalNumber: $this->targetOriginalNumber,
            resolvedBlockId: $blockId,
            resolutionConfidence: 1.0,
        );
    }

    /**
     * Résolution incertaine : plusieurs candidats équivalents.
     */
    public function resolveWithLowConfidence(string $blockId, float $confidence): self
    {
        return new self(
            matchedText: $this->matchedText,
            targetCategory: $this->targetCategory,
            targetOriginalNumber: $this->targetOriginalNumber,
            resolvedBlockId: $blockId,
            resolutionConfidence: $confidence,
        );
    }

    /**
     * Sérialisation vers le schéma JSON structurel commun (§6).
     *
     * @return array{
     *     matched_text: string,
     *     target_category: string,
     *     target_original_number: string,
     *     resolved_block_id: null|string,
     *     resolution_confidence: null|float
     * }
     */
    public function toArray(): array
    {
        return [
            'matched_text' => $this->matchedText,
            'target_category' => $this->targetCategory->value,
            'target_original_number' => $this->targetOriginalNumber,
            'resolved_block_id' => $this->resolvedBlockId,
            'resolution_confidence' => $this->resolutionConfidence,
        ];
    }

    /**
     * Reconstruit un renvoi croisé depuis le schéma JSON structurel.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $confidence = $data['resolution_confidence'] ?? null;

        return new self(
            matchedText: (string) ($data['matched_text'] ?? ''),
            targetCategory: BlockCategory::fromString(
                (string) ($data['target_category'] ?? BlockCategory::Figure->value)
            ),
            targetOriginalNumber: (string) ($data['target_original_number'] ?? ''),
            resolvedBlockId: isset($data['resolved_block_id'])
                ? (string) $data['resolved_block_id']
                : null,
            resolutionConfidence: $confidence === null ? null : (float) $confidence,
        );
    }
}
