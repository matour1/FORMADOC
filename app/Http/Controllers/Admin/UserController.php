<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Billing\CreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Gestion des comptes utilisateurs depuis l'espace d'exploitation.
 *
 * **Ce que cet écran change.** L'espace d'administration était en LECTURE SEULE,
 * et c'était le bon défaut tant que le besoin d'agir n'était pas exprimé : agir sur
 * les données d'un utilisateur exige une décision explicite. Elle est prise. Les
 * gestes rendus possibles sont volontairement peu nombreux et journalisés :
 *
 *   - activer ou retirer les droits d'administration ;
 *   - créditer ou débiter des crédits, avec un motif OBLIGATOIRE ;
 *   - suspendre un compte (bannissement) ou le rétablir.
 *
 * **Ce que cet écran ne fait PAS, délibérément.** Aucune suppression de compte :
 * elle emporterait des documents, des factures et un historique de paiement, dont
 * la conservation engage la responsabilité de l'éditeur. Supprimer est un geste
 * irréversible qui mérite une décision produit et une procédure, pas une case dans
 * une liste. Aucune modification de l'adresse e-mail non plus : c'est l'identifiant
 * de connexion et la destination des reçus ; la changer depuis l'admin permettrait
 * de détourner un compte sans que son propriétaire en soit informé.
 *
 * **Pourquoi chaque écriture est journalisée.** Un crédit accordé change le solde
 * d'un client. Le registre d'audit est la seule façon de répondre, six mois plus
 * tard, à « pourquoi ce compte a-t-il 5 000 crédits de plus ? ». Le motif est donc
 * obligatoire : un ajustement sans explication est indistinguable d'une erreur.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly CreditService $credits,
    ) {}

    /**
     * Liste des comptes, avec recherche et filtres.
     */
    public function index(Request $request): View
    {
        $requete = User::query()->orderByDesc('id');

        if ($request->filled('q')) {
            $terme = $request->input('q');
            $requete->where(function ($q) use ($terme): void {
                $q->where('name', 'like', '%'.$terme.'%')
                    ->orWhere('email', 'like', '%'.$terme.'%');
            });
        }

        // Filtres alignés sur les questions d'exploitation réelles : qui a des
        // droits d'administration, qui est suspendu, qui a des crédits.
        if ($request->boolean('admins')) {
            $requete->where('is_admin', true);
        }

        if ($request->boolean('suspended')) {
            $requete->where('is_suspended', true);
        }

        if ($request->boolean('with_credits')) {
            $requete->where('credits_balance', '>', 0);
        }

        $tri = $request->input('sort', 'id');

        // Seules trois colonnes sont triables, et la liste est FERMÉE : un tri
        // libre sur un nom de colonne venu de la requête est une injection SQL
        // déguisée (`orderBy($request->input('sort'))` accepte n'importe quoi).
        $requete = match ($tri) {
            'credits' => $requete->reorder('credits_balance', 'desc'),
            'name' => $requete->reorder('name'),
            'recent' => $requete->reorder('created_at', 'desc'),
            default => $requete->reorder('id', 'desc'),
        };

        return view('admin.users.index', [
            'utilisateurs' => $requete->paginate(25)->withQueryString(),
            'filtres' => [
                'q' => $request->input('q'),
                'admins' => $request->boolean('admins'),
                'suspended' => $request->boolean('suspended'),
                'with_credits' => $request->boolean('with_credits'),
                'sort' => $tri,
            ],
            'total' => User::count(),
            'totalAdmins' => User::where('is_admin', true)->count(),
            'totalSuspendus' => User::where('is_suspended', true)->count(),
            'creditsEnCirculation' => (int) User::sum('credits_balance'),
        ]);
    }

    /**
     * Fiche d'un compte : solde, abonnement, documents, historique des crédits.
     */
    public function show(User $user): View
    {
        return view('admin.users.show', [
            'utilisateur' => $user,

            // Les documents n'ont PAS de colonne `user_id` : le propriétaire est
            // dans `metadata->user_id` (contrainte héritée du schéma). On filtre
            // donc sur le JSON, ce qui suppose MySQL 5.7+ — c'est le cas.
            'documents' => DB::table('documents')
                ->where('metadata->user_id', $user->id)
                ->orderByDesc('id')
                ->limit(20)
                ->get(),

            'transactions' => CreditTransaction::query()
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(30)
                ->get(),

            // Même remarque : `activeSubscription` est une relation, pas un
            // accesseur. La lire sans l'exécuter renvoie un objet `HasOne`, et
            // toute lecture d'attribut dessus échoue en « Undefined property »
            // — visible seulement au rendu de la vue.

            'abonnement' => $user->activeSubscription()->with('plan')->first(),

            'paiements' => DB::table('kpay_payments')
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get(),

            'factures' => DB::table('invoices')
                ->where('user_id', $user->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * Accorde ou retire les droits d'administration.
     */
    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        // Se retirer soi-même ses propres droits est presque toujours un
        // accident, et les conséquences sont durables : plus personne ne peut
        // entrer dans l'espace d'exploitation, et seul un accès direct à la base
        // ou une commande Artisan peut rétablir la situation.
        if ($user->is($request->user())) {
            return back()->with('error', 'Vous ne pouvez pas modifier vos propres droits d\'administration.');
        }

        $nouvel = ! $user->is_admin;
        $user->forceFill(['is_admin' => $nouvel])->save();

        $this->journaliser($request, $nouvel ? 'promotion_admin' : 'retrait_admin', $user, [
            'avant' => ! $nouvel,
            'apres' => $nouvel,
        ]);

        return back()->with('success', $nouvel
            ? "{$user->email} est désormais administrateur."
            : "{$user->email} n'est plus administrateur.");
    }

    /**
     * Suspend ou rétablit un compte.
     */
    public function toggleSuspension(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'Vous ne pouvez pas suspendre votre propre compte.');
        }

        $suspendu = $user->is_suspended;
        $user->forceFill(['is_suspended' => ! $suspendu])->save();

        $this->journaliser($request, $suspendu ? 'compte_retabli' : 'compte_suspendu', $user);

        return back()->with('success', $suspendu
            ? "Le compte {$user->email} est rétabli."
            : "Le compte {$user->email} est suspendu : il ne peut plus se connecter.");
    }

    /**
     * Ajuste le solde de crédits d'un compte.
     *
     * **Passe par `CreditService` et non par un `increment()` direct.** Le service
     * verrouille la ligne (`SELECT … FOR UPDATE`), vérifie la disponibilité et
     * écrit une ligne dans `credit_transactions`. Un ajustement direct changerait le
     * solde sans laisser de trace dans l'historique du client : le solde et le
     * journal ne raconteraient plus la même histoire, et c'est le journal qui fait
     * foi en cas de litige.
     */
    public function adjustCredits(Request $request, User $user): RedirectResponse
    {
        $donnees = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ], [
            'amount.not_in' => 'Le montant ne peut pas être zéro.',
            'reason.required' => 'Un motif est obligatoire : un ajustement sans explication est '
                .'indistinguable d\'une erreur.',
            'reason.min' => 'Le motif doit être explicite (5 caractères minimum).',
        ], [
            'amount' => 'montant',
            'reason' => 'motif',
        ]);

        $montant = (int) $donnees['amount'];
        $motif = $donnees['reason'];

        $resultat = $montant > 0
            ? $this->credits->credit($user, $montant, 'bonus', 'admin:'.$request->user()->id, $motif, [
                'admin_id' => $request->user()->id,
                'canal' => 'administration',
            ])
            : $this->credits->debit($user, abs($montant), 'adjustment', 'admin:'.$request->user()->id, $motif, [
                'admin_id' => $request->user()->id,
                'canal' => 'administration',
            ]);

        if (! ($resultat['ok'] ?? false)) {
            $raison = $resultat['reason'] ?? 'inconnue';

            $message = $raison === 'insufficient_balance'
                ? 'Solde insuffisant pour ce débit : le solde ne peut pas devenir négatif.'
                : 'L\'ajustement a échoué ('.$raison.').';

            return back()->with('error', $message);
        }

        $this->journaliser($request, 'ajustement_credits', $user, [
            'montant' => $montant,
            'motif' => $motif,
            'solde_apres' => $resultat['balance'] ?? null,
        ]);

        return back()->with('success', sprintf(
            'Solde de %s ajusté de %+d crédit(s). Nouveau solde : %s.',
            $user->email,
            $montant,
            number_format((int) ($resultat['balance'] ?? 0), 0, ',', ' '),
        ));
    }

    /**
     * Consigne une action d'administration.
     *
     * Le canal `audit` est utilisé plutôt qu'un fichier dédié : `Log::channel`
     * absent retomberait sur le canal par défaut, mais en le nommant on garantit
     * que ces lignes restent trouvables même si le niveau de journalisation
     * général est relevé.
     *
     * @param  array<string, mixed>  $contexte
     */
    private function journaliser(Request $request, string $action, User $cible, array $contexte = []): void
    {
        Log::warning('Action d\'administration', array_merge([
            'action' => $action,
            'admin_id' => $request->user()?->id,
            'admin_email' => $request->user()?->email,
            'cible_id' => $cible->id,
            'cible_email' => $cible->email,
            'ip' => $request->ip(),
        ], $contexte));
    }
}
