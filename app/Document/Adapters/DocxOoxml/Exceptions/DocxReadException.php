<?php

declare(strict_types=1);

namespace App\Document\Adapters\DocxOoxml\Exceptions;

use RuntimeException;

/**
 * Erreur de lecture d'un paquet OOXML (.docx).
 *
 * Toutes les erreurs de lecture passent par cette exception : l'appelant
 * (adaptateur) peut ainsi décider de basculer sur un autre format ou de
 * remonter un message compréhensible à l'utilisateur.
 */
final class DocxReadException extends RuntimeException
{
    public static function fileNotFound(string $path): self
    {
        return new self("Fichier introuvable : {$path}");
    }

    public static function notAnArchive(string $path, int $zipCode): self
    {
        return new self(
            "Le fichier n'est pas une archive lisible (code {$zipCode}) : {$path}. "
            .'Un .docx valide est une archive ZIP.'
        );
    }

    public static function missingMainDocument(string $path): self
    {
        return new self(
            "Archive sans document principal (word/document.xml) : {$path}. "
            .'Le fichier a peut-être été produit par un autre logiciel que Word.'
        );
    }

    public static function missingPart(string $partName): self
    {
        return new self("Partie absente du paquet : {$partName}");
    }

    public static function invalidXml(string $partName, string $reason): self
    {
        return new self("XML illisible dans {$partName} : {$reason}");
    }

    public static function cannotCreateDirectory(string $path): self
    {
        return new self("Impossible de créer le répertoire : {$path}");
    }

    public static function cannotWrite(string $path): self
    {
        return new self("Impossible d'écrire le fichier : {$path}");
    }

    public static function unexpectedRootElement(string $partName, string $expected, string $found): self
    {
        return new self(
            "Élément racine inattendu dans {$partName} : attendu « {$expected} », trouvé « {$found} »."
        );
    }

    /**
     * Le paquet dépasse un garde-fou de taille (bombe de décompression).
     *
     * **Pourquoi ce cas existe.** La limite de 50 Mo porte sur le fichier
     * COMPRESSÉ. Une archive ZIP peut contenir des entrées dont le rapport de
     * compression est extrême : quelques mégaoctets deviennent des gigaoctets
     * une fois décompressés. Sans ce refus, un seul fichier peut épuiser la
     * mémoire du serveur — ce n'est pas un document invalide, c'est un déni de
     * service.
     *
     * @param  string  $raison  Ce qui a dépassé (taille totale ou nombre d'entrées)
     */
    public static function bombesDeDecompression(string $raison): self
    {
        return new self(
            "Archive refusée : {$raison}. Le fichier est peut-être corrompu ou "
            .'construit pour épuiser les ressources du serveur.'
        );
    }
}
