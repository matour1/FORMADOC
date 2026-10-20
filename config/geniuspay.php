<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | GeniusPay — passerelle mobile money et carte (Wave, Orange, MTN, Stripe…)
    |--------------------------------------------------------------------------
    |
    | **Pourquoi une troisième passerelle.** KPay et Monetbil ciblent la zone
    | CEMOA mais ont chacun connu des indisponibilités. GeniusPay ajoute Wave
    | (SN, CI, ML, BF), M-Pesa (KE) et les cartes internationales via Stripe.
    | Le registre de passerelles garde les trois indépendantes : en désactiver
    | une n'affecte pas les autres.
    |
    | **Ce que GeniusPay fait différemment.** Son endpoint de paiement renvoie une
    | `checkout_url` HÉBERGÉE : on redirige le client, il choisit son moyen sur
    | la page GeniusPay, puis on reçoit un webhook. C'est le même modèle que
    | KPay (passerelle hébergée), donc le code d'initiation se ressemble — mais
    | l'authentification et la signature du webhook diffèrent totalement.
    |
    */

    /*
    | Identifiants d'API.
    |
    | Le mode (sandbox ou live) est déterminé par le PRÉFIXE des clés :
    |   pk_sandbox_… / sk_sandbox_…  → sandbox (transactions simulées)
    |   pk_live_…    / sk_live_…     → production
    |
    | **Les noms de variables suivent la documentation OFFICIELLE du SDK**
    | (`geniuspay.ci/docs/sdk`) : `GENIUSPAY_API_KEY`, `GENIUSPAY_API_SECRET`,
    | `GENIUSPAY_SANDBOX`. La première version utilisait `…_SECRET_KEY`, qui
    | n'existe pas dans la doc — un déploiement qui suivait la doc aurait laissé
    | le secret vide, et la passerelle aurait été déclarée « non configurée ».
    |
    | **Pourquoi la clé de configuration s'appelle `secret_key` en interne.**
    | `PaymentGatewayRegistry` résout les identifiants génériquement :
    | `{prefixe}.api_key` et `{prefixe}.secret_key`. Le NOM DE LA VARIABLE
    | d'environnement suit la doc du fournisseur ; le nom de la CLÉ DE CONFIG
    | suit la convention interne. Les deux sont distincts, et c'est voulu.
    */
    'api_key' => env('GENIUSPAY_API_KEY', ''),
    'secret_key' => env('GENIUSPAY_API_SECRET', ''),

    /*
    | Mode sandbox.
    |
    | Le SDK officiel expose `GENIUSPAY_SANDBOX`, mais nos appels REST directs
    | n'en ont pas besoin : c'est le PRÉFIXE des clés qui détermine le mode côté
    | serveur GeniusPay. Ce drapeau sert à l'affichage et à la journalisation —
    | savoir qu'on est en sandbox évite de chercher pourquoi aucun argent
    | n'arrive. Il n'est jamais utilisé pour choisir une URL.
    */
    'sandbox' => (bool) env('GENIUSPAY_SANDBOX', false),

    /*
    | URL de base de l'API marchand.
    |
    | **`pay.genius.ci`, et non `geniuspay.ci`.** La documentation du SDK
    | officiel donne très explicitement `https://pay.genius.ci/api/v1/merchant`.
    | Les deux hôtes répondent aujourd'hui (vérifié : 401 MISSING_API_KEY), donc
    | une erreur ici ne provoquerait aucun échec visible — elle pointerait vers
    | l'hôte de secours, ou vers rien du tout le jour où celui-ci change. On
    | suit donc la doc du fournisseur.
    */
    'base_url' => env('GENIUSPAY_BASE_URL', 'https://pay.genius.ci/api/v1/merchant'),

    /*
    | Secret de signature du webhook (`whsec_…`).
    |
    | **Un secret PAR endpoint**, affiché une seule fois à sa création côté
    | GeniusPay. Il sert à vérifier `X-Webhook-Signature`, qui est un
    | HMAC-SHA256 de `timestamp . "." . payload` — le PAYLOAD BRUT, tel que reçu.
    |
    | Conséquence technique : le contrôleur doit lire `php://input` AVANT toute
    | désérialisation. Un `$request->all()` puis `json_encode()` produirait une
    | chaîne différente (ordre des clés, espaces, échappements) et la signature
    | ne correspondrait JAMAIS. C'est l'erreur classique de cette intégration.
    */
    'webhook_secret' => env('GENIUSPAY_WEBHOOK_SECRET', ''),

    /*
    | Devise d'encaissement.
    |
    | **L'API n'accepte PAS le XAF.** Elle accepte XOF, EUR et USD ; les clients
    | hors zone XOF paient l'équivalent converti dans leur devise locale.
    |
    | Décision du responsable (2026-10-19) : **XAF = XOF à parité 1:1**. La
    | conversion est donc une identité, sans taux externe. Ce n'est pas un
    | raccourci : le franc CFA d'Afrique centrale et celui d'Afrique de l'Ouest
    | ont la même valeur nominale et sont arrimés à l'euro par le même mécanisme.
    |
    | Si un jour un taux devait s'appliquer, il se règle ici — mais changer cette
    | valeur changerait le montant RÉELLEMENT débité, donc la décision doit rester
    | explicite et documentée.
    */
    'currency' => env('GENIUSPAY_CURRENCY', 'XOF'),
    'xaf_to_xof_rate' => (float) env('GENIUSPAY_XAF_TO_XOF_RATE', 1.0),

    /*
    | Montant minimum. L'API exige 200 XOF minimum ; le seuil applicatif est plus
    | haut pour que les frais d'opérateur ne dépassent pas une part raisonnable
    | du montant. Distinct de KPay et Monetbil : chaque passerelle a sa grille.
    */
    'min_amount' => (int) env('GENIUSPAY_MIN_AMOUNT', 500),

    // Timeout HTTP et tentatives (mêmes contraintes réseau que les autres).
    'timeout' => (int) env('GENIUSPAY_TIMEOUT', 30),
    'retries' => (int) env('GENIUSPAY_RETRIES', 3),

    /*
    | Tolérance de l'horodatage du webhook, en secondes.
    |
    | **Ce que cette fenêtre protège — et ce qu'elle ne protège pas.** GeniusPay
    | signe `timestamp.payload`, et fournit le timestamp dans un en-tête séparé.
    | Un rejeu à l'identique porte le MÊME timestamp et la MÊME signature : il
    | resterait valide indéfiniment. La fenêtre limite donc la réutilisation d'une
    | capture ANCIENNE, pas un rejeu immédiat.
    |
    | La protection contre le double versement repose sur l'idempotence du crédit
    | (`CreditService::creditIfNotProcessed`, référence = `geniuspay:{reference}`),
    | jamais sur la seule signature.
    */
    'webhook_tolerance_seconds' => (int) env('GENIUSPAY_WEBHOOK_TOLERANCE', 300),

    /*
    | Statuts de transaction.
    |
    | `completed` est le seul statut qui engage un versement. `processing` et
    | `pending` sont des états transitoires : un webhook qui les annonce ne doit
    | rien créditer, car le paiement peut encore échouer.
    */
    'status' => [
        'pending' => 'pending',
        'processing' => 'processing',
        'completed' => 'completed',
        'failed' => 'failed',
        'cancelled' => 'cancelled',
        'refunded' => 'refunded',
    ],
];
