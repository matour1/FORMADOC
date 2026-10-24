<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client de l'API GeniusPay (`/api/v1/merchant`).
 *
 * **Ce que cette classe fait et ne fait pas.** Elle parle à l'API et traduit ses
 * réponses ; elle ne décide RIEN du crédit à verser. La décision appartient au
 * contrôleur, qui croise la réponse, la signature et l'idempotence.
 *
 * **Authentification** : deux en-têtes, `X-API-Key` (clé publique) et
 * `X-API-Secret`. Le mode (sandbox/live) se lit dans le PRÉFIXE des clés, pas
 * dans une URL distincte.
 *
 * **Pourquoi la vérification SSL n'est pas désactivée.** Les requêtes
 * transportent un montant et une référence de paiement. Sans vérification du
 * certificat, une interception réseau permettrait de modifier l'un ou l'autre.
 * `Http` de Laravel vérifie par défaut — on ne touche pas à ce réglage.
 */
final class GeniusPayService
{
    /**
     * Crée un paiement et renvoie l'URL de checkout hébergée.
     *
     * **Pourquoi on n'envoie PAS `payment_method`.** Sans ce champ, GeniusPay
     * génère une page de checkout où le CLIENT choisit son moyen (Wave, Orange,
     * MTN, carte). C'est l'approche que sa documentation recommande pour
     * maximiser les conversions : on ne présume pas du moyen disponible dans le
     * pays du payeur.
     *
     * @param  int  $montantFcfa  Montant en XAF (converti en XOF à l'envoi)
     * @param  array{
     *     user_id?: int,
     *     purpose?: string,
     *     amount_fcfa?: int,
     *     plan_id?: int
     * }  $metadata  métadonnées métier, retournées dans le webhook
     * @return array{
     *     ok: bool,
     *     checkoutUrl?: string,
     *     paymentUrl?: string,
     *     reference?: string,
     *     expiresAt?: string,
     *     message?: string
     * }
     */
    public function initierPaiement(
        int $montantFcfa,
        string $referenceExterne,
        string $successUrl,
        string $errorUrl,
        ?string $email = null,
        ?string $nom = null,
        ?string $telephone = null,
        ?string $pays = null,
        array $metadata = [],
        string $description = 'Achat de crédits FORMADOC',
    ): array {
        if (! $this->estConfigure()) {
            return ['ok' => false, 'message' => 'GeniusPay n\'est pas configuré (clés d\'API absentes).'];
        }

        $payload = [
            'amount' => $this->convertirEnXof($montantFcfa),
            'currency' => (string) config('geniuspay.currency', 'XOF'),
            // `external_reference` sert AUSSI de clé d'idempotence côté GeniusPay :
            // rejouer la création avec la même valeur renvoie la transaction
            // existante au lieu d'en créer une seconde. C'est plus robuste qu'un
            // dédoublonnage local, qui ne verrait pas une requête perdue en route.
            'external_reference' => $referenceExterne,
            'success_url' => $successUrl,
            'error_url' => $errorUrl,
            'description' => $description !== '' ? $description : 'Achat de crédits FORMADOC',
        ];

        $client = array_filter([
            'email' => $email,
            'name' => $nom,
            'phone' => $telephone,
            'country' => $pays,
        ], static fn ($v): bool => is_string($v) && $v !== '');

        if ($client !== []) {
            $payload['customer'] = $client;
        }

        // Le pays peut aussi être passé à la racine : l'API le documente comme
        // alias de `customer.country`, et il détermine les moyens proposés au
        // checkout (M-Pesa pour le Kenya, par exemple).
        if (is_string($pays) && $pays !== '') {
            $payload['country'] = strtoupper($pays);
        }

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $reponse = $this->envoyer('/payments', $payload, $referenceExterne);

        if ($reponse === null) {
            return ['ok' => false, 'message' => 'GeniusPay est indisponible (réessayez plus tard).'];
        }

        $corps = $reponse->json();
        $donnees = $corps['data'] ?? null;

        if (! $reponse->successful() || ! is_array($donnees)) {
            Log::warning('GeniusPay : création du paiement refusée', [
                'statut' => $reponse->status(),
                'corps' => mb_substr((string) $reponse->body(), 0, 300),
                'reference' => $referenceExterne,
            ]);

            return ['ok' => false, 'message' => 'Le paiement n\'a pas pu être initialisé.'];
        }

        $checkout = $donnees['checkout_url'] ?? null;

        if (! is_string($checkout) || $checkout === '') {
            // Réponse sans URL : la traiter comme un échec plutôt que de
            // rediriger le client vers nulle part.
            Log::warning('GeniusPay : réponse sans checkout_url', [
                'reference' => $referenceExterne,
                'cles' => implode(',', array_keys($donnees)),
            ]);

            return ['ok' => false, 'message' => 'Réponse inattendue de la passerelle.'];
        }

        return [
            'ok' => true,
            'checkoutUrl' => $checkout,
            // `payment_url` n'est présent qu'en mode DIRECT (avec `payment_method`) ;
            // en mode checkout il est absent, et c'est normal.
            'paymentUrl' => is_string($donnees['payment_url'] ?? null) ? $donnees['payment_url'] : null,
            'reference' => (string) ($donnees['reference'] ?? ''),
            'expiresAt' => $donnees['expires_at'] ?? null,
        ];
    }

    /**
     * Récupère l'état RÉEL d'une transaction.
     *
     * **C'est la source d'autorité, jamais le webhook seul.** Un webhook annonce
     * un événement ; il ne prouve pas que l'état est encore celui-là. Sur une
     * passerelle où la signature ne porte pas de nonce, interroger l'API avant
     * tout versement reste la seule vérification solide.
     *
     * @return array{ok: bool, statut: null|string, montant: null|int, reference: null|string, environnement: null|string}
     */
    public function verifierPaiement(string $reference): array
    {
        $echec = ['ok' => false, 'statut' => null, 'montant' => null, 'reference' => null, 'environnement' => null];

        if ($reference === '') {
            return $echec;
        }

        if (! $this->estConfigure()) {
            return $echec;
        }

        $url = $this->baseUrl().'/payments/'.rawurlencode($reference);
        $reponse = $this->requete('get', $url);

        if ($reponse === null || ! $reponse->successful()) {
            Log::warning('GeniusPay : vérification du paiement impossible', ['reference' => $reference]);

            return $echec;
        }

        $donnees = $reponse->json('data');

        if (! is_array($donnees)) {
            return $echec;
        }

        return [
            'ok' => true,
            'statut' => is_string($donnees['status'] ?? null) ? $donnees['status'] : null,
            'montant' => isset($donnees['amount']) ? (int) $donnees['amount'] : null,
            'reference' => is_string($donnees['reference'] ?? null) ? $donnees['reference'] : null,
            'environnement' => is_string($donnees['environment'] ?? null) ? $donnees['environment'] : null,
        ];
    }

    /**
     * Rembourse une transaction (totalement ou partiellement) auprès de GeniusPay.
     *
     * Endpoint documenté : `POST /payments/{reference}/refund` avec `amount`
     * (optionnel ; omis = remboursement total) et `reason` (optionnel).
     *
     * @param  int|null  $montantFcfa  Montant à rembourser en XAF (null = total)
     * @return array{ok: bool, statut?: null|string, montantRembourse?: null|int, message?: string}
     */
    public function rembourserPaiement(string $reference, ?int $montantFcfa = null, ?string $motif = null): array
    {
        if ($reference === '') {
            return ['ok' => false, 'message' => 'Référence de paiement absente.'];
        }

        if (! $this->estConfigure()) {
            return ['ok' => false, 'message' => 'GeniusPay n\'est pas configuré (clés d\'API absentes).'];
        }

        $payload = [];

        if ($montantFcfa !== null && $montantFcfa > 0) {
            $payload['amount'] = $this->convertirEnXof($montantFcfa);
        }

        if (is_string($motif) && trim($motif) !== '') {
            $payload['reason'] = trim($motif);
        }

        $reponse = $this->envoyer(
            '/payments/'.rawurlencode($reference).'/refund',
            $payload,
            'REFUND-'.$reference.'-'.($montantFcfa ?? 'FULL'),
        );

        if ($reponse === null) {
            return ['ok' => false, 'message' => 'GeniusPay est indisponible (réessayez plus tard).'];
        }

        $corps = $reponse->json();
        $donnees = $corps['data'] ?? null;

        if (! $reponse->successful() || ! is_array($donnees)) {
            Log::warning('GeniusPay : remboursement refusé', [
                'statut' => $reponse->status(),
                'corps' => mb_substr((string) $reponse->body(), 0, 300),
                'reference' => $reference,
            ]);

            return ['ok' => false, 'message' => (string) ($corps['message'] ?? 'Le remboursement a échoué.')];
        }

        return [
            'ok' => true,
            'statut' => is_string($donnees['status'] ?? null) ? $donnees['status'] : null,
            'montantRembourse' => isset($donnees['refunded_amount']) ? (int) $donnees['refunded_amount'] : null,
        ];
    }

    /**
     * Vérifie la signature d'un webhook GeniusPay.
     *
     * **La chaîne signée inclut le corps BRUT.** `$payloadBrut` doit être la
     * chaîne exactement reçue (`php://input`), pas un ré-encodage JSON : un
     * `json_encode(json_decode($raw))` peut changer l'ordre des clés ou les
     * espaces, et la signature ne correspondrait plus.
     *
     * **Ce que la vérification garantit, et ce qu'elle ne garantit pas.** Elle
     * prouve que l'émetteur connaît le secret partagé. Elle ne prouve pas que
     * l'événement est récent — d'où la fenêtre de tolérance, qui limite la
     * réutilisation d'une capture ancienne. Le rejeu immédiat d'un payload
     * identique reste possible : la protection réelle est l'idempotence du
     * crédit.
     */
    public function signatureValide(string $payloadBrut, string $timestamp, string $signature): bool
    {
        $secret = (string) config('geniuspay.webhook_secret', '');

        if ($secret === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        $attendue = hash_hmac('sha256', $timestamp.'.'.$payloadBrut, $secret);

        // `hash_equals` : une comparaison ordinaire s'arrête au premier caractère
        // différent et laisse mesurer le temps de réponse pour deviner la
        // signature caractère par caractère.
        if (! hash_equals($attendue, $signature)) {
            return false;
        }

        // Fenêtre de tolérance, appliquée APRÈS la signature : rejeter un
        // horodatage sans avoir vérifié la signature exposerait à un simple déni
        // de service par des requêtes forgées.
        $tolerance = (int) config('geniuspay.webhook_tolerance_seconds', 300);
        $horodatage = (int) $timestamp;

        return $horodatage > 0 && abs(time() - $horodatage) <= $tolerance;
    }

    /**
     * Le statut annoncé correspond-il à un paiement abouti ?
     *
     * Seul `completed` engage un versement. `processing` et `pending` sont
     * transitoires : créditer dessus reviendrait à payer un paiement qui peut
     * encore échouer.
     */
    public function estUnSucces(?string $statut): bool
    {
        return $statut === (string) config('geniuspay.status.completed', 'completed');
    }

    /**
     * GeniusPay est-il configuré ?
     *
     * Un moyen affiché mais non configuré mène le client vers une page d'erreur :
     * mieux vaut ne pas le proposer du tout.
     */
    public function estConfigure(): bool
    {
        return (string) config('geniuspay.api_key', '') !== ''
            && (string) config('geniuspay.secret_key', '') !== '';
    }

    /**
     * Convertit un montant XAF en la devise d'encaissement.
     *
     * Décision du responsable : parité 1:1 (XAF = XOF). La conversion est donc
     * une identité, mais elle passe par un taux configuré — pour que le jour où
     * un taux réel s'appliquerait, il n'y ait qu'une valeur à changer, et pas
     * une multiplication à retrouver dans le code.
     */
    public function convertirEnXof(int $montantFcfa): int
    {
        $taux = (float) config('geniuspay.xaf_to_xof_rate', 1.0);

        return (int) round($montantFcfa * $taux);
    }

    /**
     * URL de base, sans barre finale.
     */
    private function baseUrl(): string
    {
        return rtrim((string) config('geniuspay.base_url', 'https://geniuspay.ci/api/v1/merchant'), '/');
    }

    /**
     * Envoie une requête POST authentifiée, avec retry sur erreurs réseau et 429.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function envoyer(string $chemin, array $donnees, string $reference): ?Response
    {
        return $this->requete('post', $this->baseUrl().$chemin, $donnees, $reference);
    }

    /**
     * Requête HTTP avec backoff exponentiel.
     *
     * Le facteur dominant n'est pas la congestion de notre serveur mais la latence
     * des opérateurs mobile money, qui dépassent régulièrement le délai et
     * répondent correctement à la tentative suivante.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function requete(string $methode, string $url, array $donnees = [], string $reference = ''): ?Response
    {
        $max = max(1, (int) config('geniuspay.retries', 3));
        $delai = 1;

        for ($tentative = 0; $tentative < $max; $tentative++) {
            try {
                $client = Http::acceptJson()
                    ->withHeaders([
                        'X-API-Key' => (string) config('geniuspay.api_key', ''),
                        'X-API-Secret' => (string) config('geniuspay.secret_key', ''),
                    ])
                    ->timeout((int) config('geniuspay.timeout', 30));

                // `Idempotency-Key` en plus de `external_reference` : les deux sont
                // documentés comme équivalents, mais les envoyer ensemble protège
                // le cas où un intermédiaire retirerait le corps de la requête en
                // la rejouant.
                if ($reference !== '') {
                    $client = $client->withHeaders(['Idempotency-Key' => $reference]);
                }

                $reponse = $methode === 'post'
                    ? $client->post($url, $donnees)
                    : $client->get($url);

                if ($reponse->status() === 429 && $tentative < $max - 1) {
                    $attente = (int) ($reponse->header('Retry-After') ?: $delai);
                    sleep(min($attente, $delai));
                    $delai *= 2;

                    continue;
                }

                return $reponse;
            } catch (ConnectionException $e) {
                Log::warning('GeniusPay : erreur réseau, nouvelle tentative', [
                    'tentative' => $tentative + 1,
                    'error' => $e->getMessage(),
                ]);

                if ($tentative < $max - 1) {
                    sleep($delai);
                    $delai *= 2;

                    continue;
                }
            } catch (\Throwable $e) {
                Log::error('GeniusPay : erreur inattendue', ['error' => $e->getMessage()]);

                return null;
            }
        }

        return null;
    }
}
