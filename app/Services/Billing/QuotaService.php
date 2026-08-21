<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gestion des quotas mensuels par plan (exigence D du cahier des charges).
 *
 * Règles métier :
 *   - quota null = illimité
 *   - le compteur est mensuel (quota_period = 'monthly') et se réinitialise
 *     automatiquement au premier usage du nouveau mois (resetIfNeeded)
 *   - l'IA est OPTIONNELLE : si le quota IA est épuisé, le mode déterministe
 *     reste disponible ; l'utilisateur peut aussi acheter des crédits
 *   - le déterministe ne consomme AUCUN crédit, uniquement le quota
 *   - le verrouillage SELECT ... FOR UPDATE évite les dépassements concurrents
 */
class QuotaService
{
    /**
     * Détermine si l'utilisateur a un quota restant pour un type d'usage.
     *
     * @param string $usageType 'deterministic' | 'ai'
     */
    public function canUse(User $user, string $usageType): bool
    {
        $this->resetIfNeeded($user);

        $quota = $this->quotaFor($user, $usageType);

        // null = illimité
        if ($quota === null) {
            return true;
        }

        $used = $usageType === 'ai'
            ? (int) $user->usage_ai_month
            : (int) $user->usage_deterministic_month;

        return $used < $quota;
    }

    /**
     * Consomme une unité du quota mensuel (sans vérification préalable).
     * Retourne false si le quota est dépassé (compteur non incrémenté).
     *
     * @param string $usageType 'deterministic' | 'ai'
     * @return array{ok: bool, used: int, quota: int|null, reason?: string}
     */
    public function consume(User $user, string $usageType): array
    {
        return DB::transaction(function () use ($user, $usageType) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $locked) {
                return ['ok' => false, 'used' => 0, 'quota' => null, 'reason' => 'user_not_found'];
            }

            $this->resetIfNeeded($locked);

            $quota = $this->quotaFor($locked, $usageType);
            $column = $usageType === 'ai' ? 'usage_ai_month' : 'usage_deterministic_month';
            $used = (int) $locked->{$column};

            // null = illimité
            if ($quota !== null && $used >= $quota) {
                return [
                    'ok' => false,
                    'used' => $used,
                    'quota' => $quota,
                    'reason' => 'quota_exhausted',
                ];
            }

            $locked->increment($column);

            Log::info('Quota consommé', [
                'user_id' => $locked->id,
                'usage_type' => $usageType,
                'used' => $used + 1,
                'quota' => $quota,
            ]);

            return ['ok' => true, 'used' => $used + 1, 'quota' => $quota];
        });
    }

    /**
     * Rembourse une unité de quota (annulation d'une opération).
     *
     * @param string $usageType 'deterministic' | 'ai'
     */
    public function refund(User $user, string $usageType): void
    {
        $column = $usageType === 'ai' ? 'usage_ai_month' : 'usage_deterministic_month';

        DB::transaction(function () use ($user, $column) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if ($locked && (int) $locked->{$column} > 0) {
                $locked->decrement($column);
            }
        });
    }

    /**
     * Renvoie l'état des quotas de l'utilisateur (affichage compte).
     *
     * @return array<string, mixed> {deterministic: {used, quota, remaining, unlimited}, ai: {...}, month}
     */
    public function status(User $user): array
    {
        $this->resetIfNeeded($user);

        $user->refresh();

        return [
            'month' => $this->currentMonth(),
            'deterministic' => $this->usageStatus($user, 'deterministic'),
            'ai' => $this->usageStatus($user, 'ai'),
        ];
    }

    /**
     * Réinitialise les compteurs si le mois a changé.
     */
    public function resetIfNeeded(User $user): void
    {
        $currentMonth = $this->currentMonth();

        if ($user->usage_month !== $currentMonth) {
            $user->forceFill([
                'usage_month' => $currentMonth,
                'usage_deterministic_month' => 0,
                'usage_ai_month' => 0,
            ])->save();
        }
    }

    /**
     * Quota (null = illimité) pour un type d'usage et le plan courant.
     */
    private function quotaFor(User $user, string $usageType): ?int
    {
        $column = $usageType === 'ai' ? 'quota_ai' : 'quota_deterministic';

        $slug = $user->currentPlanSlug();

        // Le plan gratuit n'existe pas en base : quotas par défaut
        if ($slug === 'default') {
            return $usageType === 'ai' ? 0 : 5; // Gratuit : 5 docs déterministes / 0 IA
        }

        $plan = Plan::where('slug', $slug)->where('is_active', true)->first();

        return $plan?->{$column};
    }

    /**
     * Statut d'usage pour un type donné.
     *
     * @return array{used: int, quota: int|null, remaining: int|null, unlimited: bool}
     */
    private function usageStatus(User $user, string $usageType): array
    {
        $quota = $this->quotaFor($user, $usageType);
        $used = $usageType === 'ai'
            ? (int) $user->usage_ai_month
            : (int) $user->usage_deterministic_month;

        return [
            'used' => $used,
            'quota' => $quota,
            'remaining' => $quota === null ? null : max(0, $quota - $used),
            'unlimited' => $quota === null,
        ];
    }

    /**
     * Mois courant au format YYYY-MM.
     */
    private function currentMonth(): string
    {
        return now()->format('Y-m');
    }
}
