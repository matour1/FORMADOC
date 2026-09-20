# État du projet, limitations identifiées et plan de vérification

> Document de travail destiné à l'agent IA de développement (GitHub Copilot). Objectif : consolider l'état réel du système, les angles morts détectés lors des échanges de conception, et les vérifications à effectuer avant d'étendre le scope.
>
> **Révision du 2026-09-20** — audit complet du code mené contre la version précédente de ce document. Les sections 1.2, 2.2, 2.4, 2.5, 3, 4 et 5 ont été réécrites ; une section 6 a été ajoutée. Plusieurs affirmations du document initial étaient **contredites par le code**, dans les deux sens : des fonctions annoncées « à confirmer » sont implémentées et testées ; une fonction annoncée comme « à construire » a en réalité été **retirée** du produit ; des défauts non documentés se sont révélés plus graves que ceux décrits. Chaque affirmation ci-dessous porte désormais sa preuve (fichier, classe, test).

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

### 1.2 bis — ⚠️ Constat majeur : ces fonctions existent mais ne sont PAS actives en production

C'est l'angle mort principal, absent de la version précédente de ce document.

Le pipeline est sélectionné dans `DocumentController::writeDocument()` :

```php
$structural = $document->structure?->structuralDocument();
if ($structural !== null && app(DocumentPipeline::class)->isNativeEnabled()) {
    // → FormattedDocumentExporter : gabarit, renumérotation, listes, renvois
}
return $this->writeLegacyDocument(...);   // sinon : DocumentReconstructor seul
```

Or `config/document.php` déclare `'v2' => env('DOCUMENT_PIPELINE_V2', false)` et **aucune variable `DOCUMENT_PIPELINE_V2` n'existe dans `.env`**. Conséquence :

- le pipeline réellement actif est le **legacy** (`app/DocAnalyzer` → `DocumentReconstructor`) ;
- `NumberingCoordinator`, `RenderCoordinator`, `CrossRefRewriter`, `CaptionLinker` ne sont **jamais atteints en usage réel** — uniquement dans les tests qui forcent `config(['document.pipeline.v2' => true])` (`DocumentPipelineIntegrationTest`, `BlockClassifierWiringTest`, `DocumentPipelineTest`).

**Les fonctionnalités 2, 3 et 4 sont donc « codées et testées » mais pas « livrées ».** Tant que la bascule n'est pas faite, un utilisateur qui dépose un rapport avec des doublons de numérotation n'obtient pas le résultat décrit dans ces tests.

**Question à trancher (nouvelle, prioritaire) : quelle est la stratégie de bascule vers `pipeline.v2` ?** C'est elle qui détermine si les fonctionnalités 2/3/4 arrivent réellement chez l'utilisateur. Le flag existe et permet une bascule instantanée (`false` → `'auto'` → `true`), mais aucune décision n'a été prise sur le moment et les critères de passage.

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

Lacune 1 — **`is_list_style` est calculé mais jamais consommé.** `ParagraphReader` produit `'is_list_style' => $this->styles->isListStyle($styleId)`, mais le champ n'apparaît **nulle part ailleurs** dans `app/` (recherche exhaustive sur `is_list_style` : une seule occurrence, celle qui le remplit). Le signal de style de sommaire est donc collecté puis jeté. En pratique, une entrée de sommaire stylée `toc 1` n'est écartée que si elle porte **aussi** des points de suite (`isListEntry`), ce qui n'est pas garanti pour un sommaire Word natif extrait par PhpWord (cf. lacune 2).

Lacune 2 — **le sommaire lui-même n'est pas écarté de la détection de titres, seul son contenu est filtré en surface.** `findTocBoundaries()` ne reconnaît que les titres `LISTE DES (FIGURES|TABLEAUX|...)`. Un `SOMMAIRE` / `TABLE DES MATIÈRES` n'ouvre **pas** de plage à ignorer. Le mot est seulement reconnu comme mot-clé de niveau 1 (`RegexTitleDetector::MOTS_CLES_NIVEAU_1`, `HeadingNumberingPattern`), donc le titre « SOMMAIRE » lui-même est bien classé — mais **les lignes de son contenu restent candidates**. Votre hypothèse initiale sur le risque d'extraction est donc confirmée sur ce point : le comportement dépend du fait que PhpWord restitue ou non les points de suite littéraux.

**Ce que la mesure du corpus dit (et qui contredit le document initial)**

Le document initial affirmait « 7/7 documents testés » et proposait une grille de test sur « 15+ documents clients ». Or **le corpus a déjà été mesuré, sur 526 documents**. Les chiffres sont enfouis dans les commentaires du code :

| Mesure | Valeur | Source |
|---|---|---|
| Corpus mesuré | **526 documents** | `NumberingPass`, `CaptionLinker` |
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

**Solution retenue pour le sommaire — signal de contenu principal, position en renfort seulement**

- Signal principal : points de suite + alignement numérique à droite + répétition sur lignes consécutives.
- Signal de cohérence croisée : le texte à gauche des points de suite doit correspondre à un titre déjà détecté ailleurs. **Partiellement implémenté** (`isListEntry`), **à compléter**.
- Position dans le document : renfort de score uniquement, jamais décisionnaire.
- **Signal le plus fiable disponible et non exploité** : le style Word `toc N` et le champ `TOC`. Ces deux signaux sont **déjà lus** — il suffit de les brancher dans la classification. C'est le correctif le moins coûteux et le plus robuste : il est déterministe, alors que les points de suite dépendent de l'extraction.

**Travail restant, par ordre de rapport bénéfice/risque** :
1. Consommer `is_list_style` (déjà calculé) dans la classification → type de bloc « entrée de sommaire », exclu des titres.
2. Faire ouvrir une plage à ignorer par `SOMMAIRE` / `TABLE DES MATIÈRES` dans `findTocBoundaries()` (actuellement limité aux `LISTE DES …`).
3. Vérifier sur le corpus que les 526 documents n'ont pas d'entrées de sommaire classées comme titres — mesure avant/après, conformément au principe 4.

**Cas limite — sommaire tapé à la main** : reste une **dette assumée**. Ce cas est couvert par la lacune 2 si les points de suite manquent ; le filet de sécurité est le seuil de 0,85 → clarification ciblée. **À vérifier** : qu'un sommaire manuel sans points de suite produit bien une clarification et non un classement silencieux en titres.

**Faux positifs à tester** : un tableau de données numériques alignées peut ressembler à une TOC. Le critère combiné (motif + répétition + cohérence croisée) doit suffire — **à tester spécifiquement**, ce n'est pas couvert par un test dédié aujourd'hui.

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
4. **Toute nouvelle règle doit être validée sur un corpus documenté**, pas sur une anecdote isolée. *Le corpus de mesure existe : 526 documents. Les règles en place (renumérotation, rattachement des légendes, fenêtre de distance = 2) sont toutes justifiées par un chiffre issu de ce corpus, cité en commentaire de code.*
   - *Observation (inférence, non règle documentée)* : les défauts jugés prioritaires dans l'historique du code se situent tous autour de 40 % du corpus — 19 fichiers XML invalides sur 51 (37 %), 43 % des légendes à distance ≥ 3, 861 légendes sans lien sur 861. Cela suggère un seuil de décision de fait (~40 % = défaut majeur), mais **ce seuil n'est écrit nulle part** et reste à formaliser si l'équipe veut s'y référer explicitement.
5. **Séparer explicitement** dans toute documentation future : ce qui est implémenté et testé / ce qui est prévu mais non codé / ce qui est dette assumée consciemment écartée.
6. **(nouveau)** **Une fonctionnalité n'est « livrée » que si elle est atteignable par un utilisateur réel.** Un composant testé derrière un flag désactivé (`document.pipeline.v2`) ne compte pas comme livré. Toute ligne d'un état des lieux doit préciser : codé ? testé ? **actif en production ?**
7. **(nouveau)** **Corriger les échecs silencieux en priorité.** Un défaut qui produit un résultat faux sans erreur (renumérotation absente, entrée de sommaire prise pour un titre) est plus coûteux qu'un échec explicite : il passe la relecture. Un défaut qui empêche l'ouverture du fichier (XML invalide) est encore plus grave, car il bloque l'utilisateur.

---

## 4. Plan de vérification à exécuter avant nouvelle phase de développement

### 4.1 Le corpus est déjà mesuré — exploiter les mesures existantes avant d'en produire de nouvelles

Le document initial demandait une grille de test sur « 15+ documents ». **Le corpus de référence compte 526 documents et a déjà été instrumenté** (voir le tableau des mesures en 2.2). Toute nouvelle règle doit être évaluée **avant/après sur ces 526 documents**, pas sur un échantillon plus petit : les mesures existantes prouvent que les échantillons de 7 à 51 documents donnaient des conclusions différentes de celles du corpus complet (7/7 documents « avec sommaire » vs. majorité du corpus « sans sommaire »).

### 4.2 Questions réellement ouvertes (après audit)

Les questions de la version précédente qui sont **tranchées** :

- ~~Sur combien de documents la détection de titre échoue-t-elle ?~~ → mesuré : 775 titres faussement rehaussés sur 51 documents ; 7 documents sur 51 sans aucun titre.
- ~~Fréquence de la règle de position « sommaire p.1 » ?~~ → question **obsolète** : la règle de position n'est pas retenue comme critère de décision (principe 1). Le signal retenu est le style Word `toc N` + champ `TOC`, déjà lus.
- ~~Le seuil de confiance est-il défini ?~~ → oui : 0,85, configurable.

Les questions **réellement ouvertes** :

1. **Quelle est la stratégie de bascule vers `pipeline.v2` ?** Critères de passage, date, modalité (`'auto'` puis `true`) ? C'est la question la plus bloquante : sans réponse, trois fonctionnalités déjà codées et testées ne sont pas livrées.
2. **Combien d'entrées de sommaire sont actuellement classées comme titres sur les 526 documents ?** Mesure avant/après le branchement de `is_list_style`. Inconnue à ce jour — c'est le chiffre qui déciderait de la priorité du correctif 2.2.
3. **Coût réel en tokens et en FCFA par document** de la double-vérification IA (nombre de blocs sous 0,85 × coût unitaire). Données partielles (12 % de blocs ambigus), pas de conversion en crédits.
4. **Fréquence réelle des problèmes de marges** sur les 526 documents : reste-t-elle en dette assumée ou justifie-t-elle un développement ?
5. **Un sommaire tapé à la main sans points de suite produit-il une clarification, ou un classement silencieux en titres ?** À vérifier par test dédié — c'est un cas d'échec silencieux (principe 7).

### 4.3 Critère de sortie avant d'attaquer une nouvelle phase

1. Répondre à la question 1 (stratégie de bascule) — sinon on empile du code non livré.
2. Pour chaque correctif de la section 2.2 : mesure avant/après sur les 526 documents.
3. Ne pas coder de nouvelle règle de détection sans chiffre sur le corpus à l'appui (principe 4).

---

## 5. Dette assumée (rappel consolidé, non résolue mais consciemment écartée)

- Versioning complet des documents
- Gestion de la concurrence à l'échelle du produit
- Chunking pour les gros documents
- Plan de test formel
- Sommaire tapé manuellement par l'utilisateur (cas rare) — *couvert par le seuil 0,85, à confirmer (question 5)*
- Gestion des marges mal définies par l'utilisateur — *fréquence inconnue (question 4)*
- Éléments flottants dans en-têtes/pieds de page
- Mécanisme de certification établissement (repoussé en Phase 7)

> **Recherche web et génération d'images ont été RETIRÉES de cette liste.** Décision : elles sont livrées et assumées (voir §2.5). Elles ne sont donc plus une dette écartée.

---

## 6. Défauts corrigés découverts par cet audit (pour mémoire)

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
