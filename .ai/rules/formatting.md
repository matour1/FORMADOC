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
