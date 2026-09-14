<?php

declare(strict_types=1);

namespace App\Document\Structure;

use App\Document\Exceptions\InvalidStructuralDocument;

/**
 * Validateur du schéma JSON structurel commun.
 *
 * Rôle critique (garde-fou §9.3) : toute sortie de tool d'édition est validée
 * contre ce schéma AVANT d'être acceptée. Une sortie invalide est rejetée et
 * l'action est retentée — jamais appliquée au document.
 *
 * Les erreurs sont cumulées (et non levées une par une) pour donner au modèle
 * un retour exploitable en une seule fois.
 */
final class StructuralSchemaValidator
{
    /**
     * Types de blocs dont la catégorie est obligatoire pour la numérotation.
     *
     * @var array<int, BlockType>
     */
    private const CATEGORY_REQUIRED_TYPES = [
        BlockType::Caption,
        BlockType::CrossRef,
    ];

    /**
     * Valide une structure brute (tableau issu de json_decode).
     *
     * @param  array<string, mixed>  $data  Structure à valider
     *
     * @throws InvalidStructuralDocument Si la structure est invalide
     */
    public function validate(array $data): void
    {
        $errors = $this->errors($data);

        if ($errors !== []) {
            throw InvalidStructuralDocument::invalidSchema($errors);
        }
    }

    /**
     * Collecte toutes les erreurs de schéma, sans lever d'exception.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string> Liste des erreurs (vide si valide)
     */
    public function errors(array $data): array
    {
        $errors = [];

        // --- Niveau document ---
        if (empty($data['document_id']) || ! is_string($data['document_id'])) {
            $errors[] = 'document_id manquant ou invalide.';
        }

        $sourceType = $data['source_type'] ?? null;
        $knownSources = [...StructuralDocument::EXACT_SOURCES, ...StructuralDocument::RECONSTRUCTED_SOURCES];
        if (! is_string($sourceType) || ! in_array($sourceType, $knownSources, true)) {
            $errors[] = 'source_type invalide (attendu : docx, gdocs ou ocr).';
        }

        if (! array_key_exists('blocks', $data) || ! is_array($data['blocks'])) {
            $errors[] = 'blocks manquant ou invalide.';

            return $errors;
        }

        // --- Version de schéma ---
        if (isset($data['schema_version'])) {
            $version = $data['schema_version'];
            if (! is_int($version) || $version > StructuralDocument::SCHEMA_VERSION) {
                $errors[] = sprintf(
                    'schema_version non supportée (reçu %s, maximum %d).',
                    is_scalar($version) ? (string) $version : gettype($version),
                    StructuralDocument::SCHEMA_VERSION
                );
            }
        }

        // --- Blocs ---
        $seenIds = [];
        foreach ($data['blocks'] as $index => $block) {
            $errors = [...$errors, ...$this->blockErrors($block, $index, $seenIds)];
        }

        return $errors;
    }

    /**
     * Valide un bloc unique et enregistre son identifiant (détection de doublon).
     *
     * @param  array<string, bool>  $seenIds  Identifiants déjà rencontrés (par référence)
     * @return array<int, string>
     */
    private function blockErrors(mixed $block, int|string $index, array &$seenIds): array
    {
        if (! is_array($block)) {
            return ["blocks[{$index}] n'est pas un tableau."];
        }

        $errors = [];
        $blockId = $block['block_id'] ?? null;

        if (! is_string($blockId) || $blockId === '') {
            $errors[] = "blocks[{$index}].block_id manquant ou invalide.";
        } elseif (isset($seenIds[$blockId])) {
            $errors[] = "blocks[{$index}].block_id dupliqué : « {$blockId} ».";
        } else {
            $seenIds[$blockId] = true;
        }

        // --- Type ---
        $typeValue = $block['type'] ?? null;
        $type = is_string($typeValue) ? BlockType::tryFrom($typeValue) : null;

        if ($type === null) {
            $errors[] = "blocks[{$index}].type invalide : « "
                .(is_scalar($typeValue) ? (string) $typeValue : gettype($typeValue)).' ».';

            // Sans type valide, les règles conditionnelles ci-dessous n'ont pas de sens.
            return $errors;
        }

        // --- Confiance ---
        if (isset($block['confidence'])) {
            $confidence = $block['confidence'];
            if (! is_numeric($confidence) || $confidence < 0 || $confidence > 1) {
                $errors[] = "blocks[{$index}].confidence doit être un nombre entre 0 et 1.";
            }
        }

        // --- Fidélité ---
        if (isset($block['fidelity'])
            && (! is_string($block['fidelity']) || Fidelity::tryFrom($block['fidelity']) === null)) {
            $errors[] = "blocks[{$index}].fidelity invalide (attendu : exact ou reconstructed).";
        }

        // --- Règles par type ---
        if ($type === BlockType::Table && ! is_array($block['table_data'] ?? null)) {
            $errors[] = "blocks[{$index}] de type « table » doit porter table_data.";
        }

        if ($type === BlockType::Heading) {
            $level = $block['heading_level'] ?? null;
            if (! is_int($level) || $level < 1) {
                $errors[] = "blocks[{$index}] de type « heading » doit porter heading_level (≥ 1).";
            }
        }

        if (in_array($type, self::CATEGORY_REQUIRED_TYPES, true)) {
            $category = $block['category'] ?? null;
            if (! is_string($category) || BlockCategory::tryFrom($category) === null) {
                $errors[] = "blocks[{$index}] de type « {$type->value} » doit porter une category valide.";
            }
        }

        if ($type === BlockType::CrossRef && ! is_array($block['cross_ref'] ?? null)) {
            $errors[] = "blocks[{$index}] de type « cross_ref » doit porter cross_ref.";
        }

        if ($type === BlockType::Figure && empty($block['image_ref'])) {
            $errors[] = "blocks[{$index}] de type « figure » doit porter image_ref.";
        }

        return $errors;
    }

    /**
     * La structure est-elle valide ? (version booléenne, sans exception)
     *
     * @param  array<string, mixed>  $data
     */
    public function isValid(array $data): bool
    {
        return $this->errors($data) === [];
    }

    /**
     * Valide directement un objet document.
     *
     * @throws InvalidStructuralDocument Si le document est invalide
     */
    public function validateDocument(StructuralDocument $document): void
    {
        $this->validate($document->toArray());
    }
}
