<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\KpayPayment;
use App\Models\PaymentLink;
use App\Services\Billing\CreditService;
use App\Services\Billing\KPayService;
use App\Services\Billing\MonetbilService;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\PaymentLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Page publique de règlement d'un lien de paiement.
 *
 * **Pourquoi une page à nous plutôt qu'un lien direct vers KPay.** L'URL KPay
 * (`gatewayUrl`) est éphémère : elle est générée par un `POST /payments/init` et
 * expire. Un lien qu'on transmet à un client doit rester valable plusieurs jours,
 * donc il doit pointer vers quelque chose de stable — et c'est notre page qui
 * ouvre la passerelle au moment du règlement.
 *
 * Cette page remplit aussi trois rôles qu'un lien direct ne permettrait pas :
 * afficher le montant et l'objet AVANT que le client ne clique (un lien opaque
 * n'inspire pas confiance), refuser un lien expiré avec une explication, et
 * proposer une issue quand la passerelle est indisponible.
 *
 * **Aucune authentification.** Le destinataire d'un lien n'a pas de compte : c'est
 * la raison d'être de la fonctionnalité. La sécurité repose donc entièrement sur le
 * jeton, d'où sa longueur et son unicité.
 */
class PaymentLinkController extends Controller
{
    public function __construct(
        private readonly PaymentLinkService $liens,
        private readonly KPayService $kpay,
        private readonly MonetbilService $monetbil,
        private readonly PaymentGatewayRegistry $passerelles,
        private readonly CreditService $credits,
    ) {}

    /**
     * Page de règlement d'un lien.
     *
     * **La page CONSTATE le règlement, elle n'attend pas qu'une tâche planifiée le
     * fasse.** Le retour de la passerelle et la notification peuvent tous deux
     * échouer ou arriver en retard. Sans constatation ici, l'utilisateur verrait
     * « en attente » indéfiniment alors que son argent est parti, et sa seule
     * issue serait d'attendre qu'un cron passe — or un cron peut ne pas tourner.
     * Un paiement ne doit JAMAIS dépendre d'une tâche planifiée pour être constaté.
     *
     * On interroge donc la passerelle au chargement de la page, en direct. Le
     * règlement reste idempotent (`regler()`), donc interroger plusieurs fois ne
     * verse jamais deux fois. Le cron de synchronisation garde son rôle de filet
     * pour les liens que PERSONNE ne rouvre — il n'est plus le chemin principal.
     */
    public function show(string $token): View
    {
        $lien = PaymentLink::where('token', $token)->first();

        // Jeton inconnu : 404. Répondre une page « ce lien n'existe pas » avec un
        // code 200 laisserait croire qu'il a existé, et permettrait d'énumérer.
        abort_if($lien === null, 404);

        $lien = $this->constaterEnDirect($lien);

        return view('payments.link', [
            'lien' => $lien,
            'utilisable' => $lien->estUtilisable(),
            'expire' => $lien->estExpire(),
            // Les moyens PROPOSABLES, pas tous : le registre combine configuré,
            // actif et affiché. Proposer un moyen non configuré mènerait le client
            // vers une page d'erreur de la passerelle.
            'moyens' => $this->passerelles->proposables(),
        ]);
    }

    /**
     * Interroge la passerelle pour constater un règlement, sans attendre le cron.
     *
     * **On ne tente la vérification que sur un lien en attente, et seulement s'il
     * est rattaché à une passerelle.** Les autres cas sont des états définitifs :
     * interroger l'API pour un lien déjà réglé gaspillerait un appel à chaque
     * affichage de page.
     *
     * Un échec d'interrogation n'est PAS remonté à l'utilisateur : la page doit
     * s'afficher normalement. Le cron, lui, retentera — c'est son rôle de filet.
     */
    private function constaterEnDirect(PaymentLink $lien): PaymentLink
    {
        if ($lien->status !== PaymentLink::STATUT_EN_ATTENTE || $lien->gateway === null) {
            return $lien;
        }

        if (! $this->passerelles->estAutomatique($lien->gateway)) {
            return $lien;
        }

        if ($lien->gateway === PaymentGatewayRegistry::KPAY) {
            return $this->constaterKpay($lien);
        }

        // Monetbil : l'identifiant de transaction n'est connu qu'à l'arrivée de la
        // notification, jamais du retour. Sans lui, il n'y a rien à interroger —
        // c'est `notify()` qui constate, et il le fait immédiatement.
        return $lien;
    }

    /**
     * Constatation KPay : le `payment_id` est stocké localement à l'initiation.
     */
    private function constaterKpay(PaymentLink $lien): PaymentLink
    {
        $local = KpayPayment::query()
            ->where('metadata->payment_link_id', $lien->id)
            ->latest('id')
            ->first();

        if ($local === null || empty($local->payment_id)) {
            return $lien;
        }

        $detail = $this->kpay->getPayment((string) $local->payment_id);

        if (($detail['status'] ?? null) !== 'COMPLETED') {
            return $lien;
        }

        $resultat = $this->liens->regler(
            lien: $lien,
            reference: (string) $local->payment_id,
            kpayPaymentId: $local->id,
        );

        if (! ($resultat['ok'] ?? false)) {
            Log::warning('Constatation en direct : règlement refusé', [
                'lien_id' => $lien->id,
                'motif' => $resultat['motif'] ?? null,
            ]);

            return $lien;
        }

        Log::info('Constatation en direct d\'un paiement KPay', [
            'lien_id' => $lien->id,
            'payment_id' => $local->payment_id,
        ]);

        return $lien->fresh() ?? $lien;
    }

    /**
     * Ouvre la passerelle choisie pour régler ce lien.
     *
     * **Pourquoi le moyen est choisi sur notre page, et non chez le fournisseur.**
     * Chaque passerelle a son propre widget : il faut donc décider AVANT de
     * rediriger. Le client voit les moyens réellement disponibles (le registre
     * écarte ceux qui ne le sont pas) et choisit — au lieu d'être envoyé vers un
     * fournisseur imposé, qui tomberait sans recours.
     */
    public function payer(Request $request, string $token): RedirectResponse
    {
        $lien = PaymentLink::where('token', $token)->first();

        abort_if($lien === null, 404);

        if (! $lien->estUtilisable()) {
            return redirect()
                ->route('payment-link.show', $token)
                ->with('error', 'Ce lien n\'est plus utilisable.');
        }

        // Aucun compte à créditer : le règlement en ligne n'aurait nulle part où
        // verser les crédits. On refuse plutôt que d'encaisser sans contrepartie.
        if ($lien->user_id === null) {
            return redirect()
                ->route('payment-link.show', $token)
                ->with('error', 'Ce lien doit être réglé hors ligne : aucun compte n\'y est rattaché.');
        }

        $passerelle = (string) $request->input('gateway', PaymentGatewayRegistry::KPAY);

        // Le moyen demandé est revalidé CÔTÉ SERVEUR : un formulaire trafiqué
        // pourrait sinon demander une passerelle désactivée ou non configurée, et
        // obtenir malgré tout une redirection.
        if (! $this->passerelles->estProposable($passerelle)) {
            Log::warning('Lien de paiement : moyen de paiement non proposable', [
                'lien_id' => $lien->id,
                'passerelle' => $passerelle,
            ]);

            return redirect()
                ->route('payment-link.show', $token)
                ->with('error', 'Ce moyen de paiement n\'est pas disponible.');
        }

        return $passerelle === PaymentGatewayRegistry::MONETBIL
            ? $this->payerParMonetbil($lien, $token)
            : $this->payerParKpay($lien, $token);
    }

    /**
     * Règlement par KPay : initialisation serveur puis redirection.
     */
    private function payerParKpay(PaymentLink $lien, string $token): RedirectResponse
    {
        // Identifiant unique : KPay rejette un `externalId` déjà actif (409).
        // Réutiliser celui du lien ferait échouer une seconde tentative légitime
        // après un abandon du client, alors que la première transaction n'est pas
        // terminée côté opérateur.
        $externalId = 'LINK-'.$lien->id.'-'.Str::uuid();

        $resultat = $this->kpay->initGatewayPayment(
            amountFcfa: (int) $lien->amount_fcfa,
            externalId: $externalId,
            returnUrl: route('payment-link.retour', $token),
            cancelUrl: route('payment-link.show', $token),
            // La devise vient de la PASSERELLE, pas d'une constante globale : le
            // lien porte la devise figée à sa création, et c'est elle qui fait foi
            // (elle a été annoncée au client).
            currency: (string) $lien->currency,
            metadata: [
                'payment_link_id' => $lien->id,
                'purpose' => 'payment_link',
                'credits' => $lien->credits,
            ],
            customerEmail: $lien->customer_email,
        );

        if (! ($resultat['ok'] ?? false)) {
            Log::warning('Lien de paiement : initiation KPay échouée', [
                'lien_id' => $lien->id,
                'message' => $resultat['message'] ?? null,
            ]);

            return redirect()
                ->route('payment-link.show', $token)
                ->with('error', $resultat['message'] ?? 'La passerelle de paiement est indisponible.');
        }

        // Trace locale, comme pour un achat de crédits : elle permet la
        // synchronisation de secours si le webhook n'arrive pas.
        KpayPayment::create([
            'user_id' => $lien->user_id,
            'payment_id' => $resultat['paymentId'] ?? null,
            'external_id' => $externalId,
            'status' => 'PENDING',
            'purpose' => 'payment_link',
            'amount_fcfa' => (int) $lien->amount_fcfa,
            'currency' => $lien->currency,
            'return_url' => route('payment-link.retour', $token),
            'cancel_url' => route('payment-link.show', $token),
            'metadata' => [
                'payment_link_id' => $lien->id,
                'credits' => $lien->credits,
            ],
            'expires_at' => isset($resultat['expiresAt'])
                ? now()->parse($resultat['expiresAt'])
                : now()->addHours(24),
        ]);

        // La passerelle est FIGÉE sur le lien : si KPay devient indisponible
        // entre-temps, la synchronisation de secours doit interroger KPay — c'est
        // là que la transaction existe, pas ailleurs.
        $lien->forceFill(['gateway' => PaymentGatewayRegistry::KPAY])->save();

        return redirect()->away($resultat['gatewayUrl']);
    }

    /**
     * Règlement par Monetbil : widget signé vers lequel on redirige.
     *
     * **Pourquoi il n'y a pas de trace locale, contrairement à KPay.** KPay
     * renvoie un `paymentId` que l'on peut interroger plus tard ; Monetbil ne
     * renvoie qu'une URL de paiement, et l'identifiant de transaction n'est connu
     * qu'à l'arrivée de la notification. La synchronisation de secours ne peut
     * donc pas partir d'un identifiant stocké à l'initiation — c'est la
     * notification, ou l'absence de paiement, qui font foi. Créer une ligne avec un
     * identifiant inventé donnerait une fausse impression de traçabilité.
     */
    private function payerParMonetbil(PaymentLink $lien, string $token): RedirectResponse
    {
        // Référence unique, comme l'`externalId` de KPay : elle identifie la
        // tentative et permet de rattacher la notification au bon lien. On y met
        // l'identifiant du lien ET un identifiant aléatoire, pour que deux
        // tentatives successives du même client ne soient pas confondues.
        $paymentRef = 'LINK'.$lien->id.'-'.Str::uuid();

        $resultat = $this->monetbil->url(
            amountFcfa: (int) $lien->amount_fcfa,
            paymentRef: $paymentRef,
            returnUrl: route('payment-link.retour', $token),
            notifyUrl: route('payment-link.notify', $token),
            itemRef: 'LINK'.$lien->id,
            customerEmail: $lien->customer_email,
        );
        if (! ($resultat['ok'] ?? false)) {
            Log::warning('Lien de paiement : initiation Monetbil échouée', [
                'lien_id' => $lien->id,
                'message' => $resultat['message'] ?? null,
            ]);

            return redirect()
                ->route('payment-link.show', $token)
                ->with('error', $resultat['message'] ?? 'La passerelle de paiement est indisponible.');
        }

        $lien->forceFill(['gateway' => PaymentGatewayRegistry::MONETBIL])->save();

        return redirect()->away($resultat['paymentUrl']);
    }

    /**
     * Retour de la passerelle, quelle qu'elle soit.
     *
     * **La redirection n'est PAS une preuve de paiement.** Le client peut rejouer
     * l'URL de retour, ou la modifier. On vérifie donc la provenance, puis on
     * interroge la passerelle sur le statut RÉEL avant de verser quoi que ce soit.
     *
     * **Pourquoi la logique diverge selon la passerelle.** Les deux protocoles
     * n'ont pas le même modèle de confiance :
     *
     *  - **KPay** signe son retour (`sig` = HMAC de `status|reference|externalId|ts`,
     *    avec rejet au-delà de 10 minutes). La signature peut donc servir de
     *    contrôle d'entrée, et le statut est confirmé ensuite par un appel API.
     *  - **Monetbil** ne signe PAS son retour : les paramètres d'arrivée n'ont
     *    aucune valeur probante. On ne peut donc rien en déduire — la seule source
     *    fiable est la notification, ou l'interrogation de l'API avec un
     *    identifiant de transaction que le retour peut ne pas contenir.
     *
     * Dans les deux cas, le règlement effectif reste le même : `PaymentLinkService::
     * regler()`, idempotent sous verrou. C'est lui qui garantit qu'un versement
     * n'a lieu qu'une fois, même si le retour ET la notification arrivent.
     */
    public function retour(Request $request, string $token): RedirectResponse
    {
        $lien = PaymentLink::where('token', $token)->first();

        abort_if($lien === null, 404);

        if ($lien->gateway === PaymentGatewayRegistry::MONETBIL) {
            // Aucune signature à vérifier côté Monetbil. On ne devine pas le statut
            // depuis l'URL de retour : la notification est la seule source, et elle
            // arrivera (ou non). Afficher un message d'attente est plus honnête
            // qu'un succès optimiste suivi d'un remboursement.
            return redirect()
                ->route('payment-link.show', $token)
                ->with('info', 'Votre paiement est en cours de confirmation par l\'opérateur. '
                    .'Cette page se met à jour dès réception de la confirmation.');
        }

        if (! $this->kpay->verifyReturnSignature($request->query())) {
            Log::warning('Lien de paiement : signature de retour invalide', [
                'lien_id' => $lien->id,
                'query' => $request->query(),
            ]);

            return redirect()
                ->route('payment-link.show', $token)
                ->with('error', 'La confirmation de paiement n\'a pas pu être vérifiée.');
        }

        // Confirmation auprès de l'API : la signature prouve l'origine, pas le
        // statut. Sans cette étape, un `status=COMPLETED` forgé mais correctement
        // signé — cas d'un lien de retour rejoué — suffirait à verser les crédits.
        $paymentId = (string) $request->query('reference', '');

        if ($paymentId !== '') {
            $detail = $this->kpay->getPayment($paymentId);
            $statut = $detail['status'] ?? null;

            if ($statut === 'COMPLETED') {
                $kpayLocal = KpayPayment::where('payment_id', $paymentId)->first();

                $resultat = $this->liens->regler(
                    lien: $lien,
                    reference: $paymentId,
                    kpayPaymentId: $kpayLocal?->id,
                );

                if (! ($resultat['ok'] ?? false)) {
                    return redirect()
                        ->route('payment-link.show', $token)
                        ->with('error', 'Le règlement n\'a pas pu être enregistré. Contactez le support.');
                }

                return redirect()
                    ->route('payment-link.show', $token)
                    ->with('success', 'Paiement confirmé. Merci !');
            }
        }

        // Statut non confirmé : on ne devine pas. Le webhook, lui, reste la source
        // d'autorité et règlera le lien s'il arrive.
        return redirect()
            ->route('payment-link.show', $token)
            ->with('info', 'Le paiement n\'est pas encore confirmé par l\'opérateur. '
                .'Cette page se met à jour dès réception de la confirmation.');
    }

    /**
     * Notification Monetbil (équivalent du webhook KPay).
     *
     * **Ce qui autorise le versement, et dans quel ordre.**
     *
     *  1. La SIGNATURE est vérifiée : elle prouve que l'émetteur connaît le secret
     *     partagé. Elle ne prouve pas que la notification est récente — Monetbil
     *     signe le secret suivi des valeurs, sans horodatage, donc une notification
     *     capturée reste rejouable. C'est pourquoi l'étape 3 est indispensable.
     *  2. Le `transaction_id` est extrait pour interroger l'API.
     *  3. Le statut RÉEL est demandé à l'API. C'est la seule confirmation du
     *     paiement : la notification n'est qu'un signal d'arrivée.
     *  4. Le règlement passe par `regler()`, idempotent sous verrou. Un rejeu de la
     *     notification ne peut donc PAS verser deux fois — la protection contre le
     *     double paiement repose sur cette idempotence, pas sur la signature.
     *  5. Monetbil attend la chaîne `received` en réponse. Répondre autre chose fait
     *     considérer la notification comme non délivrée et déclenche des réessais.
     */
    public function notify(Request $request, string $token): Response
    {
        $lien = PaymentLink::where('token', $token)->first();

        if ($lien === null) {
            // Le lien a disparu : répondre 200 évite des réessais sans fin pour une
            // notification qu'on ne pourra jamais rattacher à quoi que ce soit.
            return response('received');
        }

        $parametres = $request->all();

        if (! $this->monetbil->signatureValide($parametres)) {
            Log::warning('Monetbil : signature de notification invalide', [
                'lien_id' => $lien->id,
                'ip' => $request->ip(),
            ]);

            return response('received');
        }

        $transactionId = (string) ($parametres['transaction_id'] ?? '');

        // Le statut annoncé est journalisé, mais jamais utilisé pour décider : c'est
        // l'appel à l'API qui tranche. Un `status` forgé ne doit avoir aucun effet.
        $statutAnnonce = (int) ($parametres['status'] ?? 0);

        $verification = $this->monetbil->checkPayment($transactionId);

        Log::info('Monetbil : notification reçue', [
            'lien_id' => $lien->id,
            'transaction_id' => $transactionId,
            'statut_annonce' => $statutAnnonce,
            'verifie' => $verification['ok'],
            'statut_reel' => $verification['statut'],
            'testmode' => $verification['testmode'],
        ]);

        if ($verification['ok'] && $verification['succes']) {
            $resultat = $this->liens->regler(
                lien: $lien,
                reference: $transactionId !== '' ? $transactionId : 'monetbil:'.$lien->id,
            );

            if (! ($resultat['ok'] ?? false)) {
                // Un refus (lien expiré, annulé, destinataire introuvable) est
                // journalisé par le service. On répond tout de même `received` :
                // faire réessayer ne changerait rien, et un litige se règle à la
                // main à partir de cette trace.
                Log::warning('Monetbil : règlement refusé', [
                    'lien_id' => $lien->id,
                    'motif' => $resultat['motif'] ?? null,
                ]);
            }
        }

        return response('received');
    }
}
