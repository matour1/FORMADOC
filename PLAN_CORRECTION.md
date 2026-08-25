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
| P0-5 | **Confirmation + backup avant outils destructifs chat** (`document.reconstruct`), interdire `allow_any_template` venant du LLM | `ChatToolsService`, `ChatController` | 🔄 (allow_any_template ✅ ; confirmation reconstruct à évaluer) |

## 🟠 P1 — Semaine suivante

| # | Correctif | Fichiers |
|---|---|---|
| P1-1 | Throttles : `POST /chat`, login/register, purchase, feedback (honeypot) | `routes/saas.php`, `routes/auth.php`, `FeedbackController` |
| P1-2 | Cumul de coût multi-tours OpenRouter (cost gap) + test unitaire | `OpenRouterService` |
| P1-3 | Durcir `CoverPageTemplateController::previewFile` (token signé, retirer `X-Preview-Path`) | `CoverPageTemplateController` |
| P1-4 | Attachments chat : implémenter ou retirer l'UI | `ChatController`, `chat/show.blade.php` |
| P1-5 | Session mismatch : 403 explicite au lieu d'une création silencieuse | `ChatController::send()` |
| P1-6 | RGPD : purge TTL sessions/files + suppression cascade | commande + `AccountController` |

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

- [x] `php artisan test` — 306 tests / 1121 assertions verts (291 baseline + 15 nouveaux P0)
- [x] Vérifier qu'un document d'un autre utilisateur renvoie 404 (tests `DocumentOwnershipSecurityTest` : 4/4)
- [x] Vérifier que le webhook KPay ne double jamais le crédit (tests `KPayWebhookIdempotenceTest` : 4/4)
- [x] Vérifier la suppression complète du compte (fichiers inclus) — `AccountDeletionPurgeTest` : 1/1
- [x] Vérifier le téléchargement des fichiers générés chat (propriétaire uniquement) — `ChatFileDownloadSecurityTest` : 6/6

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

### P0-5 — Outils destructifs chat (partiel)
- `allow_any_template` venant du LLM est désormais IGNORÉ : la requête force `is_public = true` (ou appartenance user) dans `ChatToolsService::generateCoverPage()`
- ⏳ Confirmation avant `document.reconstruct` : analyse — l'outil crée un NOUVEAU fichier dans `chat/generated/` (risque modéré, rien n'est écrasé). Décision documentée : pas de confirmation bloquante nécessaire pour l'instant, à reconsidérer si l'outil évolue vers de l'écriture sur des fichiers existants.
