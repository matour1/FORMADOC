<?php

declare(strict_types=1);

namespace Tests\Unit\DocAnalyzer;

use App\DocAnalyzer\AnalyzerResult;
use App\DocAnalyzer\DocAnalyzer;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests d'intégration du DocAnalyzer (orchestrateur).
 *
 * Pipeline complet : DocumentParser → RuleBasedDetector → DeepSeekAnalyzer
 * (si nécessaire) → ResultMerger. On vérifie :
 *  - les règles suffisent → pas d'appel IA (forceIA=false par défaut)
 *  - catégorie critique vide → IA appelée en complément
 *  - forceIA=true → IA appelée, fusion règles+IA sans doublons
 *  - l'échec IA n'est jamais fatal (résultat = règles seules)
 */
class DocAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/formadoc_analyzer_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        config(['deepseek.retry_delays_ms' => [0, 0, 0, 0]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * DOCX contrôlé avec titres HeadingN (les règles suffisent) + header/footer.
     */
    private function createControlledDocx(string $filename = 'controle.docx'): string
    {
        $phpWord = new PhpWord();

        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);

        $section = $phpWord->addSection();
        $section->addHeader()->addText('EN-TÊTE FORMADOC');
        $section->addFooter()->addText('Page 1');

        $section->addTitle('Résumé', 1);
        $section->addTitle('1.1 Sous-section', 2);
        $section->addText('Ceci est un paragraphe normal.', ['size' => 11]);

        $path = $this->tempDir . '/' . $filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    /**
     * DOCX SANS titres (catégorie critique vide → déclenche l'IA).
     */
    private function createDocxWithoutTitles(string $filename = 'sans_titres.docx'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Ceci est un paragraphe sans aucun titre.', ['size' => 11]);
        $section->addText('Un autre paragraphe.', ['size' => 11]);

        $path = $this->tempDir . '/' . $filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Réponse IA JSON valide (les positions correspondent au DOCX contrôlé).
     */
    private function iaJsonResponse(): string
    {
        return json_encode([
            'titres' => [
                ['texte' => 'Résumé', 'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body']],
            ],
            'sous_titres' => [
                ['texte' => '1.1 Sous-section', 'position' => ['section_index' => 0, 'element_index' => 1, 'parent' => 'body']],
            ],
            'en_tetes' => [
                ['texte' => 'EN-TÊTE FORMADOC', 'position' => ['section_index' => 0, 'element_index' => 2, 'parent' => 'header']],
            ],
            'pieds_de_page' => [
                ['texte' => 'Page 1', 'position' => ['section_index' => 0, 'element_index' => 3, 'parent' => 'footer']],
            ],
            'tableaux' => [],
            'images' => [],
            'elements_flottants' => [
                ['texte' => 'Ceci est un paragraphe normal.', 'position' => ['section_index' => 0, 'element_index' => 4, 'parent' => 'body']],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_les_regles_suffisent_sans_appel_ia(): void
    {
        Http::fake();

        $analyzer = new DocAnalyzer(config_path('analyzer.php'));
        $result = $analyzer->analyze($this->createControlledDocx());

        $this->assertTrue(AnalyzerResult::isValid($result));
        $this->assertCount(1, $result['titres']);
        $this->assertSame('Résumé', $result['titres'][0]['texte']);
        $this->assertCount(1, $result['sous_titres']);
        $this->assertCount(1, $result['en_tetes']);
        $this->assertSame('EN-TÊTE FORMADOC', $result['en_tetes'][0]['texte']);
        $this->assertCount(1, $result['pieds_de_page']);

        // Les règles suffisent → AUCUN appel réseau
        Http::assertNothingSent();
    }

    public function test_categorie_critique_vide_declenche_l_ia(): void
    {
        $iaJson = json_encode([
            'titres' => [
                ['texte' => 'Titre IA', 'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body']],
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

        $analyzer = new DocAnalyzer(config_path('analyzer.php'));
        $result = $analyzer->analyze($this->createDocxWithoutTitles());

        // L'IA a complété la catégorie critique vide
        $this->assertCount(1, $result['titres']);
        $this->assertSame('Titre IA', $result['titres'][0]['texte']);
        Http::assertSentCount(1);
    }

    public function test_force_ia_fusionne_sans_doublons(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $this->iaJsonResponse()]]],
            ], 200),
        ]);

        $analyzer = new DocAnalyzer(config_path('analyzer.php'));
        $result = $analyzer->analyze($this->createControlledDocx(), forceIA: true);

        // La fusion garde les règles (Résumé / 1.1 Sous-section) SANS doublon
        // avec ceux de l'IA (mêmes element_index) — l'IA ajoute le flottant.
        $this->assertCount(1, $result['titres']);
        $this->assertSame('Résumé', $result['titres'][0]['texte']);
        $this->assertCount(1, $result['sous_titres']);
        $this->assertCount(1, $result['elements_flottants']);
        $this->assertSame('Ceci est un paragraphe normal.', $result['elements_flottants'][0]['texte']);
        Http::assertSentCount(1);
    }

    public function test_echec_ia_n_est_jamais_fatal(): void
    {
        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => 'overloaded'], 503),
        ]);

        $analyzer = new DocAnalyzer(config_path('analyzer.php'));
        $result = $analyzer->analyze($this->createDocxWithoutTitles());

        // L'IA a échoué → résultat vide valide (règles seules, ici rien)
        $this->assertTrue(AnalyzerResult::isValid($result));
        $this->assertSame([], $result['titres']);
        Http::assertSentCount(1);
    }

    public function test_fichier_de_regles_introuvable_leve_une_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fichier de règles introuvable');

        $analyzer = new DocAnalyzer(sys_get_temp_dir() . '/inexistant.php');
        $analyzer->analyze($this->createControlledDocx());
    }

    public function test_apply_styles_n_est_pas_implemente_en_v1(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('gabarit non implémenté');

        $analyzer = new DocAnalyzer(config_path('analyzer.php'));
        $analyzer->applyStyles($this->createControlledDocx(), ['police' => 'Arial']);
    }
}
