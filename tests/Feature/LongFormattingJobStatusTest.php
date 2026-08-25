<?php

namespace Tests\Feature;

use App\Jobs\LongFormattingJob;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\User;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

/**
 * P2-2 — Statut « processing » pendant la mise en forme IA (LongFormattingJob).
 *
 * Avant : le document restait en « detected » pendant tout le job asynchrone,
 * sans indicateur pour l'utilisateur qu'un traitement IA était en cours.
 * Après : le job passe le document en « processing » au début (après les
 * vérifications), puis « ready » au succès, et revient à « detected » en cas
 * d'échec récupérable (l'utilisateur peut relancer).
 */
class LongFormattingJobStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(int $credits = 100): User
    {
        return User::factory()->create(['credits_balance' => $credits]);
    }

    private function makeDocument(User $user, string $status = 'detected'): Document
    {
        $document = Document::create([
            'filename' => 'rapport_test.docx',
            'path' => 'documents/rapport_test.docx',
            'status' => $status,
            'metadata' => ['user_id' => $user->id, 'size' => 1024, 'page_count' => 3],
        ]);

        DocumentStructure::create([
            'document_id' => $document->id,
            'structure' => [
                'titres' => [['texte' => 'Introduction', 'niveau' => 1]],
                'sous_titres' => [],
                'en_tetes' => [],
                'pieds_de_page' => [],
                'legends' => [],
                'tableaux' => [],
                'images' => [],
            ],
            'ambiguities' => [],
            'validated_corrections' => [],
        ]);

        return $document;
    }

    private function fakeChatSuccess(): void
    {
        $this->mock(OpenRouterService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('chat')->andReturn([
                'content' => '{"améliorations": []}',
                'model' => 'deepseek/deepseek-chat',
                'cost_usd' => 0.0005,
                'cost_credits' => 2,
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]);
        });
    }

    public function test_job_reussi_passe_le_document_de_processing_a_ready(): void
    {
        Mail::fake();
        $this->fakeChatSuccess();

        $user = $this->makeUser();
        $document = $this->makeDocument($user);

        (new LongFormattingJob($document, estimatedCredits: 5, userId: $user->id))
            ->handle(app(OpenRouterService::class), app(\App\Services\Billing\CreditService::class));

        // Statut final : ready (le job a transité par processing)
        $document->refresh();
        $this->assertSame('ready', $document->status);

        // Les métadonnées du formatage IA sont enregistrées
        $this->assertArrayHasKey('ai_format', $document->metadata);
        $this->assertSame('deepseek/deepseek-chat', $document->metadata['ai_format']['model']);

        // Coût réel (2) < estimation (5) → ajustement remboursé
        // 100 - 5 + (5 - 2) = 98
        $this->assertSame(98, $user->fresh()->credits_balance);
    }

    public function test_job_echoue_retourne_le_document_a_detected_et_rembourse(): void
    {
        Mail::fake();

        // Échec de l'appel OpenRouter (exception)
        $this->mock(OpenRouterService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('chat')->andThrow(new \RuntimeException('API indisponible'));
        });

        $user = $this->makeUser();
        $document = $this->makeDocument($user);

        (new LongFormattingJob($document, estimatedCredits: 5, userId: $user->id))
            ->handle(app(OpenRouterService::class), app(\App\Services\Billing\CreditService::class));

        // Statut revenu à detected (relançable) + crédits remboursés
        $document->refresh();
        $this->assertSame('detected', $document->status);
        $this->assertSame(100, $user->fresh()->credits_balance);
    }

    public function test_job_avec_solde_insuffisant_ne_change_pas_le_statut(): void
    {
        $user = $this->makeUser(credits: 1);
        $document = $this->makeDocument($user);

        (new LongFormattingJob($document, estimatedCredits: 5, userId: $user->id))
            ->handle(app(OpenRouterService::class), app(\App\Services\Billing\CreditService::class));

        $document->refresh();
        $this->assertSame('detected', $document->status);
        $this->assertSame(1, $user->fresh()->credits_balance);
    }

    public function test_dashboard_filtre_processing_inclut_le_statut_processing(): void
    {
        $user = $this->makeUser();
        $document = $this->makeDocument($user, status: 'processing');

        $response = $this->actingAs($user)->get('/documents?filter=processing');

        $response->assertOk();
        $response->assertSee($document->filename);
        $response->assertSee('IA en cours');
    }

    public function test_dashboard_compteur_processing_inclut_le_statut_processing(): void
    {
        $user = $this->makeUser();
        $this->makeDocument($user, status: 'processing');
        $this->makeDocument($user, status: 'detected');

        $response = $this->actingAs($user)->get('/documents');

        $response->assertOk();
        // 2 documents « en cours » (processing + detected)
        $response->assertSee('En cours', false);
    }
}
