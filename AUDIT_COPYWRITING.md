# AUDIT COPYWRITING — FORMADOC

**Date :** 2026 (session en cours) · **Périmètre :** landing page, application (Blade), emails transactionnels, textes de tarification, messages flash, états vides/erreurs, pages légales (manquantes) · **Cible :** étudiants et professionnels au Cameroun (DQP, CQP, BTS, licences, masters ; cabinets et entreprises).

> **Note de méthode** : conformément à la consigne, **aucun fichier n'a été modifié**. Ce rapport liste les corrections, les textes manquants (avec modèles complets) et les priorités. Les numéros de ligne correspondent aux fichiers actuels ; ils peuvent bouger de quelques lignes après édition.

---

## 1. Synthèse exécutive

### Points forts (à conserver)
- **Transparence tarifaire** : « 1 crédit = 1 FCFA », « coût affiché avant toute action IA », mode déterministe gratuit — messages rares et crédibles, à préserver.
- **Microcopy de confiance** : « Non cochée par défaut : aucune donnée n'est envoyée à un service externe » (upload), confirmation de coût avant envoi du chat, modale d'annulation rassurante (« Garder mon abonnement »).
- **États vides et erreurs** globalement bien rédigés (404, 500 rassurant « vos documents ne sont pas affectés »).
- **Titres de pages et eyebrows** cohérents dans l'application (formule « eyebrow + H1 + sous-titre » appliquée partout).

### Problèmes bloquants (P0) — résumé
| # | Problème | Localisation |
|---|----------|--------------|
| 1 | **Incohérence « 2 documents » vs « 5 documents »** dans l'offre gratuite | `auth/register.blade.php` |
| 2 | **PDF annoncé comme accepté, mais l'upload refuse les PDF** (`.docx,.doc,.txt` uniquement) | landing (FAQ + étapes), onboarding, vs `documents/upload.blade.php` |
| 3 | **« Orange CI » / « MTN CI »** dans le checkout alors que le produit est basé  (Cameroun) | `subscriptions/checkout.blade.php` |
| 4 | **Liens légaux vides** (`href="#"`) : Mentions légales, CGU, Politique de confidentialité — pages inexistantes | `landing.blade.php` (footer) |
| 5 | **Emails factices** : `support@formadoc.example` (landing) et `owner@formadoc.dev` (feedback) | `landing.blade.php`, `FeedbackController.php` |

### Problèmes importants (P1) — résumé
- Ton tutoiement/vouvoiement non normalisé (cf. §2.1).
- « immédiatement » dans le checkout alors que la confirmation KPay peut prendre quelques minutes.
- « page de statut » mentionnée (erreurs 500/503) mais inexistante.
- Pas de lien « Mot de passe oublié ? » sur la page de connexion.
- « Maintenance planifiée · Retour estimé : 14h30 UTC » codé en dur.
- Promesses à vérifier : « Recherche web » (chat), « API & intégrations » (plan Pro).
- Texte technique exposé : « LibreOffice n'a pas répondu » (aperçu indisponible).

---

## 2. Constats transverses

### 2.1 Ton : tutoiement vs vouvoiement
Le prompt de référence impose : **tutoiement pour les étudiants, vouvoiement pour les entreprises/centres de formation**. Or l'application mélange actuellement :
- Vouvoiement partout dans l'app (login, register, chat, dashboard, erreurs) ;
- Tutoiement dans le chat des suggestions (« Explique-moi comment structurer… »), dans les chips d'action (« Génère une page de garde… ») et dans l'email « Merci {{ $user->name }} ! ».

**Recommandation** : choisir un ton par contexte :
- **Landing + inscriptions (étudiants) : tutoiement** — « Téléverse ton rapport, choisis un gabarit, télécharge. »
- **Pages entreprises/cabinets (plan Entreprises, contact) : vouvoiement**.
- **Application (product) : tutoiement** pour rester cohérent avec le chat et les chips.

Tout changement doit être appliqué de bout en bout (éviter « vos documents » à côté de « ton rapport »).

### 2.2 Chiffres et promesses à vérifier avant mise en ligne
| Promesse | Localisation | État |
|---|---|---|
| « 5 documents gratuits/mois » | landing, account, QuotaService (retourne 5) | ✅ cohérent, sauf register (voir P0-1) |
| « Les PDF sont automatiquement convertis en DOCX » | landing FAQ + étapes ; onboarding | ❌ non implémenté dans l'upload |
| « Recherche web » (chat) | chat/index (chip + action) | ⚠️ à vérifier côté backend |
| « API & intégrations » (plan Pro) | PlanSeeder | ⚠️ à vérifier |
| « Orange CI » / « MTN CI » | checkout | ❌ pays incohérent (Cameroun) |
| « page de statut » | erreurs 500/503 | ❌ inexistante |
| « retour estimé : 14h30 UTC » | states/maintenance | ⚠️ en dur, risque de fausse info |

---

## 3. Tableau des corrections

### 3.1 Landing page (`resources/views/landing.blade.php`)

| Localisation | Texte actuel | Type | Problème | Proposition | Priorité | Impact attendu |
|---|---|---|---|---|---|---|
| L6-8 (meta description) | « FORMADOC — mettez en forme automatiquement vos mémoires, rapports et CV. Mode déterministe gratuit, assistance IA optionnelle. » | SEO | Pas de mot-clé local (étudiants, Cameroun), pas de bénéfice temps/qualité | « FORMADOC met en forme automatiquement vos rapports, mémoires et CV — DQP, BTS, licence, master. 5 documents gratuits par mois, assistance IA optionnelle à coût affiché. » | P1 | Référencement local + clarté de l'offre |
| L10 (title) | « FORMADOC — Mise en forme automatique de documents » | SEO | Peut cibler le bénéfice étudiant | « FORMADOC — Mise en forme automatique de rapports, mémoires et CV » | P1 | Meilleur CTR recherche |
| L67 (eyebrow) | « FORMADOC SaaS » | Microcopy | « SaaS » est technique, sans bénéfice | « Mise en forme automatique de documents » | P2 | Compréhension immédiate |
| L68 (H1) | « Vos documents, mis en forme au cordeau. » | Proposition de valeur | Correct mais générique ; ne parle pas directement aux étudiants ni au bénéfice temps | Voir §4 (3 variantes) | P1 | Taux de conversion hero |
| L69 (sous-titre) | « Rapports, mémoires, CV et documents professionnels transformés automatiquement en fichiers propres et cohérents — avec ou sans assistance IA. » | Proposition de valeur | Long ; « avec ou sans assistance IA » est défensif en position secondaire | « Rapports, mémoires, CV : téléverse un fichier, choisis un gabarit, télécharge un document propre et cohérent — sans perdre ton contenu. » | P1 | Clarté + tutoiement |
| L72-73 (CTA hero) | « Commencer gratuitement » / « Voir les tarifs » | CTA | « Commencer gratuitement » bon ; « Voir les tarifs » secondaire ok | Conserver ; tester « Analyser mon premier document » en variante A/B | P2 | Test A/B |
| L76-78 (hero-proof) | « DOCX / PDF » · « 5 documents gratuits/mois » · « IA optionnelle » | Preuve | « DOCX / PDF » faux si le PDF n'est pas accepté (voir P0-2) | Remplacer « DOCX / PDF » par « DOCX · DOC · TXT » tant que le PDF n'est pas supporté | **P0** | Crédibilité (éviter le rejet au moment de l'upload) |
| L99-102 (trust strip) | « MODE DÉTERMINISTE TOUJOURS DISPONIBLE · PAIEMENT KPAY · ORANGE MONEY · MTN MOMO · COÛT AFFICHÉ AVANT TOUTE ACTION IA » | Preuve | Bien. « PAIEMENT KPAY · ORANGE MONEY · MTN MOMO » cohérent avec le Cameroun | Ajouter « SANS CARTE BANCAIRE » si pertinent ; sinon conserver tel quel | P2 | Rassurance paiement |
| L118-150 (features) | « Structure détectée », « Gabarits maîtrisés », « Page de garde sur mesure », « Assistant IA intégré », « Crédits transparents », « Aperçu fidèle » | Contenu | Bonnes features ; certaines formulations techniques (« Structure détectée ») | « Structure reconnue automatiquement » ; « Assistant IA intégré » → « Assistant IA (facultatif, coût affiché) » | P2 | Lisibilité |
| L179 (étape 1) | « Un fichier DOCX ou PDF, jusqu'à 50 Mo. » | Contenu | **PDF non accepté par l'upload** | « Un fichier DOCX, DOC ou TXT, jusqu'à 50 Mo. » (ou implémenter le PDF) | **P0** | Éviter frustration au téléversement |
| L208 (plan Gratuit, landing) | « Pour tester le mode déterministe. » | Tarifs | « tester » sous-vend l'offre | « Pour commencer gratuitement, sans carte bancaire. » | P1 | Activation de l'inscription |
| L210-212 (plan Gratuit, landing) | « 5 documents / mois · Mode déterministe uniquement · Modèles publics » | Tarifs | N'évoque ni pages de garde ni chat IA (pourtant inclus au coût réel) | Ajouter « Pages de garde incluses » et « Chat IA au coût réel (crédits) » | P2 | Parité d'info avec le compte |
| L232-234 (CTA plans) | « Choisir » (chaque plan) | CTA | Générique | « Choisir Standard », « Passer à Premium », etc. | P2 | Clarté d'action |
| L252-280 (FAQ) | 5 questions | Contenu | Pas de question sur : sécurité des documents, paiement Mobile Money, confidentialité, crédits (comment ça marche), support | Voir §6.3 (FAQ complémentaire) | P1 | Réduction des frictions |
| L276-278 (FAQ formats) | « Les fichiers .docx et .pdf jusqu'à 50 Mo. Les PDF sont automatiquement convertis en DOCX pour l'analyse. » | Contenu | **Promesse non tenue** (upload sans PDF) | « Les fichiers .docx, .doc et .txt jusqu'à 50 Mo. » (tant que le PDF n'est pas supporté) | **P0** | Crédibilité |
| L283-285 (CTA final) | « Prêt à mettre vos documents en forme ? » / « Cinq documents gratuits par mois, sans carte bancaire requise pour commencer. » | CTA | Bon | Conserver ; tester « Tes cinq premiers documents gratuits t'attendent. » | P2 | A/B test |
| L306 (footer brand) | « La mise en forme automatique de documents, avec ou sans assistance IA. Basé à Douala, Cameroun. » | Contenu | Bon. Pourrait ajouter le public cible | « … conçu pour les étudiants et professionnels au Cameroun. Basé à Douala. » | P2 | Proximité |
| L316-317 (footer Support) | « Contacter le support » → `mailto:support@formadoc.example` | Contact | **Domaine factice** | Remplacer par l'email réel (ex. support@formadoc.cm) ; sinon lien vers formulaire de feedback | **P0** | Confiance, délivrabilité |
| L324-326 (footer Légal) | « Mentions légales », « Conditions générales », « Politique de confidentialité » → `href="#"` | Légal | **Liens morts, pages inexistantes** | Créer les 3 pages (modèles en §6.1) et router | **P0** | Conformité + confiance |

### 3.2 Inscription / connexion

| Localisation | Texte actuel | Type | Problème | Proposition | Priorité | Impact attendu |
|---|---|---|---|---|---|---|
| `auth/register.blade.php:17` | « Deux documents déterministes sont inclus chaque mois dans l'offre gratuite. » | Tarifs | **Incohérence : 5 documents partout ailleurs** (QuotaService = 5) | « Cinq documents déterministes sont inclus chaque mois dans l'offre gratuite. » | **P0** | Éviter le sentiment d'arnaque au premier usage |
| `auth/register.blade.php` (aside) | « Plan Gratuit : 5 documents déterministes / mois, chat IA et pages de garde. » | Tarifs | « chat IA » sous-entend un chat gratuit ; en réalité il consomme des crédits (0 crédit à l'inscription) | « Plan Gratuit : 5 documents déterministes / mois, pages de garde incluses. Chat IA au coût réel (crédits). » | P1 | Honnêteté de l'offre |
| `auth/register.blade.php:66` | « Créer mon compte » | CTA | OK | Conserver | — | — |
| `auth/login.blade.php` | « Se souvenir de moi » · « Pas encore de compte ? » | Microcopy | OK | Conserver | — | — |
| `auth/login.blade.php` | — | Fonctionnalité | **Absence de lien « Mot de passe oublié ? »** | Ajouter un lien sous le mot de passe (« Mot de passe oublié ? ») + flux de réinitialisation (email en §6.2) | P1 | Réduction des blocages de connexion |
| `AuthController.php:55` | « Ces identifiants ne correspondent pas à nos enregistrements. » | Flash | OK (sécurité) | Conserver | — | — |
| `AuthController.php:81` | « Compte créé. Bienvenue sur FORMADOC ! » | Flash | OK | Conserver | — | — |

### 3.3 Application (microcopy)

| Localisation | Texte actuel | Type | Problème | Proposition | Priorité | Impact attendu |
|---|---|---|---|---|---|---|
| `documents/upload.blade.php` (dropzone) | « Glissez-déposez votre rapport ici » · « ou cliquez pour parcourir vos fichiers » · « .DOCX · .DOC · .TXT — 50 Mo max » | Microcopy | **Contredit la landing (PDF)** ; « 50 Mo max » cohérent | Conserver les formats affichés (ils sont vrais) ; corriger la landing (P0-2) | **P0** | Cohérence produit |
| `documents/upload.blade.php` (analyse IA) | « Plus précise mais plus lente (quelques minutes selon la taille du rapport) » | Microcopy | Bonne transparence | Conserver | — | — |
| `documents/upload.blade.php` | « Utiliser l'assistance IA » + note « Non cochée par défaut : aucune donnée n'est envoyée à un service externe. » | Microcopy | Excellent | Conserver, c'est un argument de vente | — | — |
| `chat/index.blade.php` | « Historique conservé 30 jours » | Microcopy | Bon | Conserver | — | — |
| `chat/index.blade.php` | Chips « Recherche web » | Contenu | Promesse à vérifier (le chat fait-il réellement une recherche web ?) | Vérifier l'implémentation ; sinon retirer la chip ou la renommer (« Aide à la recherche de sources ») | P1 | Éviter une promesse non tenue |
| `chat/index.blade.php` | « posez une question ou demandez une action » (vouvoiement) | Ton | Incohérence avec chips tutoiement | « pose ta question ou demande une action » (tutoiement app) | P1 | Ton unifié |
| `chat/show.blade.php` | « Modèle : … — X crédit(s) consommé(s) au total » | Microcopy | OK | Conserver | — | — |
| `documents/processing.blade.php` | « selon le gabarit institutionnel » | Microcopy | « institutionnel » inadapté (gabarit choisi par l'utilisateur, pas forcément d'institution) | « selon le gabarit choisi » | P2 | Clarté |
| `documents/export.blade.php` | « Téléchargez le DOCX reconstruit ci-dessous. » | Microcopy | OK | Conserver | — | — |
| `subscriptions/checkout.blade.php` | « Orange Money — Orange CI » · « MTN MoMo — MTN CI » | Tarifs | **CI = Côte d'Ivoire ; le produit est à Douala (Cameroun)** | « Orange Money — Orange CM » et « MTN MoMo — MTN CM » (ou « Orange Money · Cameroun ») | **P0** | Confiance (erreur pays visible) |
| `subscriptions/checkout.blade.php` | « votre abonnement X sera activé immédiatement après confirmation du paiement » | Tarifs | Le webhook peut mettre quelques minutes (fallback 5 min) | « … sera activé sous quelques minutes après confirmation du paiement. » | P1 | Pas de promesse excessive |
| `subscriptions/checkout.blade.php` | « Paiement sécurisé » (eyebrow) | Microcopy | OK | Conserver | — | — |
| `account/index.blade.php` | « Documents traités mois 2026-08 » | Microcopy | Vérifier que « 2026-08 » est dynamique (mois courant) et non codé en dur | Si codé en dur : « Documents traités ce mois-ci » | P1 | Fraîcheur de l'info |
| `account/index.blade.php` | « Épuisé — plan supérieur ou crédits » | Microcopy | Abrupt | « Quota atteint — passe à un plan supérieur ou achète des crédits. » | P2 | Ton |
| `account/index.blade.php` | « Aucune transaction pour le moment. » | État vide | OK | Conserver | — | — |
| `account/settings.blade.php` | « Action définitive : vos documents, modèles personnels et historique de discussion seront supprimés après confirmation. » | Microcopy | Bon (clair) | Conserver | — | — |
| `AccountController.php:157` | « Votre compte a été supprimé définitivement. À bientôt ! » | Flash | « À bientôt ! » dissonant après une suppression | « Votre compte a bien été supprimé. Merci de votre passage, et bonne continuation ! » | P2 | Ton |
| `feedback/form.blade.php` | « Sélectionnez une note » · « Votre email » | Microcopy | OK | Conserver | — | — |
| `FeedbackController.php` | `config('app.project_owner_email', 'owner@formadoc.dev')` | Contact | **Domaine factice** | Configurer l'email réel du porteur de projet | P1 | Réception des avis |

### 3.4 États et erreurs

| Localisation | Texte actuel | Type | Problème | Proposition | Priorité | Impact attendu |
|---|---|---|---|---|---|---|
| `states/preview-fallback.blade.php` | « LibreOffice n'a pas répondu : l'aperçu PDF n'a pas pu être généré… » | Erreur | Jargon technique exposé | « Le service d'aperçu est temporairement indisponible. Ton document reste généré et téléchargeable. » | P1 | Confiance, jargon supprimé |
| `states/maintenance.blade.php` | « Retour estimé : 14h30 UTC · REF-MAINT-2026-08 » | Erreur | Heure en dur, peut devenir fausse | Rendre la date dynamique (variable) ou « De retour très vite — nous vous prévenons par email. » | P1 | Fiabilité |
| `errors/500.blade.php` | « Vérifiez l'état du service sur notre page de statut. » | Erreur | Page de statut inexistante | Remplacer par « Contacte le support en indiquant la référence ci-dessus. » (l'action existe déjà) | P1 | Promesse tenue |
| `errors/503.blade.php` | « Suivez l'état du service sur notre page de statut. » | Erreur | Idem | Même correction | P1 | Promesse tenue |
| `errors/403.blade.php` | « … contactez le support. » | Erreur | Message renvoie au support mais l'action « Contacter le support » n'est pas proposée (seulement « Retour au tableau de bord » / « Aller à l'accueil ») | Ajouter une action « Contacter le support » → formulaire de feedback | P2 | Parcours de secours |
| `states/onboarding.blade.php` | « Accepter uniquement DOCX/PDF jusqu'à 50 Mo. » | Contenu | PDF non supporté | « Accepter uniquement DOCX, DOC et TXT jusqu'à 50 Mo. » | **P0** | Cohérence |

### 3.5 Tarifs et plans (`database/seeders/PlanSeeder.php`)

| Localisation | Texte actuel | Type | Problème | Proposition | Priorité | Impact attendu |
|---|---|---|---|---|---|---|
| Standard (description) | « Pour les étudiants et indépendants : IA sur documents, assistance chat de base. » | Tarifs | OK | Conserver | — | — |
| Premium (description) | « Pour les professionnels : modèles IA avancés, mises en forme généreuses. » | Tarifs | « mises en forme généreuses » vague ; « professionnels » exclut les étudiants exigeants | « Pour les mémoires et gros rapports : modèles IA avancés, génération d'images, support prioritaire. » | P1 | Clarté d'offre |
| Pro (features) | « API & intégrations » | Tarifs | Promesse forte — l'API est-elle réellement exposée ? | Vérifier ; sinon retirer ou « Accès API (sur demande) » | P1 | Pas de promesse non tenue |
| Pro (features) | « Skills documentaires Claude (expérimental) » | Tarifs | Honnête (« expérimental ») | Conserver (bonne pratique) | — | — |
| Entreprises | « Sur devis : volume, personnalisation, sécurité renforcée. » | Tarifs | OK | Conserver | — | — |

---

## 4. Trois variantes de proposition de valeur (hero)

### Variante A — Bénéfice émotionnel étudiant (temps retrouvé)
> **Titre :** « Tes rapports et mémoires impeccables, sans y passer tes nuits. »
> **Sous-titre :** « FORMADOC détecte la structure de ton document (rapport, mémoire, DQP, CV) et applique une mise en forme professionnelle, aux normes académiques. L'IA reste optionnelle — et son coût s'affiche avant chaque action. »

**Pourquoi ça marche :** parle directement à la douleur (le temps passé à la mise en forme), cible les étudiants, reste crédible.

### Variante B — Efficacité (processus en 4 étapes)
> **Titre :** « De ton brouillon au document final, en 4 étapes. »
> **Sous-titre :** « Téléverse un fichier, choisis un gabarit, valide la structure : FORMADOC reconstruit un document propre et cohérent, prêt à rendre. Sans IA par défaut, sans carte bancaire pour commencer. »

**Pourquoi ça marche :** rend le produit concret et simple, ancre le parcours, élimine l'objection « c'est compliqué ».

### Variante C — Confiance / transparence
> **Titre :** « Une mise en forme sans compromis — et sans coût caché. »
> **Sous-titre :** « Le moteur déterministe de FORMADOC applique tes gabarits sans jamais toucher à ton contenu. L'assistance IA ne s'active que si tu le décides, avec un coût estimé affiché avant chaque exécution. »

**Pourquoi ça marche :** différenciation par la transparence (rare sur ce marché), rassure sur les données et le prix.

> **Recommandation :** tester A/B la variante A contre la variante B (la C peut servir de page « sécurité/confidentialité »).

---

## 5. Textes manquants identifiés

| Texte manquant | Localisation attendue | Priorité |
|---|---|---|
| Page **Mentions légales** | `resources/views/pages/mentions-legales.blade.php` + route | **P0** |
| Page **Conditions générales d'utilisation (CGU)** | `resources/views/pages/cgu.blade.php` + route | **P0** |
| Page **Politique de confidentialité** | `resources/views/pages/confidentialite.blade.php` + route | **P0** |
| Email de **bienvenue** | `app/Mail/WelcomeMail.php` (envoi à l'inscription) | P1 |
| Email de **réinitialisation de mot de passe** | flux auth (absent) | P1 |
| Email **« Document prêt »** (notification de fin de traitement) | `app/Mail/DocumentReady.php` (si notifications activées) | P1 |
| Email **« Quota dépassé »** (proposition de plan supérieur) | `app/Mail/QuotaReached.php` | P2 |
| Email **« Paiement en attente / échec »** | `app/Mail/PaymentFailed.php` | P1 |
| Email **« Renouvellement prochain »** (J-3 avant renouvellement auto) | `app/Mail/RenewalReminder.php` | P2 |
| Page **« Mot de passe oublié »** | `resources/views/auth/forgot-password.blade.php` | P1 |

---

## 6. Modèles complets des textes manquants

### 6.1 Pages légales (contexte Cameroun)

> Les modèles ci-dessous utilisent `[À COMPLÉTER]` pour les informations juridiques de l'éditeur (RCCM, NIU, adresse, DPO…). Ils doivent être relus par un juriste avant publication.

---

#### 6.1.1 Mentions légales

```blade
@extends('layouts.app')

@section('title', 'Mentions légales')

@section('content')
<div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
    <div class="page-header">
        <div>
            <span class="eyebrow">Légal</span>
            <h1>Mentions légales</h1>
            <p>Informations relatives à l'éditeur et à l'hébergement de FORMADOC.</p>
        </div>
    </div>

    <div class="card" style="padding:1.8rem 2rem;line-height:1.75">
        <h2 class="card-title">1. Éditeur du service</h2>
        <p>
            Le site et le service FORMADOC sont édités par :<br>
            <strong>[Raison sociale ou nom de l'éditeur]</strong><br>
            [Forme juridique, capital social] — [RCCM n° …, NIU …]<br>
            [Adresse complète], Douala, Cameroun<br>
            Email : <a href="mailto:support@formadoc.cm">support@formadoc.cm</a>
        </p>
        <p><strong>Directeur de la publication :</strong> [Nom, Prénom].</p>

        <h2 class="card-title" style="margin-top:1.5rem">2. Hébergement</h2>
        <p>
            Le service est hébergé par :<br>
            <strong>[Nom de l'hébergeur]</strong> — [adresse, contact technique]<br>
            Les données sont hébergées en [zone géographique].
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">3. Propriété intellectuelle</h2>
        <p>
            L'ensemble des contenus de FORMADOC (textes, graphismes, logos, icônes, gabarits,
            code source) est protégé par le droit de la propriété intellectuelle. Toute
            reproduction, sans autorisation écrite préalable, est interdite. Les documents
            téléversés par les utilisateurs restent la propriété exclusive de leurs auteurs.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">4. Responsabilité</h2>
        <p>
            FORMADOC s'efforce d'assurer l'exactitude des informations publiées et la
            disponibilité du service, sans garantie d'absence totale d'interruption.
            L'éditeur ne saurait être tenu responsable d'un usage inapproprié des documents
            produits, ni de pertes de contenu consécutives à la suppression automatique
            des fichiers (voir CGU, rétention de 30 jours).
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">5. Droit applicable</h2>
        <p>
            Les présentes mentions sont soumises au droit camerounais. Tout litige relève
            de la compétence des tribunaux de Douala (Cameroun), sauf disposition légale
            impérative contraire.
        </p>
    </div>
</div>
@endsection
```

---

#### 6.1.2 Conditions générales d'utilisation (CGU / CGV)

```blade
@extends('layouts.app')

@section('title', 'Conditions générales d'utilisation')

@section('content')
<div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
    <div class="page-header">
        <div>
            <span class="eyebrow">Légal</span>
            <h1>Conditions générales d'utilisation</h1>
            <p>Les règles d'utilisation du service FORMADOC. En vigueur au [date].</p>
        </div>
    </div>

    <div class="card" style="padding:1.8rem 2rem;line-height:1.75">
        <h2 class="card-title">1. Objet</h2>
        <p>
            Les présentes conditions générales régissent l'accès et l'utilisation du service
            FORMADOC, qui permet de mettre en forme automatiquement des documents
            (rapports, mémoires, CV, documents professionnels) à partir de fichiers
            téléversés, avec ou sans assistance IA.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">2. Compte utilisateur</h2>
        <p>
            La création d'un compte est requise pour utiliser le service. L'utilisateur
            s'engage à fournir des informations exactes et à conserver la confidentialité
            de ses identifiants. Toute utilisation frauduleuse du compte peut entraîner
            sa suspension.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">3. Description du service</h2>
        <ul>
            <li><strong>Traitement déterministe</strong> : analyse de la structure du document et application d'un gabarit, sans envoi de données à un service externe.</li>
            <li><strong>Assistance IA (optionnelle)</strong> : amélioration de l'analyse ou mise en forme avancée, avec estimation du coût affichée avant exécution. Le coût réel est débité après usage.</li>
            <li><strong>Crédits</strong> : unité de paiement des traitements IA (1 crédit = 1 FCFA).</li>
            <li><strong>Abonnements</strong> : plans mensuels avec quotas (documents déterministes et traitements IA), renouvelables automatiquement, résiliables à tout moment.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">4. Conditions financières</h2>
        <ul>
            <li>Achat de crédits : minimum 500 FCFA, par carte bancaire, KPay, Orange Money ou MTN MoMo.</li>
            <li>Abonnements : paiement mensuel au début de chaque période ; renouvellement automatique sauf annulation avant la date de renouvellement.</li>
            <li>Annulation : le service reste actif jusqu'à la fin de la période déjà payée, puis bascule sur le plan Gratuit.</li>
            <li>Changement de plan : le prorata des jours restants de l'ancien plan est crédité en crédits.</li>
            <li>Remboursement : en cas de double débit avéré, le montant est recrédité sous 7 jours ouvrés. Aucun remboursement des crédits consommés.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">5. Quotas et plan Gratuit</h2>
        <p>
            Le plan Gratuit inclut 5 documents déterministes par mois et 0 traitement IA.
            Les quotas sont réinitialisés le 1er de chaque mois. Les documents téléversés
            sont supprimés automatiquement après 30 jours.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">6. Obligations de l'utilisateur</h2>
        <p>
            L'utilisateur s'engage à n'utiliser FORMADOC que pour des documents dont il
            détient les droits, à ne pas téléverser de contenus illicites, frauduleux ou
            contraires à l'ordre public, et à ne pas tenter de compromettre la sécurité
            du service.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">7. Données personnelles</h2>
        <p>
            Le traitement des données est décrit dans la
            <a href="{{ route('pages.privacy') }}">Politique de confidentialité</a>.
            L'activation de l'assistance IA implique l'envoi du contenu du document à un
            prestataire d'IA ; ce choix est toujours explicite et désactivé par défaut.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">8. Responsabilité</h2>
        <p>
            FORMADOC fournit un service de mise en forme. L'utilisateur reste seul
            responsable du contenu et de l'usage de ses documents. FORMADOC ne garantit
            pas un résultat conforme à un référentiel académique donné, ni l'absence
            d'erreur d'analyse automatique.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">9. Droit applicable — litiges</h2>
        <p>
            Les présentes CGU sont soumises au droit camerounais. En cas de litige, une
            solution amiable sera recherchée avant toute action judiciaire devant les
            tribunaux compétents de Douala (Cameroun).
        </p>
    </div>
</div>
@endsection
```

---

#### 6.1.3 Politique de confidentialité

```blade
@extends('layouts.app')

@section('title', 'Politique de confidentialité')

@section('content')
<div class="max-w-3xl" style="margin:0 auto;padding:2.5rem 0">
    <div class="page-header">
        <div>
            <span class="eyebrow">Légal</span>
            <h1>Politique de confidentialité</h1>
            <p>Comment FORMADOC collecte, utilise et protège vos données. En vigueur au [date].</p>
        </div>
    </div>

    <div class="card" style="padding:1.8rem 2rem;line-height:1.75">
        <h2 class="card-title">1. Responsable du traitement</h2>
        <p>
            Le responsable du traitement est [Raison sociale], [adresse], Douala, Cameroun.
            Contact : <a href="mailto:dpo@formadoc.cm">dpo@formadoc.cm</a>.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">2. Données collectées</h2>
        <ul>
            <li><strong>Compte :</strong> nom, adresse email, préférences (langue, thème, notifications).</li>
            <li><strong>Documents :</strong> fichiers téléversés et métadonnées associées (nom, taille, type, structure détectée).</li>
            <li><strong>Paiements :</strong> référence de transaction (via KPay ou autres passerelles) — FORMADOC ne conserve jamais les données de carte bancaire.</li>
            <li><strong>Usage :</strong> quotas consommés, conversations IA, factures.</li>
            <li><strong>Techniques :</strong> journaux de connexion, adresse IP, type de navigateur (sécurité et diagnostic).</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">3. Finalités et bases légales</h2>
        <ul>
            <li>Fournir le service (contrat) : traitement des documents, gestion des quotas.</li>
            <li>Facturation (contrat / obligation légale) : factures, reçus, historique d'achat.</li>
            <li>Support et communication (intérêt légitime) : répondre aux demandes, informer sur le service.</li>
            <li>Sécurité (intérêt légitime) : prévention des abus, journalisation.</li>
            <li>Amélioration du produit (intérêt légitime) : statistiques agrégées anonymes.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">4. Durée de conservation</h2>
        <ul>
            <li>Documents téléversés : <strong>30 jours</strong> après le traitement, puis suppression automatique.</li>
            <li>Compte : tant que le compte est actif, plus 30 jours après suppression.</li>
            <li>Factures : 10 ans (obligation fiscale).</li>
            <li>Journaux techniques : 12 mois maximum.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">5. Partage des données</h2>
        <p>
            FORMADOC ne vend aucune donnée. Les données peuvent être transmises à des
            sous-traitants strictement nécessaires :
        </p>
        <ul>
            <li><strong>Hébergeur</strong> [nom] pour le stockage et l'exécution du service ;</li>
            <li><strong>Prestataires de paiement</strong> (KPay, Orange Money, MTN MoMo) pour les transactions ;</li>
            <li><strong>Prestataire d'IA</strong> (OpenRouter et modèles associés) — <em>uniquement si vous activez l'assistance IA</em> ; à défaut, aucun contenu n'est transmis.</li>
        </ul>

        <h2 class="card-title" style="margin-top:1.5rem">6. Transferts hors du Cameroun</h2>
        <p>
            L'activation de l'assistance IA peut impliquer un transfert du contenu du
            document vers des serveurs situés hors du Cameroun. Ce transfert n'a lieu
            qu'avec votre consentement exprès (case « Utiliser l'assistance IA »),
            jamais par défaut.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">7. Vos droits</h2>
        <p>
            Conformément à la loi camerounaise n° 2010/012 du 21 décembre 2010 relative
            à la protection des données à caractère personnel, vous disposez des droits
            d'accès, de rectification, de suppression et d'opposition. Pour les exercer,
            écrivez à <a href="mailto:dpo@formadoc.cm">dpo@formadoc.cm</a> ou depuis les
            Paramètres de votre compte. Une réponse vous sera adressée sous 30 jours.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">8. Sécurité</h2>
        <p>
            Les données sont chiffrées en transit (HTTPS) et les accès internes sont
            restreints. Les mots de passe sont hachés et jamais stockés en clair.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">9. Cookies</h2>
        <p>
            FORMADOC utilise uniquement des cookies techniques et de préférences
            (session, langue, thème). Aucun cookie publicitaire ni traceur tiers n'est déposé.
        </p>

        <h2 class="card-title" style="margin-top:1.5rem">10. Modifications</h2>
        <p>
            Cette politique peut être mise à jour. Les utilisateurs en seront informés par
            email en cas de changement substantiel.
        </p>
    </div>
</div>
@endsection
```

---

### 6.2 Emails transactionnels manquants (modèles Laravel Markdown)

#### 6.2.1 Email de bienvenue

```
@component('mail::message')
# Bienvenue sur FORMADOC, {{ $user->name }} !

Ton compte est prêt. Dès maintenant, tu disposes de **5 documents déterministes
gratuits par mois** : téléverse un fichier, choisis un gabarit, et FORMADOC
s'occupe de la mise en forme — sans IA, sans crédit, sans carte bancaire.

@component('mail::button', ['url' => route('documents.create')])
Mettre en forme mon premier document
@endcomponent

Tu veux en savoir plus avant de commencer ?

- [Comment ça marche]({{ route('landing') }}#parcours)
- [Les tarifs]({{ route('landing') }}#tarifs)
- [La FAQ]({{ route('landing') }}#faq)

L'équipe FORMADOC
@endcomponent
```

#### 6.2.2 Réinitialisation de mot de passe

```
@component('mail::message')
# Réinitialisation de ton mot de passe

Tu as demandé la réinitialisation de ton mot de passe FORMADOC.
Ce lien est valable **60 minutes** et ne peut être utilisé qu'une fois.

@component('mail::button', ['url' => $resetUrl])
Réinitialiser mon mot de passe
@endcomponent

Si tu n'es pas à l'origine de cette demande, ignore simplement cet email —
ton mot de passe actuel reste inchangé.

L'équipe FORMADOC
@endcomponent
```

#### 6.2.3 Document prêt

```
@component('mail::message')
# Ton document est prêt {{ $user->name }} !

**{{ $document->filename }}** a été mis en forme avec le gabarit
**{{ $templateName }}**. Tu peux le prévisualiser et le télécharger dès maintenant.

@component('mail::button', ['url' => route('documents.export', $document)])
Télécharger mon document
@endcomponent

Tes fichiers sont conservés **30 jours** puis supprimés automatiquement.

L'équipe FORMADOC
@endcomponent
```

#### 6.2.4 Quota dépassé (proposition d'évolution)

```
@component('mail::message')
# Ton quota mensuel est atteint

Tu as utilisé **{{ $used }}/{{ $quota }} documents** sur ton plan
{{ $planName }} ce mois-ci. Tes prochains documents seront disponibles le
1er du mois, ou dès maintenant si tu passes à un plan supérieur.

@component('mail::button', ['url' => route('account.index')])
Voir les plans
@endcomponent

Rien n'est perdu : le mode déterministe reste disponible selon ton quota,
et tu peux aussi acheter des crédits pour les traitements IA.

L'équipe FORMADOC
@endcomponent
```

#### 6.2.5 Paiement en attente / échec

```
@component('mail::message')
# Ton paiement n'a pas abouti

Nous n'avons pas confirmé le paiement de **{{ $amount }} FCFA**
(référence {{ $reference }}). Aucun crédit n'a été débité et aucun abonnement
n'a été activé.

@component('mail::button', ['url' => route('account.index')])
Réessayer le paiement
@endcomponent

En cas de difficulté, réponds simplement à cet email : nous t'aiderons
volontiers.

L'équipe FORMADOC
@endcomponent
```

#### 6.2.6 Rappel de renouvellement (J-3)

```
@component('mail::message')
# Ton abonnement se renouvelle le {{ $renewDate }}

Ton abonnement **{{ $planName }}** ({{ $price }}/mois) sera renouvelé
automatiquement le {{ $renewDate }}. Si tu souhaites l'annuler, fais-le
avant cette date depuis ton compte — il restera actif jusqu'à la fin de la
période payée.

@component('mail::button', ['url' => route('account.index')])
Gérer mon abonnement
@endcomponent

L'équipe FORMADOC
@endcomponent
```

### 6.3 FAQ complémentaire (landing)

```blade
<details>
    <summary>Mes documents sont-ils transmis à l'IA ?</summary>
    <div class="faq-body">Non, pas par défaut. Le mode déterministe traite vos documents localement, sans envoi à un service externe. L'assistance IA ne s'active que si vous cochez l'option, et son coût est affiché avant chaque action.</div>
</details>
<details>
    <summary>Comment payer avec Orange Money ou MTN MoMo ?</summary>
    <div class="faq-body">Au moment du paiement, choisissez « Orange Money » ou « MTN MoMo » : vous êtes redirigé vers la passerelle sécurisée (KPay) pour confirmer le règlement depuis votre téléphone. Vous recevez ensuite un reçu par email.</div>
</details>
<details>
    <summary>Que deviennent mes fichiers après le traitement ?</summary>
    <div class="faq-body">Vos fichiers sont supprimés automatiquement 30 jours après le traitement, pour votre confidentialité. Vous pouvez les télécharger à tout moment avant cette échéance.</div>
</details>
<details>
    <summary>Comment sont facturés les traitements IA ?</summary>
    <div class="faq-body">1 crédit = 1 FCFA. Chaque action IA affiche son coût estimé avant exécution ; seul le coût réel est débité après usage. Vous pouvez acheter des crédits à partir de 500 FCFA, sans abonnement.</div>
</details>
<details>
    <summary>Le plan Gratuit est-il vraiment gratuit ?</summary>
    <div class="faq-body">Oui. Cinq documents déterministes par mois, sans carte bancaire, sans limite de temps. Les traitements IA et les fonctionnalités avancées sont payants, mais jamais imposés.</div>
</details>
```

---

## 7. Checklist finale avant mise en production

- [x] **P0-1** — Corriger « Deux documents » → « Cinq documents » dans `auth/register.blade.php`.
- [x] **P0-2** — Aligner la landing (FAQ, étapes, badge hero) et l'onboarding sur les formats réellement acceptés (DOCX/DOC/TXT) **ou** implémenter la conversion PDF.
- [x] **P0-3** — Corriger « Orange CI » / « MTN CI » → Cameroun (CM) dans le checkout.
- [x] **P0-4** — Créer les 3 pages légales (modèles §6.1) + routes `pages.legal`, `pages.terms`, `pages.privacy` + liens footer.
- [x] **P0-5** — Remplacer `support@formadoc.example` (landing) par l'email réel ; configurer `app.project_owner_email` (feedback).
- [ ] **P1** — Normaliser le ton (tutoiement app/étudiants, vouvoiement entreprises) sur toutes les vues et emails.
- [ ] **P1** — « immédiatement » → « sous quelques minutes » (checkout).
- [ ] **P1** — Retirer les références à la « page de statut » (500/503) ou créer la page.
- [ ] **P1** — Ajouter le flux « Mot de passe oublié » + lien sur le login.
- [ ] **P1** — Rendre dynamique le « Retour estimé » (maintenance) et remplacer « LibreOffice » (aperçu) par un texte non technique.
- [ ] **P1** — Vérifier l'implémentation de « Recherche web » (chat) et « API & intégrations » (Pro) ; sinon reformuler.
- [ ] **P1** — Vérifier que « mois 2026-08 » (account) est dynamique.
- [ ] **P1** — Améliorer la description du plan Premium (« mises en forme généreuses » → description concrète).
- [ ] **P1** — Ajouter les emails de bienvenue, document prêt, échec paiement, renouvellement (modèles §6.2).
- [ ] **P2** — Intégrer les 3 variantes de proposition de valeur (§4) et lancer un test A/B.
- [ ] **P2** — Ajouter la FAQ complémentaire (§6.3).
- [ ] **P2** — Corrections de ton mineures (« À bientôt ! », « Épuisé — plan supérieur ou crédits », « gabarit institutionnel »).
- [ ] **P2** — Ajouter l'action « Contacter le support » sur la page 403.
- [ ] **Qualité** — Relire tous les textes en français (orthographe/grammaire), rejouer les 288 tests, vérifier le rendu des emails (Mailpit/maileclipse).

---

## 8. Prochaines étapes recommandées

1. **Application immédiate (P0)** : corriger les 5 incohérences bloquantes, créer les 3 pages légales, remplacer les emails factices. *Ce lot peut être livré en une journée.*
2. **Lot P1 (avant bêta privée)** : ton unifié, flux mot de passe oublié, emails transactionnels, vérification des promesses (recherche web, API), corrections des états/erreurs.
3. **Tests utilisateurs** : faire réaliser le parcours complet (inscription → upload → téléchargement) à 5-8 étudiants (DQP/BTS/licence/master) et recueillir leur compréhension de l'offre (« combien de documents gratuits ? », « l'IA est-elle obligatoire ? »).
4. **A/B testing landing** : variantes A/B/C (§4) avec mesure du taux de clic « Commencer gratuitement » et du taux d'inscription.
5. **Bêta privée** : collecter le feedback in-app existant, surveiller les chutes d'étape (upload → validation → export) via des stats d'usage.
6. **Amélioration continue (P2)** : maintenance de la FAQ, itérations sur les emails (relance inactifs J+7, retour utilisateur), préparation i18n (Phase 11) en gardant le français comme langue source.
