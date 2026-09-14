<?php

declare(strict_types=1);

namespace App\Document\Clarification;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Models\DocumentClarification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gestion des clarifications : création des questions et application des réponses.
 *
 * Point de vigilance essentiel (§8) : **une réponse ne corrige QUE le bloc visé**.
 * Il serait tentant d'appliquer une réponse à tous les blocs similaires, mais ce
 * serait imposer une décision de l'utilisateur là où il ne l'a pas donnée.
 */
final class ClarificationService
{
    public function __construct(private readonly AskUserClarificationTool $tool) {}

    /**
     * Crée les questions pour les blocs restés ambigus après classification.
     *
     * Une clarification DÉJÀ répondue n'est jamais recréée (contrainte
     * d'unicité `document_id` + `block_id`) : l'utilisateur n'a pas à répondre
     * deux fois à la même question après une nouvelle analyse.
     *
     * @param  array<int, Block>  $ambiguousBlocks
     * @return array{created: int, skipped: int, questions: array<int, array<string, mixed>>}
     */
    public function createQuestions(int $documentId, array $ambiguousBlocks): array
    {
        $created = 0;
        $skipped = 0;
        $questions = [];

        foreach ($ambiguousBlocks as $block) {
            // Déjà répondue : la réponse de l'utilisateur prime sur toute
            // nouvelle analyse (c'est une décision humaine, pas un calcul).
            $existing = DocumentClarification::where('document_id', $documentId)
                ->where('block_id', $block->blockId)
                ->first();

            if ($existing !== null) {
                $skipped++;

                // On la conserve dans les questions à afficher pour mémoire.
                if (! $existing->isAnswered()) {
                    $questions[] = $this->tool->buildQuestion($block);
                }

                continue;
            }

            $question = $this->tool->buildQuestion($block);

            try {
                DocumentClarification::create([
                    'document_id' => $documentId,
                    'block_id' => $block->blockId,
                    'question' => $question['question'],
                    'input_type' => $question['input_type'],
                    'options' => $question['options'],
                    'excerpt' => $question['context']['excerpt'],
                    'confidence' => $block->confidence,
                    'reason' => $question['context']['reason'],
                ]);

                $created++;
                $questions[] = $question;
            } catch (Throwable $e) {
                // Un échec d'enregistrement ne doit pas interrompre le
                // traitement : le bloc restera simplement à clarifier plus tard.
                Log::warning('Clarification : enregistrement impossible', [
                    'document_id' => $documentId,
                    'block_id' => $block->blockId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'questions' => $questions,
        ];
    }

    /**
     * Applique les réponses enregistrées à un document structurel.
     *
     * @return array{document: StructuralDocument, applied: int, pending: int}
     */
    public function applyAnswers(int $documentId, StructuralDocument $document): array
    {
        $clarifications = DocumentClarification::where('document_id', $documentId)
            ->whereNotNull('answered_at')
            ->get();

        $applied = 0;

        foreach ($clarifications as $clarification) {
            $block = $document->blockById($clarification->block_id);

            if ($block === null) {
                // Le bloc a disparu (structure régénérée, bloc supprimé) : on
                // ne fait rien, mais on le trace pour le diagnostic.
                Log::info('Clarification : bloc introuvable, réponse ignorée', [
                    'document_id' => $documentId,
                    'block_id' => $clarification->block_id,
                ]);

                continue;
            }

            $corrected = $this->correctBlock($block, $clarification);
            $document = $document->replaceBlock($corrected);
            $applied++;
        }

        $pending = DocumentClarification::where('document_id', $documentId)
            ->whereNull('answered_at')
            ->count();

        return [
            'document' => $document,
            'applied' => $applied,
            'pending' => $pending,
        ];
    }

    /**
     * Corrige un bloc selon la réponse de l'utilisateur.
     *
     * Confiance portée à 1,0 : la décision vient de l'utilisateur lui-même,
     * il n'y a donc plus rien d'incertain.
     */
    private function correctBlock(Block $block, DocumentClarification $clarification): Block
    {
        $type = $clarification->answer_type ?? 'paragraph';

        $blockType = match ($type) {
            'heading_1', 'heading_2', 'heading_3' => BlockType::Heading,
            'table' => BlockType::Table,
            'figure' => BlockType::Figure,
            'image' => BlockType::Image,
            'annexe' => BlockType::Annexe,
            'planche' => BlockType::Planche,
            'caption' => BlockType::Caption,
            default => BlockType::Paragraph,
        };

        $headingLevel = $clarification->headingLevelFromAnswer();

        return $block
            ->withClassification($blockType, 1.0, $headingLevel)
            // Une réponse de l'utilisateur est une vérité : plus aucune
            // clarification ne sera demandée sur ce bloc.
            ->withConfidence(1.0);
    }

    /**
     * Questions en attente pour un document (pour l'interface).
     *
     * @return array<int, DocumentClarification>
     */
    public function pendingFor(int $documentId): array
    {
        return DocumentClarification::where('document_id', $documentId)
            ->whereNull('answered_at')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
