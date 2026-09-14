<?php

declare(strict_types=1);

namespace App\Document\Structure;

use InvalidArgumentException;

/**
 * Bloc élémentaire du schéma JSON structurel commun.
 *
 * Objet IMMUABLE : toute modification passe par une méthode `with*()` qui
 * renvoie une nouvelle instance. Cette immuabilité est ce qui permet aux
 * garde-fous du chat (§9) de fonctionner : un snapshot est une simple copie
 * de la liste de blocs, sans risque d'aliasing.
 *
 * Correspond au schéma de `REFONTE_ARCHITECTURE.md` §6 :
 * un bloc porte son identifiant, son type, son texte, ses signaux structurels
 * (taille, gras, retrait, position) et, selon son type, des données
 * spécialisées (tableau, image, légende, numérotation, renvoi).
 */
final readonly class Block
{
    /**
     * Seuil de confiance à partir duquel la classification est appliquée
     * automatiquement, sans demander de clarification à l'utilisateur (§6).
     */
    public const AUTO_ACCEPT_THRESHOLD = 0.85;

    /**
     * @param  string  $blockId  Identifiant stable du bloc (b_001, b_002…)
     * @param  BlockType  $type  Type classifié du bloc
     * @param  string  $text  Texte brut du bloc (jamais généré, toujours classifié)
     * @param  null|int  $headingLevel  Niveau de titre (1 à 6), null si non-titre
     * @param  null|float  $fontSize  Taille de police en points (signal visuel)
     * @param  bool  $isBold  Gras (signal visuel)
     * @param  int  $indentLevel  Niveau de retrait / profondeur de liste
     * @param  null|float  $positionY  Position verticale estimée (signal de tri)
     * @param  Fidelity  $fidelity  Fidélité de la structure (exacte ou reconstruite)
     * @param  float  $confidence  Confiance de la classification (0 à 1)
     * @param  null|string  $linkedBlockId  Bloc lié : légende → figure, renvoi → cible
     * @param  null|TableData  $tableData  Données du tableau, contenu jamais reformulé
     * @param  null|string  $imageRef  Référence de l'image (img_007.png)
     * @param  null|BlockCategory  $category  Catégorie de numérotation (légende, renvoi)
     * @param  null|string  $originalNumber  Numéro saisi dans le document (jamais fiable)
     * @param  null|string  $finalNumber  Numéro recalculé par le système
     * @param  null|CrossRef  $crossRef  Détail du renvoi croisé (type cross_ref)
     */
    public function __construct(
        public string $blockId,
        public BlockType $type,
        public string $text = '',
        public ?int $headingLevel = null,
        public ?float $fontSize = null,
        public bool $isBold = false,
        public int $indentLevel = 0,
        public ?float $positionY = null,
        public Fidelity $fidelity = Fidelity::Exact,
        public float $confidence = 1.0,
        public ?string $linkedBlockId = null,
        public ?TableData $tableData = null,
        public ?string $imageRef = null,
        public ?BlockCategory $category = null,
        public ?string $originalNumber = null,
        public ?string $finalNumber = null,
        public ?CrossRef $crossRef = null,
    ) {
        if ($this->blockId === '') {
            throw new InvalidArgumentException('Un bloc doit porter un identifiant.');
        }

        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException(
                'La confiance doit être comprise entre 0 et 1.'
            );
        }

        if ($this->headingLevel !== null && $this->headingLevel < 1) {
            throw new InvalidArgumentException(
                'Le niveau de titre doit être supérieur ou égal à 1.'
            );
        }

        if ($this->type === BlockType::Table && $this->tableData === null) {
            throw new InvalidArgumentException(
                'Un bloc de type « table » doit porter ses données (tableData).'
            );
        }
    }

    /**
     * La classification est-elle assez fiable pour être appliquée sans question ?
     */
    public function isConfident(): bool
    {
        return $this->confidence >= self::AUTO_ACCEPT_THRESHOLD;
    }

    /**
     * Ce bloc déclenche-t-il une clarification utilisateur ciblée ?
     *
     * Règle §6 : sous le seuil, on demande à l'utilisateur — mais uniquement
     * sur ce bloc précis, jamais sur tout le document.
     */
    public function needsClarification(): bool
    {
        return ! $this->isConfident();
    }

    /**
     * Ce bloc est-il visuellement incertain du fait de sa source ?
     *
     * Un document reconstruit (PDF scanné) exige un aperçu avant/après avec
     * validation, MÊME si la classification est confiante : l'incertitude vient
     * de la source, pas de la classification.
     */
    public function requiresVisualReview(): bool
    {
        return $this->fidelity->requiresUserValidation();
    }

    /**
     * Le bloc porte-t-il un numéro calculé par le système ?
     */
    public function isNumbered(): bool
    {
        return $this->finalNumber !== null;
    }

    /**
     * Ce bloc consomme-t-il un numéro dans sa catégorie ?
     *
     * Uniquement les blocs numérotables (figure, table, annexe, planche). Une
     * légende ou un renvoi appartient à une catégorie mais ne consomme pas de
     * numéro : il référence celui d'un autre bloc.
     */
    public function isNumberable(): bool
    {
        return $this->type->isNumberable();
    }

    /**
     * Catégorie effective de numérotation du bloc.
     *
     * - Un bloc numérotable (figure, table, annexe, planche) tire sa catégorie
     *   de son type.
     * - Une légende ou un renvoi porte sa catégorie explicitement.
     */
    public function effectiveCategory(): ?BlockCategory
    {
        return $this->category ?? $this->type->category();
    }

    /**
     * Numéro à afficher : le numéro recalculé prime toujours sur l'original.
     *
     * `originalNumber` n'étant jamais fiable (trous, doublons), il ne sert que
     * de repli avant la passe de renumérotation.
     */
    public function displayNumber(): ?string
    {
        return $this->finalNumber ?? $this->originalNumber;
    }

    // -------------------------------------------------------------------------
    // Copies immuables
    // -------------------------------------------------------------------------

    public function withType(BlockType $type, ?float $confidence = null): self
    {
        return $this->copyWith([
            'type' => $type,
            'confidence' => $confidence ?? $this->confidence,
        ]);
    }

    public function withText(string $text): self
    {
        return $this->copyWith(['text' => $text]);
    }

    /**
     * Applique le résultat de la classification : type, confiance, niveau.
     */
    public function withClassification(BlockType $type, float $confidence, ?int $headingLevel = null): self
    {
        return $this->copyWith([
            'type' => $type,
            'confidence' => $confidence,
            'headingLevel' => $headingLevel ?? $this->headingLevel,
        ]);
    }

    public function withFinalNumber(?string $finalNumber): self
    {
        return $this->copyWith(['finalNumber' => $finalNumber]);
    }

    public function withOriginalNumber(?string $originalNumber): self
    {
        return $this->copyWith(['originalNumber' => $originalNumber]);
    }

    public function withCategory(?BlockCategory $category): self
    {
        return $this->copyWith(['category' => $category]);
    }

    public function withLinkedBlockId(?string $linkedBlockId): self
    {
        return $this->copyWith(['linkedBlockId' => $linkedBlockId]);
    }

    public function withCrossRef(?CrossRef $crossRef): self
    {
        return $this->copyWith(['crossRef' => $crossRef]);
    }

    public function withConfidence(float $confidence): self
    {
        return $this->copyWith(['confidence' => $confidence]);
    }

    public function withFidelity(Fidelity $fidelity): self
    {
        return $this->copyWith(['fidelity' => $fidelity]);
    }

    public function withTableData(?TableData $tableData): self
    {
        return $this->copyWith(['tableData' => $tableData]);
    }

    /**
     * Crée une copie en remplaçant uniquement les propriétés fournies.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function copyWith(array $overrides): self
    {
        return new self(
            blockId: $this->blockId,
            type: $overrides['type'] ?? $this->type,
            text: $overrides['text'] ?? $this->text,
            headingLevel: array_key_exists('headingLevel', $overrides)
                ? $overrides['headingLevel']
                : $this->headingLevel,
            fontSize: $this->fontSize,
            isBold: $this->isBold,
            indentLevel: $this->indentLevel,
            positionY: $this->positionY,
            fidelity: $overrides['fidelity'] ?? $this->fidelity,
            confidence: $overrides['confidence'] ?? $this->confidence,
            linkedBlockId: array_key_exists('linkedBlockId', $overrides)
                ? $overrides['linkedBlockId']
                : $this->linkedBlockId,
            tableData: array_key_exists('tableData', $overrides)
                ? $overrides['tableData']
                : $this->tableData,
            imageRef: $this->imageRef,
            category: array_key_exists('category', $overrides)
                ? $overrides['category']
                : $this->category,
            originalNumber: array_key_exists('originalNumber', $overrides)
                ? $overrides['originalNumber']
                : $this->originalNumber,
            finalNumber: array_key_exists('finalNumber', $overrides)
                ? $overrides['finalNumber']
                : $this->finalNumber,
            crossRef: array_key_exists('crossRef', $overrides)
                ? $overrides['crossRef']
                : $this->crossRef,
        );
    }

    // -------------------------------------------------------------------------
    // Sérialisation
    // -------------------------------------------------------------------------

    /**
     * Sérialisation vers le schéma JSON structurel commun (§6).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'block_id' => $this->blockId,
            'type' => $this->type->value,
            'text' => $this->text,
            'heading_level' => $this->headingLevel,
            'font_size' => $this->fontSize,
            'is_bold' => $this->isBold,
            'indent_level' => $this->indentLevel,
            'position_y' => $this->positionY,
            'fidelity' => $this->fidelity->value,
            'confidence' => $this->confidence,
            'linked_block_id' => $this->linkedBlockId,
            'table_data' => $this->tableData?->toArray(),
            'image_ref' => $this->imageRef,
            'category' => $this->category?->value,
            'original_number' => $this->originalNumber,
            'final_number' => $this->finalNumber,
            'cross_ref' => $this->crossRef?->toArray(),
        ];
    }

    /**
     * Reconstruit un bloc depuis le schéma JSON structurel.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tableData = $data['table_data'] ?? null;
        $crossRef = $data['cross_ref'] ?? null;
        $category = $data['category'] ?? null;
        $headingLevel = $data['heading_level'] ?? null;

        return new self(
            blockId: (string) ($data['block_id'] ?? ''),
            type: BlockType::fromString((string) ($data['type'] ?? BlockType::Paragraph->value)),
            text: (string) ($data['text'] ?? ''),
            headingLevel: $headingLevel === null ? null : (int) $headingLevel,
            fontSize: isset($data['font_size']) ? (float) $data['font_size'] : null,
            isBold: (bool) ($data['is_bold'] ?? false),
            indentLevel: (int) ($data['indent_level'] ?? 0),
            positionY: isset($data['position_y']) ? (float) $data['position_y'] : null,
            fidelity: Fidelity::tryFrom((string) ($data['fidelity'] ?? Fidelity::Exact->value))
                ?? Fidelity::Exact,
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : 1.0,
            linkedBlockId: isset($data['linked_block_id']) ? (string) $data['linked_block_id'] : null,
            tableData: is_array($tableData) ? TableData::fromArray($tableData) : null,
            imageRef: isset($data['image_ref']) ? (string) $data['image_ref'] : null,
            category: $category === null ? null : BlockCategory::fromString((string) $category),
            originalNumber: isset($data['original_number']) ? (string) $data['original_number'] : null,
            finalNumber: isset($data['final_number']) ? (string) $data['final_number'] : null,
            crossRef: is_array($crossRef) ? CrossRef::fromArray($crossRef) : null,
        );
    }
}
