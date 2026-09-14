<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paramètres du chat IA — organisés en deux volets : « chat » et « argent »
    |--------------------------------------------------------------------------
    | Comme dans VS Code (où les réglages sont groupés par catégorie), les
    | paramètres du chat sont répartis en deux sections :
    |
    |   chat.*   → comportement de la conversation (historique, compression,
    |              mode d'exécution des outils)
    |   argent.* → tout ce qui touche aux crédits (coût des pièces jointes,
    |              ajustement du coût réel)
    |
    | Un « mode d'exécution » contrôle l'utilisation des outils par le modèle,
    | comme le sélecteur Chat / Agent de VS Code Copilot :
    |
    |   mode = agent → (DÉFAUT) les outils sont OBLIGATOIRES dès que
    |                   disponibles : le modèle doit appeler un outil
    |                   (tool_choice: required) et l'exécution est
    |                   automatique. Idéal pour modifier un document joint,
    |                   le convertir en PDF, générer un Word/PDF… C'est le
    |                   comportement par défaut : AUCUN sélecteur n'est
    |                   exposé dans l'UI (le mode se gère automatiquement).
    |   mode = auto   → les outils sont proposés au modèle, qui décide seul de
    |                   les utiliser (mode économique)
    |   mode = chat   → conversation pure : les outils sont désactivés
    |
    | Le mode est paramétrable par variable d'environnement (CHAT_MODE).
    */

    /*
    |--------------------------------------------------------------------------
    | Volet « chat » — comportement de la conversation
    |--------------------------------------------------------------------------
    */

    /*
    | Mode d'exécution des outils (auto | chat | agent).
    | agent : (défaut) outils obligatoires (tool_choice: required) + exécution
    |         automatique — comportement invisible, aucune option dans l'UI.
    | auto  : le modèle décide (outils proposés).
    | chat  : conversation pure, pas d'outils.
    */
    'mode' => env('CHAT_MODE', 'agent'),

    /*
    |--------------------------------------------------------------------------
    | Compression du contexte de conversation
    |--------------------------------------------------------------------------
    | Pour ne pas trop consommer de crédits sur les longues conversations,
    | le contexte envoyé au modèle est compressé :
    |   - history_messages : nombre maximum de messages récents inclus
    |     (les plus anciens sont résumés ou élagués)
    |   - history_max_chars   : budget maximal de caractères pour l'historique
    |     (les messages les plus anciens au-delà de ce budget sont élagués)
    |   - attachment_max_chars : budget maximal de caractères pour le contenu
    |     des pièces jointes injecté dans le contexte
    |
    | Le résumé est produit par le modèle lui-même (voir
    | ChatContextCompressor), ce qui évite de perdre les informations clés
    | des premiers échanges tout en maîtrisant la taille du prompt.
    */
    'history_messages' => (int) env('CHAT_HISTORY_MESSAGES', 10),
    'history_max_chars' => (int) env('CHAT_HISTORY_MAX_CHARS', 12000),
    'attachment_max_chars' => (int) env('CHAT_ATTACHMENT_MAX_CHARS', 8000),

    /*
    |--------------------------------------------------------------------------
    | Coût des pièces jointes (crédits)
    |--------------------------------------------------------------------------
    | Chaque pièce jointe ajoutée à la conversation est traitée (validation,
    | stockage privé, extraction de texte) puis son contenu est injecté dans
    | le contexte IA. Ce traitement a un coût en crédits, en plus du coût du
    | message lui-même.
    |
    |   attachments_cost_credits : coût fixe par pièce jointe (crédits)
    |
    | Le coût des PJ est inclus dans l'estimation affichée avant envoi et
    | débité avec le message (jamais de débit séparé).
    */
    'attachments_cost_credits' => (int) env('CHAT_ATTACHMENT_COST_CREDITS', 1),

    /*
    |--------------------------------------------------------------------------
    | Volet « argent » — coûts et facturation en crédits
    |--------------------------------------------------------------------------
    */

    /*
    | Ajustement du coût réel (crédits)
    |   - adjust_to_actual : si true, quand le coût réel du message est
    |     inférieur à l'estimation, la différence est remboursée (défaut).
    |     Si false, l'estimation reste acquise (mode forfaitaire simple).
    |   - overshoot_absorbed : si true, un coût réel supérieur à l'estimation
    |     est absorbé (jamais de débit supplémentaire). Si false, l'utilisateur
    |     est débité de l'écart (à utiliser avec prudence).
    */
    'adjust_to_actual' => (bool) env('CHAT_ADJUST_TO_ACTUAL', true),
    'overshoot_absorbed' => (bool) env('CHAT_OVERSHOOT_ABSORBED', true),

    /*
    | Boucle d'exécution des outils (mode agent)
    |   - tool_loop_max_turns : nombre maximum de tours d'outils consécutifs
    |     (un tour = le modèle appelle un outil → exécution → le modèle
    |     répond). Évite qu'un agent parte en boucle infinie.
    |   - tool_choice_required : si true (mode agent), le payload envoie
    |     tool_choice: 'required' pour OBLIGER le modèle à appeler un outil.
    */
    'tool_loop_max_turns' => (int) env('CHAT_TOOL_LOOP_MAX_TURNS', 5),
    'tool_choice_required' => (bool) env('CHAT_TOOL_CHOICE_REQUIRED', true),
];
