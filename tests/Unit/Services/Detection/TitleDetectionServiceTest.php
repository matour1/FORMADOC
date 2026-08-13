<?php

namespace Tests\Unit\Services\Detection;

use App\Services\Detection\TitleDetectionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests du TitleDetectionService avec API DeepSeek simulée (Http::fake).
 *
 * Aucun appel réseau réel : le comportement de l'API est mocké pour
 * vérifier la construction de la requête et le parsing de la réponse.
 */
class TitleDetectionServiceTest extends TestCase
{
    private TitleDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TitleDetectionService();
    }

    public function test_appelle_l_api_avec_le_bon_modele_et_la_bonne_url(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "# Introduction\n## 1. Contexte"]],
                ],
            ], 200),
        ]);

        $this->service->execute("Introduction\n1. Contexte\nContenu du rapport.");

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/chat/completions')
                && $request['model'] === config('deepseek.model');
        });
    }

    public function test_renvoie_le_markdown_et_la_reponse_brute(): void
    {
        $markdown = "# Introduction\n## 1. Contexte";
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => $markdown]],
                ],
            ], 200),
        ]);

        $result = $this->service->execute("Introduction\n1. Contexte");

        $this->assertSame($markdown, $result['markdown']);

        // La réponse brute est un JSON valide contenant le markdown échappé
        $this->assertJson($result['raw']);
        $decoded = json_decode($result['raw'], true);
        $this->assertSame($markdown, $decoded['choices'][0]['message']['content']);
    }

    public function test_leve_une_exception_si_l_api_repond_en_erreur(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => 'rate limit'], 429),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DeepSeek API error');
        $this->service->execute("Un texte suffisamment long pour être analysé.");
    }

    public function test_leve_une_exception_si_la_reponse_est_vide(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(['choices' => []], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('réponse sans contenu exploitable');
        $this->service->execute("Un texte suffisamment long pour être analysé.");
    }

    public function test_leve_une_exception_si_le_texte_est_trop_court(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('texte trop court');
        $this->service->execute('Court');
    }

    public function test_transmet_le_prompt_du_systeme(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '# Titre']],
                ],
            ], 200),
        ]);

        $this->service->execute("Un rapport de test suffisamment long.");

        Http::assertSent(function (Request $request) {
            $messages = $request['messages'];
            $systemContent = $messages[0]['content'] ?? '';
            return $messages[0]['role'] === 'system'
                && str_contains($systemContent, 'analyse de structure documentaire')
                && $messages[1]['role'] === 'user';
        });
    }
}
