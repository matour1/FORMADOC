---
paths:
  - 'app/Document/Editing/Tools/**'
---

# Tools

## Un tool refuse lui-même ce qu'il ne sait pas faire
Chaque tool doit **refuser lui-même** ce qu'il ne sait pas faire, sans s'en remettre à l'orchestrateur. Un appel direct (test unitaire, commande artisan, autre service) contournerait la validation centrale et produirait un résultat silencieusement faux.

Règles de fond appliquées par les tools :
- `rewrite_paragraph` : paragraphes et titres seulement. Refuse tableaux et légendes (§15 : données chiffrées et numérotation).
- `insert_block` : refuse les types de `TYPES_INTERDITS` (header, footer, cross_ref) — ils ne vivent pas dans le flux du corps. Vérifie `ToolWhitelist::insertableTypes()`.
- `modify_table` : opérations de STRUCTURE uniquement (add_row, add_column, remove_row, set_header). `set_cell` n'écrit que la valeur **fournie par l'utilisateur** — aucune IA n'est appelée dans un tool.
- `delete_block` : refuse le dernier bloc, un porteur décrit par une légende, et une cible de renvoi.
- `regenerate_section` : refuse une plage contenant un bloc à données (tableau, figure, légende).

Les seuils mesurés sur le corpus réel : le numéro est porté par les légendes (861/861), jamais par les porteurs (0/318) — un outil qui touche une légende change donc la numérotation.
