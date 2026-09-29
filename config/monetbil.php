<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Monetbil — passerelle mobile money (MTN, Orange, Moov…)
    |--------------------------------------------------------------------------
    |
    | **Pourquoi un second fournisseur, et pas seulement KPay.** KPay est resté
    | plusieurs jours entièrement indisponible (site en maintenance) pendant le
    | développement des liens de paiement. Un dispositif qui ne sait encaisser
    | qu'auprès d'un fournisseur perd la vente quand ce fournisseur tombe —
    | c'est arrivé, et c'est mesuré.
    |
    | **Ce que Monetbil apporte de différent.** KPay initie un paiement côté
    | serveur (`POST /payments/init`) puis renvoie une `gatewayUrl` éphémère.
    | Monetbil fonctionne par URL de widget signée : on construit une URL de
    | paiement à partir du montant et des références, et le client y est
    | redirigé. Les deux modèles coexistent donc dans une même abstraction
    | (`PaymentGatewayRegistry`), sans que le reste du code ait à les connaître.
    |
    | **Ce qu'on n'a PAS copié du SDK officiel.** Le SDK `Monetbil/monetbil-php`
    | désactive la vérification SSL (`CURLOPT_SSL_VERIFYPEER, 0`) et stocke sa
    | configuration dans des propriétés statiques globales. Les deux sont des
    | défauts : le premier expose à une attaque par interposition entre nous et
    | la passerelle (donc à une modification du montant ou des références), le
    | second rend le client intestable. On interroge l'API par `Http` de Laravel,
    | qui vérifie les certificats, et l'on n'a donc besoin d'AUCUN paquet
    | supplémentaire — `composer.json` reste inchangé.
    |
    */

    // Clé de service et secret, fournis par https://www.monetbil.com/services
    'service_key' => env('MONETBIL_SERVICE_KEY', ''),
    'service_secret' => env('MONETBIL_SERVICE_SECRET', ''),

    /*
    | Version du widget. `v2.1` est celle qui renvoie une `payment_url` par API
    | et fonctionne en affichage responsive. `v1` construit une URL de plus.
    | On ne garde que la version moderne : supporter les deux multiplierait les
    | chemins de test pour un format que Monetbil ne documente plus.
    */
    'widget_version' => env('MONETBIL_WIDGET_VERSION', 'v2.1'),

    // URL de base du widget (la version et la clé de service y sont ajoutées)
    'widget_url' => env('MONETBIL_WIDGET_URL', 'https://www.monetbil.com/widget/'),

    // API de vérification d'un paiement (source d'autorité sur le statut)
    'check_payment_url' => env('MONETBIL_CHECK_PAYMENT_URL', 'https://api.monetbil.com/payment/v1/checkPayment'),

    /*
    | Devise et langue. XAF au Cameroun, XOF ailleurs dans la zone CEMOA.
    | `locale` est l'affichage du widget : « fr » ou « en ».
    */
    'currency' => env('MONETBIL_CURRENCY', 'XAF'),
    'locale' => env('MONETBIL_LOCALE', 'fr'),

    /*
    | Montant minimum. Les opérateurs mobile money prélèvent des frais fixes :
    | sous un certain montant, la transaction coûte plus qu'elle ne rapporte.
    | Le seuil est distinct de celui de KPay, car les grilles tarifaires des
    | deux passerelles ne sont pas les mêmes.
    */
    'min_amount' => (int) env('MONETBIL_MIN_AMOUNT', 500),

    // Timeout HTTP et nombre de tentatives (mêmes valeurs que KPay : mêmes
    // contraintes réseau, la latence d'un opérateur mobile étant le facteur
    // dominant).
    'timeout' => (int) env('MONETBIL_TIMEOUT', 30),
    'retries' => (int) env('MONETBIL_RETRIES', 3),

    /*
    | Fenêtre d'acceptation d'une notification entrante, en minutes.
    |
    | **La signature Monetbil ne porte PAS d'horodatage** — elle est le MD5 du
    | secret concaténé aux valeurs triées par clé. Il n'y a donc rien dans la
    | requête qui permette de rejeter un rejeu : la même notification reste
    | indéfiniment valide et une signature capturée peut être réémise telle
    | quelle. La protection contre le double versement repose donc entièrement
    | sur l'idempotence du règlement (le lien ne se règle qu'une fois), et non
    | sur l'anti-rejeu de la signature. Ce réglage documente la fenêtre au-delà
    | de laquelle une notification est ignorée comme probablement périmée.
    */
    'notification_ttl_minutes' => (int) env('MONETBIL_NOTIFICATION_TTL_MINUTES', 60),

    /*
    | Statuts Monetbil. Le SDK définit deux jeux de valeurs : les statuts réels
    | (1 / 0 / -1) et ceux du mode test (7 / 8 / 9). Un paiement de test réussi
    | vaut 7, PAS 1 : confondre les deux ferait échouer toute la phase de
    | recette, le statut de test n'étant jamais reconnu comme un succès.
    */
    'status' => [
        'success' => 1,
        'failed' => 0,
        'cancelled' => -1,
        'success_testmode' => 7,
        'failed_testmode' => 8,
        'cancelled_testmode' => 9,
    ],
];
