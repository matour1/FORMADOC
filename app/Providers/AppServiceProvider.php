<?php

namespace App\Providers;

use App\Document\Adapters\DocxNativeAdapter;
use App\Document\DocumentPipeline;
use App\Document\Editing\DocumentEditingService;
use App\Document\Structure\LegacyStructureBridge;
use App\Services\Anthropic\ClaudeSkillsService;
use App\Services\Chat\ChatToolsService;
use App\Services\Chat\DocumentEditService;
use App\Services\Detection\TextExtractionService;
use App\Services\DocumentGeneration\CoverGenerationService;
use App\Services\DocumentGeneration\CoverPageRenderer;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Orchestrateur de la coexistence entre l'ancien pipeline documentaire
        // (`app/DocAnalyzer`) et celui de la refonte (`app/Document`). Enregistré
        // en singleton : il ne porte aucun état, mais éviter de reconstruire le
        // parseur à chaque injection est utile sur les traitements en boucle.
        $this->app->singleton(DocumentPipeline::class, function (): DocumentPipeline {
            return new DocumentPipeline(
                new DocxNativeAdapter,
                new LegacyStructureBridge,
            );
        });

        // Éditeur structurel : il porte les snapshots et le verrou d'édition, et
        // doit donc être un singleton.
        $this->app->singleton(DocumentEditingService::class);

        // ChatToolsService est lié explicitement pour une raison précise : ses
        // derniers paramètres sont optionnels (édition structurelle, Skills
        // Claude, extraction de texte). Or l'injection automatique de Laravel
        // **renseigne les paramètres optionnels à `null`** au lieu de les
        // résoudre. Les tools d'édition répondraient donc « non disponible » en
        // production, sans qu'aucune erreur ne le signale — un test de câblage
        // a mis ce défaut en évidence.
        $this->app->singleton(ChatToolsService::class, function ($app): ChatToolsService {
            return new ChatToolsService(
                $app->make(OpenRouterService::class),
                $app->make(CoverGenerationService::class),
                $app->make(CoverPageRenderer::class),
                $app->make(DocumentEditService::class),
                $app->make(ClaudeSkillsService::class),
                $app->make(TextExtractionService::class),
                $app->make(DocumentEditingService::class),
            );
        });
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
