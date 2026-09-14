---
paths:
  - app/Document/Adapters/DocxOoxml/TableReader.php
  - app/Document/Adapters/DocxOoxml/XmlLoader.php
---

# Docx Ooxml

## Tableaux Word : contenu intact, fusions gridSpan/vMerge, tblGrid fait foi
**Le contenu des cellules n'est JAMAIS reformulé** (`REFONTE_ARCHITECTURE.md` §15). Un tableau contient des données chiffrées qu'une reformulation corromprait en silence. On lit, on restyle, on ne réécrit pas.

**Fusions — représentation XML contre-intuitive** :
- `w:gridSpan w:val="2"` : la cellule couvre 2 colonnes. Les cellules suivantes de la ligne sont **absentes** de l'XML (elles ne sont pas vides). Ne jamais en déduire que la ligne est incomplète.
- `w:vMerge w:val="restart"` : **démarre** la fusion (sa propre valeur est lue).
- `w:vMerge` sans `w:val` ou `="continue"` : **prolonge** la fusion vers le bas. Son contenu ne doit **pas** être relu (Word affiche celui de la cellule d'origine) — sinon doublon à l'affichage.

**Nombre de colonnes : le `w:tblGrid` est la source de vérité**, jamais la somme des `gridSpan` d'une ligne. Une ligne de titre fusionnée donne un total faux (bug corrigé en R1.2b : `gridSpan=2` sur une seule cellule → total 3 au lieu de 2).

**Cellules** : une cellule contient des **paragraphes** (`w:p`), pas un texte. Concaténer avec `\n`, retirer les paragraphes vides **de fin** uniquement (artefacts de mise en page), et préserver les sauts `w:br` internes. Word fragmente aussi le texte sur plusieurs runs (`« Du »` + `« 01/04/202 »` + `« 6 »`) : toujours concaténer les `w:t`.

**Ordre** : paragraphes et tableaux sont lus dans **une seule boucle** dans `DocxNativeAdapter::readBody()`. Cet ordre d'apparition définit la renumérotation (R4) et la proximité des renvois (R5) — un traitement en deux passes produirait des numéros incohérents.

## mc:AlternateContent duplique les zones de texte — ignorer mc:Fallback
**PIÈGE CRITIQUE : `mc:AlternateContent` contient le contenu DEUX FOIS.**

Word stocke les zones de texte, images ancrées et graphiques dans un bloc `mc:AlternateContent` qui contient :
- `mc:Choice` → forme moderne (`wps:txbx`)
- `mc:Fallback` → forme de compatibilité (`v:textbox` VML)

Un parcours DOM naïf descend dans **les deux** → chaque titre placé dans une zone de texte est lu **en double** (`INTRODUCTION GENERALEINTRODUCTION GENERALE`). 28 documents sur 224 étaient touchés.

**`XmlLoader::collect()` ignore explicitement la branche `mc:Fallback`.** Ne jamais retirer ce garde-fou.

**Autres pièges de ce fichier :**
1. `getElementsByTagName('w:p')` retourne **0 résultat** — compare au nom local, pas au préfixe. Utiliser `XmlLoader::descendants()` / `query()`.
2. `XmlLoader::load()` désactive les entités externes (`LIBXML_NONET`) : un `.docx` utilisateur ne doit jamais pouvoir lire le disque (XXE).
3. Le balisage des révisions (`w:ins`/`w:del`) doit être traversé : le texte inséré fait partie du contenu, le texte supprimé doit être ignoré.

**Unités OOXML** : `w:sz` en demi-points (`halfPointsToPoints`), retraits en twips (`twipsToPoints`), bordures en huitièmes de point (`eighthsToPoints`).
