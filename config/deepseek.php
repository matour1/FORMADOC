<?php

return [
    /*
    |--------------------------------------------------------------------------
    | DeepSeek API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration pour l'intégration API DeepSeek (modèle deepseek-v4-flash)
    | Utilisé pour la détection automatique de la hiérarchie des titres
    |
    */

    'api_key' => env('DEEPSEEK_API_KEY', ''),
    'api_url' => env('DEEPSEEK_API_URL', 'https://api.deepseek.com/v1'),
    'model' => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
    'timeout' => env('DEEPSEEK_TIMEOUT', 30), // secondes
    'max_retries' => env('DEEPSEEK_MAX_RETRIES', 3),
    'retry_delay' => env('DEEPSEEK_RETRY_DELAY', 100), // millisecondes
];
