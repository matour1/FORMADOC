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

## Un style de LISTE n'est pas un style de SOMMAIRE
`list paragraph` est le style que Word applique à **toute** liste à puces ou numérotée. `toc 1`…`toc 9`, `table of contents`, `table of figures` sont les styles qu'un sommaire **généré** porte.

Les confondre retire le contenu listé du document. Mesure (2026-09-29, `fn7Ze5U5…docx`) : consommer `isListStyle()` comme signal de sommaire a reclassé **175 paragraphes de puces** en entrées de sommaire — « Promouvoir les entreprises locales… », « La Radio ; », toute la liste du corps.

`StyleReader` porte donc deux méthodes et **une seule autorise une décision** :

| Méthode | Répond pour | Usage |
| --- | --- | --- |
| `tocStyleLevel()` | sommaire seulement | **seule à autoriser le reclassement** en `BlockType::TocEntry` |
| `listStyleLevel()` / `isListStyle()` | sommaire **et** puce | diagnostic, traçabilité |

`ParagraphReader` expose les deux signaux : `is_toc_style` (décision) et `is_list_style` (diagnostic).

**Un signal correct mais jamais consommé devient un piège.** `list paragraph` figurait dans la liste depuis l'origine et était inoffensif tant que `is_list_style` n'était lu nulle part. Le brancher a transformé un signal mort en régression. Avant de consommer un signal dormant, mesurer ce qu'il contient réellement — sa définition d'origine n'a jamais été éprouvée.

## Ne jamais écrire une alternance dont une branche ne capture pas le groupe attendu
`'/[\t]|[\x{00A0}]| {2,}(\d+)$/u'` : les deux premières branches matchent un blanc **sans numéro**, donc `$m[1]` est indéfini → `Undefined array key 1` faisait échouer la conversion du document entier.

Écrire la séparation en groupe non capturant et le numéro en groupe 1 :
`'/(?:\t|\x{00A0}| {2,})(\d{1,3}|[ivxlcdmIVXLCDM]{1,6})\.?$/u'`.

## Deux chemins vers un même type de bloc doivent produire le même texte
`BlockType::TocEntry` a **deux** producteurs dans `DocxNativeAdapter` : le reclassement par **style** (`tocStyleLevel`) et le reclassement par **contenu** (`reclasserEntreesSommaire`). Le second retirait le numéro de page, le premier non.

Conséquence : une entrée `toc 1` gardait `"CHAPITRE I : PRESENTATION\t2"`, et ce numéro de page se retrouvait **recopié dans le sommaire généré** — le défaut même que le branchement existait pour corriger.

**Un type de bloc partagé par plusieurs producteurs est un point de divergence.** Quand on en ajoute un, aligner tous les chemins sur la même transformation (`sansNumeroDePage()` ici), et le tester **au niveau de l'adaptateur** — pas seulement sur les composants intermédiaires. Un `StyleReader` correct branché à un usage incomplet produit quand même un bloc faux.
