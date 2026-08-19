<?php

namespace App\Services\OpenRouter;

use InvalidArgumentException;

/**
 * Routeur centralisé des modèles OpenRouter.
 *
 * Sélectionne le modèle approprié selon :
 *   - le type de tâche (chat_text, document_analysis, document_full_format,
 *     image_generation, image_analysis, web_search, function_calling,
 *     powerpoint_generation)
 *   - le plan de l'utilisateur (default, standard, premium, pro, enterprise)
 *
 * Le premier modèle de la liste configurée est le préféré ; les suivants sont
 * les fallbacks. La sélection retourne TOUJOURS une liste complète de
 * candidats, ce qui permet au service d'appel de basculer automatiquement
 * en cas d'échec (timeout, rate limit, modèle indisponible).
 */
class ModelRouter
{
    /**
     * Choisit le modèle à utiliser pour une tâche et un plan donnés.
     *
     * @return array{task_type: string, plan: string, model: string, fallbacks: array<int, string>, all_candidates: array<int, string>}
     */
    public function select(string $taskType, string $plan = 'default'): array
    {
        $tasks = config('openrouter.tasks', []);
        $plan = strtolower($plan);

        if (! isset($tasks[$taskType])) {
            throw new InvalidArgumentException("Type de tâche OpenRouter inconnu : {$taskType}");
        }

        $modelsByPlan = $tasks[$taskType];

        // Plans non configurés → repli sur le plan gratuit 'default'
        $candidates = $modelsByPlan[$plan]
            ?? $modelsByPlan['default']
            ?? null;

        if (! is_array($candidates) || count($candidates) === 0) {
            throw new InvalidArgumentException(
                "Aucun modèle configuré pour la tâche '{$taskType}' (plan '{$plan}')."
            );
        }

        $model = $candidates[0];
        $fallbacks = array_values(array_slice($candidates, 1));

        return [
            'task_type' => $taskType,
            'plan' => $plan,
            'model' => $model,
            'fallbacks' => $fallbacks,
            'all_candidates' => $candidates,
        ];
    }

    /**
     * Retourne tous les candidats pour une tâche (tous plans confondus, sans doublon).
     *
     * @return array<int, string>
     */
    public function candidatesFor(string $taskType): array
    {
        $tasks = config('openrouter.tasks', []);
        if (! isset($tasks[$taskType])) {
            return [];
        }

        $models = [];
        foreach ($tasks[$taskType] as $list) {
            foreach ($list as $model) {
                $models[$model] = true;
            }
        }

        return array_keys($models);
    }
}
