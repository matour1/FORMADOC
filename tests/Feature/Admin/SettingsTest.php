<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\UsageCostCalculator;
use App\Services\Settings\SettingsCatalog;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Réglages d'exploitation modifiables depuis l'administration.
 *
 * **Ce que ces tests protègent.** Un réglage est une valeur qui change le PRIX payé
 * par l'utilisateur. Trois défauts sont possibles, et aucun ne produit d'erreur :
 *
 *  1. **Le réglage n'a aucun effet.** L'écran enregistre, la base contient la
 *     nouvelle valeur, et le code métier continue de lire `config/`. L'exploitant
 *     croit avoir changé la marge ; elle n'a pas bougé. C'est ce que vérifie
 *     `test_un_reglage_modifie_change_reellement_le_calcul`.
 *  2. **Le type est perdu.** `false` stocké puis relu en chaîne `"0"` devient
 *     *truthy* en PHP : le renouvellement automatique resterait actif alors qu'on
 *     vient de le désactiver.
 *  3. **Une soumission partielle écrase le reste.** Une requête qui n'envoie qu'un
 *     champ décocherait toutes les cases à cocher absentes — le renouvellement
 *     automatique et le prorata passeraient à `false` sans que personne n'y ait
 *     touché. Ce défaut a été observé pendant le développement, pas supposé.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // -------------------------------------------------------------------------
    // Catalogue
    // -------------------------------------------------------------------------

    /**
     * Tout réglage doit viser une configuration qui EXISTE.
     *
     * Un réglage dont le nom de configuration est introuvable ne serait jamais
     * surchargé : `applyToConfig()` ignore silencieusement les clés inconnues.
     * L'écran l'afficherait, l'exploitant le modifierait, et rien ne changerait.
     * Ce test a d'ailleurs détecté un orphelin réel (`payments.link_ttl_days`)
     * pendant le développement.
     */
    public function test_chaque_reglage_pointe_vers_une_configuration_existante(): void
    {
        $orphelins = [];

        foreach (SettingsCatalog::all() as $nom => $definition) {
            if (config($definition['key']) === null) {
                $orphelins[] = "{$nom} → {$definition['key']}";
            }
        }

        $this->assertSame(
            [],
            $orphelins,
            "Ces réglages visent une configuration inexistante, donc n'auraient aucun effet :\n - "
            .implode("\n - ", $orphelins)
        );
    }

    /**
     * Tout réglage doit avoir un libellé et une description.
     *
     * L'écran se construit entièrement à partir du catalogue : sans libellé, il
     * affiche une case vide, et sans description il n'explique pas l'effet de la
     * valeur — or c'est la seule chose qui permette de la saisir correctement.
     */
    public function test_chaque_reglage_est_decrit(): void
    {
        foreach (SettingsCatalog::all() as $nom => $definition) {
            $this->assertNotEmpty($definition['label'], "Libellé manquant pour « {$nom} ».");
            $this->assertNotEmpty($definition['description'], "Description manquante pour « {$nom} ».");
            $this->assertContains($definition['type'], SettingsRepository::TYPES, "Type invalide pour « {$nom} ».");
        }
    }

    // -------------------------------------------------------------------------
    // Effet réel de la surcharge
    // -------------------------------------------------------------------------

    /**
     * **Le test central.** Modifier un réglage doit changer le calcul, pas
     * seulement la base.
     *
     * C'est la propriété la plus facile à perdre : il suffit que quelqu'un
     * remplace `config('openrouter.cost_margin')` par une constante dans
     * `UsageCostCalculator` pour que l'écran devienne purement décoratif — sans
     * qu'aucun autre test ne s'en aperçoive, puisque rien ne plante.
     */
    public function test_un_reglage_modifie_change_reellement_le_calcul(): void
    {
        $calculateur = app(UsageCostCalculator::class);

        // Valeur de départ : marge 0,60 + infra 0,15 → coefficient 1,84.
        //
        // Comparaison à une tolérance et non à l'identique : 1,15 × 1,60 vaut
        // 1.8399999999999999 en binaire. `assertSame` échoue, et le message
        // (« 1.8399999999999999 n'est pas identique à 1.84 ») laisse croire à un
        // défaut de calcul alors que c'est une propriété des nombres flottants.
        $this->assertEqualsWithDelta(1.84, $calculateur->profitabilityCoefficient(), 0.000001);

        app(SettingsRepository::class)->set('openrouter.cost_margin', 1.00, 'float');

        // 1,15 × 2,00 = 2,30. Le calcul doit avoir suivi.
        $this->assertEqualsWithDelta(
            2.30,
            $calculateur->profitabilityCoefficient(),
            0.000001,
            'La modification de la marge ne se répercute pas sur le coefficient : '
            .'le réglage est décoratif, `UsageCostCalculator` lit encore `config/`.'
        );

        // Et la conséquence concrète : le prix facturé change.
        $credits = $calculateur->usdToCredits(1.0);
        $this->assertGreaterThan(620, $credits, 'Le prix facturé devrait intégrer la nouvelle marge.');
    }

    /**
     * Les types survivent à l'aller-retour en base.
     *
     * `filter_var(..., FILTER_VALIDATE_BOOLEAN)` et non `(bool)` : `(bool) 'false'`
     * vaut `true` en PHP, et la chaîne `"0"` est *truthy*. Un simple cast
     * inverserait donc la valeur enregistrée.
     */
    public function test_les_types_survivent_a_l_aller_retour_en_base(): void
    {
        $repo = app(SettingsRepository::class);

        $repo->set('billing.auto_renew', false, 'bool');
        $repo->flush();
        $this->assertFalse($repo->get('billing.auto_renew'), 'Un booléen faux doit rester faux.');

        $repo->set('billing.auto_renew', true, 'bool');
        $repo->flush();
        $this->assertTrue($repo->get('billing.auto_renew'));

        $repo->set('openrouter.rate_fcfa_per_usd', 655.957, 'float');
        $repo->flush();
        $this->assertSame(655.957, $repo->get('openrouter.rate_fcfa_per_usd'));

        $repo->set('kpay.min_amount', 1000, 'int');
        $repo->flush();
        $this->assertSame(1000, $repo->get('kpay.min_amount'));
    }

    /**
     * Un type inconnu est refusé, pas stocké.
     *
     * Un type non géré serait relu par le `default` du `match`, donc en chaîne —
     * et une valeur « 0 » en chaîne est *truthy*.
     */
    public function test_un_type_inconnu_est_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SettingsRepository::class)->set('billing.auto_renew', true, 'date');
    }

    // -------------------------------------------------------------------------
    // Écran
    // -------------------------------------------------------------------------

    public function test_l_ecran_de_configuration_est_reserve_a_l_administrateur(): void
    {
        $this->get(route('admin.settings'))->assertRedirect(route('login'));

        $utilisateur = User::factory()->create(['is_admin' => false]);
        $this->actingAs($utilisateur)->get(route('admin.settings'))->assertNotFound();

        $this->actingAs($this->admin())->get(route('admin.settings'))->assertOk();
    }

    public function test_l_ecran_affiche_le_nom_de_configuration_de_chaque_reglage(): void
    {
        // Le nom de configuration est ce que lit le code : sans lui, impossible de
        // relier un réglage affiché à l'appel `config()` correspondant lors d'un
        // diagnostic.
        $response = $this->actingAs($this->admin())->get(route('admin.settings'))->assertOk();

        foreach (SettingsCatalog::all() as $definition) {
            $response->assertSee($definition['label']);
        }

        $response->assertSee('openrouter.cost_margin');
    }

    public function test_une_valeur_hors_bornes_est_refusee_et_non_enregistree(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), ['cost_margin' => 40])
            ->assertSessionHasErrors('cost_margin');

        $this->assertDatabaseMissing('settings', ['key' => 'openrouter.cost_margin']);
    }

    public function test_une_devise_invalide_est_refusee(): void
    {
        // Une devise libre serait rejetée par KPay au moment de l'initiation du
        // paiement — soit à l'instant le plus coûteux : quand le client est devant
        // la page de règlement.
        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), ['currency' => 'FRANCS'])
            ->assertSessionHasErrors('currency');
    }

    public function test_une_valeur_valide_est_enregistree(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), ['cost_margin' => 0.7])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'key' => 'openrouter.cost_margin',
            'value' => '0.7',
            'type' => 'float',
        ]);
    }

    // -------------------------------------------------------------------------
    // Soumission partielle
    // -------------------------------------------------------------------------

    /**
     * **Le défaut observé pendant le développement.**
     *
     * Une case à cocher non soumise vaut faux — c'est la règle HTML, et elle est
     * légitime quand le formulaire envoie tout. Mais une requête qui n'envoie qu'un
     * champ (appel direct, script de maintenance) décocherait alors TOUTES les
     * cases absentes : le renouvellement automatique et le prorata passeraient à
     * `false` en silence, et le renouvellement s'arrêterait pour tous les
     * abonnements sans que personne n'ait touché à ces réglages.
     */
    public function test_une_soumission_partielle_ne_modifie_pas_les_booleens_absents(): void
    {
        $repo = app(SettingsRepository::class);
        $repo->set('billing.auto_renew', true, 'bool');
        $repo->set('billing.prorata', true, 'bool');

        // Requête qui ne contient QUE la marge.
        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), ['cost_margin' => 0.7])
            ->assertSessionHasNoErrors();

        $repo->flush();

        $this->assertTrue(
            $repo->get('billing.auto_renew'),
            'Une soumission partielle a désactivé le renouvellement automatique : '
            .'une case à cocher absente ne doit pas valoir « décochée » si le champ n\'a pas été soumis.'
        );
        $this->assertTrue($repo->get('billing.prorata'));
    }

    /**
     * Le formulaire déclare bien ses booléens, même décochés.
     *
     * C'est ce `<input type="hidden" value="0">` qui rend légitime de considérer
     * l'absence d'un booléen comme une absence de soumission plutôt qu'une
     * désactivation : quand l'utilisateur décoche, le champ caché transmet `0`.
     * Sans lui, on ne pourrait pas distinguer « décoché » de « non soumis » — les
     * deux étant une absence.
     */
    public function test_le_formulaire_declare_ses_booleens_meme_decoches(): void
    {
        $html = $this->actingAs($this->admin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->getContent();

        foreach (SettingsCatalog::all() as $nom => $definition) {
            if ($definition['type'] !== 'bool') {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/<input[^>]+type="hidden"[^>]+name="'.preg_quote($nom, '/').'"/',
                $html,
                "Le réglage booléen « {$nom} » n'a pas de champ caché : décocher et ne pas "
                .'soumettre deviendraient indiscernables.'
            );
        }
    }

    /**
     * Décocher RÉELLEMENT un booléen doit le désactiver.
     *
     * Le test précédent protège l'absence ; celui-ci protège la présence. Sans lui,
     * une protection trop zélée rendrait le réglage impossible à désactiver — ce
     * qui est tout aussi faux.
     */
    public function test_decocher_un_booleen_le_desactive_vraiment(): void
    {
        $repo = app(SettingsRepository::class);
        $repo->set('billing.auto_renew', true, 'bool');

        // Le champ caché transmet 0 quand la case est décochée.
        $this->actingAs($this->admin())
            ->put(route('admin.settings.update'), ['auto_renew' => '0'])
            ->assertSessionHasNoErrors();

        $repo->flush();

        $this->assertFalse($repo->get('billing.auto_renew'));
    }

    // -------------------------------------------------------------------------
    // Amorçage
    // -------------------------------------------------------------------------

    /**
     * Une table vide ne casse rien : les défauts de `config/` s'appliquent.
     *
     * C'est ce qui rend le système sûr à installer. La table est volontairement
     * vide au départ (elle ne contient que ce qui a été modifié), donc une base
     * neuve doit fonctionner sans aucun amorçage.
     */
    public function test_une_table_vide_laisse_les_defauts_de_configuration(): void
    {
        $this->assertSame(0, Setting::count());

        $this->assertSame(0.6, config('openrouter.cost_margin'));
        $this->assertSame(500, config('kpay.min_amount'));
        $this->assertEqualsWithDelta(1.84, app(UsageCostCalculator::class)->profitabilityCoefficient(), 0.000001);
    }
}
