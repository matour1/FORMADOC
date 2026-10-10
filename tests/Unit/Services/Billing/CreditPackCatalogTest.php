<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\CreditPackCatalog;
use Tests\TestCase;

/**
 * Catalogue des paliers de recharge de crédits.
 *
 * **Ce que ces tests protègent.** Deux endroits consomment les paliers :
 * l'interface, qui les AFFICHE, et le contrôleur d'achat, qui VALIDE le montant
 * reçu. Une divergence entre les deux produirait un défaut silencieux et coûteux :
 * un montant affiché mais refusé au paiement (« ce montant n'est pas disponible »
 * alors qu'il est à l'écran), ou l'inverse — un montant accepté sans service
 * Monetbil où l'encaisser, donc un paiement impossible qui échouerait après
 * l'engagement du client.
 *
 * **Pourquoi des montants FIXES.** Une passerelle mobile money exige un service
 * déclaré par offre, avec ses propres clés. Un montant libre serait inencaissable :
 * il faudrait un service par montant possible. Les paliers rendent la liste finie.
 *
 * **Le point de sécurité** : un palier sans service rattaché n'est PAS proposable.
 * Tant que les services Monetbil ne sont pas déclarés, l'interface doit afficher
 * « achat momentanément indisponible » plutôt que des boutons morts.
 */
class CreditPackCatalogTest extends TestCase
{
    private function catalogue(): CreditPackCatalog
    {
        return app(CreditPackCatalog::class);
    }

    /**
     * Configuration de test : trois paliers, dont deux rattachés à un service.
     *
     * @param  array<int, array<string, mixed>>  $packs
     */
    private function configurerPacks(array $packs): void
    {
        config(['billing.credit_packs' => $packs]);
    }

    // -------------------------------------------------------------------------
    // Palier sans service : jamais proposé
    // -------------------------------------------------------------------------

    /**
     * **Un palier sans service rattaché n'est PAS proposable.**
     *
     * C'est la règle centrale du catalogue. Sans elle, l'interface afficherait un
     * bouton qui mène à un encaissement impossible : le service n'existant pas
     * chez Monetbil, la création du paiement échouerait — après que le client a
     * cliqué et s'est engagé.
     */
    public function test_un_palier_sans_service_n_est_pas_proposable(): void
    {
        $this->configurerPacks([
            ['montant' => 1000, 'libelle' => 'Découverte', 'service' => null],
            ['montant' => 3000, 'libelle' => 'Mémoire', 'service' => 'srv_3000'],
        ]);

        $catalogue = $this->catalogue();

        $this->assertFalse($catalogue->estProposable(1000),
            'Un palier sans service doit être refusé : l\'encaissement serait impossible.');

        $this->assertTrue($catalogue->estProposable(3000));
        $this->assertSame([3000], $catalogue->montantsProposables());
    }

    /**
     * Un service vide ou fait d'espaces équivaut à ABSENT.
     *
     * Un `.env` avec `MONETBIL_SERVICE_PACK_1000=` produit une chaîne vide, pas
     * `null`. La confondre avec une valeur renseignée proposerait un palier
     * inencaissable — c'est le cas le plus courant en pratique, puisqu'un `.env`
     * fraîchement copié a toutes ces clés vides.
     */
    public function test_un_service_vide_equivaut_a_absent(): void
    {
        foreach (['', '   ', null] as $vide) {
            $this->configurerPacks([
                ['montant' => 1000, 'libelle' => 'Découverte', 'service' => $vide],
            ]);

            $this->assertFalse($this->catalogue()->estProposable(1000),
                'Un service vide ('.var_export($vide, true).') ne doit pas rendre le '
                .'palier proposable : la clé .env vide est le cas par défaut.');
        }
    }

    // -------------------------------------------------------------------------
    // Cohérence entre les deux lectures
    // -------------------------------------------------------------------------

    /**
     * **`estProposable()` et `proposables()` répondent la même chose.**
     *
     * Test de cohérence, et il protège d'un défaut précis : l'interface itère sur
     * `proposables()` pour afficher, le contrôleur appelle `estProposable()` pour
     * valider. Si les deux listes divergeaient, un montant AFFICHÉ serait REFUSÉ
     * au paiement — le client verrait son choix rejeté sans comprendre.
     */
    public function test_les_deux_lectures_sont_coherentes(): void
    {
        $this->configurerPacks([
            ['montant' => 1000, 'service' => 'srv_1000'],
            ['montant' => 2000, 'service' => null],
            ['montant' => 3000, 'service' => 'srv_3000'],
            ['montant' => 5000, 'service' => 'srv_5000'],
        ]);

        $catalogue = $this->catalogue();
        $montants = $catalogue->montantsProposables();

        foreach ($catalogue->tous() as $pack) {
            $attendu = in_array($pack['montant'], $montants, true);

            $this->assertSame(
                $attendu,
                $catalogue->estProposable($pack['montant']),
                'Les deux lectures divergent pour le montant '.$pack['montant'].' : '
                .'un montant affiché pourrait être refusé au paiement.',
            );
        }
    }

    /**
     * Un montant hors palier est refusé.
     *
     * C'est ce qui remplace la saisie libre : un montant arbitraire ne correspond
     * à aucun service, donc à aucun encaissement possible.
     */
    public function test_un_montant_hors_palier_est_refuse(): void
    {
        $this->configurerPacks([
            ['montant' => 1000, 'service' => 'srv_1000'],
            ['montant' => 3000, 'service' => 'srv_3000'],
        ]);

        $catalogue = $this->catalogue();

        foreach ([0, 500, 2999, 3001, 999999, -1000] as $montant) {
            $this->assertFalse($catalogue->estProposable($montant),
                "Le montant {$montant} ne correspond à aucun palier : il doit être refusé.");
        }
    }

    // -------------------------------------------------------------------------
    // Robustesse de la configuration
    // -------------------------------------------------------------------------

    /**
     * Un palier sans montant positif est ignoré.
     *
     * Une entrée incomplète (montant absent ou à 0) produirait un bouton à
     * 0 crédit. Mieux vaut ignorer l'entrée que d'afficher une offre vide.
     */
    public function test_un_palier_sans_montant_positif_est_ignore(): void
    {
        $this->configurerPacks([
            ['montant' => 0, 'service' => 'srv_0'],
            ['montant' => -500, 'service' => 'srv_neg'],
            ['libelle' => 'Sans montant', 'service' => 'srv_x'],
            ['montant' => 1000, 'service' => 'srv_1000'],
        ]);

        $catalogue = $this->catalogue();

        $this->assertCount(1, $catalogue->tous(),
            'Seul le palier à montant positif doit être conservé.');
        $this->assertSame(1000, $catalogue->tous()[0]['montant']);
    }

    /**
     * Le nombre de crédits suit le montant par défaut (1 crédit = 1 FCFA).
     *
     * La parité est la règle du projet : l'oublier ferait afficher un nombre de
     * crédits sans rapport avec le montant payé.
     */
    public function test_les_credits_suivent_le_montant_par_defaut(): void
    {
        $this->configurerPacks([
            ['montant' => 3000, 'service' => 'srv_3000'],
        ]);

        $pack = $this->catalogue()->pourMontant(3000);

        $this->assertNotNull($pack);
        $this->assertSame(3000, $pack['credits'],
            '1 crédit = 1 FCFA : sans clé `credits`, le montant fait foi.');
    }

    /**
     * Une configuration absente ne fait pas échouer le catalogue.
     *
     * Le cas se produit au premier déploiement, avant que les paliers ne soient
     * déclarés. Une exception ferait planter la page du compte ; on retourne une
     * liste vide, et l'interface affiche l'indisponibilité.
     */
    public function test_une_configuration_absente_retourne_une_liste_vide(): void
    {
        config(['billing.credit_packs' => []]);

        $catalogue = $this->catalogue();

        $this->assertSame([], $catalogue->tous());
        $this->assertSame([], $catalogue->proposables());
        $this->assertFalse($catalogue->estProposable(1000));
    }

    /**
     * Le libellé d'un montant inconnu ne lève pas d'erreur.
     *
     * Sert à la traçabilité (journaliser un achat sans palier doit rester
     * possible), donc la méthode ne doit jamais échouer.
     */
    public function test_le_libelle_d_un_montant_inconnu_ne_leve_pas_d_erreur(): void
    {
        $this->configurerPacks([]);

        $this->assertSame('999999 crédits', $this->catalogue()->libelle(999999));
    }

    /**
     * `populaire` marque un palier, sans changer sa proposabilité.
     *
     * Le drapeau sert à orienter le choix à l'écran ; il ne doit pas rendre un
     * palier acceptable alors que son service est absent — ce serait proposer une
     * offre mise en avant qu'on ne peut pas encaisser.
     */
    public function test_populaire_ne_rend_pas_un_palier_proposable(): void
    {
        $this->configurerPacks([
            ['montant' => 3000, 'populaire' => true, 'service' => null],
        ]);

        $this->assertFalse($this->catalogue()->estProposable(3000),
            'Un palier marqué « populaire » mais sans service reste inencaissable.');
    }
}
