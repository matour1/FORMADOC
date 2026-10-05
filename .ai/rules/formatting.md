---
paths:
  - 'app/Document/Formatting/**'
---

# Formatting

## Format exact attendu par DocumentReconstructor
Le pont vers DocumentReconstructor doit produire ces clés EXACTES. Une clé erronée n'échoue pas : elle produit un document incomplet en silence.
- `analysis.legends` (anglais, pas `legendes`) avec `type` / `number` / `label` — sinon listes de figures vides.
- Tableau : `element['rows'][]['cells']` — un `table_data` à plat donne un tableau vide sans erreur.
- Image : `element['image_data']` (base64) + `image_name` + `image_extension` — un nom seul perd l'image.
- Titre : `element['depth']` plafonné à 1–3.
- `legends[]['label']` SANS préfixe de numéro (le reconstructeur recompose « Figure 3 : label »).

## Gabarit : une valeur vide ne doit jamais écraser le défaut
Règle de conception : `TemplateEngine::normalizeTemplate()` fusionne les clés une par une et IGNORE toute valeur vide (chaîne vide ou espaces). Un champ de formulaire laissé vide produirait sinon `police = ''` et un rendu invalide — bug trouvé par test, pas par relecture.
Corollaire : toute nouvelle clé de gabarit doit suivre ce même traitement (vide → valeur par défaut).

## Les diagrammes en formes sont PRESERVES, jamais interpretes
`ShapePreserver` PRESERVE les diagrammes en formes vectorielles (`wps:wsp`, `wpg:wgp`) au lieu de les perdre. Mesure : 4 contenus du corpus sur 1722, mais 285 formes et 250 zones de texte dont le texte n'est lu par AUCUN chemin.

Defaut d'origine : `ParagraphReader` traite `w:drawing` comme une image sans distinguer un bitmap d'une forme. Une forme n'ayant aucune relation d'image (`r:embed`), le paragraphe qui la porte n'a ni texte ni image, donc `read()` retournait `null` : le diagramme disparaissait entierement, sans erreur.

Chaine complete, a ne pas casser :
1. `ParagraphReader` pose `has_shape` (`contientUneForme()`) — tester la FORME AVANT l'image, car `imageName()` retourne le repli `embedded-object` qui rend `has_image` vrai ;
2. `DocxNativeAdapter::toBlock()` produit `BlockType::Shape` ;
3. `ReconstructionPayloadBuilder` ecrit `type => 'forme'` avec `ShapePreserver::MARQUEUR` ;
4. `DocumentReconstructor::writeElement` ecrit le marqueur (cas `'forme'`) ;
5. `DocumentReconstructor::avecSource()` doit etre appele A CHAQUE export — le reconstructeur est un singleton, sinon un export reutilise le chemin du precedent ;
6. `ShapePreserver::reinjecter()` s'execute APRES `applyPageNumberingFormats` et `applyGridSpan` (les trois reecrivent document.xml).

**Le point critique** : `reinjecter()` retire les marqueurs MEME sans forme a placer. Un marqueur non remplace serait un texte technique VISIBLE dans le document livre — pire que l'absence du diagramme.

Choix assume : recopier les formes a l'octet pres, ne PAS les analyser ni les convertir en image (irreversible).
