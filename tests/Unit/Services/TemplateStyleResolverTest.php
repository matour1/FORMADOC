<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DocumentGeneration\TemplateStyleResolver;
use Tests\TestCase;

/**
 * Tests du TemplateStyleResolver : résolution des paramètres d'un gabarit
 * (table `templates`) vers les styles PhpWord (font, paragraph, section,
 * table).
 */
class TemplateStyleResolverTest extends TestCase
{
    public function test_normalize_avec_params_nuls_retourne_les_defauts(): void
    {
        $defaults = TemplateStyleResolver::normalize(null);

        $this->assertSame('Times New Roman', $defaults['police']);
        $this->assertSame(16, $defaults['tailles']['titre1']);
        $this->assertSame(12, $defaults['tailles']['corps']);
        $this->assertSame('1F3864', $defaults['couleurs']['titre1']);
        $this->assertSame(1.5, $defaults['interligne']);
        $this->assertSame(1440, $defaults['marges']['top']);
    }

    public function test_normalize_fusionne_avec_les_defauts(): void
    {
        $params = [
            'police' => 'Arial',
            'tailles' => ['corps' => 11],
            'interligne' => 2.0,
        ];

        $normalized = TemplateStyleResolver::normalize($params);

        // Fusion : valeurs fournies + défauts pour le reste
        $this->assertSame('Arial', $normalized['police']);
        $this->assertSame(11, $normalized['tailles']['corps']);
        $this->assertSame(16, $normalized['tailles']['titre1']);
        $this->assertSame(2.0, $normalized['interligne']);
        $this->assertSame('1F3864', $normalized['couleurs']['titre1']);
    }

    public function test_font_style_titres_est_gras_avec_taille_du_gabarit(): void
    {
        $gabarit = TemplateStyleResolver::normalize([
            'police' => 'Calibri',
            'tailles' => ['titre1' => 18],
            'couleurs' => ['titre1' => 'AA0000'],
        ]);

        $style = TemplateStyleResolver::fontStyle($gabarit, 'titre1');

        $this->assertSame('Calibri', $style['name']);
        $this->assertSame(18, $style['size']);
        $this->assertSame('AA0000', $style['color']);
        $this->assertTrue($style['bold']);
    }

    public function test_font_style_corps_est_pas_gras(): void
    {
        $gabarit = TemplateStyleResolver::normalize(null);

        $style = TemplateStyleResolver::fontStyle($gabarit, 'corps');

        $this->assertSame(12, $style['size']);
        $this->assertFalse($style['bold']);
        $this->assertSame('000000', $style['color']);
    }

    public function test_title_paragraph_style_utilise_espacements_et_interligne(): void
    {
        $gabarit = TemplateStyleResolver::normalize([
            'interligne' => 1.5,
            'espacements' => ['avant_titre' => 240, 'apres_titre' => 120],
            'alignement_titres' => 'center',
        ]);

        $style = TemplateStyleResolver::titleParagraphStyle($gabarit);

        $this->assertSame('center', $style['alignment']);
        $this->assertSame(240, $style['spaceBefore']);
        $this->assertSame(120, $style['spaceAfter']);
        $this->assertSame(1.5, $style['lineHeight']);
    }

    public function test_body_paragraph_style_utilise_interligne_et_espacement_apres(): void
    {
        $gabarit = TemplateStyleResolver::normalize([
            'interligne' => 2.0,
            'espacements' => ['apres_paragraphe' => 200],
        ]);

        $style = TemplateStyleResolver::bodyParagraphStyle($gabarit);

        $this->assertSame(200, $style['spaceAfter']);
        $this->assertSame(2.0, $style['lineHeight']);
    }

    public function test_section_style_retourne_les_marges_en_twips(): void
    {
        $gabarit = TemplateStyleResolver::normalize([
            'marges' => ['top' => 1800, 'left' => 1080, 'header' => 500],
        ]);

        $style = TemplateStyleResolver::sectionStyle($gabarit);

        $this->assertSame(1800, $style['marginTop']);
        $this->assertSame(1080, $style['marginLeft']);
        $this->assertSame(500, $style['headerHeight']);
        $this->assertSame(1440, $style['marginBottom']);
    }

    public function test_table_header_style_utilise_les_couleurs_du_gabarit(): void
    {
        $gabarit = TemplateStyleResolver::normalize([
            'tableau' => ['header_couleur' => '333333', 'header_texte' => 'FFFF00'],
        ]);

        $style = TemplateStyleResolver::tableHeaderStyle($gabarit);

        $this->assertSame('333333', $style['fill']);
        $this->assertSame('FFFF00', $style['color']);
        $this->assertTrue($style['bold']);
    }

    public function test_table_style_active_les_bordures_selon_le_gabarit(): void
    {
        $avec = TemplateStyleResolver::tableStyle(
            TemplateStyleResolver::normalize(['tableau' => ['bordure' => true]])
        );
        $this->assertSame(6, $avec['borderSize']);

        $sans = TemplateStyleResolver::tableStyle(
            TemplateStyleResolver::normalize(['tableau' => ['bordure' => false]])
        );
        $this->assertSame(0, $sans['borderSize']);
    }
}
