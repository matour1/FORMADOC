<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_page_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->json('elements');
            $table->json('page_style')->nullable();
            $table->boolean('is_public')->default(true);
            $table->foreignId('user_id')->nullable()
                ->constrained()->onDelete('cascade');
            $table->foreignId('based_on_id')->nullable()
                ->constrained('cover_page_templates')->onDelete('set null');
            $table->timestamps();
        });

        // Lien optionnel depuis un document généré vers un modèle de page de garde
        if (!Schema::hasColumn('generated_documents', 'cover_page_template_id')) {
            Schema::table('generated_documents', function (Blueprint $table) {
                $table->foreignId('cover_page_template_id')->nullable()
                    ->after('cover_template_id')
                    ->constrained('cover_page_templates')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('generated_documents', 'cover_page_template_id')) {
            Schema::table('generated_documents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('cover_page_template_id');
            });
        }
        Schema::dropIfExists('cover_page_templates');
    }
};
