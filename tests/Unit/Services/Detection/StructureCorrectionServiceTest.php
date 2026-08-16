<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Detection;

use App\Services\Detection\AmbiguityDetectionService;
use App\Services\Detection\StructureCorrectionService;
use Tests\TestCase;

/**
 * Tests du StructureCorrectionService (Phase 4).
 *
 * Applique les corrections validées par l'utilisateur à la structure
 * détectée : promouvoir/rétrograder un titre, ou le retirer du plan.
 */
class StructureCorrectionServiceTest extends TestCase
{
    private function item(string $texte, int $niveau, int $index): array
    {
        return [
            'texte' => $texte,
            'niveau' => $niveau,
            'position' => [
                'section_index' => 0,
                'element_index' => $index,
                'parent' => 'body',
            ],
        ];
    }

    private function structure(): array
    {
        return [
            'titres' => [
                $this->item('1. Introduction', 1, 0),
                $this->item('2. Méthodologie', 1, 1),
            ],
            'sous_titres' => [
                $this->item('1.1 Contexte', 2, 2),
            ],
            'legends' => [
                ['type' => 'Figure', 'number' => '1', 'label' => 'Architecture'],
            ],
        ];
    }

    public function test_promeut_un_sous_titre_en_titre(): void
    {
        $corrections = ['s0e2pbody' => '1'];

        $result = (new StructureCorrectionService())->apply($this->structure(), $corrections);

        $titres = array_column($result['titres'], 'texte');
        $this->assertContains('1.1 Contexte', $titres);
        $this->assertCount(3, $result['titres']);
        $this->assertSame([], $result['sous_titres']);
    }

    public function test_retrograde_un_titre_en_sous_titre(): void
    {
        $corrections = ['s0e1pbody' => '2'];

        $result = (new StructureCorrectionService())->apply($this->structure(), $corrections);

        $this->assertNotContains('2. Méthodologie', array_column($result['titres'], 'texte'));
        $sousTitres = array_column($result['sous_titres'], 'texte');
        $this->assertContains('2. Méthodologie', $sousTitres);

        // Le niveau a été mis à jour
        $metodo = collect($result['sous_titres'])->firstWhere('texte', '2. Méthodologie');
        $this->assertSame(2, $metodo['niveau']);
    }

    public function test_retire_un_titre_du_plan(): void
    {
        $corrections = ['s0e0pbody' => 'remove'];

        $result = (new StructureCorrectionService())->apply($this->structure(), $corrections);

        $this->assertNotContains('1. Introduction', array_column($result['titres'], 'texte'));
        $this->assertCount(1, $result['titres']);
    }

    public function test_conserve_les_autres_categories(): void
    {
        $result = (new StructureCorrectionService())->apply($this->structure(), []);

        $this->assertCount(1, $result['legends']);
        $this->assertSame('Architecture', $result['legends'][0]['label']);
    }

    public function test_conserve_un_item_non_corrige_dans_sa_categorie(): void
    {
        $result = (new StructureCorrectionService())->apply($this->structure(), ['s0e0pbody' => 'remove']);

        // Les items non corrigés restent dans leur catégorie d'origine
        $this->assertContains('2. Méthodologie', array_column($result['titres'], 'texte'));
        $this->assertContains('1.1 Contexte', array_column($result['sous_titres'], 'texte'));
    }

    public function test_ignore_une_cle_inconnue(): void
    {
        $result = (new StructureCorrectionService())->apply($this->structure(), ['inconnue' => '1']);

        $this->assertCount(2, $result['titres']);
        $this->assertCount(1, $result['sous_titres']);
    }
}
