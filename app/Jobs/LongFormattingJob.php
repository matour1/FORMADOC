<?php

namespace App\Jobs;

use App\Mail\DocumentReadyMail;
use App\Models\Document;
use App\Models\User;
use App\Services\Billing\CreditService;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Traitement long : mise en forme complète d'un document par IA.
 *
 * Placé en file d'attente pour ne pas bloquer la requête HTTP.
 * Flux :
 *   1. vérification du solde (échec → remboursement)
 *   2. débit des crédits estimés
 *   3. sélection du modèle (document_full_format) via ModelRouter
 *   4. appel OpenRouter avec la structure du document
 *   5. succès → mise à jour du document ; échec → remboursement intégral
 */
class LongFormattingJob implements ShouldQueue
{
    use Queueable;

    /**
     * Durées de suppression avant exécution.
     */
    public int $tries = 3;
    public int $timeout = 600;
    public int $backoff = 30;

    public function __construct(
        public readonly Document $document,
        public readonly int $estimatedCredits,
        public readonly ?int $userId = null,
    ) {
    }

    public function handle(
        OpenRouterService $openRouter,
        CreditService $credits,
    ): void {
        $user = $this->userId ? User::find($this->userId) : null;

        if (! $user) {
            Log::error('LongFormattingJob : utilisateur introuvable', ['document_id' => $this->document->id]);
            $this->fail(new \RuntimeException('Utilisateur introuvable.'));
            return;
        }

        // 1. Vérification du solde
        if (! $user->hasCredits($this->estimatedCredits)) {
            Log::warning('LongFormattingJob : solde insuffisant', [
                'user_id' => $user->id,
                'document_id' => $this->document->id,
                'required' => $this->estimatedCredits,
            ]);
            $this->fail(new \RuntimeException('Solde de crédits insuffisant.'));
            return;
        }

        // 1bis. P2-2 : marque le document comme « en cours de traitement »
        // (statut processing) pour l'affichage — l'utilisateur sait que la
        // mise en forme IA est en cours, et les vues/filtres le reflètent.
        $this->document->update(['status' => 'processing']);

        // 2. Débit des crédits estimés
        $debit = $credits->debit(
            $user,
            $this->estimatedCredits,
            type: 'usage',
            reference: 'format:'.$this->document->id,
            description: 'Mise en forme IA du document #'.$this->document->id,
            metadata: ['purpose' => 'document_full_format', 'document_id' => $this->document->id, 'estimated' => true],
        );

        if (! $debit['ok']) {
            // P2-2 : retour au statut détecté (l'utilisateur peut relancer)
            $this->document->update(['status' => 'detected']);
            $this->fail(new \RuntimeException('Impossible de débiter les crédits ('.$debit['reason'].').'));
            return;
        }

        // 3. Structure du document
        $structure = $this->document->structure?->structure;
        if (empty($structure)) {
            $this->refund($credits, $user, 'structure_vide');
            // P2-2 : retour au statut détecté (l'utilisateur peut relancer)
            $this->document->update(['status' => 'detected']);
            $this->fail(new \RuntimeException('Aucune structure détectée pour le document.'));
            return;
        }

        // 4. Appel OpenRouter (mise en forme complète)
        $plan = $user->currentPlanSlug();
        $messages = [
            [
                'role' => 'system',
                'content' => 'Vous êtes un expert en mise en forme de documents académiques. '
                    .'À partir de la structure JSON fournie, proposez des améliorations de '
                    .'formatage (titres, hiérarchie, styles, cohérence). Répondez en JSON valide.',
            ],
            [
                'role' => 'user',
                'content' => "Document #{$this->document->id}\nStructure JSON :\n"
                    .json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ],
        ];

        try {
            $response = $openRouter->chat('document_full_format', $messages, $plan, [
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object'],
            ]);
        } catch (\Throwable $e) {
            $this->refund($credits, $user, 'llm_failure');
            // P2-2 : retour au statut détecté (l'utilisateur peut relancer)
            $this->document->update(['status' => 'detected']);
            Log::error('LongFormattingJob : échec execution', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        // 5. Mise à jour du document
        $metadata = $this->document->metadata ?? [];
        $metadata['ai_format'] = [
            'model' => $response['model'],
            'usage' => $response['usage'],
            'cost_credits' => $response['cost_credits'],
            'processed_at' => now()->toIso8601String(),
        ];
        $this->document->update([
            'status' => 'ready',
            'metadata' => $metadata,
        ]);

        // Email « document prêt » (ne bloque jamais le traitement)
        $templateName = null;
        if (isset($metadata['template_name']) && is_string($metadata['template_name'])) {
            $templateName = $metadata['template_name'];
        } elseif (isset($metadata['template_id'])) {
            $templateName = \App\Models\Template::find((int) $metadata['template_id'])?->name;
        }

        try {
            Mail::to($user->email)->send(new DocumentReadyMail(
                user: $user,
                document: $this->document,
                templateName: $templateName,
            ));
            Log::info('LongFormattingJob : email document prêt envoyé', [
                'document_id' => $this->document->id,
                'user_id' => $user->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('LongFormattingJob : échec envoi email document prêt', [
                'document_id' => $this->document->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Ajustement du coût réel (remboursement si inférieur à l'estimation)
        $actual = (int) $response['cost_credits'];
        $diff = $this->estimatedCredits - $actual;
        if ($diff > 0) {
            $credits->credit(
                $user,
                $diff,
                type: 'refund',
                reference: 'format:'.$this->document->id,
                description: 'Ajustement mise en forme IA (coût réel inférieur)',
                metadata: ['purpose' => 'document_full_format', 'document_id' => $this->document->id, 'adjustment' => true],
            );
        }

        Log::info('LongFormattingJob : terminé', [
            'document_id' => $this->document->id,
            'user_id' => $user->id,
            'model' => $response['model'],
            'cost_credits' => $actual,
        ]);
    }

    /**
     * Rembourse l'intégralité des crédits estimés.
     */
    private function refund(CreditService $credits, User $user, string $reason): void
    {
        $credits->credit(
            $user,
            $this->estimatedCredits,
            type: 'refund',
            reference: 'format:'.$this->document->id,
            description: 'Remboursement mise en forme IA ('.$reason.')',
            metadata: ['purpose' => 'document_full_format', 'document_id' => $this->document->id, 'refund_reason' => $reason],
        );
    }
}
