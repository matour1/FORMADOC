# PROJECTION_D_IMPLEMENTATION.md

> **Date** : 2026-10-18
> **Objet** : état des lieux vérifié + objectifs d'implémentation issus de
> `Formadoc instructions d'implémentation.md` (v1, mode déterministe) et de
> `API_Documentation.md` (GeniusPay).
> **Méthode** : chaque ligne « fait » ou « manquant » vient d'une lecture du code,
> pas d'un plan. Les affirmations non vérifiables ici sont marquées comme telles.

---

## 0. Avertissement sur deux écarts structurants

Avant tout plan, deux points doivent être posés clairement, car ils changent la
nature du travail.

### 0.1 Le dépôt n'est pas dans l'état décrit par ce document

Les fichiers de suivi ont été **supprimés de l'arbre de travail** (non commité) :
`ETAT_PROJET_ET_ANGLES_MORTS.md`, `PLAN_CORRECTION.md`, `PLAN_DEVELOPPEMENT.md`,
`PLAN_REFONTE.md`. Ils restent dans l'historique Git mais ne sont plus lisibles
en local. Toute reprise s'appuiera donc sur ce document, ou exige un
`git checkout --` préalable.

### 0.2 Le projet actuel N'EST PAS déterministe

Les instructions v1 demandent « aucun appel à une IA dans cette version ». Or le
code livré contient un pipeline IA complet : `DeepSeekAnalyzer`,
`AiCorrectionService`, `OpenRouterService`, classification assistée, chat
d'édition, facturation de crédits, et **trois modes d'analyse** exposés à
l'utilisateur (`regex`, `assiste`, `precision`).

Ce n'est pas un détail : les instructions décrivent une **branche produit
différente** (traitement XML pur), là où le code implémente une **plateforme
assistée par IA**. Le plan ci-dessous traite le mode `regex` comme le « mode
déterministe v1 » des instructions : c'est le seul mode qui y correspond
aujourd'hui.

---

## 1. Ce qui est fait (vérifié dans le code)

### 1.1 Socle OOXML natif

| Exigence des instructions | Code | État |
| --- | --- | --- |
| Lecture par `ZipArchive` + XML, sans PHPWord en entrée | `PackageReader`, `XmlLoader`, `DocxNativeAdapter` | ✅ |
| Parties lues par nom (`getFromName`), jamais `extractTo` | `PackageReader:112,141` | ✅ |
| Alias de namespaces Word | `XmlLoader` | ✅ à confirmer par test |
| Champs coupés sur plusieurs runs reconstruits | `FieldReader` (SEQ/TOC/PAGEREF/STYLEREF) | ✅ |
| `SEQ <Type>` reconnu comme catégorie | `FieldReader::sequenceCategory()` | ✅ |
| Styles de sommaire (`toc N`) reconnus | `StyleReader::isListStyle()` | ✅ |
| Sommaire tapé reclassé par contenu | filtre de contenu + `BlockType::TocEntry` | ✅ |
| Diagrammes en formes préservés | `ShapePreserver` (recopie à l'octet) | ✅ |
| Numérotation + renvois internes | `Numbering/` (6 classes) | ✅ |
| Génération TOC + listes | `Lists/TableOfContentsGenerator`, `RenderCoordinator` | ✅ |
| Deux sections, romain puis arabe | `DocumentReconstructor`, `ReconstructionPayloadBuilder` | ✅ |
| En-têtes/pieds Word réels + champ PAGE | `HeaderFooterInjector` | ✅ |
| Feuille de route de bascule | `DocumentPipeline`, flag `pipeline.v2` | ✅ |
| Suite de tests | 1 620 passés / 3 ignorés (mesuré le 2026-10-11) | ✅ |

**Bilan** : le cœur de l'architecture de la section 3 des instructions est
présent. Ce qui manque se situe à l'entrée (analyseur en lecture seule) et sur
quelques capacités précises listées ci-dessous.

### 1.2 Paiements

| Exigence | Code | État |
| --- | --- | --- |
| KPay (carte, mobile money) | `KPayService`, `KPayController` | ✅ |
| Monetbil (widget signé, un service par palier) | `MonetbilService`, `MonetbilServiceRegistry` | ✅ |
| Acquisition de crédits par paliers fixes | `CreditPackCatalog` | ✅ |
| Idempotence des règlements | `CreditService::creditIfNotProcessed` | ✅ |
| Activation/masquage d'un moyen sans redéploiement | `payments.gateways.*` + `/admin/settings` | ✅ |
| Signature de webhook vérifiée | KPay HMAC, Monetbil MD5 trié | ✅ |

---

## 2. Ce qui reste à faire

Classé par bloc, avec pour chacun **la source** (instructions §, ou analyse
paiement) et **le critère de sortie**.

### 2.1 Analyseur en lecture seule — Phase 1 des instructions (BLOQUANT)

**Source** : instructions §4.
**Constat** : `php artisan list` ne contient **aucune** commande `formadoc:analyse`.

Il n'existe aujourd'hui **aucun outil de diagnostic** capable de dire, sur un
document donné, ce que le pipeline a vu. Toutes les mesures des instructions
(plus longue série de paragraphes vides, champs `SEQ` par type, titres en zones
de texte, signets `_Toc`) sont inaccessibles sans écrire du code ad hoc.

| À livrer | Détail |
| --- | --- |
| `php artisan formadoc:analyse fichier.docx --json` | N'écrit **rien** dans le document |
| Statistiques | paragraphes, vides + plus longue série, sauts de page, sections, ancres / images en ligne / zones de texte, signets `_Toc` |
| Styles | utilisés / définis jamais utilisés |
| Champs | instruction complète + position |
| Sommaires existants | en champ **et** tapés |
| Zones de texte | contenu, style, ancre |
| Candidats | légendes avec niveau de preuve, titres avec confiance, page de garde |
| En-têtes/pieds | texte, champ PAGE, `pgNumType`, `titlePg` |

**Critère de sortie** : produit le rapport JSON sur les 4 fixtures, et les
repères mesurés des instructions (§4, tableau) sont retrouvés ou tout écart est
expliqué.

**Pourquoi c'est bloquant** : sans cet instrument, « mesurer le rappel et la
précision à chaque changement de règle » (instructions §11) est impossible, et
toute correction de détection serait faite à l'aveugle. C'est exactement le
piège documenté dans `.ai/rules/general.md` (« vérifier la base de mesure »).

### 2.2 Zones de texte (`w:txbxContent`) — Phase 1 et 2

**Source** : instructions §3, §4, §5.4.
**Constat** : aucune occurrence de `txbxContent` dans `app/Document/`.

Le corpus de référence porte **19 titres de niveau 1 dans des zones de texte**
(DQP final) et **26 zones de texte** (DQP brut). Aujourd'hui, leur contenu n'est
lu par aucun chemin : la mesure des instructions (69 % de rappel) inclut déjà ces
titres comme perdus.

| À livrer | Détail |
| --- | --- |
| Lecture explicite | contenu, style des paragraphes, ancre |
| Promotion | zone de 1-2 paragraphes courts correspondant aux règles de titre → **vrai paragraphe** à la place de l'ancre |
| Garde-fou | zone contenant un schéma (plusieurs formes) ou une image → laissée intacte + signalée |

**Critère de sortie** : un test synthétique par cas (titre promu, schéma préservé,
zone à 3 paragraphes non promue).

### 2.3 `mc:AlternateContent` / `mc:Fallback` — Phase 1

**Source** : instructions §3.
**Constat** : `ShapePreserver` recopie les formes, mais aucune lecture ne filtre
`mc:Fallback`.

Le risque exact est décrit par les instructions : le fallback VML est une **copie**
de la version moderne. Le lire produit chaque élément **en double** — c'est
précisément l'un des deux défauts que le critère de réussite de la phase 1
interdit (« sans doublons dus à `mc:Fallback` »). `ShapePreserver` étant un
post-traitement de réinjection, il ne corrige pas ce double comptage en lecture.

**Critère de sortie** : test sur un `.docx` synthétique contenant
`mc:AlternateContent`, vérifiant que le contenu n'est compté qu'une fois.

### 2.4 Page de garde — décision contradictoire

**Source** : instructions §2 et §5.2.
**Constat** : le module a été **volontairement retiré** du produit, et un test
d'invariant interdit son retour (`CoverModuleRemovalInvariantTest`).

Les instructions demandent l'inverse : « si le document en contient une, la
**détecter, la supprimer** et le dire à l'utilisateur ».
Ce n'est pas la même chose que générer une page de garde — c'est **retirer** la
page de garde de l'étudiant avant traitement. Aucun code ne le fait aujourd'hui.

**Décision requise** : confirmer que « détecter et supprimer » est bien voulu.
C'est irréversible pour l'utilisateur sur son document (l'original reste intact,
mais le rendu perd une page). Garde-fou des instructions : au-delà de 2 pages,
ne rien supprimer et avertir.

### 2.5 Nettoyage des anciens sommaires — partiellement couvert

**Source** : instructions §5.3.

| Élément | État |
| --- | --- |
| Champ `TOC` supprimé avant détection | à vérifier (non constaté dans la lecture) |
| Blocs `w:sdt` de type TOC | à vérifier |
| Styles `TOC1-9`, `TM1-9`, `TableofFigures` | ✅ reconnus |
| Listes tapées (≥ 3 lignes avec points de suite) | ✅ filtre de contenu |
| Suppression du titre de ces blocs | ⚠️ à vérifier |

### 2.6 Paragraphes vides séparateurs — Phase 2

**Source** : instructions §5.5.
**Constat** : aucune normalisation de séries de paragraphes vides dans
`app/Document/`.

Attendu : série ≥ 3 → supprimées + `pageBreakBefore` sur le titre suivant ;
sinon réduites à 1. Exclusions obligatoires : ancre, image, champ, saut de
section, dernière cellule d'un tableau.

### 2.7 Rapport utilisateur structuré — Phase 5

**Source** : instructions §9.
**Constat** : `QualityReport` existe pour les listes, mais il n'existe pas de
**rapport unique** JSON couvrant : page de garde supprimée, légendes non
reconnues, mots inconnus, numéros dupliqués/manquants, titres à valider, zones
de texte intactes, ordre inhabituel.

La règle « jamais de correction silencieuse » n'est donc pas encore honorée de
bout en bout.

### 2.8 LibreOffice (numéros de page réels) — Phase 5, expérimental

**Source** : instructions §8.
**Constat** : `Lists/pagination_uno.py` existe. Les instructions demandent de
**mesurer** l'option A (réenregistrement) contre l'option B (PDF + relecture des
pages) avant de trancher.

Point d'attention déjà présent dans le code : `TableOfContentsGenerator` refuse
d'inventer un numéro de page et le signale — c'est cohérent avec les
instructions. La décision reste ouverte (§13.6).

### 2.9 Garde-fous d'entrée

**Source** : instructions §10.

| Garde-fou | État |
| --- | --- |
| ≤ 50 Mo compressé | ✅ `StoreDocumentRequest` (`max:51200`) |
| Extension + MIME | ✅ `docx,doc,txt` |
| Refus `.docm` | ✅ (non listé) |
| `LIBXML_NONET`, pas de DTD | ⚠️ à vérifier dans `XmlLoader` |
| Bombe de décompression (taille totale, nb d'entrées) | ❌ **absent** |
| Suppression auto après délai (données personnelles) | ✅ `files:purge-temp` |
| Estimation du temps affichée | ⚠️ à vérifier côté UI |

**Point 1** : la bombe zip est un vrai manque. 50 Mo compressés peuvent produire
plusieurs gigaoctets ; `PackageReader` compte les entrées (`numFiles`) mais ne
refuse rien sur ce critère.

### 2.10 `docs/DECISIONS.md` et `HeadingClassifier`

**Source** : instructions §0 et §3.

| Élément | État |
| --- | --- |
| `docs/DECISIONS.md` | ❌ inexistant |
| Interface `HeadingClassifier` | ❌ inexistante (`BlockClassifier` est une classe concrète) |

L'interface est explicitement demandée comme point d'extension, **sans
implémentation**. Aujourd'hui, brancher un classifieur alternatif exigerait de
modifier `BlockClassifier` lui-même.

### 2.11 Paiements — GeniusPay

**Source** : `API_Documentation.md`.

**Constat** : aucune trace de GeniusPay dans le code ni la configuration.
Seuls KPay et Monetbil existent (`PaymentGatewayRegistry::KPAY`, `::MONETBIL`,
`::HORS_LIGNE`).

| À livrer | Détail |
| --- | --- |
| `GeniusPayService` | `POST /payments` (checkout hébergé), `GET /payments/{ref}`, `POST /payments/{ref}/refund` |
| Client HTTP | en-têtes `X-API-Key` / `X-API-Secret`, mode **checkout** (sans `payment_method`) |
| Webhook | `POST` dédié + vérification `X-Webhook-Signature` = HMAC-SHA256 de `timestamp.payload` |
| Idempotence | `Idempotency-Key` **ou** `external_reference` ; s'appuyer dessus plutôt que sur un dédoublonnage local |
| Nouveau moyen | constante + entrée dans `PaymentGatewayRegistry::tous()` |
| Config | `config/geniuspay.php` + variables `.env` |
| Admin | état actif/visible via les réglages existants (pas de nouvelle table) |

**Point d'architecture à ne pas rater** : le webhook GeniusPay signe le **corps
brut**. Il faudra lire `php://input` avant toute désérialisation, sinon la
signature ne correspondra jamais — l'erreur classique.

**Prérequis** : un secret de webhook (`whsec_…`) par endpoint, affiché une seule
fois. Décider où le stocker (env, comme les autres secrets) et comment le
rotater.

---

## 3. Ordre d'exécution proposé

```
BLOQUANT (rien d'autre ne se mesure sans lui)
  1. formadoc:analyse + fixtures            ← §4 des instructions
  2. Garde-fous zip (bombe de décompression) ← §10, 30 min de travail
  3. mc:Fallback en lecture                  ← condition du critère 69 %/92 %

DÉTECTION ET NETTOYAGE
  4. Zones de texte (lecture + promotion)    ← +19 titres du DQP final
  5. Paragraphes vides séparateurs
  6. Nettoyage complet des anciens sommaires (champs TOC, w:sdt)
  7. Page de garde : DÉCISION requise avant tout code

RAPPORT ET EXTENSIBILITÉ
  8. Rapport utilisateur unique (JSON)
  9. Interface HeadingClassifier + docs/DECISIONS.md

PAIEMENTS (parallélisable)
 10. GeniusPay : service + config + webhook + moyen au registre

EXPÉRIMENTAL
 11. LibreOffice : mesurer A vs B, puis trancher (§13.6)
```

### Justification de l'ordre

- **1 avant 2-9** : sans instrument, les corrections sont invérifiables. C'est la
  leçon déjà consignée dans le projet (un audit mal instrumenté a produit un
  « 100 % de faux positifs » faux).
- **2 avant tout traitement de fichiers utilisateurs** : une bombe zip ne
  consomme pas un document, elle **arrête le serveur**. C'est un correctif de
  sécurité, pas de qualité.
- **3 avant 4** : promouvoir des titres de zones de texte alors que le fallback
  les compte en double doublerait le défaut au lieu de le corriger.
- **7 isolé** : la suppression d'une page est irréversible côté rendu. Elle
  attend une confirmation explicite, conformément à la règle « ne devine pas ».

---

## 4. Points à confirmer (hérités des instructions §13)

| # | Question | Défaut proposé |
| --- | --- | --- |
| 1 | Ordre des listes (figures avant tableaux ?) | ordre du fichier de référence, configurable |
| 2 | Partie/Chapitre = niveau 1, Section = 2 | à valider |
| 3 | Réordonner les pages liminaires ? | non, signaler |
| 4 | Titre et nom de l'en-tête saisis par l'utilisateur ? | oui, paramètres du formulaire |
| 5 | Renvois « voir Figure 3 » mis à jour ? | signaler seulement |
| 6 | Stratégie des numéros de page (A ou B) | décision après mesure |

**Question ajoutée par cet état des lieux** :

| # | Question | Pourquoi elle est bloquante |
| --- | --- | --- |
| 7 | Le produit reste-t-il **IA optionnelle** ( état actuel) ou devient-il **100 % déterministe** (instructions v1) ? | Change le périmètre : retirer les modes `assiste`/`precision` supprimerait une fonctionnalité facturée |
| 8 | La **suppression de la page de garde** est-elle voulue ? | Contredit une décision produit antérieure, verrouillée par un test |
| 9 | GeniusPay **remplace**-t-il un moyen existant ou s'**ajoute**-t-il ? | Détermine s'il faut migrer des paiements en cours |

---

## 5. Résumé en une phrase

Le socle OOXML et les paiements existants sont solides ; ce qui manque n'est pas
une réécriture mais **l'instrument de mesure**, **trois capacités d'entrée**
(zones de texte, `mc:Fallback`, garde-fou zip), **un rapport utilisateur unique**,
et **GeniusPay** — le tout précédé de deux décisions produit qui ne peuvent pas
être prises à la place du responsable.
