<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\KPayController;
use App\Http\Controllers\SubscriptionController;
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

    // Achat de crédits
    Route::post('/credits/purchase', [KPayController::class, 'initPurchase'])->name('credits.purchase');
    Route::get('/credits/return', [KPayController::class, 'return'])->name('kpay.return');
    Route::get('/credits/cancel', [KPayController::class, 'cancel'])->name('kpay.cancel');

    // Abonnements récurrents (Phase 9)
    Route::get('/checkout/{plan:slug}', [SubscriptionController::class, 'checkout'])->name('subscriptions.checkout');
    Route::post('/subscriptions/subscribe', [SubscriptionController::class, 'subscribe'])->name('subscriptions.subscribe');
    Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
    Route::post('/subscriptions/change', [SubscriptionController::class, 'change'])->name('subscriptions.change');

    // Factures
    Route::get('/invoices', [SubscriptionController::class, 'invoices'])->name('invoices.index');
    Route::get('/invoices/{invoice}/pdf', [SubscriptionController::class, 'downloadInvoice'])->name('invoices.download');

    // Chat IA
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{chatSession}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{chatSession?}', [ChatController::class, 'send'])->name('chat.send');
    Route::delete('/chat/{chatSession}', [ChatController::class, 'destroy'])->name('chat.destroy');
    // Téléchargement sécurisé des fichiers générés par le chat (P0-4)
    Route::get('/chat/files/download', [ChatController::class, 'downloadFile'])->name('chat.files.download');
});

// Webhook KPay — PAS de middleware CSRF (requête externe signée HMAC)
Route::post('/kpay/webhook', [KPayController::class, 'webhook'])
    ->name('kpay.webhook')
    ->middleware('web')
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
