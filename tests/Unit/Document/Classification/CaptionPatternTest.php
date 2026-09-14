<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Classification;

use App\Document\Classification\CaptionPattern;
use App\Document\Structure\BlockCategory;
use Tests\TestCase;

/**
 * Tests du détecteur de légendes et de renvois.
 *
 * Enjeu : vérifier qu'on reconnaît les conventions SANS jamais appeler l'IA
 * (règle n°1 du projet), tout en évitant les faux positifs — une phrase du
 * corps mentionnant « la figure 3 » n'est pas une légende.
 */
class CaptionPatternTest extends TestCase
{
    private CaptionPattern $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new CaptionPattern;
    }

    // -------------------------------------------------------------------------
    // Légendes — les 4 catégories
    // -------------------------------------------------------------------------

    public function test_une_legende_de_figure_est_reconnue(): void
    {
        $detected = $this->detector->detect('Figure 1 : Architecture du système');

        $this->assertNotNull($detected);
        $this->assertSame(BlockCategory::Figure, $detected['category']);
        $this->assertSame('1', $detected['original_number']);
        $this->assertSame('arabic', $detected['number_source']);
        $this->assertSame('Architecture du système', $detected['caption_text']);
    }

    public function test_une_legende_de_tableau_est_reconnue(): void
    {
        $detected = $this->detector->detect('Tableau 3 : Résultats de l\'enquête');

        $this->assertSame(BlockCategory::Table, $detected['category']);
        $this->assertSame('3', $detected['original_number']);
    }

    public function test_une_legende_d_annexe_avec_lettre_est_reconnue(): void
    {
        $detected = $this->detector->detect('Annexe B : Questionnaire');

        $this->assertSame(BlockCategory::Annexe, $detected['category']);
        $this->assertSame('B', $detected['original_number']);
        $this->assertSame('letter', $detected['number_source']);
    }

    public function test_une_legende_de_planche_est_reconnue(): void
    {
        $detected = $this->detector->detect('Planche 2 : Coupe transversale');

        $this->assertSame(BlockCategory::Planche, $detected['category']);
        $this->assertSame('2', $detected['original_number']);
    }

    // -------------------------------------------------------------------------
    // Tolérance d'écriture (les auteurs varient)
    // -------------------------------------------------------------------------

    public function test_le_separateur_peut_etre_un_deux_points_un_point_ou_un_tiret(): void
    {
        foreach ([
            'Figure 1 : Schéma',
            'Figure 1: Schéma',
            'Figure 1 - Schéma',
            'Figure 1. Schéma',
            'Figure 1 — Schéma',
        ] as $text) {
            $detected = $this->detector->detect($text);

            $this->assertNotNull($detected, "Échec sur : {$text}");
            $this->assertSame(BlockCategory::Figure, $detected['category']);
            $this->assertSame('1', $detected['original_number']);
        }
    }

    public function test_la_casse_et_les_accents_n_empechent_pas_la_detection(): void
    {
        $this->assertNotNull($this->detector->detect('FIGURE 1 : Schéma'));
        $this->assertNotNull($this->detector->detect('Légende 1 : Schéma'));
        $this->assertNotNull($this->detector->detect('LEGENDE 1 : Schéma'));
    }

    public function test_une_legende_sans_texte_reste_detectee(): void
    {
        // « Figure 1 » seul : la légende existe (numéro posé par un champ SEQ
        // de Word), seul son libellé manque. On la détecte comme légende.
        $detected = $this->detector->detect('Figure 1');

        $this->assertNotNull($detected);
        $this->assertSame('1', $detected['original_number']);
        $this->assertSame('', $detected['caption_text']);
    }

    public function test_un_mot_coupe_apres_le_numero_n_est_pas_une_legende(): void
    {
        // « Figure 1er trimestre » : le « 1 » fait partie d'un mot composé, ce
        // n'est pas un numéro de figure. Sans ce garde-fou, tout nombre suivi
        // de lettres produirait une fausse légende.
        $this->assertNull($this->detector->detect('Figure 1er trimestre de l\'année'));
    }

    // -------------------------------------------------------------------------
    // Rejets (faux positifs)
    // -------------------------------------------------------------------------

    public function test_une_phrase_du_corps_mentionnant_une_figure_n_est_pas_une_legende(): void
    {
        // La légende doit être en TÊTE de paragraphe. Sinon toute phrase
        // parlant d'une figure deviendrait une légende.
        $this->assertNull($this->detector->detect(
            'Comme le montre la figure 3, le débit augmente sensiblement.'
        ));
    }

    public function test_un_texte_sans_mot_cle_n_est_pas_une_legende(): void
    {
        $this->assertNull($this->detector->detect('Le schéma ci-dessous présente l\'architecture.'));
    }

    public function test_un_texte_vide_n_est_pas_une_legende(): void
    {
        $this->assertNull($this->detector->detect(''));
    }

    public function test_une_entree_de_liste_avec_points_de_conduite_est_detectee_comme_telle(): void
    {
        // Ces entrées vivent dans le frontispice : les reclassifier comme
        // légendes créerait des doublons dans la liste générée.
        $text = 'Figure 1 : Architecture .......... 12';

        $this->assertTrue($this->detector->isListEntry($text));
    }

    public function test_une_legende_normale_n_est_pas_une_entree_de_liste(): void
    {
        $this->assertFalse($this->detector->isListEntry('Figure 1 : Architecture du système'));
    }

    // -------------------------------------------------------------------------
    // Légendes sans numéro (champ SEQ non résolu)
    // -------------------------------------------------------------------------

    public function test_une_legende_sans_numero_est_reconnue_avec_sa_categorie(): void
    {
        // Word insère un champ SEQ : le numéro n'apparaît pas dans le texte brut.
        $this->assertSame(
            BlockCategory::Figure,
            $this->detector->detectWithoutNumber('Figure : Schéma du pipeline')
        );
    }

    public function test_detect_without_number_rejette_un_texte_ordinaire(): void
    {
        $this->assertNull($this->detector->detectWithoutNumber('Le tableau ci-dessus montre que…'));
    }

    // -------------------------------------------------------------------------
    // Renvois croisés
    // -------------------------------------------------------------------------

    public function test_un_renvoi_en_milieu_de_phrase_est_detecte(): void
    {
        // Différence clé avec une légende : un renvoi peut apparaître n'importe où.
        $found = $this->detector->detectCrossReferences('Voir la Figure 3 pour plus de détails.');

        $this->assertCount(1, $found);
        $this->assertSame(BlockCategory::Figure, $found[0]['category']);
        $this->assertSame('3', $found[0]['original_number']);
    }

    public function test_plusieurs_renvois_dans_un_paragraphe_sont_tous_detectes(): void
    {
        $found = $this->detector->detectCrossReferences(
            'Cf. Figure 1 et Tableau 2, ainsi que l\'Annexe B.'
        );

        $this->assertCount(3, $found);

        $categories = array_map(static fn (array $r): BlockCategory => $r['category'], $found);
        $this->assertContains(BlockCategory::Figure, $categories);
        $this->assertContains(BlockCategory::Table, $categories);
        $this->assertContains(BlockCategory::Annexe, $categories);
    }

    public function test_un_renvoi_porte_sa_position_dans_le_texte(): void
    {
        // La position sert au signalement et au remplacement ciblé lors de la
        // réécriture des renvois (R4).
        $found = $this->detector->detectCrossReferences('Texte. Voir Figure 5 ici.');

        $this->assertCount(1, $found);
        $this->assertGreaterThan(0, $found[0]['position']);
    }

    public function test_un_paragraphe_sans_renvoi_ne_retourne_rien(): void
    {
        $this->assertSame([], $this->detector->detectCrossReferences(
            'Ce paragraphe ne référence aucun élément numéroté.'
        ));
    }

    public function test_les_4_categories_sont_detectees_en_renvoi(): void
    {
        foreach (['Figure 1', 'Tableau 2', 'Annexe A', 'Planche 3'] as $reference) {
            $found = $this->detector->detectCrossReferences("Voir {$reference} ci-dessous.");

            $this->assertCount(1, $found, "Renvoi non détecté : {$reference}");
        }
    }

    public function test_un_renvoi_sans_numero_n_est_pas_detecte(): void
    {
        // « voir la figure ci-dessous » n'est pas un renvoi numéroté : il n'y a
        // rien à résoudre ni à renuméroter.
        $this->assertSame([], $this->detector->detectCrossReferences(
            'Voir la figure ci-dessous pour plus de détails.'
        ));
    }
}
