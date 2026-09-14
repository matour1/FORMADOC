<?php

declare(strict_types=1);

namespace App\Models;

use App\Document\Editing\EditLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Verrou d'édition d'un document (garde-fou §9.8).
 *
 * Une seule ligne par document : deux processus ne peuvent pas éditer le même
 * document simultanément. La contrainte d'unicité sur `document_id` est ce qui
 * rend la garantie **structurelle** plutôt que déclarative — elle ne dépend pas
 * du code appelant.
 */
class DocumentEditLock extends Model
{
    protected $fillable = [
        'document_id',
        'owner',
        'reason',
        'acquired_at',
    ];

    protected $casts = [
        'acquired_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Le verrou est-il abandonné (processus interrompu) ?
     *
     * Un verrou éternel serait pire que pas de verrou : un processus tué en
     * cours de traitement bloquerait le document pour toujours. On préfère un
     * risque de double traitement après expiration.
     */
    public function isStale(): bool
    {
        if ($this->acquired_at === null) {
            return false;
        }

        return $this->ageInSeconds() > EditLock::STALE_AFTER;
    }

    /**
     * Âge du verrou, en secondes.
     */
    public function ageInSeconds(): int
    {
        if ($this->acquired_at === null) {
            return 0;
        }

        return (int) abs(now()->diffInSeconds($this->acquired_at));
    }

    /**
     * Libellé lisible du verrou, pour l'écran d'administration.
     */
    public function label(): string
    {
        return $this->owner.($this->reason !== '' && $this->reason !== null ? ' — '.$this->reason : '');
    }
}
