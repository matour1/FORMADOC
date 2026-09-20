<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace locale des paiements KPay initiés (crédits ou abonnements).
 *
 * Rôle : permettre la synchronisation de secours via GET /api/v1/payments/:id
 * quand le webhook KPay n'a pas pu être délivré (serveur local, tunnel
 * indisponible, réseau…). Le webhook reste la source d'autorité ; cette
 * table est uniquement un registre pour le fallback.
 */
#[Fillable([
    'user_id',
    'payment_id',
    'external_id',
    'status',
    'purpose',
    'amount_fcfa',
    'currency',
    'return_url',
    'cancel_url',
    'metadata',
    'paid_at',
    'expires_at',
    'sync_attempts',
    'last_synced_at',
])]
class KpayPayment extends Model
{
    protected function casts(): array
    {
        return [
            'amount_fcfa' => 'integer',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'sync_attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Paiements en attente dont le webhook n'est pas encore arrivé.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['PENDING', 'PROCESSING']);
    }
}
