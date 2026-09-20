<?php

namespace App\Models;

use App\Models\Concerns\HasHashId;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facture émise à l'occasion d'une souscription, d'un renouvellement ou
 * d'un achat de crédits (Phase 9).
 *
 * Règles :
 *   - numéro unique lisible : INV-AAAA-XXXXXX (ex. INV-2026-000042)
 *   - montant en plus petite unité (FCFA entier, EUR/USD centimes)
 *   - une facture = un paiement (jamais de paiement partiel)
 */
#[Fillable([
    'number',
    'user_id',
    'plan_id',
    'subscription_id',
    'type',
    'amount',
    'currency',
    'status',
    'payment_method',
    'reference',
    'paid_at',
    'period_start',
    'period_end',
    'metadata',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasHashId;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Montant formaté selon la devise (FCFA entier, EUR/USD décimal).
     */
    public function formattedAmount(): string
    {
        $symbols = ['XAF' => 'FCFA', 'XOF' => 'FCFA', 'EUR' => '€', 'USD' => '$'];

        $symbol = $symbols[$this->currency] ?? $this->currency;

        if (in_array($this->currency, ['EUR', 'USD'], true)) {
            return number_format($this->amount / 100, 2, ',', ' ').' '.$symbol;
        }

        return number_format($this->amount, 0, ',', ' ').' '.$symbol;
    }
}
