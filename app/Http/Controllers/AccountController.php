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
     *
     * P0-3 : corrige le disk utilisé pour la suppression des documents
     * (Storage::delete() visait le disk 'local' au lieu de 'storage') et
     * purge les fichiers générés par le chat (chat/generated/**, claude-skills/**)
     * tracés dans metadata.generated_files des messages de l'utilisateur,
     * AVANT la suppression des messages (best-effort).
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Nettoyage manuel des documents (pas de FK user_id sur documents)
        $documents = $user->documents ?? collect();

        // --- Purge des fichiers générés par le chat (P0-3 + P1-6) ---
        // Les chemins sont tracés dans metadata.generated_files (messages
        // assistant) et metadata.attachments (messages user) ; on les
        // collecte AVANT de supprimer les messages. En complément, on purge
        // récursivement le dossier chat/attachments/{sessionId} (P1-6 :
        // suppression cascade RGPD) pour couvrir les fichiers non tracés.
        $chatStorage = \Illuminate\Support\Facades\Storage::disk('local');
        $generatedPaths = [];

        foreach ($user->chatSessions as $session) {
            foreach ($session->messages as $message) {
                $files = $message->metadata['generated_files'] ?? null;
                if (is_array($files)) {
                    foreach ($files as $path) {
                        if (is_string($path) && $path !== '') {
                            $generatedPaths[] = $path;
                        }
                    }
                }

                $attachments = $message->metadata['attachments'] ?? null;
                if (is_array($attachments)) {
                    foreach ($attachments as $attachment) {
                        $path = $attachment['path'] ?? null;
                        if (is_string($path) && $path !== '') {
                            $generatedPaths[] = $path;
                        }
                    }
                }
            }

            // Purge récursive des pièces jointes de la session (P1-6).
            try {
                if ($chatStorage->exists('chat/attachments/'.$session->id)) {
                    $chatStorage->deleteDirectory('chat/attachments/'.$session->id);
                }
            } catch (\Throwable) {
                // Best-effort
            }

            $session->messages()->delete();
            $session->delete();
        }

        // Suppression physique des fichiers générés et pièces jointes tracés
        // (best-effort, une fois seulement par chemin — le même fichier peut
        // être référencé par plusieurs messages/sessions).
        foreach (array_unique($generatedPaths) as $path) {
            try {
                if ($chatStorage->exists($path)) {
                    $chatStorage->delete($path);
                }
            } catch (\Throwable) {
                // Fichier déjà absent ou erreur de stockage — on continue
            }
        }

        $user->creditTransactions()->delete();
        $user->invoices()->delete();
        $user->subscriptions()->delete();
        \App\Models\KpayPayment::where('user_id', $user->id)->delete();

        // --- Fichiers de documents (disk 'storage' = storage/uploads) ---
        // P0-3 : disk explicite 'storage' — avant, le Storage::delete() par
        // défaut ciblait le disk 'local', laissant les fichiers de documents
        // uploadés sur le disque après suppression du compte.
        $documentStorage = \Illuminate\Support\Facades\Storage::disk('storage');
        foreach ($documents as $document) {
            try {
                $relativePath = str_replace('\\', '/', (string) $document->path);
                if ($relativePath !== '' && $documentStorage->exists($relativePath)) {
                    $documentStorage->delete($relativePath);
                }
            } catch (\Throwable) {
                // Fichier déjà absent — on continue
            }

            // Fichiers DOCX/PDF générés (storage/test_scripts, hors disks)
            try {
                foreach ($document->generatedDocuments as $generated) {
                    if (! is_string($generated->output_path) || $generated->output_path === '') {
                        continue;
                    }
                    $out = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $generated->output_path);
                    if (is_file($out)) {
                        @unlink($out);
                    }
                }
            } catch (\Throwable) {
                // Best-effort
            }

            // Suppression en base (structure + générations liées)
            try {
                $document->structure()?->delete();
                $document->generatedDocuments()->delete();
                $document->delete();
            } catch (\Throwable) {
                // Best-effort
            }
        }

        // Déconnexion D'ABORD : Auth::logout() fait un save() implicite sur le
        // modèle (rotation du remember token). Si le user était déjà supprimé,
        // ce save() déclenchait un rollback de la transaction en cours.
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Suppression définitive du compte (lignes user + token)
        $user->delete();

        return redirect('/')->with('success', 'Votre compte a bien été supprimé. Merci de votre passage, et bonne continuation !');
    }
}
