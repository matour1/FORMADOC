<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Validation de la génération avec couverture (Phase 3).
 *
 * L'étudiant fournit une couverture d'exemple (DOCX) et les valeurs à
 * substituer (nom, titre, encadrant, date). Les valeurs sont optionnelles :
 * seules les zones fournies sont remplacées, la structure/styles de l'exemple
 * étant conservés.
 *
 * P0-1 (anti-IDOR) : l'appartenance du document est vérifiée ICI, AVANT les
 * règles de validation, pour qu'un document d'autrui réponde 404 même
 * lorsqu'une règle échouerait (ex. fichier « cover » manquant) — on ne
 * révèle jamais l'existence du document.
 */
class GenerateCoverRequest extends FormRequest
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
            'cover' => [
                'required',
                'file',
                'max:51200', // 50 Mo en Ko
                'mimes:docx',
                'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'nom' => ['nullable', 'string', 'max:255'],
            'titre' => ['nullable', 'string', 'max:255'],
            'encadrant' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'cover.required' => 'Veuillez fournir une couverture d\'exemple (DOCX).',
            'cover.file' => 'Le fichier envoyé est invalide.',
            'cover.max' => 'La couverture ne doit pas dépasser 50 Mo.',
            'cover.mimes' => 'Format non supporté. Formats acceptés : .docx',
            'cover.mimetypes' => 'Le type réel du fichier ne correspond pas à un document Word (.docx).',
        ];
    }
}
