<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Classification;

use App\Document\Classification\SignalAggregator;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests de l'agrégateur de signaux déterministes.
 *
 * **Enjeu budgétaire.** Ce composant décide quels blocs sont classés
 * gratuitement — il évite des milliers d'appels au modèle de langage. Ces tests
 * verrouillent donc deux propriétés :
 *
 * 1. les cas ÉVIDENTS sont classés avec une confiance haute (aucun token) ;
 * 2. les cas réellement AMBIGUS restent sous le seuil (une question à poser).
 */
class SignalAggregatorTest extends TestCase
{
    private SignalAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aggregator = new SignalAggregator;
    }

    private function paragraph(string $text, bool $bold = false): Block
    {
        return new Block(
            blockId: 'b_0001',
            type: BlockType::Paragraph,
            text: $text,
            isBold: $bold,
        );
    }

    // -------------------------------------------------------------------------
    // Types structurels : le XML est définitif
    // -------------------------------------------------------------------------

    public function test_un_tableau_reste_un_tableau_malgre_un_texte_de_phrase(): void
    {
        // Régression critique évitée : le texte aplati d'un tableau
        // (« Tâche | Durée | Statut Analyse | 2 semaines… ») ressemble à une
        // phrase longue et se ferait reclasser en paragraphe. Le tableau
        // DISPARAÎTRAIT alors de la structure.
        $block = new Block(
            blockId: 'b_0001',
            type: BlockType::Table,
            text: 'Raison sociale | RELAIS MULTISERVICES | Forme juridique | SARL | Siege social | TAMDJA immeuble Beauty',
            tableData: TableData::fromGrid([['Raison sociale', 'SARL']]),
        );

        $assessment = $this->aggregator->assess($block);

        $this->assertSame(BlockType::Table, $assessment['type']);
        $this->assertTrue($this->aggregator->assess($block)['confidence'] >= 0.85);
        $this->assertFalse($assessment['needs_ai'], 'Un tableau est un signal XML définitif');
    }

    public function test_un_en_tete_reste_un_en_tete(): void
    {
        $block = new Block(blockId: 'b_0001', type: BlockType::Header, text: 'RAPPORT DE STAGE');

        $assessment = $this->aggregator->assess($block);

        $this->assertSame(BlockType::Header, $assessment['type']);
        $this->assertFalse($assessment['needs_ai']);
    }

    public function test_un_pied_de_page_reste_un_pied_de_page(): void
    {
        $block = new Block(blockId: 'b_0001', type: BlockType::Footer, text: 'Page 1');

        $this->assertSame(BlockType::Footer, $this->aggregator->assess($block)['type']);
    }

    // -------------------------------------------------------------------------
    // Pattern texte (priorité absolue)
    // -------------------------------------------------------------------------

    public function test_une_legende_est_classee_sans_ia(): void
    {
        $assessment = $this->aggregator->assess($this->paragraph('Figure 1 : Architecture du système'));

        $this->assertSame(BlockType::Caption, $assessment['type']);
        $this->assertSame('figure', $assessment['category']);
        $this->assertFalse($assessment['needs_ai']);
        $this->assertGreaterThanOrEqual(0.9, $assessment['confidence']);
    }

    public function test_une_legende_de_tableau_est_classee_sans_ia(): void
    {
        $assessment = $this->aggregator->assess($this->paragraph('Tableau 3 : Résultats de l\'enquête'));

        $this->assertSame(BlockType::Caption, $assessment['type']);
        $this->assertSame('table', $assessment['category']);
        $this->assertFalse($assessment['needs_ai']);
    }

    public function test_une_numerotation_de_section_est_classee_sans_ia(): void
    {
        $assessment = $this->aggregator->assess($this->paragraph('1.1 Contexte institutionnel'));

        $this->assertSame(BlockType::Heading, $assessment['type']);
        $this->assertSame(2, $assessment['heading_level']);
        $this->assertFalse($assessment['needs_ai']);
    }

    public function test_un_mot_cle_chapitre_est_classe_sans_ia(): void
    {
        $assessment = $this->aggregator->assess($this->paragraph('CHAPITRE II : DEROULEMENT DU STAGE'));

        $this->assertSame(BlockType::Heading, $assessment['type']);
        $this->assertFalse($assessment['needs_ai']);
    }

    // -------------------------------------------------------------------------
    // Style Word
    // -------------------------------------------------------------------------

    public function test_un_style_de_titre_word_est_classe_sans_ia(): void
    {
        $block = new Block(
            blockId: 'b_0001',
            type: BlockType::Paragraph,
            text: 'Introduction générale',
            headingLevel: 1,
        );

        $assessment = $this->aggregator->assess($block);

        $this->assertSame(BlockType::Heading, $assessment['type']);
        $this->assertSame(1, $assessment['heading_level']);
        $this->assertFalse($assessment['needs_ai']);
    }

    // -------------------------------------------------------------------------
    // Contradictions (le cas révélateur du projet)
    // -------------------------------------------------------------------------

    public function test_une_contradiction_style_numerotation_declenche_une_question(): void
    {
        // Cas réel : style « Titre 1 » (niveau 1) mais texte numéroté « 1.1 »
        // (niveau 2). On retient le niveau de la NUMÉROTATION (l'intention de
        // l'auteur), mais la confiance chute sous le seuil.
        $block = new Block(
            blockId: 'b_0001',
            type: BlockType::Heading,
            text: '1.1 Institution',
            headingLevel: 1,
        );

        $assessment = $this->aggregator->assess($block);

        $this->assertSame(2, $assessment['heading_level'], 'La numérotation prime (intention de l\'auteur)');
        $this->assertLessThan(0.7, $assessment['confidence'], 'Confiance < 0,7 exigée par la spec §7');
        $this->assertTrue($assessment['needs_ai']);
        $this->assertStringContainsString('Contradiction', $assessment['reason']);
    }

    public function test_style_et_numerotation_concordants_ne_declenchent_aucune_question(): void
    {
        $block = new Block(
            blockId: 'b_0001',
            type: BlockType::Heading,
            text: '2.1 Historique',
            headingLevel: 2,
        );

        $assessment = $this->aggregator->assess($block);

        $this->assertSame(2, $assessment['heading_level']);
        $this->assertGreaterThanOrEqual(0.95, $assessment['confidence']);
        $this->assertFalse($assessment['needs_ai']);
    }

    // -------------------------------------------------------------------------
    // Cas évidents : ne JAMAIS payer un appel pour confirmer l'évidence
    // -------------------------------------------------------------------------

    public function test_un_paragraphe_de_phrase_est_classe_avec_une_confiance_haute(): void
    {
        // Erreur corrigée en cours de développement : appliquer une pénalité à
        // un paragraphe évident faisait payer un appel IA pour confirmer que
        // « ceci est un paragraphe ». Le gain budgétaire tombait de 68 % à 33 %.
        $assessment = $this->aggregator->assess($this->paragraph(
            'Cette solution permet de centraliser les informations, de réduire les délais '
            .'de traitement et d\'améliorer la traçabilité des dossiers patients.'
        ));

        $this->assertSame(BlockType::Paragraph, $assessment['type']);
        $this->assertFalse($assessment['needs_ai'], 'Un paragraphe évident ne doit pas coûter de tokens');
        $this->assertGreaterThanOrEqual(0.85, $assessment['confidence']);
    }

    public function test_un_texte_long_est_classe_comme_paragraphe(): void
    {
        $text = str_repeat('Le projet a été mené dans une entreprise locale. ', 6);

        $assessment = $this->aggregator->assess($this->paragraph($text));

        $this->assertSame(BlockType::Paragraph, $assessment['type']);
        $this->assertFalse($assessment['needs_ai']);
    }

    public function test_une_phrase_courte_avec_ponctuation_finale_reste_ambigue_si_elle_est_seule(): void
    {
        // « Bonjour. » : court, avec ponctuation. Aucun signal décisif.
        $assessment = $this->aggregator->assess($this->paragraph('Ceci est un test.'));

        $this->assertSame(BlockType::Paragraph, $assessment['type']);
        $this->assertTrue($assessment['needs_ai'] || $assessment['confidence'] >= 0.85);
    }

    // -------------------------------------------------------------------------
    // Ambiguïtés réelles
    // -------------------------------------------------------------------------

    public function test_une_ligne_courte_sans_signal_reste_ambigue(): void
    {
        // « Introduction » seul : titre ou simple ligne de texte ? Aucun signal
        // ne permet de trancher, c'est une vraie ambiguïté à clarifier.
        $assessment = $this->aggregator->assess($this->paragraph('Introduction'));

        $this->assertTrue($assessment['needs_ai']);
    }

    public function test_une_ligne_courte_en_gras_est_proposee_comme_titre_mais_reste_a_confirmer(): void
    {
        $assessment = $this->aggregator->assess($this->paragraph('Étude de marché', bold: true));

        $this->assertSame(BlockType::Heading, $assessment['type']);
        $this->assertTrue($assessment['needs_ai'], 'Le gras seul ne suffit pas à trancher');
    }

    public function test_une_phrase_longue_en_gras_est_un_paragraphe_ambigu(): void
    {
        $assessment = $this->aggregator->assess($this->paragraph(
            'Cette section présente la méthodologie retenue pour la collecte des données, '
            .'qui combine des entretiens semi-directifs et une analyse documentaire approfondie.',
            bold: true,
        ));

        $this->assertSame(BlockType::Paragraph, $assessment['type']);
        $this->assertTrue($assessment['needs_ai']);
    }

    // -------------------------------------------------------------------------
    // Images : figure numérotée contre image décorative
    // -------------------------------------------------------------------------

    public function test_une_image_avec_legende_devient_une_figure(): void
    {
        $block = new Block(
            blockId: 'b_0001',
            type: BlockType::Figure,
            text: '[image:rId5.img] Figure 2 : Schéma du pipeline',
            imageRef: 'rId5.img',
            originalNumber: '2',
        );

        $assessment = $this->aggregator->assess($block);

        $this->assertSame(BlockType::Figure, $assessment['type']);
        $this->assertFalse($assessment['needs_ai']);
    }

    public function test_une_image_sans_legende_est_decoration(): void
    {
        $block = new Block(
            blockId: 'b_0001',
            type: BlockType::Image,
            text: '[image:rId5.img]',
            imageRef: 'rId5.img',
        );

        $assessment = $this->aggregator->assess($block);

        $this->assertSame(BlockType::Image, $assessment['type']);
        $this->assertFalse($assessment['needs_ai']);
    }

    // -------------------------------------------------------------------------
    // Partition (décision budgétaire globale)
    // -------------------------------------------------------------------------

    public function test_la_partition_separe_les_blocs_surs_des_ambigus(): void
    {
        $blocks = [
            $this->paragraph('1. Introduction'),          // sûr (numérotation)
            $this->paragraph('Figure 1 : Schéma'),        // sûr (légende)
            $this->paragraph('Introduction'),             // ambigu (ligne courte)
        ];

        $partition = $this->aggregator->partition($blocks);

        $this->assertCount(2, $partition['confident']);
        $this->assertCount(1, $partition['ambiguous']);
        $this->assertSame(3, $partition['stats']['total']);
    }

    public function test_la_partition_expose_le_taux_de_traitement_gratuit(): void
    {
        // C'est LA métrique budgétaire du pipeline : la part des blocs classés
        // sans aucun appel au modèle.
        $blocks = [
            $this->paragraph('1. Introduction'),
            $this->paragraph('Contenu courant de la section.'),
            $this->paragraph('Introduction'),
            $this->paragraph('Méthodologie'),
        ];

        $stats = $this->aggregator->partition($blocks)['stats'];

        $this->assertSame(0.5, $stats['free_ratio']);
    }

    public function test_la_partition_accepte_un_seuil_personnalise(): void
    {
        $blocks = [$this->paragraph('Introduction')];

        $this->assertCount(1, $this->aggregator->partition($blocks, 0.5)['confident']);
        $this->assertCount(1, $this->aggregator->partition($blocks, 0.95)['ambiguous']);
    }

    public function test_une_partition_vide_ne_divise_pas_par_zero(): void
    {
        $stats = $this->aggregator->partition([])['stats'];

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0.0, $stats['free_ratio']);
    }

    // -------------------------------------------------------------------------
    // Contexte pour le modèle (réduction des tokens)
    // -------------------------------------------------------------------------

    public function test_le_contexte_envoye_au_modele_est_borne(): void
    {
        // On n'envoie pas 3 000 caractères pour deviner s'il s'agit d'un titre :
        // cela réduirait l'économie de tokens obtenue par le pré-filtrage.
        $block = new Block(
            blockId: 'b_0001',
            type: BlockType::Paragraph,
            text: str_repeat('Texte très long. ', 100),
        );

        $context = $this->aggregator->contextFor($block);

        $this->assertLessThanOrEqual(160, mb_strlen($context['text']));
        $this->assertSame('b_0001', $context['block_id']);
        $this->assertArrayHasKey('font_size', $context);
        $this->assertArrayHasKey('is_bold', $context);
        $this->assertArrayHasKey('position_y', $context);
    }

    public function test_le_contexte_contient_les_signaux_structurels(): void
    {
        $block = new Block(
            blockId: 'b_0042',
            type: BlockType::Paragraph,
            text: 'Texte',
            fontSize: 14.0,
            isBold: true,
            indentLevel: 2,
        );

        $context = $this->aggregator->contextFor($block);

        $this->assertSame(14.0, $context['font_size']);
        $this->assertTrue($context['is_bold']);
        $this->assertSame(2, $context['indent_level']);
    }
}
