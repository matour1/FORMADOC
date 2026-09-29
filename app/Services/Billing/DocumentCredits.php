<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Facturation des traitements IA appliqués à un document.
 *
 * **Le défaut que ce service rend impossible.** Deux chemins envoyaient le
 * document (ou ses éléments) au modèle SANS qu'aucun crédit ne soit débité :
 *
 *   - le mode « IA complète » (`DeepSeekAnalyzer`, `title_method = ia`), qui
 *     envoie le document ENTIER à l'API DeepSeek ;
 *   - l'« assistance IA » (`AiCorrectionService`, `use_ai`), qui lui envoie les
 *     éléments ambigus.
 *
 * Les deux appelaient l'API en direct, hors de `OpenRouterService` — donc hors
 * du registre d'usage (`AiUsageLedger`) et hors de tout calcul de coût. Aucun
 * débit n'avait lieu : les deux modes étaient gratuits alors qu'ils consomment
 * réellement.
 *
 * **Comment on facture ici.** Le débit se fait en DEUX temps, comme le chat :
 *   1. AVANT l'appel, un débit estimé (`estimated: true`) — c'est ce qui
 *      empêche un utilisateur sans crédits de lancer un traitement long ;
 *   2. APRÈS l'appel, un AJUSTEMENT à la différence entre l'estimation et le
 *      coût réel mesuré sur les tokens effectivement échangés.
 *
 * Facturer directement le coût réel serait plus simple mais laisserait passer un
 * document volumineux sur un solde insuffisant ; débiter une estimation sans
 * ajuster ferait payer au-dessus ou en dessous du réel. Le couple débit +
 * ajustement est le seul qui garantisse les deux.
 */
final class DocumentCredits
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly UsageCostCalculator $calculator,
    ) {}

    /**
     * Débite l'estimation et renvoie la référence de l'opération.
     *
     * @param  array{gratuit: bool, credits: int}  $estimation  Sortie de `DocumentModePricing::estimate()`
     * @return array{ok: bool, reference: string, debited: int, reason: null|string}
     */
    public function debitEstimation(User $user, Document $document, string $mode, array $estimation): array
    {
        // Le mode déterministe n'appelle aucune API : rien à facturer, et ce
        // n'est pas une exception mais le comportement normal de ce mode.
        if (($estimation['gratuit'] ?? true) || ($estimation['credits'] ?? 0) <= 0) {
            return ['ok' => true, 'reference' => 'document:'.$document->id.':'.$mode, 'debited' => 0, 'reason' => null];
        }

        $montant = (int) $estimation['credits'];
        $reference = 'document:'.$document->id.':'.$mode;

        $debit = $this->credits->debit(
            $user,
            $montant,
            type: 'usage',
            reference: $reference,
            description: 'Analyse du document #'.$document->id.' ('.$mode.')',
            metadata: [
                'purpose' => 'document_analysis',
                'document_id' => $document->id,
                'mode' => $mode,
                'estimated' => true,
            ],
        );

        return [
            'ok' => $debit['ok'],
            'reference' => $reference,
            'debited' => $debit['ok'] ? $montant : 0,
            'reason' => $debit['ok'] ? null : ($debit['reason'] ?? 'débit refusé'),
        ];
    }

    /**
     * Ajuste après l'appel : rembourse l'écart si le coût réel est inférieur.
     *
     * **On ne débite jamais un complément.** Si le coût réel dépasse
     * l'estimation, la différence est absorbée par l'application. Débiter après
     * coup un utilisateur qui a déjà reçu son résultat produirait un solde
     * négatif ou une réclamation, pour un écart que l'estimation doit couvrir.
     * C'est le choix déjà retenu par le chat (voir `ChatController`).
     *
     * **Le contrat de retour est UNIQUE, quelle que soit la branche.** La
     * première version renvoyait `['adjusted', 'actual', 'usd']` sur le chemin
     * normal et `['refunded']` sur le chemin « prix inconnu » — deux formes qui
     * obligeaient l'appelant à savoir quel chemin avait été pris pour lire le
     * résultat. Une vérification de bout en bout a échoué dessus (« Undefined
     * array key "adjusted" ») : c'est le signe qu'un appelant ne peut pas
     * consommer ce retour sans connaître l'implémentation.
     *
     * @param  array{input_tokens: int, output_tokens: int}  $usage  Usage réel remonté par l'API
     * @param  int  $coutSupplementaireCredits  Coût d'une AUTRE passe IA, déjà mesuré en
     *                                          crédits (le classifieur de blocs, qui
     *                                          n'expose pas de compteurs de tokens)
     * @return array{adjusted: int, actual: null|int, usd: null|float, price_known: bool, passes: int}
     */
    public function ajusterApresAppel(
        User $user,
        Document $document,
        string $mode,
        int $estimationCredits,
        string $reference,
        array $usage,
        string $model,
        int $coutSupplementaireCredits = 0,
        bool $succeeded = true,
    ): array {
        if ($estimationCredits <= 0 && $coutSupplementaireCredits <= 0) {
            return ['adjusted' => 0, 'actual' => 0, 'usd' => 0.0, 'price_known' => true, 'passes' => 0];
        }

        $usd = $this->calculator->costUsdFor($model, $usage['input_tokens'], $usage['output_tokens']);

        // Prix inconnu : on ne peut pas calculer l'écart. On rembourse
        // l'intégralité plutôt que de conserver un montant dont on ne sait pas
        // s'il correspond à quoi que ce soit. Un débit non justifié est pire
        // qu'un débit nul.
        if ($usd === null) {
            Log::warning('DocumentCredits : prix du modèle inconnu, remboursement intégral', [
                'document_id' => $document->id,
                'mode' => $mode,
                'model' => $model,
            ]);

            $this->rembourser($user, $document, $estimationCredits, $reference, 'prix_inconnu');

            return [
                'adjusted' => $estimationCredits,
                'actual' => null,
                'usd' => null,
                'price_known' => false,
                'passes' => 1,
            ];
        }

        // Coût réel TOTAL : la passe mesurée en tokens, PLUS toute autre passe
        // dont le coût est déjà connu. Additionner les deux est le seul moyen de
        // ne pas laisser la seconde à la charge de l'application.
        $reel = $this->calculator->usdToCredits($usd) + max(0, $coutSupplementaireCredits);
        $ecart = $estimationCredits - $reel;

        // Le coût réel dépasse l'estimation : l'application absorbe, on ne
        // prélève pas de complément (voir le commentaire de méthode). On le
        // journalise pour que l'écart soit visible et l'estimation corrigeable.
        if ($ecart < 0) {
            Log::info('DocumentCredits : coût réel supérieur à l\'estimation, absorbé', [
                'document_id' => $document->id,
                'mode' => $mode,
                'estimation' => $estimationCredits,
                'reel' => $reel,
                'supplement' => $coutSupplementaireCredits,
                'ecart' => $ecart,
            ]);
        }

        if ($ecart > 0) {
            $this->credits->credit(
                $user,
                $ecart,
                type: 'refund',
                reference: $reference,
                description: 'Ajustement analyse document #'.$document->id.' (coût réel inférieur)',
                metadata: [
                    'purpose' => 'document_analysis',
                    'document_id' => $document->id,
                    'mode' => $mode,
                    'adjustment' => true,
                ],
            );
        }

        return [
            'adjusted' => max(0, $ecart),
            'actual' => $reel,
            'usd' => $usd,
            'price_known' => true,
            'passes' => $coutSupplementaireCredits > 0 ? 2 : 1,
        ];
    }

    /**
     * Rembourse l'intégralité du débit (échec du traitement).
     *
     * Un traitement qui échoue n'a produit aucun résultat : le conserver serait
     * facturer un service non rendu.
     */
    public function rembourser(
        User $user,
        Document $document,
        int $montant,
        string $reference,
        string $raison,
    ): array {
        if ($montant <= 0) {
            return ['refunded' => 0];
        }

        $this->credits->credit(
            $user,
            $montant,
            type: 'refund',
            reference: $reference,
            description: 'Remboursement analyse document #'.$document->id,
            metadata: [
                'purpose' => 'document_analysis',
                'document_id' => $document->id,
                'refund_reason' => $raison,
            ],
        );

        return ['refunded' => $montant];
    }
}
