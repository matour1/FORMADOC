<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client de l'API Monetbil (paiement mobile money : MTN, Orange, Moov…).
 *
 * **Pourquoi un client maison plutôt que le SDK officiel.** Le paquet
 * `Monetbil/monetbil-php` a trois défauts qui le rendent inutilisable ici :
 *
 *  1. **Il désactive la vérification SSL.** `CURLOPT_SSL_VERIFYPEER` et
 *     `CURLOPT_SSL_VERIFYHOST` sont mis à `0` dans chaque appel. Notre application
 *     transmet un montant et des références de paiement dans ces requêtes : sans
 *     vérification du certificat, une interception réseau permet de les modifier.
 *     C'est une faille, pas un détail de configuration.
 *  2. **Il configure par propriétés statiques globales** (`Monetbil::setAmount()`),
 *     donc deux paiements simultanés se marchent dessus et aucun test ne peut
 *     l'isoler sans polluer l'état global du processus.
 *  3. **Il est écrit pour PHP 5.2** (2016, dernière mise à jour il y a 7 ans) et
 *     lit `$_SERVER` directement.
 *
 * On interroge donc l'API avec `Http` de Laravel, qui vérifie les certificats par
 * défaut. `composer.json` n'est pas modifié : aucun paquet n'est ajouté.
 *
 * **Le protocole, tel qu'il est réellement documenté.** Deux appels et une
 * vérification de signature :
 *
 *  - `url()` — `POST` sur `{widget_url}{version}/{service_key}` avec les paramètres
 *    du paiement. La réponse contient `payment_url`, vers laquelle on redirige le
 *    client ;
 *  - `checkPayment()` — `POST` sur l'API de vérification avec le `transaction_id`.
 *    C'est la SOURCE D'AUTORITÉ du statut : la notification entrante annonce un
 *    statut, mais seul cet appel le confirme ;
 *  - `signatureValide()` — la notification est signée par un MD5 du secret
 *    concaténé aux valeurs des paramètres triés par clé.
 */
class MonetbilService
{
    /**
     * Construit l'URL de paiement Monetbil et la retourne.
     *
     * **Pourquoi un POST et non une URL à paramètres.** En widget v2.1, Monetbil
     * renvoie une `payment_url` propre, générée côté serveur, qui embarque le
     * contexte du paiement (montant, références, retour). Construire l'URL
     * nous-mêmes demanderait de reproduire leur format interne, qui n'est pas
     * documenté et pourrait changer — c'est le v1 qui fonctionnait ainsi, et cette
     * version n'est plus documentée.
     *
     * @param  array<string, mixed>  $contexte  métadonnées métier (rattachées par nos soins,
     *                                          Monetbil ne les exploite pas)
     * @return array{ok: bool, paymentUrl?: string, message?: string}
     */
    public function url(
        int $amountFcfa,
        string $paymentRef,
        string $returnUrl,
        string $notifyUrl,
        string $itemRef = '',
        ?string $customerEmail = null,
        ?string $phone = null,
        array $contexte = [],
    ): array {
        $serviceKey = (string) config('monetbil.service_key', '');

        if ($serviceKey === '') {
            return ['ok' => false, 'message' => 'Monetbil n\'est pas configuré (clé de service absente).'];
        }

        // Les paramètres sont SIGNÉS : Monetbil rejette une requête dont la
        // signature ne correspond pas. Le tri par clé est fait par `signature()`,
        // il ne faut donc pas réordonner le tableau après coup.
        $parametres = [
            'amount' => $amountFcfa,
            'currency' => (string) config('monetbil.currency', 'XAF'),
            'locale' => (string) config('monetbil.locale', 'fr'),
            'item_ref' => $itemRef !== '' ? $itemRef : $paymentRef,
            'payment_ref' => $paymentRef,
            'return_url' => $returnUrl,
            'notify_url' => $notifyUrl,
        ];

        // `phone` et `email` sont facultatifs : préremplir le widget évite au
        // client de ressaisir ce qu'on connaît déjà, mais un champ vide est
        // accepté par Monetbil et n'empêche pas le paiement.
        if ($phone !== null && $phone !== '') {
            $parametres['phone'] = $phone;
        }

        if ($customerEmail !== null && $customerEmail !== '') {
            $parametres['email'] = $customerEmail;
        }

        $reponse = $this->envoyer(
            'post',
            $this->widgetUrl(),
            [...$parametres, 'sign' => $this->signature($parametres)],
        );

        if ($reponse === null) {
            return ['ok' => false, 'message' => 'Monetbil est indisponible (réessayez plus tard).'];
        }

        if (! $reponse->successful()) {
            Log::warning('Monetbil : initialisation du paiement refusée', [
                'statut' => $reponse->status(),
                'corps' => mb_substr((string) $reponse->body(), 0, 300),
                'payment_ref' => $paymentRef,
            ]);

            return ['ok' => false, 'message' => 'Le paiement n\'a pas pu être initialisé.'];
        }

        $corps = $reponse->json();
        $url = $corps['payment_url'] ?? null;

        if (! is_string($url) || $url === '') {
            // Réponse sans URL : traiter comme un échec plutôt que de renvoyer
            // une chaîne vide au contrôleur, qui redirigerait vers nulle part.
            Log::warning('Monetbil : réponse sans payment_url', [
                'payment_ref' => $paymentRef,
                'cles' => is_array($corps) ? implode(',', array_keys($corps)) : '(non tableau)',
            ]);

            return ['ok' => false, 'message' => 'Réponse inattendue de la passerelle.'];
        }

        return ['ok' => true, 'paymentUrl' => $url];
    }

    /**
     * Interroge Monetbil sur le statut RÉEL d'une transaction.
     *
     * **C'est la source d'autorité, jamais la notification.** La notification
     * entrante annonce un statut, mais son format et son authenticité reposent sur
     * une signature sans horodatage (voir `config/monetbil.php`) : elle est
     * rejouable telle quelle. Vérifier le statut auprès de l'API avant tout
     * versement de crédits est donc le seul moyen de distinguer un vrai paiement
     * d'une notification rejouée.
     *
     * @return array{ok: bool, statut: null|int, succes: bool, testmode: bool, telephone: null|string, montant: null|int}
     */
    public function checkPayment(string $transactionId): array
    {
        $echec = ['ok' => false, 'statut' => null, 'succes' => false, 'testmode' => false, 'telephone' => null, 'montant' => null];

        if ($transactionId === '') {
            return $echec;
        }

        $reponse = $this->envoyer('post', (string) config('monetbil.check_payment_url'), [
            'paymentId' => $transactionId,
        ]);

        if ($reponse === null || ! $reponse->successful()) {
            Log::warning('Monetbil : vérification de paiement impossible', [
                'transaction_id' => $transactionId,
            ]);

            return $echec;
        }

        $corps = $reponse->json();
        $transaction = $corps['transaction'] ?? null;

        if (! is_array($transaction)) {
            return $echec;
        }

        $statut = (int) ($transaction['status'] ?? 0);

        return [
            'ok' => true,
            'statut' => $statut,
            'succes' => $this->estUnSucces($statut),
            // Le mode test doit être PROPAGÉ : un paiement de recette ne doit
            // jamais débloquer de crédits réels. L'appelant décide de la suite,
            // mais il faut qu'il sache qu'il est en test.
            'testmode' => (bool) ($transaction['testmode'] ?? false),
            'telephone' => $transaction['msisdn'] ?? null,
            'montant' => isset($transaction['amount']) ? (int) $transaction['amount'] : null,
        ];
    }

    /**
     * Le statut traduit-il un paiement abouti ?
     *
     * **Deux statuts valent succès, et les confondre coûte une phase de recette.**
     * `1` est le succès réel, `7` celui du mode test. Un code qui ne testerait que
     * `1` rejetterait TOUS les paiements de test : on ne pourrait jamais valider
     * l'intégration avant la mise en production.
     */
    public function estUnSucces(int $statut): bool
    {
        return $statut === (int) config('monetbil.status.success')
            || $statut === (int) config('monetbil.status.success_testmode');
    }

    /**
     * Le statut traduit-il un abandon du client ?
     *
     * Distinguer « annulé » de « échoué » n'est pas cosmétique : un abandon est un
     * comportement normal (le client ferme le widget), un échec est un incident. Le
     * premier ne doit pas déclencher d'alerte, le second si.
     */
    public function estUnAbandon(int $statut): bool
    {
        return $statut === (int) config('monetbil.status.cancelled')
            || $statut === (int) config('monetbil.status.cancelled_testmode');
    }

    /**
     * Vérifie la signature d'une notification Monetbil.
     *
     * **Ce que cette vérification garantit — et ce qu'elle ne garantit pas.**
     * Elle prouve que la notification a été émise par quelqu'un qui connaît le
     * secret partagé. Elle ne prouve PAS que la notification est récente : la
     * chaîne signée est le secret suivi des valeurs triées par clé, sans
     * horodatage ni nonce. Une notification capturée reste donc valide
     * indéfiniment et peut être réémise.
     *
     * Conséquence directe sur la conception : **la protection contre le double
     * versement ne repose pas sur cette signature mais sur l'idempotence du
     * règlement** (`PaymentLinkService::regler()`, qui refuse de payer un lien
     * déjà réglé sous verrou). Il serait faux de croire que vérifier la signature
     * suffit à se prémunir d'un rejeu.
     *
     * @param  array<string, mixed>  $parametres  paramètres reçus (la clé `sign` est retirée pour le calcul)
     */
    public function signatureValide(array $parametres): bool
    {
        $secret = (string) config('monetbil.service_secret', '');
        $recue = (string) ($parametres['sign'] ?? '');

        if ($secret === '' || $recue === '') {
            return false;
        }

        unset($parametres['sign']);

        // `hash_equals` et non `===` : une comparaison de chaînes ordinaire
        // s'arrête au premier caractère différent, ce qui laisse mesurer le temps
        // de réponse pour deviner la signature caractère par caractère.
        return hash_equals($this->signature($parametres), $recue);
    }

    /**
     * Signature Monetbil : MD5 du secret suivi des valeurs, triées par CLÉ.
     *
     * **Le tri porte sur la clé, pas sur la valeur.** Un tri par valeur donnerait
     * une signature différente de celle de Monetbil, et toutes les notifications
     * seraient rejetées — sans que le moindre message d'erreur n'indique pourquoi,
     * puisque la comparaison est une simple inégalité de chaînes.
     *
     * Les valeurs non scalaires sont écartées : `http_build_query` sérialise un
     * tableau imbriqué de façon qui ne correspond pas à ce que Monetbil signe.
     *
     * @param  array<string, mixed>  $parametres
     */
    public function signature(array $parametres): string
    {
        $plats = [];

        foreach ($parametres as $cle => $valeur) {
            if (is_scalar($valeur)) {
                $plats[$cle] = (string) $valeur;
            }
        }

        ksort($plats);

        return md5((string) config('monetbil.service_secret', '').implode('', $plats));
    }

    /**
     * URL du widget, version et clé de service incluses.
     */
    public function widgetUrl(): string
    {
        $base = rtrim((string) config('monetbil.widget_url', 'https://www.monetbil.com/widget/'), '/');
        $version = (string) config('monetbil.widget_version', 'v2.1');

        return $base.'/'.$version.'/'.(string) config('monetbil.service_key', '');
    }

    /**
     * Monetbil est-il configuré ?
     *
     * Sert à déterminer si le moyen de paiement doit être proposé. Un moyen
     * affiché mais non configuré mène le client vers une page d'erreur : mieux
     * vaut ne pas l'afficher du tout.
     */
    public function estConfigure(): bool
    {
        return (string) config('monetbil.service_key', '') !== ''
            && (string) config('monetbil.service_secret', '') !== '';
    }

    /**
     * Envoi HTTP avec retry et backoff exponentiel.
     *
     * Même stratégie que `KPayService` : le facteur dominant n'est pas la
     * congestion du serveur mais la latence des opérateurs mobile money, qui
     * dépassent régulièrement le délai et répondent ensuite correctement.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function envoyer(string $methode, string $url, array $donnees = []): ?Response
    {
        $maxTentatives = max(1, (int) config('monetbil.retries', 3));
        $delai = 1;

        for ($tentative = 0; $tentative < $maxTentatives; $tentative++) {
            try {
                $requete = Http::acceptJson()
                    ->timeout((int) config('monetbil.timeout', 30));

                $reponse = $methode === 'post'
                    ? $requete->asForm()->post($url, $donnees)
                    : $requete->get($url, $donnees);

                // 429 : on respecte `Retry-After` quand il est fourni, sans
                // dépasser notre propre délai (un opérateur peut annoncer une
                // attente de plusieurs minutes, incompatible avec une requête web).
                if ($reponse->status() === 429 && $tentative < $maxTentatives - 1) {
                    $attente = (int) ($reponse->header('Retry-After') ?: $delai);
                    sleep(min($attente, $delai));
                    $delai *= 2;

                    continue;
                }

                return $reponse;
            } catch (ConnectionException $e) {
                Log::warning('Monetbil : erreur réseau, nouvelle tentative', [
                    'tentative' => $tentative + 1,
                    'error' => $e->getMessage(),
                ]);

                if ($tentative < $maxTentatives - 1) {
                    sleep($delai);
                    $delai *= 2;

                    continue;
                }
            } catch (\Throwable $e) {
                Log::error('Monetbil : erreur inattendue', [
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }
}
