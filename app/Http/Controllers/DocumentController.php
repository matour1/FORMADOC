<?php

namespace App\Http\Controllers;

use App\DocAnalyzer\DocAnalyzer;
use App\DocAnalyzer\DocumentParser;
use App\DocAnalyzer\DocumentReconstructor;
use App\Document\DocumentPipeline;
use App\Document\Editing\DocumentEditingService;
use App\Http\Requests\GenerateCoverRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\ValidateStructureRequest;
use App\Jobs\LongFormattingJob;
use App\Models\CoverPageTemplate;
use App\Models\CoverTemplate;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\GeneratedDocument;
use App\Models\Template;
use App\Services\Billing\QuotaService;
use App\Services\Detection\AiCorrectionService;
use App\Services\Detection\AmbiguityDetectionService;
use App\Services\Detection\LegendDetectionService;
use App\Services\Detection\StructureCorrectionService;
use App\Services\Detection\TextExtractionService;
use App\Services\DocumentGeneration\CoverDetectionService;
use App\Services\DocumentGeneration\PdfPreviewService;
use App\Services\OpenRouter\OpenRouterService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

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
        private readonly DocumentEditingService $editing,
    ) {}

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
        $currentUserId = (int) Auth::id();

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
        // P2-1 : quotas consommés pendant ce traitement — remboursés en bloc
        // dans le catch si une exception survient (aucune perte en cas d'échec).
        $consumed = ['deterministic' => false, 'ai' => false];

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
                $consumed['deterministic'] = true;
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
                } else {
                    $consumed['ai'] = true;
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

            try {
                $this->runDetection($document, $titleMethod, $useAi);
            } catch (Throwable $e) {
                // P2-1 : échec de l'analyse → on nettoie le document créé et
                // son fichier, puis on rembourse les quotas consommés. L'exception
                // est relancée pour être loggée par le catch global.
                try {
                    Storage::disk('storage')->delete($document->path);
                    $document->delete();
                } catch (Throwable) {
                    // Best-effort : le catch global rembourse déjà les quotas
                }
                throw $e;
            }

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
            // P2-1 : une exception (stockage, analyse, IO...) ne doit jamais
            // faire perdre un quota à l'utilisateur. On rembourse les unités
            // consommées pendant ce traitement.
            if ($user = $request->user()) {
                if ($consumed['deterministic']) {
                    $this->quotas->refund($user, 'deterministic');
                }
                if ($consumed['ai']) {
                    $this->quotas->refund($user, 'ai');
                }
            }

            Log::error('Erreur lors de l\'upload du document', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return back()
                ->withInput()
                ->withErrors(['document' => 'Erreur lors du traitement : '.$e->getMessage()]);
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
            // Historique des actions annulables (R6 §9.7). Exposé ici parce que
            // c'est la page où l'utilisateur constate le résultat d'une édition :
            // proposer l'annulation ailleurs l'obligerait à chercher.
            'undoHistory' => $this->editing->undoHistory($document->id),
        ]);
    }

    /**
     * Annule la dernière modification du document (R6 §9.7).
     *
     * L'annulation restaure un **état complet** enregistré avant l'action, et non
     * une opération inversée : un journal d'opérations à rejouer à l'envers se
     * trompe dès qu'une opération n'est pas parfaitement réversible.
     */
    public function undoEdit(Request $request, Document $document): RedirectResponse
    {
        $this->authorizeDocument($document);

        $snapshotId = $request->filled('snapshot_id') ? (int) $request->input('snapshot_id') : null;

        $resultat = $this->editing->undo($document->id, $snapshotId);

        if (! $resultat['restored']) {
            return back()->with('error', $resultat['error']);
        }

        return back()->with('status', $resultat['summary']);
    }

    /**
     * Relance l'analyse du document SOURCE avec la méthode choisie.
     *
     * Permet de comparer les 3 modes d'analyse directement depuis l'onglet
     * Traitement (page de validation) sans ré-uploader le fichier :
     *   - regex       : analyse 100 % déterministe (styles Word + motifs)
     *   - ia          : lecture complète par le LLM (DeepSeek)
     *   - regex + assistée : détection regex complétée par l'IA correctrice
     *                        (post-processeur sur ambiguïtés)
     *
     * La structure précédente est conservée dans metadata['previous_structure']
     * à titre de référence (traçabilité avant/après).
     */
    public function reanalyze(Request $request, Document $document): RedirectResponse
    {
        $this->authorizeDocument($document);

        // Méthode demandée : 'regex' ou 'ia' (règles déterministes sinon)
        $titleMethod = in_array($request->input('title_method'), [
            DocAnalyzer::METHOD_REGEX,
            DocAnalyzer::METHOD_IA,
        ], true) ? $request->input('title_method') : DocAnalyzer::METHOD_REGEX;

        // Assistance IA (post-processeur correctif) — mode « assisté »
        $useAi = $request->boolean('use_ai', false);

        // Quota IA : l'assistance consomme 1 unité du quota IA, mais reste
        // OPTIONNELLE (bascule déterministe si le quota est épuisé).
        $user = $request->user();
        $aiActive = false;
        if ($useAi && $user) {
            $quota = $this->quotas->consume($user, 'ai');
            if ($quota['ok']) {
                $aiActive = true;
            } else {
                $useAi = false;
                session()->flash('warning', 'Quota IA mensuel atteint ('
                    .$quota['used'].'/'.$quota['quota'].'). '
                    .'Analyse relancée en mode déterministe.');
            }
        }

        // L'IA peut être lente : on prolonge l'exécution PHP si nécessaire.
        if (($useAi || $titleMethod === DocAnalyzer::METHOD_IA) && function_exists('set_time_limit')) {
            set_time_limit((int) config('deepseek.timeout.max', 600) + 60);
        }

        try {
            // Mémorise l'ancienne structure pour traçabilité (avant/après)
            $previous = $document->structure?->structure;
            if (! empty($previous)) {
                $document->update([
                    'metadata' => array_merge($document->metadata ?? [], [
                        'previous_structure' => $previous,
                        'previous_analysis' => [
                            'title_method' => $document->metadata['title_method'] ?? null,
                            'use_ai' => $document->metadata['use_ai'] ?? false,
                            'at' => now()->toDateTimeString(),
                        ],
                    ]),
                ]);
            }

            $this->runDetection($document, $titleMethod, $useAi);

            // Traçabilité de la méthode réellement utilisée
            $document->update([
                'metadata' => array_merge($document->metadata ?? [], [
                    'title_method' => $titleMethod,
                    'use_ai' => $useAi,
                    'reanalyzed_at' => now()->toDateTimeString(),
                ]),
            ]);

            $mode = match (true) {
                $useAi => 'regex + assistance IA',
                $titleMethod === DocAnalyzer::METHOD_IA => 'IA complète',
                default => 'regex (déterministe)',
            };

            return back()->with('success', 'Analyse relancée avec succès (mode '.$mode.'). '
                .'La structure a été mise à jour.');
        } catch (Throwable $e) {
            // P2-1 : échec → remboursement du quota IA consommé
            if ($aiActive && $user) {
                $this->quotas->refund($user, 'ai');
            }

            Log::error('Erreur lors de la réanalyse du document', [
                'document_id' => $document->id,
                'title_method' => $titleMethod,
                'use_ai' => $useAi,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return back()->withErrors(['document' => 'Erreur lors de la réanalyse : '.$e->getMessage()]);
        }
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

        $path = storage_path('uploads/'.$document->path);

        if (! is_file($path)) {
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
            'Content-Disposition' => 'inline; filename="'.$document->filename.'"',
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

        $coverTemplates = CoverPageTemplate::query()
            ->where('is_public', true)
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'elements']);

        $templates = Template::query()
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

        $template = CoverPageTemplate::find($request->integer('cover_page_template_id'));

        if (! $template) {
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
            $reconstructor = new DocumentReconstructor;
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
            $pdfPath = (new PdfPreviewService)->convertToPdf($outputPath);

            // Mémorise la génération
            GeneratedDocument::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'output_path' => $outputPath,
                    'status' => 'generated',
                    'template_id' => $template?->id,
                ]
            );

            // Stocke le chemin du PDF pour la route d'affichage (iframe).
            // Le document est « prêt » dès que l'aperçu est produit : en mode
            // sans IA (le plus courant), aucun job asynchrone ne tourne, c'est
            // ici que le document passe à l'état « Terminé ».
            $document->update([
                'status' => 'ready',
                'metadata' => array_merge($document->metadata ?? [], [
                    // Chemin relatif au dossier storage/ (indépendant des séparateurs)
                    'pdf_preview_path' => str_replace(
                        [storage_path().DIRECTORY_SEPARATOR, storage_path().'/'],
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

            return back()->withErrors(['document' => 'Erreur lors de l\'aperçu : '.$e->getMessage()]);
        }
    }

    /**
     * Sert le PDF d'aperçu en inline (affichage dans une iframe).
     */
    public function previewPdfFile(Document $document): BinaryFileResponse
    {
        $this->authorizeDocument($document);

        $pdfPath = $document->metadata['pdf_preview_path'] ?? null;

        if (! is_string($pdfPath) || $pdfPath === '') {
            abort(404, 'Aperçu non généré.');
        }

        // Chemin relatif à storage/ OU absolu (tolérance aux anciens enregistrements)
        $absolute = is_file($pdfPath) ? $pdfPath : storage_path($pdfPath);

        if (! is_file($absolute)) {
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
    private function resolveTemplate(Request $request): ?Template
    {
        $templateId = $request->integer('template_id');

        if ($templateId <= 0) {
            return null;
        }

        $template = Template::where('is_public', true)->find($templateId);

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
     * @param  null|array<string, mixed>  $cover  { detection, values, cover_template_id }
     */
    private function generateAndDownload(Document $document, ?array $cover, ?Template $template = null): Response|RedirectResponse|BinaryFileResponse
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

            $reconstructor = new DocumentReconstructor;

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

            // Le document est généré et téléchargeable → il devient « Terminé »
            // (badge « ✓ Terminé » + téléchargement sur le tableau de bord).
            // En mode sans IA, aucun job asynchrone ne passe le statut à
            // « ready » : c'est ici que la transition se produit.
            $document->update(['status' => 'ready']);

            return response()
                ->download($outputPath, $this->generatedFilename($document))
                ->deleteFileAfterSend(false);
        } catch (Exception $e) {
            Log::error('Erreur lors de la génération du document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return back()->withErrors(['document' => 'Erreur lors de la génération : '.$e->getMessage()]);
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
        $absoluteCoverPath = storage_path('uploads/'.$coverPath);

        $detection = (new CoverDetectionService)->detect($absoluteCoverPath);

        $values = array_filter([
            'nom' => $request->input('nom'),
            'titre' => $request->input('titre'),
            'encadrant' => $request->input('encadrant'),
            'date' => $request->input('date'),
        ], static fn ($value) => is_string($value) && trim($value) !== '');

        // Gabarit de couverture : zones détectées + mapping automatique (V1)
        $coverTemplate = CoverTemplate::create([
            'name' => 'Couverture — '.$file->getClientOriginalName(),
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
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir.'/gen_'.$document->id.'_'.date('Ymd_His').'.docx';
    }

    /**
     * Nom de fichier proposé au téléchargement.
     */
    private function generatedFilename(Document $document): string
    {
        $base = pathinfo($document->filename, PATHINFO_FILENAME);

        return $base.'_reconstruit.docx';
    }

    /**
     * Pipeline de détection complet (DocAnalyzer → légendes → ambiguïtés →
     * post-processeur IA facultatif → sauvegarde).
     *
     * @param  string  $titleMethod  'regex' (défaut) ou 'ia'
     * @param  bool  $useAi  Assistance IA activée explicitement par
     *                       l'utilisateur (case à cocher). Faux par défaut :
     *                       aucun appel externe n'est émis.
     */
    private function runDetection(Document $document, string $titleMethod = DocAnalyzer::METHOD_REGEX, bool $useAi = false): void
    {
        // 1. Chemin absolu du fichier stocké
        $absolutePath = storage_path('uploads/'.$document->path);

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
        $parser = new DocumentParser($absolutePath);
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
        $ambiguities = (new AmbiguityDetectionService)->detect($structure);

        // 5bis. Assistance IA FACULTATIVE (post-processeur correctif).
        //       Intervient APRÈS la détection déterministe, UNIQUEMENT si
        //       l'utilisateur a coché « Utiliser l'assistance IA ».
        //       L'IA reçoit uniquement les éléments ambigus/incertains et
        //       renvoie des corrections ciblées (kind, level) fusionnées
        //       dans la structure. En cas d'échec/timeout, elle est ignorée
        //       et la structure déterministe est conservée telle quelle.
        if ($useAi) {
            $structure = (new AiCorrectionService)->correct($structure, $ambiguities);
        }

        // 6. Sauvegarde (colonne 'structure' et 'ambiguities', casts array)
        //
        // Le nouveau pipeline documentaire (refonte) est exécuté EN PARALLÈLE
        // quand il est activé : il produit le JSON structurel commun sans rien
        // retirer à l'ancien format, qui reste la source de vérité tant que la
        // migration n'est pas validée (principe du strangleur).
        $structural = $this->buildStructuralPayload($document, $absolutePath);
        $legacyPayload = [
            'structure' => $structure,
            'ambiguities' => $ambiguities,
        ];

        DocumentStructure::updateOrCreate(
            ['document_id' => $document->id],
            $structural === null ? $legacyPayload : [...$legacyPayload, ...$structural]
        );

        $document->update(['status' => 'detected']);
    }

    /**
     * Exécute le nouveau pipeline documentaire et prépare sa persistance.
     *
     * Renvoie null quand le pipeline est désactivé, quand le format n'est pas
     * supporté, ou quand la conversion a échoué en mode `auto` — dans tous ces
     * cas l'ancien pipeline fait foi, et l'utilisateur ne voit aucune différence.
     *
     * @return null|array{structural_json: array<string, mixed>, schema_version: int, pipeline: string}
     */
    private function buildStructuralPayload(Document $document, string $absolutePath): ?array
    {
        $pipeline = app(DocumentPipeline::class);

        if (! $pipeline->isNativeEnabled()) {
            return null;
        }

        try {
            $structural = $pipeline->convert($absolutePath, (string) $document->hash_id);

            if ($structural === null) {
                return null;
            }

            return $pipeline->forPersistence($structural, DocumentPipeline::NATIVE);
        } catch (Throwable $e) {
            // En mode strict, l'échec du pipeline natif ne doit PAS empêcher le
            // document d'être traité : l'ancien pipeline a déjà réussi, et
            // perdre le document serait bien plus grave qu'un log d'erreur.
            Log::error('Pipeline natif : échec, l\'ancien pipeline fait foi', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
        if (! empty($structureData['ai_corrections'])) {
            $document->update([
                'metadata' => array_merge($document->metadata ?? [], [
                    'previous_ai_structure' => $structureData,
                ]),
            ]);
        }

        // Nouvelle analyse 100 % déterministe (regex, sans forceIA, sans use_ai)
        $absolutePath = storage_path('uploads/'.$document->path);
        $analyzer = new DocAnalyzer(config_path('analyzer.php'));
        $analysis = $analyzer->analyze($absolutePath, titleMethod: DocAnalyzer::METHOD_REGEX);

        $text = $this->textExtraction->execute($absolutePath);
        $legends = $this->legendDetection->execute($text);

        $parser = new DocumentParser($absolutePath);
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
                'ambiguities' => (new AmbiguityDetectionService)->detect($structure),
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
     * @param  array<string, mixed>  $structure
     * @return array<string, mixed> Structure complétée
     */
    private function enrichBodyImages(Document $document, array $structure): array
    {
        $bodyComplet = $structure['body_complet'] ?? null;
        if (! is_array($bodyComplet) || $bodyComplet === []) {
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
        $absolutePath = storage_path('uploads/'.$document->path);
        if (! is_file($absolutePath)) {
            return $structure;
        }

        try {
            $parser = new DocumentParser($absolutePath);
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
                        ($pos['section_index'] ?? 0).':'.($pos['element_index'] ?? 0)
                    ] = [
                        'image_data' => $element['image_data'] ?? null,
                        'image_extension' => $element['image_extension'] ?? null,
                        'image_name' => $element['image_name'] ?? null,
                    ];
                }
            }

            // Complète / convertit les éléments image du body_complet mémorisé
            foreach ($bodyComplet as &$element) {
                if (! $needsEnrichment($element)) {
                    continue;
                }

                $pos = $element['position'] ?? [];
                $key = ($pos['section_index'] ?? 0).':'.($pos['element_index'] ?? 0);

                if (! isset($imagesByPosition[$key])) {
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
        } catch (Throwable $e) {
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
        $corrected = (new StructureCorrectionService)->apply($structure->structure, $corrections);

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
