<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DocumentGeneration;

use App\Document\Adapters\DocxNativeAdapter;
use App\Services\DocumentGeneration\FormattedDocumentExporter;
use DOMDocument;
use Tests\Support\DocxFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * Échappement XML du texte — défaut de production corrigé (2026-09-17).
 *
 * **Le défaut.** PHPWord écrit le texte via `writeRaw()` quand l'échappement est
 * désactivé, ce qui est son réglage **par défaut**. Un `&` ou un `<` présent dans
 * le document source produisait donc un `word/document.xml` invalide :
 * `xmlParseEntityRef: no name` pour le premier, `StartTag: invalid element name`
 * pour le second.
 *
 * **Pourquoi aucun test ne le voyait.** Les fixtures sont construites à la main et
 * ne contiennent jamais ces caractères. Il a fallu mesurer sur les documents
 * réels pour le découvrir : **19 fichiers sur 51** étaient générés avec un XML
 * invalide, donc **refusé par Word**. Les caractères concernés sont banals dans un
 * mémoire (« Hebergement & nom de Domaine », « Prix < 1 000 »).
 *
 * **Ce que ces tests protègent.** La validation XML du fichier produit, pas la
 * simple présence du texte : un document valide pour un humain (on y lit bien
 * « & ») mais invalide pour un parser est exactement le piège à éviter.
 */
class XmlEscapingTest extends TestCase
{
    /**
     * Source contenant les caractères qui cassaient le XML.
     */
    private function sourceAvecCaracteresSensibles(): string
    {
        $styles = DocxFixture::style('Heading1', 'Heading 1', outlineLevel: 0);

        return DocxFixture::create(
            DocxFixture::paragraph('Introduction', styleId: 'Heading1')
            .DocxFixture::paragraph('Hebergement & nom de Domaine')
            .DocxFixture::paragraph('Un montant < 1 000 FCFA')
            .DocxFixture::table([
                ['Produit', 'Prix'],
                ['Hebergement & repas', '500 < 700'],
            ]),
            $styles
        );
    }

    private function exporter(): FormattedDocumentExporter
    {
        return new FormattedDocumentExporter;
    }

    private function outputPath(): string
    {
        return storage_path('test_scripts/xml_escape_'.uniqid().'.docx');
    }

    /**
     * Le `word/document.xml` produit est-il un XML VALIDE ?
     */
    private function xmlEstValide(string $docxPath): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($docxPath) !== true) {
            return false;
        }

        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === '') {
            return false;
        }

        // Les erreurs libxml sont capturées pour ne pas polluer la sortie du test.
        $precedent = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument;
        $valide = $dom->loadXML($xml);

        libxml_clear_errors();
        libxml_use_internal_errors($precedent);

        return $valide === true;
    }

    // -------------------------------------------------------------------------
    // Le défaut lui-même
    // -------------------------------------------------------------------------

    public function test_le_xml_produit_reste_valide_avec_une_esperluette(): void
    {
        $source = $this->sourceAvecCaracteresSensibles();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-xml');
        $sortie = $this->outputPath();

        $this->exporter()->export($document, $source, $sortie);

        // Sans l'échappement, `&` cru produit `xmlParseEntityRef: no name`,
        // et Word refuse d'ouvrir le fichier.
        $this->assertTrue(
            $this->xmlEstValide($sortie),
            'Le DOCX produit doit avoir un XML valide même si le contenu porte une esperluette.'
        );

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }

    public function test_le_xml_produit_reste_valide_avec_un_chevron(): void
    {
        $source = DocxFixture::create(
            DocxFixture::paragraph('Comparaison < 1000 et > 500')
        );
        $document = (new DocxNativeAdapter)->convert($source, 'doc-chevron');
        $sortie = $this->outputPath();

        $this->exporter()->export($document, $source, $sortie);

        $this->assertTrue(
            $this->xmlEstValide($sortie),
            'Un chevron ouvrant produit « StartTag: invalid element name » sans échappement.'
        );

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }

    public function test_le_contenu_reste_lisible_apres_echappement(): void
    {
        // L'échappement ne doit pas DÉGRADER le texte : l'utilisateur doit lire
        // « & » et non « &amp; ». C'est le piège d'une double échappement.
        $source = $this->sourceAvecCaracteresSensibles();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-lisible');
        $sortie = $this->outputPath();

        $this->exporter()->export($document, $source, $sortie);

        $zip = new ZipArchive;
        $zip->open($sortie);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        // Le texte est échappé dans le XML...
        $this->assertStringContainsString('&amp;', $xml);

        // ... mais une fois décodé, il correspond au contenu d'origine.
        $decode = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $this->assertStringContainsString('Hebergement & nom de Domaine', $decode);
        $this->assertStringContainsString('Un montant < 1 000 FCFA', $decode);

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }

    public function test_le_contenu_des_tableaux_survit_a_l_echappement(): void
    {
        $source = $this->sourceAvecCaracteresSensibles();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-tableau');
        $sortie = $this->outputPath();

        $this->exporter()->export($document, $source, $sortie);

        $zip = new ZipArchive;
        $zip->open($sortie);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $decode = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $this->assertStringContainsString('Hebergement & repas', $decode);
        $this->assertStringContainsString('500 < 700', $decode);

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }

    public function test_le_sommaire_natif_n_est_pas_brise_par_l_echappement(): void
    {
        // Les champs (sommaire, numérotation) sont écrits via des appels XMLWriter
        // directs, sans passer par `writeText()`. Activer l'échappement ne doit
        // donc PAS les casser — c'est la crainte légitime en touchant un réglage
        // global de PHPWord.
        $source = $this->sourceAvecCaracteresSensibles();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-toc');
        $sortie = $this->outputPath();

        $this->exporter()->export($document, $source, $sortie);

        $zip = new ZipArchive;
        $zip->open($sortie);
        $xml = (string) $zip->getFromName('word/document.xml');
        $settings = (string) $zip->getFromName('word/settings.xml');
        $zip->close();

        $this->assertMatchesRegularExpression('/TOC|SOMMAIRE/u', $xml);
        $this->assertStringContainsString('updateFields', $settings);

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }
}
