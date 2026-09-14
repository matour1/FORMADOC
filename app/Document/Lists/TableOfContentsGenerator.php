<?php

declare(strict_types=1);

namespace App\Document\Lists;

use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;

/**
 * Génération des listes de fin de document : table des matières et listes
 * dédiées (figures, tableaux, annexes, planches) — phase R5.3 à R5.7.
 *
 * **Zéro token.** Tout repose sur le JSON structurel déjà établi : les titres
 * ont leur niveau, les éléments numérotés ont leur numéro calculé en R4, et la
 * pagination vient de `PaginationCalculator`. Générer une table des matières ne
 * demande aucune intelligence — c'est une mise en forme de données existantes.
 *
 * **Ce que ce composant refuse de faire** — inventer un numéro de page. Si la
 * pagination est indisponible (LibreOffice absent), les entrées sont produites
 * **sans numéro** et le fait est signalé. Une TOC avec des numéros faux est
 * pire qu'une TOC sans numéros : elle enverrait le lecteur au mauvais endroit.
 * Le plan prévoit pour ce cas l'usage de champs Word natifs (`RenderCoordinator`).
 */
final class TableOfContentsGenerator
{
    /**
     * Profondeur maximale reprise dans la table des matières.
     *
     * Aligné sur `HeadingFormatter::MAX_LEVEL` : le gabarit gère trois niveaux,
     * et lister un niveau non stylé produirait une entrée sans repère visuel.
     */
    public const MAX_DEPTH = 3;

    /**
     * Titres du document qui ne doivent PAS figurer dans la table des matières.
     *
     * Ce sont les intitulés des listes elles-mêmes : Word ne les inclut pas, et
     * les inclure produirait « SOMMAIRE … 3 » dans le sommaire, visiblement faux.
     */
    private const TITRES_EXCLUS = [
        'SOMMAIRE',
        'TABLE DES MATIERES',
        'TABLE DES MATIÈRES',
        'LISTE DES FIGURES',
        'LISTE DES TABLEAUX',
        'LISTE DES ANNEXES',
        'LISTE DES PLANCHES',
        'TABLE DES ANNEXES',
    ];

    /**
     * Construit la table des matières.
     *
     * @param  StructuralDocument  $document  Document renuméroté (sortie de R4)
     * @param  array<string, int>  $pages  Pages par blockId (issues de `PaginationCalculator`)
     * @return array{
     *     entries: array<int, array{block_id: string, text: string, level: int, number: null|string, page: null|int, has_page: bool}>,
     *     truncated: int,
     *     has_pages: bool,
     *     count: int
     * }
     */
    public function generate(StructuralDocument $document, array $pages = []): array
    {
        $entrees = [];
        $tronques = 0;

        foreach ($document->headings() as $titre) {
            // Un titre sans texte produirait une entrée fantôme dans le
            // sommaire — une ligne sans intitulé, immédiatement suspecte.
            if (trim($titre->text) === '') {
                continue;
            }

            if ($this->estTitreExclu($titre->text)) {
                continue;
            }

            $niveau = $this->niveauEffectif($titre);

            if ($niveau > self::MAX_DEPTH) {
                // Un titre trop profond n'est pas listé — mais il est compté,
                // pour que le rapport qualite puisse le signaler.
                $tronques++;

                continue;
            }

            $page = $pages[$titre->blockId] ?? null;

            $entrees[] = [
                'block_id' => $titre->blockId,
                'text' => $titre->text,
                // Le numéro est celui calculé par R4 : une TOC doit citer le
                // même numéro que le corps du document.
                'number' => $titre->displayNumber(),
                'level' => $niveau,
                'page' => $page,
                'has_page' => $page !== null,
            ];
        }

        return [
            'entries' => $entrees,
            'truncated' => $tronques,
            'has_pages' => $this->toutesOntUnePage($entrees),
            'count' => count($entrees),
        ];
    }

    /**
     * Construit une liste dédiée à une catégorie (figures, tableaux, annexes, planches).
     *
     * Le contenu provient des **légendes** : c'est là que vit le libellé lisible
     * (« Figure 1 : Architecture de la plateforme »). Les porteurs sans légende
     * sont repris avec un libellé vide plutôt qu'omis — un élément non listé
     * serait invisible pour le lecteur, alors qu'un libellé manquant se voit et
     * se corrige.
     *
     * @param  StructuralDocument  $document  Document renuméroté
     * @param  BlockCategory  $category  Catégorie à lister
     * @param  array<string, int>  $pages  Pages par blockId
     * @return array{
     *     title: string,
     *     entries: array<int, array{block_id: string, number: null|string, label: string, page: null|int, has_page: bool, has_label: bool}>,
     *     count: int,
     *     has_pages: bool
     * }
     */
    public function generateCategory(
        StructuralDocument $document,
        BlockCategory $category,
        array $pages = [],
    ): array {
        $entrees = [];
        $porteursAvecLegende = [];

        // Les légendes d'abord : elles portent le libellé.
        foreach ($document->blocks as $block) {
            if ($block->type !== BlockType::Caption || $block->effectiveCategory() !== $category) {
                continue;
            }

            $libelle = $this->libelleDeLegende($block);

            if ($block->linkedBlockId !== null) {
                $porteursAvecLegende[$block->linkedBlockId] = true;
            }

            // La page lue est celle de la légende ; à défaut, celle du porteur
            // (les deux sont adjacents dans le document, mais mieux vaut une
            // page approchée qu'aucune).
            $page = $pages[$block->blockId]
                ?? ($block->linkedBlockId !== null ? ($pages[$block->linkedBlockId] ?? null) : null);

            $entrees[] = [
                'block_id' => $block->blockId,
                'number' => $block->displayNumber(),
                'label' => $libelle,
                'page' => $page,
                'has_page' => $page !== null,
                'has_label' => $libelle !== '',
            ];
        }

        // Puis les porteurs SANS légende rattachée : sans cette passe, un
        // tableau non légendé n'apparaîtrait dans aucune liste.
        foreach ($document->blocks as $block) {
            if ($block->type->category() !== $category || ! $block->type->isSelfNumbered()) {
                continue;
            }

            if (isset($porteursAvecLegende[$block->blockId])) {
                continue;
            }

            $page = $pages[$block->blockId] ?? null;

            $entrees[] = [
                'block_id' => $block->blockId,
                'number' => $block->displayNumber(),
                'label' => '',
                'page' => $page,
                'has_page' => $page !== null,
                'has_label' => false,
            ];
        }

        return [
            'title' => $category->listTitle(),
            'entries' => $entrees,
            'count' => count($entrees),
            'has_pages' => $this->toutesOntUnePage($entrees),
        ];
    }

    /**
     * Construit les quatre listes dédiées d'un document.
     *
     * Les catégories vides sont omises : une « LISTE DES PLANCHES » suivie de
     * rien est un défaut visible dans un mémoire, pas une preuve d'exhaustivité.
     *
     * @param  array<string, int>  $pages
     * @return array<int, array<string, mixed>>
     */
    public function generateAllCategories(StructuralDocument $document, array $pages = []): array
    {
        $listes = [];

        foreach (BlockCategory::all() as $categorie) {
            $liste = $this->generateCategory($document, $categorie, $pages);

            if ($liste['count'] > 0) {
                $listes[] = $liste;
            }
        }

        return $listes;
    }

    /**
     * Niveau effectif d'un titre.
     *
     * Un titre sans niveau est traité comme niveau 1 : c'est la lecture la plus
     * probable et la plus visible à la relecture.
     */
    private function niveauEffectif(Block $titre): int
    {
        if ($titre->headingLevel === null) {
            return 1;
        }

        return max(1, $titre->headingLevel);
    }

    /**
     * Le titre est-il l'intitulé d'une liste (à exclure du sommaire) ?
     */
    private function estTitreExclu(string $texte): bool
    {
        $normalise = mb_strtoupper(trim($texte));

        // Les marques de ponctuation finales sont retirées avant comparaison :
        // « LISTE DES FIGURES : » doit être reconnu comme « LISTE DES FIGURES ».
        $normalise = rtrim($normalise, " :.\t\n\r\0\x0B");

        return in_array($normalise, self::TITRES_EXCLUS, true);
    }

    /**
     * Libellé d'une légende, sans son numéro.
     *
     * Les listes affichent « Figure 1 : Architecture » : ne garder que la partie
     * descriptive évite « Figure 1 : Figure 1 — Architecture ».
     */
    private function libelleDeLegende(Block $legende): string
    {
        $texte = trim($legende->text);

        if ($texte === '') {
            return '';
        }

        // Préfixe « Figure 3 », « Tableau 2.1 », « ANNEXE IV » + séparateur.
        $motif = '/^(légende|legende|figure|tableau|annexe|planche|table)\s+[0-9IVXLCDMAB-Z]+(?:\.[0-9]+)*\s*[-–—:.]?\s*/iu';

        $nettoye = preg_replace($motif, '', $texte);

        return trim($nettoye === null ? $texte : $nettoye);
    }

    /**
     * Toutes les entrées ont-elles une page ?
     *
     * @param  array<int, array<string, mixed>>  $entrees
     */
    private function toutesOntUnePage(array $entrees): bool
    {
        if ($entrees === []) {
            return false;
        }

        foreach ($entrees as $entree) {
            if (! ($entree['has_page'] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
