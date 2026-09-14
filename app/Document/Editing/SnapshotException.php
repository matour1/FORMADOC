<?php

declare(strict_types=1);

namespace App\Document\Editing;

/**
 * Exception du gestionnaire de snapshots (§9.7).
 *
 * Distincte de `EditingException` parce qu'elle signale un problème
 * d'**infrastructure** (base indisponible) et non une règle métier. La
 * distinction compte : l'appelant peut décider de réessayer un échec
 * d'infrastructure, alors qu'une règle métier violée ne se réessaie pas.
 */
final class SnapshotException extends \RuntimeException
{
    /**
     * L'enregistrement de l'état antérieur a échoué.
     */
    public static function enregistrementImpossible(string $raison, \Throwable $cause): self
    {
        return new self(
            "Impossible d'enregistrer l'état antérieur du document ({$raison}) : "
            .$cause->getMessage()
            .'. L\'action a été refusée : sans possibilité d\'annulation, '
            .'une modification irréversible ne doit pas être appliquée.',
            0,
            $cause
        );
    }

    /**
     * Le contenu enregistré n'est pas relisible.
     */
    public static function snapshotIllisible(int $snapshotId, \Throwable $cause): self
    {
        return new self(
            "L'état antérieur #{$snapshotId} n'est pas relisible : ".$cause->getMessage()
            .'. La restauration est impossible ; le document courant reste inchangé.',
            0,
            $cause
        );
    }
}
