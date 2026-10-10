<?php

namespace App\Http\Controllers;

use App\Mail\PaymentFailedMail;
use App\Mail\PaymentReceipt;
use App\Models\KpayPayment;
use App\Models\PaymentLink;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CreditPackCatalog;
use App\Services\Billing\CreditService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\KPayService;
use App\Services\Billing\MonetbilService;
use App\Services\Billing\MonetbilServiceRegistry;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\PaymentLinkService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Achat de crédits via KPay (passerelle carte/PayPal).
 *
 * Flux :
 *   POST /credits/purchase  → init KPay (externalId unique) → redirection gatewayUrl
 *   GET  /credits/return    → retour passerelle (signé) → redirection compte
 *   GET  /credits/cancel    → annulation → redirection compte
 *   POST /kpay/webhook      → notification KPay (source d'autorité du crédit)
 *
 * Règle de sécurité : le solde n'est JAMAIS crédité sur le simple retour
 * passerelle (non fiable). Seul le webhook payment.completed, signé HMAC
 * et vérifié, crédite l'utilisateur (idempotence par metadata.status).
 */
class KPayController extends Controller
{
    public function __construct(
        private readonly KPayService $kpay,
        private readonly CreditService $credits,
        private readonly MonetbilService $monetbil,
        private readonly MonetbilServiceRegistry $servicesMonetbil,
        private readonly PaymentGatewayRegistry $passerelles,
        private readonly CreditPackCatalog $catalogue,
    ) {}

    /**
     * Initialise un achat de crédits et redirige vers la passerelle KPay.
     */
    public function initPurchase(Request $request): RedirectResponse|JsonResponse
    {
        // **Le montant doit correspondre à un PALIER déclaré.** Une saisie libre
        // était acceptée ; elle est refusée, car un montant arbitraire ne peut être
        // rattaché à aucun service de paiement — une passerelle mobile money exige
        // un service par offre, avec ses propres clés. Accepter un montant sans
        // palier créerait un paiement qu'aucune passerelle ne saurait encaisser.
        //
        // La règle est lue dans `CreditPackCatalog`, la MÊME source que l'affichage.
        // Une liste dupliquée ici divergerait : un montant proposé à l'écran
        // pourrait être refusé au paiement, ou l'inverse.
        $montantsValides = app(CreditPackCatalog::class)->montantsProposables();

        $request->validate([
            'amount' => ['required', 'integer', Rule::in($montantsValides)],
            // Le moyen est FACULTATIF et vaut KPay par défaut : c'est le
            // comportement historique, et l'exiger ferait échouer toute soumission
            // qui ne le transmet pas encore. Quand il est fourni, il est revalidé
            // côté serveur — un formulaire trafiqué pourrait sinon demander une
            // passerelle désactivée ou non configurée.
            'gateway' => ['nullable', 'string', 'in:kpay,monetbil'],
        ], [
            'amount.in' => 'Ce montant n\'est pas disponible. Choisissez l\'un des paliers proposés.',
            'gateway.in' => 'Ce moyen de paiement n\'est pas reconnu.',
        ]);

        $amountFcfa = (int) $request->integer('amount');
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login')->with('error', 'Connectez-vous pour acheter des crédits.');
        }

        $gateway = (string) $request->input('gateway', PaymentGatewayRegistry::KPAY);

        // Le moyen demandé doit être PROPOSABLE (configuré, actif ET visible). Un
        // moyen configuré mais masqué ne doit pas être atteignable par URL directe,
        // sinon le masquage ne protégerait rien.
        if (! $this->passerelles->estProposable($gateway)) {
            Log::warning('Achat de crédits : moyen non proposable', [
                'user_id' => $user->id,
                'gateway' => $gateway,
            ]);

            return back()->with('error', 'Ce moyen de paiement n\'est pas disponible.');
        }

        if ($gateway === PaymentGatewayRegistry::MONETBIL) {
            return $this->initierAchatMonetbil($user, $amountFcfa);
        }

        // Identifiant unique pour l'idempotence KPay (409 si déjà actif)
        $externalId = 'CREDIT-'.$user->id.'-'.Str::uuid();

        $result = $this->kpay->initGatewayPayment(
            amountFcfa: $amountFcfa,
            externalId: $externalId,
            returnUrl: route('kpay.return'),
            cancelUrl: route('kpay.cancel'),
            currency: config('kpay.currency', 'XAF'),
            metadata: [
                'user_id' => $user->id,
                'purpose' => 'credit_purchase',
                'amount_fcfa' => $amountFcfa,
                'credits' => $amountFcfa, // 1 crédit = 1 FCFA
            ],
            customerEmail: $user->email,
        );

        if (! $result['ok']) {
            return back()->with('error', $result['message'] ?? 'Échec de l\'initialisation du paiement.');
        }

        Log::info('Achat crédits initié', [
            'user_id' => $user->id,
            'amount_fcfa' => $amountFcfa,
            'external_id' => $externalId,
            'payment_id' => $result['paymentId'] ?? null,
        ]);

        // Enregistrer le paiement pour la synchronisation de secours (fallback webhook)
        KpayPayment::create([
            'user_id' => $user->id,
            'payment_id' => $result['paymentId'] ?? null,
            'external_id' => $externalId,
            'status' => 'PENDING',
            'purpose' => 'credit_purchase',
            'amount_fcfa' => $amountFcfa,
            'currency' => config('kpay.currency', 'XAF'),
            'return_url' => route('kpay.return'),
            'cancel_url' => route('kpay.cancel'),
            'metadata' => [
                'user_id' => $user->id,
                'purpose' => 'credit_purchase',
                'amount_fcfa' => $amountFcfa,
                'credits' => $amountFcfa,
            ],
            'expires_at' => isset($result['expiresAt']) ? now()->parse($result['expiresAt']) : now()->addHours(24),
        ]);

        return redirect()->away($result['gatewayUrl']);
    }

    /**
     * Initie un achat de crédits par Monetbil (widget signé).
     *
     * **Pourquoi Monetbil n'est pas un simple « second KPay ».** KPay renvoie un
     * `paymentId` interrogeable que l'on stocke pour la synchronisation de secours.
     * Monetbil renvoie une URL de paiement ; l'identifiant de transaction n'est
     * connu qu'à l'arrivée de la notification. Il n'y a donc PAS de trace locale à
     * créer — le faire avec un identifiant inventé donnerait une fausse impression
     * de traçabilité.
     *
     * **Chaque palier utilise SON service.** Les identifiants du service rattaché au
     * montant sont résolus et passés explicitement : signer avec un couple global
     * rendrait le second palier inencaissable, et sa notification serait vérifiée
     * avec le mauvais secret.
     *
     * **La corrélation est portée par `user` (le compte) et `item_ref`.** Monetbil
     * retourne `user` dans la notification ; il permet de rattacher le paiement au
     * bon compte — sans lui, un crédit arrivé sur une URL devenue invalide ne peut
     * plus être rattaché, et un litige se règle sans preuve.
     */
    private function initierAchatMonetbil(User $user, int $amountFcfa): RedirectResponse
    {
        $service = $this->servicesMonetbil->pourMontant($amountFcfa, $this->catalogue);

        // Un palier sans service exploitable ne devrait pas être proposable — le
        // catalogue l'écarte déjà. Ce contrôle est une ceinture de sécurité : il
        // évite de signer avec des identifiants vides si la configuration des
        // paliers et celle des services ont divergé.
        if (! $this->servicesMonetbil->estExploitable($service)) {
            Log::error('Achat de crédits Monetbil : aucun service exploitable pour ce palier', [
                'user_id' => $user->id,
                'montant' => $amountFcfa,
            ]);

            return back()->with('error', 'Ce montant n\'est pas disponible pour le paiement mobile money.');
        }

        // Référence unique : elle identifie CETTE tentative et permet de rattacher
        // la notification au bon achat. L'identifiant du compte y figure pour que
        // la corrélation survive même si `user` n'était pas retourné.
        $paymentRef = 'CREDIT'.$user->id.'-'.Str::uuid();

        $resultat = $this->monetbil->url(
            amountFcfa: $amountFcfa,
            paymentRef: $paymentRef,
            returnUrl: route('credits.return.monetbil'),
            notifyUrl: route('credits.notify'),
            itemRef: 'CREDIT'.$user->id,
            customerEmail: $user->email,
            userId: $user->id,
            nomComplet: $user->name,
            service: $service,
        );

        if (! ($resultat['ok'] ?? false)) {
            Log::warning('Achat de crédits : initiation Monetbil échouée', [
                'user_id' => $user->id,
                'montant' => $amountFcfa,
                'message' => $resultat['message'] ?? null,
            ]);

            return back()->with('error', $resultat['message'] ?? 'La passerelle de paiement est indisponible.');
        }

        Log::info('Achat crédits initié via Monetbil', [
            'user_id' => $user->id,
            'amount_fcfa' => $amountFcfa,
            'payment_ref' => $paymentRef,
            'service_id' => $service['id'],
        ]);

        return redirect()->away($resultat['paymentUrl']);
    }

    /**
     * Notification Monetbil d'un ACHAT DE CRÉDITS (équivalent du webhook KPay).
     *
     * **Pourquoi une route distincte de celle des liens de paiement.** Les deux
     * flux sont séparés à l'initiation (comptes vs destinataires externes) et le
     * restent à la notification : les mélanger exigerait de deviner le flux depuis
     * la charge entrante, et une erreur de devinette créditerait le mauvais objet.
     * Chaque route applique donc SES règles, sans ambiguïté.
     *
     * **L'ordre des contrôles est le cœur de la sécurité**, et il est identique à
     * celui du flux « lien de paiement » :
     *
     *  1. **Corrélation AVANT signature — et c'est délibéré.** Le secret Monetbil
     *     peut être partagé entre services, donc une signature valide ne prouve PAS
     *     que la notification concerne CE compte. On identifie donc d'abord le
     *     compte destinataire (`user`, ou `item_ref` en repli), puis on vérifie la
     *     signature avec le secret du SERVICE de son palier.
     *  2. **Montant** lu de la notification (il est signé) : il désigne le palier,
     *     donc le service dont le secret sert à vérifier.
     *  3. **Signature** vérifiée avec CE secret — un `hash_equals` constant.
     *  4. **Statut RÉEL** demandé à l'API : la notification n'est qu'un signal
     *     d'arrivée, seul `checkPayment()` fait foi.
     *  5. **Crédit idempotent** (`creditIfNotProcessed`) : un rejeu ne verse jamais
     *     deux fois, et c'est cette idempotence — pas la signature — qui protège du
     *     double versement, la signature Monetbil ne portant aucun horodatage.
     *
     * La réponse est la chaîne `received` : Monetbil la considère comme un accusé
     * de réception, et répondre autre chose déclencherait des réessais.
     */
    public function notifyMonetbil(Request $request): Response
    {
        $parametres = $request->all();
        $transactionId = (string) ($parametres['transaction_id'] ?? '');

        // --- 1. Identifier le COMPTE destinataire (corrélation) ----------------
        //
        // `user` désigne le compte, transmis à l'initiation. `item_ref`
        // (« CREDIT{id} ») le porte aussi : il sert de repli si `user` n'est pas
        // revenu. Sans l'un des deux, on ne peut pas savoir qui créditer — on
        // refuse plutôt que de créditer au hasard.
        $userId = isset($parametres['user']) ? (int) $parametres['user'] : 0;

        if ($userId <= 0) {
            $itemRef = (string) ($parametres['item_ref'] ?? '');

            if (preg_match('/^CREDIT(\d+)$/', $itemRef, $m) === 1) {
                $userId = (int) $m[1];
            }
        }

        if ($userId <= 0) {
            Log::warning('Monetbil crédits : aucune corrélation, versement refusé', [
                'transaction_id' => $transactionId,
                'montant_annonce' => (int) ($parametres['amount'] ?? 0),
            ]);

            return response('received');
        }

        $utilisateur = User::find($userId);

        if ($utilisateur === null) {
            Log::warning('Monetbil crédits : compte introuvable', [
                'user_id' => $userId,
                'transaction_id' => $transactionId,
            ]);

            return response('received');
        }

        // --- 2. Montant lu de la notification ----------------------------------
        //
        // Le montant désigne le PALIER, donc le SERVICE, donc le secret qui servira
        // à vérifier la signature. C'est la seule source disponible : la
        // notification ne transmet pas la clé de service.
        $montant = (int) ($parametres['amount'] ?? 0);
        $reference = $this->catalogue->serviceDe($montant);

        // --- 3. Signature vérifiée avec le secret de CE service ----------------
        $service = $this->servicesMonetbil->pourReference($reference);
        $secret = $this->servicesMonetbil->estExploitable($service) ? $service['secret'] : null;

        if (! $this->monetbil->signatureValide($parametres, $secret)) {
            Log::warning('Monetbil crédits : signature de notification invalide', [
                'user_id' => $userId,
                'montant' => $montant,
                'transaction_id' => $transactionId,
                'ip' => $request->ip(),
            ]);

            return response('received');
        }

        // --- 4. Statut RÉEL demandé à l'API ------------------------------------
        $statutAnnonce = (int) ($parametres['status'] ?? 0);
        $verification = $this->monetbil->checkPayment($transactionId);

        Log::info('Monetbil crédits : notification reçue', [
            'user_id' => $userId,
            'montant' => $montant,
            'transaction_id' => $transactionId,
            'statut_annonce' => $statutAnnonce,
            'verifie' => $verification['ok'],
            'statut_reel' => $verification['statut'],
            'testmode' => $verification['testmode'],
        ]);

        if (! ($verification['ok'] && $verification['succes'])) {
            return response('received');
        }

        // --- 5. Contrôle du MONTANT confirmé par l'API -------------------------
        //
        // **Faute que ce contrôle ferme :** le montant n'était comparé à rien, donc
        // un règlement de 500 FCFA débloquait les crédits d'un palier de 2500. On
        // compare le montant confirmé à celui de la notification : les deux
        // doivent concorder, sinon la notification ne décrit pas le même paiement.
        $montantConfirme = $verification['montant'];

        if ($montantConfirme !== null && $montantConfirme !== $montant) {
            Log::warning('Monetbil crédits : montant confirmé différent de l\'annoncé', [
                'user_id' => $userId,
                'montant_annonce' => $montant,
                'montant_confirme' => $montantConfirme,
                'transaction_id' => $transactionId,
            ]);

            return response('received');
        }

        // --- 6. Crédit idempotent ----------------------------------------------
        //
        // La référence est le `transaction_id` : un rejeu de la même notification
        // (ou un double envoi) retrouve la transaction déjà enregistrée et ne
        // crédite pas deux fois. C'est ce qui remplace l'anti-rejeu d'une signature
        // sans horodatage.
        $credits = $this->catalogue->creditsPour($montant) ?? $montant;

        $resultat = $this->credits->creditIfNotProcessed(
            user: $utilisateur,
            amount: $credits,
            reference: 'monetbil:'.$transactionId,
            description: 'Achat de '.$credits.' crédits via Monetbil',
            metadata: [
                'purpose' => 'credit_purchase',
                'gateway' => PaymentGatewayRegistry::MONETBIL,
                'transaction_id' => $transactionId,
                'montant_fcfa' => $montant,
                'testmode' => $verification['testmode'],
            ],
        );

        if (! ($resultat['ok'] ?? false)) {
            Log::error('Monetbil crédits : versement impossible', [
                'user_id' => $userId,
                'transaction_id' => $transactionId,
            ]);

            // Répondre 200 malgré l'échec : faire réessayer Monetbil indéfiniment
            // sur une erreur interne ne la résoudra pas. La trace permet le
            // traitement manuel.
            return response('received');
        }

        Log::info('Monetbil crédits : crédits versés', [
            'user_id' => $userId,
            'credits' => $credits,
            'transaction_id' => $transactionId,
            'deja_traite' => $resultat['already_processed'] ?? false,
        ]);

        return response('received');
    }

    /**
     * Retour de la passerelle (statut non fiable — on redirige vers le compte).
     * Le crédit sera appliqué par le webhook payment.completed.
     */
    public function return(Request $request): RedirectResponse
    {
        // Signature vérifiée ? Si oui, on peut afficher l'état (mais pas créditer)
        $verified = $this->kpay->verifyReturnSignature($request->query());

        Log::info('Retour passerelle KPay', [
            'status' => $request->query('status'),
            'externalId' => $request->query('externalId'),
            'verified' => $verified,
        ]);

        if ($request->query('status') === 'COMPLETED') {
            return redirect()->route('account.index')
                ->with('info', 'Paiement reçu ! Vos crédits seront ajoutés dans quelques instants (confirmation KPay).');
        }

        return redirect()->route('account.index')
            ->with('error', 'Paiement annulé ou échoué. Aucun crédit débité.');
    }

    /**
     * Annulation par l'utilisateur depuis la passerelle.
     */
    public function cancel(Request $request): RedirectResponse
    {
        return redirect()->route('account.index')
            ->with('error', 'Achat de crédits annulé.');
    }

    /**
     * Retour Monetbil d'un achat de crédits.
     *
     * **Pourquoi un handler distinct de celui de KPay.** Monetbil ne signe PAS son
     * retour : ses paramètres n'ont aucune valeur probante, et il ne transmet pas
     * de statut exploitable. Le handler KPay, lui, attend un `status=COMPLETED`
     * signé et retombe sinon sur « paiement annulé ou échoué » — un message FAUX
     * sur un paiement réussi, qui enverrait le client chercher un problème
     * inexistant. On affiche donc l'attente honnête : la notification fait foi.
     *
     * La confirmation arrive par la notification (`notifyMonetbil`), qui vérifie la
     * signature et interroge l'API. Aucun crédit n'est versé ici — le retour n'est
     * jamais une preuve de paiement.
     */
    public function returnMonetbil(Request $request): RedirectResponse
    {
        Log::info('Retour Monetbil d\'un achat de crédits', [
            'transaction_id' => $request->query('transaction_id'),
        ]);

        return redirect()->route('account.index')
            ->with('info', 'Votre paiement est en cours de confirmation par l\'opérateur. '
                .'Vos crédits seront ajoutés dès réception de la confirmation.');
    }

    /**
     * Webhook KPay — SOURCE D'AUTORITÉ du crédit.
     *
     * - Vérifie la signature HMAC sur le corps brut
     * - Ne traite QUE les statuts terminaux (completed/failed/cancelled)
     * - Idempotent via metadata.status sur la transaction de crédit
     * - Répond 200 immédiatement (traitement léger)
     */
    public function webhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-KPAY-Signature', '');
        $event = $request->header('X-KPAY-Event', '');

        // 1. Vérification de la signature
        if (! $this->kpay->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('KPay webhook : signature invalide', [
                'event' => $event,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = $request->json()->all();
        $status = strtolower((string) ($payload['status'] ?? $payload['event'] ?? ''));
        $paymentId = (string) ($payload['paymentId'] ?? '');
        $externalId = (string) ($payload['externalId'] ?? '');
        $amount = (int) ($payload['amount'] ?? 0);
        $metadata = (array) ($payload['metadata'] ?? []);

        Log::info('KPay webhook reçu', [
            'event' => $event,
            'status' => $status,
            'paymentId' => $paymentId,
            'externalId' => $externalId,
        ]);

        // 2. Seuls les statuts terminaux engagent une décision métier
        if (! in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            return response()->json(['status' => 'ignored_non_terminal'], 200);
        }

        // 3. Récupérer l'utilisateur depuis les métadonnées
        //
        // **Deux sources, et l'ordre compte.** La métadonnée `user_id` est fournie
        // par les flux qui connaissent l'utilisateur (achat de crédits, abonnement).
        // Un lien de paiement, lui, transmet `payment_link_id` et pas `user_id` :
        // sans ce repli, le webhook répondait `user_not_found` et SORTAIT, avant
        // même d'examiner le `purpose`. Le lien n'était donc jamais réglé — le
        // montant était encaissé côté opérateur et le lien restait « en attente »,
        // donc payable une seconde fois.
        $userId = (int) ($metadata['user_id'] ?? 0);

        if ($userId === 0 && (int) ($metadata['payment_link_id'] ?? 0) > 0) {
            $userId = (int) (PaymentLink::whereKey((int) $metadata['payment_link_id'])->value('user_id') ?? 0);
        }

        $user = $userId > 0 ? User::find($userId) : null;

        // Un lien de paiement sans destinataire (client externe) est légitime :
        // le règlement doit être enregistré même sans compte à créditer. Exiger un
        // utilisateur ici perdrait la trace du paiement — c'est pourquoi le repli
        // ci-dessous existe plutôt qu'un simple `return`.
        $purpose = (string) ($metadata['purpose'] ?? 'credit_purchase');
        $estLienSansCompte = $purpose === 'payment_link' && (int) ($metadata['payment_link_id'] ?? 0) > 0;

        if (! $user && ! $estLienSansCompte) {
            Log::warning('KPay webhook : utilisateur introuvable', ['user_id' => $userId, 'paymentId' => $paymentId]);

            return response()->json(['status' => 'user_not_found'], 200);
        }

        // 4. Décision métier
        // (L'idempotence des achats de crédits est garantie ATOMIQUEMENT par
        // CreditService::creditIfNotProcessed — vérification + crédit dans la
        // même transaction, sérialisée par lockForUpdate sur la ligne user.
        // Le check-then-act historique (exists() puis credit()) laissait une
        // fenêtre de course où deux webhooks concurrents pouvaient doubler.)

        if ($status === 'completed') {
            // --- Lien de paiement -------------------------------------------
            //
            // Traité AVANT l'achat de crédits, et pour une raison précise : sans
            // cette branche, un lien de paiement réglé en ligne tombait dans le
            // chemin « achat de crédits », qui créditait le montant au nom de
            // l'utilisateur SANS jamais marquer le lien comme payé. Le lien restait
            // « en attente » alors que l'argent était encaissé — donc payable une
            // seconde fois — et rien ne reliait le versement au lien d'origine.
            //
            // Le règlement passe par `PaymentLinkService`, qui verrouille la ligne
            // et garantit un versement unique même si le retour de passerelle a déjà
            // traité le paiement : c'est le cas nominal, pas théorique.
            if ($purpose === 'payment_link') {
                $lienId = (int) ($metadata['payment_link_id'] ?? 0);
                $lien = $lienId > 0 ? PaymentLink::find($lienId) : null;

                if ($lien === null) {
                    Log::warning('KPay webhook : lien de paiement introuvable', [
                        'payment_link_id' => $lienId,
                        'paymentId' => $paymentId,
                    ]);

                    return response()->json(['status' => 'link_not_found'], 200);
                }

                $kpayLocal = KpayPayment::where('external_id', $externalId)->first();

                $reglement = app(PaymentLinkService::class)->regler(
                    lien: $lien,
                    reference: $paymentId,
                    kpayPaymentId: $kpayLocal?->id,
                );

                Log::info('KPay webhook : lien de paiement traité', [
                    'lien_id' => $lien->id,
                    'paymentId' => $paymentId,
                    'ok' => $reglement['ok'] ?? false,
                    'motif' => $reglement['motif'] ?? null,
                    'credits_verses' => $reglement['credits_verses'] ?? null,
                ]);

                // Un échec de règlement (lien expiré entre-temps, destinataire
                // introuvable) n'est PAS une erreur du webhook : répondre 500 ferait
                // réessayer KPay indéfiniment sur une situation qu'un réessai ne
                // résoudra pas. On répond 200 et on laisse la trace pour traitement
                // manuel.
                return response()->json([
                    'status' => ($reglement['ok'] ?? false) ? 'processed' : 'link_not_registrable',
                ], 200);
            }

            // --- Abonnement (souscription ou renouvellement) ---
            if (in_array($purpose, ['subscription', 'subscription_renewal'], true)) {
                $activated = app(SubscriptionService::class)
                    ->activateFromWebhook($payload, $metadata);

                Log::info('KPay webhook : abonnement traité', [
                    'user_id' => $user->id,
                    'purpose' => $purpose,
                    'paymentId' => $paymentId,
                    'activated' => $activated,
                ]);

                return response()->json(['status' => 'processed'], 200);
            }

            // --- Achat de crédits (comportement historique) ---
            $credits = (int) ($metadata['credits'] ?? $amount);

            $result = $this->credits->creditIfNotProcessed(
                $user,
                $credits,
                reference: $paymentId,
                description: 'Achat de '.$credits.' crédits via KPay',
                metadata: [
                    'purpose' => 'credit_purchase',
                    'payment_status' => 'completed',
                    'payment_id' => $paymentId,
                    'external_id' => $externalId,
                    'amount_fcfa' => $amount,
                ],
            );

            // Déjà crédité (webhook dupliqué / retry KPay) → réponse idempotente
            if (($result['already_processed'] ?? false)) {
                Log::info('KPay webhook : paiement déjà traité (idempotence)', [
                    'user_id' => $user->id,
                    'paymentId' => $paymentId,
                    'credits' => $credits,
                ]);

                // On met quand même à jour le registre local de fallback
                KpayPayment::query()
                    ->where('external_id', $externalId)
                    ->update([
                        'status' => strtoupper($status),
                        'paid_at' => $status === 'completed' ? now() : null,
                        'last_synced_at' => now(),
                    ]);

                return response()->json(['status' => 'already_processed'], 200);
            }

            if (! ($result['ok'] ?? false)) {
                Log::error('KPay webhook : crédit impossible', [
                    'user_id' => $user->id,
                    'paymentId' => $paymentId,
                    'credits' => $credits,
                ]);

                return response()->json(['status' => 'credit_failed'], 500);
            }

            // Facture PDF + email de confirmation
            $invoiceService = app(InvoiceService::class);
            $invoice = $invoiceService->createForCreditPurchase(
                $user,
                amount: $amount,
                currency: (string) ($payload['currency'] ?? 'XAF'),
                reference: $paymentId,
                status: 'paid',
            );

            // Chemin absolu du PDF pour la pièce jointe
            $pdfStoragePath = $invoiceService->downloadPath($invoice);
            $pdfPath = $pdfStoragePath
                ? Storage::disk((string) config('billing.invoice_storage_disk', 'local'))
                    ->path($pdfStoragePath)
                : null;

            try {
                Mail::to($user->email)->send(new PaymentReceipt(
                    user: $user,
                    invoice: $invoice,
                    label: 'Achat de '.$credits.' crédits',
                    pdfPath: $pdfPath,
                ));
                Log::info('KPay webhook : email de reçu envoyé', [
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'paymentId' => $paymentId,
                ]);
            } catch (\Throwable $e) {
                Log::error('KPay webhook : échec envoi email de reçu', [
                    'user_id' => $user->id,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('KPay webhook : crédits ajoutés', [
                'user_id' => $user->id,
                'credits' => $credits,
                'paymentId' => $paymentId,
                'invoice_id' => $invoice->id,
            ]);
        } else {
            // failed / cancelled → journaliser (aucun crédit).
            //
            // **Un lien de paiement s'arrête ici.** Son échec ne se traite pas comme
            // celui d'un achat de crédits : il n'y a rien à rembourser (aucun crédit
            // n'a été versé) et rien à notifier par e-mail. Le lien reste
            // simplement « en attente », ce qui est le bon état : le client peut
            // réessayer tant qu'il n'est pas expiré. Envoyer un e-mail d'échec au
            // destinataire serait même contre-productif — les abandons de paiement
            // mobile money sont fréquents et souvent involontaires.
            if ($purpose === 'payment_link') {
                Log::info('KPay webhook : règlement de lien échoué ou annulé', [
                    'paymentId' => $paymentId,
                    'status' => $status,
                    'reason' => $payload['failureReason'] ?? null,
                ]);

                return response()->json(['status' => 'link_not_paid'], 200);
            }

            // Un lien de paiement sans compte destinataire n'a pas d'utilisateur à
            // qui écrire : on ne va pas plus loin plutôt que de déréférencer null.
            if ($user === null) {
                return response()->json(['status' => 'no_user_to_notify'], 200);
            }

            // Pour un abonnement : marquer past_due (grace period)
            $failedSubscription = null;
            if (in_array($purpose, ['subscription', 'subscription_renewal'], true)) {
                $subscriptionId = (int) ($metadata['subscription_id'] ?? 0);
                $subscription = $subscriptionId > 0
                    ? Subscription::find($subscriptionId)
                    : $user->activeSubscription;

                if ($subscription) {
                    app(SubscriptionService::class)
                        ->markFailedPayment($subscription);
                    $failedSubscription = $subscription;
                }
            }

            // Email de notification d'échec (ne bloque jamais le webhook)
            try {
                Mail::to($user->email)->send(new PaymentFailedMail(
                    user: $user,
                    amount: $amount,
                    currency: (string) ($payload['currency'] ?? 'XAF'),
                    reference: $paymentId,
                    subscription: $failedSubscription,
                ));
                Log::info('KPay webhook : email d\'échec de paiement envoyé', [
                    'user_id' => $user->id,
                    'paymentId' => $paymentId,
                    'status' => $status,
                ]);
            } catch (\Throwable $e) {
                Log::error('KPay webhook : échec envoi email d\'échec de paiement', [
                    'user_id' => $user->id,
                    'paymentId' => $paymentId,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('KPay webhook : paiement non abouti', [
                'status' => $status,
                'paymentId' => $paymentId,
                'user_id' => $user->id,
                'purpose' => $purpose,
            ]);
        }

        // Mise à jour du registre local de synchronisation (fallback webhook)
        KpayPayment::query()
            ->where('external_id', $externalId)
            ->update([
                'status' => strtoupper($status),
                'paid_at' => $status === 'completed' ? now() : null,
                'last_synced_at' => now(),
            ]);

        return response()->json(['status' => 'processed'], 200);
    }
}
