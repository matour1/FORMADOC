<?php

declare(strict_types=1);

namespace App\Services\DocumentGeneration;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use App\Document\Adapters\DocxOoxml\ImageReader;
use App\Document\Adapters\DocxOoxml\PackageReader;
use App\Document\Structure\StructuralDocument;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fournit le binaire des images référencées par un document structurel.
 *
 * **Le problème que ce service résout.** Le modèle structurel ne conserve qu'une
 * **référence** d'image (`Block::$imageRef` = `rId5.img`), pas son contenu : une
 * image pèse quelques centaines de kilo-octets, la dupliquer dans le JSON de
 * chaque document le rendrait inutilisable. Or l'écriture d'un DOCX a besoin du
 * binaire. Il faut donc le relire depuis la source, à chaque export.
 *
 * **Pourquoi ne pas passer par `ParagraphReader::imageName()`.** Ce dernier
 * encapsule la référence dans une marque `[image:rId5.img]` insérée dans le
 * texte, ce qui **corrompt le contenu** d'un paragraphe — inacceptable pour un
 * export. Ici on parcourt les blocs directement, sans jamais toucher au texte.
 *
 * **Ce qui se passe quand une image manque.** Aucun échec : la référence absente
 * est simplement omise de la correspondance. En aval, le générateur écrit
 * « [Image: nom] » à la place du visuel. C'est un choix délibéré — une image
 * manquante doit être **visible**, jamais remplacée par une image inventée ni
 * faire échouer l'export complet du document.
 */
final class SourceImageProvider
{
    /**
     * Suffixe ajouté par le lecteur de paragraphes à l'identifiant de relation.
     *
     * `ParagraphReader::imageName()` produit `rId5.img` : le `.img` distingue un
     * identifiant de relation d'un nom de fichier, sans quoi un `rId` ressemblant
     * à un nom serait résolu à tort comme un chemin.
     */
    private const REFERENCE_SUFFIX = '.img';

    /**
     * Correspondance `imageRef → {data, extension}` pour un document.
     *
     * @param  string  $sourcePath  Chemin du `.docx` d'origine
     * @param  StructuralDocument  $document  Document dont on extrait les images
     * @return array<string, array{data: string, extension: string}> Indexé par la valeur de `Block::$imageRef`
     */
    public function provide(string $sourcePath, StructuralDocument $document): array
    {
        $references = $this->referencesOf($document);

        if ($references === []) {
            return [];
        }

        if (! is_file($sourcePath)) {
            // La source a disparu (purge, déplacement) : l'export reste possible,
            // les images seront signalées par leur nom. On le trace parce que
            // c'est anormal — un document conservé sans sa source l'est rarement
            // de façon voulue.
            Log::warning('Export : fichier source absent, images non résolues', [
                'document_id' => $document->documentId,
                'references' => count($references),
            ]);

            return [];
        }

        try {
            $package = new PackageReader($sourcePath);
            $package->open();

            try {
                return $this->resolve($package, $references);
            } finally {
                $package->close();
            }
        } catch (Throwable $e) {
            // Une archive illisible ne doit pas empêcher l'export : le document
            // est écrit sans ses images, et le défaut est visible à l'écran.
            Log::warning('Export : lecture des images impossible', [
                'document_id' => $document->documentId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Références d'image distinctes portées par les blocs.
     *
     * @return array<int, string> Identifiants de relation, dans l'ordre d'apparition
     */
    private function referencesOf(StructuralDocument $document): array
    {
        $references = [];

        foreach ($document->blocks as $block) {
            $reference = $block->imageRef;

            if ($reference === null || $reference === '') {
                continue;
            }

            $relationId = $this->relationIdOf($reference);

            if ($relationId !== null) {
                $references[$relationId] = true;
            }
        }

        return array_keys($references);
    }

    /**
     * Résout chaque relation d'image en binaire base64.
     *
     * @param  array<int, string>  $relationIds
     * @return array<string, array{data: string, extension: string}>
     *
     * @throws DocxReadException
     */
    private function resolve(PackageReader $package, array $relationIds): array
    {
        $reader = new ImageReader($package);
        $images = [];

        foreach ($relationIds as $relationId) {
            $description = $reader->describe($relationId);

            // La relation ne pointe pas une image, ou la partie est absente :
            // on l'ignore plutôt que d'interrompre la collecte des autres.
            if ($description === null) {
                continue;
            }

            $binary = $package->readBinary($description['part_name']);

            if ($binary === null || $binary === '') {
                continue;
            }

            // Base64 : le format attendu par le générateur DOCX, qui décode au
            // moment de l'écriture. On ne réencode pas le binaire lui-même.
            $images[$relationId.self::REFERENCE_SUFFIX] = [
                'data' => base64_encode($binary),
                'extension' => $description['extension'],
            ];
        }

        return $images;
    }

    /**
     * Identifiant de relation contenu dans une référence de bloc.
     *
     * Une référence sans suffixe est acceptée telle quelle : les documents
     * enregistrés avant l'introduction du suffixe portent l'identifiant nu.
     */
    private function relationIdOf(string $reference): ?string
    {
        $relationId = str_ends_with($reference, self::REFERENCE_SUFFIX)
            ? substr($reference, 0, -strlen(self::REFERENCE_SUFFIX))
            : $reference;

        return $relationId === '' ? null : $relationId;
    }
}
