<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Classification;

use App\Document\Classification\HeadingClassifier;
use App\Document\Classification\RuleBasedHeadingClassifier;
use App\Document\Classification\SignalAggregator;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use Tests\TestCase;

/**
 * Vérifie le contrat `HeadingClassifier` et son implémentation déterministe
 * `RuleBasedHeadingClassifier` (§3 des instructions & décision D-008).
 */
class HeadingClassifierTest extends TestCase
{
    public function test_le_container_resout_heading_classifier_vers_l_implementation_par_regles(): void
    {
        $classifier = $this->app->make(HeadingClassifier::class);

        $this->assertInstanceOf(RuleBasedHeadingClassifier::class, $classifier);
        $this->assertSame($classifier, $this->app->make(HeadingClassifier::class));
    }

    public function test_un_titre_avec_style_et_numerotation_concordants_est_accepte_sans_ia(): void
    {
        $classifier = new RuleBasedHeadingClassifier(new SignalAggregator(preserveExistingHeadingConfidence: false));

        $block = new Block(
            blockId: 'b_1',
            type: BlockType::Heading,
            text: '1.1 Cadre méthodologique',
            headingLevel: 2,
            confidence: 0.9,
        );

        $verdict = $classifier->classifyHeading($block);

        $this->assertTrue($verdict['is_heading']);
        $this->assertSame(BlockType::Heading, $verdict['type']);
        $this->assertSame(2, $verdict['heading_level']);
        $this->assertSame(0.98, $verdict['confidence']);
        $this->assertFalse($verdict['needs_ai']);
        $this->assertTrue($classifier->isConfidentHeading($block));
    }

    public function test_une_contradiction_style_numerotation_marque_le_titre_comme_incertain_pour_ia(): void
    {
        $classifier = new RuleBasedHeadingClassifier(new SignalAggregator(preserveExistingHeadingConfidence: false));

        $block = new Block(
            blockId: 'b_2',
            type: BlockType::Heading,
            text: '1.1 Historique de l\'entreprise',
            headingLevel: 1,
            confidence: 0.65,
        );

        $verdict = $classifier->classifyHeading($block);

        $this->assertTrue($verdict['is_heading']);
        $this->assertSame(2, $verdict['heading_level']);
        $this->assertSame(0.65, $verdict['confidence']);
        $this->assertTrue($verdict['needs_ai']);
        $this->assertFalse($classifier->isConfidentHeading($block));
    }

    public function test_une_civilite_m_n_est_jamais_classee_comme_titre(): void
    {
        $classifier = $this->app->make(HeadingClassifier::class);

        $block = new Block(
            blockId: 'b_3',
            type: BlockType::Paragraph,
            text: 'M. ZIVO Desmond, promoteur de la structure, nous a accueillis.',
            confidence: 0.85,
        );

        $verdict = $classifier->classifyHeading($block);

        $this->assertFalse($verdict['is_heading']);
        $this->assertSame(BlockType::Paragraph, $verdict['type']);
        $this->assertNull($verdict['heading_level']);
        $this->assertFalse($classifier->isConfidentHeading($block));
    }

    public function test_une_ligne_isolee_en_gras_est_detectee_comme_titre_incertain_pour_future_ia(): void
    {
        $classifier = $this->app->make(HeadingClassifier::class);

        $block = new Block(
            blockId: 'b_4',
            type: BlockType::Paragraph,
            text: 'Organisation interne du service',
            isBold: true,
            confidence: 0.6,
        );

        $verdict = $classifier->classifyHeading($block);

        $this->assertTrue($verdict['is_heading']);
        $this->assertSame(BlockType::Heading, $verdict['type']);
        $this->assertTrue($verdict['needs_ai']);
        $this->assertFalse($classifier->isConfidentHeading($block));
    }
}

