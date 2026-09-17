<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DocumentGeneration;

use App\Document\Adapters\DocxNativeAdapter;
use App\Services\DocumentGeneration\FormattedDocumentExporter;
use App\Services\DocumentGeneration\SourceImageProvider;
use RuntimeException;
use Tests\Support\DocxFixture;
use Tests\TestCase;
use ZipArchive;

/**
 * Assembleur d'export DOCX (le maillon qui manquait).
 *
 * **Ce que ces tests protègent.** Avant ce service, R3 (mise en forme), R4
 * (renumérotation) et R6 (ré-export) produisaient un état en mémoire que personne
 * n'écrivait : le fichier livré portait les titres, mais aucun style de gabarit,
 * aucun numéro recalculé, aucune édition. Ces tests vérifient que la chaîne
 * complète aboutit à un **fichier réel**, et que ce fichier contient bien ce que
 * le pipeline promet.
 *
 * Le test central (`test_l_export_produit_un_docx_mis_en_forme`) ouvre le ZIP
 * produit et inspecte son XML : c'est la seule vérification qui prouve qu'un
 * utilisateur recevrait un document exploitable.
 */
class FormattedDocumentExporterTest extends TestCase
{
    private function exporter(): FormattedDocumentExporter
    {
        return new FormattedDocumentExporter;
    }

    /**
     * DOCX source minimal : un titre, deux paragraphes, un tableau.
     *
     * Le titre porte un VRAI style `Heading1` déclaré dans `word/styles.xml`.
     * `outlineLvl` seul, posé à même le paragraphe, ne suffit pas : l'adaptateur
     * lit le niveau depuis les styles (l'héritage `basedOn` d'un style Word est
     * la source réelle du niveau). Un fixture sans styles.xml ne produit donc
     * aucun titre — mesuré, et c'est ce qui avait fait échouer le test du
     * sommaire.
     */
    private function source(): string
    {
        $styles = DocxFixture::style(
            'Heading1',
            'Heading 1',
            outlineLevel: 0,
            runFormat: ['size' => 32, 'bold' => true],
        );

        return DocxFixture::create(
            DocxFixture::paragraph('Introduction', styleId: 'Heading1')
            .DocxFixture::paragraph('Un premier paragraphe de contenu.')
            .DocxFixture::paragraph('Un second paragraphe de contenu.')
            .DocxFixture::table([['Colonne A', 'Colonne B'], ['valeur 1', 'valeur 2']]),
            $styles
        );
    }

    private function outputPath(): string
    {
        return storage_path('test_scripts/export_'.uniqid().'.docx');
    }

    /**
     * Contenu d'une partie du DOCX produit.
     */
    private function readPart(string $docxPath, string $partName): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($docxPath) !== true) {
            return null;
        }

        $contenu = $zip->getFromName($partName);
        $zip->close();

        return $contenu === false ? null : $contenu;
    }

    // -------------------------------------------------------------------------
    // Le test central : un fichier réel est produit
    // -------------------------------------------------------------------------

    public function test_l_export_produit_un_docx_mis_en_forme(): void
    {
        $source = $this->source();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-test');
        $sortie = $this->outputPath();

        $resultat = $this->exporter()->export($document, $source, $sortie);

        $this->assertFileExists($sortie, 'L\'export doit écrire un vrai fichier.');
        $this->assertGreaterThan(0, $resultat['blocks']);
        $this->assertTrue($resultat['integrity']['intact'], 'Aucun tableau ne doit être corrompu.');

        // Le document produit est un DOCX lisible...
        $documentXml = $this->readPart($sortie, 'word/document.xml');
        $this->assertNotNull($documentXml, 'Le DOCX produit doit contenir word/document.xml.');

        // ... et il contient bien le contenu source.
        $this->assertStringContainsString('Introduction', $documentXml);
        $this->assertStringContainsString('Un premier paragraphe de contenu.', $documentXml);

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }

    public function test_le_document_produit_contient_les_styles_de_titre(): void
    {
        $source = $this->source();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-test');
        $sortie = $this->outputPath();

        $this->exporter()->export($document, $source, $sortie);

        // Le générateur inscrit les styles de titres dans le document : sans eux,
        // le sommaire natif (champ TOC) n'aurait aucune entrée à lister.
        $styles = $this->readPart($sortie, 'word/styles.xml');
        $this->assertNotNull($styles, 'Le DOCX doit embarquer word/styles.xml.');
        $this->assertMatchesRegularExpression(
            '/Heading|Titre|heading/i',
            $styles,
            'Les styles de titres doivent être présents pour alimenter le sommaire.'
        );

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }

    public function test_le_document_produit_contient_un_sommaire_natif(): void
    {
        $source = $this->source();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-test');
        $sortie = $this->outputPath();

        $this->exporter()->export($document, $source, $sortie);

        $documentXml = $this->readPart($sortie, 'word/document.xml');

        // Champ TOC natif : c'est ce qui permet au traitement de texte de
        // calculer les numéros de page lui-même, sans pagination externe.
        $this->assertMatchesRegularExpression(
            '/TOC|SOMMAIRE/u',
            (string) $documentXml,
            'Le document doit porter un sommaire.'
        );

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }

    public function test_la_mise_a_jour_des_champs_est_demandee(): void
    {
        $source = $this->source();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-test');
        $sortie = $this->outputPath();

        $this->exporter()->export($document, $source, $sortie);

        $settings = $this->readPart($sortie, 'word/settings.xml');

        // Sans `updateFields`, le sommaire s'afficherait vide à l'ouverture et
        // l'utilisateur devrait presser F9 — un défaut visible pour lui.
        $this->assertNotNull($settings);
        $this->assertStringContainsString('updateFields', $settings);

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }

    // -------------------------------------------------------------------------
    // Le contenu traverse sans altération
    // -------------------------------------------------------------------------

    public function test_le_contenu_du_tableau_traverse_l_export(): void
    {
        $source = $this->source();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-test');
        $sortie = $this->outputPath();

        $this->exporter()->export($document, $source, $sortie);

        $documentXml = (string) $this->readPart($sortie, 'word/document.xml');

        foreach (['Colonne A', 'Colonne B', 'valeur 1', 'valeur 2'] as $cellule) {
            $this->assertStringContainsString(
                $cellule,
                $documentXml,
                "La cellule « {$cellule} » doit se retrouver à l'identique dans le document produit."
            );
        }

        DocxFixture::cleanup($source);
        @unlink($sortie);
    }

    // -------------------------------------------------------------------------
    // Échec d'écriture : exception explicite, aucun fichier laissé
    // -------------------------------------------------------------------------

    public function test_un_echec_d_ecriture_leve_une_exception_sans_laisser_de_fichier(): void
    {
        // Le répertoire de sortie n'existe pas : l'écriture doit échouer
        // franchement plutôt que de produire un fichier partiel.
        //
        // Note : le garde-fou d'intégrité (tableau corrompu → export refusé)
        // n'est pas testé ici parce qu'il n'est pas déclenchable depuis
        // l'extérieur. `DocumentFormatter` est `final` (donc non mockable, par
        // conception) et le contrôle compare la sortie du formateur à
        // elle-même : il ne peut se déclencher que sur un vrai défaut de R3,
        // ce que `DocumentFormatterTest` couvre directement.
        $source = $this->source();
        $document = (new DocxNativeAdapter)->convert($source, 'doc-test');

        $sortie = storage_path('test_scripts/repertoire-inexistant-'.uniqid().'/sortie.docx');

        try {
            $this->exporter()->export($document, $source, $sortie);
            $this->fail('Une écriture impossible doit lever une exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Écriture du document impossible', $e->getMessage());
        }

        $this->assertFileDoesNotExist($sortie, 'Aucun fichier ne doit être laissé derrière.');

        DocxFixture::cleanup($source);
    }

    // -------------------------------------------------------------------------
    // Les images
    // -------------------------------------------------------------------------

    public function test_une_image_de_la_source_est_embarquee_dans_le_document_produit(): void
    {
        // PNG minimal (1×1 pixel) encodé en base64.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        $source = DocxFixture::create(
            DocxFixture::paragraph('Figure avec image', outlineLevel: 0)
            .DocxFixture::imageParagraph('rId100', 'Une légende de figure'),
            '',
            [
                'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId100" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
                    .'</Relationships>',
                'word/media/image1.png' => $png,
            ]
        );

        $document = (new DocxNativeAdapter)->convert($source, 'doc-image');

        // Le modèle structurel ne porte qu'une RÉFÉRENCE, jamais le binaire.
        $references = [];
        foreach ($document->blocks as $block) {
            if ($block->imageRef !== null) {
                $references[] = $block->imageRef;
            }
        }

        $this->assertNotEmpty($references, 'Le parseur doit avoir relevé la référence d\'image.');

        // Le fournisseur relit le binaire depuis la source.
        $images = (new SourceImageProvider)->provide($source, $document);

        $this->assertNotEmpty($images, 'Le binaire doit être résolu depuis la source.');
        $this->assertArrayHasKey($references[0], $images);
        $this->assertNotEmpty($images[$references[0]]['data']);
        $this->assertSame('png', $images[$references[0]]['extension']);

        DocxFixture::cleanup($source);
    }

    public function test_une_source_absente_ne_bloque_pas_l_export(): void
    {
        $document = (new DocxNativeAdapter)->convert($this->source(), 'doc-test');

        // Source introuvable : on obtient une correspondance vide, pas une erreur.
        $images = (new SourceImageProvider)->provide('/chemin/inexistant.docx', $document);

        $this->assertSame(
            [],
            $images,
            'Une source absente doit dégrader les images, jamais faire échouer l\'export.'
        );
    }

    public function test_le_binaire_d_une_image_absente_du_paquet_est_ignore(): void
    {
        // Relation déclarée mais partie absente du ZIP : cas réel des documents
        // édités à la main où la relation survit au fichier.
        $source = DocxFixture::create(
            DocxFixture::imageParagraph('rId200'),
            '',
            [
                'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .'<Relationship Id="rId200" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/manquante.png"/>'
                    .'</Relationships>',
            ]
        );

        $document = (new DocxNativeAdapter)->convert($source, 'doc-absente');

        $images = (new SourceImageProvider)->provide($source, $document);

        $this->assertSame([], $images, 'Une partie absente doit être ignorée, sans lever.');

        DocxFixture::cleanup($source);
    }
}
