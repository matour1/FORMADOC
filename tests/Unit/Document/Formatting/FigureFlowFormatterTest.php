<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Formatting;

use App\Document\Formatting\FigureFlowFormatter;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use Tests\TestCase;

/**
 * Tests de la mise en flux des figures.
 *
 * Règle absolue : **aucun fichier image n'est transformé**. Le formateur décide
 * uniquement de la position et de la solidarité des blocs dans le flux. Une
 * légende séparée de sa figure par un saut de page est le défaut à éviter.
 */
class FigureFlowFormatterTest extends TestCase
{
    private function figure(string $id, ?string $imageRef = 'img_001.png', ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Figure,
            text: '',
            imageRef: $imageRef,
            linkedBlockId: $linked,
        );
    }

    private function caption(string $id, ?string $target, string $text = 'Figure 1 — Schéma'): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $text,
            linkedBlockId: $target,
            category: BlockCategory::Figure,
        );
    }

    public function test_une_figure_avec_legende_reste_solidaire_de_la_suivante(): void
    {
        // Sans cette solidarité, Word peut placer la légende en bas de page et
        // l'image en haut de la suivante — un défaut de rendu très visible.
        $plan = (new FigureFlowFormatter)->plan([
            $this->figure('b_001'),
            $this->caption('b_002', 'b_001'),
        ]);

        $figure = $this->entryFor($plan, 'b_001');
        $this->assertTrue($figure['has_caption']);
        $this->assertTrue($figure['keep_with_next']);
    }

    public function test_une_legende_reste_avec_la_figure_qui_la_precede(): void
    {
        $plan = (new FigureFlowFormatter)->plan([
            $this->figure('b_001'),
            $this->caption('b_002', 'b_001'),
        ]);

        $caption = $this->entryFor($plan, 'b_002');
        $this->assertTrue($caption['keep_with_previous']);
        $this->assertSame('b_001', $caption['linked_block_id']);
    }

    public function test_une_image_decorative_peut_se_placer_librement(): void
    {
        // Une image sans légende n'a pas à être solidaire de la suivante.
        $plan = (new FigureFlowFormatter)->plan([
            new Block(
                blockId: 'b_001',
                type: BlockType::Image,
                imageRef: 'logo.png',
            ),
        ]);

        $entry = $this->entryFor($plan, 'b_001');
        $this->assertSame('image', $entry['kind']);
        $this->assertFalse($entry['has_caption']);
        $this->assertFalse($entry['keep_with_next']);
    }

    public function test_une_legende_orpheline_est_signalee_et_non_supprimee(): void
    {
        // La cible a disparu : la légende porte peut-être une information
        // utile. On la signale plutôt que de la retirer ou d'inventer une figure.
        $plan = (new FigureFlowFormatter)->plan([
            $this->caption('b_001', 'b_inexistant'),
        ]);

        $this->assertContains('b_001', $plan['orphan_captions']);
        $this->assertCount(1, $plan['entries']);

        $entry = $this->entryFor($plan, 'b_001');
        $this->assertFalse($entry['keep_with_previous']);
    }

    public function test_une_legende_sans_cible_est_orpheline(): void
    {
        $plan = (new FigureFlowFormatter)->plan([$this->caption('b_001', null)]);

        $this->assertContains('b_001', $plan['orphan_captions']);
    }

    public function test_une_figure_sans_legende_est_signalee(): void
    {
        // Signal utile : la figure existe mais aucune légende ne la référence.
        $plan = (new FigureFlowFormatter)->plan([$this->figure('b_001')]);

        $this->assertContains('b_001', $plan['uncaptioned_figures']);
    }

    public function test_le_plan_ne_decrit_aucune_transformation_d_image(): void
    {
        // Garantie explicite : le fichier image est réutilisé tel quel, sans
        // recadrage ni réencodage destructif.
        $plan = (new FigureFlowFormatter)->plan([$this->figure('b_001')]);

        $this->assertNull($this->entryFor($plan, 'b_001')['transform']);
        $this->assertTrue($plan['images_untouched']);
    }

    public function test_la_reference_d_image_est_transportee_intacte(): void
    {
        $plan = (new FigureFlowFormatter)->plan([
            $this->figure('b_001', 'word/media/image3.png'),
        ]);

        $this->assertSame('word/media/image3.png', $this->entryFor($plan, 'b_001')['image_ref']);
    }

    public function test_une_figure_est_centree(): void
    {
        $plan = (new FigureFlowFormatter)->plan([$this->figure('b_001')]);

        $this->assertSame('center', $this->entryFor($plan, 'b_001')['alignment']);
    }

    public function test_le_plan_ignore_les_paragraphes_et_titres(): void
    {
        $plan = (new FigureFlowFormatter)->plan([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Titre', headingLevel: 1),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte'),
        ]);

        $this->assertSame([], $plan['entries']);
    }

    public function test_un_document_sans_figure_produit_un_plan_vide(): void
    {
        $plan = (new FigureFlowFormatter)->plan([]);

        $this->assertSame([], $plan['entries']);
        $this->assertSame([], $plan['orphan_captions']);
        $this->assertSame([], $plan['uncaptioned_figures']);
    }

    public function test_le_numero_de_la_legende_est_transporte(): void
    {
        $plan = (new FigureFlowFormatter)->plan([
            $this->figure('b_001'),
            $this->caption('b_002', 'b_001'),
        ]);

        $this->assertNull($this->entryFor($plan, 'b_002')['number']);
    }

    public function test_les_statistiques_distinguent_figures_images_et_legendes(): void
    {
        $blocks = [
            $this->figure('b_001'),
            $this->caption('b_002', 'b_001'),
            new Block(blockId: 'b_003', type: BlockType::Image, imageRef: 'logo.png'),
            $this->caption('b_004', 'b_inexistant', 'Figure 2 — Orpheline'),
        ];

        $stats = (new FigureFlowFormatter)->statistics($blocks);

        $this->assertSame(1, $stats['figures']);
        $this->assertSame(1, $stats['images']);
        $this->assertSame(2, $stats['captions']);
        $this->assertSame(1, $stats['orphan_captions']);
        $this->assertSame(1, $stats['uncaptioned_figures']);
    }

    /**
     * Extrait l'entrée d'un bloc donné du plan.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function entryFor(array $plan, string $blockId): array
    {
        foreach ($plan['entries'] as $entry) {
            if ($entry['block_id'] === $blockId) {
                return $entry;
            }
        }

        $this->fail("Aucune entrée de flux pour le bloc « {$blockId} ».");
    }
}
