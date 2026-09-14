---
paths:
  - app/Providers/AppServiceProvider.php
---

# Providers

## Les paramètres optionnels ne sont pas résolus par le container
**Piège d'injection de dépendance, trouvé DEUX FOIS par des tests de câblage.** L'injection automatique de Laravel **renseigne les paramètres optionnels à `null`** au lieu de les résoudre via le container.

Cas 1 — `ChatToolsService` : paramètres optionnels `structuralEditor`, `claudeSkills`, `textExtraction`. Sans binding explicite, ils arrivaient tous à `null` : les tools d'édition répondaient « non disponible » **en production**, sans aucune erreur visible.

Cas 2 — `OpenRouterService` : paramètre optionnel `$ledger` (`UsageLedger`). Il est optionnel **par nécessité** — le compilateur PHP interdit qu'un paramètre obligatoire suive un paramètre optionnel, donc `turnsCost` puis `ledger` ne peuvent qu'être optionnels. Sans binding, aucune ligne du registre R7 n'était écrite en production, sans erreur levée : la facturation redevenait approximative.

**Le même symptôme dans les deux cas** : aucun test unitaire ne le voit, parce qu'ils injectent la dépendance à la main. La boucle « ça passe en test mais pas en production » est donc structurelle, pas accidentelle.

Correction appliquée : `ChatToolsService` et `OpenRouterService` sont liés explicitement dans `AppServiceProvider::register()`, tous leurs arguments résolus par `$app->make(...)`. `DocumentEditingService` est un singleton (il porte snapshots et verrou).

`OpenRouterService` est lié par `bind()` et non `singleton()` : il porte un état d'appel (coûts par tour, compteur de tentatives, contexte de facturation). Un singleton partagerait cet état d'un appel à l'autre.

### Règle générale
Tout service dont **un paramètre est optionnel** et doit recevoir une vraie implémentation **doit** être lié explicitement. Ne pas se fier au fait que « ça marche » : vérifier par un test de garde qui résout via `$this->app->make(...)` et inspecte la propriété par réflexion.

Tests de garde : `DocumentEditingServiceTest::test_le_chat_recoit_l_editeur_structurel_par_le_container` et `UsageLedgerTest::test_le_service_openrouter_recoit_le_registre_par_le_container`. Les supprimer rendrait les défauts à nouveau silencieux.
