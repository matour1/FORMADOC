<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-5 — Séquence de numérotation des factures.
 *
 * Avant : `InvoiceService::nextNumber()` faisait `count(invoices)+1` → non
 * atomique (2 requêtes concurrentes pouvaient lire le même count et générer
 * le même numéro → violation de la contrainte unique `invoices.number`).
 *
 * Ici : une table `invoice_sequences` avec une ligne par année, incrémentée
 * sous `lockForUpdate` (InnoDB) → numérotation atomique et sans doublon,
 * même avec des appels concurrents (webhooks KPay, renouvellements, achats).
 *
 * Backfill : la ligne de l'année courante est initialisée au maximum des
 * numéros de facture existants (pour ne jamais réutiliser un numéro déjà émis).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->integer('year')->unique();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        // Backfill : année courante = max suffixe numérique existant
        $year = (int) now()->format('Y');
        $max = 0;
        foreach (DB::table('invoices')->whereYear('created_at', $year)->pluck('number') as $number) {
            if (is_string($number) && preg_match('/^INV-\d{4}-(\d+)$/', $number, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        DB::table('invoice_sequences')->insert([
            'year' => $year,
            'last_number' => $max,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
