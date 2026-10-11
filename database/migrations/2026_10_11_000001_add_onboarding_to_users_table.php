<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * État d'avancement du parcours d'accueil (onboarding).
 *
 * **Pourquoi persister l'étape, et non la garder en session.** Un parcours dont
 * la progression vit dans la session repart à zéro dès qu'elle expire ou que
 * l'utilisateur change d'appareil : il répond aux mêmes questions deux fois, ou
 * découvre un parcours « terminé » qu'il n'a jamais vu. L'étape appartient au
 * compte, pas à la visite.
 *
 * Deux colonnes suffisent :
 *  - `onboarding_step` : étape COURANTE (1..N). Défaut 1, donc tout nouveau
 *    compte démarre au début sans qu'aucun code n'ait à l'initialiser ;
 *  - `onboarding_completed_at` : date d'achèvement, `null` tant que le parcours
 *    n'est pas terminé. La nullité est le signal « à reprendre », plus robuste
 *    qu'un booléen qu'il faudrait penser à poser.
 *
 * Aucune contrainte de clé étrangère ni index : on filtre par utilisateur
 * identifié, jamais par ces colonnes en masse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('onboarding_step')->default(1)->after('preferences');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_step');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['onboarding_step', 'onboarding_completed_at']);
        });
    }
};
