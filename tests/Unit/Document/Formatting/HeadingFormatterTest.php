<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Formatting;

use App\Document\Formatting\HeadingFormatter;
use App\Document\Formatting\TemplateEngine;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use Tests\TestCase;

/**
 * Tests de la mise en forme des titres.
 *
 * Le point sensible est le **plafonnement à 3 niveaux** : au-delà, on réutilise
 * le style du niveau 3 plutôt que d'inventer des tailles décroissantes, qui
 * rendraient les titres profonds illisibles.
 */
class HeadingFormatterTest extends TestCase
{
    private function heading(?int $level, string $text = 'Introduction'): Block
    {
        return new Block(
            blockId: 'b_001',
            type: BlockType::Heading,
            text: $text,
            headingLevel: $level,
        );
    }

    public function test_chaque_niveau_tire_sa_taille_du_gabarit(): void
    {
        $formatter = new HeadingFormatter;
        $gabarit = TemplateEngine::defaultTemplate();

        $this->assertSame(16, $formatter->style($this->heading(1), $gabarit)['font_size']);
        $this->assertSame(14, $formatter->style($this->heading(2), $gabarit)['font_size']);
        $this->assertSame(12, $formatter->style($this->heading(3), $gabarit)['font_size']);
    }

    public function test_un_niveau_profond_reutilise_le_style_du_niveau_trois(): void
    {
        $formatter = new HeadingFormatter;
        $gabarit = TemplateEngine::defaultTemplate();

        $this->assertSame(12, $formatter->style($this->heading(7), $gabarit)['font_size']);
        $this->assertSame(
            $formatter->style($this->heading(3), $gabarit)['font_size'],
            $formatter->style($this->heading(7), $gabarit)['font_size']
        );
    }

    public function test_un_titre_sans_niveau_est_traite_comme_niveau_un(): void
    {
        $style = (new HeadingFormatter)->style($this->heading(null), TemplateEngine::defaultTemplate());

        $this->assertSame(16, $style['font_size']);
    }

    public function test_un_titre_n_est_jamais_justifie(): void
    {
        // Un titre justifié est étiré par l'espacement : défaut visuel immédiat.
        $style = (new HeadingFormatter)->style($this->heading(1), TemplateEngine::defaultTemplate());

        $this->assertSame('left', $style['alignment']);
    }

    public function test_un_titre_reste_solidaire_de_son_contenu(): void
    {
        $style = (new HeadingFormatter)->style($this->heading(1), TemplateEngine::defaultTemplate());

        $this->assertTrue($style['keep_with_next']);
        $this->assertTrue($style['bold']);
    }

    public function test_un_gabarit_personnalise_est_respecte(): void
    {
        $gabarit = TemplateEngine::normalizeTemplate([
            'police' => 'Arial',
            'tailles' => ['titre1' => 20],
            'couleurs' => ['titre1' => 'FF0000'],
            'alignement_titres' => 'center',
        ]);

        $style = (new HeadingFormatter)->style($this->heading(1), $gabarit);

        $this->assertSame('Arial', $style['font_name']);
        $this->assertSame(20, $style['font_size']);
        $this->assertSame('FF0000', $style['color']);
        $this->assertSame('center', $style['alignment']);
    }

    public function test_le_niveau_original_est_conserve_dans_le_style(): void
    {
        // Le plafonnement ne doit pas effacer l'information : le rapport de
        // traitement a besoin du niveau réel pour signaler une hiérarchie
        // anormalement profonde.
        $style = (new HeadingFormatter)->style($this->heading(6), TemplateEngine::defaultTemplate());

        $this->assertSame(6, $style['heading_level']);
    }

    public function test_la_cle_de_gabarit_suit_le_niveau(): void
    {
        $formatter = new HeadingFormatter;

        $this->assertSame('titre1', $formatter->key(1));
        $this->assertSame('titre2', $formatter->key(2));
        $this->assertSame('titre3', $formatter->key(3));
        $this->assertSame('titre3', $formatter->key(9));
    }

    public function test_le_format_phpword_est_delegue_au_resolver_du_projet(): void
    {
        // Une seule source de vérité pour la conversion gabarit → PHPWord :
        // dupliquer cette logique ferait diverger les deux chemins de rendu.
        $font = (new HeadingFormatter)->font(TemplateEngine::defaultTemplate(), 1);

        $this->assertSame('Times New Roman', $font['name']);
        $this->assertSame(16, $font['size']);
        $this->assertTrue($font['bold']);
    }

    public function test_le_style_de_paragraphe_phpword_porte_l_alignement_des_titres(): void
    {
        $paragraph = (new HeadingFormatter)->paragraph(TemplateEngine::defaultTemplate());

        $this->assertSame('left', $paragraph['alignment']);
        $this->assertArrayHasKey('spaceBefore', $paragraph);
        $this->assertArrayHasKey('lineHeight', $paragraph);
    }

    public function test_les_niveaux_presents_sont_listes_et_tries(): void
    {
        $levels = (new HeadingFormatter)->levelsIn([
            $this->heading(3),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte'),
            new Block(blockId: 'b_003', type: BlockType::Heading, text: 'Titre', headingLevel: 1),
            new Block(blockId: 'b_004', type: BlockType::Heading, text: 'Titre', headingLevel: 5),
        ]);

        // Le niveau 5 est plafonné à 3 : il ne produit pas un niveau distinct.
        $this->assertSame([1, 3], $levels);
    }

    public function test_un_document_sans_titre_ne_liste_aucun_niveau(): void
    {
        $levels = (new HeadingFormatter)->levelsIn([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte'),
        ]);

        $this->assertSame([], $levels);
    }
}
