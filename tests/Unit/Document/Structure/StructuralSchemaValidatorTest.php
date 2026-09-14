<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Structure;

use App\Document\Exceptions\InvalidStructuralDocument;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\StructuralSchemaValidator;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests des décisions portées par StructuralSchemaValidator.
 *
 * Ce validateur porte le garde-fou §9.3 : toute sortie de tool d'édition est
 * validée AVANT d'être appliquée. Une sortie invalide est rejetée, jamais
 * appliquée au document.
 */
class StructuralSchemaValidatorTest extends TestCase
{
    private StructuralSchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new StructuralSchemaValidator;
    }

    /**
     * Structure minimale valide.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function valid(array $blocks = []): array
    {
        return [
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'document_id' => 'doc_123',
            'source_type' => 'docx',
            'blocks' => $blocks,
        ];
    }

    private function paragraphBlock(string $id = 'b_001'): array
    {
        return ['block_id' => $id, 'type' => 'paragraph', 'text' => 'Texte'];
    }

    // -------------------------------------------------------------------------
    // Structure valide
    // -------------------------------------------------------------------------

    public function test_une_structure_minimale_valide_ne_produit_aucune_erreur(): void
    {
        $this->assertSame([], $this->validator->errors($this->valid()));
        $this->assertTrue($this->validator->isValid($this->valid()));
    }

    public function test_validate_ne_leve_rien_pour_une_structure_valide(): void
    {
        $this->validator->validate($this->valid([$this->paragraphBlock()]));

        $this->expectNotToPerformAssertions();
    }

    public function test_un_document_complet_avec_blocs_specialises_est_valide(): void
    {
        $data = $this->valid([
            ['block_id' => 'b_001', 'type' => 'heading', 'text' => 'Intro', 'heading_level' => 1],
            [
                'block_id' => 'b_002',
                'type' => 'figure',
                'text' => '[image:a.png]',
                'image_ref' => 'a.png',
                'original_number' => '3',
            ],
            [
                'block_id' => 'b_003',
                'type' => 'caption',
                'text' => 'Figure 3 : Schéma',
                'category' => 'figure',
                'linked_block_id' => 'b_002',
            ],
            [
                'block_id' => 'b_004',
                'type' => 'table',
                'table_data' => ['rows' => 1, 'cols' => 2, 'cells' => ['A', 'B']],
            ],
            [
                'block_id' => 'b_005',
                'type' => 'cross_ref',
                'text' => 'voir Figure 3',
                'category' => 'figure',
                'cross_ref' => [
                    'matched_text' => 'voir Figure 3',
                    'target_category' => 'figure',
                    'target_original_number' => '3',
                ],
            ],
        ]);

        $this->assertTrue($this->validator->isValid($data), implode(' ', $this->validator->errors($data)));
    }

    // -------------------------------------------------------------------------
    // Champs du document
    // -------------------------------------------------------------------------

    public function test_un_document_sans_identifiant_est_rejete(): void
    {
        $data = $this->valid();
        $data['document_id'] = '';

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('document_id', $errors[0]);
    }

    public function test_une_source_inconnue_est_rejetee(): void
    {
        $data = $this->valid();
        $data['source_type'] = 'pdf_texte';

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('source_type', $errors[0]);
    }

    public function test_l_absence_de_blocs_est_rejetee(): void
    {
        $errors = $this->validator->errors([
            'document_id' => 'doc_1',
            'source_type' => 'docx',
        ]);

        $this->assertContains('blocks manquant ou invalide.', $errors);
    }

    public function test_une_version_de_schema_future_est_rejetee(): void
    {
        $data = $this->valid();
        $data['schema_version'] = StructuralDocument::SCHEMA_VERSION + 1;

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('schema_version', $errors[0]);
    }

    public function test_validate_leve_une_exception_avec_le_detail_des_erreurs(): void
    {
        $this->expectException(InvalidStructuralDocument::class);
        $this->expectExceptionMessage('Structure de document invalide');

        $this->validator->validate([
            'document_id' => '',
            'source_type' => 'inconnu',
            'blocks' => [],
        ]);
    }

    // -------------------------------------------------------------------------
    // Blocs — identifiants et types
    // -------------------------------------------------------------------------

    public function test_un_bloc_sans_identifiant_est_rejete(): void
    {
        $data = $this->valid([['type' => 'paragraph', 'text' => 'sans id']]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('block_id', $errors[0]);
    }

    public function test_un_identifiant_de_bloc_duplique_est_rejete(): void
    {
        // Un doublon casserait la résolution des renvois et les snapshots.
        $data = $this->valid([
            $this->paragraphBlock('b_001'),
            $this->paragraphBlock('b_001'),
        ]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('dupliqué', $errors[0]);
    }

    public function test_un_type_de_bloc_inconnu_est_rejete(): void
    {
        $data = $this->valid([['block_id' => 'b_001', 'type' => 'legende', 'text' => 'x']]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('type invalide', $errors[0]);
    }

    public function test_un_bloc_qui_n_est_pas_un_tableau_est_rejete(): void
    {
        $data = $this->valid(['pas un bloc']);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('n\'est pas un tableau', $errors[0]);
    }

    // -------------------------------------------------------------------------
    // Blocs — règles conditionnelles par type
    // -------------------------------------------------------------------------

    public function test_un_tableau_sans_donnees_est_rejete(): void
    {
        // Le contenu des cellules ne doit jamais pouvoir disparaître.
        $data = $this->valid([['block_id' => 'b_001', 'type' => 'table']]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('table_data', $errors[0]);
    }

    public function test_un_titre_sans_niveau_est_rejete(): void
    {
        $data = $this->valid([['block_id' => 'b_001', 'type' => 'heading', 'text' => 'Titre']]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('heading_level', $errors[0]);
    }

    public function test_un_titre_avec_un_niveau_valide_est_accepte(): void
    {
        $data = $this->valid([
            ['block_id' => 'b_001', 'type' => 'heading', 'text' => 'Titre', 'heading_level' => 2],
        ]);

        $this->assertTrue($this->validator->isValid($data));
    }

    public function test_une_legende_sans_categorie_est_rejetee(): void
    {
        // Sans catégorie, la légende serait exclue de sa liste (figures/tableaux…).
        $data = $this->valid([['block_id' => 'b_001', 'type' => 'caption', 'text' => 'Figure 1']]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('category', $errors[0]);
    }

    public function test_un_renvoi_sans_categorie_est_rejete(): void
    {
        $data = $this->valid([
            ['block_id' => 'b_001', 'type' => 'cross_ref', 'text' => 'voir Figure 1'],
        ]);

        $errors = $this->validator->errors($data);

        $this->assertStringContainsString('category', $errors[0]);
    }

    public function test_un_renvoi_sans_detail_cross_ref_est_rejete(): void
    {
        $data = $this->valid([
            [
                'block_id' => 'b_001',
                'type' => 'cross_ref',
                'text' => 'voir Figure 1',
                'category' => 'figure',
            ],
        ]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('cross_ref', $errors[0]);
    }

    public function test_une_figure_sans_image_est_rejetee(): void
    {
        $data = $this->valid([['block_id' => 'b_001', 'type' => 'figure', 'text' => '[image:]']]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('image_ref', $errors[0]);
    }

    public function test_une_annexe_sans_categorie_est_acceptee_car_son_type_la_porte(): void
    {
        // `figure`, `table`, `annexe` et `planche` sont eux-mêmes des catégories :
        // seule la légende et le renvoi ont besoin d'une catégorie explicite.
        $data = $this->valid([['block_id' => 'b_001', 'type' => 'annexe', 'text' => 'Annexe A']]);

        $this->assertTrue($this->validator->isValid($data));
    }

    // -------------------------------------------------------------------------
    // Blocs — valeurs numériques et énumérations
    // -------------------------------------------------------------------------

    public function test_une_confiance_hors_bornes_est_rejetee(): void
    {
        $data = $this->valid([
            ['block_id' => 'b_001', 'type' => 'paragraph', 'confidence' => 1.5],
        ]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('confidence', $errors[0]);
    }

    public function test_une_confiance_non_numerique_est_rejetee(): void
    {
        $data = $this->valid([
            ['block_id' => 'b_001', 'type' => 'paragraph', 'confidence' => 'haute'],
        ]);

        $errors = $this->validator->errors($data);

        $this->assertStringContainsString('confidence', $errors[0]);
    }

    public function test_une_fidelite_inconnue_est_rejetee(): void
    {
        $data = $this->valid([
            ['block_id' => 'b_001', 'type' => 'paragraph', 'fidelity' => 'parfaite'],
        ]);

        $errors = $this->validator->errors($data);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('fidelity', $errors[0]);
    }

    public function test_les_erreurs_sont_cumulees_pour_donner_un_retour_complet(): void
    {
        // Un retour unique et complet évite de faire boucler le modèle.
        $data = [
            'document_id' => '',
            'source_type' => 'inconnu',
            'blocks' => [
                ['type' => 'paragraph'],
                ['block_id' => 'b_002', 'type' => 'table'],
            ],
        ];

        $errors = $this->validator->errors($data);

        $this->assertGreaterThanOrEqual(4, count($errors));
    }

    // -------------------------------------------------------------------------
    // Validation d'un document existant
    // -------------------------------------------------------------------------

    public function test_validate_document_accepte_un_document_construit_par_les_objets(): void
    {
        $document = new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [
                new Block(
                    blockId: 'b_001',
                    type: BlockType::Table,
                    tableData: TableData::fromGrid([['A', 'B']]),
                ),
            ],
        );

        $this->validator->validateDocument($document);

        $this->expectNotToPerformAssertions();
    }

    public function test_toute_structure_serialisee_par_les_objets_est_valide(): void
    {
        // Garantie forte : les objets ne peuvent pas produire un schéma invalide.
        $document = new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [
                new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Intro', headingLevel: 1),
                new Block(
                    blockId: 'b_002',
                    type: BlockType::Figure,
                    text: '[image:a.png]',
                    imageRef: 'a.png',
                ),
                new Block(
                    blockId: 'b_003',
                    type: BlockType::Table,
                    tableData: TableData::fromGrid([['A']]),
                ),
            ],
        );

        $this->assertSame([], $this->validator->errors($document->toArray()));
    }
}
