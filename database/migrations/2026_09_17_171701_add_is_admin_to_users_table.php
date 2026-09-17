<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accès administrateur (étape 8).
 *
 * **Pourquoi un simple booléen et non un système de rôles.** Le besoin est unique
 * et binaire : accéder ou non à l'espace d'administration. Un système de rôles
 * (table `roles`, table pivot, permissions granulaires) introduirait de la
 * complexité — et donc des angles morts — pour un cas qui n'en a pas besoin. Si
 * plusieurs niveaux apparaissent un jour (support en lecture seule, facturation,
 * modération), la migration sera le bon moment pour les modéliser.
 *
 * **Valeur par défaut `false`.** Un utilisateur créé n'est jamais administrateur :
 * l'élévation est un geste explicite, jamais un effet de bord d'une inscription.
 *
 * L'index est posé car la vérification a lieu à chaque requête de l'espace admin
 * (le middleware lit la colonne), sur une table qui grandit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->index()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nom explicite : Laravel dérive « users_is_admin_index », mais s'en
            // remettre à la convention casserait le rollback si elle changeait.
            $table->dropIndex('users_is_admin_index');
            $table->dropColumn('is_admin');
        });
    }
};
