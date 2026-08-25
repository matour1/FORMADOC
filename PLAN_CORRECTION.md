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
| P1-4 | Attachments chat : implémenter ou retirer l'UI | `ChatController`, `chat/show.blade.php` | ✅ (implémentés : upload privé, extraction txt/md/docx, contexte IA, téléchargement sécurisé) |
| P1-5 | Session mismatch : 403 explicite au lieu d'une création silencieuse | `ChatController::send()` | ✅ |
| P1-6 | RGPD : purge TTL sessions/files + suppression cascade | commande + `AccountController` | ✅ (cascade attachments + commande `files:purge-temp` cron quotidien) |

## 🟡 P2 — Prochain sprint

| # | Correctif | Fichiers | Statut |
|---|---|---|---|
| P2-1 | Quota consommé avant traitement (perdu si échec) | `DocumentController::upload` | ✅ (remboursement catch + nettoyage fichier/document) |
| P2-2 | Statut `processing` document pendant `LongFormattingJob` | `LongFormattingJob` + Dashboard + vues | ✅ (processing → ready, retour detected sur échec) |
| P2-3 | Honeypot feedback | `FeedbackController` | ✅ (déjà couvert par P1-1 : champ `website` + test `RateLimitingTest`) |
| P2-4 | Purge fichiers temporaires (`preview-*`, `test_scripts`) | commande | ✅ (gen_*.docx orphelins purgés, scripts conservés) |
| P2-5 | `InvoiceService::nextNumber` atomique (séquence dédiée) | `InvoiceService` | ✅ (table invoice_sequences + lockForUpdate + backfill) |
| P2-6 | Idempotence gate `confirm_cost` (token) | `ChatController` | ✅ (token one-time + hash du message + tests) |
| P2-7 | Harmoniser `cost_infrastructure` (défaut code 0.25 vs config 0.15) | `OpenRouterService` | ✅ (fallbacks ramenés à 0.15 + test verrou) |
| P2-8 | Créer un test Feature de sécurité : 403 si cross-user, auth requise | `tests/Feature/` | ✅ (`InvoiceDownloadSecurityTest` : 4/4 — facture d'autrui 403, invité → login, propriétaire OK, hash invalide 404) |
| P2-9 | **Obfusquer les IDs de base de données dans les URLs** (Document, ChatSession, Invoice, CoverPageTemplate) | trait `HasHashId` + 4 modèles | ✅ (hashids 5.0, sel par modèle dérivé de APP_KEY, `hash_id` comme clé de route, décodage dans le binding, tests dédiés) |

## 🧪 Validation finale (avant commit)

- [x] `php artisan test` — **363 tests / 1377 assertions verts** (355 + 4 `HasHashIdObfuscationTest` + 4 `InvoiceDownloadSecurityTest`)
- [x] Vérifier qu'un document d'un autre utilisateur renvoie 404 (tests `DocumentOwnershipSecurityTest` : 4/4)
- [x] Vérifier que le webhook KPay ne double jamais le crédit (tests `KPayWebhookIdempotenceTest` : 4/4)
- [x] Vérifier la suppression complète du compte (fichiers inclus) — `AccountDeletionPurgeTest` : 1/1
- [x] Vérifier le téléchargement des fichiers générés chat (propriétaire uniquement) — `ChatFileDownloadSecurityTest` : 6/6
- [x] Vérifier les throttles (login/register/chat/purchase/feedback) — `RateLimitingTest` : 7/7
- [x] Vérifier le 403 sur session chat d'autrui — `ChatSessionMismatchTest` : 4/4
- [x] Vérifier le cumul de coût multi-tours OpenRouter — `OpenRouterMultiTurnCostTest` : 4/4
- [x] Vérifier le durcissement de l'aperçu cover (URL signée, pas de fuite de chemin) — `CoverTemplatePreviewSecurityTest` : 6/6
- [x] Vérifier les pièces jointes chat (stockage, contexte IA, téléchargement sécurisé) — `ChatAttachmentsTest` : 6/6

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

### P1-4 — Pièces jointes du chat (implémentées)
- **Bug (fonctionnalité factice)** : l'UI envoyait `attachments[]` (multipart) mais le backend ne les validait/stockait/passait JAMAIS → fichiers joints silencieusement ignorés.
- Correctif :
  - `ChatAttachmentService` : whitelist extensions (docx, pdf, txt, md, xlsx, pptx), max 5 Mo/fichier, max 5 fichiers ; stockage disque privé `chat/attachments/{sessionId}/{uuid}.{ext}` ; extraction texte (txt/md brut, docx via PhpWord) ; pdf/xlsx/pptx = mention sans dump
  - `ChatController::send()` : validation `attachments.*`, traitement après création de session, bloc contexte IA préfixé au message utilisateur (`[Pièce jointe N : nom]` + extrait), trace `metadata.attachments` (nom/chemin/taille/ext) sur le message utilisateur
  - `ChatController::downloadFile()` : accepte `chat/attachments/{sessionId}/` avec ownership vérifié via la session (fix : le check `generated_files` était exécuté ensuite → 404 systématique)
  - UI `chat/show.blade.php` : affichage des pièces jointes avec lien de téléchargement sécurisé sous le message
- Tests : `ChatAttachmentsTest` (6 tests) — stockage + contexte, extension interdite, taille max, téléchargement propriétaire, refus intrus, traversée refusée

### P1-6 — RGPD : purge TTL + suppression cascade (implémenté)
- **Gap RGPD** : les pièces jointes `chat/attachments/*` (ajoutées en P1-4) n'étaient supprimées ni à la suppression d'une session de chat, ni à la suppression du compte ; les previews de couverture (`storage/app/preview-*.docx`) restaient sur le disque si l'URL signée (15 min) expirait sans téléchargement ; des pièces jointes orphelines pouvaient subsister après une purge interrompue.
- Correctif — suppression cascade :
  - `ChatController::destroy()` : purge récursive `chat/attachments/{sessionId}/**` (fichiers tracés ET non tracés) + fichiers `chat/generated/**`/`claude-skills/**` tracés dans `metadata.generated_files`, AVANT suppression des messages (P1-6)
  - `AccountController::destroy()` : collecte des chemins `metadata.attachments` (en plus de `metadata.generated_files`) et purge récursive `chat/attachments/{sessionId}/**` pour chaque session, avant suppression des messages
- Correctif — purge TTL :
  - Nouvelle commande `files:purge-temp` (`app/Console/Commands/PurgeTempFiles.php`) : supprime les previews `storage/app/preview-*.docx` plus vieux que le TTL (défaut 24 h, option `--ttl-hours`) et les dossiers `chat/attachments/{sessionId}` dont la session n'existe plus (orphelins)
  - Planifiée dans `routes/console.php` : quotidien à 02:30, `withoutOverlapping`
- Tests : `ChatSessionPurgeTest` (5 tests) — suppression de session purge pièces jointes + fichiers générés, intrus 403 sans toucher aux fichiers, TTL previews (expiré supprimé / récent conservé / hors préfixe intact), purge des attachments de session supprimée, TTL personnalisé ; `AccountDeletionPurgeTest` étendu (pièce jointe supprimée à la suppression du compte)

### P2-1 — Quota consommé avant traitement (perdu si échec) (implémenté)
- **Gap** : le quota déterministe (et IA) était consommé au DÉBUT de l'upload, AVANT le stockage du fichier et l'analyse. Une exception (stockage KO, fichier corrompu, analyse en échec) faisait perdre le quota à l'utilisateur SANS document créé.
- Correctif :
  - Suivi des quotas consommés dans `$consumed['deterministic'|'ai']` pendant le traitement
  - Catch global : remboursement en bloc via `QuotaService::refund()` (même pattern que le chat, échec LLM) — aucune perte en cas d'échec
  - Échec d'analyse : nettoyage du fichier stocké (disk 'storage') et suppression de la ligne `Document` avant de relancer l'exception vers le catch global
- Tests : `UploadQuotaRefundTest` (3 tests) — échec d'analyse rembourse le quota déterministe et nettoie le fichier, upload réussi consomme normalement, échec stockage (mock façade Storage) rembourse les quotas déterministe + IA

### P2-2 — Statut `processing` document pendant `LongFormattingJob` (implémenté)
- **Gap** : pendant la mise en forme IA asynchrone, le document restait en `detected` — aucun indicateur « traitement IA en cours » pour l'utilisateur, impossible de distinguer un document en attente d'un document en cours de traitement.
- Correctif :
  - `LongFormattingJob::handle()` : passe le document en `processing` au début (après vérification utilisateur + solde, avant débit). Succès → `ready` (déjà en place). Échec récupérable (débit KO, structure vide, échec LLM) → retour à `detected` (l'utilisateur peut relancer) + remboursement intégral
  - `DashboardController::documents()` : filtre `processing` et compteur `$statusCounts['processing']` incluent désormais `processing` (dans les `whereIn`)
  - Vues : badge distinct « ⏳ IA en cours » pour `processing` dans `documents/index.blade.php` et `dashboard.blade.php` (reste dans la catégorie « En cours » mais visiblement en traitement)
- Tests : `LongFormattingJobStatusTest` (5 tests) — job réussi → `ready` + ajustement coût réel, échec LLM → retour `detected` + remboursement, solde insuffisant → statut inchangé, filtre dashboard inclut `processing`, compteur dashboard inclut `processing`

### P2-3 — Honeypot feedback (déjà couvert par P1-1 — aucun code nécessaire)
- **Vérification** : le champ invisible `website` est déjà implémenté dans `FeedbackController::store()` (rempli → feedback ignoré silencieusement avec réponse « merci » + log `Feedback honeypot triggered`).
- Test déjà présent : `RateLimitingTest::test_le_honeypot_feedback_ignore_le_robot` (feedback non stocké).
- Conclusion : P2-3 est un doublon de P1-1 → marqué ✅ sans modification.

### P2-4 — Purge fichiers temporaires `preview-*` + `test_scripts` (implémenté)
- **Gap** : la commande `files:purge-temp` (P1-6) purgeait les previews cover et les pièces jointes orphelines, mais PAS les DOCX reconstruits `storage/test_scripts/gen_*.docx` (générés à la volée par `DocumentController::generateOutputPath()`). Ces fichiers s'accumulaient quand l'export n'était jamais téléchargé (111 orphelins constatés en prod locale).
- Correctif :
  - `PurgeTempFiles::handle()` : nouvelle passe sur `storage/test_scripts/gen_*.docx` — purge si plus vieux que le TTL (défaut 24 h, option `--ttl-hours`)
  - Les scripts utilitaires (`.php`, `reports/`) sont CONSERVÉS : ils sont versionnés et utilisés par les tests unitaires DocAnalyzer (fixture `rapport_test_structure.docx`)
- Tests : `ChatSessionPurgeTest::test_files_purge_temp_supprime_les_docx_generes_orphelins_et_conserve_les_scripts` (1 test) — gen_*.docx expiré purgé, récent conservé, scripts `.php` + fixtures `reports/` conservés
- ⚠️ Leçon : le test écrase la fixture versionnée `reports/rapport_test_structure.docx` au premier jet → tests unitaires DocAnalyzer en échec (archive corrompue). Corrigé : fixture isolée `fixture_purge_tmp.docx` + restauration `git checkout` des fichiers versionnés.

### P2-5 — `InvoiceService::nextNumber` atomique (implémenté)
- **Gap** : `nextNumber()` faisait `Invoice::whereYear()->count() + 1` → NON atomique : deux requêtes concurrentes (webhook KPay + renouvellement + achat) pouvaient lire le même count et générer le même numéro → violation contrainte unique `invoices.number`. De plus, l'appel sans création de facture entre deux retournait toujours le même numéro.
- Correctif :
  - Nouvelle table `invoice_sequences` (migration `2026_08_25_000002_create_invoice_sequences_table.php`) : une ligne par année, `last_number` incrémenté sous `lockForUpdate` (InnoDB) dans une transaction → numérotation atomique
  - `insertOrIgnore` pour le premier appel de l'année (robuste à la course à l'insertion)
  - Backfill : ligne de l'année courante initialisée au max des suffixes existants (jamais de réutilisation de numéro émis)
- Tests : `InvoiceServiceTest` (3 nouveaux) — deux appels sans création donnent des numéros distincts, séquence continue après factures existantes (backfill à 42 → 43), séparation par année (2025 et 2026 repartent de 1)
- ⚠️ Test existant adapté : `test_next_number_sequence_croissante` créait la facture via `Invoice::factory()` (hors séquence) → le `count()+1` donnait 000002 mais la séquence restait à 0. Désormais il passe par `createForCreditPurchase()` (flux réel).

### P2-6 — Idempotence gate `confirm_cost` (token one-time) (implémenté)
- **Gap** : un POST direct `message + confirm_cost=1` (sans passer par l'estimation) exécutait le message ; un double-clic ou un rechargement du banner de confirmation ré-envoyait le même message 2 fois → double débit + messages dupliqués ; le message était stocké en clair dans le champ caché de la vue.
- Correctif :
  - `ChatController::send()` étape 3 : à la première soumission (estimation, sans `confirm_cost`), un token `Str::random(32)` est généré et inclus dans le flash `pending_cost`
  - Étape confirmation : le POST doit fournir `confirm_token` EXACTEMENT égal au token en session (`hash_equals`, constant-time) ET le message confirmé doit être celui estimé (même logique `hash_equals` sur le message) → sinon `back()` avec « Confirmation expirée ou invalide », AUCUN message créé, AUCUN débit
  - Le token est à usage unique : `session()->forget('pending_cost')` dès la confirmation consommée → un double-clic / rechargement / rejeu du même token est rejeté (pas de double débit, pas de doublon)
  - `chat/show.blade.php` : champ caché `confirm_token` ajouté au formulaire de confirmation
- Tests : `ChatConfirmationTokenTest` (4 tests) — confirmation sans token refusée (aucun message), mauvais token refusé (solde intact), token à usage unique (double POST → 1 seul message, 1 seul débit), message différent avec le bon token refusé
- ⚠️ Tests adaptés : `ChatAttachmentsTest` (5 POSTs) passent désormais par le flux complet via le helper `confirmChat()` (estimation → lecture du token en session → confirmation)

### P2-7 — Harmoniser `cost_infrastructure` (fallback code 0.25 vs config 0.15) (implémenté)
- **Gap** : `config('openrouter.cost_infrastructure', 0.25)` dans `UsageCostCalculator::profitabilityCoefficient()` et `OpenRouterService::usdToCredits()` — fallback en dur à 0.25 alors que `config/openrouter.php` définit 0.15. Incohérence silencieuse : si la clé config disparaissait, le coefficient passerait de 1.84× à 2.0× (facturation plus chère) sans aucun avertissement.
- Correctif : les deux fallbacks ramenés à `0.15` (alignés sur la config et sur la doc des commentaires « infra +15 % »).
- Tests : `UsageCostCalculatorTest::test_le_fallback_infrastructure_est_aligne_sur_la_config_015` — retire réellement la clé du tableau de config (`Arr::except`) et vérifie que le coefficient reste 1.84.
- ⚠️ Piège Laravel : `Config::offsetUnset('openrouter.cost_infrastructure')` met la clé à `null` (Repository::set) → `config()` retourne `null` → `(float) null = 0` → coefficient 1.6. Pour simuler une clé ABSENTE, retirer la clé du tableau parent (`Arr::except((array) config('openrouter'), 'cost_infrastructure')`) puis `Config::set('openrouter', $tableau)`.

### P2-8 — Test Feature sécurité factures (implémenté)
- Le check d'ownership existait déjà dans `SubscriptionController::downloadInvoice` (`if ($invoice->user_id !== $request->user()->id) abort(403)`) mais n'était PAS testé.
- Tests : `InvoiceDownloadSecurityTest` (4 tests) — facture d'un autre utilisateur → 403 ; invité → redirect login ; propriétaire → téléchargement PDF OK ; hash invalide dans l'URL → 404 (binding ne résout rien).

### P2-9 — Obfuscation des IDs de base de données dans les URLs (implémenté)
- **Problème** : les clés de route des modèles exposés dans les URLs (documents, chat, factures, cover-templates) étaient les IDs auto-incrémentés bruts → énumération triviale (IDOR latéral), fuite du volume d'activité.
- **Solution** : trait `app/Models/Concerns/HasHashId.php` + lib `hashids/hashids` 5.0, appliqué à `Document`, `ChatSession`, `Invoice`, `CoverPageTemplate` :
  - `getRouteKeyName()` → `'hash_id'` : `route('...', $model)` génère l'URL avec le hash.
  - Accessor `getHashIdAttribute()` → `Hashids::encode($this->id)` à la volée (AUCUNE colonne/migration, aucune donnée en base).
  - `resolveRouteBinding()` → `decode()` le hash puis résout par l'id réel ; hash invalide → `null` → 404.
  - **Sel par modèle** : `'formadoc-'.class_basename($this).'-'.config('app.key')` + longueur minimale 12 → hashs non corrélables entre modèles ni entre environnements.
- **Transparent pour les vues/contrôleurs** : aucune modification (tout passe par `route()` / le binding implicite).
- Tests : `HasHashIdObfuscationTest` (4 tests) — clé de route hash pour les 4 modèles, hash déterministe et propre à chaque modèle, hash invalide → 404 (y compris id brut en clair → 404), `route()` ne contient jamais l'id brut.
- ⚠️ Tests adaptés : `DocumentOwnershipSecurityTest`, `CoverGenerationFlowTest`, `DocumentAnalysisPipelineTest`, `ParcoursCompletFlowTest`, `StructureValidationFlowTest` utilisaient `/documents/{$document->id}` en dur → remplacés par `$document->hash_id`.

### P1-5 — Session mismatch chat (403 explicite)
- `ChatController::send()` : si une session est fournie dans l'URL mais `user_id !== auth()->id()` → `abort(403)`
- Vérification déplacée en ÉTAPE 0 (avant estimation du coût, solde, quota, débit) : aucune consommation de crédits/quota en cas de tentative d'accès croisé
- Avant : création SILENCIEUSE d'une nouvelle session (l'utilisateur croyait écrire dans la sienne, et les tentatives d'accès croisé étaient masquées)
- Tests : `ChatSessionMismatchTest` (4 tests) — 403 cross-user, aucune session créée pour l'intrus, aucun message écrit dans la session d'autrui, flux normal préservé (propriétaire + confirmation coût)
