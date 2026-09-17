<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\ClarificationController;
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

// Espace d'administration (étape 8).
//
// `auth` PUIS `admin` : l'ordre compte. Un invité doit être redirigé vers le
// login (réponse utile), un utilisateur connecté sans droits reçoit un 404 — et
// non un 403, qui confirmerait l'existence de l'espace.
//
// Toutes les routes sont en LECTURE SEULE : agir sur les données d'un utilisateur
// depuis l'admin exige une décision explicite, qui n'a pas été prise.
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::get('/billing', [AdminController::class, 'billing'])->name('billing');
    Route::get('/usage', [AdminController::class, 'usage'])->name('usage');
    Route::get('/classification', [AdminController::class, 'classification'])->name('classification');
    Route::get('/documents', [AdminController::class, 'documents'])->name('documents');
});

// ⚠️ MODULE « PAGE DE GARDE » SUPPRIMÉ DE CETTE VERSION.
//
// Les routes `cover-templates.*` (builder visuel) et `documents.generate-cover*`
// ne sont plus déclarées : le module a été retiré pour recentrer le produit sur
// la MISE EN FORME de document, qui est l'objectif de cette version.
//
// Le retrait est COMPLET : contrôleur, services, modèle, requêtes, vues et
// tests unitaires ont été supprimés du disque, et les vues proposaient encore
// l'option ont été réécrites. Il n'existe donc AUCUN code de page de garde
// vivant — un simple retrait de route aurait laissé des chemins atteignables
// ailleurs (voir ci-dessous).
//
// ⚠️ Leçon du retrait : la première passe avait cherché le module dans `app/`,
// `resources/` et `routes/`, mais PAS dans `app/Services/Chat/`. Trois chemins
// restaient vivants : l'outil de chat `cover_page_generate`, trois méthodes de
// contrôleur, et la logique de couverture de `DocumentReconstructor`, appelée
// par `previewPdf()`. **Après un retrait, chercher le CONCEPT dans tout `app/`,
// pas seulement à ses points d'entrée connus.**
//
// Aucune donnée n'est perdue : les tables `cover_page_templates` /
// `cover_templates` restent en base (leur migration est conservée) — les
// supprimer serait destructif et sans rapport avec le retrait du code.
//
// Pour réactiver : repartir de l'historique Git (le module y est complet), puis
// redéclarer les routes.

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

        // Clarifications (R2) : confirmer les blocs dont la classification est
        // incertaine. Dans le groupe `auth` ET avec la même vérification
        // d'appartenance que le reste : une réponse modifie le contenu du
        // document, donc un tiers ne doit pas pouvoir y accéder.
        Route::get('/{document}/clarifications', [ClarificationController::class, 'index'])
            ->name('documents.clarifications.index');
        Route::post('/{document}/clarifications', [ClarificationController::class, 'store'])
            ->name('documents.clarifications.store');
    });
});
