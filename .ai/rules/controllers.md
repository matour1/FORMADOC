---
paths:
  - app/Http/Controllers/ChatController.php
  - app/Http/Controllers/PaymentLinkController.php
---

# Controllers

## Remboursement partiel : base = coût réel, comptage = appels d'outils
Le compteur `$toolCallsFailed` se lit sur `! empty($result['error'])` dans l'exécuteur d'outils (étape 9), AVANT la mise à plat du tool_call : le service d'outils simulé/testé reçoit la forme brute `function.name`.
Le service d'outils est simulé : échec/succès dépend du préfixe du nom (`ko_` / `ok_`).
L'ordre des remboursements est important : étape 11 = ajustement estimation→réel, étape 11b = prorata des échecs. Inverser les deux ferait porter le prorata sur l'estimation déjà remboursée.

## La verification de paiement ne depend pas de la queue
Regle du proprietaire : la VERIFICATION d'un paiement ne doit pas dependre des queues ni d'une tache planifiee.

`show()` constate donc en direct (`constaterEnDirect()`) : on interroge la passerelle au chargement de la page, sans attendre le cron `kpay:sync`. Un paiement ne doit jamais rester « en attente » parce qu'un `schedule:run` ne tourne pas.

L'idempotence de `PaymentLinkService::regler()` rend l'interrogation repetee sans danger : elle ne verse jamais deux fois.

Le cron garde son role de FILET pour les liens que personne ne rouvre — il n'est plus le chemin principal.

Limite connue : Monetbil ne peut pas etre constate a l'affichage, son `transaction_id` n'etant connu qu'a l'arrivee de la notification. C'est `notify()` qui constate, et il le fait immediatement.
