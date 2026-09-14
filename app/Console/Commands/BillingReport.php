<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiUsageLedger;
use App\Services\Billing\UsageCostCalculator;
use App\Services\Billing\UsageLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Rapport de rentabilité des appels IA (R7).
 *
 * **À quoi il sert.** Le registre d'usage (`ai_usage_ledger`) enregistre chaque
 * tentative facturée, mais un registre ne se lit pas. Cette commande en tire les
 * trois chiffres qui décident d'un prix :
 *
 *  1. **le coût réellement dû au fournisseur** — retries et échecs inclus, donc
 *     supérieur à la somme des appels qui ont abouti ;
 *  2. **la fuite sèche** — ce qui a été payé sans contrepartie pour
 *     l'utilisateur. Un pourcentage élevé ne signale pas une erreur de
 *     facturation mais une instabilité de fournisseur : c'est un chiffre à
 *     surveiller, pas à corriger ;
 *  3. **le taux de reversibilité** — la part du coût qui a été refacturée à
 *     l'utilisateur. En dessous de 1, la marge absorbée ne vient pas d'un choix
 *     commercial mais d'un écart de calcul.
 *
 * Les remboursements partiels (R7.4) font baisser ce taux de façon **voulue** :
 * un échec d'outil n'est pas facturé. Sans cette lecture, on pourrait croire à
 * une fuite alors qu'il s'agit d'une décision.
 */
#[Signature('billing:report
    {--from= : Début de période (date ou datetime)}
    {--to= : Fin de période (date ou datetime)}
    {--user= : Restreint le rapport à un identifiant utilisateur}
    {--document= : Restreint le rapport à un identifiant de document}
    {--verify : Contrôle la recalculabilité du coût depuis les données brutes}')]
#[Description('Rapport de rentabilité des appels IA (coût réel, retries et échecs inclus) depuis le registre d\'usage')]
class BillingReport extends Command
{
    public function handle(UsageLedger $ledger, UsageCostCalculator $calculateur): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $userId = $this->option('user');
        $documentId = $this->option('document');

        $requete = AiUsageLedger::query();

        // Les filtres sont appliqués à la main (et non via les méthodes de
        // `UsageLedger`) parce que ce rapport doit pouvoir croiser DEUX
        // restrictions à la fois (un utilisateur ET une période), ce que les
        // méthodes dédiées exposent séparément.
        if ($from !== null) {
            $requete->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $requete->where('created_at', '<=', $to);
        }

        if ($userId !== null) {
            $requete->where('user_id', (int) $userId);
        }

        if ($documentId !== null) {
            $requete->where('document_id', (int) $documentId);
        }

        $lignes = $requete->get();

        if ($lignes->isEmpty()) {
            $this->components->warn('Aucune ligne dans le registre d\'usage pour ce périmètre.');

            return self::SUCCESS;
        }

        $this->renderTotaux($lignes, $calculateur);
        $this->renderParModele($ledger, $from, $to);

        if ($this->option('verify')) {
            $this->renderVerification($ledger);
        }

        return self::SUCCESS;
    }

    /**
     * Totaux de la période : coût, tentative, fuite sèche, reversibilité.
     *
     * @param  Collection<int, AiUsageLedger>  $lignes
     */
    private function renderTotaux($lignes, UsageCostCalculator $calculateur): void
    {
        $tentatives = $lignes->count();
        $echecs = $lignes->where('succeeded', false)->count();
        $bascules = $lignes->where('is_fallback', true)->count();
        $estimees = $lignes->where('estimated', true)->count();

        $coutUsd = (float) $lignes->sum('cost_usd');
        $coutCredits = (int) $lignes->sum('cost_credits');
        $fuiteCredits = (int) $lignes->where('succeeded', false)->sum('cost_credits');
        $tokens = (int) $lignes->sum('input_tokens') + (int) $lignes->sum('output_tokens');

        // Reversibilité : ce que la grille tarifaire aurait facturé pour ces
        // mêmes tokens, rapporté au coût réellement reversé au registre. Un
        // ratio inférieur à 1 signale que le coefficient de rentabilité a été
        // absorbé par des coûts non refacturés (échecs, remboursements).
        $theorique = $calculateur->usdToCredits($coutUsd);

        $this->components->info('Rapport de rentabilité IA');

        $this->table(
            ['Indicateur', 'Valeur'],
            [
                ['Tentatives facturées', (string) $tentatives],
                ['Échecs (retries inclus)', $echecs.' ('.($tentatives > 0 ? round($echecs / $tentatives * 100, 1) : 0).' %)'],
                ['Bascules de fournisseur', (string) $bascules],
                ['Tokens consommés', number_format($tokens, 0, ',', ' ')],
                ['Coût réel USD', number_format($coutUsd, 6, ',', ' ').' $'],
                ['Coût réel crédits', number_format($coutCredits, 0, ',', ' ').' FCFA'],
                ['Fuite sèche (échecs)', number_format($fuiteCredits, 0, ',', ' ').' FCFA ('.($coutCredits > 0 ? round($fuiteCredits / $coutCredits * 100, 1) : 0).' %)'],
                ['Prix théorique grille', number_format($theorique, 0, ',', ' ').' FCFA'],
                ['Coefficient appliqué', number_format($calculateur->profitabilityCoefficient(), 2, ',', ' ').'×'],
            ],
        );

        if ($estimees > 0) {
            // Signalé explicitement : une ligne estimée ne peut pas servir de
            // base à un remboursement au centime près.
            $this->components->warn($estimees.' ligne(s) à tokens ESTIMÉS (échec ou repli) — coût approché.');
        }
    }

    /**
     * Répartition par modèle, pour identifier le poste qui pèse.
     */
    private function renderParModele(UsageLedger $ledger, ?string $from, ?string $to): void
    {
        $lignes = $ledger->breakdownByModel($from, $to);

        if ($lignes === []) {
            return;
        }

        $this->newLine();
        $this->components->info('Répartition par modèle');

        $this->table(
            ['Modèle', 'Fournisseur', 'Tentatives', 'Échecs', 'Tokens', 'Crédits'],
            array_map(static fn (array $l): array => [
                $l['model'],
                $l['provider'],
                (string) $l['attempts'],
                (string) $l['failures'],
                number_format($l['tokens'], 0, ',', ' '),
                number_format($l['credits'], 0, ',', ' '),
            ], $lignes),
        );
    }

    /**
     * Contrôle de recalculabilité du coût.
     */
    private function renderVerification(UsageLedger $ledger): void
    {
        $resultat = $ledger->recalculabilite();

        $this->newLine();
        $this->components->info('Recalculabilité du coût depuis les données brutes');

        $this->table(
            ['Indicateur', 'Valeur'],
            [
                ['Lignes vérifiées', (string) $resultat['verifiees']],
                ['Lignes cohérentes', (string) $resultat['coherentes']],
                ['Écarts', (string) $resultat['ecarts']],
                ['Lignes estimées (exclues)', (string) $resultat['estimees']],
                ['Taux de cohérence', ($resultat['taux'] * 100).' %'],
            ],
        );

        foreach ($resultat['exemples'] as $ecart) {
            $this->components->error(sprintf(
                'Ligne %d (%s) : %d crédits stockés, %d recalculés',
                $ecart['id'],
                $ecart['model'],
                $ecart['credits_stockes'],
                $ecart['credits_recalcules'],
            ));
        }

        if ($resultat['ecarts'] > 0) {
            // Un écart signifie qu'un tarif a changé sans retraitement des lignes
            // historiques. Les lignes STOCKÉES restent la référence facturée ;
            // c'est le prix courant qui doit être vérifié.
            $this->components->warn(
                'Des écarts existent : un tarif de `config/openrouter.php` a probablement changé. '
                .'Les lignes stockées restent la référence facturée.'
            );
        }
    }
}
