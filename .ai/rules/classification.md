---
paths:
  - 'app/Document/Classification/**'
---

# Classification

## Classification : pattern texte prioritaire, contradiction → confiance basse
**Priorité des signaux de classification** (conforme à `REFONTE_ARCHITECTURE.md` §7) :

1. **Pattern texte** (le plus fiable — saisi par l'auteur) : `Figure 1 :`, `1.1 Contexte`, `CHAPITRE 1`
2. **Signaux visuels** : `w:outlineLvl` (niveau de plan), taille de police, gras

**Contradiction style ↔ numérotation → confiance < 0,7 obligatoire.** Cas réel fréquent : un paragraphe en style « Titre 1 » dont le texte est « 1.1 » — le style a été appliqué à la légère. Le niveau retenu suit la **numérotation** (intention de l'auteur), mais la confiance chute à 0,65 pour déclencher `ask_user_clarification` sur CE bloc.

**Garde-fous obligatoires pour éviter les faux positifs** (mesurés : 13,9 % → 7,3 %) :
- longueur maximale de 120 caractères (une phrase n'est pas un titre) ;
- liste noire de civilités (`M.`, `Dr`, `cf.`…) — sinon « M. ZIVO, promoteur » devient un titre niveau 2 ;
- rejet des énumérations finissant par un point (« 1) Le système affiche… » = procédure, pas titre) ;
- les mots-clés de section (`INTRODUCTION`) sont valides **sans** contenu additionnel, contrairement aux numérotations.

**Légendes** : détectées par regex uniquement (règle n°1 du projet). Un faux positif coûteux à éviter : `figure ci-dessous` — exiger que le numéro soit un chiffre ou une **majuscule seule** (`Annexe B` valide, `B` isolé non).

**Budget** : tout ce qui est reconnu ici ne consomme **aucun token**. `detect_blocks` (R2) ne reçoit que les blocs sous 0,85.

## Classification : types structurels intouchables, 66 % de blocs sans LLM
**Enjeu : économie de tokens.** Le `SignalAggregator` classe 66 % des blocs SANS appel LLM (mesuré sur 11 370 blocs réels). Toute modification de ses seuils a un impact budgétaire direct : vérifier avec le script de mesure avant de valider.

**Ordre de priorité des décisions dans `SignalAggregator::assess()`** — l'ordre compte :
1. **Types structurels XML** (`structuralTypeOf`) : un tableau vient d'un `<w:tbl>`, un en-tête d'un `<w:headerReference>`. Le XML est un signal **définitif**, jamais écrasé par une heuristique de texte.
2. **Pattern texte** : `Figure 1 :`, `1.1`, `CHAPITRE II` — saisi par l'auteur, fiabilité maximale.
3. **Style Word** (`w:outlineLvl`).
4. **Signaux visuels** (gras, ligne isolée).

**🔴 Bug grave évité** : reclasser les tableaux par heuristique de texte les faisait **disparaître** de la structure (le texte aplati d'un tableau ressemble à une phrase longue → classé « paragraphe »). Ne jamais retirer `structuralTypeOf()`.

**Autre erreur corrigée** : pénaliser un paragraphe ayant la forme d'une phrase faisait payer un appel IA pour confirmer l'évidence (« ceci est un paragraphe ») → le gain budgétaire tombait de 68 % à 33 %. Un paragraphe évident doit avoir une confiance **haute** (0,9).

**Contradiction style ↔ numérotation → confiance 0,65** : on retient le niveau de la **numérotation** (intention de l'auteur) et on demande une clarification ciblée.

**Jamais bloquant** : sans clé API, en cas d'erreur réseau ou de réponse illisible, la structure déterministe est intégralement conservée. Un test le vérifie avec une clé invalide.

**Clarification ciblée** : une question porte sur **UN bloc** (`document_clarifications.block_id`), jamais sur le document. Une réponse ne corrige **que le bloc visé** — ne jamais propager une décision à des blocs similaires. Une réponse porte la confiance à 1,0 et n'est jamais reposée.

## Détection insensible à la casse : garde-fou obligatoire sur le numéro alphabétique
`CaptionPattern::detectCrossReferences()` est insensible à la casse **sur le mot-clé seulement** (`/iu`), car les auteurs écrivent « la figure 2 ». Conséquence : `[A-Z]` accepte dès lors **toute** lettre, ce qui produit des renvois bidon sur le corpus réel :
- « Tableau n°14 » → renvoi vers un prétendu « Tableau n »
- « TABLEAU D'AMORTISSEMENT » → renvoi vers un « Tableau D »

Garde-fous obligatoires (ne pas les retirer) :
1. valider le numéro alphabétique via `isPlausibleNumber()` — une lettre seule doit être en **majuscule** ;
2. exclure l'apostrophe (droite et typographique) du lookahead : `(?![a-zA-Z\p{L}'’])`.

Le même garde-fou est dupliqué dans `CrossRefRewriter::ancienNumeroDe()` — toute modification doit être appliquée aux deux endroits.
