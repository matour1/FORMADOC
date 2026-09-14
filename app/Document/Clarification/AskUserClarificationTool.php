<?php

declare(strict_types=1);

namespace App\Document\Clarification;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;

/**
 * Tool `ask_user_clarification` : demande ciblée sur UN bloc ambigu.
 *
 * Règle stricte de la spécification (§7, §8) : la question porte **uniquement
 * sur le bloc concerné**, jamais sur tout le document. Cela évite de noyer
 * l'utilisateur sous un formulaire global — il corrige exactement ce qui est
 * incertain, et rien d'autre.
 *
 * Sortie : un JSON Schema de formulaire affiché côté frontend.
 */
final class AskUserClarificationTool
{
    /**
     * Options proposées selon le type probable du bloc.
     *
     * @var array<string, array<int, string>>
     */
    private const OPTIONS_BY_CONTEXT = [
        'heading_or_paragraph' => ['Titre niveau 1', 'Titre niveau 2', 'Titre niveau 3', 'Paragraphe normal'],
        'caption_category' => ['Figure', 'Tableau', 'Annexe', 'Planche'],
        'table_or_text' => ['Tableau', 'Paragraphe'],
        'figure_or_image' => ['Figure (numérotée)', 'Image décorative'],
    ];

    /**
     * Construit la question à poser pour un bloc ambigu.
     *
     * @param  Block  $block  Bloc dont la classification est incertaine
     * @param  string  $deterministicReason  Raison de l'incertitude (traçabilité)
     * @return array{
     *     block_id: string,
     *     question: string,
     *     input_type: string,
     *     options: array<int, string>,
     *     context: array{excerpt: string, current_type: string, confidence: float, reason: string},
     *     schema: array<string, mixed>
     * }
     */
    public function buildQuestion(Block $block, string $deterministicReason = ''): array
    {
        $context = $this->contextOf($block);
        $options = self::OPTIONS_BY_CONTEXT[$context];

        return [
            'block_id' => $block->blockId,
            'question' => $this->questionFor($block, $context),
            'input_type' => 'single_select',
            'options' => $options,
            'context' => [
                // Extrait borné : l'utilisateur doit reconnaître le passage sans
                // qu'on lui réaffiche tout le document.
                'excerpt' => mb_substr($block->text, 0, 200),
                'current_type' => $block->type->value,
                'confidence' => round($block->confidence, 2),
                'reason' => $deterministicReason,
            ],
            // JSON Schema prêt à consommer par le frontend.
            'schema' => $this->formSchema($block, $options),
        ];
    }

    /**
     * Construit les questions pour tous les blocs ambigus d'un document.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, array<string, mixed>>
     */
    public function buildQuestions(array $blocks): array
    {
        return array_map(
            fn (Block $block): array => $this->buildQuestion($block),
            $blocks
        );
    }

    /**
     * Résumé lisible pour l'interface : « 3 blocs à confirmer ».
     *
     * @param  array<int, Block>  $blocks
     * @return array{count: int, summary: string, blocks: array<int, string>}
     */
    public function summary(array $blocks): array
    {
        $count = count($blocks);

        $summary = match (true) {
            $count === 0 => 'Aucune clarification nécessaire.',
            $count === 1 => '1 passage à confirmer.',
            default => "{$count} passages à confirmer.",
        };

        return [
            'count' => $count,
            'summary' => $summary,
            'blocks' => array_map(static fn (Block $block): string => $block->blockId, $blocks),
        ];
    }

    /**
     * Détermine le contexte de la question à partir du bloc.
     *
     * @return string clé de `OPTIONS_BY_CONTEXT`
     */
    private function contextOf(Block $block): string
    {
        // Un texte portant un mot-clé de légende sans numéro exploitable.
        if (preg_match('/^(Légende|Figure|Tableau|Annexe|Planche)\b/iu', $block->text) === 1) {
            return 'caption_category';
        }

        // Un bloc issu d'une image : figure numérotée ou image décorative ?
        if ($block->type === BlockType::Image || $block->type === BlockType::Figure) {
            return 'figure_or_image';
        }

        // Un bloc structuré en tableau mais mal détecté.
        if ($block->tableData !== null) {
            return 'table_or_text';
        }

        // Cas par défaut, de loin le plus fréquent (752 titres contradictoires
        // sur les documents du projet) : titre ou paragraphe ?
        return 'heading_or_paragraph';
    }

    /**
     * Formule la question, en la contextualisant.
     */
    private function questionFor(Block $block, string $context): string
    {
        $excerpt = mb_substr(trim($block->text), 0, 60);

        return match ($context) {
            'caption_category' => "« {$excerpt} » est-il une légende ? De quel élément ?",
            'figure_or_image' => "« {$excerpt} » est-il une figure numérotée ou une image décorative ?",
            'table_or_text' => "« {$excerpt} » est-il un tableau ou un paragraphe ?",
            default => $block->isBold
                ? "Ce texte en gras est-il un titre de section ou un paragraphe d'emphase ?\n« {$excerpt} »"
                : "Ce texte est-il un titre de section ou un paragraphe ?\n« {$excerpt} »",
        };
    }

    /**
     * JSON Schema du formulaire (§7).
     *
     * @param  array<int, string>  $options
     * @return array<string, mixed>
     */
    private function formSchema(Block $block, array $options): array
    {
        return [
            'type' => 'object',
            'title' => 'Clarification de structure',
            'properties' => [
                $block->blockId => [
                    'type' => 'string',
                    'title' => 'Type de ce passage',
                    'enum' => $options,
                ],
            ],
            'required' => [$block->blockId],
        ];
    }
}
