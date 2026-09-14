<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des clarifications demandées à l'utilisateur.
 *
 * Une ligne = une question ciblée sur UN bloc ambigu. La réponse est
 * réinjectée dans le contexte de l'agent et applique la correction sur ce
 * seul `block_id` (§8 de la refonte).
 *
 * La table est volontairement indépendante de `document_structures` : une
 * clarification survit à une régénération de structure, et son historique
 * permet de comprendre pourquoi un bloc a été classé d'une certaine façon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_clarifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained()->onDelete('cascade');

            // Identifiant du bloc concerné (b_0042) — jamais le document entier.
            $table->string('block_id');

            // Question posée et type de saisie attendu.
            $table->text('question');
            $table->string('input_type')->default('single_select');

            // Options proposées (JSON) : vide pour une saisie libre.
            $table->json('options')->nullable();

            // Extrait du texte concerné : permet de réafficher la question même
            // si la structure est régénérée plus tard.
            $table->text('excerpt')->nullable();

            // Confiance déterministe au moment de la question : sert à mesurer
            // l'efficacité du pré-filtrage (combien de blocs réellement ambigus).
            $table->decimal('confidence', 4, 3)->nullable();

            // Raison de l'incertitude (« Contradiction : style 1, numérotation 2 »).
            $table->string('reason')->nullable();

            // --- Réponse de l'utilisateur ---
            $table->string('answer_type')->nullable();   // type retenu (heading_2…)
            $table->string('answer_raw')->nullable();    // option choisie telle quelle
            $table->timestamp('answered_at')->nullable();

            $table->timestamps();

            // Une seule question EN ATTENTE par bloc : une nouvelle analyse ne
            // doit pas empiler des doublons sur le même bloc.
            $table->unique(['document_id', 'block_id']);

            // Recherche des questions en attente pour un document.
            $table->index(['document_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_clarifications');
    }
};
