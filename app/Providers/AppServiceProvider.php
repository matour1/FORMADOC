<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Correction pour MySQL ancien : longueur de clé maximale 1000 octets
        // utf8mb4 nécessite des clés plus courtes (191 max)
        Schema::defaultStringLength(191);

        $this->configureRateLimiters();
    }

    /**
     * P1-1 — Rate limiters applicatifs (bruteforce / spam / abus).
     *
     * Les tentatives de login/register sont aussi comptées globalement par
     * IP (en plus de l'email) : un attaquant qui distribue le brute-force sur
     * plusieurs comptes reste limité. Les clés sont basées sur l'IP + email
     * pour ne pas bloquer les utilisateurs légitimes d'un même réseau.
     */
    private function configureRateLimiters(): void
    {
        // --- Chat IA : 20 envois / minute par utilisateur (ou IP si non connecté) ---
        RateLimiter::for('chat', function (Request $request) {
            return Limit::perMinute(20)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        // --- Login : 5 tentatives / minute ---
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(
                $request->input('email').'|'.$request->ip()
            );
        });

        // --- Register : 3 tentatives / minute ---
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)->by(
                $request->input('email').'|'.$request->ip()
            );
        });

        // --- Achat de crédits : 5 requêtes / minute (évite le spam de paiement) ---
        RateLimiter::for('purchase', function (Request $request) {
            return Limit::perMinute(5)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        // --- Feedback : 3 envois / heure par IP ---
        RateLimiter::for('feedback', function (Request $request) {
            return Limit::perHour(3)->by($request->ip());
        });

        // --- Reset mot de passe : 3 emails / minute par email+IP ---
        RateLimiter::for('password_email', function (Request $request) {
            return Limit::perMinute(3)->by(
                $request->input('email').'|'.$request->ip()
            );
        });
    }
}
