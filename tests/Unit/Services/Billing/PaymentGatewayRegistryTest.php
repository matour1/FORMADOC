<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\PaymentGatewayRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Registre des moyens de paiement.
 *
 * **Ce que ces tests protègent.** Le registre décide de ce que voit l'utilisateur au
 * moment de payer. Trois notions y sont distinctes à dessein, et les confondre est le
 * défaut qu'il existe pour empêcher :
 *
 *  - **configuré** — les clés d'API sont renseignées ;
 *  - **actif** — l'exploitant accepte le moyen ;
 *  - **visible** — le moyen apparaît dans l'interface d'achat.
 *
 * Deux confusions sont particulièrement coûteuses :
 *
 *  1. **Afficher un moyen non configuré** envoie le client vers une page d'erreur du
 *     fournisseur. Il a déjà cliqué, il ne comprend pas, il appelle le support.
 *  2. **Confondre « masquer » et « désactiver »** oblige à couper un moyen qui
 *     fonctionne pour simplement cesser de le proposer — et fait perdre les
 *     encaissements négociés qui passaient par lui.
 *
 * Un dernier cas mérite un test explicite : le **hors-ligne** est le recours quand
 * toutes les passerelles tombent. Il ne doit jamais être désactivé par défaut.
 */
class PaymentGatewayRegistryTest extends TestCase
{
    private function registre(): PaymentGatewayRegistry
    {
        return app(PaymentGatewayRegistry::class);
    }

    // -------------------------------------------------------------------------
    // Le défaut déduit : actif si configuré
    // -------------------------------------------------------------------------

    /**
     * **Un moyen configuré est proposable sans réglage préalable.**
     *
     * Ce test couvre deux défauts que le code a réellement eus :
     *
     *  1. `config()` ne retourne son défaut que si la clé est ABSENTE ; or
     *     `config/payments.php` utilisait `env()` sans défaut, donc la clé existait
     *     avec la valeur `null`. Le défaut déduit ne s'appliquait jamais, et KPay —
     *     pourtant configuré — apparaissait INACTIF.
     *  2. La sentinelle `null` elle-même était une erreur de conception : elle est
     *     indiscernable d'une clé absente, ce que l'invariant du projet refuse
     *     (`.ai/rules/general.md`, `SettingsTest`). Un réglage valorisé à `null`
     *     serait affiché, modifiable, et sans effet.
     *
     * La solution retenue est un défaut EXPLICITE (`true`) dans la configuration :
     * plus de sentinelle ambiguë, et une intégration fraîchement déployée est
     * active sans réglage préalable.
     */
    public function test_un_moyen_configure_est_proposable_par_defaut(): void
    {
        config([
            'kpay.api_key' => 'test_key',
            'kpay.secret_key' => 'test_secret',
            // Valeur explicite du fichier de configuration : `true` par défaut.
            'payments.gateways.kpay.actif' => true,
            'payments.gateways.kpay.visible' => true,
        ]);

        $registre = $this->registre();

        $this->assertTrue($registre->estConfigure(PaymentGatewayRegistry::KPAY));
        $this->assertTrue($registre->estActif(PaymentGatewayRegistry::KPAY),
            'Un moyen configuré doit être actif sans réglage préalable : exiger une '
            .'seconde activation ferait paraître l\'intégration en panne.');
        $this->assertTrue($registre->estProposable(PaymentGatewayRegistry::KPAY));
    }

    /**
     * **La configuration ne contient jamais `null` comme sentinelle.**
     *
     * Test d'invariant, et il protège d'une erreur que j'ai réellement commise.
     * `null` est indiscernable d'une clé ABSENTE : `SettingsRepository::applyToConfig()`
     * ignorant silencieusement les clés inconnues, un réglage valorisé à `null`
     * serait affiché dans l'écran d'administration, modifiable, et **sans aucun
     * effet**. L'exploitant croirait avoir désactivé un moyen.
     *
     * C'est ce qu'affirme l'invariant du projet, et il a raison : la configuration
     * doit porter une valeur, pas une absence. C'est le même contrôle que
     * `SettingsTest::test_chaque_reglage_pointe_vers_une_configuration_existante`.
     */
    public function test_la_configuration_des_moyens_ne_contient_jamais_null(): void
    {
        foreach (PaymentGatewayRegistry::cles() as $moyen) {
            foreach (['actif', 'visible'] as $drapeau) {
                $cle = 'payments.gateways.'.$moyen.'.'.$drapeau;

                $this->assertNotNull(
                    config($cle),
                    "`{$cle}` vaut null. C'est indiscernable d'une clé absente : le "
                    .'réglage serait affiché, modifiable, et sans effet. Mettre un '
                    .'défaut explicite (`true`).',
                );

                $this->assertIsBool(
                    config($cle),
                    "`{$cle}` doit être un booléen strict : `false` relu comme "
                    .'chaîne `"0"` deviendrait *truthy* en PHP.',
                );
            }
        }
    }

    /**
     * Un moyen NON configuré n'est jamais proposable, même activé.
     *
     * C'est le cas le plus important : l'exploitant active un moyen dont il n'a pas
     * encore les clés. Le proposer enverrait le client vers une page d'erreur, ce qui
     * est pire qu'un moyen absent de la liste.
     */
    public function test_un_moyen_non_configure_n_est_jamais_proposable(): void
    {
        config([
            'monetbil.service_key' => '',
            'monetbil.service_secret' => '',
            'payments.gateways.monetbil.actif' => true,
            'payments.gateways.monetbil.visible' => true,
        ]);

        $registre = $this->registre();

        $this->assertFalse($registre->estConfigure(PaymentGatewayRegistry::MONETBIL));
        $this->assertTrue($registre->estActif(PaymentGatewayRegistry::MONETBIL),
            'L\'exploitant a explicitement activé le moyen : ce fait est conservé.');
        $this->assertFalse($registre->estProposable(PaymentGatewayRegistry::MONETBIL),
            'Actif mais non configuré ne doit PAS être proposé : le client serait '
            .'mené vers une page d\'erreur du fournisseur.');
    }

    // -------------------------------------------------------------------------
    // Masquer n'est pas désactiver
    // -------------------------------------------------------------------------

    /**
     * **Un moyen actif mais masqué reste utilisable.**
     *
     * C'est la raison d'être des deux drapeaux. Cas d'usage réel : encaisser via un
     * fournisseur pour des liens négociés sans l'exposer en libre-service — frais
     * plus élevés, quota limité, contrat en renégociation.
     *
     * Si « masquer » impliquait « désactiver », il faudrait couper le moyen pour
     * cesser de le proposer, et perdre aussi les encaissements en cours.
     */
    public function test_un_moyen_actif_mais_masque_reste_utilisable(): void
    {
        config([
            'kpay.api_key' => 'test_key',
            'kpay.secret_key' => 'test_secret',
            'payments.gateways.kpay.actif' => true,
            'payments.gateways.kpay.visible' => false,
        ]);

        $registre = $this->registre();

        $this->assertTrue($registre->estActif(PaymentGatewayRegistry::KPAY),
            'Masquer ne doit PAS désactiver.');
        $this->assertFalse($registre->estVisible(PaymentGatewayRegistry::KPAY));
        $this->assertFalse($registre->estProposable(PaymentGatewayRegistry::KPAY),
            'Un moyen masqué n\'est pas proposé dans l\'interface d\'achat.');
    }

    /**
     * Un moyen désactivé n'est pas proposable.
     *
     * **Ce que ce test vérifie, et ce qu'il ne vérifie pas.** Une première version
     * affirmait aussi que `estVisible()` retournerait `false` — c'était FAUX, et le
     * test échouait sur du code correct. `visible` est **déduit** de `actif`
     * uniquement quand l'exploitant n'a rien saisi ; une valeur explicite prime.
     *
     * Ce comportement est le bon, et le forcer serait une perte d'information : avec
     * `actif = false` et `visible = true`, l'écran d'administration affiche
     * « activé : non / affiché : oui », donc l'exploitant voit qu'il lui suffit de
     * réactiver pour retrouver le comportement d'avant. Forcer `visible = false`
     * effacerait ce fait, et il devrait reconfigurer les deux.
     *
     * L'absence de bouton mort est garantie par `proposable`, pas par `visible`.
     */
    public function test_un_moyen_desactive_n_est_pas_proposable(): void
    {
        config([
            'kpay.api_key' => 'test_key',
            'kpay.secret_key' => 'test_secret',
            'payments.gateways.kpay.actif' => false,
            'payments.gateways.kpay.visible' => true,
        ]);

        $registre = $this->registre();

        $this->assertFalse($registre->estProposable(PaymentGatewayRegistry::KPAY),
            'Un moyen désactivé ne doit jamais être proposé, même si le drapeau '
            .'`visible` est resté à vrai : c\'est `proposable` qui protège du bouton mort.');

        $this->assertTrue($registre->estVisible(PaymentGatewayRegistry::KPAY),
            'La valeur explicite de `visible` est respectée : l\'écran '
            .'d\'administration peut ainsi montrer qu\'une simple réactivation suffira.');
    }

    /**
     * **Un moyen inactif ET masqué n'est pas proposable.**
     *
     * Ce test serait redondant avec le précédent si les deux drapeaux ne pouvaient pas
     * diverger — mais ils le peuvent, et c'est justement l'intérêt de les avoir
     * séparés. Ici les deux valent `false` : le moyen est coupé sur toute la ligne. Le
     * cas où ils divergent (actif, mais masqué) est couvert par
     * `test_un_moyen_actif_mais_masque_reste_utilisable`.
     */
    public function test_un_moyen_inactif_et_masque_n_est_pas_proposable(): void
    {
        config([
            'kpay.api_key' => 'test_key',
            'kpay.secret_key' => 'test_secret',
            'payments.gateways.kpay.actif' => false,
            'payments.gateways.kpay.visible' => false,
        ]);

        $registre = $this->registre();

        $this->assertFalse($registre->estActif(PaymentGatewayRegistry::KPAY));
        $this->assertFalse($registre->estVisible(PaymentGatewayRegistry::KPAY));
        $this->assertFalse($registre->estProposable(PaymentGatewayRegistry::KPAY));
    }

    // -------------------------------------------------------------------------
    // Le hors-ligne, moyen de secours
    // -------------------------------------------------------------------------

    /**
     * **Le hors-ligne est proposable sans configuration.**
     *
     * Il ne dépend d'aucun service externe : c'est la seule voie qui reste quand la
     * connexion ou l'opérateur tombe. KPay est resté plusieurs jours indisponible
     * pendant le développement de cette fonctionnalité — c'est un cas vécu, pas
     * hypothétique.
     */
    public function test_le_hors_ligne_est_toujours_disponible(): void
    {
        config([
            'payments.gateways.offline.actif' => true,
            'payments.gateways.offline.visible' => true,
        ]);

        $registre = $this->registre();

        $this->assertTrue($registre->estConfigure(PaymentGatewayRegistry::HORS_LIGNE),
            'Le hors-ligne ne requiert aucune clé d\'API.');
        $this->assertTrue($registre->estProposable(PaymentGatewayRegistry::HORS_LIGNE));
        $this->assertFalse($registre->estAutomatique(PaymentGatewayRegistry::HORS_LIGNE),
            'Le hors-ligne ne déclenche aucun appel réseau : c\'est ce qui le rend '
            .'insensible aux pannes des passerelles.');
    }

    // -------------------------------------------------------------------------
    // Cohérence d'ensemble
    // -------------------------------------------------------------------------

    /**
     * `proposable` est TOUJOURS la conjonction des trois conditions.
     *
     * Test de cohérence sur toutes les combinaisons de drapeaux, pour chaque moyen.
     * Sans lui, une modification future de `estProposable()` pourrait oublier une
     * condition sans qu'aucun test existant ne s'en aperçoive.
     *
     * @return array<string, array{string, bool, bool, bool}>
     */
    public static function combinaisons(): array
    {
        $cas = [];

        foreach (['kpay', 'monetbil'] as $moyen) {
            foreach ([true, false] as $actif) {
                foreach ([true, false] as $visible) {
                    $cas[$moyen.' actif='.var_export($actif, true).' visible='.var_export($visible, true)] = [
                        $moyen, $actif, $visible, $actif && $visible,
                    ];
                }
            }
        }

        return $cas;
    }

    /**
     * @param  bool  $proposableAttendu  `false` dès que l'un des drapeaux est faux
     */
    #[DataProvider('combinaisons')]
    public function test_proposable_est_la_conjonction_des_conditions(
        string $moyen,
        bool $actif,
        bool $visible,
        bool $proposableAttendu,
    ): void {
        config([
            $moyen.'.api_key' => 'cle',
            $moyen.'.secret_key' => 'secret',
            $moyen.'.service_key' => 'cle',
            $moyen.'.service_secret' => 'secret',
            'payments.gateways.'.$moyen.'.actif' => $actif,
            'payments.gateways.'.$moyen.'.visible' => $visible,
        ]);

        $this->assertSame(
            $proposableAttendu,
            $this->registre()->estProposable($moyen),
            'Un moyen doit être configuré ET actif ET visible pour être proposé.',
        );
    }

    /**
     * Les moyens proposables sont exposés avec leur libellé et leur description.
     *
     * L'interface d'achat consomme `proposables()` : sans libellé, elle n'aurait rien
     * à afficher, et l'utilisateur ne saurait pas ce qu'il choisit.
     */
    public function test_les_moyens_proposables_exposent_leur_libelle(): void
    {
        config([
            'kpay.api_key' => 'cle',
            'kpay.secret_key' => 'secret',
            'monetbil.service_key' => '',
            'monetbil.service_secret' => '',
            'payments.gateways.offline.actif' => true,
            'payments.gateways.offline.visible' => true,
        ]);

        $proposables = $this->registre()->proposables();

        $this->assertArrayHasKey(PaymentGatewayRegistry::KPAY, $proposables);
        $this->assertArrayHasKey(PaymentGatewayRegistry::HORS_LIGNE, $proposables);
        $this->assertArrayNotHasKey(PaymentGatewayRegistry::MONETBIL, $proposables,
            'Monetbil sans clé ne doit pas figurer parmi les moyens proposés.');

        foreach ($proposables as $definition) {
            $this->assertNotEmpty($definition['libelle']);
            $this->assertNotEmpty($definition['description']);
        }
    }

    /**
     * Un moyen INCONNU n'est jamais proposable.
     *
     * **Ce test protège d'un défaut réellement introduit.** `estConfigure()`
     * retournait `true` pour toute passerelle absente de la table des préfixes de
     * configuration, au motif qu'elle n'a pas besoin de clés. Un moyen inconnu était
     * donc déclaré configuré, puis proposable — et le contrôleur, ne le reconnaissant
     * pas comme Monetbil, le routait vers **KPay par défaut**. Un client demandant
     * « paypal » aurait été envoyé chez le mauvais fournisseur : le paiement aurait
     * abouti, mais sur une transaction que rien n'aurait permis de rapprocher.
     *
     * La règle est maintenant une liste blanche : le moyen doit être DÉCLARÉ.
     */
    public function test_un_moyen_inconnu_n_est_jamais_proposable(): void
    {
        $registre = $this->registre();

        foreach (['paypal', 'stripe', 'inconnu', ''] as $moyen) {
            $this->assertFalse($registre->estConfigure($moyen),
                "« {$moyen} » n'est pas déclaré : il ne peut pas être considéré comme "
                .'configuré, sinon il serait routé vers la passerelle par défaut.');
            $this->assertFalse($registre->estProposable($moyen));
        }
    }

    /**
     * Chaque moyen exposé par `etats()` porte ses trois drapeaux SÉPARÉMENT.
     *
     * L'écran d'administration s'en sert pour dire POURQUOI un moyen n'apparaît pas.
     * N'exposer que `proposable` laisserait chercher la cause entre trois
     * possibilités très différentes : clé absente, interrupteur fermé, masquage.
     */
    public function test_les_etats_exposent_les_trois_drapeaux_separement(): void
    {
        $etats = $this->registre()->etats();

        foreach (PaymentGatewayRegistry::cles() as $cle) {
            $this->assertArrayHasKey($cle, $etats);

            foreach (['configure', 'actif', 'visible', 'proposable', 'automatique', 'cle_config'] as $champ) {
                $this->assertArrayHasKey($champ, $etats[$cle],
                    "L'état de « {$cle} » doit exposer « {$champ} » pour que l'écran "
                    .'d\'administration puisse nommer la cause d\'une indisponibilité.');
            }
        }
    }
}
