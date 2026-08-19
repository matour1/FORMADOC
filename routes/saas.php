<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\KPayController;
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

    // Achat de crédits
    Route::post('/credits/purchase', [KPayController::class, 'initPurchase'])->name('credits.purchase');
    Route::get('/credits/return', [KPayController::class, 'return'])->name('kpay.return');
    Route::get('/credits/cancel', [KPayController::class, 'cancel'])->name('kpay.cancel');

    // Chat IA
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{chatSession}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{chatSession?}', [ChatController::class, 'send'])->name('chat.send');
});

// Webhook KPay — PAS de middleware CSRF (requête externe signée HMAC)
Route::post('/kpay/webhook', [KPayController::class, 'webhook'])
    ->name('kpay.webhook')
    ->middleware('web')
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
