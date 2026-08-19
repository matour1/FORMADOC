# FORMADOC — Mise en forme automatique de rapports académiques

Application web qui prend un rapport académique (stage, projet, mémoire), détecte sa structure automatiquement (titres, hiérarchie, en-têtes, pieds de page, tableaux, images, légendes), applique un gabarit de mise en forme selon les normes de l'établissement, et génère un document `.docx` final prêt à télécharger — avec aperçu PDF avant export.

## 🧭 Parcours utilisateur

```
Upload (.docx/.doc/.txt) → Validation de la structure détectée → Traitement → Export (aperçu PDF + DOCX)
```

1. **Upload** — choix de la méthode de détection + téléversement du fichier (50 Mo max).
2. **Validation** — la structure détectée est affichée : titres, hiérarchie, ambiguïtés de numérotation. L'utilisateur peut corriger avant traitement.
3. **Traitement** — mise en forme automatique selon le gabarit institutionnel (couverture optionnelle).
4. **Export** — aperçu PDF généré par LibreOffice, puis téléchargement du DOCX reconstruit.

## 🔍 Méthodes de détection des titres

| Méthode | Champ du formulaire | Description |
|---------|--------------------|-------------|
| **Analyse rapide (Regex)** | `title_method=regex` (défaut) | Détection par les styles Word (Heading 1-3, tailles, gras) complétée par des motifs regex (numérotation « 1. », « 1.1 », mots-clés CHAPITRE, INTRODUCTION…). **Rapide et 100 % hors-ligne.** |
| **Analyse par IA** | `title_method=ia` | Un modèle de langue (DeepSeek) lit l'intégralité du texte pour classer chaque élément. Plus précise mais plus lente (quelques minutes selon la taille). |

### Assistance IA optionnelle (case « Utiliser l'assistance IA »)

- Paramètre `use_ai` (`0`/`1`), **non cochée par défaut** : aucune donnée n'est envoyée à un service externe sans action explicite de l'utilisateur.
- L'IA agit en **post-processeur** (`AiCorrectionService`) après l'analyse déterministe : elle corrige la classification des **listes** (éléments détectés comme texte mais qui sont des listes à puces) et **lève les ambiguïtés**.
- Les corrections sont tracées dans la structure (`ai_corrections`) et visibles à l'export (carte « Assistance IA »).
- Si `DEEPSEEK_API_KEY` est vide ou si l'API échoue, le pipeline **retombe en mode 100 % déterministe** sans erreur.

### Comparaison avec/sans IA

À l'export, le bouton **« Régénérer sans IA pour comparer »** (`regenerate_without_ai=1`) relance une analyse 100 % déterministe du document afin de comparer visuellement les deux résultats.

## 💳 Plateforme SaaS & monétisation par crédits

FORMADOC est désormais une plateforme SaaS : l'IA avancée (chat, analyse de documents, mise en forme complète, génération d'images, recherche web, function calling, PowerPoint) est facturée en **crédits**, avec paiement via **KPay** (carte/PayPal en priorité, mobile money possible).

### 🔐 Comptes & authentification

- Inscription / connexion par session (`/register`, `/login`) — flux classique Laravel (guard `web`, CSRF, sessions).
- Chaque compte démarre avec **0 crédit** et peut acheter à la carte dès **500 FCFA**.
- Les pages `/account`, `/chat` et les achats sont protégés par le middleware `auth` (redirection vers `/login`).
- Routes d'authentification dans `routes/auth.php` (chargé via `bootstrap/app.php`) — `routes/web.php` reste intacte.

### Barème des plans (1 crédit = 1 FCFA)

| Plan | Prix | Crédits/mois | Idéal pour |
|------|------|--------------|------------|
| **Défaut (gratuit)** | 0 FCFA | Au fur et à mesure | Découverte, usage ponctuel (achat à la carte dès 500 FCFA) |
| **Standard** | 3 000 FCFA/mois | 3 000 | Étudiants, usage régulier |
| **Premium** | 5 000 FCFA/mois | 5 000 | Professionnels |
| **Pro** | 8 000 FCFA/mois | 8 000 | Utilisation intensive |
| **Entreprises** | Sur devis | Sur mesure | Organisations |

Les crédits s'achètent à la carte (**minimum 500 FCFA**) depuis la page **Mon compte** (`/account`).

### Routage des modèles IA (OpenRouter)

`ModelRouter` sélectionne le modèle selon le **type de tâche** et le **plan** de l'utilisateur :

| Tâche | Modèle par défaut | Modèles Premium/Pro |
|-------|-------------------|---------------------|
| `chat_text` | `deepseek/deepseek-chat` | `claude-3.5-sonnet`, `gpt-4o` |
| `document_analysis` | `deepseek/deepseek-chat` | `gpt-4o`, `claude-3.5-sonnet` |
| `document_full_format` | `gpt-4o-mini` | `claude-3-opus`, `claude-3.5-sonnet` |
| `image_generation` | `openai/gpt-image-1-mini` | `openai/gpt-image-1` |
| `image_analysis` | `gpt-4o-mini` | `gpt-4o` |
| `web_search` | `deepseek/deepseek-chat` | `gpt-4o-mini` |
| `function_calling` | `gpt-4o-mini` | `gpt-4o` |
| `powerpoint_generation` | `deepseek/deepseek-chat` | `gpt-4o` |

Chaque tâche dispose de **fallbacks** (repli automatique en cas d'échec avec retry/backoff) et le **coût réel est consigné** (USD) puis converti en crédits consommés (marge 20 %, taux `RATE_FCFA_PER_USD` = 620).

### Paiement KPay

- Passerelle hébergée : `POST /credits/purchase` → redirection vers `gatewayUrl` KPay.
- **Webhook signé HMAC-SHA256** (`X-KPAY-Signature` + `X-KPAY-Event`) : seule source d'autorité — les crédits sont crédités **uniquement** sur événement `completed` signé et valide.
- Idempotence par référence (`paymentId`), fenêtre de signature de 10 min sur le retour, backoff 1s/2s/4s sur les 429.
- Le retour utilisateur (`/credits/return`) vérifie la signature mais **n'accrédite jamais** : décision uniquement côté webhook.

### Chat IA assisté

- Page `/chat` : historique des sessions, nouvelle conversation.
- Chaque message affiche le **modèle utilisé** et le **coût en crédits** réellement consommé.
- Le coût est **estimé avant** envoi (crédits requis affichés), débité avant appel, et le **différentiel est remboursé** si le coût réel est inférieur.

### Mode déterministe intact

Le pipeline de mise en forme **sans IA reste 100 % fonctionnel et gratuit** : les fonctionnalités SaaS sont additionnelles (chat, analyse avancée, mise en forme assistée par IA).

## 🎨 Gabarits de mise en forme

Trois gabarits sont fournis via `TemplateSeeder` et sélectionnables à l'export (`template_id`) :

| Gabarit | Police | Titres | Interligne |
|---------|--------|--------|------------|
| **Rapport** | Times New Roman | Bleu foncé `1F3864` | 1,5 |
| **Mémoire** | Arial | Noirs | Double |
| **Document professionnel** | Calibri | Gris foncé | Simple |

Chaque gabarit définit aussi : tailles de police, espacements, marges (twips), style de tableau (`TableGrid` avec en-tête coloré), alignement des titres.

## 🖨️ Aperçu PDF (LibreOffice)

- Route `POST /documents/{id}/preview-pdf` → génère l'aperçu ; `GET /documents/{id}/preview-pdf/file` sert le PDF inline (iframe).
- Conversion via `PdfPreviewService` (soffice). `LIBREOFFICE_PATH` est optionnel : à défaut, le service auto-détecte `soffice` dans le PATH puis les chemins standards (dont `C:\Program Files\LibreOffice\program\soffice.com`).

## 🚀 Installation

### Prérequis

- PHP 8.2+
- Composer
- MySQL 5.7+ (ou 8.0)
- Node.js & NPM (optionnel, pour assets)

### Installation

```bash
# 1. Cloner le projet
git clone <url-repo> FORMADOC
cd FORMADOC

# 2. Installer les dépendances PHP
composer install

# 3. Copier la configuration
cp .env.example .env
php artisan key:generate

# 4. Configurer la base de données dans .env
# DB_CONNECTION=mysql
# DB_DATABASE=formadoc_v1
# DB_USERNAME=root
# DB_PASSWORD=

# 5. Créer la base et migrer (+ seed des gabarits)
php artisan migrate --seed

# 6. Démarrer le serveur
php artisan serve
```

L'application est alors accessible sur `http://localhost:8000`.

> **Production** : exécuter `php artisan migrate --seed` (ou `php artisan migrate` puis `php artisan db:seed --class=TemplateSeeder`) sur l'environnement de déploiement pour créer la table `cover_page_templates` et insérer les 3 gabarits. Vérifier aussi `DEEPSEEK_API_KEY` (vide = IA désactivée, pipeline 100 % hors-ligne) et `LIBREOFFICE_PATH` (optionnel).

## 🏗️ Structure du projet

```
app/
├── Http/Controllers/          # Contrôleurs (Feedback, Document, Cover, Validation, Account, Chat, KPay, Auth)
├── Models/                     # Modèles Eloquent (Document, Template, Plan, Subscription, CreditTransaction, ChatSession, ChatMessage)
├── Providers/                  # Providers Laravel
├── Jobs/                       # LongFormattingJob (mise en forme IA en file d'attente — créé, à brancher)
└── Services/
    ├── Detection/              # Détection de structure
    │   ├── TitleDetectionService.php   # Détection titres (regex/IA, hybride)
    │   ├── TextExtractionService.php   # Extraction du texte (.docx/.doc/.txt)
    │   ├── LegendDetectionService.php  # Détection légendes via regex
    │   ├── AmbiguityDetectionService.php # Ambiguïtés (numérotation vs niveau, déterministe)
    │   ├── AiCorrectionService.php     # Post-processeur IA optionnel (listes, ambiguïtés)
    │   └── StructureCorrectionService.php # Application des corrections validées
    ├── DocumentGeneration/     # Génération DOCX
    │   ├── TemplateExtractionService.php
    │   ├── TemplateStyleResolver.php   # Résolution des gabarits (Rapport/Mémoire/Pro)
    │   ├── StyleMapper.php
    │   ├── PdfPreviewService.php       # Aperçu PDF via LibreOffice (soffice)
    │   ├── CoverDetectionService.php
    │   └── CoverGenerationService.php
    ├── OpenRouter/             # SaaS — routage IA
    │   ├── ModelRouter.php             # Sélection du modèle (tâche × plan) + fallbacks
    │   └── OpenRouterService.php       # Appels chat/complétions + estimation coût
    └── Billing/                # SaaS — monétisation
        ├── CreditService.php           # Débit/crédit atomique + journalisation
        ├── UsageCostCalculator.php     # USD → crédits (marge, taux)
        └── KPayService.php             # Passerelle KPay + vérification HMAC
```

## 📋 Fonctionnalités (par phase)

| Phase | Statut | Fonctionnalité |
|-------|--------|----------------|
| **0** | ✅ Terminée | Squelette Laravel, DB MySQL, migrations, formulaire feedback |
| **1** | ✅ Terminée | Pipeline de détection (titres via DeepSeek + légendes via regex) |
| **2** | ✅ Terminée | Extraction gabarit auto + génération DOCX stylisée |
| **3** | ✅ Terminée | Détection/génération de couverture |
| **4** | ✅ Terminée | Interface validation ambiguïtés |
| **5** | ✅ Terminée | Parcours complet (upload → validation → traitement → export) |
| **6** | ✅ Terminée | IA optionnelle (assistance listes/ambiguïtés), gabarits Rapport/Mémoire/Pro, aperçu PDF |
| **7** | ✅ Terminée | **SaaS & monétisation** : crédits (1 crédit = 1 FCFA), plans Standard/Premium/Pro/Entreprises, paiement KPay (webhook signé HMAC), routage OpenRouter par tâche × plan, chat IA payant, file d'attente (`LongFormattingJob` créé — déclenchement à brancher dans le parcours document) |
| **7b** | ✅ Terminée | **Authentification** : inscription/connexion/déconnexion par session, protection des routes `/account` et `/chat` |
| **8** | ⏳ À faire | Brancher `LongFormattingJob` au parcours document (dispatch + worker `queue:work`), abonnements récurrents, UI des tâches IA (PowerPoint, analyse d'image) |

## 🔑 Variables d'environnement

| Variable | Description |
|----------|-------------|
| `DB_*` | Configuration MySQL (charset `utf8` recommandé pour anciennes versions) |
| `DEEPSEEK_API_KEY` | Clé API DeepSeek (modèle `deepseek-v4-flash`). **Vide = IA désactivée, pipeline 100 % hors-ligne.** |
| `DEEPSEEK_API_URL` | URL de l'API DeepSeek (défaut `https://api.deepseek.com/v1`) |
| `DEEPSEEK_MODEL` | Modèle utilisé (défaut `deepseek-v4-flash`) |
| `DEEPSEEK_TIMEOUT` | Timeout des appels IA en secondes (défaut `30`) |
| `DEEPSEEK_MAX_RETRIES` | Nombre de tentatives (défaut `3`) |
| `OPENROUTER_API_KEY` | Clé API OpenRouter (routage IA SaaS). **Vide = chat/IA payante indisponible, mode déterministe intact.** |
| `OPENROUTER_API_URL` | URL de l'API OpenRouter (défaut `https://openrouter.ai/api/v1`) |
| `OPENROUTER_TIMEOUT_BASE` | Timeout de base des appels (défaut `180` s) |
| `OPENROUTER_TIMEOUT_PER_CHAR` | Secondes par caractère ajoutées au timeout (défaut `0.008`) |
| `OPENROUTER_TIMEOUT_MIN` / `MAX` | Bornes du timeout (défaut `120` / `600` s) |
| `OPENROUTER_MAX_RETRIES` | Tentatives par appel (défaut `2`) |
| `RATE_FCFA_PER_USD` | Taux de conversion USD → FCFA (défaut `620`) |
| `KPAY_API_KEY` | Clé API KPay (préfixe `test_` ou `prod_`) |
| `KPAY_SECRET_KEY` | Clé secrète KPay |
| `KPAY_WEBHOOK_SECRET` | Secret HMAC de signature des webhooks KPay |
| `KPAY_BASE_URL` | URL de l'API KPay (défaut `https://admin.kpay.site/api/v1`) |
| `KPAY_MIN_AMOUNT` | Montant minimal d'achat en FCFA (défaut `500`) |
| `KPAY_CURRENCY` | Devise (défaut `XAF`) |
| `KPAY_PAYMENT_METHOD` | Méthode de paiement (défaut `gateway`) |
| `LIBREOFFICE_PATH` | Chemin vers `soffice` (optionnel, auto-détecté sinon) |
| `PROJECT_OWNER_EMAIL` | Email du porteur du projet (reçoit les feedbacks) |

## 📚 Documentation

- `CAHIER_DES_CHARGES.md` — Cahier des charges complet (sections 1-10)
- `PLAN_DEVELOPPEMENT.md` — Plan de développement par phases
- `PROMPTS_ET_TESTS.md` — Prompts validés et historique des tests
- `copilot-instructions.md` — Instructions pour GitHub Copilot

## ⚠️ Règles de développement

1. **Jamais de LLM pour un problème qu'une regex résout** (ex : légendes `Figure N:`)
2. **Sécurité par défaut** : validation MIME, taille max 50 MB, requêtes préparées
3. **Styles DOCX natifs uniquement** (Heading 1/2/3), images en ligne
4. **4 étapes distinctes** : détection → structuration → gabarit → génération
5. **Sécurité paiement** : clé API jamais côté client, webhook KPay signé HMAC = seule source d'autorité, crédits crédités uniquement sur statut terminal `completed`, idempotence par référence, minimum d'achat 500 FCFA
6. **`routes/web.php` ne doit pas être modifiée** : les routes SaaS vivent dans `routes/saas.php` et les routes d'auth dans `routes/auth.php` (chargés via `bootstrap/app.php`)
7. **Authentification** : mot de passe haché (`bcrypt`), sessions régénérées à la connexion, `remember_token` pour « Se souvenir de moi »
8. **Workflow Git** : une fonctionnalité = une branche `feature/<nom>` créée depuis `main`, puis fusionnée dans `main` — jamais de commit direct sur `main` pour une nouvelle fonctionnalité (seuls correctifs et hygiène) ; clés API jamais commitées (`.env` ignoré)
9. **Tests** : suite complète = **199 tests, 803 assertions** (`php artisan test`) ; tout ajout de fonctionnalité doit être couvert par des tests

## 📄 Licence

Projet privé — à définir.
