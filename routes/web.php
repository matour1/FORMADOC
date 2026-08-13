<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FeedbackController;

// Route de test
Route::get('/', function () {
    return response('OK', 200);
});

// Routes Feedback (collecte d'avis sans compte utilisateur)
Route::get('/feedback', [FeedbackController::class, 'showForm'])->name('feedback.form');
Route::post('/feedback', [FeedbackController::class, 'store'])->name('feedback.store');

// Routes documents (upload + analyse de structure)
Route::group(['prefix' => 'documents'], function () {
    Route::get('/upload', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('/upload', [DocumentController::class, 'upload'])->name('documents.upload');
    Route::get('/{document}', [DocumentController::class, 'show'])->name('documents.show');
});
