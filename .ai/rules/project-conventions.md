# Conventions FORMADOC

> Règles durables validées par l'équipe. Complètent les guidelines Boost (`.ai/rules/boost/`).

## Environnement de développement (Windows / WAMP)

- Serveur : `php artisan serve --host=127.0.0.1 --port=8765`
- Tests : `$env:QUEUE_CONNECTION='sync'; $env:SESSION_DRIVER='array'; php artisan test`
- **Le shell est PowerShell 5.1** :
  - Jamais `&&` → utiliser `;`
  - Affectation : `$var = $(...)` et **non** `var=$(...)`
- Build CSS : `npx vite build` **obligatoire** après toute modification de `resources/css/**`
- Compte de test : `test@example.com` / `password`

## Règles métier impératives

1. **Jamais de LLM pour un problème qu'une regex résout** — ex. légendes `Figure N:` → regex.
2. **PHPWord ne lit jamais un document source** — écriture seule (`REFONTE_ARCHITECTURE.md` §14.2).
3. **Sécurité par défaut** : validation MIME réelle, taille max 50 Mo, requêtes préparées, `DocumentPolicy`.
4. **Styles DOCX natifs** (Heading 1/2/3), images en ligne — jamais de zones de texte flottantes.
5. **4 étapes distinctes** : détection → structuration → gabarit → génération. Ne jamais les fusionner.
6. **Le chat ne modifie jamais le document rendu** — uniquement le JSON structurel, puis re-génération complète. Liste blanche stricte de tools.
7. **Ordre de ré-export** : gabarit → renumérotation → listes. Jamais de TOC/listes avant la pagination finale.
8. **Ledger au coût réel** : tokens `usage` réels, retries inclus, marge appliquée au coût calculé, remboursement d'échec **partiel**.
9. **Signalement discret, jamais de blocage** pour un renvoi croisé ambigu (`resolution_confidence < 0.7`).
10. **Jamais « identique à 100 % »** pour un document issu d'un PDF scanné → « reconstruction fidèle, validée par vous ».

## Routes

- `routes/web.php` : parcours principal (landing, dashboard, documents, templates, couvertures, pages légales, feedback)
- `routes/saas.php` : compte, crédits, abonnements, factures, chat (chargé via `bootstrap/app.php`)
- `routes/auth.php` : login / register / logout
- **Toute route `documents.*`, `cover-templates.*` ou `templates.*` doit être dans le groupe `middleware(['auth'])`** — la sortir réintroduit une faille IDOR (audit P0-1).
- Toute route POST publique exposée doit avoir un `throttle:` (ex. `feedback` → `throttle:feedback`).

## Pipeline document (`app/DocAnalyzer`, `app/Services/Detection`, `app/Services/DocumentGeneration`)

- Lecture d'un `.docx` : **parseur OOXML natif** (`ZipArchive` + `DOMDocument`), jamais PHPWord.
- Écriture du `.docx` final : PHPWord (`DocumentReconstructor`).
- Ne jamais générer le contenu texte d'un bloc `table`/`paragraph` **existant** sans demande explicite de l'utilisateur — classification et reformulation sont deux opérations séparées.
- Toute renumérotation de figure/tableau/annexe/planche doit tenter la résolution des renvois croisés associés.

## Base de données

- Les IDs exposés dans les URLs sont **obfusqués** via le trait `HasHashId` (hashids 5.0, sel dérivé de `APP_KEY`). Utiliser `hash_id` comme clé de route, jamais l'ID brut.
- La relation `User::documents()` passe par `metadata->user_id` (colonne JSON), pas une colonne dédiée.
- Les statuts de document sont : `pending`, `detected`, `validated`, `processing`, `generated`, `ready`, `failed`.
  - Le filtre « En cours » doit inclure **`processing`** (statut du `LongFormattingJob`).

## Frontend

- Un seul design system : `resources/css/formadoc.css` (variables `--color-*`, dark mode `[data-theme="dark"]`).
- Icônes **lucide** (CDN) — jamais Material Symbols, jamais de classes Tailwind `bg-surface-container-*`.
- Polices : Newsreader (titres), Inter (texte), IBM Plex Mono (code).
- Après toute modification CSS : `npx vite build`.

## Tests

- Suite : `php artisan test`. **410 tests** (407 passés, 3 ignorés) — ne pas régresser.
- Ne **pas** renommer les routes `documents.*` : des tests POSTent vers `/documents/upload`.
- Créer un test avec `php artisan make:test --phpunit {name}` (sans le dossier de suite).
- Lancer le test le plus cible d'abord, puis élargir.

## Git

- Une fonctionnalité = une branche `feature/<nom>` créée depuis `main`, puis fusionnée.
- Jamais de commit direct sur `main` pour une nouvelle fonctionnalité (correctifs et hygiène uniquement).
- `.env` **et** `.env.*` ignorés — seul `.env.example` est versionné. Jamais de clé API commitée.

## ⚠️ Ne jamais supprimer de fichiers utilisateurs en masse

`storage/uploads/` contient des rapports d'étudiants **réels** et n'est **pas dans git**.
- Suppression impossible à annuler (`Remove-Item` ne va pas dans la corbeille).
- Toujours faire un `-WhatIf` d'abord, puis vérifier le compte avant d'exécuter.
- Préférer un script PHP qui vérifie `count($fichiers) === count($orphelins)` avant toute suppression.
