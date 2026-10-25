<?php

declare(strict_types=1);

namespace App\Document\Classification;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;

/**
 * Implémentation déterministe (par règles, sans IA) de `HeadingClassifier` (§3 & D-008).
 *
 * S'appuie sur `SignalAggregator` et `HeadingNumberingPattern` pour évaluer
 * les signaux structurels et textuels d'un bloc :
 *  - concordance ou contradiction entre style Word (`outlineLvl`) et numérotation ;
 *  - reconnaissance des motifs de numérotation (`I.`, `1.1`, `CHAPITRE`, etc.)
 *    avec filtrage des civilités (`M.`, `Mme`, `Dr`…) ;
 *  - détection des lignes isolées en gras comme candidats incertains (`needs_ai = true`).
 */
final class RuleBasedHeadingClassifier implements HeadingClassifier
{
    public function __construct(
        private readonly SignalAggregator $signals = new SignalAggregator(preserveExistingHeadingConfidence: true),
    ) {}

    /**
     * {@inheritDoc}
     */
    public function classifyHeading(Block $block): array
    {
        $assessment = $this->signals->assess($block);
        $isHeading = $assessment['type'] === BlockType::Heading;

        return [
            'is_heading' => $isHeading,
            'type' => $assessment['type'],
            'heading_level' => $isHeading ? $assessment['heading_level'] : null,
            'confidence' => (float) $assessment['confidence'],
            'signals_used' => $assessment['signals_used'],
            'needs_ai' => (bool) $assessment['needs_ai'],
            'reason' => (string) $assessment['reason'],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function isConfidentHeading(Block $block): bool
    {
        $verdict = $this->classifyHeading($block);

        return $verdict['is_heading'] && $verdict['confidence'] >= Block::AUTO_ACCEPT_THRESHOLD;
    }
}
