<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Billing\AdminChartService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Analyse : graphiques d'activité, de consommation et de rentabilité.
 *
 * **Pourquoi un écran séparé de la vue d'ensemble.** La vue d'ensemble répond à
 * « où en est-on MAINTENANT » avec des totaux. Celui-ci répond à « comment cela
 * évolue » avec des séries. Un total ne montre pas qu'une activité s'arrête : c'est
 * la série qui le montre. Les mélanger dans un même écran noierait les points
 * d'attention sous les courbes.
 *
 * **La fenêtre est un paramètre, et elle est affichée.** Un graphique sans période
 * annoncée ne se lit pas : « 79 crédits » sur 7 jours et sur 90 jours sont deux
 * situations opposées. Le libellé de la page reprend donc la durée retenue.
 */
class AnalyticsController extends Controller
{
    /**
     * Durées proposées.
     *
     * Volontairement peu nombreuses : chaque valeur supplémentaire est une courbe
     * de plus à interpréter. 7 jours pour voir une panne, 30 pour une tendance,
     * 90 pour un cycle de facturation trimestriel.
     *
     * @var array<int, int>
     */
    private const FENETRES = [7, 30, 90];

    public function __construct(
        private readonly AdminChartService $charts,
    ) {}

    /**
     * Écran d'analyse.
     */
    public function index(Request $request): View
    {
        $jours = (int) $request->input('jours', AdminChartService::DEFAULT_DAYS);

        // Une durée arbitraire venue de la requête serait interpolée dans un
        // intervalle SQL. On la ramène donc à la liste déclarée : `?jours=999999`
        // ferait balayer toute la table à chaque affichage.
        if (! in_array($jours, self::FENETRES, true)) {
            $jours = AdminChartService::DEFAULT_DAYS;
        }

        return view('admin.analytics', [
            'jours' => $jours,
            'fenetres' => self::FENETRES,
            'coutIa' => $this->charts->coutIaParJour($jours),
            'inscriptions' => $this->charts->inscriptionsParJour($jours),
            'documents' => $this->charts->documentsParJour($jours),
            'fournisseurs' => $this->charts->consommationParFournisseur(),
            'taches' => $this->charts->consommationParTache(),
            'rentabilite' => $this->charts->rentabilite($jours),
            'cumuls' => $this->charts->cumuls(),
        ]);
    }
}
