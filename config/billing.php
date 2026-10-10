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
    | Paliers de recharge de crédits — MONTANTS FIXES
    |--------------------------------------------------------------------------
    |
    | **Pourquoi des montants fixés et non une saisie libre.** Une passerelle
    | mobile money comme Monetbil exige un SERVICE déclaré par offre : chaque
    | article a son propre nom, ses pays d'activation et ses propres clés. Un
    | formulaire acceptant n'importe quel montant serait donc IMPOSSIBLE à
    | rattacher à un service — il faudrait un service par montant possible, soit
    | une infinité. En fixant la liste, chaque palier correspond à UN service, et
    | l'encaissement reste possible.
    |
    | C'est aussi une simplification pour l'utilisateur : trois choix lisibles
    | plutôt qu'un champ numérique où il doit deviner un montant accepté.
    |
    | **1 crédit = 1 FCFA.** Le montant du palier EST le nombre de crédits, ce qui
    | rend la grille immédiatement compréhensible.
    |
    | **Chaque palier porte une CLÉ de configuration** (`service`), et non un
    | libellé : c'est elle qui désigne le service Monetbil à utiliser pour ce
    | montant. `null` signifie « pas encore rattaché à un service » — l'interface
    | ne doit alors PAS proposer ce palier, sous peine de mener le client vers un
    | encaissement impossible.
    |
    | `populaire` met un palier en avant : c'est le montant le plus choisi, et le
    | signaler oriente le choix sans le contraindre.
    |
    */

    'credit_packs' => [
        [
            'montant' => 1000,
            'credits' => 1000,
            'libelle' => 'Découverte',
            'description' => 'Pour un document court ou quelques corrections.',
            'populaire' => false,
            'service' => env('MONETBIL_SERVICE_PACK_1000'),
        ],
        [
            'montant' => 3000,
            'credits' => 3000,
            'libelle' => 'Mémoire',
            'description' => 'De quoi traiter un rapport de stage complet.',
            'populaire' => true,
            'service' => env('MONETBIL_SERVICE_PACK_3000'),
        ],
        [
            'montant' => 5000,
            'credits' => 5000,
            'libelle' => 'Projet',
            'description' => 'Plusieurs documents, ou un mémoire volumineux.',
            'populaire' => false,
            'service' => env('MONETBIL_SERVICE_PACK_5000'),
        ],
    ],

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
