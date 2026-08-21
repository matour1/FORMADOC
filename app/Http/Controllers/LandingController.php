<?php

namespace App\Http\Controllers;

use App\Models\Plan;

class LandingController extends Controller
{
    /**
     * Page d'accueil (landing page basée sur landingPage.html).
     */
    public function index()
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return view('landing', ['plans' => $plans]);
    }
}
