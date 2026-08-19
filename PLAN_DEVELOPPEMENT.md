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

## Prochaines phases (pistes)

- **Phase 8** — Abonnements récurrents (renouvellement automatique, prorata), factures
- **Phase 9** — Statistiques d'usage par utilisateur, tableau de bord admin
- **Phase 10** — Internationalisation (EN/FR), devises multiples
