# Instructions de génération de documents Word (PHPWord)

Ce dossier contient les directives précises pour générer des rapports de stage / mémoires professionnels éditables dans Microsoft Word, avec une page de garde fidèle aux standards camerounais (type DQP/CQP).

## Objectif

Produire un fichier `.docx` :
- Entièrement généré en code (pas de template Word préexistant)
- Éditable dans Microsoft Word et LibreOffice
- Avec une page de garde professionnelle fidèle à l’exemple fourni
- Avec gestion pragmatique des listes de figures et de tableaux
- Compatible avec `setUpdateFields(true)` pour que Word mette à jour les champs à l’ouverture

## Structure de ce dossier

| Fichier | Contenu |
|---------|---------|
| `01-architecture.md` | Architecture globale de la génération |
| `02-page-de-garde.md` | Règles strictes pour la page de garde (fidélité à l’exemple) |
| `03-listes-figures-tableaux.md` | Stratégie pour les listes de figures et tableaux |
| `04-styles-et-structure.md` | Styles de titres, TOC, sections |
| `05-integration-code.md` | Comment intégrer dans l’application PHP |
| `06-limitations-et-alternatives.md` | Ce que PHPWord ne fait pas bien + alternatives |

## Principe fondamental

> **Ne pas essayer de faire de PHPWord un clone parfait de Word.**  
> On génère une structure propre et éditable. Les fonctionnalités avancées (vraies captions SEQ + Table of Figures native) sont soit simulées, soit laissées à Word pour mise à jour manuelle.

## Flux de données recommandé

```
Formulaire utilisateur
        ↓
Tableau $data (titre, auteur, logos, dates, encadreurs…)
        ↓
Service DocumentGenerator
        ↓
1. Page de garde (section 1)
2. Corps du document (section 2+)
3. Listes générées manuellement
        ↓
$phpWord->getSettings()->setUpdateFields(true);
        ↓
Sauvegarde .docx
```
