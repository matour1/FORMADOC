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
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware(['web', 'auth']);
