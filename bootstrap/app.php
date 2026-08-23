<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Routes SaaS (crédits, abonnements, chat IA, KPay)
            require base_path('routes/saas.php');
            // Routes d'authentification (login / register / logout)
            require base_path('routes/auth.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Webhook KPay : requête externe signée HMAC (X-KPAY-Signature),
        // exclue de la vérification CSRF.
        $middleware->validateCsrfTokens(except: [
            'kpay/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
