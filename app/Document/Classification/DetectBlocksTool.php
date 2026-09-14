<?php

declare(strict_types=1);

namespace App\Document\Classification;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tool `detect_blocks` : classification des blocs AMBIGUS par le modèle.
 *
 * **Appelé en dernier recours uniquement.** Le `SignalAggregator` classe 68 %
 * des blocs gratuitement ; ce tool ne reçoit que le tiers restant, pour lequel
 * aucun signal déterministe n'a permis de trancher (REFONTE_ARCHITECTURE.md §7).
 *
 * Contraintes de la spécification, toutes respectées ici :
 *
 * 1. **Ne jamais halluciner de texte** — le modèle ne renvoie qu'un type, une
 *    confiance et le signal utilisé. Le champ `text` n'est jamais généré.
 * 2. **Confiance < 0,7 si les signaux sont contradictoires ou absents** — la
 *    consigne est passée explicitement dans le prompt système.
 * 3. **Priorité au pattern texte** — déjà appliqué en amont, donc les blocs
 *    reçus ici n'ont justement PAS de pattern texte.
 * 4. **Traitement par lots** — les blocs sont envoyés par paquets pour limiter
 *    le nombre d'appels, tout en gardant une taille de contexte raisonnable.
 */
final class DetectBlocksTool
{
    /** Nombre de blocs par appel : compromis coût / taille de contexte. */
    private const BATCH_SIZE = 25;

    /** Types autorisés en sortie, alignés sur `BlockType`. */
    private const ALLOWED_TYPES = [
        'heading_1', 'heading_2', 'heading_3', 'heading_4',
        'paragraph', 'table_header', 'figure', 'caption', 'annexe', 'planche',
    ];

    public function __construct(private readonly OpenRouterService $openRouter) {}

    /**
     * Classifie une liste de blocs ambigus.
     *
     * @param  array<int, Block>  $blocks  Blocs à classer (sous le seuil de confiance)
     * @param  string  $plan  Plan de l'utilisateur (détermine le modèle)
     * @return array{
     *     results: array<string, array{type: BlockType, confidence: float, signal: string, heading_level: null|int, category: null|string}>,
     *     cost_usd: float,
     *     cost_credits: int,
     *     batches: int,
     *     failed: array<int, string>
     * } Indexé par `block_id`
     */
    public function classify(array $blocks, string $plan = 'default'): array
    {
        $results = [];
        $failed = [];
        $costUsd = 0.0;
        $costCredits = 0;
        $batches = 0;

        if ($blocks === []) {
            return [
                'results' => [],
                'cost_usd' => 0.0,
                'cost_credits' => 0,
                'batches' => 0,
                'failed' => [],
            ];
        }

        // Le pré-filtrage budgétaire (décision D4) : si la clé API est absente,
        // on ne tente même pas l'appel — le pipeline reste 100 % déterministe.
        if (empty(config('openrouter.api_key'))) {
            Log::info('detect_blocks : clé API absente, classification IA ignorée', [
                'blocks' => count($blocks),
            ]);

            return [
                'results' => [],
                'cost_usd' => 0.0,
                'cost_credits' => 0,
                'batches' => 0,
                'failed' => array_map(static fn (Block $b): string => $b->blockId, $blocks),
            ];
        }

        foreach (array_chunk($blocks, self::BATCH_SIZE) as $batch) {
            $batches++;

            try {
                $parsed = $this->classifyBatch($batch, $plan);

                $results = [...$results, ...$parsed['results']];
                $costUsd += $parsed['cost_usd'];
                $costCredits += $parsed['cost_credits'];
            } catch (Throwable $e) {
                // Un lot en échec n'interrompt PAS le traitement : les blocs
                // concernés resteront à leur classification déterministe.
                Log::warning('detect_blocks : lot en échec', [
                    'batch_size' => count($batch),
                    'error' => $e->getMessage(),
                ]);

                foreach ($batch as $block) {
                    $failed[] = $block->blockId;
                }
            }
        }

        return [
            'results' => $results,
            'cost_usd' => $costUsd,
            'cost_credits' => $costCredits,
            'batches' => $batches,
            'failed' => $failed,
        ];
    }

    /**
     * Classifie un lot de blocs par un appel unique.
     *
     * @param  array<int, Block>  $batch
     * @return array{results: array<string, array<string, mixed>>, cost_usd: float, cost_credits: int}
     */
    private function classifyBatch(array $batch, string $plan): array
    {
        $contexts = array_map(
            fn (Block $block): array => [
                'block_id' => $block->blockId,
                // Texte borné : on n'envoie pas 3 000 caractères pour deviner
                // si c'est un titre, et cela réduit le coût en tokens.
                'text' => mb_substr($block->text, 0, 160),
                'font_size' => $block->fontSize,
                'is_bold' => $block->isBold,
                'indent_level' => $block->indentLevel,
                'position_y' => $block->positionY,
            ],
            $batch
        );

        $response = $this->openRouter->chat(
            taskType: 'document_analysis',
            messages: [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => json_encode($contexts, JSON_UNESCAPED_UNICODE)],
            ],
            plan: $plan,
            options: [
                // Température basse : on veut une classification reproductible,
                // pas de la créativité.
                'temperature' => 0.1,
                'max_tokens' => 2000,
                'response_format' => ['type' => 'json_object'],
            ],
        );

        return [
            'results' => $this->parseResults((string) ($response['content'] ?? '')),
            'cost_usd' => (float) ($response['cost_usd'] ?? 0.0),
            'cost_credits' => (int) ($response['cost_credits'] ?? 0),
        ];
    }

    /**
     * Prompt système du tool (§7 de la spécification).
     *
     * Les consignes sont volontairement explicites : le modèle doit savoir
     * qu'admettre son incertitude est un résultat VALIDE et attendu, sinon il
     * force une réponse au hasard — ce qui coûterait plus cher en corrections.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un outil de classification structurelle de documents Word.

Tu reçois une liste de blocs avec leurs signaux structurels
(font_size, is_bold, indent_level, position_y, texte brut).

Pour CHAQUE bloc, retourne un JSON :
{
  "block_id": "...",
  "predicted_type": "heading_1 | heading_2 | heading_3 | heading_4 |
                     paragraph | table_header | figure | caption | annexe | planche",
  "confidence": 0.0-1.0,
  "signal_used": "font_size+bold | isolated_line | position | text_pattern"
}

Format de réponse attendu (JSON strict, aucun texte autour) :
{"results": [ { ... }, { ... } ]}

Règles impératives :
1. Ne devine JAMAIS sans signal à l'appui.
2. Si les signaux sont contradictoires ou absents, confidence DOIT être < 0.7.
   Il est CORRECT et ATTENDU de retourner une confiance basse : c'est un
   résultat utile, pas un échec.
3. Ne génère JAMAIS de texte : tu ne fais que CLASSIFIER un bloc existant.
   Le champ "text" ne doit jamais apparaître dans ta réponse.
4. Un texte long, contenant un verbe conjugué ou finissant par un point est
   un "paragraph", pas un titre.
5. Un texte court (moins de 80 caractères), sans ponctuation finale, en gras
   ou plus grand que le texte courant est probablement un titre.
6. Retourne un résultat pour CHAQUE block_id reçu, sans exception.
PROMPT;
    }

    /**
     * Analyse la réponse JSON du modèle.
     *
     * Toute entrée invalide est ignorée : le bloc restera à sa classification
     * déterministe plutôt que d'être classé sur une sortie malformée.
     *
     * @return array<string, array{type: BlockType, confidence: float, signal: string, heading_level: null|int, category: null|string}>
     */
    private function parseResults(string $content): array
    {
        $decoded = $this->decodeJson($content);

        if ($decoded === null) {
            Log::warning('detect_blocks : réponse non décodable', [
                'prefix' => mb_substr($content, 0, 200),
            ]);

            return [];
        }

        // Le modèle peut renvoyer directement une liste ou l'envelopper.
        $items = $decoded['results'] ?? $decoded;

        if (! is_array($items)) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $blockId = (string) ($item['block_id'] ?? '');
            $predicted = (string) ($item['predicted_type'] ?? '');

            if ($blockId === '' || ! in_array($predicted, self::ALLOWED_TYPES, true)) {
                continue;
            }

            $confidence = $item['confidence'] ?? null;
            if (! is_numeric($confidence) || $confidence < 0 || $confidence > 1) {
                continue;
            }

            $results[$blockId] = [
                'type' => $this->typeFromPrediction($predicted),
                'confidence' => round((float) $confidence, 2),
                'signal' => (string) ($item['signal_used'] ?? 'unknown'),
                'heading_level' => $this->levelFromPrediction($predicted),
                'category' => $this->categoryFromPrediction($predicted),
            ];
        }

        return $results;
    }

    /**
     * Décode un JSON, en tolérant les blocs Markdown que certains modèles ajoutent.
     *
     * @return null|array<mixed>
     */
    private function decodeJson(string $content): ?array
    {
        $content = trim($content);

        // Retirer un éventuel encadrement Markdown (```json … ```).
        if (str_starts_with($content, '```')) {
            $content = (string) preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $content);
            $content = trim($content);
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Dernier recours : extraire le premier objet JSON équilibré.
        $start = strpos($content, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            if ($content[$i] === '{') {
                $depth++;
            } elseif ($content[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    $candidate = json_decode(substr($content, $start, $i - $start + 1), true);

                    return is_array($candidate) ? $candidate : null;
                }
            }
        }

        return null;
    }

    /**
     * Convertit un `predicted_type` en type de bloc du schéma commun.
     */
    private function typeFromPrediction(string $prediction): BlockType
    {
        return match ($prediction) {
            'heading_1', 'heading_2', 'heading_3', 'heading_4' => BlockType::Heading,
            'table_header' => BlockType::Table,
            'figure' => BlockType::Figure,
            'caption' => BlockType::Caption,
            'annexe' => BlockType::Annexe,
            'planche' => BlockType::Planche,
            default => BlockType::Paragraph,
        };
    }

    /**
     * Niveau hiérarchique déduit d'un `predicted_type` de titre.
     */
    private function levelFromPrediction(string $prediction): ?int
    {
        if (preg_match('/^heading_(\d)$/', $prediction, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Catégorie de numérotation déduite d'un `predicted_type`.
     */
    private function categoryFromPrediction(string $prediction): ?string
    {
        return match ($prediction) {
            'figure' => 'figure',
            'caption' => 'figure', // catégorie affinée par le contexte du bloc lié
            'annexe' => 'annexe',
            'planche' => 'planche',
            default => null,
        };
    }
}
