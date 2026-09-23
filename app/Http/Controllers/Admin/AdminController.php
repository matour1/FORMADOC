<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Document\Clarification\ClarificationService;
use App\Http\Controllers\Controller;
use App\Models\AiUsageLedger;
use App\Models\Document;
use App\Models\DocumentClarification;
use App\Models\User;
use App\Services\Billing\UsageCostCalculator;
use App\Services\Billing\UsageLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Espace d'administration (étape 8).
 *
 * **Pourquoi ces écrans existent.** Jusqu'ici, l'état réel du service ne se lisait
 * qu'en ligne de commande (`billing:report`) ou en SQL. Trois questions restaient
 * sans réponse visuelle :
 *
 *  1. **Que coûte réellement l'IA ?** Le registre d'usage enregistre chaque
 *     tentative, mais un registre ne se lit pas — et le coût des échecs, qui est
 *     le poste le plus difficile à anticiper, disparaît d'un simple survol.
 *  2. **Où en est le traitement des documents ?** Un document bloqué, une
 *     classification qui échoue en série, des clarifications sans réponse : rien
 *     ne le montrait.
 *  3. **Le pipeline fonctionne-t-il ?** Le taux de classification automatique et
 *     les anomalies de structure sont les indicateurs qui décident d'ajuster les
 *     seuils.
 *
 * **Lecture seule.** Aucun écran ne modifie de donnée : agir sur les données d'un
 * utilisateur depuis l'admin exige une décision explicite, qui n'a pas été prise.
 *
 * **Réutiliser les services.** Les chiffres viennent de `UsageLedger` et
 * `ClarificationService`, pas de requêtes Eloquent directes : c'est ce qui garantit
 * que l'écran et la commande `billing:report` affichent les MÊMES totaux. Deux
 * calculs parallèles finiraient par diverger.
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly UsageLedger $ledger,
        private readonly ClarificationService $clarifications,
    ) {}

    /**
     * Tableau de bord : l'état du service en un écran.
     */
    public function index(): View
    {
        // --- Documents par statut ------------------------------------------
        $parStatut = Document::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $totalDocuments = array_sum($parStatut);

        // --- Coût IA global ------------------------------------------------
        // On passe par le registre d'usage : il inclut les échecs et les retries,
        // donc il représente ce qui est RÉELLEMENT dû au fournisseur.
        $coutTotal = $this->totauxGlobaux();

        // --- Clarifications en attente --------------------------------------
        $clarificationsEnAttente = DocumentClarification::whereNull('answered_at')->count();
        $documentsConcernes = DocumentClarification::whereNull('answered_at')
            ->distinct('document_id')
            ->count('document_id');

        // --- Documents sans structure native --------------------------------
        $avecStructureNative = DB::table('document_structures')
            ->whereNotNull('structural_json')
            ->count();

        // --- Répartition par moteur IA --------------------------------------
        // Section « Moteurs & Modèles IA » du tableau de bord. Elle répond à une
        // question que le total global ne permet pas de trancher : QUEL modèle
        // porte le coût, et lequel échoue. Un total stable peut cacher un modèle
        // en train de basculer tous ses appels en repli.
        //
        // On réutilise `breakdownByModel()` plutôt qu'une requête dédiée : c'est
        // la même source que `billing:report`, donc les chiffres affichés ici et
        // en ligne de commande ne peuvent pas diverger.
        $parMoteur = $this->ledger->breakdownByModel();

        // Part de chaque modèle dans le coût total, pour la barre de répartition.
        // Calculée sur les crédits (la grandeur facturée), pas sur les appels :
        // un modèle peut être appelé souvent et coûter peu.
        $creditsTotaux = array_sum(array_column($parMoteur, 'credits'));

        return view('admin.index', [
            'parStatut' => $parStatut,
            'totalDocuments' => $totalDocuments,
            'totalUtilisateurs' => User::count(),
            'coutTotal' => $coutTotal,
            'clarificationsEnAttente' => $clarificationsEnAttente,
            'documentsAvecClarifications' => $documentsConcernes,
            'avecStructureNative' => $avecStructureNative,
            'parMoteur' => $parMoteur,
            'creditsTotaux' => $creditsTotaux,
            'pipelineActif' => config('document.pipeline.v2'),
            'dernieresLignes' => AiUsageLedger::orderByDesc('id')->limit(10)->get(),
        ]);
    }

    /**
     * Rapport de rentabilité : les mêmes chiffres que `billing:report`.
     */
    public function billing(Request $request): View
    {
        $from = $request->input('from');
        $to = $request->input('to');

        $totaux = $this->totauxGlobaux($from, $to);
        $parModele = $this->ledger->breakdownByModel($from, $to);
        $calculateur = app(UsageCostCalculator::class);

        // Prix théorique de la grille : ce qui aurait été facturé pour ces mêmes
        // tokens. Le comparer au coût réellement reversé révèle si le coefficient
        // de rentabilité est absorbé par des coûts non refacturés.
        $theorique = $calculateur->usdToCredits($totaux['usd']);

        return view('admin.billing', [
            'totaux' => $totaux,
            'parModele' => $parModele,
            'theorique' => $theorique,
            'coefficient' => $calculateur->profitabilityCoefficient(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Registre d'usage : les lignes brutes, pour vérifier ce que le rapport agrège.
     */
    public function usage(Request $request): View
    {
        $requete = AiUsageLedger::query()->orderByDesc('id');

        if ($request->filled('model')) {
            $requete->where('model', 'like', '%'.$request->input('model').'%');
        }

        if ($request->filled('user')) {
            $requete->where('user_id', (int) $request->input('user'));
        }

        if ($request->boolean('failures')) {
            $requete->where('succeeded', false);
        }

        if ($request->boolean('fallbacks')) {
            $requete->where('is_fallback', true);
        }

        return view('admin.usage', [
            'lignes' => $requete->paginate(50)->withQueryString(),
            'parModele' => $this->ledger->breakdownByModel(),
            'recalculabilite' => $this->ledger->recalculabilite(),
            'filtres' => [
                'model' => $request->input('model'),
                'user' => $request->input('user'),
                'failures' => $request->boolean('failures'),
                'fallbacks' => $request->boolean('fallbacks'),
            ],
        ]);
    }

    /**
     * Qualité de la classification : ce que la détection n'a pas su trancher.
     */
    public function classification(): View
    {
        // Documents dont la structure native existe : ce sont les seuls pour
        // lesquels la classification a tourné.
        $documents = Document::query()
            ->whereHas('structure', function ($q): void {
                $q->whereNotNull('structural_json');
            })
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $lignes = [];

        foreach ($documents as $document) {
            $structurel = $document->structure?->structuralDocument();

            if ($structurel === null) {
                continue;
            }

            $blocks = $structurel->count();

            if ($blocks === 0) {
                continue;
            }

            $ambigus = $structurel->ambiguous();
            $titres = count($structurel->headings());

            $lignes[] = [
                'document' => $document,
                'blocks' => $blocks,
                'titres' => $titres,
                'ambigus' => count($ambigus),
                // Taux de confiance : la part de blocs acceptés sans hésitation.
                // C'est l'indicateur qui décide d'ajuster le seuil de 0,85.
                'taux' => round(($blocks - count($ambigus)) / $blocks * 100, 1),
                'sans_titre' => $titres === 0,
                'en_attente' => $document->clarifications()->whereNull('answered_at')->count(),
            ];
        }

        // Les documents les plus incertains d'abord : ce sont eux qui demandent
        // une action (ajuster un seuil, confirmer des passages).
        usort($lignes, static fn (array $a, array $b): int => $a['taux'] <=> $b['taux']);

        return view('admin.classification', [
            'lignes' => $lignes,
            'totalAmbigus' => array_sum(array_column($lignes, 'ambigus')),
            'sansTitre' => count(array_filter($lignes, static fn (array $l): bool => $l['sans_titre'])),
        ]);
    }

    /**
     * Liste tous les documents, tous utilisateurs confondus.
     */
    public function documents(Request $request): View
    {
        $requete = Document::query()->orderByDesc('id');

        if ($request->filled('status')) {
            $requete->where('status', $request->input('status'));
        }

        $documents = $requete->paginate(50)->withQueryString();

        // Propriétaires des documents de la page COURANTE seulement : charger tous
        // les utilisateurs de la base pour en afficher 50 serait un gaspillage, et
        // la liste complète n'apporterait rien à l'écran.
        $ids = [];

        foreach ($documents as $document) {
            $id = (int) ($document->metadata['user_id'] ?? 0);

            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return view('admin.documents', [
            'documents' => $documents,
            'statut' => $request->input('status'),
            'proprietaires' => $ids === []
                ? []
                : User::whereIn('id', array_keys($ids))->pluck('email', 'id')->all(),
        ]);
    }

    /**
     * Totaux globaux du registre d'usage.
     *
     * `totalForUser(0)` serait faux : un `user_id` à 0 ne correspond à personne.
     * On agrège donc sur l'ensemble des lignes en passant par la même méthode que
     * les écrans ciblés, avec un identifiant nul qui ne filtre rien — d'où
     * l'agrégation dédiée ci-dessous.
     *
     * @return array{usd: float, credits: int, attempts: int, failures: int, fallbacks: int, tokens: int}
     */
    private function totauxGlobaux(?string $from = null, ?string $to = null): array
    {
        // On reprend la même agrégation que `UsageLedger` sur la totalité : la
        // cohérence des chiffres entre l'admin et la commande en dépend.
        $requete = AiUsageLedger::query();

        if ($from !== null) {
            $requete->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $requete->where('created_at', '<=', $to);
        }

        $ligne = $requete->selectRaw(
            'COALESCE(SUM(cost_usd), 0) as usd,'
            .' COALESCE(SUM(cost_credits), 0) as credits,'
            .' COUNT(*) as attempts,'
            .' COALESCE(SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END), 0) as failures,'
            .' COALESCE(SUM(CASE WHEN is_fallback = 1 THEN 1 ELSE 0 END), 0) as fallbacks,'
            .' COALESCE(SUM(input_tokens + output_tokens), 0) as tokens'
        )->first();

        return [
            'usd' => round((float) ($ligne->usd ?? 0), 6),
            'credits' => (int) ($ligne->credits ?? 0),
            'attempts' => (int) ($ligne->attempts ?? 0),
            'failures' => (int) ($ligne->failures ?? 0),
            'fallbacks' => (int) ($ligne->fallbacks ?? 0),
            'tokens' => (int) ($ligne->tokens ?? 0),
        ];
    }
}
