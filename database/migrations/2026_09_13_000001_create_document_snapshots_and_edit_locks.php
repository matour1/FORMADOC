<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots et verrous d'édition du chat (garde-fous §9.7 et §9.8).
 *
 * Deux tables dans une seule migration parce qu'elles forment un même
 * dispositif : « avant de modifier, on enregistre l'état ET on s'assure d'être
 * seul ». Les séparer laisserait croire qu'on peut en avoir une sans l'autre,
 * ce qui n'a pas de sens.
 *
 * Le `payload` est en `longText` et non en `json` : le JSON structurel d'un
 * document peut dépasser la limite de colonne JSON sur certains moteurs, et une
 * troncature silencieuse rendrait le snapshot inutilisable — exactement ce qu'on
 * cherche à éviter. C'est du texte que l'application sérialise elle-même.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_snapshots', function (Blueprint $table) {
            $table->id();

            // Un document supprimé emporte ses snapshots : ils n'ont plus d'objet.
            $table->foreignId('document_id')->constrained()->onDelete('cascade');

            // Nombre de blocs au moment du snapshot : permet d'afficher
            // « 693 blocs » dans l'historique sans relire le payload.
            $table->unsignedInteger('block_count')->default(0);

            // Motif lisible (« delete_block sur b_0042 »).
            $table->string('reason')->default('');

            // Contexte de l'action (outil, arguments, auteur).
            $table->json('metadata')->nullable();

            // JSON structurel complet : la restauration réécrit l'état tel quel,
            // sans reconstruction ni heuristique.
            $table->longText('payload');

            $table->timestamps();

            // L'historique se lit toujours du plus récent au plus ancien pour un
            // document donné : cet index est celui de toutes les requêtes.
            $table->index(['document_id', 'id']);
        });

        Schema::create('document_edit_locks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained()->onDelete('cascade');

            // Identifiant du processus détenteur (« chat:12 », « pipeline »).
            $table->string('owner');

            // Motif lisible, affiché à l'utilisateur bloqué.
            $table->string('reason')->default('');

            // Date de prise du verrou : sert à détecter un verrou abandonné.
            $table->timestamp('acquired_at');

            $table->timestamps();

            // UN SEUL verrou par document : c'est la garantie structurelle que
            // deux processus ne peuvent pas éditer en même temps. Elle ne dépend
            // pas du code appelant, donc elle ne peut pas être contournée par
            // inadvertance.
            $table->unique('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_edit_locks');
        Schema::dropIfExists('document_snapshots');
    }
};
