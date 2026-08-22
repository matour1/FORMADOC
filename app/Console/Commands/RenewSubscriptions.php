<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewSubscriptions extends Command
{
    protected $signature = 'subscriptions:renew';

    protected $description = 'Renouvelle automatiquement les abonnements arrivant à échéance (Q1a) et expire ceux dont la période de grâce est dépassée';

    public function handle(SubscriptionService $service): int
    {
        $this->info('Début du renouvellement automatique des abonnements...');

        $due = $service->subscriptionsDueForRenewal();

        $this->line(sprintf('Abonnements à renouveler : %d', $due->count()));

        $renewed = 0;
        $failed = 0;

        foreach ($due as $subscription) {
            $result = $service->renew($subscription);

            if ($result['ok']) {
                $renewed++;
                $this->info(sprintf(
                    '[OK] Abonnement #%d (user %d) — %s',
                    $subscription->id,
                    $subscription->user_id,
                    $result['reason'] ?? 'renouvellement initié'
                ));
            } else {
                $failed++;
                $reason = $result['reason'] ?? 'unknown';
                $this->warn(sprintf(
                    '[ÉCHEC] Abonnement #%d (user %d) — %s',
                    $subscription->id,
                    $subscription->user_id,
                    $reason
                ));

                // Paiement impossible : passage en période de grâce (retentera au prochain run)
                if (in_array($reason, ['kpay_init_failed', 'free_plan'], true)) {
                    $service->markFailedPayment($subscription);
                }
            }
        }

        // Expiration : abonnements dont la période est dépassée de plus que la période de grâce
        $graceDays = (int) config('billing.grace_days', 5);
        $expired = Subscription::query()
            ->whereIn('status', ['active', 'past_due'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now()->subDays($graceDays))
            ->get();

        $expiredCount = 0;
        foreach ($expired as $subscription) {
            $service->expire($subscription);
            $expiredCount++;
            $this->warn(sprintf(
                '[EXPIRÉ] Abonnement #%d (user %d) — période terminée depuis plus de %d jours',
                $subscription->id,
                $subscription->user_id,
                $graceDays
            ));
        }

        Log::info('subscriptions:renew exécuté', [
            'due' => $due->count(),
            'renewed' => $renewed,
            'failed' => $failed,
            'expired' => $expiredCount,
        ]);

        $this->info(sprintf('Terminé : %d renouvelés, %d échecs, %d expirés.', $renewed, $failed, $expiredCount));

        return self::SUCCESS;
    }
}
