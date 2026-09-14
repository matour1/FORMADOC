# REFONTE — Traitement de documents (architecture cible)

> **Statut** : 🟡 Architecture documentée — implémentation à démarrer (Phase R0)
> **Source** : document de refonte « SaaS de traitement de documents » (9 sections + annexes)
> **Branche** : `feature/refonte-document-processing`
> **Prérequis lus** : `CAHIER_DES_CHARGES.md`, `PLAN_DEVELOPPEMENT.md`, `copilot-instructions.md`

---

## 1. Pourquoi cette refonte

L'architecture actuelle échoue sur trois points :

| Problème | Cause actuelle | Conséquence |
|----------|----------------|-------------|
| Détection des titres peu fiable | Styles Word mal appliqués, formatage manuel | Hiérarchie fausse → TOC cassé |
| Couverture limitée au `.docx` | `DocumentParser` lit via **PHPWord** (interdit par la refonte §15) | Google Docs et PDF scanné non supportés |
| Coût IA désynchronisé | Points forfaitaires vs coût token réel | Marge fausse, ledger inexact |

**Décision structurante** : PHPWord cesse d'être la **source de vérité en entrée**.
Il ne sert plus qu'à la **génération finale** du `.docx` de sortie.

### État actuel vs cible

| Aspect | Aujourd'hui | Cible |
|--------|-------------|-------|
| Lecture `.docx` | PHPWord (`DocumentParser`) ❌ | Parseur OOXML natif (ZipArchive + DOMDocument) ✅ |
| Google Docs | Non supporté ❌ | Export via Google Docs API ✅ |
| PDF scanné | Non supporté ❌ | OCR + layout (API payante) ✅ |
| Format pivot interne | Structure ad hoc (`DocumentStructure`) ❌ | **JSON structurel commun** (blocs) ✅ |
| Classification | Regex + `AiCorrectionService` post-hoc | Tool `detect_blocks` + seuil de confiance ✅ |
| Ambiguïtés | Page de validation globale | `ask_user_clarification` **ciblé par bloc** ✅ |
| Renvois croisés | Non gérés ❌ | `cross_ref` + résolution + renumérotation ✅ |
| Renumérotation | Partielle | Compteurs indépendants par catégorie ✅ |
| Listes (figures/tableaux/annexes/planches) | Partielles | Passe finale après pagination réelle ✅ |
| Chat d'édition | Tools ad hoc | **Liste blanche stricte** de 6 tools ✅ |
| Ledger facturation | Estimations a priori | **Tokens réels** + retries + échec partiel ✅ |

---

## 2. Principe d'architecture central

```
┌─────────────────────────────────────────────────────────────┐
│  MOTEUR DÉTERMINISTE (aucun token)                          │
│  • Applique le gabarit sur des blocs DÉJÀ classifiés        │
│  • Restylage tableaux, en-tête/pied, numérotation           │
│  • Renumérotation + résolution des renvois croisés          │
│  • Génération TOC / listes (après pagination réelle)        │
└─────────────────────────────────────────────────────────────┘
                              ▲
                              │ blocs classifiés
┌─────────────────────────────────────────────────────────────┐
│  AGENT IA (tokens, coût mesuré)                             │
│  • Classifie les blocs AMBIGUS (mode automatique)           │
│  • Répond aux demandes d'édition du chat (mode libre)       │
└─────────────────────────────────────────────────────────────┘
```

> **Règle d'or** : ne jamais faire deviner à l'IA ce que le déterministe peut faire seul.

---

## 3. Périmètre

**Formats d'entrée** : `.docx`, Google Docs, PDF scanné.

**Hors scope (ne rien développer, mais garder l'architecture extensible)** :
- Génération de fichiers Excel par IA
- Génération de CV adaptés à un poste
- Toute fonctionnalité non listée dans le document de refonte

**Contraintes** : budget serré (gratuit/natif d'abord), **français + anglais** uniquement, 100–1000 documents/mois.

---

## 4. Pipeline d'ingestion

```
[Upload]
   ├─ .docx       → parseur OOXML natif (gratuit — structure native préservée)
   ├─ Google Docs → export via Google Docs API (gratuit — structure native)
   └─ PDF scanné  → OCR + layout (API payante type Azure Document Intelligence
                     ou Google Document AI — volume faible = coût maîtrisé)
                            ↓
              JSON structurel unique (SEUL format connu du reste du système)
```

> **Règle stricte** : aucun module en aval ne connaît le format d'origine.

---

## 5. Schéma JSON structurel commun

```json
{
  "document_id": "doc_123",
  "source_type": "docx | gdocs | ocr",
  "blocks": [
    {
      "block_id": "b_001",
      "type": "heading | paragraph | table | figure | image | caption | annexe | planche | header | footer | cross_ref",
      "text": "Introduction générale",
      "heading_level": 1,
      "font_size": 16,
      "is_bold": true,
      "indent_level": 0,
      "position_y": 120,
      "fidelity": "exact | reconstructed",
      "confidence": 0.94,
      "linked_block_id": null,
      "table_data": { "rows": 3, "cols": 4, "cells": ["..."] },
      "image_ref": "img_007.png",
      "category": "figure | table | annexe | planche",
      "original_number": "3",
      "final_number": null,
      "cross_ref": {
        "matched_text": "Figure 3",
        "target_category": "figure",
        "target_original_number": "3",
        "resolved_block_id": null,
        "resolution_confidence": null
      }
    }
  ]
}
```

### Points de vigilance

| Champ | Règle |
|-------|-------|
| `fidelity` | `exact` pour `.docx`/Google Docs · `reconstructed` pour PDF scanné |
| `type: figure` | Image **numérotée avec légende** — distinct de `image` (décorative) |
| `type: caption` | Porte `linked_block_id` vers la figure/tableau/annexe/planche |
| `annexe`, `planche` | Catégories à part entière (comme figure/tableau) |
| `cross_ref` | Marque un renvoi textuel (« voir Figure 3 ») |
| `table_data` | Conserve fusion de cellules et contenu exact — **jamais reformulé par l'IA** |

---

## 6. Classification des blocs (IA, mode automatique)

### Tool `detect_blocks`

Signaux fournis au modèle : `font_size`, `is_bold`, `indent_level`, `position`, `source_type`, texte brut.

Sortie attendue (JSON uniquement) :
```json
{ "block_id": "...", "predicted_type": "heading_1|heading_2|heading_3|paragraph|table_header|figure|caption|annexe|planche",
  "confidence": 0.0, "signal_used": "font_size+bold|isolated_line|position|text_pattern" }
```

**Priorité des signaux** :
1. **Pattern texte explicite** (le plus fiable — saisi par l'utilisateur) :
   - `Figure` → `^Figure\s+\d+`
   - `Tableau` → `^Tableau\s+\d+`
   - `Annexe` → `^Annexe\s+[A-Z0-9]+`
   - `Planche` → `^Planche\s+\d+`
2. **Signaux visuels** (`font_size`, `is_bold`, `position`) si aucun pattern.

> Ne jamais deviner sans signal à l'appui. Signaux contradictoires ou absents → `confidence < 0.7`.
> **Ne jamais halluciner de texte** : le champ `text` est classifié, jamais généré.

### Règle de décision

| Cas | Action |
|-----|--------|
| `confidence ≥ 0.85` | Classification appliquée automatiquement |
| `confidence < 0.85` | `ask_user_clarification` **ciblé sur ce bloc** (jamais tout le document) |
| `fidelity == "reconstructed"` | Aperçu avant/après **systématique** avec validation, même à haute confiance |

---

## 7. Tool `ask_user_clarification`

Retourne un JSON Schema affiché côté frontend :

```json
{
  "block_id": "b_042",
  "question": "Ce texte en gras est-il un titre de section ou un paragraphe d'emphase ?",
  "input_type": "single_select | free_text | checkbox",
  "options": ["Titre niveau 1", "Titre niveau 2", "Paragraphe normal"]
}
```

La réponse est réinjectée dans le contexte de l'agent et corrige **uniquement** le `block_id` visé.

---

## 8. Chat conversationnel et tools d'édition libre

### Rôle

Le chat modifie **les blocs du JSON structurel**, jamais le document rendu directement.
Après modification → re-génération complète (gabarit → renumérotation → listes) avant livraison.

### Liste blanche des tools (aucun autre tool autorisé)

| Tool | Effet |
|------|-------|
| `rewrite_paragraph(block_id, instruction)` | Reformule le texte d'un bloc existant |
| `insert_block(position_block_id, type, content, category?)` | Insère un bloc (paragraphe, figure+légende, tableau, annexe…) |
| `modify_table(block_id, instruction)` | Modifie contenu/structure d'un tableau |
| `delete_block(block_id)` | Supprime un bloc |
| `regenerate_section(start_block_id, end_block_id, instruction)` | Réécrit une section complète |
| `ask_user_clarification` | Réutilisé tel quel (§7) |

### Prompt système du chat agent

```
Tu es un assistant qui aide l'utilisateur à modifier un document via des tools stricts.
Tu ne dois JAMAIS générer de texte directement dans ta réponse pour représenter
le contenu du document — tu dois appeler un tool.
Tu ne peux appeler que les tools listés ci-dessus. Aucune autre action n'est autorisée.
Avant toute action destructive (delete_block, regenerate_section sur plus de 5 blocs),
demande confirmation explicite à l'utilisateur via ask_user_clarification.
```

### Garde-fous obligatoires

| # | Garde-fou | Détail |
|---|-----------|--------|
| 1 | **Snapshot avant action destructive** | Sauvegarder l'état JSON avant `delete_block`/`regenerate_section` → « annuler » en session |
| 2 | **Verrou d'édition** | Un seul processus (auto OU chat) modifie le JSON à la fois |
| 3 | **Validation de schéma** | Toute sortie de tool validée contre le schéma §5 ; rejet + retry si invalide |
| 4 | **Renumérotation automatique** | Tout tool touchant figure/table/annexe/planche déclenche la renumérotation (§10) |
| 5 | **Traçabilité coût** | Chaque appel de tool loggé dans le ledger avec tokens réels — **aucune exception** |
| 6 | **Ordre de ré-export** | gabarit (§9) → renumérotation (§10) → listes (§11) — dans cet ordre |

---

## 9. Moteur de gabarit (déterministe)

Applique sur les blocs **déjà classifiés** :
- **Titres** : police, taille par niveau, couleur, interligne, centrage
- **Tableaux** : bordures + couleur d'en-tête réappliquées — **contenu des cellules jamais modifié**
- **En-tête / pied de page / numérotation** : injectés au rendu final
- **Figures/images** : repositionnées selon le flux ; fichier image inchangé

> Pour un PDF scanné (`fidelity: "reconstructed"`) : viser la fidélité maximale,
> **jamais** afficher « identique à l'original » → dire « reconstruction fidèle, validée par vous ».

---

## 10. Numérotation et renvois croisés

### Numérotation

- Les **4 catégories** (Figure, Tableau, Annexe, Planche) partagent le **même style** (chiffres arabes).
- Chaque catégorie a son **compteur indépendant** repartant à 1.
- `original_number` (saisi) **n'est jamais fiable** → le système renumérote en séquence propre et continue par ordre d'apparition (`final_number`), ignorant trous et doublons.

### Détection des renvois

Pattern sur chaque bloc `paragraph` : `^.*(Figure|Tableau|Annexe|Planche)\s+([A-Z0-9]+).*$`
→ crée un bloc `cross_ref` avec `matched_text`, `target_category`, `target_original_number`.

### Résolution

| Cas | Traitement |
|-----|-----------|
| **Simple** (un seul bloc avec ce numéro d'origine) | Résolution directe, `resolution_confidence = 1.0` |
| **Ambigu** (numéro dupliqué) | Heuristique de **proximité dans l'ordre du document** (pas une distance en caractères), **sans bloquer** sur validation utilisateur |
| **Proximité non concluante** | `resolution_confidence < 0.7` |

> **Signalement discret** (non bloquant) : les renvois `resolution_confidence < 0.7` sont listés
> dans un rapport de fin de traitement (« 3 renvois résolus automatiquement avec confiance faible — à vérifier »).
> **Jamais** de `ask_user_clarification` bloquant pour un renvoi ambigu.

---

## 11. Génération des listes (passe finale)

```
1. Rendu complet du document avec le gabarit appliqué (§9 terminé)
2. Pagination réelle calculée (moteur de rendu docx/PDF)
3. Parcours des blocs "heading"          → table des matières, avec page réelle
4. Parcours des blocs "figure" + caption → Liste des figures   — page dédiée
5. Parcours des blocs "table" + caption  → Liste des tableaux  — page dédiée
6. Parcours des blocs "annexe"           → Liste des annexes   — page dédiée
7. Parcours des blocs "planche"          → Liste des planches  — page dédiée
8. Insertion de chaque liste sur sa propre page
```

> **Aucun token** consommé — calcul déterministe sur le JSON + pagination du moteur de rendu.
> **Règle stricte** : cette génération **ne peut jamais précéder le rendu final** (les numéros
> de page dépendent de la pagination réelle, elle-même dépendante du gabarit appliqué).

---

## 12. Ledger de facturation

Checklist stricte sur **chaque** appel LLM ou tool payant :

1. Logger `usage.input_tokens` / `usage.output_tokens` **réels** — jamais d'estimation a priori
2. Le modèle facturé doit correspondre **exactement** au modèle appelé (**pas de fallback silencieux**)
3. Compter les tokens des **tentatives échouées/retry** dans le coût réel
4. Appliquer la marge sur le **coût réel calculé**, jamais sur un forfait par type d'action
5. Prévoir le **remboursement d'échec partiel** (ex. 3 tools sur 5 réussis), pas seulement tout-ou-rien
6. Chaque appel de tool du chat est loggé **comme un appel du mode automatique** — pas de logique séparée

---

## 13. Ordre d'implémentation

> 📋 **Le détail complet (tâches, livrables, critères d'acceptation, risques, décisions requises)
> est dans [`PLAN_REFONTE.md`](PLAN_REFONTE.md).** Le tableau ci-dessous en est le résumé.

| Étape | Contenu | Statut |
|-------|---------|--------|
| **R1** | Schéma JSON structurel commun + 3 adaptateurs d'entrée (docx natif, Google Docs, OCR) | ⬜ |
| **R2** | Tool `detect_blocks` (priorité pattern texte) + seuil de confiance + `ask_user_clarification` | ⬜ |
| **R3** | Moteur de gabarit déterministe (dont restylage tableaux) | ⬜ |
| **R4** | Renumérotation automatique + résolution des renvois croisés | ⬜ |
| **R5** | Passe de génération TOC / listes figures / tableaux / annexes / planches | ⬜ |
| **R6** | Chat conversationnel + tools d'édition libre (sur pipeline auto stable) | ⬜ |
| **R7** | Correction du ledger de facturation (parallélisable) | ⬜ |
| **R8** | Excel / CV — **ne pas commencer** avant que R1–R7 soient en production et validés | ⛔ |

---

## 14. Ce que l'agent IA en charge du code ne doit JAMAIS faire

1. Faire générer par un LLM le contenu texte d'un bloc `table`/`paragraph` **existant** sans demande explicite — classification et reformulation sont deux opérations séparées.
2. Utiliser **PHPWord pour lire** un document source — uniquement pour écrire la sortie finale.
3. Générer TOC/listes **avant** la pagination finale.
4. Afficher « identique à 100 % » pour un document issu d'un PDF scanné.
5. Renuméroter une figure/tableau/annexe/planche **sans** tenter la résolution des renvois croisés associés.
6. Bloquer l'utilisateur avec un `ask_user_clarification` pour une résolution de renvoi ambiguë — signalement discret uniquement (§10).
7. Laisser le chat appeler un tool **hors de la liste blanche** (§8).
8. Ré-exporter un document après modification via chat **sans** rejouer gabarit → renumérotation → listes.
9. Exécuter une action destructive du chat (`delete_block`, `regenerate_section`) **sans snapshot préalable**.

---

## 15. Écarts actuels du code (audit 2026-09-11)

### ✅ Déjà conforme

| Élément | Fichier | Note |
|---------|---------|------|
| Écriture DOCX via PHPWord | `app/DocAnalyzer/DocumentReconstructor.php` | Autorisé (§14.2 : sortie finale) |
| Lecture XML native | `DocumentReconstructor` (`DOMDocument`, `ZipArchive`) | — |
| Détection légendes par regex | `app/Services/Detection/LegendDetectionService.php` | Conforme à la règle « jamais de LLM pour une regex » |
| Moteur déterministe sans IA | `TitleDetectionService` (mode regex) | Pipeline hors-ligne préservé |
| Facturation au coût réel | `app/Services/Billing/UsageCostCalculator.php` | Base du ledger §12 |

### ❌ Non conforme (à traiter)

| Écart | Fichier | Section violée |
|-------|---------|----------------|
| **Lecture via PHPWord** | `app/DocAnalyzer/DocumentParser.php` (`use PhpOffice\PhpWord\Element\*`) | §14.2 |
| Absence de JSON structurel commun | `app/Models/DocumentStructure.php` (structure ad hoc) | §5 |
| Absence des tools d'édition | — | §8 |
| Absence de `cross_ref` / renumérotation | — | §10 |
| Listes non générées après pagination | — | §11 |
| Classification sans seuil de confiance formel | `AiCorrectionService` | §6 |
| Pas de `fidelity` (PDF scanné non supporté) | — | §4 |
| Ledger sans échec partiel | `UsageCostCalculator` | §12.5 |

### Concepts absents du code (0 occurrence)

`detect_blocks` · `ask_user_clarification` · `rewrite_paragraph` · `insert_block` ·
`modify_table` · `delete_block` · `regenerate_section` · `cross_ref` · `final_number` ·
`original_number` · `fidelity` · `block_id` · `planche` · `reconstructed`

---

## 16. Références

- `CAHIER_DES_CHARGES.md` — cahier des charges v3.1
- `PLAN_DEVELOPPEMENT.md` — phases 0 à 9 (toutes terminées)
- `copilot-instructions.md` — règles impératives du projet
- `docs-generation-instructions/` — directives PHPWord (page de garde, listes, styles)
- `guide-skills-documentaires-api-claude.md` — skills Claude
