---
paths:
  - app/Document/Structure/LegacyStructureBridge.php
---

# Structure

## Bridge legacy : format catégoriel, restaurer l'ordre de lecture
**Enjeu** : 35 structures de documents en base au format historique (`document_structures.structure`). Sans ce pont, brancher le nouveau pipeline invaliderait les analyses déjà validées par les utilisateurs (décision D6).

**Trois formats historiques coexistent** — le bridge doit les gérer tous :
- `titles` (Markdown généré par l'IA) → artefact d'affichage, **non convertible**. Seules les `legends` sont exploitables → émettre un avertissement.
- `titres`/`sous_titres`/`tableaux`/`images`/`legends` → conversion complète.
- + `body_complet` → conversion complète avec ordre de lecture.

**Le format historique est CATÉGORIEL, pas séquentiel.** Les blocs y sont rangés par type (tous les titres ensemble, toutes les légendes ensemble). L'ordre réel n'existe que dans `position.element_index` (et `line` pour les légendes). **`restoreReadingOrder()` est indispensable** : sans lui, une légende se retrouve après toutes les images, et la renumérotation (R4) produit des numéros incohérents.

**Tri STABLE obligatoire** : plusieurs blocs partagent souvent la même position (un titre et son tableau). Un `usort` non stable mélangerait l'ordre.

**Limite connue** : le format `structure` (11 enregistrements) ne stocke aucune position de légende → ordre approximatif. **Émettre un avertissement, ne jamais inventer un ordre.**

**Ne jamais lever d'exception** sur une structure vide : rendre un document inaccessible est pire que de le signaler (`meta.conversion_warnings`).

**`toLegacy()` sert la transition** : il permet au `DocumentReconstructor` existant de continuer à fonctionner, garantissant que les tests restent verts pendant la migration (strangleur). Convention à respecter : niveau 1 → `titres`, niveaux 2+ → `sous_titres`, et les légendes **ne doivent pas** être dupliquées dans `body_complet`.
