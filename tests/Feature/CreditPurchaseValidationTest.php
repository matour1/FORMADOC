<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Achat de crédits : seuls les PALIERS déclarés sont acceptés.
 *
 * **Ce que ces tests protègent.** Le formulaire proposait auparavant une saisie
 * libre (`type="number"`, min/max). C'est inencaissable : une passerelle mobile
 * money exige un service déclaré par offre, avec ses propres clés — un montant
 * arbitraire ne correspond à aucun service. Le formulaire est devenu une liste de
 * paliers, et le serveur doit REFUSER tout montant hors palier.
 *
 * **Pourquoi la validation serveur est indispensable.** Le formulaire est du HTML :
 * n'importe qui peut le modifier et envoyer 1 FCFA, ou 999 999. Sans contrôle
 * serveur, un montant sans service serait accepté, le paiement serait créé, et
 * l'encaissement échouerait — après l'engagement du client.
 *
 * **Un palier SANS service rattaché est refusé aussi.** Tant que les services
 * Monetbil ne sont pas déclarés, aucun achat n'est possible ; c'est le
 * comportement correct, et l'interface l'annonce.
 */
class CreditPurchaseValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deux paliers, dont un SEUL rattaché à un service.
        config([
            'billing.credit_packs' => [
                ['montant' => 1000, 'credits' => 1000, 'libelle' => 'Découverte', 'service' => 'srv_1000'],
                ['montant' => 3000, 'credits' => 3000, 'libelle' => 'Mémoire', 'service' => null],
            ],
            'kpay.api_key' => 'kpay_cle',
            'kpay.secret_key' => 'kpay_secret',
            'payments.gateways.kpay.actif' => true,
            'payments.gateways.kpay.visible' => true,
        ]);
    }

    private function utilisateur(): User
    {
        return User::factory()->create(['credits_balance' => 0]);
    }

    /**
     * **Un montant hors palier est REFUSÉ.**
     *
     * C'est la règle qui remplace la saisie libre. Un montant sans palier ne
     * correspond à aucun service de paiement, donc à aucun encaissement possible.
     */
    public function test_un_montant_hors_palier_est_refuse(): void
    {
        foreach ([500, 1500, 999999, 0, -1000] as $montant) {
            $reponse = $this->actingAs($this->utilisateur())
                ->post(route('credits.purchase'), ['amount' => $montant]);

            $reponse->assertSessionHasErrors('amount');

            $this->assertSame(0, User::where('credits_balance', '>', 0)->count(),
                "Le montant {$montant} ne doit crediter personne : il ne correspond a "
                .'aucun palier, donc a aucun service encaissable.');
        }
    }

    /**
     * **Un palier SANS service rattaché est refusé.**
     *
     * Le palier existe, mais son service Monetbil n'est pas déclaré : le paiement
     * ne pourrait pas aboutir. Le refus est explicite plutôt qu'une erreur de
     * passerelle après engagement du client.
     */
    public function test_un_palier_sans_service_est_refuse(): void
    {
        $this->actingAs($this->utilisateur())
            ->post(route('credits.purchase'), ['amount' => 3000])
            ->assertSessionHasErrors('amount');
    }

    /**
     * Un palier rattaché à un service est ACCEPTÉ et mène à la passerelle.
     *
     * Contrôle positif : sans lui, on ne saurait pas si les refus précédents
     * viennent de la validation ou d'un chemin entièrement cassé.
     */
    public function test_un_palier_valide_est_accepte(): void
    {
        Http::fake([
            'admin.kpay.site/*' => Http::response([
                'gatewayUrl' => 'https://admin.kpay.site/pay/abc',
                'paymentId' => 'pay_123',
                'externalId' => 'CREDIT-1-uuid',
            ], 201),
        ]);

        $reponse = $this->actingAs($this->utilisateur())
            ->post(route('credits.purchase'), ['amount' => 1000]);

        $reponse->assertRedirect('https://admin.kpay.site/pay/abc');
        $reponse->assertSessionHasNoErrors();
    }

    /**
     * **Aucun achat n'est possible quand AUCUN palier n'a de service.**
     *
     * C'est l'état d'un `.env` fraîchement copié : les clés de service sont
     * vides. L'interface annonce l'indisponibilité, et le serveur refuse — les
     * deux doivent être cohérents, sinon un utilisateur verrait un message
     * d'indisponibilité tout en pouvant déclencher un achat par URL directe.
     */
    public function test_aucun_achat_sans_palier_rattache(): void
    {
        config([
            'billing.credit_packs' => [
                ['montant' => 1000, 'service' => null],
                ['montant' => 3000, 'service' => ''],
            ],
        ]);

        Http::fake();

        $this->actingAs($this->utilisateur())
            ->post(route('credits.purchase'), ['amount' => 1000])
            ->assertSessionHasErrors('amount');

        Http::assertNothingSent();
    }

    /**
     * Le message d'erreur GUIDE l'utilisateur.
     *
     * « Ce champ est invalide » laisserait l'utilisateur sans issue. Le message
     * doit dire quoi faire : choisir l'un des paliers proposés.
     */
    public function test_le_message_d_erreur_guide_l_utilisateur(): void
    {
        $reponse = $this->actingAs($this->utilisateur())
            ->post(route('credits.purchase'), ['amount' => 777]);

        $reponse->assertSessionHasErrors('amount');

        // Le message est lu depuis le sac d'erreurs partagé par la réponse.
        $erreurs = $reponse->getSession()->get('errors')->get('amount');

        $this->assertNotEmpty($erreurs);

        $this->assertStringContainsString('palier', mb_strtolower(implode(' ', $erreurs)),
            'Le message doit mentionner les paliers : sinon l\'utilisateur ne sait '
            .'pas quels montants sont acceptés.');
    }
}
