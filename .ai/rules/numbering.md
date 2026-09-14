---
paths:
  - 'app/Document/Numbering/**'
---

# Numbering

## Un élément numéroté ne compte qu'une fois : la légende compte, le porteur reflète
Un élément numéroté = 2 blocs (porteur `figure`/`table` + légende `caption`), mais **UN SEUL numéro**.
Mesure du corpus (526 docs) : le numéro vit sur la **légende** (861/861) et JAMAIS sur le porteur (0/318). `linked_block_id` est vide partout avant R4.

Règle unique à respecter dans toute la numérotation :
- **la légende compte** (elle porte le numéro, elle est visible à sa place) ;
- **le porteur reflète** le numéro de sa légende, sauf s'il n'a aucune légende (il compte alors lui-même).

Interdit : faire compter le porteur quand une légende le représente. Sans quoi un tableau et sa légende reçoivent chacun « 1 », « 2 »… → deux séries de numéros pour les mêmes éléments, et un défaut d'**idempotence** (un porteur peut avoir plusieurs légendes : la « première » changeait d'une passe à l'autre — 24 documents affectés).
