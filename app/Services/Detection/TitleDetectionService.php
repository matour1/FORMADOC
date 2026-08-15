<?php

namespace App\Services\Detection;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Détection de la hiérarchie des titres via l'API DeepSeek (LLM).
 *
 * Seul point du pipeline autorisé à utiliser un LLM : la variabilité réelle
 * des styles de numérotation des rapports étudiants justifie un modèle,
 * contrairement aux légendes (regex, voir LegendDetectionService).
 *
 * Prompt validé : 19/19 titres correctement identifiés sur texte synthétique
 * avec pièges volontaires (voir PROMPTS_ET_TESTS.md).
 */
class TitleDetectionService
{
    /**
     * Analyse un texte et retourne l'arborescence des titres.
     *
     * @param string $text Texte intégral du rapport
     * @return array{markdown: string, raw: string} Arborescence Markdown + réponse brute LLM
     * @throws Exception Si l'API échoue après les retries
     */
    public function execute(string $text): array
    {
        try {
            Log::info('TitleDetectionService started', ['text_length' => strlen($text)]);

            if (mb_strlen(trim($text)) < 20) {
                throw new Exception('TitleDetectionService : texte trop court pour une analyse fiable');
            }

            $result = $this->analyzeWithLlm($text);

            Log::info('TitleDetectionService completed successfully', [
                'markdown_length' => strlen($result['markdown']),
            ]);

            return $result;
        } catch (Exception $e) {
            Log::error('TitleDetectionService failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * Appelle l'API DeepSeek avec le prompt validé et parse la réponse.
     *
     * @param string $text
     * @return array{markdown: string, raw: string}
     * @throws Exception
     */
    private function analyzeWithLlm(string $text): array
    {
        $apiUrl = rtrim(config('deepseek.api_url'), '/') . '/chat/completions';

        // Nombre total de tentatives (1 + retries)
        $maxAttempts = 1 + (int) config('deepseek.max_retries', 2);
        $delays = (array) config('deepseek.retry_delays_ms', [2000, 4000, 8000, 15000]);
        $growth = (float) config('deepseek.timeout_growth', 1.5);

        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // Timeout dynamique selon la taille du texte, croissant à chaque tentative
            $timeout = $this->dynamicTimeout($text, $attempt, $growth);

            Log::info('TitleDetectionService tentative', [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'timeout' => $timeout,
                'text_length' => mb_strlen($text),
            ]);

            try {
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . config('deepseek.api_key'),
                        'Content-Type' => 'application/json',
                    ])
                    ->post($apiUrl, [
                        'model' => config('deepseek.model', 'deepseek-v4-flash'),
                        'messages' => [
                            ['role' => 'system', 'content' => $this->systemPrompt()],
                            ['role' => 'user', 'content' => $text],
                        ],
                    ]);

                if ($response->failed()) {
                    throw new Exception('DeepSeek API error: ' . $response->body());
                }

                $json = $response->json();

                if (empty($json['choices'][0]['message']['content'])) {
                    throw new Exception('DeepSeek API : réponse sans contenu exploitable');
                }

                $markdown = $json['choices'][0]['message']['content'];

                return [
                    'markdown' => $markdown,
                    'raw' => json_encode($json, JSON_UNESCAPED_UNICODE),
                ];
            } catch (Exception $e) {
                $lastError = $e;

                // Dernière tentative : on remonte l'erreur
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                // Seul un timeout ou une erreur réseau justifie une nouvelle
                // tentative (le serveur n'a peut-être pas reçu/terminé la requête).
                // Une erreur HTTP applicative (429/500/…) ne sera pas résolue
                // en relançant : on remonte immédiatement.
                if (!$this->isRetryable($e)) {
                    throw $e;
                }

                // Attendre l'intervalle de vérification avant la prochaine tentative
                $delayMs = $delays[$attempt - 1] ?? (int) config('deepseek.retry_delay', 2000);
                Log::warning('TitleDetectionService retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delayMs,
                    'error' => $e->getMessage(),
                ]);
                usleep($delayMs * 1000);
            }
        }

        // Ne devrait jamais être atteint, mais satisfait le type de retour
        throw $lastError ?? new Exception('DeepSeek API : échec inconnu');
    }

    /**
     * Détermine si une erreur mérite une nouvelle tentative.
     *
     * Seuls les timeouts (cURL error 28) et les erreurs de connexion sont
     * retentables — une erreur HTTP applicative (429, 5xx) ne sera pas
     * résolue en relançant la même requête.
     */
    private function isRetryable(Exception $e): bool
    {
        return $e instanceof \Illuminate\Http\Client\ConnectionException
            || str_contains($e->getMessage(), 'cURL error');
    }

    /**
     * Calcule un timeout proportionnel à la taille du texte.
     *
     * Le modèle DeepSeek "raisonne" : la latence croît avec la longueur du
     * document. On part d'un temps de base incompressible et on ajoute un
     * coût par caractère, borné par [min, max]. Chaque tentative successive
     * dispose d'un timeout plus large (× growth) pour laisser le modèle
     * terminer son raisonnement.
     *
     * @param string $text
     * @param int $attempt Numéro de tentative (1 = première)
     * @param float $growth Multiplicateur par tentative
     * @return float Nombre de secondes (arrondi à l'entier supérieur)
     */
    private function dynamicTimeout(string $text, int $attempt = 1, float $growth = 1.5): float
    {
        $chars = mb_strlen($text);

        $timeout = (float) config('deepseek.timeout.base', 180)
            + ($chars * (float) config('deepseek.timeout.per_char', 0.008));

        $min = (float) config('deepseek.timeout.min', 120);
        $max = (float) config('deepseek.timeout.max', 600);

        // Croissance du timeout par tentative (plafonnée à max)
        $timeout = $timeout * ($growth ** ($attempt - 1));

        return (float) max($min, min($max, $timeout));
    }

    /**
     * Prompt système validé (voir PROMPTS_ET_TESTS.md, testé 19/19).
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un outil d'analyse de structure documentaire.
Ton rôle est d'extraire de manière exhaustive tous les titres, sous-titres et sous-sous-titres
d'un rapport, quel que soit le type de numérotation ou de mise en forme utilisée.
À partir du texte intégral du rapport fourni, suis ces étapes :

1. Détecter tous les blocs qui fonctionnent comme un titre
   Sont considérés comme des titres :
   - Les lignes courtes commençant par un numéro ou un symbole : 1., 1.1, 1.1.1, I., II., A., a), etc.
   - Les expressions explicites comme « Partie », « Chapitre », « Section », « Annexe », « Introduction », « Conclusion ».
   - Les phrases isolées, souvent en gras, en majuscules ou en retrait, qui annoncent un nouveau sujet.
   - Les intitulés de scénarios, cas d'utilisation, modules, etc., même sans numéro.

2. Déterminer la hiérarchie
   - Attribue à chaque titre un niveau de 1 à 6 (comme les niveaux de titre Markdown) en respectant la logique du document.
   - Utilise la numérotation pour déduire le niveau (1. → ##, 1.1 → ###, I. → ##, A. → ###, etc.)
   - Si aucune numérotation n'est présente, déduis le niveau à partir de l'emplacement dans le texte et des titres précédents.

3. Ne rien fusionner, ne rien omettre
   - Chaque bloc titre doit apparaître dans l'arborescence.
   - Les listes qui sont en réalité des sous-sections doivent être transformées en titres avec leur niveau.
   - Vérifie tout le document après extraction pour t'assurer qu'aucun titre n'a été oublié.

4. Produire une sortie structurée
   - Affiche l'arborescence complète en Markdown, avec des niveaux de titre (#, ##, ###, etc.).
   - Ajoute un tableau récapitulatif indiquant le nombre total de titres détectés et le nombre par niveau.
   - Si un titre est ambigu (tu hésites entre deux niveaux), signale-le brièvement.

Pour les titres isolés non balisés, utilise le sous-titre précédent et le texte qui suit pour déduire le niveau.
PROMPT;
    }
}
