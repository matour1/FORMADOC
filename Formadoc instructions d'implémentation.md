# Formadoc : instructions d'implémentation pour l'agent IA

Version 1 · mode déterministe (règles, sans IA) · Laravel + PHP

## 0. Règles de travail

- Travaille **phase par phase** (sections 4 à 8). Ne passe à la phase suivante qu'une fois les tests de la phase en cours verts et un résumé fourni.
- Priorités : **ne jamais perdre de contenu** > exactitude > vitesse.
- Le traitement est **déterministe** : même fichier en entrée, même fichier en sortie. Aucun appel à une IA dans cette version.
- L'**original n'est jamais modifié**. Tout se fait sur une copie de travail.
- Quand une règle est ambiguë, **ne devine pas** : applique le comportement le plus prudent (ne rien modifier) et ajoute un avertissement au rapport utilisateur.
- Consigne chaque décision technique dans `docs/DECISIONS.md`. Pas de nouvelle dépendance sans justification écrite.
- Les points de la section 13 sont **ouverts** : implémente un défaut configurable et signale-le, ne tranche pas en silence.

## 1. Contexte

Application web Laravel (+ SQL) qui reçoit le .docx d'un rapport de stage ou d'un mémoire d'étudiant et renvoie un .docx correctement mis en forme : sommaire, table des matières, listes de légendes, numérotation de pages romaine puis arabe, en-tête et pied de page éditables, **en conservant** images, figures et éléments flottants.

Les entrées sont hétérogènes : aucun style, styles mal utilisés, sommaire tapé à la main, titres dans des zones de texte, pages séparées par des paragraphes vides.

## 2. Décisions déjà prises (à respecter)

- **Un seul traitement pour tous les rapports** : pas de classification par type (DQP, droit, mode…). La variété est gérée par la détection de ce qui est présent.
- **Pages de garde non gérées** : si le document en contient une, la détecter, la supprimer et le dire à l'utilisateur.
- **Fichiers > 50 Mo rejetés** automatiquement ; une **estimation du temps** de traitement est affichée.
- **Tous les types de légendes** (Figure, Tableau, Planche, Photo…), une liste par type, mais uniquement pour les types **connus ou prouvés par un champ de numérotation**. Un mot inconnu est **signalé**, jamais transformé en liste.
- **Sommaire** : titres de niveaux 1 et 2. **Table des matières** : tous les niveaux, **à la fin** du document. Les deux sont générés.
- Pages liminaires en **chiffres romains minuscules**, corps en **chiffres arabes à partir de 1**.
- En-tête et pied de page **éditables** (vrais en-têtes Word, pas des zones de texte). Police du document : **Times New Roman**.
- Numérotation des légendes **automatique**, comme dans Word.
- On travaille **directement sur le XML du .docx**. PHPWord n'est pas utilisé pour modifier un document existant : il reconstruit le document et perd ce qu'il ne connaît pas.

## 3. Architecture

- PHP (version du projet), `ZipArchive`, `DOMDocument` + `DOMXPath`. Parties lues ou modifiées : `word/document.xml`, `styles.xml`, `settings.xml`, `numbering.xml`, en-têtes et pieds, `[Content_Types].xml`, `*.rels`. **Toute autre partie est recopiée octet pour octet** (images, polices…).
- On ne reconstruit jamais le document : on **modifie les nœuds en place**. Une image ancrée ou en ligne suit son paragraphe.
- Espaces de noms à gérer : `w`, `wp`, `mc`, `wps`, `v` (VML), `a`, `pic`, `r`.
- `mc:AlternateContent` contient `mc:Choice` (version moderne) **et** `mc:Fallback` (copie VML). **Lecture : ignorer `Fallback`**, sinon tout est vu en double. **Suppression ou déplacement : traiter l'AlternateContent entier.**
- Le texte d'une zone de texte (`w:txbxContent`) n'est **pas** un paragraphe du corps : lecture explicite.
- Un champ Word peut être coupé sur plusieurs runs : **reconstruire l'instruction complète** (de `begin` à `separate`) avant de l'analyser.
- Organisation suggérée : `App\Docx\` avec `Package` (ouverture sûre du zip), `Analyzer` (lecture seule), `Cleaners`, `Normalizers`, `Detectors`, `Generators`, `Report`. Chaque étape reçoit un paquet et renvoie un paquet + un journal d'actions. Aucune règle en dur dans le code : listes, expressions et seuils vont dans `config/formadoc.php` (ou JSON).
- Exécution en **file d'attente Laravel** : un job par document, statut et progression en base, un seul traitement lourd à la fois.
- Prévoir une interface `HeadingClassifier` (implémentation actuelle : règles) pour brancher plus tard une IA sur les titres incertains. Ne pas l'implémenter maintenant.

## 4. Phase 1 : analyseur en lecture seule (à faire en premier)

Commande `php artisan formadoc:analyse fichier.docx --json` qui n'écrit rien dans le document et produit :

- Statistiques : paragraphes, paragraphes vides (et plus longue série consécutive), sauts de page, sections, ancres / images en ligne / zones de texte, signets `_Toc`.
- Styles utilisés, et styles définis mais jamais utilisés.
- Champs (instruction complète) : TOC, SEQ, PAGEREF, PAGE… avec leur position.
- Sommaire et listes **existants**, en champ ou **tapés** (voir 5.3).
- Zones de texte : contenu, style des paragraphes, ancre.
- Candidats légendes (avec le niveau de preuve, section 6.1) et candidats titres (niveau, règle, signaux, confiance).
- Candidat page de garde (plage de blocs).
- En-têtes et pieds : texte, présence d'un champ PAGE, références par section ; `pgNumType`, `titlePg`.

**Repères mesurés à la main** sur les fichiers de test (ordres de grandeur : recalcule et signale tout écart important) :

| Fichier | Repères |
| --- | --- |
| DQP final | 20 champs SEQ (10 Figure, 10 Tableau) ; 4 champs TOC ; 2 sections ; 19 titres de niveau 1 dans des zones de texte ; \~254 paragraphes vides (jusqu'à 25 d'affilée) ; 8 sauts de page ; 36 ancres pour 11 images en ligne |
| DQP brut | même texte que le final ; aucun paragraphe stylé ; \~247 paragraphes vides ; 26 zones de texte ; \~300 signets `_Toc` ; 20 champs SEQ |
| Droit | 1 section ; pieds de page vides ; sommaire tapé (points de suite en caractères) ; aucune légende ; page de garde en zones de texte |
| Mode | \~1300 paragraphes dont \~664 vides ; 126 images (\~40 Mo) ; styles de titre mal utilisés ; 5 champs TOC ; 0 champ SEQ ; légendes tapées (Planche, Photo, Tableau, Image) |

**Critère de réussite** : sur le DQP brut, la détection de titres retrouve au moins \~69 % des 89 titres du DQP final avec ≥ 92 % de précision (mesure actuelle des règles de texte seules), **sans doublons** dus à `mc:Fallback` et sans prendre « M. » (Monsieur) pour une numérotation.

## 5. Phase 2 : nettoyage et normalisation (sur la copie)

**5.1 Contrôles d'entrée et copie de travail.** Voir section 10. L'original reste intact.

**5.2 Page de garde.** Le candidat est tout ce qui précède le premier titre liminaire reconnu (liste en configuration : sommaire, dédicace, remerciements, avant-propos, sigles, listes, résumé, abstract, introduction) ou, à défaut, le premier titre détecté. Suppression de tous les blocs concernés, y compris zones de texte et ancres.

Garde-fous : si le candidat dépasse 2 pages (seuil configurable), ne rien supprimer et avertir. Une dédicace sans titre peut se trouver là : mentionner dans l'avertissement le début du texte supprimé.

**5.3 Éléments déjà générés.** Les supprimer **avant** toute détection :

- Champ `TOC` (de `begin` à `end`, paragraphes de résultat compris) et blocs `w:sdt` de type table des matières.
- Paragraphes aux styles `TOC1-9`, `TM1-9` (français), `TableofFigures`, `Tabledesillustrations`.
- Listes **tapées** : bloc d'au moins 3 lignes consécutives de la forme texte + (points de suite `.` ou `…` ou tabulation) + (nombre arabe ou romain), situé sous un titre du type SOMMAIRE, TABLE DES MATIERES, LISTE DES FIGURES, LISTE DES TABLEAUX, etc. Ne jamais appliquer ce motif à une ligne isolée : un vrai titre peut finir par un nombre.
- Supprimer aussi le titre de ces blocs : il sera régénéré.

**5.4 Titres dans des zones de texte.** Une zone de texte qui ne contient que 1 ou 2 paragraphes courts correspondant aux règles de titre ou aux titres liminaires est remplacée par un **vrai paragraphe**, inséré à la place de son paragraphe d'ancrage (texte conservé). Les zones de texte qui contiennent des schémas (plusieurs petites formes, organigrammes) ou des images sont **laissées intactes** et signalées.

**5.5 Paragraphes vides servant de séparateurs.** Série d'au moins 3 paragraphes vides consécutifs : les supprimer et poser `pageBreakBefore` sur le titre de niveau 1 / liminaire / partie / chapitre qui suit ; sinon réduire à 1. **Ne jamais supprimer** : un paragraphe qui contient une ancre, une image, un champ ou un saut de section ; le dernier paragraphe d'une cellule de tableau (obligatoire en OOXML) ; un paragraphe dans une cellule.

**5.6 Signets.** Les nouveaux signets de titres utilisent un préfixe propre (`_TocFormadoc…`) pour éviter toute collision avec les anciens.

## 6. Phase 3 : détection

### 6.1 Légendes

Par ordre de preuve décroissante :

1. **Champ `SEQ <Type>` existant** : preuve forte, type accepté. Si le paragraphe porte un style de titre (cas observé : légendes en `Titre4` qui se retrouvent dans la table des matières), **remplacer par le style Légende**.
2. **Légende tapée** : début de paragraphe `Type` + numéro (arabe, romain ou `1.2`) + séparateur (`:`, `.`, `-`, `–`), longueur < 130 caractères, **et** type connu, **et** voisinage d'un `w:drawing`, `w:pict`, forme ou `w:tbl` (légende souvent sous une figure, au-dessus d'un tableau : tester les deux côtés), **et** numéros qui se suivent pour ce type.
3. **Mot inconnu** : ne jamais générer de liste ; signaler.
4. **Exclusions permanentes** (jamais des légendes) : section, chapitre, partie, phase, étape, scénario, semaine, article.

Types connus (configuration, avec pluriel) : figure→figures, tableau→tableaux, planche→planches, photo→photos, image→images, schéma→schémas, graphique→graphiques, diagramme→diagrammes, illustration→illustrations, carte→cartes. Comparaison insensible à la casse et aux accents ; `Fig.` = `Figure`.

Conversion en vraie légende : style `Légende` (le créer s'il manque), puis ` Type  ` + champ `SEQ Type \\* ARABIC` + texte. **Une suite de numéros par type.** Mémoriser pour chaque légende `ancien numéro → nouveau`.

À signaler, jamais à corriger en silence : numéro dupliqué (deux « Figure 1 »), trou dans la suite, légende dans une zone de texte (non convertie), mot inconnu, numéros qui ne commencent pas à 1.

Les listes se comptent **après** suppression des anciennes listes (5.3) : sinon chaque légende est comptée plusieurs fois.

Optionnel (plus tard) : mettre à jour les renvois dans le texte (« voir Figure 3 ») quand un numéro change, **en une seule passe** avec des marqueurs temporaires pour éviter les remplacements en cascade.

### 6.2 Titres

Signaux combinés par un score (poids en configuration). Pour chaque titre : niveau, confiance, règle appliquée.

1. Style de titre existant (`TitreN` / `HeadingN`) sur un paragraphe du corps, **hors légendes**.
2. **Règles de texte** (les plus fiables sur les fichiers sans style) :
   - PARTIE : `^(PREMI[ÈE]RE|DEUXI[ÈE]ME|TROISI[ÈE]ME) PARTIE`
   - CHAPITRE : `^CHAPITRE\\s+[IVX\\d]+`
   - SECTION : `^SECTION\\s+[IVX\\d]+`
   - Numérotations : `I.`, `I-`, `I :`, `A.`, `A-`, `1.`, `1-`, `a-`, `a)`. Ordre de profondeur par défaut : romain > lettre majuscule > arabe > lettre minuscule, mais **déduire l'ordre réel de l'ordre d'apparition** dans le document.
   - Une seule lettre suivie d'un point (`M.`, `Dr.`) n'est **pas** une numérotation si ce qui suit est un nom propre ou s'il n'y a pas de suite cohérente.
   - Titres liminaires connus (liste en configuration).
3. Signaux de mise en forme : paragraphe entièrement en gras, court (< 120 caractères), sans point final, taille ≥ corps, centré. **Attention** : le gras sert aussi à des lignes qui ne sont pas des titres (dédicaces).
4. Signet `_Toc…` sur le paragraphe : **bonus** uniquement, jamais critère unique.
5. Contexte : ligne courte suivie d'un paragraphe de corps.

Niveaux par défaut (voir 13) : liminaires, Introduction, Conclusion, Références, **Partie** et **Chapitre** = 1 ; **Section** = 2 ; `I.` = 3 ; `A.` et `1.` = 4 ; niveaux suivants = 5 et plus. Résultat : le sommaire (niveaux 1-2) contient parties, chapitres, sections et liminaires ; la table des matières contient tout.

Un titre sous le seuil de confiance est marqué **« à valider »** (point d'entrée de la future IA) ; il n'est ni appliqué ni mis dans la table des matières.

Mesure actuelle des règles de texte seules sur le DQP : 61 titres retrouvés sur 89 (69 %), 5 faux positifs (4 doublons dus aux zones de texte, 1 « M. »). Les 28 manquants sont presque tous des sous-titres sans numéro (« Mission », « Objectif », « Les clients »…).

## 7. Phase 4 : génération

**7.1 Styles.** S'assurer que `Titre1…Titre9`, `Légende`, `TM1…TM9` (ou `TOC1…`) et `Table des illustrations` existent dans `styles.xml` (les créer sinon). Police Times New Roman dans les styles et `docDefaults`. Ne pas écraser les polices à chasse fixe (Courier New, Consolas) dans le corps.

**7.2 Application des titres.** Style de niveau N sur chaque titre retenu. `pageBreakBefore` pour : titres liminaires, Introduction, Partie, Chapitre, Conclusion, Références, Table des matières (configurable).

**7.3 Blocs générés**, chacun sur sa page :

- **Sommaire** en première page : titre « SOMMAIRE » puis champ `TOC \\o \"1-2\" \\h \\z \\u`.
- **Une liste par type de légende trouvé** : « LISTE DES FIGURES », « LISTE DES TABLEAUX », « LISTE DES PLANCHES »… avec `TOC \\h \\z \\c \"Figure\"` (l'identifiant doit être identique à celui des champs `SEQ`). Aucune liste si aucune légende de ce type.
- **Table des matières** à la fin du document : `TOC \\o \"1-9\" \\h \\z \\u`.
- Chaque champ : `fldChar begin` (avec `w:dirty=\"true\"`), `instrText`, `fldChar separate`, texte provisoire, `fldChar end`. Ajouter `w:updateFields` dans `settings.xml`.

**7.4 Ordre** (à confirmer, voir 13) : sommaire, dédicace, remerciements, avant-propos, sigles, listes de légendes, résumé, abstract, introduction générale… Par défaut, **ne pas réordonner** le contenu de l'étudiant : insérer les blocs générés aux positions canoniques et signaler tout écart d'ordre.

**7.5 Sections et numérotation.** Deux sections. Section 1 (du sommaire à la page qui précède l'introduction) : `pgNumType` format `lowerRoman`, début à 1. Section 2 (de l'introduction à la fin) : format `decimal`, début à 1. Le `sectPr` d'une section non finale est dans le `pPr` de son dernier paragraphe ; le dernier est enfant direct de `body`. Référencer explicitement les en-têtes et pieds dans les deux sections.

**7.6 En-tête et pied** (vrais paragraphes, pas de zones de texte) :

- En-tête : titre du rapport en gras, centré, filet bleu dessous.
- Pied : filet bleu dessus ; à gauche « RÉDIGÉ EN VUE D'ÊTRE PRÉSENTÉ PAR : \<nom> » ; à droite le champ `PAGE` (tabulation alignée à droite). Le texte dans **un seul run** : le fichier de référence le coupe en plusieurs, ce qui casse les remplacements.
- Titre et nom viennent de **paramètres fournis par l'utilisateur**, pas déduits du document (à confirmer, voir 13). Couleur du filet et tailles en configuration.
- Créer les parties `header*.xml` et `footer*.xml`, leurs relations et les entrées de `[Content_Types].xml`.

## 8. Phase 5 : mise à jour des champs (expérimental)

Les numéros de page d'un sommaire dépendent de la mise en page réelle : le PHP ne peut pas les calculer. LibreOffice en ligne de commande peut le faire, mais **la conversion simple n'actualise pas les index** : il faut une macro qui lance la mise à jour des index avant l'export, avec des chemins absolus.

**Piège d'architecture** : si LibreOffice **réenregistre** le .docx, il reconstruit tout le document et peut dégrader les éléments flottants, ce qui contredit le principe de la section 3. Mesurer avant de choisir :

- **Option A** : livrer le .docx réenregistré par LibreOffice. Comparer avant/après : nombre d'ancres, d'images, de zones de texte, aspect.
- **Option B** (recommandée a priori) : exporter en PDF avec LibreOffice, **lire la page de chaque titre et de chaque légende**, puis écrire ces valeurs dans les résultats en cache des champs du **.docx produit par le XML**, en gardant `dirty` pour que Word recalcule. La pagination de LibreOffice peut différer de celle de Word d'une page : le signaler.

Contraintes : copie temporaire du fichier ; profil LibreOffice unique par job (`-env:UserInstallation`) ; un seul job à la fois ; délai maximal et arrêt forcé des processus `soffice` restants ; en conteneur Docker, risque de blocage silencieux, à tester. Ne pas bloquer les phases 1 à 4 sur celle-ci.

## 9. Rapport à l'utilisateur

Produire un rapport structuré (JSON, affiché par l'interface) :

- Corrections appliquées, avec leur nombre.
- **Avertissements** : page de garde supprimée (pages + début du texte), légendes non reconnues (position), mots de légende inconnus, numéros dupliqués ou manquants, titres « à valider », zones de texte laissées intactes, ordre inhabituel des pages liminaires.
- Lien vers l'**original**.

Jamais de correction silencieuse.

## 10. Sécurité et limites

- **≤ 50 Mo** compressé : contrôle côté serveur (obligatoire) et côté navigateur (confort). Régler `upload_max_filesize` et `post_max_size` au-dessus de 50 Mo (par exemple 64M) et la limite de corps de requête du serveur web.
- **Bombe de décompression** : à l'ouverture, refuser si la taille décompressée totale ou le nombre d'entrées dépasse un seuil configurable. La limite de 50 Mo ne suffit pas.
- Valider : extension `.docx`, signature zip, présence de `[Content_Types].xml` et `word/document.xml`. Refuser les `.docm`.
- XML : charger avec `LIBXML_NONET`, **sans** `LIBXML_NOENT` ni chargement de DTD (XXE). Lire les entrées par nom (`getFromName`), jamais `extractTo` avec des noms non vérifiés (zip slip).
- Les documents d'étudiants sont des données personnelles : stockage **privé**, noms aléatoires, **suppression automatique** après un délai configurable. Les fichiers de test ne vont **jamais** dans un dépôt public.
- **Estimation du temps** : à partir de mesures (pages, paragraphes, images) étalonnées sur les fichiers de test. Afficher une fourchette, pas une promesse.

## 11. Tests

- Fixtures privées (non versionnées publiquement) : les 4 documents ci-dessus.
- Tests unitaires de chaque détecteur sur de petits .docx synthétiques construits à partir de chaînes XML.
- **Idempotence** : passer la sortie dans le pipeline une seconde fois ne doit rien changer.
- **Non-perte** : mêmes images (nombre de relations `blip`), mêmes ancres, même texte du corps hors éléments volontairement supprimés (page de garde, anciens sommaires et listes).
- **Paire DQP** : sortie du DQP brut comparée au DQP final (liste des titres, légendes, champs présents).
- **Validité** : XML bien formé, relations résolues, ouverture sans erreur par LibreOffice (conversion PDF).
- Mesurer rappel et précision de la détection de titres à chaque changement de règles.

## 12. Livraison attendue, phase par phase

Pour chaque phase : code, tests, `docs/DECISIONS.md` à jour, résumé court (ce qui est fait, ce qui reste, risques) et **exemple de sortie réel** sur au moins un fichier de test.

## 13. Points à confirmer par le responsable du projet

1. **Ordre des listes** : le fichier de référence met figures avant tableaux ; la description initiale disait tableaux avant figures. Défaut : ordre du fichier de référence, configurable.
2. **Niveaux** : Partie et Chapitre au niveau 1, Section au niveau 2 (reproduit le sommaire du fichier de référence). À valider.
3. **Réordonnancement** des pages liminaires : défaut = ne pas réordonner, signaler.
4. **Titre et nom** de l'en-tête et du pied : saisis dans l'application ? Défaut : paramètres du formulaire.
5. **Renvois dans le texte** après renumérotation : mise à jour automatique ou simple signalement ? Défaut : signalement.
6. **Stratégie finale pour les numéros de page** (option A ou B, section 8) : décision après mesure.
