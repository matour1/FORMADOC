<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gestion des abonnements récurrents (Phase 9).
 *
 * Règles métier :
 *   - Q1a : renouvellement automatique via KPay (commande subscriptions:renew)
 *   - Q2a : prorata au changement de plan (crédit des jours restants)
 *   - Q3a : facture PDF à chaque paiement (souscription, renouvellement)
 *   - Q5b : devises multiples (FCFA / EUR / USD)
 *   - Q6a : flux KPay conservé (init → passerelle → webhook signé HMAC)
 *
 * Le webhook KPay est LA SOURCE D'AUTORITÉ : un abonnement n'est créé/
 * renouvelé qu'après un paiement completed vérifié.
 */
class SubscriptionService
{
    public function __construct(
        private readonly KPayService $kpay,
        private readonly CreditService $credits,
        private readonly InvoiceService $invoices,
    ) {
    }

    /**
     * Statut d'un utilisateur par rapport aux abonnements.
     *
     * @return array{active: bool, plan: string, subscription?: Subscription, renew_at?: string}
     */
    public function status(User $user): array
    {
        $subscription = $user->activeSubscription?->load('plan');

        if (! $subscription) {
            return ['active' => false, 'plan' => 'default'];
        }

        return [
            'active' => true,
            'plan' => $subscription->plan->slug,
            'subscription' => $subscription,
            'renew_at' => $subscription->ends_at?->format('d/m/Y'),
        ];
    }

    /**
     * Calcule le prix d'un plan dans une devise donnée.
     *
     * @return array{amount: int, symbol: string, decimals: int, currency: string}
     */
    public function priceInCurrency(Plan $plan, string $currency = 'XAF'): array
    {
        $currency = strtoupper($currency);
        $currencies = config('billing.currencies', []);
        $cfg = $currencies[$currency] ?? $currencies['XAF'];

        // Prix de base en FCFA → convertir
        $amountFcfa = (int) $plan->price_fcfa;
        $rate = (float) ($cfg['rate_fcfa'] ?? 1.0);
        $decimals = (int) ($cfg['decimals'] ?? 0);

        if ($currency === 'XAF' || $currency === 'XOF') {
            return [
                'amount' => $amountFcfa,
                'symbol' => $cfg['symbol'] ?? 'FCFA',
                'decimals' => 0,
                'currency' => $currency,
            ];
        }

        // Devise externe : montant en plus petite unité (centimes)
        $converted = $amountFcfa / $rate;
        $amount = (int) round($converted * (10 ** $decimals));

        return [
            'amount' => $amount,
            'symbol' => $cfg['symbol'] ?? $currency,
            'decimals' => $decimals,
            'currency' => $currency,
        ];
    }

    /**
     * Montant d'un plan formaté pour affichage dans une devise.
     */
    public function formatPrice(Plan $plan, string $currency = 'XAF'): string
    {
        $p = $this->priceInCurrency($plan, $currency);

        return number_format($p['amount'] / (10 ** $p['decimals']), $p['decimals'], ',', ' ')
            .' '.$p['symbol'];
    }

    /**
     * Initialise la souscription : init KPay → renvoie la gatewayUrl.
     *
     * @return array{ok: bool, gatewayUrl?: string, externalId?: string, message?: string}
     */
    public function initiateSubscription(User $user, Plan $plan, string $paymentMethod = 'kpay', string $currency = 'XAF'): array
    {
        if (! $plan->is_active) {
            return ['ok' => false, 'message' => 'Ce plan n\'est plus disponible.'];
        }

        if ($plan->price_fcfa <= 0) {
            // Plans gratuits / sur devis : création directe sans paiement
            return $this->activateFree($user, $plan, $paymentMethod, $currency);
        }

        $externalId = 'SUB-'.$user->id.'-'.Str::uuid();

        $result = $this->kpay->initGatewayPayment(
            amountFcfa: (int) $plan->price_fcfa,
            externalId: $externalId,
            returnUrl: route('account.index'),
            cancelUrl: route('account.index'),
            currency: $currency === 'XAF' ? 'XAF' : $currency,
            metadata: [
                'user_id' => $user->id,
                'purpose' => 'subscription',
                'plan_slug' => $plan->slug,
                'plan_id' => $plan->id,
                'amount_fcfa' => $plan->price_fcfa,
                'payment_method' => $paymentMethod,
                'currency' => $currency,
            ],
        );

        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['message'] ?? 'Échec de l\'initialisation du paiement.'];
        }

        return [
            'ok' => true,
            'gatewayUrl' => $result['gatewayUrl'],
            'externalId' => $externalId,
        ];
    }

    /**
     * Active un abonnement gratuit / sur devis (sans passer par KPay).
     */
    private function activateFree(User $user, Plan $plan, string $paymentMethod, string $currency): array
    {
        $this->cancelCurrent($user, refundProrata: false);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'auto_renew' => false,
            'payment_method' => $paymentMethod,
            'currency' => $currency,
            'starts_at' => now(),
            'ends_at' => $plan->slug === 'enterprise' ? null : now()->addMonth(),
        ]);

        return [
            'ok' => true,
            'subscription' => $subscription,
            'message' => 'Abonnement '.$plan->name.' activé.',
        ];
    }

    /**
     * Confirme la souscription après webhook KPay completed.
     *
     * Appelé depuis KPayController::webhook avec un paiement vérifié.
     */
    public function activateFromWebhook(array $payload, array $metadata): bool
    {
        $user = User::find((int) ($metadata['user_id'] ?? 0));
        if (! $user) {
            return false;
        }

        $plan = Plan::find((int) ($metadata['plan_id'] ?? 0));
        if (! $plan || ! $plan->is_active) {
            Log::warning('SubscriptionService : plan introuvable au webhook', ['metadata' => $metadata]);
            return false;
        }

        // Idempotence : un même paiement ne doit activer qu'une fois.
        // Vérification + création DANS la transaction (lockForUpdate) pour
        // éviter la course entre deux webhooks concurrents : le check-then-act
        // hors transaction laissait une fenêtre où cancelCurrent() annulait
        // l'ancien abonnement alors qu'un doublon allait le remplacer 2 fois.
        $externalId = (string) ($metadata['external_id'] ?? $payload['externalId'] ?? '');

        $already = false;
        DB::transaction(function () use ($user, $plan, $payload, $metadata, $externalId, &$already) {
            // Sérialise les activations pour ce user (évite la course)
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($externalId !== '') {
                $existing = Subscription::where('external_id', $externalId)->first();
                if ($existing) {
                    $already = true;

                    return;
                }
            }

            // Annuler tout abonnement actif précédent (sans prorata ici :
            // le nouveau paiement couvre la nouvelle période)
            $this->cancelCurrent($user, refundProrata: false);

            $now = now();
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'auto_renew' => config('billing.auto_renew', true),
                'payment_method' => $metadata['payment_method'] ?? 'kpay',
                'external_id' => $externalId !== '' ? $externalId : null,
                'currency' => $metadata['currency'] ?? 'XAF',
                'last_payment_status' => 'completed',
                'last_renewed_at' => $now,
                'starts_at' => $now,
                'ends_at' => $plan->slug === 'enterprise' ? null : $now->copy()->addMonth(),
            ]);

            // Facture
            $this->invoices->createForSubscription(
                $user,
                $subscription,
                amount: (int) ($metadata['amount_fcfa'] ?? $plan->price_fcfa),
                currency: $metadata['currency'] ?? 'XAF',
                reference: (string) ($payload['paymentId'] ?? $payload['reference'] ?? $externalId),
            );
        });

        if ($already) {
            Log::info('SubscriptionService : paiement déjà traité (idempotence)', [
                'user_id' => $user->id,
                'plan_slug' => $plan->slug,
                'external_id' => $externalId,
            ]);

            return true;
        }

        Log::info('SubscriptionService : abonnement activé via webhook', [
            'user_id' => $user->id,
            'plan_slug' => $plan->slug,
            'external_id' => $externalId,
        ]);

        return true;
    }

    /**
     * Annule l'abonnement actif (statut cancelled, reste actif jusqu'à ends_at).
     *
     * Le template : « reste actif jusqu'à la fin de la période déjà payée,
     * puis bascule automatiquement vers le plan Gratuit. »
     */
    public function cancelCurrent(User $user, bool $refundProrata = true): ?Subscription
    {
        $subscription = $user->activeSubscription;

        if (! $subscription) {
            return null;
        }

        // Prorata : créditer les jours restants (Q2a)
        if ($refundProrata && $subscription->ends_at && $subscription->ends_at->isFuture()) {
            $plan = $subscription->plan;
            $totalDays = max(1, (int) $subscription->starts_at?->diffInDays($subscription->ends_at) ?: 30);
            $remainingDays = (int) now()->diffInDays($subscription->ends_at);
            $prorataFcfa = (int) round($plan->price_fcfa * ($remainingDays / $totalDays));

            if ($prorataFcfa > 0) {
                $this->credits->credit(
                    $user,
                    $prorataFcfa,
                    type: 'refund',
                    reference: 'PRORATA-'.$subscription->id,
                    description: 'Prorata changement d\'abonnement ('.$remainingDays.' j restants)',
                    metadata: ['subscription_id' => $subscription->id, 'reason' => 'cancel'],
                );
            }
        }

        $subscription->update([
            'status' => 'cancelled',
            'auto_renew' => false,
            'last_payment_status' => 'cancelled',
        ]);

        Log::info('SubscriptionService : abonnement annulé', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'refund_prorata' => $refundProrata,
        ]);

        return $subscription;
    }

    /**
     * Renouvelle un abonnement arrivant à échéance (Q1a).
     *
     * Appelé par la commande subscriptions:renew (cron quotidien).
     * Relance KPay pour le mois suivant ; le webhook confirmera l'activation.
     *
     * @return array{ok: bool, reason?: string, subscription?: Subscription}
     */
    public function renew(Subscription $subscription): array
    {
        // Garde-fous
        if ($subscription->status !== 'active') {
            return ['ok' => false, 'reason' => 'not_active'];
        }
        if (! $subscription->auto_renew) {
            return ['ok' => false, 'reason' => 'auto_renew_disabled'];
        }
        if (! $subscription->ends_at) {
            return ['ok' => false, 'reason' => 'no_end_date'];
        }
        $plan = $subscription->plan;
        if (! $plan || $plan->price_fcfa <= 0) {
            return ['ok' => false, 'reason' => 'free_plan'];
        }

        $user = $subscription->user;

        $externalId = 'RENEW-'.$subscription->id.'-'.Str::uuid();

        $result = $this->kpay->initGatewayPayment(
            amountFcfa: (int) $plan->price_fcfa,
            externalId: $externalId,
            returnUrl: route('account.index'),
            cancelUrl: route('account.index'),
            currency: $subscription->currency ?: 'XAF',
            metadata: [
                'user_id' => $user->id,
                'purpose' => 'subscription_renewal',
                'subscription_id' => $subscription->id,
                'plan_slug' => $plan->slug,
                'plan_id' => $plan->id,
                'amount_fcfa' => $plan->price_fcfa,
                'currency' => $subscription->currency ?: 'XAF',
                'payment_method' => $subscription->payment_method ?: 'kpay',
            ],
        );

        if (! $result['ok']) {
            Log::warning('SubscriptionService : échec renouvellement', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'message' => $result['message'] ?? 'unknown',
            ]);

            $subscription->update([
                'last_payment_status' => 'failed',
            ]);

            return ['ok' => false, 'reason' => 'kpay_init_failed', 'message' => $result['message'] ?? null];
        }

        $subscription->update([
            'last_payment_status' => 'pending',
            'external_id' => $result['externalId'] ?? $externalId,
        ]);

        return ['ok' => true, 'reason' => 'kpay_initiated', 'subscription' => $subscription];
    }

    /**
     * Gère l'échec de paiement : past_due puis expired après grace period.
     */
    public function markFailedPayment(Subscription $subscription): void
    {
        $graceDays = (int) config('billing.grace_days', 5);
        $graceUntil = now()->addDays($graceDays);

        $subscription->update([
            'status' => 'past_due',
            'auto_renew' => true, // on retentera
            'last_payment_status' => 'failed',
        ]);

        Log::warning('SubscriptionService : paiement échoué, période de grâce', [
            'subscription_id' => $subscription->id,
            'grace_until' => $graceUntil->toDateTimeString(),
        ]);
    }

    /**
     * Révoque un abonnement en fin de période (expired) ou après grâce épuisée.
     */
    public function expire(Subscription $subscription): void
    {
        $subscription->update([
            'status' => 'expired',
            'auto_renew' => false,
        ]);

        Log::info('SubscriptionService : abonnement expiré', [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
        ]);
    }

    /**
     * Détermine les abonnements à renouveler aujourd'hui.
     *
     * @return \Illuminate\Support\Collection<int, Subscription>
     */
    public function subscriptionsDueForRenewal(): \Illuminate\Support\Collection
    {
        $daysBefore = (int) config('billing.renew_days_before', 3);
        $threshold = now()->addDays($daysBefore);

        return Subscription::query()
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $threshold)
            ->with(['user', 'plan'])
            ->get();
    }
}
