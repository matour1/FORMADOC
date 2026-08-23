<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Pages de démonstration / états (fidélité template) :
 * onboarding, chargement, état vide, aperçu indisponible, maintenance.
 */
class StateController extends Controller
{
    public function onboarding(): View
    {
        return view('states.onboarding');
    }

    public function loading(): View
    {
        return view('states.loading');
    }

    public function empty(): View
    {
        return view('states.empty');
    }

    public function previewFallback(): View
    {
        return view('states.preview-fallback');
    }

    public function maintenance(): View
    {
        return view('states.maintenance');
    }
}
