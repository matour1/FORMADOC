<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Abonnements récurrents (Phase 9).
 *
 * Flux conforme au template validé :
 *   - GET  /checkout/{plan}          → page de paiement (carte/KPay/OM/MTN)
 *   - POST /subscriptions/subscribe  → init KPay → redirection passerelle
 *   - POST /subscriptions/cancel     → annulation (reste actif jusqu'à ends_at)
 *   - POST /subscriptions/change     → changement de plan (prorata)
 *   - GET  /invoices                 → liste des factures
 *   - GET  /invoices/{invoice}/pdf   → téléchargement PDF
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly InvoiceService $invoices,
    ) {
    }

    /**
     * Page checkout (conforme au template : méthode de paiement + récap).
     */
    public function checkout(Request $request, Plan $plan): View|RedirectResponse
    {
        if (! $plan->is_active) {
            return redirect()->route('account.index')->with('error', 'Ce plan n\'est plus disponible.');
        }

        $currency = $request->query('currency', config('billing.default_currency', 'XAF'));
        $price = $this->subscriptions->priceInCurrency($plan, $currency);
        $currentPlan = $request->user()->currentPlanSlug();
        $isUpgrade = $this->isUpgrade($currentPlan, $plan->slug);

        return view('subscriptions.checkout', [
            'plan' => $plan,
            'currency' => $currency,
            'price' => $price,
            'currentPlan' => $currentPlan,
            'isUpgrade' => $isUpgrade,
        ]);
    }

    /**
     * Initialise la souscription (init KPay → redirection passerelle).
     */
    public function subscribe(Request $request): RedirectResponse
    {
        $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'payment_method' => ['required', 'in:card,kpay,orange,mtn'],
            'currency' => ['required', 'string', 'max:3'],
        ]);

        $user = $request->user();
        $plan = Plan::findOrFail((int) $request->integer('plan_id'));

        $result = $this->subscriptions->initiateSubscription(
            $user,
            $plan,
            paymentMethod: (string) $request->string('payment_method'),
            currency: strtoupper((string) $request->string('currency')),
        );

        if (! $result['ok']) {
            return back()->with('error', $result['message'] ?? 'Échec de l\'initialisation du paiement.');
        }

        // Plan gratuit / sur devis : déjà activé sans passerelle
        if (isset($result['subscription'])) {
            return redirect()->route('account.index')->with('success', $result['message'] ?? 'Abonnement activé.');
        }

        Log::info('Souscription initiée', [
            'user_id' => $user->id,
            'plan_slug' => $plan->slug,
            'external_id' => $result['externalId'] ?? null,
        ]);

        return redirect()->away($result['gatewayUrl']);
    }

    /**
     * Annule l'abonnement actif (modale du template : reste actif jusqu'à ends_at).
     */
    public function cancel(Request $request): RedirectResponse
    {
        $subscription = $this->subscriptions->cancelCurrent($request->user());

        if (! $subscription) {
            return back()->with('error', 'Aucun abonnement actif.');
        }

        return redirect()->route('account.index')
            ->with('success', 'Abonnement annulé. Il restera actif jusqu\'à la fin de la période payée, puis vous basculerez sur le plan Gratuit.');
    }

    /**
     * Change de plan (prorata sur l'ancien abonnement, Q2a).
     */
    public function change(Request $request): RedirectResponse
    {
        $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'payment_method' => ['required', 'in:card,kpay,orange,mtn'],
            'currency' => ['required', 'string', 'max:3'],
        ]);

        $user = $request->user();
        $plan = Plan::findOrFail((int) $request->integer('plan_id'));

        // Le plan actif doit d'abord être annulé (prorata crédité)
        $this->subscriptions->cancelCurrent($user, refundProrata: true);

        $result = $this->subscriptions->initiateSubscription(
            $user,
            $plan,
            paymentMethod: (string) $request->string('payment_method'),
            currency: strtoupper((string) $request->string('currency')),
        );

        if (! $result['ok']) {
            return back()->with('error', $result['message'] ?? 'Échec du changement de plan.');
        }

        if (isset($result['subscription'])) {
            return redirect()->route('account.index')
                ->with('success', 'Plan '.$plan->name.' activé (prorata de l\'ancien plan crédité en crédits).');
        }

        return redirect()->away($result['gatewayUrl']);
    }

    /**
     * Liste des factures de l'utilisateur.
     */
    public function invoices(Request $request): View
    {
        $invoices = $request->user()->invoices()
            ->with('plan')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('subscriptions.invoices', ['invoices' => $invoices]);
    }

    /**
     * Télécharge le PDF d'une facture.
     */
    public function downloadInvoice(Request $request, Invoice $invoice): \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
    {
        if ($invoice->user_id !== $request->user()->id) {
            abort(403);
        }

        $path = $this->invoices->downloadPath($invoice);

        if (! $path) {
            return redirect()->route('account.index')->with('error', 'Impossible de générer le PDF de cette facture.');
        }

        $disk = (string) config('billing.invoice_storage_disk', 'local');
        $filename = $invoice->number.'.'.pathinfo($path, PATHINFO_EXTENSION);

        return response()->streamDownload(
            function () use ($disk, $path) {
                echo \Illuminate\Support\Facades\Storage::disk($disk)->get($path);
            },
            $filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * Compare deux slugs de plans pour déterminer s'il s'agit d'une montée.
     */
    private function isUpgrade(string $current, string $target): bool
    {
        $order = ['default' => 0, 'standard' => 1, 'premium' => 2, 'pro' => 3, 'enterprise' => 4];

        return ($order[$target] ?? 0) > ($order[$current] ?? 0);
    }
}
