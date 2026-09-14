<?php

declare(strict_types=1);

namespace App\Document\Exceptions;

use RuntimeException;

/**
 * Structure de document invalide ou illisible.
 *
 * Levée à la désérialisation du schéma JSON commun, à la validation de
 * schéma, et lors des opérations de plage invalides.
 */
final class InvalidStructuralDocument extends RuntimeException
{
    /**
     * Une plage de blocs est invalide (début après fin, ou bloc introuvable).
     */
    public static function invalidRange(string $startBlockId, string $endBlockId): self
    {
        return new self(
            "Plage de blocs invalide : de « {$startBlockId} » à « {$endBlockId} ». "
            .'Les deux blocs doivent exister et le début doit précéder la fin.'
        );
    }

    /**
     * Le tableau fourni ne respecte pas le schéma JSON commun.
     *
     * @param  array<int, string>  $errors
     */
    public static function invalidSchema(array $errors): self
    {
        return new self(
            'Structure de document invalide : '.implode(' ', $errors)
        );
    }

    /**
     * Le JSON est illisible (syntaxe invalide).
     */
    public static function unreadable(string $reason): self
    {
        return new self("JSON structurel illisible : {$reason}");
    }

    /**
     * La structure ne peut pas être sérialisée en JSON.
     */
    public static function unserializable(string $reason): self
    {
        return new self("Structure non sérialisable en JSON : {$reason}");
    }

    /**
     * La version de schéma est plus récente que celle supportée par le code.
     */
    public static function unsupportedVersion(int $found, int $supported): self
    {
        return new self(
            "Version de schéma non supportée : {$found} (le code supporte jusqu'à {$supported}). "
            .'Mettre à jour l\'application.'
        );
    }

    /**
     * Un identifiant de bloc est introuvable dans le document.
     */
    public static function blockNotFound(string $blockId): self
    {
        return new self("Bloc introuvable : « {$blockId} ».");
    }

    /**
     * Une sortie de tool ne respecte pas le schéma (garde-fou §9.3).
     */
    public static function invalidToolOutput(string $tool, string $reason): self
    {
        return new self(
            "Sortie invalide du tool « {$tool} » : {$reason}. "
            .'L\'action est rejetée et doit être retentée.'
        );
    }
}
