<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\AiUsageLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registre d'usage IA : enregistrement et agrégation du coût réel (phase R7).
 *
 * **Le problème qu'il résout.** Avant R7, la facturation reposait sur le coût du
 * **dernier appel réussi**. Or trois coûts réels échappaient :
 *
 *  1. **les retries** — Laravel réessaie jusqu'à `max_retries` fois, et chaque
 *     tentative est facturée par le fournisseur, même quand elle échoue ;
 *  2. **les modèles candidats échoués** — la sélection en parcourt plusieurs
 *     jusqu'à trouver un modèle qui réponde, et chaque échec coûte ;
 *  3. **les bascules de fournisseur** — le repli DeepSeek change le prix sans
 *     laisser de trace.
 *
 * Le registre enregistre **chaque tentative** avec le modèle réellement appelé.
 * Conséquence directe : le coût total est **recalculable depuis les lignes
 * brutes**, ce qui rend la facturation vérifiable. C'est le critère
 * d'acceptation « le coût du ledger est recalculable depuis les données brutes ».
 *
 * **Tolérance à l'échec.** Une écriture qui échoue n'interrompt jamais l'appel
 * IA : perdre une ligne de comptabilité est regrettable, perdre la réponse à
 * l'utilisateur ne l'est pas. L'échec est loggé, et c'est tout.
 */
final class UsageLedger
{
    /**
     * Enregistre une tentative d'appel.
     *
     * @param  array<string, mixed>  $donnees  Champs de la ligne
     * @return null|AiUsageLedger null si l'enregistrement a échoué
     */
    public function record(array $donnees): ?AiUsageLedger
    {
        try {
            return AiUsageLedger::create([
                'user_id' => $donnees['user_id'] ?? null,
                'chat_session_id' => $donnees['chat_session_id'] ?? null,
                'document_id' => $donnees['document_id'] ?? null,
                'provider' => (string) ($donnees['provider'] ?? 'openrouter'),
                'model' => (string) ($donnees['model'] ?? 'inconnu'),
                'task_type' => (string) ($donnees['task_type'] ?? ''),
                'input_tokens' => (int) ($donnees['input_tokens'] ?? 0),
                'output_tokens' => (int) ($donnees['output_tokens'] ?? 0),
                'attempt' => max(1, (int) ($donnees['attempt'] ?? 1)),
                'succeeded' => (bool) ($donnees['succeeded'] ?? false),
                'is_fallback' => (bool) ($donnees['is_fallback'] ?? false),
                'fallback_from' => $donnees['fallback_from'] ?? null,
                'estimated' => (bool) ($donnees['estimated'] ?? false),
                'cost_usd' => round((float) ($donnees['cost_usd'] ?? 0.0), 6),
                'cost_credits' => max(0, (int) ($donnees['cost_credits'] ?? 0)),
                'reference' => $donnees['reference'] ?? null,
                'error' => isset($donnees['error'])
                    ? mb_substr((string) $donnees['error'], 0, 500)
                    : null,
                'metadata' => $donnees['metadata'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Une ligne perdue ne doit jamais faire échouer l'appel IA : la
            // réponse à l'utilisateur passe avant la comptabilité.
            Log::error('Registre d’usage : enregistrement impossible', [
                'model' => $donnees['model'] ?? 'inconnu',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Enregistre une tentative ÉCHOUÉE.
     *
     * Un échec coûte quand même : le fournisseur facture les tokens d'entrée
     * envoyés avant que la connexion ne tombe. Ne pas l'enregistrer sous-estime
     * systématiquement le coût des périodes d'instabilité — exactement quand le
     * volume de retries explose.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function recordFailure(string $model, string $error, array $contexte = []): ?AiUsageLedger
    {
        return $this->record([
            ...$contexte,
            'model' => $model,
            'succeeded' => false,
            'error' => $error,
            // Les tokens d'entrée ont bien été envoyés, même sans réponse.
            'input_tokens' => (int) ($contexte['input_tokens'] ?? 0),
            'output_tokens' => 0,
        ]);
    }

    /**
     * Enregistre une bascule de fournisseur.
     *
     * Le `fallback_from` est essentiel : sans lui, on constate un coût au prix
     * DeepSeek sans savoir quel modèle avait échoué avant, ni pourquoi le tarif
     * a changé.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function recordFallback(string $fromModel, string $toModel, array $contexte = []): ?AiUsageLedger
    {
        return $this->record([
            ...$contexte,
            'model' => $toModel,
            'provider' => 'deepseek_fallback',
            'is_fallback' => true,
            'fallback_from' => $fromModel,
            'succeeded' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Agrégation
    // -------------------------------------------------------------------------

    /**
     * Coût total d'une référence métier (« chat:12 », « document:34 »).
     *
     * Inclut les tentatives échouées : c'est le coût réellement dû au
     * fournisseur, pas le coût de ce qui a produit une réponse.
     *
     * @return array{usd: float, credits: int, attempts: int, failures: int, fallbacks: int, tokens: int}
     */
    public function totalFor(string $reference): array
    {
        return $this->agreger(AiUsageLedger::where('reference', $reference));
    }

    /**
     * Coût total d'une session de chat.
     *
     * @return array{usd: float, credits: int, attempts: int, failures: int, fallbacks: int, tokens: int}
     */
    public function totalForSession(int $chatSessionId): array
    {
        return $this->agreger(AiUsageLedger::where('chat_session_id', $chatSessionId));
    }

    /**
     * Coût total d'un document (pipeline documentaire).
     *
     * @return array{usd: float, credits: int, attempts: int, failures: int, fallbacks: int, tokens: int}
     */
    public function totalForDocument(int $documentId): array
    {
        return $this->agreger(AiUsageLedger::where('document_id', $documentId));
    }

    /**
     * Coût total d'un utilisateur sur une période.
     *
     * @return array{usd: float, credits: int, attempts: int, failures: int, fallbacks: int, tokens: int}
     */
    public function totalForUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        $requete = AiUsageLedger::where('user_id', $userId);

        if ($from !== null) {
            $requete->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $requete->where('created_at', '<=', $to);
        }

        return $this->agreger($requete);
    }

    /**
     * Coût imputable aux seules tentatives échouées.
     *
     * Mesure la « fuite sèche » : ce qui est payé sans contrepartie pour
     * l'utilisateur. Un montant élevé signale une instabilité de fournisseur, pas
     * une erreur de facturation — mais il doit rester visible.
     */
    public function wasteForUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        $requete = AiUsageLedger::where('user_id', $userId)->where('succeeded', false);

        if ($from !== null) {
            $requete->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $requete->where('created_at', '<=', $to);
        }

        return $this->agreger($requete);
    }

    /**
     * Agrège un ensemble de lignes.
     *
     * L'agrégation est faite **en base** (SUM/COUNT) plutôt qu'en PHP : un mois
     * d'activité représente plusieurs milliers de lignes, et les charger toutes
     * en mémoire pour les additionner serait inutilement coûteux.
     *
     * @param  Builder<AiUsageLedger>  $requete
     * @return array{usd: float, credits: int, attempts: int, failures: int, fallbacks: int, tokens: int}
     */
    private function agreger($requete): array
    {
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

    /**
     * Répartition par modèle, pour le rapport de rentabilité.
     *
     * @return array<int, array<string, mixed>>
     */
    public function breakdownByModel(?string $from = null, ?string $to = null): array
    {
        $requete = AiUsageLedger::query();

        if ($from !== null) {
            $requete->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $requete->where('created_at', '<=', $to);
        }

        return $requete
            ->selectRaw(
                'model, provider,'
                .' COUNT(*) as attempts,'
                .' COALESCE(SUM(CASE WHEN succeeded = 0 THEN 1 ELSE 0 END), 0) as failures,'
                .' COALESCE(SUM(cost_usd), 0) as usd,'
                .' COALESCE(SUM(cost_credits), 0) as credits,'
                .' COALESCE(SUM(input_tokens + output_tokens), 0) as tokens'
            )
            ->groupBy('model', 'provider')
            ->orderByRaw('SUM(cost_usd) DESC')
            ->get()
            ->map(static fn ($ligne): array => [
                'model' => (string) $ligne->model,
                'provider' => (string) $ligne->provider,
                'attempts' => (int) $ligne->attempts,
                'failures' => (int) $ligne->failures,
                'usd' => round((float) $ligne->usd, 6),
                'credits' => (int) $ligne->credits,
                'tokens' => (int) $ligne->tokens,
            ])
            ->all();
    }

    /**
     * Vérifie que le coût stocké est recalculable depuis les données brutes.
     *
     * **Pourquoi c'est un contrôle, et pas une simple redondance.** Le registre
     * stocke à la fois les tokens (bruts, fournis par le fournisseur) et le coût
     * (dérivé, recalculé à l'écriture). Si les deux divergent, c'est qu'un prix a
     * changé en base de configuration sans que les lignes historiques soient
     * retraitées — un écart silencieux, exactement le défaut que R7 doit
     * supprimer. Ce contrôle le rend visible.
     *
     * Les lignes dont les tokens ont été **estimés** (échecs, table repli) sont
     * comptées à part : leur coût ne peut pas être recalculé au token près, la
     * comparaison n'aurait pas de sens. Les confondre avec de vrais écarts
     * noierait le signal.
     *
     * @param  int  $limite  Nombre maximal de lignes relues (garde-fou mémoire)
     * @return array{verifiees: int, coherentes: int, ecarts: int, estimees: int, taux: float, exemples: array<int, array<string, mixed>>}
     */
    public function recalculabilite(int $limite = 5000): array
    {
        $calculateur = app(UsageCostCalculator::class);

        $verifiees = 0;
        $coherentes = 0;
        $ecarts = 0;
        $estimees = 0;
        $exemples = [];

        AiUsageLedger::query()
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->each(function (AiUsageLedger $ligne) use ($calculateur, &$verifiees, &$coherentes, &$ecarts, &$estimees, &$exemples): void {
                if ($ligne->estimated) {
                    $estimees++;

                    return;
                }

                $verifiees++;

                $attendu = $calculateur->estimateCredits(
                    (string) $ligne->model,
                    (int) $ligne->input_tokens,
                    (int) $ligne->output_tokens,
                );

                if ($attendu['credits'] === (int) $ligne->cost_credits) {
                    $coherentes++;

                    return;
                }

                $ecarts++;

                if (count($exemples) < 5) {
                    $exemples[] = [
                        'id' => $ligne->id,
                        'model' => $ligne->model,
                        'credits_stockes' => (int) $ligne->cost_credits,
                        'credits_recalcules' => $attendu['credits'],
                    ];
                }
            });

        return [
            'verifiees' => $verifiees,
            'coherentes' => $coherentes,
            'ecarts' => $ecarts,
            'estimees' => $estimees,
            'taux' => $verifiees > 0 ? round($coherentes / $verifiees, 4) : 1.0,
            'exemples' => $exemples,
        ];
    }
}
