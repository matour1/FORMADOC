<?php

declare(strict_types=1);

namespace App\Document\Structure;

use InvalidArgumentException;

/**
 * Catégories d'éléments numérotables.
 *
 * Les 4 catégories (Figure, Tableau, Annexe, Planche) utilisent le MÊME style de
 * numérotation (chiffres arabes : 1, 2, 3…) — il n'y a pas de convention
 * différenciée. En revanche, CHACUNE a son propre compteur indépendant qui
 * repart à 1.
 */
enum BlockCategory: string
{
    case Figure = 'figure';
    case Table = 'table';
    case Annexe = 'annexe';
    case Planche = 'planche';

    /**
     * Mot-clé utilisé dans les renvois textuels et les légendes.
     *
     * Sert à la détection des renvois croisés (`voir Figure 3`, `cf. Annexe B`)
     * et à la génération des libellés de légendes.
     */
    public function keyword(): string
    {
        return match ($this) {
            self::Figure => 'Figure',
            self::Table => 'Tableau',
            self::Annexe => 'Annexe',
            self::Planche => 'Planche',
        };
    }

    /**
     * Mot-clé tel qu'écrit par les utilisateurs, en minuscules, sans accent.
     *
     * Utilisé pour construire la regex de détection des renvois.
     */
    public function keywordLowerCase(): string
    {
        return match ($this) {
            self::Figure => 'figure',
            self::Table => 'tableau',
            self::Annexe => 'annexe',
            self::Planche => 'planche',
        };
    }

    /**
     * Libellé de la liste dédiée à cette catégorie (frontispice).
     */
    public function listTitle(): string
    {
        return match ($this) {
            self::Figure => 'LISTE DES FIGURES',
            self::Table => 'LISTE DES TABLEAUX',
            self::Annexe => 'LISTE DES ANNEXES',
            self::Planche => 'LISTE DES PLANCHES',
        };
    }

    /**
     * Le type de bloc correspondant (un bloc numérotable porte lui-même son numéro).
     */
    public function blockType(): BlockType
    {
        return match ($this) {
            self::Figure => BlockType::Figure,
            self::Table => BlockType::Table,
            self::Annexe => BlockType::Annexe,
            self::Planche => BlockType::Planche,
        };
    }

    /**
     * Toutes les catégories, dans l'ordre canonique de traitement.
     *
     * @return array<int, self>
     */
    public static function all(): array
    {
        return [self::Figure, self::Table, self::Annexe, self::Planche];
    }

    /**
     * Recherche une catégorie par son mot-clé (insensible à la casse).
     *
     * Utilisé par la détection des renvois croisés : le texte `Annexe B`
     * produit le mot-clé « annexe ».
     *
     * @return null|self null si le mot-clé ne correspond à aucune catégorie
     */
    public static function fromKeyword(string $keyword): ?self
    {
        $normalized = mb_strtolower(trim($keyword));

        foreach (self::all() as $category) {
            if ($category->keywordLowerCase() === $normalized) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Convertit une chaîne en catégorie, en rejetant les valeurs inconnues.
     *
     * @throws InvalidArgumentException Si la valeur ne correspond à aucune catégorie
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Catégorie de bloc inconnue : « {$value} ».");
    }
}
