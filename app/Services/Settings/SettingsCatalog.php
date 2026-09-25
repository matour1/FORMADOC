<?php

declare(strict_types=1);

namespace App\Services\Settings;

/**
 * Catalogue des réglages modifiables depuis l'administration.
 *
 * **Pourquoi un catalogue en code plutôt qu'une table ouverte.** Un réglage n'est
 * pas une donnée libre : il a un type, une plage de valeurs plausible, et surtout
 * un EFFET sur le prix payé par l'utilisateur. Un formulaire qui accepte n'importe
 * quelle clé et n'importe quelle valeur permet de saisir une marge de 5000 %, de
 * supprimer un réglage que le code lit, ou d'écrire un type incohérent — sans
 * aucun signal. Le catalogue définit donc ce qui est réglable, ce qui n'est pas
 * une limite imposée à l'exploitant mais la liste de ce qui a été RENDU réglable,
 * avec ses bornes.
 *
 * **Chaque entrée doit avoir un effet réel.** `config_key` pointe vers une
 * configuration qui existe et qui est LUE quelque part. Un réglage qui ne change
 * rien à l'exécution serait un mensonge d'interface : l'exploitant croirait avoir
 * modifié un comportement.
 */
final class SettingsCatalog
{
    /**
     * Groupes affichés dans l'écran de configuration, dans l'ordre.
     *
     * @var array<string, string>
     */
    public const GROUPS = [
        'billing' => 'Facturation et rentabilité',
        'payments' => 'Paiements et encaissement',
        'platform' => 'Plateforme',
    ];

    /**
     * Réglages proposés à la modification.
     *
     * `config_key` est le nom lu par l'application : c'est lui qui est surchargé
     * au démarrage, donc modifier la ligne en base suffit à changer le
     * comportement, sans redéploiement.
     *
     * @return array<string, array{
     *     key: string,
     *     type: string,
     *     group: string,
     *     label: string,
     *     description: string,
     *     default: mixed,
     *     min?: float,
     *     max?: float,
     *     step?: float,
     *     unit?: string
     * }>
     */
    public static function all(): array
    {
        return [
            // --- Rentabilité -------------------------------------------------
            'cost_margin' => [
                'key' => 'openrouter.cost_margin',
                'type' => 'float',
                'group' => 'billing',
                'label' => 'Marge appliquée',
                'description' => 'Part ajoutée au coût direct pour assurer la pérennité du service. '
                    .'0,60 = +60 %. Combinée au coût d\'infrastructure, elle forme le coefficient '
                    .'de rentabilité.',
                'default' => 0.60,
                'min' => 0,
                'max' => 5,
                'step' => 0.05,
            ],
            'cost_infrastructure' => [
                'key' => 'openrouter.cost_infrastructure',
                'type' => 'float',
                'group' => 'billing',
                'label' => 'Coût d\'infrastructure',
                'description' => 'Majoration couvrant serveur, stockage et support. 0,15 = +15 %.',
                'default' => 0.15,
                'min' => 0,
                'max' => 5,
                'step' => 0.05,
            ],
            'rate_fcfa_per_usd' => [
                'key' => 'openrouter.rate_fcfa_per_usd',
                'type' => 'float',
                'group' => 'billing',
                'label' => 'Taux de change (FCFA par USD)',
                'description' => 'Utilisé pour convertir le coût des appels IA (facturé en dollars '
                    .'par les fournisseurs) en crédits. Un écart avec le taux réel se répercute '
                    .'directement sur la marge.',
                'default' => 620.0,
                'min' => 1,
                'max' => 5000,
                'step' => 0.01,
                'unit' => 'FCFA',
            ],

            // --- Paiements ---------------------------------------------------
            'min_amount' => [
                'key' => 'kpay.min_amount',
                'type' => 'int',
                'group' => 'payments',
                'label' => 'Montant minimum d\'achat',
                'description' => 'En FCFA. En dessous, les frais de transaction dépassent la marge.',
                'default' => 500,
                'min' => 0,
                'max' => 1_000_000,
                'step' => 1,
                'unit' => 'FCFA',
            ],
            'currency' => [
                'key' => 'kpay.currency',
                'type' => 'string',
                'group' => 'payments',
                'label' => 'Devise des encaissements',
                'description' => 'Code ISO à trois lettres (XAF au Cameroun, XOF ailleurs). '
                    .'Elle détermine le pays et les opérateurs proposés par la passerelle.',
                'default' => 'XAF',
            ],
            'exchange_rate' => [
                'key' => 'kpay.exchange_rate',
                'type' => 'float',
                'group' => 'payments',
                'label' => 'Taux de change affiché (USSD)',
                'description' => 'Taux indicatif montré à l\'utilisateur pour les paiements mobile '
                    .'money. N\'a pas d\'effet sur le montant débité.',
                'default' => 620.0,
                'min' => 1,
                'max' => 5000,
                'step' => 0.01,
                'unit' => 'FCFA',
            ],
            'link_ttl_days' => [
                'key' => 'payments.link_ttl_days',
                'type' => 'int',
                'group' => 'payments',
                'label' => 'Validité d\'un lien de paiement',
                'description' => 'En jours. Au-delà, le lien n\'est plus honoré et l\'utilisateur '
                    .'voit une page d\'expiration explicite — un lien mort sans explication est '
                    .'un appel au support.',
                'default' => 7,
                'min' => 1,
                'max' => 365,
                'step' => 1,
                'unit' => 'jours',
            ],

            // --- Plateforme --------------------------------------------------
            'auto_renew' => [
                'key' => 'billing.auto_renew',
                'type' => 'bool',
                'group' => 'platform',
                'label' => 'Renouvellement automatique des abonnements',
                'description' => 'Désactiver arrête les tentatives de renouvellement et les relances '
                    .'de paiement pour tous les abonnements.',
                'default' => true,
            ],
            'grace_days' => [
                'key' => 'billing.grace_days',
                'type' => 'int',
                'group' => 'platform',
                'label' => 'Jours de grâce après échec de paiement',
                'description' => 'Délai avant passage d\'un abonnement au statut expiré quand le '
                    .'renouvellement échoue. Couper l\'accès le jour même d\'un échec technique '
                    .'pénalise un client de bonne foi.',
                'default' => 5,
                'min' => 0,
                'max' => 90,
                'step' => 1,
                'unit' => 'jours',
            ],
            'renew_days_before' => [
                'key' => 'billing.renew_days_before',
                'type' => 'int',
                'group' => 'platform',
                'label' => 'Anticipation du renouvellement',
                'description' => 'Nombre de jours avant expiration où la tentative de '
                    .'renouvellement est déclenchée.',
                'default' => 3,
                'min' => 0,
                'max' => 60,
                'step' => 1,
                'unit' => 'jours',
            ],
            'skills_no_subscription_multiplier' => [
                'key' => 'billing.skills_no_subscription_multiplier',
                'type' => 'float',
                'group' => 'platform',
                'label' => 'Majoration sans abonnement',
                'description' => 'Coefficient appliqué au coût des fonctionnalités IA pour un '
                    .'utilisateur sans abonnement payant (paiement à l\'usage). 1,50 = +50 %.',
                'default' => 1.5,
                'min' => 1,
                'max' => 10,
                'step' => 0.1,
            ],

            'prorata' => [
                'key' => 'billing.prorata',
                'type' => 'bool',
                'group' => 'platform',
                'label' => 'Remboursement au prorata',
                'description' => 'Lors d\'un changement de plan, créditer la valeur des jours '
                    .'restants sur l\'ancien abonnement. Désactiver supprime tout remboursement.',
                'default' => true,
            ],
        ];
    }

    /**
     * Définitions d'un groupe, dans l'ordre du catalogue.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function group(string $group): array
    {
        return array_filter(
            self::all(),
            static fn (array $definition): bool => $definition['group'] === $group
        );
    }

    /**
     * Nom de configuration surchargé par un réglage.
     */
    public static function configKey(string $key): ?string
    {
        return self::all()[$key]['key'] ?? null;
    }
}
