<?php

declare(strict_types=1);

namespace App\Document\Adapters\DocxOoxml;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Chargement et navigation dans un XML WordprocessingML.
 *
 * Deux pièges que ce chargeur concentre :
 *
 * 1. **XXE** : un `.docx` peut contenir des entités externes malveillantes.
 *    On charge donc sans accès réseau — un document utilisateur n'est jamais
 *    une source de confiance.
 *
 * 2. **`getElementsByTagName('w:p')` NE FONCTIONNE PAS.** C'est le piège
 *    principal de l'OOXML en PHP : contrairement à ce que le nom suggère,
 *    cette méthode compare au **nom local** de l'élément, jamais au préfixe
 *    qualifié. Sur un document Word, `getElementsByTagName('w:p')` retourne
 *    donc **zéro résultat** — un parseur qui l'utilise ne lit rien du tout.
 *    Toutes les recherches passent ici par un parcours comparant `localName`,
 *    avec XPath + namespaces enregistrés pour les requêtes.
 */
final class XmlLoader
{
    /** Espace de noms principal de WordprocessingML. */
    public const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Espace de noms des relations (attribut `r:id`, `r:embed`). */
    public const RELS_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Espace de noms de DrawingML (images). */
    public const DRAWING_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    /** Espace de noms du dessin dans Word (`wp:inline`, `wp:anchor`). */
    public const WORDPROCESSING_DRAWING_NS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    /** Espace de noms VML (anciens dessins, `v:imagedata`). */
    public const VML_NS = 'urn:schemas-microsoft-com:vml';

    /** Espace de noms du balisage étendu Word 2010 (sauts de page). */
    public const W14_NS = 'http://schemas.microsoft.com/office/word/2010/wordml';

    /**
     * Charge un XML en désactivant toute résolution d'entité externe.
     *
     * @throws DocxReadException Si le XML est illisible
     */
    public static function load(string $xml, string $partName = 'xml'): DOMDocument
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $reason = $errors === []
                ? 'erreur inconnue'
                : trim($errors[0]->message).' (ligne '.$errors[0]->line.')';

            throw DocxReadException::invalidXml($partName, $reason);
        }

        return $document;
    }

    /**
     * Crée un XPath avec les namespaces OOXML enregistrés.
     *
     * À utiliser pour toute requête complexe (`//w:tbl`, `ancestor::w:sectPr`).
     */
    public static function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);

        $xpath->registerNamespace('w', self::WORD_NS);
        $xpath->registerNamespace('r', self::RELS_NS);
        $xpath->registerNamespace('a', self::DRAWING_NS);
        $xpath->registerNamespace('wp', self::WORDPROCESSING_DRAWING_NS);
        $xpath->registerNamespace('v', self::VML_NS);
        $xpath->registerNamespace('w14', self::W14_NS);

        return $xpath;
    }

    /**
     * Évalue une requête XPath, en ne retournant que les éléments.
     *
     * @return array<int, DOMElement>
     */
    public static function query(DOMDocument $document, string $expression, ?DOMElement $context = null): array
    {
        $nodes = self::xpath($document)->query($expression, $context);

        if ($nodes === false) {
            return [];
        }

        $elements = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    // -------------------------------------------------------------------------
    // Navigation (par nom local — seule méthode fiable)
    // -------------------------------------------------------------------------

    /**
     * Enfants directs d'un élément portant le nom qualifié donné.
     *
     * @param  null|string  $qualifiedName  Nom qualifié (`w:p`) ou null pour tous
     * @return array<int, DOMElement>
     */
    public static function childElements(DOMElement $parent, ?string $qualifiedName = null): array
    {
        $local = $qualifiedName === null ? null : self::localName($qualifiedName);
        $children = [];

        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($local === null || $node->localName === $local) {
                $children[] = $node;
            }
        }

        return $children;
    }

    /**
     * Premier enfant direct portant le nom qualifié donné.
     */
    public static function firstChild(DOMElement $parent, string $qualifiedName): ?DOMElement
    {
        $local = self::localName($qualifiedName);

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $local) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Premier descendant (toutes profondeurs) portant le nom qualifié donné.
     */
    public static function firstDescendant(DOMElement $parent, string $qualifiedName): ?DOMElement
    {
        $found = self::descendants($parent, $qualifiedName);

        return $found[0] ?? null;
    }

    /**
     * Tous les descendants portant le nom qualifié donné, en profondeur d'abord.
     *
     * @return array<int, DOMElement>
     */
    public static function descendants(DOMElement $parent, string $qualifiedName): array
    {
        $found = [];
        self::collect($parent, [self::localName($qualifiedName)], $found);

        return $found;
    }

    /**
     * Descendants dont le nom local appartient à un ensemble donné.
     *
     * Permet de chercher « un paragraphe OU un tableau » en un seul parcours,
     * ce qui préserve l'ordre d'apparition des éléments.
     *
     * @param  array<int, string>  $localNames
     * @return array<int, DOMElement>
     */
    public static function descendantsOfAny(DOMElement $parent, array $localNames): array
    {
        $found = [];
        self::collect($parent, $localNames, $found);

        return $found;
    }

    // -------------------------------------------------------------------------
    // Attributs
    // -------------------------------------------------------------------------

    /**
     * Valeur d'un attribut WordprocessingML (`w:val` par défaut).
     *
     * Les attributs OOXML sont préfixés eux aussi (`w:val`). On teste donc le
     * nom qualifié, puis le nom local en repli.
     */
    public static function attributeValue(?DOMElement $element, string $attribute = 'w:val'): ?string
    {
        if ($element === null) {
            return null;
        }

        if ($element->hasAttribute($attribute)) {
            return $element->getAttribute($attribute);
        }

        $local = self::localName($attribute);

        foreach ($element->attributes ?? [] as $node) {
            if ($node->localName === $local) {
                return $node->nodeValue;
            }
        }

        return null;
    }

    /**
     * Lecture d'un indicateur booléen OOXML.
     *
     * Trois formes coexistent selon les générateurs :
     *  - `<w:b/>`            → présent sans attribut = vrai
     *  - `<w:b w:val="1"/>`  → vrai
     *  - `<w:b w:val="0"/>`  → faux
     *
     * Distinguer « absent » (null) de « explicitement faux » (false) est
     * indispensable : c'est ce qui fait fonctionner l'héritage des styles, où
     * un style enfant annule un attribut hérité de son parent.
     *
     * @return null|bool null si l'élément est absent (aucune information)
     */
    public static function flag(?DOMElement $element): ?bool
    {
        if ($element === null) {
            return null;
        }

        $value = self::attributeValue($element);

        if ($value === null || $value === '') {
            return true;
        }

        return ! in_array(mb_strtolower($value), ['0', 'false', 'off'], true);
    }

    // -------------------------------------------------------------------------
    // Conversions d'unités OOXML
    // -------------------------------------------------------------------------

    /**
     * Demi-points → points (tailles de police : `w:sz` 32 = 16 pt).
     */
    public static function halfPointsToPoints(string|int|null $halfPoints): ?float
    {
        if ($halfPoints === null || $halfPoints === '') {
            return null;
        }

        return ((float) $halfPoints) / 2;
    }

    /**
     * Twips → points (retraits, espacements : 1 pt = 20 twips).
     */
    public static function twipsToPoints(string|int|null $twips): ?float
    {
        if ($twips === null || $twips === '') {
            return null;
        }

        return ((float) $twips) / 20;
    }

    /**
     * Huitièmes de point → points (épaisseur des bordures de tableau).
     */
    public static function eighthsToPoints(string|int|null $eighths): ?float
    {
        if ($eighths === null || $eighths === '') {
            return null;
        }

        return ((float) $eighths) / 8;
    }

    /**
     * Extrait le nom local d'un nom qualifié (`w:p` → `p`).
     */
    public static function localName(string $qualifiedName): string
    {
        $position = strrpos($qualifiedName, ':');

        return $position === false ? $qualifiedName : substr($qualifiedName, $position + 1);
    }

    // -------------------------------------------------------------------------
    // Parcours interne
    // -------------------------------------------------------------------------

    /**
     * Parcours récursif en profondeur d'abord.
     *
     * Point critique : `mc:AlternateContent` (utilisé par Word pour les zones de
     * texte, les images ancrées et les graphiques) contient le MÊME contenu DEUX
     * FOIS — une fois dans `mc:Choice` (forme moderne) et une fois dans
     * `mc:Fallback` (compatibilité avec les anciennes versions). Parcourir les
     * deux dupliquerait chaque titre placé dans une zone de texte.
     *
     * On ne descend donc QUE dans `mc:Choice` et on ignore `mc:Fallback`.
     *
     * @param  array<int, string>  $localNames
     * @param  array<int, DOMElement>  $found  Collecté par référence
     */
    private static function collect(DOMElement $parent, array $localNames, array &$found): void
    {
        foreach ($parent->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            // Ignorer la branche de compatibilité : son contenu est le doublon
            // de celui de `mc:Choice`.
            if ($node->localName === 'Fallback') {
                continue;
            }

            if (in_array($node->localName, $localNames, true)) {
                $found[] = $node;
            }

            self::collect($node, $localNames, $found);
        }
    }
}
