<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Detection;

use App\Services\Detection\AiCorrectionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests du AiCorrectionService (post-processeur IA facultatif — Phase 4).
 *
 * L'IA n'est JAMAIS appelée par défaut :
 *  - sans clé API → structure inchangée, aucun appel réseau
 *  - sans ambiguïtés → aucun appel réseau
 *  - échec/timeout → structure inchangée (jamais fatal)
 *  - corrections reçues → fusion ciblée (kind, level) dans la structure
 */
class AiCorrectionServiceTest extends TestCase
{
    /**
     * Structure 100 % déterministe sans ambiguïté : aucun marqueur de liste,
     * aucun titre (donc aucun élément "proche d'un titre").
     */
    private function structureBase(): array
    {
        return [
            'titres' => [],
            'sous_titres' => [],
            'en_tetes' => [],
            'pieds_de_page' => [],
            'tableaux' => [],
            'images' => [],
            'elements_flottants' => [],
            'legends' => [],
            'body_complet' => [
                [
                    'text' => 'Ceci est un paragraphe d\'introduction.',
                    'type' => 'texte',
                    'depth' => 0,
                    'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body'],
                ],
                [
                    'text' => 'Le contexte du projet est présenté ici.',
                    'type' => 'texte',
                    'depth' => 0,
                    'position' => ['section_index' => 0, 'element_index' => 1, 'parent' => 'body'],
                ],
                [
                    'text' => 'Un autre paragraphe sans marqueur de liste.',
                    'type' => 'texte',
                    'depth' => 0,
                    'position' => ['section_index' => 0, 'element_index' => 2, 'parent' => 'body'],
                ],
            ],
        ];
    }

    /**
     * Structure avec une liste IMPLICITE (tirets, sans style numPr Word) :
     * le point critique que l'IA doit pouvoir corriger.
     */
    private function structureAvecCandidatListe(): array
    {
        return [
            'titres' => [
                ['texte' => 'Introduction', 'niveau' => 1, 'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body']],
            ],
            'sous_titres' => [],
            'en_tetes' => [],
            'pieds_de_page' => [],
            'tableaux' => [],
            'images' => [],
            'elements_flottants' => [],
            'legends' => [],
            'body_complet' => [
                [
                    'text' => 'Introduction',
                    'type' => 'titre',
                    'depth' => 1,
                    'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body'],
                ],
                [
                    'text' => '- Premier élément de liste',
                    'type' => 'texte',
                    'depth' => 0,
                    'position' => ['section_index' => 0, 'element_index' => 1, 'parent' => 'body'],
                ],
                [
                    'text' => '- Deuxième élément de liste',
                    'type' => 'texte',
                    'depth' => 0,
                    'position' => ['section_index' => 0, 'element_index' => 2, 'parent' => 'body'],
                ],
                [
                    'text' => '- Troisième élément de liste',
                    'type' => 'texte',
                    'depth' => 0,
                    'position' => ['section_index' => 0, 'element_index' => 3, 'parent' => 'body'],
                ],
            ],
        ];
    }

    private function fakeCorrections(array $corrections): void
    {
        config(['deepseek.api_key' => 'sk-test']);
        config(['deepseek.retry_delays_ms' => [0, 0, 0, 0]]);

        $iaJson = json_encode(['corrections' => $corrections], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $iaJson]]],
            ], 200),
        ]);
    }

    public function test_sans_cle_api_structure_inchangee_et_aucun_appel(): void
    {
        config(['deepseek.api_key' => '']);
        Http::fake();

        $structure = $this->structureAvecCandidatListe();
        $service = new AiCorrectionService();

        $result = $service->correct($structure, []);

        // Structure strictement identique (aucune correction)
        $this->assertSame($structure, $result);
        Http::assertNothingSent();
    }

    public function test_sans_ambiguites_aucun_appel_ia(): void
    {
        config(['deepseek.api_key' => 'sk-test']);
        Http::fake();

        $structure = $this->structureBase();
        $service = new AiCorrectionService();

        $result = $service->correct($structure, []);

        // Pas de candidat liste, pas d'ambiguïté → aucun appel réseau
        $this->assertSame($structure, $result);
        Http::assertNothingSent();
    }

    public function test_echec_ia_structure_inchangee(): void
    {
        config(['deepseek.api_key' => 'sk-test']);
        config(['deepseek.retry_delays_ms' => [0, 0, 0, 0]]);

        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => 'overloaded'], 503),
        ]);

        $structure = $this->structureAvecCandidatListe();
        $service = new AiCorrectionService();

        $result = $service->correct($structure, []);

        // Jamais fatal : la structure déterministe est conservée
        $this->assertSame($structure, $result);
        // Erreur HTTP 503 non retryable (pas une ConnectionException)
        Http::assertSentCount(1);
    }

    public function test_correction_texte_en_liste_est_appliquee(): void
    {
        $this->fakeCorrections([
            ['element_index' => 1, 'kind' => 'liste', 'level' => 0],
            ['element_index' => 2, 'kind' => 'liste', 'level' => 0],
        ]);

        $structure = $this->structureAvecCandidatListe();
        $service = new AiCorrectionService();

        $result = $service->correct($structure, []);

        // Les types sont corrigés en 'liste' dans body_complet
        $this->assertSame('liste', $result['body_complet'][1]['type']);
        $this->assertSame('liste', $result['body_complet'][2]['type']);
        $this->assertSame(0, $result['body_complet'][1]['depth']);

        // Traçabilité : la correction est mémorisée
        $this->assertArrayHasKey('ai_corrections', $result);
        $this->assertCount(2, $result['ai_corrections']);
        $this->assertSame('liste', $result['ai_corrections'][0]['kind']);
    }

    public function test_correction_ambiguite_niveau_titre(): void
    {
        $this->fakeCorrections([
            // L'IA confirme que « Le contexte du projet… » est un sous-titre
            ['element_index' => 1, 'kind' => 'sous_titre', 'level' => 2],
        ]);

        $structure = $this->structureBase();
        $ambiguities = [
            [
                'id' => 's0e1pbody',
                'texte' => 'Le contexte du projet est présenté ici.',
                'niveau_detecte' => 1,
                'niveau_suggere' => 2,
                'raison' => 'La numérotation suggère un niveau 2.',
            ],
        ];

        $service = new AiCorrectionService();
        $result = $service->correct($structure, $ambiguities);

        // body_complet : le type devient 'sous_titre' et la profondeur 2
        $this->assertSame('sous_titre', $result['body_complet'][1]['type']);
        $this->assertSame(2, $result['body_complet'][1]['depth']);
        $this->assertArrayHasKey('ai_corrections', $result);
    }

    public function test_parse_ambiguite_avec_id_format_s_e_p(): void
    {
        // Vérifie le parsing de l'id « s0e1pbody » → element_index 1
        $reflection = new \ReflectionClass(AiCorrectionService::class);
        $method = $reflection->getMethod('ambiguityElementIndex');
        $method->setAccessible(true);

        $service = new AiCorrectionService();
        $this->assertSame(1, $method->invoke($service, ['id' => 's0e1pbody']));
        $this->assertSame(12, $method->invoke($service, ['id' => 's2e12pbody']));
        $this->assertSame(5, $method->invoke($service, ['element_index' => 5, 'id' => 's0e99pbody']));
        $this->assertNull($method->invoke($service, ['id' => 'inconnu']));
    }
}
