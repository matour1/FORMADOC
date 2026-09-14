<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index sur `reference` du registre d'usage (phase R7).
 *
 * **Pourquoi une migration séparée plutôt que modifier la création de table.**
 * La table peut déjà exister dans un environnement où la migration initiale a été
 * jouée ; `migrate` ne la rejouerait pas, et l'index manquerait alors
 * silencieusement. Une migration distincte s'applique dans tous les cas.
 *
 * **Le problème qu'il résout.** `reference` (« chat:12 », « document:34 ») est la
 * clé de rapprochement métier : c'est par elle qu'on répond à « combien ce
 * document a-t-il réellement coûté ? » lors d'un audit ou d'un litige. Le registre
 * gagne une ligne par tour HTTP, donc cette recherche devenait un balayage complet
 * de table — sur un mois d'activité, des dizaines de milliers de lignes lues pour
 * retrouver quelques dizaines.
 *
 * Les autres axes d'agrégation sont déjà indexés dans la migration de création
 * (`user_id` + `created_at`, `chat_session_id` + `created_at`, `document_id`,
 * `succeeded` + `created_at`) ; `reference` était le seul oublié.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_ledger', function (Blueprint $table) {
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_ledger', function (Blueprint $table) {
            // Nom explicite : Laravel dérive « ai_usage_ledger_reference_index »,
            // mais s'en remettre à la convention casserait le rollback si la
            // convention changeait.
            $table->dropIndex('ai_usage_ledger_reference_index');
        });
    }
};
