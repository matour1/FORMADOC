---
paths:
  - 'app/DocAnalyzer/**'
---

# Doc Analyzer

## Échappement XML obligatoire : PHPWord écrit cru par défaut
**Défaut de production corrigé le 2026-09-17.** PHPWord écrit le texte via `writeRaw()` quand l'échappement est désactivé — son réglage **par défaut**. Un `&` ou un `<` dans le document source produisait donc un `word/document.xml` INVALIDE (`xmlParseEntityRef: no name`, `StartTag: invalid element name`), et **Word refuse d'ouvrir un tel fichier**.

Mesure sur les documents réels : **19 fichiers sur 51** étaient concernés. Les caractères en cause sont banals dans un mémoire (« Hebergement & nom de Domaine », « Prix < 1 000 »).

Correction : `Settings::setOutputEscapingEnabled(true)` autour de `$writer->save()`, dans un `try/finally` (le réglage est **global et statique** — ne jamais le laisser modifié).

Les champs (sommaire `addTOC`, numérotation) ne sont PAS affectés : ils passent par des appels XMLWriter directs (`startElement`/`text`), pas par `writeText()`.

Garde-fou : `tests/Unit/Services/DocumentGeneration/XmlEscapingTest.php` valide le XML produit par `DOMDocument::loadXML`, et vérifie que le texte reste LISIBLE (pas de double échappement : l'utilisateur doit lire « & », pas « &amp; »).
