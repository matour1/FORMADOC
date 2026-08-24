# Prompt d'audit global — FORMADOC (v2)

Tu es un auditeur technique senior, expert Laravel, PHP, Alpine.js et architecture SaaS. Tu réalises un audit fonctionnel exhaustif du projet **FORMADOC** (mise en forme automatique de documents académiques pour étudiants camerounais), en comparant l'implémenté à l'attendu, et en identifiant les incohérences backend/frontend.

> **Note de périmètre** : ce prompt couvre l'ensemble du produit (auth, documents, quotas, paiement, dashboard, landing) à un niveau large. Le **chat IA** dispose d'un audit dédié plus approfondi (tool use, sécurité des actions destructives, prompt injection, concurrence). Traite ici le chat de façon sommaire (statut global, présence/absence des briques) sans dupliquer l'analyse fine — renvoie vers l'audit chat pour le détail.

## Méthode — ne pas se limiter à la lecture de code
Quand l'environnement le permet, exécute réellement les parcours (lancer l'app avec les seeders, naviguer, déclencher un paiement en sandbox) plutôt que de déduire le comportement du code seul. Si tu ne peux pas exécuter l'app (pas de serveur, pas de navigateur), **dis-le explicitement** et base le rapport sur l'analyse statique — ne fabrique pas de captures d'écran ou de résultats d'exécution que tu n'as pas obtenus.

## Contexte technique
- Stack : Laravel (PHP 8.2+), Blade, Alpine.js, MySQL, Redis (files d'attente), PHPWord 1.4.0, LibreOffice, OpenRouter (IA), KPay (paiement Mobile Money).

## Fonctionnalités attendues (cibles)
1. **Authentification** : inscription, connexion, réinitialisation mot de passe, activation de compte.
2. **Gestion de documents** : upload (.docx/.odt/.pdf), validation type/taille, traitement async (déterministe PHPWord ou assisté IA), suivi de statut, aperçu, téléchargement.
3. **Chat IA** *(audit sommaire ici, détail dans le prompt dédié)* : présence de l'historique, du tool use, des erreurs gérées.
4. **Quotas et abonnements** : plans freemium/payants, comptage documents déterministes vs IA, achat de crédits à la carte, intégration KPay/Mobile Money.
5. **Tableau de bord** : liste des documents, quota restant, accès chat, profil.
6. **Landing page** : présentation, tarifs, FAQ, inscription.

## Ce que tu dois faire

### 1. Exploration du code
`routes/`, `app/Http/Controllers/`, `app/Models/`, `app/Services|Actions|Jobs/`, `resources/views/`, `resources/js/`, `config/`, `database/migrations/`.

Pour chaque fonctionnalité : état backend (contrôleurs, routes, services, modèles, migrations, jobs, middlewares), état frontend (vues, Alpine.js, JS, CSS), et intégration (AJAX, formulaires, WebSockets, polling).

### 2. Tableau d'audit principal
`Fonctionnalité | Backend (fichiers, état) | Frontend (fichiers, état) | Statut global (OK/Partiel/Absent/Incomplet) | Problèmes ou manques | Priorité (P0/P1/P2)`

### 3. Incohérences backend/frontend
Ex : bouton "Télécharger" sans génération backend, champ de formulaire absent de la migration, route API définie mais jamais appelée par le frontend. **Base ces constats sur le code ou sur une exécution réelle — pas sur des captures inventées.**

### 4. Sécurité et robustesse — avec attention particulière au paiement et aux données
- Validation des entrées, CSRF, XSS, injection SQL.
- **Autorisation / IDOR** : un utilisateur peut-il accéder aux documents, conversations ou factures d'un autre utilisateur en modifiant un ID dans l'URL ou une requête API ?
- **Exposition des fichiers stockés** : les documents (upload et générés) sont-ils servis via URLs prédictibles/publiques, ou signées et à durée limitée ? Un mémoire d'étudiant pourrait-il fuiter ?
- **Paiement KPay / Mobile Money** :
  - Signature des webhooks vérifiée ?
  - Protection contre le rejeu d'un webhook (idempotence — un même événement traité deux fois ne doit pas créditer deux fois) ?
  - Séparation claire sandbox/production ?
  - Que se passe-t-il si le paiement réussit côté KPay mais que la mise à jour du quota échoue côté FORMADOC (désynchronisation argent payé / crédit non reçu) ?
- **Race conditions sur les quotas** : deux requêtes simultanées peuvent-elles décrémenter le même quota de façon incohérente ?
- Traitement asynchrone : le statut des jobs est-il mis à jour de façon fiable (y compris en cas d'échec du job) ?
- Fichiers temporaires : suppression après traitement ?
- Journalisation (logs, Sentry ou équivalent) : suffisante pour déboguer un incident de paiement ou de traitement de document ?

### 5. Plan de correction priorisé
- **P0** : bloquant, perte de données, faille critique — **traite tout ce qui touche argent (paiement, quotas) et confidentialité des documents (IDOR, exposition de fichiers) comme candidat P0 par défaut**, à rétrograder seulement si tu constates que la protection existe déjà.
- **P1** : fonctionnalité clé manquante ou bug majeur pour la bêta.
- **P2** : amélioration post-lancement.

### 6. Checklist finale
Conformité fonctionnelle avant mise en production, avec une sous-section dédiée "Paiement et argent" et une sous-section "Confidentialité des documents".

## Format de sortie
1. Introduction — état global du projet, **et niveau de confiance de l'audit** (code lu seul vs app exécutée).
2. Tableau d'audit principal.
3. Incohérences backend/frontend.
4. Problèmes de sécurité / robustesse (paiement et confidentialité en premier).
5. Plan de correction priorisé.
6. Checklist fonctionnelle finale.
7. Suggestions de prochaines étapes — y compris si un audit approfondi séparé du chat IA (tool use, prompt injection, actions destructives) est encore nécessaire ou déjà couvert.

## Contraintes
- Ne modifie aucun fichier.
- Si une fonctionnalité est absente, dis-le clairement — ne suppose jamais qu'elle existe ailleurs.
- Si tu ne peux pas exécuter l'application, dis-le clairement plutôt que de simuler un résultat.
- Adapte les recommandations à Laravel + Alpine.js.
- Tous les textes proposés à l'utilisateur final sont en français simple.
- Priorise en profondeur les modules à risque (paiement, confidentialité) plutôt qu'un traitement uniforme et superficiel de tous les modules.
