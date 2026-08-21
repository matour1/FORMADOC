<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quotas mensuels par plan + compteurs d'usage par utilisateur.
     *
     * Exigence D du cahier des charges :
     *   - Gratuit   : 0 FCFA  /  5 docs déterministes /  0 IA
     *   - Standard  : 3 000   / 10 docs déterministes /  5 IA
     *   - Premium   : 5 000   / 30 docs déterministes / 15 IA
     *   - Pro       : 13 500  / illimité (null)       / 90 IA
     *
     * Règles :
     *   - quota null = illimité
     *   - quota_period = 'monthly' → réinitialisation automatique des compteurs
     *     à chaque nouveau mois (voir QuotaService::resetIfNeeded).
     *   - l'IA est optionnelle : le mode déterministe reste toujours disponible,
     *     même quand le quota IA est épuisé.
     */
    public function up(): void
    {
        // Quotas par plan
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('quota_deterministic')->nullable()->after('price_fcfa');
            $table->unsignedInteger('quota_ai')->nullable()->after('quota_deterministic');
            $table->string('quota_period')->default('monthly')->after('quota_ai');
        });

        // Compteurs d'usage mensuel par utilisateur
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('usage_deterministic_month')->default(0)->after('credits_balance');
            $table->unsignedInteger('usage_ai_month')->default(0)->after('usage_deterministic_month');
            $table->string('usage_month')->nullable()->after('usage_ai_month'); // 'YYYY-MM'
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['quota_deterministic', 'quota_ai', 'quota_period']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['usage_deterministic_month', 'usage_ai_month', 'usage_month']);
        });
    }
};
