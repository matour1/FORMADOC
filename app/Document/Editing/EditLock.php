<?php

declare(strict_types=1);

namespace App\Document\Editing;

use App\Models\DocumentEditLock;
use Illuminate\Support\Facades\Log;

/**
 * Verrou d'édition : un seul processus à la fois par document (garde-fou §9.8).
 *
 * **Pourquoi c'est indispensable** — deux traitements peuvent vouloir modifier le
 * même document : le pipeline automatique (classification, renumérotation) et le
 * chat d'édition. Sans verrou, ils liraient le même état, produiraient chacun
 * leur version, et la dernière écriture effacerait silencieusement le travail de
 * l'autre. L'utilisateur se retrouverait avec un document qui a « perdu » ses
 * corrections, sans message d'erreur.
 *
 * **Durée de vie** — le verrou expire (`stale_after`). Un verrou éternel serait
 * pire que pas de verrou : un processus interrompu (timeout, redémarrage)
 * bloquerait le document pour toujours. On préfère un risque de double
 * traitement après expiration à un document définitivement inaccessible.
 *
 * **Le libellé du verrou est hydraté dans les tests** : le modèle Eloquent est
 * utilisé tel quel, sans mock, pour que les requêtes soient réellement exercées.
 */
final class EditLock
{
    /**
     * Durée (secondes) au-delà de laquelle un verrou est considéré comme abandonné.
     *
     * Trois minutes : plus long que le traitement réel d'un document (mesuré à
     * 2,6 s pour l'ingestion, ~17 s pour la pagination), assez court pour qu'un
     * processus interrompu ne bloque pas longtemps.
     */
    public const STALE_AFTER = 180;

    /**
     * Tente de prendre le verrou d'un document.
     *
     * @param  int  $documentId  Document à verrouiller
     * @param  string  $owner  Identifiant du processus (« chat:12 », « pipeline », …)
     * @param  string  $reason  Motif lisible, affiché à l'utilisateur bloqué
     * @return null|string null si le verrou est acquis ; sinon le motif du blocage
     */
    public function acquire(int $documentId, string $owner, string $reason = ''): ?string
    {
        $existant = DocumentEditLock::where('document_id', $documentId)->first();

        if ($existant !== null) {
            // Verrou abandonné (processus interrompu) : on le libère plutôt que
            // de bloquer le document indéfiniment.
            if ($existant->isStale()) {
                Log::warning('Verrou d\'édition abandonné, libéré', [
                    'document_id' => $documentId,
                    'owner' => $existant->owner,
                    'age' => $existant->ageInSeconds(),
                ]);

                $existant->delete();
            } elseif ($existant->owner !== $owner) {
                // Déjà tenu par un AUTRE processus : c'est le cas que le verrou
                // doit interdire.
                return $existant->reason !== '' ? $existant->reason : 'traitement en cours';
            } else {
                // Même processus : le verrou est rafraîchi (un traitement long
                // ne doit pas expirer en cours de route).
                $existant->update([
                    'reason' => $reason,
                    'acquired_at' => now(),
                ]);

                return null;
            }
        }

        DocumentEditLock::create([
            'document_id' => $documentId,
            'owner' => $owner,
            'reason' => $reason,
            'acquired_at' => now(),
        ]);

        return null;
    }

    /**
     * Libère le verrou d'un document.
     *
     * Seul le propriétaire peut libérer : un processus ne doit pas relâcher le
     * verrou d'un autre, ce qui laisserait ce dernier croire qu'il travaille
     * encore en exclusivité.
     *
     * @return bool true si un verrou a été libéré
     */
    public function release(int $documentId, string $owner): bool
    {
        $supprimes = DocumentEditLock::where('document_id', $documentId)
            ->where('owner', $owner)
            ->delete();

        return $supprimes > 0;
    }

    /**
     * Libère le verrou quel qu'en soit le propriétaire.
     *
     * Réservé aux cas de nettoyage (commande artisan, intervention
     * administrative) : un processus normal ne doit pas pouvoir le faire.
     *
     * @return bool true si un verrou a été libéré
     */
    public function forceRelease(int $documentId): bool
    {
        return DocumentEditLock::where('document_id', $documentId)->delete() > 0;
    }

    /**
     * Le document est-il verrouillé par un autre processus ?
     */
    public function isLockedByOther(int $documentId, string $owner): bool
    {
        $verrou = DocumentEditLock::where('document_id', $documentId)->first();

        if ($verrou === null || $verrou->isStale()) {
            return false;
        }

        return $verrou->owner !== $owner;
    }

    /**
     * Verrou actif d'un document, ou null.
     */
    public function current(int $documentId): ?DocumentEditLock
    {
        $verrou = DocumentEditLock::where('document_id', $documentId)->first();

        if ($verrou !== null && $verrou->isStale()) {
            return null;
        }

        return $verrou;
    }

    /**
     * Exécute une action en tenant le verrou, et le libère quoi qu'il arrive.
     *
     * L'usage de `try/finally` est le point important : une exception en cours
     * de traitement ne doit pas laisser le document verrouillé. Sans cela, un
     * échec ponctuel bloquerait le document jusqu'à expiration du verrou.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     *
     * @throws EditingException Si le document est verrouillé par un autre processus
     */
    public function withLock(int $documentId, string $owner, callable $action, string $reason = '')
    {
        $blocage = $this->acquire($documentId, $owner, $reason);

        if ($blocage !== null) {
            // L'action transmise est le MOTIF métier, pas l'identifiant du
            // processus demandeur : l'utilisateur doit lire ce qui est en cours,
            // pas le nom interne d'un worker.
            throw EditingException::documentVerrouille($blocage, $reason !== '' ? $reason : $owner);
        }

        try {
            return $action();
        } finally {
            $this->release($documentId, $owner);
        }
    }

    /**
     * Verrous actifs, pour l'écran d'administration.
     *
     * @return array<int, DocumentEditLock>
     */
    public function activeLocks(): array
    {
        return DocumentEditLock::orderByDesc('acquired_at')
            ->get()
            ->filter(static fn (DocumentEditLock $verrou): bool => ! $verrou->isStale())
            ->all();
    }
}
