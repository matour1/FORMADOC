<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests P2-1 — Quota consommé avant traitement (perdu si échec).
 *
 * Le quota déterministe (et IA) était consommé au DÉBUT de l'upload, AVANT
 * le stockage et l'analyse. Si une exception survenait (stockage KO, fichier
 * corrompu, analyse en échec), l'utilisateur perdait son quota SANS document.
 *
 * Correctif : remboursement en bloc dans le catch (QuotaService::refund) +
 * nettoyage du fichier stocké et de la ligne document en cas d'échec.
 */
class UploadQuotaRefundTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function postUpload(array $overrides = []): TestResponse
    {
        $docx = UploadedFile::fake()->create('rapport_test.docx', 1024, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        return $this->post('/documents/upload', array_merge([
            'document' => $docx,
            'title_method' => 'regex',
        ], $overrides));
    }

    public function test_echec_analyse_rembourse_le_quota_deterministe_et_nettoie_le_fichier(): void
    {
        $user = $this->makeUser();

        // Disk isolé : le fichier est stocké VIRTUELLEMENT (fake) → le parse
        // réel via storage_path() échoue → analyse en échec → remboursement.
        Storage::fake('storage');

        // Simule un échec de l'analyse (fichier corrompu) : le service de
        // détection lève une exception → le catch global doit rembourser.
        // On force un DOCX invalide (contenu bidon, mime trompeur).
        $docx = UploadedFile::fake()->createWithContent(
            'corrompu.docx',
            'pas un vrai docx'
        );

        $response = $this->post('/documents/upload', [
            'document' => $docx,
            'title_method' => 'regex',
        ]);

        $response->assertSessionHasErrors('document');

        // Quota remboursé : le compteur reste à 0
        $this->assertSame(0, $user->fresh()->usage_deterministic_month);

        // Aucun document créé ni fichier orphelin
        $this->assertDatabaseCount('documents', 0);
        $files = Storage::disk('storage')->allFiles('documents');
        $this->assertSame([], $files, 'Aucun fichier uploadé ne doit rester sur le disque.');
    }

    public function test_upload_reussi_consomme_le_quota_normalement(): void
    {
        $user = $this->makeUser();

        // Document valide réel (généré par PhpWord) pour un pipeline complet
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addTitle('Introduction', 1);
        $section->addText('Contenu du rapport.');
        $path = storage_path('app/test_p2_quota_'.uniqid().'.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        $response = $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $path,
                'rapport_ok.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'regex',
        ]);

        $response->assertRedirect();

        // Quota consommé (1 upload réussi)
        $this->assertSame(1, $user->fresh()->usage_deterministic_month);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_echec_stockage_rembourse_les_quotas_deterministe_et_ia(): void
    {
        $user = $this->makeUser();

        // Simule un échec du disque : le stockage du fichier lève une
        // exception → le catch global doit rembourser les quotas consommés.
        // Le mock de la façade Storage fait échouer disk('storage')->putFile().
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFile')->andThrow(new \RuntimeException('Disk full'));
        Storage::shouldReceive('disk')->with('storage')->andReturn($disk);

        // use_ai=1 : le quota IA est aussi consommé avant le stockage
        $response = $this->post('/documents/upload', [
            'document' => UploadedFile::fake()->create(
                'rapport_test.docx',
                1024,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            ),
            'title_method' => 'regex',
            'use_ai' => '1',
        ]);

        $response->assertSessionHasErrors('document');

        // Les deux quotas remboursés
        $this->assertSame(0, $user->fresh()->usage_deterministic_month);
        $this->assertSame(0, $user->fresh()->usage_ai_month);

        $this->assertDatabaseCount('documents', 0);
    }
}
