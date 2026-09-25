<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Mail\PasswordResetMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

#[Fillable(['name', 'email', 'password', 'credits_balance', 'preferences', 'usage_deterministic_month', 'usage_ai_month', 'usage_month'])]
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
            'is_admin' => 'boolean',
            'is_suspended' => 'boolean',
            'password' => 'hashed',
            'credits_balance' => 'integer',
            'preferences' => 'array',
            'usage_deterministic_month' => 'integer',
            'usage_ai_month' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Vérification d'email (non bloquante)
    |--------------------------------------------------------------------------
    | L'utilisateur peut tout utiliser sans confirmer son adresse : un bandeau
    | discret l'invite à le faire, et le lien signé expire après 24 h.
    */

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function sendEmailVerificationNotification(): void
    {
        // Les envois passent par AuthController::sendEmailVerification()
        // (try/catch, non bloquant) — cette méthode reste pour la compatibilité
        // avec le contrat MustVerifyEmail.
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

    /*
    |--------------------------------------------------------------------------
    | Suspension de compte
    |--------------------------------------------------------------------------
    | Le contrôle se fait à la connexion (voir AuthController) et non par un
    | middleware global : vérifier à chaque requête ajouterait une lecture de
    | session sans rien apporter, puisque la session ne survit pas à une
    | déconnexion forcée.
    */

    /**
     * Le compte peut-il se connecter ?
     *
     * Centralisé ici plutôt qu'écrit en clair dans le contrôleur de connexion :
     * le jour où une seconde condition d'accès apparaîtra (compte fermé à la
     * demande du client, période d'essai expirée), il n'y aura qu'un endroit à
     * modifier — et la connexion est précisément l'endroit qu'on oublie de
     * mettre à jour.
     */
    public function canSignIn(): bool
    {
        return ! $this->is_suspended;
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

    /**
     * Documents liés à l'utilisateur (les documents sont associés via
     * le JSON `metadata.user_id`, pas de colonne dédiée).
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'metadata->user_id', 'id');
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

    /**
     * Envoie la notification de réinitialisation de mot de passe
     * (email personnalisé aux couleurs FORMADOC).
     */
    public function sendPasswordResetNotification($token): void
    {
        Mail::to($this->email)->send(new PasswordResetMail($this, $token));
    }
}
