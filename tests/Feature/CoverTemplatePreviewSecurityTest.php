<?php

namespace Tests\Feature;

use App\Http\Controllers\CoverPageTemplateController;
use App\Models\CoverPageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-3 — Durcissement de l'aperçu serveur des pages de garde :
 * - l'URL de téléchargement est signée et expirable (temporarySignedRoute)
 * - le header X-Preview-Path (fuite du chemin serveur) est supprimé
 * - le token doit correspondre à un preview généré (préfixe `preview-`)
 * - un simple fichier `.docx` de storage/app n'est pas téléchargeable
 */
class CoverTemplatePreviewSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeTemplate(): CoverPageTemplate
    {
        return CoverPageTemplate::create([
            'name' => 'Gabarit P1-3',
            'description' => 'Test',
            'elements' => [
                ['type' => 'row', 'cells' => [
                    ['gridSpan' => 1, 'blocks' => [
                        ['kind' => 'text', 'text' => '{{titre}}', 'align' => 'center', 'size' => 20],
                    ]],
                ]],
            ],
            'page_style' => [
                'format' => 'A4',
                'orientation' => 'portrait',
                'margins' => ['top' => 25, 'bottom' => 25, 'left' => 25, 'right' => 25],
            ],
            'is_public' => true,
            'user_id' => User::factory()->create()->id,
        ]);
    }

    #[Test]
    public function la_reponse_preview_ne_fuit_plus_le_chemin_serveur(): void
    {
        $template = $this->makeTemplate();

        $response = $this->post(route('cover-templates.preview', $template), [
            'values' => ['titre' => 'Mon rapport'],
        ]);

        $response->assertOk();
        // P1-3 : le header qui exposait le chemin complet est supprimé
        $response->assertHeaderMissing('X-Preview-Path');
        $this->assertNotEmpty($response->json('url'));
        // L'URL retournée est bien la route signée
        $this->assertStringContainsString('/cover-templates/preview/', $response->json('url'));
    }

    #[Test]
    public function preview_sans_signature_est_refuse(): void
    {
        $template = $this->makeTemplate();

        // Génère un preview pour obtenir un token réel
        $response = $this->post(route('cover-templates.preview', $template), [
            'values' => ['titre' => 'Mon rapport'],
        ]);
        $url = $response->json('url');
        $path = parse_url($url, PHP_URL_PATH);

        // Même chemin, sans les paramètres de signature => refusé
        $this->get($path)->assertForbidden();
    }

    #[Test]
    public function preview_avec_signature_valide_telecharge_le_docx(): void
    {
        $template = $this->makeTemplate();

        $response = $this->post(route('cover-templates.preview', $template), [
            'values' => ['titre' => 'Mon rapport'],
        ]);
        $url = $response->json('url');

        $download = $this->get($url);
        $download->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $download->headers->get('Content-Type'),
        );
    }

    #[Test]
    public function signature_tamponnee_est_refusee(): void
    {
        $template = $this->makeTemplate();

        $response = $this->post(route('cover-templates.preview', $template), [
            'values' => ['titre' => 'Mon rapport'],
        ]);
        $url = $response->json('url');

        // Signature = dernier segment du query string => on la corrompt
        $tampered = preg_replace('/signature=[a-f0-9]+/i', 'signature=0', $url);

        $this->get($tampered)->assertForbidden();
    }

    #[Test]
    public function un_fichier_docx_ordinaire_de_storage_app_n_est_pas_telechargeable(): void
    {
        // Fichier légitime appartenant à l'app (ex. généré par le chat), hors preview
        Storage::disk('local')->put('generated/rapport.docx', 'contenu fictif');
        $token = 'generated/rapport.docx';

        // URL signée « valide » mais qui ne respecte pas le préfixe preview-
        $url = URL::signedRoute('cover-templates.preview.file', ['token' => $token]);

        $this->get($url)->assertNotFound();
    }

    #[Test]
    public function token_avec_traversee_de_repertoire_est_refuse(): void
    {
        // URL signée valide (signature acceptée) mais le token tente une
        // traversée de répertoire => le préfixe preview- + .docx ne matche
        // pas, le fichier n'existe pas => 404 attendu.
        $url = URL::signedRoute('cover-templates.preview.file', [
            'token' => 'preview-../../.env',
        ]);

        $this->get($url)->assertNotFound();
    }
}
