<?php

declare(strict_types=1);

namespace App\Document\Structure;

/**
 * Fidélité de la structure extraite par rapport au document d'origine.
 *
 * Règle produit (REFONTE_ARCHITECTURE.md §10) : un document dont la source est
 * un PDF scanné ne doit JAMAIS être présenté comme « identique à l'original ».
 * L'interface doit afficher « reconstruction fidèle, validée par vous ».
 */
enum Fidelity: string
{
    /**
     * Structure native préservée (.docx, Google Docs) : la fidélité est garantie
     * car le document source porte lui-même sa sémantique.
     */
    case Exact = 'exact';

    /**
     * Structure reconstruite (PDF scanné → OCR) : la fidélité est visée au
     * maximum mais jamais garantie. Exige un aperçu avant/après systématique
     * avec validation utilisateur, même si la confiance est haute.
     */
    case Reconstructed = 'reconstructed';

    /**
     * Cette fidélité garantit-elle une identité avec l'original ?
     *
     * Utilisé pour interdire toute mention « identique à 100 % » dans l'UI.
     */
    public function isExact(): bool
    {
        return $this === self::Exact;
    }

    /**
     * Cette fidélité exige-t-elle une validation utilisateur systématique ?
     */
    public function requiresUserValidation(): bool
    {
        return $this === self::Reconstructed;
    }

    /**
     * Libellé affichable à l'utilisateur (jamais de promesse d'identité).
     */
    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Structure d\'origine préservée',
            self::Reconstructed => 'Reconstruction fidèle, validée par vous',
        };
    }
}
