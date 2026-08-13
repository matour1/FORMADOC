<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('path'); // Stockage relatif à storage/
            $table->string('status')->default('pending'); // pending, detected, validated, generated, ready
            $table->json('metadata')->nullable(); // Infos supplémentaires
            $table->timestamps();
            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('document_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->json('structure'); // Structure détectée (titres + légendes)
            $table->json('ambiguities')->nullable(); // Cas ambigus détectés
            $table->json('validated_corrections')->nullable(); // Corrections validées par utilisateur
            $table->timestamps();
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->json('params'); // Paramètres du gabarit (police, tailles, couleurs, espacement)
            $table->boolean('is_public')->default(true); // Peut être utilisé par tous les utilisateurs
            $table->timestamps();
        });

        Schema::create('cover_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('example_docx_path'); // Chemin couverture exemple stockée
            $table->json('detected_zones')->nullable(); // Zones détectées (Nom, Titre, Date, Encadrant, etc.)
            $table->json('zone_mapping')->nullable(); // Mapping validé par utilisateur
            $table->timestamps();
        });

        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->onDelete('cascade');
            $table->foreignId('template_id')->nullable()->constrained('templates')->onDelete('set null');
            $table->foreignId('cover_template_id')->nullable()->constrained('cover_templates')->onDelete('set null');
            $table->string('output_path'); // Chemin du .docx généré
            $table->json('cover_values')->nullable(); // Valeurs saisies (Nom, Titre, Date, Encadrant)
            $table->string('status')->default('pending'); // pending, generated, ready, downloaded
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_documents');
        Schema::dropIfExists('cover_templates');
        Schema::dropIfExists('templates');
        Schema::dropIfExists('document_structures');
        Schema::dropIfExists('documents');
    }
};
