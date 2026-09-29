<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\MonitoringController;
use App\Http\Controllers\Admin\PaymentLinkAdminController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TemplateAdminController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ClarificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PaymentLinkController;
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
// **Ce groupe n'est plus en lecture seule.** Il l'était, et c'était le bon défaut
// tant que le besoin d'agir n'était pas exprimé. L'exploitation demande maintenant
// de gérer les comptes, les crédits, les abonnements, les liens de paiement et la
// configuration tarifaire. Chaque route qui MODIFIE une donnée d'utilisateur est
// donc nommée explicitement par son verbe, et journalisée — voir les contrôleurs.
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    // --- Consultation ------------------------------------------------------
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::get('/billing', [AdminController::class, 'billing'])->name('billing');
    Route::get('/usage', [AdminController::class, 'usage'])->name('usage');
    Route::get('/classification', [AdminController::class, 'classification'])->name('classification');
    Route::get('/documents', [AdminController::class, 'documents'])->name('documents');

    // --- Surveillance -------------------------------------------------------
    // « Qu'est-ce qui bloque MAINTENANT » : une liste de cas concrets, là où la vue
    // d'ensemble donne des agrégats et l'analyse des tendances.
    Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring');

    // --- Analyse -----------------------------------------------------------
    // Séries temporelles : un total dit où on en est, une série dit si cela
    // s'arrête. Les deux questions sont distinctes et demandent deux écrans.
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');

    // --- Configuration -----------------------------------------------------
    // Sans elle, ajuster une marge exigeait d'éditer un fichier PHP et de
    // redéployer. C'est la route qui donne son sens à la table `settings`.
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    // --- Gestion des comptes ------------------------------------------------
    // Routes ÉCRITURE, isolées des routes de consultation ci-dessus pour que
    // l'inventaire de ce qui modifie une donnée d'utilisateur reste lisible d'un
    // coup d'œil dans ce fichier. Chaque action est journalisée par le contrôleur.
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::post('/users/{user}/admin', [UserController::class, 'toggleAdmin'])->name('users.admin');
    Route::post('/users/{user}/suspension', [UserController::class, 'toggleSuspension'])->name('users.suspension');
    Route::post('/users/{user}/credits', [UserController::class, 'adjustCredits'])->name('users.credits');

    // --- Gabarits de mise en forme ------------------------------------------
    // Routes ÉCRITURE, et c'est leur raison d'être ici : modifier un gabarit
    // change la mise en forme de TOUS les documents à venir, pour tous les
    // utilisateurs. Ce n'est pas une action d'utilisateur mais d'exploitation,
    // alors que `/templates` (public) ne fait que les consulter.
    //
    // Auparavant, ajouter ou corriger un gabarit supposait un accès à la base ou
    // un seeder : le moindre réglage passait par un accès technique.
    Route::get('/templates', [TemplateAdminController::class, 'index'])->name('templates.index');
    Route::get('/templates/create', [TemplateAdminController::class, 'create'])->name('templates.create');
    Route::post('/templates', [TemplateAdminController::class, 'store'])->name('templates.store');
    Route::get('/templates/{template}/edit', [TemplateAdminController::class, 'edit'])->name('templates.edit');
    Route::put('/templates/{template}', [TemplateAdminController::class, 'update'])->name('templates.update');
    // `delete` et non `destroy` : la route publique des documents utilise déjà
    // le verbe DELETE, et deux noms différents pour la même action compliquent
    // la lecture des journaux.
    Route::delete('/templates/{template}', [TemplateAdminController::class, 'destroy'])->name('templates.destroy');
    // Publication : action séparée. Dépublier est la réponse COURANTE à « ce
    // gabarit ne doit plus être proposé », et elle ne touche à aucun réglage —
    // la mêler au formulaire obligerait à tout réenregistrer pour un simple
    // retrait de la liste.
    Route::post('/templates/{template}/publication', [TemplateAdminController::class, 'togglePublic'])->name('templates.publication');

    // --- Liens de paiement ---------------------------------------------------
    // KPay n'expose aucun endpoint de « lien de paiement » : l'objet est une
    // construction FORMADOC. Deux modes, dont le hors ligne — indispensable quand
    // les opérateurs sont en maintenance, ce qui s'est produit plusieurs jours
    // pendant le développement de cette fonctionnalité.
    Route::get('/payment-links', [PaymentLinkAdminController::class, 'index'])->name('payment-links.index');
    Route::get('/payment-links/create', [PaymentLinkAdminController::class, 'create'])->name('payment-links.create');
    Route::post('/payment-links', [PaymentLinkAdminController::class, 'store'])->name('payment-links.store');
    Route::get('/payment-links/{paymentLink}', [PaymentLinkAdminController::class, 'show'])->name('payment-links.show');
    Route::post('/payment-links/{paymentLink}/regler', [PaymentLinkAdminController::class, 'reglerHorsLigne'])->name('payment-links.regler');
    Route::post('/payment-links/{paymentLink}/annuler', [PaymentLinkAdminController::class, 'annuler'])->name('payment-links.annuler');
    Route::post('/payment-links/{paymentLink}/prolonger', [PaymentLinkAdminController::class, 'prolonger'])->name('payment-links.prolonger');
});

/*
|--------------------------------------------------------------------------
| Page publique de règlement d'un lien de paiement
|--------------------------------------------------------------------------
|
| Pas de middleware `auth` : le destinataire d'un lien n'a pas de compte FORMADOC,
| c'est la raison d'être de la fonctionnalité. La sécurité repose sur le jeton, dont
| la longueur et l'unicité ne sont pas décoratives — un jeton deviné ouvrirait la
| page de règlement d'un tiers.
|
| `throttle` : sans limite, un jeton pourrait être cherché par force brute. 30
| requêtes par minute laisse largement de quoi afficher puis régler un lien.
*/
Route::middleware(['web', 'throttle:30,1'])->prefix('paiement')->name('payment-link.')->group(function () {
    Route::get('/{token}', [PaymentLinkController::class, 'show'])->name('show');
    Route::post('/{token}/payer', [PaymentLinkController::class, 'payer'])->name('payer');
    Route::get('/{token}/retour', [PaymentLinkController::class, 'retour'])->name('retour');

    // Notification Monetbil (équivalent du webhook KPay).
    //
    // Route SÉPARÉE du retour, et c'est nécessaire : la notification est appelée
    // serveur à serveur par Monetbil, sans session ni cookie, et doit répondre la
    // chaîne `received` en texte brut. La faire passer par `retour` (qui redirige
    // vers une page HTML) ferait considérer la notification comme non délivrée, et
    // Monetbil la réémettrait indéfiniment.
    //
    // Elle ne relève pas du `throttle:30,1` du groupe : une rafale de notifications
    // légitimes (plusieurs clients payant en même temps depuis le même opérateur)
    // serait rejetée en 429 et le paiement resterait non constaté.
    Route::post('/{token}/notify', [PaymentLinkController::class, 'notify'])
        ->name('notify')
        ->withoutMiddleware('throttle:30,1');
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
