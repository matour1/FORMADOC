<?php

declare(strict_types=1);

namespace App\Document\Editing;

use App\Document\Numbering\NumberingCoordinator;
use App\Document\Structure\StructuralDocument;
use App\Models\DocumentSnapshot;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrateur du chat d'édition : applique les six garde-fous du §9 (phase R6.2).
 *
 * Un tool pris isolément est une transformation de données. Ce composant est ce
 * qui rend l'ensemble sûr, en imposant une séquence que **le modèle ne peut pas
 * contourner** — c'est le point essentiel. Le modèle demande une action ; il ne
 * décide pas de la sécurité.
 *
 * Séquence imposée pour toute action :
 *
 * ```
 *   1. liste blanche      — le tool est-il autorisé ?            (§9.14)
 *   2. validation entrée  — les arguments sont-ils exploitables ? (§9.9)
 *   3. verrou             — un autre processus édite-t-il ?      (§9.8)
 *   4. confirmation       — l'action est-elle trop large ?       (§9.16)
 *   5. snapshot           — état antérieur enregistré ?          (§9.7)
 *   6. exécution du tool  — transformation pure
 *   7. validation sortie  — le document reste-t-il cohérent ?    (§9.9)
 *   8. renumérotation     — si figure/table/annexe/planche touché (§9.10)
 * ```
 *
 * **Pourquoi la renumérotation vient en dernier** — elle doit constater l'état
 * final. La lancer avant l'édition renuméroterait un document qui va changer.
 * Le plan prévoit d'ailleurs de la déclencher « après tout tool touchant
 * figure/table/annexe/planche », ce que `requiresRenumbering()` détermine.
 */
final class EditOrchestrator
{
    /**
     * Nombre maximal de tentatives en cas de sortie invalide (§9.9).
     *
     * Deux tentatives : un modèle qui échoue deux fois sur le même contrat ne
     * réussira pas mieux à la troisième — c'est un signe que la demande est
     * mal formée, et insister ne ferait qu'accumuler des coûts (R7).
     */
    public const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly SnapshotManager $snapshots = new SnapshotManager,
        private readonly EditLock $lock = new EditLock,
        private readonly ToolOutputValidator $validator = new ToolOutputValidator,
        private readonly NumberingCoordinator $numbering = new NumberingCoordinator,
    ) {}

    /**
     * Applique une demande d'édition, avec tous les garde-fous.
     *
     * @param  StructuralDocument  $document  Document courant
     * @param  int  $documentId  Identifiant en base (pour le snapshot et le verrou)
     * @param  string  $tool  Nom du tool demandé par le modèle
     * @param  array<string, mixed>  $arguments  Arguments décodés
     * @param  string  $owner  Identifiant du processus appelant (« chat:12 »)
     * @param  bool  $confirmed  L'utilisateur a-t-il confirmé une action large ?
     * @return array{
     *     applied: bool,
     *     document: StructuralDocument,
     *     summary: string,
     *     details: array<string, mixed>,
     *     snapshot_id: null|int,
     *     renumbered: bool,
     *     confirmation_required: bool,
     *     error: null|string
     * }
     */
    public function apply(
        StructuralDocument $document,
        int $documentId,
        string $tool,
        array $arguments,
        string $owner = 'chat',
        bool $confirmed = false,
    ): array {
        // --- 1. Liste blanche -------------------------------------------------
        // Hors liste blanche : refus immédiat, sans aucune autre vérification.
        // Tester l'autorisation en premier évite d'exécuter du code (validation,
        // verrou) pour une demande qui n'aurait jamais abouti.
        if (! ToolWhitelist::allows($tool) || $tool === 'ask_user_clarification') {
            return $this->echec($tool, 'outil non autorisé pour l’édition', $document);
        }

        // --- 2. Validation des arguments --------------------------------------
        $validation = $this->validator->validateArguments($tool, $arguments);

        if (! $validation['valid']) {
            return $this->echec(
                $tool,
                'arguments inexploitables : '.implode(' ; ', $validation['errors']),
                $document
            );
        }

        // L'instanciation est faite DANS le bloc protégé plus bas : une erreur
        // d'instanciation doit produire une réponse d'échec propre, pas remonter
        // au chat comme une exception non gérée.
        $instance = null;

        // --- 3. Confirmation si l'action est large ----------------------------
        // Le comptage des blocs affectés est fait par le tool lui-même : c'est
        // la seule source fiable, puisqu'elle seule connaît l'étendue réelle de
        // l'action demandée.
        $taille = $this->affectedBlocks($tool, $document, $arguments);

        if (ToolWhitelist::requiresConfirmation($tool, $taille) && ! $confirmed) {
            return [
                'applied' => false,
                'document' => $document,
                'summary' => '',
                'details' => ['affected_blocks' => $taille],
                'snapshot_id' => null,
                'renumbered' => false,
                'confirmation_required' => true,
                'error' => null,
            ];
        }

        // --- 4. Verrou + snapshot + exécution ---------------------------------
        // Le verrou est tenu pendant TOUTE l'opération, snapshot compris : sans
        // cela, deux processus pourraient capturer le même état avant que l'un
        // n'ait écrit le sien.
        try {
            return $this->lock->withLock($documentId, $owner, function () use (
                $document,
                $documentId,
                $tool,
                $arguments,
                $taille
            ): array {
                $instance = $this->toolInstance($tool);
                $snapshotId = null;

                // --- 5. Snapshot avant action destructive (§9.7) --------------
                // Aucune action destructive ne s'exécute sans filet : si
                // l'enregistrement échoue, `SnapshotManager` lève et rien n'est
                // appliqué. C'est le critère « delete_block sans snapshot
                // préalable lève une exception ».
                if ($instance->isDestructive()) {
                    $snapshot = $this->snapshots->capture(
                        $document,
                        $tool.' sur '.$taille.' bloc(s)',
                        ['tool' => $tool, 'arguments' => $arguments, 'owner' => $documentId]
                    );

                    $snapshotId = $snapshot->id;
                }

                // --- 6. Exécution du tool -------------------------------------
                $resultat = $instance->apply($document, $arguments);
                $nouveau = $resultat['document'];

                // --- 7. Validation de la sortie (§9.9) ------------------------
                $this->validator->assertResult($nouveau, $tool);

                // --- 8. Renumérotation si nécessaire (§9.10) ------------------
                $renumerote = false;

                if ($this->requiresRenumbering($tool, $document, $nouveau)) {
                    $nouveau = $this->numbering->run($nouveau)['document'];
                    $renumerote = true;
                }

                return [
                    'applied' => true,
                    'document' => $nouveau,
                    'summary' => (string) ($resultat['summary'] ?? ''),
                    'details' => $resultat['details'] ?? [],
                    'snapshot_id' => $snapshotId,
                    'renumbered' => $renumerote,
                    'confirmation_required' => false,
                    'error' => null,
                ];
            }, $tool);
        } catch (EditingException $e) {
            // Règle métier violée (document verrouillé, bloc introuvable, sortie
            // invalide) : on renvoie le message à l'utilisateur, sans réessayer —
            // une règle métier ne se contourne pas en réessayant.
            return $this->echec($tool, $e->getMessage(), $document);
        } catch (Throwable $e) {
            Log::error('Échec inattendu d’un tool d’édition', [
                'tool' => $tool,
                'message' => $e->getMessage(),
            ]);

            return $this->echec($tool, 'échec inattendu : '.$e->getMessage(), $document);
        }
    }

    /**
     * Le tool nécessite-t-il une renumérotation après application (§9.10) ?
     *
     * La renumérotation est déclenchée si le document contient un élément
     * numéroté : supprimer un tableau décale les suivants, insérer une figure
     * en ajoute un, régénérer une section peut faire disparaître des légendes.
     *
     * On compare aussi le nombre d'éléments numérotables avant/après : un
     * changement de comptage rend la renumérotation **obligatoire**.
     */
    private function requiresRenumbering(
        string $tool,
        StructuralDocument $avant,
        StructuralDocument $apres,
    ): bool {
        // Outils qui ne peuvent pas affecter la numérotation du corps.
        if ($tool === 'rewrite_paragraph') {
            return false;
        }

        if ($this->numberableCount($avant) !== $this->numberableCount($apres)) {
            return true;
        }

        // Les légendes portent les numéros : leur retrait ou rattachement change
        // la numérotation même si le nombre d'éléments porteurs est inchangé.
        return $this->captionCount($avant) !== $this->captionCount($apres);
    }

    /**
     * Nombre d'éléments auto-numérotés (figure, tableau, annexe, planche).
     */
    private function numberableCount(StructuralDocument $document): int
    {
        $nombre = 0;

        foreach ($document->blocks as $bloc) {
            if ($bloc->type->isSelfNumbered()) {
                $nombre++;
            }
        }

        return $nombre;
    }

    /**
     * Nombre de légendes (elles portent les numéros affichés).
     */
    private function captionCount(StructuralDocument $document): int
    {
        $nombre = 0;

        foreach ($document->blocks as $bloc) {
            if ($bloc->type->value === 'caption') {
                $nombre++;
            }
        }

        return $nombre;
    }

    /**
     * Nombre de blocs qu'une action va affecter.
     *
     * Délégué au tool, mais **tolérant à l'échec** : cette valeur ne sert qu'à
     * décider d'une confirmation éventuelle. Une instanciation qui échoue ne
     * doit pas empêcher la réponse d'erreur d'être produite proprement — elle
     * doit juste ne pas déclencher de confirmation, puisque l'action n'ira pas
     * plus loin.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function affectedBlocks(string $tool, StructuralDocument $document, array $arguments): int
    {
        try {
            return $this->toolInstance($tool)->affectedBlockCount($document, $arguments);
        } catch (Throwable $e) {
            Log::warning('Comptage des blocs affectés impossible', [
                'tool' => $tool,
                'message' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Instancie le tool demandé.
     *
     * @throws EditingException Si le tool n'est pas autorisé
     */
    private function toolInstance(string $tool): EditTool
    {
        $classe = ToolWhitelist::classFor($tool);

        $instance = app()->make($classe);

        if (! $instance instanceof EditTool) {
            throw EditingException::outilNonAutorise($tool);
        }

        return $instance;
    }

    /**
     * Réponse d'échec, de forme identique à une réussite.
     *
     * La forme constante est importante : l'appelant (le chat) traite la réponse
     * sans avoir à deviner sa structure selon le cas.
     */
    private function echec(string $tool, string $message, StructuralDocument $document): array
    {
        return [
            'applied' => false,
            'document' => $document,
            'summary' => '',
            'details' => [],
            'snapshot_id' => null,
            'renumbered' => false,
            'confirmation_required' => false,
            'error' => $message,
        ];
    }

    /**
     * Annule la dernière action en restaurant l'état antérieur (§9.7).
     *
     * L'annulation restaure un **état complet**, pas une opération inversée : un
     * journal d'opérations à rejouer à l'envers se trompe dès qu'une opération
     * n'est pas parfaitement réversible. Réécrire l'état est fiable par
     * construction.
     *
     * @return null|array{document: StructuralDocument, snapshot: DocumentSnapshot}
     */
    public function undo(int $documentId, ?int $snapshotId = null): ?array
    {
        try {
            return $this->snapshots->restore($documentId, $snapshotId);
        } catch (SnapshotException $e) {
            Log::error('Restauration impossible', ['document_id' => $documentId, 'erreur' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Historique des actions annulables d'un document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function undoHistory(int $documentId, int $limit = 5): array
    {
        return array_map(
            static fn (DocumentSnapshot $snapshot): array => [
                'id' => $snapshot->id,
                'reason' => $snapshot->label(),
                'tool' => $snapshot->tool(),
                'block_count' => $snapshot->block_count,
                'created_at' => $snapshot->created_at?->toIso8601String(),
            ],
            $this->snapshots->history($documentId, $limit)
        );
    }
}
