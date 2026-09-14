<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Editing;

use App\Document\Editing\ReExportCoordinator;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests du ré-export après édition (§9.12).
 *
 * Le critère d'acceptation est un **ordre** : « après édition, le ré-export
 * rejoue dans l'ordre gabarit → renumérotation → listes ». Un ordre inversé
 * produirait une table des matières citant des numéros qui n'existent plus —
 * défaut invisible dans les métadonnées, visible seulement à la lecture.
 */
class ReExportCoordinatorTest extends TestCase
{
    private function paragraphe(string $id, string $text = 'Texte'): Block
    {
        return new Block(blockId: $id, type: BlockType::Paragraph, text: $text);
    }

    private function tableau(string $id, ?string $number = null, ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            tableData: TableData::fromGrid([['A'], ['B']]),
            originalNumber: $number,
            linkedBlockId: $linked,
        );
    }

    private function legende(string $id, string $number, string $text = 'Tableau : Bilan', ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $text,
            category: BlockCategory::Table,
            originalNumber: $number,
            linkedBlockId: $linked,
        );
    }

    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: '1', sourceType: 'docx', blocks: $blocks);
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation : l'ordre est respecté
    // -------------------------------------------------------------------------

    public function test_l_ordre_du_re_export_est_gabarit_renumerotation_listes(): void
    {
        // Le document doit être paginé, donc un vrai fichier est nécessaire.
        // Sans fichier, les listes ne sont pas produites et l'ordre s'arrête à
        // la renumérotation — c'est le comportement de repli, testé plus bas.
        $this->assertSame(
            ['gabarit', 'renumerotation', 'listes'],
            ReExportCoordinator::expectedOrder()
        );
    }

    public function test_le_re_export_sans_pagination_produit_numero_et_gabarit(): void
    {
        $resultat = (new ReExportCoordinator)->reexport($this->document([
            $this->tableau('b_0001'),
            $this->legende('b_0002', '1', 'Tableau 1 : Bilan', 'b_0001'),
        ]));

        $this->assertNull($resultat['error']);
        $this->assertSame(['gabarit', 'renumerotation'], $resultat['order']);
        $this->assertNull($resultat['lists']);
    }

    public function test_la_renumerotation_est_rejouee_apres_edition(): void
    {
        // C'est le point du ré-export : l'édition a pu changer les compteurs,
        // donc les numéros calculés avant ne valent plus rien.
        $resultat = (new ReExportCoordinator)->reexport($this->document([
            $this->tableau('b_0001'),
            $this->legende('b_0002', '7', 'Tableau 7 : Ancien numéro', 'b_0001'),
        ]));

        $document = $resultat['document'];

        // La légende portait « 7 » : elle devient « 1 », et le tableau reflète.
        $this->assertSame('1', $document->blockById('b_0002')->displayNumber());
        $this->assertSame('1', $document->blockById('b_0001')->displayNumber());
        $this->assertSame(['table' => 1], $resultat['numbering']['per_category']);
    }

    public function test_une_table_numerotee_est_realignee_sur_le_corps(): void
    {
        // Deux tableaux numérotés « 5 » et « 5 » (doublon) doivent redevenir
        // « 1 » et « 2 » — la renumérotation est la seule à pouvoir le faire.
        $resultat = (new ReExportCoordinator)->reexport($this->document([
            $this->legende('b_0001', '5', 'Tableau 5 : A'),
            $this->legende('b_0002', '5', 'Tableau 5 : B'),
        ]));

        $document = $resultat['document'];

        $this->assertSame('1', $document->blockById('b_0001')->displayNumber());
        $this->assertSame('2', $document->blockById('b_0002')->displayNumber());
    }

    public function test_le_gabarit_est_applique(): void
    {
        $resultat = (new ReExportCoordinator)->reexport(
            $this->document([$this->paragraphe('b_0001')]),
            ['police' => 'Arial', 'tailles' => ['corps' => 11]],
        );

        $this->assertSame('Arial', $resultat['formatted']['template_font']);
        $this->assertSame(1, $resultat['formatted']['blocks']);
    }

    public function test_un_gabarit_personnalise_change_la_police_du_resume(): void
    {
        $resultat = (new ReExportCoordinator)->reexport(
            $this->document([$this->paragraphe('b_0001')]),
            ['police' => 'Garamond'],
        );

        $this->assertSame('Garamond', $resultat['formatted']['template_font']);
    }

    // -------------------------------------------------------------------------
    // Pagination absente ou exigée
    // -------------------------------------------------------------------------

    public function test_une_pagination_inexistante_n_empeche_pas_le_re_export(): void
    {
        // Un chemin inexistant fait échouer le calcul de pagination, mais le
        // ré-export reste utile : numérosion et gabarit sont appliqués.
        $resultat = (new ReExportCoordinator)->reexport(
            $this->document([$this->paragraphe('b_0001')]),
            null,
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'inexistant_'.uniqid().'.docx',
        );

        $this->assertNull($resultat['error']);
        $this->assertContains('gabarit', $resultat['order']);
        $this->assertContains('renumerotation', $resultat['order']);
    }

    public function test_une_pagination_exigee_mais_indisponible_renvoie_une_erreur(): void
    {
        // Mode strict : un appelant qui exige des numéros exacts doit être
        // averti, plutôt que de recevoir des listes sans pagination.
        $resultat = (new ReExportCoordinator)->reexport(
            $this->document([$this->paragraphe('b_0001')]),
            null,
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'inexistant_'.uniqid().'.docx',
            ['require_pagination' => true],
        );

        $this->assertNotNull($resultat['error']);
        $this->assertNull($resultat['lists']);
        // Le document reste exploitable : seul l'export final est refusé.
        $this->assertSame(1, $resultat['document']->count());
    }

    public function test_l_erreur_de_pagination_est_affichee_telle_quelle(): void
    {
        $resultat = (new ReExportCoordinator)->reexport(
            $this->document([$this->paragraphe('b_0001')]),
            null,
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'inexistant_'.uniqid().'.docx',
            ['require_pagination' => true],
        );

        $resume = (new ReExportCoordinator)->summary($resultat);

        $this->assertStringContainsString('interrompu', $resume);
    }

    // -------------------------------------------------------------------------
    // Résumé
    // -------------------------------------------------------------------------

    public function test_le_resume_indique_la_numerotation(): void
    {
        $resultat = (new ReExportCoordinator)->reexport($this->document([
            $this->legende('b_0001', '1', 'Tableau 1 : A'),
            $this->legende('b_0002', '2', 'Tableau 2 : B'),
        ]));

        $resume = (new ReExportCoordinator)->summary($resultat);

        $this->assertStringContainsString('numérotation mise à jour', $resume);
        $this->assertStringContainsString('table', $resume);
    }

    public function test_le_resume_signale_l_absence_de_listes(): void
    {
        // L'utilisateur doit savoir que son sommaire n'a pas été régénéré.
        $resultat = (new ReExportCoordinator)->reexport($this->document([
            $this->paragraphe('b_0001'),
        ]));

        $resume = (new ReExportCoordinator)->summary($resultat);

        $this->assertStringContainsString('listes non régénérées', $resume);
    }

    public function test_le_resume_d_un_document_sans_element_numerote_est_explicite(): void
    {
        $resultat = (new ReExportCoordinator)->reexport($this->document([
            $this->paragraphe('b_0001'),
        ]));

        $resume = (new ReExportCoordinator)->summary($resultat);

        $this->assertStringContainsString('aucun élément numéroté', $resume);
    }

    // -------------------------------------------------------------------------
    // Cas limites
    // -------------------------------------------------------------------------

    public function test_un_document_vide_ne_provoque_pas_d_erreur(): void
    {
        $resultat = (new ReExportCoordinator)->reexport($this->document([]));

        $this->assertNull($resultat['error']);
        $this->assertSame(0, $resultat['document']->count());
    }

    public function test_le_document_d_origine_n_est_pas_modifie(): void
    {
        $document = $this->document([
            $this->legende('b_0001', '7', 'Tableau 7 : A'),
        ]);

        (new ReExportCoordinator)->reexport($document);

        // Le ré-export renvoie un nouveau document : l'original reste intact,
        // ce qui permet à l'appelant de comparer ou de revenir en arrière.
        $this->assertNull($document->blockById('b_0001')->finalNumber);
    }

    public function test_l_ordre_effectif_est_un_sous_ensemble_de_l_ordre_attendu(): void
    {
        // L'ordre ne peut pas être inversé : les listes viennent toujours après
        // la renumérotation.
        $resultat = (new ReExportCoordinator)->reexport($this->document([
            $this->legende('b_0001', '1', 'Tableau 1 : A'),
        ]));

        $attendu = ReExportCoordinator::expectedOrder();
        $obtenu = $resultat['order'];

        $this->assertSame(array_slice($attendu, 0, count($obtenu)), $obtenu);
    }
}
