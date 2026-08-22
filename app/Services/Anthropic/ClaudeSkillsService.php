<?php

declare(strict_types=1);

namespace App\Services\Anthropic;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Intégration EXPÉRIMENTALE des Skills documentaires Claude (docx, xlsx,
 * pptx, pdf) via l'API Anthropic (exigence B).
 *
 * Accessible (Q4) :
 *   - aux abonnés des plans payants ≥ config('billing.skills_min_plan')
 *     (standard, premium, pro, enterprise) : inclus dans l'abonnement ;
 *   - aux autres utilisateurs en pay-per-use via crédits, avec une
 *     majoration de config('billing.skills_no_subscription_multiplier', 1.5).
 *
 * Ne doit JAMAIS bloquer l'application : toute erreur lève une exception
 * propre attrapée par l'appelant, qui bascule sur le fallback interne
 * (PHPWord / outils internes).
 *
 * Flux :
 *   1. POST /v1/messages avec le container de skills + code execution
 *   2. Le modèle répond avec des blocs bash_code_execution_tool_result
 *   3. On extrait file_id et on télécharge le fichier via l'API Files
 *   4. On stocke le fichier dans le disk 'local'
 *
 * Coûts (crédits) :
 *   - tokens modèle (input + output)
 *   - conteneur : 0,05 $/h, minimum 5 min par exécution
 *   - coefficient de rentabilité (×2) : couvre infrastructure + marge 40-60 %
 *   - ×1,5 supplémentaire si l'utilisateur paie en crédits (sans abonnement)
 */
class ClaudeSkillsService
{
    /**
     * Liste des skills disponibles.
     *
     * @var string[]
     */
    public const SKILLS = ['docx', 'xlsx', 'pptx', 'pdf'];

    /**
     * Ordre des plans pour comparer les niveaux d'accès.
     *
     * @var array<string, int>
     */
    private const PLAN_RANKS = [
        'default' => 0,
        'standard' => 1,
        'premium' => 2,
        'pro' => 3,
        'enterprise' => 4,
    ];

    public function __construct(
        private readonly \App\Services\Billing\UsageCostCalculator $costCalculator,
    ) {
    }

    /**
     * Vérifie que l'intégration est configurée et disponible.
     */
    public function isConfigured(): bool
    {
        return config('anthropic.api_key') !== '';
    }

    /**
     * Vérifie que l'utilisateur est éligible aux Skills documentaires.
     *
     * Q4 : abonnement payant ≥ skills_min_plan (inclus) OU solde de crédits
     * suffisant pour le pay-per-use (×1,5 sans abonnement).
     */
    public function isEligible(?\App\Models\User $user): bool
    {
        if ($user === null || ! $this->isConfigured()) {
            return false;
        }

        return $this->hasPaidSubscription($user)
            || $user->hasCredits($this->minPayPerUseCredits());
    }

    /**
     * L'utilisateur a-t-il un abonnement payant (≥ skills_min_plan) actif ?
     */
    public function hasPaidSubscription(\App\Models\User $user): bool
    {
        $minRank = $this->planRank((string) config('billing.skills_min_plan', 'standard'));

        return $this->planRank($user->currentPlanSlug()) >= max(1, $minRank);
    }

    /**
     * Coût minimal (crédits) d'un pay-per-use : le skill le moins cher
     * (docx), avec la majoration sans abonnement.
     */
    public function minPayPerUseCredits(): int
    {
        $base = $this->estimateCredits([], 'docx');
        $multiplier = (float) config('billing.skills_no_subscription_multiplier', 1.5);

        return (int) max(1, (int) ceil($base * $multiplier));
    }

    /**
     * Rang d'un plan (ordre décroissant d'accès).
     */
    private function planRank(string $slug): int
    {
        return self::PLAN_RANKS[$slug] ?? 0;
    }

    /**
     * Génère un document natif (docx/xlsx/pptx/pdf) via le skill Claude.
     *
     * @param \App\Models\User $user       Utilisateur Pro (éligibilité vérifiée avant)
     * @param string           $skill      'docx' | 'xlsx' | 'pptx' | 'pdf'
     * @param string           $prompt     Instructions de génération
     * @param string           $outputName Nom du fichier de sortie (sans extension)
     *
     * @return array{path: string, filename: string, cost_credits: int, model: string}
     *
     * @throws \RuntimeException Si la génération échoue (l'appelant gère le fallback)
     */
    public function generate(\App\Models\User $user, string $skill, string $prompt, string $outputName = 'document'): array
    {
        if (! in_array($skill, self::SKILLS, true)) {
            throw new \RuntimeException("Skill documentaire inconnu : {$skill}");
        }
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Clé API Anthropic non configurée.');
        }

        $messages = [
            [
                'role' => 'user',
                'content' => "Génère un fichier .{$skill} conforme à la demande suivante :\n\n{$prompt}\n\n"
                    .'Réponds avec un fichier unique et complet. Si plusieurs blocs de code sont exécutés, '
                    .'le fichier final doit être le résultat consolidé.',
            ],
        ];

        $payload = $this->buildPayload($skill, $messages);

        try {
            $response = $this->postWithRetry('/messages', $payload);
        } catch (RequestException|ConnectionException $e) {
            Log::error('ClaudeSkills : échec API', [
                'skill' => $skill,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Échec de la génération Claude : '.$e->getMessage(), 0, $e);
        }

        $data = $response->json();

        // Extraction du fichier généré depuis les blocs de résultat
        $fileId = $this->extractFileId($data);
        if (! $fileId) {
            Log::warning('ClaudeSkills : aucun fichier dans la réponse', [
                'skill' => $skill,
                'raw' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);

            throw new \RuntimeException('Aucun fichier généré par le modèle.');
        }

        // Téléchargement du fichier
        try {
            $fileContent = $this->downloadFile($fileId);
        } catch (RequestException|ConnectionException $e) {
            Log::error('ClaudeSkills : échec téléchargement', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Échec du téléchargement du fichier généré.', 0, $e);
        }

        if ($fileContent === '' || $fileContent === null) {
            throw new \RuntimeException('Fichier généré vide.');
        }

        // Stockage
        $filename = $this->safeFilename($outputName).'.'.$skill;
        $path = 'claude-skills/'.date('Y/m/d').'/'.$filename;
        Storage::disk('local')->put($path, $fileContent);

        // Coût en crédits (tokens + conteneur, coefficient appliqué)
        $costCredits = $this->effectiveCost($data, $skill, $user);

        Log::info('ClaudeSkills : fichier généré', [
            'skill' => $skill,
            'user_id' => $user->id,
            'path' => $path,
            'cost_credits' => $costCredits,
        ]);

        return [
            'path' => $path,
            'filename' => $filename,
            'cost_credits' => $costCredits,
            'model' => (string) config('anthropic.model'),
        ];
    }

    /**
     * Coût effectif en crédits d'une génération, selon le statut de
     * l'utilisateur (Q4) :
     *   - abonnement payant actif → coût de base (inclus dans l'abonnement)
     *   - pay-per-use (sans abonnement) → × skills_no_subscription_multiplier
     *
     * @param array<string, mixed> $responseData Réponse API (tokens réels)
     */
    public function effectiveCost(array $responseData, string $skill, ?\App\Models\User $user = null): int
    {
        $base = $this->estimateCredits($responseData, $skill);

        if ($user !== null && ! $this->hasPaidSubscription($user)) {
            $multiplier = (float) config('billing.skills_no_subscription_multiplier', 1.5);

            return (int) max(1, (int) ceil($base * $multiplier));
        }

        return $base;
    }

    /**
     * Estimation du coût en crédits d'une génération Skill Claude.
     *
     * @param array<string, mixed> $responseData Réponse API (pour tokens réels)
     */
    public function estimateCredits(array $responseData, string $skill): int
    {
        $usage = $responseData['usage'] ?? [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        // Coût modèle estimé (claude-sonnet-4-6 : ~3 $/M input, ~15 $/M output)
        $modelCost = $inputTokens / 1_000_000 * 3.0
            + $outputTokens / 1_000_000 * 15.0;

        // Conteneur : 0,05 $/h, minimum 5 min
        $containerMinutes = (float) max(
            config('anthropic.container_min_minutes', 5),
            $this->estimateContainerMinutes($skill)
        );
        $containerCost = config('anthropic.container_cost_per_minute', 0.05 / 60) * $containerMinutes;

        $totalUsd = $modelCost + $containerCost;

        // Coefficient de rentabilité (×2 : infrastructure + marge 40-60 %)
        $multiplier = (float) config('anthropic.credit_multiplier', 2.0);

        return (int) max(1, (int) ceil($totalUsd * $multiplier * $this->rateFcfaPerUsd()));
    }

    /**
     * Durée estimée du conteneur selon le skill (minutes).
     * Approximation : les générations plus complexes prennent plus de temps.
     */
    private function estimateContainerMinutes(string $skill): float
    {
        return match ($skill) {
            'pdf' => 8.0,
            'pptx' => 7.0,
            'xlsx' => 6.0,
            default => 5.0, // docx
        };
    }

    /* ------------------------------------------------------------------
     |  Construction de la requête
     | ------------------------------------------------------------------ */

    /**
     * @param array<int, array{role: string, content: string}> $messages
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $skill, array $messages): array
    {
        $skillConfig = config('anthropic.skills.'.$skill);

        return [
            'model' => config('anthropic.model'),
            'betas' => config('anthropic.betas', ['skills-2025-10-02']),
            'max_tokens' => 16384,
            'messages' => $messages,
            'container' => [
                'skills' => [
                    $skillConfig,
                ],
            ],
            'tools' => [
                [
                    'type' => config('anthropic.code_execution_tool', 'code_execution_20260521'),
                    'name' => 'code_execution',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractFileId(array $data): ?string
    {
        $blocks = $data['content'] ?? [];

        foreach ($blocks as $block) {
            // Les blocs peuvent être {type: 'bash_code_execution_tool_result', file_id: ...}
            // ou contenir un tableau de blocs imbriqués
            $candidates = [];

            if (($block['type'] ?? '') === 'bash_code_execution_tool_result') {
                $candidates[] = $block;
            }

            // Parcours récursif des blocs imbriqués
            foreach ($candidates as $candidate) {
                $nested = $candidate['content'] ?? [];
                if (is_array($nested)) {
                    foreach ($nested as $inner) {
                        if (is_array($inner) && ! empty($inner['file_id'])) {
                            return (string) $inner['file_id'];
                        }
                    }
                }
                if (! empty($candidate['file_id'])) {
                    return (string) $candidate['file_id'];
                }
            }
        }

        // Fallback : parcours récursif générique
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveArrayIterator($data),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $key => $value) {
            if ($key === 'file_id' && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Télécharge un fichier généré via l'API Files.
     */
    private function downloadFile(string $fileId): ?string
    {
        $response = Http::withHeaders([
            'x-api-key' => config('anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout((int) config('anthropic.timeout_seconds', 300))
            ->get(config('anthropic.api_url').'/files/'.$fileId.'/content');

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response->body();
    }

    /**
     * POST avec retry backoff exponentiel sur 429 / 529 / erreurs réseau.
     *
     * @param array<string, mixed> $payload
     */
    private function postWithRetry(string $endpoint, array $payload): \Illuminate\Http\Client\Response
    {
        $backoffs = config('anthropic.retry_backoff_ms', [1000, 2000, 4000]);
        $attempt = 0;
        $maxAttempts = (int) config('anthropic.max_retries', 3) + 1;

        while (true) {
            $response = Http::withHeaders([
                'x-api-key' => config('anthropic.api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout((int) config('anthropic.timeout_seconds', 300))
                ->post(config('anthropic.api_url').$endpoint, $payload);

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            $retryable = $status === 429 || $status === 529
                || ($status >= 500 && $status < 600);

            if (! $retryable || $attempt >= $maxAttempts) {
                throw new RequestException($response);
            }

            $delay = $backoffs[min($attempt, count($backoffs) - 1)] ?? 1000;
            usleep((int) $delay * 1000);
            $attempt++;
        }
    }

    /* ------------------------------------------------------------------
     |  Utilitaires
     | ------------------------------------------------------------------ */

    private function rateFcfaPerUsd(): float
    {
        return (float) config('openrouter.rate_fcfa_per_usd', 620);
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'document';
        $name = trim($name, '._-');

        return $name !== '' ? substr($name, 0, 60) : 'document';
    }
}
