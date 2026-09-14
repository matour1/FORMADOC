<?php

declare(strict_types=1);

namespace App\Document\Editing;

use App\Document\Formatting\DocumentFormatter;
use App\Document\Lists\ListsGenerationException;
use App\Document\Lists\RenderCoordinator;
use App\Document\Numbering\NumberingCoordinator;
use App\Document\Structure\StructuralDocument;

/**
 * Ré-export après édition, dans l'ordre imposé (garde-fou §9.12).
 *
 * **L'ordre n'est pas une préférence, c'est une contrainte de cohérence.**
 * Chaque étape produit une donnée dont la suivante dépend :
 *
 * ```
 *   1. gabarit        → les styles sont résolus (R3)
 *   2. renumérotation → les numéros sont recalculés APRÈS édition (R4)
 *   3. listes         → la TOC cite les numéros de l'étape 2 (R5)
 * ```
 *
 * Inverser deux étapes produirait un document faux :
 *
 *  - **listes avant renumérotation** → la table des matières citerait des numéros
 *    qui n'existent plus. Ce défaut est particulièrement vicieux : il ne se voit
 *    pas dans les métadonnées, seulement à la lecture.
 *  - **gabarit après renumérotation** → sans effet sur les numéros, mais un
 *    aller-retour inutile ; on garde l'ordre pour une raison plus forte :
 *    `RenderCoordinator::generate()` **exige** d'avoir été paginé, et la
 *    pagination se lit sur le document **final** (gabarit + numérotation).
 *
 * C'est donc la même logique que le verrou d'ordre de R5 : l'ordre est ce qui
 * empêche une incohérence silencieuse.
 *
 * **Deux modes**, alignés sur ceux de R5 :
 *  - par défaut, une pagination indisponible ne bloque pas : les listes sont
 *    produites sans numéros de page et le fait est signalé ;
 *  - `require_pagination` fait échouer le ré-export, pour un appelant qui exige
 *    des numéros exacts.
 */
final class ReExportCoordinator
{
    public function __construct(
        private readonly DocumentFormatter $formatter = new DocumentFormatter,
        private readonly NumberingCoordinator $numbering = new NumberingCoordinator,
        private readonly RenderCoordinator $lists = new RenderCoordinator,
    ) {}

    /**
     * Rejoue le pipeline complet après une édition.
     *
     * @param  StructuralDocument  $document  Document édité
     * @param  null|array<string, mixed>  $template  Gabarit de mise en forme
     * @param  null|string  $docxPath  Chemin du DOCX de pagination (null = pas de listes paginées)
     * @param  array<string, mixed>  $options  Options (`require_pagination`)
     * @return array{
     *     document: StructuralDocument,
     *     formatted: array<string, mixed>,
     *     numbering: array<string, mixed>,
     *     lists: null|array<string, mixed>,
     *     order: array<int, string>,
     *     error: null|string
     * }
     */
    public function reexport(
        StructuralDocument $document,
        ?array $template = null,
        ?string $docxPath = null,
        array $options = [],
    ): array {
        $ordre = [];

        // --- 1. Gabarit (R3) --------------------------------------------------
        // Exécuté en premier : la mise en forme ne dépend d'aucun numéro, et elle
        // porte les styles dont les étapes suivantes ne dépendent pas non plus —
        // mais l'ordre reste celui du plan, et le forcer ici évite qu'un futur
        // changement le rende implicite.
        $resultatGabarit = $this->formatter->format($document, $template, $options);
        $ordre[] = 'gabarit';

        // --- 2. Renumérotation (R4) -------------------------------------------
        // Après l'édition : c'est le point de tout le ré-export. Une édition peut
        // avoir supprimé un tableau ou inséré une figure, donc les numéros
        // calculés avant l'édition ne valent plus rien.
        $resultatNumerotation = $this->numbering->run($document);
        $document = $resultatNumerotation['document'];
        $ordre[] = 'renumerotation';

        // --- 3. Listes (R5) ----------------------------------------------------
        // En dernier, sur les numéros recalculés. Sans chemin de pagination, les
        // listes ne sont pas produites : elles seraient sans numéros de page, ce
        // qui reste possible mais doit être explicitement demandé.
        $resultatListes = null;

        if ($docxPath !== null) {
            try {
                $this->lists->paginate($document, $docxPath, $options);
                $resultatListes = $this->lists->generate($document);
                $ordre[] = 'listes';
            } catch (ListsGenerationException $e) {
                // La pagination était exigée et n'a pas pu être obtenue : on
                // remonte l'échec sans avoir produit des numéros faux.
                return [
                    'document' => $document,
                    'formatted' => $resultatGabarit['rendered']->summary(),
                    'numbering' => [
                        'per_category' => $resultatNumerotation['numbering']['per_category'],
                        'renumbered' => $resultatNumerotation['numbering']['renumbered'],
                    ],
                    'lists' => null,
                    'order' => $ordre,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'document' => $document,
            'formatted' => $resultatGabarit['rendered']->summary(),
            'numbering' => [
                'per_category' => $resultatNumerotation['numbering']['per_category'],
                'renumbered' => $resultatNumerotation['numbering']['renumbered'],
                'propagated' => $resultatNumerotation['numbering']['propagated'],
            ],
            'lists' => $resultatListes,
            'order' => $ordre,
            'error' => null,
        ];
    }

    /**
     * L'ordre d'exécution, exposé pour la documentation et les tests.
     *
     * Une constante plutôt qu'une chaîne écrite à plusieurs endroits : c'est ce
     * qui permet de vérifier que l'ordre réellement suivi est bien celui prévu.
     *
     * @return array<int, string>
     */
    public static function expectedOrder(): array
    {
        return ['gabarit', 'renumerotation', 'listes'];
    }

    /**
     * Résumé lisible du ré-export, pour l'affichage dans le chat.
     *
     * @param  array<string, mixed>  $resultat  Sortie de `reexport()`
     */
    public function summary(array $resultat): string
    {
        if (($resultat['error'] ?? null) !== null) {
            return 'Ré-export interrompu : '.(string) $resultat['error'];
        }

        $parties = ['Ré-export effectué'];

        $numeros = $resultat['numbering']['per_category'] ?? [];

        if ($numeros !== []) {
            $detail = [];

            foreach ($numeros as $categorie => $nombre) {
                $detail[] = $nombre.' '.$categorie;
            }

            $parties[] = 'numérotation mise à jour ('.implode(', ', $detail).')';
        } else {
            $parties[] = 'aucun élément numéroté';
        }

        if ($resultat['lists'] !== null) {
            $entrees = $resultat['lists']['table_of_contents']['count'] ?? 0;
            $parties[] = 'sommaire de '.$entrees.' entrée(s)';
        } else {
            $parties[] = 'listes non régénérées (pagination indisponible)';
        }

        return implode(' · ', $parties).'.';
    }
}
