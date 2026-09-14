<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne du registre d'usage IA (phase R7).
 *
 * **Pourquoi ce registre existe** — avant R7, seul le coût du **dernier** appel
 * réussi était enregistré. Trois cas échappaient donc à la facturation :
 *
 *  1. **les retries** — Laravel réessaie jusqu'à `max_retries` fois, et chaque
 *     tentative est facturée par le fournisseur même quand elle échoue ;
 *  2. **les modèles candidats échoués** — la sélection parcourt plusieurs modèles
 *     jusqu'à en trouver un qui réponde ; les échecs précédents coûtent aussi ;
 *  3. **les bascules de fournisseur** — le fallback DeepSeek direct change le
 *     prix sans laisser de trace.
 *
 * Le registre enregistre donc **chaque tentative**, réussie ou non, avec le
 * modèle réellement appelé. Le coût total se recalcule ensuite depuis ces lignes
 * brutes — c'est ce qui rend la facturation vérifiable plutôt que déclarative.
 *
 * **`estimated`** distingue un coût calculé depuis les tokens d'un coût forfaitaire
 * (image, conteneur Claude). La distinction compte : un coût estimé ne peut pas
 * servir de base à un remboursement au centime près.
 */
class AiUsageLedger extends Model
{
    protected $table = 'ai_usage_ledger';

    protected $fillable = [
        'user_id',
        'chat_session_id',
        'document_id',
        'provider',
        'model',
        'task_type',
        'input_tokens',
        'output_tokens',
        'attempt',
        'succeeded',
        'is_fallback',
        'fallback_from',
        'estimated',
        'cost_usd',
        'cost_credits',
        'reference',
        'error',
        'metadata',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'attempt' => 'integer',
        'succeeded' => 'boolean',
        'is_fallback' => 'boolean',
        'estimated' => 'boolean',
        'cost_usd' => 'float',
        'cost_credits' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Total des tokens consommés par cette ligne.
     */
    public function totalTokens(): int
    {
        return $this->input_tokens + $this->output_tokens;
    }

    /**
     * Coût réel par million de tokens, pour le rapport de rentabilité.
     *
     * Retourne null quand aucun token n'a été compté (appel échoué avant
     * réponse, ou coût forfaitaire) : diviser par zéro n'a pas de sens ici.
     */
    public function costPerMillionTokens(): ?float
    {
        $tokens = $this->totalTokens();

        if ($tokens === 0) {
            return null;
        }

        return round(($this->cost_usd / $tokens) * 1_000_000, 4);
    }

    /**
     * Libellé lisible, pour l'écran d'administration.
     */
    public function label(): string
    {
        $statut = $this->succeeded ? 'réussi' : 'échec';
        $fallback = $this->is_fallback ? ' (repli)' : '';

        return "{$this->model}{$fallback} — {$statut}";
    }
}
