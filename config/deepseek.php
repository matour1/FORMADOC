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

    /*
    |--------------------------------------------------------------------------
    | Timeout dynamique selon la taille du document
    |--------------------------------------------------------------------------
    | Le modèle "raisonne" et le temps de réponse croît avec la longueur du
    | texte envoyé. Un timeout fixe est donc insuffisant (cURL error 28).
    |
    | Le timeout réel est calculé à l'exécution :
    |   timeout = base + (chars * per_char)
    | ex. 180 + (15000 * 0.008) = 300 s pour un rapport de 15 000 caractères.
    |
    | bornes : timeout_min <= timeout <= timeout_max
    */
    'timeout' => [
        'base' => env('DEEPSEEK_TIMEOUT_BASE', 180), // secondes — incompressible (modèle avec raisonnement)
        'per_char' => env('DEEPSEEK_TIMEOUT_PER_CHAR', 0.008), // secondes par caractère envoyé
        'min' => env('DEEPSEEK_TIMEOUT_MIN', 120), // secondes — jamais en dessous
        'max' => env('DEEPSEEK_TIMEOUT_MAX', 600), // secondes — jamais au-dessus (10 min)
    ],

    /*
    |--------------------------------------------------------------------------
    | Tentatives et intervalles de vérification
    |--------------------------------------------------------------------------
    | En cas de timeout ou d'erreur réseau, on relance avec des délais
    | progressifs (backoff) et un timeout de plus en plus large :
    |   tentatives : timeout_base, +50% à chaque tentative (plafonné à max)
    |   délai entre tentatives : 2s, 4s, 8s... (intervalles de vérification)
    |
    | retry_delays_ms  : liste des délais d'attente AVANT chaque nouvelle tentative
    |                    (index 0 = délai avant la 2e tentative, etc.)
    | max_retries      : nombre de tentatives supplémentaires après la 1re
    */
    'max_retries' => env('DEEPSEEK_MAX_RETRIES', 2), // jusqu'à 3 tentatives au total
    'retry_delay' => env('DEEPSEEK_RETRY_DELAY', 2000), // millisecondes — délai de base
    'retry_delays_ms' => [2000, 4000, 8000, 15000], // intervalles progressifs entre tentatives
    'timeout_growth' => 1.5, // multiplicateur du timeout à chaque tentative
];
