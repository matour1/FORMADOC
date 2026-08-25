<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
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
 *
 * P0-1 (anti-IDOR) : l'appartenance du document est vérifiée AVANT les
 * règles de validation, pour une réponse 404 uniforme (on ne révèle pas
 * l'existence de documents tiers).
 */
class ValidateStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        if (! $document instanceof Document) {
            return false;
        }

        $userId = (int) ($document->metadata['user_id'] ?? 0);

        return $userId !== 0 && $userId === (int) Auth::id();
    }

    /**
     * 404 plutôt que 403 : on ne révèle pas l'existence du document.
     */
    protected function failedAuthorization(): void
    {
        abort(404, 'Document introuvable.');
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
