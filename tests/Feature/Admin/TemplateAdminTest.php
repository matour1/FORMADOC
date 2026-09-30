<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Document;
use App\Models\GeneratedDocument;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gestion des gabarits depuis l'espace d'administration.
 *
 * **Ce que ces tests protègent.** Les gabarits n'avaient aucun écran d'écriture :
 * la seule façon d'en ajouter ou d'en corriger un était de passer par la base ou
 * par un seeder. Cet écran introduit donc un chemin d'écriture vers une donnée
 * qui pilote la mise en forme de TOUS les documents à venir — pour tous les
 * utilisateurs. Une valeur absurde enregistrée ici ne casse pas un document, elle
 * casse tous les suivants.
 *
 * Trois propriétés sont vérifiées en priorité :
 *   1. l'accès est réservé à l'administration ;
 *   2. les valeurs hors bornes sont REFUSÉES (elles produiraient un document que
 *      Word refuse d'ouvrir, ou une mise en forme inutilisable) ;
 *   3. un gabarit utilisé par des documents ne peut pas être supprimé — la
 *      suppression laisserait `generated_documents.template_id` orphelin, et le
 *      document perdrait la référence de sa mise en forme.
 *
 * **Un mot sur l'unité des tailles.** L'implémentation a d'abord supposé des
 * DEMI-POINTS (24 = 12 pt), en s'appuyant sur une convention répandue dans les
 * formats bureautiques. Vérification faite dans `TemplateStyleResolver`, la
 * valeur est transmise TELLE QUELLE à PhpWord (`'size' => $size`) : ce sont des
 * POINTS. L'erreur affichait « 5,5 pt » pour un corps de 11 pt et invitait à
 * saisir des valeurs doublées. Les bornes de validation ci-dessous (6 à 72) sont
 * celles de l'unité réelle.
 */
class TemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'credits_balance' => 1000]);
    }

    /**
     * Paramètres valides, pour ne faire varier qu'un champ à la fois.
     *
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function paramsValides(array $surcharges = []): array
    {
        return array_replace_recursive([
            'name' => 'Gabarit de test',
            'description' => 'Créé par un test.',
            'is_public' => '1',
            'police' => 'Times New Roman',
            'interligne' => '1.5',
            'alignement_titres' => 'left',
            'alignement_corps' => 'both',
            'tailles' => ['titre1' => 16, 'titre2' => 14, 'titre3' => 12, 'corps' => 12],
            'couleurs' => [
                'titre1' => '1F3864', 'titre2' => '1F3864',
                'titre3' => '1F3864', 'corps' => '000000',
            ],
            'espacements' => ['avant_titre' => 240, 'apres_titre' => 120, 'apres_paragraphe' => 120],
            'marges' => [
                'top' => 1440, 'right' => 1440, 'bottom' => 1440,
                'left' => 1440, 'header' => 720, 'footer' => 720,
            ],
            'tableau' => [
                'style' => 'TableGrid', 'header_couleur' => '1F3864',
                'header_texte' => 'FFFFFF', 'bordure' => '1',
            ],
        ], $surcharges);
    }

    // -------------------------------------------------------------------------
    // Accès
    // -------------------------------------------------------------------------

    /**
     * Modifier un gabarit change la mise en forme de tous les documents à venir.
     * Ce n'est pas une action d'utilisateur : l'accès est donc réservé.
     *
     * **404 et non 403**, et c'est le choix documenté de `AdminMiddleware` : un
     * 403 confirmerait l'existence d'un espace d'administration, un 404 ne révèle
     * rien. Le test fixe ce comportement plutôt que de réclamer un 403 — la
     * première version de ce test attendait 403 et échouait sur une décision
     * délibérée.
     */
    public function test_un_utilisateur_non_admin_ne_peut_pas_acceder_a_la_gestion(): void
    {
        $utilisateur = User::factory()->create(['is_admin' => false]);

        $this->actingAs($utilisateur)->get(route('admin.templates.index'))->assertNotFound();
    }

    /**
     * Un non-admin ne peut pas non plus ÉCRIRE.
     *
     * Lire la liste et créer un gabarit sont deux pouvoirs différents : vérifier
     * seulement la lecture laisserait une écriture ouverte.
     */
    public function test_un_utilisateur_non_admin_ne_peut_pas_creer_de_gabarit(): void
    {
        $utilisateur = User::factory()->create(['is_admin' => false]);

        $this->actingAs($utilisateur)
            ->post(route('admin.templates.store'), $this->paramsValides())
            ->assertNotFound();

        $this->assertDatabaseCount('templates', 0);
    }

    public function test_un_invite_est_redirige_vers_la_connexion(): void
    {
        $this->get(route('admin.templates.index'))->assertRedirect(route('login'));
    }

    public function test_l_admin_voit_la_liste_des_gabarits(): void
    {
        Template::create([
            'name' => 'Gabarit visible',
            'description' => '',
            'params' => $this->paramsValides(),
            'is_public' => true,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.templates.index'))
            ->assertOk()
            ->assertSee('Gabarit visible');
    }

    // -------------------------------------------------------------------------
    // Création
    // -------------------------------------------------------------------------

    public function test_l_admin_peut_creer_un_gabarit(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.templates.store'), $this->paramsValides())
            ->assertRedirect(route('admin.templates.index'));

        $template = Template::where('name', 'Gabarit de test')->first();

        $this->assertNotNull($template);
        $this->assertTrue($template->is_public);
        $this->assertSame('Times New Roman', $template->params['police']);
        $this->assertSame(12, $template->params['tailles']['corps']);
    }

    /**
     * Les clés sont réassemblées dans la structure attendue par le moteur.
     *
     * Le formulaire envoie des champs plats par section (`tailles[corps]`) : si
     * l'assemblage était incomplet, le moteur appliquerait ses valeurs par défaut
     * et le réglage saisi n'aurait AUCUN effet, sans erreur.
     */
    public function test_le_gabarit_est_enregistre_dans_la_structure_attendue_par_le_moteur(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.templates.store'), $this->paramsValides())
            ->assertRedirect();

        $params = Template::where('name', 'Gabarit de test')->firstOrFail()->params;

        foreach (['police', 'tailles', 'couleurs', 'interligne', 'espacements', 'alignement_titres', 'alignement_corps', 'marges', 'tableau'] as $cle) {
            $this->assertArrayHasKey($cle, $params, "La clé « {$cle} » doit être présente.");
        }

        $this->assertSame(1440, $params['marges']['top']);
        $this->assertSame('TableGrid', $params['tableau']['style']);
        $this->assertTrue($params['tableau']['bordure']);
    }

    /**
     * **Une taille hors bornes est refusée.**
     *
     * Une taille de 200 pt produirait un document inutilisable, et le gabarit
     * s'applique à TOUS les documents suivants. La borne haute est de 72 pt.
     */
    public function test_une_taille_hors_bornes_est_refusee(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.templates.store'), $this->paramsValides([
                'tailles' => ['corps' => 200],
            ]))
            ->assertSessionHasErrors('tailles.corps');

        $this->assertDatabaseCount('templates', 0);
    }

    /**
     * Une taille inférieure à 6 pt est refusée.
     *
     * Le seuil bas n'est pas arbitraire : en dessous, le texte devient illisible
     * à l'impression. Un gabarit ainsi réglé produirait des documents que
     * l'étudiant ne pourrait pas rendre.
     */
    public function test_une_taille_trop_petite_est_refusee(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.templates.store'), $this->paramsValides([
                'tailles' => ['corps' => 2],
            ]))
            ->assertSessionHasErrors('tailles.corps');
    }

    /**
     * **Une couleur hors format hexadécimal est refusée.**
     *
     * PhpWord attend six chiffres hexadécimaux SANS dièse. Accepter `#1F3864` ou
     * `bleu` produirait soit une erreur à la génération, soit une couleur ignorée
     * en silence — le titre s'afficherait alors en noir sans que personne ne
     * sache pourquoi.
     */
    public function test_une_couleur_invalide_est_refusee(): void
    {
        foreach (['#1F3864', 'bleu', '12345', 'GGGGGG'] as $invalide) {
            $this->actingAs($this->admin())
                ->post(route('admin.templates.store'), $this->paramsValides([
                    'couleurs' => ['titre1' => $invalide],
                ]))
                ->assertSessionHasErrors('couleurs.titre1');
        }

        $this->assertDatabaseCount('templates', 0);
    }

    /**
     * Une marge négative est refusée.
     *
     * Une marge négative place le texte hors de la page imprimable.
     */
    public function test_une_marge_negative_est_refusee(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.templates.store'), $this->paramsValides([
                'marges' => ['left' => -100],
            ]))
            ->assertSessionHasErrors('marges.left');
    }

    /**
     * Un nom déjà employé est refusé.
     *
     * La colonne est unique en base : sans cette règle, l'utilisateur recevrait
     * une erreur SQL au lieu d'un message compréhensible.
     */
    public function test_un_nom_deja_utilise_est_refuse(): void
    {
        Template::create([
            'name' => 'Nom pris',
            'description' => '',
            'params' => $this->paramsValides(),
            'is_public' => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.templates.store'), $this->paramsValides(['name' => 'Nom pris']))
            ->assertSessionHasErrors('name');
    }

    /**
     * Un alignement hors de la liste est refusé.
     *
     * La valeur alimente directement le moteur : une valeur libre y produirait un
     * comportement indéfini plutôt qu'une erreur visible.
     */
    public function test_un_alignement_invalide_est_refuse(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.templates.store'), $this->paramsValides([
                'alignement_titres' => 'diagonal',
            ]))
            ->assertSessionHasErrors('alignement_titres');
    }

    // -------------------------------------------------------------------------
    // Modification
    // -------------------------------------------------------------------------

    public function test_l_admin_peut_modifier_un_gabarit(): void
    {
        $template = Template::create([
            'name' => 'À modifier',
            'description' => '',
            'params' => $this->paramsValides(),
            'is_public' => true,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $template), $this->paramsValides([
                'name' => 'Modifié',
                'police' => 'Arial',
            ]))
            ->assertRedirect(route('admin.templates.index'));

        $template->refresh();
        $this->assertSame('Modifié', $template->name);
        $this->assertSame('Arial', $template->params['police']);
    }

    /**
     * **Réenregistrer un gabarit sans changer son nom doit fonctionner.**
     *
     * La règle d'unicité du nom exclut le gabarit courant lors d'une
     * modification. Sans cette exclusion, ouvrir un gabarit puis l'enregistrer
     * sans rien changer échouerait sur sa propre contrainte — un défaut qui
     * rendrait l'écran inutilisable dès la première utilisation.
     */
    public function test_reenregistrer_un_gabarit_sans_changer_son_nom_fonctionne(): void
    {
        $template = Template::create([
            'name' => 'Nom inchangé',
            'description' => '',
            'params' => $this->paramsValides(),
            'is_public' => true,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.templates.update', $template), $this->paramsValides(['name' => 'Nom inchangé']))
            ->assertRedirect(route('admin.templates.index'))
            ->assertSessionHasNoErrors();
    }

    /**
     * Le formulaire de modification pré-remplit les valeurs NORMALISÉES.
     *
     * Un gabarit ancien peut n'avoir que quelques clés. Le moteur applique alors
     * ses valeurs par défaut. Si le formulaire affichait les valeurs brutes, les
     * champs manquants apparaîtraient vides — et l'administrateur croirait qu'ils
     * sont à zéro alors que le moteur applique autre chose.
     */
    public function test_le_formulaire_de_modification_affiche_les_valeurs_normalisees(): void
    {
        $template = Template::create([
            // Gabarit volontairement incomplet : seules deux clés.
            'name' => 'Gabarit incomplet',
            'description' => '',
            'params' => ['police' => 'Georgia'],
            'is_public' => true,
        ]);

        // Les valeurs par défaut du moteur doivent apparaître dans le formulaire.
        $this->actingAs($this->admin())
            ->get(route('admin.templates.edit', $template))
            ->assertOk()
            ->assertSee('Georgia')
            ->assertSee('tableau[style]', false)
            // Le style de tableau par défaut du moteur, absent des params stockés.
            ->assertSee('TableGrid');
    }

    // -------------------------------------------------------------------------
    // Suppression
    // -------------------------------------------------------------------------

    public function test_l_admin_peut_supprimer_un_gabarit_jamais_utilise(): void
    {
        $template = Template::create([
            'name' => 'Jamais utilisé',
            'description' => '',
            'params' => $this->paramsValides(),
            'is_public' => true,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.templates.destroy', $template))
            ->assertRedirect(route('admin.templates.index'));

        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    /**
     * **Un gabarit utilisé ne peut pas être supprimé.**
     *
     * `generated_documents.template_id` y réfère. Le supprimer laisserait une
     * référence orpheline, et le document perdrait l'indication de la mise en
     * forme sous laquelle il a été produit — information dont on a besoin pour
     * comprendre un document ancien ou traiter une réclamation.
     *
     * Le refus n'est pas un échec : c'est le comportement attendu, et il indique
     * le nombre de documents concernés pour que l'administrateur puisse décider.
     */
    public function test_un_gabarit_utilise_ne_peut_pas_etre_supprime(): void
    {
        $utilisateur = $this->admin();
        $template = Template::create([
            'name' => 'Gabarit utilisé',
            'description' => '',
            'params' => $this->paramsValides(),
            'is_public' => true,
        ]);

        $document = Document::create([
            'filename' => 'test.docx',
            'path' => 'documents/test.docx',
            'status' => 'ready',
            'metadata' => ['user_id' => $utilisateur->id],
        ]);

        GeneratedDocument::create([
            'document_id' => $document->id,
            'output_path' => 'test_scripts/gen.docx',
            'status' => 'generated',
            'template_id' => $template->id,
        ]);

        $this->actingAs($utilisateur)
            ->delete(route('admin.templates.destroy', $template))
            ->assertRedirect(route('admin.templates.index'))
            ->assertSessionHasErrors('template');

        // Le gabarit est TOUJOURS là : la suppression a bien été refusée.
        $this->assertDatabaseHas('templates', ['id' => $template->id]);
    }

    // -------------------------------------------------------------------------
    // Publication
    // -------------------------------------------------------------------------

    /**
     * Dépublier est la réponse courante à « ce gabarit ne doit plus être
     * proposé ». L'action ne touche aucun réglage — c'est pourquoi elle est
     * séparée du formulaire de modification.
     */
    public function test_l_admin_peut_depublier_et_republier_un_gabarit(): void
    {
        $template = Template::create([
            'name' => 'À basculer',
            'description' => '',
            'params' => $this->paramsValides(),
            'is_public' => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.templates.publication', $template))
            ->assertRedirect();

        $this->assertFalse($template->fresh()->is_public);

        $this->actingAs($this->admin())
            ->post(route('admin.templates.publication', $template))
            ->assertRedirect();

        $this->assertTrue($template->fresh()->is_public);
    }

    /**
     * Dépublier ne modifie AUCUN paramètre.
     *
     * Si la bascule touchait aux réglages, un simple retrait de la liste
     * modifierait la mise en forme — le contraire de ce qu'annonce le bouton.
     */
    public function test_la_bascule_de_publication_ne_modifie_aucun_parametre(): void
    {
        $params = $this->paramsValides();
        $template = Template::create([
            'name' => 'Réglages intacts',
            'description' => '',
            'params' => $params,
            'is_public' => true,
        ]);

        $this->actingAs($this->admin())->post(route('admin.templates.publication', $template));

        $this->assertSame('Times New Roman', $template->fresh()->params['police']);
        $this->assertSame($params['marges'], $template->fresh()->params['marges']);
    }
}
