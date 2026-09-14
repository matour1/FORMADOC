<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Lists;

use App\Document\Lists\TableOfContentsGenerator;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests des générateurs de listes.
 *
 * Enjeu central : la table des matières doit refléter **exactement** le document
 * — bons titres, bons niveaux, bons numéros de page. Une entrée manquante est
 * invisible pour l'auteur ; une entrée en trop l'est tout autant.
 */
class TableOfContentsGeneratorTest extends TestCase
{
    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: 'doc-1', sourceType: 'docx', blocks: $blocks);
    }

    private function heading(string $id, string $text, ?int $level = 1, ?string $number = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Heading,
            text: $text,
            headingLevel: $level,
            finalNumber: $number,
        );
    }

    private function paragraph(string $id): Block
    {
        return new Block(blockId: $id, type: BlockType::Paragraph, text: 'Texte');
    }

    private function caption(string $id, BlockCategory $category, string $text, ?string $number = null, ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $text,
            category: $category,
            finalNumber: $number,
            linkedBlockId: $linked,
        );
    }

    private function table(string $id, ?string $number = null, ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            tableData: TableData::fromGrid([['A'], ['B']]),
            finalNumber: $number,
            linkedBlockId: $linked,
        );
    }

    // -------------------------------------------------------------------------
    // Table des matières
    // -------------------------------------------------------------------------

    public function test_la_table_des_matieres_reprend_tous_les_titres_dans_l_ordre(): void
    {
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            $this->heading('b_001', 'Introduction', 1),
            $this->paragraph('b_002'),
            $this->heading('b_003', 'Contexte', 2),
            $this->heading('b_004', 'Conclusion', 1),
        ]));

        $this->assertSame(3, $resultat['count']);
        $this->assertSame('Introduction', $resultat['entries'][0]['text']);
        $this->assertSame('Contexte', $resultat['entries'][1]['text']);
        $this->assertSame('Conclusion', $resultat['entries'][2]['text']);
    }

    public function test_les_niveaux_de_titres_sont_repris(): void
    {
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            $this->heading('b_001', 'Niveau 1', 1),
            $this->heading('b_002', 'Niveau 2', 2),
            $this->heading('b_003', 'Niveau 3', 3),
        ]));

        $this->assertSame(1, $resultat['entries'][0]['level']);
        $this->assertSame(2, $resultat['entries'][1]['level']);
        $this->assertSame(3, $resultat['entries'][2]['level']);
    }

    public function test_les_numeros_de_page_sont_repris(): void
    {
        $resultat = (new TableOfContentsGenerator)->generate(
            $this->document([
                $this->heading('b_001', 'Introduction', 1),
                $this->heading('b_002', 'Méthode', 1),
            ]),
            ['b_001' => 3, 'b_002' => 7],
        );

        $this->assertSame(3, $resultat['entries'][0]['page']);
        $this->assertSame(7, $resultat['entries'][1]['page']);
        $this->assertTrue($resultat['has_pages']);
    }

    public function test_une_entree_sans_page_est_signalee_sans_bloquer(): void
    {
        // Sans pagination disponible, les entrées sont produites sans numéro :
        // une TOC sans numéros reste utile, une TOC avec des numéros faux est
        // nuisible. La dégradation doit être visible, pas silencieuse.
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            $this->heading('b_001', 'Introduction', 1),
        ]));

        $this->assertCount(1, $resultat['entries']);
        $this->assertNull($resultat['entries'][0]['page']);
        $this->assertFalse($resultat['entries'][0]['has_page']);
        $this->assertFalse($resultat['has_pages']);
    }

    public function test_les_titres_trop_profonds_ne_sont_pas_listes_mais_comptes(): void
    {
        // Un titre au-delà du niveau 3 n'est pas listé (il n'a pas de style
        // dédié dans le gabarit), mais il est compté pour le rapport qualité.
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            $this->heading('b_001', 'Normal', 2),
            $this->heading('b_002', 'Très profond', 5),
        ]));

        $this->assertSame(1, $resultat['count']);
        $this->assertSame(1, $resultat['truncated']);
    }

    public function test_les_intitules_de_listes_sont_exclus_du_sommaire(): void
    {
        // Sans cette exclusion, le document contiendrait « SOMMAIRE ...... 3 »
        // dans son propre sommaire — visiblement faux.
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            $this->heading('b_001', 'SOMMAIRE', 1),
            $this->heading('b_002', 'LISTE DES FIGURES', 1),
            $this->heading('b_003', 'Introduction', 1),
        ]));

        $this->assertSame(1, $resultat['count']);
        $this->assertSame('Introduction', $resultat['entries'][0]['text']);
    }

    public function test_un_intitule_de_liste_avec_ponctuation_est_exclu(): void
    {
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            $this->heading('b_001', 'LISTE DES TABLEAUX :', 1),
            $this->heading('b_002', 'Introduction', 1),
        ]));

        $this->assertSame(1, $resultat['count']);
    }

    public function test_un_titre_sans_niveau_est_traite_comme_niveau_un(): void
    {
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Sans niveau'),
        ]));

        $this->assertSame(1, $resultat['entries'][0]['level']);
    }

    public function test_le_numero_calcule_du_titre_est_repris(): void
    {
        // La TOC doit citer le même numéro que le corps du document.
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            $this->heading('b_001', '1. Introduction', 1, '1'),
        ]));

        $this->assertSame('1', $resultat['entries'][0]['number']);
    }

    // -------------------------------------------------------------------------
    // Listes dédiées
    // -------------------------------------------------------------------------

    public function test_la_liste_des_figures_reprend_les_legendes_de_figures(): void
    {
        $resultat = (new TableOfContentsGenerator)->generateCategory(
            $this->document([
                $this->caption('b_001', BlockCategory::Figure, 'Figure 1 : Architecture', '1'),
                $this->caption('b_002', BlockCategory::Table, 'Tableau 1 : Résultats', '1'),
            ]),
            BlockCategory::Figure,
        );

        $this->assertSame('LISTE DES FIGURES', $resultat['title']);
        $this->assertSame(1, $resultat['count']);
        $this->assertSame('Architecture', $resultat['entries'][0]['label']);
    }

    public function test_le_libelle_d_une_legende_perd_son_prefixe_de_numero(): void
    {
        // « Figure 3 : Schéma » devient « Schéma » : la liste recompose ellemême
        // « Figure 3 : Schéma », donc garder le préfixe le doublerait.
        $resultat = (new TableOfContentsGenerator)->generateCategory(
            $this->document([
                $this->caption('b_001', BlockCategory::Figure, 'Figure 3 : Schéma du pipeline', '3'),
            ]),
            BlockCategory::Figure,
        );

        $this->assertSame('Schéma du pipeline', $resultat['entries'][0]['label']);
    }

    public function test_un_tableau_sans_legende_reste_liste_avec_un_libelle_vide(): void
    {
        // Un élément absent de la liste serait invisible pour le lecteur ;
        // un libellé manquant se voit à la lecture et se corrige.
        $resultat = (new TableOfContentsGenerator)->generateCategory(
            $this->document([$this->table('b_001', '1')]),
            BlockCategory::Table,
        );

        $this->assertSame(1, $resultat['count']);
        $this->assertSame('', $resultat['entries'][0]['label']);
        $this->assertFalse($resultat['entries'][0]['has_label']);
    }

    public function test_un_tableau_avec_legende_n_est_pas_liste_deux_fois(): void
    {
        // Le porteur est rattaché à sa légende : seule la légende est listée,
        // sinon la liste contiendrait deux entrées pour un seul tableau.
        $resultat = (new TableOfContentsGenerator)->generateCategory(
            $this->document([
                $this->table('b_001', '1', 'b_002'),
                $this->caption('b_002', BlockCategory::Table, 'Tableau 1 : Bilan', '1', 'b_001'),
            ]),
            BlockCategory::Table,
        );

        $this->assertSame(1, $resultat['count']);
        $this->assertSame('Bilan', $resultat['entries'][0]['label']);
    }

    public function test_la_page_de_la_legende_est_utilisee(): void
    {
        $resultat = (new TableOfContentsGenerator)->generateCategory(
            $this->document([
                $this->table('b_001', '1', 'b_002'),
                $this->caption('b_002', BlockCategory::Table, 'Tableau 1 : Bilan', '1', 'b_001'),
            ]),
            BlockCategory::Table,
            ['b_002' => 12],
        );

        $this->assertSame(12, $resultat['entries'][0]['page']);
    }

    public function test_les_quatre_categories_sont_generees_independamment(): void
    {
        $resultat = (new TableOfContentsGenerator)->generateAllCategories($this->document([
            $this->caption('b_001', BlockCategory::Figure, 'Figure 1 : A', '1'),
            $this->caption('b_002', BlockCategory::Table, 'Tableau 1 : B', '1'),
            $this->caption('b_003', BlockCategory::Annexe, 'Annexe 1 : C', '1'),
            $this->caption('b_004', BlockCategory::Planche, 'Planche 1 : D', '1'),
        ]));

        $this->assertCount(4, $resultat);

        $titres = array_column($resultat, 'title');
        $this->assertContains('LISTE DES FIGURES', $titres);
        $this->assertContains('LISTE DES TABLEAUX', $titres);
        $this->assertContains('LISTE DES ANNEXES', $titres);
        $this->assertContains('LISTE DES PLANCHES', $titres);
    }

    public function test_une_categorie_vide_est_omise(): void
    {
        // Une « LISTE DES PLANCHES » suivie de rien est un défaut visible dans
        // un mémoire, pas une preuve d'exhaustivité.
        $resultat = (new TableOfContentsGenerator)->generateAllCategories($this->document([
            $this->caption('b_001', BlockCategory::Figure, 'Figure 1 : A', '1'),
        ]));

        $this->assertCount(1, $resultat);
        $this->assertSame('LISTE DES FIGURES', $resultat[0]['title']);
    }

    public function test_un_document_sans_element_numerote_ne_produit_aucune_liste(): void
    {
        $resultat = (new TableOfContentsGenerator)->generateAllCategories($this->document([
            $this->heading('b_001', 'Introduction', 1),
        ]));

        $this->assertSame([], $resultat);
    }

    // -------------------------------------------------------------------------
    // Cas limites
    // -------------------------------------------------------------------------

    public function test_un_document_vide_ne_provoque_pas_d_erreur(): void
    {
        $generateur = new TableOfContentsGenerator;
        $document = $this->document([]);

        $sommaire = $generateur->generate($document);

        $this->assertSame(0, $sommaire['count']);
        $this->assertSame([], $generateur->generateAllCategories($document));
    }

    public function test_un_titre_vide_est_ignore(): void
    {
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            $this->heading('b_001', '', 1),
            $this->heading('b_002', 'Réel', 1),
        ]));

        $this->assertSame(1, $resultat['count']);
        $this->assertSame('Réel', $resultat['entries'][0]['text']);
    }

    public function test_les_titres_conservent_leur_ordre_d_apparition(): void
    {
        // L'ordre du sommaire suit l'ordre du document : c'est ce qu'attend un
        // lecteur, et le trier alphabétiquement serait une erreur manifeste.
        $resultat = (new TableOfContentsGenerator)->generate($this->document([
            $this->heading('b_001', 'Zèbre', 1),
            $this->heading('b_002', 'Alpha', 1),
            $this->heading('b_003', 'Milieu', 1),
        ]));

        $this->assertSame(['Zèbre', 'Alpha', 'Milieu'], array_column($resultat['entries'], 'text'));
    }
}
