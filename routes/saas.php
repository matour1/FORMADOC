<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\KPayController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes SaaS (crédits, abonnements, chat IA, KPay)
|--------------------------------------------------------------------------
| Chargées via bootstrap/app.php (then:) — routes/web.php reste intacte.
|
*/

// Compte utilisateur (solde, transactions, abonnement)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/account', [AccountController::class, 'index'])->name('account.index');
    Route::get('/account/settings', [AccountController::class, 'settings'])->name('account.settings');
    Route::post('/account/settings/profile', [AccountController::class, 'updateProfile'])->name('account.settings.profile');
    Route::post('/account/settings/preferences', [AccountController::class, 'updatePreferences'])->name('account.settings.preferences');
    Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');

    // Achat de crédits (P1-1 : throttle anti-spam paiement)
    Route::post('/credits/purchase', [KPayController::class, 'initPurchase'])
        ->name('credits.purchase')
        ->middleware('throttle:purchase');
    Route::get('/credits/return', [KPayController::class, 'return'])->name('kpay.return');
    Route::get('/credits/cancel', [KPayController::class, 'cancel'])->name('kpay.cancel');

    // Retour Monetbil d'un achat de crédits. Route SÉPARÉE de `kpay.return` :
    // Monetbil ne signe PAS son retour, donc ses paramètres d'arrivée n'ont aucune
    // valeur probante. Le handler KPay, lui, attend un `status=COMPLETED` signé —
    // y router le retour Monetbil afficherait « paiement échoué » sur un paiement
    // en réalité réussi, et le client chercherait un problème qui n'existe pas.
    Route::get('/credits/return/monetbil', [KPayController::class, 'returnMonetbil'])->name('credits.return.monetbil');

    // Abonnements récurrents (Phase 9)
    Route::get('/checkout/{plan:slug}', [SubscriptionController::class, 'checkout'])->name('subscriptions.checkout');
    Route::post('/subscriptions/subscribe', [SubscriptionController::class, 'subscribe'])->name('subscriptions.subscribe');
    Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
    Route::post('/subscriptions/change', [SubscriptionController::class, 'change'])->name('subscriptions.change');

    // Factures
    Route::get('/invoices', [SubscriptionController::class, 'invoices'])->name('invoices.index');
    Route::get('/invoices/{invoice}/pdf', [SubscriptionController::class, 'downloadInvoice'])->name('invoices.download');

    // Chat IA (P1-1 : throttle 20/min contre le spam d'appels IA payants)
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{chatSession}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{chatSession?}', [ChatController::class, 'send'])
        ->name('chat.send')
        ->middleware('throttle:chat');
    Route::delete('/chat/{chatSession}', [ChatController::class, 'destroy'])->name('chat.destroy');
    // Téléchargement sécurisé des fichiers générés par le chat (P0-4)
    Route::get('/chat/files/download', [ChatController::class, 'downloadFile'])->name('chat.files.download');
});

// Webhook KPay — PAS de middleware CSRF (requête externe signée HMAC)
Route::post('/kpay/webhook', [KPayController::class, 'webhook'])
    ->name('kpay.webhook')
    ->middleware('web')
    ->withoutMiddleware(VerifyCsrfToken::class);

// Notification Monetbil d'un achat de crédits — PAS de middleware CSRF.
// Une notification vient d'un serveur à serveur : pas de session, pas de cookie,
// donc aucun jeton possible. Sans exemption, Laravel répond 419 et le paiement
// n'est JAMAIS constaté — le client serait débité chez l'opérateur et jamais
// crédité. La sécurité repose sur la corrélation, la signature et l'interrogation
// de l'API, pas sur le CSRF.
Route::match(['get', 'post'], '/credits/notify', [KPayController::class, 'notifyMonetbil'])
    ->name('credits.notify')
    ->middleware('web')
    ->withoutMiddleware(VerifyCsrfToken::class);
