<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Document;
use App\Models\DocumentClarification;
use App\Models\DocumentStructure;
use App\Models\User;
use App\Services\Billing\DocumentMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Surveillance du traitement documentaire.
 *
 * **Ce que ces tests protègent.** La surveillance repose sur un choix explicite :
 * n'afficher que des mesures VRAIES, et distinguer un défaut technique d'un
 * utilisateur qui a abandonné. Deux erreurs sont possibles, et toutes deux
 * conduiraient à une décision fausse :
 *
 *  1. **compter un document terminé comme bloqué** — un document `ready` vieux de
 *     30 jours n'est pas « en panne », il est traité. L'alerter ferait perdre du
 *     temps sur un faux positif, et banaliserait l'alerte suivante ;
 *  2. **conclure « bloqué » sur un document simplement abandonné** — l'utilisateur a
 *     déposé un fichier et n'est jamais revenu. C'est un signal commercial, pas une
 *     panne, et les deux appellent des actions opposées.
 */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crée une structure documentaire.
     *
     * `structure` est NOT NULL en base : SQLite applique la contrainte alors que
     * MySQL l'aurait laissée passer avec un avertissement, ce qui aurait fait
     * échouer le test sur un moteur et pas sur l'autre.
     */
    private function structure(Document $document, ?string $pipeline = null): DocumentStructure
    {
        return DocumentStructure::create([
            'document_id' => $document->id,
            'schema_version' => 1,
            'structure' => ['blocks' => []],
            'structural_json' => ['blocks' => []],
            'pipeline' => $pipeline,
        ]);
    }

    private function service(): DocumentMonitoringService
    {
        return app(DocumentMonitoringService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * Crée un document daté.
     *
     * `created_at` et `updated_at` sont forcés après création : ils ne sont pas
     * *fillable* sur `Document` (une date de dépôt doit refléter l'instant réel, pas
     * une valeur fournie). Les passer à `create()` serait ignoré SILENCIEUSEMENT, et
     * le test porterait alors sur une donnée qui n'est pas où on croit.
     */
    private function document(string $statut, int $jours = 0, ?Carbon $date = null): Document
    {
        $document = Document::create([
            'filename' => 'fichier.docx',
            'path' => 'documents/fichier.docx',
            'status' => $statut,
        ]);

        $date ??= now()->subDays($jours);
        $document->forceFill(['created_at' => $date, 'updated_at' => $date])->save();

        return $document;
    }

    // -------------------------------------------------------------------------
    // Répartition par statut
    // -------------------------------------------------------------------------

    public function test_les_documents_sont_regroupes_par_statut_avec_leur_age(): void
    {
        $this->document('pending', 10);
        $this->document('pending', 5);
        $this->document('ready', 2);

        $statuts = collect($this->service()->documentsParStatut())->keyBy('status');

        $this->assertSame(2, $statuts['pending']['total']);
        $this->assertSame(1, $statuts['ready']['total']);
        // Âge moyen de 7,5 jours pour les deux `pending` (10 et 5).
        $this->assertEqualsWithDelta(7.5, $statuts['pending']['age_moyen'], 0.5);
        $this->assertSame(10, $statuts['pending']['age_max']);
    }

    public function test_les_seuils_separent_les_documents_a_examiner_des_abandonnes(): void
    {
        // 1 jour : sous tous les seuils (3 j comme 14 j).
        $this->document('pending', 1);
        // 7 jours : entre 3 et 14 → à examiner.
        $this->document('pending', 7);
        // 30 jours : au-delà de 14 → abandonné.
        $this->document('pending', 30);

        $statuts = collect($this->service()->documentsParStatut())->keyBy('status');

        // `attention` compte TOUT ce qui dépasse 3 jours, donc 7 et 30 seulement —
        // et non les trois documents : celui de 1 jour ne dépasse aucun seuil.
        // C'est pourquoi `abandonnes` est un SOUS-ENSEMBLE de `attention` : l'écran
        // affiche `attention - abandonnes` pour la colonne « à examiner ».
        $this->assertSame(2, $statuts['pending']['attention']);
        $this->assertSame(1, $statuts['pending']['abandonnes'], 'Un seul document dépasse 14 jours.');

        // Et les deux catégories sont bien distinctes : 1 à examiner, 1 abandonné.
        $this->assertSame(
            1,
            $statuts['pending']['attention'] - $statuts['pending']['abandonnes'],
            'La différence doit isoler les documents réellement bloqués des abandons.'
        );
    }

    /**
     * **Un document terminé n'est pas bloqué.**
     *
     * Le filtre exclut `ready` et `generated` : sans lui, un document prêt vieux de
     * 30 jours apparaîtrait dans la liste des « bloqués » et noierait les vrais cas.
     */
    public function test_un_document_termine_n_apparait_pas_dans_les_bloques(): void
    {
        $this->document('ready', 60);
        $this->document('generated', 60);
        $this->document('pending', 5);

        $bloques = $this->service()->documentsBloques();

        $this->assertCount(1, $bloques);
        $this->assertSame('pending', $bloques->first()->status);
    }

    public function test_les_documents_bloques_sont_ordonnes_du_plus_ancien_au_plus_recent(): void
    {
        $this->document('pending', 5);
        $this->document('detected', 20);
        $this->document('validated', 1);

        $bloques = $this->service()->documentsBloques();

        $this->assertSame(20, $bloques->first()->age_jours, 'Le plus ancien vient en premier.');
        $this->assertSame(5, $bloques[1]->age_jours);
    }

    // -------------------------------------------------------------------------
    // Clarifications
    // -------------------------------------------------------------------------

    public function test_les_passages_sans_reponse_sont_comptes_par_document(): void
    {
        $document = $this->document('validated', 1);
        $autre = $this->document('validated', 1);

        foreach (range(1, 5) as $i) {
            DocumentClarification::create([
                'document_id' => $document->id,
                'block_id' => 'b'.$i,
                'question' => 'Question '.$i,
                'input_type' => 'text',
            ]);
        }

        // Un passage RÉPONDU ne doit pas être compté comme en attente.
        DocumentClarification::create([
            'document_id' => $autre->id,
            'block_id' => 'b1',
            'question' => 'Répondue',
            'input_type' => 'text',
            'answered_at' => now(),
        ]);

        $resultat = $this->service()->clarificationsEnAttente();

        $this->assertSame(5, $resultat['total']);
        $this->assertSame(1, $resultat['documents'], 'Le document dont le passage est répondu ne compte pas.');
        $this->assertSame($document->id, $resultat['plus_charge']['document_id']);
        $this->assertSame(5, $resultat['plus_charge']['passages']);
    }

    /**
     * **Le défaut réel observé en production.**
     *
     * Un document peut se terminer « sans erreur » tout en demandant des centaines de
     * confirmations manuelles. Techniquement réussi, pratiquement inexploitable — et
     * aucun autre écran ne le montre.
     */
    public function test_un_document_avec_trop_de_passages_est_signale_comme_defaillant(): void
    {
        $document = $this->document('validated', 1);

        foreach (range(1, DocumentMonitoringService::SEUIL_PASSAGES_EXCESSIFS + 5) as $i) {
            DocumentClarification::create([
                'document_id' => $document->id,
                'block_id' => 'b'.$i,
                'question' => 'Question '.$i,
                'input_type' => 'text',
            ]);
        }

        $resultat = $this->service()->clarificationsEnAttente();

        $this->assertCount(1, $resultat['excessive']);
        $this->assertSame($document->id, $resultat['excessive'][0]['document_id']);
        $this->assertSame(
            DocumentMonitoringService::SEUIL_PASSAGES_EXCESSIFS + 5,
            $resultat['excessive'][0]['passages']
        );
    }

    public function test_un_document_avec_peu_de_passages_n_est_pas_signale(): void
    {
        // Contrôle négatif : sans lui, un seuil mal placé signalerait TOUS les
        // documents dès qu'un seul passage existe.
        $document = $this->document('validated', 1);

        DocumentClarification::create([
            'document_id' => $document->id,
            'block_id' => 'b1',
            'question' => 'Une seule question',
            'input_type' => 'text',
        ]);

        $this->assertSame([], $this->service()->clarificationsEnAttente()['excessive']);
    }

    // -------------------------------------------------------------------------
    // Pipelines
    // -------------------------------------------------------------------------

    public function test_les_pipelines_distingue_le_natif_de_l_historique(): void
    {
        $a = $this->document('ready', 1);
        $b = $this->document('ready', 1);

        $this->structure($a, 'native');

        // Ancien chemin : la colonne `pipeline` n'est pas renseignée (NULL), c'est
        // la marque de PHPWord.
        $this->structure($b);

        $pipelines = $this->service()->pipelines();

        $this->assertSame(1, $pipelines['natif']);
        $this->assertSame(1, $pipelines['historique']);
        $this->assertSame(50.0, $pipelines['part_natif']);
    }

    public function test_aucune_structure_donne_une_part_nulle_sans_division_par_zero(): void
    {
        // Sans garde, `0 / 0` produirait une division par zéro — donc une erreur
        // fatale sur un écran d'exploitation, à l'installation.
        $pipelines = $this->service()->pipelines();

        $this->assertSame(0, $pipelines['total']);
        $this->assertSame(0.0, $pipelines['part_natif']);
    }

    // -------------------------------------------------------------------------
    // Points d'attention
    // -------------------------------------------------------------------------

    public function test_un_defaut_de_classification_produit_un_point_d_attention(): void
    {
        $document = $this->document('validated', 1);

        foreach (range(1, 50) as $i) {
            DocumentClarification::create([
                'document_id' => $document->id,
                'block_id' => 'b'.$i,
                'question' => 'Question '.$i,
                'input_type' => 'text',
            ]);
        }

        $points = collect($this->service()->pointsDAttention());
        $classification = $points->firstWhere(fn ($p) => str_contains($p['titre'], 'Classification en échec'));

        $this->assertNotNull($classification, 'Un document à 50 passages doit être signalé.');
        $this->assertSame('warning', $classification['niveau']);
        // Le détail doit EXPLIQUER le problème : une alerte sans explication mène à
        // une interprétation erronée, donc à une action inutile.
        $this->assertStringContainsString('inexploitable', $classification['detail']);
    }

    public function test_un_pipeline_natif_jamais_exerce_produit_un_point_d_attention(): void
    {
        // Tout tourne sur l'ancien chemin : l'application fonctionne, mais rien de
        // ce qui a été développé n'est exercé.
        $this->structure($this->document('ready', 1));

        $points = collect($this->service()->pointsDAttention());
        $pipeline = $points->firstWhere(fn ($p) => str_contains($p['titre'], 'pipeline natif'));

        $this->assertNotNull($pipeline);
        $this->assertStringContainsString('repli', $pipeline['detail']);
    }

    /**
     * Un document abandonné est signalé comme INFO, pas comme panne.
     *
     * La distinction décide de l'action : un abandon appelle une relance
     * commerciale, un vrai blocage appelle une correction technique. Les confondre
     * enverrait l'exploitant chercher un défaut inexistant.
     */
    public function test_un_document_abandonne_est_signale_comme_information_et_non_comme_panne(): void
    {
        $this->document('pending', 30);

        $points = collect($this->service()->pointsDAttention());
        $abandon = $points->firstWhere(fn ($p) => str_contains($p['titre'], 'inachevé'));

        $this->assertNotNull($abandon);
        $this->assertStringContainsString('signal commercial', $abandon['detail']);
        $this->assertStringNotContainsString('panne', $abandon['titre']);
    }

    public function test_une_file_vide_ne_produit_aucun_point_d_attention(): void
    {
        // Le traitement est synchrone : les tables `jobs` et `failed_jobs` doivent
        // rester vides, donc ne rien signaler.
        $this->assertSame([], $this->service()->pointsDAttention());
    }

    // -------------------------------------------------------------------------
    // Écran
    // -------------------------------------------------------------------------

    public function test_la_surveillance_est_reservee_a_l_administrateur(): void
    {
        $this->get(route('admin.monitoring'))->assertRedirect(route('login'));

        $ordinaire = User::factory()->create(['is_admin' => false]);
        $this->actingAs($ordinaire)->get(route('admin.monitoring'))->assertNotFound();

        $this->actingAs($this->admin())->get(route('admin.monitoring'))->assertOk();
    }

    /**
     * Les seuils doivent être AFFICHÉS.
     *
     * Un code couleur sans légende se découvre par tâtonnement, et une alerte
     * incomprise est une alerte ignorée — y compris la suivante, qui serait légitime.
     */
    public function test_l_ecran_affiche_les_seuils_appliques(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.monitoring'))
            ->assertOk()
            ->assertSee((string) DocumentMonitoringService::SEUIL_ATTENTION_JOURS.' jours')
            ->assertSee((string) DocumentMonitoringService::SEUIL_ABANDON_JOURS.' jours')
            ->assertSee((string) DocumentMonitoringService::SEUIL_PASSAGES_EXCESSIFS.' passages');
    }

    public function test_l_ecran_explique_l_absence_de_defaut_technique(): void
    {
        // Un écran vide ne dit pas si tout va bien ou si la mesure n'a pas lieu.
        //
        // `assertSee` échappe par défaut, donc l'apostrophe du texte rendu
        // (`d'attention`) ne correspondrait pas à la chaîne recherchée : on
        // désactive l'échappement, le texte étant déjà celui qu'on veut lire.
        $this->actingAs($this->admin())
            ->get(route('admin.monitoring'))
            ->assertOk()
            ->assertSee('Aucun point d\'attention', false);
    }
}
