<?php

namespace App\Services\OpenRouter;

/**
 * Normalise un tool_call OpenAI/DeepSeek vers le format aplati attendu
 * par les exécuteurs d'outils (ChatToolsService, ChatController).
 *
 * L'API OpenAI/DeepSeek retourne les tool_calls imbriqués :
 *   {id, type, function: {name, arguments}}
 * alors que les exécuteurs internes lisent :
 *   {id, type, name, arguments}
 *
 * Sans cette normalisation, le nom d'outil arrive vide ("") et chaque
 * appel d'outil échoue en boucle (coût inutile).
 */
trait NormalizesToolCalls
{
    /**
     * @param  array<string, mixed>  $toolCall
     * @return array<string, mixed>
     */
    private function normalizeToolCall(array $toolCall): array
    {
        // Format OpenAI/DeepSeek : function.name / function.arguments imbriqués
        if (isset($toolCall['function']) && is_array($toolCall['function'])) {
            $toolCall['name'] = (string) ($toolCall['function']['name'] ?? $toolCall['name'] ?? '');
            $toolCall['arguments'] = $toolCall['function']['arguments'] ?? $toolCall['arguments'] ?? '{}';
            // La clé imbriquée n'est plus utile une fois aplatie
            unset($toolCall['function']);
        }

        return $toolCall;
    }
}
