<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Numbering;

use App\Document\Numbering\LowConfidenceReporter;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\CrossRef;
use App\Document\Structure\StructuralDocument;
use Tests\TestCase;

/**
 * Tests du rapport discret de fin de traitement.
 *
 * Le principe est un **critère d'acceptation de R4** : un renvoi ambigu doit
 * être signalé sans jamais bloquer l'utilisateur. Un document de 80 pages peut
 * contenir 40 renvois — demander confirmation pour chacun rendrait le service
 * inutilisable, alors que la renumérotation est censée supprimer le travail
 * manuel, pas en ajouter.
 */
class LowConfidenceReporterTest extends TestCase
{
    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: 'doc-1', sourceType: 'docx', blocks: $blocks);
    }

    private function renvoiIncertain(string $id, float $confidence = 0.55): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Paragraph,
            text: 'Voir Figure 3.',
            crossRef: (new CrossRef('Figure 3', BlockCategory::Figure, '3'))
                ->resolveWithLowConfidence('b_010', $confidence),
        );
    }

    private function renvoiNonResolu(string $id): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Paragraph,
            text: 'Voir Figure 9.',
            crossRef: new CrossRef('Figure 9', BlockCategory::Figure, '9'),
        );
    }

    // -------------------------------------------------------------------------
    // Le rapport n'est jamais bloquant
    // -------------------------------------------------------------------------

    public function test_le_rapport_n_exige_aucune_action_bloquante(): void
    {
        // Critère d'acceptation R4 : « aucun formulaire bloquant ». Le marqueur
        // est écrit dans le résultat pour rendre la garantie vérifiable.
        $report = (new LowConfidenceReporter)->report($this->document([
            $this->renvoiIncertain('b_001'),
        ]));

        $this->assertFalse($report['blocking']);
    }

    public function test_un_renvoi_incertain_produit_un_message_de_verification(): void
    {
        $report = (new LowConfidenceReporter)->report($this->document([
            $this->renvoiIncertain('b_001'),
        ]));

        $this->assertTrue($report['has_warnings']);
        $this->assertSame(1, $report['count']);
        $this->assertStringContainsString('à vérifier', (string) $report['message']);
        // Formulation non alarmiste : « à vérifier », pas « erreur » — une
        // résolution par proximité est une estimation raisonnable.
        $this->assertStringNotContainsString('erreur', mb_strtolower((string) $report['message']));
    }

    public function test_le_message_est_au_singulier_pour_un_seul_renvoi(): void
    {
        $report = (new LowConfidenceReporter)->report($this->document([
            $this->renvoiIncertain('b_001'),
        ]));

        $this->assertStringContainsString('1 renvoi résolu', (string) $report['message']);
        $this->assertStringNotContainsString('1 renvois', (string) $report['message']);
    }

    public function test_le_message_est_au_pluriel_pour_plusieurs_renvois(): void
    {
        $report = (new LowConfidenceReporter)->report($this->document([
            $this->renvoiIncertain('b_001'),
            $this->renvoiIncertain('b_002'),
            $this->renvoiIncertain('b_003'),
        ]));

        $this->assertStringContainsString('3 renvois', (string) $report['message']);
    }

    public function test_un_renvoi_non_resolu_est_signale(): void
    {
        $report = (new LowConfidenceReporter)->report($this->document([
            $this->renvoiNonResolu('b_001'),
        ]));

        $this->assertTrue($report['has_warnings']);
        $this->assertSame(1, $report['unresolved']);
        $this->assertStringContainsString('sans cible', (string) $report['message']);
    }

    public function test_un_document_sans_renvoi_incertain_n_a_pas_d_avertissement(): void
    {
        $report = (new LowConfidenceReporter)->report($this->document([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte ordinaire.'),
        ]));

        $this->assertFalse($report['has_warnings']);
        $this->assertNull($report['message']);
        $this->assertSame(0, $report['count']);
    }

    // -------------------------------------------------------------------------
    // Lisibilité du rapport
    // -------------------------------------------------------------------------

    public function test_l_enumeration_est_bornee_pour_rester_lisible(): void
    {
        // Au-delà de MAX_DETAILED, la liste devient illisible et l'utilisateur
        // ne la lit plus : on garde le compte exact, mais on borne le détail.
        $blocks = [];
        for ($i = 1; $i <= 12; $i++) {
            $blocks[] = $this->renvoiIncertain('b_0'.$i);
        }

        $report = (new LowConfidenceReporter)->report($this->document($blocks));

        $this->assertSame(12, $report['count']);
        $this->assertCount(LowConfidenceReporter::MAX_DETAILED, $report['details']);
        $this->assertSame(12 - LowConfidenceReporter::MAX_DETAILED, $report['truncated']);
    }

    public function test_chaque_detail_indique_le_bloc_et_le_texte_du_renvoi(): void
    {
        // Sans le texte du renvoi, l'utilisateur ne saurait pas quoi vérifier.
        $report = (new LowConfidenceReporter)->report($this->document([
            $this->renvoiIncertain('b_001'),
        ]));

        $detail = $report['details'][0];

        $this->assertSame('b_001', $detail['block_id']);
        $this->assertSame('Figure 3', $detail['matched_text']);
        $this->assertLessThan(0.7, $detail['confidence']);
    }

    public function test_le_rapport_utilise_la_liste_fournie_quand_elle_existe(): void
    {
        $report = (new LowConfidenceReporter)->report(
            $this->document([]),
            [['block_id' => 'b_099', 'matched_text' => 'Figure 5', 'confidence' => 0.4]],
        );

        $this->assertSame(1, $report['count']);
        $this->assertSame('b_099', $report['details'][0]['block_id']);
    }

    public function test_le_rapport_retrouve_seul_les_renvois_incertains_du_document(): void
    {
        // Utilisable sans passer la liste en paramètre.
        $report = (new LowConfidenceReporter)->report($this->document([
            $this->renvoiIncertain('b_001', 0.55),
            $this->renvoiNonResolu('b_002'),
        ]));

        $this->assertSame(1, $report['count']);
        $this->assertSame('b_001', $report['details'][0]['block_id']);
    }

    // -------------------------------------------------------------------------
    // Résumé de renumérotation
    // -------------------------------------------------------------------------

    public function test_le_resume_indique_le_nombre_par_categorie(): void
    {
        $resume = (new LowConfidenceReporter)->numberingSummary([
            'figure' => 12,
            'table' => 8,
        ]);

        $this->assertNotNull($resume);
        $this->assertStringContainsString('12 figures', $resume);
        $this->assertStringContainsString('8 tableaux', $resume);
    }

    public function test_le_resume_signale_les_doublons_corriges(): void
    {
        // L'utilisateur voit que le système a corrigé quelque chose — c'est ce
        // qui rend la renumérotation vérifiable plutôt qu'opaque.
        $resume = (new LowConfidenceReporter)->numberingSummary(
            ['table' => 10],
            ['duplicates' => ['table' => ['2', '2']]],
        );

        $this->assertStringContainsString('dupliqué', (string) $resume);
    }

    public function test_le_resume_est_null_sans_categorie(): void
    {
        $this->assertNull((new LowConfidenceReporter)->numberingSummary([]));
    }

    public function test_un_resume_en_anglais_reste_lisible(): void
    {
        // Le résumé se construit sur les identifiants internes des catégories.
        $resume = (new LowConfidenceReporter)->numberingSummary(['annexe' => 1]);

        $this->assertStringContainsString('1 annexe', (string) $resume);
    }
}
