# Adaptation de la maquette Stitch « Academic Precision »

> **Source** : maquette Google Stitch, 17 écrans + `DESIGN.md` (fournie dans une archive)
> **Objet** : registre des écarts entre la maquette et le backend réel, et règles d'adaptation retenues.
> **Principe directeur** : on adapte la maquette au produit réel, **jamais l'inverse**. Afficher une donnée qui n'existe pas est un échec silencieux : l'écran semble fonctionner tout en mentant.

---

## 1. Ce qui a été appliqué tel quel (identité visuelle)

Le `DESIGN.md` a été traduit dans `resources/css/formadoc.css`, qui sert **79 vues** via **246 classes**. Aucune vue n'a eu besoin d'être réécrite : l'identité passe par les jetons.

| Élément | Avant | Après (maquette) |
|---|---|---|
| Canevas | papier chaud `#FAF9F6` | ardoise froide `#FBFBFA` / `#F8FAFC` |
| Texte principal | encre chaude | ardoise profonde `#0F172A` |
| Action primaire | bleu marine `#2B3F66` | ardoise `#0F172A` |
| Lien / focus / nav active | confondu avec l'action | **cobalt `#2563EB`** (jeton `--color-accent` distinct) |
| Police de corps | Inter | **Plus Jakarta Sans** |
| Ombres | neutres | teintées ardoise, très discrètes |
| Chiffres | non alignés | `tabular-nums` (montants FCFA) |

**Deux jetons ont été ajoutés** au-delà de la maquette, parce qu'un seul jeton ne peut pas porter deux rôles :
- `--color-on-accent` — texte posé sur un fond cobalt (pagination active)
- `--color-on-secondary` — texte posé sur un fond émeraude

Sans eux, l'éclaircissement de ces couleurs en thème sombre rendait le texte illisible (texte blanc sur fond clair).

---

## 2. Écarts entre la maquette et le backend — décisions

### 2.1 Données affichées par la maquette qui n'existent PAS

| Donnée de la maquette | Réalité du backend | Décision |
|---|---|---|
| Opérateurs de paiement (Orange, Wave, MTN, Moov) | `kpay_payments` ne stocke **aucun opérateur** : seulement `payment_id`, `status`, `amount_fcfa`, `metadata` | **Ne pas afficher.** Une colonne « Opérateur » vide, ou déduite du `metadata` quand présent, serait trompeuse |
| Nœuds / régions (Dakar, Abidjan, « Nœud Dakar ») | Aucune notion de nœud ni de région dans le schéma | **Ne pas afficher.** FORMADOC est mono-serveur |
| « Latence IA: 240ms » | Aucune mesure de latence n'est enregistrée | **Ne pas afficher** (ou seulement si le registre d'usage gagne un champ `duration_ms` — il existe par appel, à vérifier) |
| « Console Centrale v2.4 » | Aucune notion de version de console | Remplacer par l'état réel des pipelines (`document.pipeline.v2`) |
| « Parc académique UEMOA » | Aucune segmentation géographique | Formulation neutre |
| Revenus « Ce mois (Août 2026) » | Les revenus existent (`billing.report`, `ai_usage_ledger`) | **Conserver** — donnée réelle, à brancher sur `AdminController::billing()` |
| « Pr. Alassane » (nom d'exemple) | Données d'exemple de la maquette | Utiliser les vraies données utilisateur |

### 2.2 Écrans de la maquette sans équivalent backend

| Écran de la maquette | Équivalent réel | Décision |
|---|---|---|
| `tableau_de_bord_administrateur` | `admin/index.blade.php` ✅ existe | **Adapter** aux vraies données |
| `tableau_de_bord_utilisateur_desktop` | `dashboard.blade.php` ✅ existe | **Adapter** |
| `tableau_de_bord_utilisateur_mobile` | idem, responsive | **Adapter** (le CSS gère déjà le repli) |
| `mes_documents_biblioth_que` | `documents/index.blade.php` ✅ existe | **Adapter** |
| `nouveau_document_mise_en_forme` | `documents/upload.blade.php` ✅ existe | **Adapter** |
| `assistant_ia_chat_documentaire` | `chat/index.blade.php` + `show.blade.php` ✅ | **Adapter** |
| `mon_compte_cr_dits_mobile_money_fcfa` | `account/*.blade.php` ✅ | **Adapter** |
| `mod_les_gabarits_acad_miques` | `templates/index.blade.php` ✅ | **Adapter** |
| `connexion_inscription` | `auth/*.blade.php` ✅ | **Adapter** |
| `accueil_formadoc_dition_acad_mique_cames` | `landing.blade.php` ✅ | **Adapter** |
| **« Page de garde » dans les écrans** | Module **retiré du produit** | **Ne pas réintroduire.** Un test d'invariant (`CoverModuleRemovalInvariantTest`) l'interdit |

---

## 3. ⚠️ Affirmations commerciales de la maquette — NE PAS REPRENDRE

La maquette de la landing est **beaucoup plus engageante** que le produit réel. Reprendre ces textes tels quels créerait des **promesses non tenues** — le défaut que `AUDIT_COPYWRITING.md` signale déjà (P1 : « vérifier les promesses faites à l'utilisateur »).

Chaque ligne ci-dessous a été **vérifiée dans le code**. Le verdict est sans ambiguïté.

| Affirmation de la maquette | Réalité vérifiée | Verdict |
|---|---|---|
| « Homologué Normes CAMES & Décret LMD UEMOA » | **Aucune occurrence de « CAMES » dans le code** (0 sur `app/`, `config/`, `database/`) | ❌ **Non fondé.** Une homologation suppose un organisme certificateur, absent |
| « Plus de 12 000 thèses et mémoires mis en forme » | Aucun compteur, aucune donnée publique de volume | ❌ **Invérifiable.** Un chiffre d'usage ne se déduit pas du code |
| « Chiffrement AES-256 » (données) | `SESSION_ENCRYPT=true` chiffre **les sessions**. Les **fichiers** stockés (`config/filesystems.php`) n'ont aucune option `encrypt` | ⚠️ **Trompeur.** Vrai pour la session, faux pour les documents — et c'est ce que le client comprend |
| « Suppression automatique après export » | `files:purge-temp` supprime les **temporaires** (TTL 24 h). Les documents et pièces jointes **restent** | ❌ **Faux.** Confondre les deux ferait croire à un effacement qui n'a pas lieu |
| « Universités partenaires : UCAD, UFHB, INP-HB, UJKZ, UAC, ESP, 2iE » | Aucune notion de partenariat en base | ❌ **Non fondé.** Un partenariat est un fait juridique |
| « Gabarits certifiés : APA 7, CAMES v3, Harvard » | Templates réellement en base : **« Rapport », « Mémoire », « Document professionnel »** | ❌ **Faux.** Aucun gabarit APA/CAMES/Harvard n'existe |
| « Essai gratuit 5 crédits offerts » | Aucun plan gratuit en base (seuls `standard`, `premium`, `pro`, `enterprise`) | ❌ **Non implémenté.** Promettre des crédits non provisionnés est une dette commerciale |
| « Rejet garanti par le Comité de Lecture » | Aucun comité, aucune instance de validation | ❌ **Non fondé** |
| « 65 % des rejets proviennent de vices de forme » | Aucune source, aucune donnée | ❌ **Invérifiable** |
| Paiement « Wave, Orange, MTN, Moov » | `kpay_payments` ne stocke **aucun opérateur** ; `config/kpay.php` liste les moyens de façon générique | ✅ **Fondé — vérifié dans le code** : `subscriptions/checkout.blade.php` propose réellement `payment_method` = `orange` / `mtn` (saisi par l'utilisateur), et le site nomme Orange Cameroon et MTN Cameroon (opérateurs du Cameroun, cohérent avec le siège à Douala) |
| « Marge reliure 3.5 cm », « Pagination i, ii → 1, 2, 3 » | **Réel** : `DocumentReconstructor` gère la section frontispice romaine puis le corps arabe | ✅ **Fondé** — c'est une force à mettre en avant |
| « Le moteur ne modifie pas un seul mot » | **Réel** : moteur déterministe, le texte n'est jamais reformulé (sauf outil explicite) | ✅ **Fondé** |
| « Word, LibreOffice, Google Docs, Overleaf » | Entrées : `.docx`, `.doc`, `.txt`. Sortie : `.docx`, `.pdf` | ⚠️ **Imprécis.** « Overleaf » (LaTeX) n'a aucun support |

### 3.1 Ce qu'il faut faire de ces textes

**Décision : ils ne sont pas repris.** La landing actuelle (410 lignes, 6 sections) est **factuelle** : elle ne contient aucune affirmation chiffrée non vérifiable. C'est une qualité à préserver, pas une faiblesse à corriger.

Si ces affirmations doivent être utilisées (argument commercial légitime), elles exigent **d'abord** une décision produit :
- **CAMES / APA / Harvard** → créer les gabarits correspondants, puis les nommer ;
- **5 crédits offerts** → provisionner le plan gratuit et son quota ;
- **AES-256** → chiffrer réellement les fichiers au repos (option `encrypt` des disks), ou ne pas l'écrire ;
- **12 000 thèses / 65 % / Comité de lecture** → sourcer ou retirer.

Ce sont des engagements commerciaux : ils ne se décident pas dans une tâche d'adaptation graphique.

---

## 4. Règle d'or à respecter pour chaque écran adapté

1. **Chaque chiffre affiché doit être traçable** jusqu'à une requête ou un calcul existant. Pas de valeur d'exemple qui reste en production.
2. **Une donnée absente est omise**, jamais remplacée par un tiret ou un zéro qui laisserait croire à une mesure.
3. **Un libellé ne promet rien que le produit ne fasse.** Si un écran annonce une action, elle doit exister.
4. **Le thème sombre est vérifié** pour tout nouveau composant (via `--color-on-*`, jamais `#fff` en dur).

---

## 5. État d'avancement

- [x] Identité visuelle appliquée (CSS, polices, jetons de contraste)
- [x] Polices harmonisées sur les 3 layouts (`app`, `admin`, `landing`)
- [x] Ancienne palette purgée du CSS et des vues
- [x] Chiffres tabulaires pour les montants
- [x] **Admin** : section « Moteurs & modèles IA » ajoutée sur données réelles
- [x] **Tableau de bord** : KPI remis en forme, progression inventée retirée
- [x] **Logo de l'application** intégré (sidebar, admin, landing, footer) + favicon
- [x] **Admin** : porte d'entrée visible dans la sidebar (section « Exploitation »)
- [x] **Admin** : layout réécrit sur les composants partagés (`sidebar`, `navbar`,
      `stat-grid`, `stat-item`, `page-head`, `split-grid`) — thème sombre, repli
      mobile et bascule de thème fonctionnels, sortie vers les autres espaces
- [x] Vérifié : aucune donnée d'exemple de la maquette dans les vues. Les seules
      occurrences de « Orange Money » / « MTN MoMo » sont de **vraies options de
      paiement** (`subscriptions/checkout.blade.php` envoie `payment_method` =
      `orange` / `mtn`) ; les opérateurs de la maquette absents du produit
      (Wave, Moov) ne sont mentionnés nulle part
- [x] Test de garde (jetons **et** classes) — 4 tests
- [ ] Écrans restants adaptés un par un (landing, chat, documents, compte, modèles…)

### 5.1 Défauts corrigés pendant l'adaptation

Tous de la **même famille** : une référence qui ne résout rien, sans erreur ni log. Ils ont été trouvés par le test de garde, pas par relecture.

| Défaut | Effet visible | Pourquoi c'était invisible |
|---|---|---|
| `--color-success`, `--color-danger`, `--color-ink` jamais définis | Badges « Validé » et « Échec » **identiques** | Le nom du jeton est correct à la lecture |
| `.banner-info` absente | L'encadré de vérification d'e-mail perdait sa variante | La classe de base `.banner` s'appliquait quand même |
| `.chat-empty` utilisée sans être définie, styles inline dupliqués | — | Les styles inline masquaient l'absence de règle |
| `toast-success` / `toast-error` / `toast-warning` vs `.toast.success` | **Tous les toasts se ressemblaient** : succès et erreur indiscernables | Deux conventions de nommage coexistaient (BEM vs Laravel) |
| `.stat-icon` / `.value` / `.label` inexistantes dans le tableau de bord | Les 4 cartes KPI **sans mise en forme** — sur l'écran d'accueil | Le design system attend `.stat-label` / `.stat-value` |
| Espace `/admin` sans **aucun lien** dans l'interface | Un administrateur voyait l'interface d'un utilisateur ordinaire, à l'identique | Le middleware répond 404 (pas 403) pour ne pas révéler l'espace : aucune page ne pouvait donc en parler |
| Logo en texte (`<span class="mark">FD</span>`) au lieu du fichier de marque | — | Rien ne le signale : le monogramme typographique restait cohérent |
| Le logo posé sur la sidebar sombre formait un **carré blanc** | Visible en thème sombre uniquement | Le fichier source (`logo 1.1`) n'a **aucune transparence** (fond blanc opaque, 24 bpp) |
| `logo large formadoc.png` : **941 Ko** pour un rendu de 26 px | Temps de chargement, gaspillage de bande passante | Le poids d'un PNG ne se voit pas dans le code |
| Cadrage du logo laissé à la marge interne du fichier | Logo petit et décentré | Le contenu réel occupe 349×349 dans une image de 427×435 (et 1386×349 dans 1536×1024) |
| Icône du bouton de thème **jamais mise à jour** | Le bouton semblait mort : le fond changeait, l'icône restait une lune | Lucide **remplace** le `<i>` par un `<svg>` au premier rendu ; le code ciblait `#theme-toggle i`, sélecteur qui ne trouve plus rien après. Défaut présent dans les **deux** layouts |
| Espace admin écrit en styles inline, dupliquant `.sidebar` / `.navbar` | L'admin ne suivait ni le thème sombre ni les évolutions du design system | Le layout réimplémentait sa propre barre de navigation avec des valeurs figées |
| `.role-tag` définie seulement sous `.sidebar-nav a` | L'étiquette de rôle serait restée **sans style** dans la barre supérieure de l'admin | Une classe non stylée ne se remarque pas : l'élément « existe » quand même |
| Taux de confiance absent rendu par un tiret `—` | Un tiret occupe la place d'une mesure et laisse croire qu'elle a été tentée | Contraire à la règle d'or du document (§ 4.2) : une donnée absente s'omet |

### 5.2 Donnée inventée supprimée

Le tableau de bord affichait `width: 58 %` **en dur** pour la barre de progression de tout document en cours : un pourcentage sans rapport avec l'avancement réel, qui ne bougeait jamais. Le traitement est asynchrone et son avancement n'est pas mesurable par étapes. La barre n'apparaît désormais que pour un état **connu** (terminé, ou échec) ; l'état « en cours » s'exprime par le badge, pas par un chiffre fabriqué. C'était la seule du projet (vérifié sur toutes les vues).

### 5.3 Faux positifs écartés (ne pas « corriger »)
Vérifiés un par un, ces cas sont **légitimes** — les corriger serait une régression :

| Cas | Pourquoi c'est correct |
|---|---|
| `honeypot` | Champ masqué par un `style` inline, volontairement (anti-spam : il doit rester invisible même sans CSS) |
| `quick-amount`, `pm-radio-input` | Hooks fonctionnels (attribut `data-amount`, `input[type=radio]`) ; le style vient des classes voisines |
| `msg-time` | Stylé par son parent `.message .meta` — un enfant sans règle propre est normal |
| `is-invalid` | Sur un input `sr-only` (caché) ; les erreurs sont rendues par `.field-error` |

### 5.4 Poids et cadrage des fichiers de marque

`logo large formadoc.png` pèse **941 Ko** pour un rendu de 26 px : le poids d'un PNG ne se voit pas dans le code. Les dérivés sont donc **générés** dans `public/images/`, avec un rognage calculé sur le contenu réel et non sur la marge interne du fichier.

| Fichier | Taille | Poids | Usage |
|---|---|---|---|
| `logo-mark.png` | 64×64 | 7 Ko | favicon, monogramme (sidebar, landing, footer) |
| `logo-mark@2x.png` | 128×128 | 21 Ko | écrans haute densité |
| `apple-touch-icon.png` | 180×180 | 36 Ko | raccourci iOS |
| `logo-full.png` | 640×161 | 75 Ko | mot-symbole complet (réserve) |
| `logo-full@2x.png` | 320×81 | 25 Ko | en-tête de l'espace admin |
| `favicon.ico` | 16/32/48 | 7 Ko | onglet navigateur |

`resources/images/` reste la **source** (les originaux y sont versionnés) ; `public/images/` ne contient que des dérivés servis par le web. `Vite::asset()` aurait aussi fonctionné — c'est la méthode documentée pour `resources/images/` — mais un favicon doit rester disponible même si le manifest de build est absent, et `asset()` n'échoue jamais dans ce cas.
