<?php

declare(strict_types=1);

namespace App\Document\Structure;

use InvalidArgumentException;

/**
 * Types de blocs du schéma JSON structurel commun.
 *
 * C'est LE format pivot du pipeline de refonte : aucun module en aval ne doit
 * connaître le format d'origine (.docx, Google Docs, PDF scanné). Tous les
 * adaptateurs d'entrée produisent des blocs de ces types.
 *
 * Distinctions importantes (voir REFONTE_ARCHITECTURE.md §6) :
 *  - `Figure`  = image NUMÉROTÉE avec légende (compteur propre)
 *  - `Image`   = image DÉCORATIVE, non numérotée
 *  - `Annexe` et `Planche` sont des catégories à part entière, au même titre
 *    que figure/tableau (chacune a son propre compteur repartant à 1).
 */
enum BlockType: string
{
    /** Titre (niveau porté par Block::$headingLevel). */
    case Heading = 'heading';

    /** Paragraphe de texte courant. */
    case Paragraph = 'paragraph';

    /** Tableau (données brutes conservées dans Block::$tableData). */
    case Table = 'table';

    /** Figure : image numérotée + légende associée. */
    case Figure = 'figure';

    /** Image décorative, non numérotée. */
    case Image = 'image';

    /** Légende rattachée à une figure/tableau/annexe/planche via linkedBlockId. */
    case Caption = 'caption';

    /** Annexe (catégorie numérotée à part entière). */
    case Annexe = 'annexe';

    /** Planche (catégorie numérotée à part entière). */
    case Planche = 'planche';

    /** En-tête de page. */
    case Header = 'header';

    /** Pied de page. */
    case Footer = 'footer';

    /** Renvoi textuel détecté ailleurs dans le document (« voir Figure 3 »). */
    case CrossRef = 'cross_ref';

    /**
     * Entrée d'un sommaire ou d'une table des matières DÉJÀ présents.
     *
     * **Pourquoi un type à part, et non un paragraphe.** Une entrée de sommaire
     * ressemble à un titre — elle porte souvent le style `toc N`, donc un niveau
     * de plan — mais ce n'en est pas un : c'est la COPIE d'un titre, avec un
     * numéro de page en fin de ligne. La classer comme titre produit deux
     * conséquences mesurables :
     *
     *   - le sommaire entre dans le sommaire qu'on génère, ce qui est
     *     manifestement faux ;
     *   - la numérotation voit des titres en double et renumérote sur une base
     *     erronée.
     *
     * Mesuré sur le document de référence du projet : les entrées du sommaire
     * représentaient **60 % des titres détectés**. Les classer à part renverse
     * la lecture du document.
     */
    case TocEntry = 'toc_entry';

    /**
     * Diagramme en FORMES vectorielles (`wps:wsp`, `wpg:wgp`).
     *
     * **Pourquoi un type à part, et non un `Figure`.** Un `Figure` porte une
     * image liée par une relation (`r:embed`), dont le binaire est lu puis
     * ré-embarqué. Une forme vectorielle n'a **aucune** relation d'image : elle
     * est un arbre XML (rectangles, flèches, zones de texte) que PhpWord ne sait
     * ni lire ni écrire. Les confondre ferait chercher un binaire inexistant.
     *
     * Mesure du 2026-09-30 sur 1 722 contenus distincts : 4 documents portent des
     * formes, pour 285 formes et 250 zones de texte. Avant ce type, le paragraphe
     * qui les portait n'ayant ni texte ni image, il était ignoré à la lecture et
     * le diagramme **disparaissait entièrement**, sans erreur ni signalement.
     *
     * Le bloc ne porte pas le contenu de la forme (trop volumineux pour le schéma
     * structurel) : il marque l'EMPLACEMENT. `ShapePreserver` réinjecte le XML
     * d'origine depuis le fichier source à cet emplacement.
     */
    case Shape = 'shape';

    /**
     * Le type est-il un titre (participe à la table des matières) ?
     */
    public function isHeading(): bool
    {
        return $this === self::Heading;
    }

    /**
     * Le type porte-t-il un compteur de numérotation indépendant ?
     *
     * Les 4 catégories numérotables partagent le même style (chiffres arabes)
     * mais chacune a son propre compteur repartant à 1.
     */
    public function isNumberable(): bool
    {
        return $this->category() !== null;
    }

    /**
     * Catégorie de numérotation associée, ou null si le type n'est pas numéroté.
     */
    public function category(): ?BlockCategory
    {
        return match ($this) {
            self::Figure => BlockCategory::Figure,
            self::Table => BlockCategory::Table,
            self::Annexe => BlockCategory::Annexe,
            self::Planche => BlockCategory::Planche,
            default => null,
        };
    }

    /**
     * Catégorie de numérotation implicite : un bloc dont le type EST une
     * catégorie (Figure, Table, Annexe, Planche) porte lui-même son numéro.
     *
     * Utilisé pour distinguer les légendes (type `caption`, catégorie
     * explicite via `Block::$category`) des blocs numérotables.
     */
    public function isSelfNumbered(): bool
    {
        return $this->category() !== null;
    }

    /**
     * Le type participe-t-il aux éléments de frontispice (listes dédiées) ?
     */
    public function hasDedicatedList(): bool
    {
        return match ($this) {
            self::Heading, self::Figure, self::Table, self::Annexe, self::Planche => true,
            default => false,
        };
    }

    /**
     * Types acceptés par le tool d'édition `insert_block` (liste blanche §9).
     *
     * @return array<int, self>
     */
    public static function insertable(): array
    {
        return [
            self::Heading,
            self::Paragraph,
            self::Table,
            self::Figure,
            self::Image,
            self::Caption,
            self::Annexe,
            self::Planche,
        ];
    }

    /**
     * Convertit une chaîne en type de bloc, en rejetant les valeurs inconnues.
     *
     * @throws InvalidArgumentException Si la valeur ne correspond à aucun type
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Type de bloc inconnu : « {$value} ».");
    }
}
