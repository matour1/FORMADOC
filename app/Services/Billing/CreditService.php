<?php

namespace App\Services\Billing;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gestion centralisée des crédits utilisateur.
 *
 * Règles :
 *   - 1 crédit = 1 FCFA (XAF/XOF)
 *   - TOUT débit est verrouillé par ligne (SELECT ... FOR UPDATE) pour
 *     empêcher les soldes négatifs en cas d'accès concurrents
 *   - chaque opération est journalisée dans credit_transactions
 */
class CreditService
{
    /**
     * Débite $amount crédits (doit être positif).
     *
     * @return array{ok: bool, balance: int, reason?: string}
     */
    public function debit(User $user, int $amount, string $type = 'usage', ?string $reference = null, string $description = '', array $metadata = []): array
    {
        if ($amount <= 0) {
            return ['ok' => false, 'balance' => $user->credits_balance, 'reason' => 'invalid_amount'];
        }

        try {
            $result = DB::transaction(function () use ($user, $amount, $type, $reference, $description, $metadata) {
                // Verrouillage de la ligne utilisateur : garantit l'atomicité
                $locked = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || $locked->credits_balance < $amount) {
                    return ['ok' => false, 'balance' => $locked?->credits_balance ?? 0, 'reason' => 'insufficient_balance'];
                }

                $locked->decrement('credits_balance', $amount);
                $locked->refresh();

                CreditTransaction::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'amount' => -$amount,
                    'balance_after' => $locked->credits_balance,
                    'currency' => 'XAF',
                    'reference' => $reference,
                    'description' => $description,
                    'metadata' => $metadata,
                ]);

                return ['ok' => true, 'balance' => $locked->credits_balance];
            });

            return $result;
        } catch (\Throwable $e) {
            Log::error('CreditService::debit — échec', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'balance' => $user->credits_balance, 'reason' => 'exception'];
        }
    }

    /**
     * Crédite $amount crédits (doit être positif).
     *
     * @return array{ok: bool, balance: int}
     */
    public function credit(User $user, int $amount, string $type = 'purchase', ?string $reference = null, string $description = '', array $metadata = []): array
    {
        if ($amount <= 0) {
            return ['ok' => false, 'balance' => $user->credits_balance];
        }

        try {
            $result = DB::transaction(function () use ($user, $amount, $type, $reference, $description, $metadata) {
                $locked = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return ['ok' => false, 'balance' => 0];
                }

                $locked->increment('credits_balance', $amount);
                $locked->refresh();

                CreditTransaction::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'amount' => $amount,
                    'balance_after' => $locked->credits_balance,
                    'currency' => 'XAF',
                    'reference' => $reference,
                    'description' => $description,
                    'metadata' => $metadata,
                ]);

                return ['ok' => true, 'balance' => $locked->credits_balance];
            });

            return $result;
        } catch (\Throwable $e) {
            Log::error('CreditService::credit — échec', [
                'user_id' => $user->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'balance' => $user->credits_balance];
        }
    }

    /**
     * Solde actuel (fraîchement rechargé).
     */
    public function balance(User $user): int
    {
        return (int) User::whereKey($user->id)->value('credits_balance') ?? 0;
    }

    /**
     * Vérifie que l'utilisateur a assez de crédits (lecture verrouillée).
     */
    public function hasEnough(User $user, int $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }

        $balance = DB::transaction(function () use ($user) {
            return User::query()->whereKey($user->id)->lockForUpdate()->value('credits_balance') ?? 0;
        });

        return $balance >= $amount;
    }
}
