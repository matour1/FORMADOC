---
paths:
  - 'app/Services/Billing/**'
  - 'app/Console/Commands/BillingReport.php'
  - 'app/Services/OpenRouter/**'
---

# Billing

## Le registre d'usage est la source de vérité du coût
`ai_usage_ledger` enregistre CHAQUE tentative (retry, échec, repli), pas seulement les succès.
- Une ligne perdue ne doit jamais faire échouer l'appel IA : tout échec d'écriture est loggé et ignoré.
- `estimated = true` marque un coût approximé (échec sans réponse) ; ces lignes sont exclues du contrôle `UsageLedger::recalculabilite()`.
- Le coût doit rester recalculable depuis `model` + tokens + `config/openrouter.php` : ne jamais stocker un coût non dérivable des tokens sans passer `estimated`.

## Rembourser au prorata sur le coût RÉEL
`CreditService::proportionalRefund()` arrondit vers le bas (`intdiv`) : arrondir au supérieur ferait payer l'app une fraction de crédit à chaque incident.
`ChatController` : la base du remboursement partiel (étape 11b) est `$actualCredits`, PAS l'estimation — l'étape 11 rembourse déjà l'écart estimation/réel, donc reprendre l'estimation rembourse deux fois la même somme.
Le comptage porte sur les appels d'OUTILS (`$toolCallsSucceeded`/`$toolCallsFailed`), pas sur les tours du modèle.
