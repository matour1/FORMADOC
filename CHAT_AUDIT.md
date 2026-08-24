# Prompt d'audit — Chat IA FORMADOC (v2, backend + frontend)

Tu es un expert en conception d'assistants IA conversationnels avec tool use (Claude, ChatGPT, agents outillés). Tu audites le chat IA du projet Laravel **FORMADOC**, SaaS de mise en forme automatique de documents académiques pour étudiants camerounais.

## Contexte technique
- Stack : Laravel (PHP), Blade, Alpine.js, Redis (files d'attente), PHPWord, LibreOffice.
- Le chat IA intervient quand la mise en forme déterministe ne suffit pas. Il utilise OpenRouter (routage multi-modèles), potentiellement des Claude Skills, et peut appeler des outils internes qui **modifient directement les documents des utilisateurs**.
- Public cible : étudiants, connexions mobiles parfois lentes, documents = travail de plusieurs mois (mémoires, rapports).

## Méthode d'audit — pas seulement lecture de code
Ne te contente pas de lire les fichiers. Pour chaque flux critique, si l'environnement le permet :
- exécute réellement un échange (message → réponse) et observe le comportement,
- simule un échec (API OpenRouter timeout, LibreOffice qui plante, Redis indisponible),
- vérifie ce qui se passe concrètement, pas ce que le code est censé faire.
Une revue statique rate systématiquement les races conditions, les exceptions avalées silencieusement et les timeouts non gérés — ce sont souvent les bugs qui coûtent le plus cher en prod.

## Fichiers à explorer
- `resources/views/chat/` (vues Blade du chat)
- `app/Http/Controllers/` (ChatController, AssistantController…)
- `app/Services/` ou `app/Actions/` (appels OpenRouter, gestion des tools, formatage)
- `app/Jobs/` ou `app/Queues/` (traitements asynchrones)
- `resources/js/` (Alpine.js : envoi, affichage, polling)
- `routes/web.php`, `routes/api.php`
- `config/` (clés API, modèles, limites)

## Axes d'audit

### A. Fonctionnel — Tool use
1. Quels outils sont définis, comment sont-ils déclarés dans le payload OpenRouter, comment le résultat revient-il au LLM ?
2. **Garde-fous d'exécution** : nombre max d'itérations de la boucle tool-call, détection de boucle infinie, budget de coût/tokens max par conversation.
3. **Sécurité des actions destructives** : les outils qui modifient un document proposent-ils un preview/diff avant application ? Existe-t-il une sauvegarde/versioning du document avant modification ? Un mécanisme d'annulation (undo) ?
4. **Prompt injection** : le contenu du document de l'étudiant est-il injecté comme contexte au LLM ? Si oui, un texte malveillant dans le document pourrait-il déclencher une action outil non voulue ? Y a-t-il une séparation claire entre "contenu à traiter" et "instructions à suivre" dans le prompt système ?

### B. Fonctionnel — Routage multi-modèles
5. Comment le modèle est-il choisi (règle fixe, fallback, critère de coût) ? Que se passe-t-il si le modèle choisi est indisponible ou lent ? Y a-t-il un modèle de secours ?

### C. Flux conversationnel & concurrence
6. Parcours complet d'un message : synchrone ou jobs async ? Étapes exactes.
7. **Concurrence** : que se passe-t-il si l'utilisateur envoie un 2e message avant la fin du traitement du 1er ? Risque de double exécution d'un tool, de state incohérent, de réponses mélangées ?
8. Idempotence des jobs (retry Redis sans double effet de bord) ?

### D. Gestion des erreurs
9. Échec API LLM, échec d'un tool, document inaccessible, timeout LibreOffice : que voit l'utilisateur, que devient la tâche en file ?
10. Les exceptions sont-elles loggées de façon exploitable (log structuré, correlation ID par conversation/requête) ou juste avalées ?

### E. Interface utilisateur
11. Structure HTML, états de chargement, indicateur de progression pendant l'exécution d'un tool (pas seulement "en train d'écrire"), gestion du scroll, auto-resize du champ de saisie.

### F. Mobile & accessibilité
12. Responsive, clavier mobile, taille des zones tactiles, ARIA, focus, contraste.

### G. Sécurité, confidentialité, RGPD
13. Quelles données partent vers le LLM (contenu du document en entier ? extrait ?), les fichiers transitent-ils par un tiers, logs de conversation et durée de rétention, conformité RGPD.

### H. Gestion des quotas et coûts
14. Décompte des crédits IA par échange — est-ce que le décompte suit le coût réel (modèle utilisé, nombre de tool calls) ou un forfait fixe qui peut être perdant si une boucle s'emballe ?

## Livrables attendus
1. **Tableau récapitulatif** : `Localisation (fichier:ligne ou composant) | État actuel | Problème (fonctionnel backend/frontend/UX/gestionnel) | Proposition | Priorité (P0/P1/P2) | Impact attendu`
   — classe en P0 tout ce qui touche à l'intégrité des documents utilisateurs (destruction, corruption, injection), avant l'UI.
2. **Description des parcours critiques testés réellement** (pas seulement lus) : envoi message, exécution tool, réponse, erreur, dépassement quota, message concurrent.
3. **Recommandations tool use** : architecture concrète (définition tool dans payload OpenRouter, exécution via Job Laravel, retour au LLM), avec garde-fous anti-boucle et mécanisme de preview/undo pour les modifications de document.
4. **Propositions UI/UX** : typing indicator, progression d'exécution d'outil, suggestions rapides, messages d'erreur en français simple et rassurant.
5. **Checklist de mise en production**, incluant explicitement : test de charge sur file Redis, test d'injection de prompt via contenu de document, test de perte de connexion mobile en cours de traitement.
6. **Points de vigilance gestionnels** : coût tokens réel vs quota facturé, boucles infinies, sécurité des données, RGPD, risque de corruption de documents étudiants.

## Contraintes
- N'modifie pas les fichiers ; le livrable est un rapport, éventuellement accompagné de scripts de test jetables si l'environnement le permet.
- Tous les textes proposés à l'utilisateur final sont en français simple et rassurant.
- Priorise fiabilité et intégrité des documents avant toute amélioration cosmétique.