<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Anthropic API Configuration (Skills documentaires — expérimental)
    |--------------------------------------------------------------------------
    |
    | Clé API Anthropic (jamais exposée côté client — serveur uniquement).
    | Endpoint : POST /v1/messages (API Messages) + /v1/files (download).
    |
    | Cette intégration est EXPÉRIMENTALE et réservée aux abonnés Pro.
    | Elle permet de générer des documents natifs Office (docx, xlsx, pptx,
    | pdf) via les Skills documentaires Claude (container skills).
    |
    */

    'api_key' => env('ANTHROPIC_API_KEY', ''),
    'api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1'),

    /*
    |--------------------------------------------------------------------------
    | Modèle et version des Skills
    |--------------------------------------------------------------------------
    */

    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    'betas' => ['skills-2025-10-02'],
    'code_execution_tool' => 'code_execution_20260521',

    /*
    |--------------------------------------------------------------------------
    | Skills activés
    |--------------------------------------------------------------------------
    | docx, xlsx, pptx, pdf (IDs officiels Anthropic)
    */

    'skills' => [
        'docx' => ['type' => 'anthropic', 'skill_id' => 'docx', 'version' => 'latest'],
        'xlsx' => ['type' => 'anthropic', 'skill_id' => 'xlsx', 'version' => 'latest'],
        'pptx' => ['type' => 'anthropic', 'skill_id' => 'pptx', 'version' => 'latest'],
        'pdf' => ['type' => 'anthropic', 'skill_id' => 'pdf', 'version' => 'latest'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Coûts (crédits)
    |--------------------------------------------------------------------------
    | Le conteneur d'exécution est facturé 0,05 USD/heure avec un minimum de
    | 5 minutes par exécution. On applique ensuite le coefficient de
    | rentabilité (infrastructure + marge 40-60 %) : le prix public est donc
    | nettement supérieur au coût direct API.
    |
    | credit_multiplier : multiplie le coût estimé du modèle par exécution
    | pour couvrir le conteneur + la marge. Valeur par défaut 2.0 (×2).
    */

    'credit_multiplier' => (float) env('ANTHROPIC_CREDIT_MULTIPLIER', 2.0),
    'container_cost_per_minute' => 0.05 / 60, // 0,05 $/h
    'container_min_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Robustesse
    |--------------------------------------------------------------------------
    | retry_backoff_ms : délais de retry exponentiels (429 / 529).
    | max_retries : nombre de tentatives avant abandon.
    | timeout_seconds : timeout HTTP par requête.
    | fallback_internal : si true, en cas d'échec des Skills Claude, on
    | bascule automatiquement vers les outils internes (PHPWord).
    */

    'max_retries' => 3,
    'retry_backoff_ms' => [1000, 2000, 4000],
    'timeout_seconds' => 300,
    'fallback_internal' => true,
];
