<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P2-8 — Sécurité des factures : téléchargement PDF.
 *
 * Le contrôleur `SubscriptionController::downloadInvoice` vérifie que la
 * facture appartient bien à l'utilisateur courant. Ces tests couvrent :
 *   - cross-user : 403 (facture d'un autre utilisateur)
 *   - non authentifié : redirection vers login (middleware auth)
 *   - propriétaire : téléchargement OK
 *   - hash invalide : 404 (binding ne résout rien)
 */
class InvoiceDownloadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(User $user): Invoice
    {
        $plan = Plan::factory()->create();

        return Invoice::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'number' => 'INV-'.now()->year.'-000001',
            'amount' => 5000,
            'currency' => 'XAF',
            'status' => 'paid',
            'payment_method' => 'kpay',
            'reference' => 'pay_'.uniqid(),
            'metadata' => ['pdf_path' => 'invoices/invoice-1.pdf'],
        ]);
    }

    public function test_le_telechargement_d_une_facture_d_un_autre_utilisateur_est_403(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $invoice = $this->makeInvoice($owner);

        $this->actingAs($attacker);

        $this->get(route('invoices.download', $invoice))
            ->assertForbidden();
    }

    public function test_le_telechargement_sans_authentification_redirige_vers_login(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->makeInvoice($owner);

        // Invité → middleware auth → redirect login
        $this->get(route('invoices.download', $invoice))
            ->assertRedirect(route('login'));
    }

    public function test_le_proprietaire_telecharge_sa_facture(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('invoices/invoice-1.pdf', 'PDF-FACTICE');

        $owner = User::factory()->create();
        $invoice = $this->makeInvoice($owner);

        $this->actingAs($owner);

        $response = $this->get(route('invoices.download', $invoice));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertDownload($invoice->number.'.pdf');
    }

    public function test_un_hash_invalide_dans_l_url_renvoie_404(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->makeInvoice($owner);

        $this->actingAs($owner);

        // Hash corrompu (bon préfixe, mauvais caractère) → binding → null → 404
        $badHash = substr($invoice->hash_id, 0, -1).'X';
        $this->get('/invoices/'.$badHash.'/pdf')->assertNotFound();
    }
}
