---
paths:
  - app/Http/Controllers/ChatController.php
---

# Controllers

## Remboursement partiel : base = coût réel, comptage = appels d'outils
Le compteur `$toolCallsFailed` se lit sur `! empty($result['error'])` dans l'exécuteur d'outils (étape 9), AVANT la mise à plat du tool_call : le service d'outils simulé/testé reçoit la forme brute `function.name`.
Le service d'outils est simulé : échec/succès dépend du préfixe du nom (`ko_` / `ok_`).
L'ordre des remboursements est important : étape 11 = ajustement estimation→réel, étape 11b = prorata des échecs. Inverser les deux ferait porter le prorata sur l'estimation déjà remboursée.
