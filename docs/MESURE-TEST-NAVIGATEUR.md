# Mesure — test navigateur du 2026-11-01

## Ce qui a été testé

Upload réel de `RAPPORT DE STAGE  la blanche.docx` (877 Ko, 713 blocs) via
l'interface, mode `auto` → moteur `regex`. Document id 3.

## Écart apparent 208 vs 93 : instrument incomplet, pas un bug

Le premier diagnostic annonçait « 208 titres dans le navigateur, 93 en CLI ».
L'instrument CLI (`.docx` → `DocxNativeAdapter::convert()`) mesurait la sortie du
**parseur seul**, pas celle du **pipeline de production**, qui fait passer le
document par `BlockClassifier` ensuite :

```
parseur      93 titres
+ promus     121
- démoteés     6
= classifié  208
```

Les 121 titres ajoutés sont massivement de **vrais titres que le parseur seul
manquait** : `DEDICACE`, `REMERCIEMENTS`, `AVANT-PROPOS`, `RESUME`, `ABSTRACT`,
`INTRODUCTION GENERALE`, `CHAPITRE I : …`, `SECTION I : …`. Le classifieur
**améliore** le résultat ; c'est la mesure qui était partielle.

**Leçon.** Un instrument qui ne traverse pas le même chemin que la production
mesure autre chose que ce qu'on croit mesurer.

## Précision des titres déduits (corpus 21 fichiers, 1 069 → 2 650 titres)

| Confiance | Règle | Nombre | Précision mesurée |
|---|---|---|---|
| 0,82 | ligne isolée + gras | 470 | **25,5 % de faux avérés** |
| 0,65 | numérotation textuelle (`I.`, `1.`) | 228 | ~100 % |

Les faux à 0,82 sont objectivement identifiables : répétés dans le document
(`Source : auteur` ×3, `Exemples :` ×6), finissant par `:`, crédits
`Source : …`, lignes d'horaire. **350 restent plausibles** et sont pour beaucoup
de vrais intitulés de service ou de section.

## Signaux testés pour corriger 0,82 : aucun n'est concluant

| Signal | Couverture | Verdict |
|---|---|---|
| `indentLevel > 0` (puce) | 2,1 % | inexploitable |
| voisin immédiat de même forme | 25,7 % | **rejette aussi de vrais titres** (`Le bon de réservation`) |

**Conclusion : pas de correctif de la règle.** Elle est imparfaite, aucun signal
disponible ne sépare proprement le vrai du faux, et — point décisif — **ces blocs
sont sous le seuil d'acceptation 0,85** : le système ne les affirme pas, il les
propose à confirmation.

## Le vrai défaut trouvé : le compteur affirme ce qui est à confirmer

L'écran de validation titre « **208 titres détectés** ». Or 68 de ces titres sont
sous 0,85 : 54 déductions du classifieur et 14 titres Word à confiance basse.
La même page, cinquante lignes plus haut, propose « Confirmer 254 passages ».
Le compteur présente donc comme établi ce que la page elle-même met en doute.

C'est le défaut le plus gênant pour l'utilisateur : il lit un chiffre, puis on lui
demande de valider autre chose.

### Correctif retenu

Afficher deux nombres au lieu d'un, quand il y a des titres à confirmer :

```
208 titres détectés · dont 140 à confirmer
```

Aucune règle de détection n'est touchée ; seule la **formulation** cesse
d'affirmer plus que la mesure.

## Limite de conception confirmée : page de garde

`meta.cover_page = null` sur ce document, et c'est **correct** : la règle est
`≤ 40 blocs ET ≥ 2 marqueurs ET aucun titre liminaire`. Un rapport de 713 blocs
n'est pas une page de garde — il en **contient** une. La règle actuelle ne sait
détecter qu'un document *qui est* une page de garde.

La page de garde du rapport est donc restée dans le corps, et ses lignes
(`RAPPORT DE STAGE ACADEMIQUE`, `NGOUANE NJOKO`, `OPTION : MARKETING`) sont
comptées comme titres.

Étendre la détection à une **plage de tête** (premiers blocs avant le premier
titre liminaire) est un chantier distinct, non mesuré à ce jour. À ne pas
improviser : supprimer à tort des blocs de contenu est irrécupérable, alors
qu'une page de garde conservée se voit.

## Faux titres observés dans le navigateur, et leur provenance

| Texte | Provenance |
|---|---|
| `RAPPORT DE STAGE ACADEMIQUE` | page de garde non supprimée (limite ci-dessus) |
| `La direction commerciale : Elle a pour mission,` | règle 0,82 (gras + ligne courte), 1 des 25,5 % de faux |
| `SOGECO SARL : près de SOCOPRAL…` | règle 0,82, deux-points + minuscule |
| `Source : document interne de l'entreprise` | règle 0,82, crédit répété |
| `LE DIRECTEUR GENERAL` | règle 0,82 sur un libellé d'organigramme |

Tous sont **sous le seuil 0,85** et apparaissent dans les passages à confirmer.
Aucun n'est appliqué en silence.
