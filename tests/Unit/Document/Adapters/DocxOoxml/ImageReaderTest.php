<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Adapters\DocxOoxml;

use App\Document\Adapters\DocxOoxml\ImageReader;
use App\Document\Adapters\DocxOoxml\PackageReader;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Tests du lecteur d'images.
 *
 * Enjeu : extraire le binaire EXACT (jamais re-encodé) et détecter le format
 * réel du contenu plutôt que de se fier à l'extension déclarée — Word produit
 * des images `.tmp` et des extensions incohérentes.
 */
class ImageReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            DocxFixture::cleanup($path);
        }
        $this->fixtures = [];

        parent::tearDown();
    }

    /** Signature PNG minimale, suivie de données factices. */
    private const PNG_BINARY = "\x89PNG\r\n\x1a\n".'donnees-image-factices';

    /** Signature JPEG minimale. */
    private const JPG_BINARY = "\xFF\xD8\xFF\xE0".'donnees-jpeg';

    /** Signature GIF minimale. */
    private const GIF_BINARY = 'GIF89a'.'donnees-gif';

    /**
     * Crée une fixture contenant une image.
     *
     * @param  string  $mediaPart  Nom de la partie média dans l'archive
     * @param  string  $binary  Contenu binaire de l'image
     * @param  string  $relationTarget  Cible déclarée dans les relations
     */
    private function fixtureWithImage(
        string $mediaPart = 'word/media/image1.png',
        string $binary = self::PNG_BINARY,
        string $relationTarget = 'media/image1.png',
    ): string {
        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="'.$relationTarget.'"/>'
            .'</Relationships>';

        $path = DocxFixture::create(
            DocxFixture::paragraph('Texte avec image'),
            extraParts: [
                'word/_rels/document.xml.rels' => $rels,
                $mediaPart => $binary,
            ]
        );
        $this->fixtures[] = $path;

        return $path;
    }

    private function readerFor(string $path): array
    {
        $package = new PackageReader($path);
        $package->open();

        return [new ImageReader($package), $package];
    }

    // -------------------------------------------------------------------------
    // Détection du format réel
    // -------------------------------------------------------------------------

    public function test_le_format_png_est_detecte(): void
    {
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $this->assertSame('png', $reader->detectFormat(self::PNG_BINARY));

        $package->close();
    }

    public function test_le_format_jpeg_est_detecte(): void
    {
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $this->assertSame('jpg', $reader->detectFormat(self::JPG_BINARY));

        $package->close();
    }

    public function test_le_format_gif_est_detecte(): void
    {
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $this->assertSame('gif', $reader->detectFormat(self::GIF_BINARY));

        $package->close();
    }

    public function test_un_format_inconnu_retourne_bin(): void
    {
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $this->assertSame('bin', $reader->detectFormat('donnees-non-reconnues'));

        $package->close();
    }

    // -------------------------------------------------------------------------
    // Résolution des relations
    // -------------------------------------------------------------------------

    public function test_une_relation_d_image_est_resolue(): void
    {
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $description = $reader->describe('rId5');

        $this->assertNotNull($description);
        $this->assertSame('rId5', $description['relation_id']);
        $this->assertSame('word/media/image1.png', $description['part_name']);
        $this->assertSame('png', $description['format']);
        $this->assertSame(strlen(self::PNG_BINARY), $description['size']);

        $package->close();
    }

    public function test_une_relation_inconnue_retourne_null(): void
    {
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $this->assertNull($reader->describe('rId999'));

        $package->close();
    }

    public function test_une_relation_non_image_est_ignoree(): void
    {
        // Un document référence aussi des styles, des en-têtes : seule une
        // relation de type « image » doit être traitée comme une image.
        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';

        $path = DocxFixture::create(
            DocxFixture::paragraph('Texte'),
            extraParts: ['word/_rels/document.xml.rels' => $rels]
        );
        $this->fixtures[] = $path;

        [$reader, $package] = $this->readerFor($path);

        $this->assertNull($reader->describe('rId1'));

        $package->close();
    }

    public function test_une_image_absente_de_l_archive_retourne_null(): void
    {
        // Relation déclarée mais partie manquante : le document est incohérent,
        // on ne doit pas planter.
        [$reader, $package] = $this->readerFor($this->fixtureWithImage(
            mediaPart: 'word/media/autre.png',
            relationTarget: 'media/image1.png'
        ));

        $this->assertNull($reader->describe('rId5'));

        $package->close();
    }

    // -------------------------------------------------------------------------
    // Détection du format réel plutôt que de l'extension
    // -------------------------------------------------------------------------

    public function test_l_extension_declaree_ne_prime_pas_sur_le_format_reel(): void
    {
        // Cas réel : Word a produit des images nommées `.tmp` dans les
        // documents du projet. Se fier à l'extension donnerait un fichier
        // inutilisable.
        [$reader, $package] = $this->readerFor($this->fixtureWithImage(
            mediaPart: 'word/media/image1.tmp',
            binary: self::PNG_BINARY,
            relationTarget: 'media/image1.tmp'
        ));

        $description = $reader->describe('rId5');

        $this->assertSame('png', $description['format']);
        $this->assertSame('png', $description['extension'], 'L\'extension doit suivre le format réel');

        $package->close();
    }

    public function test_une_extension_incoherente_est_corrigee(): void
    {
        // Fichier déclaré `.png` mais contenant du JPEG : le format détecté
        // doit primer, sinon le fichier extrait est corrompu.
        [$reader, $package] = $this->readerFor($this->fixtureWithImage(
            mediaPart: 'word/media/image1.png',
            binary: self::JPG_BINARY,
            relationTarget: 'media/image1.png'
        ));

        $this->assertSame('jpg', $reader->describe('rId5')['extension']);

        $package->close();
    }

    // -------------------------------------------------------------------------
    // Extraction vers un fichier
    // -------------------------------------------------------------------------

    public function test_une_image_est_extraite_sans_modification_du_binaire(): void
    {
        // Règle de la refonte : « le fichier image reste inchangé ». Le binaire
        // extrait doit être octet pour octet identique à celui du paquet.
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'formadoc_img_'.uniqid();
        $path = $reader->extract('rId5', $directory);

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertSame(self::PNG_BINARY, file_get_contents($path), 'Le binaire doit être intact');

        // Nettoyage
        unlink($path);
        @rmdir($directory);

        $package->close();
    }

    public function test_extract_retourne_null_pour_une_relation_invalide(): void
    {
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $this->assertNull($reader->extract('rId999', sys_get_temp_dir()));

        $package->close();
    }

    public function test_extract_cree_le_repertoire_si_necessaire(): void
    {
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'formadoc_nouveau_'.uniqid().DIRECTORY_SEPARATOR.'sous-dossier';
        $path = $reader->extract('rId5', $directory);

        $this->assertFileExists($path);

        unlink($path);
        @rmdir(dirname($path));
        @rmdir(dirname($directory));

        $package->close();
    }

    // -------------------------------------------------------------------------
    // Inventaire des images
    // -------------------------------------------------------------------------

    public function test_toutes_les_images_referencees_sont_decrites(): void
    {
        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
            .'<Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image2.jpg"/>'
            .'</Relationships>';

        $path = DocxFixture::create(
            DocxFixture::paragraph('Texte'),
            extraParts: [
                'word/_rels/document.xml.rels' => $rels,
                'word/media/image1.png' => self::PNG_BINARY,
                'word/media/image2.jpg' => self::JPG_BINARY,
            ]
        );
        $this->fixtures[] = $path;

        [$reader, $package] = $this->readerFor($path);
        $images = $reader->describeAll();

        $this->assertCount(2, $images);
        $this->assertSame('png', $images['rId5']['format']);
        $this->assertSame('jpg', $images['rId6']['format']);

        $package->close();
    }

    public function test_les_parties_media_sont_listees(): void
    {
        [$reader, $package] = $this->readerFor($this->fixtureWithImage());

        $this->assertContains('word/media/image1.png', $reader->mediaParts());

        $package->close();
    }

    public function test_une_image_orpheline_est_detectee(): void
    {
        // Un document contient souvent des médias téléversés puis supprimés :
        // les re-embarquer gonflerait le fichier de sortie inutilement.
        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
            .'</Relationships>';

        $path = DocxFixture::create(
            DocxFixture::paragraph('Texte'),
            extraParts: [
                'word/_rels/document.xml.rels' => $rels,
                'word/media/image1.png' => self::PNG_BINARY,
                'word/media/orpheline.png' => self::GIF_BINARY,
            ]
        );
        $this->fixtures[] = $path;

        [$reader, $package] = $this->readerFor($path);
        $orphans = $reader->orphanMediaParts();

        $this->assertContains('word/media/orpheline.png', $orphans);
        $this->assertNotContains('word/media/image1.png', $orphans);

        $package->close();
    }

    public function test_un_document_sans_image_ne_retourne_aucune_image(): void
    {
        $path = DocxFixture::create(DocxFixture::paragraph('Texte sans image'));
        $this->fixtures[] = $path;

        [$reader, $package] = $this->readerFor($path);

        $this->assertSame([], $reader->describeAll());
        $this->assertSame([], $reader->mediaParts());

        $package->close();
    }
}
