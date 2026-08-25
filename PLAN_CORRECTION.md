# PLAN_CORRECTION.md — Plan de correction Formadoc

> Issu des audits **CHAT_AUDIT** et **prompt-audit-global-formadoc-v2** (session précédente).
> Priorité : **intégrité des documents utilisateurs + argent + confidentialité AVANT le UI**.

## 🔴 P0 — À corriger en premier (sécurité critique)

| # | Correctif | Fichiers | Statut |
|---|---|---|---|
| P0-1 | **Auth + ownership sur toutes les routes documents** (routes actuellement publiques → IDOR total) | `routes/web.php`, `DocumentController`, `app/Policies/DocumentPolicy.php` (nouveau), `GenerateCoverRequest`, `ValidateStructureRequest` | ✅ |
| P0-2 | **Idempotence webhook KPay atomique** (race condition check-then-insert → double crédit) | `KPayController`, `SyncKpayPayments`, `SubscriptionService`, `CreditService::creditIfNotProcessed()` | ✅ |
| P0-3 | **Corriger `AccountController::destroy()`** : `Storage::delete` sans disk → fichiers jamais supprimés ; purge complète (documents, chat, skills, factures) | `AccountController` | ✅ |
| P0-4 | **Route de téléchargement sécurisée** pour les fichiers générés chat (`chat/generated`, `claude-skills`) | `routes/saas.php`, `ChatController` (nouvelle méthode `downloadFile`) | ✅ |
| P0-5 | **Confirmation + backup avant outils destructifs chat** (`document.reconstruct`), interdire `allow_any_template` venant du LLM | `ChatToolsService`, `ChatController` | ✅ (allow_any_template ignoré ; reconstruct documenté : crée toujours un nouveau fichier — aucune confirmation bloquante nécessaire) |

## 🟠 P1 — Semaine suivante

| # | Correctif | Fichiers | Statut |
|---|---|---|---|
| P1-1 | Throttles : `POST /chat`, login/register, purchase, feedback (honeypot) | `routes/saas.php`, `routes/auth.php`, `FeedbackController` | ✅ (limiters dans `AppServiceProvider`, honeypot feedback) |
| P1-2 | Cumul de coût multi-tours OpenRouter (cost gap) + test unitaire | `OpenRouterService` | ✅ (cumul `cost_usd`/`cost_credits` sur tous les tours, détail `cost_usd_per_turn`) |
| P1-3 | Durcir `CoverPageTemplateController::previewFile` (token signé, retirer `X-Preview-Path`) | `CoverPageTemplateController` | ✅ (URL signée + expirable, préfixe `preview-`, header supprimé) |
| P1-4 | Attachments chat : implémenter ou retirer l'UI | `ChatController`, `chat/show.blade.php` | ⬜ |
| P1-5 | Session mismatch : 403 explicite au lieu d'une création silencieuse | `ChatController::send()` | ✅ |
| P1-6 | RGPD : purge TTL sessions/files + suppression cascade | commande + `AccountController` | ⬜ |

## 🟡 P2 — Prochain sprint

| # | Correctif | Fichiers |
|---|---|---|
| P2-1 | Quota consommé avant traitement (perdu si échec) | `DocumentController::upload` |
| P2-2 | Statut `processing` document pendant `LongFormattingJob` | `LongFormattingJob` |
| P2-3 | Honeypot feedback | `FeedbackController` |
| P2-4 | Purge fichiers temporaires (`preview-*`, `test_scripts`) | commande |
| P2-5 | `InvoiceService::nextNumber` atomique (séquence dédiée) | `InvoiceService` |
| P2-6 | Idempotence gate `confirm_cost` (token) | `ChatController` |
| P2-7 | Harmoniser `cost_infrastructure` (défaut code 0.25 vs config 0.15) | `OpenRouterService` |
| P2-8 | Créer un test Feature de sécurité : 403 si cross-user, auth requise | `tests/Feature/` |

## 🧪 Validation finale (avant commit)

- [x] `php artisan test` — **327 tests / 1225 assertions verts** (321 + 6 nouveaux P1-3 : preview signé)
- [x] Vérifier qu'un document d'un autre utilisateur renvoie 404 (tests `DocumentOwnershipSecurityTest` : 4/4)
- [x] Vérifier que le webhook KPay ne double jamais le crédit (tests `KPayWebhookIdempotenceTest` : 4/4)
- [x] Vérifier la suppression complète du compte (fichiers inclus) — `AccountDeletionPurgeTest` : 1/1
- [x] Vérifier le téléchargement des fichiers générés chat (propriétaire uniquement) — `ChatFileDownloadSecurityTest` : 6/6
- [x] Vérifier les throttles (login/register/chat/purchase/feedback) — `RateLimitingTest` : 7/7
- [x] Vérifier le 403 sur session chat d'autrui — `ChatSessionMismatchTest` : 4/4
- [x] Vérifier le cumul de coût multi-tours OpenRouter — `OpenRouterMultiTurnCostTest` : 4/4
- [x] Vérifier le durcissement de l'aperçu cover (URL signée, pas de fuite de chemin) — `CoverTemplatePreviewSecurityTest` : 6/6

## 📝 Détails d'implémentation P0

### P0-1 — Auth + ownership documents
- Routes documents déplacées dans le groupe `auth` (`routes/web.php`), création de `DocumentPolicy.php`
- `DocumentController::authorizeDocument()` : vérifie `metadata.user_id === auth()->id()`, 404 sinon (ne révèle pas l'existence)
- **Bug découvert** : `GenerateCoverRequest` (champ `cover` requis) et `ValidateStructureRequest` validaient AVANT `authorizeDocument()` → 302 au lieu de 404. Correction : la vérification d'ownership est déplacée dans `authorize()` des FormRequests (exécuté avant les règles) + `failedAuthorization()` → abort(404)

### P0-2 — Idempotence webhook KPay
- **Migration index unique supprimée** : une contrainte (reference,type) aurait cassé les débits usage multiples d'une même session chat + les refunds multiples
- `CreditService::creditIfNotProcessed()` : vérification `already exists` + crédit DANS la même transaction, sérialisée par `lockForUpdate` sur la ligne user → idempotence atomique
- `KPayController::webhook()` : réponse `already_processed` (200) si doublon, `credit_failed` (500) si échec
- `SubscriptionService::activateFromWebhook()` : check `external_id` déplacé dans la transaction (variable `&$already`)
- `SyncKpayPayments` : utilise `creditIfNotProcessed()` — pas de double facture/email sur resync

### P0-3 — Suppression de compte complète
- `Storage::disk('storage')->delete($path)` (corrige le bug disk 'local' — les fichiers uploadés n'étaient jamais supprimés)
- Purge des fichiers générés chat (`chat/generated/**`, `claude-skills/**`) via `metadata.generated_files` des messages, AVANT suppression des messages
- Purge `storage/test_scripts` (DOCX générés) + suppression en base documents/structures/générations
- Suppression des `KpayPayment` (manquait)
- **Bug critique découvert** : `$user->delete()` n'était JAMAIS appelé (le compte restait en base malgré le message de confirmation)
- **Bug subtil** : `Auth::logout()` fait un `save()` implicite (rotation remember token) sur le modèle → si le user est supprimé avant, rollback de la transaction. Ordre corrigé : logout → invalidation session → delete user

### P0-4 — Téléchargement sécurisé fichiers chat
- `GET /chat/files/download?file=` : normalisation du chemin, rejet `..` / chemins absolus, préfixes autorisés (`chat/generated/`, `claude-skills/`), ownership via `metadata->generated_files` (ou fallback `content LIKE`) sur les sessions de l'utilisateur
- Les fichiers générés sont tracés dans `metadata.generated_files` du message assistant (`ChatController::send()`)

### P0-5 — Outils destructifs chat
- `allow_any_template` venant du LLM est désormais IGNORÉ : la requête force `is_public = true` (ou appartenance user) dans `ChatToolsService::generateCoverPage()`
- Confirmation avant `document.reconstruct` : analyse — l'outil crée un NOUVEAU fichier dans `chat/generated/` (risque modéré, rien n'est écrasé). **Décision** : aucune confirmation bloquante nécessaire pour l'instant ; à reconsidérer si l'outil évolue vers de l'écriture sur des fichiers existants.

## 📝 Détails d'implémentation P1

### P1-1 — Throttles (bruteforce / spam / abus)
- Limiters définis dans `AppServiceProvider::configureRateLimiters()` :
  - `chat` : 20/min par `user_id` (ou IP si non connecté) — protège les appels IA payants
  - `login` : 5/min par `email|IP` — les tentatives distributives (multi-comptes) restent limitées par IP
  - `register` : 3/min par `email|IP`
  - `purchase` : 5/min par `user_id` (ou IP) — anti-spam paiement
  - `feedback` : 3/h par IP — anti-spam formulaire public
  - `password_email` : 3/min par `email|IP` (appliqué à `password.email` et `password.update`)
- Middlewares `throttle:` posés sur : `POST /chat` (`saas.php`), `login.attempt`, `register.attempt`, `password.email`, `password.update` (`auth.php`), `feedback.store` (`web.php`), `credits.purchase` (`saas.php`)
- **Honeypot feedback** : champ invisible `website` ajouté au formulaire ; s'il est rempli → le feedback est ignoré silencieusement (log `Feedback honeypot triggered`)
- Tests : `RateLimitingTest` (7 tests) — throttle login (5→6e), register (3→4e), feedback (3→4e), chat (20→21e), purchase (5→6e), honeypot (feedback non stocké), isolation par utilisateur

### P1-2 — Cumul du coût multi-tours OpenRouter
- **Bug (cost gap)** : la boucle de function calling effectue plusieurs appels HTTP (1 initial + N tours d'outils), chaque appel consommant des tokens facturés. Avant, seul le coût du DERNIER tour était retourné → les tours intermédiaires étaient facturés à perte (le contrôleur remboursait la différence estimation/réel avec un réel sous-estimé).
- Correctif dans `OpenRouterService::chat()` : cumul de `cost_usd` et `cost_credits` sur TOUS les tours (variables `$totalUsd`/`$totalCredits`), reset à chaque candidat (un échec sur un modèle ne pollue pas le fallback)
- Nouveau champ `cost_usd_per_turn` (array) exposé pour le debug/métriques
- `parseResponse()` mémorise le coût de chaque tour dans `$this->turnCosts`
- Le contrôleur `ChatController::send()` (étape 11) utilise déjà `$response['cost_credits']` → l'ajustement estimation/réel est désormais correct
- Tests : `OpenRouterMultiTurnCostTest` (4 tests) — cumul 2 tours, tour unique, 3 tours avec exécuteur, reset entre appels successifs

### P1-3 — Durcir l'aperçu serveur des pages de garde
- **Faille** : `preview()` écrivait `storage/app/preview-XXXXXXXXXX.docx` et retournait une URL où le token ÉTAIT le nom de fichier brut ; `previewFile()` ne contrôlait que `.docx` + existence → n'importe quel `.docx` de `storage/app` (chat/generated, invoices…) était téléchargeable en connaissant son nom ; le header `X-Preview-Path` exposait le chemin serveur complet.
- Correctif :
  - `URL::temporarySignedRoute(..., now()->addMinutes(15), ['token' => basename($tmp)])` → URL signée + expirable
  - middleware `signed` sur la route + garde `$request->hasValidSignature()` (403 si invalide/expirée)
  - préfixe `preview-` obligatoire + `.docx` + existence (404 sinon) → un fichier ordinaire de `storage/app` n'est plus téléchargeable
  - suppression du header `X-Preview-Path`
  - route simplifiée : `cover-templates/preview/{token}` (le paramètre superflu `{coverTemplate}` retiré)
- Tests : `CoverTemplatePreviewSecurityTest` (6 tests) — pas de fuite du chemin, refus sans signature, téléchargement OK signé, signature falsifiée refusée, `.docx` ordinaire refusé, traversée de répertoire refusée

### P1-4 à P1-6 — à venir
- P1-4 : attachments chat — implémenter ou retirer l'UI
- P1-6 : RGPD — purge TTL sessions/files + suppression cascade

### P1-5 — Session mismatch chat (403 explicite)
- `ChatController::send()` : si une session est fournie dans l'URL mais `user_id !== auth()->id()` → `abort(403)`
- Vérification déplacée en ÉTAPE 0 (avant estimation du coût, solde, quota, débit) : aucune consommation de crédits/quota en cas de tentative d'accès croisé
- Avant : création SILENCIEUSE d'une nouvelle session (l'utilisateur croyait écrire dans la sienne, et les tentatives d'accès croisé étaient masquées)
- Tests : `ChatSessionMismatchTest` (4 tests) — 403 cross-user, aucune session créée pour l'intrus, aucun message écrit dans la session d'autrui, flux normal préservé (propriétaire + confirmation coût)
