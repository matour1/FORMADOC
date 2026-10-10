<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Catalogue des paliers de recharge de crédits.
 *
 * **Pourquoi une classe, et non une lecture directe de la configuration.** Deux
 * endroits consomment les paliers : l'interface (qui les affiche) et le contrôleur
 * d'achat (qui VALIDE le montant reçu). Si chacun lisait `config()` de son côté,
 * une divergence produirait un défaut silencieux et coûteux : un montant affiché
 * mais refusé à la validation (« ce montant n'existe pas » alors qu'il est à
 * l'écran), ou l'inverse — un montant accepté sans palier correspondant, donc
 * sans service Monetbil où l'encaisser.
 *
 * **Pourquoi des montants FIXES.** Une passerelle mobile money exige un service
 * déclaré par offre, avec ses propres clés. Un montant libre serait impossible à
 * rattacher : il faudrait un service par montant possible. Les paliers résolvent
 * cette contrainte en rendant la liste finie.
 *
 * **Un palier sans service rattaché n'est PAS proposé.** C'est le point de
 * sécurité du catalogue : `MONETBIL_SERVICE_PACK_1000` non renseigné signifie que
 * le service n'existe pas encore chez Monetbil. L'afficher mènerait le client vers
 * un encaissement impossible — mieux vaut un palier absent qu'un bouton mort.
 */
final class CreditPackCatalog
{
    /**
     * Tous les paliers déclarés, service rattaché ou non.
     *
     * @return array<int, array{montant: int, credits: int, libelle: string, description: string, populaire: bool, service: null|string, proposable: bool}>
     */
    public function tous(): array
    {
        $packs = [];

        foreach ((array) config('billing.credit_packs', []) as $pack) {
            $montant = (int) ($pack['montant'] ?? 0);

            // Un palier sans montant positif est inexploitable : on l'écarte
            // plutôt que de produire un bouton à 0 crédit.
            if ($montant <= 0) {
                continue;
            }

            $service = $pack['service'] ?? null;
            $service = is_string($service) && trim($service) !== '' ? trim($service) : null;

            $packs[] = [
                'montant' => $montant,
                'credits' => (int) ($pack['credits'] ?? $montant),
                'libelle' => (string) ($pack['libelle'] ?? $montant.' crédits'),
                'description' => (string) ($pack['description'] ?? ''),
                'populaire' => (bool) ($pack['populaire'] ?? false),
                'service' => $service,
                'proposable' => $service !== null,
            ];
        }

        return $packs;
    }

    /**
     * Paliers réellement proposables : ceux dont le service est rattaché.
     *
     * @return array<int, array{montant: int, credits: int, libelle: string, description: string, populaire: bool, service: null|string, proposable: bool}>
     */
    public function proposables(): array
    {
        return array_values(array_filter($this->tous(), fn (array $p): bool => $p['proposable']));
    }

    /**
     * Le montant correspond-il à un palier PROPOSABLE ?
     *
     * C'est le contrôle que le contrôleur d'achat applique. Il exige un palier ET
     * un service rattaché : accepter un montant sans service laisserait créer un
     * paiement qu'aucune passerelle ne pourrait encaisser.
     */
    public function estProposable(int $montant): bool
    {
        foreach ($this->proposables() as $pack) {
            if ($pack['montant'] === $montant) {
                return true;
            }
        }

        return false;
    }

    /**
     * Palier correspondant à un montant, ou `null`.
     *
     * @return null|array{montant: int, credits: int, libelle: string, description: string, populaire: bool, service: null|string, proposable: bool}
     */
    public function pourMontant(int $montant): ?array
    {
        foreach ($this->proposables() as $pack) {
            if ($pack['montant'] === $montant) {
                return $pack;
            }
        }

        return null;
    }

    /**
     * Montants proposables, sous forme de liste — pour les règles de validation.
     *
     * @return array<int, int>
     */
    public function montantsProposables(): array
    {
        return array_map(fn (array $p): int => $p['montant'], $this->proposables());
    }

    /**
     * Le libellé lisible d'un montant, pour l'affichage et la traçabilité.
     */
    public function libelle(int $montant): string
    {
        return $this->pourMontant($montant)['libelle'] ?? $montant.' crédits';
    }

    /**
     * La référence du service Monetbil rattachée à un montant, ou `null`.
     *
     * **Pourquoi la notification passe par ici.** Elle ne connaît pas la référence
     * de service : elle reçoit un MONTANT signé. C'est le seul lien vers le service
     * qui permettra de vérifier sa signature — il faut donc pouvoir remonter du
     * montant vers le service sans ambiguïté.
     *
     * **On lit `tous()` et non `proposables()`.** La notification peut arriver
     * après qu'un service a été retiré de la configuration (rotation de clés,
     * service désactivé) alors que le paiement, lui, a bien eu lieu. Refuser la
     * corrélation au seul motif que le palier n'est plus « proposable » ferait
     * perdre un encaissement légitime. Le service est rendu s'il est DÉCLARÉ, et
     * c'est la vérification de signature qui décidera de la suite.
     */
    public function serviceDe(int $montant): ?string
    {
        foreach ($this->tous() as $pack) {
            if ($pack['montant'] === $montant) {
                return $pack['service'];
            }
        }

        return null;
    }

    /**
     * Le nombre de crédits d'un montant, ou `null` si le montant n'est pas un palier.
     *
     * Sert au versement : la notification Monetbil transmet un montant, et c'est lui
     * qui détermine combien de crédits accorder — et non le montant lui-même, car la
     * grille pourrait un jour offrir des crédits bonus (un palier de 5000 qui rend
     * 6000 crédits). On lit donc la valeur DÉCLARÉE dans le palier.
     */
    public function creditsPour(int $montant): ?int
    {
        foreach ($this->tous() as $pack) {
            if ($pack['montant'] === $montant) {
                return $pack['credits'];
            }
        }

        return null;
    }
}
