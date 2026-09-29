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
- **`cost_usd` vient de la réponse du fournisseur : c'est le FAIT.** Le recalculer depuis les tokens ne sert qu'à VÉRIFIER (`recalculabilite()`), jamais à remplacer la valeur — sur les appels échoués (SSL, 402, 404) il inventerait une dépense qui n'a pas eu lieu. Une mesure qui le faisait annonçait « 82,8 % du coût en échecs » alors que le vrai chiffre était 0 %.

## Moyens de paiement : trois états distincts, jamais un seul booléen
`PaymentGatewayRegistry` distingue **configuré** (clés d'API renseignées), **actif** (l'exploitant accepte le moyen, refus PARTOUT) et **visible** (apparaît dans l'interface d'achat). Seul `estProposable()` combine les trois.

**Masquer n'est pas désactiver.** Un moyen actif mais masqué reste utilisable pour les liens négociés sans être proposé en libre-service. Un booléen unique aurait obligé à couper le moyen pour cesser de le proposer — donc à perdre aussi les encaissements en cours.

**Les clés de configuration ne doivent JAMAIS valoir `null`.** `null` est indiscernable d'une clé absente, et `SettingsRepository::applyToConfig()` ignore silencieusement les clés inconnues : le réglage serait affiché, modifiable, et sans effet. L'invariant `SettingsTest::test_chaque_reglage_pointe_vers_une_configuration_existante` le refuse, et il a raison. Mettre un défaut explicite (`true`).

**Un moyen non déclaré n'est pas « sans clé », c'est un moyen inconnu.** `estConfigure()` est une liste blanche : retourner `true` par défaut pour une passerelle absente de la table la rendrait proposable, et le contrôleur la routerait vers la passerelle par défaut. Un client demandant « paypal » serait envoyé chez KPay.

## Monetbil : ce qu'il ne faut PAS copier du SDK officiel
Le paquet `Monetbil/monetbil-php` désactive la vérification SSL (`CURLOPT_SSL_VERIFYPEER, 0`) alors que la requête transporte montants et références de paiement, et configure par propriétés statiques globales (deux paiements simultanés se marchent dessus, aucun test ne peut l'isoler). On interroge l'API via `Http`, qui vérifie les certificats — aucun paquet ajouté.

- **Signature = MD5 du secret suivi des valeurs triées par CLÉ.** Trier par valeur donne une signature toujours différente, et TOUTES les notifications sont rejetées sans message utile.
- **`status = 7` est un SUCCÈS** (mode test), pas seulement `1`. Ne reconnaître que `1` rend la phase de recette impossible.
- **La signature ne porte aucun horodatage** : elle est rejouable telle quelle. La protection contre le double versement repose sur l'idempotence de `PaymentLinkService::regler()`, jamais sur la signature.
- **Le statut ANNONCÉ par une notification n'a aucun effet** : seul `checkPayment()` (appel API) décide.


## Rembourser au prorata sur le coût RÉEL
`CreditService::proportionalRefund()` arrondit vers le bas (`intdiv`) : arrondir au supérieur ferait payer l'app une fraction de crédit à chaque incident.
`ChatController` : la base du remboursement partiel (étape 11b) est `$actualCredits`, PAS l'estimation — l'étape 11 rembourse déjà l'écart estimation/réel, donc reprendre l'estimation rembourse deux fois la même somme.
Le comptage porte sur les appels d'OUTILS (`$toolCallsSucceeded`/`$toolCallsFailed`), pas sur les tours du modèle.

## tool_choice: required n'est pas fiable chez tous les fournisseurs
`deepseek/deepseek-chat` via OpenRouter IGNORE `tool_choice: required` : il répond en texte libre alors qu'un appel d'outil est exigé. Le tour est facturé mais aucune action n'est exécutée (aucun fichier généré, aucun lien de téléchargement). `openai/gpt-4o-mini` honore la contrainte.

`OpenRouterService::chat()` détecte ce cas et lève `ToolChoiceIgnoredException` pour basculer sur le candidat suivant. Le tour reste enregistré en succès dans le registre (pas de `recordFailure` supplémentaire) : le fournisseur l'a bien facturé et a rapporté son usage.

Quand le budget de tours (`tool_loop_max_turns`) est épuisé alors que le modèle demandait encore un outil, un appel de SYNTHÈSE FINAL est effectué sans outils : sinon l'utilisateur ne reçoit qu'un message neutre, sans analyse ni lien de téléchargement.
