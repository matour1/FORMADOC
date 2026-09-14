<?php

declare(strict_types=1);

namespace App\Document\Numbering;

use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;

/**
 * Renumérotation des éléments numérotés (phase R4.1–R4.3).
 *
 * **Principe** : les numéros du document d'origine ne sont jamais fiables. La
 * mesure du corpus (526 documents) le confirme — **98 documents portent des
 * doublons** (74 sur des tableaux, 21 sur des figures, 3 sur des annexes) et
 * **3 ont des trous**. Un seul style est retenu : chiffres arabes, 1, 2, 3…
 * et **chaque catégorie a son compteur indépendant qui repart à 1**.
 *
 * **Le modèle de données réel** — la mesure a montré un écart important avec
 * l'intuition. Un élément numéroté se compose de **deux blocs** :
 *
 * ```
 *   porteur (figure/table)  ← contient l'image ou le tableau, SANS numéro
 *   légende (caption)       ← « Figure 1 : … », porte le numéro, rattachée au porteur
 * ```
 *
 * Sur 526 documents : **861 légendes** pour seulement **318 porteurs**, et
 * `linked_block_id` est vide partout avant R4. Deux conséquences :
 *
 *  1. beaucoup de légendes n'ont **aucun porteur** — leur numéro n'existe qu'en
 *     elles ;
 *  2. un porteur peut avoir **plusieurs légendes** (cas observé sur le corpus).
 *
 * **Règle de comptage — une seule série par catégorie.** Le principe est unique
 * et rend la passe idempotente par construction :
 *
 *  - un **porteur** consomme un numéro **sauf** si une légende rattachée le
 *    représente — sinon l'élément serait numéroté deux fois ;
 *  - une **légende** (rattachée ou orpheline) consomme un numéro : c'est elle
 *    qui porte le numéro d'origine, et elle est visible à sa place dans le
 *    document. Le porteur reçoit ensuite ce numéro par réflexion.
 *
 * Un seul bloc consomme donc par élément numéroté. La direction de la réflexion
 * (légende → porteur) est ce qui rend la passe stable : un porteur peut avoir
 * **plusieurs légendes**, et faire autorité sur le porteur plutôt que sur la
 * légende ferait osciller le résultat d'une exécution à l'autre — le défaut
 * d'idempotence mesuré (24 documents) avant cette correction.
 *
 * **Ce que la passe fait des numéros d'origine** : elle les conserve dans
 * `originalNumber` et écrit le calcul dans `finalNumber`. `displayNumber()`
 * renvoie toujours le calculé en priorité — mais garder l'original permet de
 * dire à l'utilisateur « votre Tableau 7 est devenu le Tableau 1 », seule façon
 * de rendre la renumérotation vérifiable.
 */
final class NumberingPass
{
    /**
     * Nombre d'éléments par catégorie après renumérotation.
     *
     * @var array<string, int>
     */
    private array $perCategory = [];

    /**
     * Changements effectués (numéro d'origine → numéro calculé).
     *
     * @var array<int, array{block_id: string, category: string, original: null|string, final: string}>
     */
    private array $changes = [];

    /**
     * Nombre de numéros reflétés du porteur vers sa légende.
     */
    private int $propagated = 0;

    /**
     * Renumérote un document : comptage, écriture, puis réflexion sur la légende.
     *
     * Une seule passe de comptage, dans l'ordre du document : **c'est l'ordre qui
     * définit la numérotation**. Trier ou regrouper par type produirait des
     * numéros qui ne correspondent plus à la lecture.
     *
     * @return array{
     *     document: StructuralDocument,
     *     renumbered: int,
     *     propagated: int,
     *     per_category: array<string, int>,
     *     changes: array<int, array{block_id: string, category: string, original: null|string, final: string}>
     * }
     */
    public function run(StructuralDocument $document): array
    {
        $this->perCategory = [];
        $this->changes = [];
        $this->propagated = 0;

        $represented = $this->representedCarriers($document);

        // --- Passe 1 : comptage, une seule série par catégorie ---
        $compteurs = [];
        $assigned = [];

        foreach ($document->blocks as $block) {
            $category = $this->countingCategoryOf($block, $represented);

            if ($category === null) {
                continue;
            }

            $compteur = ($compteurs[$category->value] ?? 0) + 1;
            $compteurs[$category->value] = $compteur;
            $this->perCategory[$category->value] = $compteur;

            $numero = (string) $compteur;

            // Un numéro calculé identique à l'original n'est pas un changement :
            // on ne réécrit pas le bloc et on n'encombre pas le rapport.
            if ($block->originalNumber === $numero) {
                continue;
            }

            $assigned[] = $block->withFinalNumber($numero);

            $this->changes[] = [
                'block_id' => $block->blockId,
                'category' => $category->value,
                'original' => $block->originalNumber,
                'final' => $numero,
            ];
        }

        $document = $assigned === [] ? $document : $document->replaceBlocks($assigned);

        // --- Passe 2 : le porteur reflète le numéro de sa légende ---
        // La légende est la source du numéro (c'est elle qui a été comptée, et
        // c'est là que l'auteur a écrit le numéro) ; le porteur n'en a aucun
        // dans le corpus mesuré (0 sur 318). Sans cette passe, une figure
        // s'afficherait sans numéro alors que sa légende afficherait « Figure 1 »
        // juste en dessous.
        //
        // On retient la **première** légende rencontrée pour un porteur donné :
        // un porteur peut avoir plusieurs légendes, et faire autorité sur la
        // dernière rendrait le résultat sensible à un détail de parcours. Ce
        // choix fixe est aussi ce qui garantit l'idempotence.
        $numerosParPorteur = [];

        foreach ($document->blocks as $block) {
            if ($block->type !== BlockType::Caption || $block->linkedBlockId === null) {
                continue;
            }

            $numero = $block->displayNumber();

            if ($numero !== null) {
                $numerosParPorteur[$block->linkedBlockId] ??= $numero;
            }
        }

        $reflected = [];

        foreach ($document->blocks as $block) {
            if (! $block->type->isSelfNumbered()) {
                continue;
            }

            $numero = $numerosParPorteur[$block->blockId] ?? null;

            if ($numero === null || $block->finalNumber === $numero) {
                continue;
            }

            $reflected[] = $block->withFinalNumber($numero);
            $this->propagated++;
        }

        $document = $reflected === [] ? $document : $document->replaceBlocks($reflected);

        return [
            'document' => $document,
            'renumbered' => count($this->changes),
            'propagated' => $this->propagated,
            'per_category' => $this->perCategory,
            'changes' => $this->changes,
        ];
    }

    /**
     * Catégorie de comptage d'un bloc, ou null s'il ne consomme pas de numéro.
     *
     * Règle unique, qui garantit **une seule série de numéros par catégorie** :
     *
     *  - **porteur représenté par une légende** (le cas normal) → ne consomme
     *    rien : sa légende compte pour lui. Le numéro vient de la légende, qui
     *    est restée à sa place dans l'ordre de lecture — donc les numéros restent
     *    contigus et fidèles à ce que voit le lecteur.
     *  - **porteur sans légende** → consomme, sinon il n'aurait aucun numéro.
     *  - **légende rattachée** → consomme : c'est elle qui porte le numéro, et
     *    elle est visible à sa place dans le document.
     *  - **légende orpheline** → consomme : sur le corpus, 642 légendes sur 861
     *    sont dans ce cas, et leur numéro n'existe nulle part ailleurs.
     *
     * Un seul bloc consomme donc par élément numéroté, ce qui interdit tout
     * double comptage — la source des numéros dupliqués mesurés.
     *
     * @param  array<string, true>  $represented  Porteurs ayant au moins une légende rattachée
     */
    private function countingCategoryOf(Block $block, array $represented): ?BlockCategory
    {
        if ($block->type->isSelfNumbered()) {
            return isset($represented[$block->blockId]) ? null : $block->type->category();
        }

        if ($block->type !== BlockType::Caption) {
            return null;
        }

        return $block->effectiveCategory();
    }

    /**
     * Porteurs ayant au moins une légende rattachée.
     *
     * Un `array` associatif sert de test d'appartenance en temps constant : la
     * boucle de comptage est ainsi linéaire au lieu d'être quadratique.
     *
     * @return array<string, true>
     */
    private function representedCarriers(StructuralDocument $document): array
    {
        $represented = [];

        foreach ($document->blocks as $block) {
            if ($block->type === BlockType::Caption && $block->linkedBlockId !== null) {
                $represented[$block->linkedBlockId] = true;
            }
        }

        return $represented;
    }

    /**
     * Statistiques de la renumérotation, pour le rapport de traitement.
     *
     * @return array{per_category: array<string, int>, renumbered: int, propagated: int}
     */
    public function statistics(): array
    {
        return [
            'per_category' => $this->perCategory,
            'renumbered' => count($this->changes),
            'propagated' => $this->propagated,
        ];
    }

    /**
     * Numéros d'origine par catégorie, avant renumérotation.
     *
     * Sert à mesurer et à signaler les doublons et les trous du document source
     * — information utile à l'utilisateur, qui peut ainsi vérifier que la
     * renumérotation correspond bien à ce qu'il voyait.
     *
     * @return array<string, array<int, string>> [catégorie => [numéros d'origine]]
     */
    public function originalNumbers(StructuralDocument $document): array
    {
        $represented = $this->representedCarriers($document);
        $parCategorie = [];

        foreach ($document->blocks as $block) {
            $category = $this->countingCategoryOf($block, $represented);

            if ($category === null || $block->originalNumber === null) {
                continue;
            }

            $parCategorie[$category->value][] = $block->originalNumber;
        }

        return $parCategorie;
    }

    /**
     * Anomalies de numérotation d'origine (doublons et trous).
     *
     * Mesurées, jamais devinées : l'objectif est de donner à l'utilisateur la
     * raison exacte pour laquelle un numéro a changé.
     *
     * @return array{duplicates: array<string, array<int, string>>, gaps: array<string, array<int, int>>}
     */
    public function anomalies(StructuralDocument $document): array
    {
        $doublons = [];
        $trous = [];

        foreach ($this->originalNumbers($document) as $categorie => $numeros) {
            $compteur = array_count_values($numeros);

            foreach ($compteur as $numero => $occurrences) {
                if ($occurrences > 1) {
                    $doublons[$categorie][] = (string) $numero;
                }
            }

            // Trous : on ne compare que les numéros purement numériques.
            $entiers = array_values(array_filter(
                array_map('intval', $numeros),
                static fn (int $n): bool => $n > 0
            ));
            sort($entiers);

            if ($entiers !== [] && $entiers !== range(1, count($entiers))) {
                $trous[$categorie] = array_values(array_diff(range(1, count($entiers)), $entiers));
            }
        }

        return ['duplicates' => $doublons, 'gaps' => $trous];
    }
}
