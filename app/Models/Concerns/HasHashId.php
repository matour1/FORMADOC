<?php

namespace App\Models\Concerns;

use Hashids\Hashids;
use Illuminate\Database\Eloquent\Model;

/**
 * Obfusque l'identifiant de base de données exposé dans les URLs.
 *
 * - `getRouteKeyName()` renvoie `hash_id` : les routes générées via
 *   `route('...', $model)` (et `url($model)`) utilisent le hash au lieu de l'id brut.
 * - L'accessor `getHashIdAttribute()` encode l'id à la volée (aucune colonne en base).
 * - `resolveRouteBinding()` décode le hash vers l'id réel lors de la résolution
 *   du binding de route (implicit binding). Un hash invalide → null → 404.
 *
 * Le sel est unique par modèle et par installation (dérivé de APP_KEY),
 * ce qui empêche toute corrélation d'un hash entre deux environnements
 * et toute attaque par énumération de l'id réel.
 *
 * @mixin Model
 */
trait HasHashId
{
    /**
     * Clé de route = hash obfusqué (jamais l'id auto-incrémenté brut).
     */
    public function getRouteKeyName(): string
    {
        return 'hash_id';
    }

    /**
     * Accessor : encode l'id réel en hash, à la volée.
     */
    public function getHashIdAttribute(): string
    {
        return $this->hashidsInstance()->encode($this->getKey());
    }

    /**
     * Résolution du binding de route : décode le hash vers l'id réel.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     * @return Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $decoded = $this->hashidsInstance()->decode($value);

        if (empty($decoded)) {
            return null; // hash invalide → 404
        }

        return $this->where('id', $decoded[0])->first();
    }

    /**
     * Instance Hashids avec un sel propre au modèle et à l'installation.
     */
    private function hashidsInstance(): Hashids
    {
        $salt = 'formadoc-'.class_basename($this).'-'.config('app.key');

        return new Hashids($salt, 12);
    }
}
