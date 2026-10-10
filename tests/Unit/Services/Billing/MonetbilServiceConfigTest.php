<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\MonetbilServiceRegistry;
use App\Services\Billing\PaymentGatewayRegistry;
use Tests\TestCase;

/**
 * Configuration Monetbil par service.
 *
 * **Ce que ces tests protègent.** Monetbil est un moyen de paiement par SERVICE :
 * chaque offre a ses propres identifiants. Une configuration qui ne renseigne que
 * les services par palier (le cas recommandé, sans clé globale) doit être reconnue
 * comme valide. Exiger une clé globale malgré des services complets retirerait
 * Monetbil de l'interface alors qu'il fonctionne — le moyen PRIORITAIRE deviendrait
 * invisible.
 *
 * **Un couple global incomplet n'est PAS un service.** Une clé sans secret ne peut
 * pas signer : elle ne doit pas rendre le moyen proposable.
 */
class MonetbilServiceConfigTest extends TestCase
{
    /**
     * **Monetbil est « configuré » dès qu'un service par palier est complet.**
     *
     * C'est le cas de déploiement recommandé : aucune clé globale, uniquement des
     * services par pack. Le registre doit le reconnaître, sinon le moyen prioritaire
     * n'apparaît jamais.
     */
    public function test_un_service_par_palier_complet_suffit_a_configurer_monetbil(): void
    {
        config([
            'monetbil.service_key' => '',
            'monetbil.service_secret' => '',
            'monetbil.services' => [
                'pack_1000' => ['id' => 'svc_1000', 'key' => 'cle_1000', 'secret' => 'secret_1000'],
            ],
            'payments.gateways.monetbil.actif' => true,
            'payments.gateways.monetbil.visible' => true,
        ]);

        $registre = app(PaymentGatewayRegistry::class);

        $this->assertTrue($registre->estConfigure(PaymentGatewayRegistry::MONETBIL),
            'Un service par palier complet doit configurer Monetbil : sans cela, le '
            .'moyen prioritaire resterait invisible malgré une configuration valide.');
        $this->assertTrue($registre->estProposable(PaymentGatewayRegistry::MONETBIL));
    }

    /**
     * **Aucun service complet : Monetbil n'est pas configuré.**
     *
     * Un service à moitié renseigné (clé sans secret) ne peut pas signer. Le traiter
     * comme configuré mènerait le client vers une page d'erreur.
     */
    public function test_aucun_service_complet_ne_configure_pas_monetbil(): void
    {
        config([
            'monetbil.service_key' => '',
            'monetbil.service_secret' => '',
            'monetbil.services' => [
                'pack_1000' => ['id' => 'svc_1000', 'key' => 'cle_1000', 'secret' => ''],
                'pack_3000' => ['id' => 'svc_3000', 'key' => '', 'secret' => ''],
            ],
            'payments.gateways.monetbil.actif' => true,
            'payments.gateways.monetbil.visible' => true,
        ]);

        $registre = app(PaymentGatewayRegistry::class);

        $this->assertFalse($registre->estConfigure(PaymentGatewayRegistry::MONETBIL),
            'Un service incomplet ne peut pas signer : il ne doit pas configurer le moyen.');
        $this->assertFalse($registre->estProposable(PaymentGatewayRegistry::MONETBIL));
    }

    /**
     * La compatibilité avec un couple global reste assurée.
     *
     * Un déploiement mono-service (clé et secret globaux, aucun service par palier)
     * doit continuer de fonctionner : la séparation par service ne doit pas casser
     * l'existant.
     */
    public function test_un_couple_global_reste_reconnu(): void
    {
        config([
            'monetbil.service_key' => 'cle_globale',
            'monetbil.service_secret' => 'secret_global',
            'monetbil.services' => [],
            'payments.gateways.monetbil.actif' => true,
            'payments.gateways.monetbil.visible' => true,
        ]);

        $this->assertTrue(app(PaymentGatewayRegistry::class)->estProposable(PaymentGatewayRegistry::MONETBIL));
    }

    /**
     * Le résolveur ne rend un service que s'il est COMPLET.
     *
     * `estExploitable()` est la garde unique utilisée par l'initiation et la
     * notification : elle doit refuser un service à moitié renseigné, sinon on
     * signerait avec un secret vide.
     */
    public function test_le_resolveur_exige_identifiant_cle_et_secret(): void
    {
        $registre = app(MonetbilServiceRegistry::class);

        config([
            'monetbil.services' => [
                'complet' => ['id' => 'svc_1', 'key' => 'k', 'secret' => 's'],
                'sans_secret' => ['id' => 'svc_2', 'key' => 'k', 'secret' => ''],
                'sans_cle' => ['id' => 'svc_3', 'key' => '', 'secret' => 's'],
            ],
        ]);

        $this->assertTrue($registre->estExploitable($registre->pourReference('complet')));
        $this->assertFalse($registre->estExploitable($registre->pourReference('sans_secret')));
        $this->assertFalse($registre->estExploitable($registre->pourReference('sans_cle')));
        $this->assertFalse($registre->estExploitable(null));
    }
}
