<?php

namespace App\Http\Controllers;

use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceipt;
use App\Models\KpayPayment;
use App\Models\PaymentLink;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CreditService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\KPayService;
use App\Services\Billing\PaymentLinkService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
    ) {}

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
            customerEmail: $user->email,
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

        // Enregistrer le paiement pour la synchronisation de secours (fallback webhook)
        KpayPayment::create([
            'user_id' => $user->id,
            'payment_id' => $result['paymentId'] ?? null,
            'external_id' => $externalId,
            'status' => 'PENDING',
            'purpose' => 'credit_purchase',
            'amount_fcfa' => $amountFcfa,
            'currency' => config('kpay.currency', 'XAF'),
            'return_url' => route('kpay.return'),
            'cancel_url' => route('kpay.cancel'),
            'metadata' => [
                'user_id' => $user->id,
                'purpose' => 'credit_purchase',
                'amount_fcfa' => $amountFcfa,
                'credits' => $amountFcfa,
            ],
            'expires_at' => isset($result['expiresAt']) ? now()->parse($result['expiresAt']) : now()->addHours(24),
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
        //
        // **Deux sources, et l'ordre compte.** La métadonnée `user_id` est fournie
        // par les flux qui connaissent l'utilisateur (achat de crédits, abonnement).
        // Un lien de paiement, lui, transmet `payment_link_id` et pas `user_id` :
        // sans ce repli, le webhook répondait `user_not_found` et SORTAIT, avant
        // même d'examiner le `purpose`. Le lien n'était donc jamais réglé — le
        // montant était encaissé côté opérateur et le lien restait « en attente »,
        // donc payable une seconde fois.
        $userId = (int) ($metadata['user_id'] ?? 0);

        if ($userId === 0 && (int) ($metadata['payment_link_id'] ?? 0) > 0) {
            $userId = (int) (PaymentLink::whereKey((int) $metadata['payment_link_id'])->value('user_id') ?? 0);
        }

        $user = $userId > 0 ? User::find($userId) : null;

        // Un lien de paiement sans destinataire (client externe) est légitime :
        // le règlement doit être enregistré même sans compte à créditer. Exiger un
        // utilisateur ici perdrait la trace du paiement — c'est pourquoi le repli
        // ci-dessous existe plutôt qu'un simple `return`.
        $purpose = (string) ($metadata['purpose'] ?? 'credit_purchase');
        $estLienSansCompte = $purpose === 'payment_link' && (int) ($metadata['payment_link_id'] ?? 0) > 0;

        if (! $user && ! $estLienSansCompte) {
            Log::warning('KPay webhook : utilisateur introuvable', ['user_id' => $userId, 'paymentId' => $paymentId]);

            return response()->json(['status' => 'user_not_found'], 200);
        }

        // 4. Décision métier
        // (L'idempotence des achats de crédits est garantie ATOMIQUEMENT par
        // CreditService::creditIfNotProcessed — vérification + crédit dans la
        // même transaction, sérialisée par lockForUpdate sur la ligne user.
        // Le check-then-act historique (exists() puis credit()) laissait une
        // fenêtre de course où deux webhooks concurrents pouvaient doubler.)

        if ($status === 'completed') {
            // --- Lien de paiement -------------------------------------------
            //
            // Traité AVANT l'achat de crédits, et pour une raison précise : sans
            // cette branche, un lien de paiement réglé en ligne tombait dans le
            // chemin « achat de crédits », qui créditait le montant au nom de
            // l'utilisateur SANS jamais marquer le lien comme payé. Le lien restait
            // « en attente » alors que l'argent était encaissé — donc payable une
            // seconde fois — et rien ne reliait le versement au lien d'origine.
            //
            // Le règlement passe par `PaymentLinkService`, qui verrouille la ligne
            // et garantit un versement unique même si le retour de passerelle a déjà
            // traité le paiement : c'est le cas nominal, pas théorique.
            if ($purpose === 'payment_link') {
                $lienId = (int) ($metadata['payment_link_id'] ?? 0);
                $lien = $lienId > 0 ? PaymentLink::find($lienId) : null;

                if ($lien === null) {
                    Log::warning('KPay webhook : lien de paiement introuvable', [
                        'payment_link_id' => $lienId,
                        'paymentId' => $paymentId,
                    ]);

                    return response()->json(['status' => 'link_not_found'], 200);
                }

                $kpayLocal = KpayPayment::where('external_id', $externalId)->first();

                $reglement = app(PaymentLinkService::class)->regler(
                    lien: $lien,
                    reference: $paymentId,
                    kpayPaymentId: $kpayLocal?->id,
                );

                Log::info('KPay webhook : lien de paiement traité', [
                    'lien_id' => $lien->id,
                    'paymentId' => $paymentId,
                    'ok' => $reglement['ok'] ?? false,
                    'motif' => $reglement['motif'] ?? null,
                    'credits_verses' => $reglement['credits_verses'] ?? null,
                ]);

                // Un échec de règlement (lien expiré entre-temps, destinataire
                // introuvable) n'est PAS une erreur du webhook : répondre 500 ferait
                // réessayer KPay indéfiniment sur une situation qu'un réessai ne
                // résoudra pas. On répond 200 et on laisse la trace pour traitement
                // manuel.
                return response()->json([
                    'status' => ($reglement['ok'] ?? false) ? 'processed' : 'link_not_registrable',
                ], 200);
            }

            // --- Abonnement (souscription ou renouvellement) ---
            if (in_array($purpose, ['subscription', 'subscription_renewal'], true)) {
                $activated = app(SubscriptionService::class)
                    ->activateFromWebhook($payload, $metadata);

                Log::info('KPay webhook : abonnement traité', [
                    'user_id' => $user->id,
                    'purpose' => $purpose,
                    'paymentId' => $paymentId,
                    'activated' => $activated,
                ]);

                return response()->json(['status' => 'processed'], 200);
            }

            // --- Achat de crédits (comportement historique) ---
            $credits = (int) ($metadata['credits'] ?? $amount);

            $result = $this->credits->creditIfNotProcessed(
                $user,
                $credits,
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

            // Déjà crédité (webhook dupliqué / retry KPay) → réponse idempotente
            if (($result['already_processed'] ?? false)) {
                Log::info('KPay webhook : paiement déjà traité (idempotence)', [
                    'user_id' => $user->id,
                    'paymentId' => $paymentId,
                    'credits' => $credits,
                ]);

                // On met quand même à jour le registre local de fallback
                KpayPayment::query()
                    ->where('external_id', $externalId)
                    ->update([
                        'status' => strtoupper($status),
                        'paid_at' => $status === 'completed' ? now() : null,
                        'last_synced_at' => now(),
                    ]);

                return response()->json(['status' => 'already_processed'], 200);
            }

            if (! ($result['ok'] ?? false)) {
                Log::error('KPay webhook : crédit impossible', [
                    'user_id' => $user->id,
                    'paymentId' => $paymentId,
                    'credits' => $credits,
                ]);

                return response()->json(['status' => 'credit_failed'], 500);
            }

            // Facture PDF + email de confirmation
            $invoiceService = app(InvoiceService::class);
            $invoice = $invoiceService->createForCreditPurchase(
                $user,
                amount: $amount,
                currency: (string) ($payload['currency'] ?? 'XAF'),
                reference: $paymentId,
                status: 'paid',
            );

            // Chemin absolu du PDF pour la pièce jointe
            $pdfStoragePath = $invoiceService->downloadPath($invoice);
            $pdfPath = $pdfStoragePath
                ? Storage::disk((string) config('billing.invoice_storage_disk', 'local'))
                    ->path($pdfStoragePath)
                : null;

            try {
                Mail::to($user->email)->send(new PaymentReceipt(
                    user: $user,
                    invoice: $invoice,
                    label: 'Achat de '.$credits.' crédits',
                    pdfPath: $pdfPath,
                ));
                Log::info('KPay webhook : email de reçu envoyé', [
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'paymentId' => $paymentId,
                ]);
            } catch (\Throwable $e) {
                Log::error('KPay webhook : échec envoi email de reçu', [
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('KPay webhook : crédits ajoutés', [
                'user_id' => $user->id,
                'credits' => $credits,
                'paymentId' => $paymentId,
                'invoice_id' => $invoice->id,
            ]);
        } else {
            // failed / cancelled → journaliser (aucun crédit).
            //
            // **Un lien de paiement s'arrête ici.** Son échec ne se traite pas comme
            // celui d'un achat de crédits : il n'y a rien à rembourser (aucun crédit
            // n'a été versé) et rien à notifier par e-mail. Le lien reste
            // simplement « en attente », ce qui est le bon état : le client peut
            // réessayer tant qu'il n'est pas expiré. Envoyer un e-mail d'échec au
            // destinataire serait même contre-productif — les abandons de paiement
            // mobile money sont fréquents et souvent involontaires.
            if ($purpose === 'payment_link') {
                Log::info('KPay webhook : règlement de lien échoué ou annulé', [
                    'paymentId' => $paymentId,
                    'status' => $status,
                    'reason' => $payload['failureReason'] ?? null,
                ]);

                return response()->json(['status' => 'link_not_paid'], 200);
            }

            // Un lien de paiement sans compte destinataire n'a pas d'utilisateur à
            // qui écrire : on ne va pas plus loin plutôt que de déréférencer null.
            if ($user === null) {
                return response()->json(['status' => 'no_user_to_notify'], 200);
            }

            // Pour un abonnement : marquer past_due (grace period)
            $failedSubscription = null;
            if (in_array($purpose, ['subscription', 'subscription_renewal'], true)) {
                $subscriptionId = (int) ($metadata['subscription_id'] ?? 0);
                $subscription = $subscriptionId > 0
                    ? Subscription::find($subscriptionId)
                    : $user->activeSubscription;

                if ($subscription) {
                    app(SubscriptionService::class)
                        ->markFailedPayment($subscription);
                    $failedSubscription = $subscription;
                }
            }

            // Email de notification d'échec (ne bloque jamais le webhook)
            try {
                Mail::to($user->email)->send(new PaymentFailedMail(
                    user: $user,
                    amount: $amount,
                    currency: (string) ($payload['currency'] ?? 'XAF'),
                    reference: $paymentId,
                    subscription: $failedSubscription,
                ));
                Log::info('KPay webhook : email d\'échec de paiement envoyé', [
                    'user_id' => $user->id,
                    'paymentId' => $paymentId,
                    'status' => $status,
                ]);
            } catch (\Throwable $e) {
                Log::error('KPay webhook : échec envoi email d\'échec de paiement', [
                    'user_id' => $user->id,
                    'paymentId' => $paymentId,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('KPay webhook : paiement non abouti', [
                'status' => $status,
                'paymentId' => $paymentId,
                'user_id' => $user->id,
                'purpose' => $purpose,
            ]);
        }

        // Mise à jour du registre local de synchronisation (fallback webhook)
        KpayPayment::query()
            ->where('external_id', $externalId)
            ->update([
                'status' => strtoupper($status),
                'paid_at' => $status === 'completed' ? now() : null,
                'last_synced_at' => now(),
            ]);

        return response()->json(['status' => 'processed'], 200);
    }
}
