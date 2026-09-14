<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Numbering;

use App\Document\Numbering\NumberingCoordinator;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests de l'orchestrateur R4.
 *
 * C'est ici que se vérifie **l'enchaînement** des cinq composants : pris
 * isolément ils sont corrects, mais un ordre erroné produirait des numéros
 * faux sans qu'aucun test unitaire ne le détecte.
 */
class NumberingCoordinatorTest extends TestCase
{
    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: 'doc-1', sourceType: 'docx', blocks: $blocks);
    }

    private function figure(string $id, ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Figure,
            imageRef: $id.'.png',
            linkedBlockId: $linked,
        );
    }

    private function table(string $id): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            tableData: TableData::fromGrid([['A'], ['B']]),
        );
    }

    private function caption(string $id, BlockCategory $category, string $number, ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $category->keyword().' '.$number,
            category: $category,
            originalNumber: $number,
            linkedBlockId: $linked,
        );
    }

    private function paragraph(string $id, string $text): Block
    {
        return new Block(blockId: $id, type: BlockType::Paragraph, text: $text);
    }

    // -------------------------------------------------------------------------
    // Enchaînement complet
    // -------------------------------------------------------------------------

    public function test_l_orchestrateur_renvoie_les_quatre_volumes_de_resultat(): void
    {
        $result = (new NumberingCoordinator)->run($this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]));

        foreach (['document', 'linking', 'numbering', 'references', 'rewritten', 'report', 'changes'] as $cle) {
            $this->assertArrayHasKey($cle, $result);
        }
    }

    public function test_la_legende_est_rattachee_puis_renumerotee(): void
    {
        // Enchaînement attendu : CaptionLinker crée le lien, puis NumberingPass
        // compte. Sans le lien, le porteur et la légende seraient comptés deux
        // fois — le doublon que R4 doit supprimer.
        $result = (new NumberingCoordinator)->run($this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '5'),
        ]));

        $document = $result['document'];

        $this->assertSame('b_001', $document->blockById('b_002')->linkedBlockId);
        $this->assertSame('1', $document->blockById('b_001')->displayNumber());
        $this->assertSame('1', $document->blockById('b_002')->displayNumber());
        $this->assertSame(1, $result['linking']['linked']);
        $this->assertSame(['figure' => 1], $result['numbering']['per_category']);
    }

    public function test_le_renvoi_est_resolu_et_reecrit_avec_le_nouveau_numero(): void
    {
        // Chaîne complète : détection (sur numéros calculés) → réécriture.
        // Le texte doit finir par citer le numéro réellement affiché.
        $result = (new NumberingCoordinator)->run($this->document([
            $this->paragraph('b_001', 'Comme le montre la Figure 1, le schéma est clair.'),
            $this->figure('b_002'),
            $this->caption('b_003', BlockCategory::Figure, '7'),
        ]));

        $document = $result['document'];

        $this->assertSame('1', $document->blockById('b_003')->displayNumber());
        $this->assertSame('1', $document->blockById('b_002')->displayNumber());
        $this->assertSame(
            'Comme le montre la Figure 1, le schéma est clair.',
            $document->blockById('b_001')->text
        );
    }

    public function test_un_renvoi_vers_un_numero_qui_change_est_reecrit(): void
    {
        // Scénario révélateur de la valeur de R4 : la légende disait
        // « Tableau 7 », la renumérotation en fait le « Tableau 2 », et le
        // texte du renvoi doit suivre — sinon le document se contredit.
        $result = (new NumberingCoordinator)->run($this->document([
            $this->paragraph('b_001', 'Le Tableau 7 détaille les charges.'),
            $this->table('b_002'),
            $this->caption('b_003', BlockCategory::Table, '7'),
        ]));

        $document = $result['document'];

        $this->assertSame('1', $document->blockById('b_003')->displayNumber());
        // Le renvoi citait « Tableau 7 » : il ne le trouve plus après
        // renumérotation, la proximité propose le tableau, et le texte est
        // réécrit en « Tableau 1 ».
        $this->assertSame(
            'Le Tableau 1 détaille les charges.',
            $document->blockById('b_001')->text
        );
    }

    public function test_chaque_categorie_est_renumerotee_independamment(): void
    {
        $result = (new NumberingCoordinator)->run($this->document([
            $this->table('b_001'),
            $this->caption('b_002', BlockCategory::Table, '12'),
            $this->figure('b_003'),
            $this->caption('b_004', BlockCategory::Figure, '40'),
        ]));

        $document = $result['document'];

        $this->assertSame('1', $document->blockById('b_002')->displayNumber());
        $this->assertSame('1', $document->blockById('b_004')->displayNumber());
    }

    public function test_le_rapport_est_produit_et_jamais_bloquant(): void
    {
        $result = (new NumberingCoordinator)->run($this->document([
            $this->paragraph('b_001', 'Voir Figure 9.'),
        ]));

        $this->assertArrayHasKey('report', $result);
        $this->assertFalse($result['report']['blocking']);
    }

    public function test_les_legendes_orphelines_sont_comptees_dans_le_rattachement(): void
    {
        // Cas réel dominant du corpus : une légende sans porteur reste non
        // rattachée et est signalée — elle n'est ni supprimée ni reliée d'office.
        $result = (new NumberingCoordinator)->run($this->document([
            $this->paragraph('b_001', 'Texte introductif.'),
            $this->caption('b_002', BlockCategory::Figure, '3'),
        ]));

        $this->assertSame(0, $result['linking']['linked']);
        $this->assertContains('b_002', $result['linking']['orphans']);
        // Elle est malgré tout renumérotée : son numéro existe.
        $this->assertSame('1', $result['document']->blockById('b_002')->displayNumber());
    }

    public function test_le_resume_est_lisible(): void
    {
        $coordinator = new NumberingCoordinator;
        $result = $coordinator->run($this->document([
            $this->table('b_001'),
            $this->caption('b_002', BlockCategory::Table, '1'),
            $this->paragraph('b_003', 'Le Tableau 1 récapitule tout.'),
        ]));

        $resume = $coordinator->summary($result);

        $this->assertNotSame('', $resume);
        $this->assertStringContainsString('tableau', $resume);
    }

    public function test_has_warnings_reflete_le_rapport(): void
    {
        $coordinator = new NumberingCoordinator;
        $sansAvertissement = $coordinator->run($this->document([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte ordinaire.'),
        ]));

        $this->assertFalse($coordinator->hasWarnings($sansAvertissement['report']));
    }

    public function test_un_document_vide_traverse_l_orchestrateur_sans_erreur(): void
    {
        $result = (new NumberingCoordinator)->run($this->document([]));

        $this->assertSame(0, $result['document']->count());
        $this->assertSame(0, $result['rewritten']);
        $this->assertSame([], $result['numbering']['per_category']);
    }

    public function test_l_ordre_du_document_est_preserve_de_bout_en_bout(): void
    {
        $document = $this->document([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Introduction', headingLevel: 1),
            $this->figure('b_002'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
            new Block(blockId: 'b_004', type: BlockType::Paragraph, text: 'Le corps du rapport.'),
        ]);

        $result = (new NumberingCoordinator)->run($document);
        $identifiants = array_map(
            static fn (Block $b): string => $b->blockId,
            $result['document']->blocks
        );

        $this->assertSame(['b_001', 'b_002', 'b_003', 'b_004'], $identifiants);
    }

    public function test_le_document_d_origine_n_est_pas_modifie(): void
    {
        $document = $this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '9'),
        ]);

        (new NumberingCoordinator)->run($document);

        $this->assertNull($document->blockById('b_002')->linkedBlockId);
        $this->assertNull($document->blockById('b_002')->finalNumber);
    }
}
