<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Chat\ChatToolsService;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Remboursement proportionnel d'une chaîne d'outils partiellement échouée (R7.4).
 *
 * **Le câblage, pas le calcul.** Le calcul du prorata est testé isolément
 * (`PartialRefundTest`). Ici on vérifie que le contrôleur de chat l'applique
 * réellement, avec la bonne base et au bon moment. C'est le point où un défaut
 * resterait invisible : un calcul juste appelé avec la mauvaise base produit un
 * remboursement exact — et faux.
 *
 * Deux pièges sont couverts :
 *
 *  1. **la base.** Le remboursement partiel porte sur le coût RÉEL, pas sur
 *     l'estimation : l'étape 11 rembourse déjà l'écart estimation/réel, donc
 *     reprendre l'estimation rembourserait deux fois la même somme ;
 *  2. **le comptage.** Le prorata se calcule sur les appels d'outils, pas sur
 *     les tours du modèle : trois outils réussis dans un seul tour ne sont pas
 *     un tour sur quatre.
 */
class ChatPartialRefundTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simule une réponse où l'IA demande `$echecs` outils en échec et
     * `$succes` outils réussis, tous dans le même tour.
     */
    private function fakeChatAvecOutils(int $succes, int $echecs): void
    {
        // Le service d'outils est simulé : sans cela, un succès dépendrait de
        // l'existence réelle des fichiers, et le test mesurerait l'environnement
        // au lieu du mécanisme de remboursement.
        $this->mock(ChatToolsService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('schemas')->andReturn([
                ['type' => 'function', 'function' => ['name' => 'outil_test', 'parameters' => []]],
            ]);
            $mock->shouldReceive('execute')->andReturnUsing(function (array $toolCall): array {
                // Le nom est lu dans les deux formes possibles : à plat (`name`)
                // ou imbriquée (`function.name`). Le service réel reçoit la forme
                // brute, la mise à plat appartient à OpenRouterService.
                $nom = (string) ($toolCall['name'] ?? $toolCall['function']['name'] ?? '');

                return str_starts_with($nom, 'ko_')
                    ? ['error' => 'Échec simulé']
                    : ['result' => 'Succès simulé'];
            });
        });

        $this->mock(OpenRouterService::class, function (Mockery\MockInterface $mock) use ($succes, $echecs) {
            $mock->shouldReceive('estimateCost')->andReturn([
                'usd' => 0.001,
                'credits' => 10,
                'model' => 'deepseek/deepseek-chat',
            ]);

            $mock->shouldReceive('chat')
                ->andReturnUsing(function (string $task, array $messages, string $plan, array $options) use ($succes, $echecs) {
                    $tools = [];

                    for ($i = 0; $i < $succes; $i++) {
                        $tools[] = [
                            'id' => 'ok_'.$i,
                            'type' => 'function',
                            'function' => ['name' => 'ok_outil', 'arguments' => '{}'],
                        ];
                    }

                    for ($i = 0; $i < $echecs; $i++) {
                        $tools[] = [
                            'id' => 'ko_'.$i,
                            'type' => 'function',
                            'function' => ['name' => 'ko_outil', 'arguments' => '{}'],
                        ];
                    }

                    $executor = $options['executor'] ?? null;

                    if (is_callable($executor) && $tools !== []) {
                        foreach ($tools as $tool) {
                            $executor($tool, 1);
                        }
                    }

                    return [
                        'content' => 'Action terminée.',
                        'model' => 'deepseek/deepseek-chat',
                        // Coût réel identique à l'estimation, pour que l'étape 11
                        // n'ajoute aucun remboursement : le seul remboursement
                        // observé est alors le partiel.
                        'cost_usd' => 0.001,
                        'cost_credits' => 10,
                        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                        'tool_turns' => 1,
                    ];
                });
        });
    }

    private function envoyer(User $user): void
    {
        $session = ChatSession::create(['user_id' => $user->id, 'title' => 'Test remboursement']);

        $this->actingAs($user)
            ->post(route('chat.send', $session), ['message' => 'Fais les actions'])
            ->assertRedirect(route('chat.show', $session));
    }

    public function test_une_chaine_partiellement_echouee_declenche_un_remboursement_partiel(): void
    {
        // 100 crédits au départ ; 10 débités (estimation) ; 10 en coût réel.
        $user = User::factory()->create(['credits_balance' => 100]);
        $this->fakeChatAvecOutils(succes: 3, echecs: 2);

        $this->envoyer($user);

        // 2 échecs sur 5 = 40 % de 10 crédits = 4 remboursés.
        $remboursement = CreditTransaction::where('type', 'refund')
            ->where('description', 'like', '%partiel%')
            ->firstOrFail();

        $this->assertSame(4, $remboursement->amount);
        $this->assertSame(94, $user->fresh()->credits_balance);
    }

    public function test_la_base_du_prorata_est_le_cout_reel_et_non_l_estimation(): void
    {
        $this->mock(ChatToolsService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('schemas')->andReturn([
                ['type' => 'function', 'function' => ['name' => 'outil_test', 'parameters' => []]],
            ]);
            $mock->shouldReceive('execute')->andReturn(['error' => 'Échec simulé']);
        });

        // Estimation 10 crédits, coût réel 5 : l'étape 11 rembourse 5.
        // Si le prorata portait sur l'estimation (10), l'utilisateur recevrait
        // 5 (ajustement) + 5 (prorata sur 10) = 10 pour 5 facturés.
        $this->mock(OpenRouterService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('estimateCost')->andReturn([
                'usd' => 0.001, 'credits' => 10, 'model' => 'deepseek/deepseek-chat',
            ]);
            $mock->shouldReceive('chat')->andReturnUsing(function (string $t, array $m, string $p, array $o) {
                $executor = $o['executor'] ?? null;

                if (is_callable($executor)) {
                    for ($i = 0; $i < 4; $i++) {
                        $executor([
                            'id' => 'ko_'.$i,
                            'type' => 'function',
                            'function' => ['name' => 'ko_outil', 'arguments' => '{}'],
                        ], 1);
                    }
                }

                return [
                    'content' => 'Fini.',
                    'model' => 'deepseek/deepseek-chat',
                    'cost_usd' => 0.0005,
                    'cost_credits' => 5,
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 25],
                    'tool_turns' => 1,
                ];
            });
        });

        $user = User::factory()->create(['credits_balance' => 100]);

        $this->envoyer($user);

        // Ajustement : 10 − 5 = 5 remboursés. Prorata : 4 échecs / 4 appels
        // → la totalité des 5 crédits RÉELS est rendue (5), pas 10.
        $ajustement = CreditTransaction::where('type', 'refund')
            ->where('description', 'like', '%Ajustement%')
            ->firstOrFail();
        $partiel = CreditTransaction::where('type', 'refund')
            ->where('description', 'like', '%partiel%')
            ->firstOrFail();

        $this->assertSame(5, $ajustement->amount);
        $this->assertSame(5, $partiel->amount);
        $this->assertSame(100, $user->fresh()->credits_balance, 'Le solde ne peut pas dépasser le solde initial.');
    }

    public function test_une_chaine_entierement_reussie_ne_rembourse_rien(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);
        $this->fakeChatAvecOutils(succes: 4, echecs: 0);

        $this->envoyer($user);

        $this->assertSame(
            0,
            CreditTransaction::where('description', 'like', '%partiel%')->count(),
            'Sans échec, aucun remboursement partiel ne doit être écrit.'
        );
        $this->assertSame(90, $user->fresh()->credits_balance);
    }

    public function test_les_echecs_d_outils_sont_traces_dans_le_message(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);
        $this->fakeChatAvecOutils(succes: 1, echecs: 3);

        $this->envoyer($user);

        $message = ChatMessage::where('role', 'assistant')->firstOrFail();

        // Sans cette trace, un litige se règle à l'aveugle : impossible de
        // savoir combien d'appels ont échoué ni ce qui a été rendu.
        $this->assertSame(1, $message->metadata['tool_calls_succeeded']);
        $this->assertSame(3, $message->metadata['tool_calls_failed']);
        $this->assertGreaterThan(0, $message->metadata['refunded_credits']);
    }

    public function test_un_seul_echec_sur_un_seul_appel_rembourse_la_totalite(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);
        $this->fakeChatAvecOutils(succes: 0, echecs: 1);

        $this->envoyer($user);

        $partiel = CreditTransaction::where('type', 'refund')
            ->where('description', 'like', '%partiel%')
            ->firstOrFail();

        // 1 échec / 1 appel = 100 % du coût réel (10 crédits).
        $this->assertSame(10, $partiel->amount);
        $this->assertSame(100, $user->fresh()->credits_balance);
    }
}
