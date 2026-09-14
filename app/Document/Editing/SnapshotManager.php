<?php

declare(strict_types=1);

namespace App\Document\Editing;

use App\Document\Structure\StructuralDocument;
use App\Models\DocumentEditLock;
use App\Models\DocumentSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Snapshots avant action destructive (garde-fou §9.7).
 *
 * **Règle absolue** : aucune action destructrice (suppression, réécriture de
 * plage) ne peut s'exécuter sans qu'un état antérieur soit enregistré. C'est la
 * condition qui rend le chat d'édition acceptable : l'utilisateur doit pouvoir
 * revenir en arrière après avoir demandé « supprime ce paragraphe », parce qu'un
 * modèle de langage peut très bien viser le mauvais bloc.
 *
 * Le snapshot est pris **avant** l'action, jamais après : un snapshot pris après
 * enregistrerait l'état endommagé.
 *
 * **Échec de snapshot = refus de l'action.** Si l'enregistrement échoue, on
 * lève une exception plutôt que de continuer sans filet. Un utilisateur qui perd
 * un paragraphe sans pouvoir annuler perd sa confiance dans l'outil — le risque
 * n'est pas comparable à celui d'un message d'erreur.
 */
final class SnapshotManager
{
    /**
     * Nombre maximal de snapshots conservés par document.
     *
     * Chaque snapshot contient le JSON structurel complet : sur un document de
     * 300 Ko, cent snapshots représenteraient 30 Mo par document. On conserve
     * donc l'historique récent — celui dont un utilisateur se sert réellement —
     * et on purge au-delà.
     */
    public const MAX_PER_DOCUMENT = 30;

    /**
     * Crée un snapshot de l'état courant d'un document.
     *
     * @param  StructuralDocument  $document  État AVANT l'action
     * @param  string  $reason  Motif lisible (« delete_block sur b_0042 »)
     * @param  array<string, mixed>  $metadata  Contexte de l'action (tool, arguments)
     *
     * @throws SnapshotException Si l'enregistrement échoue
     */
    public function capture(
        StructuralDocument $document,
        string $reason,
        array $metadata = [],
    ): DocumentSnapshot {
        try {
            $snapshot = DB::transaction(function () use ($document, $reason, $metadata): DocumentSnapshot {
                $snapshot = DocumentSnapshot::create([
                    'document_id' => (int) $document->documentId,
                    'block_count' => $document->count(),
                    'reason' => $reason,
                    'metadata' => $metadata,
                    'payload' => $document->toJson(\JSON_UNESCAPED_UNICODE),
                ]);

                $this->purge($document->documentId, $snapshot->id);

                return $snapshot;
            });

            return $snapshot;
        } catch (Throwable $e) {
            // On ne laisse PAS l'action se poursuivre sans filet : l'appelant
            // doit échouer explicitement plutôt que de risquer une perte.
            throw SnapshotException::enregistrementImpossible($reason, $e);
        }
    }

    /**
     * Restaure le dernier état enregistré pour un document.
     *
     * @param  int  $documentId  Document concerné
     * @param  null|int  $snapshotId  Snapshot précis (null = le plus récent)
     * @return null|array{document: StructuralDocument, snapshot: DocumentSnapshot}
     */
    public function restore(int $documentId, ?int $snapshotId = null): ?array
    {
        $snapshot = $snapshotId === null
            ? $this->latest($documentId)
            : DocumentSnapshot::where('document_id', $documentId)->find($snapshotId);

        if ($snapshot === null) {
            return null;
        }

        $document = StructuralDocument::fromJson($snapshot->payload);

        return ['document' => $document, 'snapshot' => $snapshot];
    }

    /**
     * Dernier snapshot d'un document.
     */
    public function latest(int $documentId): ?DocumentSnapshot
    {
        return DocumentSnapshot::where('document_id', $documentId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Un snapshot existe-t-il pour ce document ?
     *
     * Vérifié par `EditLock`-like guards avant toute action destructrice : c'est
     * le « garde-fou §9.7 » rendu exécutable.
     */
    public function hasSnapshot(int $documentId): bool
    {
        return DocumentSnapshot::where('document_id', $documentId)->exists();
    }

    /**
     * Historique des snapshots d'un document, du plus récent au plus ancien.
     *
     * @return array<int, DocumentSnapshot>
     */
    public function history(int $documentId, int $limit = 10): array
    {
        return DocumentSnapshot::where('document_id', $documentId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Supprime l'historique d'un document.
     *
     * @return int Nombre de snapshots supprimés
     */
    public function forget(int $documentId): int
    {
        return DocumentSnapshot::where('document_id', $documentId)->delete();
    }

    /**
     * Purge les snapshots les plus anciens au-delà de la limite.
     *
     * La borne est appliquée **après** insertion : on n'efface donc jamais le
     * snapshot qui vient d'être pris, même si la limite n'était pas respectée
     * par ailleurs.
     */
    private function purge(int|string $documentId, int $keepId): void
    {
        $trop = DocumentSnapshot::where('document_id', (int) $documentId)
            ->where('id', '!=', $keepId)
            ->orderByDesc('id')
            ->skip(self::MAX_PER_DOCUMENT - 1)
            ->take(PHP_INT_MAX)
            ->pluck('id');

        if ($trop->isEmpty()) {
            return;
        }

        DocumentSnapshot::whereIn('id', $trop->all())->delete();

        Log::info('Snapshots purgés', [
            'document_id' => $documentId,
            'supprimes' => $trop->count(),
        ]);
    }

    /**
     * Le modèle de verrou est importé ici parce que les deux garde-fous vivent
     * dans le même module : un document verrouillé doit aussi pouvoir être
     * restauré sans conflit.
     *
     * @return class-string
     */
    public function lockModel(): string
    {
        return DocumentEditLock::class;
    }
}
