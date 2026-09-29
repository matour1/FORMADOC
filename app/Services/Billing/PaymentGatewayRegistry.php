<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Registre des moyens de paiement proposés à l'utilisateur.
 *
 * **Le problème résolu.** Les passerelles étaient désignées par des chaînes
 * littérales dispersées (`'kpay'`, `'online'`, `'offline'`) et l'interface
 * affichait ce qui existait dans le code. Désactiver un moyen en panne demandait
 * donc de modifier et redéployer le code — or un fournisseur qui tombe est un
 * événement d'exploitation, pas une livraison. KPay est resté indisponible
 * plusieurs jours : c'est exactement le cas que ce registre doit permettre de
 * traiter sans intervention technique.
 *
 * **Trois notions distinctes, souvent confondues.** Les séparer est le cœur de
 * cette classe :
 *
 *  - **configuré** — les clés d'API sont renseignées. Un moyen non configuré ne
 *    peut pas fonctionner : l'afficher mènerait le client vers une erreur ;
 *  - **actif** — l'exploitant accepte de s'en servir. C'est l'interrupteur
 *    d'exploitation : un moyen actif mais en panne se désactive ici ;
 *  - **visible** — le moyen apparaît dans l'interface d'achat. Un moyen peut être
 *    actif sans être visible : on l'utilise pour les liens de paiement envoyés
 *    manuellement, sans le proposer en libre-service. Cacher n'est donc PAS
 *    désactiver, et confondre les deux ferait perdre la vente sans raison.
 *
 * **Ce que ce registre ne fait pas.** Il ne décide pas à la place de l'exploitant
 * et ne devine pas l'état du fournisseur : il expose ce qui a été décidé. La
 * détection automatique de panne serait un autre sujet, et une fausse détection
 * désactiverait un moyen qui fonctionne.
 */
final class PaymentGatewayRegistry
{
    /**
     * Passerelle KPay : mobile money et carte, via passerelle hébergée.
     */
    public const KPAY = 'kpay';

    /**
     * Passerelle Monetbil : mobile money, via widget signé.
     */
    public const MONETBIL = 'monetbil';

    /**
     * Règlement hors ligne constaté par un administrateur.
     *
     * Ce n'est pas une passerelle — aucun appel réseau n'est effectué — mais il
     * figure au registre : c'est un moyen de paiement au sens de l'utilisateur, et
     * il doit pouvoir être affiché ou retiré comme les autres. Le traitement
     * particulier est dans `estAutomatique()`, pas dans son absence du registre.
     */
    public const HORS_LIGNE = 'offline';

    /**
     * Clés de configuration de chaque passerelle.
     *
     * @var array<string, string>
     */
    private const PREFIXE_CONFIG = [
        self::KPAY => 'kpay',
        self::MONETBIL => 'monetbil',
    ];

    /**
     * Description de chaque moyen, dans l'ordre d'affichage.
     *
     * `configurable` indique si le moyen peut être proposé sans configuration
     * d'API. Le hors-ligne l'est toujours : il ne dépend d'aucun service externe,
     * ce qui en fait le recours quand tout le reste tombe — et c'est précisément
     * pourquoi il n'est jamais masqué par défaut.
     *
     * @return array<string, array{libelle: string, description: string, configurable: bool, automatique: bool}>
     */
    public static function tous(): array
    {
        return [
            self::KPAY => [
                'libelle' => 'Mobile Money et carte (KPay)',
                'description' => 'Orange Money, MTN, Moov et cartes bancaires, via la passerelle KPay.',
                'configurable' => true,
                'automatique' => true,
            ],
            self::MONETBIL => [
                'libelle' => 'Mobile Money (Monetbil)',
                'description' => 'Orange Money, MTN et Moov, via le widget Monetbil.',
                'configurable' => true,
                'automatique' => true,
            ],
            self::HORS_LIGNE => [
                'libelle' => 'Règlement hors ligne',
                'description' => 'Espèces, virement ou mobile money direct, constaté par un administrateur.',
                'configurable' => false,
                'automatique' => false,
            ],
        ];
    }

    /**
     * Le moyen est-il proposable, c'est-à-dire actif, visible ET configuré ?
     *
     * C'est la seule méthode que l'interface d'achat doit consulter. Elle
     * regroupe trois conditions parce qu'une seule d'entre elles ne suffit
     * jamais : un moyen actif mais non configuré mène à une erreur, un moyen
     * configuré mais inactif contourne la décision de l'exploitant.
     */
    public function estProposable(string $passerelle): bool
    {
        return $this->estActif($passerelle)
            && $this->estVisible($passerelle)
            && $this->estConfigure($passerelle);
    }

    /**
     * L'exploitant accepte-t-il d'utiliser ce moyen ?
     *
     * Défaut : `true`. Une intégration fraîchement déployée est donc active sans
     * réglage préalable, et c'est `estConfigure()` qui l'écarte si ses clés
     * d'API manquent. Exiger une seconde activation ferait paraître l'intégration
     * en panne au premier déploiement, et l'exploitant chercherait la cause dans
     * les clés.
     *
     * **Pourquoi `true` et non « déduire de la configuration ».** Une première
     * version utilisait `null` en sentinelle. C'était une erreur : `null` est
     * indiscernable d'une clé ABSENTE, et l'invariant du projet
     * (`SettingsTest::test_chaque_reglage_pointe_vers_une_configuration_existante`)
     * refuse tout réglage dont la configuration vaut `null`. Il avait raison — un
     * tel réglage serait affiché, modifiable, et sans effet, parce que
     * `SettingsRepository::applyToConfig()` ignore les clés inconnues.
     */
    public function estActif(string $passerelle): bool
    {
        // `=== false` et non un cast direct : on veut un booléen strict, et une
        // clé absente doit valoir `true` (défaut), jamais `false` par conversion
        // d'un `null`.
        return config('payments.gateways.'.$passerelle.'.actif') !== false;
    }

    /**
     * Le moyen apparaît-il dans l'interface d'achat ?
     *
     * Défaut : `true`. Le cas « actif mais masqué » est un choix délibéré — garder
     * un moyen utilisable pour les liens envoyés à la main sans l'exposer en
     * libre-service. C'est pourquoi le masquage n'est PAS déduit de l'activation :
     * les deux drapeaux restent indépendants.
     */
    public function estVisible(string $passerelle): bool
    {
        return config('payments.gateways.'.$passerelle.'.visible') !== false;
    }

    /**
     * Les clés d'API du moyen sont-elles renseignées ?
     *
     * Un moyen non configuré n'est jamais proposé, même si l'exploitant l'a
     * activé : le client serait redirigé vers une page d'erreur, ce qui est pire
     * qu'un moyen absent de la liste.
     *
     * **Un moyen INCONNU est refusé, et ce n'était pas le cas au départ.** La
     * fonction retournait `true` quand la passerelle n'apparaissait pas dans
     * `PREFIXE_CONFIG` — au motif qu'elle n'a pas besoin de clés. Un moyen inconnu
     * était donc réputé configuré, déclaré proposable, et **routé vers KPay par
     * défaut** par le contrôleur : un client demandant « paypal » aurait été envoyé
     * chez le mauvais fournisseur, et le paiement n'aurait été rapproché avec rien.
     *
     * Défaut trouvé par un test d'intégration qui envoyait justement un moyen
     * inconnu. La règle est désormais explicite : le moyen doit être DÉCLARÉ dans
     * `tous()`. Le seul moyen sans clé est le hors-ligne, et il y est déclaré.
     */
    public function estConfigure(string $passerelle): bool
    {
        // Passerelle non déclarée : refus. C'est une liste blanche, pas un défaut
        // permissif — une passerelle qu'on ne connaît pas ne peut pas fonctionner.
        if (! array_key_exists($passerelle, self::tous())) {
            return false;
        }

        $prefixe = self::PREFIXE_CONFIG[$passerelle] ?? null;

        if ($prefixe === null) {
            // Le hors-ligne est déclaré mais sans API : il ne dépend d'aucun
            // service externe, donc rien à configurer.
            return true;
        }

        $cle = (string) config($prefixe.'.api_key', config($prefixe.'.service_key', ''));
        $secret = (string) config($prefixe.'.secret_key', config($prefixe.'.service_secret', ''));

        return $cle !== '' && $secret !== '';
    }

    /**
     * Le moyen déclenche-t-il un appel réseau ?
     *
     * Sert à distinguer le hors-ligne, dont le règlement est constaté à la main,
     * des passerelles dont le statut se vérifie auprès du fournisseur.
     */
    public function estAutomatique(string $passerelle): bool
    {
        return (bool) (self::tous()[$passerelle]['automatique'] ?? false);
    }

    /**
     * Libellé lisible, ou la clé brute si elle est inconnue.
     *
     * Une clé inconnue est renvoyée telle quelle plutôt que remplacée par un
     * libellé générique : si un moyen est renommé, on veut le voir dans
     * l'interface, pas le confondre avec un autre.
     */
    public function libelle(string $passerelle): string
    {
        return self::tous()[$passerelle]['libelle'] ?? $passerelle;
    }

    /**
     * Moyens proposables dans l'interface d'achat, dans l'ordre d'affichage.
     *
     * @return array<string, array{libelle: string, description: string}>
     */
    public function proposables(): array
    {
        $resultat = [];

        foreach (self::tous() as $cle => $definition) {
            if ($this->estProposable($cle)) {
                $resultat[$cle] = [
                    'libelle' => $definition['libelle'],
                    'description' => $definition['description'],
                ];
            }
        }

        return $resultat;
    }

    /**
     * État détaillé de chaque moyen — pour l'écran d'administration.
     *
     * On expose les trois drapeaux SÉPARÉMENT (et non le seul `proposable`) pour
     * que l'exploitant voie POURQUOI un moyen n'apparaît pas. Afficher seulement
     * « proposable : non » laisserait chercher la cause entre trois possibilités
     * très différentes : clé absente, interrupteur fermé, ou masquage volontaire.
     *
     * @return array<string, array{libelle: string, description: string, configure: bool, actif: bool, visible: bool, proposable: bool, automatique: bool, cle_config: string}>
     */
    public function etats(): array
    {
        $resultat = [];

        foreach (self::tous() as $cle => $definition) {
            $resultat[$cle] = [
                'libelle' => $definition['libelle'],
                'description' => $definition['description'],
                'configure' => $this->estConfigure($cle),
                'actif' => $this->estActif($cle),
                'visible' => $this->estVisible($cle),
                'proposable' => $this->estProposable($cle),
                'automatique' => $this->estAutomatique($cle),
                'cle_config' => 'payments.gateways.'.$cle,
            ];
        }

        return $resultat;
    }

    /**
     * Toutes les clés de passerelle connues.
     *
     * @return array<int, string>
     */
    public static function cles(): array
    {
        return array_keys(self::tous());
    }
}
