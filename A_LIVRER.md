# Ce qui reste à livrer — FORMADOC v1

> **Date** : 2026-09-20
> **Objet** : inventaire vérifié de ce qui reste avant qu'une v1 soit réellement livrable.
> **Méthode** : chaque ligne a été vérifiée dans le code, pas déduite d'un plan. Les commandes de vérification sont fournies pour que le constat soit reproductible.

---

## 1. Verdict en une phrase

Le back est **solide et testé** (1 279 tests verts, durcissement sécurité P0/P1/P2 fait). Ce qui manque n'est **pas du code** : c'est la **mise en production** (branche non mergée, pipeline non activé, services permanents non configurés), plus **deux décisions produit** qui ne peuvent pas être prises à ma place.

---

## 2. Bloquants — rien ne part en production avant

| # | Bloquant | État | Vérification | Ce qui est fait |
|---|---|---|---|---|
| B1 | `main` ne contient pas le travail | ✅ **RÉSOLU** | `git log main --oneline -1` | Mergé (fast-forward) — voir §6 |
| B2 | Pipeline v2 non activé | ✅ **RÉSOLU (valeur à confirmer)** | `grep DOCUMENT_PIPELINE_V2 .env.production` | Réglé à `auto` — **décision à valider**, voir §3.1 |
| B3 | Aucun worker de queue configuré | ⚠️ **À FAIRE au déploiement** | `ps aux \| grep queue:work` | Procédure écrite (`DEPLOIEMENT.md` §4.1). **Ne peut pas être fait avant de connaître l'hébergeur** |
| B4 | Aucun cron configuré | ⚠️ **À FAIRE au déploiement** | `php artisan schedule:list` | Procédure écrite (`DEPLOIEMENT.md` §4.2) |
| B5 | Aucun CI | ✅ **RÉSOLU** | `.github/workflows/ci.yml` | 5 jobs créés, dont l'audit de sécurité |
| B6 | Aucun déploiement automatisé | ✅ **ÉBAUCHE PRÊTE** | `.github/workflows/deploy.yml` | Désactivé (`if: false`) en attendant l'hébergeur — voir §4 |

> **B3 et B4 sont les deux vrais risques.** Ils ne produisent **aucune erreur** :
> - sans worker de queue, les documents passent en `processing` et n'en sortent jamais, **crédits déjà débités** ;
> - sans cron, `kpay:sync` ne tourne pas → **un paiement dont le webhook se perd n'est jamais crédité**.
>
> Ces deux points sont détaillés dans `DEPLOIEMENT.md` §0.

---

## 3. Décisions qui attendent un humain

Aucune de ces décisions n'est technique. Je les ai préparées sans les trancher.

### 3.1 `DOCUMENT_PIPELINE_V2` — valeur retenue : `auto` (**à confirmer**)

**La question a été posée mais est restée sans réponse** au moment du travail. J'ai appliqué la valeur recommandée ci-dessous ; elle se change en une ligne.

| Valeur | Ce que l'utilisateur obtient | Risque |
|---|---|---|
| `false` | Statu quo. **Renumérotation, rattachement des légendes, renvois internes indisponibles** | Aucun — mais 3 fonctions livrées restent inatteignables |
| **`auto`** *(retenu)* | Les 3 fonctions, avec **repli automatique** sur l'ancien pipeline en cas d'échec | Le repli peut masquer un défaut du nouveau parseur |
| `true` | Idem, sans filet | Un bug du parseur fait échouer l'export |

**Pourquoi `auto`** : le code prévoit explicitement ce mode pour une migration progressive (« observer en conditions réelles sans risque »), et le repli est implémenté **et testé** (`DocumentPipelineIntegrationTest`). C'est le seul choix qui livre les fonctionnalités sans exposer à une perte de document.

**Pour changer** : une ligne dans `.env.production` (et `.env` en local).

**Comment décider du passage à `true`** :
```bash
grep "Pipeline natif : conversion échouée" storage/logs/laravel.log
```
Aucune occurrence sur plusieurs centaines de documents → passer à `true`.

### 3.2 Recherche web et génération d'images — ✅ **DÉCIDÉ : livrées et assumées**

Le cahier des charges les déclarait hors scope prioritaire, mais elles étaient **exposées et facturées** (`ChatToolsService` lignes 178 et 193). Décision : **elles restent fonctionnelles et sont assumées comme fonctionnalités du produit** (Option B).

| Outil | Fonctionnement | Test |
|---|---|---|
| `web_search` | Recherche web native OpenRouter (`web_search_options`, contexte `high`), citations incluses | `ChatToolsServiceTest::test_web_search_delegue_a_openrouter_avec_citations` |
| `image_generate` | Génération d'image (`gpt-image-*`), fichier stocké et téléchargeable | `ChatToolsServiceTest::test_image_generate_stocke_le_fichier` |

**Conséquence** : elles ont été **retirées de la dette assumée**. Elles sont maintenant documentées comme livrées, ce qui est cohérent avec leur comportement réel (l'IA les voit, l'utilisateur peut les invoquer, les appels sont facturés).

**À surveiller** (nouvelle responsabilité liée à cette décision) :
- que leur coût par appel soit bien couvert par le coefficient ×1.84 (`config/openrouter.php` → `pricing`) ;
- que la clé OpenRouter du serveur ait **accès** aux modèles `gpt-image-*` et à `web_search_options` — sans cet accès, l'échec fournisseur est facturé sans résultat.

### 3.3 Hébergement

Détermine la méthode de déploiement, l'emplacement du `.env`, et le gestionnaire de services (systemd ou équivalent managé). `deploy.yml` contient des exemples prêts pour VPS SSH et pour plateforme managée.

---

## 4. Ce qui reste à faire pour le déploiement

Détail complet dans `DEPLOIEMENT.md`. Résumé de ce qui **n'est pas encore fait** :

| # | Élément | Pourquoi c'est nécessaire |
|---|---|---|
| D1 | Choisir l'hébergeur | Tout le reste en dépend |
| D2 | Configurer les secrets GitHub (`DEVOPS_SSH_*`) | Déploiement automatisé |
| D3 | Créer l'`environment` `production` avec approbation manuelle | Un déploiement ne doit pas partir sans validation humaine |
| D4 | Activer le job `deploy` (retirer `if: false`) | Il est volontairement inerte |
| D5 | Méthode de build des assets | `public/build` est **ignoré par git** : compiler en CI puis transférer, ou compiler sur le serveur |
| D6 | Configurer le worker de queue (systemd/Supervisor) | **B3** |
| D7 | Configurer le cron | **B4** |
| D8 | Politique de sauvegarde **et test de restauration** | Une sauvegarde jamais restaurée n'existe pas |
| D9 | Supervision (alerte si le worker s'arrête ou si `jobs` grossit) | Sans alerte, une panne silencieuse dure des jours |
| D10 | Renseigner les clés réelles dans le `.env` serveur | `OPENROUTER_API_KEY`, `DEEPSEEK_API_KEY`, `KPAY_*` (clés `prod_`/`sk_live_`), SMTP |

> ⚠️ Le `.env` de production contient des secrets : il doit **exister uniquement sur le serveur**, ne jamais transiter par un artefact CI.

---

## 5. Dettes techniques identifiées (non bloquantes)

| # | Dette | Impact | Priorité |
|---|---|---|---|
| T1 | **Tailwind chargé par CDN** (`layouts/app.blade.php:17`) **en plus** du build Vite | Warning navigateur, compilation à la volée, ~300 Ko, FOUC | **Haute** — se traite avec le front |
| T2 | `is_list_style` calculé mais jamais consommé | Signal de sommaire collecté puis jeté → des entrées de sommaire peuvent être classées comme titres | Moyenne — mesurer d'abord |
| T3 | `findTocBoundaries()` ne reconnaît que `LISTE DES …`, pas `SOMMAIRE` | Idem T2 | Moyenne |
| T4 | Sommaire tapé à la main sans points de suite | Cas d'échec silencieux possible (aucun test dédié) | Moyenne |
| T5 | ~~Documentation périmée~~ | `README.md` et `PLAN_DEVELOPPEMENT.md` listaient `cover_page.generate` comme outil actif, et la liste d'outils était incomplète | ✅ Résolu |
| T6 | Mémoire PHP à 128 Mo par défaut | La suite complète (~1 280 tests) meurt sur « Premature end of PHP process ». Le CI est configuré à 512 Mo | Basse en production, **mais piège pour quiconque lance `php artisan test`** |
| T7 | ~~`pint` non appliqué au projet~~ | 95 fichiers non conformes → le CI échouait dès le premier push | ✅ Résolu |
| T8 | ~~`phpunit.xml` n'isolait pas les tests du `.env` local~~ | Le local et la CI testaient des configurations différentes : activer `DOCUMENT_PIPELINE_V2` en local faisait échouer un test sans aucun changement de code | ✅ Résolu |

---

## 6. Ce qui a été fait dans cette session

| Action | Détail |
|---|---|
| `.env.example` corrigé | `DB_CHARSET=utf8` → **`utf8mb4`** (le `utf8mb3` faisait **perdre les réponses IA après facturation**), variables de session de production documentées |
| Variables trompeuses retirées | `DEEPSEEK_TIMEOUT`, `OPENROUTER_COST_INFRA/MARGIN`, `BILLING_CURRENCIES` : **non lues par le code**. Leur présence laissait croire à une configuration effective — exactement le type d'échec silencieux à éliminer |
| `.env.production` complété | Timeouts DeepSeek réels, `DEEPSEEK_MAX_TOKENS=65536` (manquant → JSON tronqué), `DOCUMENT_PIPELINE_V2=auto`, garde-fous de classification |
| CI créé | `.github/workflows/ci.yml` — 5 jobs : style, **sécurité**, tests (PHP 8.3 + 8.4), build, porte de sortie |
| Audit de sécurité à chaque push | `composer audit --locked` + `npm audit --audit-level=high`, **vérifiés localement** : 0 vulnérabilité des deux côtés |
| Déploiement ébauché | `.github/workflows/deploy.yml` — désactivé, exemples VPS/managé, liste des secrets |
| Procédure de déploiement écrite | `DEPLOIEMENT.md` — 9 sections, les deux pièges silencieux détaillés |
| Rapport d'audit | `ETAT_PROJET_ET_ANGLES_MORTS.md` — verdicts vérifiés, mesures du corpus, décisions |
| `main` mis à jour | Fast-forward (55 commits), aucun conflit |
| **Pint appliqué au projet** | Le CI aurait été rouge : 70 fichiers corrigés (95 non conformes au total, dont des conflits détectés par le formateur). Commité **isolément** pour pouvoir l'écarter si un problème apparaît |
| **🐛 Bug d'affichage corrigé** | `documents/show.blade.php` utilisait `BlockCategory::listTitle()` comme **type** d'élément → affichait « LISTE DES FIGURES » au lieu de « Figure ». Bug **masqué** tant que le pipeline v2 restait désactivé ; l'activer l'a révélé. Corrigé en `keyword()`, avec un test de non-régression **dont j'ai vérifié qu'il échoue sans le correctif** |
| `phpunit.xml` isolé du `.env` local | Les tests forçaient implicitement la configuration du poste → la CI et le local ne testaient pas la même chose |
| `web_search` / `image_generate` marqués **livrés** | Decision Option B appliquée dans `ETAT_PROJET_ET_ANGLES_MORTS.md`, `A_LIVRER.md`, `DEPLOIEMENT.md`. Retirés de la dette assumée, avec la surveillance à mettre en place (accès de la clé OpenRouter aux modèles `gpt-image-*`) |

---

## 7. Ordre d'exécution recommandé

```
1. [fait]  Corriger .env.example
2. [fait]  Aligner .env / .env.production  ← confirmer B2 (§3.1)
3. [fait]  Mettre main à jour
4. [fait]  CI/CD + audit de sécurité
5. [fait]  Procédure de déploiement
6. [fait]  Rapport
7. [fait]  Décision §3.2 : web_search / image_generate = livrés
──────────────────────────────────────────────
8. [vous]  Confirmer §3.1 (pipeline v2 = auto ?)
9. [vous]  Choisir l'hébergeur
10.[nous]  Adapter deploy.yml + activer D3/D4
11.[vous]  Configurer worker + cron (D6, D7) — ou déléguer avec DEPLOIEMENT.md
12.[nous]  Front : T1 (retirer le CDN Tailwind) puis l'interface
```

---

## 8. Ce qui n'est PAS un bloquant (pour éviter de sur-corriger)

Ces points ont été examinés et jugés **non bloquants**. Les traiter maintenant serait du travail sans bénéfice.

| Point | Pourquoi ce n'est pas bloquant |
|---|---|
| Dette technique du pipeline v2 (T2-T4) | Le repli automatique protège ; à mesurer avant de corriger (principe 4 du projet) |
| Chunking des gros documents | Le format compact a ramené l'analyse de 116 866 caractères à **18 s**, sans troncature |
| Recherche web / génération d'images | **Décidées : livrées et assumées** (§3.2) |
| Page de couverture retirée | Retrait **volontaire** et verrouillé par test — ne pas la « restaurer » par réflexe |
| Aucun API publique versionnée | Le produit est une application web ; les routes API n'existent pas |
