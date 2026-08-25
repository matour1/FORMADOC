<?php

namespace App\Http\Controllers;

use App\DocAnalyzer\DocAnalyzer;
use App\DocAnalyzer\DocumentReconstructor;
use App\Http\Requests\GenerateCoverRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\ValidateStructureRequest;
use App\Jobs\LongFormattingJob;
use App\Models\CoverTemplate;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Billing\CreditService;
use App\Services\Billing\QuotaService;
use App\Services\Detection\AiCorrectionService;
use App\Services\Detection\AmbiguityDetectionService;
use App\Services\Detection\LegendDetectionService;
use App\Services\Detection\StructureCorrectionService;
use App\Services\Detection\TextExtractionService;
use App\Services\DocumentGeneration\CoverDetectionService;
use App\Services\OpenRouter\OpenRouterService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        private readonly QuotaService $quotas,
        private readonly OpenRouterService $openRouter,
    ) {
    }

    /**
     * Vérifie l'appartenance du document à l'utilisateur connecté (P0-1).
     *
     * Correctif IDOR : les routes documents sont désormais derrière 'auth'
     * ET chaque méthode vérifie que metadata.user_id === auth()->id().
     * En cas d'échec → 404 (on ne révèle pas l'existence du document).
     */
    private function authorizeDocument(Document $document): void
    {
        $userId = (int) ($document->metadata['user_id'] ?? 0);
        $currentUserId = (int) \Illuminate\Support\Facades\Auth::id();

        if ($userId === 0 || $userId !== $currentUserId) {
            abort(404, 'Document introuvable.');
        }
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
     *
     * Quotas mensuels (exigence D) :
     *   - chaque upload consomme 1 unité du quota DÉTERMINISTE du plan
     *     (Gratuit 5, Standard 10, Premium 30, Pro illimité) — uniquement
     *     si l'utilisateur est connecté (mode invité conservé pour l'accès
     *     public historique)
     *   - l'assistance IA consomme 1 unité du quota IA ; si épuisé, l'IA
     *     est OPTIONNELLE : bascule en mode déterministe avec un message
     *   - si l'IA est active, un LongFormattingJob (mise en forme complète
     *     asynchrone) est dispatché APRÈS la détection : il débitera les
     *     crédits estimés et passera le document en 'ready'
     */
    public function upload(StoreDocumentRequest $request): RedirectResponse
    {
        try {
            // Option « Utiliser l'assistance IA » (case à cocher explicite).
            // Le mode par défaut est SANS IA : aucun appel externe n'est émis
            // si l'utilisateur ne l'a pas activé (exigence Phase 4).
            $useAi = $request->boolean('use_ai', false);

            // Utilisateur connecté (nullable : les routes documents restent
            // publiques, le mode invité ne consomme pas de quotas)
            $user = $request->user();

            // --- Quota déterministe (1 unité par upload) ---
            if ($user) {
                $quota = $this->quotas->consume($user, 'deterministic');
                if (! $quota['ok']) {
                    return back()
                        ->withInput()
                        ->withErrors([
                            'document' => 'Quota mensuel de documents atteint ('
                                .$quota['used'].'/'.$quota['quota'].'). '
                                .'Passez à un plan supérieur ou attendez la prochaine période.',
                        ]);
                }
            }

            // --- Quota IA (uniquement si l'IA est demandée) ---
            if ($useAi && $user) {
                $aiQuota = $this->quotas->consume($user, 'ai');
                if (! $aiQuota['ok']) {
                    // L'IA est OPTIONNELLE : on bascule en mode déterministe
                    // avec un message clair (jamais bloquant)
                    $useAi = false;
                    Log::info('Upload : quota IA épuisé, bascule en mode déterministe', [
                        'user_id' => $user->id,
                        'used' => $aiQuota['used'],
                        'quota' => $aiQuota['quota'],
                    ]);
                    session()->flash('warning', 'Quota IA mensuel atteint ('
                        .$aiQuota['used'].'/'.$aiQuota['quota'].'). '
                        .'Le document a été traité en mode déterministe. '
                        .'Passez à un plan supérieur ou achetez des crédits pour réactiver l\'IA.');
                }
            }

            // Le LLM peut être lent et le timeout est dynamique selon la taille
            // du document. On ne prolonge l'exécution PHP QUE si l'IA est
            // activée : en mode déterministe, la limite par défaut suffit.
            if ($useAi && function_exists('set_time_limit')) {
                set_time_limit((int) config('deepseek.timeout.max', 600) + 60);
            }

            $file = $request->file('document');

            // MIME réel détecté par Symfony (pas juste l'extension)
            $mimeType = $file->getMimeType();
            Log::info('Document upload reçu', [
                'filename' => $file->getClientOriginalName(),
                'mime' => $mimeType,
                'size' => $file->getSize(),
                'use_ai' => $useAi,
                'user_id' => $user?->id,
            ]);

            // Stockage hors web root
            $path = $file->store('documents', 'storage');

            // Méthode de détection des titres (regex par défaut, sans IA)
            $titleMethod = $request->input('title_method', DocAnalyzer::METHOD_REGEX);

            $document = Document::create([
                'filename' => $file->getClientOriginalName(),
                'path' => $path,
                'status' => 'pending',
                'metadata' => [
                    'mime_type' => $mimeType,
                    'size' => $file->getSize(),
                    'title_method' => $titleMethod,
                    'use_ai' => $useAi,
                    'user_id' => $user?->id,
                ],
            ]);

            $this->runDetection($document, $titleMethod, $useAi);

            // --- Mise en forme complète asynchrone (LongFormattingJob) ---
            // Le job exige un utilisateur (débit de crédits). Il est dispatché
            // APRÈS la détection : la structure est déjà disponible.
            if ($useAi && $user) {
                $estimate = $this->openRouter->estimateCost(
                    'document_full_format',
                    max(500, (int) ceil($file->getSize() / 4)),
                    1500,
                    $user->currentPlanSlug(),
                );
                $estimatedCredits = max(1, $estimate['credits']);

                if (! $user->hasCredits($estimatedCredits)) {
                    Log::warning('Upload : crédits insuffisants pour le job de formatage IA', [
                        'user_id' => $user->id,
                        'required' => $estimatedCredits,
                    ]);
                    session()->flash('warning', 'Crédits insuffisants ('.$estimatedCredits
                        .' requis) pour la mise en forme IA complète. '
                        .'Le document a été analysé ; ajoutez des crédits depuis votre compte puis '
                        .'relancez la génération.');
                } else {
                    LongFormattingJob::dispatch($document, $estimatedCredits, $user->id);

                    return redirect()
                        ->route('documents.show', $document)
                        ->with('success', 'Document analysé avec succès. La mise en forme IA complète '
                            .'est en cours de traitement (~'.$estimatedCredits.' crédits estimés).');
                }
            }

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
     * Affiche le document et sa structure détectée (étape 2 — validation).
     */
    public function show(Document $document): View
    {
        $this->authorizeDocument($document);

        return view('documents.show', [
            'document' => $document,
            'structure' => $document->structure,
        ]);
    }

    /**
     * Aperçu du fichier uploadé (étape 1 — après sélection).
     *
     * Renvoie le fichier en inline (Content-Disposition) pour que le
     * navigateur puisse l'afficher, ou un extrait de texte pour les .txt.
     */
    public function preview(Document $document): Response|BinaryFileResponse
    {
        $this->authorizeDocument($document);

        $path = storage_path('uploads/' . $document->path);

        if (!is_file($path)) {
            abort(404, 'Fichier introuvable.');
        }

        $mime = $document->metadata['mime_type'] ?? mime_content_type($path);

        // TXT : renvoie le contenu brut (affichage direct dans l'aperçu)
        if (strtolower(pathinfo($document->path, PATHINFO_EXTENSION)) === 'txt') {
            return response(file_get_contents($path), 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // DOCX/DOC : renvoie le fichier en inline (aperçu navigateur natif)
        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $document->filename . '"',
        ]);
    }

    /**
     * Écran de traitement (étape 3) — transition visuelle avant l'export.
     *
     * La génération du DOCX reste déclenchée depuis la page d'export afin de
     * conserver un flux déterministe : cette page redirige le navigateur vers
     * l'export une fois l'animation terminée.
     */
    public function processing(Document $document): View
    {
        $this->authorizeDocument($document);

        return view('documents.processing', [
            'document' => $document,
        ]);
    }

    /**
     * Page d'export (étape 4) — récapitulatif + téléchargement du DOCX.
     *
     * L'utilisateur choisit un gabarit de mise en forme (parmi les gabarits
     * prédéfinis) puis peut générer un aperçu PDF ou télécharger le DOCX.
     * Le changement de gabarit ne nécessite pas de ré-uploader le fichier.
     */
    public function export(Document $document): View
    {
        $this->authorizeDocument($document);

        $coverTemplates = \App\Models\CoverPageTemplate::query()
            ->where('is_public', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'elements']);

        $templates = \App\Models\Template::query()
            ->where('is_public', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'params']);

        return view('documents.export', [
            'document' => $document,
            'structure' => $document->structure,
            'coverTemplates' => $coverTemplates,
            'templates' => $templates,
        ]);
    }

    /**
     * Génère le DOCX reconstruit AVEC une page de garde issue du builder
     * visuel (Phase 6).
     *
     * L'utilisateur sélectionne un modèle + fournit les valeurs des
     * placeholders. La page de garde est rendue (ghost-table) puis préfixée
     * au document reconstruit.
     */
    public function generateWithCoverPageTemplate(Request $request, Document $document): Response|RedirectResponse|BinaryFileResponse
    {
        $this->authorizeDocument($document);

        $template = \App\Models\CoverPageTemplate::find($request->integer('cover_page_template_id'));

        if (!$template) {
            return back()->withErrors(['cover_page_template_id' => 'Modèle de page de garde introuvable.']);
        }

        return $this->generateAndDownload($document, [
            'cover_page_template' => $template,
            'values' => (array) $request->input('values', []),
            'cover_page_template_id' => $template->id,
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
    public function generate(Request $request, Document $document): Response|RedirectResponse|BinaryFileResponse
    {
        $this->authorizeDocument($document);

        $template = $this->resolveTemplate($request);

        return $this->generateAndDownload($document, null, $template);
    }

    /**
     * Génère le DOCX reconstruit et renvoie son aperçu PDF (LibreOffice).
     *
     * L'utilisateur voit le rendu réel avant de télécharger. Le gabarit
     * choisi est appliqué ; il peut être changé sans ré-uploader le fichier.
     */
    public function previewPdf(Request $request, Document $document): RedirectResponse
    {
        $this->authorizeDocument($document);

        try {
            $template = $this->resolveTemplate($request);

            // Comparaison « sans IA » : si l'utilisateur le demande, on
            // ré-analyse le document SOURCE en mode 100 % déterministe
            // (aucun appel externe) puis on régénère l'aperçu. La structure
            // IA d'origine est conservée dans metadata pour référence.
            if ($request->boolean('regenerate_without_ai', false)) {
                $this->regenerateWithoutAi($document);
            }

            if (function_exists('set_time_limit')) {
                // Reconstruction DOCX + conversion PDF : purement local, sans IA.
                set_time_limit(240);
            }

            $generatedPath = $this->generateOutputPath($document);
            $reconstructor = new DocumentReconstructor();
            $structure = $document->structure?->structure;

            if (empty($structure)) {
                return back()->withErrors(['document' => 'Aucune structure détectée pour ce document.']);
            }

            // Phase 3 : complète les images du body_complet par leur binaire
            // (extrait depuis l'archive DOCX source — voir DocumentParser::readImageData).
            // Sans cela les images sont perdues à la reconstruction (défaut 3).
            $structure = $this->enrichBodyImages($document, $structure);

            $outputPath = $reconstructor->reconstruct($structure, $generatedPath, null, $template?->params);

            // Conversion PDF (LibreOffice) pour l'aperçu
            $pdfPath = (new \App\Services\DocumentGeneration\PdfPreviewService())->convertToPdf($outputPath);

            // Mémorise la génération
            GeneratedDocument::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'output_path' => $outputPath,
                    'status' => 'generated',
                    'template_id' => $template?->id,
                ]
            );

            // Stocke le chemin du PDF pour la route d'affichage (iframe)
            $document->update([
                'metadata' => array_merge($document->metadata ?? [], [
                    // Chemin relatif au dossier storage/ (indépendant des séparateurs)
                    'pdf_preview_path' => str_replace(
                        [storage_path() . DIRECTORY_SEPARATOR, storage_path() . '/'],
                        '',
                        $pdfPath
                    ),
                    'preview_template_id' => $template?->id,
                ]),
            ]);

            return back()->with('success', 'Aperçu PDF généré.');
        } catch (Exception $e) {
            Log::error('Erreur lors de la génération de l\'aperçu PDF', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return back()->withErrors(['document' => 'Erreur lors de l\'aperçu : ' . $e->getMessage()]);
        }
    }

    /**
     * Sert le PDF d'aperçu en inline (affichage dans une iframe).
     */
    public function previewPdfFile(Document $document): BinaryFileResponse
    {
        $this->authorizeDocument($document);

        $pdfPath = $document->metadata['pdf_preview_path'] ?? null;

        if (!is_string($pdfPath) || $pdfPath === '') {
            abort(404, 'Aperçu non généré.');
        }

        // Chemin relatif à storage/ OU absolu (tolérance aux anciens enregistrements)
        $absolute = is_file($pdfPath) ? $pdfPath : storage_path($pdfPath);

        if (!is_file($absolute)) {
            abort(404, 'Fichier PDF introuvable.');
        }

        return response()->file($absolute, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="apercu.pdf"',
        ]);
    }

    /**
     * Résout le gabarit de mise en forme depuis la requête (template_id).
     */
    private function resolveTemplate(Request $request): ?\App\Models\Template
    {
        $templateId = $request->integer('template_id');

        if ($templateId <= 0) {
            return null;
        }

        $template = \App\Models\Template::where('is_public', true)->find($templateId);

        return $template ?: null;
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
        $this->authorizeDocument($document);

        $cover = $this->prepareCover($request);

        return $this->generateAndDownload($document, $cover);
    }

    /**
     * Flux commun de génération + téléchargement (avec ou sans couverture).
     *
     * @param null|array<string, mixed> $cover { detection, values, cover_template_id }
     */
    private function generateAndDownload(Document $document, ?array $cover, ?\App\Models\Template $template = null): Response|RedirectResponse|BinaryFileResponse
    {
        try {
            $structure = $document->structure?->structure;

            if (empty($structure) || empty($structure['titres'] ?? [])) {
                return back()->withErrors(['document' => 'Aucune structure détectée pour ce document.']);
            }

            if (function_exists('set_time_limit')) {
                // Reconstruction DOCX : local (PhpWord), sans appel IA.
                set_time_limit(240);
            }

            $generatedPath = $this->generateOutputPath($document);

            $reconstructor = new DocumentReconstructor();

            // Phase 3 : mêmes complétions d'images que previewPdf (binaire
            // extrait depuis le DOCX source — défaut 3).
            $structure = $this->enrichBodyImages($document, $structure);

            $outputPath = $reconstructor->reconstruct($structure, $generatedPath, $cover, $template?->params);

            // Mémorise la génération (tableau de bord / historique)
            $generated = GeneratedDocument::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'output_path' => $outputPath,
                    'status' => 'generated',
                    'cover_values' => $cover['values'] ?? null,
                    'cover_template_id' => $cover['cover_template_id'] ?? null,
                    'cover_page_template_id' => $cover['cover_page_template_id'] ?? null,
                    'template_id' => $template?->id,
                ]
            );

            Log::info('Document généré', [
                'document_id' => $document->id,
                'output_path' => $outputPath,
                'with_cover' => $cover !== null,
                'template_id' => $template?->id,
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
     * Pipeline de détection complet (DocAnalyzer → légendes → ambiguïtés →
     * post-processeur IA facultatif → sauvegarde).
     *
     * @param string $titleMethod 'regex' (défaut) ou 'ia'
     * @param bool   $useAi       Assistance IA activée explicitement par
     *                            l'utilisateur (case à cocher). Faux par défaut :
     *                            aucun appel externe n'est émis.
     */
    private function runDetection(Document $document, string $titleMethod = DocAnalyzer::METHOD_REGEX, bool $useAi = false): void
    {
        // 1. Chemin absolu du fichier stocké
        $absolutePath = storage_path('uploads/' . $document->path);

        // 2. Analyse structurelle : parse → règles déterministes → regex
        //    (si demandé) → IA (UNIQUEMENT si title_method='ia') → fusion
        $analyzer = new DocAnalyzer(config_path('analyzer.php'));
        $analysis = $analyzer->analyze($absolutePath, titleMethod: $titleMethod);

        // 3. Détection des légendes (regex — déterministe, conservée)
        $text = $this->textExtraction->execute($absolutePath);
        $legends = $this->legendDetection->execute($text);

        // 3bis. Body complet (paragraphes, listes, tableaux, images) : re-parse
        //       pour conserver TOUS les éléments avec leurs styles. C'est la
        //       source de vérité de la reconstruction (aucune perte de contenu).
        $parser = new \App\DocAnalyzer\DocumentParser($absolutePath);
        $parsed = $parser->parse();
        $bodyComplet = [];
        foreach (($parsed['sections'] ?? []) as $sectionIndex => $section) {
            foreach (($section['body'] ?? []) as $element) {
                $bodyComplet[] = $element;
            }
        }

        // 4. Assemblage de la structure normalisée
        $structure = [
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
            // Contenu complet du corps : paragraphes, listes, tableaux, images
            // dans l'ordre d'apparition (reconstruction fidèle).
            'body_complet' => $bodyComplet,
        ];

        // 5. Détection des ambiguïtés (déterministe — numérotation vs niveau)
        $ambiguities = (new AmbiguityDetectionService())->detect($structure);

        // 5bis. Assistance IA FACULTATIVE (post-processeur correctif).
        //       Intervient APRÈS la détection déterministe, UNIQUEMENT si
        //       l'utilisateur a coché « Utiliser l'assistance IA ».
        //       L'IA reçoit uniquement les éléments ambigus/incertains et
        //       renvoie des corrections ciblées (kind, level) fusionnées
        //       dans la structure. En cas d'échec/timeout, elle est ignorée
        //       et la structure déterministe est conservée telle quelle.
        if ($useAi) {
            $structure = (new AiCorrectionService())->correct($structure, $ambiguities);
        }

        // 6. Sauvegarde (colonne 'structure' et 'ambiguities', casts array)
        DocumentStructure::updateOrCreate(
            ['document_id' => $document->id],
            [
                'structure' => $structure,
                'ambiguities' => $ambiguities,
            ]
        );

        $document->update(['status' => 'detected']);
    }

    /**
     * Régénère la structure du document en mode 100 % déterministe (SANS IA).
     *
     * Permet à l'utilisateur de comparer le rendu avec et sans assistance IA
     * (exigence Phase 4). La structure IA d'origine est mémorisée dans
     * metadata['previous_ai_structure'] (référence), la structure courante
     * est remplacée par la version déterministe pure.
     */
    private function regenerateWithoutAi(Document $document): void
    {
        $current = $document->structure;

        // Mémorise la version IA si elle a été produite (trace)
        $structureData = $current?->structure ?? [];
        if (!empty($structureData['ai_corrections'])) {
            $document->update([
                'metadata' => array_merge($document->metadata ?? [], [
                    'previous_ai_structure' => $structureData,
                ]),
            ]);
        }

        // Nouvelle analyse 100 % déterministe (regex, sans forceIA, sans use_ai)
        $absolutePath = storage_path('uploads/' . $document->path);
        $analyzer = new DocAnalyzer(config_path('analyzer.php'));
        $analysis = $analyzer->analyze($absolutePath, titleMethod: DocAnalyzer::METHOD_REGEX);

        $text = $this->textExtraction->execute($absolutePath);
        $legends = $this->legendDetection->execute($text);

        $parser = new \App\DocAnalyzer\DocumentParser($absolutePath);
        $parsed = $parser->parse();
        $bodyComplet = [];
        foreach (($parsed['sections'] ?? []) as $section) {
            foreach (($section['body'] ?? []) as $element) {
                $bodyComplet[] = $element;
            }
        }

        $structure = [
            'titres' => $analysis['titres'],
            'sous_titres' => $analysis['sous_titres'],
            'en_tetes' => $analysis['en_tetes'],
            'pieds_de_page' => $analysis['pieds_de_page'],
            'tableaux' => $analysis['tableaux'],
            'images' => $analysis['images'],
            'elements_flottants' => $analysis['elements_flottants'],
            'legends' => $legends,
            'body_complet' => $bodyComplet,
        ];

        // Marque la régénération (sans IA) pour traçabilité
        $structure['ai_corrections'] = [];

        DocumentStructure::updateOrCreate(
            ['document_id' => $document->id],
            [
                'structure' => $structure,
                'ambiguities' => (new AmbiguityDetectionService())->detect($structure),
            ]
        );

        $document->update([
            'status' => 'detected',
            'metadata' => array_merge($document->metadata ?? [], [
                'regenerated_without_ai' => true,
            ]),
        ]);
    }

    /**
     * Complète les images du body_complet par leur binaire (base64) et leur
     * extension, extraits depuis le DOCX source.
     *
     * Phase 3 — défaut 3 : la structure sauvegardée au moment de l'analyse
     * ne contient pas les binaires d'images (le Reader PhpWord renvoie une
     * source "zip://doc.docx#word/media/x.png" non lisible par is_file()).
     * Cette méthode re-parse le DOCX source et recopie pour chaque élément
     * image du body_complet les données extraites par DocumentParser.
     *
     * @param Document              $document
     * @param array<string, mixed>  $structure
     *
     * @return array<string, mixed> Structure complétée
     */
    private function enrichBodyImages(Document $document, array $structure): array
    {
        $bodyComplet = $structure['body_complet'] ?? null;
        if (!is_array($bodyComplet) || $bodyComplet === []) {
            return $structure;
        }

        // Un élément est "image à compléter" s'il est :
        //  - type 'image' sans binaire (parse récent, enrichissement partiel),
        //  - type 'texte' avec placeholder "[image:xxx.ext]" (structure
        //    sauvegardée AVANT la Phase 3 : le Reader PhpWord encapsulait
        //    les images dans des TextRun et le parser les classait 'texte').
        $needsEnrichment = static function (array $element): bool {
            if (($element['type'] ?? '') === 'image') {
                return empty($element['image_data'] ?? '');
            }

            return ($element['type'] ?? '') === 'texte'
                && preg_match('/^\[image:[^\]]+\]$/u', trim((string) ($element['text'] ?? ''))) === 1;
        };

        // Document déjà enrichi (mémorisé) : aucun travail à refaire
        $hasAll = true;
        foreach ($bodyComplet as $element) {
            if ($needsEnrichment($element)) {
                $hasAll = false;

                break;
            }
        }
        if ($hasAll) {
            return $structure;
        }

        // Re-parse du DOCX source pour extraire les binaires d'images
        $absolutePath = storage_path('uploads/' . $document->path);
        if (!is_file($absolutePath)) {
            return $structure;
        }

        try {
            $parser = new \App\DocAnalyzer\DocumentParser($absolutePath);
            $parsed = $parser->parse();

            // Map position → données d'image (depuis le parse frais)
            $imagesByPosition = [];
            foreach (($parsed['sections'] ?? []) as $sectionIndex => $section) {
                foreach (($section['body'] ?? []) as $element) {
                    if (($element['type'] ?? '') !== 'image') {
                        continue;
                    }

                    $pos = $element['position'] ?? [];
                    $imagesByPosition[
                        ($pos['section_index'] ?? 0) . ':' . ($pos['element_index'] ?? 0)
                    ] = [
                        'image_data' => $element['image_data'] ?? null,
                        'image_extension' => $element['image_extension'] ?? null,
                        'image_name' => $element['image_name'] ?? null,
                    ];
                }
            }

            // Complète / convertit les éléments image du body_complet mémorisé
            foreach ($bodyComplet as &$element) {
                if (!$needsEnrichment($element)) {
                    continue;
                }

                $pos = $element['position'] ?? [];
                $key = ($pos['section_index'] ?? 0) . ':' . ($pos['element_index'] ?? 0);

                if (!isset($imagesByPosition[$key])) {
                    continue;
                }

                // Placeholder "[image:...]" en type 'texte' (anciennes
                // structures) : on le re-typifie en 'image' pour que le
                // reconstructeur l'embarque réellement dans le DOCX.
                if (($element['type'] ?? '') === 'texte') {
                    $element['type'] = 'image';
                }

                foreach ($imagesByPosition[$key] as $k => $v) {
                    if (($element[$k] ?? null) === null || $element[$k] === '') {
                        $element[$k] = $v;
                    }
                }
            }
            unset($element);

            $structure['body_complet'] = $bodyComplet;
        } catch (\Throwable $e) {
            Log::warning('enrichBodyImages : re-parse impossible, images non complétées', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $structure;
    }

    /**
     * Enregistre la validation utilisateur de la structure (Phase 4).
     *
     * Les corrections validées sont appliquées à la structure, mémorisées
     * dans 'validated_corrections', puis les ambiguïtés sont purgées et le
     * statut passe à « validated ».
     */
    public function validate(ValidateStructureRequest $request, Document $document): RedirectResponse
    {
        $this->authorizeDocument($document);

        $structure = $document->structure;

        if ($structure === null) {
            return back()->withErrors(['document' => 'Aucune structure détectée pour ce document.']);
        }

        $corrections = $request->validated('corrections') ?? [];
        $corrected = (new StructureCorrectionService())->apply($structure->structure, $corrections);

        $structure->update([
            'structure' => $corrected,
            'validated_corrections' => $corrections,
            'ambiguities' => [],
        ]);

        $document->update(['status' => 'validated']);

        return redirect()
            ->route('documents.processing', $document)
            ->with('success', 'Structure validée. Lancement du traitement…');
    }
}
