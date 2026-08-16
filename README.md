# FORMADOC — Mise en forme automatique de rapports académiques

Application web qui prend un rapport académique (stage, projet, mémoire), détecte sa structure automatiquement (titres, figures, tableaux), applique un gabarit de mise en forme selon les normes de l'établissement, et génère un document `.docx` final prêt à télécharger.

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

# 5. Créer la base et migrer
php artisan migrate

# 6. Démarrer le serveur
php artisan serve
```

L'application est alors accessible sur `http://localhost:8000`.

## 🏗️ Structure du projet

```
app/
├── Http/Controllers/          # Contrôleurs (Feedback, Document, Cover, Validation)
├── Models/                     # Modèles Eloquent (Document, Template, etc.)
├── Providers/                  # Providers Laravel
└── Services/
    ├── Detection/              # Détection de structure
    │   ├── TitleDetectionService.php   # Détection titres via DeepSeek (LLM)
    │   └── LegendDetectionService.php  # Détection légendes via regex
    └── DocumentGeneration/     # Génération DOCX
        ├── TemplateExtractionService.php
        ├── StyleApplicationService.php
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
| **4** | ⏳ | Interface validation ambiguïtés |
| **5** | ⏳ | Parcours complet (upload → téléchargement) |

## 🔑 Variables d'environnement

| Variable | Description |
|----------|-------------|
| `DB_*` | Configuration MySQL (charset `utf8` recommandé pour anciennes versions) |
| `DEEPSEEK_API_KEY` | Clé API DeepSeek (modèle `deepseek-v4-flash`) |
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
