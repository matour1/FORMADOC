<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Numbering;

use App\Document\Numbering\CrossRefRewriter;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\CrossRef;
use App\Document\Structure\StructuralDocument;
use Tests\TestCase;

/**
 * Tests de la réécriture des renvois et du rapport discret.
 *
 * Deux enjeux opposés :
 *  - la réécriture doit rendre le document **cohérent** (le texte doit citer le
 *    nouveau numéro), sinon le document se contredit ;
 *  - elle ne doit **jamais** toucher au reste du texte de l'auteur.
 */
class CrossRefRewriterTest extends TestCase
{
    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: 'doc-1', sourceType: 'docx', blocks: $blocks);
    }

    private function caption(string $id, BlockCategory $category, string $number): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $category->keyword().' '.$number,
            category: $category,
            finalNumber: $number,
        );
    }

    // -------------------------------------------------------------------------
    // Réécriture
    // -------------------------------------------------------------------------

    public function test_le_numero_du_renvoi_est_remplace_par_le_numero_calcule(): void
    {
        // Sans cette réécriture, le document se contredirait : le texte dirait
        // « Figure 3 » alors que la figure s'appelle « 1 » — le pire défaut
        // possible pour un mémoire, car il est visible à la lecture.
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'Comme le montre la Figure 3, le processus est linéaire.',
                crossRef: (new CrossRef('Figure 3', BlockCategory::Figure, '3'))->resolveTo('b_002'),
            ),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);

        $this->assertSame(
            'Comme le montre la Figure 1, le processus est linéaire.',
            $result['document']->blockById('b_001')->text
        );
        $this->assertSame(1, $result['rewritten']);
    }

    public function test_le_reste_du_paragraphe_est_preserve_au_caractere_pres(): void
    {
        // Le composant remplace un numéro, il ne réécrit jamais une phrase.
        $texte = 'Conformément à la Figure 2 (détaillée en annexe), le débit augmente — n\'est-ce pas ?';

        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: $texte,
                crossRef: (new CrossRef('Figure 2', BlockCategory::Figure, '2'))->resolveTo('b_002'),
            ),
            $this->caption('b_002', BlockCategory::Figure, '7'),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);
        $nouveau = $result['document']->blockById('b_001')->text;

        $this->assertStringContainsString('Conformément à la Figure 7', $nouveau);
        $this->assertStringContainsString('(détaillée en annexe), le débit augmente — n\'est-ce pas ?', $nouveau);
    }

    public function test_toutes_les_occurrences_du_meme_renvoi_sont_remplacees(): void
    {
        // Un paragraphe qui cite deux fois la même figure doit rester cohérent.
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'La Figure 4 est claire ; la Figure 4 suffit.',
                crossRef: (new CrossRef('Figure 4', BlockCategory::Figure, '4'))->resolveTo('b_002'),
            ),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);

        $this->assertSame(
            'La Figure 1 est claire ; la Figure 1 suffit.',
            $result['document']->blockById('b_001')->text
        );
    }

    public function test_un_renvoi_non_resolu_n_est_pas_reecrit(): void
    {
        // Réécrire avec un numéro inventé serait pire que laisser le texte
        // d'origine : on ne sait pas vers quoi pointe le renvoi.
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'Voir Figure 9.',
                crossRef: new CrossRef('Figure 9', BlockCategory::Figure, '9'),
            ),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);

        $this->assertSame('Voir Figure 9.', $result['document']->blockById('b_001')->text);
        $this->assertSame(0, $result['rewritten']);
    }

    public function test_un_renvoi_deja_correct_n_est_pas_reecrit(): void
    {
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'Voir Figure 1.',
                crossRef: (new CrossRef('Figure 1', BlockCategory::Figure, '1'))->resolveTo('b_002'),
            ),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);

        $this->assertSame('Voir Figure 1.', $result['document']->blockById('b_001')->text);
        $this->assertSame(0, $result['rewritten']);
    }

    public function test_un_renvoi_vers_une_cible_disparue_n_est_pas_reecrit(): void
    {
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'Voir Figure 3.',
                crossRef: (new CrossRef('Figure 3', BlockCategory::Figure, '3'))->resolveTo('b_disparu'),
            ),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);

        $this->assertSame('Voir Figure 3.', $result['document']->blockById('b_001')->text);
    }

    public function test_un_renvoi_de_tableau_est_reecrit_de_la_meme_facon(): void
    {
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'Le Tableau 8 récapitule les charges.',
                crossRef: (new CrossRef('Tableau 8', BlockCategory::Table, '8'))->resolveTo('b_002'),
            ),
            $this->caption('b_002', BlockCategory::Table, '2'),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);

        $this->assertSame(
            'Le Tableau 2 récapitule les charges.',
            $result['document']->blockById('b_001')->text
        );
    }

    public function test_un_numero_voisin_d_une_autre_categorie_n_est_pas_touche(): void
    {
        // « Figure 3 et Tableau 3 » : seul le numéro de figure doit changer.
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'Voir Figure 3 et Tableau 3.',
                crossRef: (new CrossRef('Figure 3', BlockCategory::Figure, '3'))->resolveTo('b_002'),
            ),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);

        $this->assertSame(
            'Voir Figure 1 et Tableau 3.',
            $result['document']->blockById('b_001')->text
        );
    }

    public function test_un_mot_commencant_par_le_numero_n_est_pas_touche(): void
    {
        // « Figure 1er trimestre » : le « 1 » fait partie d'un mot composé.
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'Voir Figure 1 et Figure 10.',
                crossRef: (new CrossRef('Figure 1', BlockCategory::Figure, '1'))->resolveTo('b_002'),
            ),
            $this->caption('b_002', BlockCategory::Figure, '2'),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);
        $texte = $result['document']->blockById('b_001')->text;

        // le « 1 » de « 10 » ne doit pas devenir « 2 », ce qui donnerait « 20 ».
        $this->assertSame('Voir Figure 2 et Figure 10.', $texte);
    }

    public function test_les_details_de_reecriture_sont_traces(): void
    {
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'Voir Figure 3.',
                crossRef: (new CrossRef('Figure 3', BlockCategory::Figure, '3'))->resolveTo('b_002'),
            ),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);

        $this->assertCount(1, $result['details']);
        $this->assertSame('Voir Figure 3.', $result['details'][0]['before']);
        $this->assertSame('Voir Figure 1.', $result['details'][0]['after']);
    }

    public function test_un_document_sans_renvoi_n_est_pas_modifie(): void
    {
        $document = $this->document([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte ordinaire.'),
        ]);

        $result = (new CrossRefRewriter)->rewrite($document);

        $this->assertSame(0, $result['rewritten']);
        $this->assertSame('Texte ordinaire.', $result['document']->blockById('b_001')->text);
    }

    public function test_le_document_d_origine_n_est_pas_modifie(): void
    {
        $document = $this->document([
            new Block(
                blockId: 'b_001',
                type: BlockType::Paragraph,
                text: 'Voir Figure 3.',
                crossRef: (new CrossRef('Figure 3', BlockCategory::Figure, '3'))->resolveTo('b_002'),
            ),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]);

        (new CrossRefRewriter)->rewrite($document);

        $this->assertSame('Voir Figure 3.', $document->blockById('b_001')->text);
    }
}
