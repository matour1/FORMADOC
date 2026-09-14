<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Lists;

use App\Document\Lists\ListsGenerationException;
use App\Document\Lists\QualityReport;
use App\Document\Lists\RenderCoordinator;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests du verrou d'ordre et du rapport qualité.
 *
 * Le verrou est un **critère d'acceptation explicite de R5** : générer une liste
 * avant la pagination doit lever une exception. La raison est pratique — insérer
 * un sommaire **décale** la pagination du document qui le contient, donc des
 * numéros calculés trop tôt seraient faux.
 *
 * Ces tests utilisent un fichier inexistant : la pagination échouera
 * proprement, ce qui est exactement le comportement à vérifier (dégradation
 * explicite, jamais silencieuse).
 */
class RenderCoordinatorTest extends TestCase
{
    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: 'doc-1', sourceType: 'docx', blocks: $blocks);
    }

    private function heading(string $id, string $text, int $level = 1): Block
    {
        return new Block(blockId: $id, type: BlockType::Heading, text: $text, headingLevel: $level);
    }

    private function caption(string $id, BlockCategory $category, string $text, ?string $number = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $text,
            category: $category,
            finalNumber: $number,
        );
    }

    private function cheminInexistant(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'inexistant_'.uniqid().'.docx';
    }

    // -------------------------------------------------------------------------
    // Verrou d'ordre — critère d'acceptation R5.9
    // -------------------------------------------------------------------------

    public function test_generer_une_liste_avant_la_pagination_leve_une_exception(): void
    {
        // Critère d'acceptation : « une tentative de générer une liste avant le
        // rendu final lève une exception ».
        $this->expectException(ListsGenerationException::class);

        (new RenderCoordinator)->generate();
    }

    public function test_generer_la_table_des_matieres_avant_la_pagination_leve_une_exception(): void
    {
        $this->expectException(ListsGenerationException::class);

        (new RenderCoordinator)->tableOfContents();
    }

    public function test_generer_les_listes_dediees_avant_la_pagination_leve_une_exception(): void
    {
        $this->expectException(ListsGenerationException::class);

        (new RenderCoordinator)->categoryLists();
    }

    public function test_le_message_d_exception_explique_la_raison(): void
    {
        // Le message doit indiquer quoi faire, pas seulement que c'est interdit.
        try {
            (new RenderCoordinator)->generate();
            $this->fail('Une exception était attendue.');
        } catch (ListsGenerationException $e) {
            $this->assertStringContainsString('pagination', mb_strtolower($e->getMessage()));
            $this->assertStringContainsString('paginate', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Pagination indisponible → dégradation explicite
    // -------------------------------------------------------------------------

    public function test_une_pagination_indisponible_ne_bloque_pas_par_defaut(): void
    {
        // Par défaut, l'absence de pagination n'empêche pas la génération : les
        // listes sont produites sans numéros, et le manque est signalé. Exiger
        // LibreOffice rendrait le service indisponible sur une machine qui ne
        // l'a pas.
        $coordinateur = new RenderCoordinator;

        $pagination = $coordinateur->paginate(
            $this->document([$this->heading('b_001', 'Introduction')]),
            $this->cheminInexistant(),
        );

        $this->assertFalse($pagination['available']);
        $this->assertTrue($coordinateur->isPaginated());
        $this->assertFalse($coordinateur->hasPagination());
        $this->assertNotNull($pagination['reason']);
    }

    public function test_une_pagination_exigee_mais_indisponible_leve_une_exception(): void
    {
        // Mode strict : l'appelant qui a besoin de numéros justes doit pouvoir
        // l'exiger explicitement.
        $this->expectException(ListsGenerationException::class);

        (new RenderCoordinator)->paginate(
            $this->document([$this->heading('b_001', 'Introduction')]),
            $this->cheminInexistant(),
            ['require_pagination' => true],
        );
    }

    public function test_la_raison_de_l_indisponibilite_est_explicite(): void
    {
        $pagination = (new RenderCoordinator)->paginate(
            $this->document([]),
            $this->cheminInexistant(),
        );

        $this->assertStringContainsString('introuvable', mb_strtolower((string) $pagination['reason']));
    }

    // -------------------------------------------------------------------------
    // Génération après pagination
    // -------------------------------------------------------------------------

    public function test_apres_pagination_les_listes_sont_generees_sans_numeros(): void
    {
        $coordinateur = new RenderCoordinator;

        $coordinateur->paginate(
            $this->document([
                $this->heading('b_001', 'Introduction', 1),
                $this->caption('b_002', BlockCategory::Figure, 'Figure 1 : Architecture', '1'),
            ]),
            $this->cheminInexistant(),
        );

        $resultat = $coordinateur->generate();

        $this->assertArrayHasKey('table_of_contents', $resultat);
        $this->assertArrayHasKey('category_lists', $resultat);
        $this->assertArrayHasKey('quality', $resultat);

        $this->assertSame(1, $resultat['table_of_contents']['count']);
        $this->assertCount(1, $resultat['category_lists']);
    }

    public function test_le_rapport_signale_l_absence_de_numeros_de_page(): void
    {
        $coordinateur = new RenderCoordinator;
        $coordinateur->paginate($this->document([$this->heading('b_001', 'Introduction')]), $this->cheminInexistant());

        $resultat = $coordinateur->generate();

        $this->assertTrue($resultat['quality']['has_warnings']);
        $this->assertFalse($resultat['quality']['pagination_available']);
        $this->assertNotNull($resultat['quality']['message']);
        // Jamais bloquant, même en cas de dégradation : le document reste livrable.
        $this->assertFalse($resultat['quality']['blocking']);
    }

    public function test_le_rapport_compte_les_entrees_par_liste(): void
    {
        $coordinateur = new RenderCoordinator;

        $coordinateur->paginate(
            $this->document([
                $this->heading('b_001', 'Un', 1),
                $this->heading('b_002', 'Deux', 2),
                $this->caption('b_003', BlockCategory::Figure, 'Figure 1 : A', '1'),
                $this->caption('b_004', BlockCategory::Table, 'Tableau 1 : B', '1'),
            ]),
            $this->cheminInexistant(),
        );

        $rapport = $coordinateur->generate()['quality'];

        $this->assertSame(2, $rapport['toc_entries']);
        $this->assertCount(2, $rapport['lists']);
        $this->assertSame(4, $rapport['total_entries']);
    }

    public function test_le_rapport_signale_les_elements_sans_legende(): void
    {
        $coordinateur = new RenderCoordinator;

        $coordinateur->paginate(
            $this->document([
                new Block(
                    blockId: 'b_001',
                    type: BlockType::Table,
                    tableData: TableData::fromGrid([['A']]),
                    finalNumber: '1',
                ),
            ]),
            $this->cheminInexistant(),
        );

        $rapport = $coordinateur->generate()['quality'];

        $this->assertSame(1, $rapport['entries_without_label']);
        $this->assertStringContainsString('légende', mb_strtolower((string) $rapport['message']));
    }

    public function test_le_rapport_signale_les_titres_trop_profonds(): void
    {
        $coordinateur = new RenderCoordinator;

        $coordinateur->paginate(
            $this->document([$this->heading('b_001', 'Très profond', 6)]),
            $this->cheminInexistant(),
        );

        $rapport = $coordinateur->generate()['quality'];

        $this->assertSame(1, $rapport['truncated_headings']);
    }

    public function test_un_second_paginate_reinitialise_l_etat(): void
    {
        // La pagination peut être relancée après modification du document : la
        // génération précédente ne doit pas subsister.
        $coordinateur = new RenderCoordinator;

        $coordinateur->paginate($this->document([$this->heading('b_001', 'Un')]), $this->cheminInexistant());
        $coordinateur->generate();

        $coordinateur->paginate($this->document([$this->heading('b_002', 'Deux')]), $this->cheminInexistant());
        $resultat = $coordinateur->generate();

        $this->assertSame(1, $resultat['table_of_contents']['count']);
        $this->assertSame('Deux', $resultat['table_of_contents']['entries'][0]['text']);
    }

    public function test_les_statistiques_refletent_l_etat(): void
    {
        $coordinateur = new RenderCoordinator;

        $this->assertFalse($coordinateur->statistics()['paginated']);

        $coordinateur->paginate($this->document([$this->heading('b_001', 'Un')]), $this->cheminInexistant());
        $coordinateur->generate();

        $stats = $coordinateur->statistics();

        $this->assertTrue($stats['paginated']);
        $this->assertFalse($stats['pagination_available']);
        $this->assertArrayHasKey('table_of_contents', $stats['generated']);
    }

    public function test_page_of_retourne_null_sans_pagination(): void
    {
        $coordinateur = new RenderCoordinator;
        $coordinateur->paginate($this->document([]), $this->cheminInexistant());

        $this->assertNull($coordinateur->pageOf('b_001'));
    }

    // -------------------------------------------------------------------------
    // Rapport qualité seul
    // -------------------------------------------------------------------------

    public function test_le_resume_du_rapport_est_lisible(): void
    {
        $rapport = [
            'toc_entries' => 5,
            'total_pages' => 24,
            'lists' => [
                ['title' => 'LISTE DES FIGURES', 'count' => 3, 'has_pages' => true],
            ],
        ];

        $resume = (new QualityReport)->summary($rapport);

        $this->assertStringContainsString('5 titres au sommaire', $resume);
        $this->assertStringContainsString('3 éléments', $resume);
        $this->assertStringContainsString('24 pages', $resume);
    }

    public function test_un_resume_sans_entree_est_explicite(): void
    {
        $this->assertSame('Aucune liste générée.', (new QualityReport)->summary([]));
    }

    public function test_un_rapport_sans_avertissement_n_a_pas_de_message(): void
    {
        $rapport = (new QualityReport)->build(
            ['entries' => [['has_page' => true]], 'truncated' => 0],
            [['title' => 'LISTE DES FIGURES', 'count' => 1, 'has_pages' => true, 'entries' => [['has_page' => true, 'has_label' => true]]]],
            ['available' => true, 'total_pages' => 10],
        );

        $this->assertFalse($rapport['has_warnings']);
        $this->assertNull($rapport['message']);
    }

    public function test_has_warnings_reflete_le_rapport(): void
    {
        $qualite = new QualityReport;

        $this->assertFalse($qualite->hasWarnings(['has_warnings' => false]));
        $this->assertTrue($qualite->hasWarnings(['has_warnings' => true]));
    }
}
