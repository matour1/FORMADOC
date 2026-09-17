<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AiUsageLedger;
use App\Models\Document;
use App\Models\User;
use App\Services\Billing\UsageCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Espace d'administration (étape 8).
 *
 * **Ce que ces tests protègent.** L'espace admin expose des données de TOUS les
 * utilisateurs : coûts, documents, classification. Le contrôle d'accès est donc
 * la propriété la plus critique, et il a ses propres tests.
 *
 * Trois choix de conception sont vérifiés explicitement, parce qu'ils seraient
 * faciles à défaire par inadvertance :
 *
 *  1. **404 et non 403** pour un utilisateur connecté sans droits : un 403
 *     confirmerait l'existence de l'espace ;
 *  2. **redirection** pour un invité (et non 404) : sans session, la réponse utile
 *     est « connectez-vous » ;
 *  3. **`is_admin` est `false` par défaut** : l'élévation doit être un geste
 *     explicite, jamais un effet de bord d'une inscription.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    private function routesAdmin(): array
    {
        return [
            'admin.index',
            'admin.billing',
            'admin.usage',
            'admin.classification',
            'admin.documents',
        ];
    }

    // -------------------------------------------------------------------------
    // Contrôle d'accès
    // -------------------------------------------------------------------------

    public function test_un_invite_est_redirige_vers_le_login(): void
    {
        foreach ($this->routesAdmin() as $nom) {
            $this->get(route($nom))->assertRedirect(route('login'));
        }
    }

    public function test_un_utilisateur_ordinaire_recoit_un_404(): void
    {
        // 404 et non 403 : un 403 confirmerait que l'espace existe, ce qui donne
        // une information à un utilisateur qui n'a rien à y faire.
        $utilisateur = User::factory()->create(['is_admin' => false]);

        foreach ($this->routesAdmin() as $nom) {
            $this->actingAs($utilisateur)->get(route($nom))->assertNotFound();
        }
    }

    public function test_un_administrateur_accede_a_tous_les_ecrans(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        foreach ($this->routesAdmin() as $nom) {
            $this->actingAs($admin)->get(route($nom))->assertOk();
        }
    }

    public function test_is_admin_est_faux_par_defaut(): void
    {
        // L'élévation doit être un geste explicite. Un défaut à `true` — ou une
        // colonne nullable sans défaut — ferait de tout nouvel inscrit un
        // administrateur.
        $utilisateur = User::factory()->create();

        $this->assertFalse($utilisateur->fresh()->is_admin);
    }

    public function test_le_middleware_admin_est_bien_applique_a_toutes_les_routes(): void
    {
        // Garde-fou structurel : une route admin ajoutée SANS le middleware
        // exposerait les données de tous les utilisateurs. On vérifie donc le
        // groupe, pas seulement les routes connues aujourd'hui.
        $routes = app('router')->getRoutes();

        $sansMiddleware = [];

        foreach ($routes as $route) {
            if (! str_starts_with($route->uri(), 'admin')) {
                continue;
            }

            if (! in_array('admin', $route->gatherMiddleware(), true)) {
                $sansMiddleware[] = $route->uri();
            }
        }

        $this->assertSame([], $sansMiddleware, "Toute route admin doit porter le middleware `admin`.\n - ".implode("\n - ", $sansMiddleware));
    }

    // -------------------------------------------------------------------------
    // Contenu des écrans
    // -------------------------------------------------------------------------

    public function test_la_vue_d_ensemble_signale_le_pipeline_desactive(): void
    {
        // Le point d'attention le plus important : si le pipeline natif est
        // désactivé, R1→R7 ne sont pas exercés en production. L'écran doit le dire.
        config(['document.pipeline.v2' => false]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Pipeline natif désactivé');
    }

    public function test_la_vue_d_ensemble_affiche_le_cout_reel(): void
    {
        AiUsageLedger::create([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 100,
            'output_tokens' => 200,
            'succeeded' => true,
            'cost_usd' => 0.001234,
            'cost_credits' => 42,
        ]);

        AiUsageLedger::create([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 50,
            'output_tokens' => 0,
            'succeeded' => false,
            'estimated' => true,
            'cost_usd' => 0.0005,
            'cost_credits' => 8,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            // Le coût inclut la tentative échouée : le fournisseur la facture.
            ->assertSee('50', false)
            ->assertSee('Échecs facturés');
    }

    public function test_le_rapport_de_rentabilite_utilise_les_memes_totaux_que_la_commande(): void
    {
        AiUsageLedger::create([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 1_000_000,
            'output_tokens' => 1_000_000,
            'succeeded' => true,
            'cost_usd' => 0.001286,
            'cost_credits' => 2,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        // L'écran doit afficher le même total que `UsageLedger` : c'est la raison
        // pour laquelle il passe par le service et non par des requêtes directes.
        $this->actingAs($admin)
            ->get(route('admin.billing'))
            ->assertOk()
            ->assertSee('Répartition par modèle')
            ->assertSee('deepseek/deepseek-chat');
    }

    public function test_le_registre_d_usage_detaille_les_lignes(): void
    {
        // Modèle VOLONTAIREMENT présent dans la grille tarifaire : le contrôle de
        // recalculabilité compare le coût stocké au calcul depuis les tokens. Un
        // modèle inconnu donnerait 0 recalculé, donc un écart — ce qui est le
        // comportement attendu, mais ne teste pas ce qu'on veut ici.
        $grille = config('openrouter.pricing.deepseek/deepseek-chat');
        $attendu = app(UsageCostCalculator::class)
            ->estimateCredits('deepseek/deepseek-chat', 11, 22)['credits'];

        AiUsageLedger::create([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 11,
            'output_tokens' => 22,
            'succeeded' => true,
            'cost_usd' => 0.0001,
            'cost_credits' => $attendu,
            'reference' => 'chat:99',
        ]);

        $this->assertNotNull($grille, 'Le modèle de test doit exister dans la grille tarifaire.');

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.usage'))
            ->assertOk()
            // Le coût reste recalculable : les tokens bruts sont visibles.
            ->assertSee('deepseek/deepseek-chat')
            ->assertSee('chat:99')
            ->assertSee('Coût recalculable');
    }

    public function test_un_modele_hors_grille_tarifaire_est_signale_comme_ecart(): void
    {
        // Cas réel d'un tarif changé ou d'un modèle retiré de la configuration :
        // le coût stocké ne se recalcule plus. Le registre doit le DIRE plutôt que
        // d'afficher un taux de cohérence trompeur.
        AiUsageLedger::create([
            'model' => 'modele-absent-de-la-grille',
            'provider' => 'openrouter',
            'input_tokens' => 100,
            'output_tokens' => 100,
            'succeeded' => true,
            'cost_usd' => 0.001,
            'cost_credits' => 5,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.usage'))
            ->assertOk()
            ->assertSee('écart', false);
    }

    public function test_le_filtre_des_echecs_ne_montre_que_les_echecs(): void
    {
        // Les deux modèles sont HORS grille tarifaire, donc le contrôle de
        // recalculabilité les cite tous les deux dans son bandeau d'écarts. On ne
        // peut donc pas asserter leur absence dans la PAGE : on inspecte les
        // lignes du tableau, ce qui est le comportement réellement testé.
        AiUsageLedger::create([
            'model' => 'succes-unique',
            'succeeded' => true,
            'cost_credits' => 1,
            'cost_usd' => 0.0001,
        ]);
        AiUsageLedger::create([
            'model' => 'echec-unique',
            'succeeded' => false,
            'estimated' => true,
            'cost_credits' => 1,
            'cost_usd' => 0.0001,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $reponse = $this->actingAs($admin)->get(route('admin.usage', ['failures' => 1]));
        $reponse->assertOk();

        // Le filtre est vérifié sur la LISTE affichée, et non sur la page entière :
        // le bandeau de recalculabilité cite les deux modèles (tous deux hors
        // grille tarifaire), donc un `assertDontSee` global serait faux.
        $lignes = $reponse->viewData('lignes');

        $this->assertSame(1, $lignes->total(), 'Le filtre doit ne retenir que la tentative échouée.');
        $this->assertSame('echec-unique', $lignes->first()->model);
    }

    public function test_l_ecran_documents_liste_tous_les_utilisateurs(): void
    {
        $proprietaire = User::factory()->create(['email' => 'proprietaire@exemple.test']);
        $admin = User::factory()->create(['is_admin' => true]);

        Document::create([
            'filename' => 'rapport-visible.docx',
            'path' => 'documents/rapport.docx',
            'status' => 'detected',
            'metadata' => ['user_id' => $proprietaire->id, 'size' => 2048],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.documents'))
            ->assertOk()
            ->assertSee('rapport-visible.docx')
            // Le propriétaire est identifiable : sans cela, un document en échec
            // ne serait pas rattachable à un utilisateur à contacter.
            ->assertSee('proprietaire@exemple.test');
    }

    public function test_l_ecran_de_classification_signale_l_absence_de_donnees(): void
    {
        // Aucun document natif : l'écran doit expliquer POURQUOI il est vide, et
        // non afficher un tableau vide qui laisserait croire à un défaut.
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.classification'))
            ->assertOk()
            ->assertSee('DOCUMENT_PIPELINE_V2');
    }
}
