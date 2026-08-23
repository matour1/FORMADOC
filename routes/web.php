<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CoverPageTemplateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\TemplateController;

// Page d'accueil (landing page)
Route::get('/', [LandingController::class, 'index']);

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
Route::resource('cover-templates', CoverPageTemplateController::class)
    ->parameters(['cover-templates' => 'coverTemplate']);

// Routes documents (upload + analyse de structure + génération DOCX)
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
