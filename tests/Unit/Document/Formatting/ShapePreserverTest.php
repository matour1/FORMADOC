<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Formatting;

use App\Document\Formatting\ShapePreserver;
use Tests\TestCase;

/**
 * Préservation des diagrammes en FORMES vectorielles.
 *
 * **Le défaut, mesuré.** Le lecteur OOXML traite `w:drawing` comme une IMAGE : il
 * cherche une relation `r:embed`. Une forme vectorielle (`wps:wsp` — rectangle,
 * flèche, zone de texte d'un organigramme) n'en a aucune. Le paragraphe qui la
 * porte n'avait donc ni texte ni image : `read()` retournait `null`, et le
 * diagramme **disparaissait entièrement** du document généré.
 *
 * Mesure du 2026-09-30 sur les 1 722 contenus distincts du corpus :
 * 4 documents portent des formes, pour 285 formes et 250 zones de texte. Sur l'un
 * d'eux, 21 paragraphes de diagramme étaient ignorés.
 *
 * **Ce que ces tests protègent en priorité, dans l'ordre d'importance :**
 *
 *  1. **Aucun marqueur technique dans le document livré.** Le marqueur est du
 *     TEXTE : s'il n'est pas remplacé, il s'affiche chez l'utilisateur. Un texte
 *     technique visible est un défaut PIRE que l'absence du diagramme, parce
 *     qu'il donne l'impression d'un document cassé. Le nettoyage doit donc avoir
 *     lieu même quand il n'y a rien à replacer.
 *  2. **Les formes du source sont effectivement présentes** dans la sortie.
 *  3. **Les images ne sont pas touchées** : `a:blip` suit son propre chemin, une
 *     détection concurrente créerait deux traitements du même contenu.
 */
class ShapePreserverTest extends TestCase
{
    /** @var array<int, string> */
    private array $fichiers = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiers as $fichier) {
            @unlink($fichier);
        }

        $this->fichiers = [];

        parent::tearDown();
    }

    /**
     * Fabrique un DOCX minimal contenant le corps XML fourni.
     */
    private function docx(string $corps): string
    {
        $chemin = tempnam(sys_get_temp_dir(), 'shape_').'.docx';
        $this->fichiers[] = $chemin;

        $zip = new \ZipArchive;
        $zip->open($chemin, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $this->documentXml($corps));
        $zip->close();

        return $chemin;
    }

    /**
     * Enveloppe WordprocessingML complète, avec les espaces de noms des formes.
     *
     * Les déclarations `wps`, `wpg` et `a` sont INDISPENSABLES : sans elles, un
     * fragment contenant `wps:wsp` serait rejeté comme XML invalide, et le test
     * échouerait pour une raison sans rapport avec le comportement vérifié.
     */
    private function documentXml(string $corps): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document'
            .' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape"'
            .' xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup">'
            .'<w:body>'.$corps.'</w:body></w:document>';
    }

    /**
     * Un diagramme en formes : un `w:drawing` contenant un `wps:wsp`.
     */
    private function formeXml(string $texte = 'Rectangle'): string
    {
        return '<w:p><w:r><w:drawing><wp:inline>'
            .'<a:graphic><a:graphicData>'
            .'<wps:wsp><wps:txbx><w:txbxContent>'
            .'<w:p><w:r><w:t>'.$texte.'</w:t></w:r></w:p>'
            .'</w:txbxContent></wps:txbx></wps:wsp>'
            .'</a:graphicData></a:graphic>'
            .'</wp:inline></w:drawing></w:r></w:p>';
    }

    /**
     * Un paragraphe de corps ordinaire.
     */
    private function paragrapheXml(string $texte): string
    {
        return '<w:p><w:r><w:t>'.$texte.'</w:t></w:r></w:p>';
    }

    // -------------------------------------------------------------------------
    // Extraction
    // -------------------------------------------------------------------------

    /**
     * **Les formes du source sont extraites.**
     *
     * Contrôle de base : sans extraction, il n'y a rien à réinjecter et le
     * diagramme reste perdu.
     */
    public function test_les_formes_du_source_sont_extraites(): void
    {
        $source = $this->docx($this->paragrapheXml('Texte').$this->formeXml('Organigramme'));

        $formes = (new ShapePreserver)->extraire($source);

        $this->assertCount(1, $formes, 'Le diagramme en forme doit être extrait.');
        $this->assertStringContainsString('wps:wsp', $formes[0]);
        $this->assertStringContainsString('Organigramme', $formes[0],
            'Le contenu de la zone de texte doit être conservé : c\'est précisément '
            .'lui que le pipeline perdait.');
    }

    /**
     * **Une IMAGE n'est PAS extraite comme forme.**
     *
     * C'est le contrôle négatif le plus important de cette classe. Une image
     * (`a:blip`) suit son propre chemin de lecture et de ré-embarquement. La
     * traiter aussi comme une forme créerait DEUX traitements du même contenu :
     * l'image apparaîtrait deux fois.
     */
    public function test_une_image_n_est_pas_extraite_comme_forme(): void
    {
        $image = '<w:p><w:r><w:drawing><wp:inline>'
            .'<a:graphic><a:graphicData>'
            .'<a:blip r:embed="rId5"/>'
            .'</a:graphicData></a:graphic>'
            .'</wp:inline></w:drawing></w:r></w:p>';

        $source = $this->docx($image);

        $this->assertSame([], (new ShapePreserver)->extraire($source),
            'Une image a une relation `r:embed` : elle suit son propre chemin. '
            .'L\'extraire aussi comme forme la ferait apparaître deux fois.');
    }

    /**
     * Un document sans forme rend une liste vide, sans erreur.
     */
    public function test_un_document_sans_forme_ne_leve_pas_d_erreur(): void
    {
        $source = $this->docx($this->paragrapheXml('Texte ordinaire'));

        $this->assertSame([], (new ShapePreserver)->extraire($source));
    }

    /**
     * Une source illisible ne fait pas échouer l'extraction.
     *
     * La génération d'un document ne doit jamais échouer à cause d'un
     * post-traitement de confort : on retourne une liste vide, le document se
     * génère sans ses diagrammes, et le défaut reste visible à la relecture.
     */
    public function test_une_source_illisible_rend_une_liste_vide(): void
    {
        $this->assertSame([], (new ShapePreserver)->extraire('/chemin/inexistant.docx'));
    }

    // -------------------------------------------------------------------------
    // Réinjection
    // -------------------------------------------------------------------------

    /**
     * **Le marqueur est remplacé par le diagramme du source.**
     *
     * Contrôle positif : c'est le comportement que tout le composant existe pour
     * produire.
     */
    public function test_le_marqueur_est_remplace_par_le_diagramme(): void
    {
        $source = $this->docx($this->formeXml('Organigramme du projet'));
        $genere = $this->docx(
            $this->paragrapheXml('Avant')
            .'<w:p><w:r><w:t>'.ShapePreserver::MARQUEUR.'</w:t></w:r></w:p>'
            .$this->paragrapheXml('Apres')
        );

        $remplaces = (new ShapePreserver)->reinjecter($genere, $source);

        $this->assertSame(1, $remplaces);

        $xml = $this->lireDocument($genere);

        $this->assertStringContainsString('wps:wsp', $xml, 'Le diagramme doit être présent.');
        $this->assertStringContainsString('Organigramme du projet', $xml,
            'Le contenu de la zone de texte doit être réinjecté.');
        $this->assertStringNotContainsString(ShapePreserver::MARQUEUR, $xml,
            'Le marqueur technique ne doit JAMAIS subsister.');

        // L'ordre du document est préservé : les paragraphes voisins ne bougent pas.
        $this->assertStringContainsString('Avant', $xml);
        $this->assertStringContainsString('Apres', $xml);
    }

    /**
     * **Un marqueur sans forme correspondante est SUPPRIMÉ, pas laissé.**
     *
     * C'est le point critique du composant. Le marqueur est du TEXTE : s'il
     * survit, l'utilisateur lit « FORMADOC_SHAPES_HERE » dans son rapport. Ce
     * défaut serait pire que l'absence du diagramme — il donne l'impression d'un
     * document cassé.
     *
     * Le cas se produit réellement : une source sans forme, ou une source
     * devenue illisible, alors que le corps porte le marqueur.
     */
    public function test_un_marqueur_sans_forme_est_supprime(): void
    {
        $source = $this->docx($this->paragrapheXml('Aucune forme ici'));
        $genere = $this->docx(
            $this->paragrapheXml('Avant')
            .'<w:p><w:r><w:t>'.ShapePreserver::MARQUEUR.'</w:t></w:r></w:p>'
            .$this->paragrapheXml('Apres')
        );

        (new ShapePreserver)->reinjecter($genere, $source);

        $xml = $this->lireDocument($genere);

        $this->assertStringNotContainsString(ShapePreserver::MARQUEUR, $xml,
            'Un marqueur non remplacé doit être SUPPRIMÉ : un texte technique '
            .'visible chez l\'utilisateur est pire que l\'absence du diagramme.');
        $this->assertStringContainsString('Avant', $xml, 'Le contenu voisin est intact.');
        $this->assertStringContainsString('Apres', $xml);
    }

    /**
     * **Une source ILLISIBLE entraîne aussi le nettoyage du marqueur.**
     *
     * Le cas le plus vicieux : le marqueur est là, la source a disparu du disque.
     * Sans ce test, on pourrait croire que le nettoyage ne s'applique qu'au cas
     * « source lisible mais sans forme ».
     */
    public function test_une_source_illisible_entraine_le_nettoyage_du_marqueur(): void
    {
        $genere = $this->docx('<w:p><w:r><w:t>'.ShapePreserver::MARQUEUR.'</w:t></w:r></w:p>');

        (new ShapePreserver)->reinjecter($genere, '/chemin/inexistant.docx');

        $this->assertStringNotContainsString(
            ShapePreserver::MARQUEUR,
            $this->lireDocument($genere),
            'Une source illisible ne doit pas laisser le marqueur dans le document.',
        );
    }

    /**
     * Plusieurs diagrammes sont réinjectés DANS L'ORDRE.
     *
     * L'ordre est ce qui donne un document lisible : des diagrammes intervertis
     * rendraient le rapport incompréhensible, et le défaut serait difficile à
     * attribuer au bon composant.
     */
    public function test_plusieurs_diagrammes_sont_reinjectes_dans_l_ordre(): void
    {
        $source = $this->docx($this->formeXml('Premier').$this->formeXml('Second'));
        $genere = $this->docx(
            '<w:p><w:r><w:t>'.ShapePreserver::MARQUEUR.'</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>'.ShapePreserver::MARQUEUR.'</w:t></w:r></w:p>'
        );

        $remplaces = (new ShapePreserver)->reinjecter($genere, $source);

        $this->assertSame(2, $remplaces);

        $xml = $this->lireDocument($genere);

        $this->assertStringContainsString('Premier', $xml);
        $this->assertStringContainsString('Second', $xml);

        $this->assertLessThan(
            mb_strpos($xml, 'Second'),
            mb_strpos($xml, 'Premier'),
            'Le premier diagramme du document doit rester le premier : un ordre '
            .'inversé rendrait le rapport incompréhensible.',
        );
    }

    /**
     * Un document sans marqueur n'est pas réécrit.
     *
     * On évite une réécriture inutile de l'archive : réécrire pour rien est un
     * risque de corruption sans aucun bénéfice.
     */
    public function test_un_document_sans_marqueur_n_est_pas_modifie(): void
    {
        $source = $this->docx($this->formeXml('Organigramme'));
        $genere = $this->docx($this->paragrapheXml('Aucun marqueur'));

        $avant = $this->lireDocument($genere);

        $this->assertSame(0, (new ShapePreserver)->reinjecter($genere, $source));
        $this->assertSame($avant, $this->lireDocument($genere),
            'Un document sans marqueur doit rester identique.');
    }

    // -------------------------------------------------------------------------
    // Aide
    // -------------------------------------------------------------------------

    private function lireDocument(string $chemin): string
    {
        $zip = new \ZipArchive;
        $zip->open($chemin);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }
}
