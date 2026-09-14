<?php

declare(strict_types=1);

namespace App\Document\Editing\Tools;

use App\Document\Editing\EditingException;
use App\Document\Editing\EditTool;
use App\Document\Editing\ToolOutputValidator;
use App\Document\Editing\ToolWhitelist;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;

/**
 * Tool `insert_block` — insère un nouveau bloc après un bloc existant.
 *
 * **Point d'attention** : l'insertion se fait **après** un bloc identifié, et si
 * ce bloc est introuvable, le bloc est ajouté en fin de document. Ce choix est
 * délibéré et vient de `StructuralDocument::insertAfter()` : l'instruction vient
 * d'un modèle de langage, et refuser l'insertion entière pour un identifiant
 * approximatif serait plus frustrant que de placer le contenu — l'utilisateur
 * voit où il a atterri et peut le déplacer.
 *
 * **Types insérables** : `BlockType::insertable()` fait autorité. Un tableau
 * inséré doit porter ses données, un titre doit porter son niveau : les
 * validations correspondantes sont faites ici, pas dans le validateur générique,
 * car elles dépendent du type choisi.
 */
final class InsertBlockTool implements EditTool
{
    public function __construct(
        private readonly ToolOutputValidator $validator = new ToolOutputValidator,
    ) {}

    public function name(): string
    {
        return 'insert_block';
    }

    public function isDestructive(): bool
    {
        // Une insertion ne détruit rien : rien à annuler, le bloc ajouté peut
        // être supprimé par `delete_block`.
        return false;
    }

    public function affectedBlockCount(StructuralDocument $document, array $arguments): int
    {
        return 1;
    }

    /**
     * @param  array<string, mixed>  $arguments  {position_block_id, type, content?, category?, heading_level?}
     * @return array{document: StructuralDocument, summary: string, details: array<string, mixed>}
     */
    public function apply(StructuralDocument $document, array $arguments): array
    {
        $positionId = (string) ($arguments['position_block_id'] ?? '');

        // La position doit exister : insérer « après un bloc » qui n'existe pas
        // n'a pas de sens. On refuse plutôt que d'insérer silencieusement en fin
        // de document — la différence serait invisible pour l'utilisateur.
        if ($document->blockById($positionId) === null) {
            throw EditingException::blocIntrouvable($positionId, $this->name());
        }

        $type = $this->validator->normalizeType($arguments['type'] ?? null);

        if ($type === null) {
            throw EditingException::sortieInvalide(
                $this->name(),
                'type de bloc absent ou inconnu'
            );
        }

        // Le tool vérifie lui-même qu'il sait construire ce type. S'en remettre
        // au seul validateur de l'orchestrateur le rendrait vulnérable à un
        // appel direct : un type non insérable produirait alors un paragraphe
        // silencieusement, et l'utilisateur croirait avoir inséré un tableau.
        if (! in_array($type, ToolWhitelist::insertableTypes(), true)) {
            throw new EditingException(
                "Le type « {$type} » ne peut pas être inséré dans le corps du document. "
                .'Types acceptés : '.implode(', ', ToolWhitelist::insertableTypes()).'.'
            );
        }

        $contenu = $this->contenuDemande($arguments);
        $bloc = $this->construireBloc($document, $type, $contenu, $arguments);

        $document = $document->insertAfter($positionId, $bloc);

        return [
            'document' => $document,
            'summary' => 'Bloc « '.$bloc->blockId.' » ('.$type.') inséré après « '.$positionId.' ».',
            'details' => [
                'block_id' => $bloc->blockId,
                'position_block_id' => $positionId,
                'type' => $type,
                'text_length' => mb_strlen($bloc->text),
            ],
        ];
    }

    /**
     * Construit le bloc à insérer, selon son type.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws EditingException Si une donnée obligatoire du type manque
     */
    private function construireBloc(
        StructuralDocument $document,
        string $type,
        string $contenu,
        array $arguments,
    ): Block {
        $blockId = $this->prochainBlockId($document);

        return match ($type) {
            'heading' => new Block(
                blockId: $blockId,
                type: BlockType::Heading,
                text: $contenu,
                // Sans niveau, on retient 1 : c'est le plus probable pour un
                // titre nouvellement inséré, et le plus visible à la relecture.
                headingLevel: $this->validator->normalizeHeadingLevel($arguments['heading_level'] ?? null) ?? 1,
            ),

            'table' => new Block(
                blockId: $blockId,
                type: BlockType::Table,
                text: (string) ($arguments['title'] ?? ''),
                tableData: $this->tableData($arguments),
            ),

            'caption' => new Block(
                blockId: $blockId,
                type: BlockType::Caption,
                text: $contenu,
                category: $this->categorie($arguments),
            ),

            'figure', 'image' => new Block(
                blockId: $blockId,
                type: BlockType::fromString($type),
                text: $contenu,
                category: $type === 'figure' ? BlockCategory::Figure : null,
            ),

            'annexe' => new Block(
                blockId: $blockId,
                type: BlockType::Annexe,
                text: $contenu,
                category: BlockCategory::Annexe,
            ),

            'planche' => new Block(
                blockId: $blockId,
                type: BlockType::Planche,
                text: $contenu,
                category: BlockCategory::Planche,
            ),

            default => new Block(
                blockId: $blockId,
                type: BlockType::Paragraph,
                text: $contenu,
            ),
        };
    }

    /**
     * Données d'un tableau inséré.
     *
     * Un tableau sans données est refusé : `Block` lèverait de toute façon une
     * exception, mais avec un message technique. Ici, on explique quoi fournir.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws EditingException
     */
    private function tableData(array $arguments): TableData
    {
        $rows = $arguments['rows'] ?? null;

        if (! is_array($rows) || $rows === []) {
            throw EditingException::sortieInvalide(
                $this->name(),
                'un tableau doit fournir ses lignes dans « rows » (tableau de tableaux de textes)'
            );
        }

        // Normalisation en grille de chaînes : le modèle produit parfois des
        // nombres ou des valeurs nulles, qui ne sont pas du texte.
        $grille = [];

        foreach ($rows as $ligne) {
            if (! is_array($ligne)) {
                throw EditingException::sortieInvalide(
                    $this->name(),
                    'chaque ligne de « rows » doit être un tableau de cellules'
                );
            }

            $grille[] = array_map(
                static fn (mixed $cellule): string => is_scalar($cellule) ? (string) $cellule : '',
                array_values($ligne)
            );
        }

        // Les lignes de longueurs différentes produiraient une grille creuse :
        // on complète à la largeur maximale plutôt que de refuser, car c'est un
        // défaut de forme, pas de fond.
        $largeur = max(array_map('count', $grille));

        foreach ($grille as $index => $ligne) {
            while (count($grille[$index]) < $largeur) {
                $grille[$index][] = '';
            }
        }

        return TableData::fromGrid($grille);
    }

    /**
     * Catégorie d'une légende insérée.
     *
     * Sans catégorie exploitable, on retient Figure : une légende sans catégorie
     * n'apparaîtrait dans aucune liste, alors qu'une catégorie par défaut est
     * corrigeable par l'utilisateur.
     */
    private function categorie(array $arguments): BlockCategory
    {
        $valeur = $arguments['category'] ?? null;

        if (! is_string($valeur)) {
            return BlockCategory::Figure;
        }

        foreach (BlockCategory::all() as $categorie) {
            if (mb_strtolower($valeur) === mb_strtolower($categorie->keyword())
                || mb_strtolower($valeur) === $categorie->value) {
                return $categorie;
            }
        }

        return BlockCategory::Figure;
    }

    /**
     * Identifiant du prochain bloc, dans le format du pipeline (`b_0123`).
     *
     * Le format est conservé pour que la numérotation des identifiants reste
     * cohérente avec celle produite par l'ingestion — un identifiant hors format
     * compliquerait le tri et le débogage.
     */
    private function prochainBlockId(StructuralDocument $document): string
    {
        $maximum = 0;

        foreach ($document->blocks as $bloc) {
            if (preg_match('/^b_(\d+)$/', $bloc->blockId, $captures) === 1) {
                $maximum = max($maximum, (int) $captures[1]);
            }
        }

        return sprintf('b_%04d', $maximum + 1);
    }

    /**
     * Contenu textuel demandé, quelle que soit la clé employée.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function contenuDemande(array $arguments): string
    {
        foreach (['content', 'text', 'instruction'] as $cle) {
            $valeur = $arguments[$cle] ?? null;

            if (is_string($valeur)) {
                return $valeur;
            }
        }

        return '';
    }
}
