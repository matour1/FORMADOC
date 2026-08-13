<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Services\Detection\LegendDetectionService;
use App\Services\Detection\TextExtractionService;
use App\Services\Detection\TitleDetectionService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Gestion des documents : upload, analyse de structure, affichage.
 *
 * V1 — pipeline synchrone :
 *   upload → extraction texte → détection titres (LLM) → détection légendes (regex)
 *   → sauvegarde de la structure → affichage.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly TextExtractionService $textExtraction,
        private readonly TitleDetectionService $titleDetection,
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
     * Pipeline de détection complet (extraction → titres → légendes → sauvegarde).
     */
    private function runDetection(Document $document): void
    {
        // 1. Extraction du texte depuis storage/uploads/
        $absolutePath = storage_path('uploads/' . $document->path);
        $text = $this->textExtraction->execute($absolutePath);

        // 2. Détection des titres (LLM — seul usage autorisé)
        $titleResult = $this->titleDetection->execute($text);

        // 3. Détection des légendes (regex — déterministe)
        $legends = $this->legendDetection->execute($text);

        // 4. Sauvegarde de la structure
        DocumentStructure::updateOrCreate(
            ['document_id' => $document->id],
            [
                'structure' => [
                    'titles' => $titleResult['markdown'],
                    'titles_raw' => $titleResult['raw'],
                    'legends' => $legends,
                ],
            ]
        );

        $document->update(['status' => 'detected']);
    }
}
