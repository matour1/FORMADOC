<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kpay_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('payment_id', 100)->nullable()->index();
            $table->string('external_id', 100)->unique();
            $table->string('status', 20)->default('PENDING')->index();
            $table->string('purpose', 30)->default('credit_purchase'); // credit_purchase | subscription | subscription_renewal
            $table->unsignedInteger('amount_fcfa')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->string('return_url', 500)->nullable();
            $table->string('cancel_url', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->integer('sync_attempts')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpay_payments');
    }
};
