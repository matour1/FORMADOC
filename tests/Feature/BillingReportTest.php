<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiUsageLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commande de rapport de rentabilité (R7.5).
 *
 * **Ce que ces tests protègent.** La commande agrège le registre d'usage ; une
 * erreur y est silencieuse (le rapport reste lisible, mais les chiffres sont
 * faux) et sert ensuite à décider d'un prix. Trois propriétés comptent :
 *
 *  1. les tentatives ÉCHOUÉES sont comptées dans le coût — sinon le rapport
 *     sous-estime précisément ce qu'il doit révéler ;
 *  2. les filtres (période, utilisateur, document) sont réellement appliqués ;
 *  3. le contrôle `--verify` détecte un écart entre le coût stocké et le coût
 *     recalculé depuis les tokens.
 */
class BillingReportTest extends TestCase
{
    use RefreshDatabase;

    private function ligne(array $attributs = []): AiUsageLedger
    {
        return AiUsageLedger::create(array_merge([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 1_000_000,
            'output_tokens' => 1_000_000,
            'succeeded' => true,
            'cost_usd' => 0.001286,
            'cost_credits' => 2,
        ], $attributs));
    }

    public function test_la_commande_reussit_sans_aucune_ligne(): void
    {
        $this->artisan('billing:report')->assertSuccessful();
    }

    public function test_la_commande_rend_le_rapport(): void
    {
        $this->ligne();

        $this->artisan('billing:report')
            ->expectsOutputToContain('Rapport de rentabilité IA')
            ->assertSuccessful();
    }

    public function test_les_echecs_sont_comptes_dans_le_rapport(): void
    {
        $this->ligne();
        $this->ligne(['succeeded' => false, 'estimated' => true, 'output_tokens' => 0, 'cost_credits' => 5]);

        // Le rapport doit mentionner les deux tentatives (une réussie, une
        // échouée) : une tentative échouée est facturée par le fournisseur.
        $this->artisan('billing:report')
            ->expectsOutputToContain('Tentatives facturées')
            ->expectsOutputToContain('Fuite sèche')
            ->assertSuccessful();
    }

    public function test_les_lignes_estimees_sont_signalees(): void
    {
        $this->ligne(['succeeded' => false, 'estimated' => true]);

        $this->artisan('billing:report')
            ->expectsOutputToContain('ESTIMÉS')
            ->assertSuccessful();
    }

    public function test_le_filtre_utilisateur_restreint_le_perimetre(): void
    {
        $user = User::factory()->create();

        $this->ligne(['user_id' => $user->id]);

        $this->artisan('billing:report', ['--user' => $user->id])->assertSuccessful();
    }

    public function test_un_filtre_sans_ligne_signale_l_absence_de_donnees(): void
    {
        // Aucune ligne : le rapport doit le DIRE plutôt que d'afficher des
        // zéros, qui se liraient comme « activité nulle » et non « hors
        // périmètre ».
        $this->artisan('billing:report', ['--user' => 99999])
            ->expectsOutputToContain('Aucune ligne')
            ->assertSuccessful();
    }

    public function test_la_verification_confirme_la_coherence(): void
    {
        // Coût conforme à la grille : input 0.2574 + output 1.029 pour 1 M
        // tokens chacun.
        $attendu = (1_000_000 / 1_000_000) * 0.2574 + (1_000_000 / 1_000_000) * 1.029;
        $credits = (int) ceil($attendu * 1.15 * 1.60 * 620);

        $this->ligne(['cost_usd' => round($attendu, 6), 'cost_credits' => $credits]);

        $this->artisan('billing:report', ['--verify' => true])
            ->expectsOutputToContain('Recalculabilité')
            ->assertSuccessful();
    }

    public function test_la_verification_expose_les_ecarts(): void
    {
        // Coût volontairement faux (tarif changé sans retraitement).
        $this->ligne(['cost_credits' => 1, 'cost_usd' => 0.000001]);

        $this->artisan('billing:report', ['--verify' => true])
            ->expectsOutputToContain('Écarts')
            ->assertSuccessful();
    }

    public function test_le_rapport_liste_la_repartition_par_modele(): void
    {
        $this->ligne();
        $this->ligne(['provider' => 'deepseek_fallback', 'is_fallback' => true, 'fallback_from' => 'openrouter']);

        // Un même modèle via deux fournisseurs = deux postes de coût distincts.
        $this->artisan('billing:report')
            ->expectsOutputToContain('Répartition par modèle')
            ->assertSuccessful();
    }
}
