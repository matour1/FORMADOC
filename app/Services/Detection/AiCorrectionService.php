<?php

declare(strict_types=1);

namespace App\Services\Detection;

use App\DocAnalyzer\AnalyzerResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Assistance IA optionnelle — post-processeur correctif.
 *
 * RÔLE (exigence Phase 4 — IA non intrusive) :
 *  - N'EST JAMAIS appelé si l'utilisateur n'a pas coché « Utiliser l'assistance IA ».
 *  - Intervient APRÈS la détection déterministe (règles + regex + ambiguïtés).
 *  - Reçoit UNIQUEMENT les éléments ambigus/incertains (pas tout le document)
 *    pour limiter les tokens et les coûts.
 *  - Retourne des CORRECTIONS CIBLÉES (kind, level, is_list) fusionnées avec
 *    les résultats déterministes par ResultMerger.
 *  - En cas d'échec/timeout → retourne un tableau de corrections VIDE : le
 *    pipeline bascule automatiquement en mode déterministe, sans erreur.
 *
 * Format d'entrée (JSON canonique) — uniquement les blocs ambigus :
 *   [
 *     {
 *       "element_index": 12, "section_index": 0, "parent": "body",
 *       "texte": "1. Contexte", "type": "texte", "style": "Heading2",
 *       "ambiguite": "niveau_detecte:2|niveau_suggere:1",
 *       "contexte": "… paragraphe précédent …"
 *     },
 *     …
 *   ]
 *
 * Format de sortie (JSON STRICT) — corrections ciblées :
 *   {
 *     "corrections": [
 *       {"element_index": 12, "kind": "sous_titre", "level": 1},
 *       {"element_index": 45, "kind": "liste", "level": 0}
 *     ]
 *   }
 *
 * Chaque correction est fusionnée avec le résultat déterministe : l'IA ne
 * régénère jamais le document, elle ajuste des champs précis.
 */
class AiCorrectionService
{
    /**
     * Nombre maximal d'éléments ambigus envoyés à l'IA par appel.
     * Limite les tokens et le coût (la demande exige : « pas tout le document »).
     */
    private const MAX_AMBIGUOUS_ITEMS = 60;

    /**
     * Contexte (lignes autour) envoyé avec chaque élément ambigu.
     */
    private const CONTEXT_LINES = 2;

    /**
     * Usage du DERNIER appel réussi (tokens d'entrée et de sortie).
     *
     * **Pourquoi ce service doit remonter son usage.** Il appelle l'API
     * DeepSeek EN DIRECT, sans passer par `OpenRouterService` — donc sans
     * passer par le registre d'usage (`AiUsageLedger`) ni par le calcul de
     * coût. L'assistance IA envoyait donc des éléments au modèle sans qu'aucune
     * ligne de comptabilité ne soit écrite : la dépense était invisible.
     *
     * L'appelant peut désormais lire cet usage et enregistrer la dépense.
     *
     * @var array{input_tokens: int, output_tokens: int}
     */
    private array $usage = ['input_tokens' => 0, 'output_tokens' => 0];

    /**
     * Usage du dernier appel réussi.
     *
     * @return array{input_tokens: int, output_tokens: int}
     */
    public function lastUsage(): array
    {
        return $this->usage;
    }

    /**
     * Corrige une structure détectée de façon déterministe en s'appuyant sur
     * l'IA, UNIQUEMENT sur les parties ambiguës ou incertaines.
     *
     * @param  array<string, mixed>  $structure  Structure canonique (sortie DocAnalyzer + body_complet)
     * @param  array<int, mixed>  $ambiguities  Sortie AmbiguityDetectionService
     * @return array<string, mixed> Structure éventuellement corrigée (sinon inchangée)
     */
    public function correct(array $structure, array $ambiguities = []): array
    {
        $apiKey = (string) config('deepseek.api_key', '');

        // Sans clé API, sans option → aucun appel, on retourne la structure telle quelle.
        if ($apiKey === '') {
            Log::info('AiCorrectionService : clé API absente, mode déterministe conservé');

            return $structure;
        }

        try {
            // 1. Extraire UNIQUEMENT les éléments ambigus/incertains
            $ambiguous = $this->extractAmbiguousItems($structure, $ambiguities);

            if ($ambiguous === []) {
                Log::info('AiCorrectionService : aucun élément ambigu, pas d\'appel IA');

                return $structure;
            }

            // 2. Appel LLM (jamais fatal)
            $corrections = $this->callLlm($ambiguous);

            if ($corrections === []) {
                return $structure;
            }

            // 3. Fusion des corrections avec la structure déterministe
            return $this->applyCorrections($structure, $corrections);
        } catch (\Throwable $e) {
            // Jamais bloquant : on retombe sur la structure déterministe.
            Log::warning('AiCorrectionService : échec, mode déterministe conservé', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return $structure;
        }
    }

    /**
     * Extrait l'element_index d'une ambiguïté.
     *
     * L'ambiguïté d'AmbiguityDetectionService contient un 'id' au format
     * "s{section}e{element}p{parent}" ET un 'texte'. On extrait l'élément
     * depuis le pattern, ou depuis 'element_index' s'il est présent.
     *
     * @param  array<string, mixed>  $ambiguity
     */
    private function ambiguityElementIndex(array $ambiguity): ?int
    {
        if (isset($ambiguity['element_index']) && is_numeric($ambiguity['element_index'])) {
            return (int) $ambiguity['element_index'];
        }

        $id = (string) ($ambiguity['id'] ?? '');
        if (preg_match('/e(\d+)/', $id, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Construit le sous-ensemble d'éléments ambigus à soumettre à l'IA.
     *
     * Priorité : les ambiguïtés signalées par AmbiguityDetectionService, puis
     * les éléments non-titres (texte) proches d'un titre (potentiels listes).
     *
     * @param  array<string, mixed>  $structure
     * @param  array<int, mixed>  $ambiguities
     * @return array<int, array<string, mixed>>
     */
    private function extractAmbiguousItems(array $structure, array $ambiguities): array
    {
        $items = [];
        $seen = [];

        // 1. Ambiguïtés explicites (numérotation vs niveau)
        foreach ($ambiguities as $ambiguity) {
            $key = $this->ambiguityElementIndex($ambiguity);
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $item = $this->findElementByKey($structure, $key);
            if ($item === null) {
                continue;
            }

            $item['ambiguite'] = (string) ($ambiguity['raison'] ?? 'ambigu');
            $item['contexte'] = $this->extractContext($structure, $item);
            $items[] = $item;

            if (count($items) >= self::MAX_AMBIGUOUS_ITEMS) {
                return $items;
            }
        }

        // 2. Éléments de type 'texte' proches d'un titre — candidats LISTES.
        //    Le point critique : les listes sans numPr/puce (tirets, numérotation
        //    manuelle, alignement) sont mal détectées par le déterministe.
        $bodyComplet = $structure['body_complet'] ?? [];
        $lastTitreKey = null;

        foreach ($bodyComplet as $element) {
            $type = (string) ($element['type'] ?? 'autre');
            $texte = trim((string) ($element['text'] ?? ''));

            if ($texte === '' && $type !== 'image') {
                continue;
            }

            $key = $this->elementKey($element);

            if (in_array($type, ['titre', 'liste', 'tableau', 'image'], true)) {
                if ($type === 'titre') {
                    $lastTitreKey = $key;
                }

                continue;
            }

            // Candidat liste : commence par un tiret, une puce, un chiffre suivi
            // d'un point, ou une lettre suivie d'un point (numérotation manuelle).
            $candidatListe = (bool) preg_match('/^\s*(?:[-–—•*·◦▪‣]|\d{1,2}[.)]|[a-zA-Z][.)])\s+/u', $texte);

            // Élément proche d'un titre (≤ 5 éléments après) : contexte liste probable.
            $procheTitre = $lastTitreKey !== null
                && $this->distanceFrom($structure, $key, $lastTitreKey) <= 5;

            if (($candidatListe || $procheTitre) && ! isset($seen[$key])) {
                $seen[$key] = true;
                $item = $this->findElementByKey($structure, $this->indexFromKey($key));
                if ($item === null) {
                    continue;
                }
                $item['ambiguite'] = $candidatListe
                    ? 'candidat_liste: commence par un marqueur de liste'
                    : 'proche_titre: pourrait être une liste';
                $item['contexte'] = $this->extractContext($structure, $item);
                $items[] = $item;

                if (count($items) >= self::MAX_AMBIGUOUS_ITEMS) {
                    return $items;
                }
            }
        }

        return $items;
    }

    /**
     * Appelle l'API DeepSeek avec timeout dynamique, retries progressifs.
     * Ne lance JAMAIS d'exception fatale : retourne [] en cas d'échec.
     *
     * @param  array<int, array<string, mixed>>  $items  Éléments ambigus
     * @return array<int, array<string, mixed>> Corrections ciblées
     */
    private function callLlm(array $items): array
    {
        $apiUrl = rtrim((string) config('deepseek.api_url', 'https://api.deepseek.com/v1'), '/')
            .'/chat/completions';
        $model = (string) config('deepseek.model', 'deepseek-v4-flash');
        $apiKey = (string) config('deepseek.api_key', '');

        // Payload réduit : uniquement les éléments ambigus.
        // NB : les éléments body_complet portent leur index dans
        // `position.element_index` (pas à la racine) — on lit les deux.
        $payload = array_map(static fn (array $item) => [
            'element_index' => (int) ($item['element_index'] ?? $item['position']['element_index'] ?? 0),
            'section_index' => (int) ($item['section_index'] ?? $item['position']['section_index'] ?? 0),
            'parent' => (string) ($item['parent'] ?? 'body'),
            'texte' => mb_substr((string) ($item['text'] ?? $item['texte'] ?? ''), 0, 200),
            'type' => (string) ($item['type'] ?? 'texte'),
            'style' => (string) ($item['style'] ?? ''),
            'ambiguite' => (string) ($item['ambiguite'] ?? ''),
            'contexte' => mb_substr((string) ($item['contexte'] ?? ''), 0, 400),
        ], $items);

        $maxAttempts = 1 + (int) config('deepseek.max_retries', 2);
        $delays = (array) config('deepseek.retry_delays_ms', [2000, 4000, 8000, 15000]);
        $growth = (float) config('deepseek.timeout_growth', 1.5);

        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $timeout = $this->dynamicTimeout(count($payload), $attempt, $growth);

            Log::info('AiCorrectionService tentative', [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'timeout' => $timeout,
                'items' => count($payload),
            ]);

            try {
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($apiUrl, [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'system', 'content' => $this->systemPrompt()],
                            ['role' => 'user', 'content' => json_encode(['elements' => $payload], JSON_UNESCAPED_UNICODE)],
                        ],
                        'response_format' => ['type' => 'json_object'],
                    ]);

                if ($response->failed()) {
                    throw new \Exception('DeepSeek API error: '.$response->body());
                }

                $json = $response->json();
                $content = (string) ($json['choices'][0]['message']['content'] ?? '');

                if ($content === '') {
                    throw new \Exception('DeepSeek API : réponse sans contenu exploitable');
                }

                // Usage remonté par l'API : seule source du coût de cet appel,
                // qui ne passe pas par le registre d'usage d'OpenRouter.
                $this->usage = [
                    'input_tokens' => (int) ($json['usage']['prompt_tokens'] ?? 0),
                    'output_tokens' => (int) ($json['usage']['completion_tokens'] ?? 0),
                ];

                $decoded = $this->extractJson($content);
                if ($decoded === null) {
                    throw new \Exception('DeepSeek API : JSON invalide');
                }

                $corrections = $this->normalizeCorrections($decoded);

                Log::info('AiCorrectionService : corrections reçues', [
                    'count' => count($corrections),
                ]);

                return $corrections;
            } catch (\Throwable $e) {
                $lastError = $e;

                if ($attempt >= $maxAttempts || ! $this->isRetryable($e)) {
                    break;
                }

                $delayMs = $delays[$attempt - 1] ?? (int) config('deepseek.retry_delay', 2000);
                Log::warning('AiCorrectionService retry', [
                    'attempt' => $attempt,
                    'delay_ms' => $delayMs,
                    'error' => $e->getMessage(),
                ]);
                usleep($delayMs * 1000);
            }
        }

        if ($lastError !== null) {
            Log::warning('AiCorrectionService : échec définitif, mode déterministe', [
                'error' => $lastError->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Applique les corrections IA à la structure déterministe.
     *
     * Stratégie de fusion (ResultMerger étendu) :
     *  - Si la correction change `kind` (ex: texte → liste) : l'élément est
     *    retiré de sa catégorie d'origine et ajouté à la nouvelle.
     *  - Si elle change `level` : le niveau est mis à jour.
     *  - Aucune correction ne crée de doublon (clé element_index+parent).
     *
     * @param  array<string, mixed>  $structure
     * @param  array<int, array<string, mixed>>  $corrections
     * @return array<string, mixed>
     */
    private function applyCorrections(array $structure, array $corrections): array
    {
        $corrections = array_values(array_filter($corrections, static fn ($c) => is_array($c)));

        if ($corrections === []) {
            return $structure;
        }

        // Map des corrections par element_index
        $correctionMap = [];
        foreach ($corrections as $correction) {
            $index = (int) ($correction['element_index'] ?? 0);
            if ($index > 0) {
                $correctionMap[$index] = $correction;
            }
        }

        // 1. Corriger les catégories titres/sous_titres/en_tetes/... (AnalyzerResult)
        foreach (AnalyzerResult::CATEGORIES as $category) {
            foreach (($structure[$category] ?? []) as $key => $item) {
                $index = (int) ($item['position']['element_index'] ?? 0);
                $correction = $correctionMap[$index] ?? null;
                if ($correction === null) {
                    continue;
                }

                // Changement de kind → retirer de cette catégorie
                $newKind = (string) ($correction['kind'] ?? '');
                if ($newKind !== '' && $newKind !== $category) {
                    unset($structure[$category][$key]);

                    continue;
                }

                // Correction du niveau (level)
                if (isset($correction['level']) && is_numeric($correction['level'])) {
                    $structure[$category][$key]['niveau'] = (int) $correction['level'];
                }
            }
            $structure[$category] = array_values($structure[$category] ?? []);
        }

        // 2. Corriger body_complet (source de vérité de la reconstruction)
        foreach (($structure['body_complet'] ?? []) as $key => $element) {
            $index = (int) ($element['position']['element_index'] ?? $element['element_index'] ?? 0);
            $correction = $correctionMap[$index] ?? null;
            if ($correction === null) {
                continue;
            }

            // Changement de type (texte → liste, etc.)
            $newKind = (string) ($correction['kind'] ?? '');
            if ($newKind !== '' && $newKind !== ($element['type'] ?? '')) {
                $structure['body_complet'][$key]['type'] = $newKind;
            }

            // Profondeur de liste
            if (isset($correction['level']) && is_numeric($correction['level'])) {
                $structure['body_complet'][$key]['depth'] = (int) $correction['level'];
                // La profondeur est aussi reportée dans les catégories
                $indexKey = (int) ($element['position']['element_index'] ?? 0);
                foreach (AnalyzerResult::CATEGORIES as $category) {
                    foreach (($structure[$category] ?? []) as $k2 => $item2) {
                        if ((int) ($item2['position']['element_index'] ?? 0) === $indexKey) {
                            $structure[$category][$k2]['depth'] = (int) $correction['level'];
                        }
                    }
                }
            }
        }

        // 3. Marquer l'usage de l'IA (traçabilité)
        $structure['ai_corrections'] = array_map(
            static fn (array $c) => [
                'element_index' => (int) ($c['element_index'] ?? 0),
                'kind' => (string) ($c['kind'] ?? ''),
                'level' => (int) ($c['level'] ?? 0),
            ],
            $corrections
        );

        return $structure;
    }

    /**
     * Normalise les corrections IA (JSON potentiellement bruité).
     *
     * @param  array<string, mixed>  $decoded
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCorrections(array $decoded): array
    {
        $raw = $decoded['corrections'] ?? $decoded['elements'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $corrections = [];
        $allowedKinds = ['titre', 'sous_titre', 'texte', 'liste', 'tableau', 'image', 'en_tete', 'pied_de_page'];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                continue;
            }

            $index = (int) ($item['element_index'] ?? 0);
            if ($index <= 0) {
                continue;
            }

            $kind = strtolower((string) ($item['kind'] ?? $item['type'] ?? ''));
            // Normalisation : 'sous_titres' → 'sous_titre', 'en_tetes' → 'en_tete'…
            $kind = str_replace(['sous_titres', 'en_tetes', 'pieds_de_page', 'elements_flottants'], ['sous_titre', 'en_tete', 'pied_de_page', 'texte'], $kind);
            if (! in_array($kind, $allowedKinds, true)) {
                $kind = '';
            }

            $corrections[] = [
                'element_index' => $index,
                'kind' => $kind,
                'level' => (int) ($item['level'] ?? $item['depth'] ?? 0),
            ];
        }

        return $corrections;
    }

    /**
     * Timeout proportionnel au nombre d'éléments ambigus.
     * Bien inférieur au timeout de l'analyse complète (payload réduit).
     */
    private function dynamicTimeout(int $itemCount, int $attempt = 1, float $growth = 1.5): float
    {
        $timeout = (float) config('deepseek.timeout.base', 180)
            + ($itemCount * (float) config('deepseek.timeout.per_char', 0.008) * 10);
        $min = (float) config('deepseek.timeout.min', 120);
        $max = (float) config('deepseek.timeout.max', 600);
        $timeout *= $growth ** ($attempt - 1);

        return (float) max($min, min($max, $timeout));
    }

    /**
     * Détermine si une erreur mérite une nouvelle tentative.
     */
    private function isRetryable(\Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || str_contains($e->getMessage(), 'cURL error');
    }

    /**
     * Extrait et décode le JSON d'une réponse LLM (robuste aux fences markdown).
     *
     * @return null|array<string, mixed>
     */
    public function extractJson(string $raw): ?array
    {
        $content = trim($raw);

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $content, $m)) {
            $content = trim($m[1]);
        }

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
     * Prompt système : corrections CIBLÉES, jamais de régénération complète.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de correction structurelle de documents Word. Tu reçois une
liste d'éléments AMBIGUS d'un rapport (ceux que l'analyse déterministe n'a pas
pu classer avec certitude), avec pour chacun :
  - element_index  : identifiant unique (à ne JAMAIS modifier)
  - section_index / parent : position dans le document
  - texte          : contenu de l'élément
  - type           : type actuel détecté (texte, liste, tableau…)
  - style          : style Word éventuel (Heading1, Normal…)
  - ambiguite      : raison de l'ambiguïté (candidat liste, numérotation, etc.)
  - contexte       : lignes autour de l'élément pour comprendre le contexte

Ta mission : proposer des CORRECTIONS CIBLÉES, uniquement quand tu es CERTAIN.
Ne régénère jamais le document, ne renvoie pas les éléments non ambigus.

Règles :
1. kind doit être UN de : titre, sous_titre, texte, liste, tableau, image,
   en_tete, pied_de_page.
2. Une liste peut être implicite : tirets, puces, numérotation manuelle
   ("1.", "a)", "- ", "• ") sans style Word de liste. Si un élément commence
   par un tel marqueur et que le contexte (lignes suivantes similaires) le
   confirme, propose kind="liste".
3. level : niveau de titre (1 = chapitre, 2 = section, 3 = sous-section) ou
   profondeur de liste (0 = premier niveau).
4. Ne propose une correction que si tu es sûr à ≥ 80 %. Sinon, n'inclus pas
   l'élément dans la réponse.
5. Ne modifie JAMAIS element_index.

Réponds UNIQUEMENT avec un objet JSON valide, sans markdown :
{
  "corrections": [
    {"element_index": 12, "kind": "sous_titre", "level": 1},
    {"element_index": 45, "kind": "liste", "level": 0}
  ]
}

Si aucune correction n'est nécessaire, réponds {"corrections": []}.
PROMPT;
    }

    /**
     * Retrouve un élément dans body_complet par sa clé de position.
     *
     * @param  array<string, mixed>  $structure
     * @return null|array<string, mixed>
     */
    private function findElementByKey(array $structure, int $elementIndex): ?array
    {
        foreach (($structure['body_complet'] ?? []) as $element) {
            if ((int) ($element['position']['element_index'] ?? $element['element_index'] ?? -1) === $elementIndex) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Clé de position d'un élément body_complet.
     *
     * @param  array<string, mixed>  $element
     */
    private function elementKey(array $element): string
    {
        $position = $element['position'] ?? [];
        $section = (int) ($position['section_index'] ?? 0);
        $index = (int) ($position['element_index'] ?? $element['element_index'] ?? 0);

        return $section.':'.$index;
    }

    /**
     * Extrait l'element_index depuis une clé "section:index".
     */
    private function indexFromKey(string $key): int
    {
        $parts = explode(':', $key);

        return (int) ($parts[1] ?? 0);
    }

    /**
     * Distance (en nombre d'éléments body_complet) entre deux éléments.
     *
     * @param  array<string, mixed>  $structure
     */
    private function distanceFrom(array $structure, string $key, ?string $fromKey): int
    {
        if ($fromKey === null) {
            return PHP_INT_MAX;
        }

        $position = 0;
        $fromPosition = null;
        foreach (($structure['body_complet'] ?? []) as $element) {
            $elementKey = $this->elementKey($element);
            if ($fromKey !== null && $elementKey === $fromKey) {
                $fromPosition = $position;
            }
            if ($elementKey === $key) {
                return $fromPosition === null ? PHP_INT_MAX : $position - $fromPosition;
            }
            $position++;
        }

        return PHP_INT_MAX;
    }

    /**
     * Extrait le contexte textuel (N lignes avant/après) d'un élément.
     *
     * @param  array<string, mixed>  $structure
     * @param  array<string, mixed>  $item
     */
    private function extractContext(array $structure, array $item): string
    {
        $body = $structure['body_complet'] ?? [];
        $targetKey = $this->elementKey($item);
        $targetIndex = null;

        foreach ($body as $i => $element) {
            if ($this->elementKey($element) === $targetKey) {
                $targetIndex = $i;
                break;
            }
        }

        if ($targetIndex === null) {
            return '';
        }

        $lines = [];
        $start = max(0, $targetIndex - self::CONTEXT_LINES);
        $end = min(count($body) - 1, $targetIndex + self::CONTEXT_LINES);

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $targetIndex) {
                $lines[] = '>> '.trim((string) ($body[$i]['text'] ?? ''));
            } else {
                $lines[] = trim((string) ($body[$i]['text'] ?? ''));
            }
        }

        return implode("\n", $lines);
    }
}
