<?php

use App\Http\Middleware\AdminMiddleware;
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
        // Webhooks de passerelle : requetes EXTERNES signees, exclues du CSRF.
        //
        // **Pourquoi l'exclusion est obligatoire, et non un confort.** Le CSRF
        // protege contre un site tiers qui ferait agir le navigateur d'un
        // utilisateur connecte. Une notification de passerelle vient d'un serveur
        // a serveur : pas de session, pas de cookie, donc AUCUN jeton possible.
        // Sans exemption, Laravel repond 419 « Page Expired » et la notification
        // est perdue — le client est debite chez l'operateur et jamais credite ici.
        //
        // **Le defaut a reellement eu lieu.** `kpay/webhook` etait exempte depuis
        // l'origine, mais la route Monetbil (`paiement/{token}/notify`) avait ete
        // declaree sans exemption. Prouve en envoyant un POST reel : 419. L'enjeu
        // est concret — Monetbil documente « chaque service de paiement doit etre
        // approuve separement, cela prendra 1-2 jours » : tout un delai d'activation
        // aurait servi a un canal muet.
        //
        // **L'authenticite n'est PAS perdue pour autant.** Ces routes verifient
        // la signature de leur fournisseur (HMAC pour KPay, MD5 du secret trie
        // pour Monetbil) et, pour Monetbil, le statut est ensuite confirme a l'API.
        // Exempter du CSRF ne dispense pas de verifier qui parle.
        $middleware->validateCsrfTokens(except: [
            'kpay/webhook',
            'paiement/*/notify',
            'credits/notify',
        ]);

        // Alias de l'accès administrateur (étape 8). Le nom court `admin` est
        // utilisé dans `routes/web.php` : un alias évite d'y importer la classe,
        // et documente l'intention là où le groupe est déclaré.
        $middleware->alias([
            'admin' => AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
