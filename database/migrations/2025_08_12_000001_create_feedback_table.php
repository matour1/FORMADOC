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
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->text('avis');
            $table->integer('note')->min(1)->max(5); // Note 1-5 étoiles
            $table->text('problemes_rencontres')->nullable();
            $table->string('status')->default('new'); // new, read, closed
            $table->timestamps();
            $table->index('created_at');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
