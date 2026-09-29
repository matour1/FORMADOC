---
paths:
  - 'app/Services/Billing/**'
  - app/Console/Commands/BillingReport.php
  - 'app/Services/OpenRouter/**'
  - 'app/Console/Commands/**'
---

# Billing

## Règle métier du propriétaire : tout token dépensé est facturé, SAUF les échecs d'outils
Décision explicite du propriétaire (2026-09-29), qui tranche l'ambiguïté sur les échecs :

1. **Tout token dépensé pendant l'exécution est facturé.** Un tour d'appel d'outil
   intermédiaire, un retry, une bascule de fournisseur, un échec de modèle : tous
   consomment des tokens que le fournisseur facture. Les ignorer sous-estimerait le
   coût précisément pendant les périodes d'instabilité, quand les retries explosent.
2. **Seuls les ÉCHECS D'OUTILS sont remboursés**, au prorata sur le coût RÉEL
   (`CreditService::refundPartial()`, étape 11b de `ChatController`). Un outil qui
   échoue n'a produit aucune valeur pour l'utilisateur : le facturer reviendrait à
   vendre un service non rendu. Remercier tout serait une perte ; ne rien rembourser
   serait un abus.
3. **Les échecs du MODÈLE ne sont PAS remboursés** — ils restent facturés (point 1).
   Distinguer les deux est essentiel : « échec » seul est ambigu et a déjà mené à des
   mesures fausses.

La distinction est portée par le CODE, pas par le libellé : `$toolCallsFailed` compte
les appels d'outils en échec (`! empty($result['error'])`), tandis que les échecs du
modèle sont enregistrés en `succeeded = false` dans `ai_usage_ledger` et restent dans
le coût.

## Le registre d'usage est la source de vérité du coût
`ai_usage_ledger` enregistre CHAQUE tentative (retry, échec, repli), pas seulement les succès.
- Une ligne perdue ne doit jamais faire échouer l'appel IA : tout échec d'écriture est loggé et ignoré.
- `estimated = true` marque un coût approximé (échec sans réponse) ; ces lignes sont exclues du contrôle `UsageLedger::recalculabilite()`.
- Le coût doit rester recalculable depuis `model` + tokens + `config/openrouter.php` : ne jamais stocker un coût non dérivable des tokens sans passer `estimated`.

## Rembourser au prorata sur le coût RÉEL
`CreditService::proportionalRefund()` arrondit vers le bas (`intdiv`) : arrondir au supérieur ferait payer l'app une fraction de crédit à chaque incident.
`ChatController` : la base du remboursement partiel (étape 11b) est `$actualCredits`, PAS l'estimation — l'étape 11 rembourse déjà l'écart estimation/réel, donc reprendre l'estimation rembourse deux fois la même somme.
Le comptage porte sur les appels d'OUTILS (`$toolCallsSucceeded`/`$toolCallsFailed`), pas sur les tours du modèle.

## tool_choice: required n'est pas fiable chez tous les fournisseurs
`deepseek/deepseek-chat` via OpenRouter IGNORE `tool_choice: required` : il répond en texte libre alors qu'un appel d'outil est exigé. Le tour est facturé mais aucune action n'est exécutée (aucun fichier généré, aucun lien de téléchargement). `openai/gpt-4o-mini` honore la contrainte.

`OpenRouterService::chat()` détecte ce cas et lève `ToolChoiceIgnoredException` pour basculer sur le candidat suivant. Le tour reste enregistré en succès dans le registre (pas de `recordFailure` supplémentaire) : le fournisseur l'a bien facturé et a rapporté son usage.

Quand le budget de tours (`tool_loop_max_turns`) est épuisé alors que le modèle demandait encore un outil, un appel de SYNTHÈSE FINAL est effectué sans outils : sinon l'utilisateur ne reçoit qu'un message neutre, sans analyse ni lien de téléchargement.
