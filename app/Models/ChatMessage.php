<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['chat_session_id', 'role', 'content', 'model_used', 'cost_credits', 'metadata'])]
class ChatMessage extends Model
{
    protected function casts(): array
    {
        return [
            'cost_credits' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }
}
