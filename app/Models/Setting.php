<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglage d'exploitation, modifiable depuis l'espace d'administration.
 *
 * La valeur est stockée en texte et son type conservé à côté (`type`), parce
 * qu'une base de données ne distingue pas « 0 » de « false » et qu'un `"0"`
 * texte est *truthy* en PHP. Voir `SettingsRepository` pour la conversion et
 * la surcharge de `config()`.
 */
#[Fillable(['key', 'value', 'type', 'group', 'label', 'description', 'updated_by'])]
class Setting extends Model
{
    /**
     * Clé primaire métier : un réglage se nomme, il ne se numérote pas.
     */
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    /**
     * Placer la clé en `false` serait tentant puisque l'identifiant est fourni
     * à l'écriture — mais `updateOrCreate()` s'appuie sur `$incrementing` pour
     * décider s'il insère ou met à jour, et sur la présence de l'attribut dans
     * le modèle résultant.
     */
    public $incrementing = false;

    /**
     * Auteur de la dernière modification.
     *
     * Ce n'est pas de la traçabilité décorative : un taux de change ou une marge
     * modifié change le prix payé par tous les utilisateurs suivants. Savoir QUI
     * l'a changé et QUAND est la seule façon de reconstituer un décalage de
     * facturation constaté plus tard.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
