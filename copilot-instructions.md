# Instructions pour GitHub Copilot — Projet de mise en forme automatique de rapports académiques

Lis ce fichier avant toute suggestion de code sur ce projet. Le cahier des charges complet est dans `/CAHIER_DES_CHARGES.md` et le plan de développement dans `/PLAN_DEVELOPPEMENT.md` — consulte-les pour le contexte détaillé avant de concevoir une nouvelle fonctionnalité.

> **Refonte en cours** : l'architecture cible (JSON structurel commun, tools `detect_blocks` /
> `ask_user_clarification`, renumérotation, renvois croisés, ledger de facturation au coût réel)
> est documentée dans **`/REFONTE_ARCHITECTURE.md`**. Lire ce document avant toute intervention
> sur le pipeline d'ingestion ou de traitement des documents.

## Contexte en une phrase

Application web qui prend un rapport académique (stage/projet/mémoire), détecte sa structure (titres, figures, tableaux), et génère un .docx mis en forme selon les normes de l'établissement de l'étudiant.

## Règles impératives

1. **Ne jamais utiliser un LLM pour un problème qu'une règle simple (regex, logique déterministe) peut résoudre.** Exemple concret déjà tranché : les légendes de figures/tableaux/annexes suivent toujours le format `Mot N: texte` — utiliser une expression régulière, jamais un appel LLM, pour cette détection. Le LLM n'est justifié QUE pour la détection de la hiérarchie des titres (variabilité réelle des styles de numérotation).

2. **Sécurité par défaut, pas ajoutée après coup.** Ce projet gère des documents académiques potentiellement sensibles (mémoires de recherche non publiés). Toute route qui accepte un upload de fichier doit valider le type MIME réel (pas juste l'extension), limiter la taille, et scanner le contenu avant traitement. Toute requête SQL doit utiliser des requêtes préparées, jamais de concaténation de chaîne.

3. **Ne jamais faire d'hypothèse sur le mécanisme de certification d'établissement.** Ce point est explicitement non résolu (voir section 5 du cahier des charges). Si une tâche touche à la gestion des comptes établissement, s'arrêter et demander confirmation du mécanisme avant de concevoir le schéma de base de données ou les rôles.

4. **Les titres régénérés utilisent toujours les styles de titre natifs du format DOCX (Heading 1/2/3...), jamais des zones de texte positionnées.** Idem pour les images : toujours insérées en ligne dans le flux du texte, jamais en ancrage flottant avec habillage. C'est une décision de conception délibérée pour éviter les problèmes de rendu entre versions de Word/LibreOffice — ne pas la remettre en cause sans revalider avec le porteur du projet.

5. **Respecter le découpage : détection (titres via LLM, légendes via regex) → structuration (arbre de document en interne) → application du gabarit (style) → génération du fichier final.** Ce sont quatre étapes distinctes avec des responsabilités séparées. Ne pas mélanger la détection de structure et l'application du style dans la même fonction/classe.

6. **Toute fonctionnalité listée comme "non résolue" ou "non testée" dans le cahier des charges (section 9) doit être signalée explicitement dans la suggestion de code, pas silencieusement implémentée avec une hypothèse arbitraire.**

7. **Workflow Git : une fonctionnalité = une branche feature.** Chaque nouvelle fonctionnalité doit être développée sur sa propre branche `feature/<nom>` (créée depuis `main`), puis fusionnée dans `main` (merge ou PR). Ne jamais committer directement sur `main` pour une nouvelle fonctionnalité — seuls les correctifs et l'hygiène peuvent atterrir directement sur `main`.

8. **Ne jamais modifier `routes/web.php`.** Les routes supplémentaires vivent dans `routes/saas.php` / `routes/auth.php` (chargés via `bootstrap/app.php`).

9. **PHPWord ne lit JAMAIS un document source** (interdit par la refonte §14.2). Il sert **uniquement à écrire** le `.docx` de sortie. La lecture passe par un parseur OOXML natif (`ZipArchive` + `DOMDocument`) ou les API Google Docs / OCR pour les PDF scannés.

10. **Le chat ne modifie jamais le document rendu** — uniquement les blocs du JSON structurel, puis re-génération complète. Sa liste blanche de tools est stricte (`rewrite_paragraph`, `insert_block`, `modify_table`, `delete_block`, `regenerate_section`, `ask_user_clarification`) — aucun autre tool autorisé.

11. **Ordre de ré-export obligatoire** : gabarit → renumérotation → génération des listes. Jamais de TOC/listes avant la pagination finale.

12. **Ledger de facturation au coût réel** : logger les tokens `usage` réels, compter les retries, appliquer la marge sur le coût calculé (jamais un forfait), prévoir le remboursement d'échec **partiel**.

13. **Signalement discret, pas de blocage** : une résolution de renvoi croisé ambiguë (`resolution_confidence < 0.7`) est listée dans le rapport de fin de traitement — jamais de `ask_user_clarification` bloquant pour ce cas.

14. **Lire `/REFONTE_ARCHITECTURE.md`** avant toute modification du pipeline d'ingestion, de classification, de numérotation ou de facturation.

## Stack

- Back-end : PHP avec Laravel. Utiliser Eloquent pour toutes les requêtes (jamais de SQL brut sans requête préparée), les Form Requests pour la validation des uploads, et les policies Laravel pour les autorisations dès qu'il y aura des rôles (étudiant / futur représentant établissement).
- Front-end : Bootstrap, JS léger (pas de framework JS lourd).
- Base de données : SQL relationnelle, schéma normalisé.
- Génération DOCX : bibliothèque PHP à valider en Phase 1 (voir plan de développement).
- LLM : API DeepSeek (deepseek-v4-flash), utilisé uniquement pour la détection de hiérarchie de titres.
- Paiement : KPay (Phase 6, non implémenté en V1).

## Style de code attendu

- Code commenté en français, mais cohérent dans tout le fichier.
- Fonctions courtes, à responsabilité unique — privilégier la lisibilité à la concision, ce projet sera probablement repris/étendu.
- Toute logique de détection (regex des légendes, règles de bascule de pagination) doit être isolée dans des fonctions testables indépendamment, pas noyée dans le contrôleur.
