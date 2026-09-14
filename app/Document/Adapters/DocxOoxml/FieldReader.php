<?php

declare(strict_types=1);

namespace App\Document\Adapters\DocxOoxml;

use DOMElement;

/**
 * Analyse des champs Word (`w:fldSimple` et `w:fldChar`/`w:instrText`).
 *
 * Un champ Word est une « formule » que Word évalue à l'affichage. Trois formes
 * existent, toutes rencontrées dans les documents du projet :
 *
 * ```
 * 1. Champ simple
 *    <w:fldSimple w:instr="PAGE">…</w:fldSimple>
 *
 * 2. Champ complexe en trois parties
 *    <w:r><w:fldChar w:fldCharType="begin"/></w:r>
 *    <w:r><w:instrText>SEQ Figure \* ARABIC</w:instrText></w:r>
 *    <w:r><w:fldChar w:fldCharType="separate"/></w:r>
 *    <w:r><w:t>3</w:t></w:r>                    ← résultat mis en cache
 *    <w:r><w:fldChar w:fldCharType="end"/></w:r>
 *
 * 3. Champ imbriqué (cas piégeux) : un champ SEQ peut contenir un champ
 *    STYLEREF, d'où des `begin` imbriqués. Le compteur de profondeur évite de
 *    fermer le mauvais champ.
 * ```
 *
 * Mesures sur les documents du projet : 1 376 champs, dominés par
 * `SEQ Tableau \* ARABIC` (180), `SEQ Figure \* ARABIC` (142) et les champs
 * de sommaire `TOC` / `PAGEREF` / `STYLEREF`.
 */
final class FieldReader
{
    /**
     * Types de champ reconnus et leur signification.
     *
     * @var array<string, string>
     */
    private const FIELD_KINDS = [
        'SEQ' => 'sequence',      // numérotation automatique (figures, tableaux)
        'REF' => 'reference',     // renvoi vers un signet
        'PAGEREF' => 'page_reference', // lien vers un signet avec numéro de page
        'PAGE' => 'page_number',
        'NUMPAGES' => 'page_count',
        'TOC' => 'table_of_contents',
        'STYLEREF' => 'style_reference', // ex. numéro de chapitre courant
        'DATE' => 'date',
        'TIME' => 'time',
        'AUTHOR' => 'author',
        'FILENAME' => 'filename',
        'HYPERLINK' => 'hyperlink',
        'NOTEREF' => 'note_reference',
        'EQ' => 'equation',
    ];

    /**
     * Interprète une instruction de champ.
     *
     * @param  string  $instruction  Instruction brute (`SEQ Figure \* ARABIC`)
     * @return array{
     *     kind: string,
     *     identifier: null|string,
     *     switches: array<int, string>,
     *     raw: string
     * }
     */
    public function parseInstruction(string $instruction): array
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $instruction));

        if ($normalized === '') {
            return ['kind' => 'unknown', 'identifier' => null, 'switches' => [], 'raw' => $instruction];
        }

        // Séparer le type du reste : « SEQ Figure \* ARABIC »
        $parts = explode(' ', $normalized);
        $type = mb_strtoupper($parts[0]);

        $kind = self::FIELD_KINDS[$type] ?? 'unknown';

        // Le premier argument après le type est l'identifiant :
        //  - pour SEQ : le nom du compteur (« Figure », « Tableau »)
        //  - pour REF/PAGEREF : le nom du signet (« _Toc221266373 »)
        $identifier = null;
        $switches = [];

        foreach (array_slice($parts, 1) as $part) {
            // Les commutateurs commencent par « \ » : \* ARABIC, \h, \s 1, \c "Figure".
            if (str_starts_with($part, '\\')) {
                $switches[] = $part;

                continue;
            }

            if ($identifier === null && ! str_starts_with($part, '"')) {
                $identifier = $part;
            }
        }

        // Les arguments entre guillemets (« \c "Figure" ») désignent aussi un
        // identifiant : on les récupère si aucun identifiant nu n'a été trouvé.
        if ($identifier === null && preg_match('/"([^"]+)"/u', $normalized, $matches) === 1) {
            $identifier = $matches[1];
        }

        return [
            'kind' => $kind,
            'identifier' => $identifier,
            'switches' => $switches,
            'raw' => $normalized,
        ];
    }

    /**
     * Le champ est-il un compteur de séquence (numérotation d'éléments) ?
     *
     * `SEQ Figure` / `SEQ Tableau` : c'est le mécanisme par lequel Word
     * attribue « Figure 1 », « Tableau 2 »… Connaître le nom du compteur
     * permet de rattacher la légende à sa catégorie.
     */
    public function isSequence(string $instruction): bool
    {
        return str_starts_with(mb_strtoupper(trim($instruction)), 'SEQ ');
    }

    /**
     * Le champ est-il un renvoi (vers un élément numéroté) ?
     *
     * `REF` et `PAGEREF` pointent vers un signet : ce sont les renvois croisés
     * que la renumérotation (R4) devra réécrire.
     */
    public function isReference(string $instruction): bool
    {
        $type = mb_strtoupper(trim(explode(' ', trim($instruction))[0] ?? ''));

        return in_array($type, ['REF', 'PAGEREF', 'NOTEREF'], true);
    }

    /**
     * Le champ est-il un champ de mise en page automatique ?
     *
     * Ces champs (TOC, PAGE, NUMPAGES, STYLEREF, PAGEREF, DATE) sont
     * **régénérés** par le moteur de rendu : leur valeur mise en cache dans le
     * document source est obsolète et ne doit jamais être reprise comme contenu.
     *
     * `PAGEREF` en fait partie : il contient un numéro de page, donc il dépend
     * entièrement de la pagination du gabarit appliqué. Le reprendre tel quel
     * afficherait un numéro faux.
     */
    public function isGeneratedField(string $instruction): bool
    {
        $type = mb_strtoupper(trim(explode(' ', trim($instruction))[0] ?? ''));

        return in_array(
            $type,
            ['TOC', 'PAGE', 'NUMPAGES', 'STYLEREF', 'PAGEREF', 'DATE', 'TIME', 'FILENAME'],
            true
        );
    }

    /**
     * Catégorie d'élément associée à un compteur SEQ.
     *
     * `SEQ Figure` → figure · `SEQ Tableau` → tableau · `SEQ Planche` → planche.
     * La correspondance tolère les fautes de frappe fréquentes : le projet
     * contient `SEQ ttablaeu` (36 occurrences) pour « Tableau ».
     *
     * @param  null|string  $identifier  Nom du compteur (« Figure », « Tableau »…)
     * @return null|string Catégorie normalisée, ou null si non reconnue
     */
    public function sequenceCategory(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }

        $key = mb_strtolower($identifier);

        // Normalisation des variantes et fautes de frappe observées.
        $aliases = [
            'figure' => 'figure',
            'fig' => 'figure',
            'image' => 'figure',
            'tableau' => 'table',
            'ttablaeu' => 'table', // faute observée dans les documents
            'tablaeu' => 'table',
            'table' => 'table',
            'annexe' => 'annexe',
            'appendix' => 'annexe',
            'planche' => 'planche',
        ];

        return $aliases[$key] ?? null;
    }

    /**
     * Champ dont le résultat mis en cache doit être ignoré.
     *
     * Utilisé par le lecteur de paragraphes pour ne pas inclure dans le texte
     * les valeurs figées des champs régénérés (numéro de page, entrée de
     * sommaire), qui seraient fausses après traitement.
     */
    public function cachedValueIsStale(string $instruction): bool
    {
        return $this->isGeneratedField($instruction);
    }

    /**
     * Extrait les champs simples (`w:fldSimple`) contenus dans un élément.
     *
     * @return array<int, array{instruction: string, kind: string, identifier: null|string}>
     */
    public function simpleFieldsIn(DOMElement $container): array
    {
        $fields = [];

        foreach (XmlLoader::descendants($container, 'w:fldSimple') as $node) {
            $instruction = $node->getAttribute('w:instr');

            if ($instruction === '') {
                // Le préfixe peut varier : on retente par nom local.
                $instruction = XmlLoader::attributeValue($node, 'w:instr') ?? '';
            }

            if ($instruction === '') {
                continue;
            }

            $parsed = $this->parseInstruction($instruction);
            $fields[] = [
                'instruction' => $parsed['raw'],
                'kind' => $parsed['kind'],
                'identifier' => $parsed['identifier'],
            ];
        }

        return $fields;
    }

    /**
     * Extrait les instructions de champ complexes d'un paragraphe.
     *
     * Un `w:instrText` isolé suffit à connaître la nature du champ ; les
     * `w:fldChar` servent au lecteur de paragraphes pour savoir QUAND ignorer
     * le texte (entre `separate` et `end` pour un champ régénéré).
     *
     * @return array<int, array{instruction: string, kind: string, identifier: null|string, is_stale: bool}>
     */
    public function complexFieldsIn(DOMElement $container): array
    {
        $fields = [];

        foreach (XmlLoader::descendants($container, 'w:instrText') as $node) {
            $instruction = trim($node->textContent);

            if ($instruction === '') {
                continue;
            }

            $parsed = $this->parseInstruction($instruction);

            $fields[] = [
                'instruction' => $parsed['raw'],
                'kind' => $parsed['kind'],
                'identifier' => $parsed['identifier'],
                'is_stale' => $this->cachedValueIsStale($parsed['raw']),
            ];
        }

        return $fields;
    }
}
