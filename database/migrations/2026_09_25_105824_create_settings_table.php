<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Valeurs des réglages d'exploitation, modifiables sans déploiement.
 *
 * **Pourquoi une table et non un fichier de configuration.** Les valeurs visées
 * (marge, coût d'infrastructure, taux de change, montant minimum d'achat) sont
 * aujourd'hui dans `config/`, donc figées au déploiement : ajuster la marge
 * obligeait à éditer un fichier, commiter, puis redéployer — pour une décision
 * commerciale qui se prend en une minute. Le prix des plans est même DÉJÀ en base
 * (`plans.price_fcfa`), ce qui rendait l'incohérence visible : on pouvait changer
 * un prix sans redéployer, mais pas le taux qui le convertit.
 *
 * **Ce que cette table ne contient PAS : libellés, descriptions et bornes.** Ils
 * vivent dans `SettingsCatalog`, en code. Ce n'est pas un oubli mais une décision
 * contre la duplication : si le libellé était aussi en base, corriger une faute de
 * frappe exigerait une migration de données, et les deux copies finiraient par
 * diverger — l'écran affichant alors un libellé qui ne décrit plus la valeur. La
 * table ne stocke donc QUE ce qui change au fil de l'exploitation.
 *
 * **Conséquence assumée : une table volontairement vide au départ.** Elle ne
 * contient que les réglages effectivement modifiés. Tant qu'aucune ligne n'existe,
 * l'application retombe sur les valeurs par défaut du catalogue (elles-mêmes
 * issues de `config/`). C'est ce qui rend le système sûr à installer : une base
 * neuve n'a aucun réglage à amorcer, donc pas d'état à maintenir en cohérence
 * entre le code et les données.
 *
 * **Pourquoi `key` comme clé primaire et non un `id`.** Un réglage est identifié
 * par son nom (`openrouter.cost_margin`), jamais par un numéro. Un `id`
 * auto-incrémenté permettrait deux lignes pour la même clé ; la contrainte
 * d'unicité le rend impossible par construction.
 *
 * **Pourquoi `type` est conservé.** Une valeur stockée en texte doit être relue
 * dans son type d'origine : `0.60` en `string` comparé à un `float` casse la
 * comparaison, et `false` en `"0"` est *truthy* en PHP. Ces deux erreurs sont
 * silencieuses — d'où le type conservé à côté de la valeur.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 191)->primary();
            $table->text('value');
            $table->string('type', 20)->default('string');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
