# Procédure de déploiement — FORMADOC

> **Objectif** : qu'un déploiement ne puisse pas réussir tout en laissant le service à moitié fonctionnel.
>
> Ce document décrit les étapes réelles, y compris celles qui ne sont **pas** automatisables et qui sont la cause la plus fréquente de « déploiement réussi mais produit cassé » : les **workers de queue** et le **cron**.
>
> **Statut de ce document** : rédigé et vérifié contre le code. À relire une fois l'hébergeur choisi pour adapter les commandes (chemins, méthode de build, service de queue).

---

## 0. Les deux pièges qui font perdre de l'argent en silence

Avant la procédure, ces deux points doivent être compris, car ils ne produisent **aucune erreur visible**.

### Piège 1 — Sans worker de queue, la mise en forme IA ne se termine jamais

`DocumentController::upload()` appelle :

```php
LongFormattingJob::dispatch($document, $estimatedCredits, $user->id);
```

Et `QUEUE_CONNECTION=database` (`.env.production`). Sans processus `queue:work` :

- le job est écrit dans la table `jobs` et **n'en sort jamais** ;
- le document reste au statut `processing` indéfiniment ;
- **les crédits ont déjà été débités**.

Le site semble fonctionner : l'utilisateur voit « ⏳ IA en cours ». Rien n'indique l'erreur. Seule la base (table `jobs` qui grossit) le révèle.

### Piège 2 — Sans cron, des paiements sont encaissés sans être crédités

`routes/console.php` planifie :

| Commande | Fréquence | Conséquence si absente |
|---|---|---|
| `kpay:sync` | toutes les 5 min | **Un paiement dont le webhook s'est perdu n'est jamais crédité.** Le client a payé, son solde ne bouge pas |
| `subscriptions:renew` | 06:00 quotidien | Les abonnements ne se renouvellent pas |
| `subscriptions:remind-renewal` | 07:00 quotidien | Aucun rappel d'expiration envoyé |
| `files:purge-temp` | 02:30 quotidien | Disque saturé par les aperçus et pièces jointes orphelines |

Le fallback `kpay:sync` est précisément le filet prévu pour les webhooks perdus. Sans cron, **le filet n'existe pas**.

---

## 1. Prérequis serveur

| Composant | Version | Vérification |
|---|---|---|
| PHP | **8.3 minimum**, 8.4 recommandé | `php -v` (`composer.json` exige `^8.3`) |
| Extensions PHP | `dom curl libxml mbstring zip fileinfo pdo pdo_mysql gd` | `php -m` |
| Composer | 2.x | `composer --version` |
| MySQL | 5.7+ / 8.x, **charset `utf8mb4`** | voir §3 |
| Node.js | 24 (uniquement pour construire les assets) | `node -v` |
| LibreOffice | optionnel | `soffice --version` — sans lui, les aperçus PDF et conversions PDF↔DOCX sont indisponibles, le reste fonctionne |

**Ressources** : l'analyse IA d'un rapport de 116 866 caractères a été mesurée à **18 s** (modèle non-raisonnant, format compact). Prévoir un `max_execution_time` PHP **≥ 300 s** et un timeout serveur web équivalent, sinon les requêtes longues sont coupées en cours d'analyse.

> ⚠️ **Sous Windows/WAMP** : le `php.ini` utilisé par Apache est distinct de celui du CLI. Si `PHPIniDir` pointe vers un dossier contenant un `php.ini` vide, **aucun bundle d'autorités de certification n'est chargé** et tout appel HTTPS échoue (`cURL error 60: SSL certificate problem`). Vérifier que `curl.cainfo` et `openssl.cafile` sont définis dans le `php.ini` **de l'interface web**, pas seulement en CLI.

---

## 2. Séquence de déploiement

À exécuter **dans cet ordre**. L'ordre n'est pas cosmétique : chaque étape suppose la précédente terminée.

```bash
# ---------------------------------------------------------------------------
# 1. Récupérer le code — fast-forward uniquement
# ---------------------------------------------------------------------------
git fetch --all --tags
git checkout <tag-ou-commit>
git pull --ff-only        # jamais de merge implicite en production

# ---------------------------------------------------------------------------
# 2. Mode maintenance (facultatif mais recommandé)
# ---------------------------------------------------------------------------
php artisan down --render="errors::503" --retry=60

# ---------------------------------------------------------------------------
# 3. Dépendances PHP — SANS les outils de développement
# ---------------------------------------------------------------------------
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# ---------------------------------------------------------------------------
# 4. Assets front — OBLIGATOIRE à chaque déploiement
# ---------------------------------------------------------------------------
# `public/build` est dans .gitignore (ligne 22) : il n'est JAMAIS dans le dépôt.
# Sans cette étape, `@vite` lève « Unable to locate file in Vite manifest »
# et TOUT le site tombe (layout principal).
npm ci --ignore-scripts
npm run build
test -f public/build/manifest.json   # échouer si le build n'a rien produit

# ---------------------------------------------------------------------------
# 5. Migrations
# ---------------------------------------------------------------------------
php artisan migrate --force          # --force OBLIGATOIRE en production

# ---------------------------------------------------------------------------
# 6. Caches de production
# ---------------------------------------------------------------------------
php artisan config:clear             # purge d'abord (sinon un vieux cache survit)
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ---------------------------------------------------------------------------
# 7. Redémarrer les workers — APRÈS la mise à jour du code
# ---------------------------------------------------------------------------
php artisan queue:restart

# ---------------------------------------------------------------------------
# 8. Sortir du mode maintenance
# ---------------------------------------------------------------------------
php artisan up
```

### Pourquoi `config:cache` doit suivre `config:clear`

Un cache de configuration obsolète fait tourner **l'ancienne** configuration sans le signaler : une variable d'environnement modifiée n'a alors aucun effet, et on cherche la panne au mauvais endroit. La purge avant mise en cache évite ce piège.

### Pourquoi `queue:restart` est en étape 7 et pas avant

Un worker garde le code PHP **en mémoire**. Redémarrer avant la mise à jour du code relance les workers sur l'ancienne version, qui continue de tourner après le déploiement.

---

## 3. Vérifications post-déploiement

À faire dans l'ordre. Chaque point a déjà été une cause de panne sur ce projet.

| # | Vérification | Commande / URL | Attendu |
|---|---|---|---|
| 1 | Application vivante | `GET /up` | `200` |
| 2 | Manifeste Vite présent | `ls public/build/manifest.json` | existe |
| 3 | Charset de la base | voir requête ci-dessous | `utf8mb4` partout |
| 4 | Worker de queue actif | `ps aux \| grep queue:work` | processus présent |
| 5 | Cron actif | `php artisan schedule:list` | 4 tâches listées |
| 6 | Migration en attente | `php artisan migrate:status` | aucun `Pending` |
| 7 | Fichiers récents traités | `SELECT COUNT(*) FROM jobs` | stable (ne grossit pas) |

**Requête de vérification du charset** (défaut corrigé sur ce projet : les tables étaient en `utf8mb3`, ce qui faisait perdre les réponses IA **après** facturation) :

```sql
SELECT TABLE_NAME, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION LIKE 'utf8mb3%';
-- Attendu : aucun résultat
```

Si des lignes remontent, la migration `convert_tables_to_utf8mb4_charset` n'a pas été jouée ou a été sautée.

---

## 4. Configuration des services permanents

### 4.1 Worker de queue

Le service doit **redémarrer automatiquement** en cas de crash, sinon une nuit d'indisponibilité suffit à accumuler des documents bloqués.

**systemd** (VPS Linux) :

```ini
# /etc/systemd/system/formadoc-queue.service
[Unit]
Description=FORMADOC queue worker
After=network.target mysql.service

[Service]
User=formadoc
Restart=always
RestartSec=5
WorkingDirectory=/var/www/formadoc
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
# --max-time=3600 : recycle le worker chaque heure, ce qui borne les fuites
# mémoire des bibliothèques PDF/DOCX sur un processus de longue durée.
StopAsGroup=true
KillMode=mixed

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now formadoc-queue
```

**Supervisor** (alternative) : équivalent, avec `autostart=true`, `autorestart=true` et `stopwaitsecs=3600` — cette dernière valeur doit dépasser la durée du job le plus long, sinon Supervisor tue un job en cours qu'Artisan considère comme échoué et rejoue.

### 4.2 Cron

Une seule ligne suffit : Laravel répartit lui-même les 4 tâches.

```
* * * * * cd /var/www/formadoc && php artisan schedule:run >> /dev/null 2>&1
```

Vérification : `php artisan schedule:list` doit afficher les 4 entrées (dont `kpay:sync` toutes les 5 minutes).

---

## 5. Retour arrière

| Situation | Action |
|---|---|
| Le code est en cause | `git checkout <commit-précédent>` puis rejouer **étapes 3, 4, 6, 7** |
| Une migration est en cause | **Ne jamais** faire `migrate:rollback` en production sans avoir vérifié `down()` : certaines migrations de ce projet ont un `down()` volontairement vide (destructif, ex. conversion utf8mb4). Restaurer un dump à la place |
| Les workers tournent sur du vieux code | `php artisan queue:restart` |
| Le site est cassé et la cause inconnue | `php artisan down` puis investiguer — un site indisponible est préférable à un site qui facture sans livrer |

---

## 6. Secrets et fichiers d'environnement

| Fichier | Usage | Versionné |
|---|---|---|
| `.env.example` | Modèle documenté, toutes les variables | ✅ oui |
| `.env` | Développement local | ❌ non (`.gitignore:7`) |
| `.env.production` | Modèle de production, à adapter | ❌ non (`.gitignore:8` → `.env.*`) |

**Règle** : le `.env` de production (avec les vraies clés) existe **uniquement sur le serveur**. Il ne doit jamais transiter par un artefact CI, un log, ni un ticket.

Variables à **obligatoirement** définir en production :

```bash
APP_ENV=production
APP_DEBUG=false          # sinon les traces exposent les clés d'API
APP_KEY=                 # php artisan key:generate
APP_URL=https://<domaine>

DB_CHARSET=utf8mb4       # NE JAMAIS mettre « utf8 » (= utf8mb3)
DB_COLLATION=utf8mb4_unicode_ci

SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true   # nécessite HTTPS
SESSION_HTTP_ONLY=true

LOG_STACK=daily
LOG_LEVEL=error

QUEUE_CONNECTION=database    # + worker obligatoire (cf. §0)
CACHE_STORE=database
```

> **Variables qui n'existent pas** — leur présence dans un fichier d'environnement ne produit aucune erreur, mais n'a **aucun effet**. Elles ont été retirées de `.env.example` et `.env.production` :
>
> | Variable trompeuse | Réalité |
> |---|---|
> | `DEEPSEEK_TIMEOUT` | non lue ; seules `DEEPSEEK_TIMEOUT_BASE/_MIN/_MAX/_PER_CHAR` comptent |
> | `OPENROUTER_COST_INFRA` / `OPENROUTER_COST_MARGIN` | **codées en dur** dans `config/openrouter.php` (0.15 / 0.60) — volontairement : une erreur de configuration ne doit pas pouvoir faire baisser le prix de vente sous le seuil de rentabilité |
> | `BILLING_CURRENCIES` | non lue ; la liste est dans `config/billing.php` (`currencies`) |

---

## 7. Décision à prendre : `DOCUMENT_PIPELINE_V2`

`DOCUMENT_PIPELINE_V2` détermine quel pipeline traite les documents.

| Valeur | Comportement | Ce que l'utilisateur obtient |
|---|---|---|
| `false` | Pipeline historique (PHPWord) | Statu quo. **Renumérotation, rattachement des légendes et renvois internes NON disponibles** |
| **`auto`** | Nouveau pipeline, **repli automatique** sur l'ancien à la moindre erreur | Les trois fonctions ci-dessus, avec filet de sécurité |
| `true` | Nouveau pipeline seul | Idem, mais un bug du parseur fait échouer l'export (exception) |

**Valeur retenue pour la v1 : `auto`** (voir `.env.production`). Le repli est implémenté et testé :

```php
// DocumentController::writeDocument()
} catch (Throwable $e) {
    Log::warning('Export natif échoué, repli sur l\'ancien pipeline', [...]);
}
return $this->writeLegacyDocument(...);
```

**Ce qu'il faut surveiller pour décider du passage à `true`** :

```bash
grep "Pipeline natif : conversion échouée" storage/logs/laravel.log
```

- **Aucune occurrence sur plusieurs centaines de documents** → passer à `true`.
- **Occurrences régulières** → ne pas passer à `true` : le repli masque un défaut réel du parseur, à corriger d'abord.

Sans ce flag activé, les fonctionnalités suivantes restent livrées **dans le code mais inatteignables** : renumérotation des doublons (98 documents concernés sur les 526 du corpus mesuré), rattachement des légendes (861 légendes sans lien), résolution des renvois internes (« voir Figure 3 »).

---

## 8. Référence CI/CD

| Workflow | Déclencheur | Rôle |
|---|---|---|
| `.github/workflows/ci.yml` | push, PR, lundi 06:00 | Style, **audit de sécurité des dépendances**, tests (PHP 8.3 + 8.4), build |
| `.github/workflows/deploy.yml` | **manuel** (`workflow_dispatch`) | À activer après le choix d'hébergeur. Désactivé (`if: false`) en attendant |

**Pourquoi l'audit de sécurité tourne à chaque push, et pas seulement chaque semaine** : une vulnérabilité est publiée dans la base d'avis d'un paquet, pas dans ce dépôt. Un scan hebdomadaire laisse une fenêtre pendant laquelle le code n'a pas changé, la faille est publique, le correctif existe — et un déploiement peut partir. L'audit à chaque push ferme cette fenêtre. Le scan programmé du lundi reste utile pour détecter une CVE publiée sur une période sans aucun commit.

**Le déploiement est refusé si l'audit échoue** (`deploy.yml`, job `verify`) : un scan qui ne bloque rien n'est qu'un rapport.

---

## 9. Points restant à décider

| # | Décision | Impact |
|---|---|---|
| 1 | **Hébergeur** | Détermine la méthode de déploiement (`deploy.yml`) et l'emplacement du `.env` |
| 2 | Méthode de build des assets | `public/build` étant ignoré par git : compiler en CI puis transférer, ou compiler sur le serveur |
| 3 | `DOCUMENT_PIPELINE_V2` : `auto` → `true` | Après observation des logs de repli |
| 4 | Politique de sauvegarde | Fréquence, rétention, **et test de restauration** (une sauvegarde jamais restaurée n'est pas une sauvegarde) |
| 5 | Supervision | Alerte si le worker de queue s'arrête, si la table `jobs` grossit, ou si `kpay:sync` ne tourne plus |
| 6 | Recherche web et génération d'images | ✅ **Décidé — livrées et assumées.** Surveiller l'accès de la clé OpenRouter aux modèles `gpt-image-*` et à `web_search_options` : un échec fournisseur sur ces outils est facturé sans résultat |
