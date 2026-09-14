<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Structure;

use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\Fidelity;
use App\Document\Structure\LegacyStructureBridge;
use App\Document\Structure\StructuralDocument;
use Tests\TestCase;

/**
 * Tests du pont entre l'ancien format de structure et le schéma JSON commun.
 *
 * Enjeu : **35 structures de documents existent en base** au format historique.
 * Sans ce pont, brancher le nouveau pipeline invaliderait les analyses déjà
 * validées par les utilisateurs (décision D6).
 *
 * Les fixtures reproduisent les trois formats réellement présents en base,
 * observés par inspection de `document_structures.structure`.
 */
class LegacyStructureBridgeTest extends TestCase
{
    private LegacyStructureBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bridge = new LegacyStructureBridge;
    }

    // -------------------------------------------------------------------------
    // Détection du format
    // -------------------------------------------------------------------------

    public function test_le_format_complet_est_detecte(): void
    {
        // Format 3 : le plus riche, contient `body_complet` (16 en base).
        $this->assertSame('complet', $this->bridge->detectFormat([
            'titres' => [],
            'legends' => [],
            'body_complet' => [],
        ]));
    }

    public function test_le_format_structure_est_detecte(): void
    {
        // Format 2 : catégories structurées, sans `body_complet` (11 en base).
        $this->assertSame('structure', $this->bridge->detectFormat([
            'titres' => [],
            'tableaux' => [],
            'legends' => [],
        ]));
    }

    public function test_le_format_markdown_est_detecte(): void
    {
        // Format 1 : les titres sont un rendu Markdown généré par l'IA
        // (8 en base). Seules les légendes sont exploitables.
        $this->assertSame('markdown', $this->bridge->detectFormat([
            'titles' => '# Arborescence des titres extraits',
            'legends' => [],
            'titles_raw' => '{}',
        ]));
    }

    public function test_un_format_inconnu_est_signale(): void
    {
        $this->assertSame('inconnu', $this->bridge->detectFormat(['autre' => 1]));
    }

    // -------------------------------------------------------------------------
    // Conversion des titres
    // -------------------------------------------------------------------------

    public function test_un_titre_est_converti_avec_son_niveau(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [
                $this->legacyHeading('1. INTRODUCTION', niveau: 1, index: 0),
                $this->legacyHeading('2. METHODOLOGIE', niveau: 1, index: 3),
            ],
            'sous_titres' => [
                $this->legacyHeading('1.1 Contexte', niveau: 2, index: 1),
            ],
        ], 'doc_1');

        $headings = $result['document']->headings();

        $this->assertCount(3, $headings);
        $this->assertSame('1. INTRODUCTION', $headings[0]->text);
        $this->assertSame(1, $headings[0]->headingLevel);
    }

    public function test_un_sous_titre_conserve_son_niveau(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [],
            'sous_titres' => [
                $this->legacyHeading('1.1 Contexte', niveau: 2, index: 1),
                $this->legacyHeading('1.1.1 Détail', niveau: 3, index: 2),
            ],
        ], 'doc_1');

        $levels = array_map(fn ($h) => $h->headingLevel, $result['document']->headings());

        $this->assertSame([2, 3], $levels);
    }

    public function test_le_niveau_utilise_niveau_plutot_que_depth(): void
    {
        // `niveau` est le niveau métier (plus fiable) ; `depth` est la
        // profondeur technique de Word. Le premier doit primer.
        $item = $this->legacyHeading('Titre', niveau: 3, index: 0);
        $item['depth'] = 1;

        $result = $this->bridge->toStructural(['titres' => [$item]], 'doc_1');

        $this->assertSame(3, $result['document']->headings()[0]->headingLevel);
    }

    public function test_les_signaux_visuels_sont_repris(): void
    {
        // Taille et gras servent à la classification : les perdre dégraderait
        // la qualité du pipeline.
        $result = $this->bridge->toStructural([
            'titres' => [
                $this->legacyHeading('Titre gras', niveau: 1, index: 0, size: 16.0, bold: true),
            ],
        ], 'doc_1');

        $block = $result['document']->headings()[0];

        $this->assertSame(16.0, $block->fontSize);
        $this->assertTrue($block->isBold);
    }

    // -------------------------------------------------------------------------
    // Conversion des légendes
    // -------------------------------------------------------------------------

    public function test_une_legende_est_convertie_avec_sa_categorie(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, 0)],
            'legends' => [
                [
                    'raw' => 'Figure 1: Architecture du système',
                    'line' => 22,
                    'type' => 'Figure',
                    'label' => 'Architecture du système',
                    'number' => '1',
                ],
            ],
        ], 'doc_1');

        $captions = $result['document']->blocksOfType(BlockType::Caption);

        $this->assertCount(1, $captions);
        $this->assertSame(BlockCategory::Figure, $captions[0]->category);
        $this->assertSame('1', $captions[0]->originalNumber);
        $this->assertSame('Figure 1: Architecture du système', $captions[0]->text);
    }

    public function test_les_quatre_categories_de_legende_sont_reprises(): void
    {
        $legends = [];
        foreach (['Figure', 'Tableau', 'Annexe', 'Planche'] as $i => $type) {
            $legends[] = [
                'raw' => "{$type} ".($i + 1).': Libellé',
                'line' => $i,
                'type' => $type,
                'label' => 'Libellé',
                'number' => (string) ($i + 1),
            ];
        }

        $result = $this->bridge->toStructural(['legends' => $legends], 'doc_1');

        $categories = array_map(
            fn ($c) => $c->category,
            $result['document']->blocksOfType(BlockType::Caption)
        );

        $this->assertContains(BlockCategory::Figure, $categories);
        $this->assertContains(BlockCategory::Table, $categories);
        $this->assertContains(BlockCategory::Annexe, $categories);
        $this->assertContains(BlockCategory::Planche, $categories);
    }

    public function test_une_legende_sans_mot_cle_reconnu_ptr_default_sur_figure(): void
    {
        // Le format historique utilise toujours un mot-clé, mais on ne doit pas
        // planter si un type inattendu apparaît.
        $result = $this->bridge->toStructural([
            'legends' => [
                ['raw' => 'Légende 1 : Quelque chose', 'line' => 1, 'type' => 'Inconnu', 'label' => 'x', 'number' => '1'],
            ],
        ], 'doc_1');

        $this->assertSame(
            BlockCategory::Figure,
            $result['document']->blocksOfType(BlockType::Caption)[0]->category
        );
    }

    public function test_une_legende_sans_texte_est_ignoree(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, 0)],
            'legends' => [
                ['raw' => '', 'line' => 1, 'type' => 'Figure', 'label' => '', 'number' => '1'],
            ],
        ], 'doc_1');

        $this->assertSame([], $result['document']->blocksOfType(BlockType::Caption));
    }

    // -------------------------------------------------------------------------
    // Conversion des autres catégories
    // -------------------------------------------------------------------------

    public function test_un_tableau_avec_contenu_est_converti(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, 0)],
            'tableaux' => [
                [
                    'type' => 'tableaux',
                    'texte' => 'A | B',
                    'rows' => [['A', 'B'], ['1', '2']],
                    'position' => ['element_index' => 5],
                ],
            ],
        ], 'doc_1');

        $tables = $result['document']->blocksOfType(BlockType::Table);

        $this->assertCount(1, $tables);
        $this->assertSame('A', $tables[0]->tableData?->cell(0, 0));
        $this->assertSame('2', $tables[0]->tableData?->cell(1, 1));
    }

    public function test_les_images_sont_converties(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, 0)],
            'images' => [
                [
                    'type' => 'images',
                    'texte' => '[image:logo.png]',
                    'image_name' => 'logo.png',
                    'position' => ['element_index' => 2],
                ],
            ],
        ], 'doc_1');

        $images = $result['document']->blocksOfType(BlockType::Image);

        $this->assertCount(1, $images);
        $this->assertSame('logo.png', $images[0]->imageRef);
    }

    public function test_les_en_tetes_et_pieds_sont_conserves(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, 0)],
            'en_tetes' => [['texte' => 'RAPPORT DE STAGE', 'position' => ['element_index' => 1]]],
            'pieds_de_page' => [['texte' => 'Page 1', 'position' => ['element_index' => 2]]],
        ], 'doc_1');

        $this->assertCount(1, $result['document']->blocksOfType(BlockType::Header));
        $this->assertCount(1, $result['document']->blocksOfType(BlockType::Footer));
    }

    public function test_une_categorie_sous_forme_de_chaine_est_ignoree(): void
    {
        // Le format « markdown » stocke les titres sous forme de chaîne :
        // il faut l'ignorer sans planter.
        $result = $this->bridge->toStructural([
            'titles' => '# Arborescence des titres',
            'legends' => [
                ['raw' => 'Figure 1: Test', 'line' => 1, 'type' => 'Figure', 'label' => 'Test', 'number' => '1'],
            ],
        ], 'doc_1');

        $this->assertSame(1, $result['document']->count());
    }

    // -------------------------------------------------------------------------
    // Restauration de l'ordre de lecture
    // -------------------------------------------------------------------------

    public function test_l_ordre_de_lecture_est_restaure_depuis_les_positions(): void
    {
        // Enjeu : le format historique est catégoriel. Sans restauration,
        // la légende (position 2) se retrouverait après le titre (position 5),
        // ce qui fausserait la renumérotation.
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, index: 5)],
            'legends' => [
                ['raw' => 'Figure 1: A', 'line' => 2, 'type' => 'Figure', 'label' => 'A', 'number' => '1'],
            ],
        ], 'doc_1');

        $texts = array_map(fn ($b) => $b->text, $result['document']->blocks);

        $this->assertSame(['Figure 1: A', 'Titre'], $texts, 'La légende (y=2) doit précéder le titre (y=5)');
    }

    public function test_l_ordre_est_stable_pour_des_positions_egales(): void
    {
        // Plusieurs blocs partagent souvent la même position (un titre et son
        // tableau). Le tri doit rester stable pour ne pas mélanger l'ordre
        // d'origine.
        $result = $this->bridge->toStructural([
            'titres' => [
                $this->legacyHeading('Premier', 1, index: 0),
                $this->legacyHeading('Deuxième', 1, index: 0),
            ],
        ], 'doc_1');

        $texts = array_map(fn ($h) => $h->text, $result['document']->headings());

        $this->assertSame(['Premier', 'Deuxième'], $texts);
    }

    public function test_un_manque_de_positions_de_legendes_est_signale(): void
    {
        // Le format « structure » ne stocke pas la ligne de chaque légende :
        // leur ordre est approximatif. Il faut le DIRE, jamais le cacher.
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, index: 0)],
            'legends' => [
                ['raw' => 'Figure 1: A', 'type' => 'Figure', 'label' => 'A', 'number' => '1'],
                ['raw' => 'Figure 2: B', 'type' => 'Figure', 'label' => 'B', 'number' => '2'],
            ],
        ], 'doc_1');

        $warnings = implode(' ', $result['warnings']);

        $this->assertStringContainsString('positions des légendes sont absentes', $warnings);
    }

    // -------------------------------------------------------------------------
    // Traçabilité et robustesse
    // -------------------------------------------------------------------------

    public function test_les_metadonnees_tracent_la_conversion(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, 0)],
            'legends' => [],
        ], 'doc_42');

        $this->assertSame('doc_42', $result['document']->documentId);
        $this->assertSame('docx', $result['document']->sourceType);
        $this->assertSame('legacy', $result['document']->meta['converted_from']);
        $this->assertSame('structure', $result['document']->meta['legacy_format']);
    }

    public function test_la_fidelite_declaree_est_exacte(): void
    {
        // L'ancien pipeline ne lisait que du `.docx` natif : la fidélité
        // ne peut donc pas être « reconstruite ».
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, 0)],
        ], 'doc_1');

        $this->assertSame(Fidelity::Exact, $result['document']->expectedFidelity());
    }

    public function test_les_blocs_convert_ont_une_confiance_haute(): void
    {
        // Une structure historique a été VALIDÉE par l'utilisateur : lui donner
        // une confiance basse déclencherait des clarifications inutiles.
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('1. Introduction', 1, 0)],
            'legends' => [
                ['raw' => 'Figure 1: A', 'line' => 5, 'type' => 'Figure', 'label' => 'A', 'number' => '1'],
            ],
        ], 'doc_1');

        foreach ($result['document']->blocks as $block) {
            $this->assertTrue(
                $block->isConfident(),
                "Le bloc « {$block->text} » devrait être confiant (structure validée)"
            );
        }
    }

    public function test_une_structure_vide_est_signalee_sans_exception(): void
    {
        // Le format « markdown » peut ne contenir aucune légende : lever une
        // exception rendrait le document inaccessible, ce qui serait pire.
        $result = $this->bridge->toStructural([
            'titles' => '# Titres',
            'legends' => [],
        ], 'doc_1');

        $this->assertTrue($result['document']->isEmpty());
        $this->assertStringContainsString('Aucun élément exploitable', implode(' ', $result['warnings']));
    }

    public function test_les_identifiants_de_blocs_sont_uniques(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('A', 1, 0), $this->legacyHeading('B', 1, 1)],
            'legends' => [
                ['raw' => 'Figure 1: X', 'line' => 2, 'type' => 'Figure', 'label' => 'X', 'number' => '1'],
            ],
        ], 'doc_1');

        $ids = array_map(fn ($b) => $b->blockId, $result['document']->blocks);

        $this->assertCount(count($ids), array_unique($ids));
    }

    public function test_le_document_converti_est_serialisable(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('1. Introduction', 1, 0)],
            'legends' => [
                ['raw' => 'Figure 1: A', 'line' => 2, 'type' => 'Figure', 'label' => 'A', 'number' => '1'],
            ],
        ], 'doc_1');

        $json = $result['document']->toJson();

        $this->assertJson($json);
        $this->assertSame($result['document']->count(), StructuralDocument::fromJson($json)->count());
    }

    // -------------------------------------------------------------------------
    // Conversion inverse (vers le format historique)
    // -------------------------------------------------------------------------

    public function test_la_conversion_inverse_produit_le_format_historique(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('1. Introduction', 1, 0)],
            'legends' => [
                ['raw' => 'Figure 1: A', 'line' => 2, 'type' => 'Figure', 'label' => 'A', 'number' => '1'],
            ],
        ], 'doc_1');

        $legacy = $this->bridge->toLegacy($result['document']);

        $this->assertArrayHasKey('titres', $legacy);
        $this->assertArrayHasKey('sous_titres', $legacy);
        $this->assertArrayHasKey('legends', $legacy);
        $this->assertArrayHasKey('body_complet', $legacy);
        $this->assertSame('1. Introduction', $legacy['titres'][0]['texte']);
    }

    public function test_un_titre_de_niveau_un_va_dans_titres_et_les_autres_dans_sous_titres(): void
    {
        // Convention de l'ancien pipeline, qu'il faut respecter pour que le
        // reconstructeur existant continue de fonctionner.
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Niveau 1', 1, 0)],
            'sous_titres' => [$this->legacyHeading('Niveau 2', 2, 1)],
        ], 'doc_1');

        $legacy = $this->bridge->toLegacy($result['document']);

        $this->assertCount(1, $legacy['titres']);
        $this->assertCount(1, $legacy['sous_titres']);
        $this->assertSame('Niveau 2', $legacy['sous_titres'][0]['texte']);
    }

    public function test_les_legendes_ne_sont_pas_dupliquees_dans_body_complet(): void
    {
        // Les légendes ont leur propre collection : les répéter dans le corps
        // créerait des doublons à la reconstruction.
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('Titre', 1, 0)],
            'legends' => [
                ['raw' => 'Figure 1: A', 'line' => 2, 'type' => 'Figure', 'label' => 'A', 'number' => '1'],
            ],
        ], 'doc_1');

        $legacy = $this->bridge->toLegacy($result['document']);

        $captionTexts = array_map(fn ($c) => $c['texte'], $legacy['body_complet']);
        $this->assertNotContains('Figure 1: A', $captionTexts);
        $this->assertCount(1, $legacy['legends']);
    }

    public function test_un_aller_retour_preserve_le_nombre_de_blocs(): void
    {
        // Critère d'acceptation R1 : l'aller-retour ne doit rien perdre.
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('1. Introduction', 1, 0)],
            'sous_titres' => [$this->legacyHeading('1.1 Contexte', 2, 1)],
            'legends' => [
                ['raw' => 'Figure 1: A', 'line' => 3, 'type' => 'Figure', 'label' => 'A', 'number' => '1'],
            ],
        ], 'doc_1');

        $before = $result['document']->count();
        $legacy = $this->bridge->toLegacy($result['document']);
        $after = $this->bridge->toStructural($legacy, 'doc_1')['document']->count();

        $this->assertSame($before, $after, 'Aucun bloc ne doit être perdu');
    }

    public function test_un_aller_retour_preserve_les_textes_des_titres(): void
    {
        $result = $this->bridge->toStructural([
            'titres' => [$this->legacyHeading('1. INTRODUCTION', 1, 0)],
            'sous_titres' => [$this->legacyHeading('1.1 Contexte du stage', 2, 1)],
        ], 'doc_1');

        $titlesBefore = array_map(fn ($h) => $h->text, $result['document']->headings());
        $legacy = $this->bridge->toLegacy($result['document']);
        $titlesAfter = array_map(
            fn ($h) => $h->text,
            $this->bridge->toStructural($legacy, 'doc_1')['document']->headings()
        );

        $this->assertSame($titlesBefore, $titlesAfter);
    }

    /**
     * Construit un item historique de type titre.
     *
     * Reproduit la structure observée en base : `texte`, `niveau`, `depth`,
     * `styles.font.basic.size`, `styles.font.style.bold`, `position.element_index`.
     *
     * @return array<string, mixed>
     */
    private function legacyHeading(
        string $text,
        int $niveau = 1,
        int $index = 0,
        ?float $size = 16.0,
        bool $bold = true,
    ): array {
        return [
            'type' => 'titres',
            'depth' => $niveau,
            'texte' => $text,
            'niveau' => $niveau,
            'styles' => [
                'font' => [
                    'name' => 'Heading_'.$niveau,
                    'basic' => ['name' => null, 'size' => $size, 'color' => null],
                    'style' => ['bold' => $bold, 'italic' => null, 'underline' => 'none'],
                ],
                'paragraph' => null,
            ],
            'position' => [
                'parent' => 'body',
                'element_index' => $index,
                'section_index' => 0,
            ],
        ];
    }
}
