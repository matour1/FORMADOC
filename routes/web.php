<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CoverPageTemplateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\SampleDocumentController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\TemplateController;

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

    // Routes documents (upload + analyse de structure + génération DOCX).
    // P0-1 (audit sécurité) : ces routes étaient HORS du groupe auth → IDOR
    // total (n'importe quel visiteur pouvait lire les documents d'autrui en
    // devinant l'ID). Elles sont désormais protégées ET vérifient
    // l'appartenance via DocumentPolicy (owns) dans le contrôleur.
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
        Route::post('/{document}/generate', [DocumentController::class, 'generate'])->name('documents.generate');
        Route::post('/{document}/generate-cover', [DocumentController::class, 'generateWithCover'])->name('documents.generate-cover');
        Route::post('/{document}/generate-cover-page', [DocumentController::class, 'generateWithCoverPageTemplate'])
            ->name('documents.generate-cover-page');
    });
});

// Routes Feedback (collecte d'avis sans compte utilisateur)
Route::get('/feedback', [FeedbackController::class, 'showForm'])->name('feedback.form');
Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');

// Routes modèles de page de garde (builder visuel)
Route::post('/cover-templates/check', [CoverPageTemplateController::class, 'exists'])
    ->name('cover-templates.check');
Route::post('/cover-templates/{coverTemplate}/preview', [CoverPageTemplateController::class, 'preview'])
    ->name('cover-templates.preview');
Route::get('/cover-templates/preview/{token}', [CoverPageTemplateController::class, 'previewFile'])
    ->name('cover-templates.preview.file');
Route::post('/cover-templates/{coverTemplate}/duplicate', [CoverPageTemplateController::class, 'duplicate'])
    ->name('cover-templates.duplicate');
// Création d'une page de garde à partir d'un exemple (détection de zones)
Route::get('/cover-templates/from-example', [CoverPageTemplateController::class, 'fromExample'])
    ->name('cover-templates.from-example');
Route::post('/cover-templates/from-example', [CoverPageTemplateController::class, 'detectExample'])
    ->name('cover-templates.detect-example');
Route::post('/cover-templates/from-example/store', [CoverPageTemplateController::class, 'storeFromExample'])
    ->name('cover-templates.store-from-example');
Route::resource('cover-templates', CoverPageTemplateController::class)
    ->parameters(['cover-templates' => 'coverTemplate']);
