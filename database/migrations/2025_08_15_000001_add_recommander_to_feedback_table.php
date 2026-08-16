<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute la colonne « recommander » (question de recommandation) au feedback.
     */
    public function up(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->boolean('recommander')->nullable()->after('note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropColumn('recommander');
        });
    }
};
