<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\CreditService;
use App\Services\Billing\QuotaService;
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

        return view('account.index', [
            'balance' => $balance,
            'subscription' => $subscription,
            'transactions' => $transactions,
            'plans' => $plans,
            'quotaStatus' => $this->quotas->status($user),
        ]);
    }
}
