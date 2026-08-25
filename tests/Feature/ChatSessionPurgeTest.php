<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PurgeTempFiles;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests P1-6 — RGPD : purge TTL + suppression cascade.
 *
 * La suppression d'une session de chat doit :
 *   - supprimer les pièces jointes de la session (disk 'local',
 *     chat/attachments/{sessionId}/**) — y compris les fichiers non tracés
 *   - supprimer les fichiers générés / claude-skills tracés dans
 *     metadata.generated_files des messages de la session
 *
 * La commande files:purge-temp (cron quotidien) doit :
 *   - supprimer les previews de couverture expirées
 *     (storage/app/preview-*.docx) au-delà du TTL (24 h par défaut)
 *   - conserver les previews récentes
 *   - supprimer les pièces jointes de sessions chat supprimées (orphelines)
 *   - conserver les pièces jointes des sessions toujours existantes
 */
class ChatSessionPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_session_purge_les_pieces_jointes_et_fichiers_generes(): void
    {
        $user = User::factory()->create();
        $session = ChatSession::create(['user_id' => $user->id]);

        // Pièce jointe tracée dans un message user
        $trackedAttachment = 'chat/attachments/'.$session->id.'/uuid-tracked.txt';
        Storage::disk('local')->put($trackedAttachment, 'contenu tracké');

        // Pièce jointe NON tracée (orpheline dans le dossier de session)
        $orphanAttachment = 'chat/attachments/'.$session->id.'/uuid-orphan.pdf';
        Storage::disk('local')->put($orphanAttachment, 'contenu orphelin');

        // Fichier généré tracé (chat/generated)
        $generatedPath = 'chat/generated/2026/02/01/session.docx';
        Storage::disk('local')->put($generatedPath, 'docx généré');

        // Fichier claude-skills tracé
        $skillsPath = 'claude-skills/2026/02/01/skills.docx';
        Storage::disk('local')->put($skillsPath, 'docx skills');

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Voici ma pièce jointe',
            'metadata' => ['attachments' => [
                ['name' => 'uuid-tracked.txt', 'path' => $trackedAttachment],
            ]],
        ]);
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Fichier généré',
            'metadata' => ['generated_files' => [$generatedPath, $skillsPath]],
        ]);

        // --- Action : suppression de la session ---
        $this->actingAs($user)
            ->delete(route('chat.destroy', $session))
            ->assertRedirect(route('chat.index'));

        // --- Assertions ---
        $this->assertFalse(Storage::disk('local')->exists($trackedAttachment), 'Pièce jointe tracée doit être supprimée.');
        $this->assertFalse(Storage::disk('local')->exists($orphanAttachment), 'Pièce jointe orpheline doit être supprimée (purge récursive).');
        $this->assertFalse(Storage::disk('local')->exists('chat/attachments/'.$session->id), 'Dossier de pièces jointes de la session doit être supprimé.');
        $this->assertFalse(Storage::disk('local')->exists($generatedPath), 'Fichier généré chat doit être supprimé.');
        $this->assertFalse(Storage::disk('local')->exists($skillsPath), 'Fichier claude-skills doit être supprimé.');
        $this->assertDatabaseMissing('chat_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('chat_messages', ['chat_session_id' => $session->id]);
    }

    public function test_destroy_session_intrus_403_et_ne_touche_pas_aux_fichiers(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $session = ChatSession::create(['user_id' => $owner->id]);

        $attachmentPath = 'chat/attachments/'.$session->id.'/uuid.txt';
        Storage::disk('local')->put($attachmentPath, 'secret');

        $this->actingAs($intruder)
            ->delete(route('chat.destroy', $session))
            ->assertForbidden();

        $this->assertTrue(Storage::disk('local')->exists($attachmentPath), 'Les fichiers de la session ne doivent pas être supprimés par un intrus.');
        $this->assertDatabaseHas('chat_sessions', ['id' => $session->id]);
    }

    public function test_files_purge_temp_supprime_les_previews_expirees_et_conserve_les_recentes(): void
    {
        $oldPreview = storage_path('app/preview-old-'.str_repeat('a', 10).'.docx');
        $recentPreview = storage_path('app/preview-recent-'.str_repeat('b', 10).'.docx');
        $unrelated = storage_path('app/rapport-final.docx');

        File::put($oldPreview, 'old');
        File::put($recentPreview, 'recent');
        File::put($unrelated, 'rapport');

        // Le preview "ancien" a plus de 24 h
        touch($oldPreview, now()->subHours(30)->getTimestamp());
        // Le preview "récent" a moins de 24 h
        touch($recentPreview, now()->subHours(2)->getTimestamp());

        $this->artisan('files:purge-temp')->assertSuccessful();

        $this->assertFileDoesNotExist($oldPreview, 'Preview expiré (> TTL) doit être supprimé.');
        $this->assertFileExists($recentPreview, 'Preview récent (< TTL) doit être conservé.');
        $this->assertFileExists($unrelated, 'Fichier hors préfixe preview- ne doit pas être touché.');
    }

    public function test_files_purge_temp_supprime_les_pieces_jointes_des_sessions_supprimees(): void
    {
        $user = User::factory()->create();
        $existing = ChatSession::create(['user_id' => $user->id]);
        $deleted = ChatSession::create(['user_id' => $user->id]);

        Storage::disk('local')->put('chat/attachments/'.$existing->id.'/a.txt', 'a');
        Storage::disk('local')->put('chat/attachments/'.$deleted->id.'/b.txt', 'b');
        // Session supprimée en base SANS purge des fichiers (orphelins)
        $deleted->delete();

        $this->artisan('files:purge-temp')->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists('chat/attachments/'.$existing->id.'/a.txt'), 'Pièces jointes d\'une session existante doivent être conservées.');
        $this->assertFalse(Storage::disk('local')->exists('chat/attachments/'.$deleted->id.'/b.txt'), 'Pièces jointes d\'une session supprimée doivent être purgées.');
        $this->assertFalse(Storage::disk('local')->exists('chat/attachments/'.$deleted->id), 'Dossier orphelin doit être supprimé.');
    }

    public function test_files_purge_temp_supprime_les_docx_generes_orphelins_et_conserve_les_scripts(): void
    {
        // P2-4 : les gen_*.docx de storage/test_scripts s'accumulent quand
        // l'export n'est jamais téléchargé → purge TTL, mais les scripts
        // utilitaires (.php, reports/) sont conservés (utilisés par les
        // tests unitaires DocAnalyzer).
        $oldGen = storage_path('test_scripts/gen_999_20260101_120000.docx');
        $recentGen = storage_path('test_scripts/gen_998_20260101_120000.docx');
        // Script isolé (ne JAMAIS toucher au fichier versionné
        // generate_test_report.php utilisé par les tests DocAnalyzer).
        $script = storage_path('test_scripts/purge_tmp_script.php');
        $reportsDir = storage_path('test_scripts/reports');

        File::put($oldGen, 'docx orphelin');
        File::put($recentGen, 'docx récent');
        File::put($script, '<?php // utilitaire');
        // Fixture isolée : ne PAS écraser reports/rapport_test_structure.docx
        // (versionné, utilisé par les tests unitaires DocAnalyzer).
        $fixture = $reportsDir.'/fixture_purge_tmp.docx';
        File::put($fixture, 'fixture');

        touch($oldGen, now()->subHours(30)->getTimestamp());
        touch($recentGen, now()->subHours(2)->getTimestamp());

        $this->artisan('files:purge-temp')->assertSuccessful();

        $this->assertFileDoesNotExist($oldGen, 'gen_*.docx expiré (> TTL) doit être purgé.');
        $this->assertFileExists($recentGen, 'gen_*.docx récent (< TTL) doit être conservé.');
        $this->assertFileExists($script, 'Script utilitaire .php doit être conservé.');
        $this->assertFileExists($fixture, 'Fixture reports/ doit être conservée.');

        // Nettoyage des fichiers temporaires créés par ce test (jamais les
        // fichiers versionnés : generate_test_report.php, reports/).
        File::delete($fixture);
        File::delete($script);
        File::delete($recentGen);
    }

    public function test_files_purge_temp_respecte_ttl_personnalise(): void
    {
        $preview = storage_path('app/preview-custom-'.str_repeat('c', 10).'.docx');
        File::put($preview, 'x');
        // 10 h d'ancienneté : > 1 h (TTL personnalisé) mais < 24 h (TTL défaut)
        touch($preview, now()->subHours(10)->getTimestamp());

        $this->artisan('files:purge-temp', ['--ttl-hours' => 1])->assertSuccessful();
        $this->assertFileDoesNotExist($preview, 'Preview de plus de 1 h doit être supprimé avec --ttl-hours=1.');

        File::put($preview, 'x');
        touch($preview, now()->subHours(10)->getTimestamp());
        $this->artisan('files:purge-temp', ['--ttl-hours' => 24])->assertSuccessful();
        $this->assertFileExists($preview, 'Preview de moins de 24 h doit être conservé avec le TTL par défaut.');
    }
}
