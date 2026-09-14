<?php

declare(strict_types=1);

namespace App\Document\Classification;

use App\Document\Structure\BlockCategory;

/**
 * Détection des légendes et des renvois par pattern texte.
 *
 * Principe fondateur du projet (règle n°1 de `copilot-instructions.md`) :
 * **une légende suit toujours le format « Mot N : texte »**. C'est une règle
 * stable et saisie par l'auteur — donc une regex suffit, et il serait absurde
 * de payer des tokens pour cela.
 *
 * Ce détecteur alimente deux besoins :
 *  1. classification (type `caption`) → gratuit, `confidence` élevée ;
 *  2. détection des renvois croisés (`cross_ref`) → gratuit également.
 *
 * Les 4 catégories sont traitées sur un pied d'égalité : Figure, Tableau,
 * Annexe, Planche (chacune avec son compteur indépendant).
 */
final class CaptionPattern
{
    /**
     * Séparateurs acceptés entre le mot-clé et le numéro : « : », « - », « . ».
     *
     * Tolérer le point et le tiret est indispensable : les auteurs écrivent
     * « Figure 1: », « Figure 1 - », « Figure 1. » selon leur habitude.
     */
    private const SEPARATOR = '(?:\s*[:.\-–—]\s*|\s+)';

    /**
     * Numéro accepté : chiffres arabes, ou lettre/chiffre pour les annexes
     * (« Annexe A », « Annexe 3 », « Planche IV »).
     */
    private const NUMBER = '(\d+|[A-Z]|[IVXLCDM]+)';

    /**
     * Détecte une légende en début de paragraphe.
     *
     * La légende doit être en DÉBUT de texte : sinon une phrase du corps
     * contenant « la figure 3 montre » serait prise pour une légende.
     *
     * @param  string  $text  Texte du paragraphe
     * @return null|array{
     *     category: BlockCategory,
     *     original_number: string,
     *     number_source: 'arabic'|'letter'|'roman',
     *     caption_text: string,
     *     matched: string
     * } null si le texte n'est pas une légende
     */
    public function detect(string $text): ?array
    {
        $text = ltrim($text);

        if ($text === '') {
            return null;
        }

        // Le mot-clé, puis un numéro, puis un séparateur OPTIONNEL : les auteurs
        // écrivent « Figure 1 : texte », « Figure 1. texte » ou simplement
        // « Figure 1 » quand la légende s'arrête au numéro.
        $pattern = '/^(?<keyword>Légende|Legende|Figure|Tableau|Annexe|Planche)\s+'
            .'(?<number>\d+|[A-Z]|[IVXLCDM]+)(?<rest>.*)$/isu';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        $number = $matches['number'];

        // Un numéro alphabétique doit être en MAJUSCULE : « Annexe B » est une
        // annexe, mais « figure ci-dessous » ne désigne rien de numéroté.
        // Sans ce contrôle, le « ci » de « ci-dessous » serait pris pour un
        // numéro de renvoi — un faux positif coûteux (il déclencherait une
        // fausse résolution de renvoi croisé).
        if (! $this->isPlausibleNumber($number)) {
            return null;
        }

        $rest = trim((string) $matches['rest']);

        // Le reste doit être une légende : un séparateur (« : », « - », « . »)
        // ou une chaîne vide. Un mot collé sans séparateur (« Figure 1er »)
        // indique qu'on a coupé un mot, donc ce n'est pas une légende.
        if ($rest !== '' && preg_match('/^[:.\-–—]\s*/u', $rest) !== 1) {
            return null;
        }

        $rest = trim((string) preg_replace('/^[:.\-–—]\s*/u', '', $rest));

        $category = $this->categoryOf($matches['keyword']);

        if ($category === null) {
            return null;
        }

        return [
            'category' => $category,
            'original_number' => $number,
            'number_source' => $this->numberSource($number),
            'caption_text' => $rest,
            'matched' => trim($matches['keyword'].' '.$number.($rest !== '' ? ' '.$rest : '')),
        ];
    }

    /**
     * Le numéro détecté est-il plausible pour un élément numéroté ?
     *
     * Règles :
     *  - les chiffres arabes le sont toujours ;
     *  - une lettre SEULE doit être MAJUSCULE (convention d'annexe « Annexe A ») ;
     *  - les chiffres romains doivent compter au moins 2 caractères (sinon
     *    « I. » serait confondu avec un simple « I » de numérotation).
     */
    private function isPlausibleNumber(string $number): bool
    {
        if (preg_match('/^\d+$/', $number) === 1) {
            return true;
        }

        // Lettre seule : la casse compte (« B » oui, « b » non, « ci » non).
        if (mb_strlen($number) === 1) {
            return preg_match('/^[A-Z]$/', $number) === 1;
        }

        return false;
    }

    /**
     * Le paragraphe ressemble-t-il à une légende sans numéro ?
     *
     * Cas fréquent : « Figure : Schéma du pipeline » (numéro omis car Word
     * insère un champ SEQ, résolu ailleurs). On reconnaît le motif mais sans
     * numéro exploitable.
     */
    public function detectWithoutNumber(string $text): ?BlockCategory
    {
        $text = ltrim($text);

        if (preg_match('/^(?<keyword>Légende|Legende|Figure|Tableau|Annexe|Planche)\s*[:.\-–—]\s*\S/isu', $text, $matches) !== 1) {
            return null;
        }

        return $this->categoryOf($matches['keyword']);
    }

    /**
     * Détecte un RENVOI textuel à un élément numéroté, où qu'il soit dans le texte.
     *
     * Différence clé avec une légende : un renvoi peut apparaître n'importe où
     * dans une phrase (« voir Figure 3 », « cf. Annexe B »), alors qu'une
     * légende commence le paragraphe.
     *
     * Le mot-clé est détecté **sans tenir compte de la casse** — les auteurs
     * écrivent « la figure 2 » en minuscule bien plus souvent qu'en capitale.
     * En revanche le numéro alphabétique reste **obligatoirement en majuscule** :
     * sans cette restriction, le « ci » de « la figure ci-dessous » serait pris
     * pour un numéro d'annexe — un faux positif qui déclencherait une fausse
     * résolution de renvoi.
     *
     * @param  string  $text  Texte d'un paragraphe quelconque
     * @return array<int, array{category: BlockCategory, original_number: string, matched: string, position: int}>
     */
    public function detectCrossReferences(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $found = [];

        // `i` s'applique au MOT-CLÉ uniquement ; le numéro alphabétique est
        // contrôlé ensuite par `isPlausibleNumber()`, qui exige une majuscule.
        // L'inverse serait un faux positif coûteux : avec `i`, le `[A-Z]`
        // accepterait toute lettre et « Tableau n°3 » produirait un renvoi vers
        // un « Tableau n ».
        //
        // L'apostrophe est exclue du lookahead : « TABLEAU D'AMORTISSEMENT »
        // (titre réel du corpus) produisait sinon un renvoi vers « Tableau D ».
        $pattern = '/\b(?<keyword>Figure|Tableau|Annexe|Planche)\s+'
            ."(?<number>\d+|[A-Z])(?![a-zA-Z\p{L}'’])/iu";

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        foreach ($matches[0] as $index => [$matchedText, $offset]) {
            $category = $this->categoryOf($matches['keyword'][$index][0]);

            if ($category === null) {
                continue;
            }

            $numero = $matches['number'][$index][0];

            // Un numéro alphabétique doit être en MAJUSCULE (convention
            // d'annexe : « Annexe A »). Le contrôle ne peut pas être fait par la
            // regex, puisque le drapeau `i` est nécessaire pour le mot-clé.
            if (! $this->isPlausibleNumber($numero)) {
                continue;
            }

            $found[] = [
                'category' => $category,
                'original_number' => $numero,
                'matched' => $matchedText,
                'position' => (int) $offset,
            ];
        }

        return $found;
    }

    /**
     * Le paragraphe est-il une entrée de liste de figures/tableaux ?
     *
     * Ces entrées (frontispice) ressemblent à des légendes mais vivent dans une
     * section dédiée : les reclassifier comme légendes créerait des doublons
     * dans les listes générées.
     */
    public function isListEntry(string $text): bool
    {
        // Forme typique : « Figure 1 : Titre ......... 12 » (points de conduite
        // et numéro de page en fin de ligne).
        return preg_match('/^(Figure|Tableau|Annexe|Planche)\s+/iu', ltrim($text)) === 1
            && preg_match('/\.{3,}\s*\d+$/u', $text) === 1;
    }

    /**
     * Catégorie correspondant à un mot-clé.
     *
     * @param  string  $keyword  Mot-clé seul (« Figure », « Légende », « annexe »…)
     */
    private function categoryOf(string $keyword): ?BlockCategory
    {
        $keyword = trim($keyword);

        // « Légende » sans autre indication : on ne peut pas trancher la
        // catégorie (figure ou tableau ?). Le bloc lié le fera, ou la
        // clarification utilisateur. On retourne Figure comme défaut neutre.
        if (preg_match('/^(Légende|Legende)$/iu', $keyword) === 1) {
            return BlockCategory::Figure;
        }

        foreach (BlockCategory::all() as $category) {
            if (preg_match('/^'.$category->keyword().'$/iu', $keyword) === 1) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Nature du numéro détecté : chiffres arabes, lettre ou chiffres romains.
     *
     * Utile à la renumérotation : la spec impose UN SEUL style de numérotation
     * (chiffres arabes) pour les 4 catégories, donc un « Annexe B » devra
     * devenir « Annexe 1 ». Traçer la source facilite ce remplacement.
     */
    private function numberSource(string $number): string
    {
        if (preg_match('/^\d+$/', $number) === 1) {
            return 'arabic';
        }

        if (preg_match('/^[IVXLCDM]+$/', $number) === 1 && mb_strlen($number) > 1) {
            return 'roman';
        }

        return 'letter';
    }
}
