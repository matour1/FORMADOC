<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Classification;

use App\Document\Adapters\DocxOoxml\StyleReader;
use App\Document\Structure\BlockType;
use Tests\TestCase;

/**
 * Détection des entrées de sommaire — L1.1 et L1.2.
 *
 * **Le défaut, mesuré.** Quand un document contient déjà un sommaire, ses entrées
 * étaient détectées comme des TITRES, numéros de page compris. Sur le document de
 * référence du projet, cela représentait **58 des 80 titres détectés (72 %)** —
 * soit une lecture entièrement fausse du document : le sommaire serait entré dans
 * le sommaire généré, et la renumérotation aurait travaillé sur une base erronée.
 *
 * **Pourquoi deux mécanismes, et non un seul.** Les sommaires n'arrivent pas
 * tous de la même façon :
 *
 *   - un sommaire GÉNÉRÉ par Word porte des styles `toc N` — signal déterministe,
 *     traité par `is_list_style` (L1.1) ;
 *   - un sommaire TAPÉ À LA MAIN n'en porte aucun — c'est le cas du document de
 *     référence, où L1.1 seul ne reclassait RIEN (mesuré : 0 sur 48). Il faut
 *     alors un signal de CONTENU (L1.2).
 *
 * Les deux mécanismes sont donc complémentaires, et un test les couvre
 * séparément : croire que le style suffit aurait laissé le cas mesuré intact.
 *
 * **Ce que ces tests protègent en priorité : ne pas trop reclasser.** Un titre
 * retiré à tort casse la hiérarchie du document ; un titre superflu se voit et se
 * corrige. Le risque est donc asymétrique, et les tests insistent sur les cas
 * qui NE DOIVENT PAS être reclassés.
 */
class TocEntryDetectionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // L1.1 — Style de liste (sommaire généré par Word)
    // -------------------------------------------------------------------------

    /**
     * **`toc 4` doit être reconnu.**
     *
     * La liste des styles s'arrêtait à `toc 3`, or le relevé des styles
     * réellement employés sur le corpus donne `toc 4` en **tête de fréquence
     * (52 occurrences)**, devant `toc 1` (50), `toc 2` (42) et `toc 3` (41).
     *
     * Le style le plus fréquent était donc celui qui n'était pas reconnu : un
     * sommaire de niveau 4 — fréquent dans un mémoire, où les sous-sections
     * descendent souvent à quatre niveaux — passait entièrement au travers.
     */
    public function test_le_style_toc_4_est_reconnu(): void
    {
        $lecteur = $this->lecteurDeStyles(['S1' => 'toc 4']);

        $this->assertTrue($lecteur->isListStyle('S1'),
            '« toc 4 » est le style de sommaire le PLUS fréquent du corpus : '
            .'s\'arrêter à « toc 3 » laissait passer les sommaires de niveau 4.');
        $this->assertSame(4, $lecteur->listStyleLevel('S1'));
    }

    /**
     * Le niveau n'est pas plafonné : un sommaire peut descendre à six niveaux.
     *
     * Une liste figée est toujours incomplète — c'est précisément ce qui a
     * produit le défaut sur `toc 4`. On reconnaît donc la FORME du nom.
     */
    public function test_le_niveau_n_est_pas_plafonne(): void
    {
        $lecteur = $this->lecteurDeStyles([
            'S1' => 'toc 1', 'S4' => 'toc 4', 'S9' => 'toc 9',
        ]);

        $this->assertSame(1, $lecteur->listStyleLevel('S1'));
        $this->assertSame(4, $lecteur->listStyleLevel('S4'));
        $this->assertSame(9, $lecteur->listStyleLevel('S9'));
    }

    /**
     * Les noms localisés sont reconnus.
     *
     * Word traduit les noms de style selon la langue de l'interface : un document
     * francophone porte « Sommaire 1 », pas « toc 1 ». Une liste anglaise seule
     * aurait manqué tous les documents produits en français.
     */
    public function test_les_noms_localises_sont_reconnus(): void
    {
        $lecteur = $this->lecteurDeStyles([
            'S1' => 'Sommaire 1',
            'S2' => 'Sommaire 2',
            'S3' => 'Table des matières',
            'S4' => 'Liste des figures',
            'S5' => 'Liste des tableaux',
        ]);

        foreach (['S1', 'S2', 'S3', 'S4', 'S5'] as $styleId) {
            $this->assertTrue($lecteur->isListStyle($styleId), "Le style « {$styleId} » doit être reconnu.");
        }
    }

    /**
     * **Un style de titre n'est PAS un style de liste.**
     *
     * Contrôle négatif indispensable : si « Titre 1 » était pris pour un style de
     * sommaire, tous les titres du document deviendraient des entrées de
     * sommaire — le document entier serait perdu, et de façon silencieuse.
     */
    public function test_un_style_de_titre_n_est_pas_un_style_de_liste(): void
    {
        $lecteur = $this->lecteurDeStyles([
            'T1' => 'Heading 1', 'T2' => 'Titre 2', 'T3' => 'Normal', 'T4' => 'Text Body',
        ]);

        foreach (['T1', 'T2', 'T3', 'T4'] as $styleId) {
            $this->assertFalse($lecteur->isListStyle($styleId), "« {$styleId} » ne doit pas être un style de liste.");
        }
    }

    /**
     * Le style sans niveau déclaré est tout de même reconnu.
     *
     * « Liste des figures » n'a pas de numéro dans son nom : retourner `null`
     * ferait passer cette entrée pour un titre. C'est le fait d'être une entrée
     * de liste qui compte, pas la connaissance de sa profondeur.
     */
    public function test_un_style_de_liste_sans_niveau_est_reconnu(): void
    {
        $lecteur = $this->lecteurDeStyles(['S1' => 'Liste des figures']);

        $this->assertTrue($lecteur->isListStyle('S1'));
        $this->assertSame(1, $lecteur->listStyleLevel('S1'),
            'Un niveau de repli est retourné plutôt que null : sans lui, l\'entrée '
            .'serait traitée comme un titre.');
    }

    /**
     * Un style inconnu ou absent ne lève pas d'erreur.
     *
     * `null` doit traverser la chaîne sans la casser : un document référence
     * souvent des styles absents de `styles.xml` (produit par un outil tiers).
     */
    public function test_un_style_absent_ne_leve_pas_d_erreur(): void
    {
        $lecteur = $this->lecteurDeStyles(['S1' => 'toc 1']);

        $this->assertNull($lecteur->listStyleLevel(null));
        $this->assertNull($lecteur->listStyleLevel('inexistant'));
        $this->assertFalse($lecteur->isListStyle(null));
    }

    // -------------------------------------------------------------------------
    // Le type de bloc
    // -------------------------------------------------------------------------

    /**
     * `TocEntry` existe et se distingue d'un titre.
     *
     * Le type est distinct de `Heading` à dessein : une entrée de sommaire est la
     * COPIE d'un titre, pas un titre. Les confondre revient à compter chaque
     * titre deux fois — mesuré : 72 % de faux titres sur le document de référence.
     */
    public function test_le_type_toc_entry_existe_et_est_distinct_d_un_titre(): void
    {
        $this->assertSame('toc_entry', BlockType::TocEntry->value);
        $this->assertNotSame(BlockType::Heading, BlockType::TocEntry);

        // Un titre participe à la table des matières ; une entrée de sommaire,
        // non — c'est justement ce qu'on veut éviter.
        $this->assertFalse(BlockType::TocEntry->isHeading(),
            'Une entrée de sommaire ne doit pas participer à la table des matières : '
            .'c\'est ce qui produisait « SOMMAIRE … 3 » dans le sommaire généré.');
    }

    // -------------------------------------------------------------------------
    // Aides
    // -------------------------------------------------------------------------

    /**
     * Construit un `StyleReader` à partir d'un mapping id → nom de style.
     *
     * On passe par le XML réel (`parse()`) plutôt que de simuler le lecteur :
     * c'est le chemin de production. Un test qui fabriquerait l'objet à la main
     * pourrait valider un comportement que le vrai lecteur n'a pas.
     *
     * @param  array<string, string>  $styles
     */
    private function lecteurDeStyles(array $styles): StyleReader
    {
        $xml = '<?xml version="1.0"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';

        foreach ($styles as $id => $nom) {
            $xml .= '<w:style w:type="paragraph" w:styleId="'.htmlspecialchars($id).'">'
                .'<w:name w:val="'.htmlspecialchars($nom).'"/>'
                .'</w:style>';
        }

        $xml .= '</w:styles>';

        $lecteur = new StyleReader;
        $lecteur->parse($xml);

        return $lecteur;
    }
}
