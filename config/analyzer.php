<?php

declare(strict_types=1);

/**
 * Règles de détection structurelle (RuleBasedDetector).
 *
 * Chaque règle possède :
 *  - nom       : identifiant lisible de la règle
 *  - categorie : catégorie de résultat (AnalyzerResult::CATEGORIES)
 *  - quand     : conditions (toutes doivent être vraies) :
 *      * type            : type d'élément PhpWord normalisé (titre, texte, tableau, image)
 *      * parent          : body | header | footer | * (tous)
 *      * style_name      : nom du style de paragraphe (Heading1, Titre1…) ou de police
 *      * font_size       : comparaison de taille de police
 *                          (>=>, >, <=, <) → ['>=' => 16], ou égalité → 16
 *      * gras            : booléen (font bold)
 *      * texte_contient  : sous-chaîne du texte
 *      * texte_matche    : regex sur le texte
 *  - niveau    : optionnel, niveau de titre (1, 2, 3…)
 *
 * Ordre important : un élément s'arrête à la PREMIÈRE règle qui matche.
 * Les règles les plus spécifiques doivent donc être en premier
 * (ex : Heading1 avant le fallback par taille).
 */

return [
    'rules' => [
        // ── Titres : d'abord les styles de paragraphe HeadingN (fiable) ─────────
        [
            'nom' => 'titre_niveau_1_style',
            'categorie' => 'titres',
            'niveau' => 1,
            'quand' => [
                'type' => 'titre',
                'parent' => '*',
                'style_name' => 'Heading1',
            ],
        ],
        [
            'nom' => 'titre_niveau_2_style',
            'categorie' => 'sous_titres',
            'niveau' => 2,
            'quand' => [
                'type' => 'titre',
                'parent' => '*',
                'style_name' => 'Heading2',
            ],
        ],
        [
            'nom' => 'titre_niveau_3_style',
            'categorie' => 'sous_titres',
            'niveau' => 3,
            'quand' => [
                'type' => 'titre',
                'parent' => '*',
                'style_name' => 'Heading3',
            ],
        ],

        // ── Fallback : titres détectés par la taille de police + gras ───────────
        // (certains documents n'utilisent pas les styles HeadingN, seulement une
        // mise en forme manuelle : police 16+ gras = titre niveau 1, etc.)
        [
            'nom' => 'titre_niveau_1_taille',
            'categorie' => 'titres',
            'niveau' => 1,
            'quand' => [
                'parent' => '*',
                'font_size' => ['>=' => 16],
                'gras' => true,
            ],
        ],
        [
            'nom' => 'titre_niveau_2_taille',
            'categorie' => 'sous_titres',
            'niveau' => 2,
            'quand' => [
                'parent' => '*',
                'font_size' => ['>=' => 14],
                'gras' => true,
            ],
        ],
        [
            'nom' => 'titre_niveau_3_taille',
            'categorie' => 'sous_titres',
            'niveau' => 3,
            'quand' => [
                'parent' => '*',
                'font_size' => ['>=' => 12],
                'gras' => true,
            ],
        ],

        // ── Titre en MAJUSCULES (forme du texte) ─────────────────────────────────
        // Complément déterministe : les paragraphes entièrement en capitales
        // (sans style Heading ni taille/gras suffisants) sont des titres de
        // niveau 1 — ex : "INTRODUCTION", "CONTEXTE GENERAL", "ÉTAT DE L'ART".
        // Placée APRÈS les règles de style/taille (1re correspondance gagne)
        // et avant en-têtes/pieds de page. Limitée aux paragraphes de corps
        // (type 'texte') et aux non-légendes ("FIGURE 1: ..." est exclu).
        [
            'nom' => 'titre_majuscules',
            'categorie' => 'titres',
            'niveau' => 1,
            'quand' => [
                'type' => 'texte',
                'parent' => 'body',
                'texte_matche' => '/^(?!(?i:(?:figure|tableau|image|schéma|schema|graphique))\s*\d)[\p{Lu}][\p{Lu}\p{N}\s\'\x{2019}\x{2014}\-:.,()°&+%]{2,58}[\p{Lu}\p{N}]$/u',
            ],
        ],

        // ── En-têtes et pieds de page (position dans le document) ────────────────
        [
            'nom' => 'en_tete',
            'categorie' => 'en_tetes',
            'quand' => [
                'parent' => 'header',
            ],
        ],
        [
            'nom' => 'pied_de_page',
            'categorie' => 'pieds_de_page',
            'quand' => [
                'parent' => 'footer',
            ],
        ],

        // ── Tableaux et images (type d'élément PhpWord) ──────────────────────────
        [
            'nom' => 'tableau',
            'categorie' => 'tableaux',
            'quand' => [
                'type' => 'tableau',
            ],
        ],
        [
            'nom' => 'image',
            'categorie' => 'images',
            'quand' => [
                'type' => 'image',
            ],
        ],

        // ── Éléments flottants (formes, zones de texte) ─────────────────────────
        [
            'nom' => 'element_flottant',
            'categorie' => 'elements_flottants',
            'quand' => [
                'type' => 'autre',
            ],
        ],
    ],
];
