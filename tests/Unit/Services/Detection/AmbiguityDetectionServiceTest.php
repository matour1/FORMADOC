<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Detection;

use App\Services\Detection\AmbiguityDetectionService;
use Tests\TestCase;

/**
 * Tests de l'AmbiguityDetectionService (Phase 4 — validation des ambiguïtés).
 *
 * Détection DÉTERMINISTE : un titre est signalé "ambigu" lorsque sa
 * numérotation (1., 1.1, 1.1.1, I.) contredit le niveau détecté par les
 * règles. Aucun LLM n'intervient.
 */
class AmbiguityDetectionServiceTest extends TestCase
{
    private function item(string $texte, int $niveau, int $index = 0): array
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

    private function structure(array $titres = [], array $sousTitres = []): array
    {
        return [
            'titres' => $titres,
            'sous_titres' => $sousTitres,
            'legends' => [],
        ];
    }

    public function test_signale_un_titre_dont_la_numerotation_contredit_le_niveau(): void
    {
        // « 1.1 Contexte » est classé niveau 1, mais la numérotation suggère 2.
        $result = (new AmbiguityDetectionService())->detect($this->structure(
            [$this->item('1.1 Contexte', 1, 0)]
        ));

        $this->assertCount(1, $result);
        $this->assertSame('1.1 Contexte', $result[0]['texte']);
        $this->assertSame(1, $result[0]['niveau_detecte']);
        $this->assertSame(2, $result[0]['niveau_suggere']);
    }

    public function test_ne_signale_pas_une_numerotation_coherente(): void
    {
        $result = (new AmbiguityDetectionService())->detect($this->structure(
            [$this->item('1. Introduction', 1, 0)],
            [$this->item('1.1 Contexte', 2, 1)]
        ));

        $this->assertSame([], $result);
    }

    public function test_detecte_une_numerotation_a_trois_niveaux(): void
    {
        // « 1.2.3 Détail » classé niveau 2, mais la numérotation suggère 3.
        $result = (new AmbiguityDetectionService())->detect($this->structure(
            [],
            [$this->item('1.2.3 Détail', 2, 0)]
        ));

        $this->assertCount(1, $result);
        $this->assertSame(3, $result[0]['niveau_suggere']);
    }

    public function test_detecte_la_numerotation_romaine_comme_niveau_1(): void
    {
        // « II. Contexte » classé niveau 2, mais « II. » est un chapitre (niveau 1).
        $result = (new AmbiguityDetectionService())->detect($this->structure(
            [],
            [$this->item('II. Contexte', 2, 0)]
        ));

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['niveau_suggere']);
    }

    public function test_ignore_un_texte_sans_numerotation(): void
    {
        $result = (new AmbiguityDetectionService())->detect($this->structure(
            [$this->item('Introduction', 1, 0)]
        ));

        $this->assertSame([], $result);
    }

    public function test_une_annee_en_debut_de_titre_n_est_pas_une_numerotation(): void
    {
        // « 2025 Rapport annuel » : l'année ne doit pas être lue comme « 2. » + « 025 ».
        $result = (new AmbiguityDetectionService())->detect($this->structure(
            [$this->item('2025 Rapport annuel', 1, 0)]
        ));

        $this->assertSame([], $result);
    }

    public function test_genere_une_cle_stable_a_partir_de_la_position(): void
    {
        $item = $this->item('1.1 Contexte', 1, 42);

        $key = AmbiguityDetectionService::itemKey($item);

        $this->assertSame('s0e42pbody', $key);
    }

    public function test_retourne_un_resultat_vide_sans_titres(): void
    {
        $result = (new AmbiguityDetectionService())->detect($this->structure());

        $this->assertSame([], $result);
    }
}
