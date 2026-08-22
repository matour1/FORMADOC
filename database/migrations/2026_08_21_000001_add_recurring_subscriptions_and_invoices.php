<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — Abonnements récurrents + factures.
 *
 * Enrichit `subscriptions` pour le renouvellement automatique KPay et les
 * devises multiples, et crée la table `invoices` (facturation mensuelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Renouvellement automatique (Q1a) — le template affiche « Renouvellement automatique le … »
            $table->boolean('auto_renew')->default(true)->after('status');
            // Moyen de paiement utilisé (card / kpay / orange / mtn) — Q6a : flux KPay conservé
            $table->string('payment_method')->nullable()->after('auto_renew');
            // Référence externe KPay du paiement d'origine (idempotence)
            $table->string('external_id')->nullable()->unique()->after('payment_method');
            // Devise du paiement (XAF / EUR / USD) — Q5b
            $table->string('currency', 3)->default('XAF')->after('external_id');
            // Statut KPay du dernier paiement (pour le suivi past_due)
            $table->string('last_payment_status')->nullable()->after('currency');
            // Date du dernier renouvellement réussi
            $table->timestamp('last_renewed_at')->nullable()->after('last_payment_status');
            // Période d'essai éventuelle (fin de l'essai avant premier prélèvement)
            $table->timestamp('trial_ends_at')->nullable()->after('last_renewed_at');
            // Rétrograder vers le plan Gratuit si l'abonnement est annulé ?
            // (le template : « reste actif jusqu'à la fin de la période déjà payée, puis Gratuit »)
            $table->index(['auto_renew', 'ends_at']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            // Numéro de facture lisible : INV-2026-000042
            $table->string('number')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('subscription'); // subscription | credit_purchase
            $table->unsignedBigInteger('amount'); // Montant HT en plus petite unité
            $table->string('currency', 3)->default('XAF');
            $table->string('status')->default('paid'); // paid | pending | failed | refunded
            $table->string('payment_method')->nullable(); // card / kpay / orange / mtn
            $table->string('reference')->nullable(); // externalId / paymentId KPay
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_start')->nullable(); // Période facturée
            $table->timestamp('period_end')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'auto_renew',
                'payment_method',
                'external_id',
                'currency',
                'last_payment_status',
                'last_renewed_at',
                'trial_ends_at',
            ]);
        });
    }
};
