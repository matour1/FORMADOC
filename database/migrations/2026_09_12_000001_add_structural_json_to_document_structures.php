<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajoute la persistance du JSON structurel de la refonte.
 *
 * Migration ADDITIVE (décision D6) : la colonne historique `structure` est
 * CONSERVÉE. Les deux formats coexistent le temps de la migration, ce qui
 * garantit un retour arrière sans perte de données.
 *
 * `structural_json` : schéma JSON structurel commun (blocs, types, numéros).
 * `schema_version` : version du schéma, pour gérer les évolutions futures
 *                    sans casser les documents déjà convertis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_structures', function (Blueprint $table) {
            // JSON structurel de la refonte (blocs). Nullable : les structures
            // historiques ne l'ont pas et sont converties à la volée par le
            // LegacyStructureBridge.
            $table->json('structural_json')->nullable()->after('structure');

            // Version du schéma : permet de détecter une structure écrite par
            // une version antérieure du code et de la régénérer si nécessaire.
            $table->unsignedSmallInteger('schema_version')->nullable()->after('structural_json');

            // Trace du pipeline utilisé : « legacy » ou « native ». Essentiel
            // pour diagnostiquer un document produit pendant la transition.
            $table->string('pipeline')->nullable()->after('schema_version');
        });
    }

    public function down(): void
    {
        Schema::table('document_structures', function (Blueprint $table) {
            $table->dropColumn(['structural_json', 'schema_version', 'pipeline']);
        });
    }
};
