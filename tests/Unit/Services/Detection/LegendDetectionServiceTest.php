<?php

namespace Tests\Unit\Services\Detection;

use App\Services\Detection\LegendDetectionService;
use Tests\TestCase;

/**
 * Tests du LegendDetectionService (regex déterministe, aucun LLM).
 *
 * @see PROMPTS_ET_TESTS.md — formats observés en usage réel.
 */
class LegendDetectionServiceTest extends TestCase
{
    private LegendDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LegendDetectionService();
    }

    public function test_detecte_figure_tableau_annexe(): void
    {
        $text = "Introduction du rapport\n"
            . "Figure 1: Architecture du système\n"
            . "Le schéma ci-dessus présente...\n"
            . "Tableau 2: Résultats comparatifs\n"
            . "Annexe 3: Questionnaire distribué\n";

        $legends = $this->service->execute($text);

        $this->assertCount(3, $legends);
        $this->assertSame('Figure', $legends[0]['type']);
        $this->assertSame('1', $legends[0]['number']);
        $this->assertSame('Architecture du système', $legends[0]['label']);
        $this->assertSame('Tableau', $legends[1]['type']);
        $this->assertSame('Annexe', $legends[2]['type']);
    }

    public function test_detecte_tous_les_types_connus(): void
    {
        $types = ['Figure', 'Tableau', 'Annexe', 'Image', 'Planche', 'Schéma'];

        $text = implode("\n", array_map(
            fn ($t, $i) => "$t " . ($i + 1) . ": Légende $t",
            $types,
            array_keys($types)
        ));

        $legends = $this->service->execute($text);

        $this->assertCount(6, $legends);
        $foundTypes = array_column($legends, 'type');
        sort($foundTypes);
        sort($types);
        $this->assertSame($types, $foundTypes);
    }

    public function test_ignore_le_texte_normal(): void
    {
        $text = "Ceci est un paragraphe ordinaire.\n"
            . "Figure 1: Légende valide\n"
            . "Le mot Figure sans numéro n'est pas une légende.\n"
            . "Figure 1 sans deux-points n'est pas détectée\n"
            . "Une phrase qui parle d'un tableau 5 mais sans format.\n";

        $legends = $this->service->execute($text);

        $this->assertCount(1, $legends);
        $this->assertSame('Figure', $legends[0]['type']);
    }

    public function test_detecte_les_numero_par_chapitre(): void
    {
        // Variation signalée dans PROMPTS_ET_TESTS.md : "Figure 2.3"
        $text = "Figure 2.3: Détail du module de connexion\n";

        $legends = $this->service->execute($text);

        $this->assertCount(1, $legends);
        $this->assertSame('2.3', $legends[0]['number']);
    }

    public function test_renvoie_le_numero_de_ligne(): void
    {
        $text = "Ligne une\nLigne deux\nFigure 7: Détection de zone\n";

        $legends = $this->service->execute($text);

        $this->assertCount(1, $legends);
        $this->assertSame(3, $legends[0]['line']);
    }

    public function test_texte_vide_leve_une_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->service->execute('   ');
    }

    public function test_insensible_a_la_casse(): void
    {
        $text = "figure 1: minuscule\nTABLEAU 2: majuscules\n";

        $legends = $this->service->execute($text);

        $this->assertCount(2, $legends);
        $this->assertSame('Figure', $legends[0]['type']);
        $this->assertSame('Tableau', $legends[1]['type']);
    }
}
