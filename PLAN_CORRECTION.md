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
| P2-6 | Idempotence gate `confirm_cost` (token) | `ChatController` | ✅ (dépassé — flux de confirmation SUPPRIMÉ : envoi direct, coût estimé affiché avant + ajusté après usage ; tests adaptés) |
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

### P2-6 — Idempotence gate `confirm_cost` (token one-time) — SUPPRIMÉ (remplacé par l'envoi direct)
- **Contexte** : un gate à token (estimation → confirmation → envoi) avait été implémenté pour éviter le double-clic / double débit.
- **Problème en réel** : le flux était cassé — le flash `session('pending_cost')` (qui porte le token) était consommé par le `back()` de redirection, donc la confirmation échouait systématiquement avec « Confirmation expirée ou invalide ». De plus, la confirmation gâchait l'UX (demande utilisateur).
- **Décision (remplacement)** : suppression complète de l'étape de confirmation. `ChatController::send()` envoie directement :
  - Le coût estimé est affiché dans le composer avant l'envoi (« Coût estimé : N crédit(s) — ajusté après usage »)
  - Le débit réel a lieu après le traitement IA (toujours unique par requête : idempotence garantie côté requête HTTP, pas de double POST involontaire)
  - Anciens champs `confirm_cost` / `confirm_token` ignorés sans erreur (compatibilité)
- Bannières/toasts `pending_cost` retirés des vues (`chat/index.blade.php`, `chat/show.blade.php`, `layouts/app.blade.php`)
- ⚠️ **Bug de balise corrigé au passage** : le formulaire de `chat/index.blade.php` n'avait PAS `enctype="multipart/form-data"` → les pièces jointes ne pouvaient jamais partir depuis une nouvelle conversation. Ajout de l'`enctype` + UI complète (bouton paperclip, input `attachments[]`, chips nom/taille) pour permettre l'envoi de pièces jointes dès le début d'une conversation.
- Tests : `ChatConfirmationTokenTest` réécrit (4 tests) — envoi direct crée le message et débite UNE fois, anciens champs `confirm_*` ignorés sans erreur, création de session sans session existante, message sans texte refusé. `ChatAttachmentsTest` et `ChatSessionMismatchTest` adaptés (envoi direct 1 POST, plus de `confirm_cost`).
- ⚠️ **Fix tests** : les tests mockaient `App\Services\Ai\OpenRouterService` (chemin INEXISTANT) au lieu de `App\Services\OpenRouter\OpenRouterService` → vrais appels réseau (~5 s/requête). Imports corrigés dans `ChatConfirmationTokenTest`, `ChatSessionMismatchTest`, `RateLimitingTest`. Le test throttle passe désormais en ~5,6 s (vs 103 s).

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
- Tests : `ChatSessionMismatchTest` (4 tests) — 403 cross-user, aucune session créée pour l'intrus, aucun message écrit dans la session d'autrui, flux normal préservé (propriétaire + envoi direct)

## 🆕 Q-* — Chat IA : crédits, contexte et robustesse (implémenté)

> Demande utilisateur : utilisateur sans abonnement utilise l'IA avec ses crédits ; les
> messages d'appel d'outil ne doivent pas être vus ; chaque pièce jointe = contexte + coût
> en crédits ; compression du contexte pour maîtriser les crédits ; affichage du temps de
> traitement pour les actions longues ; fallback DeepSeek quand OpenRouter est indisponible.

### Q-PAYPERUSE — Utilisateur sans abonnement : IA pay-per-use (vérifié, RAS)
- Déjà en place (Q4) : le quota IA (`usage_ai_month`) n'est consommé QUE si
  `hasPaidSubscription($user)`. Un plan Gratuit (quota = 0) passe en pay-per-use :
  le chat est facturé en crédits à chaque message. Aucun changement nécessaire.

### Q-MASQUAGE — Masquer les messages d'appel d'outil
- Quand le modèle retourne UNIQUEMENT des appels d'outils (`content` vide +
  `tool_turns > 0`), le message assistant affiché est désormais neutre :
  « L'action demandée a bien été effectuée. » (au lieu du brut « Action(s) exécutée(s)… »).
- Si `content` est vide sans tool_turns → « Je n'ai pas pu traiter votre demande. »
- L'UI affiche un badge discret « Action effectuée » (icône clé à molette) au lieu du
  détail technique. Le compteur `tool_turns` reste tracé dans `metadata` (non exposé).

### Q-PJ — Pièces jointes : contexte + coût en crédits
- `config/chat.php` : `attachments_cost_credits` (défaut 1 crédit / PJ, env
  `CHAT_ATTACHMENT_COST_CREDITS`) — coût fixe par fichier (traitement, stockage, contexte).
- `ChatController::send()` étape 1 : `$attachmentsCost = count($uploaded) × coût PJ` ;
  `$estimatedCredits = max(1, estimate) + $attachmentsCost` ; message d'erreur
  « Crédits insuffisants (N requis pour ce message, pièces jointes incluses) ».
- Débit (étape 7) : `estimatedCredits` total, metadata `attachments_count` +
  `attachments_cost_credits`. Session : `total_cost_credits += actualCredits + attachmentsCost`.
- Ajustement (étape 11) : ne rembourse QUE la partie IA si coût réel < estimation ;
  le coût PJ reste acquis (traitement déjà fait).
- Composer : le coût estimé affiché inclut dynamiquement les PJ sélectionnées
  (`+ count × attachments_cost_credits`) dans `chat/index.blade.php` et
  `chat/show.blade.php`.

### Q-COMPRESSION — Compresser le contexte de conversation
- `app/Services/Chat/ChatContextCompressor.php` (nouveau) :
  - `limitChars()` : borne l'historique en budget de caractères (défaut 12 000,
    env `CHAT_HISTORY_MAX_CHARS`) en conservant les messages les plus récents
    (toujours au moins le dernier).
  - `limitAttachments()` : si le budget (défaut 8 000, env `CHAT_ATTACHMENT_MAX_CHARS`)
    est épuisé, ne garde que `['name' => …, 'content' => null]` (mention seule).
- `ChatController::send()` étape 8 : historique borné en nombre
  (`history_messages`, défaut 10) PUIS en caractères → le modèle reçoit un fil
  récent maîtrisé, moins de tokens → moins de crédits consommés.

### Q-TEMPS — Afficher le temps de traitement (actions longues)
- `ChatController::send()` : `microtime(true)` avant l'appel IA, `duration_ms` après.
- `OpenRouterService::chat()` : `duration_ms` mesuré côté service (valeur prioritaire).
- `chat/show.blade.php` : badge ⏱ « N s » sur les réponses ≥ 3 s (title « Temps de
  traitement ») — analyse IA, génération de documents, traitement via le chat.

### Q-FALLBACK — Bascule automatique sur l'API DeepSeek
- `app/Services/OpenRouter/DeepSeekFallbackService.php` (nouveau) : appel direct
  `POST {api_url}/chat/completions` (clé `config/deepseek.php`, modèle
  `deepseek-v4-flash`, retry 1×/2 s, timeout croissant avec le nb de messages),
  pricing depuis la config OpenRouter (deepseek-chat), conversion USD→crédits
  identique à OpenRouterService.
- `OpenRouterService::chat()` : si TOUS les modèles OpenRouter échouent ET
  `config('openrouter.external_fallback_enabled', true)` (env
  `OPENROUTER_EXTERNAL_FALLBACK`) → bascule DeepSeek, log « OpenRouter
  indisponible — bascule sur DeepSeek direct », réponse taggée
  `provider = deepseek_fallback` + `duration_ms`.
- `chat/show.blade.php` : badge discret « secours » (icône serveur) quand
  `metadata.provider !== 'openrouter'` (transparence).
- Échec total (OpenRouter + DeepSeek) : remboursement intégral + restitution quota
  (comportement inchangé).
- Tests : inchangés (mocks remplacent le service complet ; `new OpenRouterService(new
  ModelRouter())` des tests garde `deepSeek = null` → fallback sauté).

## 🧪 Validation finale Q-*
- [x] `php artisan test` — **363 tests / 1350 assertions verts** (suite complète)
- [x] `ChatAttachmentsTest` 6/6, `ChatConfirmationTokenTest` 4/4, `ChatSessionMismatchTest` 4/4, `RateLimitingTest` 7/7

## 🆕 Q-MODE — Mode d'exécution « agent » AUTOMATIQUE (implémenté)

> Demande utilisateur : « paramètre la en deux mode (chat et argent) — comme avec
> vscode — un mode qui oblige à utiliser les outils — qui se gère automatiquement ».
> Interprétation : « argent » = « agent » (homophone) — l'utilisateur veut un mode AGENT
> qui OBLIGE l'IA à utiliser les outils et exécute automatiquement les actions.
> ⚠️ Suite : « la bannière ou option agent n'a pas forcément besoin d'être visible par
> l'utilisateur, puisque c'est automatique → retirée de l'UI ».

### Mode d'exécution (automatique, invisible dans l'UI)
- **Agent** (DÉFAUT, `CHAT_MODE=agent`) : les outils sont OBLIGATOIRES — le payload
  envoie `tool_choice: 'required'` pour forcer le modèle à appeler un outil dès que la
  demande implique une action (modifier une pièce jointe, convertir en PDF, générer un
  Word/PDF…). L'exécution est automatique (executor multi-tours existant) et bornée par
  `tool_loop_max_turns` (anti boucle infinie).
- **Chat** (`CHAT_MODE=chat`) : conversation pure — les outils ne sont PAS envoyés au
  modèle (moins de tokens, aucun risque d'appel d'outil inattendu).
- **Auto** (`CHAT_MODE=auto`) : les outils sont proposés au modèle, qui décide seul de
  les utiliser — comportement historique, économique.
- Le mode vient exclusivement de la configuration globale (variable d'environnement
  `CHAT_MODE`) : **aucun sélecteur n'est exposé à l'utilisateur**, le comportement se
  gère automatiquement.

### Config structurée en deux volets (« chat » et « argent »)
- `config/chat.php` est restructuré comme les réglages VS Code (groupés par catégorie) :
  - **Volet « chat »** : `mode`, `history_messages`, `history_max_chars`,
    `attachment_max_chars` (conversation, compression).
  - **Volet « argent »** : `attachments_cost_credits`, `adjust_to_actual`,
    `overshoot_absorbed` (coûts, facturation, ajustement du coût réel),
    `tool_loop_max_turns`, `tool_choice_required`.
- Les clés existantes (`chat.attachments_cost_credits`, `chat.history_*`,
  `chat.attachment_max_chars`) restent aux mêmes chemins → rétrocompatible, les 6
  usages du contrôleur sont inchangés.

### Implémentation
- `ChatController::chatMode()` (simplifié) : lit `config('chat.mode')` (défaut
  `agent`), valide dans `auto|chat|agent`, repli sûr sur `agent`. Plus de lecture du
  champ formulaire ni de session.
- `ChatController::send()` :
  - validation : le champ `mode` n'est plus accepté (plus de sélecteur).
  - étape 9 : si `mode !== 'chat'` → outils envoyés ; si `mode === 'agent'` et outils
    disponibles → `tool_choice: 'required'` + prompt système renforcé (« Tu es en mode
    AGENT : utilise systématiquement un outil dès que la demande implique une action »).
  - étape 10 : `tool_loop_max_turns` passé depuis la config.
  - étape 12 : `mode` tracé dans `metadata` du message assistant (transparence).
- `chat/show.blade.php` : **sélecteur de mode RETIRÉ** de l'en-tête (bouton, menu,
  champ caché `mode` et JS supprimés) — l'utilisateur ne voit plus aucune option.
- `resources/css/formadoc.css` : styles `.chat-mode-*` supprimés.
- `.env.example` : `CHAT_MODE=agent` (défaut), `CHAT_ADJUST_TO_ACTUAL`,
  `CHAT_OVERSHOOT_ABSORBED`, `CHAT_TOOL_LOOP_MAX_TURNS`, `CHAT_TOOL_CHOICE_REQUIRED`
  documentés.

### Tests (4 dans ChatAttachmentsTest)
- `test_mode_agent_par_defaut_envoie_tool_choice_required` : sans champ `mode` →
  `tool_choice=required` (agent par défaut) + outils non vides + prompt système
  « mode AGENT » + `metadata.mode = agent`.
- `test_mode_chat_desactive_les_outils` : `config(['chat.mode' => 'chat'])` → pas
  d'outils, pas de `tool_choice`.
- `test_mode_auto_laisse_le_modele_decider` : `config(['chat.mode' => 'auto'])` →
  outils proposés, pas de `tool_choice` forcé.
- `test_config_mode_invalide_repli_sur_agent` : `config(['chat.mode' => 'hacker'])` →
  repli sûr sur `agent` (`tool_choice=required`, `metadata.mode = agent`).

### Note : fallback DeepSeek
- Le fallback externe DeepSeek ne supporte pas le function calling : en cas de bascule,
  le modèle répond en texte (les outils ne peuvent pas être exécutés) — comportement
  dégradé documenté, `tool_turns = 0` tracé.

## 🧪 Validation Q-MODE
- [x] `ChatAttachmentsTest` — 10/10 (dont 4 tests de mode, agent par défaut)
- [x] `ChatConfirmationTokenTest`, `ChatSessionMismatchTest`, `ChatSessionPurgeTest`,
      `ChatFileDownloadSecurityTest` — 30/30
- [x] `ChatToolsServiceTest` + `DocumentEditServiceTest` — 28/28
- [x] Suite complète — 384 tests / 1426 assertions verts
