---
paths:
  - app/Services/OpenRouter/OpenRouterService.php
---

# Open Router

## Une ligne de registre par tour HTTP
Une ligne du registre est écrite par TOUR HTTP (appel initial + chaque tour d'outil), jamais une seule ligne cumulée pour tout l'appel : le total doit rester la somme des lignes.
`httpAttempts` est remis à zéro à chaque modèle candidat — sinon un échec du modèle précédent gonflerait le nombre de tentatives attribuées au modèle qui répond.
`lastInputCharCount` est mémorisé AVANT l'envoi : en cas d'échec, la requête n'est plus disponible pour estimer les tokens d'entrée facturés.
`retry()` (Laravel 13) : le callback `when` reçoit `($exception, $request, $method)`, PAS `($attempt, $exception)`.
