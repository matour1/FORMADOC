# 01 – Architecture de génération

## Décision structurante

Le document est **toujours** généré en deux sections minimum :

1. **Section 1** → Page de garde (pas de header/footer classique, marges spécifiques)
2. **Section 2+** → Corps du document (avec header + footer numérotés)

On n’utilise **pas** de template `.docx` préexistant. Tout est construit en code PHPWord.

## Pourquoi cette architecture ?

- La page de garde a un design très différent du reste (logos, bandeau, ruban).
- On veut un « Different First Page » propre.
- Les utilisateurs doivent pouvoir éditer le document après génération.
- On évite les problèmes de corruption de templates avec LibreOffice récents.

## Composants principaux

```
DocumentGenerator
├── generateCoverPage($phpWord, $data)     // Section 1
├── generateBody($phpWord, $data)          // Section 2+
├── generateTableOfContents(...)
├── generateListOfFigures(...)             // Générée manuellement
├── generateListOfTables(...)              // Générée manuellement
└── applyGlobalSettings($phpWord)
```

## Données d’entrée minimales ($data)

```php
[
    // Page de garde
    'titre'                    => string,
    'auteur'                   => string,
    'filiere'                  => string,
    'specialite'               => string,
    'entreprise'               => string,
    'date_debut'               => string,
    'date_fin'                 => string,
    'encadreur_academique'     => string,
    'encadreur_academique_role'=> string,   // ex: "(Enseignant au CFPMNP)"
    'encadreur_pro'            => string,
    'encadreur_pro_role'       => string,   // ex: "(Développeur full stack au RMS)"
    'annee'                    => string,   // "2025-2026"
    
    // Logos (chemins absolus)
    'logo_minedop'             => ?string,
    'logo_isn'                 => ?string,
    'logo_reseau'              => ?string,
    
    // Contenu
    'sections'                 => array,    // chapitres / contenu
    'figures'                  => array,    // pour la liste des figures
    'tableaux'                 => array,    // pour la liste des tableaux
]
```

## Ordre de génération obligatoire

1. Créer `PhpWord`
2. `setUpdateFields(true)`
3. Définir les `TitleStyle` (1, 2, 3…)
4. Générer la page de garde (section 1)
5. Générer le corps (section 2)
6. Insérer le TOC
7. Insérer le contenu + légendes de figures/tableaux
8. Générer les listes de figures et tableaux (manuellement)
9. Sauvegarder

## Interdictions

- Ne jamais mélanger page de garde et corps dans la même section.
- Ne pas compter sur les TextBox pour tout le positionnement critique.
- Ne pas utiliser `addHtml()` pour la page de garde (trop imprévisible).
