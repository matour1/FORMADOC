---
paths:
  - app/Providers/AppServiceProvider.php
---

# Providers

## Les paramètres optionnels ne sont pas résolus par le container
**Piège d'injection de dépendance, trouvé par un test de câblage.** L'injection automatique de Laravel **renseigne les paramètres optionnels à `null`** au lieu de les résoudre via le container.

`ChatToolsService` a des paramètres optionnels (`structuralEditor`, `claudeSkills`, `textExtraction`). Sans binding explicite, ils arrivaient tous à `null` : les tools d'édition répondaient « non disponible » **en production**, sans aucune erreur visible.

Correction appliquée : `ChatToolsService` est lié explicitement dans `AppServiceProvider::register()`, avec tous ses arguments résolus par `$app->make(...)`. `DocumentEditingService` est un singleton (il porte snapshots et verrou).

Test de garde : `DocumentEditingServiceTest::test_le_chat_recoit_l_editeur_structurel_par_le_container` — il inspecte la propriété par réflexion. Le supprimer rendrait le défaut à nouveau silencieux.

Règle générale : tout service dont un paramètre est optionnel et doit recevoir une vraie implémentation **doit** être lié explicitement.
