<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AiUsageLedger;
use App\Models\User;
use App\Services\Billing\AdminChartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Séries temporelles des graphiques d'exploitation.
 *
 * **Ce que ces tests protègent.** Un graphique faux ne plante pas : il affiche une
 * courbe plausible, et une décision se prend sur cette courbe. Deux propriétés
 * sont donc critiques, et aucune ne se voit à l'œil sur un écran :
 *
 *  1. **Les jours sans activité doivent exister.** SQL ne renvoie que les jours
 *     AYANT des lignes. Sans complétion, une interruption de dix jours apparaîtrait
 *     comme un espacement régulier — le graphique montrerait une continuité
 *     inexistante, l'exact inverse de ce qu'on lui demande.
 *  2. **Les chiffres doivent correspondre à la base.** Une agrégation fausse (mauvais
 *     filtre de date, `SUM` sur la mauvaise colonne) produit un total crédible.
 */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crée une ligne du registre d'usage en la datant.
     *
     * **Pourquoi pas `create(['created_at' => ...])`.** `created_at` n'est pas
     * *fillable* sur `AiUsageLedger` — c'est une décision de sécurité : une date de
     * coût doit refléter l'instant réel de l'appel, pas une valeur fournie. Le
     * passer à `create()` est donc IGNORÉ SILENCIEUSEMENT, et la ligne se retrouve
     * datée d'aujourd'hui. Le test échouait alors sur une assertion qui semblait
     * porter sur la logique de fenêtre, alors que la donnée n'était pas où on
     * croyait.
     *
     * On force donc l'attribut après création, ce qui est le seul moyen d'obtenir
     * une ligne ancienne.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function ligneLedger(array $attributs, ?Carbon $date = null): AiUsageLedger
    {
        $ligne = AiUsageLedger::create(array_merge([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'succeeded' => true,
            'cost_usd' => 0.0001,
            'cost_credits' => 0,
        ], $attributs));

        if ($date !== null) {
            $ligne->forceFill(['created_at' => $date])->save();
        }

        return $ligne;
    }

    private function service(): AdminChartService
    {
        return app(AdminChartService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // -------------------------------------------------------------------------
    // Complétion des jours manquants
    // -------------------------------------------------------------------------

    /**
     * **Le test central.** Un jour sans donnée doit figurer dans la série, à zéro.
     *
     * Sans cette complétion, un trou de plusieurs jours serait invisible : la série
     * n'aurait que les jours actifs, et le graphique dessinerait des barres
     * régulièrement espacées. Il montrerait donc une activité continue là où il y a
     * eu une interruption.
     */
    public function test_les_jours_sans_activite_sont_presents_a_zero(): void
    {
        // Un seul appel, il y a 5 jours. Les 4 jours qui suivent sont vides.
        $this->ligneLedger(['cost_credits' => 7], Carbon::today()->subDays(5));

        $serie = $this->service()->coutIaParJour(7);

        $this->assertCount(7, $serie['labels'], 'La série doit couvrir les 7 jours demandés.');
        $this->assertCount(7, $serie['series']['credits']);

        // Exactement un jour non nul, et il porte la bonne valeur.
        $nonNuls = array_filter($serie['series']['credits'], fn ($v) => $v != 0);
        $this->assertCount(1, $nonNuls);
        $this->assertSame(7, (int) array_sum($nonNuls));

        // Et l'index du jour non nul correspond bien à J-5, pas au premier élément.
        $indexAttendu = 7 - 1 - 5;
        $this->assertSame(7, (int) $serie['series']['credits'][$indexAttendu]);
    }

    public function test_une_serie_sans_donnee_est_signalee_vide(): void
    {
        // `vide` permet à l'écran d'expliquer l'absence de données au lieu
        // d'afficher un graphique plat, qui se lit comme une chute d'activité.
        $serie = $this->service()->coutIaParJour(7);

        $this->assertTrue($serie['vide']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], array_map('intval', $serie['series']['credits']));
    }

    public function test_une_serie_avec_donnees_n_est_pas_signalee_vide(): void
    {
        AiUsageLedger::create([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 1,
            'output_tokens' => 1,
            'succeeded' => true,
            'cost_usd' => 0.0001,
            'cost_credits' => 3,
        ]);

        $this->assertFalse($this->service()->coutIaParJour(7)['vide']);
    }

    // -------------------------------------------------------------------------
    // Exactitude des agrégations
    // -------------------------------------------------------------------------

    public function test_les_credits_agreges_correspondent_a_la_base(): void
    {
        foreach ([10, 20, 30] as $credits) {
            AiUsageLedger::create([
                'model' => 'deepseek/deepseek-chat',
                'provider' => 'openrouter',
                'input_tokens' => 10,
                'output_tokens' => 10,
                'succeeded' => true,
                'cost_usd' => 0.0001,
                'cost_credits' => $credits,
            ]);
        }

        $serie = $this->service()->coutIaParJour(7);

        // Toutes les lignes sont d'aujourd'hui : le dernier point de la série.
        $dernier = array_key_last($serie['series']['credits']);
        $this->assertSame(60, (int) $serie['series']['credits'][$dernier]);
        $this->assertSame(3, (int) $serie['series']['appels'][$dernier]);

        // Et le total correspond au `SUM` de la base, pas à un calcul intermédiaire.
        $this->assertSame(
            (int) AiUsageLedger::sum('cost_credits'),
            (int) array_sum($serie['series']['credits'])
        );
    }

    /**
     * Les tentatives échouées sont comptées.
     *
     * Elles sont FACTURÉES : les exclure du graphique ferait disparaître une fuite
     * réelle — c'est exactement le défaut que le tableau de bord doit révéler.
     */
    public function test_les_echecs_sont_comptes_et_inclus_dans_le_cout(): void
    {
        AiUsageLedger::create([
            'model' => 'openai/gpt-image-1-mini',
            'provider' => 'openrouter',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'succeeded' => false,
            'cost_usd' => 0.0005,
            'cost_credits' => 5,
        ]);

        $serie = $this->service()->coutIaParJour(7);
        $dernier = array_key_last($serie['series']['echecs']);

        $this->assertSame(1, (int) $serie['series']['echecs'][$dernier]);
        $this->assertSame(1, (int) $serie['series']['appels'][$dernier]);
        $this->assertSame(5, (int) $serie['series']['credits'][$dernier]);
    }

    public function test_les_lignes_hors_fenetre_sont_exclues(): void
    {
        // Il y a 40 jours : hors d'une fenêtre de 30.
        $this->ligneLedger(['cost_credits' => 99], Carbon::today()->subDays(40));

        $serie = $this->service()->coutIaParJour(30);

        $this->assertTrue($serie['vide'], 'Une ligne hors fenêtre ne doit pas apparaître.');
        $this->assertSame(0, (int) array_sum($serie['series']['credits']));

        // Mais elle reste dans les cumuls, qui portent sur toute l'histoire.
        $this->assertSame(99, $this->service()->cumuls()['cout_ia_total_credits']);
    }

    // -------------------------------------------------------------------------
    // Fournisseurs
    // -------------------------------------------------------------------------

    public function test_la_consommation_par_fournisseur_calcule_le_taux_d_echec(): void
    {
        // 4 appels, 3 échecs = 75 %.
        foreach ([true, false, false, false] as $ok) {
            AiUsageLedger::create([
                'model' => 'openai/gpt-image-1-mini',
                'provider' => 'openrouter',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'succeeded' => $ok,
                'cost_usd' => 0,
                'cost_credits' => 0,
            ]);
        }

        $lignes = $this->service()->consommationParFournisseur();

        $this->assertCount(1, $lignes);
        $this->assertSame(4, $lignes[0]['appels']);
        $this->assertSame(3, $lignes[0]['echecs']);
        $this->assertSame(75.0, $lignes[0]['taux_echec']);
    }

    public function test_la_part_du_cout_couvre_cent_pour_cent(): void
    {
        // Deux modèles, 30 et 70 crédits : les parts doivent valoir 30 et 70.
        AiUsageLedger::create([
            'model' => 'modele-a', 'provider' => 'p1',
            'input_tokens' => 1, 'output_tokens' => 1,
            'succeeded' => true, 'cost_usd' => 0, 'cost_credits' => 30,
        ]);
        AiUsageLedger::create([
            'model' => 'modele-b', 'provider' => 'p2',
            'input_tokens' => 1, 'output_tokens' => 1,
            'succeeded' => true, 'cost_usd' => 0, 'cost_credits' => 70,
        ]);

        $lignes = $this->service()->consommationParFournisseur();
        $parts = array_column($lignes, 'part');

        $this->assertCount(2, $lignes);
        // Tolérance : les arrondis à une décimale ne totalisent pas exactement 100.
        $this->assertEqualsWithDelta(100.0, array_sum($parts), 0.2);
    }

    // -------------------------------------------------------------------------
    // Rentabilité
    // -------------------------------------------------------------------------

    /**
     * Les recettes se lisent sur `paid_at`, pas sur `created_at`.
     *
     * Une facture émise puis réglée plus tard doit être comptée au jour du
     * RÈGLEMENT : c'est la date à laquelle l'argent entre, et celle qui permet un
     * rapprochement avec le relevé de la passerelle.
     */
    public function test_les_recettes_sont_datees_au_reglement(): void
    {
        // Émise il y a 60 jours, réglée aujourd'hui.
        DB::table('invoices')->insert([
            'number' => 'INV-TEST-0001',
            'user_id' => User::factory()->create()->id,
            'type' => 'credit_purchase',
            'amount' => 1000,
            'currency' => 'XAF',
            'status' => 'paid',
            'created_at' => Carbon::today()->subDays(60),
            'paid_at' => Carbon::now(),
        ]);

        // Fenêtre courte : une lecture sur `created_at` donnerait 0.
        $rentabilite = $this->service()->rentabilite(7);

        $this->assertSame(
            1000,
            $rentabilite['encaisse_fcfa'],
            'La recette doit être comptée à sa date de règlement, pas à sa date d\'émission.'
        );
    }

    public function test_la_rentabilite_separe_le_cout_des_echecs(): void
    {
        AiUsageLedger::create([
            'model' => 'm', 'provider' => 'p',
            'input_tokens' => 1, 'output_tokens' => 1,
            'succeeded' => false, 'cost_usd' => 0, 'cost_credits' => 12,
        ]);

        $rentabilite = $this->service()->rentabilite(7);

        $this->assertSame(12, $rentabilite['cout_ia_credits']);
        $this->assertSame(12, $rentabilite['cout_echecs_credits']);
        $this->assertSame(1, $rentabilite['echecs']);
        $this->assertSame(100.0, $rentabilite['taux_echec']);
    }

    // -------------------------------------------------------------------------
    // Écran
    // -------------------------------------------------------------------------

    public function test_l_ecran_d_analyse_est_reserve_a_l_administrateur(): void
    {
        $this->get(route('admin.analytics'))->assertRedirect(route('login'));

        $ordinaire = User::factory()->create(['is_admin' => false]);
        $this->actingAs($ordinaire)->get(route('admin.analytics'))->assertNotFound();

        $this->actingAs($this->admin())->get(route('admin.analytics'))->assertOk();
    }

    /**
     * La fenêtre demandée est ramenée à une valeur connue.
     *
     * `?jours=999999` serait interpolé dans un intervalle SQL : la requête
     * balayerait toute la table à chaque affichage. La liste est fermée dans le
     * contrôleur.
     */
    public function test_une_fenetre_arbitraire_est_ramenee_a_une_valeur_connue(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.analytics', ['jours' => 999999]))
            ->assertOk()
            ->assertSee('30 derniers jours');

        $this->actingAs($this->admin())
            ->get(route('admin.analytics', ['jours' => 7]))
            ->assertOk()
            ->assertSee('7 derniers jours');
    }

    public function test_l_ecran_explique_une_serie_vide(): void
    {
        // Un graphique plat se lit comme une chute d'activité. L'écran doit dire
        // que le registre est vide, ce qui est une tout autre situation.
        $this->actingAs($this->admin())
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('registre est vide');
    }

    public function test_l_ecran_affiche_les_cumuls_meme_hors_fenetre(): void
    {
        $this->ligneLedger(['cost_credits' => 42], Carbon::today()->subDays(200));

        // Hors fenêtre de 30 jours : la série est vide, mais le cumul reste vrai.
        $response = $this->actingAs($this->admin())->get(route('admin.analytics'))->assertOk();

        $response->assertSee('Encaissé (total)');
        $this->assertSame(42, $this->service()->cumuls()['cout_ia_total_credits']);
    }
}
