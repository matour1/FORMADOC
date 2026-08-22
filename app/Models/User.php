<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'credits_balance', 'usage_deterministic_month', 'usage_ai_month', 'usage_month'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'credits_balance' => 'integer',
            'usage_deterministic_month' => 'integer',
            'usage_ai_month' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations SaaS
    |--------------------------------------------------------------------------
    */

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Abonnement actif courant (le plus récent, si statut active).
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Méthodes SaaS
    |--------------------------------------------------------------------------
    */

    /**
     * Slug du plan courant : 'default' si aucun abonnement actif.
     */
    public function currentPlanSlug(): string
    {
        $subscription = $this->activeSubscription;

        if ($subscription && $subscription->plan) {
            return $subscription->plan->slug;
        }

        return 'default';
    }

    /**
     * Vérifie que l'utilisateur dispose d'au moins $amount crédits.
     */
    public function hasCredits(int $amount = 1): bool
    {
        return $this->credits_balance >= $amount;
    }
}
