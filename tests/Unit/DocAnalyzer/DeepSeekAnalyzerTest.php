<?php

declare(strict_types=1);

namespace Tests\Unit\DocAnalyzer;

use App\DocAnalyzer\AnalyzerResult;
use App\DocAnalyzer\DeepSeekAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests du DeepSeekAnalyzer (IA complémentaire) avec API simulée.
 *
 * Aucun appel réseau réel : le comportement de l'API est mocké (Http::fake)
 * pour vérifier la construction de la requête, le parsing JSON et la
 * tolérance aux pannes (timeout, JSON invalide → résultat vide, jamais fatal).
 */
class DeepSeekAnalyzerTest extends TestCase
{
    private DeepSeekAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new DeepSeekAnalyzer('sk-test');

        // Pas d'attente réelle entre les tentatives dans les tests
        config(['deepseek.retry_delays_ms' => [0, 0, 0, 0]]);
    }

    /**
     * Exemple de texte contextuel positionné (format exact du DocumentParser).
     */
    private function contextText(): string
    {
        return "[POS:section_0,element_0,parent_body]Introduction\n"
            . "[POS:section_0,element_1,parent_body]1. Contexte général\n"
            . "[POS:section_0,element_2,parent_body]Paragraphe de contenu\n"
            . "[POS:section_0,element_3,parent_header]EN-TÊTE FORMADOC";
    }

    /**
     * Réponse JSON valide conforme au contrat AnalyzerResult.
     */
    private function validJsonResponse(): string
    {
        return json_encode([
            'titres' => [
                ['texte' => 'Introduction', 'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body']],
            ],
            'sous_titres' => [
                ['texte' => '1. Contexte général', 'position' => ['section_index' => 0, 'element_index' => 1, 'parent' => 'body']],
            ],
            'en_tetes' => [
                ['texte' => 'EN-TÊTE FORMADOC', 'position' => ['section_index' => 0, 'element_index' => 3, 'parent' => 'header']],
            ],
            'pieds_de_page' => [],
            'tableaux' => [],
            'images' => [],
            'elements_flottants' => [
                ['texte' => 'Paragraphe de contenu', 'position' => ['section_index' => 0, 'element_index' => 2, 'parent' => 'body']],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_appelle_l_api_avec_le_texte_positionne(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $this->validJsonResponse()]]],
            ], 200),
        ]);

        $this->analyzer->analyze($this->contextText());

        Http::assertSent(function (Request $request) {
            $body = $request->data();
            $userMessage = end($body['messages'])['content'] ?? null;

            return str_contains($request->url(), '/chat/completions')
                && str_contains((string) $userMessage, '[POS:section_0,element_0');
        });
    }

    public function test_retourne_un_resultat_conforme_au_contrat(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $this->validJsonResponse()]]],
            ], 200),
        ]);

        $result = $this->analyzer->analyze($this->contextText());

        $this->assertTrue(AnalyzerResult::isValid($result));
        $this->assertCount(1, $result['titres']);
        $this->assertSame('Introduction', $result['titres'][0]['texte']);
        $this->assertSame(0, $result['titres'][0]['position']['element_index']);
        $this->assertCount(1, $result['en_tetes']);
        $this->assertSame('EN-TÊTE FORMADOC', $result['en_tetes'][0]['texte']);
    }

    public function test_extrait_le_json_entoure_de_fences_markdown(): void
    {
        $raw = "Voici le résultat :\n```json\n{$this->validJsonResponse()}\n```\nMerci.";

        $decoded = $this->analyzer->extractJson($raw);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('titres', $decoded);
        $this->assertCount(1, $decoded['titres']);
    }

    public function test_extrait_le_json_sans_fences_avec_texte_autour(): void
    {
        $raw = "Réponse du modèle : {$this->validJsonResponse()} Fin de la réponse.";

        $decoded = $this->analyzer->extractJson($raw);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('sous_titres', $decoded);
    }

    public function test_retourne_null_si_pas_de_json(): void
    {
        $this->assertNull($this->analyzer->extractJson('Aucun JSON ici, juste du texte.'));
        $this->assertNull($this->analyzer->extractJson(''));
    }

    public function test_retourne_un_resultat_vide_si_json_invalide(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Je suis désolé, je ne peux pas répondre.']]],
            ], 200),
        ]);

        $result = $this->analyzer->analyze($this->contextText());

        $this->assertTrue(AnalyzerResult::isValid($result));
        $this->assertSame([], $result['titres']);
        $this->assertSame([], $result['sous_titres']);
    }

    public function test_retourne_un_resultat_vide_si_l_api_repond_en_erreur(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => 'invalid key'], 401),
        ]);

        $result = $this->analyzer->analyze($this->contextText());

        // Jamais fatal : résultat vide, l'orchestrateur retombe sur les règles
        $this->assertTrue(AnalyzerResult::isValid($result));
        $this->assertSame([], $result['titres']);
    }

    public function test_relance_apres_un_timeout_avec_intervalle(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->pushFailedConnection('cURL error 28: Operation timed out after 300000 milliseconds')
                ->push([
                    'choices' => [['message' => ['content' => $this->validJsonResponse()]]],
                ], 200),
        ]);

        $result = $this->analyzer->analyze($this->contextText());

        $this->assertTrue(AnalyzerResult::isValid($result));
        $this->assertCount(1, $result['titres']);
        Http::assertSentCount(2);
    }

    public function test_retourne_un_resultat_vide_apres_epuisement_des_tentatives(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => 'overloaded'], 503),
        ]);

        // Une erreur HTTP applicative n'est pas retentée → 1 seule requête,
        // résultat vide (jamais d'exception).
        $result = $this->analyzer->analyze($this->contextText());

        $this->assertTrue(AnalyzerResult::isValid($result));
        Http::assertSentCount(1);
    }

    public function test_retourne_un_resultat_vide_si_texte_trop_court(): void
    {
        $result = $this->analyzer->analyze('court');

        $this->assertTrue(AnalyzerResult::isValid($result));
        Http::assertNothingSent();
    }
}
