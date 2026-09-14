<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Billing\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Remboursement proportionnel des chaînes d'outils partiellement échouées (R7.4).
 *
 * **Le cas qui motive cette suite.** Une chaîne de cinq appels d'outils dont
 * trois réussissent a produit trois cinquièmes de valeur. Rembourser la
 * totalité ferait payer à l'application le service rendu ; ne rien rembourser
 * facturerait un service non rendu. La part remboursée est donc calculée au
 * prorata des échecs.
 *
 * Ces tests vérifient le calcul **isolément** (méthode statique, sans base de
 * données) puis son écriture comptable. La séparation compte : le calcul doit
 * rester vérifiable sans base, c'est lui qui décide du montant.
 */
class PartialRefundTest extends TestCase
{
    use RefreshDatabase;

    private CreditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CreditService;
    }

    // -------------------------------------------------------------------------
    // Calcul pur (sans base de données)
    // -------------------------------------------------------------------------

    public function test_le_remboursement_est_proportionnel_au_nombre_d_echecs(): void
    {
        // 3 échecs sur 5 pour 100 crédits débités → 60 remboursés.
        $this->assertSame(60, CreditService::proportionalRefund(100, 3, 5));
    }

    public function test_un_seul_echec_sur_cinq_rembourse_un_cinquieme(): void
    {
        $this->assertSame(20, CreditService::proportionalRefund(100, 1, 5));
    }

    public function test_un_echec_total_rembourse_la_totalite(): void
    {
        $this->assertSame(100, CreditService::proportionalRefund(100, 5, 5));
        // Le cas « plus d'échecs que d'appels » ne doit pas rembourser plus que
        // le débit : un compteur erroné ne doit pas créer de crédits.
        $this->assertSame(100, CreditService::proportionalRefund(100, 7, 5));
    }

    public function test_aucun_echec_ne_rembourse_rien(): void
    {
        $this->assertSame(0, CreditService::proportionalRefund(100, 0, 5));
    }

    public function test_les_entrees_invalides_ne_remboursent_rien(): void
    {
        $this->assertSame(0, CreditService::proportionalRefund(0, 3, 5));
        $this->assertSame(0, CreditService::proportionalRefund(-10, 3, 5));
        $this->assertSame(0, CreditService::proportionalRefund(100, 3, 0));
    }

    public function test_l_arrondi_se_fait_vers_le_bas(): void
    {
        // 100 × 1 / 3 = 33,33 → 33. Arrondir au supérieur ferait payer à
        // l'application une fraction de crédit par incident, et des échecs
        // répétés rendraient le remboursement plus cher que le service rendu.
        $this->assertSame(33, CreditService::proportionalRefund(100, 1, 3));
        $this->assertSame(66, CreditService::proportionalRefund(100, 2, 3));
    }

    // -------------------------------------------------------------------------
    // Écriture comptable
    // -------------------------------------------------------------------------

    public function test_le_remboursement_partiel_credite_le_solde_et_journalise(): void
    {
        $user = User::factory()->create(['credits_balance' => 500]);

        $resultat = $this->service->refundPartial(
            $user,
            100,
            3,
            5,
            reference: 'chat:12',
            description: 'Remboursement partiel',
        );

        $this->assertTrue($resultat['ok']);
        $this->assertSame(60, $resultat['refunded']);
        $this->assertSame(560, $resultat['balance']);
        $this->assertSame(560, $user->fresh()->credits_balance);

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'refund',
            'amount' => 60,
            'balance_after' => 560,
            'reference' => 'chat:12',
        ]);
    }

    public function test_la_transaction_trace_le_detail_du_prorata(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);

        $this->service->refundPartial($user, 50, 2, 4);

        $transaction = CreditTransaction::firstOrFail();

        // Sans ces trois nombres, un litige est indéchiffrable : on saurait
        // qu'un montant a été remboursé, pas d'où il vient.
        $this->assertTrue($transaction->metadata['partial_refund']);
        $this->assertSame(2, $transaction->metadata['failed']);
        $this->assertSame(4, $transaction->metadata['total']);
        $this->assertSame(50, $transaction->metadata['base_credits']);
    }

    public function test_aucun_echec_n_ecrit_aucune_transaction(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);

        $resultat = $this->service->refundPartial($user, 50, 0, 4);

        $this->assertTrue($resultat['ok']);
        $this->assertSame(0, $resultat['refunded']);
        $this->assertSame('rien_a_rembourser', $resultat['reason']);
        $this->assertSame(100, $user->fresh()->credits_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_le_remboursement_partiel_ne_rend_pas_le_solde_negatif(): void
    {
        $user = User::factory()->create(['credits_balance' => 0]);

        $resultat = $this->service->refundPartial($user, 40, 1, 2);

        $this->assertTrue($resultat['ok']);
        $this->assertSame(20, $resultat['refunded'], 'Un remboursement est toujours créditeur.');
        $this->assertSame(20, $user->fresh()->credits_balance);
    }

    public function test_un_remboursement_partiel_reste_un_credit_journalise(): void
    {
        $user = User::factory()->create(['credits_balance' => 10]);

        $this->service->refundPartial($user, 90, 4, 5);

        $transaction = CreditTransaction::firstOrFail();

        // Le type reste 'refund' : la vue du compte distingue remboursement et
        // achat, un type dédié n'apporterait rien et casserait l'affichage.
        $this->assertSame('refund', $transaction->type);
        $this->assertSame(72, $transaction->amount);
    }

    public function test_la_reference_est_conservee_pour_le_rapprochement(): void
    {
        $user = User::factory()->create(['credits_balance' => 100]);

        $this->service->refundPartial($user, 100, 1, 4, reference: 'chat:77', description: 'Test');

        $this->assertSame('chat:77', CreditTransaction::firstOrFail()->reference);
    }
}
