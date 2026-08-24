<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi du rappel de renouvellement J-3 (audit copywriting §6.2.6).
 *
 * `renewal_reminded_at` évite d'envoyer plusieurs fois le même rappel
 * quand la commande subscriptions:remind-renewal tourne chaque jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('renewal_reminded_at')->nullable()->after('last_renewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('renewal_reminded_at');
        });
    }
};
