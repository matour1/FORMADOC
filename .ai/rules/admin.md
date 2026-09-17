---
paths:
  - 'app/Http/Controllers/Admin/**'
---

# Admin

## Espace admin : accès et lecture seule
Accès protégé par `auth` PUIS `admin` (alias de `AdminMiddleware`) — l'ordre compte : un invité est redirigé vers le login (réponse utile), un utilisateur connecté sans droits reçoit **404 et non 403** (un 403 confirmerait l'existence de l'espace).

`is_admin` sur `users` : booléen, **défaut `false`**, indexé. Aucun système de rôles — le besoin est binaire ; plusieurs niveaux se modéliseront le jour où ils apparaîtront.

**Tous les écrans sont en LECTURE SEULE** : agir sur les données d'un utilisateur depuis l'admin exige une décision explicite.

**Réutiliser `UsageLedger`, pas des requêtes Eloquent directes** : c'est ce qui garantit que l'écran et la commande `billing:report` affichent les MÊMES totaux. `AdminController::totauxGlobaux()` réplique l'agrégation de `UsageLedger::agreger()` — si l'une change, l'autre doit suivre.

Le layout `layouts/admin.blade.php` est SÉPARÉ de `layouts.app` (navigation d'exploitation ≠ navigation utilisateur). ⚠️ Il doit utiliser les entrées Vite existantes (`resources/css/app.css`, `resources/js/app.js`) : une entrée inexistante lève `ViteException: Unable to locate file in Vite manifest` (erreur rencontrée, 500 sur tous les écrans).

Test de garde : `AdminAccessTest::test_le_middleware_admin_est_bien_applique_a_toutes_les_routes` vérifie le GROUPE, pas seulement les routes connues — une route admin ajoutée sans middleware exposerait les données de tous les utilisateurs.
