<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\CreditService;
use App\Services\Billing\QuotaService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Page compte : solde de crédits, abonnement actif, historique des
 * transactions et formulaire d'achat (montant libre ≥ 500 FCFA).
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly QuotaService $quotas,
        private readonly SubscriptionService $subscriptions,
    ) {
    }

    /**
     * Affiche le compte utilisateur.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $balance = $this->credits->balance($user);

        $subscription = $user->activeSubscription?->load('plan');

        $transactions = $user->creditTransactions()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Devise d'affichage (Q5b) : choisie via ?currency= ou devise par défaut
        $currency = strtoupper((string) $request->query('currency', config('billing.default_currency', 'XAF')));
        if (! array_key_exists($currency, config('billing.currencies', []))) {
            $currency = config('billing.default_currency', 'XAF');
        }

        // Prix de chaque plan formatés dans la devise choisie
        $prices = $plans->mapWithKeys(
            fn (Plan $plan) => [$plan->slug => $this->subscriptions->formatPrice($plan, $currency)]
        );

        return view('account.index', [
            'balance' => $balance,
            'subscription' => $subscription,
            'transactions' => $transactions,
            'plans' => $plans,
            'quotaStatus' => $this->quotas->status($user),
            'currency' => $currency,
            'prices' => $prices,
        ]);
    }
}
