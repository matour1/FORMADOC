<?php

declare(strict_types=1);

namespace App\Document\Classification;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;

/**
 * Contrat de classification des titres d'un document (§3 & D-008).
 *
 * Permet d'évaluer un bloc candidat au statut de titre (niveau hiérarchique,
 * confiance, signaux mobilisés et besoin de vérification ultérieure).
 *
 * L'implémentation actuelle (`RuleBasedHeadingClassifier`) repose exclusivement
 * sur des règles déterministes (styles Word, motifs de numérotation, mots-clés
 * de section et signaux visuels) sans appel externe, tout en exposant le point
 * d'extension prévu pour brancher plus tard un classifieur IA sur les titres
 * incertains (`needs_ai === true`).
 */
interface HeadingClassifier
{
    /**
     * Évalue un bloc pour déterminer s'il s'agit d'un titre et à quel niveau.
     *
     * @return array{
     *     is_heading: bool,
     *     type: BlockType,
     *     heading_level: null|int,
     *     confidence: float,
     *     signals_used: array<int, string>,
     *     needs_ai: bool,
     *     reason: string
     * }
     */
    public function classifyHeading(Block $block): array;

    /**
     * Indique si le bloc est reconnu comme titre avec une confiance suffisante
     * pour être accepté automatiquement (≥ `Block::AUTO_ACCEPT_THRESHOLD`).
     */
    public function isConfidentHeading(Block $block): bool;
}
