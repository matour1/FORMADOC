# État du projet, limitations identifiées et plan de vérification

> Document de travail destiné à l'agent IA de développement (GitHub Copilot). Objectif : consolider l'état réel du système, les angles morts détectés lors des échanges de conception, et les vérifications à effectuer avant d'étendre le scope.
>
> **Révision du 2026-09-20** — audit complet du code mené contre la version précédente de ce document. Les sections 1.2, 2.2, 2.4, 2.5, 3, 4 et 5 ont été réécrites ; une section 6 a été ajoutée. Plusieurs affirmations du document initial étaient **contredites par le code**, dans les deux sens : des fonctions annoncées « à confirmer » sont implémentées et testées ; une fonction annoncée comme « à construire » a en réalité été **retirée** du produit ; des défauts non documentés se sont révélés plus graves que ceux décrits. Chaque affirmation ci-dessous porte désormais sa preuve (fichier, classe, test).
>
> **Révision du 2026-09-29** — contre-mesure indépendante sur le code **et** sur le corpus réellement présent. **Trois affirmations de la révision précédente se sont révélées fausses**, dont une qui structurait tout le document (§1.2bis « les fonctions ne sont pas livrées »). Mesures à l'appui :
>
> | Affirmation du 2026-09-20 | Réalité mesurée le 2026-09-29 |
> |---|---|
> | §1.2bis : « aucune variable `DOCUMENT_PIPELINE_V2` n'existe dans `.env` » → 3 fonctions non livrées | **FAUX.** `DOCUMENT_PIPELINE_V2=auto` (`.env:113`) et `=false` (`.env.example:192`). Le pipeline natif **tourne** : 693 blocs produits en base sur un document réel. Les fonctions 2/3/4 **sont atteignables** ; ce qui reste à évaluer est leur *qualité*, pas leur *accès*. |
> | §2.2 : « le corpus a déjà été mesuré, sur **526 documents** » | **FAUX.** Le corpus compte **2 241 fichiers**, soit **1 347 contenus distincts** — 33 % de duplication (un même fichier y figure jusqu'à 9 fois). Toute fréquence calculée sur le nombre de *fichiers* est donc surestimée. |
> | §2.2 : « `is_list_style` calculé mais jamais consommé » | **VRAI, et corrigé** le 2026-09-29 (branche `feature/sommaire-detection`). Voir §2.2 pour la mesure avant/après. |
>
> **Deux précisions que la révision précédente ignorait :**
>
> 1. **La renumérotation ne s'exécute pas à l'analyse, mais à l'EXPORT** (`FormattedDocumentExporter` → `ReExportCoordinator`). Les `finalNumber` vides dans les structures stockées sont donc **normaux** et ne prouvent aucune panne.
> 2. **Le corpus est dupliqué.** Mesurer « sur 2 241 fichiers » revient à compter des copies. Les fréquences ci-dessous portent sur les **contenus distincts**, sauf mention contraire — c'est la seule base honnête, et l'absence de cette précaution explique très probablement l'écart entre le « 7/7 documents » d'origine et les 0,22 % mesurés depuis.

---

## 1. État actuel — ce qui est fonctionnel et testé

### 1.1 Génération automatique (moteur de base)

Confirmé fonctionnel avec preuve empirique :

- Détection de la hiérarchie des titres par regex + LLM (testée : 19/19 sur texte piège)
- Application de gabarit : police, interligne, couleur des titres
- Insertion d'en-têtes, pieds de page, numéros de page
- Insertion d'images en ligne (zones de texte flottantes évitées)
- Gestion des documents **sans** sommaire ni table des matières générés

### 1.2 Ce qui est implémenté et testé (audit du 2026-09-20)

Les 5 points listés comme « à trancher » dans la version précédente ont été vérifiés un par un dans le code.

| # | Fonctionnalité | Verdict | Preuve |
|---|---|---|---|
| 1 | Regex de légendes figures/tableaux/annexes (`Mot N: ...`) | **✅ implémenté et testé** | `app/Document/Classification/CaptionPattern.php` (mot-clés `Légende\|Figure\|Tableau\|Annexe\|Planche`, numéro arabe/lettre/romain, garde-fous anti-faux-positifs) et `app/Services/Detection/LegendDetectionService.php` (pipeline historique). Tests : `CaptionPatternTest`, `LegendDetectionServiceTest`, `DocxNativeAdapterTest::test_une_legende_de_figure_est_detectee_sans_appel_ia` |
| 2 | Sommaire + pagination romaine/arabe dérivée de l'arbre de titres | **✅ implémenté et testé** | `app/Document/Lists/` (5 classes : `TableOfContentsGenerator`, `PaginationCalculator`, `RenderCoordinator`, `QualityReport`, `ListsGenerationException`) + `DocumentReconstructor` (section frontispice, bascule `Arabic`/romaine, champ TOC natif). Tests : `TableOfContentsGeneratorTest`, `RenderCoordinatorTest`, `DocumentReconstructorTest`, `HeaderFooterInjectorTest`, `FormattedDocumentExporterTest::test_le_document_produit_contient_un_sommaire_natif` |
| 3 | Renumérotation en cas de doublons/trous saisis par l'utilisateur | **✅ implémenté et testé** | `app/Document/Numbering/` (6 classes). Tests : `NumberingPassTest::test_des_figures_numérotées_1_5_2_sont_renumerotees_1_2_3`, `::test_les_doublons_sont_corriges` |
| 4 | Résolution des renvois internes (« voir Figure 3 ») après renumérotation | **✅ implémenté et testé** | `app/Document/Numbering/CrossRefRewriter.php` + `CrossReferenceDetector.php` (3 niveaux de confiance : 1,0 unique / 0,75 proximité / 0,55 ambigu). Tests : `CrossRefRewriterTest`, `NumberingCoordinatorTest` |
| 5 | Traitement de la page de couverture | **❌ fonctionnalité RETIRÉE du produit** | `CoverPageTemplateController`, `CoverGenerationService`, `CoverDetectionService`, `CoverPageRenderer`, `CoverPageTemplate`, `CoverTemplate`… supprimés. Un test d'invariant interdit leur retour : `CoverModuleRemovalInvariantTest::test_aucun_code_ne_reference_une_classe_supprimee` analyse `app/` et échoue si une des 8 classes retirées est **référencée**. `routes/web.php` documente le retrait. |

> **Correction du document initial sur le point 5.** Il était listé comme « à traiter via exemple fourni par l'utilisateur ». En réalité, le module de page de garde a été **volontairement retiré** du produit. Deux documentations sont **périmées** et doivent être corrigées : `PLAN_DEVELOPPEMENT.md:153` et `README.md:111`, qui listent encore `cover_page.generate` comme outil actif.

### 1.2 bis — ✅ Constat majeur : ces fonctions SONT actives en production *(section corrigée le 2026-09-29)*

> **Correction.** La version du 2026-09-20 affirmait ici que les fonctionnalités 2/3/4 n'étaient « pas livrées », parce qu'aucune variable `DOCUMENT_PIPELINE_V2` n'existerait dans `.env`. **C'était faux**, et cette erreur structurait tout le document : elle en faisait une liste de blocages inexistants, et justifiait le principe 6 (« une fonctionnalité n'est livrée que si elle est atteignable ») par un exemple qui n'en est pas un.

Le pipeline est bien sélectionné dans `DocumentController::writeDocument()` :

```php
$structural = $document->structure?->structuralDocument();
if ($structural !== null && app(DocumentPipeline::class)->isNativeEnabled()) {
    // → FormattedDocumentExporter : gabarit, renumérotation, listes, renvois
}
return $this->writeLegacyDocument(...);   // sinon : DocumentReconstructor seul
```

**Mesures du 2026-09-29 :**

| Vérification | Résultat |
|---|---|
| `.env` ligne 113 | `DOCUMENT_PIPELINE_V2=auto` |
| `.env.example` ligne 192 (fichier versionné) | `DOCUMENT_PIPELINE_V2=false`, documenté en commentaire |
| `config('document.pipeline.v2')` | `'auto'` |
| `isNativeEnabled()` / `isAutoMode()` | `true` / `true` |
| Documents persistés avec `structural_json` | **1 sur 54** — le pipeline natif a **réellement produit** des données |

Le pipeline natif **tourne donc**, avec repli automatique sur le legacy en cas d'échec. Les fonctionnalités 2, 3 et 4 sont **atteignables par un utilisateur réel**.

**Nuance à ne pas perdre.** « Atteignable » ne veut pas dire « correct » : ce sont deux questions distinctes, et la confusion entre les deux était le vice de la révision précédente.

- **Accès** — mesuré : acquis.
- **Qualité** — mesurée le 2026-09-29 : la détection **promouvait à tort 58 des 80 titres** du document de référence (72 %), tous issus de son sommaire. Défaut réel, corrigé sur la branche `feature/sommaire-detection` (voir §2.2).

**Une précision que la révision précédente ignorait.** La renumérotation **ne s'exécute pas à l'analyse** mais à l'**EXPORT**, via `FormattedDocumentExporter` → `ReExportCoordinator` → `NumberingCoordinator`. Chercher des `finalNumber` renseignés dans les structures stockées est donc vain : ils sont **vides par conception** justqu'à l'export. Cette méprise pourrait faire conclure à tort que la renumérotation ne fonctionne pas.

**Question désormais tranchée** : la stratégie de bascule n'est plus « faut-il basculer ? » mais « quand passer de `auto` à `true` ? ». Deux options : **laisser `auto`** (le repli protège, au prix d'une divergence silencieuse entre deux moteurs) ou **passer à `true`** après avoir constaté un taux de repli nul sur un volume suffisant. Le repli est journalisé (`Log::warning('Pipeline natif : échec, l\'ancien pipeline fait foi')`) : compter ces lignes dans les logs est la mesure qui déciderait.

### 1.3 Architecture des offres

- **Mode gratuit** : détection et mise en forme par regex seul. En cas d'ambiguïté (score de confiance bas), signalement explicite à l'utilisateur — pas de correction silencieuse fausse. L'utilisateur peut acheter des crédits IA ponctuels pour débloquer la vérification IA sans abonnement.
- **Mode avec IA (payant)** : double-vérification IA sur les cas ambigus détectés par regex.
- **Moteur unique** : un seul moteur de traitement pour la mise en forme automatique et l'assistant IA conversationnel. L'IA accède aux fonctionnalités du moteur via un système d'outils (tool use), pas via un pipeline dupliqué.

**Vérification de ce principe dans le code** : il est respecté et **verrouillé par des tests d'architecture**. L'assistant expose **16 outils** au total (`ChatToolsServiceTest::test_available_tools_liste_les_outils` affirme le compte) : 10 outils historiques (`ChatToolsService::schemas()`) **plus** 6 outils d'édition/annulation dérivés de `EditToolSchemas::all()` (`rewrite_paragraph`, `insert_block`, `modify_table`, `delete_block`, `regenerate_section`, `undo_last_action`), qui passent par `ToolWhitelist` → `EditOrchestrator` — c'est-à-dire **le même moteur** que le pipeline de mise en forme, pas un second système. `ChatToolsService::execute()` route d'abord vers `executeStructuralEdit()` avant le `match` des outils historiques.

> **Nuance importante sur les seuils de confiance.** Le document initial demandait de « définir précisément » le seuil déclenchant l'action. Il **est** défini : `config/document.php` → `'confidence_threshold' => 0.85` (surchargeable par `DOCUMENT_CONFIDENCE_THRESHOLD`), consommé par `ClassificationPolicy::canApplyAutomatically()` / `requiresClarification()`, et `Block::AUTO_ACCEPT_THRESHOLD`. En dessous du seuil, `ClarificationService` pose une question **ciblée sur ce bloc précis** (jamais une action globale). Le principe « pas de règle binaire isolée » est donc implémenté.

---

## 2. Angles morts et limitations détectés en conversation

### 2.1 Détection de titres — faux positifs sur paragraphes mal définis par l'utilisateur

**Problème observé** : si un utilisateur a manuellement (et incorrectement) appliqué un style « titre » à un paragraphe, le regex le détecte systématiquement comme titre, sans remise en question.

**Solution retenue — implémentée** : architecture à deux passes.
1. Le regex effectue une première détection rapide (gratuite).
2. L'IA reçoit uniquement les **candidats-titres** (liste courte + contexte immédiat), pas l'intégralité du document.

**Preuve dans le code** : `app/Document/Classification/` — `SignalAggregator` (poids par signal, seuil 0,85), `DetectBlocksTool` (appel IA borné aux blocs sous le seuil), `ClassificationPolicy`, `ClarificationService`.

**Défaut réel trouvé et corrigé (mesuré)** : `SignalAggregator` comptait **deux fois le même signal** quand `Block::$headingLevel` provenait de la seule numérotation du texte (cas où aucun style Word n'existe). `assessHeadingWithBothSignals()` voyait alors « style ET numérotation concordent » et calculait 0,98 — alors qu'un seul signal existait en réalité. **Mesure sur 51 documents réels : 775 titres** estimés à 0,80 par l'adaptateur (numérotation seule, fiable) étaient recalculés à 0,98, donc **acceptés sans aucune vérification IA**, supprimant le bénéfice de la double-vérification. Correctif : `SignalAggregator(preserveExistingHeadingConfidence: true)` dans `AppServiceProvider` (test : `BlockClassifierWiringTest::test_un_titre_par_numerotation_seule_garde_la_confiance_de_l_adaptateur`).

**Reste à chiffrer** : le coût réel en tokens de la double-vérification IA (nombre de blocs sous 0,85 par document moyen). Les données de mesure existent (12 % de blocs ambigus selon `SignalAggregator`), mais pas la conversion en FCFA/document.

### 2.2 Détection erronée du sommaire / table des matières déjà générés

**Problème observé** : quand un document contient déjà un sommaire ou une table des matières, la détection les rescanne comme des titres et conserve à tort leurs numéros de page.

**État réel dans le code — traitement partiel, avec deux lacunes identifiées**

Ce qui **existe** :

| Mécanisme | Fichier | Couvre |
|---|---|---|
| Styles Word de sommaire (`toc 1`, `toc 2`, `toc 3`, `table of figures`…) | `DocxOoxml/StyleReader.php` → `isListStyle()` + constante `LIST_NAMES` | Sommaire généré par Word (**détection**) |
| Champs `TOC` / `PAGEREF` / `STYLEREF` | `DocxOoxml/FieldReader.php` | Sommaire généré par Word (**signal fort**) |
| Bordures de blocs « LISTE DES FIGURES/TABLEAUX » | `LegendDetectionService::findTocBoundaries()` | Listes de figures/tableaux du frontispice |
| Entrées de liste avec points de suite | `CaptionPattern::isListEntry()` (motif `\.{3,}\s*\d+$`) | Renfort : empêche de reclasser une entrée de liste en légende |
| Explication du libellé | `LegendDetectionService::cleanLabel()` | Retire `<TAB>17` et `......17` des libellés |
| Exclusion des intitulés de listes du sommaire généré | `TableOfContentsGenerator` (constante `SOMMAIRE`) | Empêche « SOMMAIRE … 3 » dans son propre sommaire |

Lacune 1 — **`is_list_style` est calculé mais jamais consommé.** *(corrigé le 2026-09-29)*

`ParagraphReader` produit `'is_list_style' => $this->styles->isListStyle($styleId)`, mais le champ n'apparaissait **nulle part ailleurs** dans `app/`. Le signal de style de sommaire était donc collecté puis jeté — une entrée `toc 1` n'était écartée que si elle portait **aussi** des points de suite (`isListEntry`), ce qui n'est pas garanti.

**Correctif (branche `feature/sommaire-detection`).** L'information est désormais consommée :
- `DocxNativeAdapter::toBlock()` lit le niveau du style AVANT les règles de titre — ordre indispensable, un style `toc 2` portant un `outlineLevel`, la règle de titre le capturerait sinon en premier ;
- un type de bloc dédié `BlockType::TocEntry` distingue une entrée de sommaire d'un titre ;
- `LIST_NAMES` a été **élargie et complétée par des motifs** (`toc N`, `sommaire N`, sans plafond de niveau).

**Le relevé des styles réellement employés a révélé pourquoi la liste figée était un défaut de conception :**

| Style | Occurrences | État dans la liste d'origine |
|---|---|---|
| **`toc 4`** | **52** | **ABSENT** |
| `toc 1` / `toc 2` / `toc 3` | 50 / 42 / 41 | présents |
| `table of figures` | 26 | présent |
| `list paragraph` | 16 | présent |

**Le style de sommaire le PLUS fréquent du corpus était celui qui n'était pas reconnu.** Une liste figée est donc toujours incomplète : les noms de style ne sont pas normalisés (Word les traduit selon la langue de l'interface), et un outil tiers peut les nommer autrement. On reconnaît désormais la **forme** du nom.

Lacune 2 — **le sommaire lui-même n'est pas écarté de la détection de titres.** `findTocBoundaries()` ne reconnaît que les titres `LISTE DES (FIGURES|TABLEAUX|...)`. Un `SOMMAIRE` n'ouvre **pas** de plage à ignorer : les lignes de son contenu restaient candidates. **Corrigé également**, par un filtre de contenu (voir ci-dessous).

---

**⚠️ La solution proposée par la version précédente ne fonctionnait pas — mesure à l'appui**

La version du 2026-09-20 retenait les **points de suite** comme signal principal de reconnaissance d'un sommaire. Mesure sur le document de référence du projet :

| Signal | Détection |
|---|---|
| Points de suite | **0 sur 49 entrées** |
| Numéro de page final | **49 sur 49** |

**Le signal proposé ne détectait rien sur le document même cité en référence.** Ce sommaire est **tapé à la main**, sans points de suite. Sur l'ensemble du corpus, seuls **2 %** des contenus portent des points de suite littéraux.

**Ce qui a réellement fonctionné** — un signal structurel, sans recours à la position :

1. la ligne **finit par un numéro de page** — **arabe ou romain**, car le frontispice d'un mémoire est paginé en romains (`iii`, `viii`, `ix`) et le corps en arabes ;
2. elle appartient à une **fenêtre dense** — au moins 4 lignes candidates dans un intervalle de 12 blocs. La consécutivité **stricte** a d'abord été essayée et n'a reclassé que **24 lignes sur 48** : les entrées d'un sommaire réel sont **entrelacées**, une sur deux n'ayant pas de numéro de page ;
3. un **intitulé de section** (`REMERCIEMENTS`, `RESUME`, `ABSTRACT`…) suivi d'un numéro de page est reclassé sans condition de densité : un titre n'affiche jamais sa propre page.

La **position dans le document n'intervient pas** — le « sommaire en première page » du document initial est une heuristique fragile que le principe 1 écarte.

**Mesure avant/après sur le document de référence :**

| | Titres détectés | Dont entrées de sommaire | Faux titres |
|---|---|---|---|
| Avant | 80 | 58 | **72,5 %** |
| Après | **22** | **0** | **0 %** |

Les 22 titres restants sont tous légitimes (chapitres, sections, conclusion, annexes), et les numéros de page ont été retirés du texte des entrées reclassées.

**Ce que le document initial appelait « 7/7 documents »** — le corpus est **dupliqué à 33 %** (2 241 fichiers pour 1 347 contenus distincts, un même fichier y figure jusqu'à 9 fois). Sept **copies** d'un même document ont très probablement été prises pour sept documents distincts. Toute fréquence calculée sur des *fichiers* doit donc être refaite sur les *contenus distincts*.

**Preuve du correctif** : `tests/Unit/Document/Classification/TocEntryDetectionTest.php` (7 tests), dont le contrôle négatif central — *un style de titre ne doit PAS être pris pour un style de liste*, faute qui transformerait tous les titres du document en entrées de sommaire.

**Cas limite — sommaire tapé à la main** : il n'est plus une dette. C'était le cas du document de référence, et il est désormais couvert par le filtre de contenu. **Reste à vérifier** : les documents dont le sommaire n'a **ni** points de suite **ni** numéros de page (rare, mais possible sur un sommaire tronqué).

**Faux positifs à tester** : un tableau de données numériques alignées peut ressembler à une TOC. La fenêtre dense et le seuil de 4 candidats réduisent le risque (il faudrait quatre lignes terminées par un nombre dans 12 blocs), mais **aucun test ne couvre encore ce cas** — à ajouter.

**Ce que la mesure du corpus dit (et qui contredit le document initial)**

Le document initial affirmait « 7/7 documents testés ». Les chiffres ci-dessous proviennent des commentaires de code de l'audit du 2026-09-20 ; **leur base (« 526 documents ») est à considérer comme un nombre de FICHIERS, donc surestimé d'environ un tiers** au regard de la duplication mesurée le 2026-09-29.

| Mesure | Valeur (base annoncée) | Source |
|---|---|---|
| Corpus mesuré | *526 fichiers* → ~350 contenus distincts estimés | `NumberingPass`, `CaptionLinker` |
| Documents portant des doublons de numéros | **98** (74 tableaux, 21 figures, 3 annexes) | `NumberingPass`, `NumberingPassTest:102` |
| Documents avec des trous de numérotation | **3** (annexe « 37 » seule) | `CrossReferenceDetectorTest:207`, `NumberingPassTest:294` |
| Légendes / porteurs | **861 légendes** pour **318 porteurs** | `CaptionLinker`, `NumberingPass` |
| Légendes sans aucun rattachement (`linked_block_id` vide) | **861 / 861** | `CaptionLinker` |
| Légendes séparées de leur porteur par 1 bloc | 24 | `CaptionLinkerTest:154` |
| Légendes à distance ≥ 3 | 366 (43 %) | `CaptionLinkerTest:167` |
| Documents XML invalides à la génération (`&`, `<`) | **19 sur 51** — Word refusait de les ouvrir | `DocumentReconstructor` |
| Titres dont la confiance était doublée à tort | **775 sur 51 documents** | `AppServiceProvider` |
| Documents sans aucun bloc de type titre | 7 sur 51 | `BlockClassifier`, `RootHeadingPromotionTest` |
| Champs Word du corpus | 1 376 (dont `SEQ Tableau` 180, `SEQ Figure` 142) | `FieldReader` |

> **Le défaut le plus grave trouvé par cet audit ne figurait pas dans ce document.** 19 fichiers sur 51 étaient générés avec un **XML invalide que Word refuse d'ouvrir**, à cause de l'échappement désactivé par défaut dans PhpWord (`Settings::$outputEscapingEnabled = false`). Les caractères en cause sont banals dans un mémoire (« Hebergement & nom de Domaine », « Prix < 1 000 »). Corrigé dans `DocumentReconstructor` (échappement activé puis restauré en `finally`), avec le test `XmlEscapingTest`. Ce défaut touchait **37 % du corpus** — très au-delà du seuil justifiant une règle d'architecture (principe 4 ci-dessous).

**Signal le plus fiable, désormais exploité** : le style Word `toc N` et le champ `TOC`. **Corrigé** — voir lacune 1 ci-dessus. C'était bien le correctif le moins coûteux et le plus robuste (signal déterministe), mais il ne couvrait **pas** le cas le plus fréquent (sommaire tapé à la main), d'où la double approche : style **et** contenu.

**Travail restant** :
1. ~~Consommer `is_list_style`~~ → **fait** (branche `feature/sommaire-detection`).
2. ~~Ouvrir une plage à ignorer pour `SOMMAIRE`~~ → **fait** par le filtre de contenu.
3. **Mesurer sur l'ensemble des 1 347 contenus distincts** le nombre d'entrées de sommaire encore classées comme titres, avant/après. La mesure n'a porté que sur le document de référence — insuffisant pour généraliser (principe 4).
4. **Ajouter le test des tableaux numériques** (faux positif possible, non couvert).

### 2.3 Gestion des marges

**Statut : problème observé mais sans solution technique définie.**

Ce qui existe : les marges du **gabarit** sont appliquées (`TemplateEngine::sectionMargins()`, `TemplateStyleResolver::margins()`, `HeaderFooterInjector::marginsFor()` avec contrôle de cohérence `header < top` et `footer < bottom`). Ce qui n'existe pas : la détection d'un document source dont les marges sont mal réglées, ou leur correction.

**Traitement retenu : dette assumée.** À ne pas coder sans mesure préalable de fréquence sur le corpus — le principe 4 s'applique (une observation ≠ une règle).

### 2.4 Éléments flottants dans en-têtes/pieds de page

Le système lit déjà les en-têtes, pieds de page et numéros de page, et peut y ajouter des éléments. Ne gère pas encore les éléments flottants dans ces zones. Amélioration prévue, non urgente.

**Précision issue de l'audit** — le pipeline historique gère déjà un cas d'élément flottant, à ne pas confondre avec celui traité ici : `DocumentParser::preferXmlText()` récupère le texte des **textboxes** (`wps:txbx` / `v:textbox`) des en-têtes/pieds que PhpWord ignore, en comparant la richesse du texte XML à celle de l'extraction PhpWord (`isMoreInformative()`, seuil : plus de la moitié des mots significatifs manquants). Le cas non couvert est donc plus étroit qu'annoncé : il s'agit des éléments flottants **positionnés** (ancrage, habillage) dans ces zones, pas de leur texte.

### 2.5 Scope de l'Assistant IA — décision close

**Constat initial** : la description de l'Assistant IA incluait des fonctionnalités génériques (recherche web sur le sujet du document, génération d'images à insérer) qui ne sont pas des différenciateurs du produit — ce sont des capacités de plateforme LLM générique, reproductibles par n'importe quel chatbot.

**Décision prise : ils sont LIVRÉS et assumés comme tels (Option B).** Recherche web et génération d'images restent fonctionnels, exposés et facturés. Ce ne sont plus une dette écartée mais des fonctionnalités du produit, au même titre que les autres outils.

| Outil | Schéma | Exécution | Modèle | Test |
|---|---|---|---|---|
| `web_search` | `ChatToolsService:178` | `webSearch()` ligne 695 (`web_search_options`, `search_context_size: high`) | `web_search` (routage `ModelRouter`) | `ChatToolsServiceTest::test_web_search_delegue_a_openrouter_avec_citations` |
| `image_generate` | `ChatToolsService:193` | `generateImage()` ligne 739 | `image_generation` (`gpt-image-*`) | `ChatToolsServiceTest::test_image_generate_stocke_le_fichier` |

**Conséquence sur la documentation** : la ligne « Recherche web et génération d'images » est **retirée de la dette assumée** (section 5). Elles apparaissent désormais dans `ChatToolsService`, dans `CapabilitiesService` (l'IA sait qu'elle peut les appeler) et dans les descriptions de plans — ce qui est cohérent avec leur statut réel.

**Ce que cela implique à surveiller** : ces deux outils consomment des crédits et dépendent de fournisseurs externes (`web_search_options` OpenRouter, `gpt-image-*`). Il faut donc :
- vérifier que le coût par appel est bien couvert par le coefficient de rentabilité (×1.84) dans `config/openrouter.php` (`pricing`) ;
- surveiller les erreurs fournisseur dans les logs (une clé OpenRouter sans accès à `gpt-image-*` produirait un échec facturé).

**En revanche, le cœur du scope est bien implémenté** :
- Dialogue autour d'un document + application de la mise en forme à la demande → `document_analyze`, `document_edit`, `document_reconstruct`
- Modification de blocs ciblés → 6 outils d'édition structurelle (`EditToolSchemas::all()`, `ToolWhitelist`, `EditOrchestrator`) : `rewrite_paragraph`, `insert_block`, `delete_block`, `modify_table`, `regenerate_section`, `undo_last_action`
- Régénération du document / du sommaire → `document_reconstruct`, `table_of_contents`, et `RenderCoordinator` (pipeline v2)
- Signalement des ambiguïtés → `ClarificationService` + `ask_user_clarification`, seuil 0,85

---

## 3. Principes d'architecture actés durant la conception

À respecter dans toute implémentation future, pour éviter de recréer les erreurs déjà corrigées :

1. **Aucune règle de détection ne doit reposer sur un signal unique fragile** (position, longueur seule, etc.) quand un signal de contenu structurel plus robuste est disponible.
2. **Aucune action destructive globale** (ex. : suppression complète de la mise en forme d'un document) ne doit se déclencher automatiquement sur la base d'une seule règle heuristique. Cibler la correction, réserver le reset complet à un mode explicite choisi par l'utilisateur.
3. **Score de confiance global**, pas de règles binaires isolées pouvant chacune déclencher seule une action à fort impact. *Implémenté : seuil 0,85 dans `config/document.php`, `ClassificationPolicy`, `Block::AUTO_ACCEPT_THRESHOLD`.*
4. **Toute nouvelle règle doit être validée sur un corpus documenté**, pas sur une anecdote isolée. *Le corpus de mesure existe — mais sa base doit être CORRIGÉE : voir la note ci-dessous.*
   - ⚠️ **Correction du 2026-09-29.** Les règles en place sont justifiées par des chiffres cités « sur 526 documents ». Or le corpus contient **2 241 fichiers pour 1 347 contenus distincts** : la duplication est de 33 %. Les chiffres de fréquence portent donc sur des FICHIERS, et surestiment la réalité d'environ un tiers. Ils restent utiles comme ordres de grandeur, mais ne doivent pas servir à trancher un seuil sans être refaits sur les contenus distincts.
   - *Observation (inférence, non règle documentée)* : les défauts jugés prioritaires dans l'historique du code se situent tous autour de 40 % du corpus — 19 fichiers XML invalides sur 51 (37 %), 43 % des légendes à distance ≥ 3, 861 légendes sans lien sur 861. Cela suggère un seuil de décision de fait (~40 % = défaut majeur), mais **ce seuil n'est écrit nulle part** et reste à formaliser si l'équipe veut s'y référer explicitement.
5. **Séparer explicitement** dans toute documentation future : ce qui est implémenté et testé / ce qui est prévu mais non codé / ce qui est dette assumée consciemment écartée.
6. **(nouveau)** **Une fonctionnalité n'est « livrée » que si elle est atteignable par un utilisateur réel.** Toute ligne d'un état des lieux doit préciser : codé ? testé ? **actif en production ?**
   - ⚠️ **L'exemple invoqué à l'origine pour ce principe était faux** (`document.pipeline.v2` était présenté comme désactivé alors qu'il vaut `auto`). Le principe reste valable, mais il doit être appliqué avec une VÉRIFICATION, pas une supposition : « le flag est-il défini dans `.env` ? » se lit en une commande, et l'erreur a structuré tout un document.
7. **(nouveau)** **Corriger les échecs silencieux en priorité.** Un défaut qui produit un résultat faux sans erreur (renumérotation absente, entrée de sommaire prise pour un titre) est plus coûteux qu'un échec explicite : il passe la relecture. Un défaut qui empêche l'ouverture du fichier (XML invalide) est encore plus grave, car il bloque l'utilisateur.
   - *Vérifié le 2026-09-29 sur le défaut du sommaire* : 72,5 % des titres étaient faux, **aucune erreur n'était levée**, et le document se serait exporté normalement. C'est exactement le profil décrit ici.
8. **(nouveau, 2026-09-29)** **Un signal proposé doit être testé sur le cas MÊME qui l'a fait proposer.** La version précédente retenait les points de suite comme signal principal de reconnaissance d'un sommaire. Mesure sur le document cité en référence par ce même document : **0 détection sur 49 entrées**. Le signal n'avait jamais été éprouvé sur le cas d'où il venait.
9. **(nouveau, 2026-09-29)** **Mesurer par composant, pas par total.** Sur la facturation IA, un compteur global de 4 requêtes HTTP a fait attribuer les appels au mauvais composant, et la conclusion était l'inverse de la réalité. Un total ne dit pas QUI a produit quoi.
10. **(nouveau, 2026-09-29)** **Un test qui échoue selon la charge de la machine crée un défaut au lieu d'en révéler un.** `PaymentLinkTest` comparait une durée en JOURS calculée depuis `created_at` (posé par la base) à partir de `now()` (calculé en PHP avant l'insertion) : l'écart de quelques millisecondes suffisait à faire retourner 2 au lieu de 3. Le test passait seul et échouait en suite complète. Correction : comparer à une seconde près, **après avoir vérifié que le test détecte toujours une durée fausse** (sinon on a supprimé le signal au lieu du bruit).

---

## 4. Plan de vérification à exécuter avant nouvelle phase de développement

### 4.1 Le corpus est mesuré — mais sur une base à CORRIGER

Le document initial demandait une grille de test sur « 15+ documents ». Une version ultérieure a mesuré « 526 documents ». **La base de cette mesure est fausse** : le corpus contient **2 241 fichiers** pour **1 347 contenus distincts** (duplication de 33 %, un fichier y figure jusqu'à 9 fois).

Les 526 « documents » étaient donc des FICHIERS. La base réelle est d'environ **350 contenus distincts** — ou 1 347 si l'on prend le corpus entier, ce qui n'a jamais été fait.

**Conséquence directe** : toute fréquence exprimée en pourcentage sur 526 doit être revue. Une règle qui touche « 98 documents sur 526 » (18,6 %) peut représenter une proportion très différente de contenus distincts.

Toute nouvelle règle doit être évaluée **avant/après sur les contenus DISTINCTS**, pas sur les fichiers, et sur l'ensemble du corpus plutôt qu'un échantillon.

### 4.2 Questions réellement ouvertes (état au 2026-09-29)

**Tranchées depuis :**

- ~~Sur combien de documents la détection de titre échoue-t-elle ?~~ → mesuré : 775 titres faussement rehaussés sur 51 documents ; 7 sur 51 sans aucun titre.
- ~~Fréquence de la règle de position « sommaire p.1 » ?~~ → **obsolète** : la position n'est pas un critère retenu (principe 1). De plus, le signal proposé à la place (`toc N`) ne couvrait **pas** le cas le plus fréquent, et les **points de suite** — signal « principal » de la version précédente — détectaient **0 entrée sur 49** sur le document même cité en référence.
- ~~Le seuil de confiance est-il défini ?~~ → oui : 0,85, configurable.
- ~~Le pipeline natif est-il actif ?~~ → **oui** (`auto`), mesuré : 693 blocs produits. La question devient « quand passer de `auto` à `true` ? » (voir question 1 ci-dessous).
- ~~Les entrées de sommaire sont-elles prises pour des titres ?~~ → **oui, et corrigé** : 58 des 80 titres du document de référence (72,5 %) → **0**. Corrigé sur `feature/sommaire-detection`.
- ~~Un sommaire tapé à la main produit-il un classement silencieux ?~~ → **il en produisait un** (le document de référence EST ce cas), et il est désormais couvert par le filtre de contenu.

**Réellement ouvertes :**

1. **Quand passer `pipeline.v2` de `auto` à `true` ?** Le repli automatique protège mais masque une divergence entre deux moteurs. Le critère mesurable : compter les lignes `Pipeline natif : échec, l'ancien pipeline fait foi` dans les logs. Tant que ce compte est nul sur un volume suffisant, `true` est justifiable ; s'il ne l'est pas, le repli travaille et il faut savoir pourquoi.
2. **Combien d'entrées de sommaire restent mal classées sur l'ensemble du corpus ?** Le correctif n'a été mesuré que sur **un** document. C'est insuffisant pour généraliser (principe 4) — il faut mesurer sur les **1 347 contenus distincts**, avant/après.
3. **Coût réel en tokens et en crédits par document** de la double-vérification IA (nombre de blocs sous 0,85 × coût unitaire). Données partielles (12 % de blocs ambigus), pas de conversion. **Cette question a pris de l'importance** : la facturation du mode « assistance IA » repose sur une *estimation*, pas sur cette mesure.
4. **Fréquence réelle des problèmes de marges** : à mesurer sur les contenus distincts. Reste en dette assumée tant que le chiffre manque.
5. **Le filtre de contenu reclasserait-il un tableau de données numériques ?** Faux positif identifié mais **non testé**. Le seuil de densité (4 lignes dans 12 blocs) le rend improbable, pas impossible.
6. **Les diagrammes du document de référence sont-ils des images aplaties ou des formes éditables ?** Question non instruite (voir §5.3) — conditionne le classement en dette.

### 4.3 Critère de sortie avant d'attaquer une nouvelle phase

1. **Refaire les fréquences sur les contenus distincts** (1 347), pas sur les fichiers (2 241) ni sur l'ancienne base de 526.
2. **Mesurer le correctif du sommaire sur l'ensemble du corpus**, pas sur un document — c'est la condition pour savoir si le défaut était de 72 % (cas particulier) ou marginal.
3. Trancher la question 1 (`auto` → `true`) avec le comptage des lignes de repli.
4. Ne pas coder de nouvelle règle de détection sans chiffre sur le corpus à l'appui (principe 4).
3. Ne pas coder de nouvelle règle de détection sans chiffre sur le corpus à l'appui (principe 4).

---

## 5. Cas de référence structurel — `RAPPORT_DE_STAGE_DQP_final.pdf`

Ce document réel sert de **double usage** : (a) référence de structure cible pour le pipeline de détection déterministe, et (b) cas de test pour les pièges de détection déjà identifiés. **Important : ne pas confondre les deux usages.** Certains éléments de ce document sont des défauts à détecter et corriger, pas des modèles à reproduire à l'identique.

> **Note d'exécution (2026-09-29).** Le fichier de travail correspondant dans le dépôt est `storage/app/private/chat/generated/2026/08/27/rapport_de_stage_modifie.docx` (490 Ko — 7 Ko pour la copie `RAPPORT_DE_STAGE_CORRIGE.docx`, trop petite pour être le document cité). Les mesures de ce document sont celles utilisées en §2.2.

### 5.1 Structure cible confirmée (à répliquer dans le gabarit par défaut)

- [ ] Ordre des pages liminaires respecté : Sommaire → Dédicace → Remerciements → Avant-propos → Liste des sigles → Liste des figures → Liste des tableaux → Résumé → Abstract, chacun sur une page dédiée.
- [ ] Table des matières complète positionnée en toute fin de document (après conclusion, après références bibliographiques).
- [ ] Différenciation de profondeur confirmée : Sommaire = niveaux Chapitre/Section uniquement (2 niveaux) ; Table des matières = tous niveaux, y compris sous-sections (I., 1., A.).
- [ ] Légendes `Tableau N: ...` positionnées au-dessus du tableau ; `Figure N: ...` positionnées en dessous de l'image.

**Vérification à exécuter** : le pipeline actuel respecte-t-il déjà cette structure lors de la génération du gabarit par défaut ? **Documenter écart par écart** — aucun de ces quatre points n'a été vérifié à ce jour.

**Éléments de preuve déjà disponibles dans le code, à confronter à cette liste :**

| Point | Ce que le code fait | Écart connu ? |
|---|---|---|
| Pages liminaires dédiées | `DocumentReconstructor` écrit une section frontispice (sommaire, liste des figures, liste des tableaux) — voir `applyFrontispice` | **L'ordre exact de la liste n'est pas implémenté** : le code produit sommaire + listes, pas la séquence complète |
| Table des matières en fin | `TableOfContentsGenerator` produit le contenu ; `RenderCoordinator` l'insère **avant** la pagination | **Position non conforme** à la cible décrite ici (fin de document) |
| Sommaire 2 niveaux / TdM tous niveaux | `TableOfContentsGenerator::MAX_DEPTH = 3` (constant, unique) | **Non différencié** : un seul générateur, une seule profondeur |
| Légende au-dessus / en dessous | `CaptionLinker` cherche le porteur « juste SOUS (convention académique), parfois juste AU-DESSUS » | **La distinction Figure/Tableau n'est pas appliquée** : la règle est symétrique |

**Conclusion provisoire** : sur les quatre points, **trois présentent un écart identifié** et le quatrième (pages liminaires) est partiel. Aucun n'est mesuré — cette liste est donc un **programme de vérification**, pas un constat.

### 5.2 Pièges de détection à vérifier explicitement (défauts du document source — ne pas reproduire, mais détecter/gérer)

- **Numérotation mixte incohérente entre niveaux** : ce document alterne romain (CHAPITRE I), arabe (SECTION 1), romain à nouveau (I. HISTORIQUES), arabe (1. HISTORIQUE), alphabétique (A. ORGANIGRAMME), arabe (1. ORGANIGRAMME) selon la profondeur. **Vérifier que la détection n'exige pas un schéma de numérotation unique et cohérent sur tout le document** — elle doit accepter des conventions différentes par niveau de profondeur, y compris quand un même style (romain, arabe) réapparaît à des niveaux différents.

  ✅ **Vérifié le 2026-09-29 — NON REPRODUIT.** Mesure par le détecteur réel : **5 schémas de numérotation cohabitent** dans le même document sans blocage (majuscules/mot-clé 79, romain seul 23, arabe 32, mot-clé + romain 5, mot-clé + arabe 6). La détection n'exige donc pas de schéma unique. Preuve : `HeadingNumberingPattern` retourne un niveau par motif indépendant, sans cohérence globale.

- **Doublons de légende avec formulation variable** : ex. « Tableau 2. tache effectue au stage » en introduction du tableau (point, casse basse) vs « Tableau 2: tache effectue au stage » en légende réelle (deux-points). Vérifier que le système ne compte pas ceci comme deux tableaux distincts ou n'entre pas en conflit de numérotation.

  ⚠️ **Vérifié le 2026-09-29 — NON REPRODUIT sur ce document, mais non conclusif.** Ce document ne contient **aucune** occurrence de la forme `Mot N:` ou `Mot N.` (0 mention détectée). Le piège ne peut donc pas être reproduit sur lui. **Le point reste ouvert** : il faudrait le chercher sur le corpus, ou construire un cas de test — sans quoi il reste une hypothèse non testée.

- **En-tête/pied de page répété distinct du contenu** : le texte « REDIGER EN VUE D'ETRE PRESENTE PAR : [nom] » + titre du mémoire se répète à l'identique sur chaque page (seul le numéro de page varie). Vérifier explicitement que le système de détection **ne classe jamais ce texte répété comme un titre récurrent**, et que la répétition à l'identique (hors numéro de page) est bien reconnue comme signal de header/footer plutôt que comme contenu de corps de texte.

  ⚠️ **Vérifié le 2026-09-29 — NON MESURABLE sur ce fichier, à vérifier ailleurs.** Le fichier de travail ne contient **aucun en-tête ni pied de page** (`en_tetes` et `pieds_de_page` : 0). Le texte cité est en corps de document. Le piège ne peut donc pas être évalué ici — le fichier cité dans cette section est un `.pdf` dont la version `.docx` disponible ne restitue pas ces zones.

  **Ce qui existe dans le code pour ce cas** : `DocumentParser::preferXmlText()` récupère le texte des textboxes d'en-têtes/pieds que PhpWord ignore, et `HeaderFooterInjector` gère l'injection. La reconnaissance de « texte répété = en-tête » n'est en revanche **pas un mécanisme dédié** — elle repose sur le fait que ces zones sont lues séparément du corps (`parent=header|footer`), donc jamais candidates au titre. **À vérifier par test dédié**, conformément au principe 7 (échec silencieux).

### 5.3 Point non résolu — éléments flottants dans les diagrammes

Ce document contient des organigrammes et diagrammes de séquence (Figures 1, 8, 9, 10) construits, selon toute vraisemblance, avec des formes reliées par des flèches — le type d'élément que l'architecture actuelle évite pour les titres et la couverture, mais qui apparaît ici comme contenu légitime et fréquent dans ce type de rapport (organigrammes, UML, PERT, Gantt).

**À investiguer, statut non tranché** (ni dette assumée, ni implémenté) :

- Le document source contient-il ces diagrammes comme **image aplatie** (capture d'écran) ou comme **formes Word éditables individuelles** ?
- Les outils actuellement disponibles permettent-ils de détecter cette distinction et de gérer les deux cas sans casse ?
- Si les formes éditables ne peuvent pas être prises en charge de façon fiable avec les outils actuels, formaliser ce point comme **dette assumée après investigation** — ne pas le classer avant d'avoir vérifié la faisabilité technique réelle.

**Éléments de méthode pour l'investigation** (aucune mesure faite à ce jour) :

| Question | Comment y répondre |
|---|---|
| Image aplatie ou formes éditables ? | Ouvrir l'archive `.docx` et chercher `word/media/` (aplatie) contre `w:drawing/wps:wsp` (formes). Le nombre d'entrées dans `word/media/` est déjà compté par `DocxNativeAdapter` (`image_count`) |
| Détectable sans casse ? | `DocxNativeAdapter` traite **toute** image comme figure ou décorative. Des formes `wps:wsp` ne sont ni l'un ni l'autre : elles seraient aujourd'hui **ignorées** — perte silencieuse, contraire au principe « aucune perte de contenu » |
| Faisable ? | À trancher après la mesure ci-dessus. Le risque est asymétrique : ignorer une forme est une **perte**, la convertir en image est une **dégradation** |

**Priorité** : investigation avant classement (principe 4 — ne pas classer une dette sans mesure).

---

## 6. Dette assumée (rappel consolidé, non résolue mais consciemment écartée)

- Versioning complet des documents
- Gestion de la concurrence à l'échelle du produit
- Chunking pour les gros documents
- Plan de test formel
- ~~Sommaire tapé manuellement par l'utilisateur (cas rare)~~ → **RETIRÉE le 2026-09-29.** Ce cas n'est plus rare : c'est **celui du document de référence**, et il produisait 72,5 % de faux titres. Il est désormais traité par le filtre de contenu (branche `feature/sommaire-detection`).
- Gestion des marges mal définies par l'utilisateur — *fréquence inconnue (question 4)*
- Éléments flottants dans en-têtes/pieds de page
- Mécanisme de certification établissement (repoussé en Phase 7)

> **Recherche web et génération d'images ont été RETIRÉES de cette liste.** Décision : elles sont livrées et assumées (voir §2.5). Elles ne sont donc plus une dette écartée.
>
> **Le sommaire tapé à la main en a également été retiré le 2026-09-29** — pour une raison différente : il n'était pas rare, il était mal mesuré. Le classer en dette supposait qu'il concernait une minorité de documents ; la mesure montre qu'il s'agit du cas du document de référence, et qu'il produisait le défaut le plus grave de la détection.

---

## 7. Défauts corrigés découverts par cet audit (pour mémoire)

Ces défauts n'étaient pas documentés. Ils sont corrigés et couverts par des tests ; ils illustrent le type d'erreur que ce document doit permettre de détecter plus tôt.

| Défaut | Impact mesuré | Correctif | Test |
|---|---|---|---|
| Échappement XML désactivé (défaut PhpWord) | **19 fichiers sur 51** invalides, **refusés par Word** | Échappement activé puis restauré en `finally` | `XmlEscapingTest` |
| Codes de champ Word recopiés littéralement | `{ SEQ Figure \* ARABIC }` apparaissait **tel quel** dans le document généré au lieu de « 1, 2, 3… » | `DocumentParser::resolveSeqFields()` (compteur global par type) + `stripFieldCodes()` pour `TOC`/`PAGE`/`REF`/`NUMPAGES`/`STYLEREF` | `DocumentReconstructorTest::test_les_champs_seq_litteraux_sont_resolus_avec_un_compteur_global` |
| Double comptage du signal de titre | **775 titres** acceptés sans vérification IA, sur 51 documents | `preserveExistingHeadingConfidence: true` | `BlockClassifierWiringTest` |
| 98 documents avec doublons de numérotation non traités | Renvois internes pointant sur des numéros faux | `NumberingPass` + `CrossRefRewriter` | `NumberingPassTest`, `CrossRefRewriterTest` |
| 861 légendes sans aucun lien vers leur porteur | Renumérotation impossible | `CaptionLinker` (3 règles ordonnées) | `CaptionLinkerTest` |
| Réponse IA perdue après facturation (charset MySQL `utf8mb3`) | L'utilisateur payait sans recevoir de résultat | Tables en `utf8mb4` + migration | `DatabaseCharsetTest` |
| `tool_choice: required` ignoré par certains fournisseurs | Demande sans effet, crédits débités | `ToolChoiceIgnoredException` + bascule de modèle | `OpenRouterMultiTurnCostTest` |
| **Entrées de sommaire promues en titres** (reclassement de la révision 2026-09-29) | **58 des 80 titres** du document de référence (72,5 %), numéros de page compris | `is_list_style` consommé (`BlockType::TocEntry`, motifs `toc N` sans plafond) + filtre de contenu (fenêtre dense, numéros arabes **et** romains) | `TocEntryDetectionTest` (7 tests) |
| **Prix des modèles illisible** (notation pointée Laravel) | 3 modèles sur 9 renvoyaient `null` → coût 0 → **tous les plans payants facturés 1 crédit au lieu de 252** | Lecture par tableau (`UsageCostCalculator::pricingFor`), `null` au lieu de `0.0` | `DocumentModePricingTest::test_tous_les_modeles_de_la_grille_ont_un_prix_lisible` |
| **Appels IA hors facturation** | Mode « IA complète » et « assistance IA » envoyaient au modèle **sans aucun débit** (hors registre d'usage) | Remontée d'usage + `DocumentCredits` (débit estimé / ajustement au réel) | `ChatToolsUsedTest`, `DocumentModePricingTest` |
