<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation stricte de l'upload de document.
 *
 * - Type MIME réel contrôlé (pas seulement l'extension)
 * - Taille maximale : 50 Mo
 * - Formats acceptés : docx, doc, txt
 */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Pas de système de comptes en V1 — accès ouvert
        return true;
    }

    public function rules(): array
    {
        return [
            'document' => [
                'required',
                'file',
                'max:51200', // 50 Mo en Ko
                'mimes:docx,doc,txt',
                'mimetypes:application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword,text/plain',
            ],
            // Méthode de détection des titres choisie par l'utilisateur :
            //   - 'regex' : rapide (styles Word + motifs regex), fallback IA
            //   - 'ia'    : analyse par intelligence artificielle
            'title_method' => ['sometimes', 'in:regex,ia'],
        ];
    }

    public function messages(): array
    {
        return [
            'document.required' => 'Veuillez sélectionner un fichier.',
            'document.file' => 'Le fichier envoyé est invalide.',
            'document.max' => 'Le fichier ne doit pas dépasser 50 Mo.',
            'document.mimes' => 'Format non supporté. Formats acceptés : .docx, .doc, .txt',
            'document.mimetypes' => 'Le type réel du fichier ne correspond pas à un document Word ou texte.',
        ];
    }
}
