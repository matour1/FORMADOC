<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suspension d'un compte (bannissement réversible).
 *
 * **Pourquoi un booleen et non une suppression.** Supprimer un compte emporte ses
 * documents, ses factures et son historique de paiement — des pièces dont la
 * conservation engage la responsabilité de l'éditeur, et qu'un litige peut rendre
 * nécessaires des mois plus tard. La suspension coupe l'accès immédiatement, sans
 * rien détruire, et se défait d'un clic quand la situation se règle.
 *
 * **Pourquoi une colonne à part de `is_admin`.** Les deux sont des booléens, mais
 * `is_admin` accorde un privilège tandis que `is_suspended` retire un accès. Les
 * confondre dans un même champ d'état rendrait impossible de suspendre un
 * administrateur — un cas réel : c'est précisément le compte dont on veut arrêter
 * l'accès en premier.
 *
 * **Défaut à `false`.** Un compte créé n'est jamais suspendu, comme il n'est jamais
 * administrateur. La sanction est un geste explicite.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Index : la vérification a lieu à CHAQUE connexion, sur une table qui
            // grandit. Sans index, elle finirait par balayer toute la table.
            $table->boolean('is_suspended')->default(false)->index()->after('is_admin');
            $table->string('suspension_reason', 255)->nullable()->after('is_suspended');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nom explicite : Laravel dérive « users_is_suspended_index », mais
            // s'en remettre à la convention casserait le rollback si elle changeait.
            $table->dropIndex('users_is_suspended_index');
            $table->dropColumn(['is_suspended', 'suspension_reason']);
        });
    }
};
