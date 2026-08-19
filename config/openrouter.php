<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OpenRouter API Configuration
    |--------------------------------------------------------------------------
    |
    | Clé API OpenRouter (jamais exposée côté client — serveur uniquement).
    | Endpoint OpenAI-compatible : POST /api/v1/chat/completions
    |
    */

    'api_key' => env('OPENROUTER_API_KEY', ''),
    'api_url' => env('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1'),
    'app_name' => env('APP_NAME', 'FORMADOC'),
    'app_url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Routage par type de tâche et par plan
    |--------------------------------------------------------------------------
    | Chaque plan liste les modèles autorisés DANS L'ORDRE DE PRÉFÉRENCE :
    |   - le premier modèle de la liste est le modèle préféré
    |   - les suivants sont les fallbacks en cas d'échec / indisponibilité
    |
    | Plans : default (gratuit), standard, premium, pro, enterprise
    |
    | Prix (USD par 1M tokens, collectés le 19/08/2026 sur OpenRouter) :
    |   deepseek/deepseek-chat            : 0,2574 / 1,029
    |   meta-llama/llama-3.1-8b-instruct  : 0,02 / 0,04
    |   openai/gpt-4o                     : 2,50 / 10,00
    |   openai/gpt-4o-mini                : 0,15 / 0,60
    |   anthropic/claude-3.5-sonnet       : 3,00 / 15,00
    |   anthropic/claude-3-opus           : 15,00 / 75,00
    |   openai/gpt-image-1-mini           : 0,035 / image
    |   openai/gpt-image-1                : 0,08 / image
    */

    'tasks' => [
        'chat_text' => [
            'default' => ['deepseek/deepseek-chat', 'meta-llama/llama-3.1-8b-instruct'],
            'standard' => ['deepseek/deepseek-chat', 'meta-llama/llama-3.1-8b-instruct'],
            'premium' => ['deepseek/deepseek-chat', 'anthropic/claude-3.5-sonnet'],
            'pro' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'enterprise' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
        ],
        'document_analysis' => [
            'default' => ['deepseek/deepseek-chat', 'meta-llama/llama-3.1-8b-instruct'],
            'standard' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'premium' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'pro' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'enterprise' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
        ],
        'document_full_format' => [
            'default' => ['deepseek/deepseek-chat'],
            'standard' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'premium' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'pro' => ['anthropic/claude-3-opus', 'anthropic/claude-3.5-sonnet'],
            'enterprise' => ['anthropic/claude-3-opus', 'anthropic/claude-3.5-sonnet'],
        ],
        'image_generation' => [
            'default' => ['openai/gpt-image-1-mini'],
            'standard' => ['openai/gpt-image-1-mini'],
            'premium' => ['openai/gpt-image-1'],
            'pro' => ['openai/gpt-image-1'],
            'enterprise' => ['openai/gpt-image-1'],
        ],
        'image_analysis' => [
            'default' => ['openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet'],
            'standard' => ['openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet'],
            'premium' => ['openai/gpt-4o', 'anthropic/claude-3.5-sonnet'],
            'pro' => ['openai/gpt-4o', 'anthropic/claude-3.5-sonnet'],
            'enterprise' => ['openai/gpt-4o', 'anthropic/claude-3.5-sonnet'],
        ],
        'web_search' => [
            'default' => ['openai/gpt-4o-mini', 'perplexity/llama-3.1-sonar-huge'],
            'standard' => ['openai/gpt-4o-mini', 'perplexity/llama-3.1-sonar-huge'],
            'premium' => ['openai/gpt-4o-mini', 'perplexity/llama-3.1-sonar-huge'],
            'pro' => ['openai/gpt-4o-mini', 'perplexity/llama-3.1-sonar-huge'],
            'enterprise' => ['openai/gpt-4o-mini', 'perplexity/llama-3.1-sonar-huge'],
        ],
        'function_calling' => [
            'default' => ['deepseek/deepseek-chat', 'openai/gpt-4o-mini'],
            'standard' => ['anthropic/claude-3.5-sonnet', 'openai/gpt-4o-mini'],
            'premium' => ['anthropic/claude-3.5-sonnet', 'openai/gpt-4o-mini'],
            'pro' => ['anthropic/claude-3.5-sonnet', 'openai/gpt-4o'],
            'enterprise' => ['anthropic/claude-3.5-sonnet', 'openai/gpt-4o'],
        ],
        'powerpoint_generation' => [
            'default' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'standard' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'premium' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'pro' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
            'enterprise' => ['anthropic/claude-3.5-sonnet', 'deepseek/deepseek-chat'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prix unitaires (USD par 1M tokens ou par image)
    |--------------------------------------------------------------------------
    | Utilisés pour estimer le coût AVANT exécution et calculer le coût réel
    | après usage. À maintenir à jour avec les prix OpenRouter.
    */

    'pricing' => [
        'deepseek/deepseek-chat' => ['input' => 0.2574, 'output' => 1.029],
        'meta-llama/llama-3.1-8b-instruct' => ['input' => 0.02, 'output' => 0.04],
        'openai/gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'openai/gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'anthropic/claude-3.5-sonnet' => ['input' => 3.00, 'output' => 15.00],
        'anthropic/claude-3-opus' => ['input' => 15.00, 'output' => 75.00],
        'openai/gpt-image-1-mini' => ['image' => 0.035],
        'openai/gpt-image-1' => ['image' => 0.08],
        'perplexity/llama-3.1-sonar-huge' => ['input' => 1.00, 'output' => 1.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout dynamique selon la taille de la requête
    |--------------------------------------------------------------------------
    | Même logique que config/deepseek.php : le timeout croît avec la longueur
    | du texte envoyé pour absorber la latence des modèles sur gros documents.
    |
    |   timeout = base + (chars * per_char)  borné par [min, max]
    */

    'timeout' => [
        'base' => env('OPENROUTER_TIMEOUT_BASE', 180),
        'per_char' => env('OPENROUTER_TIMEOUT_PER_CHAR', 0.008),
        'min' => env('OPENROUTER_TIMEOUT_MIN', 120),
        'max' => env('OPENROUTER_TIMEOUT_MAX', 600),
    ],

    'max_retries' => env('OPENROUTER_MAX_RETRIES', 2),
    'retry_delays_ms' => [2000, 4000, 8000, 15000],
    'timeout_growth' => 1.5,

    /*
    |--------------------------------------------------------------------------
    | Marge de sécurité sur le coût réel
    |--------------------------------------------------------------------------
    | S'applique au coût brut estimé pour couvrir les frais de transaction,
    | les échecs partiels et la volatilité des prix. 0.20 = +20 %.
    */

    'cost_margin' => 0.20,

    /*
    |--------------------------------------------------------------------------
    | Taux de conversion USD → FCFA
    |--------------------------------------------------------------------------
    | Utilisé par UsageCostCalculator : 1 crédit = 1 FCFA.
    | La valeur par défaut (620) est une hypothèse conservatrice.
    */

    'rate_fcfa_per_usd' => (float) env('OPENROUTER_RATE_FCFA_PER_USD', 620),
];
