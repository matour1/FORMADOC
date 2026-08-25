<?php

namespace App\Models;

use App\Models\Concerns\HasHashId;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoverPageTemplate extends Model
{
    use HasHashId;

    protected $fillable = [
        'name',
        'description',
        'elements',
        'page_style',
        'is_public',
        'user_id',
        'based_on_id',
    ];

    protected $casts = [
        'elements' => 'array',
        'page_style' => 'array',
        'is_public' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_id');
    }

    /**
     * Slug stable pour les URLs et le JS.
     */
    protected function slug(): Attribute
    {
        return Attribute::make(
            get: fn () => \Illuminate\Support\Str::slug($this->name),
        );
    }
}
