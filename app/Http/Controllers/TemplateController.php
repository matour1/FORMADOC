<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bibliothèque de gabarits (mise en forme) et pages de garde.
 *
 * Fidélité template : « Modèles » (bibliothèque) et « Comparer des gabarits »
 * (comparaison côte à côte, réservée Premium+).
 */
class TemplateController extends Controller
{
    /**
     * Bibliothèque des gabarits et pages de garde.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // Gabarits de mise en forme : publics (la table n'a pas de user_id)
        $formatTemplates = Template::query()
            ->where('is_public', true)
            ->orderBy('name')
            ->get();

        return view('templates.index', [
            'formatTemplates' => $formatTemplates,
        ]);
    }

    /**
     * Comparaison côte à côte de deux gabarits (Premium+).
     */
    public function compare(Request $request): View
    {
        $user = $request->user();

        $templates = Template::query()
            ->where('is_public', true)
            ->orderBy('name')
            ->get();

        // Réservé Premium+ (cohérent avec le badge du template)
        $premium = in_array($user->currentPlanSlug(), ['premium', 'pro', 'enterprise']);

        return view('templates.compare', [
            'templates' => $templates,
            'premium' => $premium,
        ]);
    }
}
