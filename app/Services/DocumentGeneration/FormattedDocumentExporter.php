<?php

declare(strict_types=1);

namespace App\Services\DocumentGeneration;

use App\DocAnalyzer\DocumentReconstructor;
use App\Document\Editing\ReExportCoordinator;
use App\Document\Formatting\DocumentFormatter;
use App\Document\Numbering\NumberingCoordinator;
use App\Document\Structure\StructuralDocument;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Écrit le DOCX final depuis le modèle structurel (JSON commun).
 *
 * **Le maillon qui manquait.** Les phases R3 (mise en forme), R4 (renumérotation)
 * et R6 (ré-export) produisaient un **état en mémoire** que personne n'écrivait :
 * `DocumentController` alimentait le générateur avec l'**ancien** format
 * (`structure['titres'] + body_complet`). Le fichier livré portait donc les titres
 * détectés, mais **aucun** style de gabarit, **aucun** numéro recalculé, **aucune**
 * édition issue du chat. Ce service ferme la boucle.
 *
 * **Pourquoi ici et non dans `app/Document/`.** Le nouveau pipeline ne doit pas
 * dépendre de l'ancien (`app/DocAnalyzer`), sinon on ne pourrait plus jamais le
 * retirer. L'assemblage des deux mondes appartient à la couche application, qui a
 * le droit de les connaître tous les deux. Le sens de dépendance reste unique :
 * `Services` → `Document`, et `Services` → `DocAnalyzer`.
 *
 * **Une seule passe d'écriture.** R5 (pagination par LibreOffice) n'est pas
 * nécessaire ici : le générateur écrit un **champ TOC natif Word**
 * (`addTOC` + `setUpdateFields`), que le traitement de texte met à jour à
 * l'ouverture, et les listes de figures/tableaux sont dérivées des légendes
 * (`legends`). Ajouter une passe de pagination pour un sommaire que Word recalcule
 * lui-même ne produirait rien de plus, pour le coût d'un lancement de LibreOffice.
 * R5 reste utile là où le **service** doit fournir des numéros de page (API).
 *
 * **Défaut volontairement visible.** Si le gabarit produit des styles que le
 * générateur ne sait pas rendre, ou si une étape est impossible, on lève une
 * exception plutôt que d'écrire un fichier partiel : un document à moitié mis en
 * forme est plus trompeur qu'un échec explicite.
 */
final class FormattedDocumentExporter
{
    public function __construct(
        private readonly DocumentFormatter $formatter = new DocumentFormatter,
        private readonly NumberingCoordinator $numbering = new NumberingCoordinator,
        private readonly ReExportCoordinator $reExport = new ReExportCoordinator,
        private readonly SourceImageProvider $images = new SourceImageProvider,
        private readonly DocumentReconstructor $reconstructor = new DocumentReconstructor,
    ) {}

    /**
     * Écrit le DOCX mis en forme d'un document structurel.
     *
     * @param  StructuralDocument  $document  Document classifié (R1 → R2)
     * @param  string  $sourcePath  Chemin du `.docx` d'origine (pour les images)
     * @param  string  $outputPath  Chemin absolu du fichier à écrire
     * @param  null|array<string, mixed>  $template  Gabarit de mise en forme (`null` → défauts)
     * @return array{path: string, blocks: int, integrity: array<string, mixed>, numbering: array<string, mixed>}
     *
     * @throws RuntimeException Si un tableau est corrompu par la mise en forme,
     *                          ou si l'écriture échoue
     */
    public function export(
        StructuralDocument $document,
        string $sourcePath,
        string $outputPath,
        ?array $template = null,
    ): array {
        // --- 1. Mise en forme (R3) + renumérotation (R4) --------------------
        // On passe par `ReExportCoordinator` plutôt que d'appeler les deux
        // phases à la main : c'est lui qui détient l'ORDRE imposé par le plan
        // (gabarit → renumérotation), et le contourner ferait diverger l'export
        // de ce que le ré-export après édition produit. Un document exporté et
        // un document ré-exporté doivent être identiques à édition égale.
        $resultat = $this->reExport->reexport($document, $template);

        if ($resultat['error'] !== null) {
            throw new RuntimeException(
                'Export interrompu : '.$resultat['error']
            );
        }

        /** @var StructuralDocument $prepare */
        $prepare = $resultat['document'];

        // --- 2. Garde-fou d'intégrité ---------------------------------------
        // Contrôle AVANT écriture : une mise en forme qui a abîmé un tableau doit
        // faire échouer l'export. Écrire le fichier puis prévenir serait trop
        // tard — l'utilisateur aurait déjà téléchargé un document faux.
        $statistiques = $this->formatter->format($prepare, $template);
        $integrite = $this->formatter->verifyContentIntegrity(
            $prepare->blocks,
            $statistiques['tables'],
        );

        if (! $integrite['intact']) {
            throw new RuntimeException(
                'Export refusé : contenu de tableau modifié (blocs : '
                .implode(', ', $integrite['corrupted']).').'
            );
        }

        // --- 3. Traduction vers le format du générateur ----------------------
        // Le binaire des images n'est pas dans le modèle structurel (une image
        // pèse des centaines de Ko) : on le relit depuis la source.
        $images = $this->images->provide($sourcePath, $prepare);

        $charge = $this->formatter->buildReconstructionPayload(
            $prepare,
            $template,
            [],
            $images,
        );

        // --- 4. Écriture ------------------------------------------------------
        // Le gabarit est le 3e paramètre : le passage de `null` en 3e position
        // (reliquat du retrait de `$cover`) le faisait ignorer silencieusement,
        // car PHP accepte un argument surnuméraire sans erreur.
        try {
            $chemin = $this->reconstructor->reconstruct(
                $charge['analysis'],
                $outputPath,
                $charge['gabarit'],
            );
        } catch (Throwable $e) {
            Log::error('Export : écriture du DOCX échouée', [
                'document_id' => $prepare->documentId,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Écriture du document impossible : '.$e->getMessage(),
                0,
                $e
            );
        }

        Log::info('Export : DOCX mis en forme écrit', [
            'document_id' => $prepare->documentId,
            'blocks' => $prepare->count(),
            'images' => count($images),
            'renumbered' => $resultat['numbering']['renumbered'] ?? 0,
            'path' => basename($chemin),
        ]);

        return [
            'path' => $chemin,
            'blocks' => $prepare->count(),
            'integrity' => $integrite,
            'numbering' => $resultat['numbering'],
        ];
    }
}
