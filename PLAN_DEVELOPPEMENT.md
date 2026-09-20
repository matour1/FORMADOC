# PLAN_DEVELOPPEMENT — FORMADOC

Plateforme de mise en forme automatique de rapports académiques, devenue **SaaS avec assistance IA avancée et monétisation par crédits**.

---

## Phase 0 — Squelette (✅ Terminée)

- Laravel 13, MySQL, migrations de base
- Formulaire de feedback (`/feedback`)

## Phase 1 — Détection de structure (✅ Terminée)

- Extraction du texte (`.docx`/`.doc`/`.txt`)
- Détection des titres par regex (méthode déterministe) et par IA (DeepSeek)
- Détection des légendes (`Figure N:`)

## Phase 2 — Extraction gabarit + génération DOCX (✅ Terminée)

- `TemplateExtractionService` : extraction du gabarit depuis un document modèle
- Génération DOCX stylisée (styles natifs Heading 1/2/3)

## Phase 3 — Couverture (✅ Terminée)

- `CoverDetectionService` + `CoverGenerationService`
- Génération de page de couverture personnalisée

## Phase 4 — Interface de validation (✅ Terminée)

- Affichage des ambiguïtés de numérotation
- Correction par l'utilisateur avant traitement

## Phase 5 — Parcours complet (✅ Terminée)

- Upload → validation → traitement → export (aperçu PDF + DOCX)

## Phase 6 — IA optionnelle + gabarits (✅ Terminée)

- `AiCorrectionService` : post-processeur IA (listes, ambiguïtés) — **optionnel**, jamais actif sans action explicite
- Gabarits Rapport / Mémoire / Document professionnel
- Aperçu PDF via LibreOffice
- Comparaison avec/sans IA

## Phase 7 — SaaS & monétisation par crédits (✅ Terminée)

### Objectif

Évoluer vers une plateforme SaaS avec assistance IA avancée et monétisation par crédits, sans casser le mode déterministe existant.

### Authentification (Phase 7b — ✅ Terminée)

- `AuthController` : inscription / connexion / déconnexion par session
- `routes/auth.php` (chargé via `bootstrap/app.php`) : `/login`, `/register`, `/logout` — `routes/web.php` intacte
- Middleware `web` + `auth` explicites sur les routes SaaS (chargées hors groupe web par défaut)
- Mots de passe hachés (`bcrypt`), régénération de session à la connexion, « Se souvenir de moi »
- Vues `resources/views/auth/{login,register}.blade.php` + boutons dans le layout (état `@auth`/`@guest`)
- Redirection par défaut du middleware `auth` → `config/auth.php` (`redirects.login` = `login`)

### Architecture IA

**ModelRouter** (`app/Services/OpenRouter/ModelRouter.php`) — routage centralisé OpenRouter :

- Sélection du modèle selon `task_type` × `plan` (default/standard/premium/pro/enterprise)
- Fallbacks automatiques (liste ordonnée de candidats)
- Repli sur le plan `default` si le plan n'est pas configuré
- 8 tâches configurées : `chat_text`, `document_analysis`, `document_full_format`, `image_generation`, `image_analysis`, `web_search`, `function_calling`, `powerpoint_generation`

**OpenRouterService** (`app/Services/OpenRouter/OpenRouterService.php`) :

- Appels `chat/completions` compatibles OpenAI (Authorization Bearer, HTTP-Referer, X-Title)
- Retry/backoff sur erreurs récupérables (429, 5xx, connexion), timeout dynamique
- Consignation du coût réel (USD) + estimation en crédits (marge 20 %)
- **La clé API n'est jamais exposée côté client**

### Monétisation

| Plan | Prix | Crédits/mois |
|------|------|--------------|
| Défaut | 0 FCFA | à la carte |
| Standard | 3 000 FCFA | 3 000 |
| Premium | 5 000 FCFA | 5 000 |
| Pro | 8 000 FCFA | 8 000 |
| Entreprises | Sur devis | Sur mesure |

- **1 crédit = 1 FCFA**, marge 20 %, taux `RATE_FCFA_PER_USD` = 620
- Achat à la carte : **minimum 500 FCFA** via KPay
- Estimation des coûts OpenRouter **avant** tarification (hypothèse : ~0,746 $/mois ≈ 460 FCFA pour un usage standard, soit ~500 crédits)

### Paiement KPay

- `KPayService` : init de passerelle (`/payments/init`), récupération de statut, vérification HMAC
- **Webhook signé HMAC-SHA256 = seule source d'autorité** : crédits crédités uniquement sur événement `completed`
- Idempotence par référence (`paymentId`), fenêtre de signature 10 min sur le retour
- Backoff 1s/2s/4s sur 429
- Retour utilisateur : vérifie la signature mais **n'accrédite jamais**

### Crédits

- `CreditService` : débit/crédit **atomique** (transaction + `lockForUpdate`), journalisation dans `credit_transactions`, refus si solde insuffisant
- `UsageCostCalculator` : conversion USD → crédits (ceil, marge)
- Détection d'insuffisance **avant** appel (estimation) + remboursement du différentiel après appel

### Chat IA

- `/chat` : sessions persistantes, historique (10 derniers messages envoyés comme contexte)
- Affichage du modèle utilisé et du coût en crédits par message
- `total_cost_credits` agrégé par session

### Mise en forme assistée (file d'attente)

- `LongFormattingJob` : mise en forme complète du document par IA en tâche de fond (tries 3, timeout 600 s, backoff 30 s), vérification du solde avant traitement, remboursement du différentiel

### Sécurité

- Clé API OpenRouter et clés KPay **jamais côté client** (config `config/openrouter.php`, `config/kpay.php`)
- Webhook vérifié par HMAC du corps brut (`hash_equals`)
- Seuls les statuts terminaux (`completed`/`failed`/`cancelled`) déclenchent une décision
- Mode déterministe intact : le pipeline sans IA reste 100 % fonctionnel et gratuit

### Fichiers de la phase

```
config/openrouter.php, config/kpay.php
database/migrations/2026_08_19_000001_add_credits_to_users_table.php
database/migrations/2026_08_19_000002_create_saas_tables.php
database/seeders/PlanSeeder.php
app/Models/{Plan,Subscription,CreditTransaction,ChatSession,ChatMessage}.php
app/Models/User.php (étendu : credits_balance, relations)
app/Services/OpenRouter/{ModelRouter,OpenRouterService}.php
app/Services/Billing/{CreditService,UsageCostCalculator,KPayService}.php
app/Http/Controllers/{AccountController,ChatController,KPayController}.php
app/Jobs/LongFormattingJob.php
routes/saas.php (chargé via bootstrap/app.php — routes/web.php intacte)
resources/views/account/index.blade.php, chat/{index,show}.blade.php
tests/Unit/Services/OpenRouter/ModelRouterTest.php
tests/Unit/Services/Billing/{CreditServiceTest,UsageCostCalculatorTest}.php
```

### Tests

- **193 tests, 783 assertions** — tous verts ✅
- Couverture : ModelRouter (sélection, fallbacks, repli plan, erreurs), CreditService (débit/crédit atomiques, insuffisance), UsageCostCalculator (arrondis, modèles inconnus)

---

## Phase 8 — Évolutions majeures du cahier des charges (✅ Terminée)

Branche : `feature/evolutions-saas-phase3`. Cahier des charges v3.0 : `CAHIER_DES_CHARGES.md`.

### 8.1 — Chat IA actionnable (exigence A)

- **`ChatToolsService`** (`app/Services/Chat/`) : outils actionnables exposés au chat.
  - Documents : `document_analyze`, `document_edit`, `document_reconstruct`, `document_create`, `document_to_pdf`, `document_to_docx`, `table_of_contents`, `structure_correct`.
  - Édition structurelle (R6, sur document analysé et persisté) : `rewrite_paragraph`, `insert_block`, `modify_table`, `delete_block`, `regenerate_section`, `undo_last_action`.
  - Externes : `web_search` (recherche web native OpenRouter), `image_generate` (gpt-image-*).
  - ⚠️ `cover_page.generate` (page de garde via `CoverGenerationService`/`CoverPageRenderer`) a été **retiré du produit** : ces classes n'existent plus, et `CoverModuleRemovalInvariantTest` interdit leur retour.
- **`OpenRouterService::chat()`** : support du function calling OpenAI (`tools[]`, `tool_choice`, `executor` callable, boucle bornée `tool_loop_max_turns` = 5).
- **`ChatController::send`** : parcours en 13 étapes (estimation → solde/quota → débit → contexte → modèle → appel → exécution des outils → coût réel → remboursement différentiel → persistance → quota → journalisation → réponse).

### 8.2 — Skills documentaires Claude — Pro only (exigence B)

- **`ClaudeSkillsService`** (`app/Services/Anthropic/`) : génération de fichiers natifs **docx/xlsx/pptx/pdf** via l'API Anthropic.
  - `POST /v1/messages`, modèle `claude-sonnet-4-6`, bêta `skills-2025-10-02`, `container.skills` (docx/xlsx/pptx/pdf), `tools: [code_execution_20260521]`.
  - Récupération du `file_id` depuis `bash_code_execution_tool_result` → téléchargement via l'API Files → stockage disk `local`.
  - **Éligibilité** : abonnement Pro actif + clé API configurée (`isEligible()`).
  - **Coût** : tokens modèle + conteneur 0,05 $/h (min 5 min/exécution), majoré ×2 (coefficient de rentabilité).
  - **Fallback systématique** sur les outils internes (PHPWord) en cas d'échec — jamais bloquant.
- `config/anthropic.php` + variables `ANTHROPIC_*` dans `.env`.
- Guide : `guide-skills-documentaires-api-claude.md`.

### 8.3 — Règle de rentabilité (exigence C)

- Formule : `prix_public = coût_API × (1 + infra) × (1 + marge)`.
- `UsageCostCalculator::profitabilityCoefficient()` : `(1 + 0.15) × (1 + 0.60) ≈ 1.84` (≈ ×2 le coût direct).
- Config : `config/openrouter.php` (`cost_infrastructure` = 0.15, `cost_margin` = 0.60 — relevée de 0.20).
- Appliqué par `OpenRouterService` (chat/analyse/génération) et `ClaudeSkillsService` (skills).

### 8.4 — Quotas mensuels par plan (exigence D)

| Plan | Prix/mois | Docs déterministes | Traitements IA | Crédits |
|------|-----------|--------------------|----------------|---------|
| Gratuit | 0 FCFA | 5 | 0 | 0 |
| Standard | 3 000 FCFA | 10 | 5 | 3 000 |
| Premium | 5 000 FCFA | 30 | 15 | 5 000 |
| Pro | 13 500 FCFA | illimité | 90 | 13 500 |
| Entreprises | Sur devis | illimité | illimité | Sur mesure |

- **`QuotaService`** : `canUse()` / `consume()` (verrouillage `FOR UPDATE`), reset mensuel automatique (`resetIfNeeded`), `null` = illimité.
- Migration `2026_08_20_000001_add_quotas_to_plans_and_users` (colonnes `plans.quota_deterministic`, `plans.quota_ai`, `plans.quota_period`, `users.usage_*_month`, `users.usage_month_started_at`).
- Branchement : `DocumentController::upload` (quota déterministe/IA), `ChatController::send` (quota IA), `AccountController` (affichage).

### 8.5 — Design system + landing page (exigence E)

- `resources/css/formadoc.css` : variables `--color-*`, dark mode `[data-theme="dark"]`, polices Newsreader/Inter/IBM Plex Mono, icônes lucide.
- Landing page fidèle à `landingPage.html` : hero, fonctionnalités, parcours 4 étapes, tarifs (Gratuit/Standard/Premium/Pro/Entreprises), FAQ, footer.
- Refonte de TOUTES les vues legacy : auth, chat, compte, upload/show/processing/export, cover-templates (index/edit), feedback, welcome.
- Build : `npx vite build` (obligatoire après toute modif CSS).

### 8.6 — Documentation (exigence F)

- `CAHIER_DES_CHARGES.md` créé (v3.0 — 6 évolutions A-F).
- `README.md` mis à jour (tarifs, quotas, skills, tools, design system, variables env).
- `PLAN_DEVELOPPEMENT.md` mis à jour (cette phase).
- Estimation des coûts : OpenRouter ~0,75 $/mois usage standard ; conteneur Skills 0,05 $/h (min 5 min) ; coefficient public ×1.84.

### Fichiers de la phase

```
database/migrations/2026_08_20_000001_add_quotas_to_plans_and_users.php
database/seeders/PlanSeeder.php (barème révisé)
app/Services/Billing/QuotaService.php
app/Services/Billing/UsageCostCalculator.php (profitabilityCoefficient, marge 0.60)
app/Services/Chat/ChatToolsService.php
app/Services/Anthropic/ClaudeSkillsService.php
app/Services/OpenRouter/OpenRouterService.php (function calling + executor)
app/Http/Controllers/{ChatController,DocumentController,AccountController}.php
config/{openrouter,anthropic}.php
resources/css/formadoc.css (design system + landing)
resources/views/{landing, welcome}.blade.php + refonte de toutes les vues
tests/Unit/Services/Billing/{QuotaServiceTest, UsageCostCalculatorTest}.php
tests/Unit/Services/Anthropic/ClaudeSkillsServiceTest.php
tests/Unit/Services/Chat/ChatToolsServiceTest.php
database/factories/{PlanFactory, SubscriptionFactory}.php
app/Models/{Plan, Subscription}.php (trait HasFactory)
CAHIER_DES_CHARGES.md
```

### Tests (Phase 8)

- MAJ `UsageCostCalculatorTest` (marge 0.20 → 0.60, coefficient ×1.84).
- `QuotaServiceTest` (18 tests : canUse/consume, reset mensuel, illimité, verrouillage) + factories `PlanFactory` / `SubscriptionFactory` créées.
- `ClaudeSkillsServiceTest` (13 tests : parsing file_id, échec, coût majoré min 5 min, isEligible Pro-only).
- `ChatToolsServiceTest` (9 tests : schemas, availableTools, execute, outil inconnu, web.search, image.generate, cover_page, structure.correct, table_of_contents).
- Suite complète : **248 tests, 944 assertions** — verte.

---

## Phase 9 — Abonnements récurrents, factures PDF, Excel/PPtX, multi-devises (✅ Terminée)

Branche : `feature/phase9-subscriptions`. Décisions actées (Q1-Q6) :
**Q1a** renouvellement automatique (cron quotidien 06:00) ; **Q2a** prorata crédits au changement de plan ; **Q3a** factures PDF ; **Q4** Excel/PPtX inclus dans les plans payants (Standard+) **ou** pay-per-use ×1,5 en crédits sans abonnement ; **Q5b** devises multiples FCFA/EUR/USD ; **Q6a** flux KPay conservé (init → passerelle → webhook HMAC).

### 9.1 — Abonnements récurrents

- **`SubscriptionService`** (`app/Services/Billing/`) :
  - `initiateSubscription()` : abonnement payant → init KPay (carte) avec idempotence ; plan gratuit → activation directe (`auto_renew` = false).
  - `activateFromWebhook()` : validation HMAC → création abonnement `active` + **facture PDF** (Q3a).
  - `renew()` : renouvellement automatique (même carte KPay), statut `pending` pendant le paiement, `failed` en cas d'échec.
  - `cancelCurrent()` : résiliation immédiate, **prorata** crédité en crédits (Q2a) : `prix × (jours restants / 30)`.
  - `markFailedPayment()` / `expire()` : gestion des échecs et fin de période (période de grâce `grace_days` = 5).
  - `subscriptionsDueForRenewal()` : requête des abonnements à renouveler (J-3, `renew_days_before`).
- **`RenewSubscriptions` command** (`app/Console/Commands/`) : cron quotidien à **06:00** (`dailyAt('06:00')`, enregistrée dans `routes/console.php`).
- **`KPayController`** : endpoints init/retour/webhook (signature HMAC-SHA256, fenêtre 10 min).
- **`Subscription`** (`app/Models/`) : statuts `active|pending|canceled|expired|failed`, cast `auto_renew`, `isActiveAt()`.
- Migration `2026_08_21_000001_add_recurring_subscriptions_and_invoices.php` (tables `subscriptions` + `invoices`).

### 9.2 — Factures PDF (Q3a)

- **`InvoiceService`** (`app/Services/Billing/`) : numéro lisible `INV-AAAA-XXXXXX` (séquence annuelle), génération PDF via PhpWord (writer PDF / fallback Word2007), stockage disk `local` (`config billing.invoice_storage_disk`), `metadata.pdf_path` journalisé.
- **`Invoice`** (`app/Models/`) : types `subscription` / `credit_purchase`, montant en plus petite unité, `formattedAmount()` (FCFA entier, EUR/USD décimal).
- **`InvoiceService::downloadPath()`** : régénère le PDF si le fichier est absent.
- Facture émise à chaque paiement : souscription, renouvellement, achat de crédits.

### 9.3 — Excel/PPtX : plans payants ou pay-per-use ×1,5 (Q4)

- **`ClaudeSkillsService`** — éligibilité élargie (`isEligible()`) :
  - Abonnement **Standard ou supérieur** (`skills_min_plan` = `standard`, `PLAN_RANKS`) → inclus.
  - **Sinon** : pay-per-use en crédits, coût majoré ×1,5 (`skills_no_subscription_multiplier`) — le chat IA est aussi facturé en crédits sans quota pour les plans Gratuit.
- **`effectiveCost()`** : estimation × 1,5 pour les non-abonnés (min 1 crédit) ; **débit réel** dans `ChatController::executeTool()` (document.skill_generate) avant génération, remboursement du différentiel après coût réel, remboursement intégral en cas d'échec.
- `config/billing.php` : `skills_min_plan`, `skills_no_subscription_multiplier` (1.5), `auto_renew`, `renew_days_before` (3), `grace_days` (5), `prorata` (true), `invoice_storage_disk`, `default_currency`.
- Textes vues chat mis à jour (« inclus Standard+, sinon pay-per-use en crédits ×1,5 »).

### 9.4 — Multi-devises (Q5b)

- `config/billing.php` : `currencies` (XAF taux 1.0 / EUR 655.957 / USD 620), `default_currency` = XAF.
- `SubscriptionService::priceInCurrency()` / `formatPrice()` : conversion + affichage (`13 500 FCFA`, `20,58 €`, `21,77 $`).
- `AccountController::index()` : sélecteur de devise (GET `?currency=`) + prix affichés dans la devise choisie + lien checkout avec `currency`.
- `views/account/index.blade.php` : `<select name="currency">` au-dessus de la grille des plans, auto-submit.

### Fichiers de la phase

```
database/migrations/2026_08_21_000001_add_recurring_subscriptions_and_invoices.php
app/Models/{Subscription, Invoice}.php (+ factories)
app/Services/Billing/{SubscriptionService, InvoiceService}.php
app/Services/Anthropic/ClaudeSkillsService.php (éligibilité pay-per-use, effectiveCost, PLAN_RANKS)
app/Http/Controllers/{KPayController, ChatController, AccountController}.php
app/Console/Commands/RenewSubscriptions.php
routes/saas.php
config/{billing, kpay}.php
routes/console.php
resources/views/{account/index, chat/index, chat/show}.blade.php
tests/Unit/Services/Billing/{SubscriptionServiceTest, InvoiceServiceTest}.php
tests/Unit/Services/Anthropic/ClaudeSkillsServiceTest.php (26 tests)
```

### Tests (Phase 9)

- `SubscriptionServiceTest` (25 tests) : statuts, prix/devises, initiateSubscription (KPay + gratuit), activateFromWebhook (facture + idempotence), cancel prorata, renew (auto/carte/échec), expire, subscriptionsDueForRenewal.
- `InvoiceServiceTest` (8 tests) : numérotation séquentielle annuelle, createForSubscription, createForCreditPurchase, génération PDF stockée + `pdf_path`, `formattedAmount` XAF/EUR/USD.
- `ClaudeSkillsServiceTest` (26 tests) : éligibilité Standard+/crédits, `hasPaidSubscription`, `effectiveCost` ×1,5 (min 1), coût réel.
- Suite complète : **288 tests** — verte.

---

## Phase 9.5 — Correctifs paiements KPay & fiabilisation (✅ Terminée)

Branche : `main` (hotfix direct, validé en session). Suite au test réel du flux de paiement Cameroun.

### Corrections apportées

- **Webhook 419 (CSRF)** : Laravel 13 utilise `PreventRequestForgery` (et non `VerifyCsrfToken`) → exception ajoutée via `validateCsrfTokens(except: ['kpay/webhook'])` dans `bootstrap/app.php`.
- **SMTP « Hôte inconnu »** : faute de frappe `MAIL_HOST=mail.reyes-developeur-web.xys` → corrigé en `.xyz` (DNS vérifié : 213.255.195.67). Port 465 SSL.
- **PDF factures en .docx (fallback)** : renderer dompdf non configuré → `Settings::setPdfRendererName(PDF_RENDERER_DOMPDF)` + `setPdfRendererPath(base_path('vendor/dompdf/dompdf'))` dans `InvoiceService::generatePdf()`. PDF valides `%PDF`.
- **Facture + email de reçu manquants** à l'achat de crédits → ajoutés dans `KPayController::webhook()` et dans la commande de sync.

### Fallback webhook (le webhook KPay ne peut pas joindre localhost)

- **Table `kpay_payments`** (`2026_08_22_234421_create_kpay_payments_table.php`) : enregistre chaque init de paiement (user_id, payment_id, external_id unique, status, purpose, amount_fcfa, currency, return_url, cancel_url, metadata, paid_at, expires_at, sync_attempts, last_synced_at).
- **Commande `kpay:sync`** (`app/Console/Commands/SyncKpayPayments.php`) : poll `GET /api/v1/payments/:id` toutes les 5 min (`routes/console.php`, `everyFiveMinutes()->withoutOverlapping()`), idempotence via `CreditTransaction reference=paymentId type=purchase`, traitement des statuts terminaux (COMPLETED → crédits/abonnement + facture + email ; FAILED/CANCELLED → statut).
- **`KpayPayment` model** : scope `pending()`, relation `user()`.
- **`PaymentReceipt` mailable** + vue markdown `emails/payment-receipt` (composants mail publiés dans `resources/views/vendor/mail`).

### Validation

- Achat réel sandbox 500 XAF (MTN `237653456789` → COMPLETED) → init enregistré en PENDING → `kpay:sync` → COMPLETED, +500 crédits (11 495 → 11 995), transaction purchase ref=paymentId metadata `sync_fallback=true`, facture INV-2026-000003 payée (PDF valide), email de reçu envoyé.
- **288 tests / 1051 assertions** — verts.

---

## Prochaines phases (pistes)

- **Phase 10** — Statistiques d'usage par utilisateur, tableau de bord admin
- **Phase 11** — Internationalisation (EN/FR)

---

## Axes possibles (hors plan initial, à prioriser)

### A1 — Mise en production KPay

- Bascule des clés sandbox (`kpay_test_…`) vers production (`kpay_live_…`) dans `.env` (config `config/kpay.php`).
- **Configurer l'URL de webhook sur `admin.kpay.site`** (volet Dépôts `payment.*`, sinon générique) : `https://votre-domaine.com/kpay/webhook` — c'est elle qui déclenche les crédits en réel.
- Vérifier que `APP_URL` pointe vers le domaine HTTPS final (le `returnUrl` de la passerelle en dépend).
- Test de bout en bout en réel avec un petit montant (ex. 500 XAF) avant communication.

### A2 — Sécurité & robustesse

- **Rate limiting** sur les routes sensibles : `/credits/purchase`, `/checkout/*`, webhook, auth (throttle configurable).
- **Monitoring** : journalisation structurée des échecs KPay, alertes email sur échecs de webhook (`sync_attempts >= 5`), stats dashboard.
- **Sauvegardes** : plan de backup MySQL + storage (`storage/app/private`) automatisé (cron).
- Durcissement : HTTPS forcé en production, headers de sécurité (HSTS, CSP), secrets hors `.env` versionné.

### A3 — Paiement par carte hors passerelle (CARD direct)

- KPay accepte `paymentMethod: CARD|PAYPAL` à l'init (force la passerelle), mais le mode USSD direct (sans passerelle) nécessite `phoneNumber` + `provider` — possible pour les pays avec opérateurs configurés.
- Ajouter un choix « Opérateur » côté formulaire (`predict-provider` pour deviner l'opérateur depuis le numéro) si on veut éviter la passerelle.

### A4 — Retraits & paiements vers bénéficiaires (payouts)

- Endpoint `POST /api/v1/payments/withdraw` (USSD ou GATEWAY avec `returnUrl`), suivi `GET /payments/withdraw/:id`.
- Cas d'usage : reversements aux utilisateurs, remboursements manuels, ou paiement de contributeurs.
- Webhooks dédiés `payout.*` (URL volet Retraits sur admin.kpay.site).
- **Attention** : solde wallet requis (422 si insuffisant), retrait min 100 XAF, commission 5 %.

### A5 — Transferts inter-wallet (multi-pays)

- `POST /api/wallets/transfer` (JWT via `POST /api/v1/payments/token`, 30 min) pour déplacer des fonds entre pays (CMR→GAB 1:1, CMR→SEN taux réel).
- Taux consultable via `GET /api/v1/payments/exchange-rate`.
- Cas d'usage : consolider les fonds des wallets pays vers un wallet central, ou alimenter un wallet avant un payout.

### A6 — Tableau de bord opérateur / gestion

- Vue admin : soldes wallets KPay (`GET /api/v1/payments/balance`), disponibilité opérateurs (`/availability`), historique des paiements, grille tarifaire (`/api/public/pricelist`).
- Interface de remboursement (refund) depuis l'admin : `POST /refunds` (paiement COMPLETED, fenêtre 7 jours, idempotent).

### A7 — Expérience utilisateur paiement

- Page de confirmation dédiée après retour passerelle (statut du paiement, bouton « réessayer » si FAILED/CANCELLED, pas seulement le flash).
- Suivi temps réel du paiement en attente (polling léger côté client pendant SUBMITTED/PROCESSING).
- Email de reçu avec pièce jointe PDF systématique (déjà en place) + relance si échec d'envoi (file d'attente).

### A8 — Finitions copywriting & conformité (reste de l'audit)

> Source : `AUDIT_COPYWRITING.md` — tâches restantes après le lot P0/P1/P2 (commit `b6444ed`).

- **Flux « Mot de passe oublié » (P1)** : routes + vues + email de réinitialisation (mail transactionnel), à ajouter à l'authentification existante.
- **6 emails transactionnels manquants (P1)** : modèles prêts dans `AUDIT_COPYWRITING.md` §6.2 — bienvenue, mot de passe oublié, document prêt, quota dépassé, échec paiement, rappel renouvellement J-3.
- **Ton tutoiement/vouvoiement (P1)** : gros chantier transversal (toutes les vues) — décider avec l'utilisateur : tutoiement app/étudiants, vouvoiement entreprises. Application cohérente « Ton document » vs « vos documents ».
- **Pages légales — infos réelles (P0/P1)** : renseigner les `[À COMPLÉTER]` dans `resources/views/pages/*.blade.php` (RCCM, NIU, adresse, forme juridique, directeur de publication, hébergeur) + remplacer les emails `@formadoc.cm` factices par les vrais.
