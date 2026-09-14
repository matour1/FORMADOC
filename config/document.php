<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pipeline documentaire (refonte)
|--------------------------------------------------------------------------
| Réglages du nouveau pipeline d'ingestion (voir REFONTE_ARCHITECTURE.md et
| PLAN_REFONTE.md). Ces options pilotent la COEXISTENCE entre l'ancien pipeline
| (`app/DocAnalyzer`) et le nouveau (`app/Document`) pendant la migration.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flag du nouveau pipeline
    |--------------------------------------------------------------------------
    | `pipeline.v2` active le parseur OOXML natif de la refonte.
    |
    | Valeurs acceptées :
    |  - false : ancien pipeline (`app/DocAnalyzer`, lecture PHPWord) — DÉFAUT,
    |            car il est couvert par les tests et éprouvé en production ;
    |  - true  : nouveau pipeline (`app/Document`, parseur OOXML natif) ;
    |  - 'auto' : le document est traité par les deux, et le nouveau est retenu
    |            seulement si sa conversion réussit (bascule silencieuse sur
    |            l'ancien en cas d'échec). Recommandé pendant la transition.
    |
    | Le flag permet une bascule INSTANTANÉE en cas de régression, sans
    | déploiement : c'est la garantie de sécurité de la migration.
    */
    'pipeline' => [
        'v2' => env('DOCUMENT_PIPELINE_V2', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistance de la structure
    |--------------------------------------------------------------------------
    | `persist_structural` écrit le JSON structurel dans
    | `document_structures.structural_json` en plus du format historique.
    |
    | Les deux colonnes COEXISTENT volontairement (principe du strangleur) :
    | l'ancienne n'est jamais supprimée avant que la migration soit confirmée
    | en production, ce qui rend le retour arrière toujours possible.
    */
    'persist_structural' => env('DOCUMENT_PERSIST_STRUCTURAL', true),

    /*
    |--------------------------------------------------------------------------
    | Traitement des sources reconstruites
    |--------------------------------------------------------------------------
    | Un document issu d'un PDF scanné passe par l'OCR : sa structure est
    | « reconstruite », ce qui N'EST PAS une identité garantie. Ce réglage
    | force l'aperçu avant/après avec validation utilisateur, même quand la
    | confiance de classification est élevée (REFONTE_ARCHITECTURE.md §6).
    */
    'require_visual_review_for_reconstructed' => true,

    /*
    |--------------------------------------------------------------------------
    | Seuil de confiance de classification
    |--------------------------------------------------------------------------
    | Sous ce seuil, un bloc déclenche une clarification ciblée auprès de
    | l'utilisateur (`ask_user_clarification`) au lieu d'être classé d'office.
    |
    | Un seuil trop bas laisse passer des erreurs de structure ; un seuil trop
    | haut génère des questions inutiles. 0,85 est la valeur retenue par la
    | spécification (§7).
    */
    'confidence_threshold' => (float) env('DOCUMENT_CONFIDENCE_THRESHOLD', 0.85),

    /*
    |--------------------------------------------------------------------------
    | Plafond de dépense IA pour la classification
    |--------------------------------------------------------------------------
    | Décision D4 : double garde-fou. Le seuil par utilisateur s'impute sur son
    | quota IA existant ; le seuil global protège la trésorerie de la
    | plateforme. Au franchissement, le pipeline bascule en mode 100 %
    | déterministe — jamais de blocage (règle du projet).
    */
    'classification_budget' => [
        'max_credits_per_document' => (int) env('DOCUMENT_CLASSIFICATION_MAX_CREDITS', 5),
        'monthly_global_limit_usd' => (float) env('DOCUMENT_CLASSIFICATION_GLOBAL_LIMIT_USD', 50.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules optionnels
    |--------------------------------------------------------------------------
    | Chaque adaptateur d'entrée est activable indépendamment. Les adaptateurs
    | externes (Google Docs, OCR) sont désactivés par défaut : ils exigent des
    | credentials et une décision de budget (décisions D1 et D2 du plan).
    */
    'adapters' => [
        'docx' => true,
        'gdocs' => false,
        'pdf_text' => false,
        'ocr' => false,
    ],

];
