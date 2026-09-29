<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien de paiement à jeton unique.
 *
 * KPay n'exposant aucun endpoint de « lien de paiement », l'objet est une
 * construction FORMADOC. Deux modes, décrits en détail dans la migration :
 * `online` (passerelle KPay hébergée) et `offline` (encaissement constaté par un
 * administrateur — indispensable quand les opérateurs sont en maintenance).
 *
 * Le jeton est TRANSMIS au client : il doit donc rester vérifiable et révocable.
 * C'est pourquoi il est stocké en clair — le hacher empêcherait de retrouver un
 * lien à partir de l'adresse reçue, ce qui est précisément l'usage qu'on en fait.
 */
#[Fillable([
    'token', 'label', 'amount_fcfa', 'currency', 'mode', 'gateway', 'status', 'credits',
    'user_id', 'customer_label', 'customer_email', 'description',
    'expires_at', 'paid_at', 'paid_by', 'payment_reference',
    'kpay_payment_id', 'created_by',
])]
class PaymentLink extends Model
{
    /**
     * Un lien non réglé dont la date n'est pas dépassée.
     *
     * Le statut seul ne suffit pas : un lien `pending` expiré reste `pending` en
     * base, parce qu'aucune tâche ne le bascule — c'est la lecture qui décide. Une
     * tâche planifiée ajouterait un état à maintenir pour une information qui se
     * déduit de deux colonnes déjà présentes.
     */
    public const STATUT_EN_ATTENTE = 'pending';

    public const STATUT_PAYE = 'paid';

    public const STATUT_ANNULE = 'cancelled';

    public const MODE_EN_LIGNE = 'online';

    public const MODE_HORS_LIGNE = 'offline';

    /**
     * Passerelle effectivement utilisée, une fois le paiement initié.
     *
     * `NULL` a deux significations distinctes qu'il ne faut pas confondre :
     *  - un lien hors ligne n'en aura jamais (aucun appel réseau n'est fait) ;
     *  - un lien en ligne pas encore réglé n'en a pas encore.
     *
     * Une fois le règlement engagé, la valeur est celle de `PaymentGatewayRegistry`.
     * Elle est figée : un lien réglé par Monetbil le restera, même si KPay devient
     * la passerelle par défaut entre-temps. Le rapprochement comptable en dépend.
     */
    public function passerelle(): ?string
    {
        return $this->gateway;
    }

    protected function casts(): array
    {
        return [
            'amount_fcfa' => 'integer',
            'credits' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Administrateur qui a constaté un règlement hors ligne.
     */
    public function encaissePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Le lien peut-il encore être réglé ?
     *
     * Point unique de décision : le contrôleur d'affichage ET celui de constatation
     * d'un règlement appellent cette méthode. Dupliquer la règle ferait qu'un lien
     * expiré pourrait encore être encaissé par un chemin et pas par l'autre.
     */
    public function estUtilisable(): bool
    {
        if ($this->status !== self::STATUT_EN_ATTENTE) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function estExpire(): bool
    {
        return $this->status === self::STATUT_EN_ATTENTE
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function scopeEnAttente(Builder $query): Builder
    {
        return $query->where('status', self::STATUT_EN_ATTENTE);
    }
}
