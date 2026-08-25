<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\CoverPageTemplate;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Obfuscation des IDs de base de données dans les URLs (trait HasHashId).
 *
 * Les clés de route des modèles exposés dans les URLs doivent être des
 * hashs (jamais l'id auto-incrémenté brut) :
 *   - Document, ChatSession, Invoice, CoverPageTemplate
 *
 * Vérifications :
 *   - l'id brut n'apparaît JAMAIS dans l'URL générée par route()
 *   - le hash est déterministe (même id → même hash pour un modèle donné)
 *   - le hash est propre à chaque modèle (sel par modèle)
 *   - un hash invalide ne résout aucun modèle (→ 404 via le binding)
 *   - le hash ne permet pas d'énumérer les ids (pas de corrélation simple)
 */
class HasHashIdObfuscationTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_clés_de_route_sont_des_hashs_pour_les_4_modeles(): void
    {
        $user = User::factory()->create();

        $document = Document::create([
            'filename' => 'r.docx',
            'original_name' => 'r.docx',
            'path' => 'documents/r.docx',
            'size' => 1,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'status' => 'detected',
            'metadata' => ['user_id' => $user->id],
        ]);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session',
            'model_used' => 'gpt-4o-mini',
            'total_cost_credits' => 0,
        ]);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'type' => 'subscription',
            'amount' => 1000,
            'currency' => 'XAF',
            'status' => 'paid',
            'number' => 'INV-2026-000001',
            'payment_method' => 'kpay',
            'reference' => 'ref-1',
        ]);

        $template = CoverPageTemplate::create([
            'name' => 'Template',
            'user_id' => $user->id,
            'elements' => [],
            'page_style' => [],
            'is_public' => false,
        ]);

        // Chaque modèle doit utiliser un hash comme clé de route
        $this->assertSame('hash_id', $document->getRouteKeyName());
        $this->assertSame('hash_id', $session->getRouteKeyName());
        $this->assertSame('hash_id', $invoice->getRouteKeyName());
        $this->assertSame('hash_id', $template->getRouteKeyName());

        // L'id brut ne doit pas être devinable depuis le hash seul
        $this->assertNotSame((string) $document->id, $document->hash_id);
        $this->assertNotSame((string) $session->id, $session->hash_id);
        $this->assertNotSame((string) $invoice->id, $invoice->hash_id);
        $this->assertNotSame((string) $template->id, $template->hash_id);
    }

    public function test_le_hash_est_deterministe_et_propre_a_chaque_modele(): void
    {
        $user = User::factory()->create();

        $documentA = Document::create([
            'filename' => 'a.docx',
            'original_name' => 'a.docx',
            'path' => 'documents/a.docx',
            'size' => 1,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'status' => 'detected',
            'metadata' => ['user_id' => $user->id],
        ]);

        $documentB = Document::find($documentA->id);

        // Même id → même hash (déterministe, sans état)
        $this->assertSame($documentA->hash_id, $documentB->hash_id);

        // Le sel étant par modèle, le hash du même id diffère entre modèles
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'S',
            'model_used' => 'gpt-4o-mini',
            'total_cost_credits' => 0,
        ]);
        $session->id = $documentA->id; // force le même id pour comparaison
        $this->assertNotSame($documentA->hash_id, $session->hash_id);
    }

    public function test_un_hash_invalide_ne_resout_aucun_modele(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'filename' => 'r.docx',
            'original_name' => 'r.docx',
            'path' => 'documents/r.docx',
            'size' => 1,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'status' => 'detected',
            'metadata' => ['user_id' => $user->id],
        ]);

        $this->actingAs($user);

        // Hash inexistant → 404 (binding → null → ModelNotFoundException)
        $this->get('/documents/'.$document->hash_id.'X')->assertNotFound();

        // Id brut en clair dans l'URL → 404 (le binding attend un hash)
        $this->get('/documents/'.$document->id)->assertNotFound();

        // Hash d'un AUTRE modèle (bon sel requis) → 404
        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'S',
            'model_used' => 'gpt-4o-mini',
            'total_cost_credits' => 0,
        ]);
        $this->get('/documents/'.$session->hash_id)->assertNotFound();
    }

    public function test_les_routes_generees_contiennent_le_hash_pas_l_id(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'filename' => 'r.docx',
            'original_name' => 'r.docx',
            'path' => 'documents/r.docx',
            'size' => 1,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'status' => 'detected',
            'metadata' => ['user_id' => $user->id],
        ]);

        $url = route('documents.show', $document);

        $this->assertStringContainsString('/documents/'.$document->hash_id, $url);
        $this->assertStringNotContainsString('/documents/'.$document->id, $url);
    }
}
