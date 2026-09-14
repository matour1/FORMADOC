<?php

declare(strict_types=1);

namespace App\Document\Lists;

use App\Document\Structure\StructuralDocument;

/**
 * Orchestrateur des listes et **verrou d'ordre** (phase R5.1, R5.9, R5.10).
 *
 * **La règle non négociable** : les numéros de page ne peuvent être établis
 * qu'**après** la mise en page du document complet. Or insérer une table des
 * matières **décale** la pagination de tout ce qui suit — le sommaire occupe lui
 * même plusieurs pages.
 *
 * D'où une séquence imposée, et vérifiée :
 *
 * ```
 *   1. paginate()  → rend le corps, lit la pagination réelle (LibreOffice/UNO)
 *   2. generate()  → produit les listes, avec les numéros lus à l'étape 1
 * ```
 *
 * Appeler `generate()` avant `paginate()` **lève une exception** au lieu de
 * produire des numéros faux. C'est un critère d'acceptation explicite de R5, et
 * la raison est pratique : une table des matières silencieusement fausse est
 * plus dommageable qu'une erreur explicite, car elle passe la relecture.
 *
 * **Coût : zéro token.** Les listes se déduisent du JSON structurel (titres,
 * numéros calculés en R4, pages lues en R5.2). C'est ce qui rend la phase
 * entièrement déterministe — un test d'architecture le verrouille.
 */
final class RenderCoordinator
{
    /**
     * État : le corps a-t-il été paginé ?
     */
    private bool $pagine = false;

    /**
     * Document paginé (corps rendu et mesuré).
     */
    private ?StructuralDocument $documentPagine = null;

    /**
     * Pages par identifiant de bloc, lues sur le rendu réel.
     *
     * @var array<string, int>
     */
    private array $pages = [];

    /**
     * Résultat brut du calcul de pagination.
     *
     * @var array<string, mixed>
     */
    private array $pagination = [];

    /**
     * Liste complète des générations effectuées (traçabilité).
     *
     * @var array<string, int>
     */
    private array $genere = [];

    public function __construct(
        private readonly TableOfContentsGenerator $generator = new TableOfContentsGenerator,
        private readonly PaginationCalculator $calculator = new PaginationCalculator,
        private readonly QualityReport $quality = new QualityReport,
    ) {}

    /**
     * Étape 1 — lit la pagination réelle du corps rendu.
     *
     * @param  StructuralDocument  $document  Document renuméroté (sortie de R4)
     * @param  string  $docxPath  Chemin du DOCX du corps (déjà rendu)
     * @param  array<string, mixed>  $options  Options (ex. `require_pagination`)
     * @return array<string, mixed> Résultat de la pagination
     *
     * @throws ListsGenerationException Si la pagination est exigée mais indisponible
     */
    public function paginate(
        StructuralDocument $document,
        string $docxPath,
        array $options = [],
    ): array {
        $this->pagination = $this->calculator->calculate($document, $docxPath);
        $this->documentPagine = $document;
        $this->pages = $this->pagination['blocks'] ?? [];
        $this->pagine = true;
        $this->genere = [];

        // La pagination est-elle exigée ? Par défaut non : un document sans
        // numéros de page reste utile, et exiger LibreOffice rendrait le
        // service indisponible sur une machine qui ne l'a pas.
        $exigee = (bool) ($options['require_pagination'] ?? false);

        if ($exigee && ! ($this->pagination['available'] ?? false)) {
            throw ListsGenerationException::paginationManquante(
                (string) ($this->pagination['reason'] ?? 'cause inconnue')
            );
        }

        return $this->pagination;
    }

    /**
     * Étape 2 — génère la table des matières et les quatre listes dédiées.
     *
     * @param  null|StructuralDocument  $document  Document paginé (utilise celui de `paginate()` si null)
     * @return array{
     *     table_of_contents: array<string, mixed>,
     *     category_lists: array<int, array<string, mixed>>,
     *     pagination: array<string, mixed>,
     *     quality: array<string, mixed>
     * }
     *
     * @throws ListsGenerationException Si `paginate()` n'a pas été appelé
     */
    public function generate(?StructuralDocument $document = null): array
    {
        $this->exigerPagination('generate');

        $document ??= $this->documentPagine;

        if ($document === null) {
            throw ListsGenerationException::renduNonEffectue('document absent');
        }

        $sommaire = $this->generator->generate($document, $this->pages);
        $this->genere = ['table_of_contents' => $sommaire['count']];

        $listes = $this->generator->generateAllCategories($document, $this->pages);

        foreach ($listes as $liste) {
            $this->genere['categorie:'.mb_strtolower((string) $liste['title'])] = (int) $liste['count'];
        }

        return [
            'table_of_contents' => $sommaire,
            'category_lists' => $listes,
            'pagination' => $this->pagination,
            'quality' => $this->quality->build($sommaire, $listes, $this->pagination),
        ];
    }

    /**
     * Génère uniquement la table des matières.
     *
     * @throws ListsGenerationException Si `paginate()` n'a pas été appelé
     */
    public function tableOfContents(): array
    {
        $this->exigerPagination('tableOfContents');

        if ($this->documentPagine === null) {
            throw ListsGenerationException::renduNonEffectue('document absent');
        }

        return $this->generator->generate($this->documentPagine, $this->pages);
    }

    /**
     * Génère uniquement les listes dédiées.
     *
     * @throws ListsGenerationException Si `paginate()` n'a pas été appelé
     */
    public function categoryLists(): array
    {
        $this->exigerPagination('categoryLists');

        if ($this->documentPagine === null) {
            throw ListsGenerationException::renduNonEffectue('document absent');
        }

        return $this->generator->generateAllCategories($this->documentPagine, $this->pages);
    }

    /**
     * Le corps a-t-il été paginé ?
     */
    public function isPaginated(): bool
    {
        return $this->pagine;
    }

    /**
     * La pagination est-elle exploitable (LibreOffice disponible) ?
     *
     * Faux signifie : les listes seront produites **sans numéros de page**, et
     * le rapport qualité le signalera. Ce n'est pas une erreur — c'est une
     * dégradation explicite, préférable à des numéros inventés.
     */
    public function hasPagination(): bool
    {
        return (bool) ($this->pagination['available'] ?? false);
    }

    /**
     * Page lue pour un bloc donné, ou null.
     */
    public function pageOf(string $blockId): ?int
    {
        return $this->pages[$blockId] ?? null;
    }

    /**
     * Statistiques de génération.
     *
     * @return array{paginated: bool, pagination_available: bool, generated: array<string, int>}
     */
    public function statistics(): array
    {
        return [
            'paginated' => $this->pagine,
            'pagination_available' => $this->hasPagination(),
            'generated' => $this->genere,
        ];
    }

    /**
     * Vérifie que la pagination a bien été calculée avant toute génération.
     *
     * C'est le verrou d'ordre : il transforme une erreur silencieuse (numéros
     * faux) en erreur explicite et immédiatement corrigeable.
     *
     * @throws ListsGenerationException
     */
    private function exigerPagination(string $contexte): void
    {
        if (! $this->pagine) {
            throw ListsGenerationException::renduNonEffectue($contexte);
        }
    }
}
