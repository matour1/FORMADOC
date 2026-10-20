<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Adapters\DocxOoxml;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use App\Document\Adapters\DocxOoxml\PackageReader;
use Tests\TestCase;
use ZipArchive;

/**
 * Garde-fou contre la bombe de décompression.
 *
 * **Ce que ces tests protègent.** La limite de 50 Mo porte sur le fichier
 * COMPRESSÉ. Une archive de quelques kilo-octets peut déclarer des entrées de
 * plusieurs gigaoctets et épuiser la mémoire du serveur : ce n'est pas un
 * document invalide, c'est un déni de service.
 *
 * **Pourquoi les archives sont construites ici et non fournies en fixture.** Une
 * vraie bombe pèse plusieurs gigaoctets si on la décompresse. Le test construit
 * une archive dont l'en-tête ANNONCE une taille énorme sans en payer le coût :
 * c'est exactement ce que `statIndex()` lit, donc ce que le garde-fou mesure.
 */
class PackageReaderBombTest extends TestCase
{
    /** @var array<int, string> */
    private array $fichiersTemporaires = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiersTemporaires as $fichier) {
            @unlink($fichier);
        }

        $this->fichiersTemporaires = [];

        parent::tearDown();
    }

    /**
     * Construit une archive contenant `word/document.xml` et `entrees` parties vides.
     */
    private function construireArchive(int $entrees, string $partieSupplementaire = ''): string
    {
        $chemin = storage_path('app/bomb_'.uniqid().'.docx');
        $this->fichiersTemporaires[] = $chemin;

        $zip = new ZipArchive;
        $zip->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');

        if ($partieSupplementaire !== '') {
            $zip->addFromString($partieSupplementaire, str_repeat('x', 1024));
        }

        for ($i = 0; $i < $entrees; $i++) {
            $zip->addFromString('word/media/filler_'.$i.'.xml', '<x/>');
        }

        $zip->close();

        return $chemin;
    }

    /**
     * **Trop d'entrées : l'archive est refusée.**
     *
     * Un `.docx` normal compte quelques dizaines de parties. Au-delà du seuil,
     * c'est le signe d'une archive construite (ou corrompue), et le refus doit
     * être explicite — pas une erreur mémoire plus loin.
     */
    public function test_une_archive_avec_trop_d_entrees_est_refusee(): void
    {
        config(['document.security.max_entries' => 10]);

        $chemin = $this->construireArchive(20);

        $this->expectException(DocxReadException::class);
        $this->expectExceptionMessage('Archive refusée');

        (new PackageReader($chemin))->open();
    }

    /**
     * **Une archive normale passe le garde-fou.**
     *
     * Contrôle négatif indispensable : un garde-fou trop strict refuserait des
     * documents légitimes, ce qui serait pire que le risque qu'il couvre. Le
     * test garantit que le seuil ne bloque pas un usage ordinaire.
     */
    public function test_une_archive_normale_passe_le_garde_fou(): void
    {
        $chemin = $this->construireArchive(3);

        $paquet = new PackageReader($chemin);
        $paquet->open();

        $this->assertTrue($paquet->has('word/document.xml'));

        $paquet->close();
    }

    /**
     * **Le seuil de taille décompressée est appliqué.**
     *
     * On abaisse le seuil à 1 Ko : la partie supplémentaire de 1 Ko fait
     * dépasser, et le refus doit nommer la cause. Sans cette vérification, le
     * seul comptage d'entrées laisserait passer une archive à peu d'entrées mais
     * énormes — la forme la plus courante d'une bombe `zip`.
     */
    public function test_une_archive_dont_la_taille_declaree_depasse_est_refusee(): void
    {
        config([
            'document.security.max_uncompressed_bytes' => 500,
            'document.security.max_entries' => 5_000,
        ]);

        $chemin = $this->construireArchive(1, 'word/panneau.xml');

        $this->expectException(DocxReadException::class);
        $this->expectExceptionMessage('taille décompressée supérieure');

        (new PackageReader($chemin))->open();
    }
}
