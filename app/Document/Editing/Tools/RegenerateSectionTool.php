<?php

declare(strict_types=1);

namespace App\Document\Editing\Tools;

use App\Document\Editing\EditingException;
use App\Document\Editing\EditTool;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;

/**
 * Tool `regenerate_section` — réécrit une plage de blocs.
 *
 * **C'est le tool le plus dangereux**, et c'est pour cela qu'il porte le seuil de
 * confirmation le plus élevé : au-delà de 5 blocs, l'action exige un accord
 * explicite de l'utilisateur (§9.16). Le scénario attendu est précis — « un
 * `regenerate_section` sur 6 blocs demande confirmation » — et un seuil plus bas
 * rendrait le tool inutilisable pour son usage légitime (réécrire une section
 * entière de dix paragraphes), tandis qu'un seuil plus haut laisserait passer
 * des réécritures massives sans accord.
 *
 * **Deux protections de fond :**
 *  1. **confirmation** au-delà du seuil ;
 *  2. **snapshot obligatoire** avant application, puisque des blocs existants
 *     sont remplacés.
 *
 * **Ce que ce tool refuse de faire** — réécrire une plage qui contient un
 * tableau, une figure ou une légende. Ces blocs portent des données qui ne se
 * régénèrent pas : un tableau réécrit perdrait ses montants. On refuse
 * explicitement, en nommant le bloc fautif, plutôt que de le laisser disparaître.
 */
final class RegenerateSectionTool implements EditTool
{
    /**
     * Types de blocs qu'une régénération peut remplacer.
     *
     * Limité au texte : un titre se réécrit, un tableau de données non.
     */
    private const TYPES_REMPLACABLES = ['paragraph', 'heading'];

    public function name(): string
    {
        return 'regenerate_section';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    /**
     * Nombre de blocs que la plage couvre réellement.
     *
     * C'est cette valeur que l'orchestrateur compare au seuil de confirmation.
     * Elle est calculée par `rangeSize()`, qui retourne 0 si la plage est
     * invalide — l'orchestrateur refusera alors l'action.
     */
    public function affectedBlockCount(StructuralDocument $document, array $arguments): int
    {
        $debut = (string) ($arguments['start_block_id'] ?? '');
        $fin = (string) ($arguments['end_block_id'] ?? '');

        if ($debut === '' || $fin === '') {
            return 0;
        }

        return $document->rangeSize($debut, $fin);
    }

    /**
     * @param  array<string, mixed>  $arguments  {start_block_id, end_block_id, content?}
     * @return array{document: StructuralDocument, summary: string, details: array<string, mixed>}
     */
    public function apply(StructuralDocument $document, array $arguments): array
    {
        $debut = (string) ($arguments['start_block_id'] ?? '');
        $fin = (string) ($arguments['end_block_id'] ?? '');

        if ($document->blockById($debut) === null) {
            throw EditingException::blocIntrouvable($debut, $this->name());
        }

        if ($document->blockById($fin) === null) {
            throw EditingException::blocIntrouvable($fin, $this->name());
        }

        $taille = $document->rangeSize($debut, $fin);

        if ($taille === 0) {
            throw EditingException::plageInvalide($debut, $fin, $taille);
        }

        $this->refuserSiDonneesNonRegenerables($document, $debut, $fin);

        $remplacement = $this->blocsDeRemplacement($document, $arguments, $debut);

        if ($remplacement === []) {
            throw EditingException::sortieInvalide(
                $this->name(),
                'aucun contenu de remplacement fourni — utilisez delete_block pour une suppression ciblée'
            );
        }

        $document = $document->replaceRange($debut, $fin, $remplacement);

        return [
            'document' => $document,
            'summary' => "Section « {$debut} » → « {$fin} » régénérée "
                .'('.$taille.' bloc(s) remplacé(s) par '.count($remplacement).').',
            'details' => [
                'start_block_id' => $debut,
                'end_block_id' => $fin,
                'blocks_replaced' => $taille,
                'blocks_inserted' => count($remplacement),
                'blocks_before' => $document->count() - count($remplacement) + $taille,
                'blocks_after' => $document->count(),
            ],
        ];
    }

    /**
     * Refuse de régénérer une plage contenant des blocs à données.
     *
     * Un tableau, une figure ou une légende ne se « réécrit » pas : le contenu
     * serait perdu. Nommer le bloc fautif permet à l'utilisateur de cibler une
     * plage plus étroite au lieu de deviner pourquoi l'action a échoué.
     *
     * @throws EditingException
     */
    private function refuserSiDonneesNonRegenerables(
        StructuralDocument $document,
        string $debut,
        string $fin,
    ): void {
        $indexDebut = $document->indexOf($debut);
        $indexFin = $document->indexOf($fin);

        if ($indexDebut === null || $indexFin === null) {
            return;
        }

        for ($index = $indexDebut; $index <= $indexFin; $index++) {
            $bloc = $document->blocks[$index] ?? null;

            if ($bloc === null || in_array($bloc->type->value, self::TYPES_REMPLACABLES, true)) {
                continue;
            }

            throw new EditingException(
                "La plage « {$debut} » → « {$fin} » contient le bloc « {$bloc->blockId} » "
                ."de type « {$bloc->type->value} », dont les données ne peuvent pas être régénérées "
                .'(contenu de tableau, image, ou légende liée à une numérotation). '
                .'Restreignez la plage au texte, ou traitez ce bloc séparément.'
            );
        }
    }

    /**
     * Blocs de remplacement à partir du contenu fourni.
     *
     * Le contenu est accepté sous trois formes, toutes produites par le modèle
     * selon la formulation de l'utilisateur :
     *  - une chaîne : un paragraphe unique ;
     *  - une liste de chaînes : un paragraphe par entrée ;
     *  - une liste de structures `{type, text, heading_level}` : contrôle complet.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<int, Block>
     */
    private function blocsDeRemplacement(
        StructuralDocument $document,
        array $arguments,
        string $positionApres,
    ): array {
        $contenu = $arguments['content'] ?? $arguments['text'] ?? null;

        if ($contenu === null) {
            return [];
        }

        $elements = is_array($contenu) ? $contenu : [$contenu];
        $blocs = [];

        // Les identifiants continuent la série existante, comme pour une insertion.
        $maximum = $this->dernierNumero($document);
        $rang = 0;

        foreach ($elements as $element) {
            $rang++;

            if (is_scalar($element)) {
                $texte = trim((string) $element);

                if ($texte === '') {
                    continue;
                }

                $blocs[] = new Block(
                    blockId: sprintf('b_%04d', $maximum + $rang),
                    type: BlockType::Paragraph,
                    text: $texte,
                );

                continue;
            }

            if (! is_array($element)) {
                continue;
            }

            $texte = trim((string) ($element['text'] ?? $element['content'] ?? ''));

            if ($texte === '') {
                continue;
            }

            $type = (string) ($element['type'] ?? 'paragraph');
            $niveau = isset($element['heading_level']) ? (int) $element['heading_level'] : null;

            $blocs[] = new Block(
                blockId: sprintf('b_%04d', $maximum + $rang),
                type: $type === 'heading'
                    ? BlockType::Heading
                    : BlockType::Paragraph,
                text: $texte,
                // Le niveau est ramené dans l'intervalle géré par le gabarit.
                headingLevel: $type === 'heading' ? min(3, max(1, $niveau ?? 1)) : null,
            );
        }

        return $blocs;
    }

    /**
     * Numéro le plus élevé parmi les identifiants de blocs existants.
     */
    private function dernierNumero(StructuralDocument $document): int
    {
        $maximum = 0;

        foreach ($document->blocks as $bloc) {
            if (preg_match('/^b_(\d+)$/', $bloc->blockId, $captures) === 1) {
                $maximum = max($maximum, (int) $captures[1]);
            }
        }

        return $maximum;
    }
}
