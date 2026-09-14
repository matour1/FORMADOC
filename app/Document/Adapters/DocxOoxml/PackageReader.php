<?php

declare(strict_types=1);

namespace App\Document\Adapters\DocxOoxml;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use ZipArchive;

/**
 * Lecteur de bas niveau du conteneur OOXML (le `.docx` est une archive ZIP).
 *
 * Responsabilité unique : ouvrir le paquet, lire une partie par son nom, et
 * RÉSOUDRE LES RELATIONS (`_rels/*.rels`) — c'est indispensable pour retrouver
 * les images, les en-têtes et les pieds de page, dont les cibles sont relatives
 * au dossier de la partie qui les référence.
 *
 * Ce lecteur ne connaît AUCUNE sémantique Word : il ne fait que restituer du XML
 * brut et des chemins. C'est la règle de la refonte : pas de PHPWord en lecture.
 */
final class PackageReader
{
    /** Partie principale du corps du document. */
    public const MAIN_DOCUMENT = 'word/document.xml';

    /** Définitions de styles (polices, tailles, niveaux de plan). */
    public const STYLES = 'word/styles.xml';

    /** Définitions de listes (numérotation, puces). */
    public const NUMBERING = 'word/numbering.xml';

    /** Propriétés du document (sections, code de langue…). */
    public const SETTINGS = 'word/settings.xml';

    private ?ZipArchive $zip = null;

    /**
     * Cache des XML déjà lus (une partie peut être demandée plusieurs fois :
     * styles par les paragraphes ET par les tableaux, par exemple).
     *
     * @var array<string, string>
     */
    private array $cache = [];

    /**
     * Cache des relations par partie.
     *
     * @var array<string, array<string, array{target: string, type: string, mode: string}>>
     */
    private array $relationsCache = [];

    public function __construct(private readonly string $filePath) {}

    /**
     * Ouvre le paquet. Idempotent : un second appel ne rouvre pas l'archive.
     *
     * @throws DocxReadException Si le fichier est absent ou n'est pas une archive lisible
     */
    public function open(): void
    {
        if ($this->zip !== null) {
            return;
        }

        if (! is_file($this->filePath)) {
            throw DocxReadException::fileNotFound($this->filePath);
        }

        $zip = new ZipArchive;
        $result = $zip->open($this->filePath);

        if ($result !== true) {
            throw DocxReadException::notAnArchive($this->filePath, (int) $result);
        }

        // Un .docx valide contient toujours word/document.xml ; sans lui, le
        // fichier est un ZIP quelconque (ou un .doc renommé).
        if ($zip->locateName(self::MAIN_DOCUMENT) === false) {
            $zip->close();

            throw DocxReadException::missingMainDocument($this->filePath);
        }

        $this->zip = $zip;
    }

    /**
     * Le paquet contient-il cette partie ?
     *
     * @throws DocxReadException
     */
    public function has(string $partName): bool
    {
        $this->open();

        return $this->zip->locateName($partName) !== false;
    }

    /**
     * Contenu brut d'une partie, ou null si absente.
     *
     * @throws DocxReadException
     */
    public function read(string $partName): ?string
    {
        $this->open();

        if (isset($this->cache[$partName])) {
            return $this->cache[$partName];
        }

        $content = $this->zip->getFromName($partName);

        if ($content === false) {
            return null;
        }

        return $this->cache[$partName] = $content;
    }

    /**
     * Contenu d'une partie, en levant une exception si elle est absente.
     *
     * @throws DocxReadException
     */
    public function readOrFail(string $partName): string
    {
        return $this->read($partName)
            ?? throw DocxReadException::missingPart($partName);
    }

    /**
     * Contenu binaire d'une partie (images).
     *
     * @throws DocxReadException
     */
    public function readBinary(string $partName): ?string
    {
        $this->open();

        $content = $this->zip->getFromName($partName);

        return $content === false ? null : $content;
    }

    /**
     * Liste les parties du paquet, filtrable par préfixe.
     *
     * @return array<int, string>
     *
     * @throws DocxReadException
     */
    public function listParts(?string $prefix = null): array
    {
        $this->open();
        $names = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);

            if ($prefix === null || str_starts_with($name, $prefix)) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Chemin d'extraction d'une partie d'archive vers un fichier temporaire.
     *
     * Nécessaire pour les images : PHPWord a besoin d'un vrai fichier sur le
     * disque pour les ré-embarquer dans le DOCX de sortie.
     *
     * @return null|string Chemin du fichier extrait, ou null si la partie est absente
     *
     * @throws DocxReadException
     */
    public function extractTo(string $partName, string $targetPath): ?string
    {
        $binary = $this->readBinary($partName);

        if ($binary === null) {
            return null;
        }

        $directory = dirname($targetPath);
        if (! is_dir($directory) && ! mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw DocxReadException::cannotCreateDirectory($directory);
        }

        if (file_put_contents($targetPath, $binary) === false) {
            throw DocxReadException::cannotWrite($targetPath);
        }

        return $targetPath;
    }

    /**
     * Résout une relation d'une partie : identifiant `rId` → chemin absolu
     * dans l'archive.
     *
     * Les cibles des relations sont RELATIVES au dossier de la partie source
     * (`Target="header1.xml"` depuis `word/document.xml` désigne
     * `word/header1.xml`). Cette résolution est la source d'erreur classique
     * des parseurs maison : elle est ici isolée et testée.
     *
     * @return null|array{target: string, type: string, mode: string} null si le rId est inconnu
     *
     * @throws DocxReadException
     */
    public function resolveRelation(string $partName, string $relationId): ?array
    {
        $relations = $this->relationsOf($partName);

        return $relations[$relationId] ?? null;
    }

    /**
     * Toutes les relations d'une partie, indexées par identifiant.
     *
     * @return array<string, array{target: string, type: string, mode: string}>
     *
     * @throws DocxReadException
     */
    public function relationsOf(string $partName): array
    {
        if (isset($this->relationsCache[$partName])) {
            return $this->relationsCache[$partName];
        }

        $relsPath = $this->relationsPathFor($partName);
        $xml = $this->read($relsPath);

        if ($xml === null) {
            return $this->relationsCache[$partName] = [];
        }

        $document = XmlLoader::load($xml, $relsPath);
        $relations = [];

        // Le fichier .rels n'utilise pas de préfixe, mais on passe par XPath
        // avec namespace pour rester robuste aux variantes de générateurs.
        foreach (XmlLoader::query($document, '//*[local-name()="Relationship"]') as $node) {
            $id = $node->getAttribute('Id');
            $target = $node->getAttribute('Target');
            $type = $node->getAttribute('Type');
            $mode = $node->getAttribute('TargetMode');

            if ($id === '' || $target === '') {
                continue;
            }

            // Une relation externe (TargetMode="External") pointe hors du paquet :
            // on la conserve telle quelle, la cible est une URL.
            $relations[$id] = [
                'target' => $mode === 'External'
                    ? $target
                    : $this->normalizeTarget($partName, $target),
                'type' => $this->shortenRelationType($type),
                'mode' => $mode === 'External' ? 'External' : 'Internal',
            ];
        }

        return $this->relationsCache[$partName] = $relations;
    }

    /**
     * Relations d'un type donné (ex. toutes les images, tous les en-têtes).
     *
     * @return array<string, array{target: string, type: string, mode: string}>
     *
     * @throws DocxReadException
     */
    public function relationsOfType(string $partName, string $shortType): array
    {
        return array_filter(
            $this->relationsOf($partName),
            static fn (array $relation): bool => $relation['type'] === $shortType
        );
    }

    /**
     * Chemin du fichier de relations d'une partie.
     *
     * `word/document.xml` → `word/_rels/document.xml.rels`
     * `word/header1.xml`  → `word/_rels/header1.xml.rels`
     */
    public function relationsPathFor(string $partName): string
    {
        $directory = dirname($partName);
        $filename = basename($partName);
        $prefix = $directory === '.' ? '' : $directory.'/';

        return $prefix.'_rels/'.$filename.'.rels';
    }

    /**
     * Ferme l'archive (libère le descripteur de fichier).
     */
    public function close(): void
    {
        $this->zip?->close();
        $this->zip = null;
        $this->cache = [];
        $this->relationsCache = [];
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Normalise une cible de relation en chemin absolu dans l'archive.
     *
     * Gère les cibles absolues (`/word/media/image1.png`) et les remontées
     * (`../media/image1.png`).
     */
    private function normalizeTarget(string $sourcePart, string $target): string
    {
        // Cible absolue dans le paquet : le chemin est déjà complet.
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }

        $base = dirname($sourcePart);

        // Remontée de dossier : on résout segment par segment.
        $segments = explode('/', $base === '.' ? '' : $base);
        foreach (explode('/', $target) as $segment) {
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            if ($segment === '.' || $segment === '') {
                continue;
            }

            $segments[] = $segment;
        }

        return implode('/', array_filter($segments, static fn (string $s): bool => $s !== ''));
    }

    /**
     * Réduit l'URI de type de relation à son dernier segment.
     *
     * `http://…/relationships/header` → `header`
     */
    private function shortenRelationType(string $type): string
    {
        $position = strrpos($type, '/');

        return $position === false ? $type : substr($type, $position + 1);
    }
}
