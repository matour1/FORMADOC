<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Structure;

use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\CrossRef;
use App\Document\Structure\Fidelity;
use App\Document\Structure\TableData;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests des décisions portées par Block.
 *
 * Les points les plus sensibles sont verrouillés ici :
 *  - le seuil de confiance qui déclenche (ou non) une clarification ciblée ;
 *  - la règle « source reconstruite → validation visuelle systématique »,
 *    appliquée même quand la classification est confiante ;
 *  - l'immuabilité, socle des snapshots du chat (§9).
 */
class BlockTest extends TestCase
{
    private function paragraph(string $id = 'b_001'): Block
    {
        return new Block(blockId: $id, type: BlockType::Paragraph, text: 'Texte');
    }

    public function test_un_bloc_confiant_ne_declenche_pas_de_clarification(): void
    {
        $block = new Block(
            blockId: 'b_001',
            type: BlockType::Heading,
            text: 'Introduction',
            headingLevel: 1,
            confidence: 0.85,
        );

        $this->assertTrue($block->isConfident());
        $this->assertFalse($block->needsClarification());
    }

    public function test_un_bloc_sous_le_seuil_declenche_une_clarification(): void
    {
        // Règle §6 : sous 0,85 on demande — mais uniquement sur CE bloc.
        $block = new Block(
            blockId: 'b_042',
            type: BlockType::Paragraph,
            text: 'Texte en gras ambigu',
            isBold: true,
            confidence: 0.69,
        );

        $this->assertFalse($block->isConfident());
        $this->assertTrue($block->needsClarification());
    }

    public function test_une_source_reconstruite_exige_une_revue_visuelle_malgre_une_confiance_haute(): void
    {
        // L'incertitude vient de la SOURCE (OCR), pas de la classification :
        // on exige donc un aperçu avant/après même à confiance maximale.
        $block = new Block(
            blockId: 'b_001',
            type: BlockType::Heading,
            text: 'Introduction',
            headingLevel: 1,
            fidelity: Fidelity::Reconstructed,
            confidence: 0.99,
        );

        $this->assertTrue($block->isConfident());
        $this->assertTrue($block->requiresVisualReview());
    }

    public function test_une_source_exacte_ne_force_pas_de_revue_visuelle(): void
    {
        $block = new Block(
            blockId: 'b_001',
            type: BlockType::Heading,
            text: 'Introduction',
            headingLevel: 1,
            confidence: 0.6,
        );

        $this->assertFalse($block->requiresVisualReview());
    }

    public function test_la_categorie_effective_est_deduite_du_type_pour_un_bloc_numerotable(): void
    {
        $block = new Block(blockId: 'b_001', type: BlockType::Figure, imageRef: 'img_1.png');

        $this->assertSame(BlockCategory::Figure, $block->effectiveCategory());
        $this->assertTrue($block->isNumberable());
    }

    public function test_la_categorie_explicite_prime_pour_une_legende(): void
    {
        // Une légende est de type `caption` : sa catégorie vient de son lien.
        $block = new Block(
            blockId: 'b_002',
            type: BlockType::Caption,
            text: 'Figure 1 : Schéma général',
            category: BlockCategory::Figure,
            linkedBlockId: 'b_001',
        );

        $this->assertSame(BlockCategory::Figure, $block->effectiveCategory());
        $this->assertFalse($block->isNumberable());
    }

    public function test_le_numero_final_prime_sur_le_numero_original(): void
    {
        // `original_number` n'est jamais fiable (trous, doublons) : il ne sert
        // que de repli avant la passe de renumérotation.
        $block = new Block(
            blockId: 'b_001',
            type: BlockType::Figure,
            imageRef: 'img_1.png',
            originalNumber: '5',
            finalNumber: '2',
        );

        $this->assertSame('2', $block->displayNumber());
        $this->assertTrue($block->isNumbered());
    }

    public function test_le_numero_original_sert_de_repli_avant_renumerotation(): void
    {
        $block = new Block(
            blockId: 'b_001',
            type: BlockType::Figure,
            imageRef: 'img_1.png',
            originalNumber: '5',
        );

        $this->assertSame('5', $block->displayNumber());
        $this->assertFalse($block->isNumbered());
    }

    public function test_with_classification_remplace_type_confiance_et_niveau(): void
    {
        $block = $this->paragraph();
        $classified = $block->withClassification(BlockType::Heading, 0.92, 2);

        $this->assertSame(BlockType::Heading, $classified->type);
        $this->assertSame(0.92, $classified->confidence);
        $this->assertSame(2, $classified->headingLevel);

        // L'original n'est pas modifié (immuabilité).
        $this->assertSame(BlockType::Paragraph, $block->type);
        $this->assertSame('b_001', $classified->blockId);
    }

    public function test_with_confidence_ne_perd_pas_les_autres_proprietes(): void
    {
        $block = new Block(
            blockId: 'b_007',
            type: BlockType::Table,
            text: '',
            isBold: true,
            indentLevel: 2,
            tableData: TableData::fromGrid([['A', 'B']]),
        );

        $updated = $block->withConfidence(0.5);

        $this->assertSame(0.5, $updated->confidence);
        $this->assertSame('b_007', $updated->blockId);
        $this->assertTrue($updated->isBold);
        $this->assertSame(2, $updated->indentLevel);
        $this->assertNotNull($updated->tableData);
    }

    public function test_with_final_number_peut_effacer_le_numero(): void
    {
        $block = new Block(
            blockId: 'b_001',
            type: BlockType::Figure,
            imageRef: 'img_1.png',
            finalNumber: '3',
        );

        $cleared = $block->withFinalNumber(null);

        $this->assertNull($cleared->finalNumber);
        $this->assertFalse($cleared->isNumbered());
    }

    public function test_un_bloc_de_type_table_sans_donnees_est_rejete(): void
    {
        // Garantit qu'aucun tableau ne circule sans son contenu exact.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doit porter ses données');

        new Block(blockId: 'b_001', type: BlockType::Table);
    }

    public function test_un_bloc_sans_identifiant_est_rejete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doit porter un identifiant');

        new Block(blockId: '', type: BlockType::Paragraph);
    }

    public function test_une_confiance_hors_bornes_est_rejetee(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('entre 0 et 1');

        new Block(blockId: 'b_001', type: BlockType::Paragraph, confidence: 1.4);
    }

    public function test_un_niveau_de_titre_inferieur_a_un_est_rejete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('supérieur ou égal à 1');

        new Block(blockId: 'b_001', type: BlockType::Heading, headingLevel: 0);
    }

    public function test_un_bloc_survit_a_un_aller_retour_json_sans_perte(): void
    {
        $block = new Block(
            blockId: 'b_003',
            type: BlockType::CrossRef,
            text: 'voir Figure 3',
            category: BlockCategory::Figure,
            crossRef: new CrossRef(
                matchedText: 'voir Figure 3',
                targetCategory: BlockCategory::Figure,
                targetOriginalNumber: '3',
            ),
        );

        $restored = Block::fromArray($block->toArray());

        $this->assertEquals($block->toArray(), $restored->toArray());
    }

    public function test_un_bloc_tableau_survit_a_un_aller_retour_json(): void
    {
        $block = new Block(
            blockId: 'b_004',
            type: BlockType::Table,
            originalNumber: '2',
            finalNumber: '1',
            tableData: TableData::fromGrid([['En-tête', 'Valeur'], ['A', 'B']]),
        );

        $restored = Block::fromArray($block->toArray());

        $this->assertSame(2, $restored->tableData?->rows);
        $this->assertSame('En-tête', $restored->tableData?->cell(0, 0));
        $this->assertSame('1', $restored->finalNumber);
    }
}
