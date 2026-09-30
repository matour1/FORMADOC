<?php

declare(strict_types=1);

namespace App\Document\Formatting;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;

/**
 * Règles de saut de page : ce qui doit commencer sur une nouvelle page.
 *
 * **Pourquoi une classe dédiée.** La règle « un bloc commence sur une nouvelle
 * page » était absente du pipeline : `page_break_before` était LU du document
 * source mais jamais ÉCRIT à la génération. Le résultat était un document
 * conforme au contenu et non conforme à la MISE EN FORME attendue d'un rapport —
 * dédicace collée aux remerciements, chapitre commençant en milieu de page.
 *
 * La règle est isolée ici, et non écrite dans le reconstructeur, pour deux
 * raisons : elle est testable sans produire un DOCX, et elle est NOMMÉE — un
 * `pageBreakBefore` disséminé dans une boucle ne dit pas pourquoi il est là.
 *
 * **Ce qui commence une nouvelle page, et pourquoi.**
 *
 *  - **Chaque pièce liminaire** — dédicace, remerciements, avant-propos, résumé,
 *    abstract, introduction, sommaire, table des matières, listes. C'est la
 *    convention d'un rapport et la demande explicite du propriétaire : « une
 *    page » chacune. Les enchaîner rendrait le frontispice illisible.
 *  - **Chaque titre de niveau 1** — un chapitre commence sur une page. C'est la
 *    convention éditoriale courante, et la respecter ne coûte rien puisque les
 *    niveaux 2 et 3, eux, s'enchaînent normalement.
 *  - **Les sections de fin** — bibliographie, annexes. Elles suivent la
 *    conclusion, pas la dernière ligne de celle-ci.
 *
 * **Ce qui ne commence PAS une nouvelle page.** Les titres de niveau 2 et 3
 * (une sous-section qui saute une page laisse un blanc inutile), les entrées de
 * sommaire (ce sont des lignes de liste, pas des titres), et le corps courant.
 */
final class PageBreakRules
{
    /**
     * Intitulés de pièces liminaires qui occupent une page dédiée.
     *
     * Comparaison en majuscules, sans accents ni espaces superflus : un document
     * français porte « DÉDICACE » et « DEDICACE » selon que l'auteur a pris la
     * peine de l'accent, et les deux doivent être reconnus.
     *
     * @var array<int, string>
     */
    private const TITRES_PAGE_DEDIEE = [
        'SOMMAIRE',
        'TABLE DES MATIERES',
        'TABLE DES MATIÈRES',
        'DEDICACE',
        'DÉDICACE',
        'REMERCIEMENTS',
        'REMERCIMENTS',
        'AVANT-PROPOS',
        'AVANT PROPOS',
        'PREFACE',
        'PRÉFACE',
        'LISTE DES FIGURES',
        'LISTE DES TABLEAUX',
        'LISTE DES ANNEXES',
        'LISTE DES PLANCHES',
        'LISTE DES ABREVIATIONS',
        'LISTE DES ABRÉVIATIONS',
        'LISTE DES SIGLES',
        'SIGLES ET ABREVIATIONS',
        'SIGLES ET ABRÉVIATIONS',
        'TABLE DES ABREVIATIONS',
        'TABLE DES ABRÉVIATIONS',
        'RESUME',
        'RÉSUMÉ',
        'ABSTRACT',
        'EXECUTIVE SUMMARY',
        'INTRODUCTION',
        'INTRODUCTION GENERALE',
        'INTRODUCTION GÉNÉRALE',
        'BIBLIOGRAPHIE',
        'REFERENCES BIBLIOGRAPHIQUES',
        'RÉFÉRENCES BIBLIOGRAPHIQUES',
        'WEBOGRAPHIE',
        'ANNEXES',
        'ANNEXE',
    ];

    /**
     * Le bloc doit-il commencer sur une nouvelle page ?
     *
     * Un seul point d'entrée : c'est lui que le constructeur de charge utile
     * appelle, et c'est lui que les tests interrogent.
     */
    public function exigeNouvellePage(Block $block): bool
    {
        // Les entrées de sommaire sont des LIGNES de liste, pas des titres : leur
        // appliquer un saut de page ferait une page par entrée. Le contrôle doit
        // donc précéder la règle sur les titres, `TocEntry` n'étant pas un titre
        // (`isHeading()` retourne déjà `false`, mais la lecture explicite vaut
        // mieux qu'une dépendance à une méthode qui pourrait changer).
        if ($block->type === BlockType::TocEntry) {
            return false;
        }

        if ($this->estPieceLiminaire($block->text)) {
            return true;
        }

        // Un titre de niveau 1 ouvre une page. Un titre de niveau 2 ou 3 s'enchaîne.
        if ($block->type->isHeading()) {
            return ($block->headingLevel ?? 1) <= 1;
        }

        return false;
    }

    /**
     * Le texte désigne-t-il une pièce liminaire ?
     *
     * **Comparaison sur le DÉBUT du texte, et non sur l'égalité.** Un intitulé
     * réel porte souvent une précision : « SOMMAIRE DES MATIERES », « AVANT-PROPOS
     * DE L'AUTEUR », « INTRODUCTION GENERALE A L'ETUDE ». Exiger l'égalité exacte
     * manquerait tous ces cas, et le défaut serait invisible — la page liminaire
     * s'enchaînerait simplement après la précédente.
     *
     * La normalisation retire accents et espaces superflus, pour que « DÉDICACE »
     * et « DEDICACE » soient la même chose.
     */
    public function estPieceLiminaire(string $texte): bool
    {
        $normalise = $this->normaliser($texte);

        if ($normalise === '') {
            return false;
        }

        foreach (self::TITRES_PAGE_DEDIEE as $intitule) {
            if (str_starts_with($normalise, $intitule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise un texte pour la comparaison : majuscules, sans accents, espaces
     * multiples réduits, ponctuation finale retirée.
     *
     * On utilise `transliterator` quand l'extension intl est disponible (elle
     * l'est sur ce projet), avec un repli par table pour ne pas dépendre d'une
     * extension facultative.
     */
    private function normaliser(string $texte): string
    {
        $texte = mb_strtoupper(trim($texte));

        $texte = class_exists(\Transliterator::class)
            ? (\Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC')?->transliterate($texte) ?? $texte)
            : strtr($texte, [
                'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
                'À' => 'A', 'Â' => 'A', 'Ä' => 'A',
                'Î' => 'I', 'Ï' => 'I',
                'Ô' => 'O', 'Ö' => 'O',
                'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
                'Ç' => 'C',
            ]);

        // Espaces multiples et tabulations réduits à un espace : « AVANT   PROPOS »
        // et « AVANT PROPOS » doivent correspondre.
        $texte = (string) preg_replace('/\s+/u', ' ', $texte);

        // Ponctuation de fin retirée : « SOMMAIRE : » ou « DEDICACE. ».
        return trim($texte, " \t\n\r\0\x0B:;.,-–—");
    }
}
