---
paths:
  - 'app/Document/**'
  - app/Document/DocumentPipeline.php
---

# Document

## Le nouveau pipeline documentaire n'utilise jamais PHPWord en lecture
`app/Document/**` est le pipeline de la refonte (voir `REFONTE_ARCHITECTURE.md` et `PLAN_REFONTE.md`).

Règle absolue : **aucune référence à PHPWord/PhpOffice** dans ce namespace. PHPWord ne sert qu'à ÉCRIRE la sortie finale (il reste dans `app/DocAnalyzer/DocumentReconstructor.php`). La lecture d'un `.docx` passe par un parseur OOXML natif (ZipArchive + DOMDocument).

Le test `tests/Unit/Document/ArchitectureConstraintsTest.php` verrouille cette règle et échoue si elle est violée.

Toute nouvelle classe de ce namespace doit être dans un module attendu : `Adapters`, `Classification`, `Clarification`, `Editing`, `Exceptions`, `Formatting`, `Lists`, `Numbering`, `Structure`.

## Invariants du schéma structurel (immuabilité, confinement IA, numérotation)
Règles durables du pipeline documentaire (refonte) :

1. **`Block` est immuable** — toute mutation passe par `with*()` qui renvoie une nouvelle instance. C'est ce qui rend les snapshots du chat (§9) sûrs, sans aliasing.
2. **`TableData` conserve le contenu EXACT des cellules** — l'IA ne reformule jamais un tableau existant (§14.1). Les fusions (`gridSpan`, `vMerge`) sont préservées.
3. **Sous `confidence < 0.85`** → `ask_user_clarification` **ciblé sur ce bloc uniquement**, jamais sur le document. Seuls les blocs ambigus partent au LLM (économie de tokens).
4. **`Fidelity::Reconstructed`** (PDF scanné) → aperçu avant/après systématique **même à confiance 0.999**. Le libellé interdit toute promesse d'identité (§14.4).
5. **`original_number` n'est jamais fiable** (trous, doublons) → le système renumérote par ordre d'apparition dans `final_number`, avec un compteur indépendant par catégorie.
6. **Renvoi croisé ambigu** → résolution par **proximité dans l'ordre du document** (via `StructuralDocument::indexOf`), jamais par distance en caractères. Un `resolution_confidence < 0.7` est **signalé discrètement**, jamais bloquant.
7. **Toute sortie de tool est validée** par `StructuralSchemaValidator` avant d'être appliquée — rejet + retry si invalide.
8. **Un document`StructuralDocument` ne peut pas produire un schéma invalide** (testé) : les invariants sont dans les constructeurs.

## Feature flag du pipeline : un échec natif ne perd jamais un document
**Orchestrateur de coexistence** entre l'ancien pipeline (`app/DocAnalyzer`, lecture PHPWord) et celui de la refonte (`app/Document`, parseur OOXML natif).

**Feature flag `DOCUMENT_PIPELINE_V2`** (`config/document.php`), trois positions :
- `false` (défaut) → ancien pipeline seul. **Le défaut reste l'ancien** tant que la migration n'est pas validée ;
- `true` → nouveau pipeline seul, les échecs remontent (diagnostic) ;
- `auto` → nouveau pipeline avec **repli silencieux** : un échec retourne `null` et l'ancien fait foi.

**Règle absolue : un échec du pipeline natif ne doit JAMAIS faire perdre un document.**
Dans `runDetection()`, l'échec est loggé et absorbé — jamais propagé. Perdre le document d'un utilisateur est bien plus grave qu'un parseur défaillant. Un test d'intégration le vérifie avec un vrai fichier corrompu (`DocumentPipelineIntegrationTest`).

**Cohabitation (strangleur)** : `document_structures.structure` (ancien) ET `structural_json` (nouveau) sont écrits **en parallèle**. La colonne historique n'est jamais supprimée avant validation en production — le retour arrière reste possible. `schema_version` permet de détecter une structure écrite par une version antérieure du code ; `pipeline` trace qui a traité le document.

**Défauts sûrs** : `persist_structural=true` mais `pipeline.v2=false` → le JSON n'est écrit que si on active explicitement le nouveau pipeline.
