<?php

declare(strict_types=1);

namespace App\Document\Lists;

/**
 * Rapport qualité de la génération des listes (phase R5.10).
 *
 * **Principe du projet** : signaler, jamais masquer. Une liste incomplète ou sans
 * numéro de page n'empêche pas la livraison, mais l'utilisateur doit le savoir —
 * sans quoi il découvrirait le défaut devant son jury.
 *
 * Le rapport distingue trois situations, de gravité croissante :
 *
 *  1. **pagination absente** : aucune page n'a pu être lue (LibreOffice
 *     indisponible). Les listes restent utilisables, avec des champs Word
 *     natifs à la place des numéros.
 *  2. **titre trop profond** : un titre au-delà du niveau 3 n'est pas listé.
 *     Perte volontaire, car un niveau non stylé produirait une entrée sans
 *     repère visuel.
 *  3. **libellé manquant** : un tableau sans légende apparaît dans la liste
 *     avec un libellé vide. L'élément est présent (donc non oublié) mais il
 *     mérite une légende.
 *
 * Aucune de ces situations n'est bloquante : le rapport accompagne, il ne
 * remplace pas.
 */
final class QualityReport
{
    /**
     * Construit le rapport.
     *
     * @param  array<string, mixed>  $tableOfContents  Sortie de `TableOfContentsGenerator::generate()`
     * @param  array<int, array<string, mixed>>  $categoryLists  Sorties de `generateAllCategories()`
     * @param  array<string, mixed>  $pagination  Sortie de `PaginationCalculator::calculate()`
     * @return array{
     *     has_warnings: bool,
     *     blocking: false,
     *     pagination_available: bool,
     *     pagination_reason: null|string,
     *     total_pages: int,
     *     toc_entries: int,
     *     total_entries: int,
     *     missing_pages: int,
     *     truncated_headings: int,
     *     entries_without_label: int,
     *     message: null|string,
     *     lists: array<int, array{title: string, count: int, has_pages: bool}>
     * }
     */
    public function build(
        array $tableOfContents,
        array $categoryLists = [],
        array $pagination = [],
    ): array {
        $entreesSommaire = $tableOfContents['entries'] ?? [];
        $tronques = (int) ($tableOfContents['truncated'] ?? 0);
        $paginationDisponible = (bool) ($pagination['available'] ?? false);

        $sansPage = 0;
        $sansLibelle = 0;
        $totalEntrees = count($entreesSommaire);
        $listes = [];

        foreach ($entreesSommaire as $entree) {
            if (! ($entree['has_page'] ?? false)) {
                $sansPage++;
            }
        }

        foreach ($categoryLists as $liste) {
            $listes[] = [
                'title' => (string) ($liste['title'] ?? ''),
                'count' => (int) ($liste['count'] ?? 0),
                'has_pages' => (bool) ($liste['has_pages'] ?? false),
            ];

            $totalEntrees += (int) ($liste['count'] ?? 0);

            foreach ($liste['entries'] ?? [] as $entree) {
                if (! ($entree['has_page'] ?? false)) {
                    $sansPage++;
                }

                if (($entree['has_label'] ?? true) === false) {
                    $sansLibelle++;
                }
            }
        }

        $avertissements = ! $paginationDisponible || $tronques > 0 || $sansLibelle > 0 || $sansPage > 0;

        return [
            'has_warnings' => $avertissements,
            // Marqueur explicite : ce rapport n'exige aucune action bloquante.
            'blocking' => false,
            'pagination_available' => $paginationDisponible,
            'pagination_reason' => $pagination['reason'] ?? null,
            'total_pages' => (int) ($pagination['total_pages'] ?? 0),
            'toc_entries' => count($entreesSommaire),
            'total_entries' => $totalEntrees,
            'missing_pages' => $sansPage,
            'truncated_headings' => $tronques,
            'entries_without_label' => $sansLibelle,
            'message' => $this->message($paginationDisponible, $pagination, $tronques, $sansLibelle, $sansPage),
            'lists' => $listes,
        ];
    }

    /**
     * Message lisible, non alarmiste et cumulatif.
     *
     * Formulation choisie : « à compléter », « signalé » — pas « erreur ». Une
     * liste sans numéro de page reste un document livrable.
     */
    private function message(
        bool $paginationDisponible,
        array $pagination,
        int $tronques,
        int $sansLibelle,
        int $sansPage,
    ): ?string {
        $parties = [];

        if (! $paginationDisponible) {
            $raison = (string) ($pagination['reason'] ?? 'cause inconnue');
            // La cause exacte est reprise : elle indique à l'utilisateur quoi
            // faire (installer LibreOffice, par exemple).
            $parties[] = 'numéros de page indisponibles : '.mb_substr($raison, 0, 120);
        } elseif ($sansPage > 0) {
            $parties[] = $sansPage === 1
                ? '1 entrée sans numéro de page'
                : $sansPage.' entrées sans numéro de page';
        }

        if ($tronques > 0) {
            $parties[] = $tronques === 1
                ? '1 titre de niveau trop profond non listé'
                : $tronques.' titres de niveau trop profond non listés';
        }

        if ($sansLibelle > 0) {
            $parties[] = $sansLibelle === 1
                ? '1 élément listé sans légende — à compléter'
                : $sansLibelle.' éléments listés sans légende — à compléter';
        }

        if ($parties === []) {
            return null;
        }

        return ucfirst(implode(' · ', $parties)).'.';
    }

    /**
     * Le rapport contient-il un avertissement ?
     *
     * @param  array<string, mixed>  $report
     */
    public function hasWarnings(array $report): bool
    {
        return (bool) ($report['has_warnings'] ?? false);
    }

    /**
     * Résumé textuel pour l'affichage.
     *
     * @param  array<string, mixed>  $report
     */
    public function summary(array $report): string
    {
        $parties = [];

        $sommaire = (int) ($report['toc_entries'] ?? 0);

        if ($sommaire > 0) {
            $parties[] = $sommaire.' titre'.($sommaire > 1 ? 's' : '').' au sommaire';
        }

        foreach ($report['lists'] ?? [] as $liste) {
            $nombre = (int) ($liste['count'] ?? 0);

            if ($nombre > 0) {
                $parties[] = $nombre.' élément'.($nombre > 1 ? 's' : '').' dans « '.$liste['title'].' »';
            }
        }

        if ($parties === []) {
            return 'Aucune liste générée.';
        }

        $total = (int) ($report['total_pages'] ?? 0);
        $suffixe = $total > 0 ? ' (document de '.$total.' page'.($total > 1 ? 's' : '').')' : '';

        return implode(', ', $parties).$suffixe.'.';
    }
}
