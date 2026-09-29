---
paths:
  - '**'
---

# General

## Jamais de commit direct sur main : toujours une branche + accord avant fusion ou push
Regle du proprietaire du projet (2026-09-25), sans exception.

1. TOUT travail se fait sur une branche dediee. Ne jamais committer directement sur `main`.
2. TOUJOURS demander l'accord explicite avant de :
   - fusionner une branche dans `main` (merge ou fast-forward) ;
   - pousser vers `origin` (push), quelle que soit la branche.
3. « Demander » signifie poser la question et ATTENDRE la reponse. L'absence de
   reponse ne vaut pas accord. Ne pas interpreter un « continue » comme un feu vert
   pour un push ou un merge.
4. Committer sur la branche courante reste libre : c'est l'etape reversible.
   Les deux etapes irreversibles ou visibles par l'equipe sont le merge et le push.

Contexte : plusieurs commits des phases d'administration ont ete pousses directement
sur `main` sans validation prealable. La branche de travail est
`feature/admin-exploitation`.

## Ne jamais RECALCULER une valeur que la base enregistre deja
Un fait enregistre (`cost_usd` vient de la reponse du fournisseur) fait foi. Le
recalculer a partir d'autres colonnes substitue une **hypothese** a un **fait**,
et l'ecart peut etre enorme.

Cas vecu (2026-09-29) : une mesure du cout IA recalculait chaque appel avec
`UsageCostCalculator::costUsdFor($modele, $tokensEntree, $tokensSortie)`. Sur les
appels **echoues**, ce recalcul donnait le prix qu'aurait coute l'appel **s'il
avait abouti** — alors que ces appels avaient echoue au niveau HTTP (certificat
SSL, 402 credits insuffisants, 404 modele inexistant) et n'avaient **jamais ete
factures**. Verdict produit : « les echecs pesent 82,8 % du cout total, fuite de
marge structurelle ». Le vrai chiffre etait **0 %**.

Regle : lire la colonne qui porte le fait. Recalculer n'est legitime que pour
**verifier** une valeur enregistree (et l'ecart doit alors etre rapporte), jamais
pour la remplacer.

## Un taux aberrant accuse d'abord l'instrument, pas le code
100 %, 0 %, 82,8 % : ces ordres de grandeur signalent presque toujours un
instrument faux. Trois cas la meme journee :
- « 100 % des reclassements suspects » — l'audit comparait chaque entree de
  sommaire aux titres restants, donc comptait les pages de frontispice comme
  faux positifs ;
- « 82,8 % du cout en echecs » — l'instrument recalculait un cout inexistant ;
- « 100 % natif, 0 repli » — le script comparait 1 a 1 en ignorant 36 lignes
  hors perimetre.

**Avant de rapporter un taux, se demander ce que l'instrument mesure
reellement.** Un taux trop propre (0 ou 100 %) est plus suspect qu'un taux
intermediaire.

## Verifier la base de mesure avant le chiffre
Compter les FICHIERS au lieu des CONTENUS DISTINCTS surestime d'un tiers sur ce
corpus (2 388 fichiers pour 1 573 contenus). Compter des lignes hors perimetre
(antérieures au choix de moteur, `pipeline = NULL`) fausse aussi bien le
numerateur que le denominateur.

Citer les deux bases quand elles different, et nommer celle qui sert au verdict.
