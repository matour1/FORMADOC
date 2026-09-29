<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passerelle réellement utilisée pour régler un lien de paiement.
 *
 * **Pourquoi la colonne est nécessaire.** Le mode `online` ne disait pas PAR QUEL
 * moyen le client avait payé. Avec une seule passerelle le problème ne se posait
 * pas ; avec deux, deux conséquences apparaissent :
 *
 *  1. la synchronisation de secours (interrogation du fournisseur quand le webhook
 *     n'arrive pas) doit savoir QUELLE API interroger — sans cette information elle
 *     interroge toutes les passerelles pour chaque lien, ou la mauvaise ;
 *  2. le rapprochement comptable devient impossible : un encaissement Monetbil
 *     apparaîtrait comme un encaissement KPay, et un écart de caisse serait
 *     indétectable.
 *
 * La colonne est NULLABLE et sans valeur par défaut : les liens créés avant cette
 * migration étaient nécessairement KPay (c'était la seule passerelle en ligne).
 * On ne les remplit donc PAS rétroactivement avec une valeur devinée — on laisse
 * `NULL`, que `PaymentGatewayRegistry::KPAY` est le seul à pouvoir revendiquer
 * avec certitude. Écrire une passerelle qu'on n'a pas observée serait inventer une
 * donnée comptable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->string('gateway', 32)->nullable()->after('mode');

            // Index : la synchronisation de secours cherche les liens à vérifier
            // par passerelle, sur un état donné. Sans index, chaque passage
            // parcourrait toute la table.
            $table->index(['gateway', 'status'], 'payment_links_gateway_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropIndex('payment_links_gateway_status_index');
            $table->dropColumn('gateway');
        });
    }
};
