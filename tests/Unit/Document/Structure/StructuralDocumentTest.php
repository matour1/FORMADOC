<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Structure;

use App\Document\Exceptions\InvalidStructuralDocument;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\CrossRef;
use App\Document\Structure\Fidelity;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests des décisions portées par StructuralDocument.
 *
 * C'est l'agrégat racine : on vérifie ici les requêtes dérivées qui pilotent
 * les phases suivantes (pré-filtrage IA, renumérotation, signalement discret)
 * ainsi que les mutations utilisées par les tools d'édition du chat.
 */
class StructuralDocumentTest extends TestCase
{
    private function document(array $blocks = [], string $sourceType = 'docx'): StructuralDocument
    {
        return new StructuralDocument(
            documentId: 'doc_123',
            sourceType: $sourceType,
            blocks: $blocks,
        );
    }

    private function heading(string $id, int $level = 1): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Heading,
            text: "Titre {$id}",
            headingLevel: $level,
        );
    }

    private function figure(string $id, ?string $originalNumber = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Figure,
            text: "[image:{$id}.png]",
            imageRef: "{$id}.png",
            originalNumber: $originalNumber,
        );
    }

    // -------------------------------------------------------------------------
    // Source et fidélité
    // -------------------------------------------------------------------------

    public function test_une_source_docx_garantit_une_fidelite_exacte(): void
    {
        $document = $this->document();

        $this->assertSame(Fidelity::Exact, $document->expectedFidelity());
        $this->assertFalse($document->isReconstructed());
    }

    public function test_une_source_gdocs_est_considerée_comme_exacte(): void
    {
        $document = $this->document(sourceType: 'gdocs');

        $this->assertSame(Fidelity::Exact, $document->expectedFidelity());
        $this->assertFalse($document->isReconstructed());
    }

    public function test_une_source_ocr_est_considerée_comme_reconstruite(): void
    {
        $document = $this->document(sourceType: 'ocr');

        $this->assertSame(Fidelity::Reconstructed, $document->expectedFidelity());
        $this->assertTrue($document->isReconstructed());
    }

    public function test_une_source_inconnue_est_rejetee(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source inconnue');

        $this->document(sourceType: 'pdf_texte');
    }

    // -------------------------------------------------------------------------
    // Requêtes de blocs
    // -------------------------------------------------------------------------

    public function test_blocks_of_type_filtre_par_type_de_bloc(): void
    {
        $document = $this->document([
            $this->heading('b_001'),
            $this->figure('b_002'),
            $this->heading('b_003', 2),
        ]);

        $this->assertCount(2, $document->blocksOfType(BlockType::Heading));
        $this->assertCount(1, $document->blocksOfType(BlockType::Figure));
        $this->assertCount(0, $document->blocksOfType(BlockType::Planche));
    }

    public function test_headings_retourne_les_titres_dans_l_ordre_du_document(): void
    {
        $document = $this->document([
            $this->heading('b_001'),
            $this->figure('b_002'),
            $this->heading('b_003', 2),
        ]);

        $ids = array_map(fn (Block $b): string => $b->blockId, $document->headings());

        $this->assertSame(['b_001', 'b_003'], $ids);
    }

    public function test_numberable_blocks_filtre_la_categorie_sur_le_type(): void
    {
        $document = $this->document([
            $this->figure('b_001'),
            new Block(
                blockId: 'b_002',
                type: BlockType::Caption,
                category: BlockCategory::Figure,
                linkedBlockId: 'b_001',
            ),
            $this->heading('b_003'),
        ]);

        // Seuls les blocs numérotables de la catégorie sont renumérotés :
        // la légende suit sa figure mais ne consomme pas de numéro.
        $numberable = $document->numberableBlocks(BlockCategory::Figure);

        $this->assertCount(1, $numberable);
        $this->assertSame('b_001', $numberable[0]->blockId);
    }

    public function test_blocks_of_category_inclut_legendes_et_blocs_numerotables(): void
    {
        $document = $this->document([
            $this->figure('b_001'),
            new Block(
                blockId: 'b_002',
                type: BlockType::Caption,
                category: BlockCategory::Figure,
                linkedBlockId: 'b_001',
            ),
        ]);

        $this->assertCount(2, $document->blocksOfCategory(BlockCategory::Figure));
    }

    // -------------------------------------------------------------------------
    // Pré-filtrage IA (enjeu budgétaire)
    // -------------------------------------------------------------------------

    public function test_ambiguous_ne_retourne_que_les_blocs_sous_le_seuil(): void
    {
        $document = $this->document([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'sûr', confidence: 0.95),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'ambigu', confidence: 0.5),
            new Block(blockId: 'b_003', type: BlockType::Paragraph, text: 'ambigu', confidence: 0.84),
        ]);

        // C'est CE pré-filtrage qui garantit l'économie de tokens : seuls les
        // blocs réellement ambigus partent vers le modèle.
        $ambiguous = $document->ambiguous();

        $this->assertCount(2, $ambiguous);
        $this->assertSame(['b_002', 'b_003'], array_map(
            fn (Block $b): string => $b->blockId,
            $ambiguous
        ));
    }

    public function test_ambiguous_accepte_un_seuil_personnalise(): void
    {
        $document = $this->document([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, confidence: 0.9),
        ]);

        $this->assertCount(0, $document->ambiguous());
        $this->assertCount(1, $document->ambiguous(0.95));
    }

    public function test_requiring_visual_review_retourne_les_blocs_d_une_source_reconstruite(): void
    {
        $document = $this->document([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, fidelity: Fidelity::Reconstructed),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, fidelity: Fidelity::Exact),
        ]);

        $review = $document->requiringVisualReview();

        $this->assertCount(1, $review);
        $this->assertSame('b_001', $review[0]->blockId);
    }

    // -------------------------------------------------------------------------
    // Renvois croisés
    // -------------------------------------------------------------------------

    public function test_unresolved_cross_refs_retourne_les_renvois_sans_cible(): void
    {
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::CrossRef,
                text: 'voir Figure 3',
                category: BlockCategory::Figure,
                crossRef: new CrossRef('voir Figure 3', BlockCategory::Figure, '3'),
            ),
            new Block(
                blockId: 'b_002',
                type: BlockType::CrossRef,
                text: 'voir Figure 4',
                category: BlockCategory::Figure,
                crossRef: (new CrossRef('voir Figure 4', BlockCategory::Figure, '4'))->resolveTo('b_010'),
            ),
        ]);

        $unresolved = $document->unresolvedCrossRefs();

        $this->assertCount(1, $unresolved);
        $this->assertSame('b_001', $unresolved[0]->blockId);
    }

    public function test_low_confidence_cross_refs_retourne_les_renvois_a_verifier(): void
    {
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::CrossRef,
                category: BlockCategory::Figure,
                crossRef: (new CrossRef('voir Figure 3', BlockCategory::Figure, '3'))
                    ->resolveWithLowConfidence('b_010', 0.55),
            ),
            new Block(
                blockId: 'b_002',
                type: BlockType::CrossRef,
                category: BlockCategory::Figure,
                crossRef: (new CrossRef('voir Figure 4', BlockCategory::Figure, '4'))->resolveTo('b_011'),
            ),
        ]);

        // Signalement discret : ces renvois alimentent le rapport de fin de
        // traitement, sans jamais bloquer l'utilisateur.
        $low = $document->lowConfidenceCrossRefs();

        $this->assertCount(1, $low);
        $this->assertSame('b_001', $low[0]->blockId);
    }

    public function test_un_renvoi_non_resolu_n_est_pas_signale_comme_confiance_faible(): void
    {
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::CrossRef,
                category: BlockCategory::Figure,
                crossRef: new CrossRef('voir Figure 3', BlockCategory::Figure, '3'),
            ),
        ]);

        $this->assertSame([], $document->lowConfidenceCrossRefs());
    }

    // -------------------------------------------------------------------------
    // Accès par identifiant et position
    // -------------------------------------------------------------------------

    public function test_block_by_id_retourne_le_bloc_ou_null(): void
    {
        $document = $this->document([$this->heading('b_001')]);

        $this->assertNotNull($document->blockById('b_001'));
        $this->assertNull($document->blockById('b_999'));
    }

    public function test_index_of_retourne_la_position_dans_l_ordre_du_document(): void
    {
        $document = $this->document([
            $this->heading('b_001'),
            $this->figure('b_002'),
            $this->heading('b_003', 2),
        ]);

        // Sert à la résolution par proximité (jamais par distance en caractères).
        $this->assertSame(0, $document->indexOf('b_001'));
        $this->assertSame(2, $document->indexOf('b_003'));
        $this->assertNull($document->indexOf('b_999'));
    }

    public function test_un_document_sans_bloc_est_vide(): void
    {
        $document = $this->document();

        $this->assertTrue($document->isEmpty());
        $this->assertSame(0, $document->count());
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    public function test_replace_block_conserve_l_ordre_et_les_autres_blocs(): void
    {
        $document = $this->document([
            $this->heading('b_001'),
            $this->heading('b_002', 2),
        ]);

        $updated = $document->replaceBlock($this->heading('b_001'));

        $this->assertSame(['b_001', 'b_002'], array_map(
            fn (Block $b): string => $b->blockId,
            $updated->blocks
        ));
        $this->assertSame(2, $updated->count());
    }

    public function test_replace_blocks_remplace_un_lot_en_une_passe(): void
    {
        $document = $this->document([
            $this->figure('b_001', '5'),
            $this->figure('b_002', '9'),
        ]);

        $updated = $document->replaceBlocks([
            $this->figure('b_001', '5')->withFinalNumber('1'),
            $this->figure('b_002', '9')->withFinalNumber('2'),
        ]);

        $this->assertSame('1', $updated->blockById('b_001')?->finalNumber);
        $this->assertSame('2', $updated->blockById('b_002')?->finalNumber);
    }

    public function test_insert_after_place_le_nouveau_bloc_juste_apres_la_reference(): void
    {
        $document = $this->document([
            $this->heading('b_001'),
            $this->heading('b_003', 2),
        ]);

        $updated = $document->insertAfter('b_001', $this->heading('b_002', 2));

        $this->assertSame(['b_001', 'b_002', 'b_003'], array_map(
            fn (Block $b): string => $b->blockId,
            $updated->blocks
        ));
    }

    public function test_insert_after_une_reference_introuvable_ajoute_en_fin(): void
    {
        // Comportement prévisible : l'instruction vient d'un LLM, mieux vaut
        // insérer que lever une exception et perdre l'action.
        $document = $this->document([$this->heading('b_001')]);

        $updated = $document->insertAfter('b_999', $this->heading('b_002', 2));

        $this->assertSame(['b_001', 'b_002'], array_map(
            fn (Block $b): string => $b->blockId,
            $updated->blocks
        ));
    }

    public function test_remove_block_supprime_le_bloc_vise(): void
    {
        $document = $this->document([
            $this->heading('b_001'),
            $this->heading('b_002', 2),
        ]);

        $updated = $document->removeBlock('b_001');

        $this->assertSame(1, $updated->count());
        $this->assertNull($updated->blockById('b_001'));
    }

    public function test_replace_range_remplace_les_blocs_de_la_plage(): void
    {
        $document = $this->document([
            $this->heading('b_001'),
            $this->heading('b_002', 2),
            $this->heading('b_003', 2),
            $this->heading('b_004', 1),
        ]);

        $updated = $document->replaceRange('b_002', 'b_003', [$this->heading('b_new', 2)]);

        $this->assertSame(['b_001', 'b_new', 'b_004'], array_map(
            fn (Block $b): string => $b->blockId,
            $updated->blocks
        ));
    }

    public function test_replace_range_rejette_une_plage_inversee(): void
    {
        $document = $this->document([
            $this->heading('b_001'),
            $this->heading('b_002', 2),
        ]);

        $this->expectException(InvalidStructuralDocument::class);
        $this->expectExceptionMessage('Plage de blocs invalide');

        $document->replaceRange('b_002', 'b_001', []);
    }

    public function test_replace_range_rejette_un_identifiant_introuvable(): void
    {
        $document = $this->document([$this->heading('b_001')]);

        $this->expectException(InvalidStructuralDocument::class);

        $document->replaceRange('b_001', 'b_999', []);
    }

    public function test_range_size_compte_les_blocs_de_la_plage(): void
    {
        // Alimente le garde-fou §9 : régénérer plus de 5 blocs exige une
        // confirmation explicite de l'utilisateur.
        $document = $this->document([
            $this->heading('b_001'),
            $this->heading('b_002', 2),
            $this->heading('b_003', 2),
            $this->heading('b_004', 1),
        ]);

        $this->assertSame(3, $document->rangeSize('b_002', 'b_004'));
        $this->assertSame(1, $document->rangeSize('b_001', 'b_001'));
        $this->assertSame(0, $document->rangeSize('b_004', 'b_001'));
        $this->assertSame(0, $document->rangeSize('b_001', 'b_999'));
    }

    public function test_with_meta_ajoute_une_metadonnee_sans_toucher_aux_blocs(): void
    {
        $document = $this->document([$this->heading('b_001')]);

        $updated = $document->withMeta('gabarit', 'memoire');

        $this->assertSame('memoire', $updated->meta['gabarit']);
        $this->assertSame(1, $updated->count());
        $this->assertSame([], $document->meta);
    }

    public function test_les_mutations_ne_modifient_pas_le_document_d_origine(): void
    {
        $original = $this->document([$this->heading('b_001')]);

        $original->removeBlock('b_001');
        $original->insertAfter('b_001', $this->heading('b_002', 2));

        $this->assertSame(1, $original->count());
        $this->assertNotNull($original->blockById('b_001'));
    }

    // -------------------------------------------------------------------------
    // Sérialisation
    // -------------------------------------------------------------------------

    public function test_le_schema_json_contient_les_cles_du_format_commun(): void
    {
        $array = $this->document([$this->heading('b_001')])->toArray();

        $this->assertSame(StructuralDocument::SCHEMA_VERSION, $array['schema_version']);
        $this->assertSame('doc_123', $array['document_id']);
        $this->assertSame('docx', $array['source_type']);
        $this->assertSame('exact', $array['fidelity']);
        $this->assertCount(1, $array['blocks']);
    }

    public function test_un_document_complet_survit_a_un_aller_retour_json(): void
    {
        $original = $this->document([
            $this->heading('b_001'),
            $this->figure('b_002', '3'),
            new Block(
                blockId: 'b_003',
                type: BlockType::Table,
                tableData: TableData::fromGrid([['A', 'B'], ['C', 'D']]),
                originalNumber: '1',
                finalNumber: '1',
            ),
        ])->withMeta('gabarit', 'rapport');

        $restored = StructuralDocument::fromJson($original->toJson());

        $this->assertSame(3, $restored->count());
        $this->assertSame('rapport', $restored->meta['gabarit']);
        $this->assertSame('3', $restored->blockById('b_002')?->originalNumber);
        $this->assertSame('C', $restored->blockById('b_003')?->tableData?->cell(1, 0));
    }

    public function test_to_json_preserve_les_accents(): void
    {
        $document = new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Résumé — étude')],
        );

        $this->assertStringContainsString('Résumé — étude', $document->toJson());
    }

    public function test_from_json_rejette_un_json_invalide(): void
    {
        $this->expectException(InvalidStructuralDocument::class);
        $this->expectExceptionMessage('illisible');

        StructuralDocument::fromJson('{invalide');
    }

    public function test_from_array_ignore_les_blocs_malformes(): void
    {
        $document = StructuralDocument::fromArray([
            'document_id' => 'doc_1',
            'source_type' => 'docx',
            'blocks' => [
                ['block_id' => 'b_001', 'type' => 'paragraph', 'text' => 'ok'],
                'pas un tableau',
            ],
        ]);

        $this->assertSame(1, $document->count());
    }

    public function test_is_compatible_accepte_les_versions_connues(): void
    {
        $this->assertTrue(StructuralDocument::isCompatible(['schema_version' => 1]));
    }

    public function test_is_compatible_rejette_une_version_future(): void
    {
        $this->assertFalse(StructuralDocument::isCompatible(['schema_version' => 99]));
    }

    public function test_is_compatible_rejette_une_version_absente(): void
    {
        $this->assertFalse(StructuralDocument::isCompatible([]));
    }

    public function test_un_document_sans_identifiant_est_rejete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doit porter un identifiant');

        new StructuralDocument(documentId: '', sourceType: 'docx');
    }

    public function test_un_document_refuse_un_bloc_non_conforme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Block');

        new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [['block_id' => 'b_001']],
        );
    }
}
