<?php

declare(strict_types=1);

namespace App\Document\Structure;

use App\Document\Exceptions\InvalidStructuralDocument;
use InvalidArgumentException;

/**
 * Document structurel : agrégat racine du schéma JSON commun.
 *
 * C'est LE format pivot du pipeline (REFONTE_ARCHITECTURE.md §5, §6). Aucun
 * module en aval (classification, gabarit, renumérotation, listes, édition,
 * facturation) ne connaît le format d'origine : tout passe par cet objet.
 *
 * Types de source : `docx` (natif), `gdocs` (export API), `ocr` (PDF scanné).
 */
final class StructuralDocument
{
    /** Version du schéma, incrémentée à chaque évolution de structure. */
    public const SCHEMA_VERSION = 1;

    /** Sources dont la structure native est préservée (fidélité garantie). */
    public const EXACT_SOURCES = ['docx', 'gdocs'];

    /**
     * Sources reconstruites (fidélité maximale visée, jamais garantie).
     */
    public const RECONSTRUCTED_SOURCES = ['ocr'];

    /**
     * @param  string  $documentId  Identifiant du document (correspond à Document::hash_id)
     * @param  string  $sourceType  Format d'origine : docx | gdocs | ocr
     * @param  array<int, Block>  $blocks  Blocs ordonnés du document
     * @param  array<string, mixed>  $meta  Métadonnées (gabarit, statistiques, alertes…)
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $sourceType,
        public array $blocks = [],
        public array $meta = [],
    ) {
        if ($this->documentId === '') {
            throw new InvalidArgumentException('Un document structurel doit porter un identifiant.');
        }

        if (! in_array($this->sourceType, [...self::EXACT_SOURCES, ...self::RECONSTRUCTED_SOURCES], true)) {
            throw new InvalidArgumentException(
                "Source inconnue : « {$this->sourceType} » "
                .'(attendu : docx, gdocs ou ocr).'
            );
        }

        foreach ($this->blocks as $block) {
            if (! $block instanceof Block) {
                throw new InvalidArgumentException(
                    'Tous les éléments de $blocks doivent être des instances de Block.'
                );
            }
        }
    }

    /**
     * Fidélité attendue pour ce type de source.
     *
     * Un `.docx` ou un Google Docs porte sa sémantique : la fidélité est exacte.
     * Un PDF scanné passe par l'OCR : la structure est reconstruite.
     */
    public function expectedFidelity(): Fidelity
    {
        return in_array($this->sourceType, self::EXACT_SOURCES, true)
            ? Fidelity::Exact
            : Fidelity::Reconstructed;
    }

    /**
     * Le document est-il issu d'une source reconstruite (PDF scanné) ?
     *
     * Implique un aperçu avant/après systématique avec validation utilisateur,
     * et interdit toute mention « identique à l'original ».
     */
    public function isReconstructed(): bool
    {
        return $this->expectedFidelity() === Fidelity::Reconstructed;
    }

    // -------------------------------------------------------------------------
    // Accès aux blocs
    // -------------------------------------------------------------------------

    /**
     * Tous les blocs d'un type donné.
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
     * Tous les blocs relevant d'une catégorie de numérotation.
     *
     * Inclut les blocs numérotables (figure, table, annexe, planche) ET ceux
     * qui portent la catégorie explicitement (légendes, renvois).
     *
     * @return array<int, Block>
     */
    public function blocksOfCategory(BlockCategory $category): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn (Block $block): bool => $block->effectiveCategory() === $category
        ));
    }

    /**
     * Blocs numérotables d'une catégorie (à renuméroter), dans l'ordre du document.
     *
     * @return array<int, Block>
     */
    public function numberableBlocks(BlockCategory $category): array
    {
        $type = $category->blockType();

        return $this->blocksOfType($type);
    }

    /**
     * Tous les titres, dans l'ordre du document (table des matières).
     *
     * @return array<int, Block>
     */
    public function headings(): array
    {
        return $this->blocksOfType(BlockType::Heading);
    }

    /**
     * Blocs dont la confiance est inférieure au seuil d'acceptation automatique.
     *
     * Ce sont les SEULS blocs envoyés au tool `detect_blocks` — c'est ce
     * pré-filtrage qui garantit l'économie de tokens (§6, contrainte budget).
     *
     * @return array<int, Block>
     */
    public function ambiguous(float $threshold = Block::AUTO_ACCEPT_THRESHOLD): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn (Block $block): bool => $block->confidence < $threshold
        ));
    }

    /**
     * Blocs exigeant une validation visuelle (source reconstruite).
     *
     * @return array<int, Block>
     */
    public function requiringVisualReview(): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn (Block $block): bool => $block->requiresVisualReview()
        ));
    }

    /**
     * Renvois croisés non résolus.
     *
     * Sensible à la PROPRIÉTÉ et non au seul type de bloc : un renvoi en ligne
     * est attaché au paragraphe qui le contient (`Block::$crossRef`), il n'est
     * pas un bloc à part. Créer un bloc distinct pour un renvoi en ligne
     * ferait apparaître le texte deux fois au rendu. Les blocs de type
     * `cross_ref` (créés par les tools d'édition) restent couverts.
     *
     * @return array<int, Block>
     */
    public function unresolvedCrossRefs(): array
    {
        return array_values(array_filter(
            $this->blocks,
            static function (Block $block): bool {
                $crossRef = $block->crossRef;

                return $crossRef !== null && ! $crossRef->isResolved();
            }
        ));
    }

    /**
     * Renvois croisés résolus avec une confiance faible (signalement discret).
     *
     * Ne bloque JAMAIS l'utilisateur : alimente le rapport de fin de traitement.
     *
     * @return array<int, Block>
     */
    public function lowConfidenceCrossRefs(float $threshold = CrossRef::LOW_CONFIDENCE_THRESHOLD): array
    {
        return array_values(array_filter(
            $this->blocks,
            static function (Block $block) use ($threshold): bool {
                $crossRef = $block->crossRef;

                return $crossRef !== null
                    && $crossRef->isResolved()
                    && ($crossRef->resolutionConfidence ?? 1.0) < $threshold;
            }
        ));
    }

    /**
     * Récupère un bloc par son identifiant.
     */
    public function blockById(string $blockId): ?Block
    {
        foreach ($this->blocks as $block) {
            if ($block->blockId === $blockId) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Index (position dans l'ordre du document) d'un bloc.
     *
     * Sert à la résolution par proximité : on associe un renvoi ambigu au
     * candidat le plus proche DANS L'ORDRE DU DOCUMENT (§10), jamais par
     * distance en caractères.
     *
     * @return null|int null si le bloc est introuvable
     */
    public function indexOf(string $blockId): ?int
    {
        foreach ($this->blocks as $index => $block) {
            if ($block->blockId === $blockId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Nombre total de blocs.
     */
    public function count(): int
    {
        return count($this->blocks);
    }

    /**
     * Le document est-il vide (aucun bloc) ?
     */
    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    // -------------------------------------------------------------------------
    // Mutations (renvoient une nouvelle instance)
    // -------------------------------------------------------------------------

    /**
     * Remplace un bloc par sa version modifiée, en conservant l'ordre.
     */
    public function replaceBlock(Block $block): self
    {
        $blocks = [];

        foreach ($this->blocks as $existing) {
            $blocks[] = $existing->blockId === $block->blockId ? $block : $existing;
        }

        return new self(
            documentId: $this->documentId,
            sourceType: $this->sourceType,
            blocks: $blocks,
            meta: $this->meta,
        );
    }

    /**
     * Remplace un lot de blocs d'un coup (efficace pour la renumérotation).
     *
     * @param  array<int, Block>  $blocks  Blocs modifiés (identifiés par blockId)
     */
    public function replaceBlocks(array $blocks): self
    {
        $replacements = [];
        foreach ($blocks as $block) {
            $replacements[$block->blockId] = $block;
        }

        $updated = [];
        foreach ($this->blocks as $existing) {
            $updated[] = $replacements[$existing->blockId] ?? $existing;
        }

        return new self(
            documentId: $this->documentId,
            sourceType: $this->sourceType,
            blocks: $updated,
            meta: $this->meta,
        );
    }

    /**
     * Insère un bloc juste après le bloc indiqué.
     *
     * Si le bloc de référence est introuvable, l'insertion se fait en fin de
     * document (comportement prévisible plutôt qu'une exception, car
     * l'instruction vient d'un modèle de langage).
     */
    public function insertAfter(string $positionBlockId, Block $block): self
    {
        $position = $this->indexOf($positionBlockId);

        if ($position === null) {
            $blocks = [...$this->blocks, $block];
        } else {
            $blocks = $this->blocks;
            array_splice($blocks, $position + 1, 0, [$block]);
        }

        return new self(
            documentId: $this->documentId,
            sourceType: $this->sourceType,
            blocks: $blocks,
            meta: $this->meta,
        );
    }

    /**
     * Supprime un bloc par son identifiant.
     */
    public function removeBlock(string $blockId): self
    {
        $blocks = array_values(array_filter(
            $this->blocks,
            static fn (Block $block): bool => $block->blockId !== $blockId
        ));

        return new self(
            documentId: $this->documentId,
            sourceType: $this->sourceType,
            blocks: $blocks,
            meta: $this->meta,
        );
    }

    /**
     * Remplace une plage de blocs (par identifiants de début et de fin inclus).
     *
     * Utilisé par `regenerate_section` : la plage est remplacée par les
     * nouveaux blocs fournis par le modèle.
     *
     * @param  array<int, Block>  $replacement
     */
    public function replaceRange(string $startBlockId, string $endBlockId, array $replacement): self
    {
        $start = $this->indexOf($startBlockId);
        $end = $this->indexOf($endBlockId);

        if ($start === null || $end === null || $end < $start) {
            throw InvalidStructuralDocument::invalidRange($startBlockId, $endBlockId);
        }

        $blocks = $this->blocks;
        array_splice($blocks, $start, ($end - $start) + 1, $replacement);

        return new self(
            documentId: $this->documentId,
            sourceType: $this->sourceType,
            blocks: array_values($blocks),
            meta: $this->meta,
        );
    }

    /**
     * Nombre de blocs d'une plage (pour le garde-fou de confirmation §9).
     */
    public function rangeSize(string $startBlockId, string $endBlockId): int
    {
        $start = $this->indexOf($startBlockId);
        $end = $this->indexOf($endBlockId);

        if ($start === null || $end === null || $end < $start) {
            return 0;
        }

        return ($end - $start) + 1;
    }

    /**
     * Ajoute ou remplace une métadonnée.
     */
    public function withMeta(string $key, mixed $value): self
    {
        $meta = $this->meta;
        $meta[$key] = $value;

        return new self(
            documentId: $this->documentId,
            sourceType: $this->sourceType,
            blocks: $this->blocks,
            meta: $meta,
        );
    }

    // -------------------------------------------------------------------------
    // Sérialisation
    // -------------------------------------------------------------------------

    /**
     * Sérialisation vers le schéma JSON structurel commun (§6).
     *
     * @return array{
     *     schema_version: int,
     *     document_id: string,
     *     source_type: string,
     *     fidelity: string,
     *     blocks: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'document_id' => $this->documentId,
            'source_type' => $this->sourceType,
            'fidelity' => $this->expectedFidelity()->value,
            'blocks' => array_map(
                static fn (Block $block): array => $block->toArray(),
                $this->blocks
            ),
            'meta' => $this->meta,
        ];
    }

    /**
     * Sérialisation JSON prête à persister.
     */
    public function toJson(int $flags = 0): string
    {
        $json = json_encode($this->toArray(), $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw InvalidStructuralDocument::unserializable(json_last_error_msg());
        }

        return $json;
    }

    /**
     * Reconstruit un document structurel depuis le schéma JSON commun.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidStructuralDocument Si la structure est invalide
     */
    public static function fromArray(array $data): self
    {
        $blocks = [];
        foreach ($data['blocks'] ?? [] as $blockData) {
            if (is_array($blockData)) {
                $blocks[] = Block::fromArray($blockData);
            }
        }

        return new self(
            documentId: (string) ($data['document_id'] ?? ''),
            sourceType: (string) ($data['source_type'] ?? 'docx'),
            blocks: $blocks,
            meta: is_array($data['meta'] ?? null) ? $data['meta'] : [],
        );
    }

    /**
     * Reconstruit un document structurel depuis une chaîne JSON.
     *
     * @throws InvalidStructuralDocument Si le JSON est illisible
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw InvalidStructuralDocument::unreadable(json_last_error_msg());
        }

        return self::fromArray($decoded);
    }

    /**
     * Le document stocké utilise-t-il une version de schéma connue ?
     */
    public static function isCompatible(array $data): bool
    {
        $version = $data['schema_version'] ?? null;

        return is_int($version) && $version <= self::SCHEMA_VERSION;
    }
}
