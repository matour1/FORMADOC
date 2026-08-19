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
├── Http/Controllers/          # Contrôleurs (Feedback, Document, Cover, Validation)
├── Models/                     # Modèles Eloquent (Document, Template, etc.)
├── Providers/                  # Providers Laravel
└── Services/
    ├── Detection/              # Détection de structure
    │   ├── TitleDetectionService.php   # Détection titres (regex/IA, hybride)
    │   ├── TextExtractionService.php   # Extraction du texte (.docx/.doc/.txt)
    │   ├── LegendDetectionService.php  # Détection légendes via regex
    │   ├── AmbiguityDetectionService.php # Ambiguïtés (numérotation vs niveau, déterministe)
    │   ├── AiCorrectionService.php     # Post-processeur IA optionnel (listes, ambiguïtés)
    │   └── StructureCorrectionService.php # Application des corrections validées
    └── DocumentGeneration/     # Génération DOCX
        ├── TemplateExtractionService.php
        ├── TemplateStyleResolver.php   # Résolution des gabarits (Rapport/Mémoire/Pro)
        ├── StyleMapper.php
        ├── PdfPreviewService.php       # Aperçu PDF via LibreOffice (soffice)
        ├── CoverDetectionService.php
        └── CoverGenerationService.php
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

## 🔑 Variables d'environnement

| Variable | Description |
|----------|-------------|
| `DB_*` | Configuration MySQL (charset `utf8` recommandé pour anciennes versions) |
| `DEEPSEEK_API_KEY` | Clé API DeepSeek (modèle `deepseek-v4-flash`). **Vide = IA désactivée, pipeline 100 % hors-ligne.** |
| `DEEPSEEK_API_URL` | URL de l'API DeepSeek (défaut `https://api.deepseek.com/v1`) |
| `DEEPSEEK_MODEL` | Modèle utilisé (défaut `deepseek-v4-flash`) |
| `DEEPSEEK_TIMEOUT` | Timeout des appels IA en secondes (défaut `30`) |
| `DEEPSEEK_MAX_RETRIES` | Nombre de tentatives (défaut `3`) |
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

## 📄 Licence

Projet privé — à définir.
