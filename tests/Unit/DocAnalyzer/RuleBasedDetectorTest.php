<?php

declare(strict_types=1);

namespace Tests\Unit\DocAnalyzer;

use App\DocAnalyzer\DocumentParser;
use App\DocAnalyzer\RuleBasedDetector;
use InvalidArgumentException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests du RuleBasedDetector : détection déterministe par styles.
 *
 * On génère un DOCX contrôlé (titres HeadingN, titre manuel par taille+gras,
 * en-tête, pied de page, tableau) et on vérifie que les règles classent
 * correctement chaque élément dans sa catégorie.
 */
class RuleBasedDetectorTest extends TestCase
{
    private string $tempDir;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/formadoc_rules_'.uniqid();
        mkdir($this->tempDir, 0777, true);

        // Règles identiques à config/analyzer.php (réduites pour le test)
        $this->rules = [
            [
                'nom' => 'titre_niveau_1_style',
                'categorie' => 'titres',
                'niveau' => 1,
                'quand' => ['type' => 'titre', 'parent' => '*', 'style_name' => 'Heading1'],
            ],
            [
                'nom' => 'titre_niveau_2_style',
                'categorie' => 'sous_titres',
                'niveau' => 2,
                'quand' => ['type' => 'titre', 'parent' => '*', 'style_name' => 'Heading2'],
            ],
            [
                'nom' => 'titre_niveau_1_taille',
                'categorie' => 'titres',
                'niveau' => 1,
                'quand' => ['parent' => '*', 'font_size' => ['>=' => 16], 'gras' => true],
            ],
            [
                'nom' => 'titre_niveau_2_taille',
                'categorie' => 'sous_titres',
                'niveau' => 2,
                'quand' => ['parent' => '*', 'font_size' => ['>=' => 14], 'gras' => true],
            ],
            [
                'nom' => 'en_tete',
                'categorie' => 'en_tetes',
                'quand' => ['parent' => 'header'],
            ],
            [
                'nom' => 'pied_de_page',
                'categorie' => 'pieds_de_page',
                'quand' => ['parent' => 'footer'],
            ],
            [
                'nom' => 'tableau',
                'categorie' => 'tableaux',
                'quand' => ['type' => 'tableau'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * Génère un DOCX contrôlé : titres HeadingN, titre manuel (taille+gras),
     * en-tête, pied de page, tableau, paragraphe normal.
     */
    private function createControlledDocx(string $filename = 'controle.docx'): string
    {
        $phpWord = new PhpWord;

        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);

        $section = $phpWord->addSection();

        $header = $section->addHeader();
        $header->addText('EN-TÊTE FORMADOC');

        $footer = $section->addFooter();
        $footer->addText('Page 1');

        $section->addTitle('Résumé', 1);
        $section->addTitle('1.1 Sous-section', 2);
        // Titre manuel : pas de style HeadingN mais taille 18 + gras
        $section->addText('ANNEXE A', ['bold' => true, 'size' => 18]);
        // Paragraphe normal : ne doit matcher aucune règle de titre
        $section->addText('Ceci est un paragraphe normal.', ['size' => 11]);

        $table = $section->addTable();
        $table->addRow();
        $table->addCell()->addText('Cellule A');
        $table->addCell()->addText('Cellule B');

        $path = $this->tempDir.'/'.$filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    public function test_constructeur_rejette_les_regles_vides(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RuleBasedDetector([]);
    }

    public function test_constructeur_rejette_une_categorie_inconnue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RuleBasedDetector([
            ['nom' => 'x', 'categorie' => 'inconnue', 'quand' => ['parent' => '*']],
        ]);
    }

    public function test_constructeur_rejette_une_regle_sans_condition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RuleBasedDetector([
            ['nom' => 'x', 'categorie' => 'titres'],
        ]);
    }

    public function test_detecte_les_titres_par_style_heading(): void
    {
        $path = $this->createControlledDocx();
        $parsed = (new DocumentParser($path))->parse();
        $result = (new RuleBasedDetector($this->rules))->detect($parsed);

        $this->assertNotEmpty($result['titres']);
        $titres = array_map(fn (array $i) => $i['texte'], $result['titres']);
        $this->assertContains('Résumé', $titres);

        $this->assertNotEmpty($result['sous_titres']);
        $sousTitres = array_map(fn (array $i) => $i['texte'], $result['sous_titres']);
        $this->assertContains('1.1 Sous-section', $sousTitres);
    }

    public function test_detecte_les_titres_par_taille_et_gras_fallback(): void
    {
        $path = $this->createControlledDocx();
        $parsed = (new DocumentParser($path))->parse();
        $result = (new RuleBasedDetector($this->rules))->detect($parsed);

        // 'ANNEXE A' (taille 18 + gras) doit être détecté comme titre niveau 1
        $titres = array_map(fn (array $i) => $i['texte'], $result['titres']);
        $this->assertContains('ANNEXE A', $titres);

        // Le niveau est bien renseigné
        foreach ($result['titres'] as $item) {
            if ($item['texte'] === 'ANNEXE A') {
                $this->assertSame(1, $item['niveau']);
            }
        }
    }

    public function test_le_paragraphe_normal_n_est_pas_un_titre(): void
    {
        $path = $this->createControlledDocx();
        $parsed = (new DocumentParser($path))->parse();
        $result = (new RuleBasedDetector($this->rules))->detect($parsed);

        $tousTextes = array_merge(
            array_map(fn (array $i) => $i['texte'], $result['titres']),
            array_map(fn (array $i) => $i['texte'], $result['sous_titres'])
        );

        $this->assertNotContains('Ceci est un paragraphe normal.', $tousTextes);
    }

    public function test_detecte_en_tete_et_pied_de_page(): void
    {
        $path = $this->createControlledDocx();
        $parsed = (new DocumentParser($path))->parse();
        $result = (new RuleBasedDetector($this->rules))->detect($parsed);

        $this->assertNotEmpty($result['en_tetes']);
        $this->assertSame('EN-TÊTE FORMADOC', $result['en_tetes'][0]['texte']);
        $this->assertSame('header', $result['en_tetes'][0]['position']['parent']);

        $this->assertNotEmpty($result['pieds_de_page']);
        $this->assertSame('Page 1', $result['pieds_de_page'][0]['texte']);
        $this->assertSame('footer', $result['pieds_de_page'][0]['position']['parent']);
    }

    public function test_detecte_les_tableaux(): void
    {
        $path = $this->createControlledDocx();
        $parsed = (new DocumentParser($path))->parse();
        $result = (new RuleBasedDetector($this->rules))->detect($parsed);

        $this->assertNotEmpty($result['tableaux']);
        $this->assertStringContainsString('Cellule A', $result['tableaux'][0]['texte']);
        $this->assertSame(1, $result['tableaux'][0]['rows_count']);
    }

    public function test_les_resultats_sont_tries_par_position(): void
    {
        $path = $this->createControlledDocx();
        $parsed = (new DocumentParser($path))->parse();
        $result = (new RuleBasedDetector($this->rules))->detect($parsed);

        // Les titres doivent être triés par element_index croissant
        $indexes = array_map(
            fn (array $i) => $i['position']['element_index'],
            $result['titres']
        );

        $sorted = $indexes;
        sort($sorted);
        $this->assertSame($sorted, $indexes);
    }

    public function test_les_categories_vides_sont_presentes(): void
    {
        $path = $this->createControlledDocx();
        $parsed = (new DocumentParser($path))->parse();
        $result = (new RuleBasedDetector($this->rules))->detect($parsed);

        foreach (['titres', 'sous_titres', 'en_tetes', 'pieds_de_page', 'tableaux', 'images', 'elements_flottants'] as $categorie) {
            $this->assertArrayHasKey($categorie, $result);
            $this->assertIsArray($result[$categorie]);
        }
        $this->assertEmpty($result['images']);
        $this->assertEmpty($result['elements_flottants']);
    }

    public function test_detecte_sur_le_fixture_existant(): void
    {
        $fixture = storage_path('test_scripts/reports/rapport_test_structure.docx');

        if (! file_exists($fixture)) {
            $this->markTestSkipped('Fixture absent — générer via storage/test_scripts/generate_test_report.php');
        }

        $parsed = (new DocumentParser($fixture))->parse();
        $result = (new RuleBasedDetector($this->rules))->detect($parsed);

        $this->assertNotEmpty($result['titres']);
        $titres = array_map(fn (array $i) => $i['texte'], $result['titres']);
        $this->assertContains('Résumé', $titres);
        $this->assertContains('Introduction', $titres);
        $this->assertContains('Conclusion', $titres);
    }

    public function test_chargement_des_regles_depuis_la_config_laravel(): void
    {
        $rules = config('analyzer.rules');

        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);

        // La config réelle doit couvrir toutes les catégories
        $categories = array_unique(array_map(fn (array $r) => $r['categorie'], $rules));
        foreach (['titres', 'sous_titres', 'en_tetes', 'pieds_de_page', 'tableaux', 'images'] as $attendu) {
            $this->assertContains($attendu, $categories);
        }

        // Le détecteur construit avec la config réelle fonctionne sur le fixture
        $fixture = storage_path('test_scripts/reports/rapport_test_structure.docx');
        if (file_exists($fixture)) {
            $parsed = (new DocumentParser($fixture))->parse();
            $result = (new RuleBasedDetector($rules))->detect($parsed);
            $this->assertNotEmpty($result['titres']);
        }
    }
}
