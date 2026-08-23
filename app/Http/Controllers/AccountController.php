<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\CreditService;
use App\Services\Billing\QuotaService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Page paramètres (profil + préférences + zone de danger).
     */
    public function settings(Request $request): View
    {
        $user = $request->user()->load('activeSubscription.plan');

        return view('account.settings', [
            'user' => $user,
            'subscription' => $user->activeSubscription,
        ]);
    }

    /**
     * Mise à jour du profil (nom, email).
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        return back()->with('success', 'Profil mis à jour avec succès.');
    }

    /**
     * Préférences (langue, thème, notifications) — stockées dans un champ
     * JSON du modèle utilisateur via une colonne `preferences`.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $user = $request->user();

        $preferences = $user->preferences ?? [];

        $preferences['locale'] = $request->input('locale', 'fr');
        $preferences['theme'] = $request->boolean('theme_dark') ? 'dark' : 'light';
        $preferences['email_notifications'] = $request->boolean('email_notifications');

        $user->preferences = $preferences;
        $user->save();

        return back()->with('success', 'Préférences enregistrées.');
    }

    /**
     * Suppression définitive du compte (toutes les données liées).
     * Exige la saisie du mot « SUPPRIMER » côté client.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Nettoyage manuel des documents (pas de FK user_id sur documents)
        $documents = $user->documents ?? collect();

        foreach ($user->chatSessions as $session) {
            $session->messages()->delete();
            $session->delete();
        }

        $user->creditTransactions()->delete();
        $user->invoices()->delete();
        $user->subscriptions()->delete();

        // Fichiers de documents stockés (nettoyage best-effort)
        foreach ($documents as $document) {
            try {
                \Illuminate\Support\Facades\Storage::delete($document->path);
            } catch (\Throwable) {
                // Fichier déjà absent — on continue
            }
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', 'Votre compte a bien été supprimé. Merci de votre passage, et bonne continuation !');
    }
}
