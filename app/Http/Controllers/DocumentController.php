<?php

namespace App\Http\Controllers;

use App\DocAnalyzer\DocAnalyzer;
use App\DocAnalyzer\DocumentReconstructor;
use App\Http\Requests\GenerateCoverRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\CoverTemplate;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\GeneratedDocument;
use App\Services\Detection\LegendDetectionService;
use App\Services\Detection\TextExtractionService;
use App\Services\DocumentGeneration\CoverDetectionService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Gestion des documents : upload, analyse de structure, affichage.
 *
 * V2 — pipeline synchrone (architecture DocAnalyzer) :
 *   upload → DocumentAnalyzer::analyze() (parse → règles déterministes → IA
 *   complémentaire si nécessaire → fusion) → détection légendes (regex)
 *   → sauvegarde de la structure JSON → affichage.
 *
 * Les règles (styles réels du document) sont prioritaires : l'IA n'intervient
 * que pour compléter les catégories critiques vides, et n'est jamais fatale.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly TextExtractionService $textExtraction,
        private readonly LegendDetectionService $legendDetection,
    ) {
    }

    /**
     * Affiche le formulaire d'upload.
     */
    public function create(): View
    {
        return view('documents.upload');
    }

    /**
     * Enregistre le document, lance la détection et affiche le résultat.
     */
    public function upload(StoreDocumentRequest $request): RedirectResponse
    {
        try {
            // Le LLM peut être lent (modèle avec raisonnement) et le timeout est
            // dynamique selon la taille du document (jusqu'à DEEPSEEK_TIMEOUT_MAX,
            // 600 s par défaut). On prolonge l'exécution PHP au-delà de la valeur
            // par défaut (120 s WAMP). En CLI (artisan serve) le serveur intégré
            // respecte cette valeur.
            if (function_exists('set_time_limit')) {
                set_time_limit((int) config('deepseek.timeout.max', 600) + 60);
            }

            $file = $request->file('document');

            // MIME réel détecté par Symfony (pas juste l'extension)
            $mimeType = $file->getMimeType();
            Log::info('Document upload reçu', [
                'filename' => $file->getClientOriginalName(),
                'mime' => $mimeType,
                'size' => $file->getSize(),
            ]);

            // Stockage hors web root
            $path = $file->store('documents', 'storage');

            $document = Document::create([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'status' => 'pending',
                'metadata' => [
                    'mime_type' => $mimeType,
                    'size' => $file->getSize(),
                ],
            ]);

            $this->runDetection($document);

            return redirect()
                ->route('documents.show', $document)
                ->with('success', 'Document analysé avec succès.');
        } catch (Exception $e) {
            Log::error('Erreur lors de l\'upload du document', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return back()
                ->withInput()
                ->withErrors(['document' => 'Erreur lors du traitement : ' . $e->getMessage()]);
        }
    }

    /**
     * Affiche le document et sa structure détectée.
     */
    public function show(Document $document): View
    {
        return view('documents.show', [
            'document' => $document,
            'structure' => $document->structure,
        ]);
    }

    /**
     * Génère (reconstruit) le DOCX à partir de la structure détectée
     * et le renvoie en téléchargement.
     *
     * Phase 2 — Génération DOCX : le document généré reprend les styles
     * natifs de titres (Heading 1-3), la numérotation romaine/arabe, le
     * sommaire, les en-têtes/pieds de page et les listes de figures/tableaux.
     */
    public function generate(Document $document): Response|RedirectResponse|BinaryFileResponse
    {
        return $this->generateAndDownload($document, null);
    }

    /**
     * Génère le DOCX reconstruit AVEC une couverture (Phase 3).
     *
     * L'étudiant fournit une couverture d'exemple (DOCX) + les valeurs à
     * substituer (nom, titre, encadrant, date). Les zones sont détectées
     * (déterministe), puis la couverture est préfixée au document reconstruit
     * en conservant la structure et les styles de l'exemple.
     */
    public function generateWithCover(GenerateCoverRequest $request, Document $document): Response|RedirectResponse|BinaryFileResponse
    {
        $cover = $this->prepareCover($request);

        return $this->generateAndDownload($document, $cover);
    }

    /**
     * Flux commun de génération + téléchargement (avec ou sans couverture).
     *
     * @param null|array<string, mixed> $cover { detection, values, cover_template_id }
     */
    private function generateAndDownload(Document $document, ?array $cover): Response|RedirectResponse|BinaryFileResponse
    {
        try {
            $structure = $document->structure?->structure;

            if (empty($structure) || empty($structure['titres'] ?? [])) {
                return back()->withErrors(['document' => 'Aucune structure détectée pour ce document.']);
            }

            if (function_exists('set_time_limit')) {
                set_time_limit((int) config('deepseek.timeout.max', 600) + 60);
            }

            $generatedPath = $this->generateOutputPath($document);

            $reconstructor = new DocumentReconstructor();
            $outputPath = $reconstructor->reconstruct($structure, $generatedPath, $cover);

            // Mémorise la génération (tableau de bord / historique)
            $generated = GeneratedDocument::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'output_path' => $outputPath,
                    'status' => 'generated',
                    'cover_values' => $cover['values'] ?? null,
                    'cover_template_id' => $cover['cover_template_id'] ?? null,
                ]
            );

            Log::info('Document généré', [
                'document_id' => $document->id,
                'output_path' => $outputPath,
                'with_cover' => $cover !== null,
            ]);

            return response()
                ->download($outputPath, $this->generatedFilename($document))
                ->deleteFileAfterSend(false);
        } catch (Exception $e) {
            Log::error('Erreur lors de la génération du document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return back()->withErrors(['document' => 'Erreur lors de la génération : ' . $e->getMessage()]);
        }
    }

    /**
     * Prépare la couverture : stocke l'exemple, détecte les zones et
     * enregistre le gabarit de couverture.
     *
     * @return array{detection: array<string, mixed>, values: array<string, string>, cover_template_id: int}
     */
    private function prepareCover(GenerateCoverRequest $request): array
    {
        $file = $request->file('cover');

        $coverPath = $file->store('cover_templates', 'storage');
        $absoluteCoverPath = storage_path('uploads/' . $coverPath);

        $detection = (new CoverDetectionService())->detect($absoluteCoverPath);

        $values = array_filter([
            'nom' => $request->input('nom'),
            'titre' => $request->input('titre'),
            'encadrant' => $request->input('encadrant'),
            'date' => $request->input('date'),
        ], static fn ($value) => is_string($value) && trim($value) !== '');

        // Gabarit de couverture : zones détectées + mapping automatique (V1)
        $coverTemplate = CoverTemplate::create([
            'name' => 'Couverture — ' . $file->getClientOriginalName(),
            'example_docx_path' => $coverPath,
            'detected_zones' => $detection['zones'],
            'zone_mapping' => $detection['zones'],
        ]);

        return [
            'detection' => $detection,
            'values' => $values,
            'cover_template_id' => $coverTemplate->id,
        ];
    }

    /**
     * Chemin de sortie du DOCX généré (dossier storage/test_scripts).
     */
    private function generateOutputPath(Document $document): string
    {
        $dir = storage_path('test_scripts');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir . '/gen_' . $document->id . '_' . date('Ymd_His') . '.docx';
    }

    /**
     * Nom de fichier proposé au téléchargement.
     */
    private function generatedFilename(Document $document): string
    {
        $base = pathinfo($document->filename, PATHINFO_FILENAME);
        return $base . '_reconstruit.docx';
    }

    /**
     * Pipeline de détection complet (DocAnalyzer → légendes → sauvegarde).
     */
    private function runDetection(Document $document): void
    {
        // 1. Chemin absolu du fichier stocké
        $absolutePath = storage_path('uploads/' . $document->path);

        // 2. Analyse structurelle : parse → règles déterministes → IA si
        //    catégorie critique vide → fusion (règles prioritaires)
        $analyzer = new DocAnalyzer(config_path('analyzer.php'));
        $analysis = $analyzer->analyze($absolutePath);

        // 3. Détection des légendes (regex — déterministe, conservée)
        $text = $this->textExtraction->execute($absolutePath);
        $legends = $this->legendDetection->execute($text);

        // 4. Sauvegarde de la structure JSON (colonne 'structure', cast array)
        DocumentStructure::updateOrCreate(
            ['document_id' => $document->id],
            [
                'structure' => [
                    // Résultat normalisé du DocAnalyzer (catégories)
                    'titres' => $analysis['titres'],
                    'sous_titres' => $analysis['sous_titres'],
                    'en_tetes' => $analysis['en_tetes'],
                    'pieds_de_page' => $analysis['pieds_de_page'],
                    'tableaux' => $analysis['tableaux'],
                    'images' => $analysis['images'],
                    'elements_flottants' => $analysis['elements_flottants'],
                    // Légendes détectées par regex (complément)
                    'legends' => $legends,
                ],
            ]
        );

        $document->update(['status' => 'detected']);
    }
}
