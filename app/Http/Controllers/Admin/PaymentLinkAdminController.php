<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\Billing\PaymentLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Liens de paiement, depuis l'espace d'exploitation.
 *
 * **Les deux modes, et pourquoi le second est indispensable.** Un lien `online`
 * ouvre la passerelle KPay. Un lien `offline` est réglé hors passerelle — espèces,
 * virement, mobile money direct — et un administrateur constate l'encaissement.
 *
 * Le second n'est pas un pis-aller théorique : KPay est resté plusieurs jours
 * entièrement indisponible (site en maintenance) pendant le développement de cette
 * fonctionnalité même. Un dispositif qui ne sait encaisser qu'en ligne perd la
 * vente, et un règlement reçu par un autre canal non enregistré crée un écart de
 * comptabilité que personne ne rattrape.
 */
class PaymentLinkAdminController extends Controller
{
    public function __construct(
        private readonly PaymentLinkService $liens,
    ) {}

    /**
     * Liste des liens, avec filtre par état.
     */
    public function index(Request $request): View
    {
        $requete = PaymentLink::query()->with(['user', 'encaissePar'])->orderByDesc('id');

        $filtre = $request->input('etat', 'tous');

        match ($filtre) {
            'attente' => $requete->where('status', PaymentLink::STATUT_EN_ATTENTE)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            'expires' => $requete->where('status', PaymentLink::STATUT_EN_ATTENTE)
                ->where('expires_at', '<=', now()),
            'payes' => $requete->where('status', PaymentLink::STATUT_PAYE),
            'annules' => $requete->where('status', PaymentLink::STATUT_ANNULE),
            default => null,
        };

        return view('admin.payment-links.index', [
            'liens' => $requete->paginate(25)->withQueryString(),
            'filtre' => $filtre,
            'totalEnAttente' => PaymentLink::where('status', PaymentLink::STATUT_EN_ATTENTE)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'totalPayes' => PaymentLink::where('status', PaymentLink::STATUT_PAYE)->count(),
            'montantPaye' => (int) PaymentLink::where('status', PaymentLink::STATUT_PAYE)->sum('amount_fcfa'),
            'totalExpires' => PaymentLink::where('status', PaymentLink::STATUT_EN_ATTENTE)
                ->where('expires_at', '<=', now())
                ->count(),
        ]);
    }

    /**
     * Formulaire de création.
     */
    public function create(): View
    {
        return view('admin.payment-links.create', [
            'utilisateurs' => User::query()->orderBy('email')->get(['id', 'name', 'email']),
            'ttlDefaut' => (int) config('payments.link_ttl_days', 7),
            'montantMin' => (int) config('kpay.min_amount', 500),
        ]);
    }

    /**
     * Crée un lien.
     */
    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'amount_fcfa' => ['required', 'integer', 'min:'.config('kpay.min_amount', 500), 'max:5000000'],
            'mode' => ['required', 'in:online,offline'],
            'label' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
            'ttl_jours' => ['nullable', 'integer', 'min:1', 'max:365'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_label' => ['nullable', 'string', 'max:191'],
            'customer_email' => ['nullable', 'email', 'max:191'],
        ], [
            'amount_fcfa.min' => 'Le montant est inférieur au minimum autorisé (:min FCFA).',
            'user_id.exists' => 'Ce compte n\'existe pas.',
        ], [
            'amount_fcfa' => 'montant',
            'mode' => 'mode de règlement',
            'ttl_jours' => 'durée de validité',
            'user_id' => 'compte destinataire',
            'customer_label' => 'nom du client',
            'customer_email' => 'adresse e-mail du client',
        ]);

        // Un lien en ligne SANS compte destinataire encaisserait sans pouvoir verser
        // les crédits. Le refus est explicite : le message doit dire quoi faire, pas
        // seulement que c'est interdit.
        if ($donnees['mode'] === 'online' && empty($donnees['user_id'])) {
            return back()
                ->withInput()
                ->withErrors([
                    'user_id' => 'Un lien réglable en ligne doit viser un compte existant, '
                        .'sinon les crédits n\'auraient nulle part où être versés. '
                        .'Choisissez un compte, ou passez en règlement hors ligne.',
                ]);
        }

        $lien = $this->liens->creer(
            donnees: [
                'amount_fcfa' => (int) $donnees['amount_fcfa'],
                'mode' => $donnees['mode'],
                'label' => $donnees['label'] ?? null,
                'description' => $donnees['description'] ?? null,
                'user_id' => isset($donnees['user_id']) ? (int) $donnees['user_id'] : null,
                'customer_label' => $donnees['customer_label'] ?? null,
                'customer_email' => $donnees['customer_email'] ?? null,
            ],
            auteur: $request->user(),
            ttlJours: isset($donnees['ttl_jours']) ? (int) $donnees['ttl_jours'] : null,
        );

        $this->journaliser($request, 'creation_lien_paiement', $lien);

        return redirect()
            ->route('admin.payment-links.show', $lien)
            ->with('success', 'Lien de paiement créé. Copiez l\'adresse et transmettez-la au client.');
    }

    /**
     * Détail d'un lien, avec son adresse à transmettre.
     */
    public function show(PaymentLink $paymentLink): View
    {
        return view('admin.payment-links.show', [
            'lien' => $paymentLink->load(['user', 'encaissePar', 'auteur']),
            'utilisable' => $paymentLink->estUtilisable(),
            'expire' => $paymentLink->estExpire(),
            'url' => route('payment-link.show', $paymentLink->token),
        ]);
    }

    /**
     * Constate un règlement hors ligne.
     */
    public function reglerHorsLigne(Request $request, PaymentLink $paymentLink): RedirectResponse
    {
        $donnees = $request->validate([
            'payment_reference' => ['required', 'string', 'min:3', 'max:191'],
        ], [
            'payment_reference.required' => 'Indiquez la référence du règlement : sans elle, '
                .'l\'encaissement est indistinguable d\'un crédit accordé par erreur.',
            'payment_reference.min' => 'La référence doit être explicite (3 caractères minimum).',
        ], [
            'payment_reference' => 'référence du règlement',
        ]);

        // Un lien destiné à la passerelle ne se règle pas à la main : soit le client
        // a payé en ligne et c'est le webhook qui tranche, soit il n'a pas payé. Une
        // constatation manuelle sur un lien en ligne créerait un double versement
        // possible au retour du webhook.
        if ($paymentLink->mode !== PaymentLink::MODE_HORS_LIGNE) {
            return back()->with('error',
                'Ce lien est destiné au règlement en ligne : il sera réglé automatiquement '
                .'à la confirmation de l\'opérateur. Créez un lien « hors ligne » pour un '
                .'encaissement constaté manuellement.');
        }

        $resultat = $this->liens->regler(
            lien: $paymentLink,
            reference: $donnees['payment_reference'],
            encaissePar: $request->user()->id,
        );

        if (! ($resultat['ok'] ?? false)) {
            $motif = $resultat['motif'] ?? 'inconnu';

            $message = match ($motif) {
                'expire' => 'Ce lien est expiré. Le règlement n\'a pas été enregistré : '
                    .'prolongez-le d\'abord, ou créez un nouveau lien.',
                'annule' => 'Ce lien a été annulé.',
                default => 'Le règlement n\'a pas pu être enregistré ('.$motif.').',
            };

            return back()->with('error', $message);
        }

        $this->journaliser($request, 'reglement_hors_ligne', $paymentLink, [
            'reference' => $donnees['payment_reference'],
            'credits_verses' => $resultat['credits_verses'] ?? false,
        ]);

        return back()->with('success', sprintf(
            'Règlement enregistré. %s crédit(s) versés%s.',
            number_format($paymentLink->credits, 0, ',', ' '),
            ($resultat['credits_verses'] ?? false) ? '' : ' (déjà versés précédemment)',
        ));
    }

    /**
     * Annule un lien non réglé.
     */
    public function annuler(Request $request, PaymentLink $paymentLink): RedirectResponse
    {
        if (! $this->liens->annuler($paymentLink)) {
            return back()->with('error', 'Seul un lien en attente peut être annulé.');
        }

        $this->journaliser($request, 'annulation_lien_paiement', $paymentLink);

        return back()->with('success', 'Lien annulé. Il ne peut plus être réglé.');
    }

    /**
     * Prolonge un lien non réglé.
     */
    public function prolonger(Request $request, PaymentLink $paymentLink): RedirectResponse
    {
        $donnees = $request->validate([
            'jours' => ['required', 'integer', 'min:1', 'max:365'],
        ], [], ['jours' => 'nombre de jours']);

        if ($paymentLink->status !== PaymentLink::STATUT_EN_ATTENTE) {
            return back()->with('error', 'Seul un lien en attente peut être prolongé.');
        }

        // La prolongation part de la date d'expiration existante si elle est encore
        // dans le futur : partir d'aujourd'hui RACCOURCIRAIT un lien qui expirait
        // dans six mois, alors que l'intention est de l'allonger.
        $base = $paymentLink->expires_at !== null && $paymentLink->expires_at->isFuture()
            ? $paymentLink->expires_at
            : now();

        $paymentLink->forceFill([
            'expires_at' => $base->copy()->addDays((int) $donnees['jours']),
        ])->save();

        $this->journaliser($request, 'prolongation_lien_paiement', $paymentLink, [
            'jours' => (int) $donnees['jours'],
        ]);

        return back()->with('success', sprintf(
            'Lien prolongé jusqu\'au %s.',
            $paymentLink->fresh()->expires_at->format('d/m/Y à H:i'),
        ));
    }

    /**
     * @param  array<string, mixed>  $contexte
     */
    private function journaliser(Request $request, string $action, PaymentLink $lien, array $contexte = []): void
    {
        Log::warning('Action d\'administration — lien de paiement', array_merge([
            'action' => $action,
            'admin_id' => $request->user()?->id,
            'admin_email' => $request->user()?->email,
            'lien_id' => $lien->id,
            'montant_fcfa' => $lien->amount_fcfa,
            'ip' => $request->ip(),
        ], $contexte));
    }
}
