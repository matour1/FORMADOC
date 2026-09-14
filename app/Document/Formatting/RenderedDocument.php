<?php

declare(strict_types=1);

namespace App\Document\Formatting;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\Fidelity;

/**
 * Description d'un document après application du gabarit.
 *
 * Contient les blocs **inchangés** (leur contenu n'est jamais modifié par la
 * mise en forme) et le style résolu de chacun. C'est le contrat entre le moteur
 * de gabarit déterministe et le générateur DOCX : le premier calcule les
 * styles, le second écrit le fichier.
 *
 * Cette séparation permet de **tester la mise en forme sans produire de
 * fichier** — et donc de vérifier que le contenu des tableaux reste identique
 * au caractère près.
 */
final readonly class RenderedDocument
{
    /**
     * @param  string  $documentId  Identifiant du document d'origine
     * @param  string  $sourceType  Format d'origine (`docx`, `gdocs`, `ocr`)
     * @param  array<int, Block>  $blocks  Blocs d'origine, jamais modifiés
     * @param  array<string, array<string, mixed>>  $styles  Styles résolus par blockId
     * @param  array<string, mixed>  $template  Gabarit normalisé appliqué
     * @param  Fidelity  $fidelity  Fidélité de la source
     * @param  array<string, mixed>  $meta  Métadonnées de rendu (statistiques, alertes)
     */
    public function __construct(
        public string $documentId,
        public string $sourceType,
        public array $blocks,
        public array $styles,
        public array $template,
        public Fidelity $fidelity,
        public array $meta = [],
    ) {}

    /**
     * Nombre de blocs rendus.
     */
    public function count(): int
    {
        return count($this->blocks);
    }

    /**
     * Style résolu d'un bloc.
     *
     * @return array<string, mixed> Tableau vide si le bloc est inconnu
     */
    public function styleOf(string $blockId): array
    {
        return $this->styles[$blockId] ?? [];
    }

    /**
     * Style résolu d'un bloc donné.
     *
     * @return array<string, mixed>
     */
    public function styleForBlock(Block $block): array
    {
        return $this->styleOf($block->blockId);
    }

    /**
     * Blocs d'un type donné, dans l'ordre du document.
     *
     * @return array<int, Block>
     */
    public function blocksOfType(BlockType $type): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn (Block $block): bool => $block->type === $type
        ));
    }

    /**
     * Le document exige-t-il une validation visuelle avant livraison ?
     *
     * Cas du PDF scanné : la fidélité est « reconstruite », donc jamais
     * garantie identique à l'original (§10, §14).
     */
    public function requiresVisualReview(): bool
    {
        return $this->fidelity->requiresUserValidation();
    }

    /**
     * Reconstruit la description avec un corrigé (post-traitement).
     *
     * @param  array<int, Block>  $blocks
     */
    public function withBlocks(array $blocks): self
    {
        return new self(
            documentId: $this->documentId,
            sourceType: $this->sourceType,
            blocks: $blocks,
            styles: $this->styles,
            template: $this->template,
            fidelity: $this->fidelity,
            meta: $this->meta,
        );
    }

    /**
     * Enrichit les métadonnées de rendu.
     */
    public function withMeta(string $key, mixed $value): self
    {
        $meta = $this->meta;
        $meta[$key] = $value;

        return new self(
            documentId: $this->documentId,
            sourceType: $this->sourceType,
            blocks: $this->blocks,
            styles: $this->styles,
            template: $this->template,
            fidelity: $this->fidelity,
            meta: $meta,
        );
    }

    /**
     * Résumé du rendu, pour le rapport de traitement.
     *
     * @return array{blocks: int, styled: int, headings: int, fidelity: string, template_font: string}
     */
    public function summary(): array
    {
        $headings = 0;
        foreach ($this->blocks as $block) {
            if ($block->headingLevel !== null) {
                $headings++;
            }
        }

        return [
            'blocks' => $this->count(),
            'styled' => count($this->styles),
            'headings' => $headings,
            'fidelity' => $this->fidelity->value,
            'template_font' => (string) ($this->template['police'] ?? 'inconnue'),
        ];
    }
}
