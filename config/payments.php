<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Liens de paiement
    |--------------------------------------------------------------------------
    |
    | Un lien de paiement est une URL à jeton unique qu'un administrateur génère
    | et transmet à un client (courriel, messagerie, remise en main propre) pour
    | qu'il règle un montant précis sans avoir de compte FORMADOC.
    |
    | **Pourquoi cette fonctionnalité existe.** KPay n'expose AUCUN endpoint de
    | « lien de paiement » (vérifié dans la documentation : seuls `init`,
    | `payments/:id`, `refund` et `availability` existent). Le lien est donc une
    | construction FORMADOC : une page à nous, qui mène soit à la passerelle
    | hébergée KPay (mode GATEWAY documenté), soit à un encaissement constaté
    | manuellement par un administrateur.
    |
    | **Pourquoi la seconde voie est indispensable.** Les opérateurs tombent en
    | maintenance — c'est arrivé pendant le développement même de cette
    | fonctionnalité, KPay étant resté plusieurs jours indisponible. Un lien qui
    | ne mène qu'à une passerelle morte transforme une vente en perte. La
    | constatation manuelle garantit qu'un règlement reçu par un autre canal
    | (espèces, virement, mobile money direct) est enregistré et donne les mêmes
    | crédits, sans ressaisie ni écart de comptabilité.
    |
    */

    // Durée de validité d'un lien, en jours. Surchargée par le réglage
    // `payments.link_ttl_days` (voir SettingsCatalog).
    'link_ttl_days' => (int) env('PAYMENT_LINK_TTL_DAYS', 7),

    // Préfixe du jeton, pour qu'un lien de paiement soit reconnaissable dans un
    // journal ou un historique sans avoir à interroger la base.
    'link_token_prefix' => 'pl_',

    // Longueur du jeton en caractères. 32 caractères d'alphabet base62 offrent
    // ~190 bits d'entropie : un jeton deviné donnerait accès à la page de
    // règlement d'un tiers, donc la valeur n'est pas décorative.
    'link_token_length' => 32,
];
