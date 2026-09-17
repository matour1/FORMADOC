<?php

declare(strict_types=1);

namespace Tests\Unit\DocAnalyzer;

use App\DocAnalyzer\DocumentReconstructor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests du DocumentReconstructor : génération d'un DOCX à partir de la
 * structure détectée par le DocAnalyzer (Phase 2 — Génération DOCX).
 *
 * On vérifie que le document généré contient bien :
 *  - des titres avec les styles natifs Heading1/Heading2/Heading3 (TOC),
 *  - une section frontispice en numérotation romaine, puis le corps en
 *    numérotation arabe recommençant à 1 (bascule via section break),
 *  - un champ TOC (sommaire) mis à jour automatiquement (updateFields),
 *  - en-têtes et pieds de page (avec champ PAGE au bon format),
 *  - une liste des figures / tableaux générée depuis les légendes.
 */
class DocumentReconstructorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/formadoc_reconstruct_'.uniqid();
        mkdir($this->tempDir, 0777, true);
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
     * Structure d'analyse minimale et réaliste (contrat AnalyzerResult +
     * légendes), typique d'un rapport de stage :
     *  - 3 titres niveau 1, 2 sous-titres niveau 2, 1 sous-titre niveau 3
     *  - en-tête + pied de page réels
     *  - légendes figures / tableaux
     */
    private function sampleAnalysis(): array
    {
        return [
            'titres' => [
                [
                    'texte' => 'SOMMAIRE',
                    'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'titres',
                    'niveau' => 1,
                ],
                [
                    'texte' => 'INTRODUCTION',
                    'position' => ['section_index' => 1, 'element_index' => 10, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'titres',
                    'niveau' => 1,
                ],
                [
                    'texte' => 'CONCLUSION',
                    'position' => ['section_index' => 1, 'element_index' => 90, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'titres',
                    'niveau' => 1,
                ],
            ],
            'sous_titres' => [
                [
                    'texte' => '1. CONTEXTE GENERAL',
                    'position' => ['section_index' => 1, 'element_index' => 11, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'sous_titres',
                    'niveau' => 2,
                ],
                [
                    'texte' => '1.1 Présentation du projet',
                    'position' => ['section_index' => 1, 'element_index' => 12, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'sous_titres',
                    'niveau' => 3,
                ],
                [
                    'texte' => '2. METHODOLOGIE',
                    'position' => ['section_index' => 1, 'element_index' => 40, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'sous_titres',
                    'niveau' => 2,
                ],
            ],
            'en_tetes' => [
                [
                    'texte' => 'CONCEPTION ET REALISATION D\'UNE PLATEFORME',
                    'position' => ['section_index' => 0, 'element_index' => 2, 'parent' => 'header'],
                    'styles' => ['font' => null, 'paragraph' => null],
                    'type' => 'en_tetes',
                ],
            ],
            'pieds_de_page' => [
                [
                    'texte' => 'Rédigé par : JEAN',
                    'position' => ['section_index' => 0, 'element_index' => 3, 'parent' => 'footer'],
                    'styles' => ['font' => null, 'paragraph' => null],
                    'type' => 'pieds_de_page',
                ],
            ],
            'tableaux' => [],
            'images' => [],
            'elements_flottants' => [],
            'legends' => [
                ['type' => 'Figure', 'number' => 1, 'label' => 'Architecture du système', 'line' => 12],
                ['type' => 'Figure', 'number' => 2, 'label' => 'Diagramme de classes', 'line' => 30],
                ['type' => 'Tableau', 'number' => 1, 'label' => 'Récapitulatif des besoins', 'line' => 25],
            ],
        ];
    }

    /**
     * Extrait le XML d'une partie du DOCX généré (document.xml, settings.xml…).
     */
    private function readPart(string $docxPath, string $partName): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($docxPath), 'Le DOCX généré doit être un ZIP valide');
        $xml = $zip->getFromName($partName);
        $zip->close();

        $this->assertIsString($xml, "La partie {$partName} doit exister");
        $this->assertNotEmpty($xml, "La partie {$partName} ne doit pas être vide");

        return $xml;
    }

    /**
     * Relit le DOCX généré via PhpWord pour vérifier les éléments.
     */
    private function reloadDocx(string $docxPath): PhpWord
    {
        $phpWord = IOFactory::load($docxPath);

        return $phpWord;
    }

    // ── Génération de base ────────────────────────────────────────────────────

    public function test_genere_un_docx_valide(): void
    {
        $outputPath = $this->tempDir.'/sortie.docx';

        $reconstructor = new DocumentReconstructor;
        $result = $reconstructor->reconstruct($this->sampleAnalysis(), $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertSame($outputPath, $result);

        // Le fichier doit être un ZIP OOXML valide
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($outputPath));
        $this->assertNotFalse($zip->getFromName('word/document.xml'));
        $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
        $zip->close();
    }

    // ── Styles de titres natifs ───────────────────────────────────────────────

    public function test_les_titres_utilisent_les_styles_natifs_heading(): void
    {
        $outputPath = $this->tempDir.'/titres.docx';
        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // Titres niveau 1 → pStyle Heading1
        $this->assertStringContainsString('w:val="Heading1"', $xml);
        // Sous-titres niveau 2 → Heading2
        $this->assertStringContainsString('w:val="Heading2"', $xml);
        // Sous-titres niveau 3 → Heading3
        $this->assertStringContainsString('w:val="Heading3"', $xml);

        // Les textes des titres sont présents dans le corps
        $this->assertStringContainsString('INTRODUCTION', $xml);
        $this->assertStringContainsString('CONCLUSION', $xml);
        $this->assertStringContainsString('CONTEXTE GENERAL', $xml);
    }

    // ── Sections : bascule romain → arabe ─────────────────────────────────────

    public function test_la_premiere_section_est_en_romain_et_le_corps_en_arabe(): void
    {
        $outputPath = $this->tempDir.'/sections.docx';
        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // Section frontispice : pgNumType fmt=lowerRoman (i, ii, iii…)
        $this->assertStringContainsString('w:fmt="lowerRoman"', $xml);

        // Corps : pgNumType fmt=decimal (1, 2, 3…) avec redémarrage à 1
        $this->assertStringContainsString('w:fmt="decimal"', $xml);
        $this->assertStringContainsString('w:start="1"', $xml);

        // Il doit y avoir exactement 2 sections (frontispice + corps)
        $this->assertSame(2, substr_count($xml, '<w:sectPr'));
    }

    public function test_le_champ_page_du_pied_utilise_le_bon_format(): void
    {
        $outputPath = $this->tempDir.'/page.docx';
        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/footer1.xml');

        // Le pied de page contient un champ PAGE au format romain
        $this->assertStringContainsString('PAGE', $xml);
        $this->assertStringContainsString('\\* roman', $xml);
    }

    // ── Sommaire (TOC) ────────────────────────────────────────────────────────

    public function test_le_sommaire_est_un_champ_toc_avec_mise_a_jour_automatique(): void
    {
        $outputPath = $this->tempDir.'/toc.docx';
        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        $documentXml = $this->readPart($outputPath, 'word/document.xml');

        // Le champ TOC est présent dans le corps (instruction TOC \o "1-3")
        $this->assertStringContainsString('TOC', $documentXml);
        $this->assertStringContainsString('\\o', $documentXml);

        // Le "SOMMAIRE" est un paragraphe stylé (addText) et non un titre
        // Heading1 : le champ TOC ne doit PAS l'inclure lui-même.
        $this->assertStringContainsString('SOMMAIRE', $documentXml);

        // settings.xml : updateFields activé → Word met à jour les champs
        $settingsXml = $this->readPart($outputPath, 'word/settings.xml');
        $this->assertStringContainsString('w:updateFields', $settingsXml);
        $this->assertStringContainsString('w:val="true"', $settingsXml);
    }

    // ── En-têtes et pieds de page ─────────────────────────────────────────────

    public function test_en_tete_et_pied_de_page_du_corps(): void
    {
        $outputPath = $this->tempDir.'/entete.docx';
        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        // En-tête : le texte détecté doit être présent
        $headerXml = $this->readPart($outputPath, 'word/header1.xml');
        $this->assertStringContainsString('CONCEPTION ET REALISATION', $headerXml);

        // Pied de page : texte détecté + champ PAGE
        $footerXml = $this->readPart($outputPath, 'word/footer1.xml');
        $this->assertStringContainsString('Rédigé par : JEAN', $footerXml);
        $this->assertStringContainsString('PAGE', $footerXml);
    }

    // ── Liste des figures / tableaux ──────────────────────────────────────────

    public function test_liste_des_figures_et_tableaux_generee_depuis_les_legendes(): void
    {
        $outputPath = $this->tempDir.'/liste.docx';
        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // La liste des figures est générée avec un titre STYLÉ (addText, hors
        // collection TOC : il ne doit pas s'inclure lui-même dans le sommaire)
        $this->assertStringContainsString('Liste des figures', $xml);
        $this->assertStringContainsString('Architecture du système', $xml);
        $this->assertStringContainsString('Diagramme de classes', $xml);

        // La liste des tableaux est générée
        $this->assertStringContainsString('Liste des tableaux', $xml);
        $this->assertStringContainsString('Récapitulatif des besoins', $xml);
    }

    public function test_les_titres_de_frontispice_ne_s_incluent_pas_dans_le_toc(): void
    {
        $outputPath = $this->tempDir.'/toc_hors_collection.docx';
        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // "SOMMAIRE", "Liste des figures", "Liste des tableaux" doivent être
        // des paragraphes de texte (addText), PAS des titres Heading1 — sinon
        // le champ TOC les listerait eux-mêmes (défaut 4).
        $this->assertStringContainsString('SOMMAIRE', $xml);
        $this->assertStringNotContainsString('w:val="Heading1"', $this->readPart($outputPath, 'word/styles.xml'));
    }

    public function test_listes_absentes_si_pas_de_legendes(): void
    {
        $analysis = $this->sampleAnalysis();
        $analysis['legends'] = [];

        $outputPath = $this->tempDir.'/sans_legendes.docx';
        (new DocumentReconstructor)->reconstruct($analysis, $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');
        $this->assertStringNotContainsString('Liste des figures', $xml);
        $this->assertStringNotContainsString('Liste des tableaux', $xml);
    }

    // ── Champs SEQ littéraux (structures pré-Phase 3) ────────────────────────

    public function test_les_champs_seq_litteraux_sont_resolus_avec_un_compteur_global(): void
    {
        $analysis = $this->sampleAnalysis();

        // Structures SAUVEGARDÉES avant la Phase 3 : le texte contient
        // encore les codes de champ Word "{ SEQ Figure \* ARABIC }".
        $analysis['body_complet'] = [
            [
                'type' => 'texte',
                'text' => 'Figure { SEQ Figure \* ARABIC } : Architecture',
                'styles' => [],
                'position' => ['section_index' => 1, 'element_index' => 20, 'parent' => 'body'],
            ],
            [
                'type' => 'texte',
                'text' => 'Figure { SEQ Figure \* ARABIC } : Diagramme de classes',
                'styles' => [],
                'position' => ['section_index' => 1, 'element_index' => 30, 'parent' => 'body'],
            ],
            [
                'type' => 'texte',
                'text' => 'Tableau { SEQ Tableau \* ARABIC } : Récapitulatif des besoins',
                'styles' => [],
                'position' => ['section_index' => 1, 'element_index' => 40, 'parent' => 'body'],
            ],
        ];
        // Les légendes de ces structures portent le même code de champ
        $analysis['legends'] = [
            ['type' => 'Figure', 'number' => '?', 'label' => '{ SEQ Figure \* ARABIC } : Architecture', 'line' => 1],
            ['type' => 'Tableau', 'number' => '?', 'label' => '{ SEQ Tableau \* ARABIC } : Récapitulatif des besoins', 'line' => 2],
        ];

        $outputPath = $this->tempDir.'/seq.docx';
        (new DocumentReconstructor)->reconstruct($analysis, $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // Plus AUCUN code de champ littéral
        $this->assertStringNotContainsString('{ SEQ', $xml);
        $this->assertStringNotContainsString('SEQ Figure', $xml);
        $this->assertStringNotContainsString('SEQ Tableau', $xml);

        // La numérotation est globale par type : Figure 1, 2… Tableau 1…
        $this->assertStringContainsString('Figure 1 : Architecture', $xml);
        $this->assertStringContainsString('Figure 2 : Diagramme de classes', $xml);
        $this->assertStringContainsString('Tableau 1 : Récapitulatif des besoins', $xml);

        // Les listes du frontispice reprennent la même numérotation
        // (même compteur global que le corps).
        $this->assertStringContainsString('Liste des figures', $xml);
        $this->assertStringContainsString('Figure 1 : Architecture', $xml);
        $this->assertStringContainsString('Liste des tableaux', $xml);
    }

    // ── Robustesse ────────────────────────────────────────────────────────────

    public function test_genere_un_document_rechargeable_par_phpword(): void
    {
        $outputPath = $this->tempDir.'/rechargeable.docx';
        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        // La relecture via PhpWord doit fonctionner (ZIP + XML valides)
        $phpWord = $this->reloadDocx($outputPath);
        $this->assertInstanceOf(PhpWord::class, $phpWord);
        $this->assertNotEmpty($phpWord->getSections());
    }

    // ── Page de garde : RETIRÉE du produit ───────────────────────────────────
    //
    // Le reconstructeur n'accepte plus de paramètre `$cover` : le module page de
    // garde a été retiré. Les tests qui vérifiaient la préfixation d'une section
    // couverture sont remplacés par l'invariant ci-dessous, qui conserve la trace
    // du retrait au lieu de le laisser silencieux.

    public function test_le_reconstructeur_ne_produit_plus_de_section_couverture(): void
    {
        $outputPath = $this->tempDir.'/sans_couverture.docx';

        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // Exactement deux sections : frontispice (romain) + corps (arabe).
        // Une troisième section signalerait une couverture réintroduite.
        $this->assertSame(2, substr_count($xml, '<w:sectPr'));
        $this->assertStringContainsString('w:fmt="lowerRoman"', $xml);
        $this->assertStringContainsString('w:fmt="decimal"', $xml);
    }

    public function test_la_signature_ne_prend_plus_de_couverture(): void
    {
        // Garde-fou structurel : si `$cover` revenait dans la signature, le
        // retrait serait annulé sans que rien ne le signale.
        $noms = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(DocumentReconstructor::class, 'reconstruct'))->getParameters(),
        );

        $this->assertNotContains('cover', $noms);
        $this->assertSame(['analysis', 'outputPath', 'gabarit'], $noms);
    }

    public function test_sans_couverture_le_document_reste_a_deux_sections(): void
    {
        $outputPath = $this->tempDir.'/sans_couverture.docx';

        (new DocumentReconstructor)->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        $this->assertSame(2, substr_count($xml, '<w:sectPr'));
        $this->assertStringContainsString('w:fmt="lowerRoman"', $xml);
        $this->assertStringContainsString('w:fmt="decimal"', $xml);
    }

    // ── Body complet : reconstruction fidèle (paragraphes, listes, tableaux) ─

    public function test_le_body_complet_restitue_paragraphes_listes_et_tableaux(): void
    {
        $analysis = $this->sampleAnalysis();
        $analysis['body_complet'] = [
            [
                'type' => 'titre',
                'text' => 'INTRODUCTION',
                'depth' => 1,
                'position' => ['section_index' => 1, 'element_index' => 10, 'parent' => 'body'],
            ],
            [
                'type' => 'texte',
                'text' => 'Ceci est le premier paragraphe d\'introduction.',
                'position' => ['section_index' => 1, 'element_index' => 11, 'parent' => 'body'],
                'styles' => [],
            ],
            [
                'type' => 'liste',
                'text' => 'Premier élément de liste',
                'depth' => 0,
                'position' => ['section_index' => 1, 'element_index' => 12, 'parent' => 'body'],
            ],
            [
                'type' => 'liste',
                'text' => 'Sous-élément imbriqué',
                'depth' => 1,
                'position' => ['section_index' => 1, 'element_index' => 13, 'parent' => 'body'],
            ],
            [
                'type' => 'texte',
                'text' => 'Paragraphe avec un tableau ci-dessous.',
                'position' => ['section_index' => 1, 'element_index' => 14, 'parent' => 'body'],
                'styles' => [],
            ],
            [
                'type' => 'tableau',
                'text' => '',
                'rows' => [
                    ['cells' => ['Colonne A', 'Colonne B']],
                    ['cells' => ['Valeur 1', 'Valeur 2']],
                ],
                'rows_count' => 2,
                'position' => ['section_index' => 1, 'element_index' => 15, 'parent' => 'body'],
            ],
        ];

        $outputPath = $this->tempDir.'/body_complet.docx';
        (new DocumentReconstructor)->reconstruct($analysis, $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // Paragraphes du corps présents
        $this->assertStringContainsString('Ceci est le premier paragraphe', $xml);
        $this->assertStringContainsString('Paragraphe avec un tableau', $xml);

        // Éléments de liste restitués
        $this->assertStringContainsString('Premier élément de liste', $xml);
        $this->assertStringContainsString('Sous-élément imbriqué', $xml);
        $this->assertStringContainsString('<w:numPr>', $xml);

        // Tableau avec contenu des cellules
        $this->assertStringContainsString('<w:tbl>', $xml);
        $this->assertStringContainsString('Colonne A', $xml);
        $this->assertStringContainsString('Valeur 2', $xml);

        // Le titre INTRODUCTION (dans body_complet) est en style Heading1
        $this->assertStringContainsString('w:val="Heading1"', $xml);
    }

    public function test_le_body_complet_ignore_les_titres_de_frontispice(): void
    {
        $analysis = $this->sampleAnalysis();
        $analysis['body_complet'] = [
            [
                'type' => 'titre',
                'text' => 'SOMMAIRE',
                'depth' => 1,
                'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body'],
            ],
            [
                'type' => 'texte',
                'text' => 'Contenu réel du rapport.',
                'position' => ['section_index' => 1, 'element_index' => 5, 'parent' => 'body'],
                'styles' => [],
            ],
        ];

        $outputPath = $this->tempDir.'/frontispice.docx';
        (new DocumentReconstructor)->reconstruct($analysis, $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // Le SOMMAIRE est géré par la section frontispice (champ TOC) — pas dupliqué
        $this->assertStringContainsString('Contenu réel du rapport.', $xml);
    }

    // ── Gabarit de mise en forme ──────────────────────────────────────────────

    public function test_le_gabarit_est_applique_aux_titres_et_au_corps(): void
    {
        $analysis = $this->sampleAnalysis();
        $analysis['body_complet'] = [
            [
                'type' => 'titre',
                'text' => 'TITRE AVEC GABARIT',
                'depth' => 1,
                'position' => ['section_index' => 1, 'element_index' => 10, 'parent' => 'body'],
            ],
            [
                'type' => 'texte',
                'text' => 'Corps de texte mis en forme avec le gabarit.',
                'position' => ['section_index' => 1, 'element_index' => 11, 'parent' => 'body'],
                'styles' => [],
            ],
        ];

        $gabarit = [
            'police' => 'Arial',
            'tailles' => ['titre1' => 18, 'titre2' => 15, 'titre3' => 13, 'corps' => 11],
            'couleurs' => ['titre1' => 'FF0000', 'titre2' => 'FF0000', 'titre3' => 'FF0000', 'corps' => '222222'],
            'interligne' => 1.0,
            'espacements' => ['avant_titre' => 200, 'apres_titre' => 100, 'apres_paragraphe' => 80],
            'alignement_titres' => 'center',
            'marges' => ['top' => 1000, 'right' => 1000, 'bottom' => 1000, 'left' => 1000, 'header' => 500, 'footer' => 500],
            'tableau' => ['style' => 'TableGrid', 'header_couleur' => 'FF0000', 'header_texte' => 'FFFFFF', 'bordure' => true],
        ];

        $outputPath = $this->tempDir.'/gabarit.docx';
        (new DocumentReconstructor)->reconstruct($analysis, $outputPath, null, $gabarit);

        // styles.xml : police Arial + taille 18 pour les titres
        $stylesXml = $this->readPart($outputPath, 'word/styles.xml');
        $this->assertStringContainsString('Arial', $stylesXml);

        // document.xml : alignement centré sur les titres
        $xml = $this->readPart($outputPath, 'word/document.xml');
        $this->assertStringContainsString('TITRE AVEC GABARIT', $xml);
        $this->assertStringContainsString('Corps de texte mis en forme', $xml);
    }

    public function test_sans_gabarit_les_defauts_academiques_sont_utilises(): void
    {
        $analysis = $this->sampleAnalysis();
        $analysis['body_complet'] = [
            [
                'type' => 'texte',
                'text' => 'Texte de test par défaut.',
                'position' => ['section_index' => 1, 'element_index' => 5, 'parent' => 'body'],
                'styles' => [],
            ],
        ];

        $outputPath = $this->tempDir.'/defauts.docx';
        (new DocumentReconstructor)->reconstruct($analysis, $outputPath);

        $stylesXml = $this->readPart($outputPath, 'word/styles.xml');

        // Police par défaut : Times New Roman
        $this->assertStringContainsString('Times New Roman', $stylesXml);
    }

    // ── Phase 3 : map positions→niveau (titres MAJUSCULES stylés HeadingN) ──

    public function test_un_titre_detecte_mais_type_texte_est_rendu_en_heading(): void
    {
        $analysis = $this->sampleAnalysis();
        $analysis['titres'] = [
            [
                'texte' => 'INTRODUCTION',
                'position' => ['section_index' => 1, 'element_index' => 10, 'parent' => 'body'],
                'styles' => [],
                'type' => 'titres',
                'niveau' => 1,
            ],
        ];
        // Dans le body_complet, le même paragraphe est type 'texte' (ex :
        // paragraphe en MAJUSCULES détecté par la passe regex, pas par les
        // styles HeadingN — défaut 1 de la Phase 3).
        $analysis['body_complet'] = [
            [
                'type' => 'texte',
                'text' => 'INTRODUCTION',
                'position' => ['section_index' => 1, 'element_index' => 10, 'parent' => 'body'],
                'styles' => [],
            ],
        ];

        $outputPath = $this->tempDir.'/majuscules.docx';
        (new DocumentReconstructor)->reconstruct($analysis, $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // Le paragraphe 'texte' correspondant à un titre détecté est restitué
        // avec le style natif Heading1 → alimente le champ TOC.
        $this->assertStringContainsString('w:val="Heading1"', $xml);
        $this->assertStringContainsString('INTRODUCTION', $xml);
    }

    public function test_les_images_du_body_complet_sont_restituees(): void
    {
        // Petit PNG 1×1 valide en base64
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $analysis = $this->sampleAnalysis();
        $analysis['body_complet'] = [
            [
                'type' => 'image',
                'text' => '[image:pixel.png]',
                'image_name' => 'pixel.png',
                'image_extension' => 'png',
                'image_data' => base64_encode($png),
                'position' => ['section_index' => 1, 'element_index' => 20, 'parent' => 'body'],
            ],
        ];

        $outputPath = $this->tempDir.'/image.docx';
        (new DocumentReconstructor)->reconstruct($analysis, $outputPath);

        // Le document contient une image embarquée (media)
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($outputPath));
        $media = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && str_starts_with($name, 'word/media/')) {
                $media[] = $name;
            }
        }
        $zip->close();

        $this->assertNotEmpty($media, 'Le DOCX généré doit embarquer l\'image');
        $this->assertStringContainsString('.png', implode(' ', $media));
    }

    public function test_sans_image_data_le_texte_placeholder_est_ecrit(): void
    {
        $analysis = $this->sampleAnalysis();
        $analysis['body_complet'] = [
            [
                'type' => 'image',
                'text' => '[image:pixel.png]',
                'image_name' => 'pixel.png',
                'position' => ['section_index' => 1, 'element_index' => 20, 'parent' => 'body'],
                // pas d'image_data
            ],
        ];

        $outputPath = $this->tempDir.'/image_placeholder.docx';
        (new DocumentReconstructor)->reconstruct($analysis, $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');
        $this->assertStringContainsString('[Image: pixel.png]', $xml);
    }
}
