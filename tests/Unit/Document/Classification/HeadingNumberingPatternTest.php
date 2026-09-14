<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Classification;

use App\Document\Classification\HeadingNumberingPattern;
use Tests\TestCase;

/**
 * Tests du détecteur de niveau par numérotation.
 *
 * Ce signal est le plus fiable de la chaîne de classification : la
 * numérotation est SAISIE par l'auteur, elle exprime donc son intention réelle
 * — contrairement aux styles Word, souvent mal appliqués.
 */
class HeadingNumberingPatternTest extends TestCase
{
    private HeadingNumberingPattern $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new HeadingNumberingPattern;
    }

    // -------------------------------------------------------------------------
    // Numérotation décimale
    // -------------------------------------------------------------------------

    public function test_une_numerotation_simple_donne_le_niveau_un(): void
    {
        $detected = $this->detector->detect('1. Introduction');

        $this->assertNotNull($detected);
        $this->assertSame(1, $detected['level']);
        $this->assertSame('decimal', $detected['kind']);
    }

    public function test_une_sous_numerotation_donne_le_niveau_deux(): void
    {
        $detected = $this->detector->detect('1.1 Contexte');

        $this->assertSame(2, $detected['level']);
    }

    public function test_une_numerotation_triple_donne_le_niveau_trois(): void
    {
        $detected = $this->detector->detect('2.3.1 Analyse détaillée');

        $this->assertSame(3, $detected['level']);
    }

    public function test_une_numerotation_quadruple_donne_le_niveau_quatre(): void
    {
        // L'ordre de test des patterns compte : « 1.2.3.4 » doit être reconnu
        // avant « 1.2.3 », sinon la profondeur serait sous-estimée.
        $detected = $this->detector->detect('1.2.3.4 Détail fin');

        $this->assertSame(4, $detected['level']);
    }

    public function test_le_separateur_peut_etre_un_parenthese_ou_un_point(): void
    {
        $this->assertSame(1, $this->detector->detect('1) Introduction')['level']);
        $this->assertSame(1, $this->detector->detect('1. Introduction')['level']);
        $this->assertSame(2, $this->detector->detect('2.1. Contexte')['level']);
    }

    public function test_un_nombre_a_deux_chiffres_est_reconnu(): void
    {
        $detected = $this->detector->detect('12. Conclusion générale');

        $this->assertSame(1, $detected['level']);
    }

    // -------------------------------------------------------------------------
    // Mots-clés
    // -------------------------------------------------------------------------

    public function test_un_mot_cle_chapitre_donne_le_niveau_un(): void
    {
        $detected = $this->detector->detect('CHAPITRE 1 : Présentation');

        $this->assertSame(1, $detected['level']);
        $this->assertSame('keyword', $detected['kind']);
    }

    public function test_un_chapitre_en_chiffres_romains_est_reconnu(): void
    {
        $this->assertSame(1, $this->detector->detect('CHAPITRE IV — Bilan')['level']);
    }

    public function test_les_sections_usuelles_sont_reconnues_comme_niveau_un(): void
    {
        foreach ([
            'INTRODUCTION',
            'CONCLUSION GÉNÉRALE',
            'RÉSUMÉ',
            'SOMMAIRE',
            'REMERCIEMENTS',
            'BIBLIOGRAPHIE',
            'ANNEXES',
        ] as $keyword) {
            $detected = $this->detector->detect($keyword);

            $this->assertNotNull($detected, "« {$keyword} » devrait être reconnu");
            $this->assertSame(1, $detected['level'], "« {$keyword} » devrait être de niveau 1");
        }
    }

    public function test_une_numerotation_romaine_donne_le_niveau_un(): void
    {
        $detected = $this->detector->detect('III. Méthodologie');

        $this->assertSame(1, $detected['level']);
        $this->assertSame('roman', $detected['kind']);
    }

    public function test_une_numerotation_alphabétique_donne_le_niveau_deux(): void
    {
        $detected = $this->detector->detect('B. Méthode de collecte des données');

        $this->assertSame(2, $detected['level']);
        $this->assertSame('alpha', $detected['kind']);
    }

    // -------------------------------------------------------------------------
    // Rejets (faux positifs à éviter)
    // -------------------------------------------------------------------------

    public function test_une_phrase_ordinaire_n_est_pas_un_titre(): void
    {
        $this->assertNull($this->detector->detect(
            'Le projet a été mené sur une période de six mois dans une entreprise locale.'
        ));
    }

    public function test_une_annee_en_debut_de_phrase_n_est_pas_un_titre(): void
    {
        // Piège classique : « 2026 a été une année charnière. » commence par un
        // nombre, mais n'est pas numéroté (pas de point ni de parenthèse).
        $this->assertNull($this->detector->detect('2026 a été une année charnière pour le secteur.'));
    }

    public function test_une_enumeration_courte_en_lettre_est_ignoree(): void
    {
        // « A. » suivi de presque rien est une énumération, pas un titre :
        // on exige du contenu après le préfixe.
        $this->assertNull($this->detector->detect('A. Ok'));
        $this->assertNotNull($this->detector->detect('A. Analyse des résultats obtenus'));
    }

    public function test_un_texte_vide_ne_contient_aucune_numerotation(): void
    {
        $this->assertNull($this->detector->detect(''));
        $this->assertNull($this->detector->detect('   '));
    }

    public function test_une_puce_en_tete_est_ignoree_avant_analyse(): void
    {
        // Word insère parfois une puce ou un tiret avant la numérotation.
        $detected = $this->detector->detect('• 1. Introduction');

        $this->assertNotNull($detected);
        $this->assertSame(1, $detected['level']);
    }

    // -------------------------------------------------------------------------
    // Contradictions avec le style
    // -------------------------------------------------------------------------

    public function test_aucune_contradiction_quand_style_et_pattern_concordent(): void
    {
        $this->assertNull($this->detector->contradictionWith('1. Introduction', 1));
    }

    public function test_une_contradiction_est_detectee_quand_le_style_ment(): void
    {
        // Cas réel : style « Titre 1 » (niveau 1) mais texte « 1.1 » (niveau 2).
        $contradiction = $this->detector->contradictionWith('1.1 Institution', 1);

        $this->assertNotNull($contradiction);
        $this->assertSame(2, $contradiction['pattern_level']);
        $this->assertSame(1, $contradiction['style_level']);
    }

    public function test_une_contradiction_inverse_est_detectee(): void
    {
        // Style « Titre 2 » mais texte « 1. » : l'auteur a rétrogradé le niveau.
        $contradiction = $this->detector->contradictionWith('1. Introduction', 2);

        $this->assertNotNull($contradiction);
        $this->assertSame(1, $contradiction['pattern_level']);
    }

    public function test_aucune_contradiction_quand_le_texte_n_est_pas_numerote(): void
    {
        // Sans numérotation, il n'y a rien à contredire : le style fait foi.
        $this->assertNull($this->detector->contradictionWith('Introduction', 1));
    }

    public function test_is_top_level_identifie_les_titres_de_premier_niveau(): void
    {
        $this->assertTrue($this->detector->isTopLevel('1. Introduction'));
        $this->assertTrue($this->detector->isTopLevel('CHAPITRE 2'));
        $this->assertFalse($this->detector->isTopLevel('1.1 Contexte'));
    }

    public function test_is_numbered_reconnait_toute_profondeur(): void
    {
        $this->assertTrue($this->detector->isNumbered('1. Introduction'));
        $this->assertTrue($this->detector->isNumbered('1.1.1 Détail'));
        $this->assertFalse($this->detector->isNumbered('Introduction générale du projet'));
    }
}
