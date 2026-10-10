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

    /*
    | Clés de service : compatibilité legacy, pas la source de vérité.
    |
    | **Le bon modèle est service-par-service.** Chaque service Monetbil a son
    | propre identifiant, sa propre clé et son propre secret. Un compte peut en
    | héberger plusieurs, donc il ne faut pas un unique couple global pour tous
    | les packs. La source de vérité est le tableau `monetbil.services` ci-dessous,
    | indexé par palier — c'est LUI que lit la signature, à l'initiation comme à la
    | vérification des notifications.
    |
    | **Pas de table en base.** Une version intermédiaire stockait ces identifiants
    | dans une table `payment_services` éditable depuis l'administration, mais la
    | signature lisait toujours cette configuration : la table affichait des clés
    | sans effet. Elle a été retirée (voir `PaymentGatewayRegistry`).
    */
    'service_key' => env('MONETBIL_SERVICE_DEFAULT_KEY', env('MONETBIL_SERVICE_KEY', '')),
    'service_secret' => env('MONETBIL_SERVICE_DEFAULT_SECRET', env('MONETBIL_SERVICE_SECRET', '')),

    /*
    | Méthode HTTP de notification attendue.
    |
    | **Réglage côté MONETBIL, et son défaut piégeux.** L'onglet « Configuration
    | du service » laisse choisir GET ou POST, et affiche **GET par défaut**. Notre
    | route accepte les deux (`Route::match(['get', 'post'], ...)`) précisément
    | parce que ce choix ne nous appartient pas : ne router que POST ferait qu'une
    | notification GET ne trouverait aucune route, et le client serait débité sans
    | jamais être crédité.
    |
    | Cette clé ne pilote PAS le routage (les deux verbes sont acceptés) — elle
    | sert à DOCUMENTER ce qui est réglé côté Monetbil, et à journaliser un écart
    | si un jour on veut détecter une configuration inattendue.
    */
    'notification_method' => env('MONETBIL_NOTIFICATION_METHOD', 'GET'),

    /*
    | Identifiants des SERVICES Monetbil, un par palier de recharge.
    |
    | **Pourquoi un service par palier, avec SES PROPRES clés.** Monetbil exige un
    | service déclaré par offre : chaque service a son nom, son article, ses pays
    | d'activation ET ses propres identifiants. Un montant libre serait donc
    | inencaissable — il faudrait un service par montant possible. En fixant les
    | paliers (voir `config/billing.php` → `credit_packs`), chaque montant
    | correspond à un service existant, et chaque service signe avec SA clé.
    |
    | **Un identifiant de service n'est PAS une clé.** `id` désigne le service chez
    | Monetbil ; `key` et `secret` sont les identifiants de signature que CE service
    | utilise. Les confondre — un seul couple global pour tous les packs — rendrait
    | impossible l'encaissement sur un second service, puisque la signature d'un
    | service ne peut pas être vérifiée avec le secret d'un autre.
    |
    | Tant qu'un service n'est pas renseigné, le palier correspondant n'est PAS
    | proposé à l'utilisateur — mieux vaut un palier absent qu'un bouton qui mène à
    | un encaissement impossible.
    */
    'services' => [
        'pack_1000' => [
            'id' => env('MONETBIL_SERVICE_PACK_1000'),
            'key' => env('MONETBIL_SERVICE_PACK_1000_KEY'),
            'secret' => env('MONETBIL_SERVICE_PACK_1000_SECRET'),
        ],
        'pack_3000' => [
            'id' => env('MONETBIL_SERVICE_PACK_3000'),
            'key' => env('MONETBIL_SERVICE_PACK_3000_KEY'),
            'secret' => env('MONETBIL_SERVICE_PACK_3000_SECRET'),
        ],
        'pack_5000' => [
            'id' => env('MONETBIL_SERVICE_PACK_5000'),
            'key' => env('MONETBIL_SERVICE_PACK_5000_KEY'),
            'secret' => env('MONETBIL_SERVICE_PACK_5000_SECRET'),
        ],
    ],

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
