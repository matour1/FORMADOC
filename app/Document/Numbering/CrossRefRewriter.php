<?php

declare(strict_types=1);

namespace App\Document\Numbering;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;

/**
 * Réécrit le texte des renvois avec le numéro calculé (phase R4.8).
 *
 * Un renvoi résolu ne suffit pas : le texte du paragraphe dit encore « voir
 * Figure 3 » alors que la figure est devenue « Figure 1 » après renumérotation.
 * Sans réécriture, le document serait en contradiction avec lui-même — le pire
 * défaut possible pour un mémoire, car il est visible à la lecture.
 *
 * **Périmètre volontairement étroit** : seuls les renvois **résolus** sont
 * réécrits, et seule **la portion correspondant au renvoi** est touchée. Le
 * reste du paragraphe est préservé au caractère près : ce composant ne réécrit
 * jamais une phrase, il remplace un numéro.
 *
 * C'est exactement le même principe que `TableRestyler` en R3 : la mise en forme
 * et la cohérence peuvent changer, le texte de l'auteur non.
 */
final class CrossRefRewriter
{
    /**
     * Réécrit les renvois de tous les blocs concernés.
     *
     * @return array{document: StructuralDocument, rewritten: int, details: array<int, array<string, string>>}
     */
    public function rewrite(StructuralDocument $document): array
    {
        $updates = [];
        $details = [];

        foreach ($document->blocks as $block) {
            $rewritten = $this->rewriteBlock($document, $block);

            if ($rewritten === null) {
                continue;
            }

            $updates[] = $rewritten;
            $details[] = [
                'block_id' => $block->blockId,
                'before' => $block->text,
                'after' => $rewritten->text,
            ];
        }

        return [
            'document' => $updates === [] ? $document : $document->replaceBlocks($updates),
            'rewritten' => count($updates),
            'details' => $details,
        ];
    }

    /**
     * Réécrit le renvoi d'un bloc, ou retourne null s'il n'y a rien à faire.
     */
    private function rewriteBlock(StructuralDocument $document, Block $block): ?Block
    {
        $crossRef = $block->crossRef;

        // Renvoi non résolu : on ne touche à rien. Réécrire avec un numéro
        // inventé serait pire que laisser le texte d'origine.
        if ($crossRef === null || ! $crossRef->isResolved()) {
            return null;
        }

        // Blocs porteurs d'un renvoi en ligne : paragraphes, titres et renvois
        // créés par les tools d'édition de R6.
        if (! in_array($block->type, [BlockType::Paragraph, BlockType::Heading, BlockType::CrossRef], true)) {
            return null;
        }

        $cible = $document->blockById((string) $crossRef->resolvedBlockId);

        if ($cible === null) {
            return null;
        }

        $nouveauNumero = $cible->displayNumber();

        if ($nouveauNumero === null || $nouveauNumero === $crossRef->targetOriginalNumber) {
            return null;
        }

        $texte = $this->replaceNumber(
            $block->text,
            $crossRef->matchedText,
            $crossRef->targetCategory->keyword(),
            $nouveauNumero,
        );

        if ($texte === $block->text) {
            return null;
        }

        return $block->withText($texte);
    }

    /**
     * Remplace le numéro dans le texte de renvoi.
     *
     * On reconstruit le motif à partir du mot-clé de la catégorie plutôt que de
     * chercher la chaîne exacte `matchedText` : le texte du document peut
     * contenir une casse différente (« voir la figure 3 »), et une recherche
     * littérale échouerait silencieusement.
     *
     * Toutes les occurrences du même renvoi dans le bloc sont remplacées : un
     * paragraphe qui cite deux fois la même figure doit rester cohérent.
     */
    private function replaceNumber(string $text, string $matchedText, string $keyword, string $nouveauNumero): string
    {
        // Motif du renvoi, insensible à la casse, limité à l'ancien numéro cité
        // pour ne pas toucher un numéro voisin d'une autre catégorie.
        $ancienNumero = $this->ancienNumeroDe($matchedText, $keyword);

        if ($ancienNumero === null) {
            return $text;
        }

        $motif = '/\b('.preg_quote($keyword, '/').')\s+'.preg_quote($ancienNumero, '/')."(?![0-9A-Za-z\p{L}'’])/iu";

        $remplace = preg_replace($motif, '$1 '.$nouveauNumero, $text);

        return $remplace ?? $text;
    }

    /**
     * Extrait l'ancien numéro cité dans un renvoi (« voir Figure 3 » → « 3 »).
     *
     * Le motif n'est PAS insensible à la casse sur le numéro : un numéro
     * alphabétique doit rester en majuscule, sinon « Tableau n°3 » serait lu
     * comme le numéro « n ». Le mot-clé, lui, tolère la casse.
     */
    private function ancienNumeroDe(string $matchedText, string $keyword): ?string
    {
        $motif = '/'.preg_quote($keyword, '/')."\s+(\d+|[A-Z])(?![0-9A-Za-z\p{L}'’])/iu";

        if (preg_match($motif, $matchedText, $captures) !== 1) {
            return null;
        }

        $numero = $captures[1];

        // Même garde-fou que la détection : un numéro alphabétique doit être en
        // majuscule, sinon on réécrirait un morceau de mot (« n », « D »…).
        if (preg_match('/^[a-z]$/', $numero) === 1) {
            return null;
        }

        return $numero;
    }

    /**
     * Statistiques de réécriture, pour le rapport de traitement.
     *
     * @param  array<int, array<string, string>>  $details
     * @return array{rewritten: int}
     */
    public function statistics(array $details): array
    {
        return ['rewritten' => count($details)];
    }
}
