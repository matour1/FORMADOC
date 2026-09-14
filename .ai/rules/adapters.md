---
paths:
  - 'app/Document/Adapters/**'
---

# Adapters

## Lire l'OOXML : jamais getElementsByTagName, toujours localName/XPath
**Piège majeur : `getElementsByTagName('w:p')` retourne 0 résultat en PHP.**

Contrairement à ce que le nom suggère, `DOMDocument::getElementsByTagName()` compare au **nom local** de l'élément, jamais au préfixe qualifié. Sur un document Word (où tout est en `w:`), un parseur qui l'utilise ne lit **rien du tout** — sans erreur, silencieusement. Le bug a coûté 100 % des conversions lors de la première implémentation.

**Toujours** passer par `XmlLoader` :
- `XmlLoader::childElements()` / `firstChild()` / `descendants()` — parcours comparant `localName`
- `XmlLoader::query($doc, '//w:tbl')` — XPath avec namespaces enregistrés (`w`, `r`, `a`, `wp`, `v`, `w14`)

Autres pièges d'unité OOXML (helpers fournis) :
- `w:sz` est en **demi-points** → `halfPointsToPoints()` (32 = 16 pt)
- retraits/espacements en **twips** → `twipsToPoints()` (1 pt = 20 twips)
- bordures de tableau en **huitièmes de point** → `eighthsToPoints()`

**Sécurité** : `XmlLoader::load()` désactive les entités externes (LIBXML_NONET). Un `.docx` fourni par un utilisateur ne doit jamais pouvoir lire le disque du serveur (XXE).

**Résolution des relations** : les cibles sont relatives au dossier de la partie source (`Target="header1.xml"` depuis `word/document.xml` → `word/header1.xml`). `PackageReader::relationsOf()` gère les chemins absolus (`/word/...`) et les remontées (`../`).
