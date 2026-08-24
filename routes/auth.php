<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes d'authentification
|--------------------------------------------------------------------------
| Chargées via bootstrap/app.php (then:) — routes/web.php reste intacte.
|
| Flux classique par session : login / register / logout.
| Le middleware 'auth' des routes SaaS (/account, /chat, crédits)
| redirige ici via config/auth.php (routes.login).
|
*/

Route::middleware(['web', 'guest'])->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.attempt');

    // Mot de passe oublié
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware(['web', 'auth']);

// Vérification d'email (non bloquant : l'utilisateur peut tout utiliser sans confirmer)
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/email/verify', [AuthController::class, 'showVerificationNotice'])->name('verification.notice');
    Route::get('/email/verify/{user}', [AuthController::class, 'verify'])->name('verification.verify');
    Route::post('/email/resend', [AuthController::class, 'resendVerification'])->name('verification.send');
});
