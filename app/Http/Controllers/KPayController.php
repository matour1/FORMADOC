<?php

namespace App\Http\Controllers;

use App\Services\Billing\CreditService;
use App\Services\Billing\KPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Achat de crédits via KPay (passerelle carte/PayPal).
 *
 * Flux :
 *   POST /credits/purchase  → init KPay (externalId unique) → redirection gatewayUrl
 *   GET  /credits/return    → retour passerelle (signé) → redirection compte
 *   GET  /credits/cancel    → annulation → redirection compte
 *   POST /kpay/webhook      → notification KPay (source d'autorité du crédit)
 *
 * Règle de sécurité : le solde n'est JAMAIS crédité sur le simple retour
 * passerelle (non fiable). Seul le webhook payment.completed, signé HMAC
 * et vérifié, crédite l'utilisateur (idempotence par metadata.status).
 */
class KPayController extends Controller
{
    public function __construct(
        private readonly KPayService $kpay,
        private readonly CreditService $credits,
    ) {
    }

    /**
     * Initialise un achat de crédits et redirige vers la passerelle KPay.
     */
    public function initPurchase(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'integer', 'min:'.config('kpay.min_amount', 500), 'max:500000'],
        ]);

        $amountFcfa = (int) $request->integer('amount');
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login')->with('error', 'Connectez-vous pour acheter des crédits.');
        }

        // Identifiant unique pour l'idempotence KPay (409 si déjà actif)
        $externalId = 'CREDIT-'.$user->id.'-'.Str::uuid();

        $result = $this->kpay->initGatewayPayment(
            amountFcfa: $amountFcfa,
            externalId: $externalId,
            returnUrl: route('kpay.return'),
            cancelUrl: route('kpay.cancel'),
            currency: config('kpay.currency', 'XAF'),
            metadata: [
                'user_id' => $user->id,
                'purpose' => 'credit_purchase',
                'amount_fcfa' => $amountFcfa,
                'credits' => $amountFcfa, // 1 crédit = 1 FCFA
            ],
        );

        if (! $result['ok']) {
            return back()->with('error', $result['message'] ?? 'Échec de l\'initialisation du paiement.');
        }

        Log::info('Achat crédits initié', [
            'user_id' => $user->id,
            'amount_fcfa' => $amountFcfa,
            'external_id' => $externalId,
            'payment_id' => $result['paymentId'] ?? null,
        ]);

        return redirect()->away($result['gatewayUrl']);
    }

    /**
     * Retour de la passerelle (statut non fiable — on redirige vers le compte).
     * Le crédit sera appliqué par le webhook payment.completed.
     */
    public function return(Request $request): RedirectResponse
    {
        // Signature vérifiée ? Si oui, on peut afficher l'état (mais pas créditer)
        $verified = $this->kpay->verifyReturnSignature($request->query());

        Log::info('Retour passerelle KPay', [
            'status' => $request->query('status'),
            'externalId' => $request->query('externalId'),
            'verified' => $verified,
        ]);

        if ($request->query('status') === 'COMPLETED') {
            return redirect()->route('account.index')
                ->with('info', 'Paiement reçu ! Vos crédits seront ajoutés dans quelques instants (confirmation KPay).');
        }

        return redirect()->route('account.index')
            ->with('error', 'Paiement annulé ou échoué. Aucun crédit débité.');
    }

    /**
     * Annulation par l'utilisateur depuis la passerelle.
     */
    public function cancel(Request $request): RedirectResponse
    {
        return redirect()->route('account.index')
            ->with('error', 'Achat de crédits annulé.');
    }

    /**
     * Webhook KPay — SOURCE D'AUTORITÉ du crédit.
     *
     * - Vérifie la signature HMAC sur le corps brut
     * - Ne traite QUE les statuts terminaux (completed/failed/cancelled)
     * - Idempotent via metadata.status sur la transaction de crédit
     * - Répond 200 immédiatement (traitement léger)
     */
    public function webhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-KPAY-Signature', '');
        $event = $request->header('X-KPAY-Event', '');

        // 1. Vérification de la signature
        if (! $this->kpay->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('KPay webhook : signature invalide', [
                'event' => $event,
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = $request->json()->all();
        $status = strtolower((string) ($payload['status'] ?? $payload['event'] ?? ''));
        $paymentId = (string) ($payload['paymentId'] ?? '');
        $externalId = (string) ($payload['externalId'] ?? '');
        $amount = (int) ($payload['amount'] ?? 0);
        $metadata = (array) ($payload['metadata'] ?? []);

        Log::info('KPay webhook reçu', [
            'event' => $event,
            'status' => $status,
            'paymentId' => $paymentId,
            'externalId' => $externalId,
        ]);

        // 2. Seuls les statuts terminaux engagent une décision métier
        if (! in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            return response()->json(['status' => 'ignored_non_terminal'], 200);
        }

        // 3. Récupérer l'utilisateur depuis les métadonnées
        $userId = (int) ($metadata['user_id'] ?? 0);
        $user = $userId > 0 ? \App\Models\User::find($userId) : null;

        if (! $user) {
            Log::warning('KPay webhook : utilisateur introuvable', ['user_id' => $userId, 'paymentId' => $paymentId]);
            return response()->json(['status' => 'user_not_found'], 200);
        }

        // 4. Idempotence : si ce paymentId a déjà été traité → ignorer
        $alreadyProcessed = \App\Models\CreditTransaction::query()
            ->where('reference', $paymentId)
            ->where('type', 'purchase')
            ->exists();

        if ($alreadyProcessed) {
            return response()->json(['status' => 'already_processed'], 200);
        }

        // 5. Décision métier
        if ($status === 'completed') {
            $credits = (int) ($metadata['credits'] ?? $amount);

            $this->credits->credit(
                $user,
                $credits,
                type: 'purchase',
                reference: $paymentId,
                description: 'Achat de '.$credits.' crédits via KPay',
                metadata: [
                    'purpose' => 'credit_purchase',
                    'payment_status' => 'completed',
                    'payment_id' => $paymentId,
                    'external_id' => $externalId,
                    'amount_fcfa' => $amount,
                ],
            );

            Log::info('KPay webhook : crédits ajoutés', [
                'user_id' => $user->id,
                'credits' => $credits,
                'paymentId' => $paymentId,
            ]);
        } else {
            // failed / cancelled → journaliser (aucun crédit)
            Log::info('KPay webhook : paiement non abouti', [
                'status' => $status,
                'paymentId' => $paymentId,
                'user_id' => $user->id,
            ]);
        }

        return response()->json(['status' => 'processed'], 200);
    }
}
