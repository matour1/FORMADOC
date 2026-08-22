# 📋 CAHIER DES CHARGES — FORMADOC

**Version** : 3.1 (Phase 9 — Abonnements récurrents, factures PDF, multi-devises)
**Statut** : ✅ Implémenté (Phase 3 sur `feature/evolutions-saas-phase3` + Phase 9 sur `feature/phase9-subscriptions`)
**Produit** : FORMADOC — Mise en forme automatique de rapports académiques, SaaS avec assistance IA avancée.

---

## Sommaire des évolutions Phase 3

| # | Exigence | Statut | Fichiers clés |
|---|----------|--------|---------------|
| **A** | Chat IA actionnable (outils internes + externes, function calling) | ✅ | `ChatToolsService`, `OpenRouterService`, `ChatController` |
| **B** | Skills documentaires Claude (docx/xlsx/pptx/pdf) — Standard+ ou pay-per-use ×1,5 | ✅ | `ClaudeSkillsService`, `config/anthropic.php`, `guide-skills-documentaires-api-claude.md` |
| **C** | Règle de rentabilité : `prix_public = coût_API × (1+infra) × (1+marge)`, marge 40-60 % | ✅ | `UsageCostCalculator::profitabilityCoefficient()`, `config/openrouter.php` |
| **D** | Nouveaux quotas mensuels par plan (Gratuit/Standard/Premium/Pro/Entreprises) | ✅ | `QuotaService`, `PlanSeeder`, migration `2026_08_20_000001_add_quotas_to_plans_and_users` |
| **E** | Intégration template HTML `formadoc-template.html` + landing page fidèle | ✅ | `resources/css/formadoc.css`, `resources/views/landing.blade.php` |
| **F** | MAJ cahier des charges + code + estimation coûts + plan d'action priorisé | ✅ | Ce document, `README.md`, `PLAN_DEVELOPPEMENT.md` |
| **G** | **Phase 9** : abonnements récurrents (carte KPay, renouvellement auto, prorata), factures PDF, multi-devises | ✅ | `SubscriptionService`, `InvoiceService`, `RenewSubscriptions`, `config/billing.php` |

---

## A. Chat IA actionnable (outils internes + externes + function calling)

### Objectif

Le chat ne se limite plus à répondre en texte : le modèle peut **exécuter des actions** concrètes via le function calling OpenRouter, avec boucle outil → réponse.

### Outils internes (exécution locale)

| Outil | Description | Implémentation |
|-------|-------------|----------------|
| `cover_page.generate` | Génère une page de garde DOCX depuis un gabarit de couverture (`template_id` + valeurs injectées dans les `{{placeholders}}`) | `CoverGenerationService` + `CoverPageRenderer` |
| `document.reconstruct` | Reconstruit un DOCX complet depuis une structure JSON détectée (titres, sous-titres, en-têtes, pieds, tableaux, images, légendes) en appliquant la convention rapport de stage | Reconstructeur interne (PHPWord) |
| `table_of_contents` | Génère un sommaire (champ TOC PhpWord) | PHPWord |
| `structure.correct` | Corrige une structure détectée : promouvoir/rétrograder des titres, retirer des items | `StructureCorrectionService` |

### Outils externes (via OpenRouter)

| Outil | Description |
|-------|-------------|
| `web.search` | Recherche web native OpenRouter (`web_search_options`) |
| `image.generate` | Génération d'image (`openai/gpt-image-*`) |

### Boucle de function calling (`OpenRouterService::chat`)

1. La requête est envoyée avec `tools[]` (schémas OpenAI) et `tool_choice`.
2. Si le modèle demande un outil (`tool_calls`), le **callable `executor`** fourni par `ChatController` l'exécute : il reçoit `(array $toolCall, int $turn)` et retourne `{result: string, error?: string}`.
3. Le résultat est renvoyé au modèle, qui peut enchaîner d'autres outils ou produire sa réponse finale.
4. La boucle est bornée par `tool_loop_max_turns` (défaut 5) pour éviter les boucles infinies.
5. Le coût réel (tokens + appels d'outils) est consigné et facturé en crédits.

### Parcours du message (13 étapes — `ChatController::send`)

1. Validation + estimation du coût (crédits requis affichés avant envoi)
2. Vérification du solde et du quota IA mensuel
3. Débit du coût estimé
4. Construction du contexte (10 derniers messages)
5. Sélection du modèle (tâche × plan) via `ModelRouter`
6. Appel OpenRouter avec tools/executor (boucle)
7. Exécution des tool_calls demandés par le modèle
8. Récupération du coût réel (usage)
9. Ajustement : remboursement du différentiel si coût réel < estimé (jamais de sur-débit)
10. Persistance du message assistant + du coût
11. Consommation du quota IA mensuel
12. Journalisation de la transaction de crédits
13. Réponse JSON au client (contenu + coût + modèle)

---

## B. Skills documentaires Claude (docx/xlsx/pptx/pdf) — expérimental

### Objectif

Permettre de générer des documents natifs (Word, Excel, PowerPoint, PDF) par simple description, via les **Skills documentaires de l'API Anthropic** — inclus dans les **plans payants** (Standard et supérieurs) ou en **pay-per-use** en crédits (Phase 9, Q4).

### Caractéristiques

- **Éligibilité** (Phase 9) : `ClaudeSkillsService::isEligible()` retourne vrai si
  - `hasPaidSubscription($user)` — abonnement **Standard ou supérieur** (`skills_min_plan` = `standard`, classement `PLAN_RANKS`), **ou**
  - `$user->hasCredits(minPayPerUseCredits())` — **pay-per-use** : le coût est estimé (×1,5 sans abonnement, min 1 crédit) et débité avant génération.
- **Chat IA plan Gratuit** : le quota IA étant 0, les non-abonnés sont facturés en crédits (pay-per-use ×1,5) sans consommer de quota.
- **Expérimental** : ne doit JAMAIS bloquer l'application — toute erreur lève une exception propre attrapée par l'appelant, qui bascule sur le **fallback interne** (PHPWord / outils internes).
- **Modèle** : `claude-sonnet-4-6` (configurable via `ANTHROPIC_MODEL`).
- **Bêta skills** : header `betas: ["skills-2025-10-02"]`, `container.skills` avec `skill_id` docx/xlsx/pptx/pdf.
- **Exécution de code** : `tools: [code_execution_20260521]` — le modèle exécute du code dans un conteneur sandbox et produit le fichier.
- **Récupération du fichier** : extraction de `file_id` depuis les blocs `bash_code_execution_tool_result`, téléchargement via l'API Files Anthropic, stockage sur le disk `local`.

### Coûts (crédits) — majorés

| Composant | Coût |
|-----------|------|
| Tokens modèle (input + output) | Tarif Anthropic, converti en crédits |
| Conteneur d'exécution | 0,05 $/h, **minimum 5 min par exécution** |
| Coefficient de rentabilité | ×2 (infrastructure + marge 40-60 %) |
| **Pay-per-use sans abonnement** | **×1,5** supplémentaire (`skills_no_subscription_multiplier`), min 1 crédit |

### Flux technique

1. `POST /v1/messages` avec le container de skills + code execution
2. Le modèle répond avec des blocs `bash_code_execution_tool_result`
3. Extraction de `file_id` → téléchargement via l'API Files
4. Stockage du fichier dans le disk `local`
5. Retour `{path, filename, cost_credits, model}` — fallback interne si erreur

### Variables d'environnement

| Variable | Description | Défaut |
|----------|-------------|--------|
| `ANTHROPIC_API_KEY` | Clé API Anthropic (jamais exposée côté client) | — |
| `ANTHROPIC_API_URL` | URL de l'API | `https://api.anthropic.com/v1` |
| `ANTHROPIC_MODEL` | Modèle utilisé | `claude-sonnet-4-6` |
| `ANTHROPIC_CREDIT_MULTIPLIER` | Coefficient de rentabilité | `2.0` |
| `SKILLS_MIN_PLAN` | Plan minimal inclus | `standard` |
| `SKILLS_NO_SUBSCRIPTION_MULTIPLIER` | Majoration pay-per-use | `1.5` |

---

## C. Règle de rentabilité

### Formule

```
prix_public = coût_API × (1 + infrastructure) × (1 + marge)
```

Avec `infra = 0.15` et `marge = 0.60` :

```
prix_public ≈ coût_API × 1.15 × 1.60 ≈ coût_API × 1.84 (≈ ×2)
```

### Implémentation

- **`UsageCostCalculator::profitabilityCoefficient()`** : retourne `(1 + cost_infrastructure) × (1 + cost_margin)`.
- **`config/openrouter.php`** : `cost_infrastructure = 0.15`, `cost_margin = 0.60` (la marge historique de 20 % a été relevée à 60 % pour respecter l'exigence « marge 40-60 % »).
- Le coefficient s'applique aux **deux** services : `OpenRouterService` (chat/analyse/génération) et `ClaudeSkillsService` (skills documentaires).

### Justification

- Couvre l'infrastructure (+15 %), la marge de pérennité du service (40-60 %), les coûts de support et d'infrastructure GPU.
- Le tarif public reste **affiché avant** toute action IA (transparence).

---

## D. Nouveaux quotas mensuels par plan

### Barème (1 crédit = 1 FCFA)

| Plan | Prix / mois | Docs déterministes | Traitements IA | Crédits inclus |
|------|-------------|--------------------|----------------|----------------|
| **Gratuit** | 0 FCFA | 5 | 0 | 0 |
| **Standard** | 3 000 FCFA | 10 | 5 | 3 000 |
| **Premium** | 5 000 FCFA | 30 | 15 | 5 000 |
| **Pro** | 13 500 FCFA | illimité (`null`) | 90 | 13 500 |
| **Entreprises** | Sur devis | illimité (`null`) | illimité (`null`) | Sur mesure |

### Règles métier (`QuotaService`)

- `quota = null` → **illimité**.
- Compteur **mensuel** (`quota_period = 'monthly'`), réinitialisation automatique au premier usage du nouveau mois (`resetIfNeeded`).
- L'IA est **optionnelle** : quota IA épuisé → le mode déterministe reste disponible, l'utilisateur peut acheter des crédits.
- Le déterministe ne consomme **aucun crédit**, uniquement le quota.
- Verrouillage `SELECT ... FOR UPDATE` pour éviter les dépassements concurrents.
- Le plan « default » (gratuit) n'est pas une ligne en base : ses quotas (5 déterministes / 0 IA) sont définis dans `QuotaService`.

### Schéma

- Table `plans` : `quota_deterministic`, `quota_ai`, `quota_period` (migration `2026_08_20_000001_add_quotas_to_plans_and_users`).
- Table `users` : `usage_deterministic_month`, `usage_ai_month`, `usage_month_started_at`.

### Application

- **Upload** (`DocumentController::upload`) : vérification du quota déterministe avant analyse ; vérification du quota IA avant une analyse IA.
- **Chat** (`ChatController::send`) : consommation du quota IA mensuel.
- **Compte** (`AccountController`) : affichage des quotas consommés / restants (mois en cours).

---

## E. Intégration du template HTML + landing page

### Objectif

Unifier toute l'interface sur un **design system unique** (fidèle à `formadoc-template.html` + `landingPage.html`), accessible et responsive, avec dark mode.

### Design system (`resources/css/formadoc.css`)

| Élément | Valeur |
|---------|--------|
| Couleur primaire | `--color-primary: #2b3f66` |
| Couleur secondaire | `--color-secondary: #3c7a63` |
| Couleur correction | `--color-correction: #a8391f` |
| Couleur warning | `--color-warning: #8a5a12` |
| Rayon | `--radius: 14px` |
| Polices | Newsreader (titres), Inter (texte), IBM Plex Mono (code) |
| Dark mode | `[data-theme="dark"]` (persisté en `localStorage 'formadoc-theme'`) |
| Icônes | **lucide** (pas Material Symbols) |

### Vues converties (toutes ✅ validées navigateur)

Landing (`/`), auth (`/login`, `/register`), chat (`/chat`, `/chat/{id}`), compte (`/account`), parcours document (`/documents/upload|show|processing|export`), pages de garde (`/cover-templates`, `/cover-templates/{id}/edit`), feedback (`/feedback`), layout applicatif (sidebar + navbar + footer), welcome (redirection).

### Build frontend

```bash
npx vite build
```

⚠️ **Obligatoire après toute modification CSS** — le build précédent est consigné (dernier : `app-Zjbh-iZY.css`, 99.65 kB).

---

## F. Documentation, estimation des coûts et plan d'action

### Livrables

| Livrable | Fichier |
|----------|---------|
| Cahier des charges | `CAHIER_DES_CHARGES.md` (ce document) |
| MAJ du README | `README.md` (tarifs, quotas, skills, tools, design system) |
| Plan de développement | `PLAN_DEVELOPPEMENT.md` (Phase 8+) |
| Guide technique Skills Claude | `guide-skills-documentaires-api-claude.md` |
| Contexte KPay | `kpay-context-php.md` |

### Estimation des coûts (hypothèses)

| Poste | Coût |
|-------|------|
| OpenRouter (chat/analyse standard) | ~0,75 $/mois ≈ 460 FCFA pour un usage standard |
| Conteneur Skills Claude | 0,05 $/h, min 5 min/exécution (~0,004 $/exécution) |
| Coût public appliqué | ×1.84 (infra 15 % + marge 60 %) — coefficient `profitabilityCoefficient()` |

### Plan d'action priorisé

1. ✅ Quotas mensuels + branchement upload/chat (D)
2. ✅ Règle de rentabilité ×2 (C)
3. ✅ Chat actionnable avec function calling (A)
4. ✅ Skills Claude Pro expérimentaux avec fallback interne (B)
5. ✅ Design system + landing + vues converties (E)
6. ✅ Documentation + tests + workflow Git (F)

---

## G. Phase 9 — Abonnements récurrents, factures PDF, multi-devises (✅ Implémentée)

Branche : `feature/phase9-subscriptions`. Décisions actées (Q1-Q6) : **Q1a** renouvellement auto (cron 06:00) ; **Q2a** prorata au changement de plan ; **Q3a** factures PDF ; **Q4** Excel/PPtX inclus plans payants (Standard+) **ou** pay-per-use ×1,5 en crédits ; **Q5b** devises multiples FCFA/EUR/USD ; **Q6a** flux KPay conservé (init → passerelle → webhook HMAC).

### G.1 Abonnements récurrents

| Règle | Décision |
|-------|----------|
| Renouvellement | Automatique chaque mois via cron quotidien 06:00 (`RenewSubscriptions`), même carte KPay |
| Période de grâce | 5 jours avant expiration (`grace_days`) |
| Résiliation | Immédiate, **prorata crédité** : `prix × (jours restants / 30)` (`prorata` = true) |
| Plan Gratuit | Activation directe sans paiement, `auto_renew` = false |
| Statuts | `active \| pending \| canceled \| expired \| failed` |

- **`SubscriptionService`** (`app/Services/Billing/`) : `initiateSubscription()` (KPay/gratuit), `activateFromWebhook()` (HMAC → abonnement + facture), `renew()`, `cancelCurrent()`, `markFailedPayment()`, `expire()`, `subscriptionsDueForRenewal()`, `priceInCurrency()`, `formatPrice()`.
- **`KPayController`** : endpoints init/retour/webhook (signature HMAC-SHA256, fenêtre 10 min, idempotence).
- Migration `2026_08_21_000001_add_recurring_subscriptions_and_invoices.php` (tables `subscriptions` + `invoices`).

### G.2 Factures PDF (Q3a)

- **`InvoiceService`** : numéro lisible `INV-AAAA-XXXXXX` (séquence annuelle), génération PDF via PhpWord (writer PDF, fallback Word2007), stockage disk `local`, `metadata.pdf_path`, `downloadPath()` régénère si absent.
- Facture émise à **chaque paiement** : souscription, renouvellement, achat de crédits.
- **`Invoice::formattedAmount()`** : FCFA entier (`5 000 FCFA`), EUR/USD décimal (`7,62 €`).

### G.3 Skills Excel/PPtX : plans payants ou pay-per-use (Q4)

- `ClaudeSkillsService::isEligible()` : abonnement **Standard+** (inclus) **ou** crédits suffisants (**pay-per-use ×1,5**, min 1 crédit).
- `effectiveCost()` : estimation ×1,5 sans abonnement ; débit réel dans `ChatController::executeTool()` avant génération, remboursement du différentiel après coût réel, remboursement intégral en cas d'échec.
- Chat IA plan Gratuit : pay-per-use en crédits (quota IA = 0 pour les non-abonnés).
- Config : `billing.skills_min_plan` (`standard`), `billing.skills_no_subscription_multiplier` (`1.5`).

### G.4 Multi-devises (Q5b)

- `config/billing.php` → `currencies` : XAF (1.0), EUR (655.957), USD (620) ; `default_currency` = XAF.
- Sélecteur de devise sur `/account` (GET `?currency=`), prix affichés dans la devise choisie, lien checkout avec `currency`.

### G.5 Tests & fichiers

- `SubscriptionServiceTest` (25 tests), `InvoiceServiceTest` (8 tests), `ClaudeSkillsServiceTest` (26 tests).
- Suite complète : **288 tests** — verte.
- Fichiers : migration `2026_08_21_000001_...`, `app/Services/Billing/{SubscriptionService,InvoiceService}.php`, `app/Models/{Subscription,Invoice}.php`, `app/Console/Commands/RenewSubscriptions.php`, `routes/{saas,console}.php`, `config/{billing,kpay}.php`, vues `account/index`, `chat/{index,show}`.

---

## Non-régression

- **Mode déterministe intact** : le pipeline sans IA reste 100 % fonctionnel et gratuit.
- **Fallback systématique** : tout échec IA (clé vide, quota épuisé, API down) retombe sur le mode déterministe ou les outils internes.
- **Sécurité** : clés API jamais côté client, webhook KPay signé HMAC = seule source d'autorité, idempotence par référence.
