<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables SaaS : plans, subscriptions, credit_transactions, chat_sessions, chat_messages.
     */
    public function up(): void
    {
        // Plans d'abonnement (Standard, Premium, Pro, Entreprises)
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_fcfa')->default(0); // 0 = gratuit / sur devis
            $table->json('features')->nullable(); // Liste de fonctionnalités incluses
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Abonnements actifs par utilisateur
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('active'); // active, cancelled, expired, past_due
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable(); // null = illimité (Entreprises)
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Transactions de crédits (achats + consommations + remboursements)
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type'); // purchase, usage, refund, bonus, adjustment
            $table->bigInteger('amount'); // Négatif = débit, positif = crédit
            $table->unsignedBigInteger('balance_after')->default(0); // Solde après opération
            $table->string('currency')->default('XAF'); // 1 crédit = 1 FCFA
            $table->string('reference')->nullable(); // Référence externe (paiement, job…)
            $table->string('description')->nullable();
            $table->json('metadata')->nullable(); // user_id, purpose, payment_id, model_used…
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('type');
        });

        // Sessions de chat IA (par utilisateur)
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title')->nullable();
            $table->string('model_used')->nullable(); // Dernier modèle utilisé
            $table->unsignedBigInteger('total_cost_credits')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        // Messages d'un chat
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_session_id')->constrained()->onDelete('cascade');
            $table->string('role'); // user, assistant
            $table->text('content');
            $table->string('model_used')->nullable();
            $table->unsignedBigInteger('cost_credits')->default(0); // Coût débité pour ce message
            $table->json('metadata')->nullable(); // usage tokens, latency…
            $table->timestamps();

            $table->index(['chat_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
        Schema::dropIfExists('credit_transactions');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
