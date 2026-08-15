<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation de la génération avec couverture (Phase 3).
 *
 * L'étudiant fournit une couverture d'exemple (DOCX) et les valeurs à
 * substituer (nom, titre, encadrant, date). Les valeurs sont optionnelles :
 * seules les zones fournies sont remplacées, la structure/styles de l'exemple
 * étant conservés.
 */
class GenerateCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Pas de système de comptes en V1 — accès ouvert
        return true;
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
