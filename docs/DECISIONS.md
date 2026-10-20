# DECISIONS.md — décisions techniques de FORMADOC

> **Règle de tenue** : chaque décision est datée, motivée, et indique ce qui
> serait nécessaire pour la réviser. Une décision sans motivation ne se distingue
> pas d'un accident.
>
> **2026-10-18 — arbitrages du responsable intégrés.** Les décisions D-002 et
> D-006 ont été **renversées** par le responsable du projet : la page de garde
> doit être supprimée, et GeniusPay passe AVANT les chantiers documentaires.
> Les réponses complètes figurent en fin de document (§ Décisions validées).

---

## D-001 — L'IA reste optionnelle ; le mode déterministe est le défaut

**Date** : 2026-10-18
**Contexte** : les instructions v1 demandent « aucun appel à une IA ». Le code
livré contient un pipeline IA complet et trois modes exposés à l'utilisateur
(`regex`, `assiste`, `precision`).
**Décision** : ne rien retirer.
**Motifs** :
1. Les modes `assiste` et `precision` sont **facturés** ; les supprimer
   supprimerait un revenu et une fonctionnalité annoncée.
2. Le mode `regex` est déjà 100 % déterministe et couvre les exigences v1. Il
   peut servir de référence sans amputer le produit.
3. Une suppression serait irréversible pour les utilisateurs qui l'utilisent.

**Révision** : possible uniquement sur décision produit explicite, avec mesure
d'usage préalable (`ai_usage_ledger` donne le volume par mode).

---

## D-002 — Page de garde : DÉTECTER, SUPPRIMER et SIGNALER

**Date** : 2026-10-18 — **décision renversée le 2026-10-18 par le responsable**
**Contexte** : les instructions §2 et §5.2 demandent de détecter **et supprimer**
la page de garde. Le module de génération de page de garde est retiré du produit.
**Décision du responsable** : **détecter, supprimer et signaler**.

**Raison donnée par le responsable (2026-10-19)** — et c'est la justification
complète de la décision : « mon outil ne génère pas de page de garde, donc il ne
doit pas prendre en charge celle-ci. Donc si la page de garde est présente, elle
doit être supprimée. »

L'outil produit une mise en forme : il ne fabrique pas de couverture. Une page de
garde présente dans le document source est donc un **résidu** que le traitement
ne sait pas mettre en forme — la conserver reviendrait à livrer un document dont
la première page échappe au gabarit. C'est ce qui justifie l'exception au
principe « ne jamais perdre de contenu ».

**Exception assumée** : la page de garde et les anciens sommaires échappent au
principe de non-perte, pour deux raisons : la page de garde n'est pas prise en
charge par le traitement, et le sommaire est **régénéré automatiquement**.

**Ce que cela ne rouvre PAS** : la GÉNÉRATION de page de garde reste retirée.
Seule la détection/suppression de celle du document source est demandée. Le test
`CoverModuleRemovalInvariantTest` porte sur les classes de génération et reste
valable.

**Garde-fou conservé** (§5.2) : au-delà de 2 pages, ne rien supprimer et avertir,
en indiquant le début du texte supprimé.

**Conséquence sur le point A1** : la question « faut-il générer une page de
garde ? » est **close** — non. L'outil ne génère pas de couverture, il la retire
quand elle existe.

**Révision** : aucune demande.

---

## D-003 — L'analyseur ne classe pas ; il rapporte des signaux

**Date** : 2026-10-18
**Contexte** : `formadoc:analyse` doit servir à mesurer la détection.
**Décision** : l'analyseur rapporte les signaux bruts (styles, motifs, champs) et
les candidats, **sans appliquer de seuil de décision**.
**Motif** : un analyseur qui déciderait reproduirait les règles qu'il est censé
mesurer, et ne pourrait donc jamais les contredire — il validerait toujours le
pipeline, y compris quand celui-ci se trompe.

**Conséquence mesurée dès la première exécution** : sur
`0yST9jio…docx`, l'analyseur trouve 5 « titres par motif », dont **4 sont des
entrées de sommaire** (elles finissent par un numéro de page : `I.\tDEVELOPEMENT
FRONTEND\t3`). Le pipeline, lui, en compte 14. L'écart était invisible avant cet
instrument.

---

## D-004 — Seuils du garde-fou de décompression

**Date** : 2026-10-18
**Décision** : refus au-delà de **500 Mo décompressés** ou **5 000 entrées**,
configurables via `document.security.*`.
**Motifs** :
- Un `.docx` normal compte quelques dizaines de parties et quelques Mo.
- 500 Mo laisse une marge très large à une mémoire illustrée légitime.
- 5 000 entrées est déjà le signe d'une archive construite ou corrompue.

**Limite assumée** : `statIndex()` lit la taille **déclarée** par l'archive. Un
en-tête falsifié peut la sous-déclarer ; ce garde-fou arrête les bombes
ordinaires, pas une falsification délibérée. La lecture se fait donc toujours
entrée par entrée (`getFromName`), jamais par extraction en bloc.

---

## D-005 — Le mode d'analyse par défaut reste `regex` (gratuit)

**Date** : 2026-10-18
**Décision** : conservé tel quel.
**Motif** : en cas de mode inconnu ou absent, le contrôleur retombe déjà sur
`MODE_REGEX` — c'est-à-dire sur le mode **gratuit**, jamais sur un mode payant.
Le comportement le plus prudent est donc déjà en place.

---

## D-006 — GeniusPay : 3ᵉ moyen de paiement, PRIORITAIRE

**Date** : 2026-10-18 — **priorité renversée par le responsable**
**Contexte** : `API_Documentation.md` fourni ; aucune trace dans le code.
**Décision du responsable** :
- GeniusPay **s'ajoute** comme 3ᵉ moyen (KPay et Monetbil restent) ;
- périmètre : **crédits ET abonnements** ;
- **devise : conversion** (l'API accepte XOF/EUR/USD, pas XAF) ;
- le responsable dispose des clés (sandbox et live) ;
- **priorité : GeniusPay AVANT les chantiers documentaires.**

**Conséquence sur le plan** : l'ordre de `PROJECTION_D_IMPLEMENTATION.md`
(paiements en dernier) n'est plus valable. GeniusPay ouvre la première phase.

**Points techniques à ne pas rater** :
- Checkout hébergé (sans `payment_method`) = approche recommandée par la doc.
- Le webhook signe `timestamp.payload` du **corps brut** : lire `php://input`
  avant toute désérialisation, sinon la signature ne correspond jamais.
- Idempotence par `Idempotency-Key` ou `external_reference`.
- Le taux de conversion XAF→XOF reste à définir avant d'écrire du code.

---

## D-007 — Les fichiers de plan supprimés ne sont pas restaurés

**Date** : 2026-10-18
**Contexte** : `ETAT_PROJET_ET_ANGLES_MORTS.md`, `PLAN_CORRECTION.md`,
`PLAN_DEVELOPPEMENT.md`, `PLAN_REFONTE.md` ont été supprimés de l'arbre de
travail (non commité).
**Décision** : ne pas les restaurer automatiquement.
**Motif** : une suppression non commitée est une action de l'utilisateur. La
restaurer sans demande écraserait son choix. Ils restent disponibles via Git
(`git checkout -- <fichier>`).

**Révision** : sur demande explicite.

---

## Décisions validées par le responsable — 2026-10-18

> Chaque ligne est un **arbitrage rendu**, plus une proposition.

| # | Sujet | Décision | Impact d'implémentation |
| --- | --- | --- | --- |
| A2 | Page de garde | **Détecter, supprimer, signaler** | Nouvelle brique de suppression (distincte de la génération, toujours retirée) |
| A3 | « Ne rien perdre » | **Exception** pour la page de garde et les sommaires : non pris en charge / régénérés | Le principe de non-perte ne s'applique pas à ces deux blocs |
| A4 | OCR / PDF scanné | **Hors périmètre** | Aucun adaptateur OCR |
| B2 | Niveaux de titres | **Confirmés** (Partie/Chapitre 1, Section 2, `I.` 3, `A.`/`1.` 4, puis 5+) | Configuration inchangée |
| B4 | Titre sous le seuil | **Disparaît du sommaire** | Pas de marque dans la table générée |
| C1 | Types de légendes | **+ Annexe** | Ajouter `annexe` à la liste des types connus |
| C2 | Ordre des listes | **Tableaux avant figures** | Inverser l'ordre actuel |
| C3 | Légende sans numéro en zone de texte | **Convertie** | Promotion + conversion |
| D1 | Sommaires tapés | **Supprimés, titre compris** | Suppression du bloc entier |
| D2 | Seuil liste tapée | **3 lignes consécutives** | Confirmé |
| D3 | Paragraphes vides | **≥ 3 → supprimer + `pageBreakBefore` ; sinon réduire à 1** | Confirmé |
| F1 | Rapport utilisateur | **Affiché ET téléchargeable** | Deux points de sortie |
| F2 | Estimation du temps | **Après sélection du fichier** | Affichage côté client |
| G2 | Analyseur sur documents réels | **Oui**, avec mention dans les CGU / confidentialité / mentions légales | 3 pages légales à mettre à jour |
| H3 | Rétention des fichiers | **72 h** (au lieu de 24 h) | `files:purge-temp` + config + pages légales |
| L1 | GeniusPay | **3ᵉ moyen** (s'ajoute) | Nouvelle passerelle au registre |
| L2 | Périmètre GeniusPay | **Crédits ET abonnements** | Les deux flux à brancher |
| L3 | Devise GeniusPay | **Conversion**, avec **XAF = XOF à parité 1:1** | Aucun taux externe : conversion identité |
| L4 | Clés GeniusPay | **Disponibles** (sandbox et live) | Intégration possible |
| L6 | Priorité GeniusPay | **Avant** les chantiers documentaires | Ordre du plan à revoir |
| J1 | Fichiers de plan supprimés | **Ne pas restaurer** | — |
| J2 | Organisation | **Une branche par phase** | — |
| J4 | Corpus de mesure | **Rapports réels** de `C:\Users\rey\OneDrive\Desktop\RAPPORT` | 14 `.docx` **non mis en forme** : des entrées à traiter et à mesurer |

### Nature du corpus

Les rapports de `C:\Users\rey\OneDrive\Desktop\RAPPORT` sont des **entrées** :
ils ne sont **pas** déjà mis en forme. Ce sont précisément les documents sur
lesquels le traitement doit être testé — hétérogènes, avec leurs défauts
originaux (styles absents ou mal utilisés, sommaires tapés, pages de garde
présentes).

**Conséquence sur la méthode** : ils ne peuvent pas servir de vérité-terrain pour
mesurer la fidélité de la SORTIE. Pour comparer entrée et sortie, il faudra soit
un rapport de référence déjà mis en forme, soit une validation manuelle sur un
échantillon. Le rappel et la précision de la détection de titres se mesurent
autrement : comptage manuel sur un échantillon, comparé à `formadoc:analyse`.

Ces fichiers sont des **données personnelles réelles** d'étudiants : ils servent
à mesurer, jamais à être copiés dans le dépôt ni ajoutés à une fixture versionnée.
Les tests continuent d'utiliser des `.docx` synthétiques ; la mesure s'exécute
hors dépôt.

### Points encore ouverts

| # | Question | Défaut en attendant |
| --- | --- | --- |
| B1 | Promotion des titres en zones de texte | 1-2 paragraphes courts seulement |
| B3 | Seuil de confiance | 0,85 |
| D4 | Réordonnancement des pages liminaires | signaler seulement |
| E1 | Titre/nom de l'en-tête | saisis dans le formulaire |
| E2 | Texte exact du pied | `RÉDIGÉ EN VUE D'ÊTRE PRÉSENTÉ PAR : <nom>` |
| E3 | Renvois « voir Figure 3 » | signaler seulement |
| E4 | Stratégie des numéros de page | option B, après mesure |
| G1 | Analyseur dans l'interface | CLI + JSON seulement |
| H1 | Seuils anti-bombe | 500 Mo / 5 000 entrées |
| H2 | Refus des `.docm` | oui |
| I5 | Secret webhook GeniusPay | `.env` |
| J3 | Emplacement des décisions | `docs/DECISIONS.md` |
| J5 | Fixtures | privées + jeu synthétique versionné |

### Points clos le 2026-10-19

| # | Question | Réponse |
| --- | --- | --- |
| A1 | Générer une page de garde ? | **Non** — l'outil n'en génère pas ; il supprime celle du document source |
| L7 | Taux XAF→XOF | **Parité 1:1** — les deux devises ont la même valeur |
| J4 | Corpus | Rapports **non mis en forme** : entrées de test, pas vérité-terrain de sortie |

---

## D-026 — Référence de sortie : `RAPPORT DE STAGE DQP 1 .docx`

**Date** : 2026-10-19
**Décision du responsable** : ce fichier, sur le Bureau, sert de **modèle de
référence** pour les tests. C'est une SORTIE déjà mise en forme, à laquelle
comparer le résultat du traitement.

### La paire de mesure est identifiée

| | `RAPPORT DE STAGE DQP.docx` | `RAPPORT DE STAGE DQP 1 .docx` |
| --- | --- | --- |
| Rôle | **entrée brute** | **sortie de référence** |
| Paragraphes en style de titre | 0 | 76 |
| Sommaire en champ TOC | 0 | 4 |
| Styles de sommaire (`toc N`) | 0 | 147 |
| Signets `_Toc` | 299 | 299 |
| Champs `SEQ` | 20 | 20 |
| Légendes détectées | 21 | 47 |

### Repères vérifiés indépendamment

Mesurés directement dans le XML (hors de l'analyseur), ils **correspondent aux
valeurs annoncées par les instructions §4** :

| Repère | Instructions | Mesure |
| --- | --- | --- |
| Signets `_Toc` (brut) | ~300 | **299** |
| Zones de texte (brut) | 26 | **26** (52 occurrences − 26 fallbacks) |
| Ancres / images en ligne | 36 / 11 | **36 / 11** |
| Champs `SEQ` | 20 | **20** |
| Plus longue série de vides | ~25 | **25** |
| Sauts de page | 8 | **8** |
| Sections | 2 (romain puis arabe) | **2**, `lowerRoman` puis décimal |

**Les titres, en revanche, ne concordent pas encore.** Le final compte 76
paragraphes en style de titre ; l'analyseur en trouve **33 par motif** sur le
brut. C'est précisément l'écart que la détection doit combler — et il est
maintenant mesurable.

### Leçon : l'instrument était faux avant d'être utile

Le premier passage de l'analyseur sur ces deux fichiers annonçait **0 signet
`_Toc`** et **aucune zone de texte**. La mesure directe du XML a montré **299**
et **26**. Trois causes, toutes corrigées :

1. les signets (`w:bookmarkStart`) ne sont pas des champs — aucun chemin ne les
   produisait ;
2. les zones de texte (`w:txbxContent`) n'étaient pas lues du tout ;
3. les images étaient comptées par paragraphe, sans distinguer `wp:inline` de
   `wp:anchor`.

**Et `mc:Fallback` doublait les zones** : 52 occurrences pour 26 zones réelles —
le défaut exact que les instructions signalent. La correction exclut désormais
les copies de fallback du DÉTAIL, pas seulement du total : un total juste avec un
détail faux reste un rapport faux.


