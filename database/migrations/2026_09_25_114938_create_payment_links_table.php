<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liens de paiement à jeton unique.
 *
 * **Pourquoi cette table existe alors que KPay fournit des paiements.** KPay
 * n'expose AUCUN endpoint de « lien de paiement » : sa documentation ne décrit que
 * `POST /payments/init`, `GET /payments/:id`, `POST /payments/:id/refund` et
 * `GET /payments/availability`. Un lien transmissible à un client qui n'a pas de
 * compte FORMADOC est donc une construction à nous.
 *
 * **Deux voies, et la seconde n'est pas un repli de secours théorique.**
 *
 *  1. `online` — le lien mène à une page FORMADOC qui ouvre la passerelle KPay
 *     hébergée (mode GATEWAY documenté). Le client choisit son moyen de paiement
 *     sur la page KPay.
 *  2. `offline` — le client a réglé par un autre canal (espèces, virement, mobile
 *     money direct), et un administrateur constate l'encaissement. Les crédits sont
 *     alors versés sans passer par KPay.
 *
 * La seconde voie est indispensable : les opérateurs tombent en maintenance —
 * KPay est resté plusieurs JOURS indisponible pendant le développement même de
 * cette fonctionnalité. Un lien qui ne mène qu'à une passerelle morte transforme
 * une vente en perte, et un encaissement reçu par un autre canal non enregistré
 * crée un écart de comptabilité.
 *
 * **Pourquoi le jeton est stocké et non dérivé.** Il doit être vérifiable (un
 * jeton inventé ne doit rien ouvrir) et révocable. Stocké en clair : il est
 * destiné à être transmis au client, donc il n'est pas un secret au sens d'un mot
 * de passe, et le hacher empêcherait de le retrouver depuis un lien reçu — ce qui
 * est précisément l'usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();

            // Jeton d'URL. Unique par construction : deux liens ne peuvent pas
            // partager une adresse, sinon l'un ouvrirait la page de l'autre.
            $table->string('token', 64)->unique();

            // Libellé interne (« Facture client SARL », « Inscription BTS ») : sert
            // à retrouver un lien dans la liste, jamais montré au client.
            $table->string('label', 191)->nullable();

            $table->unsignedBigInteger('amount_fcfa');
            $table->string('currency', 3)->default('XAF');

            // 'online' (passerelle KPay) ou 'offline' (encaissement constaté).
            $table->string('mode', 16);

            // 'pending' | 'paid' | 'cancelled'
            $table->string('status', 16)->default('pending');

            // Crédits versés à l'encaissement. Distinct de `amount_fcfa` parce que
            // 1 crédit = 1 FCFA aujourd'hui mais que la parité pourrait changer —
            // et un lien payé avant un changement doit verser ce qui était annoncé.
            $table->unsignedBigInteger('credits');

            // Destinataire : un compte existant si l'administrateur le connaît,
            // sinon NULL. Un lien pour un prospect n'a pas de compte à créditer
            // tant qu'il n'en a pas créé un.
            $table->unsignedBigInteger('user_id')->nullable();

            // Coordonnées libres, pour un client sans compte. Gardées pour
            // rapprocher un paiement reçu d'un lien transmis.
            $table->string('customer_label', 191)->nullable();
            $table->string('customer_email', 191)->nullable();
            $table->string('description', 500)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Règlement hors ligne : qui l'a constaté, et sur quelle référence.
            // Sans ces deux informations, un encaissement manuel est indistinguable
            // d'un crédit accordé par erreur.
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->string('payment_reference', 191)->nullable();

            // Paiement KPay associé quand le lien a été réglé en ligne.
            $table->unsignedBigInteger('kpay_payment_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'expires_at']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('kpay_payment_id')->references('id')->on('kpay_payments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
