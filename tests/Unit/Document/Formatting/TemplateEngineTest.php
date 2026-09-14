<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Formatting;

use App\Document\Formatting\TemplateEngine;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\Fidelity;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests du moteur de gabarit déterministe.
 *
 * Deux propriétés importent plus que les autres :
 *  - **aucun token** : le moteur est pur, donc reproductible et gratuit ;
 *  - **aucune réécriture** : les blocs ressortent identiques (mêmes objets), le
 *    moteur ne fait qu'y associer des styles.
 */
class TemplateEngineTest extends TestCase
{
    private function document(array $blocks = [], string $sourceType = 'docx'): StructuralDocument
    {
        return new StructuralDocument(
            documentId: 'doc-1',
            sourceType: $sourceType,
            blocks: $blocks,
        );
    }

    private function heading(string $id = 'b_001', int $level = 1): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Heading,
            text: 'Introduction générale',
            headingLevel: $level,
        );
    }

    // -------------------------------------------------------------------------
    // Gabarit par défaut et normalisation
    // -------------------------------------------------------------------------

    public function test_le_gabarit_par_defaut_porte_les_valeurs_academiques_du_projet(): void
    {
        $gabarit = TemplateEngine::defaultTemplate();

        $this->assertSame('Times New Roman', $gabarit['police']);
        $this->assertSame(16, $gabarit['tailles']['titre1']);
        $this->assertSame(12, $gabarit['tailles']['corps']);
        $this->assertSame(1.5, $gabarit['interligne']);
        // Marges d'en-tête/pied strictement inférieures aux marges de page,
        // sinon le texte chevauche l'en-tête.
        $this->assertLessThan($gabarit['marges']['top'], $gabarit['marges']['header']);
        $this->assertLessThan($gabarit['marges']['bottom'], $gabarit['marges']['footer']);
    }

    public function test_un_gabarit_null_retombe_sur_les_defauts(): void
    {
        $this->assertSame(
            TemplateEngine::defaultTemplate(),
            TemplateEngine::normalizeTemplate(null)
        );
    }

    public function test_un_gabarit_partiel_conserve_les_valeurs_non_definies(): void
    {
        // Un utilisateur qui ne règle que la police ne doit pas perdre ses
        // tailles et ses marges : l'écrasement clé par clé l'évite.
        $gabarit = TemplateEngine::normalizeTemplate(['police' => 'Arial']);

        $this->assertSame('Arial', $gabarit['police']);
        $this->assertSame(16, $gabarit['tailles']['titre1']);
        $this->assertSame(12, $gabarit['tailles']['corps']);
        $this->assertSame(1.5, $gabarit['interligne']);
        $this->assertSame(1440, $gabarit['marges']['top']);
    }

    public function test_un_gabarit_imbrique_partiel_ne_perd_pas_les_autres_cles(): void
    {
        $gabarit = TemplateEngine::normalizeTemplate([
            'tailles' => ['titre1' => 20],
            'tableau' => ['bordure' => false],
        ]);

        $this->assertSame(20, $gabarit['tailles']['titre1']);
        // Les autres tailles restent celles du défaut.
        $this->assertSame(14, $gabarit['tailles']['titre2']);
        $this->assertSame(12, $gabarit['tailles']['corps']);
        // Les autres clés du tableau aussi.
        $this->assertFalse($gabarit['tableau']['bordure']);
        $this->assertSame('TableGrid', $gabarit['tableau']['style']);
    }

    public function test_une_valeur_vide_du_gabarit_ne_remplace_pas_le_defaut(): void
    {
        // Une chaîne vide (champ de formulaire laissé vide) produirait une
        // police invalide : on garde donc la valeur par défaut.
        $gabarit = TemplateEngine::normalizeTemplate(['police' => '', 'tailles' => ['corps' => '']]);

        $this->assertSame('Times New Roman', $gabarit['police']);
        $this->assertSame(12, $gabarit['tailles']['corps']);
    }

    // -------------------------------------------------------------------------
    // Application
    // -------------------------------------------------------------------------

    public function test_apply_produit_un_style_par_bloc(): void
    {
        $document = $this->document([
            $this->heading('b_001'),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte'),
        ]);

        $rendered = (new TemplateEngine)->apply($document);

        $this->assertCount(2, $rendered->styles);
        $this->assertArrayHasKey('b_001', $rendered->styles);
        $this->assertArrayHasKey('b_002', $rendered->styles);
        $this->assertSame(2, $rendered->count());
    }

    public function test_apply_ne_modifie_aucun_bloc(): void
    {
        // Le moteur de mise en forme ne réécrit jamais le contenu : les objets
        // Block doivent ressortir identiques (même instances).
        $heading = $this->heading('b_001');
        $paragraph = new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte exact');

        $rendered = (new TemplateEngine)->apply($this->document([$heading, $paragraph]));

        $this->assertSame($heading, $rendered->blocks[0]);
        $this->assertSame($paragraph, $rendered->blocks[1]);
        $this->assertSame('Texte exact', $rendered->blocks[1]->text);
    }

    public function test_un_titre_recoit_le_style_de_son_niveau(): void
    {
        $document = $this->document([
            $this->heading('b_001', 1),
            $this->heading('b_002', 2),
            $this->heading('b_003', 3),
        ]);

        $rendered = (new TemplateEngine)->apply($document);

        $this->assertSame(16, $rendered->styleOf('b_001')['font_size']);
        $this->assertSame(14, $rendered->styleOf('b_002')['font_size']);
        $this->assertSame(12, $rendered->styleOf('b_003')['font_size']);
    }

    public function test_un_titre_profond_reutilise_le_style_du_niveau_trois(): void
    {
        // Au-delà du niveau 3, inventer des tailles décroissantes rendrait les
        // titres illisibles : on réutilise le niveau 3.
        $rendered = (new TemplateEngine)->apply($this->document([
            $this->heading('b_001', 7),
        ]));

        $this->assertSame(12, $rendered->styleOf('b_001')['font_size']);
    }

    public function test_un_titre_n_est_jamais_justifie(): void
    {
        // Un titre justifié est déformé par l'espacement : c'est un défaut
        // visuel immédiatement repérable.
        $rendered = (new TemplateEngine)->apply($this->document([$this->heading()]));

        $this->assertSame('left', $rendered->styleOf('b_001')['alignment']);
    }

    public function test_un_titre_reste_solidaire_du_paragraphe_suivant(): void
    {
        // Évite un titre seul en bas de page.
        $rendered = (new TemplateEngine)->apply($this->document([$this->heading()]));

        $this->assertTrue($rendered->styleOf('b_001')['keep_with_next']);
    }

    public function test_une_legende_est_centree_et_en_italique(): void
    {
        $rendered = (new TemplateEngine)->apply($this->document([
            new Block(blockId: 'b_001', type: BlockType::Caption, text: 'Figure 1 — Schéma', category: BlockCategory::Figure),
        ]));

        $style = $rendered->styleOf('b_001');
        $this->assertSame('center', $style['alignment']);
        $this->assertTrue($style['italic']);
        $this->assertSame(10, $style['font_size']);
    }

    public function test_un_tableau_recoit_le_style_de_tableau_du_gabarit(): void
    {
        $rendered = (new TemplateEngine)->apply(
            $this->document([$this->tableBlock()]),
            ['tableau' => ['style' => 'TableGridLight', 'header_couleur' => 'FF0000']],
        );

        $style = $rendered->styleOf('b_001');
        $this->assertSame('TableGridLight', $style['table_style']);
        $this->assertSame('FF0000', $style['table_header_color']);
    }

    // -------------------------------------------------------------------------
    // Idempotence et fidélité
    // -------------------------------------------------------------------------

    public function test_appliquer_le_gabarit_deux_fois_donne_le_meme_resultat(): void
    {
        // Propriété essentielle : la mise en forme est une fonction pure, donc
        // la rejouer (reprise sur erreur, relance du job) ne dérive pas.
        $document = $this->document([
            $this->heading('b_001'),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte'),
            $this->tableBlock(),
        ]);

        $engine = new TemplateEngine;
        $once = $engine->apply($document);
        $twice = $engine->apply($document);

        $this->assertSame($once->styles, $twice->styles);
        $this->assertSame($once->template, $twice->template);
        $this->assertSame($once->count(), $twice->count());
    }

    public function test_la_fidelite_est_transportee_jusqu_au_rendu(): void
    {
        $rendered = (new TemplateEngine)->apply(
            $this->document([$this->heading()], 'ocr')
        );

        $this->assertSame(Fidelity::Reconstructed, $rendered->fidelity);
        // Une source reconstruite ne peut jamais être livrée sans validation.
        $this->assertTrue($rendered->requiresVisualReview());
    }

    public function test_un_document_docx_est_marque_fidele_et_ne_requiert_pas_de_revue(): void
    {
        $rendered = (new TemplateEngine)->apply($this->document([$this->heading()], 'docx'));

        $this->assertSame(Fidelity::Exact, $rendered->fidelity);
        $this->assertFalse($rendered->requiresVisualReview());
    }

    public function test_le_resume_indique_la_police_et_le_nombre_de_titres(): void
    {
        $rendered = (new TemplateEngine)->apply($this->document([
            $this->heading('b_001'),
            $this->heading('b_002', 2),
            new Block(blockId: 'b_003', type: BlockType::Paragraph, text: 'Texte'),
        ]));

        $summary = $rendered->summary();

        $this->assertSame(3, $summary['blocks']);
        $this->assertSame(2, $summary['headings']);
        $this->assertSame('Times New Roman', $summary['template_font']);
        $this->assertSame('exact', $summary['fidelity']);
    }

    /**
     * Un bloc `table` exige ses données : on fournit un tableau minimal.
     */
    private function tableBlock(string $id = 'b_001'): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            text: 'Tableau 1',
            tableData: TableData::fromGrid([
                ['Nom', 'Note'],
                ['Alice', '15'],
                ['Bob', '12'],
            ]),
        );
    }

    // -------------------------------------------------------------------------
    // Marges
    // -------------------------------------------------------------------------

    public function test_les_marges_de_section_sont_en_twips(): void
    {
        $margins = TemplateEngine::sectionMargins();

        $this->assertSame(1440, $margins['top']);
        $this->assertSame(1440, $margins['left']);
        $this->assertArrayHasKey('header', $margins);
        $this->assertArrayHasKey('footer', $margins);
    }

    public function test_les_marges_suivent_un_gabarit_personnalise(): void
    {
        $margins = TemplateEngine::sectionMargins([
            'marges' => ['top' => 720, 'left' => 1000],
        ]);

        $this->assertSame(720, $margins['top']);
        $this->assertSame(1000, $margins['left']);
        // Les marges non définies restent celles du défaut.
        $this->assertSame(1440, $margins['right']);
    }
}
