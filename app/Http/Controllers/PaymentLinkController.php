<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\KpayPayment;
use App\Models\PaymentLink;
use App\Services\Billing\CreditService;
use App\Services\Billing\KPayService;
use App\Services\Billing\PaymentLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        private readonly CreditService $credits,
    ) {}

    /**
     * Page de règlement d'un lien.
     */
    public function show(string $token): View
    {
        $lien = PaymentLink::where('token', $token)->first();

        // Jeton inconnu : 404. Répondre une page « ce lien n'existe pas » avec un
        // code 200 laisserait croire qu'il a existé, et permettrait d'énumérer.
        abort_if($lien === null, 404);

        return view('payments.link', [
            'lien' => $lien,
            'utilisable' => $lien->estUtilisable(),
            'expire' => $lien->estExpire(),
        ]);
    }

    /**
     * Ouvre la passerelle KPay pour régler ce lien.
     */
    public function payer(string $token): RedirectResponse
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
            currency: $lien->currency,
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

        return redirect()->away($resultat['gatewayUrl']);
    }

    /**
     * Retour de la passerelle.
     *
     * **La redirection n'est PAS une preuve de paiement.** Le client peut rejouer
     * l'URL de retour, ou la modifier. On vérifie donc la signature, puis on
     * interroge KPay sur le statut réel avant de verser quoi que ce soit — règle
     * explicite de la documentation : « ne marquez la commande payée qu'après
     * signature VALIDE ET statut COMPLETED confirmé via GET /api/v1/payments/:id ».
     */
    public function retour(Request $request, string $token): RedirectResponse
    {
        $lien = PaymentLink::where('token', $token)->first();

        abort_if($lien === null, 404);

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
        $statut = null;

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
}
