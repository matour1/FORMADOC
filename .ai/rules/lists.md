---
paths:
  - 'app/Document/Lists/**'
---

# Lists

## Pagination exacte : LibreOffice UNO, pas d'extraction PDF
PHPWord **ne pagine pas** : aucune information de numéro de page. La pagination réelle s'obtient via **LibreOffice en écoute UNO** (Python embarqué) — solution établie par mesure, voir `PaginationCalculator`.

Ce qui NE fonctionne PAS (testé, ne pas retenter) :
- `pdftotext`, `pdftoppm`, Ghostscript, `mutool` : **absents** de la machine ;
- PDF → TXT par LibreOffice : exit code 1 ;
- parser les flux PDF à la main (`/Length`, gzuncompress) : texte encodé par police, 0 flux exploitable ;
- `dompdf` : écriture seule (adapter CPDF) ;
- `XPageCursor.getPage()` depuis Python : `RuntimeException`, interface non exposée ;
- chargement UNO **visible** (`Hidden=False`) : fait tomber le pont (`DisposedException`).

Ce qui FONCTIONNE : `getCurrentController().getViewCursor()` + `getPosition().Y` donne une position verticale **absolue en 1/100 mm**, puis `page = intdiv(y, pageHeight) + 1` avec `pageHeight = PageStyles(0).Height` (29700 pour un A4).

Pièges d'exploitation (obligatoires) :
- soffice met **10-15 s** à ouvrir le port → attendre en boucle, ne jamais conclure trop tôt ;
- `soffice.bin` résiduel **bloque le port** → tuer les processus avant ;
- `-env:UserInstallation=<profil unique>` obligatoire, sinon collision ;
- un **seul** document à la fois en mode caché, et toujours `doc.close(False)`.`;

## Verrou d'ordre : aucune liste avant la pagination
**Verrou d'ordre, non négociable** : `RenderCoordinator::generate()` / `tableOfContents()` / `categoryLists()` lèvent `ListsGenerationException` si `paginate()` n'a pas été appelé avant.

Raison : insérer un sommaire **décale la pagination de tout ce qui suit** (le sommaire occupe lui-même plusieurs pages). Des numéros calculés trop tôt seraient donc faux — et une TOC fausse passe la relecture, contrairement à une erreur explicite.

Deux modes :
- défaut : pagination indisponible → listes générées **sans numéros**, signalées par `QualityReport` (document livrable) ;
- `['require_pagination' => true]` : pagination exigée → exception si indisponible.

Corollaire : ne jamais inventer un numéro de page. Une liste sans numéros est préférable à une liste avec des numéros faux.

Coût : **zéro token** — tout se déduit du JSON structurel (titres, numéros de R4, pages de R5.2). Verrouillé par `ArchitectureConstraintsTest::test_le_module_des_listes_ne_contient_aucun_appel_ia`.

## Sommaire 2 niveaux en tete, table des matieres tous niveaux en fin
Regles de mise en forme d'un rapport (proprietaire) :
- **Sommaire** : natif Word, niveaux 1-2 SEULEMENT, en PREMIERE page, doit tenir sur une page.
- **Table des matieres** : natif Word, TOUS les niveaux, en FIN de document (apres conclusion et annexes).
- **Chaque piece liminaire** sur sa propre page : dedicace, remerciements, avant-propos, listes (figures/tableaux/annexes), resume, abstract, introduction.
- Les listes d'abreviations/sigles saisies dans un tableau restent telles quelles.

`TableOfContentsGenerator::MAX_DEPTH` vaut 3 et sert la TABLE DES MATIERES. Le sommaire utilise `addTOC(null, null, 1, 2)` dans `DocumentReconstructor` — les deux ne doivent PAS partager la meme borne : un sommaire a 3 niveaux deborde sur plusieurs pages.

Les sauts de page sont decides par `PageBreakRules` (classe testable) et transmis sur `element['page_break']` ; `DocumentReconstructor` applique `addPageBreak()`. Un saut MANUEL, pas `pageBreakBefore` : Word recalcule ce dernier a chaque ouverture.
