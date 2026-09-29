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

    /*
    |--------------------------------------------------------------------------
    | Moyens de paiement : activer, désactiver, masquer
    |--------------------------------------------------------------------------
    |
    | **Pourquoi ces trois drapeaux, et pas un seul booléen.** « Désactiver » et
    | « masquer » répondent à deux besoins différents qu'un booléen unique
    | confondrait :
    |
    |  - `actif = false` — l'exploitant refuse ce moyen. Il n'est plus utilisé,
    |    même pour un lien envoyé à la main. C'est l'interrupteur d'urgence :
    |    un fournisseur qui tombe se coupe ici, en une modification de réglage,
    |    sans redéploiement.
    |
    |  - `visible = false` — le moyen reste UTILISABLE mais n'apparaît plus dans
    |    l'interface d'achat. Cas d'usage : encaisser via un fournisseur pour des
    |    liens de paiement négociés sans proposer le moyen en libre-service (frais
    |    plus élevés, contrat en cours de renégociation, quota limité).
    |
    | Un seul booléen obligerait à choisir entre « couper le moyen » et « ne rien
    | pouvoir faire » : masquer aurait nécessité de désactiver, donc de perdre
    | aussi les encaissements manuels.
    |
    | **La configuration d'API est vérifiée séparément.** Un moyen activé mais
    | sans clé ne doit PAS être proposé : il mènerait le client vers une page
    | d'erreur, ce qui est pire qu'un moyen absent. `PaymentGatewayRegistry`
    | combine donc les trois conditions.
    |
    | **Valeurs par défaut.** `true` explicitement, et non `null`. Une première
    | version utilisait `null` pour signifier « déduire : actif si configuré ». C'était
    | une erreur de conception, et c'est l'invariant `SettingsTest::
    | test_chaque_reglage_pointe_vers_une_configuration_existante` qui l'a révélée :
    | il refuse tout réglage dont la configuration vaut `null`, parce qu'un `null` est
    | indiscernable d'une clé ABSENTE. `SettingsRepository::applyToConfig()` ignore
    | silencieusement les clés inconnues : le réglage aurait été affiché, modifiable,
    | et sans effet — le défaut exact que cet invariant existe pour empêcher.
    |
    | Avec `true` par défaut, une intégration fraîchement déployée est active et
    | affichée SANS réglage préalable, et c'est `PaymentGatewayRegistry` qui écarte
    | les moyens dont les clés d'API manquent. Le comportement voulu est donc obtenu,
    | sans sentinelle ambiguë.
    |
    | Note : `env('X', true)` retourne un vrai booléen (Laravel interprète les
    | chaînes `"true"`/`"false"`), donc pas de conversion nécessaire.
    |
    */
    'gateways' => [
        'kpay' => [
            'actif' => (bool) env('PAYMENTS_KPAY_ACTIF', true),
            'visible' => (bool) env('PAYMENTS_KPAY_VISIBLE', true),
        ],
        'monetbil' => [
            'actif' => (bool) env('PAYMENTS_MONETBIL_ACTIF', true),
            'visible' => (bool) env('PAYMENTS_MONETBIL_VISIBLE', true),
        ],
        'offline' => [
            // Jamais coupé par défaut : c'est le moyen de secours.
            'actif' => (bool) env('PAYMENTS_OFFLINE_ACTIF', true),
            'visible' => (bool) env('PAYMENTS_OFFLINE_VISIBLE', true),
        ],
    ],
];
