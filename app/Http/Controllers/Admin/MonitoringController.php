<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Billing\DocumentMonitoringService;
use Illuminate\View\View;

/**
 * Surveillance du traitement documentaire.
 *
 * **Pourquoi un écran distinct.** La vue d'ensemble répond à « où en est-on » et
 * l'analyse à « comment cela évolue ». Celui-ci répond à « qu'est-ce qui bloque
 * MAINTENANT » — une question opérationnelle qui demande une liste de cas concrets,
 * pas des agrégats.
 *
 * **Trois indicateurs sont délibérément absents** (durée de traitement, progression
 * d'un document, débit de file). Les explications complètes sont dans
 * `DocumentMonitoringService` : en résumé, aucune colonne de jalons n'existe, donc
 * ces chiffres seraient inventés. Le calcul disponible (`updated_at - created_at`)
 * mesure aussi le temps où l'utilisateur n'a rien fait : mesuré sur les documents
 * réels, il donne de 11 minutes à 3 jours et demi pour le même traitement. On
 * affiche donc l'ÂGE, qui est une mesure vraie.
 */
class MonitoringController extends Controller
{
    public function __construct(
        private readonly DocumentMonitoringService $monitoring,
    ) {}

    /**
     * Écran de surveillance.
     */
    public function index(): View
    {
        return view('admin.monitoring', [
            'statuts' => $this->monitoring->documentsParStatut(),
            'bloques' => $this->monitoring->documentsBloques(20),
            'clarifications' => $this->monitoring->clarificationsEnAttente(),
            'pipelines' => $this->monitoring->pipelines(),
            'file' => $this->monitoring->fileAttente(),
            'points' => $this->monitoring->pointsDAttention(),
            'seuilAttention' => DocumentMonitoringService::SEUIL_ATTENTION_JOURS,
            'seuilAbandon' => DocumentMonitoringService::SEUIL_ABANDON_JOURS,
            'seuilPassages' => DocumentMonitoringService::SEUIL_PASSAGES_EXCESSIFS,
        ]);
    }
}
