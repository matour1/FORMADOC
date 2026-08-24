# AUDIT UI/UX — FORMADOC

> **Date** : 23/08/2026 · **Périmètre** : interface complète (Blade, Alpine.js, Tailwind CDN, CSS personnalisé `formadoc.css`)
> **Méthode** : exploration statique du code + analyse des parcours critiques + checklist WCAG 2.1 AA
> **Statut** : rapport d'audit — aucune modification de fichier à cette étape (consigne « ne modifie pas les fichiers »)

---

## 1. Introduction — État global

**Verdict global : bon socle, incohérences localisées.**

FORMADOC dispose d'un design system soigné (`formadoc.css` : variables, dark mode, radius, ombres, typographie Newsreader/Inter/IBM Plex Mono) et d'une couverture d'états remarquable (erreurs 400→504, états vides, états de chargement, maintenance, preview-fallback). Les parcours principaux sont fonctionnels et l'accessibilité de base (skip-link, aria-live sur les toasts, labels associés, focus-visible) est présente.

Les faiblesses majeures se concentrent sur :
1. **Le responsive** : plusieurs breakpoints manquants ou incomplets (320px, tableaux de factures, chat mobile).
2. **L'accessibilité des modales** : pas de gestion du focus, ni de trap, ni d'Escape.
3. **Le feedback pendant les longs traitements** : l'écran de traitement ne gère pas les files > 2 min (pas de polling, pas d'estimation).
4. **La cohérence des boutons de soumission** : pas d'état "chargement" systématique (disabled + spinner).
5. **Le ton** : tutoiement/vouvoiement mélangé (déjà identifié en copywriting, rappel ici pour les composants UI).

---

## 2. Tableau récapitulatif des corrections

### 2.1 Landing page

| Localisation | État actuel | Problème | Proposition | Priorité | Impact |
|---|---|---|---|---|---|
| `landing.blade.php` (hero) | CTA « Commencer gratuitement » | Pas de preuve sociale ni de nombre d'utilisateurs | Ajouter une ligne « Déjà utilisé par des étudiants BTS/DQP/Licence » ou un avis 5 étoiles | P1 | Conversion |
| `landing.blade.php` (hero) | `document-stage` illustratif | L'illustration est statique (CSS pur) — pas de démo réelle | Ajouter un lien « Voir un exemple de résultat » ouvrant un DOCX réel téléchargeable | P2 | Confiance |
| `landing.blade.php` (nav) | Menu mobile | Le `navbar-menu-toggle` existe mais la landing n'a **pas** de menu mobile JS (pas de `sidebar`, seulement le header) | Ajouter un menu mobile (burger → panneau) ou au minimum des liens visibles en colonne à 320px | **P0** | Utilisateurs mobiles (cible 1re) |
| `landing.blade.php` (section tarifs) | `plan-card` | Le plan Gratuit n'a pas de carte `plan-card` (hors foreach) mais utilise une structure `<ul>` différente → rupture visuelle | Uniformiser : donner au plan Gratuit la même carte `plan-card` | P2 | Cohérence |
| `landing.blade.php` (FAQ) | `<details>` | Pas d'animation d'ouverture, pas d'icône | Ajouter une icône chevron tournante + transition CSS | P2 | Polissage |
| `landing.blade.php` (footer) | Liens légaux | ✅ Déjà corrigés (commit b6444ed) | — | — | — |

### 2.2 Parcours d'upload et de traitement

| Localisation | État actuel | Problème | Proposition | Priorité | Impact |
|---|---|---|---|---|---|
| `documents/upload.blade.php` (dropzone) | Drag & drop déclaré (« Glissez-déposez ») | **Aucun handler JS** `dragover`/`drop` sur la dropzone — le drag & drop réel ne fonctionne pas (seul le clic ouvre le sélecteur) | Ajouter les événements `dragover`/`dragleave`/`drop` + classe `dragging` (visuel) | **P0** | Promesse non tenue (erreur de cohérence) |
| `documents/upload.blade.php` (validation) | `accept=".docx,.doc,.txt"` + `required` | Pas de validation JS de la **taille** (50 Mo) ni du **type** réel avant envoi ; un fichier > 50 Mo part en requête puis échoue | Ajouter une validation JS (taille ≤ 50 Mo, extension autorisée) avec message inline avant soumission | **P0** | Fichiers rejetés tardivement |
| `documents/upload.blade.php` (preview) | `showPreview()` | Le preview ne différencie pas DOCX/DOC/TXT visuellement (même icône) et `.doc` n'a pas d'aperçu | Icône par type + message « Aperçu disponible uniquement pour les .txt » | P2 | Clarté |
| `documents/upload.blade.php` (bouton) | `#upload-submit` | Au submit, le formulaire est masqué et remplacé par le panneau d'analyse — mais le **bouton n'est pas désactivé** ni remplacé par un spinner | Désactiver le bouton + spinner (aria-busy) pendant l'analyse | P1 | Évite double-clic |
| `documents/upload.blade.php` (erreur AJAX) | `.catch()` | En cas d'erreur, le log affiche `err.message` brut (potentiellement technique) | Afficher « Une erreur réseau est survenue. Vérifiez votre connexion. » (message générique + référence) | P1 | Compréhension |
| `documents/processing.blade.php` | Écran statique « patientez » | **Pas de polling** : si le traitement dépasse 2 min, l'utilisateur attend sans savoir si ça avance (page non rechargée) | Ajouter un polling léger (`fetch` toutes les 15-30 s vers le statut du document) + barre de progression estimée + temps écoulé | **P0** | Rétention (traitements longs IA) |
| `documents/processing.blade.php` | Journal de traitement | Journal purement décoratif (étapes codées en dur) | Alimenter le journal depuis le vrai statut backend (ou au moins ajouter un indicateur « toujours en cours » qui pulse) | P1 | Confiance |
| `documents/export.blade.php` | Page de garde | Le sélecteur de page de garde est dans une section « Ajouter une page de garde » avec placeholders | Bien, mais l'action « Régénérer le DOCX avec la page de garde » n'a pas de confirmation de coût si IA | P2 | Clarté |
| `documents/export.blade.php` | Bouton téléchargement | Pas d'état de chargement au clic (téléchargement long = double-clic possible) | Désactiver + spinner pendant la génération PDF/export | P1 | Robustesse |

### 2.3 Dashboard & bibliothèque

| Localisation | État actuel | Problème | Proposition | Priorité | Impact |
|---|---|---|---|---|---|
| `dashboard.blade.php` | Stat-grid | 4 stats mais pas de **progression des quotas** dans les cartes (seulement barres dans la card dédiée) | Déjà correct — la card quotas est claire | — | — |
| `dashboard.blade.php` | Docs récents | Pas d'action rapide « Télécharger » sur les docs `ready` de la liste (seulement via "Voir tout") | Ajouter un bouton download inline | P2 | Gain de temps |
| `documents/index.blade.php` | `.doc-row` | **Responsive faible** : les actions passent à la ligne mais restent encombrées sur mobile | À 560px : empiler (nom / statut / actions), masquer la date, agrandir les zones cliquables | **P1** | Mobile |
| `documents/index.blade.php` | Filtres | Filtres « En cours / Terminés / Échecs » en `segmented` | OK — mais pas de compteur par statut (ex. « Terminés (12) ») | P2 | Info |
| `documents/index.blade.php` | Pagination | `{{ $documents->links() }}` | Vérifier le rendu mobile (pagination par défaut Laravel peut déborder) — envisager un « Voir plus » (infinite scroll) | P2 | Mobile |
| `layouts/app.blade.php` | Recherche navbar | Recherche filtre uniquement les `.doc-row`/`.chat-item`/`.template-card` de la **page courante** | Ambigüe : placeholder « Rechercher… » laisse croire à une recherche globale. Renommer « Filtrer la page » ou implémenter une vraie recherche globale | P1 | Compréhension |
| `layouts/app.blade.php` | Bouton déconnexion | `onsubmit="return confirm(...)"` | OK mais le `confirm()` natif est bloquant/peu stylé — acceptable en P1 | P2 | Polissage |

### 2.4 Onboarding & auth

| Localisation | État actuel | Problème | Proposition | Priorité | Impact |
|---|---|---|---|---|---|
| `states/onboarding.blade.php` | Page onboarding | **Existe** mais n'est **pas branchée** sur le flux réel (aucune route ne redirige vers elle après inscription) | Router la redirection post-register vers `onboarding` (ou intégrer un vrai wizard en 3 étapes) | **P0** | Activation (première action clé) |
| `auth/register.blade.php` | Mot de passe | Placeholder « 8 caractères minimum » | Ajouter une **validation en temps réel** (force du mot de passe) ou au minimum un indicateur de longueur | P1 | Réduction d'erreurs |
| `auth/login.blade.php` | « Mot de passe oublié » | **Absent** (confirmé) | Ajouter le lien (tâche H1 du plan : flux à créer) | **P0** | Bloquant auth |
| `auth/login.blade.php` | Panneau décoratif `auth-aside` | Texte « FORMADOC met en forme vos rapports académiques (Word, PDF) » — mentionne **PDF** qui n'est pas supporté (corrigé ailleurs mais pas ici) | Corriger le texte (retirer « PDF ») | **P1** | Promesse non tenue |
| `auth/*` | Tabs Connexion/Inscription | `role="tablist"` sans `aria-controls` ni `role="tabpanel"` | Ajouter les attributs ARIA complets ou retirer le rôle tab (liens simples) | P2 | A11y |

### 2.5 Paiement & abonnements

| Localisation | État actuel | Problème | Proposition | Priorité | Impact |
|---|---|---|---|---|---|
| `subscriptions/checkout.blade.php` | Champs carte | `payName` a `value` mais **pas de `name`** → non soumis | Ajouter `name="cardholder_name"` + validation | **P0** | Paiement |
| `subscriptions/checkout.blade.php` | Méthodes de paiement | Les labels ont `role="button"` + `tabindex="0"` mais pas de gestion clavier (Enter/Space) | Ajouter keydown Enter/Space pour sélectionner la méthode | P1 | A11y |
| `subscriptions/checkout.blade.php` | Bouton payer | Pas d'état de chargement (redirection KPay peut prendre du temps) | Désactiver + spinner + « Redirection vers KPay… » | **P0** | Paiement |
| `account/index.blade.php` (modale crédits) | `openModal` | **Pas de trap de focus, pas de focus initial, pas d'Escape, pas de retour focus** | Ajouter : focus sur le premier champ, `Escape` pour fermer, trap Tab, `aria-hidden` sur le reste, retour du focus au bouton déclencheur | **P0** | A11y modale |
| `account/index.blade.php` (modale annulation) | idem | idem | idem | **P0** | A11y modale |
| `account/index.blade.php` | Tableau factures | Tableau responsive ? | À 560px, transformer en cartes empilées (ou permettre le scroll horizontal avec `overflow-x`) | **P1** | Mobile |
| `account/index.blade.php` | Quick amounts | Boutons 500/1000/2000/5000 | ✅ OK — ajouter un `aria-pressed` sur le bouton actif | P2 | A11y |
| `subscriptions/invoices.blade.php` | Liste factures | Vérifier l'affichage mobile du tableau | Table responsive (scroll horizontal ou cartes) | P2 | Mobile |

### 2.6 Chat IA

| Localisation | État actuel | Problème | Proposition | Priorité | Impact |
|---|---|---|---|---|---|
| `chat/index.blade.php` | Zone messages | Pas d'état de chargement explicite pendant l'envoi (spinner « L'IA réfléchit… ») | Ajouter un indicateur « L'IA réfléchit… » avec animation (aria-busy) | **P0** | Confiance |
| `chat/index.blade.php` | Champ de saisie | Non désactivé pendant la réponse | Désactiver + empêcher l'envoi multiple pendant une réponse | P1 | Robustesse |
| `chat/show.blade.php` | Bandeau quota | `banner-danger` en haut | ✅ OK mais le texte « attendez la prochaine période » est frustrant — ajouter un lien direct « Acheter des crédits » | P1 | Conversion |
| `chat/index.blade.php` | Sidebar conversations | `chat-item` avec `role="button"` + click | ✅ Gère Enter (bon). Le bouton supprimer est dans le même conteneur cliquable → conflit de click | **P1** | Erreurs de clic |
| `chat/index.blade.php` | Modèle menu | `chat-model-menu` s'ouvre mais pas de gestion clavier (Escape, flèches) ni `aria-expanded` dynamique | Ajouter gestion clavier + fermeture au clic extérieur | P2 | A11y |

### 2.7 États d'erreur & états manquants

| Localisation | État actuel | Problème | Proposition | Priorité | Impact |
|---|---|---|---|---|---|
| `errors/partials/error-card.blade.php` | Détail technique | Affiche `REF-XXX-XXXXXX` + méthode/path | ✅ Bon (référence utile au support). Veiller à ne pas exposer de stack trace (aucune actuellement) | — | — |
| `errors/419.blade.php` | Expiration session | Vérifier le message (CSRF) | Doit proposer « Recharger la page » + explication simple | P1 | Confiance |
| `errors/429.blade.php` | Rate limit | Vérifier le message | Doit expliquer « trop de tentatives, réessayez dans X » sans jargon | P1 | Clarté |
| `states/maintenance.blade.php` | Maintenance | ✅ Corrigé (retrait date en dur) | — | — | — |
| `states/preview-fallback.blade.php` | Aperçu indisponible | ✅ Corrigé (jargon LibreOffice retiré) | — | — | — |
| — | **Page 404 hors app** | Les erreurs utilisent `layouts.app` (avec sidebar) → si la session expire, la page d'erreur peut boucler | Prévoir un layout d'erreur minimal sans dépendance auth | P2 | Robustesse |

### 2.8 Responsive & accessibilité transverses

| Localisation | État actuel | Problème | Proposition | Priorité | Impact |
|---|---|---|---|---|---|
| `formadoc.css` | Breakpoints | Breakpoints à 900/768/560/480 mais pas de test à 320px | Vérifier 320px : boutons, formulaires, modales, chat | P1 | Mobile |
| `formadoc.css` | Zones cliquables | Certains boutons `icon-btn` font 36×36px (< 44px requis WCAG) | Passer à ≥ 44×44px (ou padding équivalent) | P1 | A11y |
| `formadoc.css` | `prefers-reduced-motion` | ✅ Présent (ligne 1004 et 1350) | Vérifier qu'il couvre les animations du chat et des toasts | P2 | A11y |
| `layouts/app.blade.php` | Modales | Aucune modale n'a de gestion de focus | Créer un composant Alpine.js `x-modal` réutilisable (focus trap + Escape + scroll lock) | **P0** | A11y |
| `layouts/app.blade.php` | Toasts | `aria-live="polite"` ✅ | Ajouter `role="status"` sur les toasts de succès et `role="alert"` sur les erreurs | P1 | A11y |
| `formadoc.css` | Contraste | Vérifier `--color-text-muted` (#7e8698) sur fond blanc : ratio ≈ 4.0:1 → sous 4.5:1 | Assombrir le muted (#6b7385) en clair ; vérifier le dark mode | P1 | A11y |
| `formadoc.css` | Focus visible | `:focus-visible` ✅ | S'assurer que les `.chat-item[role=button]` et `.payment-method[role=button]` ont un focus visible | P1 | A11y |
| `layouts/app.blade.php` | Menu mobile | Sidebar fonctionne (toggle + overlay + Escape) ✅ | Vérifier l'état `aria-expanded` initial et le focus après fermeture | P2 | A11y |
| `chat/index.blade.php` | `chat-sidebar` | Sur mobile (< 900px), la sidebar passe au-dessus — hauteur/largeur à vérifier | Tester 320px : la sidebar de conversations doit rester utilisable | P1 | Mobile |

---

## 3. Parcours utilisateurs critiques — frictions majeures

### 3.1 Parcours upload
1. Clique « Nouveau document » → `documents/upload`
2. **Friction** : la promesse « Glissez-déposez » n'est pas implémentée (pas de drag & drop réel).
3. Sélection du fichier → preview (OK) → choix méthode (Regex/IA) → « Lancer l'analyse ».
4. **Friction** : pas de validation taille/type avant envoi → erreur tardive pour un fichier trop gros.
5. Soumission AJAX → animation d'analyse (journal décoratif).
6. Redirection vers validation → traitement → export.

**Problème bloquant** : l'écran `processing` est statique. Pour un rapport long avec IA (plusieurs minutes), l'utilisateur ne sait pas si ça avance → risque d'abandon ou de rechargement (qui relance tout).

### 3.2 Parcours traitement (long)
- Pas de polling, pas de temps écoulé, pas de possibilité d'annuler.
- **Recommandation** : ajouter un endpoint `GET /documents/{doc}/status` (JSON) + polling 15-30 s + temps écoulé + bouton « Continuer » dès que prêt (au lieu d'un rechargement aveugle).

### 3.3 Parcours téléchargement
- Export → page de garde (facultatif) → régénération → téléchargement.
- **Friction** : le bouton de téléchargement n'a pas d'état de chargement → double-clic possible, ou attente sans feedback si génération > 5 s.

### 3.4 Parcours paiement
- Choisir plan → checkout → méthode de paiement → KPay/Orange/MTN.
- **Friction bloquante** : `payName` sans attribut `name` → le nom sur la carte n'est pas envoyé (incohérent, mais le paiement peut passer sans).
- **Friction** : pas d'état de chargement au clic « Payer » → double-clic = risque de double initiation.
- **Friction** : après retour KPay, pas de page de confirmation dédiée (flash toast uniquement) — tâche A7 du plan.

### 3.5 Parcours inscription
- Register → compte créé → **aucun onboarding** (la page existe mais n'est pas routée).
- **Friction** : pas de « mot de passe oublié » → l'utilisateur bloqué ne peut pas se reconnecter (P0).
- **Friction** : pas d'email de bienvenue (tâche H2 du plan).

---

## 4. Écrans manquants proposés (maquettes textuelles)

### 4.1 Écran de traitement avec progression réelle (P0)
```
┌──────────────────────────────────────────────┐
│ Étape 3/4 — Traitement              [⏱ 01:23] │
│ Traitement en cours                           │
│ "rapport_stage.docx" selon le gabarit choisi  │
│                                               │
│  [████████████░░░░]  62%                       │
│                                               │
│  ✓ Analyse de la structure                    │
│  ✓ Application du gabarit                     │
│  ● Génération du DOCX…                        │
│                                               │
│  [Annuler]  [Continuer vers l'export (dès prêt)]│
└──────────────────────────────────────────────┘
```

### 4.2 Modale de confirmation d'abandon (P1)
```
┌────────────────────────────────────────┐
│  Quitter le traitement ?               │
│  Votre document n'est pas encore prêt. │
│  Vous pourrez reprendre depuis la      │
│  bibliothèque « Mes documents ».       │
│        [Continuer]  [Quitter quand même]│
└────────────────────────────────────────┘
```

### 4.3 Page « Mot de passe oublié » (P0)
```
┌────────────────────────────────────────┐
│ Mot de passe oublié                    │
│ Saisissez votre adresse e-mail : nous  │
│ vous enverrons un lien de réinitialisa-│
│ tion.                                  │
│ [vous@exemple.com        ]             │
│ [Envoyer le lien de réinitialisation]  │
│ ← Retour à la connexion                │
└────────────────────────────────────────┘
```

### 4.4 État « Réponse IA en cours » dans le chat (P0)
```
  Vous :  Reformule ce paragraphe…
  ┌───────────────────────────────┐
  │  ● ● ●  L'IA réfléchit…       │
  │  (3 points animés, aria-busy) │
  └───────────────────────────────┘
```

### 4.5 Page 404 minimal (sans dépendance auth) (P2)
```
         FORMADOC
   ┌──────────────────────────┐
   │        (404)             │
   │  Page introuvable        │
   │  [Retour à l'accueil]    │
   └──────────────────────────┘
```

---

## 5. Checklist finale de validation UI/UX (avant mise en production)

### Bloquant (P0)
- [x] Drag & drop réel sur la dropzone (ou retirer la promesse)
- [x] Validation JS taille/type avant upload
- [x] Polling sur l'écran de traitement (ou au minimum temps écoulé + message « toujours en cours »)
- [x] Onboarding branché après inscription
- [x] Lien « Mot de passe oublié » (flux complet)
- [x] Modales accessibles : focus trap + Escape + focus initial
- [x] `name` sur les champs de carte dans le checkout
- [x] État de chargement sur le bouton « Payer »
- [x] Indicateur « L'IA réfléchit… » dans le chat
- [x] Menu mobile landing fonctionnel

### Important (P1)
- [x] Contraste `--color-text-muted` ≥ 4.5:1
- [x] Zones cliquables ≥ 44×44px
- [x] Tableaux (factures, docs) responsive à 320-560px
- [x] Recherche navbar renommée « Filtrer la page » ou recherche globale
- [x] Texte auth « (Word, PDF) » sans PDF
- [x] `role="status"` / `role="alert"` sur les toasts
- [x] Focus visible sur les `[role=button]` custom
- [x] Message générique erreur réseau dans l'upload

### Amélioration continue (P2)
- [ ] Animations FAQ, menus déroulants accessibles
- [ ] Aperçu de contenu .doc / icônes par type
- [ ] Compteurs de statuts dans les filtres docs
- [ ] Infinite scroll ou pagination mobile
- [ ] `prefers-reduced-motion` couvrant chat + toasts
- [ ] Tests utilisateurs réels (5 étudiants, 5 pros)

---

## 6. Recommandations pour tester l'UI/UX

1. **Tests utilisateurs rapides (5-8 pers.)** : étudiants cibles, sur mobile 320-390px et connexion lente (throttle DevTools 3G). Observer : upload, traitement long, paiement.
2. **Tests d'accessibilité automatisés** : axe-core (extension DevTools) sur chaque page principale → viser 0 violation critique.
3. **Test clavier complet** : tab order sur upload, checkout, chat, modales (aucun piège de focus).
4. **Heatmap** (post-lancement) : Hotjar/Clarity sur la landing (sections scrollées, CTA cliqués) et l'upload (abandons).
5. **Mesures** : taux d'abandon entre upload et téléchargement ; taux de complétion du checkout ; temps moyen d'un traitement IA.
6. **Test réseau** : comportement hors-ligne (messages réseau, relance), et reprise de session après expiration.

---

## 7. Prochaines étapes concrètes (suggestion)

| Étape | Action | Fichiers concernés |
|---|---|---|
| 1 (P0) | Créer un composant `x-modal` Alpine réutilisable (focus trap + Escape) | `layouts/app.blade.php`, `account/index.blade.php` |
| 2 (P0) | Drag & drop réel + validation taille/type dans l'upload | `documents/upload.blade.php` |
| 3 (P0) | Polling du statut sur l'écran de traitement | `documents/processing.blade.php` + route/controller |
| 4 (P0) | Brancher l'onboarding post-register | `routes/auth.php`, `AuthController` |
| 5 (P0) | Flux mot de passe oublié | routes + vues + mail (tâche H1) |
| 6 (P0) | État chargement checkout + `name` carte | `subscriptions/checkout.blade.php` |
| 7 (P1) | Contraste muted + zones cliquables + tableaux responsive | `formadoc.css`, `account/index`, `documents/index` |
| 8 (P1) | Indicateur chat « L'IA réfléchit… » + désactivation champ | `chat/index.blade.php`, `chat/show.blade.php` |
| 9 (P2) | Recherche globale ou renommage | `layouts/app.blade.php` |
| 10 | Re-audit après corrections | — |

---

*Rapport généré conformément au prompt `UI_UX_AUDIT.md`. Aucun fichier de l'application n'a été modifié.*
