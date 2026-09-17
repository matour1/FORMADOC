<?php

use App\Http\Controllers\CoverPageTemplateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\SampleDocumentController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

// Page d'accueil (landing page)
Route::get('/', [LandingController::class, 'index']);

// Document d'exemple téléchargeable (landing page — « Voir un exemple de résultat »)
Route::get('/exemple-document.docx', [SampleDocumentController::class, 'download'])
    ->name('sample-document.download');

// Pages légales (publiques)
Route::view('/mentions-legales', 'pages.mentions-legales')->name('pages.legal');
Route::view('/conditions-generales', 'pages.cgu')->name('pages.terms');
Route::view('/politique-de-confidentialite', 'pages.confidentialite')->name('pages.privacy');

// Tableau de bord + bibliothèque (authentifié)
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/documents', [DashboardController::class, 'documents'])->name('documents.index');

    // Bibliothèque de gabarits + comparaison (Premium+)
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('/templates/compare', [TemplateController::class, 'compare'])->name('templates.compare');

    // Onboarding (parcours premiers pas)
    Route::get('/onboarding', [StateController::class, 'onboarding'])->name('onboarding');

    // Démonstrations d'états (fidélité template)
    Route::get('/demo/loading', [StateController::class, 'loading'])->name('demo.loading');
    Route::get('/demo/empty', [StateController::class, 'empty'])->name('demo.empty');
    Route::get('/demo/preview-fallback', [StateController::class, 'previewFallback'])->name('demo.preview-fallback');
    Route::get('/demo/maintenance', [StateController::class, 'maintenance'])->name('demo.maintenance');
});

// Routes Feedback (collecte d'avis sans compte utilisateur).
// P1-1 : throttle 3/h + honeypot anti-spam (P2-3).
Route::get('/feedback', [FeedbackController::class, 'showForm'])->name('feedback.form');
Route::post('/feedback', [FeedbackController::class, 'store'])
    ->name('feedback.store')
    ->middleware('throttle:feedback');

// ⚠️ MODULE « PAGE DE GARDE » RETIRÉ DE CETTE VERSION.
//
// Les routes `cover-templates.*` (builder visuel) et `documents.generate-cover*`
// ne sont plus déclarées : elles ont été retirées pour recentrer le produit sur
// la MISE EN FORME de document, qui est l'objectif de cette version.
//
// Le code correspondant (`CoverPageTemplateController`, `CoverGenerationService`,
// `CoverPageRenderer`, le modèle `CoverPageTemplate`, les vues
// `resources/views/cover-templates/**`) est CONSERVÉ sur le disque, mais
// inatteignable faute de route. Il n'est pas supprimé pour deux raisons :
//
//   1. le retour arrière reste possible sans reconstruction depuis l'historique ;
//   2. `Documents::generateAndDownload()` n'appelle plus `prepareCover()`, donc
//      aucune vue ne doit plus proposer l'option — le retrait des routes suffit
//      à garantir qu'aucun utilisateur n'y accède.
//
// Pour réactiver : redéclarer les routes et rebrancher le paramètre `$cover` de
// `generateAndDownload()`. Aucune donnée n'est perdue : les tables
// `cover_page_templates` / `cover_templates` restent intactes.

// Routes documents (upload + analyse de structure + génération DOCX).
// P0-1 (audit sécurité) : ces routes étaient HORS du groupe auth → IDOR
// total (n'importe quel visiteur pouvait lire les documents d'autrui en
// devinant l'ID). Elles sont désormais protégées ET vérifient
// l'appartenance via DocumentPolicy (owns) dans le contrôleur.
Route::middleware(['auth'])->group(function () {
    Route::group(['prefix' => 'documents'], function () {
        Route::get('/upload', [DocumentController::class, 'create'])->name('documents.create');
        Route::post('/upload', [DocumentController::class, 'upload'])->name('documents.upload');
        Route::get('/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');
        Route::get('/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::get('/{document}/processing', [DocumentController::class, 'processing'])->name('documents.processing');
        Route::get('/{document}/export', [DocumentController::class, 'export'])->name('documents.export');
        Route::post('/{document}/preview-pdf', [DocumentController::class, 'previewPdf'])->name('documents.preview-pdf');
        Route::get('/{document}/preview-pdf/file', [DocumentController::class, 'previewPdfFile'])->name('documents.preview-pdf.file');
        Route::post('/{document}/validate', [DocumentController::class, 'validate'])->name('documents.validate');
        Route::post('/{document}/reanalyze', [DocumentController::class, 'reanalyze'])->name('documents.reanalyze');
        // Annulation d'une modification faite par le chat (R6 §9.7). Reste dans
        // le groupe `auth` : l'annulation agit sur le contenu d'un document, donc
        // elle est soumise à la même vérification d'appartenance que le reste.
        Route::post('/{document}/undo-edit', [DocumentController::class, 'undoEdit'])->name('documents.undo-edit');
        Route::post('/{document}/generate', [DocumentController::class, 'generate'])->name('documents.generate');
    });
});
