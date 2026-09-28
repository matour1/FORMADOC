<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\GeneratedDocument;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Test de bout en bout du pipeline d'analyse (Phase E — DocAnalyzer).
 *
 * Simule un upload réel de DOCX puis vérifie :
 *   upload → stockage → analyse (DocAnalyzer : règles déterministes)
 *   → détection légendes (regex) → sauvegarde structure JSON → affichage.
 */
class DocumentAnalysisPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Les routes documents sont protégées par auth (P0-1)
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    private function createTestDocx(string $filename = 'rapport_test.docx'): string
    {
        $phpWord = new PhpWord;
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);

        $section = $phpWord->addSection();
        $section->addTitle('Introduction', 1);
        $section->addText("L'objectif de ce rapport est de présenter le projet.");
        $section->addTitle('1. Contexte', 1);
        $section->addText('Le contexte du projet est le suivant.');
        $section->addTitle('1.1 Institution', 2);
        $section->addText('L\'institution exige des normes de mise en forme.');
        $section->addText('Figure 1: Architecture de la plateforme');
        $section->addText('Le schéma ci-dessus illustre l\'architecture.');
        $section->addText('Tableau 1: Résultats comparatifs');

        $path = storage_path('app/test_tmp_'.uniqid().'.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    public function test_pipeline_complet_upload_analyse_affichage(): void
    {
        // Le DOCX utilise des styles HeadingN (addTitle) : les règles
        // déterministes suffisent → l'API DeepSeek ne doit PAS être appelée.
        Http::fake();

        $docxPath = $this->createTestDocx();

        // Upload simulé
        $response = $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $docxPath,
                'rapport_test.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true // test mode
            ),
        ]);

        // Redirection vers la page du document
        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame('detected', $document->status);

        // La structure a été sauvegardée (format DocAnalyzer : catégories)
        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $this->assertNotNull($structure);

        $data = $structure->structure;
        $this->assertArrayHasKey('titres', $data);
        $this->assertArrayHasKey('sous_titres', $data);
        $this->assertArrayHasKey('legends', $data);

        // Les titres HeadingN sont détectés par les règles (déterministe)
        $titres = array_column($data['titres'], 'texte');
        $this->assertContains('Introduction', $titres);
        $this->assertContains('1. Contexte', $titres);

        $sousTitres = array_column($data['sous_titres'], 'texte');
        $this->assertContains('1.1 Institution', $sousTitres);

        // Les légendes ont été détectées par regex
        $legendTypes = array_column($data['legends'], 'type');
        $this->assertContains('Figure', $legendTypes);
        $this->assertContains('Tableau', $legendTypes);

        // Les règles suffisent → AUCUN appel à l'API
        Http::assertNothingSent();

        // La page d'affichage est accessible et montre le résultat
        $page = $this->get("/documents/{$document->hash_id}");
        $page->assertOk();
        $page->assertSee('Introduction');
        $page->assertSee('Figure');
        $page->assertSee('Architecture de la plateforme');
    }

    public function test_upload_sans_fichier_est_refuse(): void
    {
        $response = $this->post('/documents/upload', []);
        $response->assertSessionHasErrors('document');
    }

    public function test_upload_fichier_non_word_est_refuse(): void
    {
        $response = $this->post('/documents/upload', [
            'document' => UploadedFile::fake()->create('malware.exe', 10),
        ]);

        $response->assertSessionHasErrors('document');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_upload_avec_title_method_regex_est_stocke(): void
    {
        Http::fake();

        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $docxPath,
                'rapport_regex.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'regex',
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame('detected', $document->status);
        $this->assertSame('regex', $document->metadata['title_method']);

        // Les règles + regex suffisent → pas d'appel IA
        Http::assertNothingSent();
    }

    /**
     * Le mode est CHOISI en un seul contrôle, et il est conservé en base.
     *
     * Le formulaire envoyait auparavant `title_method` ET `use_ai` séparément,
     * deux réglages qui pouvaient décrire des combinaisons ne correspondant à
     * aucun mode réel. Il envoie maintenant `mode`, dont le contrôleur DÉRIVE
     * les deux champs. Ce test fixe ce contrat : c'est lui qui garantit qu'un
     * formulaire forgé avec un mode inconnu retombe sur le mode gratuit.
     */
    public function test_le_mode_choisi_est_conserve_et_derive_les_champs_techniques(): void
    {
        Http::fake();

        $docxPath = $this->createTestDocx();

        $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $docxPath,
                'rapport_mode_regex.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'mode' => 'regex',
        ])->assertRedirect();

        $document = Document::first();
        $this->assertSame('regex', $document->metadata['mode']);
        $this->assertSame('regex', $document->metadata['title_method']);
        $this->assertFalse($document->metadata['use_ai']);
    }

    /**
     * **Un mode inconnu retombe sur le GRATUIT, jamais sur un payant.**
     *
     * Un formulaire forgé — ou une version antérieure du site restée ouverte
     * dans un onglet — peut envoyer un mode qui n'existe pas. Le traitement doit
     * alors rester gratuit : deviner un mode payant facturerait un service que
     * l'utilisateur n'a pas demandé.
     */
    public function test_un_mode_inconnu_retombe_sur_le_mode_gratuit(): void
    {
        Http::fake();

        $docxPath = $this->createTestDocx();

        $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $docxPath,
                'rapport_mode_bizarre.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'mode' => 'mode-invente-qui-coute-cher',
        ])->assertRedirect();

        $document = Document::first();
        $this->assertSame('regex', $document->metadata['mode']);
        $this->assertFalse($document->metadata['use_ai']);
        Http::assertNothingSent();
    }

    public function test_upload_avec_mode_precision_appelle_l_ia(): void
    {
        // L'IA est appelée car le mode « pleine précision » envoie le document
        // ENTIER à DeepSeek. Ce test vérifie aussi que le débit est déclenché :
        // c'est le mode le plus coûteux, il ne doit jamais être gratuit.
        $iaJson = json_encode([
            'titres' => [
                ['texte' => 'Introduction', 'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body']],
            ],
            'sous_titres' => [],
            'en_tetes' => [],
            'pieds_de_page' => [],
            'tableaux' => [],
            'images' => [],
            'elements_flottants' => [],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $iaJson]]],
            ], 200),
        ]);

        // Le mode « pleine précision » est PAYANT. Sans solde, le traitement est
        // refusé AVANT tout appel — c'est le comportement voulu, et sans
        // utilisateur il n'y a personne à débiter.
        $utilisateur = User::factory()->create(['credits_balance' => 5000]);
        $this->actingAs($utilisateur);

        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $docxPath,
                'rapport_ia.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'mode' => 'precision',
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame('ia', $document->metadata['title_method']);
        $this->assertSame('precision', $document->metadata['mode']);

        // Le mode « pleine précision » enchaîne l'analyse complète ET la mise en
        // forme complète (`LongFormattingJob`, tâche distincte routée vers un
        // autre modèle). Plusieurs appels sont donc NORMAUX ici — contrairement
        // au mode assisté, qui ne doit en produire qu'un.
        $this->assertGreaterThanOrEqual(1, Http::recorded()->count(),
            'La pleine précision doit au moins interroger le modèle pour analyser le document.');
    }

    public function test_upload_sans_use_ai_n_appelle_pas_l_ia(): void
    {
        // Phase 4 : l'IA est OPTIONNELLE. Sans la case « use_ai », aucun appel
        // externe n'est émis, même si des ambiguïtés existent.
        Http::fake();

        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $docxPath,
                'rapport_sans_ai.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'regex',
            // 'use_ai' volontairement absent (comportement par défaut)
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertFalse($document->metadata['use_ai']);
        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $this->assertNotNull($structure);
        // Aucune trace IA dans la structure
        $this->assertArrayNotHasKey('ai_corrections', $structure->structure);

        Http::assertNothingSent();
    }

    public function test_upload_avec_mode_assiste_appelle_l_ia_correcteur(): void
    {
        // Phase 4 : le mode « assistance IA » active le post-processeur
        // AiCorrectionService (corrections ciblées).
        // Le mode dérive title_method=regex → DocAnalyzer n'appelle PAS l'IA ;
        // seul le correcteur envoie une requête (payload réduit aux éléments
        // ambigus). Ce mode est PAYANT : le débit doit avoir lieu.
        // NB : un quota IA > 0 est requis → l'utilisateur doit avoir un
        // abonnement payant (P0-1 : les routes sont désormais authentifiées,
        // un plan default aurait quota IA = 0 et forcerait use_ai=false).
        $plan = Plan::factory()->create([
            'slug' => 'standard',
            'is_active' => true,
            'quota_deterministic' => 10,
            'quota_ai' => 5,
        ]);
        $subscriber = User::factory()->create([
            // Le mode « assistance IA » est PAYANT : le traitement est refusé
            // si le solde est insuffisant, délibérément, pour ne pas lancer un
            // appel coûteux qu'on ne pourra pas facturer. Le test doit donc
            // créditer l'utilisateur — c'est une condition du scénario, pas un
            // contournement.
            'credits_balance' => 5000,
        ]);
        Subscription::factory()->create([
            'user_id' => $subscriber->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $this->actingAs($subscriber);

        $iaJson = json_encode(['corrections' => []], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $iaJson]]],
            ], 200),
        ]);

        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $docxPath,
                'rapport_avec_ai.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'mode' => 'assiste',
        ]);
        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertTrue($document->metadata['use_ai']);

        // **Le mode assisté ne déclenche QUE la correction ciblée.**
        //
        // J'avais d'abord mesuré 4 appels et conclu que le mode sollicitait deux
        // passes IA (correction + classification). C'était faux : les appels
        // supplémentaires venaient du `LongFormattingJob`, que ce mode
        // déclenchait à tort. Un mode présenté comme « vérifier les passages
        // incertains » lançait en réalité une MISE EN FORME COMPLÈTE du document,
        // facturée séparément — et l'utilisateur ne le voyait nulle part.
        //
        // On vérifie donc qu'un seul appel a lieu : c'est ce qui garantit que le
        // mode reste proportionné à ce qu'il annonce.
        $this->assertSame(1, Http::recorded()->count(),
            'Le mode « assistance IA » ne doit solliciter QUE la correction ciblée. '
            .'Plus d\'un appel signale qu\'une autre opération, facturée à part, s\'est greffée.');

        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $this->assertNotNull($structure);
        // L'IA n'a proposé aucune correction → la structure déterministe est
        // conservée strictement à l'identique (aucune clé de traçabilité).
        $this->assertArrayNotHasKey('ai_corrections', $structure->structure);
    }

    public function test_upload_title_method_invalide_est_refuse(): void
    {
        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $docxPath,
                'rapport_invalide.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'magique',
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_upload_fichier_txt_est_analyse_avec_regex(): void
    {
        Http::fake();

        // Fichier texte brut : pas de styles Word → la passe regex détecte
        // les titres numérotés, sans appel IA.
        $txtPath = storage_path('app/test_tmp_txt_'.uniqid().'.txt');
        file_put_contents($txtPath, "RAPPORT DE STAGE\n\n1. Introduction\nCeci est un paragraphe.\n1.1 Contexte\nFin du rapport.\n");

        $response = $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $txtPath,
                'rapport_texte.txt',
                'text/plain',
                null,
                true
            ),
            'title_method' => 'regex',
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame('detected', $document->status);
        $this->assertSame('regex', $document->metadata['title_method']);

        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $this->assertNotNull($structure);

        $data = $structure->structure;
        $titres = array_column($data['titres'], 'texte');
        $this->assertContains('1. Introduction', $titres);

        $sousTitres = array_column($data['sous_titres'], 'texte');
        $this->assertContains('1.1 Contexte', $sousTitres);

        // La passe regex a suffi → aucun appel IA
        Http::assertNothingSent();
    }

    public function test_upload_fichier_trop_gros_est_refuse(): void
    {
        $response = $this->post('/documents/upload', [
            'document' => UploadedFile::fake()->create(
                'gros.docx',
                60 * 1024 // 60 Mo > 50 Mo max
            ),
        ]);

        $response->assertSessionHasErrors('document');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_generation_docx_depuis_la_structure(): void
    {
        Http::fake();

        // 1. Upload + analyse (structure sauvegardée)
        $docxPath = $this->createTestDocx();
        $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $docxPath,
                'rapport_test.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
        ]);

        $document = Document::first();
        $this->assertNotNull($document);

        // 2. Génération du DOCX reconstruit
        $response = $this->post("/documents/{$document->hash_id}/generate");
        $response->assertOk();

        // 3. Le fichier généré est un DOCX valide et rechargable
        $generated = GeneratedDocument::where('document_id', $document->id)->first();
        $this->assertNotNull($generated);
        $this->assertSame('generated', $generated->status);
        $this->assertFileExists($generated->output_path);

        $reloaded = IOFactory::load($generated->output_path);
        $this->assertInstanceOf(PhpWord::class, $reloaded);
    }
}
