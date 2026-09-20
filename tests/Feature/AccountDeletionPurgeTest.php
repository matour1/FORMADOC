<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\CreditTransaction;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\GeneratedDocument;
use App\Models\Invoice;
use App\Models\KpayPayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests P0-3 — Suppression de compte : purge complète des données.
 *
 * La suppression du compte doit :
 *   - supprimer les fichiers de documents sur le BON disk ('storage',
 *     storage/uploads) — bug corrigé (avant : Storage::delete() visait 'local')
 *   - supprimer les fichiers générés par le chat (disk 'local',
 *     chat/generated/** et claude-skills/**) tracés dans
 *     metadata.generated_files des messages de l'utilisateur
 *   - purger la base : documents + structures + générations, sessions chat,
 *     transactions, factures, abonnements, paiements KPay
 */
class AccountDeletionPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_purge_toutes_les_donnees_et_fichiers(): void
    {
        $user = User::factory()->create();

        // --- Documents uploadés (fichier physique sur disk 'storage') ---
        $document = Document::create([
            'filename' => 'rapport.docx',
            'original_name' => 'rapport.docx',
            'path' => 'documents/'.$user->id.'/rapport.docx',
            'size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'status' => 'ready',
            'metadata' => ['user_id' => $user->id],
        ]);
        Storage::disk('storage')->put($document->path, 'contenu docx');

        // Structure + génération liées
        DocumentStructure::create([
            'document_id' => $document->id,
            'structure' => ['titres' => [['titre' => 'Intro', 'niveau' => 1]]],
            'ambiguities' => [],
            'validated_corrections' => [],
        ]);
        GeneratedDocument::create([
            'document_id' => $document->id,
            'output_path' => storage_path('test_scripts/gen_'.$document->id.'.docx'),
            'status' => 'generated',
        ]);
        file_put_contents(storage_path('test_scripts/gen_'.$document->id.'.docx'), 'docx généré');

        // --- Fichiers générés par le chat (disk 'local') ---
        $session = ChatSession::create(['user_id' => $user->id]);
        $chatPath = 'chat/generated/2026/01/15/rapport_reconstruit.docx';
        Storage::disk('local')->put($chatPath, 'contenu généré chat');
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Fichier : '.$chatPath,
            'metadata' => ['generated_files' => [$chatPath]],
        ]);

        // --- Pièces jointes de chat (P1-6, disk 'local') ---
        $attachmentPath = 'chat/attachments/'.$session->id.'/'.$user->id.'-note.txt';
        Storage::disk('local')->put($attachmentPath, 'contenu pièce jointe');
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Voici ma pièce jointe',
            'metadata' => ['attachments' => [['name' => 'note.txt', 'path' => $attachmentPath]]],
        ]);

        // --- Données SaaS ---
        $plan = Plan::factory()->create();
        CreditTransaction::create([
            'user_id' => $user->id,
            'type' => 'purchase',
            'amount' => 1000,
            'balance_after' => 1000,
            'currency' => 'XAF',
            'reference' => 'KPAY-TEST',
            'description' => 'test',
            'metadata' => [],
        ]);
        Invoice::create([
            'user_id' => $user->id,
            'number' => 'INV-'.time(),
            'amount' => 1000,
            'currency' => 'XAF',
            'status' => 'paid',
        ]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
        KpayPayment::create([
            'user_id' => $user->id,
            'payment_id' => 'KPAY-123',
            'external_id' => 'CREDIT-'.$user->id.'-1',
            'status' => 'COMPLETED',
            'purpose' => 'credit_purchase',
            'amount_fcfa' => 1000,
            'currency' => 'XAF',
            'metadata' => [],
        ]);

        // --- Action : suppression du compte ---
        $this->actingAs($user)
            ->delete(route('account.destroy'))
            ->assertRedirect('/');

        // --- Assertions : fichiers physiques supprimés ---
        $this->assertFalse(Storage::disk('storage')->exists($document->path), 'Fichier document uploadé doit être supprimé (disk storage).');
        $this->assertFalse(Storage::disk('local')->exists($chatPath), 'Fichier chat généré doit être supprimé (disk local).');
        $this->assertFalse(Storage::disk('local')->exists($attachmentPath), 'Pièce jointe chat doit être supprimée (P1-6, disk local).');
        $this->assertFileDoesNotExist(storage_path('test_scripts/gen_'.$document->id.'.docx'), 'DOCX généré doit être supprimé.');

        // --- Assertions : base purgée ---
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('document_structures', ['document_id' => $document->id]);
        $this->assertDatabaseMissing('generated_documents', ['document_id' => $document->id]);
        $this->assertDatabaseMissing('chat_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('chat_messages', ['chat_session_id' => $session->id]);
        $this->assertDatabaseMissing('credit_transactions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('invoices', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('subscriptions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('kpay_payments', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
