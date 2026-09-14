<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * P1-4 — Pièces jointes du chat.
 *
 * L'UI envoie `attachments[]` (multipart) : le backend doit les stocker sur
 * le disque privé, extraire le texte (txt/md/docx) pour le contexte IA,
 * tracer les fichiers dans metadata, et permettre le téléchargement
 * sécurisé (ownership vérifié). Avant : les pièces jointes étaient
 * SILENCIEUSEMENT IGNORÉES.
 */
class ChatAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private ?array $lastChatMessages = null;

    private ?array $lastChatOptions = null;

    private function fakeChatResponse(): void
    {
        $this->mock(OpenRouterService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('estimateCost')->andReturn([
                'usd' => 0.001,
                'credits' => 1,
                'model' => 'deepseek/deepseek-chat',
            ]);
            $mock->shouldReceive('chat')
                ->andReturnUsing(function (string $task, array $messages, string $plan, array $options) {
                    $this->lastChatMessages = $messages;
                    $this->lastChatOptions = $options;

                    return [
                        'content' => 'Réponse avec la pièce jointe.',
                        'model' => 'deepseek/deepseek-chat',
                        'cost_usd' => 0.0005,
                        'cost_credits' => 1,
                        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                        'tool_turns' => 0,
                    ];
                });
        });
    }

    /**
     * Envoi direct (la confirmation de coût a été supprimée — UX) : un seul
     * POST avec message + pièces jointes suffit.
     */
    private function confirmChat(ChatSession $session, array $payload): TestResponse
    {
        return $this->actingAs($session->user)
            ->post(route('chat.send', $session), $payload);
    }

    public function test_une_piece_jointe_txt_est_stockee_et_passee_en_contexte(): void
    {
        Storage::fake('local');
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session attachments',
        ]);

        $attachment = UploadedFile::fake()->createWithContent('notes.txt', 'Contenu du fichier joint.');

        $this->confirmChat($session, [
            'message' => 'Analyse ce fichier',
            'attachments' => [$attachment],
        ])->assertRedirect(route('chat.show', $session));

        // Le fichier est stocké sur le disque privé (nom UUID, chemin tracé)
        $userMessage = ChatMessage::where('chat_session_id', $session->id)->where('role', 'user')->first();
        $storedPath = $userMessage->metadata['attachments'][0]['path'] ?? null;
        $this->assertNotNull($storedPath);
        Storage::disk('local')->assertExists($storedPath);
        $this->assertStringStartsWith('chat/attachments/'.$session->id.'/', $storedPath);

        // Le message utilisateur est enregistré avec la trace metadata
        $this->assertDatabaseHas('chat_messages', [
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Analyse ce fichier',
        ]);
        $this->assertSame('notes.txt', $userMessage->metadata['attachments'][0]['name']);

        // Le contenu de la pièce jointe est injecté dans le contexte IA,
        // avec son chemin (indispensable pour les outils document_edit /
        // document_to_pdf qui ciblent la PJ).
        $this->assertNotNull($this->lastChatMessages);
        $lastUserContent = $this->lastChatMessages[count($this->lastChatMessages) - 1]['content'] ?? '';
        $this->assertStringContainsString('[Pièce jointe 1 : notes.txt', $lastUserContent);
        $this->assertStringContainsString('chemin : chat/attachments/'.$session->id.'/', $lastUserContent);
        $this->assertStringContainsString('Contenu du fichier joint.', $lastUserContent);

        // La réponse a été générée (flux complet OK)
        $this->assertDatabaseHas('chat_messages', [
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Réponse avec la pièce jointe.',
        ]);
    }

    /**
     * Q-MODE — Mode « agent » (DÉFAUT, automatique, aucun sélecteur dans
     * l'UI) : les outils sont OBLIGATOIRES (tool_choice: required) pour que
     * l'IA exécute automatiquement les actions (modifier une PJ, convertir
     * en PDF, générer un Word/PDF) au lieu de répondre en texte libre.
     * Le mode vient de la configuration (config/chat.php → CHAT_MODE), le
     * formulaire n'envoie plus aucun champ mode.
     */
    public function test_mode_agent_par_defaut_envoie_tool_choice_required(): void
    {
        Storage::fake('local');
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session mode agent',
        ]);

        $attachment = UploadedFile::fake()->createWithContent('notes.txt', 'Contenu du fichier joint.');

        $this->confirmChat($session, [
            'message' => 'Modifie ce fichier : remplace X par Y',
            'attachments' => [$attachment],
        ])->assertRedirect(route('chat.show', $session));

        // L'option tool_choice est requise (outils obligatoires)
        $this->assertSame('required', $this->lastChatOptions['tool_choice'] ?? null);
        $this->assertNotEmpty($this->lastChatOptions['tools'] ?? []);
        // Le prompt système insiste sur le mode agent
        $system = collect($this->lastChatMessages)->firstWhere('role', 'system');
        $this->assertNotNull($system);
        $this->assertStringContainsString('mode AGENT', $system['content']);

        // Le mode est tracé dans les métadonnées du message assistant
        $assistant = ChatMessage::where('chat_session_id', $session->id)->where('role', 'assistant')->first();
        $this->assertSame('agent', $assistant->metadata['mode'] ?? null);
    }

    /**
     * Q-MODE — Config sur « chat » (CHAT_MODE=chat) : conversation pure, les
     * outils ne sont PAS envoyés au modèle (économique, aucun coût d'outil).
     */
    public function test_mode_chat_desactive_les_outils(): void
    {
        config(['chat.mode' => 'chat']);
        Storage::fake('local');
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session mode chat',
        ]);

        $this->confirmChat($session, [
            'message' => 'Explique-moi simplement',
        ])->assertRedirect(route('chat.show', $session));

        // Aucun outil envoyé → la tâche retombe sur chat_text
        $this->assertEmpty($this->lastChatOptions['tools'] ?? []);
        $this->assertArrayNotHasKey('tool_choice', $this->lastChatOptions);
    }

    /**
     * Q-MODE — Config sur « auto » (CHAT_MODE=auto) : les outils sont
     * proposés au modèle qui décide seul de les utiliser (pas de tool_choice
     * forcé).
     */
    public function test_mode_auto_laisse_le_modele_decider(): void
    {
        config(['chat.mode' => 'auto']);
        Storage::fake('local');
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session mode auto',
        ]);

        $this->confirmChat($session, [
            'message' => 'Discutons',
        ])->assertRedirect(route('chat.show', $session));

        // Les outils sont proposés (le modèle décide), pas de tool_choice forcé
        $this->assertNotEmpty($this->lastChatOptions['tools'] ?? []);
        $this->assertArrayNotHasKey('tool_choice', $this->lastChatOptions);
    }

    /**
     * Q-MODE — Config invalide → repli sûr sur « agent » (le mode est borné
     * dans chatMode(), aucune valeur invalide possible).
     */
    public function test_config_mode_invalide_repli_sur_agent(): void
    {
        config(['chat.mode' => 'hacker']);
        Storage::fake('local');
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session mode invalide',
        ]);

        $this->confirmChat($session, [
            'message' => 'Test mode invalide',
        ])->assertRedirect(route('chat.show', $session));

        // Repli sûr : tool_choice forcé (agent par défaut) + mode agent tracé
        $this->assertSame('required', $this->lastChatOptions['tool_choice'] ?? null);
        $assistant = ChatMessage::where('chat_session_id', $session->id)->where('role', 'assistant')->first();
        $this->assertSame('agent', $assistant->metadata['mode'] ?? null);
    }

    public function test_extension_non_autorisee_est_refusee(): void
    {
        Storage::fake('local');
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session refus',
        ]);

        $bad = UploadedFile::fake()->createWithContent('virus.php', '<?php evil();');

        $this->confirmChat($session, [
            'message' => 'Fichier dangereux',
            'attachments' => [$bad],
        ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $value) => str_contains($value, 'extension .php non autorisée'));

        // Aucun fichier stocké, aucun message créé (le traitement s'arrête avant le débit)
        Storage::disk('local')->assertMissing('chat/attachments/'.$session->id.'/virus.php');
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_un_fichier_depassant_la_taille_max_est_refuse(): void
    {
        Storage::fake('local');
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session taille',
        ]);

        // 6 Mo > 5 Mo max
        $big = UploadedFile::fake()->create('gros.pdf', 6 * 1024);

        $this->confirmChat($session, [
            'message' => 'Fichier trop gros',
            'attachments' => [$big],
        ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $value) => str_contains($value, 'dépasse la limite de 5120 Ko'));

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_telechargement_du_fichier_par_le_proprietaire(): void
    {
        Storage::fake('local');
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session download',
        ]);

        $attachment = UploadedFile::fake()->createWithContent('notes.txt', 'Contenu du fichier joint.');

        $this->confirmChat($session, [
            'message' => 'Analyse ce fichier',
            'attachments' => [$attachment],
        ]);

        $userMessage = ChatMessage::where('chat_session_id', $session->id)->where('role', 'user')->first();
        $path = $userMessage->metadata['attachments'][0]['path'];

        // Le propriétaire télécharge son fichier
        $this->actingAs($user)
            ->get(route('chat.files.download', ['file' => $path]))
            ->assertOk();
    }

    public function test_telechargement_du_fichier_par_un_autre_utilisateur_est_refuse(): void
    {
        Storage::fake('local');
        $this->fakeChatResponse();

        $owner = User::factory()->create(['credits_balance' => 10000]);
        $intruder = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $owner->id,
            'title' => 'Session privée',
        ]);

        $attachment = UploadedFile::fake()->createWithContent('notes.txt', 'Contenu du fichier joint.');

        $this->confirmChat($session, [
            'message' => 'Analyse ce fichier',
            'attachments' => [$attachment],
        ]);

        $userMessage = ChatMessage::where('chat_session_id', $session->id)->where('role', 'user')->first();
        $path = $userMessage->metadata['attachments'][0]['path'];

        // L'intrus n'a pas accès (ownership vérifié via la session)
        $this->actingAs($intruder)
            ->get(route('chat.files.download', ['file' => $path]))
            ->assertNotFound();
    }

    public function test_traversee_de_repertoire_dans_le_chemin_est_refusee(): void
    {
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);

        $this->actingAs($user)
            ->get(route('chat.files.download', ['file' => 'chat/attachments/1/../../.env']))
            ->assertNotFound();
    }

    /**
     * P1-4 — DOCX complexe (tableaux + éléments imbriqués) : l'extraction
     * doit être RÉCURSIVE et robuste. Avant : `(string) $element->getText()`
     * provoquait « Array to string conversion » sur les DOCX réels (footnotes,
     * PreserveText, structures imbriquées) → le LLM voyait « contenu non
     * extractible » et ne pouvait pas utiliser document_analyze.
     */
    public function test_docx_complexe_est_extrait_de_maniere_robuste(): void
    {
        Storage::fake('local');
        $this->fakeChatResponse();

        $user = User::factory()->create(['credits_balance' => 10000]);
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session docx complexe',
        ]);

        // Génère un DOCX réel avec : titre, paragraphe, TextRun imbriqué,
        // tableau (cellules imbriquées) et PreserveText (champ Word).
        $phpWord = new PhpWord;
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $section = $phpWord->addSection();
        $section->addTitle('Rapport de stage');
        $section->addText('Introduction du rapport.');
        $run = $section->addTextRun();
        $run->addText('Texte ');
        $run->addText('imbriqué');
        $section->addText('{ PAGE }');
        $table = $section->addTable();
        $table->addRow();
        $cell = $table->addCell();
        $cell->addText('Cellule A1');
        $cell->addText('Cellule A1bis');
        $table->addRow();
        $table->addCell()->addText('Cellule B1');

        $tmp = tempnam(sys_get_temp_dir(), 'complexe_').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);

        $attachment = new UploadedFile($tmp, 'rapport_complexe.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);

        $this->confirmChat($session, [
            'message' => 'Analyse la structure',
            'attachments' => [$attachment],
        ])->assertRedirect(route('chat.show', $session));

        @unlink($tmp);

        // Le texte du DOCX est extrait et passé en contexte (avec son chemin)
        $this->assertNotNull($this->lastChatMessages);
        $lastUserContent = $this->lastChatMessages[count($this->lastChatMessages) - 1]['content'] ?? '';
        $this->assertStringContainsString('[Pièce jointe 1 : rapport_complexe.docx', $lastUserContent);
        $this->assertStringContainsString('Rapport de stage', $lastUserContent);
        $this->assertStringContainsString('Introduction du rapport.', $lastUserContent);
        $this->assertStringContainsString('Texte imbriqué', $lastUserContent);
        $this->assertStringContainsString('Cellule A1', $lastUserContent);
        $this->assertStringContainsString('Cellule B1', $lastUserContent);
    }
}
