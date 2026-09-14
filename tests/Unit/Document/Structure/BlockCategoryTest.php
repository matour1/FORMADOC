<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Structure;

use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\Fidelity;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests des décisions portées par BlockCategory et Fidelity.
 *
 * Deux points de conformité produit sont verrouillés ici :
 *  - les 4 catégories partagent le même style de numérotation (pas de
 *    convention différenciée), chacune avec son compteur propre ;
 *  - une source reconstruite (PDF scanné) interdit toute promesse d'identité.
 */
class BlockCategoryTest extends TestCase
{
    public function test_les_quatre_categories_sont_enumerees_dans_l_ordre_canonique(): void
    {
        $this->assertSame([
            BlockCategory::Figure,
            BlockCategory::Table,
            BlockCategory::Annexe,
            BlockCategory::Planche,
        ], BlockCategory::all());
    }

    public function test_chaque_categorie_expose_le_mot_cle_utilise_dans_les_renvois(): void
    {
        $this->assertSame('Figure', BlockCategory::Figure->keyword());
        $this->assertSame('Tableau', BlockCategory::Table->keyword());
        $this->assertSame('Annexe', BlockCategory::Annexe->keyword());
        $this->assertSame('Planche', BlockCategory::Planche->keyword());
    }

    public function test_chaque_categorie_expose_le_titre_de_sa_liste_dediee(): void
    {
        $this->assertSame('LISTE DES FIGURES', BlockCategory::Figure->listTitle());
        $this->assertSame('LISTE DES TABLEAUX', BlockCategory::Table->listTitle());
        $this->assertSame('LISTE DES ANNEXES', BlockCategory::Annexe->listTitle());
        $this->assertSame('LISTE DES PLANCHES', BlockCategory::Planche->listTitle());
    }

    public function test_chaque_categorie_pointe_vers_son_type_de_bloc_numerotable(): void
    {
        $this->assertSame(BlockType::Figure, BlockCategory::Figure->blockType());
        $this->assertSame(BlockType::Table, BlockCategory::Table->blockType());
        $this->assertSame(BlockType::Annexe, BlockCategory::Annexe->blockType());
        $this->assertSame(BlockType::Planche, BlockCategory::Planche->blockType());
    }

    public function test_from_keyword_reconnait_les_mots_cles_sans_casse_ni_espaces(): void
    {
        $this->assertSame(BlockCategory::Figure, BlockCategory::fromKeyword('Figure'));
        $this->assertSame(BlockCategory::Table, BlockCategory::fromKeyword('  TABLEAU  '));
        $this->assertSame(BlockCategory::Annexe, BlockCategory::fromKeyword('annexe'));
        $this->assertSame(BlockCategory::Planche, BlockCategory::fromKeyword('PLANCHE'));
    }

    public function test_from_keyword_retourne_null_pour_un_mot_inconnu(): void
    {
        // Un mot inconnu ne doit pas lever d'exception : la détection des renvois
        // parcourt du texte libre et doit pouvoir ignorer ce qu'elle ne comprend pas.
        $this->assertNull(BlockCategory::fromKeyword('tableau_annexe'));
        $this->assertNull(BlockCategory::fromKeyword(''));
    }

    public function test_from_string_rejette_une_categorie_inconnue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Catégorie de bloc inconnue');

        BlockCategory::fromString('tableau');
    }

    public function test_une_source_docx_garantit_une_fidelite_exacte(): void
    {
        $fidelity = Fidelity::Exact;

        $this->assertTrue($fidelity->isExact());
        $this->assertFalse($fidelity->requiresUserValidation());
    }

    public function test_une_source_reconstruite_exige_une_validation_utilisateur(): void
    {
        $fidelity = Fidelity::Reconstructed;

        $this->assertFalse($fidelity->isExact());
        $this->assertTrue($fidelity->requiresUserValidation());
    }

    public function test_le_libelle_d_une_reconstruction_ne_promet_jamais_l_identite(): void
    {
        // Règle produit absolue : jamais « identique à 100 % » pour un PDF scanné.
        $label = Fidelity::Reconstructed->label();

        $this->assertStringContainsString('validée par vous', $label);
        $this->assertStringNotContainsString('identique', mb_strtolower($label));
        $this->assertStringNotContainsString('100', $label);
    }
}
