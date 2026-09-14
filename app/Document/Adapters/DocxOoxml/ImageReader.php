<?php

declare(strict_types=1);

namespace App\Document\Adapters\DocxOoxml;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;

/**
 * Lecture des images d'un document Word.
 *
 * Une image est référencée par un identifiant de RELATION (`r:embed`) qu'il faut
 * résoudre via `word/_rels/document.xml.rels` pour obtenir le chemin réel dans
 * l'archive (`word/media/image1.png`). Deux formes coexistent :
 *
 * ```
 * 1. Moderne (DrawingML)        2. Ancienne (VML)
 *    <w:drawing>                   <w:pict>
 *      <a:blip r:embed="rId5"/>      <v:imagedata r:id="rId4"/>
 *    </w:drawing>                  </w:pict>
 * ```
 *
 * **Le binaire n'est jamais re-encodé** : l'image est extraite telle quelle pour
 * être ré-embarquée à l'identique dans le DOCX de sortie. Une recompression
 * dégraderait la qualité visuelle sans aucun bénéfice — et la refonte impose que
 * « le fichier image reste inchangé » (§9).
 *
 * Note : lors de la phase 3 de l'ancien pipeline, un bug avait fait perdre
 * silencieusement toutes les images, car PHPWord référence les images d'un DOCX
 * lu sous la forme `zip:///chemin/doc.docx#word/media/image1.png` et non par un
 * chemin de fichier. La résolution par relation employée ici évite ce piège.
 */
final class ImageReader
{
    /**
     * Signatures binaires des formats d'image acceptés.
     *
     * Sert à DÉTECTER le format réel plutôt que de faire confiance à
     * l'extension déclarée — un fichier nommé `.png` peut contenir du JPEG, et
     * Word insère parfois des images avec l'extension `.tmp`.
     *
     * @var array<string, string>
     */
    private const SIGNATURES = [
        "\x89PNG" => 'png',
        "\xFF\xD8\xFF" => 'jpg',
        'GIF8' => 'gif',
        'BM' => 'bmp',
        'II*' => 'tif',
        'MM' => 'tif',
        'RIFF' => 'webp',
    ];

    public function __construct(private readonly PackageReader $package) {}

    /**
     * Résout la référence d'une image et retourne ses informations.
     *
     * @param  string  $relationId  Identifiant de relation (`r:embed` / `r:id`)
     * @param  string  $referencingPart  Partie qui référence l'image
     *                                   (`word/document.xml` ou un en-tête)
     * @return null|array{
     *     relation_id: string,
     *     part_name: string,
     *     format: string,
     *     size: int,
     *     extension: string
     * } null si la relation est introuvable ou ne pointe pas une image
     *
     * @throws DocxReadException
     */
    public function describe(string $relationId, string $referencingPart = PackageReader::MAIN_DOCUMENT): ?array
    {
        $relation = $this->package->resolveRelation($referencingPart, $relationId);

        if ($relation === null || $relation['type'] !== 'image') {
            return null;
        }

        $partName = $relation['target'];
        $binary = $this->package->readBinary($partName);

        if ($binary === null) {
            return null;
        }

        $format = $this->detectFormat($binary);

        return [
            'relation_id' => $relationId,
            'part_name' => $partName,
            'format' => $format,
            'size' => strlen($binary),
            'extension' => $this->extensionFor($partName, $format),
        ];
    }

    /**
     * Extrait une image vers un fichier temporaire.
     *
     * Indispensable pour la reconstruction : le générateur a besoin d'un vrai
     * fichier sur le disque pour ré-embarquer le binaire dans le DOCX de sortie.
     *
     * @param  string  $targetDirectory  Répertoire de destination
     * @return null|string Chemin du fichier créé, ou null si la relation est invalide
     *
     * @throws DocxReadException
     */
    public function extract(
        string $relationId,
        string $targetDirectory,
        string $referencingPart = PackageReader::MAIN_DOCUMENT,
    ): ?string {
        $description = $this->describe($relationId, $referencingPart);

        if ($description === null) {
            return null;
        }

        // Le nom de fichier combine l'identifiant de bloc et l'extension réelle :
        // deux images différentes peuvent porter la même référence dans des
        // parties distinctes (corps et en-tête).
        $filename = sprintf(
            '%s_%s.%s',
            preg_replace('/[^a-z0-9]/i', '', $relationId) ?: 'image',
            substr(md5($description['part_name']), 0, 8),
            $description['extension']
        );

        $target = rtrim($targetDirectory, '/\\').DIRECTORY_SEPARATOR.$filename;

        return $this->package->extractTo($description['part_name'], $target);
    }

    /**
     * Extrait toutes les images référencées par une partie.
     *
     * @return array<string, array{relation_id: string, part_name: string, format: string, size: int, extension: string}>
     *                                                                                                                    Indexé par identifiant de relation
     *
     * @throws DocxReadException
     */
    public function describeAll(string $referencingPart = PackageReader::MAIN_DOCUMENT): array
    {
        $images = [];

        foreach ($this->package->relationsOfType($referencingPart, 'image') as $relationId => $relation) {
            $description = $this->describe($relationId, $referencingPart);

            if ($description !== null) {
                $images[$relationId] = $description;
            }
        }

        return $images;
    }

    /**
     * Liste les parties image présentes dans l'archive.
     *
     * Utile pour repérer les images ORPHELINES : un document contient souvent
     * des médias téléversés puis supprimés, dont plus aucune relation ne
     * dépend. Les re-embarquer gonflerait le fichier de sortie inutilement.
     *
     * @return array<int, string>
     *
     * @throws DocxReadException
     */
    public function mediaParts(): array
    {
        return $this->package->listParts('word/media/');
    }

    /**
     * Images de l'archive auxquelles AUCUNE relation ne renvoie.
     *
     * @return array<int, string> Chemins des parties orphelines
     *
     * @throws DocxReadException
     */
    public function orphanMediaParts(): array
    {
        $referenced = [];

        // Les images peuvent être référencées depuis le corps ET les en-têtes.
        foreach ($this->package->listParts('word/') as $part) {
            if (! str_ends_with($part, '.xml') || str_contains($part, '_rels/')) {
                continue;
            }

            foreach ($this->package->relationsOfType($part, 'image') as $relation) {
                $referenced[$relation['target']] = true;
            }
        }

        return array_values(array_filter(
            $this->mediaParts(),
            static fn (string $part): bool => ! isset($referenced[$part])
        ));
    }

    /**
     * Détecte le format réel d'une image depuis sa signature binaire.
     *
     * @return string Extension normalisée (`png`, `jpg`, `gif`, `bmp`, `tif`,
     *                `webp`), ou `bin` si le format n'est pas reconnu
     */
    public function detectFormat(string $binary): string
    {
        foreach (self::SIGNATURES as $signature => $format) {
            if (str_starts_with($binary, $signature)) {
                return $format;
            }
        }

        return 'bin';
    }

    /**
     * Extension à utiliser, en préférant le format RÉEL à l'extension déclarée.
     *
     * Word écrit parfois `.tmp` (observé dans les documents du projet) ou une
     * extension incohérente avec le contenu. Le format détecté prime.
     */
    private function extensionFor(string $partName, string $detectedFormat): string
    {
        if ($detectedFormat !== 'bin') {
            return $detectedFormat;
        }

        $extension = mb_strtolower(pathinfo($partName, PATHINFO_EXTENSION));

        // Les extensions `.tmp`/`.bin` ne disent rien : on retombe sur `png`,
        // format le plus courant, plutôt que de produire un fichier sans type.
        if ($extension === '' || in_array($extension, ['tmp', 'bin', 'dat'], true)) {
            return 'png';
        }

        return $extension;
    }
}
