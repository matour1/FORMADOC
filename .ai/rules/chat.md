---
paths:
  - 'app/Services/Chat/**'
---

# Chat

## Chat : deux familles d'outils (pièce jointe vs document analysé)
Deux familles d'outils coexistent, et les confondre fait échouer chaque appel :
- **`document_edit`** (historique) agit sur une **pièce jointe** de la conversation, désignée par `source_path`.
- **Les tools d'édition R6** (`rewrite_paragraph`, `insert_block`, `modify_table`, `delete_block`, `regenerate_section`, `undo_last_action`) agissent sur un **document analysé et persisté**, désigné par `document_id`.

Le routage se fait dans `ChatToolsService::execute()` : les tools de R6 sont traités **avant** le `match` général, via `ToolWhitelist::allows()`. Un tool hors liste blanche reçoit un refus explicite (§9.14) plutôt qu'un « outil inconnu ».

Les schémas OpenAI sont dans `EditToolSchemas`, **dérivés de `ToolWhitelist`** : la liste des noms et l'énumération des types insérables (`BlockType::insertable()`) viennent de la source de vérité, donc aucune divergence n'est possible. Ajouter un tool au `match` de `ChatToolsService` sans l'inscrire dans `ToolWhitelist` le rendrait inaccessible.

`DocumentEditingService` est le **seul** endroit du module qui touche la base : il charge le JSON structurel, applique l'édition, persiste. Persister sans mettre à jour `schema_version` **et** `pipeline` ferait croire à une rétrogradation du document au prochain chargement.
