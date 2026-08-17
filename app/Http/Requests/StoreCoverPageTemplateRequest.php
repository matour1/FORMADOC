<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCoverPageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // TODO policy plus stricte si auth requise
    }

    /**
     * Les champs cachés du builder soumettent elements/page_style en JSON
     * (chaîne). On les décode ici pour que les règles de validation reçoivent
     * de vrais tableaux.
     */
    protected function prepareForValidation(): void
    {
        foreach (['elements', 'page_style'] as $key) {
            if ($this->has($key) && is_string($this->input($key))) {
                $decoded = json_decode($this->input($key), true);
                if (is_array($decoded)) {
                    $this->merge([$key => $decoded]);
                }
            }
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('cover_page_templates', 'name')->ignore($this->route('cover_template')),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_public' => ['boolean'],
            'based_on_id' => ['nullable', 'integer', 'exists:cover_page_templates,id'],
            // Structure
            'elements' => ['required', 'array', 'min:1'],
            'elements.*.type' => ['required', Rule::in(['row'])],
            'elements.*.cells' => ['required', 'array', 'min:1'],
            'elements.*.cells.*.gridSpan' => ['nullable', 'integer', 'min:1', 'max:12'],
            'elements.*.cells.*.blocks' => ['required', 'array', 'min:1'],
            'elements.*.cells.*.blocks.*.kind' => ['required', Rule::in([
                'text', 'image', 'spacer', 'divider', 'logo',
            ])],
            'elements.*.cells.*.blocks.*.text' => ['nullable', 'string', 'max:2000'],
            'elements.*.cells.*.blocks.*.placeholder' => ['nullable', 'string', 'max:120'],
            'elements.*.cells.*.blocks.*.src' => ['nullable', 'string', 'max:255'],
            'elements.*.cells.*.blocks.*.heightPx' => ['nullable', 'integer', 'min:10', 'max:2000'],
            // Style de page
            'page_style' => ['nullable', 'array'],
            'page_style.orientation' => ['nullable', Rule::in(['portrait', 'landscape'])],
            'page_style.size' => ['nullable', 'string', 'max:20'],
            'page_style.marginTopMm' => ['nullable', 'integer', 'min:0', 'max:200'],
            'page_style.marginBottomMm' => ['nullable', 'integer', 'min:0', 'max:200'],
            'page_style.marginLeftMm' => ['nullable', 'integer', 'min:0', 'max:200'],
            'page_style.marginRightMm' => ['nullable', 'integer', 'min:0', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Un modèle de page de garde porte déjà ce nom.',
            'elements.required' => 'Le modèle doit contenir au moins une rangée.',
            'elements.*.cells.*.blocks.*.kind.in' => 'Type de bloc non supporté.',
        ];
    }

    /**
     * Normalise la structure avant persistance (tri des clés, clamp des valeurs).
     */
    public function normalized(): array
    {
        $data = $this->validated();
        $data['elements'] = array_values(array_map(function ($row) {
            $row['cells'] = array_values(array_map(function ($cell) {
                $cell['gridSpan'] = max(1, min(12, (int) ($cell['gridSpan'] ?? 1)));
                $cell['blocks'] = array_values(array_map(function ($block) {
                    if (($block['kind'] ?? null) === 'spacer') {
                        $block['heightPx'] = max(10, min(2000, (int) ($block['heightPx'] ?? 60)));
                    }
                    return $block;
                }, $cell['blocks']));
                return $cell;
            }, $row['cells']));
            return $row;
        }, $data['elements']));
        return $data;
    }
}
