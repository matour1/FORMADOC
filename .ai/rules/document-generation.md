---
paths:
  - 'app/Services/DocumentGeneration/**'
---

# Document Generation

## Export DOCX : une passe suffit (le TOC est un champ natif)
`FormattedDocumentExporter` est la passerelle d'écriture DOCX : elle assemble R3 (mise en forme) → R4 (renumérotation) via `ReExportCoordinator`, contrôle l'intégrité des tableaux AVANT écriture, puis traduit vers le format de `DocumentReconstructor` (`buildReconstructionPayload`).

**Une seule passe suffit** — le générateur écrit un **champ TOC natif Word** (`addTOC` + `setUpdateFields`), donc Word calcule lui-même les numéros de page. La pagination de R5 (`RenderCoordinator::paginate`, qui exige un DOCX déjà écrit + LibreOffice) n'apporte **rien** au DOCX. Le plan initial prévoyait 2 passes sur une hypothèse fausse.

`SourceImageProvider` : le modèle structurel ne conserve qu'une **référence** d'image (`Block::imageRef` = `rId5.img`, sérialisé en `image_ref`), jamais le binaire. Le fournisseur relit le paquet source et résout la relation (le `.img` est un suffixe ajouté par l'adaptateur, à retirer). Une image introuvable produit `[Image: nom]` plutôt qu'une image inventée.

Appelé par `DocumentController::writeDocument()` : nouveau chemin si `structural_json` existe, **repli sur l'ancien pipeline** sinon (strangler).

Mesure sur 51 documents réels : 51/51 exports réussis, 0 contenu de tableau modifié, 44 avec sommaire (les 7 sans sommaire sont les 7 sans titre).
