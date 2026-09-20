<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $fillable = [
        'email',
        'avis',
        'note',
        'recommander',
        'problemes_rencontres',
        'status',
    ];

    protected $casts = [
        'note' => 'integer',
        'recommander' => 'boolean',
    ];
}
