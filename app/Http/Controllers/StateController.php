<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Pages de démonstration / états (fidélité template) :
 * chargement, état vide, aperçu indisponible, maintenance.
 *
 * **L'onboarding n'est plus ici.** Il a été extrait vers `OnboardingController`,
 * qui persiste la progression sur le compte : la page `states/onboarding` était
 * une maquette (aucune écriture, étape perdue à chaque visite). Les états
 * restants ci-dessous sont, eux, de pures démonstrations d'interface.
 */
class StateController extends Controller
{
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
