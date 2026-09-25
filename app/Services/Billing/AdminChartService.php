<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\AiUsageLedger;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Séries temporelles de l'exploitation, pour les graphiques.
 *
 * **Le problème que ce service résout.** Les écrans d'administration montraient des
 * totaux. Un total ne dit pas si une activité s'arrête : « 44 appels » est
 * rassurant vu de loin, mais 44 appels dont 43 hier et 1 aujourd'hui est une panne
 * qui ne se voit nulle part. C'est la SÉRIE qui le montre, pas la somme.
 *
 * **Pourquoi la fenêtre est paramétrable, et pourquoi les jours vides sont
 * conservés.** Les données existent par jour et non par période : agréger sur un
 * mois donnerait une barre, ce qui n'apprend rien. On produit donc une série
 * journalière. Mais SQL ne renvoie que les jours AYANT des lignes — un « trou »
 * dans la série apparaîtrait comme un jour absent, et le graphique dessinerait un
 * espacement régulier là où il y a eu une interruption de dix jours. La série est
 * donc complétée à zéro jour par jour, ce qui rend la discontinuité VISIBLE.
 *
 * **Pourquoi les requêtes ne passent pas par les modèles.** Ces agrégations
 * portent sur toutes les lignes d'une table : passer par Eloquent chargerait des
 * modèles complets pour n'en garder que trois colonnes. On interroge donc le
 * constructeur avec `selectRaw`, comme le fait déjà `AdminController`, pour rester
 * cohérent avec le reste de l'espace d'exploitation.
 *
 * **La conséquence à assumer : un registre vide produit une série de zéros.** Ce
 * n'est pas un graphique cassé, c'est un graphique honnête — mais l'écran doit
 * alors dire POURQUOI il est plat (aucun appel enregistré) plutôt que de laisser
 * croire à une chute d'activité.
 */
class AdminChartService
{
    /**
     * Nombre de jours par défaut.
     *
     * 30 jours : assez long pour voir une tendance, assez court pour qu'un
     * changement récent reste lisible. Une fenêtre de 90 jours lisserait une panne
     * de deux jours jusqu'à la rendre invisible.
     */
    public const DEFAULT_DAYS = 30;

    /**
     * Série du coût IA par jour.
     *
     * @return array{labels: array<int, string>, series: array<string, array<int, float|int>>, vide: bool}
     */
    public function coutIaParJour(int $jours = self::DEFAULT_DAYS): array
    {
        $debut = Carbon::today()->subDays($jours - 1);

        $lignes = AiUsageLedger::query()
            ->where('created_at', '>=', $debut)
            ->selectRaw(
                'DATE(created_at) as jour,'
                .' COUNT(*) as appels,'
                .' COALESCE(SUM(cost_credits), 0) as credits,'
                .' COALESCE(SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END), 0) as echecs'
            )
            ->groupBy('jour')
            ->orderBy('jour')
            ->get()
            ->keyBy('jour');

        return $this->serie(
            $debut,
            $jours,
            [
                'credits' => 'Crédits facturés',
                'appels' => 'Appels facturés',
                'echecs' => 'Échecs (facturés aussi)',
            ],
            fn (Carbon $jour): array => [
                'credits' => (int) ($lignes[$jour->toDateString()]->credits ?? 0),
                'appels' => (int) ($lignes[$jour->toDateString()]->appels ?? 0),
                'echecs' => (int) ($lignes[$jour->toDateString()]->echecs ?? 0),
            ],
        );
    }

    /**
     * Série des inscriptions par jour.
     *
     * @return array{labels: array<int, string>, series: array<string, array<int, float|int>>, vide: bool}
     */
    public function inscriptionsParJour(int $jours = self::DEFAULT_DAYS): array
    {
        $debut = Carbon::today()->subDays($jours - 1);

        $lignes = User::query()
            ->where('created_at', '>=', $debut)
            ->selectRaw('DATE(created_at) as jour, COUNT(*) as inscriptions')
            ->groupBy('jour')
            ->orderBy('jour')
            ->get()
            ->keyBy('jour');

        return $this->serie(
            $debut,
            $jours,
            ['inscriptions' => 'Comptes créés'],
            fn (Carbon $jour): array => [
                'inscriptions' => (int) ($lignes[$jour->toDateString()]->inscriptions ?? 0),
            ],
        );
    }

    /**
     * Série des documents traités par jour.
     *
     * @return array{labels: array<int, string>, series: array<string, array<int, float|int>>, vide: bool}
     */
    public function documentsParJour(int $jours = self::DEFAULT_DAYS): array
    {
        $debut = Carbon::today()->subDays($jours - 1);

        $lignes = Document::query()
            ->where('created_at', '>=', $debut)
            ->selectRaw(
                'DATE(created_at) as jour,'
                .' COUNT(*) as total,'
                .' COALESCE(SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END), 0) as echecs'
            )
            ->groupBy('jour')
            ->orderBy('jour')
            ->get()
            ->keyBy('jour');

        return $this->serie(
            $debut,
            $jours,
            ['total' => 'Documents déposés', 'echecs' => 'Échecs'],
            fn (Carbon $jour): array => [
                'total' => (int) ($lignes[$jour->toDateString()]->total ?? 0),
                'echecs' => (int) ($lignes[$jour->toDateString()]->echecs ?? 0),
            ],
        );
    }

    /**
     * Répartition de la consommation par fournisseur et modèle.
     *
     * C'est l'analyse la plus directement actionnable : elle dit QUEL modèle porte
     * le coût, et lequel échoue. Un taux d'échec de 100 % sur un modèle payant est
     * une fuite — chaque tentative est facturée sans rien produire.
     *
     * @return array<int, array{
     *     provider: string, model: string, appels: int, echecs: int,
     *     taux_echec: float, tokens: int, credits: int, part: float
     * }>
     */
    public function consommationParFournisseur(): array
    {
        $lignes = AiUsageLedger::query()
            ->selectRaw(
                'provider, model,'
                .' COUNT(*) as appels,'
                .' COALESCE(SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END), 0) as echecs,'
                .' COALESCE(SUM(input_tokens + output_tokens), 0) as tokens,'
                .' COALESCE(SUM(cost_credits), 0) as credits'
            )
            ->groupBy('provider', 'model')
            ->orderByDesc('credits')
            ->get();

        $totalCredits = (int) $lignes->sum('credits');

        return $lignes->map(function ($l) use ($totalCredits): array {
            $appels = (int) $l->appels;
            $echecs = (int) $l->echecs;

            return [
                'provider' => (string) $l->provider,
                'model' => (string) $l->model,
                'appels' => $appels,
                'echecs' => $echecs,
                'taux_echec' => $appels > 0 ? round($echecs / $appels * 100, 1) : 0.0,
                'tokens' => (int) $l->tokens,
                'credits' => (int) $l->credits,
                'part' => $totalCredits > 0 ? round((int) $l->credits / $totalCredits * 100, 1) : 0.0,
            ];
        })->all();
    }

    /**
     * Répartition par type de tâche.
     *
     * @return array<int, array{task_type: string, appels: int, echecs: int, credits: int, part: float}>
     */
    public function consommationParTache(): array
    {
        $lignes = AiUsageLedger::query()
            ->selectRaw(
                'task_type,'
                .' COUNT(*) as appels,'
                .' COALESCE(SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END), 0) as echecs,'
                .' COALESCE(SUM(cost_credits), 0) as credits'
            )
            ->groupBy('task_type')
            ->orderByDesc('credits')
            ->get();

        $total = (int) $lignes->sum('credits');

        return $lignes->map(fn ($l): array => [
            'task_type' => (string) ($l->task_type ?: 'non précisé'),
            'appels' => (int) $l->appels,
            'echecs' => (int) $l->echecs,
            'credits' => (int) $l->credits,
            'part' => $total > 0 ? round((int) $l->credits / $total * 100, 1) : 0.0,
        ])->all();
    }

    /**
     * Rentabilité : recettes encaissées contre coût réel de l'IA.
     *
     * **Ce que ce rapport peut et ne peut pas dire.** Les recettes viennent des
     * factures payées et des crédits achetés ; le coût vient du registre d'usage,
     * qui inclut les échecs. Les deux sont réels et traçables. En revanche le
     * résultat n'est PAS une marge comptable : il ignore les frais de la passerelle,
     * les remboursements et la fiscalité. L'écran doit le dire, sinon un chiffre
     * partiel est lu comme un bénéfice.
     *
     * @return array{
     *     encaisse_fcfa: int, cout_ia_credits: int, ecart_credits: int,
     *     factures_payees: int, appels: int, echecs: int,
     *     taux_echec: float, cout_echecs_credits: int
     * }
     */
    public function rentabilite(int $jours = self::DEFAULT_DAYS): array
    {
        $debut = Carbon::today()->subDays($jours - 1);

        // Encaissements : les factures marquées payées, sur la période.
        //
        // Le filtre porte sur `paid_at` et NON sur `created_at`. Une facture peut
        // être émise puis réglée plusieurs jours plus tard : la compter à sa date de
        // création daterait l'encaissement au jour de l'émission, et le rapprochement
        // avec le relevé de la passerelle serait faux. C'est `paid_at` qui porte la
        // date à laquelle l'argent est réellement entré.
        $encaisse = (int) DB::table('invoices')
            ->where('status', 'paid')
            ->where('paid_at', '>=', $debut)
            ->sum('amount');

        $factures = (int) DB::table('invoices')
            ->where('status', 'paid')
            ->where('paid_at', '>=', $debut)
            ->count();

        // Coût IA : le registre, qui inclut les tentatives échouées — c'est ce qui
        // est réellement dû au fournisseur.
        $agregat = AiUsageLedger::query()
            ->where('created_at', '>=', $debut)
            ->selectRaw(
                'COALESCE(SUM(cost_credits), 0) as credits,'
                .' COUNT(*) as appels,'
                .' COALESCE(SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END), 0) as echecs,'
                .' COALESCE(SUM(CASE WHEN succeeded = 0 THEN cost_credits ELSE 0 END), 0) as cout_echecs'
            )
            ->first();

        $credits = (int) ($agregat->credits ?? 0);
        $appels = (int) ($agregat->appels ?? 0);
        $echecs = (int) ($agregat->echecs ?? 0);

        return [
            'encaisse_fcfa' => $encaisse,
            'cout_ia_credits' => $credits,
            'ecart_credits' => $encaisse - $credits,
            'factures_payees' => $factures,
            'appels' => $appels,
            'echecs' => $echecs,
            'taux_echec' => $appels > 0 ? round($echecs / $appels * 100, 1) : 0.0,
            'cout_echecs_credits' => (int) ($agregat->cout_echecs ?? 0),
        ];
    }

    /**
     * Totaux cumulés, sans fenêtre de temps.
     *
     * **Pourquoi ces chiffres existent à côté des séries.** Une série fenêtrée sur
     * 30 jours affiche zéro si le dernier encaissement date de 35 jours — ce qui est
     * exact pour la période, mais se lit comme « rien n'a jamais été encaissé ». Les
     * cumuls répondent à l'autre question : « depuis le début, où en est-on ? ». Les
     * deux sont nécessaires, et les confondre fait conclure à une panne ou à une
     * erreur de calcul.
     *
     * @return array{
     *     encaisse_total_fcfa: int, factures_total: int,
     *     cout_ia_total_credits: int, appels_total: int, echecs_total: int,
     *     credits_en_circulation: int, utilisateurs: int, documents: int
     * }
     */
    public function cumuls(): array
    {
        return [
            'encaisse_total_fcfa' => (int) DB::table('invoices')->where('status', 'paid')->sum('amount'),
            'factures_total' => (int) DB::table('invoices')->where('status', 'paid')->count(),
            'cout_ia_total_credits' => (int) AiUsageLedger::sum('cost_credits'),
            'appels_total' => (int) AiUsageLedger::count(),
            'echecs_total' => (int) AiUsageLedger::where('succeeded', false)->count(),
            'credits_en_circulation' => (int) User::sum('credits_balance'),
            'utilisateurs' => (int) User::count(),
            'documents' => (int) Document::count(),
        ];
    }

    /**
     * Construit une série journalière complète, jours vides inclus.
     *
     * @param  array<string, string>  $series  clé technique → libellé
     * @param  callable(Carbon): array<string, int|float>  $extracteur
     * @return array{labels: array<int, string>, series: array<string, array<int, float|int>>, vide: bool}
     */
    private function serie(Carbon $debut, int $jours, array $series, callable $extracteur): array
    {
        $labels = [];
        $donnees = array_fill_keys(array_keys($series), []);
        $totalGeneral = 0;

        for ($i = 0; $i < $jours; $i++) {
            $jour = $debut->copy()->addDays($i);

            // Format court : sur 30 étiquettes, « 25/09 » reste lisible là où
            // « mercredi 25 septembre » se chevauche.
            $labels[] = $jour->format('d/m');

            foreach ($extracteur($jour) as $cle => $valeur) {
                $donnees[$cle][] = $valeur;
                $totalGeneral += $valeur;
            }
        }

        return [
            'labels' => $labels,
            'series' => $donnees,
            // « vide » au sens strict : aucune valeur sur toute la période. L'écran
            // doit alors expliquer l'absence de données plutôt que d'afficher un
            // graphique plat, qui se lit comme une chute d'activité.
            'vide' => $totalGeneral === 0,
        ];
    }
}
