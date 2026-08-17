<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CoverPageTemplateController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FeedbackController;

// Route de test
Route::get('/', function () {
    return response('OK', 200);
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
    Route::post('/{document}/validate', [DocumentController::class, 'validate'])->name('documents.validate');
    Route::post('/{document}/generate', [DocumentController::class, 'generate'])->name('documents.generate');
    Route::post('/{document}/generate-cover', [DocumentController::class, 'generateWithCover'])->name('documents.generate-cover');
    Route::post('/{document}/generate-cover-page', [DocumentController::class, 'generateWithCoverPageTemplate'])
        ->name('documents.generate-cover-page');
});
