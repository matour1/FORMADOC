<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client de l'API KPay (https://admin.kpay.site/api/v1).
 *
 * Conforme à la documentation kpay-context-php.md :
 *   - Auth : en-têtes X-API-Key + X-Secret-Key (préfixe kpay_test_/sk_test_ = sandbox)
 *   - Init paiement passerelle : POST /payments/init SANS phoneNumber/provider/
 *     customerName, avec returnUrl requis + cancelUrl optionnel
 *   - Retour passerelle signé : {returnUrl}?status&reference&externalId&ts&sig
 *     (HMAC-SHA256 hex de "status|reference|externalId|ts", rejeter si ts > 10 min)
 *   - Webhook : POST avec X-KPAY-Signature (HMAC-SHA256 du corps BRUT), seuls
 *     les statuts terminaux (completed/failed/cancelled) engagent une décision
 *   - Rate limit 100 req/min ; backoff exponentiel 1s/2s/4s sur 429 + Retry-After
 */
class KPayService
{
    /**
     * Initialise un paiement via passerelle (carte/PayPal).
     *
     * @param  array<string, mixed>  $metadata  métadonnées métier (user_id, purpose…)
     * @return array{ok: bool, gatewayUrl?: string, externalId?: string, paymentId?: string, expiresAt?: string, message?: string}
     */
    public function initGatewayPayment(
        int $amountFcfa,
        string $externalId,
        string $returnUrl,
        string $cancelUrl,
        string $currency = 'XAF',
        array $metadata = [],
        ?string $customerEmail = null,
    ): array {
        $payload = [
            'amount' => $amountFcfa,
            'currency' => $currency,
            'externalId' => $externalId,
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
            'metadata' => $metadata,
        ];

        // Passerelle générique : le client choisit carte/PayPal/mobile money sur
        // la page KPay. Ne PAS envoyer paymentMethod (sinon 400 « doit valoir
        // CARD ou PAYPAL ») — un paymentMethod CARD/PAYPAL restreindrait le moyen.
        // customerEmail est accepté en passerelle générique.
        if ($customerEmail !== null && $customerEmail !== '') {
            $payload['customerEmail'] = $customerEmail;
        }

        $response = $this->post('/payments/init', $payload);

        if (! $response) {
            return ['ok' => false, 'message' => 'KPay indisponible (réessayez plus tard).'];
        }

        $body = $response->json();
        $status = $response->status();

        // 201 attendu avec gatewayUrl pour une passerelle
        if ($status === 201 && ! empty($body['gatewayUrl'])) {
            return [
                'ok' => true,
                'gatewayUrl' => $body['gatewayUrl'],
                'externalId' => $body['externalId'] ?? $externalId,
                'paymentId' => $body['paymentId'] ?? $body['id'] ?? null,
                'expiresAt' => $body['expiresAt'] ?? null,
            ];
        }

        // Erreur 409 = externalId déjà actif (idempotence KPay)
        if ($status === 409) {
            Log::warning('KPay : externalId déjà actif', ['externalId' => $externalId]);

            return ['ok' => false, 'message' => 'Un paiement identique est déjà en cours.'];
        }

        Log::warning('KPay : init échec', [
            'status' => $status,
            'body' => $body,
            'externalId' => $externalId,
        ]);

        return [
            'ok' => false,
            'message' => $body['message'] ?? $body['error'] ?? 'Paiement refusé par KPay (code '.$status.').',
        ];
    }

    /**
     * Récupère le détail d'un paiement.
     *
     * @return array<string, mixed>|null
     */
    public function getPayment(string $paymentId): ?array
    {
        $response = $this->get('/payments/'.urlencode($paymentId));

        if (! $response || ! $response->successful()) {
            Log::warning('KPay : getPayment échec', ['paymentId' => $paymentId]);

            return null;
        }

        return $response->json();
    }

    /**
     * Vérifie la signature HMAC-SHA256 d'un webhook KPay.
     *
     * @param  string  $rawBody  corps BRUT de la requête (sans modification)
     * @param  string  $signature  en-tête X-KPAY-Signature
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = config('kpay.webhook_secret', '');

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Vérifie la signature du retour passerelle (query string).
     *
     * Chaîne signée : "status|reference|externalId|ts"
     * Rejette si ts > 10 min (anti-rejeu).
     *
     * **L'unité de `ts` est en MILLISECONDES, et c'est la source d'un défaut
     * silencieux qu'il faut connaître.** La documentation donne l'exemple
     * `ts=1747245600000` — treize chiffres, donc un horodatage en millisecondes.
     * Le code comparait cette valeur à `time()`, qui est en SECONDES : l'écart
     * calculé valait ~1,7 × 10¹² au lieu de quelques dizaines, donc TOUT retour de
     * passerelle était rejeté comme « trop ancien ». Le client était bien débité,
     * mais la page de retour annonçait systématiquement un échec de vérification —
     * défaut invisible en test unitaire classique, parce qu'il ne produit aucune
     * erreur, seulement un refus.
     *
     * On ramène donc l'horodatage en secondes avant comparaison, sans supposer
     * l'unité : une valeur de plus de 10 chiffres ne peut pas être en secondes
     * (elle correspondrait à une date au-delà de l'an 2286), donc on divise. La
     * conversion est ainsi tolérante aux deux formats, ce qui évite de casser si
     * KPay normalise un jour son horodatage.
     *
     * @param  array<string, string>  $query
     */
    public function verifyReturnSignature(array $query): bool
    {
        $sig = $query['sig'] ?? '';
        $ts = (int) ($query['ts'] ?? 0);

        if ($sig === '' || $ts <= 0) {
            return false;
        }

        $tsSecondes = $this->horodatageEnSecondes($ts);

        // Anti-rejeu : fenêtre de 10 minutes
        $ttl = (int) config('kpay.signature_ttl_minutes', 10);

        if (abs(time() - $tsSecondes) > $ttl * 60) {
            return false;
        }

        $secret = config('kpay.secret_key', '');

        if ($secret === '') {
            return false;
        }

        // La chaîne signée utilise l'horodatage tel qu'il a été REÇU, pas la
        // version convertie : KPay l'a signé dans son format d'origine, et signer
        // une autre représentation produirait une signature différente.
        $chaine = ($query['status'] ?? '').'|'
            .($query['reference'] ?? '').'|'
            .($query['externalId'] ?? '').'|'
            .$ts;

        $expected = hash_hmac('sha256', $chaine, $secret);

        return hash_equals($expected, $sig);
    }

    /**
     * Ramène un horodatage KPay en secondes, quelle que soit son unité.
     *
     * 10 chiffres correspondent à une date jusqu'en 2286 : au-delà, la valeur est
     * nécessairement en millisecondes. Le test est donc fiable pour des décennies,
     * et il évite de dépendre d'une unité que la documentation ne nomme pas
     * explicitement — elle ne l'indique que par l'exemple.
     */
    private function horodatageEnSecondes(int $ts): int
    {
        return $ts > 9_999_999_999 ? (int) intdiv($ts, 1000) : $ts;
    }

    /**
     * Envoi POST avec retry sur erreurs réseau et 429 (backoff 1s/2s/4s).
     */
    private function post(string $path, array $payload): ?Response
    {
        return $this->send('post', $path, $payload);
    }

    /**
     * Envoi GET avec retry.
     */
    private function get(string $path): ?Response
    {
        return $this->send('get', $path);
    }

    /**
     * Envoi HTTP avec gestion des retries et du backoff exponentiel.
     */
    private function send(string $method, string $path, array $payload = []): ?Response
    {
        $maxRetries = (int) config('kpay.retries', 3);
        $delay = 1; // secondes

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $request = Http::withHeaders($this->headers())
                    ->timeout((int) config('kpay.timeout', 30))
                    ->baseUrl(rtrim((string) config('kpay.base_url', 'https://admin.kpay.site/api/v1'), '/'));

                $response = $method === 'post'
                    ? $request->post($path, $payload)
                    : $request->get($path);

                // Backoff exponentiel sur 429 (rate limit)
                if ($response->status() === 429 && $attempt < $maxRetries - 1) {
                    $retryAfter = (int) ($response->header('Retry-After') ?: $delay);
                    sleep(min($retryAfter, $delay));
                    $delay *= 2;

                    continue;
                }

                return $response;
            } catch (ConnectionException $e) {
                Log::warning('KPay : erreur réseau, nouvelle tentative', [
                    'path' => $path,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage(),
                ]);
                if ($attempt < $maxRetries - 1) {
                    sleep($delay);
                    $delay *= 2;

                    continue;
                }
            } catch (\Throwable $e) {
                Log::error('KPay : erreur inattendue', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }

    /**
     * En-têtes d'authentification KPay.
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-API-Key' => (string) config('kpay.api_key'),
            'X-Secret-Key' => (string) config('kpay.secret_key'),
        ];
    }
}
