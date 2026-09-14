<?php

declare(strict_types=1);

namespace App\Document\Lists;

/**
 * Exception levée quand une liste est demandée avant la pagination (phase R5.9).
 *
 * **Verrou d'ordre — critère d'acceptation explicite de R5** : « une tentative de
 * générer une liste avant le rendu final lève une exception ».
 *
 * La raison est concrète : les numéros de page n'existent qu'après la mise en
 * page du document complet. Générer une table des matières avant produirait des
 * numéros faux — ou, pire, une table des matières dont les numéros ne
 * correspondraient plus au corps du document après son insertion (chaque liste
 * ajoute des pages, donc **décale** tout ce qui suit).
 *
 * C'est cet effet de bord qui rend le verrou indispensable : insérer le sommaire
 * change la pagination du document qui le contient.
 */
final class ListsGenerationException extends \RuntimeException
{
    /**
     * Une liste a été demandée sans pagination disponible.
     */
    public static function paginationManquante(string $contexte = ''): self
    {
        $detail = $contexte === '' ? '' : ' (contexte : '.$contexte.')';

        return new self(
            'Impossible de générer les listes : la pagination n\'a pas été calculée'.$detail.'. '
            .'Les numéros de page dépendent de la mise en page réelle du document — '
            .'or l\'insertion des listes décale elle-même la pagination. '
            .'Appelez d\'abord `RenderCoordinator::paginate()`.'
        );
    }

    /**
     * Une liste a été demandée avant le rendu final du corps.
     */
    public static function renduNonEffectue(string $contexte = ''): self
    {
        $detail = $contexte === '' ? '' : ' (contexte : '.$contexte.')';

        return new self(
            'Impossible de générer les listes : la pagination n\'a pas encore été calculée'.$detail.'. '
            .'Les numéros de page se lisent sur un fichier réellement mis en page — '
            .'insérer un sommaire décale d\'ailleurs la pagination de tout ce qui suit. '
            .'Appelez d\'abord `RenderCoordinator::paginate()`.'
        );
    }
}
