---
paths:
  - 'app/Document/Editing/**'
  - app/Document/Editing/ReExportCoordinator.php
---

# Editing

## Module Editing : l'interface EditTool reste hors de Tools/
L'interface `EditTool` vit dans `app/Document/Editing/EditTool.php`, **jamais** dans `Tools/`.
Raison : `ArchitectureConstraintsTest::test_les_tools_d_edition_sont_contenus_dans_un_seul_repertoire` vérifie que `Tools/` contient EXACTEMENT les 5 tools exposés, et que `ToolWhitelist` correspond au répertoire. Y placer une interface (ou tout helper) fait échouer la suite.

Ordre du §9, imposé et non négociable (il transforme une erreur silencieuse en erreur explicite) :
1. liste blanche → 2. validation des arguments → 3. confirmation si action large → 4. verrou → 5. snapshot (si destructif) → 6. exécution → 7. validation du résultat → 8. renumérotation si le comptage a changé.

Seuils de confirmation (§9.16) : `delete_block` et `regenerate_section` au-delà de **5 blocs** ; les tools non destructifs n'en demandent jamais. Le verrou est structurel (contrainte d'unicité sur `document_edit_locks.document_id`), donc non contournable.

## Ré-export : l'ordre gabarit → renumérotation → listes est imposé
L'ordre du ré-export est **imposé** : `gabarit` → `renumerotation` → `listes` (voir `expectedOrder()`, vérifié par test).

Pourquoi cet ordre et pas un autre :
- **renumérotation avant listes** : la table des matières cite les numéros recalculés. L'inverse produirait une TOC pointant vers des numéros qui n'existent plus — défaut **invisible** dans les métadonnées, visible seulement à la lecture.
- **listes en dernier** : `RenderCoordinator::generate()` exige d'avoir été paginé, et la pagination se lit sur le document **final** (gabarit + numérotation appliqués).

`numbering.per_category` est une clé **imbriquée** (sortie de `NumberingCoordinator::run()` — attention à la forme, une lecture à la racine lève `Undefined array key`).

Sans chemin de pagination (`$docxPath = null`), les listes ne sont pas produites : le ré-export reste utile (gabarit + numérotation), et le résumé le signale explicitement. Avec `['require_pagination' => true]`, l'échec est remonté dans `error` plutôt que de produire des numéros faux.
