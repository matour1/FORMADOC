<?php

declare(strict_types=1);

namespace App\Document\Editing\Tools;

use App\Document\Editing\EditingException;
use App\Document\Editing\EditTool;
use App\Document\Structure\StructuralDocument;

/**
 * Tool `delete_block` — supprime un bloc du document.
 *
 * **Tool destructif** : c'est celui qui exige un snapshot préalable sans
 * exception possible. Un modèle de langage peut très bien viser le mauvais bloc
 * en répondant à « supprime ce paragraphe », et l'utilisateur ne doit pas avoir
 * à regretter sa demande.
 *
 * **Deux protections complémentaires :**
 *  1. le snapshot (§9.7) est pris par l'orchestrateur **avant** l'appel ;
 *  2. une confirmation utilisateur est requise dès le premier bloc (§9.16),
 *     conformément au scénario attendu : « delete_block sur 1 bloc passe », mais
 *     l'action reste irréversible et mérite un accord explicite.
 *
 * **Ce que ce tool refuse de faire** — supprimer un bloc qui porte une donnée
 * introuvable ailleurs : une légende référencée par un renvoi, ou un porteur dont
 * la légende resterait orpheline. La suppression laisserait un renvoi pointant
 * dans le vide, défaut visible à la lecture et difficile à diagnostiquer.
 */
final class DeleteBlockTool implements EditTool
{
    public function name(): string
    {
        return 'delete_block';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function affectedBlockCount(StructuralDocument $document, array $arguments): int
    {
        return 1;
    }

    /**
     * @param  array<string, mixed>  $arguments  {block_id}
     * @return array{document: StructuralDocument, summary: string, details: array<string, mixed>}
     */
    public function apply(StructuralDocument $document, array $arguments): array
    {
        $blockId = (string) ($arguments['block_id'] ?? '');
        $bloc = $document->blockById($blockId);

        if ($bloc === null) {
            throw EditingException::blocIntrouvable($blockId, $this->name());
        }

        $this->refuserSiReference($document, $blockId);
        $this->refuserSiDernierBloc($document);

        $document = $document->removeBlock($blockId);
        $document = $this->detacherLegendeOrpheline($document, $blockId);

        return [
            'document' => $document,
            'summary' => 'Bloc « '.$blockId.' » ('.$bloc->type->value.') supprimé.',
            'details' => [
                'block_id' => $blockId,
                'type' => $bloc->type->value,
                'excerpt' => mb_substr($bloc->text, 0, 120),
                'blocks_before' => $document->count() + 1,
                'blocks_after' => $document->count(),
            ],
        ];
    }

    /**
     * Refuse la suppression d'un bloc visé par un renvoi croisé.
     *
     * Un renvoi (« voir Figure 3 ») pointerait vers un bloc disparu. Le
     * remplacer par rien produirait une phrase tronquée, plus gênante que le
     * refus de l'opération.
     *
     * @throws EditingException
     */
    private function refuserSiReference(StructuralDocument $document, string $blockId): void
    {
        foreach ($document->blocks as $bloc) {
            $renvoi = $bloc->crossRef;

            if ($renvoi !== null && $renvoi->resolvedBlockId === $blockId) {
                throw new EditingException(
                    "Le bloc « {$blockId} » est visé par un renvoi croisé dans « {$bloc->blockId} » "
                    .'(« '.$renvoi->matchedText.' »). Le supprimer laisserait ce renvoi sans cible. '
                    .'Supprimez ou corrigez d’abord le renvoi.'
                );
            }

            // Une légende rattachée désigne explicitement ce bloc : la supprimer
            // aussi serait une décision que l'utilisateur n'a pas prise.
            if ($bloc->type->value === 'caption' && $bloc->linkedBlockId === $blockId) {
                throw new EditingException(
                    "Le bloc « {$blockId} » est décrit par la légende « {$bloc->blockId} » "
                    .'(« '.mb_substr($bloc->text, 0, 80).' »). '
                    .'Supprimez la légende en premier, ou supprimez les deux dans un même message.'
                );
            }
        }
    }

    /**
     * Refuse de vider entièrement le document.
     *
     * Un document sans aucun bloc ne serait plus exportable ni analysable : c'est
     * un état sans retour utile. Le refus est préférable à un document vide que
     * l'utilisateur découvrirait en téléchargeant.
     *
     * @throws EditingException
     */
    private function refuserSiDernierBloc(StructuralDocument $document): void
    {
        if ($document->count() <= 1) {
            throw new EditingException(
                'Ce bloc est le dernier du document : le supprimer laisserait un document vide. '
                .'Créez d’abord le contenu de remplacement.'
            );
        }
    }

    /**
     * Détache les légendes du bloc supprimé.
     *
     * Si un porteur est supprimé alors que sa légende reste, la légende porte un
     * `linked_block_id` qui ne désigne plus rien. On la détache explicitement :
     * elle redevient une légende autonome (numérotée par R4), au lieu de pointer
     * vers un bloc fantôme.
     */
    private function detacherLegendeOrpheline(StructuralDocument $document, string $blockId): StructuralDocument
    {
        $misesAJour = [];

        foreach ($document->blocks as $bloc) {
            if ($bloc->linkedBlockId === $blockId) {
                $misesAJour[] = $bloc->withLinkedBlockId(null);
            }
        }

        return $misesAJour === [] ? $document : $document->replaceBlocks($misesAJour);
    }
}
