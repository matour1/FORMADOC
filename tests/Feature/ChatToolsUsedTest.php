<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Chat\ChatToolsService;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Trace et affichage des outils RÉELLEMENT utilisés par un message.
 *
 * **La demande, et ce qui est réellement possible.** L'objectif initial était de
 * montrer la progression outil par outil PENDANT le traitement. C'est impossible
 * en l'état : l'envoi d'un message est une requête **synchrone** unique — le
 * navigateur transmet le formulaire et ne reçoit RIEN avant que le serveur ait
 * terminé l'ensemble (modèle + outils). Le client n'a donc aucun moyen de savoir
 * quel outil s'exécute à un instant donné.
 *
 * Afficher des étapes simulées (« analyse en cours… ») aurait été un mensonge sur
 * l'état de l'application — précisément le défaut corrigé trois fois dans ce
 * projet, et le plus coûteux, parce qu'une interface qui invente son état fait
 * perdre confiance dans tout ce qu'elle affiche par ailleurs.
 *
 * **Ce qui est vrai et donc affiché.** Une fois la réponse arrivée, la liste des
 * outils exécutés est connue avec certitude. On l'affiche sous le message, sous
 * forme de libellés lisibles, avec le nom technique en infobulle pour que le
 * support puisse citer un outil précis dans un ticket.
 *
 * **Pourquoi seuls les outils RÉUSSIS sont listés.** Annoncer « Analyse de la
 * structure » alors que l'analyse a échoué serait pire que de ne rien annoncer :
 * l'utilisateur croirait son document traité. Le nombre d'échecs est déjà tracé
 * séparément (`tool_calls_failed`), et le remboursement partiel s'appuie dessus.
 */
class ChatToolsUsedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simule une réponse de l'IA qui appelle `$succes` outils réussis et
     * `$echecs` outils en échec.
     *
     * Les services sont simulés : sans cela, un « succès » dépendrait de
     * l'existence réelle de fichiers dans le stockage, et le test mesurerait
     * l'environnement au lieu du mécanisme de traçage.
     */
    private function fakeChatAvecOutils(int $succes, int $echecs): void
    {
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

            $mock->shouldReceive('chat')->andReturnUsing(function (string $task, array $messages, string $plan, array $options) use ($succes, $echecs) {
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

                if (is_callable($executor)) {
                    foreach ($tools as $tool) {
                        // L'exécuteur reçoit la forme APLATIE. Ce n'est pas un
                        // détail de simulation : en production, `OpenRouterService`
                        // aplatit `function.name`/`function.arguments` vers
                        // `name`/`arguments` (trait `NormalizesToolCalls`) AVANT
                        // d'appeler l'exécuteur, et c'est ce contrat que lit le
                        // contrôleur pour tracer l'outil.
                        //
                        // La première version de ce test passait la forme brute :
                        // elle ne reproduisait donc pas la réalité, et faisait
                        // échouer le test sur une divergence de la SIMULATION, pas
                        // du code testé. Le test a ainsi servi à corriger sa propre
                        // fidélité.
                        $executor([
                            'id' => $tool['id'],
                            'name' => $tool['function']['name'],
                            'arguments' => $tool['function']['arguments'],
                        ], 1);
                    }
                }

                return [
                    'content' => 'Action terminée.',
                    'model' => 'deepseek/deepseek-chat',
                    'cost_usd' => 0.001,
                    'cost_credits' => 10,
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                    'tool_turns' => 1,
                ];
            });
        });
    }

    private function envoyer(User $user): ChatMessage
    {
        $session = ChatSession::create(['user_id' => $user->id, 'title' => 'Test outils']);

        $this->actingAs($user)
            ->post(route('chat.send', $session), ['message' => 'Fais les actions'])
            ->assertRedirect(route('chat.show', $session));

        return ChatMessage::where('role', 'assistant')->latest('id')->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Traçage
    // -------------------------------------------------------------------------

    /**
     * Les outils réussis sont enregistrés, et le nom technique est bien lu dans
     * la réponse brute du modèle.
     *
     * C'est le point fragile : `OpenRouterService` met à plat la forme imbriquée
     * (`function.name` → `name`) avant d'appeler l'exécuteur. Si cette mise à
     * plat changeait, la trace deviendrait une liste de chaînes vides — et
     * l'interface n'afficherait rien, sans la moindre erreur.
     */
    public function test_les_outils_reussis_sont_traces_dans_les_metadonnees(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);
        $this->fakeChatAvecOutils(succes: 2, echecs: 0);

        $message = $this->envoyer($user);

        $this->assertSame(['ok_outil'], $message->metadata['tools_used']);
    }

    /**
     * **Un outil en échec n'est PAS annoncé comme utilisé.**
     *
     * Afficher « Analyse de la structure » alors que l'analyse a échoué ferait
     * croire au traitement du document. C'est plus grave qu'un affichage vide :
     * l'utilisateur attendrait un résultat qui n'arrivera jamais.
     */
    public function test_un_outil_en_echec_n_est_pas_annonce_comme_utilise(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);
        $this->fakeChatAvecOutils(succes: 0, echecs: 2);

        $message = $this->envoyer($user);

        $this->assertSame([], $message->metadata['tools_used']);
    }

    /**
     * Un outil appelé plusieurs fois n'est listé qu'une fois.
     *
     * Sans déduplication, cinq appels de `document_analyze` produiraient cinq
     * pastilles identiques, et l'utilisateur croirait que son document a été
     * analysé cinq fois.
     */
    public function test_un_meme_outil_appele_plusieurs_fois_n_est_liste_qu_une_fois(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);
        $this->fakeChatAvecOutils(succes: 4, echecs: 0);

        $message = $this->envoyer($user);

        $this->assertSame(['ok_outil'], $message->metadata['tools_used']);
    }

    // -------------------------------------------------------------------------
    // Affichage
    // -------------------------------------------------------------------------

    /**
     * La vue affiche le libellé LISIBLE, pas le nom technique.
     *
     * « document_analyze » est un identifiant destiné au code ; l'afficher tel
     * quel demande à l'utilisateur de traduire. Le libellé français figure donc
     * en clair, et le nom technique en infobulle — un rapport de support doit
     * pouvoir citer l'outil exact.
     */
    public function test_la_vue_affiche_le_libelle_lisible_et_garde_le_nom_technique_en_infobulle(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::create(['user_id' => $user->id, 'title' => 'Test affichage']);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Analyse terminée.',
            'metadata' => ['tools_used' => ['document_analyze', 'web_search']],
        ]);

        $reponse = $this->actingAs($user)->get(route('chat.show', $session))->assertOk();

        $reponse->assertSee('Analyse de la structure');
        $reponse->assertSee('Recherche web');

        // Le nom technique reste accessible pour le support.
        $this->assertStringContainsString('title="document_analyze"', $reponse->getContent());
    }

    /**
     * Un libellé absent de la table de correspondance est affiché TEL QUEL.
     *
     * On ne masque pas un nom inconnu : ce serait faire disparaître la trace d'un
     * outil qui a réellement tourné, au moment précis où l'on a besoin de savoir
     * lequel. Une erreur de configuration doit se voir.
     */
    public function test_un_outil_sans_libelle_est_affiche_par_son_nom_technique(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::create(['user_id' => $user->id, 'title' => 'Test libellé absent']);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Fait.',
            'metadata' => ['tools_used' => ['outil_du_futur']],
        ]);

        $this->actingAs($user)
            ->get(route('chat.show', $session))
            ->assertOk()
            ->assertSee('outil_du_futur');
    }

    /**
     * **Les pastilles portent une VARIANTE de style, pas seulement `.badge`.**
     *
     * Défaut réel, trouvé à la capture d'écran et invisible aux autres tests :
     * dans le design system, `.badge` seul ne définit **ni fond ni couleur** —
     * ce sont `.badge-info`, `.badge-success`, etc. qui les portent. Les
     * pastilles s'affichaient donc en texte nu, sans contour, comme un simple
     * paragraphe.
     *
     * Les tests de contenu vérifiaient `assertSee('Analyse de la structure')`,
     * qui passe avec ou sans fond : ils confirmaient le texte, pas le composant.
     * C'est la limite connue d'un test de contenu — d'où cette vérification de
     * la CLASSE, qui est ce qui produit l'apparence.
     */
    public function test_les_pastilles_d_outils_portent_une_variante_de_style(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::create(['user_id' => $user->id, 'title' => 'Test style pastilles']);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Fait.',
            'metadata' => ['tools_used' => ['document_analyze']],
        ]);

        $html = $this->actingAs($user)->get(route('chat.show', $session))->assertOk()->getContent();

        // La forme CSS exacte produite par :class Blade sur une pastille.
        $this->assertMatchesRegularExpression(
            '/<span class="[^"]*badge badge-info[^"]*"\s+title="document_analyze"/',
            $html,
            'Une pastille d\'outil doit porter `.badge-info` : `.badge` seul n\'a ni fond ni '
            .'couleur dans le design system, donc la pastille s\'affiche en texte nu.'
        );
    }

    /**
     * `.badge` seul reste sans fond dans le design system.
     *
     * Ce test fige la PRÉMISSE du précédent. Si quelqu'un ajoutait un fond à
     * `.badge` (ce qui serait défendable), la variante ne serait plus nécessaire
     * et ce test signalerait que la justification a changé — au lieu de laisser
     * une règle appliquée pour une raison qui n'existe plus.
     */
    public function test_badge_seul_ne_definit_pas_de_fond(): void
    {
        $css = (string) file_get_contents(resource_path('css/formadoc.css'));

        // On isole la règle `.badge { ... }` sans variante.
        preg_match('/^\.badge\s*\{([^}]*)\}/m', $css, $m);

        $this->assertNotEmpty($m, 'La règle `.badge` est introuvable.');
        $this->assertStringNotContainsString('background', $m[1],
            '`.badge` définit désormais un fond : la variante n\'est peut-être plus '
            .'nécessaire — vérifier `.msg-tools` et ce test.');
    }

    /**
     * Aucun bloc « outils » n'est rendu quand aucun outil n'a tourné.
     *
     * Un bloc vide laisserait croire à un échec d'affichage. Beaucoup de
     * conversations n'utilisent aucun outil : c'est le cas normal, pas une
     * anomalie.
     */
    public function test_aucun_bloc_outils_n_est_affiche_quand_aucun_outil_n_a_tourne(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::create(['user_id' => $user->id, 'title' => 'Test sans outil']);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Bonjour, comment puis-je vous aider ?',
            'metadata' => ['tool_calls_succeeded' => 0],
        ]);

        $reponse = $this->actingAs($user)->get(route('chat.show', $session))->assertOk();

        $reponse->assertDontSee('Outil utilisé');
        $reponse->assertDontSee('Outils utilisés');
    }
}
