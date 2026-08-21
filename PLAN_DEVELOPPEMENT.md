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
  - Internes : `cover_page.generate` (page de garde DOCX via `CoverGenerationService`/`CoverPageRenderer`), `document.reconstruct` (reconstructeur), `table_of_contents` (sommaire TOC PhpWord), `structure.correct` (corrections de structure).
  - Externes : `web.search` (recherche web native OpenRouter), `image.generate` (gpt-image-*).
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

## Prochaines phases (pistes)

- **Phase 9** — Abonnements récurrents (renouvellement automatique, prorata), factures, UI des tâches IA (PowerPoint, analyse d'image)
- **Phase 10** — Statistiques d'usage par utilisateur, tableau de bord admin
- **Phase 11** — Internationalisation (EN/FR), devises multiples
