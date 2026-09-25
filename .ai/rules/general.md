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
