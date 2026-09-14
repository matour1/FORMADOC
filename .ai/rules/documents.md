---
paths:
  - 'resources/views/documents/**'
---

# Documents

## Interface d'annulation : visible seulement s'il y a un historique
Le bloc « Annuler une modification » (R6 §9.7) est affiché **uniquement si `$undoHistory` n'est pas vide**. Un bloc visible sans rien à annuler laisserait croire à une fonctionnalité en panne.

Le contrôleur `DocumentController::show()` passe `undoHistory` (via `DocumentEditingService::undoHistory()`), et `undoEdit()` restaure l'état choisi. Les deux méthodes appellent `authorizeDocument()` : l'annulation **modifie le contenu** d'un document, elle est donc soumise à la même vérification d'appartenance que l'affichage. Un 404 (et non 403) est renvoyé pour ne pas révéler l'existence du document — convention du projet depuis la correction IDOR.

La route `documents.undo-edit` reste **dans le groupe `middleware(['auth'])`**. Ne jamais la déplacer : elle agit sur le contenu d'autrui sinon.

Sémantique affichée à l'utilisateur : restaurer un état **remplace** le contenu actuel, et l'annulation n'est pas elle-même annulable. C'est indiqué dans la vue, car un utilisateur qui restaure un état ancien sans le savoir perdrait son travail récent.
