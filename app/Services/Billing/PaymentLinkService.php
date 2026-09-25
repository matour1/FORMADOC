<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Création et règlement des liens de paiement.
 *
 * **Le service porte les règles, pas le contrôleur.** Trois invariants doivent tenir
 * quel que soit le chemin d'appel — création depuis l'administration, règlement en
 * ligne par retour de passerelle, constatation hors ligne par un administrateur :
 *
 *  1. un lien réglé ne se règle pas deux fois (et les crédits ne sont versés qu'une
 *     fois, même si le webhook et le retour passerelle arrivent tous les deux) ;
 *  2. un lien expiré ou annulé ne peut plus être réglé ;
 *  3. le versement des crédits est ATOMIQUE avec le passage du lien à `paid`. Si le
 *     lien était marqué payé sans que le versement aboutisse, le client aurait payé
 *     sans rien recevoir et rien ne signalerait l'anomalie.
 */
class PaymentLinkService
{
    public function __construct(
        private readonly CreditService $credits,
    ) {}

    /**
     * Crée un lien de paiement.
     *
     * @param  array{
     *     amount_fcfa: int, mode: string, label?: string, description?: string,
     *     user_id?: int|null, customer_label?: string|null, customer_email?: string|null
     * }  $donnees
     * @param  User  $auteur  administrateur créateur
     * @param  int|null  $ttlJours  durée de validité ; le réglage `payments.link_ttl_days`
     *                              s'applique par défaut
     */
    public function creer(array $donnees, User $auteur, ?int $ttlJours = null): PaymentLink
    {
        $montant = (int) $donnees['amount_fcfa'];
        $ttl = $ttlJours ?? (int) config('payments.link_ttl_days', 7);

        return PaymentLink::create([
            'token' => $this->genererJeton(),
            'label' => $donnees['label'] ?? null,
            'amount_fcfa' => $montant,
            'currency' => (string) config('kpay.currency', 'XAF'),
            'mode' => $donnees['mode'],
            'status' => PaymentLink::STATUT_EN_ATTENTE,

            // 1 crédit = 1 FCFA. La valeur est FIGÉE à la création : si la parité
            // changeait, un lien déjà transmis doit verser ce qui a été annoncé au
            // client, pas ce que la nouvelle parité donnerait.
            'credits' => $montant,

            'user_id' => $donnees['user_id'] ?? null,
            'customer_label' => $donnees['customer_label'] ?? null,
            'customer_email' => $donnees['customer_email'] ?? null,
            'description' => $donnees['description'] ?? null,
            'expires_at' => now()->addDays($ttl),
            'created_by' => $auteur->id,
        ]);
    }

    /**
     * Marque un lien comme réglé et verse les crédits, une seule fois.
     *
     * @param  string  $reference  identifiant du paiement (KPay ou référence saisie)
     * @param  int|null  $encaissePar  administrateur ayant constaté un règlement hors ligne
     * @return array{ok: bool, motif?: string, lien?: PaymentLink, credits_verses?: bool}
     */
    public function regler(
        PaymentLink $lien,
        string $reference,
        ?int $encaissePar = null,
        ?int $kpayPaymentId = null,
    ): array {
        try {
            return DB::transaction(function () use ($lien, $reference, $encaissePar, $kpayPaymentId): array {
                // Verrouillage : deux règlements simultanés (retour de passerelle et
                // webhook, cas nominal et non théorique) liraient tous les deux
                // `pending` et verseraient les crédits deux fois.
                $verrouille = PaymentLink::query()
                    ->whereKey($lien->id)
                    ->lockForUpdate()
                    ->first();

                if ($verrouille === null) {
                    return ['ok' => false, 'motif' => 'introuvable'];
                }

                if ($verrouille->status === PaymentLink::STATUT_PAYE) {
                    // Idempotence : c'est le cas normal d'un doublon de notification,
                    // pas une erreur. On le signale sans alerter.
                    return ['ok' => true, 'lien' => $verrouille, 'credits_verses' => false];
                }

                if ($verrouille->status === PaymentLink::STATUT_ANNULE) {
                    return ['ok' => false, 'motif' => 'annule'];
                }

                if (! $verrouille->estUtilisable()) {
                    // Expiré : l'argent a pu être débité côté opérateur. On refuse le
                    // versement automatique mais on journalise pour qu'un
                    // administrateur puisse créditer à la main après vérification.
                    Log::warning('Lien de paiement réglé après expiration', [
                        'lien_id' => $verrouille->id,
                        'reference' => $reference,
                        'expire_le' => $verrouille->expires_at?->toIso8601String(),
                    ]);

                    return ['ok' => false, 'motif' => 'expire', 'lien' => $verrouille];
                }

                $versement = false;

                // Le versement n'a lieu que s'il y a un compte à créditer. Un lien
                // pour un prospect sans compte est marqué payé : le règlement est
                // constaté, et les crédits seront attribués à l'ouverture du compte
                // (l'historique les porte, l'administrateur peut les retrouver).
                if ($verrouille->user_id !== null) {
                    $utilisateur = User::find($verrouille->user_id);

                    if ($utilisateur === null) {
                        return ['ok' => false, 'motif' => 'destinataire_introuvable'];
                    }

                    $resultat = $this->credits->creditIfNotProcessed(
                        user: $utilisateur,
                        amount: (int) $verrouille->credits,
                        reference: 'payment_link:'.$verrouille->id,
                        description: 'Règlement du lien '.($verrouille->label ?: '#'.$verrouille->id),
                        metadata: [
                            'payment_link_id' => $verrouille->id,
                            'canal' => $verrouille->mode,
                            'reference_paiement' => $reference,
                        ],
                    );

                    if (! ($resultat['ok'] ?? false)) {
                        // Échec du versement : on lève pour annuler TOUTE la
                        // transaction. Marquer le lien payé sans que le client ait
                        // reçu ses crédits serait un préjudice silencieux.
                        throw new \RuntimeException('Versement des crédits impossible.');
                    }

                    $versement = ! ($resultat['already_processed'] ?? false);
                }

                $verrouille->forceFill([
                    'status' => PaymentLink::STATUT_PAYE,
                    'paid_at' => now(),
                    'payment_reference' => $reference,
                    'paid_by' => $encaissePar,
                    'kpay_payment_id' => $kpayPaymentId,
                ])->save();

                return ['ok' => true, 'lien' => $verrouille, 'credits_verses' => $versement];
            });
        } catch (\Throwable $e) {
            Log::error('Échec du règlement d\'un lien de paiement', [
                'lien_id' => $lien->id,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'motif' => 'exception'];
        }
    }

    /**
     * Annule un lien non réglé.
     */
    public function annuler(PaymentLink $lien): bool
    {
        if ($lien->status !== PaymentLink::STATUT_EN_ATTENTE) {
            return false;
        }

        $lien->forceFill(['status' => PaymentLink::STATUT_ANNULE])->save();

        return true;
    }

    /**
     * Jeton d'URL.
     *
     * `Str::random()` s'appuie sur le générateur aléatoire cryptographique de PHP.
     * La longueur importe : un jeton deviné ouvrirait la page de règlement d'un
     * tiers, donc la valeur n'est pas décorative. Le préfixe rend un lien
     * reconnaissable dans une conversation ou un journal, sans avoir à interroger
     * la base.
     */
    private function genererJeton(): string
    {
        $prefixe = (string) config('payments.link_token_prefix', 'pl_');
        $longueur = (int) config('payments.link_token_length', 32);

        do {
            $jeton = $prefixe.Str::random(max(16, $longueur));
        } while (PaymentLink::where('token', $jeton)->exists());

        return $jeton;
    }
}
