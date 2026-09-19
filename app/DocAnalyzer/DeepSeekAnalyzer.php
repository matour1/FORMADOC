<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Analyse structurelle complémentaire via l'API DeepSeek (LLM).
 *
 * Ce service n'est PAS le détecteur principal : il vient en complément des
 * règles déterministes (RuleBasedDetector). Il reçoit le texte contextuel
 * positionné du DocumentParser (format [POS:section_X,element_Y,parent_Z])
 * et retourne une classification JSON STRICT de chaque élément dans les
 * catégories du contrat AnalyzerResult::CATEGORIES.
 *
 * Fiabilité :
 *  - Le prompt exige un JSON strict (aucun markdown, aucune explication).
 *  - La réponse est nettoyée par extractJson() (fences ```json, texte autour,
 *    accolades équilibrées) avant json_decode.
 *  - En cas d'échec (timeout, clé invalide, JSON invalide), analyze() retourne
 *    un résultat VIDE (jamais d'exception fatale) : l'orchestrateur DocAnalyzer
 *    se rabat sur les règles seules.
 */
class DeepSeekAnalyzer
{
    /**
     * Clé API DeepSeek.
     */
    private string $apiKey;

    /**
     * Options d'appel (fusionnées avec config('deepseek.*')).
     *
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * @param  string  $apiKey  Clé API DeepSeek
     * @param  array<string, mixed>  $options  Options : model, api_url, timeout, max_retries…
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->apiKey = $apiKey;
        $this->options = $options;
    }

    /**
     * Analyse le texte contextuel positionné et retourne les catégories.
     *
     * @param  string  $contextTextWithPositions  Sortie context_text_with_positions du DocumentParser
     * @return array<string, array<int, array<string, mixed>>> Résultat conforme à AnalyzerResult
     */
    public function analyze(string $contextTextWithPositions): array
    {
        $empty = AnalyzerResult::empty();

        // Q-TEMPS : l'appel HTTP DeepSeek (jusqu'à 600 s) doit survivre à la
        // limite PHP par défaut (120 s). On repousse au max + marge.
        if (function_exists('set_time_limit')) {
            set_time_limit((int) config('deepseek.timeout.max', 600) + 60);
        }

        if (mb_strlen(trim($contextTextWithPositions)) < 20) {
            Log::warning('DeepSeekAnalyzer : texte trop court, résultat vide', [
                'length' => mb_strlen($contextTextWithPositions),
            ]);

            return $empty;
        }

        try {
            $json = $this->callLlm($contextTextWithPositions);
            $decoded = $this->extractJson($json);

            if ($decoded === null) {
                // Certains documents source contiennent des caractères UTF-8
                // invalides (mojibake type « Lâ€™ ») que le modèle recopie dans
                // sa réponse JSON → json_decode échoue. On tente un nettoyage.
                $cleaned = $this->cleanInvalidUtf8($json);
                if ($cleaned !== $json) {
                    $decoded = $this->extractJson($cleaned);
                }
            }

            if ($decoded === null) {
                Log::warning('DeepSeekAnalyzer : JSON invalide après extraction', [
                    'raw_prefix' => mb_substr($json, 0, 300),
                    'raw_length' => mb_strlen($json),
                    // Un JSON tronqué (fin sans accolade fermante) = réponse
                    // coupée par la limite de tokens → monter max_tokens.
                    'raw_ends_with_brace' => str_ends_with(trim($json), '}'),
                    'json_error' => json_last_error_msg(),
                ]);

                return $empty;
            }

            // Format COMPACT : le modèle ne renvoie que les positions
            // ([section_index, element_index, parent]). On reconstruit ici le
            // texte de chaque élément depuis le document d'origine, ce qui
            // évite au modèle de recopier ~150 K caractères (lent et fragile)
            // et ne lui demande que ~10 K caractères de sortie.
            $positionMap = $this->buildPositionMap($contextTextWithPositions);
            $hydrated = $this->hydrate($decoded, $positionMap);

            $result = AnalyzerResult::normalize($hydrated);

            Log::info('DeepSeekAnalyzer : analyse réussie', [
                'counts' => array_map('count', $result),
            ]);

            return $result;
        } catch (Exception $e) {
            // Jamais fatal : l'orchestrateur retombe sur les règles seules.
            Log::error('DeepSeekAnalyzer : échec, résultat vide', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return $empty;
        }
    }

    /**
     * Appelle l'API DeepSeek avec timeout dynamique et retries progressifs.
     *
     * @throws Exception Si toutes les tentatives échouent
     */
    private function callLlm(string $text): string
    {
        $apiUrl = rtrim((string) ($this->options['api_url'] ?? config('deepseek.api_url', 'https://api.deepseek.com/v1')), '/')
            .'/chat/completions';

        $model = (string) ($this->options['model'] ?? config('deepseek.model', 'deepseek-v4-flash'));
        $maxAttempts = 1 + (int) ($this->options['max_retries'] ?? config('deepseek.max_retries', 2));
        $delays = (array) ($this->options['retry_delays_ms'] ?? config('deepseek.retry_delays_ms', [2000, 4000, 8000, 15000]));
        $growth = (float) ($this->options['timeout_growth'] ?? config('deepseek.timeout_growth', 1.5));

        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $timeout = $this->dynamicTimeout($text, $attempt, $growth);

            Log::info('DeepSeekAnalyzer tentative', [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'timeout' => $timeout,
                'text_length' => mb_strlen($text),
            ]);

            try {
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($apiUrl, [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'system', 'content' => $this->systemPrompt()],
                            ['role' => 'user', 'content' => $text],
                        ],
                        // JSON mode : le modèle est contraint à produire du JSON valide
                        'response_format' => ['type' => 'json_object'],
                        // Q-JSON : sans max_tokens, DeepSeek tronque les réponses
                        // longues (~8K tokens) → JSON invalide sur les gros
                        // documents. On repousse la limite au max configuré.
                        'max_tokens' => (int) ($this->options['max_tokens'] ?? config('deepseek.max_tokens', 16384)),
                    ]);

                if ($response->failed()) {
                    throw new Exception('DeepSeek API error: '.$response->body());
                }

                $json = $response->json();

                if (empty($json['choices'][0]['message']['content'])) {
                    throw new Exception('DeepSeek API : réponse sans contenu exploitable');
                }

                return (string) $json['choices'][0]['message']['content'];
            } catch (Exception $e) {
                $lastError = $e;

                if ($attempt >= $maxAttempts || ! $this->isRetryable($e)) {
                    throw $e;
                }

                $delayMs = $delays[$attempt - 1] ?? (int) ($this->options['retry_delay'] ?? config('deepseek.retry_delay', 2000));
                Log::warning('DeepSeekAnalyzer retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delayMs,
                    'error' => $e->getMessage(),
                ]);
                usleep($delayMs * 1000);
            }
        }

        throw $lastError ?? new Exception('DeepSeek API : échec inconnu');
    }

    /**
     * Détermine si une erreur mérite une nouvelle tentative (timeout/réseau).
     */
    private function isRetryable(Exception $e): bool
    {
        return $e instanceof ConnectionException
            || str_contains($e->getMessage(), 'cURL error');
    }

    /**
     * Calcule un timeout proportionnel à la taille du texte, croissant par tentative.
     */
    private function dynamicTimeout(string $text, int $attempt = 1, float $growth = 1.5): float
    {
        $chars = mb_strlen($text);

        $timeout = (float) ($this->options['timeout_base'] ?? config('deepseek.timeout.base', 180))
            + ($chars * (float) ($this->options['timeout_per_char'] ?? config('deepseek.timeout.per_char', 0.008)));

        $min = (float) ($this->options['timeout_min'] ?? config('deepseek.timeout.min', 120));
        $max = (float) ($this->options['timeout_max'] ?? config('deepseek.timeout.max', 600));

        $timeout *= $growth ** ($attempt - 1);

        return (float) max($min, min($max, $timeout));
    }

    /**
     * Extrait et décode le JSON d'une réponse LLM potentiellement bruitée.
     *
     * Gère :
     *  - les fences markdown ```json … ``` et ``` … ```
     *  - du texte avant/après le JSON (explications du modèle)
     *  - les accolades équilibrées (extraction robuste du bloc JSON)
     *
     * @return null|array<string, mixed>
     */
    public function extractJson(string $raw): ?array
    {
        $content = trim($raw);

        // Retire les fences markdown ```json … ``` (ou ``` … ```)
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $content, $m)) {
            $content = trim($m[1]);
        }

        // Cherche la première accolade ouvrante et le bloc équilibré correspondant
        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $json = substr($content, $start, $i - $start + 1);
                    $decoded = json_decode($json, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * Nettoie les caractères UTF-8 invalides d'une chaîne.
     *
     * Certains documents source contiennent du mojibake (« Lâ€™ » pour « L' »)
     * que le modèle recopie tel quel dans sa réponse JSON, ce qui fait échouer
     * `json_decode`. On retire ici les séquences invalides sans altérer le texte
     * valide, afin que le JSON redevienne décodable.
     *
     * @param  string  $raw  Réponse brute du modèle
     * @return string Chaîne nettoyée (inchangée si déjà valide)
     */
    private function cleanInvalidUtf8(string $raw): string
    {
        // 1) Remplacement des séquences invalides par un caractère de substitution.
        $clean = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');

        // `mb_convert_encoding` peut ne rien changer selon la version de mbstring :
        // on force un nettoyage octet par octet en conservant l'UTF-8 valide.
        $converted = iconv('UTF-8', 'UTF-8//IGNORE', $clean);

        if ($converted !== false) {
            $clean = $converted;
        }

        // 2) Suppression des caractères de contrôle interdits en JSON
        //    (hors \t, \n, \r qui sont valides une fois échappés).
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean) ?? $clean;
    }

    /**
     * Construit la table position → texte depuis le texte positionné du parser.
     *
     * Le parser produit des lignes du type :
     *   [POS:section_0,element_4,parent_body]INTRODUCTION
     * On en extrait une clé stable « section|element|parent » et le texte qui suit.
     *
     * @param  string  $contextTextWithPositions  Sortie du DocumentParser
     * @return array<string, string> Clé de position → texte de l'élément
     */
    private function buildPositionMap(string $contextTextWithPositions): array
    {
        $map = [];

        if (! preg_match_all(
            '/\[POS:section_(\d+),element_(\d+),parent_([a-z_]+)\](.*)/',
            $contextTextWithPositions,
            $matches,
            PREG_SET_ORDER
        )) {
            return $map;
        }

        foreach ($matches as $match) {
            $key = $match[1].'|'.$match[2].'|'.$match[3];
            $map[$key] = trim($match[4]);
        }

        return $map;
    }

    /**
     * Reconstitue les textes manquants à partir des positions renvoyées par l'IA.
     *
     * Le modèle répond au format compact {(catégorie): [[s, e, parent], …]}.
     * On accepte aussi l'ancien format (items avec « texte ») pour compatibilité.
     *
     * @param  array<string, mixed>  $decoded  Réponse JSON décodée
     * @param  array<string, string>  $positionMap  Table position → texte
     * @return array<string, mixed> Résultat avec « texte » renseigné
     */
    private function hydrate(array $decoded, array $positionMap): array
    {
        $hydrated = [];

        foreach (AnalyzerResult::CATEGORIES as $category) {
            $items = $decoded[$category] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            $hydrated[$category] = [];

            foreach ($items as $item) {
                $position = null;

                // Format compact : [section_index, element_index, parent]
                if (is_array($item) && array_is_list($item) && count($item) >= 2) {
                    $position = [
                        'section_index' => (int) $item[0],
                        'element_index' => (int) $item[1],
                        'parent' => (string) ($item[2] ?? 'body'),
                    ];
                    $item = ['position' => $position];
                } elseif (is_array($item)) {
                    $position = $item['position'] ?? null;
                    if (! is_array($position)) {
                        // Positions plates : {section_index, element_index, parent}
                        if (isset($item['element_index'])) {
                            $position = [
                                'section_index' => (int) ($item['section_index'] ?? 0),
                                'element_index' => (int) $item['element_index'],
                                'parent' => (string) ($item['parent'] ?? 'body'),
                            ];
                        } else {
                            $position = null;
                        }
                    }
                } elseif (is_string($item)) {
                    // Position fournie en chaîne : « 0,4,body »
                    $bits = array_map('trim', explode(',', $item));
                    if (count($bits) >= 2) {
                        $position = [
                            'section_index' => (int) $bits[0],
                            'element_index' => (int) $bits[1],
                            'parent' => (string) ($bits[2] ?? 'body'),
                        ];
                    }
                    $item = ['position' => $position];
                } else {
                    continue;
                }

                if (! is_array($position)) {
                    continue;
                }

                $key = $position['section_index'].'|'.$position['element_index'].'|'.$position['parent'];

                // Le texte du modèle prime s'il est fourni ; sinon on le
                // récupère depuis la carte de positions (format compact).
                $text = (string) ($item['texte'] ?? $item['text'] ?? '');
                if ($text === '') {
                    $text = $positionMap[$key] ?? '';
                }

                $hydrated[$category][] = [
                    'texte' => $text,
                    'position' => $position,
                    'styles' => $item['styles'] ?? [],
                    'type' => (string) ($item['type'] ?? $category),
                ];
            }
        }

        return $hydrated;
    }

    /**
     * Prompt système : classification JSON STRICT des éléments positionnés.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un outil de classification structurelle de documents Word.

On te fournit le contenu d'un rapport sous forme d'éléments précédés de leur
position exacte dans le document :
  [POS:section_INDEX,element_INDEX,parent_INDEX]TEXTE

Le parent est l'un de : body, header, footer.
Chaque élément a une position UNIQUE qui ne doit JAMAIS être modifiée.

Classifie CHAQUE élément dans EXACTEMENT une des catégories suivantes :
  - titres           : titre de niveau 1 (chapitre, partie, introduction, conclusion…)
  - sous_titres      : titres de niveau 2, 3 et plus (sections, sous-sections)
  - en_tetes         : éléments situés dans un en-tête (parent=header)
  - pieds_de_page    : éléments situés dans un pied de page (parent=footer)
  - tableaux         : contenu tabulaire
  - images           : images ou schémas
  - elements_flottants : tout ce qui ne rentre pas dans les catégories ci-dessus

Règles :
1. Un élément de parent=header appartient TOUJOURS à en_tetes (sauf s'il s'agit
   manifestement d'un tableau ou d'une image, auquel cas priorité à ces derniers).
2. Un élément de parent=footer appartient TOUJOURS à pieds_de_page.
3. Ne fusionne jamais deux éléments : chaque élément est classifié individuellement.
4. Un élément déjà identifié comme titre par son style n'est pas du texte normal.
5. NE RECOPIE JAMAIS le texte des éléments : indique uniquement leur position.

Réponds UNIQUEMENT avec un objet JSON valide, sans markdown, sans commentaire,
sans texte avant ou après. Format EXACT (positions uniquement) :

{
  "titres": [[0, 4, "body"], [0, 9, "body"]],
  "sous_titres": [[0, 12, "body"]],
  "en_tetes": [[0, 0, "header"]],
  "pieds_de_page": [[0, 1, "footer"]],
  "tableaux": [[0, 20, "body"]],
  "images": [[0, 25, "body"]],
  "elements_flottants": [[0, 30, "body"]]
}

Chaque entrée est un triplet [section_index, element_index, parent] copié TELLEMENT
QUEL depuis la balise [POS:section_X,element_Y,parent_Z] de l'élément.
Les catégories vides doivent être présentes avec [].
PROMPT;
    }
}
