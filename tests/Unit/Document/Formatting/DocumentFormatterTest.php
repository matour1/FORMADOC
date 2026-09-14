<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Formatting;

use App\Document\Formatting\DocumentFormatter;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests de l'orchestrateur de mise en forme.
 *
 * Vérifie surtout que les quatre composants sont bien enchaînés et que la
 * **garantie sur les tableaux survit à l'assemblage** : c'est à ce niveau que
 * se perdrait une protection laissée à un composant pris isolément.
 */
class DocumentFormatterTest extends TestCase
{
    private function document(array $blocks, string $sourceType = 'docx'): StructuralDocument
    {
        return new StructuralDocument(
            documentId: 'doc-1',
            sourceType: $sourceType,
            blocks: $blocks,
        );
    }

    private function table(string $id, array $grid): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            text: 'Tableau',
            tableData: TableData::fromGrid($grid),
        );
    }

    public function test_format_produit_les_quatre_volumes_de_description(): void
    {
        $result = (new DocumentFormatter)->format($this->document([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Introduction', headingLevel: 1),
            $this->table('b_002', [['Nom', 'Note'], ['Alice', '15']]),
        ]));

        $this->assertArrayHasKey('rendered', $result);
        $this->assertArrayHasKey('tables', $result);
        $this->assertArrayHasKey('figures', $result);
        $this->assertArrayHasKey('header_footer', $result);
    }

    public function test_format_conserve_l_integrite_de_tous_les_tableaux(): void
    {
        $document = $this->document([
            $this->table('b_001', [['Poste', 'Montant'], ['Fournitures', '125 000'], ['Transport', '47 500']]),
            $this->table('b_002', [['Référence', 'Délai'], ['CMD-2025-014', '30 jours']]),
        ]);

        $formatter = new DocumentFormatter;
        $result = $formatter->format($document);
        $verification = $formatter->verifyContentIntegrity($document->blocks, $result['tables']);

        $this->assertTrue($verification['intact']);
        $this->assertSame(2, $verification['checked']);
        $this->assertSame([], $verification['corrupted']);
    }

    public function test_les_montants_chiffres_traversent_la_mise_en_forme_sans_alteration(): void
    {
        // Le cas concret qui motive la garantie : un montant corrompu serait
        // indétectable dans un rapport livré.
        $document = $this->document([
            $this->table('b_001', [['Désignation', 'Montant (FCFA)'], ['Total général', '1 725 000']]),
        ]);

        $result = (new DocumentFormatter)->format($document);

        $this->assertSame('1 725 000', $result['rendered']->blocks[0]->tableData->cell(1, 1));
        $this->assertSame('Total général', $result['rendered']->blocks[0]->tableData->cell(1, 0));
    }

    public function test_la_verification_detecte_un_tableau_altere(): void
    {
        $document = $this->document([
            $this->table('b_001', [['Montant'], ['100']]),
        ]);

        $formatter = new DocumentFormatter;
        $result = $formatter->format($document);

        // Simulation d'une corruption postérieure au calcul de l'empreinte.
        $corrupted = [$this->table('b_001', [['Montant'], ['1000']])];
        $verification = $formatter->verifyContentIntegrity($corrupted, $result['tables']);

        $this->assertFalse($verification['intact']);
        $this->assertSame(['b_001'], $verification['corrupted']);
    }

    public function test_la_verification_ignore_les_blocs_non_tableaux(): void
    {
        $document = $this->document([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte'),
            $this->table('b_002', [['A'], ['B']]),
        ]);

        $formatter = new DocumentFormatter;
        $result = $formatter->format($document);
        $verification = $formatter->verifyContentIntegrity($document->blocks, $result['tables']);

        // Un seul tableau réellement contrôlé.
        $this->assertSame(1, $verification['checked']);
        $this->assertTrue($verification['intact']);
    }

    public function test_le_rendu_porte_les_metadonnees_des_quatre_composants(): void
    {
        $result = (new DocumentFormatter)->format($this->document([
            $this->table('b_001', [['A'], ['B']]),
            new Block(blockId: 'b_002', type: BlockType::Figure, imageRef: 'img_001.png', linkedBlockId: 'b_003'),
            new Block(blockId: 'b_003', type: BlockType::Caption, text: 'Figure 1', linkedBlockId: 'b_002', category: BlockCategory::Figure),
        ]));

        $meta = $result['rendered']->meta;

        $this->assertArrayHasKey('tables', $meta);
        $this->assertArrayHasKey('figures', $meta);
        $this->assertArrayHasKey('header_footer', $meta);
        $this->assertSame(1, $meta['tables']['tables']);
        $this->assertSame(1, $meta['figures']['figures']);
    }

    public function test_les_legendes_orphelines_remontent_dans_les_metadonnees(): void
    {
        $result = (new DocumentFormatter)->format($this->document([
            new Block(blockId: 'b_001', type: BlockType::Caption, text: 'Figure 9', linkedBlockId: 'b_inexistant', category: BlockCategory::Figure),
        ]));

        $this->assertArrayHasKey('orphan_captions', $result['rendered']->meta);
        $this->assertContains('b_001', $result['rendered']->meta['orphan_captions']);
    }

    public function test_un_document_sans_legende_orpheline_n_a_pas_la_cle_correspondante(): void
    {
        // On n'ajoute la clé que si elle a du contenu : le rapport de traitement
        // reste lisible et ne signale pas de problème inexistant.
        $result = (new DocumentFormatter)->format($this->document([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte'),
        ]));

        $this->assertArrayNotHasKey('orphan_captions', $result['rendered']->meta);
    }

    public function test_un_gabarit_personnalise_traverse_tout_le_pipeline(): void
    {
        $result = (new DocumentFormatter)->format(
            $this->document([
                new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Titre', headingLevel: 1),
                $this->table('b_002', [['A'], ['B']]),
            ]),
            ['police' => 'Arial', 'tailles' => ['titre1' => 18], 'tableau' => ['style' => 'GridTable4']],
        );

        $this->assertSame(18, $result['rendered']->styleOf('b_001')['font_size']);
        $this->assertSame('Arial', $result['rendered']->styleOf('b_001')['font_name']);
        $this->assertSame('GridTable4', $result['tables']['b_002']['table_style']);
    }

    public function test_les_options_d_en_tete_traversent_le_pipeline(): void
    {
        $result = (new DocumentFormatter)->format(
            $this->document([new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte')]),
            null,
            ['header' => 'Mémoire 2025', 'page_number' => true],
        );

        $this->assertSame('Mémoire 2025', $result['header_footer']['header']['text']);
        $this->assertNotNull($result['header_footer']['page_number']);
    }

    public function test_le_formatage_est_idempotent(): void
    {
        $document = $this->document([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Titre', headingLevel: 1),
            $this->table('b_002', [['A', 'B'], ['C', 'D']]),
        ]);

        $formatter = new DocumentFormatter;
        $once = $formatter->format($document);
        $twice = $formatter->format($document);

        $this->assertSame($once['rendered']->styles, $twice['rendered']->styles);
        $this->assertSame($once['tables'], $twice['tables']);
        $this->assertSame($once['figures'], $twice['figures']);
    }

    public function test_un_document_vide_ne_provoque_pas_d_erreur(): void
    {
        $result = (new DocumentFormatter)->format($this->document([]));

        $this->assertSame(0, $result['rendered']->count());
        $this->assertSame([], $result['tables']);
        $this->assertSame(0, $result['rendered']->meta['tables']['tables']);
    }

    public function test_la_fidelite_de_la_source_est_conservee_et_rappelee(): void
    {
        $result = (new DocumentFormatter)->format(
            $this->document([new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte')], 'ocr')
        );

        $this->assertTrue($result['rendered']->requiresVisualReview());
        $this->assertSame('reconstructed', $result['rendered']->meta['source_fidelity']);
    }

    public function test_aucun_bloc_n_est_ajoute_ni_retire_par_la_mise_en_forme(): void
    {
        $blocks = [
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Titre', headingLevel: 1),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte'),
            $this->table('b_003', [['A'], ['B']]),
        ];

        $result = (new DocumentFormatter)->format($this->document($blocks));

        $this->assertCount(3, $result['rendered']->blocks);
        $this->assertSame('b_001', $result['rendered']->blocks[0]->blockId);
        $this->assertSame('b_003', $result['rendered']->blocks[2]->blockId);
    }
}
