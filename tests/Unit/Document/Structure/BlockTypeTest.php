<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Structure;

use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests des décisions portées par BlockType.
 *
 * Vérifie notamment la distinction structurante du schéma : un type
 * numérotable (figure, table, annexe, planche) porte sa propre catégorie,
 * alors qu'une légende ou un renvoi reçoit la sienne explicitement.
 */
class BlockTypeTest extends TestCase
{
    public function test_les_quatre_categories_numerotables_exposent_leur_categorie(): void
    {
        $this->assertSame(BlockCategory::Figure, BlockType::Figure->category());
        $this->assertSame(BlockCategory::Table, BlockType::Table->category());
        $this->assertSame(BlockCategory::Annexe, BlockType::Annexe->category());
        $this->assertSame(BlockCategory::Planche, BlockType::Planche->category());
    }

    public function test_les_types_non_numerotables_n_ont_pas_de_categorie(): void
    {
        $this->assertNull(BlockType::Paragraph->category());
        $this->assertNull(BlockType::Heading->category());
        $this->assertNull(BlockType::Image->category());
        $this->assertNull(BlockType::Caption->category());
        $this->assertNull(BlockType::CrossRef->category());
    }

    public function test_seul_heading_est_reconnu_comme_titre(): void
    {
        $this->assertTrue(BlockType::Heading->isHeading());
        $this->assertFalse(BlockType::Paragraph->isHeading());
    }

    public function test_une_image_de_decorative_n_est_pas_numerotable(): void
    {
        // Distinction clé du schéma : `figure` (numérotée) ≠ `image` (décorative).
        $this->assertTrue(BlockType::Figure->isNumberable());
        $this->assertFalse(BlockType::Image->isNumberable());
    }

    public function test_les_types_a_liste_dediee_sont_identifies(): void
    {
        $this->assertTrue(BlockType::Heading->hasDedicatedList());
        $this->assertTrue(BlockType::Figure->hasDedicatedList());
        $this->assertTrue(BlockType::Table->hasDedicatedList());
        $this->assertTrue(BlockType::Annexe->hasDedicatedList());
        $this->assertTrue(BlockType::Planche->hasDedicatedList());

        $this->assertFalse(BlockType::Paragraph->hasDedicatedList());
        $this->assertFalse(BlockType::Image->hasDedicatedList());
    }

    public function test_les_types_insertables_couvrent_le_contenu_mais_pas_les_renvois(): void
    {
        $insertable = BlockType::insertable();

        $this->assertContains(BlockType::Paragraph, $insertable);
        $this->assertContains(BlockType::Figure, $insertable);
        $this->assertContains(BlockType::Planche, $insertable);

        // Un renvoi croisé est détecté, jamais inséré par un modèle.
        $this->assertNotContains(BlockType::CrossRef, $insertable);
        $this->assertNotContains(BlockType::Header, $insertable);
        $this->assertNotContains(BlockType::Footer, $insertable);
    }

    public function test_from_string_accepte_une_valeur_connue(): void
    {
        $this->assertSame(BlockType::Caption, BlockType::fromString('caption'));
    }

    public function test_from_string_rejette_une_valeur_inconnue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Type de bloc inconnu');

        BlockType::fromString('legende');
    }
}
