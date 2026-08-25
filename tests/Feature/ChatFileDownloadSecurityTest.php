<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests P0-4 — Téléchargement sécurisé des fichiers générés par le chat.
 *
 * Sans cette protection, les fichiers `chat/generated/**` et
 * `claude-skills/**` (disk 'local') étaient inaccessibles — la nouvelle
 * route /chat/files/download vérifie :
 *   - auth requise (redirection login si invité)
 *   - chemin normalisé (pas de traversal `..`, pas de chemin absolu)
 *   - préfixes autorisés uniquement (chat/generated/, claude-skills/)
 *   - ownership : le fichier doit être référencé dans metadata.generated_files
 *     d'un message assistant d'une session de l'utilisateur
 *   - existence du fichier sur le disk
 */
class ChatFileDownloadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeSessionWithMessage(User $user, array $generatedFiles = []): ChatSession
    {
        $session = ChatSession::create(['user_id' => $user->id]);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Voici votre document généré : '.($generatedFiles[0] ?? ''),
            'metadata' => ['generated_files' => $generatedFiles],
        ]);

        return $session;
    }

    public function test_proprietaire_telecharge_son_fichier(): void
    {
        $user = User::factory()->create();
        $path = 'chat/generated/2026/01/15/rapport.docx';

        Storage::disk('local')->put($path, 'contenu du docx généré');

        $this->makeSessionWithMessage($user, [$path]);

        $this->actingAs($user)
            ->get(route('chat.files.download', ['file' => $path]))
            ->assertOk()
            ->assertDownload('rapport.docx');
    }

    public function test_fichier_non_reference_est_introuvable(): void
    {
        $user = User::factory()->create();
        $path = 'chat/generated/2026/01/15/autre.docx';

        Storage::disk('local')->put($path, 'contenu non référencé');

        // Le fichier existe sur le disk mais n'est référencé par aucun message
        $this->actingAs($user)
            ->get(route('chat.files.download', ['file' => $path]))
            ->assertNotFound();
    }

    public function test_fichier_d_un_autre_utilisateur_est_introuvable(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $path = 'chat/generated/2026/01/15/confidentiel.docx';

        Storage::disk('local')->put($path, 'contenu secret');

        $this->makeSessionWithMessage($owner, [$path]);

        // L'attaquant ne voit pas le fichier du propriétaire
        $this->actingAs($attacker)
            ->get(route('chat.files.download', ['file' => $path]))
            ->assertNotFound();
    }

    public function test_traversal_chemin_est_rejete(): void
    {
        $user = User::factory()->create();

        // Tentative de traversal : remonter hors de chat/generated
        $this->actingAs($user)
            ->get(route('chat.files.download', ['file' => 'chat/generated/../../config/database.php']))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('chat.files.download', ['file' => '....//etc/passwd']))
            ->assertNotFound();
    }

    public function test_mauvais_prefixe_est_rejete(): void
    {
        $user = User::factory()->create();

        // Préfixe non autorisé (documents uploadés, config, etc.)
        $this->actingAs($user)
            ->get(route('chat.files.download', ['file' => 'documents/1/rapport.docx']))
            ->assertNotFound();

        // Chemin absolu
        $this->actingAs($user)
            ->get(route('chat.files.download', ['file' => '/etc/passwd']))
            ->assertNotFound();
    }

    public function test_non_authentifie_est_redirige_vers_login(): void
    {
        $this->get(route('chat.files.download', ['file' => 'chat/generated/x.docx']))
            ->assertRedirect(route('login'));
    }
}
