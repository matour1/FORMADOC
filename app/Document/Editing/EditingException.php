<?php

declare(strict_types=1);

namespace App\Document\Editing;

use Throwable;

/**
 * Exceptions du module d'édition (phase R6).
 *
 * Chaque fabrique correspond à un garde-fou du §9. Le message est rédigé pour
 * être affiché tel quel à l'utilisateur : il dit **ce qui s'est passé** et
 * **ce qu'il peut faire**, sans jargon interne.
 */
final class EditingException extends \RuntimeException
{
    /**
     * Un snapshot était requis avant une action destructive (§9.7).
     */
    public static function snapshotRequis(string $action): self
    {
        return new self(
            "L'action « {$action} » modifie le document de façon irréversible : "
            .'un état antérieur doit être enregistré au préalable pour permettre l\'annulation. '
            .'Aucune modification n\'a été appliquée.'
        );
    }

    /**
     * Un autre traitement édite déjà ce document (§9.8).
     */
    public static function documentVerrouille(string $raison, string $action): self
    {
        return new self(
            'Ce document est déjà en cours de modification'
            .($raison !== '' ? ' ('.$raison.')' : '')
            ." : l'action « {$action} » ne peut pas s'exécuter simultanément. "
            .'Deux modifications concurrentes produiraient un document incohérent — '
            .'attendez la fin du traitement en cours.'
        );
    }

    /**
     * Un outil hors liste blanche a été demandé (§9.14).
     */
    public static function outilNonAutorise(string $nom): self
    {
        $autorises = implode(', ', ToolWhitelist::names());

        return new self(
            "L'outil « {$nom} » ne fait pas partie des outils d'édition autorisés. "
            ."Outils disponibles : {$autorises}."
        );
    }

    /**
     * La sortie d'un outil ne respecte pas le schéma attendu (§9.9).
     */
    public static function sortieInvalide(string $outil, string $raison): self
    {
        return new self(
            "La réponse de l'outil « {$outil} » est inexploitable : {$raison}. "
            .'Le document n\'a pas été modifié.'
        );
    }

    /**
     * Un bloc visé n'existe pas.
     */
    public static function blocIntrouvable(string $blockId, string $outil): self
    {
        return new self(
            "L'outil « {$outil} » vise le bloc « {$blockId} », qui n'existe pas dans ce document. "
            .'Aucune modification n\'a été appliquée.'
        );
    }

    /**
     * Une plage de blocs est invalide (fin avant début, ou trop large).
     */
    public static function plageInvalide(string $debut, string $fin, int $taille): self
    {
        return new self(
            "La plage « {$debut} » → « {$fin} » est invalide"
            .($taille > 0 ? " (taille calculée : {$taille} bloc(s))" : '')
            .'. Vérifiez l\'ordre des blocs dans le document.'
        );
    }

    /**
     * Une action exige une confirmation de l'utilisateur (§9.16).
     */
    public static function confirmationRequise(string $action, int $taille, int $seuil): self
    {
        return new self(
            "L'action « {$action} » porte sur {$taille} blocs, au-delà du seuil de {$seuil}. "
            .'Une confirmation explicite de l\'utilisateur est nécessaire avant de l\'appliquer.'
        );
    }

    /**
     * Le gabarit fourni est inexploitable.
     */
    public static function gabaritInvalide(string $raison): self
    {
        return new self('Le gabarit de mise en forme est inutilisable : '.$raison);
    }

    /**
     * Fabrique générique pour un échec inattendu.
     */
    public static function echec(string $outil, Throwable $cause): self
    {
        return new self(
            "L'outil « {$outil} » a échoué : ".$cause->getMessage(),
            0,
            $cause
        );
    }
}
