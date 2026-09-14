<?php

declare(strict_types=1);

namespace App\Document\Editing;

use App\Document\Structure\StructuralDocument;
use App\Models\Document;
use App\Models\DocumentStructure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pont entre la persistance et l'orchestrateur d'édition (phase R6.14–R6.16).
 *
 * **Pourquoi ce composant existe** — `EditOrchestrator` travaille sur un
 * `StructuralDocument` en mémoire, alors que le chat ne connaît qu'un
 * identifiant de document. Quelqu'un doit faire la jonction : charger le JSON
 * structurel, appliquer l'édition, enregistrer le résultat. C'est ce rôle, et
 * c'est le seul endroit du module qui touche à la base de données.
 *
 * **Séparation volontaire** : l'orchestrateur reste pur (aucune I/O), donc
 * entièrement testable et réutilisable. Le pont est la fine couche qui assume
 * les effets de bord. Mélanger les deux rendrait l'orchestrateur impossible à
 * éprouver sans base de données.
 *
 * **Deux garanties concrètes :**
 *  1. l'édition n'est appliquée que si le document **porte** une structure
 *     native. Sans elle, il n'y a rien à éditer — et on le dit clairement au
 *     lieu d'échouer sur un `null` ;
 *  2. la persistance est **atomique** : on enregistre la structure et on met à
 *     jour la version. Une structure modifiée sans sa version rendrait le
 *     document incohérent au prochain chargement.
 */
final class DocumentEditingService
{
    public function __construct(
        private readonly EditOrchestrator $orchestrator = new EditOrchestrator,
    ) {}

    /**
     * Applique une demande d'édition à un document persisté.
     *
     * @param  int  $documentId  Document à éditer
     * @param  string  $tool  Tool de la liste blanche
     * @param  array<string, mixed>  $arguments  Arguments décodés du modèle
     * @param  string  $owner  Identifiant du processus (« chat:12 »)
     * @param  bool  $confirmed  L'utilisateur a-t-il confirmé une action large ?
     * @return array{
     *     applied: bool,
     *     summary: string,
     *     details: array<string, mixed>,
     *     snapshot_id: null|int,
     *     renumbered: bool,
     *     confirmation_required: bool,
     *     error: null|string
     * }
     */
    public function apply(
        int $documentId,
        string $tool,
        array $arguments,
        string $owner = 'chat',
        bool $confirmed = false,
    ): array {
        $chargement = $this->load($documentId);

        if ($chargement['error'] !== null) {
            return $this->echec($chargement['error']);
        }

        /** @var Document $document */
        $document = $chargement['document'];
        /** @var DocumentStructure $structure */
        $structure = $chargement['structure'];
        $structural = $chargement['structural'];

        $resultat = $this->orchestrator->apply(
            $structural,
            $documentId,
            $tool,
            $arguments,
            $owner,
            $confirmed,
        );

        // Rien à persister si l'action n'a pas été appliquée : ni confirmation
        // en attente, ni échec ne doivent toucher au document.
        if (! ($resultat['applied'] ?? false)) {
            return [
                'applied' => false,
                'summary' => '',
                'details' => $resultat['details'] ?? [],
                'snapshot_id' => null,
                'renumbered' => false,
                'confirmation_required' => (bool) ($resultat['confirmation_required'] ?? false),
                'error' => $resultat['error'] ?? null,
            ];
        }

        try {
            $this->persist($structure, $resultat['document']);
        } catch (Throwable $e) {
            Log::error('Édition appliquée mais non persistée', [
                'document_id' => $documentId,
                'tool' => $tool,
                'error' => $e->getMessage(),
            ]);

            // On le dit explicitement : le document en base n'a PAS bougé, donc
            // l'utilisateur ne doit pas croire son édition enregistrée.
            return $this->echec(
                "L'édition a été calculée mais n'a pas pu être enregistrée : "
                .$e->getMessage().'. Le document reste dans son état précédent.'
            );
        }

        return [
            'applied' => true,
            'summary' => (string) ($resultat['summary'] ?? ''),
            'details' => $resultat['details'] ?? [],
            'snapshot_id' => $resultat['snapshot_id'] ?? null,
            'renumbered' => (bool) ($resultat['renumbered'] ?? false),
            'confirmation_required' => false,
            'error' => null,
        ];
    }

    /**
     * Charge le document et sa structure native.
     *
     * @return array{
     *     document: null|Document,
     *     structure: null|DocumentStructure,
     *     structural: null|StructuralDocument,
     *     error: null|string
     * }
     */
    public function load(int $documentId): array
    {
        $document = Document::find($documentId);

        if ($document === null) {
            return $this->chargementEchoue("Aucun document ne porte l'identifiant {$documentId}.");
        }

        $structure = DocumentStructure::where('document_id', $documentId)->first();

        if ($structure === null) {
            return $this->chargementEchoue(
                'Ce document n’a pas encore été analysé : sa structure est inconnue, '
                .'il n’y a donc rien à éditer.'
            );
        }

        $structural = $structure->structuralDocument();

        if ($structural === null) {
            return $this->chargementEchoue(
                'Ce document a été traité par l’ancien pipeline : sa structure n’est pas '
                .'modifiable par le chat. Relancez une analyse pour activer l’édition.'
            );
        }

        return [
            'document' => $document,
            'structure' => $structure,
            'structural' => $structural,
            'error' => null,
        ];
    }

    /**
     * Annule la dernière action et persiste l'état restauré.
     *
     * @return array{restored: bool, summary: string, error: null|string}
     */
    public function undo(int $documentId, ?int $snapshotId = null): array
    {
        $chargement = $this->load($documentId);

        if ($chargement['error'] !== null) {
            return ['restored' => false, 'summary' => '', 'error' => $chargement['error']];
        }

        $restauration = $this->orchestrator->undo($documentId, $snapshotId);

        if ($restauration === null) {
            return [
                'restored' => false,
                'summary' => '',
                'error' => 'Aucune action à annuler pour ce document.',
            ];
        }

        try {
            $this->persist($chargement['structure'], $restauration['document']);
        } catch (Throwable $e) {
            Log::error('Annulation restaurée mais non persistée', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'restored' => false,
                'summary' => '',
                'error' => "L'annulation n'a pas pu être enregistrée : ".$e->getMessage(),
            ];
        }

        return [
            'restored' => true,
            'summary' => 'Document restauré : '.$restauration['snapshot']->label().'.',
            'error' => null,
        ];
    }

    /**
     * Actions annulables d'un document, pour l'interface.
     *
     * @return array<int, array<string, mixed>>
     */
    public function undoHistory(int $documentId, int $limit = 5): array
    {
        return $this->orchestrator->undoHistory($documentId, $limit);
    }

    /**
     * Enregistre la structure éditée et sa version.
     *
     * Les deux écritures vont ensemble : une structure modifiée sans sa version
     * serait relue par un pipeline qui croirait à une ancienne révision. Le
     * `pipeline` est conservé pour que `wasProcessedByNativePipeline()` reste
     * vrai — sinon le document semblerait rétrogradé après une simple édition.
     */
    private function persist(DocumentStructure $structure, StructuralDocument $document): void
    {
        $structure->update([
            'structural_json' => $document->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => $structure->pipeline ?? 'native',
        ]);
    }

    /**
     * Réponse d'échec de chargement, de forme homogène.
     *
     * @return array{document: null, structure: null, structural: null, error: string}
     */
    private function chargementEchoue(string $message): array
    {
        return ['document' => null, 'structure' => null, 'structural' => null, 'error' => $message];
    }

    /**
     * Réponse d'échec d'application, de forme identique à un succès.
     *
     * @return array<string, mixed>
     */
    private function echec(string $message): array
    {
        return [
            'applied' => false,
            'summary' => '',
            'details' => [],
            'snapshot_id' => null,
            'renumbered' => false,
            'confirmation_required' => false,
            'error' => $message,
        ];
    }
}
