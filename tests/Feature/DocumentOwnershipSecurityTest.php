<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de sécurité P0-1 — IDOR documents.
 *
 * Les routes documents étant désormais protégées par auth + vérification
 * d'appartenance (metadata.user_id), un utilisateur ne peut PAS accéder
 * (ni télécharger, ni traiter, ni exporter) aux documents d'un autre
 * utilisateur : réponse 404 (comportement « introuvable » pour ne pas
 * révéler l'existence de documents tiers).
 */
class DocumentOwnershipSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeDocument(User $owner, string $status = 'detected'): Document
    {
        return Document::create([
            'filename' => 'rapport_'.$owner->id.'.docx',
            'original_name' => 'rapport_'.$owner->id.'.docx',
            'path' => 'documents/'.$owner->id.'/rapport.docx',
            'size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'status' => $status,
            'metadata' => ['user_id' => $owner->id],
        ]);
    }

    public function test_document_d_un_autre_utilisateur_est_introuvable(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $document = $this->makeDocument($owner);

        $this->actingAs($attacker);

        // Toutes les routes sensibles doivent répondre 404 (et non 403,
        // pour ne pas révéler l'existence du document). L'URL utilise le
        // hash obfusqué (jamais l'id brut) : l'attaquant peut connaître le
        // hash (ex. partagé), mais pas accéder au document d'autrui.
        $routes = [
            fn () => $this->get("/documents/{$document->hash_id}"),
            fn () => $this->get("/documents/{$document->hash_id}/preview"),
            fn () => $this->get("/documents/{$document->hash_id}/processing"),
            fn () => $this->get("/documents/{$document->hash_id}/export"),
            fn () => $this->post("/documents/{$document->hash_id}/preview-pdf"),
            fn () => $this->post("/documents/{$document->hash_id}/validate"),
            fn () => $this->post("/documents/{$document->hash_id}/generate"),
            fn () => $this->post("/documents/{$document->hash_id}/generate-cover"),
            fn () => $this->post("/documents/{$document->hash_id}/generate-cover-page"),
        ];

        foreach ($routes as $i => $makeResponse) {
            $response = $makeResponse();
            $this->assertSame(
                404,
                $response->status(),
                'Route #'.$i.' devrait être 404, statut : '.$response->status()
                    .' — '.$response->headers->get('Location', ''),
            );
        }
    }

    public function test_proprietaire_accede_a_son_document(): void
    {
        $owner = User::factory()->create();
        $document = $this->makeDocument($owner);

        // Le preview lit le fichier physique storage/uploads/{path}
        $previewDir = storage_path('uploads/'.dirname($document->path));
        if (! is_dir($previewDir)) {
            mkdir($previewDir, 0775, true);
        }
        file_put_contents(storage_path('uploads/'.$document->path), 'contenu docx factice');

        $this->actingAs($owner);

        $this->get("/documents/{$document->hash_id}")->assertOk();
        $this->get("/documents/{$document->hash_id}/preview")->assertOk();
    }

    public function test_non_authentifie_est_redirige_vers_login(): void
    {
        $owner = User::factory()->create();
        $document = $this->makeDocument($owner);

        // Pas d'actingAs → invité
        $this->get("/documents/{$document->hash_id}")
            ->assertRedirect(route('login'));
    }

    public function test_document_sans_user_id_est_introuvable(): void
    {
        $user = User::factory()->create();

        $orphan = Document::create([
            'filename' => 'orphelin.docx',
            'original_name' => 'orphelin.docx',
            'path' => 'documents/orphelin.docx',
            'size' => 1234,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'status' => 'detected',
            'metadata' => [],
        ]);

        $this->actingAs($user);

        // Un document sans propriétaire ne doit être accessible à personne
        $this->get("/documents/{$orphan->hash_id}")->assertNotFound();
    }
}
