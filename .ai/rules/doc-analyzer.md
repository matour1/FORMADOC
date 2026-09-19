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

## DeepSeek : utiliser deepseek-chat et un format de sortie compact
Le modèle `deepseek-v4-flash` explicite est un modèle à RAISONNEMENT : il brûle tout `max_tokens` en `reasoning_content` → `content` vide sur les gros documents. Utiliser `deepseek-chat` (alias non-raisonnant, même modèle V4-Flash, supporte json_object + tool_calls).

Le prompt doit exiger un format COMPACT : uniquement les positions `[[section_index, element_index, parent], …]`, jamais le texte des éléments (sinon ~150 K chars de réponse, 130-200 s, JSON tronqué/invalide par intermittence). PHP reconstruit les textes via `buildPositionMap()` + `hydrate()`.

Toujours passer `max_tokens` (défaut 65536 ; v4-flash supporte jusqu'à 384K de sortie) : sans cela DeepSeek tronque le JSON (~8K tokens).
