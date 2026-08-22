<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Configuration facturation Phase 9
    |--------------------------------------------------------------------------
    | Devises multiples (Q5b), renouvellement automatique (Q1a),
    | accès Skills documentaires (Q4) et factures PDF (Q3a).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Devises acceptées
    |--------------------------------------------------------------------------
    | La première devise de la liste est la devise par défaut.
    | 'symbol' : affichage. 'rate_fcfa' : 1 unité de la devise = X FCFA.
    | Les prix des plans sont stockés en FCFA (price_fcfa) ; l'affichage et
    | le paiement peuvent être convertis dans la devise choisie.
    |
    */

    'currencies' => [
        'XAF' => ['symbol' => 'FCFA', 'rate_fcfa' => 1.0, 'decimals' => 0],
        'EUR' => ['symbol' => '€', 'rate_fcfa' => (float) env('BILLING_RATE_EUR', 655.957), 'decimals' => 2],
        'USD' => ['symbol' => '$', 'rate_fcfa' => (float) env('BILLING_RATE_USD', 620), 'decimals' => 2],
    ],

    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'XAF'),

    /*
    |--------------------------------------------------------------------------
    | Renouvellement automatique (Q1a)
    |--------------------------------------------------------------------------
    | SUBSCRIPTION_RENEW_DAYS_BEFORE : nombre de jours avant expiration où le
    |   renouvellement KPay est déclenché (le template : « Renouvellement
    |   automatique le 15/09/2026 »).
    | SUBSCRIPTION_GRACE_DAYS : jours de grâce après un paiement échoué avant
    |   passage au statut expired.
    |
    */

    'auto_renew' => (bool) env('SUBSCRIPTION_AUTO_RENEW', true),
    'renew_days_before' => (int) env('SUBSCRIPTION_RENEW_DAYS_BEFORE', 3),
    'grace_days' => (int) env('SUBSCRIPTION_GRACE_DAYS', 5),

    /*
    |--------------------------------------------------------------------------
    | Factures (Q3a)
    |--------------------------------------------------------------------------
    | Stockage des PDF générés.
    |
    */

    'invoice_storage_disk' => env('INVOICE_STORAGE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Skills documentaires (Q4)
    |--------------------------------------------------------------------------
    | SKILLS_MIN_PLAN : slug du plan minimal pour accéder aux Skills Claude
    |   (docx/xlsx/pptx/pdf) inclus dans l'abonnement.
    | SKILLS_NO_SUBSCRIPTION_MULTIPLIER : majoration du coût en crédits quand
    |   l'utilisateur n'a pas d'abonnement payant (pay-per-use ×1,5).
    |
    */

    'skills_min_plan' => env('SKILLS_MIN_PLAN', 'standard'),
    'skills_no_subscription_multiplier' => (float) env('SKILLS_NO_SUBSCRIPTION_MULTIPLIER', 1.5),

    /*
    |--------------------------------------------------------------------------
    | Prorata (Q2a)
    |--------------------------------------------------------------------------
    | Au changement de plan, créditer le prorata des jours restants sur
    | l'ancien abonnement (remboursement crédits).
    |
    */

    'prorata' => true,
];
