<?php

declare(strict_types=1);

namespace App\Document\Adapters;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use App\Document\Structure\Fidelity;
use App\Document\Structure\StructuralDocument;

/**
 * Contrat d'un adaptateur d'entrée.
 *
 * Chaque format d'origine (`.docx` natif, Google Docs, PDF scanné via OCR)
 * implémente ce contrat et produit le MÊME format pivot : le JSON structurel
 * commun. C'est la règle fondatrice de la refonte — aucun module en aval ne
 * doit connaître le format d'origine.
 */
interface InputAdapter
{
    /** Type de source produit (`docx`, `gdocs`, `ocr`). */
    public function sourceType(): string;

    /**
     * Fidélité attendue pour ce type de source.
     *
     * Un `.docx` porte sa sémantique → fidélité exacte.
     * Un PDF scanné passe par l'OCR → structure reconstruite.
     */
    public function fidelity(): Fidelity;

    /**
     * Cet adaptateur sait-il traiter ce fichier ?
     *
     * La détection se fait sur le CONTENU (signature binaire), jamais sur la
     * seule extension : un `.doc` renommé en `.docx` doit être rejeté.
     */
    public function supports(string $filePath): bool;

    /**
     * Convertit le fichier source en document structurel.
     *
     * @param  string  $filePath  Chemin absolu du fichier d'origine
     * @param  string  $documentId  Identifiant du document (pour la traçabilité)
     *
     * @throws DocxReadException
     */
    public function convert(string $filePath, string $documentId): StructuralDocument;
}
