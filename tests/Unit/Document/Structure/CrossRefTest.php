<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Structure;

use App\Document\Structure\BlockCategory;
use App\Document\Structure\CrossRef;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests des décisions portées par CrossRef.
 *
 * Règle produit centrale : un renvoi ambigu est signalé discrètement, jamais
 * bloquant. Le seuil de 0,7 détermine ce qui remonte dans le rapport de fin
 * de traitement.
 */
class CrossRefTest extends TestCase
{
    private function crossRef(string $number = '3'): CrossRef
    {
        return new CrossRef(
            matchedText: "voir Figure {$number}",
            targetCategory: BlockCategory::Figure,
            targetOriginalNumber: $number,
        );
    }

    public function test_un_renvoi_non_resolu_n_est_pas_considere_comme_resolu(): void
    {
        $crossRef = $this->crossRef();

        $this->assertFalse($crossRef->isResolved());
        $this->assertNull($crossRef->resolvedBlockId);
        $this->assertFalse($crossRef->isLowConfidence());
    }

    public function test_une_resolution_directe_porte_une_confiance_maximale(): void
    {
        $resolved = $this->crossRef()->resolveTo('b_012');

        $this->assertTrue($resolved->isResolved());
        $this->assertSame('b_012', $resolved->resolvedBlockId);
        $this->assertSame(1.0, $resolved->resolutionConfidence);
        $this->assertFalse($resolved->isLowConfidence());
    }

    public function test_une_resolution_ambigue_sous_le_seuil_est_signalee(): void
    {
        // Proximité non concluante → confiance faible, signalement discret.
        $resolved = $this->crossRef()->resolveWithLowConfidence('b_020', 0.6);

        $this->assertTrue($resolved->isResolved());
        $this->assertTrue($resolved->isLowConfidence());
    }

    public function test_une_resolution_a_la_confiance_exactement_au_seuil_n_est_pas_signalee(): void
    {
        // Le seuil est un minimum strict : 0,7 est accepté sans signalement.
        $resolved = $this->crossRef()->resolveWithLowConfidence('b_020', 0.7);

        $this->assertFalse($resolved->isLowConfidence());
    }

    public function test_une_confiance_de_resolution_hors_bornes_est_rejetee(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('entre 0 et 1');

        $this->crossRef()->resolveWithLowConfidence('b_020', 1.5);
    }

    public function test_une_resolution_ne_modifie_pas_le_renvoi_d_origine(): void
    {
        $original = $this->crossRef();
        $original->resolveTo('b_012');

        $this->assertFalse($original->isResolved());
    }

    public function test_le_texte_origine_est_conserve_tel_quel(): void
    {
        $crossRef = new CrossRef(
            matchedText: 'cf. Annexe B du présent rapport',
            targetCategory: BlockCategory::Annexe,
            targetOriginalNumber: 'B',
        );

        $this->assertSame('cf. Annexe B du présent rapport', $crossRef->matchedText);
        $this->assertSame('B', $crossRef->targetOriginalNumber);
        $this->assertSame(BlockCategory::Annexe, $crossRef->targetCategory);
    }

    public function test_le_schema_json_utilise_les_cles_du_format_commun(): void
    {
        $array = $this->crossRef()->toArray();

        $this->assertSame([
            'matched_text' => 'voir Figure 3',
            'target_category' => 'figure',
            'target_original_number' => '3',
            'resolved_block_id' => null,
            'resolution_confidence' => null,
        ], $array);
    }

    public function test_un_renvoi_resolu_survit_a_un_aller_retour_json(): void
    {
        $original = $this->crossRef()->resolveWithLowConfidence('b_020', 0.65);
        $restored = CrossRef::fromArray($original->toArray());

        $this->assertSame('b_020', $restored->resolvedBlockId);
        $this->assertSame(0.65, $restored->resolutionConfidence);
        $this->assertSame(BlockCategory::Figure, $restored->targetCategory);
        $this->assertTrue($restored->isLowConfidence());
    }

    public function test_un_renvoi_non_resolu_survit_a_un_aller_retour_json(): void
    {
        $original = $this->crossRef();
        $restored = CrossRef::fromArray($original->toArray());

        $this->assertFalse($restored->isResolved());
        $this->assertNull($restored->resolutionConfidence);
        $this->assertSame('3', $restored->targetOriginalNumber);
    }
}
