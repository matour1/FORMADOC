<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Classification;

use App\Document\Classification\BlockClassifier;
use App\Document\Classification\ClassificationPolicy;
use App\Document\Classification\DetectBlocksTool;
use App\Document\Classification\SignalAggregator;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use App\Services\OpenRouter\OpenRouterService;
use Tests\TestCase;

/**
 * Tests de l'orchestrateur de classification.
 *
 * Deux garanties sont vérifiées ici :
 *  1. **le gain budgétaire** — les blocs sûrs ne sont jamais envoyés au modèle ;
 *  2. **aucune perte de contenu** — quelle que soit la défaillance de l'IA
 *     (clé absente, API en panne, réponse illisible), la structure déterministe
 *     est conservée et utilisable.
 */
class BlockClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aucune clé API : les tests restent hors ligne et déterministes.
        config(['openrouter.api_key' => '']);
    }

    private function classifier(): BlockClassifier
    {
        return new BlockClassifier(
            new SignalAggregator,
            new DetectBlocksTool(app(OpenRouterService::class)),
            new ClassificationPolicy,
        );
    }

    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: $blocks,
        );
    }

    private function block(string $id, string $text, bool $bold = false, ?int $level = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Paragraph,
            text: $text,
            isBold: $bold,
            headingLevel: $level,
        );
    }

    // -------------------------------------------------------------------------
    // Gain budgétaire
    // -------------------------------------------------------------------------

    public function test_les_blocs_surs_ne_sont_pas_envoyes_au_modele(): void
    {
        // Sans clé API, tout appel réel échouerait. Le fait que le rapport
        // indique 0 bloc consulté prouve qu'aucun appel n'a été tenté.
        $document = $this->document([
            $this->block('b_0001', '1. Introduction'),
            $this->block('b_0002', 'Figure 1 : Schéma du système'),
            $this->block('b_0003', 'Ce paragraphe décrit la méthodologie retenue pour collecter les données, en combinant entretiens et analyse documentaire.'),
        ]);

        $result = $this->classifier()->classify($document, 'default', aiEnabled: true);

        $this->assertSame(0, $result['report']['ai_consulted']);
        $this->assertSame(3, $result['report']['auto_applied']);
        $this->assertSame(1.0, $result['report']['free_ratio']);
    }

    public function test_le_rapport_expose_le_taux_de_traitement_gratuit(): void
    {
        $document = $this->document([
            $this->block('b_0001', '1. Introduction'),
            $this->block('b_0002', 'Introduction'),
        ]);

        $report = $this->classifier()->classify($document, 'default', aiEnabled: false)['report'];

        $this->assertSame(2, $report['total']);
        $this->assertSame(1, $report['auto_applied']);
        $this->assertSame(0.5, $report['free_ratio']);
    }

    public function test_l_ia_n_est_pas_consultée_si_elle_n_est_pas_demandée(): void
    {
        // L'IA reste OPTIONNELLE : sans activation explicite, le pipeline est
        // 100 % déterministe et gratuit.
        $document = $this->document([$this->block('b_0001', 'Introduction')]);

        $result = $this->classifier()->classify($document, 'default', aiEnabled: false);

        $this->assertSame(0, $result['report']['ai_consulted']);
        $this->assertStringContainsString('non demandée', (string) $result['report']['ai_skipped_reason']);
    }

    public function test_l_absence_de_cle_api_n_empeche_pas_le_traitement(): void
    {
        // Garantie essentielle : sans clé API, le pipeline bascule en mode
        // déterministe sans erreur (règle du projet : le déterministe ne
        // s'arrête jamais).
        config(['openrouter.api_key' => '']);

        $document = $this->document([
            $this->block('b_0001', '1. Introduction'),
            $this->block('b_0002', 'Introduction'),
        ]);

        $result = $this->classifier()->classify($document, 'default', aiEnabled: true);

        $this->assertSame(2, $result['document']->count(), 'Aucun bloc ne doit être perdu');
        $this->assertSame(0.0, $result['report']['ai_cost_usd']);
    }

    // -------------------------------------------------------------------------
    // Application de la classification
    // -------------------------------------------------------------------------

    public function test_la_classification_met_a_jour_les_types(): void
    {
        $document = $this->document([
            $this->block('b_0001', '1. Introduction'),
            $this->block('b_0002', 'Figure 1 : Schéma'),
        ]);

        $result = $this->classifier()->classify($document, 'default', aiEnabled: false);

        $this->assertSame(BlockType::Heading, $result['document']->blockById('b_0001')?->type);
        $this->assertSame(BlockType::Caption, $result['document']->blockById('b_0002')?->type);
    }

    public function test_la_classification_preserve_le_texte_a_l_identique(): void
    {
        // Règle absolue : classifier n'est PAS reformuler. Le texte doit sortir
        // octet pour octet identique.
        $text = '1.1 Contexte institutionnel — étude menée au Centre de Formation';
        $document = $this->document([$this->block('b_0001', $text)]);

        $result = $this->classifier()->classify($document, 'default', aiEnabled: false);

        $this->assertSame($text, $result['document']->blockById('b_0001')?->text);
    }

    public function test_la_classification_preserve_le_contenu_des_tableaux(): void
    {
        // Un tableau ne doit JAMAIS perdre ses données : le contenu est sa
        // raison d'être.
        $table = new Block(
            blockId: 'b_0001',
            type: BlockType::Table,
            text: 'A | B',
            tableData: TableData::fromGrid([['A', 'B'], ['1', '2']]),
        );

        $result = $this->classifier()->classify($this->document([$table]), 'default', aiEnabled: false);

        $classified = $result['document']->blockById('b_0001');

        $this->assertSame(BlockType::Table, $classified?->type);
        $this->assertSame('1', $classified?->tableData?->cell(1, 0));
    }

    public function test_les_blocs_ambigus_sont_listes_pour_clarification(): void
    {
        $document = $this->document([
            $this->block('b_0001', '1. Introduction'),
            $this->block('b_0002', 'Introduction'),
            $this->block('b_0003', 'Méthodologie'),
        ]);

        $report = $this->classifier()->classify($document, 'default', aiEnabled: false)['report'];

        $this->assertCount(2, $report['clarification_needed']);
        $this->assertContains('b_0002', $report['clarification_needed']);
        $this->assertContains('b_0003', $report['clarification_needed']);
    }

    public function test_un_document_vide_est_gerere_sans_erreur(): void
    {
        $result = $this->classifier()->classify($this->document([]), 'default', aiEnabled: true);

        $this->assertSame(0, $result['report']['total']);
        $this->assertSame(1.0, $result['report']['free_ratio']);
        $this->assertSame([], $result['report']['clarification_needed']);
    }

    // -------------------------------------------------------------------------
    // Robustesse (jamais de perte)
    // -------------------------------------------------------------------------

    public function test_une_erreur_du_modele_ne_perd_aucun_bloc(): void
    {
        // Une clé bidon provoquera un échec réseau : la structure déterministe
        // doit être intégralement conservée.
        config(['openrouter.api_key' => 'cle_invalide_pour_test']);

        $document = $this->document([
            $this->block('b_0001', '1. Introduction'),
            $this->block('b_0002', 'Introduction'),
        ]);

        $result = $this->classifier()->classify($document, 'default', aiEnabled: true);

        $this->assertSame(2, $result['document']->count());
        $this->assertSame(BlockType::Heading, $result['document']->blockById('b_0001')?->type);
    }

    public function test_le_rapport_trace_le_cout_de_l_ia(): void
    {
        // La traçabilité du coût est exigée par la spécification (§12) : chaque
        // appel doit être mesuré, jamais estimé.
        $document = $this->document([$this->block('b_0001', '1. Introduction')]);

        $report = $this->classifier()->classify($document, 'default', aiEnabled: false)['report'];

        $this->assertArrayHasKey('ai_cost_usd', $report);
        $this->assertArrayHasKey('ai_cost_credits', $report);
        $this->assertSame(0.0, $report['ai_cost_usd']);
    }

    public function test_les_metadonnees_du_document_sont_conservees(): void
    {
        $document = new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [$this->block('b_0001', '1. Introduction')],
            meta: ['gabarit' => 'memoire'],
        );

        $result = $this->classifier()->classify($document, 'default', aiEnabled: false);

        $this->assertSame('memoire', $result['document']->meta['gabarit']);
    }
}
