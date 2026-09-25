<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Surveillance du traitement documentaire.
 *
 * **Ce que cette surveillance PEUT mesurer.** Les documents sont traités en
 * synchrone dans le cycle de requête (aucun job en file), donc leur état est
 * lisible directement en base. On surveille ce qui bloque réellement : des
 * documents arrêtés à une étape, des passages en attente de décision, et la
 * répartition des pipelines.
 *
 * **Ce qu'elle ne peut PAS mesurer, et pourquoi c'est écrit ici.** Trois
 * indicateurs qu'on attendrait naturellement d'un écran de monitoring sont
 * absents, et les simuler serait pire que de ne rien afficher :
 *
 *  1. **La durée de traitement.** Il n'existe aucune colonne de jalons. Calculer
 *     `updated_at - created_at` donnerait un chiffre FAUX : `updated_at` bouge à
 *     chaque action de l'utilisateur (validation, réponse à un passage,
 *     téléchargement), pas à chaque étape technique. Mesuré sur les documents
 *     réels, ce calcul donne de 11 minutes à 3 jours et demi pour le même
 *     traitement — parce qu'il mesure aussi le temps où l'utilisateur n'a rien
 *     fait.
 *  2. **La progression d'un document en cours.** Aucun jalon n'est persisté, donc
 *     un pourcentage serait inventé. On affiche l'ÂGE, qui est une mesure vraie.
 *  3. **Le débit de la file d'attente.** Le pipeline est synchrone : aucune file
 *     n'est utilisée, donc il n'y a pas de débit à mesurer. On affiche tout de
 *     même l'état des tables `jobs` et `failed_jobs`, parce qu'un jour où le
 *     traitement passerait en asynchrone, un échec silencieux s'y logerait.
 *
 * **Les seuils sont annoncés comme des seuils.** « 43 jours » n'est pas en soi un
 * problème pour un document que l'utilisateur a déposé puis oublié. Le seuil
 * distingue ce qui demande une action de ce qui est simplement ancien — et il est
 * affiché pour que l'exploitant sache ce qui déclenche l'alerte, plutôt que de
 * découvrir un code couleur sans légende.
 */
class DocumentMonitoringService
{
    /**
     * Au-delà, un document inachevé demande une vérification.
     *
     * 3 jours : un traitement normal prend des minutes. Passé ce délai, soit le
     * document est bloqué, soit l'utilisateur a abandonné — dans les deux cas la
     * ligne vaut un coup d'œil.
     */
    public const SEUIL_ATTENTION_JOURS = 3;

    /**
     * Au-delà, le document est considéré comme abandonné.
     *
     * 14 jours : le dépôt n'a manifestement pas été poursuivi. Ce n'est pas
     * nécessairement une anomalie technique, mais c'est un signal commercial — un
     * utilisateur qui a déposé un document et ne l'a jamais repris.
     */
    public const SEUIL_ABANDON_JOURS = 14;

    /**
     * Nombre de passages au-delà duquel un document signale un défaut de
     * classification.
     *
     * 30 est déjà élevé : l'utilisateur doit confirmer chaque passage à la main.
     * Au-delà, le document est inexploitable en pratique — la classification a
     * échoué, même si elle a « réussi » techniquement.
     */
    public const SEUIL_PASSAGES_EXCESSIFS = 30;

    /**
     * Répartition des documents par statut, avec leur âge.
     *
     * L'âge se compte depuis `updated_at` et non `created_at` : un document
     * récemment déposé mais jamais touché et un document ancien oublié n'ont pas la
     * même signification.
     *
     * **Le calcul d'âge est fait en PHP, pas en SQL.** `DATEDIFF()` et `NOW()` sont
     * propres à MySQL : la suite de tests tourne sur SQLite en mémoire, où ces
     * fonctions n'existent pas — l'écran renvoyait donc une erreur 500 DÈS qu'on
     * l'ouvrait dans un test. Or c'est précisément en test qu'un écran
     * d'exploitation doit rester utilisable, sinon rien ne peut être vérifié.
     *
     * Le coût est nul : une seule colonne est lue, et le nombre de documents par
     * statut se compte en dizaines. Compter sur la base ferait gagner quelques
     * microsecondes au prix d'une requête qui ne fonctionne que sur un moteur.
     *
     * @return array<int, array{
     *     status: string, total: int, age_moyen: float, age_max: int,
     *     attention: int, abandonnes: int
     * }>
     */
    public function documentsParStatut(): array
    {
        $maintenant = now();

        $documents = Document::query()
            ->select(['status', 'updated_at'])
            ->get();

        return $documents
            ->groupBy('status')
            ->map(function ($groupe, $statut) use ($maintenant): array {
                // `diffInDays` renvoie une valeur absolue ; on garde le sens « âge »
                // en calculant depuis la date de référence.
                $ages = $groupe
                    ->map(fn (Document $d): int => $d->updated_at === null
                        ? 0
                        : (int) $d->updated_at->diffInDays($maintenant))
                    ->all();

                $total = count($ages);

                return [
                    'status' => (string) $statut,
                    'total' => $total,
                    'age_moyen' => $total > 0 ? round(array_sum($ages) / $total, 1) : 0.0,
                    'age_max' => $total > 0 ? max($ages) : 0,
                    'attention' => count(array_filter($ages, fn (int $a): bool => $a >= self::SEUIL_ATTENTION_JOURS)),
                    'abandonnes' => count(array_filter($ages, fn (int $a): bool => $a >= self::SEUIL_ABANDON_JOURS)),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Documents les plus anciens, inachevés.
     *
     * Ce sont eux qui demandent une action. `ready` et `generated` sont exclus :
     * un document terminé n'est pas « bloqué » parce qu'il est ancien.
     *
     * @return Collection<int, object>
     */
    public function documentsBloques(int $limite = 15): Collection
    {
        return Document::query()
            ->whereNotIn('status', ['ready', 'generated'])
            ->orderBy('updated_at')
            ->limit($limite)
            ->get()
            ->map(fn (Document $d): object => (object) [
                'id' => $d->id,
                'filename' => $d->filename,
                'status' => $d->status,
                'created_at' => $d->created_at,
                'updated_at' => $d->updated_at,
                'age_jours' => $d->updated_at === null ? 0 : (int) $d->updated_at->diffInDays(now()),
            ]);
    }

    /**
     * Passages en attente de décision utilisateur.
     *
     * Un passage sans réponse n'est pas une anomalie en soi : c'est le principe
     * même de la clarification. Ce qui en est une, c'est un document qui en
     * accumule trop — l'utilisateur ne les traitera jamais, et son document reste
     * inexploitable. On expose donc les DEUX chiffres : le total, et le document
     * le plus chargé.
     *
     * @return array{
     *     total: int, documents: int, plus_charge: array{document_id: int, passages: int}|null,
     *     excessive: array<int, array{document_id: int, passages: int}>
     * }
     */
    public function clarificationsEnAttente(): array
    {
        $parDocument = DB::table('document_clarifications')
            ->whereNull('answered_at')
            ->selectRaw('document_id, COUNT(*) as passages')
            ->groupBy('document_id')
            ->orderByDesc('passages')
            ->get();

        $excessifs = $parDocument
            ->filter(fn ($l): bool => (int) $l->passages >= self::SEUIL_PASSAGES_EXCESSIFS)
            ->map(fn ($l): array => [
                'document_id' => (int) $l->document_id,
                'passages' => (int) $l->passages,
            ])
            ->values()
            ->all();

        return [
            'total' => (int) DB::table('document_clarifications')->whereNull('answered_at')->count(),
            'documents' => $parDocument->count(),
            'plus_charge' => $parDocument->isEmpty() ? null : [
                'document_id' => (int) $parDocument->first()->document_id,
                'passages' => (int) $parDocument->first()->passages,
            ],
            'excessive' => $excessifs,
        ];
    }

    /**
     * Répartition des pipelines réellement exercés.
     *
     * La colonne `pipeline` de `document_structures` vaut `native` pour la refonte
     * et `NULL` pour l'ancien chemin (PHPWord), qui ne la renseigne pas. C'est un
     * indicateur direct de la question « le nouveau pipeline est-il réellement
     * exercé en production ? » — à laquelle un écran de santé doit répondre, parce
     * qu'une configuration `auto` peut masquer un repli systématique sur l'ancien
     * chemin : tout fonctionne, mais rien de ce qui a été développé n'est testé.
     *
     * @return array{natif: int, historique: int, total: int, part_natif: float}
     */
    public function pipelines(): array
    {
        $natif = (int) DB::table('document_structures')->where('pipeline', 'native')->count();
        $historique = (int) DB::table('document_structures')->whereNull('pipeline')->count();
        $total = $natif + $historique;

        return [
            'natif' => $natif,
            'historique' => $historique,
            'total' => $total,
            'part_natif' => $total > 0 ? round($natif / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * État des files d'attente.
     *
     * Le traitement est synchrone aujourd'hui, donc ces tables devraient rester
     * vides. Les surveiller n'est pas décoratif : le jour où une étape passerait en
     * asynchrone, un échec s'y logerait sans que rien ne le signale ailleurs.
     *
     * @return array{en_attente: int, echoues: int, plus_ancien_echec: ?string}
     */
    public function fileAttente(): array
    {
        $plusAncien = DB::table('failed_jobs')->orderBy('failed_at')->value('failed_at');

        return [
            'en_attente' => (int) DB::table('jobs')->count(),
            'echoues' => (int) DB::table('failed_jobs')->count(),
            'plus_ancien_echec' => $plusAncien === null ? null : (string) $plusAncien,
        ];
    }

    /**
     * Points demandant une action, avec leur explication.
     *
     * Le même principe que les « points d'attention » du tableau de bord : une
     * alerte sans explication oblige à interpréter, et une interprétation erronée
     * mène à une action inutile — ou à ignorer l'alerte suivante.
     *
     * @return array<int, array{niveau: string, titre: string, detail: string}>
     */
    public function pointsDAttention(): array
    {
        $points = [];

        $statuts = collect($this->documentsParStatut());
        $abandonnes = (int) $statuts->sum('abandonnes');
        $enAttention = (int) $statuts->sum('attention');

        if ($abandonnes > 0) {
            $points[] = [
                'niveau' => 'info',
                'titre' => $abandonnes.' document(s) inachevé(s) depuis plus de '.self::SEUIL_ABANDON_JOURS.' jours',
                'detail' => 'Le dépôt n\'a pas été poursuivi. Ce n\'est pas nécessairement un défaut '
                    .'technique : c\'est un utilisateur qui a déposé un document et ne l\'a jamais '
                    .'repris. À traiter comme un signal commercial plutôt que comme une panne.',
            ];
        }

        $actifs = $enAttention - $abandonnes;

        if ($actifs > 0) {
            $points[] = [
                'niveau' => 'warning',
                'titre' => $actifs.' document(s) inachevé(s) entre '.self::SEUIL_ATTENTION_JOURS.' et '
                    .self::SEUIL_ABANDON_JOURS.' jours',
                'detail' => 'Un traitement normal prend des minutes. Cet intervalle est celui où le '
                    .'document est le plus probablement BLOQUÉ, plutôt qu\'abandonné.',
            ];
        }

        $clarifications = $this->clarificationsEnAttente();

        if ($clarifications['excessive'] !== []) {
            $plus = $clarifications['excessive'][0];
            $points[] = [
                'niveau' => 'warning',
                'titre' => 'Classification en échec sur le document '.$plus['document_id']
                    .' : '.$plus['passages'].' passages à confirmer',
                'detail' => 'Chaque passage doit être confirmé à la main par l\'utilisateur. Au-delà de '
                    .self::SEUIL_PASSAGES_EXCESSIFS.', le document est inexploitable en pratique — la '
                    .'classification a échoué même si elle s\'est terminée sans erreur.',
            ];
        }

        $pipelines = $this->pipelines();

        if ($pipelines['total'] > 0 && $pipelines['natif'] === 0) {
            $points[] = [
                'niveau' => 'warning',
                'titre' => 'Aucun document traité par le pipeline natif',
                'detail' => 'Les '.$pipelines['total'].' structures en base viennent de l\'ancien '
                    .'chemin (PHPWord). Si le pipeline natif est configuré en « auto », le repli est '
                    .'systématique : tout fonctionne, mais rien de ce qui a été développé n\'est exercé.',
            ];
        }

        $file = $this->fileAttente();

        if ($file['echoues'] > 0) {
            $points[] = [
                'niveau' => 'danger',
                'titre' => $file['echoues'].' tâche(s) en échec',
                'detail' => 'Le traitement est synchrone, donc la file devrait rester vide. Un échec '
                    .'enregistré ici signale soit un passage en asynchrone, soit un traitement qu\'on '
                    .'croyait terminé et qui ne l\'est pas.',
            ];
        }

        return $points;
    }
}
