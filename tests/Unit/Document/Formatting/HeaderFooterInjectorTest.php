<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Formatting;

use App\Document\Formatting\HeaderFooterInjector;
use App\Document\Formatting\TemplateEngine;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use Tests\TestCase;

/**
 * Tests de l'injection des en-têtes, pieds de page et numérotation.
 *
 * Deux règles importent :
 *  - un en-tête **déjà présent** dans la source est préservé, jamais écrasé ;
 *  - la page de garde **n'est pas numérotée** (convention académique), sans
 *    pour autant décaler la pagination des pages suivantes.
 */
class HeaderFooterInjectorTest extends TestCase
{
    private function header(string $text): Block
    {
        return new Block(blockId: 'b_h1', type: BlockType::Header, text: $text);
    }

    private function footer(string $text): Block
    {
        return new Block(blockId: 'b_f1', type: BlockType::Footer, text: $text);
    }

    public function test_les_options_fournissent_un_en_tete_et_un_pied(): void
    {
        $description = (new HeaderFooterInjector)->describe(
            [],
            TemplateEngine::defaultTemplate(),
            ['header' => 'Rapport de stage', 'footer' => 'Mémoire 2025'],
        );

        $this->assertSame('Rapport de stage', $description['header']['text']);
        $this->assertSame('Mémoire 2025', $description['footer']['text']);
        $this->assertFalse($description['header']['from_source']);
    }

    public function test_un_en_tete_present_dans_la_source_est_preserve(): void
    {
        // L'utilisateur a déjà mis son en-tête : on ne le remplace pas par une
        // valeur générique, on le complète seulement de la numérotation.
        $description = (new HeaderFooterInjector)->describe(
            [$this->header('En-tête original du document')],
            TemplateEngine::defaultTemplate(),
            ['header' => 'Rapport de stage'],
        );

        $this->assertSame('En-tête original du document', $description['header']['text']);
        $this->assertTrue($description['header']['from_source']);
    }

    public function test_une_option_vide_ne_cree_pas_de_bande_blanche(): void
    {
        // Un en-tête vide laisserait une bande blanche réservée en haut de
        // chaque page : mieux vaut ne rien demander.
        $description = (new HeaderFooterInjector)->describe(
            [],
            TemplateEngine::defaultTemplate(),
            ['header' => '   ', 'footer' => ''],
        );

        $this->assertNull($description['header']);
        $this->assertNull($description['footer']);
    }

    public function test_la_numerotation_ne_compte_pas_la_page_de_garde(): void
    {
        // `start_at` reste 1 : c'est le drapeau `skip_first` (page de garde
        // distincte) qui masque le numéro. Un décalage produirait « Page 2 »
        // sur ce qui est visiblement la première page.
        $description = (new HeaderFooterInjector)->describe(
            [],
            TemplateEngine::defaultTemplate(),
            ['page_number' => true, 'skip_first_page' => true],
        );

        $this->assertSame(1, $description['page_number']['start_at']);
        $this->assertTrue($description['page_number']['skip_first']);
    }

    public function test_la_numerotation_peut_inclure_la_page_de_garde(): void
    {
        $description = (new HeaderFooterInjector)->describe(
            [],
            TemplateEngine::defaultTemplate(),
            ['page_number' => true, 'skip_first_page' => false],
        );

        $this->assertFalse($description['page_number']['skip_first']);
    }

    public function test_la_numerotation_peut_etre_desactivee(): void
    {
        $description = (new HeaderFooterInjector)->describe(
            [],
            TemplateEngine::defaultTemplate(),
            ['page_number' => false],
        );

        $this->assertNull($description['page_number']);
    }

    public function test_la_numerotation_se_place_dans_le_pied_par_defaut(): void
    {
        $description = (new HeaderFooterInjector)->describe([], TemplateEngine::defaultTemplate());

        $this->assertSame('footer', $description['page_number']['position']);
        $this->assertSame('center', $description['page_number']['alignment']);
        $this->assertSame('decimal', $description['page_number']['format']);
    }

    public function test_le_total_de_pages_est_desactive_par_defaut(): void
    {
        // Le champ NUMPAGES force un recalcul complet du document à chaque
        // ouverture : on ne l'active que si « / N » est réellement demandé.
        $description = (new HeaderFooterInjector)->describe([], TemplateEngine::defaultTemplate());

        $this->assertFalse($description['page_total']);
    }

    public function test_la_presence_d_un_en_tete_source_est_detectable(): void
    {
        $injector = new HeaderFooterInjector;

        $this->assertTrue($injector->hasSourceHeaderFooter([$this->header('Titre')]));
        $this->assertTrue($injector->hasSourceHeaderFooter([$this->footer('Pied')]));
        $this->assertFalse($injector->hasSourceHeaderFooter([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte'),
        ]));
    }

    public function test_les_marges_d_en_tete_sont_valides_par_defaut(): void
    {
        // Si la marge d'en-tête dépassait la marge haute, le texte de page
        // chevaucherait l'en-tête : défaut visible et pénible à diagnostiquer.
        $margins = (new HeaderFooterInjector)->marginsFor();

        $this->assertTrue($margins['valid']);
        $this->assertLessThan($margins['top'], $margins['header']);
    }

    public function test_des_marges_incoherentes_du_gabarit_sont_signalees(): void
    {
        $margins = (new HeaderFooterInjector)->marginsFor([
            'marges' => ['top' => 500, 'header' => 900],
        ]);

        $this->assertFalse($margins['valid']);
    }

    public function test_le_document_sans_en_tete_ni_option_ne_produit_rien(): void
    {
        $description = (new HeaderFooterInjector)->describe(
            [new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte')],
            TemplateEngine::defaultTemplate(),
            ['header' => '', 'footer' => '', 'page_number' => false],
        );

        $this->assertNull($description['header']);
        $this->assertNull($description['footer']);
        $this->assertNull($description['page_number']);
    }
}
