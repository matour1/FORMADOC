<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
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

        $this->actingAs($user)
            ->post(route('chat.send', $session), [
                'message' => 'Analyse ce fichier',
                'confirm_cost' => '1',
                'attachments' => [$attachment],
            ])
            ->assertRedirect(route('chat.show', $session));

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

        // Le contenu de la pièce jointe est injecté dans le contexte IA
        $this->assertNotNull($this->lastChatMessages);
        $lastUserContent = $this->lastChatMessages[count($this->lastChatMessages) - 1]['content'] ?? '';
        $this->assertStringContainsString('[Pièce jointe 1 : notes.txt]', $lastUserContent);
        $this->assertStringContainsString('Contenu du fichier joint.', $lastUserContent);

        // La réponse a été générée (flux complet OK)
        $this->assertDatabaseHas('chat_messages', [
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Réponse avec la pièce jointe.',
        ]);
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

        $this->actingAs($user)
            ->post(route('chat.send', $session), [
                'message' => 'Fichier dangereux',
                'confirm_cost' => '1',
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

        $this->actingAs($user)
            ->post(route('chat.send', $session), [
                'message' => 'Fichier trop gros',
                'confirm_cost' => '1',
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

        $this->actingAs($user)
            ->post(route('chat.send', $session), [
                'message' => 'Analyse ce fichier',
                'confirm_cost' => '1',
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

        $this->actingAs($owner)
            ->post(route('chat.send', $session), [
                'message' => 'Analyse ce fichier',
                'confirm_cost' => '1',
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
}
