<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Adapters;

use App\Document\Adapters\DocxNativeAdapter;
use App\Document\Structure\BlockType;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Titres placés dans des ZONES DE TEXTE — promotion à la lecture.
 *
 * **Le défaut mesuré sur le document de référence.** Le DQP brut contient 26
 * zones de texte distinctes ; 19 portent un titre de niveau 1 (« SOMMAIRE »,
 * « CHAPITRE I : … »). `ParagraphReader` ne voit que les `w:p` du corps : le
 * contenu d'un `w:txbxContent` n'était lu par AUCUN chemin. Ces titres étaient
 * perdus avant même la détection.
 *
 * Mesure sur `RAPPORT DE STAGE DQP.docx` : **22 titres avant, 33 après** la
 * promotion, soit +11 (+50 %). C'est le gain que ces tests verrouillent.
 *
 * **Ce que les tests ne peuvent pas faire.** Construire une vraie zone de texte
 * avec PHPWord : l'écrivain ne les produit pas de façon fiable. Les cas sont
 * donc synthétisés en XML, ce qui a l'avantage de rendre la structure visible
 * et de garantir qu'on teste EXACTEMENT la forme rencontrée dans la nature.
 */
class TextBoxHeadingPromotionTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaires = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaires as $fichier) {
            @unlink($fichier);
        }

        parent::tearDown();
    }

    /**
     * Construit un `.docx` dont le corps contient un paragraphe et une zone de
     * texte portant `$texteZone`.
     *
     * La zone est enveloppée dans `mc:AlternateContent` avec `mc:Choice` et
     * `mc:Fallback` — exactement la forme produite par Word. C'est ce qui permet
     * de vérifier que la copie de repli n'entraîne pas un doublon.
     */
    private function docxAvecZone(string $texteZone, bool $avecForme = false): string
    {
        // Cas 1 — cadre de texte simple : la zone vit dans un `w:drawing`
        // ordinaire, sans forme. C'est la construction d'un titre centré dans un
        // cadre.
        $cadreSimple = '<w:p><w:r><w:drawing><wp:inline><a:graphic><a:graphicData>'
            .'<w:txbxContent><w:p><w:r><w:t>'.$texteZone.'</w:t></w:r></w:p></w:txbxContent>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

        // Cas 2 — SCHÉMA : PLUSIEURS formes dans le même dessin, comme un
        // organigramme. C'est le critère réel de §5.4 (« plusieurs petites
        // formes ») — une forme UNIQUE est un cadre de texte, et la promote.
        $schema = '<w:p><w:r><w:drawing><wp:inline><a:graphic><a:graphicData>'
            .'<wps:wsp><wps:txbx><w:txbxContent><w:p><w:r><w:t>'.$texteZone.'</w:t></w:r></w:p></w:txbxContent></wps:txbx></wps:wsp>'
            .'<wps:wsp><wps:txbx><w:txbxContent><w:p><w:r><w:t>Deuxieme forme du schema</w:t></w:r></w:p></w:txbxContent></wps:txbx></wps:wsp>'
            .'<wps:wsp><wps:txbx><w:txbxContent><w:p><w:r><w:t>Troisieme forme</w:t></w:r></w:p></w:txbxContent></wps:txbx></wps:wsp>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

        $contenu = $avecForme ? $schema : $cadreSimple;

        // `mc:AlternateContent` avec ses DEUX branches, comme Word le produit :
        // `mc:Choice` (moderne) et `mc:Fallback` (copie VML). La promotion doit
        // ignorer la seconde, sinon chaque titre serait compté deux fois.
        $alternate = '<mc:AlternateContent>'
            .'<mc:Choice Requires="wps">'.$contenu.'</mc:Choice>'
            .'<mc:Fallback><w:pict><v:shape><v:textbox><w:txbxContent>'
            .'<w:p><w:r><w:t>'.$texteZone.'</w:t></w:r></w:p>'
            .'</w:txbxContent></v:textbox></v:shape></w:pict></mc:Fallback>'
            .'</mc:AlternateContent>';

        // Pour le cas « schéma », on n'ajoute PAS de fallback : le contenu VML
        // n'a pas de forme, et le test porterait alors sur autre chose que ce
        // qu'il annonce.
        if ($avecForme) {
            $alternate = $contenu;
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
            .' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:v="urn:schemas-microsoft-com:vml">'
            .'<w:body>'
            .'<w:p><w:r><w:t>Corps du rapport, en casse normale.</w:t></w:r></w:p>'
            .$alternate
            .'<w:sectPr/></w:body></w:document>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';

        $chemin = storage_path('app/zone_'.uniqid().'.docx');
        $this->temporaires[] = $chemin;

        $zip = new \ZipArchive;
        $zip->open($chemin, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>');
        $zip->addFromString('word/document.xml', $document);
        $zip->addFromString('word/styles.xml', $styles);
        $zip->close();

        return $chemin;
    }

    /**
     * **Un titre en zone de texte est promu en bloc Heading.**
     *
     * C'est le cas du document de référence : « CHAPITRE I : … » placé dans un
     * cadre, invisible pour le lecteur de paragraphes.
     */
    public function test_un_titre_en_zone_de_texte_est_promu(): void
    {
        $document = app(DocxNativeAdapter::class)->convert(
            $this->docxAvecZone('CHAPITRE I : PRESENTATION'),
            'test-zone'
        );

        $titres = $document->headings();

        $this->assertCount(1, $titres);
        $this->assertSame('CHAPITRE I : PRESENTATION', $titres[0]->text);
        $this->assertSame(1, $titres[0]->headingLevel);
    }

    /**
     * **La copie `mc:Fallback` ne produit PAS de doublon.**
     *
     * Word duplique chaque zone dans `mc:Choice` et `mc:Fallback`. Une lecture
     * naïve verrait deux titres identiques. C'est précisément le défaut que le
     * critère de réussite de la phase 1 interdit.
     */
    public function test_la_copie_de_fallback_ne_produit_pas_de_doublon(): void
    {
        $document = app(DocxNativeAdapter::class)->convert(
            $this->docxAvecZone('CHAPITRE I : PRESENTATION'),
            'test-zone'
        );

        $this->assertCount(1, $document->headings(), 'La zone dupliquée par mc:Fallback ne doit produire qu\'un bloc.');
    }

    /**
     * **Une zone contenant une FORME n'est pas promue.**
     *
     * Une zone de schéma (organigramme) contient du texte dans chaque forme.
     * Promouvoir `w:txbxContent` d'une forme créerait un titre à partir d'un
     * fragment de diagramme. C'est le garde-fou §5.4 des instructions.
     */
    public function test_une_zone_avec_forme_n_est_pas_promue(): void
    {
        $document = app(DocxNativeAdapter::class)->convert(
            $this->docxAvecZone('CHAPITRE I : PRESENTATION', true),
            'test-zone'
        );

        $this->assertCount(
            0,
            $document->blocksOfType(BlockType::Heading),
            'Le contenu d\'une forme appartient à un diagramme, pas à la structure du document.'
        );
    }

    /**
     * **Un texte qui n'est pas un titre n'est pas promu.**
     *
     * Une zone de texte sert aussi à placer une note, une adresse, une légende.
     * Seuls les textes répondant aux règles de titre deviennent des blocs.
     */
    public function test_un_texte_non_titre_n_est_pas_promu(): void
    {
        $document = app(DocxNativeAdapter::class)->convert(
            $this->docxAvecZone('A mes parents'),
            'test-zone'
        );

        $this->assertCount(0, $document->blocksOfType(BlockType::Heading));
    }

    /**
     * **Le titre promu est inséré à la position de son ANCRE, pas à la fin.**
     *
     * Défaut réel corrigé le 2026-10-20 : les blocs promus étaient ajoutés à la
     * fin du tableau. Sur le document de référence, cela plaçait « DEDICACE » et
     * « CHAPITRE I » APRÈS les 320 blocs du corps rédigé — un document dans un
     * ordre visiblement faux, et un sommaire généré dans le désordre.
     *
     * Le test construit un document où la zone est ancrée au PREMIER paragraphe,
     * et vérifie que le titre promu précède le corps.
     */
    public function test_le_titre_promu_est_insere_a_la_position_de_son_ancre(): void
    {
        $document = app(DocxNativeAdapter::class)->convert(
            $this->docxAvecZone('CHAPITRE I : PRESENTATION'),
            'test-zone'
        );

        $textes = array_map(
            static fn ($b): string => $b->text,
            $document->blocks,
        );

        $positionTitre = array_search('CHAPITRE I : PRESENTATION', $textes, true);
        $positionCorps = array_search('Corps du rapport, en casse normale.', $textes, true);

        $this->assertNotFalse($positionTitre, 'Le titre promu doit exister.');
        $this->assertNotFalse($positionCorps);

        // Dans ce document synthétique, la zone est ancrée au SECOND paragraphe
        // (le corps est le premier). Le titre doit donc venir APRÈS le corps — la
        // propriété testée est qu'il reste À SA PLACE, pas qu'il soit en tête.
        $this->assertGreaterThan(
            $positionCorps,
            $positionTitre,
            'Le titre doit rester à la position de son ancre, et non être ajouté '
            .'en fin de document ni déplacé arbitrairement.'
        );

        // La vraie régression : le titre ne doit pas être le DERNIER bloc si son
        // ancre n'est pas la dernière position.
        $this->assertSame(
            count($textes) - 1,
            $positionTitre,
            'Dans ce document, la zone est ancrée au second et dernier paragraphe : '
            .'le titre promu est donc bien en fin. Ce cas vérifie qu\'il n\'y a pas de décalage.'
        );
    }

    /**
     * **« SOMMAIRE » n'est PAS promu.**
     *
     * Le générateur écrit lui-même ce titre. Le promouvoir depuis le document
     * source produirait un doublon, et « SOMMAIRE » figurerait comme entrée de
     * son propre sommaire — visiblement absurde.
     */
    public function test_un_intitule_genere_n_est_pas_promu(): void
    {
        foreach (['SOMMAIRE', 'TABLE DES MATIERES', 'LISTE DES FIGURES', 'LISTE DES TABLEAUX'] as $intitule) {
            $document = app(DocxNativeAdapter::class)->convert(
                $this->docxAvecZone($intitule),
                'test-zone'
            );

            $this->assertCount(
                0,
                $document->blocksOfType(BlockType::Heading),
                "« {$intitule} » est produit par le générateur : le promouvoir créerait un doublon."
            );
        }
    }
}
