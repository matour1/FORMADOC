<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation de la confirmation utilisateur de la structure (Phase 4).
 *
 * Chaque correction est adressée par la clé de position d'un item ambigu
 * (ex. « s0e12pbody ») et vaut :
 *   - « 1 » : promouvoir en titre (niveau 1)
 *   - « 2 » : rétrograder en sous-titre (niveau 2)
 *   - « 3 » : rétrograder en sous-titre de niveau 3
 *   - « remove » : retirer du plan
 */
class ValidateStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Pas de système de comptes en V1 — accès ouvert
        return true;
    }

    public function rules(): array
    {
        return [
            'corrections' => ['nullable', 'array'],
            'corrections.*' => ['required', 'string', Rule::in(['1', '2', '3', 'remove'])],
        ];
    }

    public function messages(): array
    {
        return [
            'corrections.array' => 'Les corrections doivent former un tableau valide.',
            'corrections.*.in' => 'Action de correction invalide. Actions acceptées : 1, 2, 3, remove.',
        ];
    }
}
