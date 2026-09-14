<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registre d'usage IA (phase R7).
 *
 * Une ligne = **une tentative d'appel** à un modèle, réussie ou non. C'est la
 * granularité qui permet de facturer le coût réel : les retries et les modèles
 * candidats échoués sont facturés par le fournisseur, donc ils doivent figurer au
 * registre même quand ils n'ont produit aucune réponse.
 *
 * **Pourquoi `cost_usd` en décimal et non en float** — un centième de dollar
 * arrondi à la sixième décimale suffit ; au-delà, les erreurs d'arrondi
 * s'accumulent sur des milliers de lignes et le total ne serait plus
 * recalculable depuis les données brutes.
 *
 * **Aucune suppression en cascade sur `user_id`** : un registre comptable ne
 * disparaît pas quand un compte est supprimé — sinon la comptabilité ne
 * s'équilibre plus. Le `null` sur suppression conserve la trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_ledger', function (Blueprint $table) {
            $table->id();

            // Contexte : un compte supprimé laisse ses lignes (traçabilité
            // comptable), d'où `nullOnDelete` plutôt que `cascade`.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chat_session_id')->nullable()->constrained()->nullOnDelete();

            // Document concerné, quand l'appel sert au pipeline documentaire.
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            // Fournisseur et modèle RÉELLEMENT appelés : c'est la donnée qui rend
            // un fallback détectable. Un modèle facturé différent du modèle voulu
            // est une fuite de marge invisible sans cette colonne.
            $table->string('provider')->default('openrouter');
            $table->string('model');

            // Type de tâche (chat_text, detect_blocks, image_generate…).
            $table->string('task_type')->default('');

            // Tokens réellement rapportés par l'API (jamais estimés).
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            // Numéro de la tentative : 1 = premier essai, 2+ = retry.
            $table->unsignedSmallInteger('attempt')->default(1);

            // Une tentative échouée est facturée par le fournisseur : elle doit
            // être enregistrée même sans réponse exploitable.
            $table->boolean('succeeded')->default(false);

            // Bascule de fournisseur (OpenRouter → DeepSeek direct, par exemple).
            $table->boolean('is_fallback')->default(false);
            $table->string('fallback_from')->nullable();

            // Coût calculé depuis les tokens, ou forfaitaire (image, conteneur).
            $table->boolean('estimated')->default(false);

            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('cost_credits')->default(0);

            // Référence métier (« chat:12 », « document:34 »), pour rattacher
            // une ligne à une action utilisateur.
            $table->string('reference')->nullable();

            // Message d'erreur condensé, pour diagnostiquer sans les logs.
            $table->string('error', 500)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            // Les requêtes du rapport : par utilisateur sur une période, par
            // session, et par document.
            $table->index(['user_id', 'created_at']);
            $table->index(['chat_session_id', 'created_at']);
            $table->index('document_id');
            // Détection des fuites de marge : retrouver les fallbacks et les
            // échecs facturés.
            $table->index(['succeeded', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_ledger');
    }
};
