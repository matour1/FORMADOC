<?php

declare(strict_types=1);

namespace App\Document\Editing\Tools;

use App\Document\Editing\EditingException;
use App\Document\Editing\EditTool;
use App\Document\Structure\StructuralDocument;

/**
 * Tool `rewrite_paragraph` — remplace le texte d'un paragraphe existant.
 *
 * **Garde-fou important** — ce tool ne s'applique **jamais** à un tableau, une
 * figure ou une légende :
 *
 *  - réécrire le contenu d'une cellule de tableau corromprait une donnée
 *    chiffrée (§15 : « contenu des cellules jamais reformulé ») ;
 *  - modifier le texte d'une légende rendrait le numéro incohérent avec le
 *    renvoi qui l'utilise.
 *
 * Dans ces cas, on refuse avec un message explicite plutôt que de tenter une
 * adaptation : l'utilisateur doit savoir pourquoi son instruction n'a pas été
 * appliquée.
 */
final class RewriteParagraphTool implements EditTool
{
    /**
     * Types de blocs réécrivables.
     *
     * Limité volontairement au texte courant et aux titres : ce sont les seuls
     * blocs dont le texte n'a pas de fonction structurante ailleurs.
     */
    private const TYPES_REECRIVABLES = ['paragraph', 'heading'];

    public function name(): string
    {
        return 'rewrite_paragraph';
    }

    public function isDestructive(): bool
    {
        // Le texte antérieur est remplacé : sans snapshot, il serait perdu.
        // C'est pourquoi le texte d'origine est malgré tout conservé ci-dessous.
        return false;
    }

    public function affectedBlockCount(StructuralDocument $document, array $arguments): int
    {
        return 1;
    }

    /**
     * @param  array<string, mixed>  $arguments  {block_id, instruction|text}
     * @return array{document: StructuralDocument, summary: string, details: array<string, mixed>}
     */
    public function apply(StructuralDocument $document, array $arguments): array
    {
        $blockId = (string) ($arguments['block_id'] ?? '');
        $bloc = $document->blockById($blockId);

        if ($bloc === null) {
            throw EditingException::blocIntrouvable($blockId, $this->name());
        }

        if (! in_array($bloc->type->value, self::TYPES_REECRIVABLES, true)) {
            throw new EditingException(
                "Le bloc « {$blockId} » est de type « {$bloc->type->value} » : "
                .'son texte ne peut pas être réécrit, car il remplit une fonction structurante '
                .'(numérotation, données de tableau, référence d’image). '
                .'Utilisez un outil adapté à ce type de contenu.'
            );
        }

        $nouveauTexte = $this->texteDemande($arguments);

        if ($nouveauTexte === null) {
            throw EditingException::sortieInvalide(
                $this->name(),
                'aucun texte fourni (attendu : « text » ou « instruction »)'
            );
        }

        if (trim($nouveauTexte) === '') {
            throw EditingException::sortieInvalide(
                $this->name(),
                'le texte fourni est vide — utilisez delete_block pour supprimer un bloc'
            );
        }
        $document = $document->replaceBlock($bloc->withText($nouveauTexte));

        return [
            'document' => $document,
            'summary' => 'Paragraphe « '.$blockId.' » réécrit.',
            'details' => [
                'block_id' => $blockId,
                'before' => $bloc->text,
                'after' => $nouveauTexte,
                'before_length' => mb_strlen($bloc->text),
                'after_length' => mb_strlen($nouveauTexte),
            ],
        ];
    }

    /**
     * Texte demandé, quelle que soit la clé employée par le modèle.
     *
     * `instruction` et `text` sont acceptées : le modèle produit l'une ou
     * l'autre selon qu'il interprète le champ comme une consigne ou comme le
     * résultat. Refuser l'une des deux ferait échouer un appel correct sur le
     * fond — et le contenu produit par le modèle **est** le texte final, puisque
     * la génération a lieu dans la conversation, pas dans le tool.
     *
     * Une chaîne vide ou blanche est renvoyée telle quelle (et non convertie en
     * `null`) : c'est ce qui permet à l'appelant de distinguer « aucun texte
     * fourni » de « texte fourni mais vide », deux erreurs de gravité différente.
     */
    private function texteDemande(array $arguments): ?string
    {
        foreach (['text', 'content', 'instruction'] as $cle) {
            $valeur = $arguments[$cle] ?? null;

            if (is_string($valeur)) {
                return $valeur;
            }
        }

        return null;
    }
}
