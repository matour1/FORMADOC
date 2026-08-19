<?php

return [
    /*
    |--------------------------------------------------------------------------
    | KPay API Configuration
    |--------------------------------------------------------------------------
    |
    | Intégration paiements mobile money (Orange Money / MTN / Moov…) et
    | passerelle carte. URL de base UNIQUE — le mode test/prod est déterminé
    | par le préfixe des clés (kpay_test_/sk_test_ = sandbox, kpay_live_/sk_live_ = prod).
    |
    */

    'api_key' => env('KPAY_API_KEY', ''),
    'secret_key' => env('KPAY_SECRET_KEY', ''),
    'webhook_secret' => env('KPAY_WEBHOOK_SECRET', ''),

    // URL de base de l'API v1
    'base_url' => env('KPAY_BASE_URL', 'https://admin.kpay.site/api/v1'),

    // Montant minimum d'achat de crédits en FCFA (XAF/XOF)
    'min_amount' => (int) env('KPAY_MIN_AMOUNT', 500),

    // Devise par défaut (XAF Cameroun / XOF autres pays CEMOA)
    'currency' => env('KPAY_CURRENCY', 'XAF'),

    // Méthodes de paiement : ussd (mobile money) ou gateway (carte/PayPal)
    'default_payment_method' => env('KPAY_DEFAULT_PAYMENT_METHOD', 'gateway'),

    // Taux de change USSD acceptés (en FCFA), réservé à l'affichage
    'exchange_rate' => (float) env('KPAY_EXCHANGE_RATE', 620),

    // Timeout HTTP (secondes)
    'timeout' => (int) env('KPAY_TIMEOUT', 30),

    // Nombre de tentatives sur erreurs réseau / 429
    'retries' => (int) env('KPAY_RETRIES', 3),

    // Fenêtre de validité de la signature du retour passerelle (minutes)
    'signature_ttl_minutes' => 10,
];
