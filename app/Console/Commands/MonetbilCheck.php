<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\CreditPackCatalog;
use App\Services\Billing\MonetbilService;
use App\Services\Billing\MonetbilServiceRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('monetbil:check
    {--service= : Référence du service ou du palier (ex. pack_1000, svc_1000)}
    {--amount=1000 : Montant FCFA du paiement de test (résout le service si --service est absent)}
    {--transaction= : Vérifie un paiement existant (checkPayment) au lieu d\'en initier un}
    {--force : Initie le paiement SANS demander de confirmation}')]
#[Description('Diagnostic Monetbil : vérifie le protocole réel (URL widget signée et checkPayment) avant la recette.')]
class MonetbilCheck extends Command
{
    /**
     * Diagnostic de l'intégration Monetbil, à lancer contre le VRAI service.
     *
     * **Pourquoi cette commande existe.** Le protocole d'initialisation
     * (`POST /widget/v2.1/{serviceKey}` renvoyant `payment_url`) est le SEUL point
     * de l'intégration qui n'a jamais été éprouvé autrement que par `Http::fake`.
     * Un faux HTTP valide notre compréhension, pas la réalité du fournisseur : si
     * le chemin ou le format de réponse diffèrent, l'échec ne se verrait qu'en
     * production, au premier paiement d'un client.
     *
     * Cette commande permet donc de trancher AVANT la recette, avec les
     * identifiants réels, sans passer par l'interface d'achat.
     *
     * **Ce qu'elle ne fait pas.** Elle n'écrit rien en base (aucun crédit, aucune
     * trace) et ne modifie aucun réglage : elle se contente d'appeler l'API et de
     * rapporter la réponse. Un paiement initié ici n'est pas rattaché à un compte
     * (le champ `user` est omis), donc aucune notification ne pourra le créditer —
     * c'est un test, pas un encaissement.
     */
    public function handle(
        MonetbilService $monetbil,
        MonetbilServiceRegistry $services,
        CreditPackCatalog $catalogue,
    ): int {
        $transactionId = (string) ($this->option('transaction') ?? '');

        if ($transactionId !== '') {
            return $this->verifierPaiement($monetbil, $transactionId);
        }

        $service = $this->resoudreService($services, $catalogue);

        if ($service === null) {
            $this->error('Aucun service Monetbil exploitable pour cette référence.');
            $this->line('Renseignez `monetbil.services.*` (id, key, secret) ou passez --service=pack_1000.');

            return self::FAILURE;
        }

        return $this->initierPaiement($monetbil, $service, $services);
    }

    /**
     * Résout le service de test depuis `--service` ou, à défaut, depuis `--amount`.
     *
     * @param  array{id: string, key: string, secret: string}|null
     */
    private function resoudreService(
        MonetbilServiceRegistry $services,
        CreditPackCatalog $catalogue,
    ): ?array {
        $reference = (string) ($this->option('service') ?? '');

        if ($reference !== '') {
            $service = $services->pourReference($reference);
        } else {
            $service = $services->pourMontant((int) $this->option('amount'), $catalogue);
        }

        return $services->estExploitable($service) ? $service : null;
    }

    /**
     * Initie un paiement de test et rapporte le protocole réellement observé.
     *
     * @param  array{id: string, key: string, secret: string}  $service
     */
    private function initierPaiement(
        MonetbilService $monetbil,
        array $service,
        MonetbilServiceRegistry $services,
    ): int {
        $montant = (int) $this->option('amount');
        $reference = 'CHECK'.Str::uuid();

        $this->info('Diagnostic Monetbil — initiation d\'un paiement de TEST');
        $this->line('  service   : '.$service['id'].' (clé '.substr($service['key'], 0, 6).'…)');
        $this->line('  montant   : '.$montant.' FCFA');
        $this->line('  URL widget: '.$monetbil->widgetUrl($service['key']));
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Initier un vrai appel à Monetbil ?', true)) {
            $this->warn('Annulé.');

            return self::SUCCESS;
        }

        $resultat = $monetbil->url(
            amountFcfa: $montant,
            paymentRef: $reference,
            returnUrl: route('credits.return.monetbil'),
            notifyUrl: route('credits.notify'),
            itemRef: 'CHECK',
            service: $service,
        );

        if (! ($resultat['ok'] ?? false)) {
            $this->error('ÉCHEC : '.($resultat['message'] ?? 'raison inconnue'));
            $this->newLine();
            $this->line('Le protocole est donc à revoir. Vérifier dans storage/logs/laravel.log');
            $this->line('la ligne « Monetbil : initialisation du paiement refusée » (statut + corps).');

            return self::FAILURE;
        }

        $this->info('SUCCÈS — le protocole est conforme.');
        $this->line('  payment_url : '.$resultat['paymentUrl']);
        $this->newLine();
        $this->line('Prochaine étape : ouvrir cette URL dans un navigateur pour confirmer');
        $this->line('l\'affichage du widget, puis relancer avec --transaction=<id> après un');
        $this->line('paiement de recette pour vérifier checkPayment().');

        return self::SUCCESS;
    }

    /**
     * Interroge `checkPayment()` sur un paiement existant.
     *
     * C'est la source d'AUTORITÉ sur le statut : la commande traduit le code
     * retourné pour confirmer les tables de statuts (1 = succès, 7 = succès de
     * test, etc.), que seul un vrai paiement permet de valider.
     */
    private function verifierPaiement(MonetbilService $monetbil, string $transactionId): int
    {
        $this->info('Diagnostic Monetbil — vérification d\'un paiement');
        $this->line('  transaction : '.$transactionId);
        $this->newLine();

        $resultat = $monetbil->checkPayment($transactionId);

        if (! ($resultat['ok'] ?? false)) {
            $this->error('checkPayment indisponible ou transaction inconnue.');

            return self::FAILURE;
        }

        $statut = (int) ($resultat['statut'] ?? -1);

        $this->line('  statut      : '.$statut);
        $this->line('  succès ?    : '.($resultat['succes'] ? 'OUI' : 'non'));
        $this->line('  mode test ? : '.($resultat['testmode'] ? 'OUI' : 'non'));
        $this->line('  abandon ?   : '.($monetbil->estUnAbandon($statut) ? 'OUI' : 'non'));
        $this->line('  montant     : '.($resultat['montant'] ?? '—').' FCFA');
        $this->line('  téléphone   : '.($resultat['telephone'] ?? '—'));
        $this->newLine();

        $this->line('Comparer ces valeurs avec config/monetbil.php → status.');

        return self::SUCCESS;
    }
}
