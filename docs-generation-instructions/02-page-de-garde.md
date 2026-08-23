# 02 – Page de garde (V2 – avec gestion des cas limites)

> Cette V2 ne remplace pas la logique visuelle de la V1 (couleurs, structure générale,
> table de correspondance éléments → technique PHPWord). Elle comble les points qui
> n'étaient pas tranchés et qui sont la source la plus probable d'écarts avec le rendu final.

## Référence visuelle

Inchangé : `premierpage-rapport.pdf`, page de garde A4 type DQP/CQP camerounais.

---

## 1. Clarification de la répartition des 3 logos

`$data` contient `logo_minedop`, `logo_isn`, `logo_reseau`. La V1 ne précisait pas
comment ils se répartissent entre les deux zones visuelles distinctes du document
("en-tête bilangue" et "logos institutionnels"). Règle explicite :

| Logo            | Zone                          | Position                        |
|------------------|-------------------------------|----------------------------------|
| `logo_minedop`   | En-tête bilangue (ligne 1)    | Centre, entre bloc FR et bloc EN |
| `logo_isn`       | Bandeau logos institutionnels (ligne 2, sous l'en-tête) | Gauche |
| `logo_reseau`    | Bandeau logos institutionnels (ligne 2) | Droite |

Ce sont donc **deux tables distinctes empilées**, pas une seule table à 3 colonnes.
Ne pas fusionner les deux lignes même si visuellement elles sont proches : elles n'ont
pas la même largeur de colonnes ni le même rôle sémantique.

---

## 2. Contrainte stricte sur les dimensions des logos

La largeur seule (55–110px) ne suffit pas : sans hauteur fixée ou ratio contrôlé,
des logos aux proportions différentes déforment l'alignement de la ligne.

### Règle

- Fixer **largeur ET hauteur** dans `addImage()`, jamais l'une sans l'autre.
- Les logos doivent être pré-redimensionnés à l'upload / au stockage pour respecter
  un ratio proche de 1:1 (carré) ou une hauteur commune, selon la zone :
  - En-tête (`logo_minedop`) : hauteur cible **~70px**, largeur proportionnelle bornée à 90px max.
  - Institutionnels (`logo_isn`, `logo_reseau`) : hauteur cible identique pour les deux, **~60px**.
- Si un logo fourni dépasse le ratio attendu de plus de 20 %, logguer un avertissement
  côté génération (pas seulement fallback texte si absent — aussi signaler si "présent
  mais visuellement disproportionné").

```php
$targetHeight = 60; // px, zone institutionnelle
$cell->addImage($path, [
    'width'  => $targetHeight * $ratioLargeurHauteur,
    'height' => $targetHeight,
]);
```

---

## 3. Gestion du texte variable (le vrai point faible de la V1)

La V1 ne traitait que le cas "logo manquant". Le cas le plus probable en production
est un **texte plus long que prévu** : titre de projet sur 2-3 lignes, nom d'encadreur
+ rôle qui wrap, spécialité à rallonge.

### Décisions

- **Titre principal** : toujours dans une **Table 1 cellule avec bgColor**, jamais
  une TextBox. Une TextBox a une hauteur fixe ou mal gérée en auto-fit selon les
  moteurs de rendu (Word vs LibreOffice) ; une cellule de table grandit proprement
  avec le contenu.
- **Longueur du titre** : si `strlen($data['titre']) > 70`, réduire automatiquement
  la taille de police du bandeau titre (ex. 16pt → 13pt) plutôt que de laisser le
  texte déborder ou wrapper de façon incontrôlée sur 3+ lignes.
- **Bloc auteur / encadreurs** : chaque cellule doit avoir `'valign' => 'top'` et
  pas de hauteur de ligne fixe imposée, pour que les deux colonnes restent alignées
  en haut même si l'une contient plus de texte que l'autre (ex. encadreur pro avec
  rôle long vs encadreur académique avec rôle court).
- **Rôles d'encadreurs** (`encadreur_academique_role`, `encadreur_pro_role`) : prévoir
  qu'ils peuvent être vides. Ne pas laisser une ligne vide moche — conditionner
  l'affichage :

```php
if (!empty($data['encadreur_pro_role'])) {
    $cell->addText($data['encadreur_pro_role'], ['italic' => true, 'size' => 9]);
}
```

### Cas explicitement non géré (à assumer, pas à ignorer en silence)

Un titre de plus de ~120 caractères cassera quand même la mise en page. Ce n'est
pas résolu par du code — c'est une limite fonctionnelle. **Décision à documenter dans
l'interface utilisateur** (ex. compteur de caractères + avertissement au moment de la
saisie du titre), plutôt que de laisser PHPWord produire un rendu dégradé silencieux.

---

## 4. Le ruban "année académique" n'est PAS un vrai footer — à traiter comme tel

Rappel architecture (`01-architecture.md`) : la section 1 (page de garde) n'a pas
de header/footer classique. Le ruban bleu en bas n'est donc **pas ancré physiquement
en bas de page** : c'est le dernier bloc du flux de contenu, sa position verticale
réelle varie selon la longueur du contenu au-dessus.

### Ce qu'on ne fait pas (trop fragile)

- Ne pas essayer de calculer un espacement dynamique en pixels/twips pour "pousser"
  le ruban en bas de page. C'est fragile entre Word et LibreOffice et dépend de la
  résolution de police du poste utilisateur.

### Ce qu'on fait à la place

- **Contraindre la variance en amont** plutôt que corriger en aval : en limitant
  strictement les longueurs de texte (règle §3) et en fixant des tailles de police
  non négociables pour chaque bloc, la hauteur totale du contenu au-dessus du ruban
  devient quasi-constante (variation de l'ordre de 1-2 lignes max, pas de pages entières).
- Documenter explicitement que le ruban est "en bas du contenu", pas "en bas de la
  page physique" — et l'assumer comme compromis. Le mentionner dans le README du
  dossier pour que personne ne redécouvre ce comportement en production en pensant
  que c'est un bug.

---

## 5. Cas de test spécifiques à la page de garde

En complément des tests génériques de `05-integration-code.md` ("ouvrir dans Word",
"ouvrir dans LibreOffice"), la page de garde a besoin de ses propres jeux de données :

| Cas de test                                   | Ce qu'on vérifie                                  |
|------------------------------------------------|-----------------------------------------------------|
| Titre très long (>100 caractères)              | Réduction de police, pas de débordement du bandeau  |
| `encadreur_pro_role` vide                       | Pas de ligne vide visible, alignement colonnes OK   |
| `logo_isn` absent + titre long (cumul)          | Fallback texte + réduction police simultanés propres|
| Logo au mauvais ratio (ex. logo très large/plat)| Pas de déformation visuelle                         |
| Toutes données minimales (juste les champs requis) | Rendu encore présentable, pas "cassé"           |

Ces 5 cas doivent faire partie d'un jeu de fixtures versionné (pas juste testés
manuellement une fois), car la page de garde est justement le point identifié comme
le plus sensible visuellement.

---

## Résumé des changements par rapport à la V1

- Répartition claire des 3 logos entre 2 zones distinctes (au lieu d'une ambiguïté).
- Contrainte de ratio/hauteur sur les logos (au lieu de largeur seule).
- Stratégie explicite de gestion des textes longs (titre, rôles), au lieu de
  supposer implicitement des données "raisonnables".
- Le ruban de fin de page est traité comme ce qu'il est réellement (pas un footer),
  avec une stratégie de réduction de variance plutôt qu'un calcul de position illusoire.
- Ajout de cas de test dédiés à la page de garde, absents de la V1.