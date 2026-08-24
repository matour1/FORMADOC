<?php

namespace App\Console\Commands;

use App\Mail\RenewalReminderMail;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envoie un rappel J-3 avant le renouvellement automatique des abonnements
 * (audit copywriting §6.2.6).
 *
 * Scheduler : routes/console.php — chaque jour à 07:00.
 * Idempotent : `renewal_reminded_at` est posé au premier envoi, donc un
 * abonnement ne reçoit qu'un seul rappel par période.
 */
class RemindSubscriptionRenewal extends Command
{
    protected $signature = 'subscriptions:remind-renewal';

    protected $description = 'Envoie le rappel de renouvellement J-3 aux abonnements avec renouvellement automatique';

    public function handle(): int
    {
        $this->info('Début de l\'envoi des rappels de renouvellement J-3...');

        // Cible : abonnements actifs, auto_renew, dont la fin de période est
        // dans 3 jours exactement, et qui n'ont pas encore reçu le rappel.
        $due = Subscription::query()
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [
                now()->addDays(3)->startOfDay(),
                now()->addDays(3)->endOfDay(),
            ])
            ->whereNull('renewal_reminded_at')
            ->with(['user', 'plan'])
            ->get();

        $this->line(sprintf('Rappels à envoyer : %d', $due->count()));

        $sent = 0;
        $failed = 0;

        foreach ($due as $subscription) {
            $user = $subscription->user;
            $plan = $subscription->plan;

            if (! $user || ! $plan || ! $user->email) {
                $this->warn(sprintf('[SAUT] Abonnement #%d — utilisateur ou plan manquant', $subscription->id));
                continue;
            }

            try {
                Mail::to($user->email)->send(new RenewalReminderMail(
                    user: $user,
                    subscription: $subscription,
                    renewDate: $subscription->ends_at->format('d/m/Y'),
                    planName: $plan->name,
                    price: (int) $plan->price_fcfa,
                    currency: $subscription->currency ?: 'XAF',
                ));

                $subscription->update(['renewal_reminded_at' => now()]);

                $sent++;
                $this->info(sprintf(
                    '[OK] Abonnement #%d (user %d) — rappel envoyé pour le %s',
                    $subscription->id,
                    $subscription->user_id,
                    $subscription->ends_at->format('d/m/Y')
                ));
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf(
                    '[ÉCHEC] Abonnement #%d (user %d) — %s',
                    $subscription->id,
                    $subscription->user_id,
                    $e->getMessage()
                ));
                Log::error('subscriptions:remind-renewal : échec envoi', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('subscriptions:remind-renewal exécuté', [
            'due' => $due->count(),
            'sent' => $sent,
            'failed' => $failed,
        ]);

        $this->info(sprintf('Terminé : %d rappels envoyés, %d échecs.', $sent, $failed));

        return self::SUCCESS;
    }
}
