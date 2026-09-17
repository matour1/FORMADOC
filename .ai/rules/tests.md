---
paths:
  - 'tests/**'
---

# Tests

## Fake the network on throttled routes, or perMinute expires
Un test qui POSTe sur une route throttlee joignant un service externe (`credits.purchase` -> KPay) DOIT appeler `Http::fake()`.

`Limit::perMinute(5)` a une fenetre de 60 s. Chaque requete non fakee attend le timeout reseau (30 s) plus ses reprises, soit ~90 s : a la 6e iteration les compteurs de la 1re sont deja decrementes, la fenetre est vide et la reponse est 302 au lieu de 429.

Symptome : le test est VERT ou ROUGE selon la latence du reseau, et tres lent (583 s mesure). Le limiteur est correct — c'est l'instrument qui ne mesure plus rien.
