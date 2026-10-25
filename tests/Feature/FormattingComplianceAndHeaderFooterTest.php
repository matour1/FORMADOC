<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\User;
use App\Services\DocumentGeneration\FormattingComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormattingComplianceAndHeaderFooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_header_footer_persists_theme_and_redacteur_in_document_structure(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'filename' => 'rapport_stage.docx',
            'path' => 'uploads/rapport_stage.docx',
            'status' => 'detected',
            'metadata' => [
                'user_id' => $user->id,
                'size' => 20480,
            ],
        ]);

        DocumentStructure::create([
            'document_id' => $document->id,
            'structure' => [
                'titres' => [
                    ['texte' => 'DEDICACE', 'niveau' => 1],
                    ['texte' => 'REMERCIEMENTS', 'niveau' => 1],
                    ['texte' => 'AVANT-PROPOS', 'niveau' => 1],
                    ['texte' => 'LISTE DES SIGLES ET ABREVIATIONS', 'niveau' => 1],
                    ['texte' => 'RESUME', 'niveau' => 1],
                    ['texte' => 'ABSTRACT', 'niveau' => 1],
                    ['texte' => 'INTRODUCTION GENERALE', 'niveau' => 1],
                    ['texte' => 'PREMIERE PARTIE : CADRE DU PROJET', 'niveau' => 1],
                    ['texte' => 'CHAPITRE I : ETUDE PREALABLE', 'niveau' => 1],
                    ['texte' => 'CONCLUSION GENERALE', 'niveau' => 1],
                    ['texte' => 'REFERENCES BIBLIOGRAPHIQUES', 'niveau' => 1],
                ],
                'sous_titres' => [
                    ['texte' => 'SECTION 1 : PRESENTATION', 'niveau' => 2],
                ],
                'en_tetes' => [],
                'pieds_de_page' => [],
                'tableaux' => [['rows_count' => 4]],
                'images' => [['image_name' => 'fig1.png']],
                'legends' => [
                    ['type' => 'Tableau', 'number' => '1', 'label' => 'Comparatif'],
                    ['type' => 'Figure', 'number' => '1', 'label' => 'Architecture'],
                ],
            ],
            'ambiguities' => [],
        ]);

        $response = $this->actingAs($user)->post(route('documents.header-footer.update', $document), [
            'theme' => 'CONCEPTION ET REALISATION D\'UNE PLATEFORME DE NUMERISATION DES CARNETS MEDICAUX',
            'redacteur' => 'DJOMO KAMGA Jean',
            'mention_redacteur' => '',
        ]);

        $response->assertRedirect();

        $document->refresh();
        $hf = (new FormattingComplianceService)->extractHeaderFooterConfig($document);

        $this->assertSame(
            'CONCEPTION ET REALISATION D\'UNE PLATEFORME DE NUMERISATION DES CARNETS MEDICAUX',
            $hf['theme']
        );
        $this->assertSame('DJOMO KAMGA Jean', $hf['redacteur']);
        $this->assertSame(
            "RÉDIGÉ EN VUE D'ÊTRE PRÉSENTÉ PAR : DJOMO KAMGA Jean",
            $hf['mention_redacteur']
        );

        $compliance = (new FormattingComplianceService)->evaluate($document);
        $this->assertSame(100, $compliance['score']);
        $this->assertSame('conforme', $compliance['status']);
        $this->assertEmpty($compliance['missing_sections']);
    }

    public function test_compliance_service_identifies_missing_academic_sections(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'filename' => 'rapport_incomplet.docx',
            'path' => 'uploads/rapport_incomplet.docx',
            'status' => 'detected',
            'metadata' => [
                'user_id' => $user->id,
            ],
        ]);

        DocumentStructure::create([
            'document_id' => $document->id,
            'structure' => [
                'titres' => [
                    ['texte' => 'CHAPITRE I : PRESENTATION', 'niveau' => 1],
                ],
                'sous_titres' => [],
                'en_tetes' => [],
                'pieds_de_page' => [],
                'tableaux' => [],
                'images' => [],
                'legends' => [],
            ],
            'ambiguities' => [],
        ]);

        $compliance = (new FormattingComplianceService)->evaluate($document);

        $this->assertLessThan(100, $compliance['score']);
        $this->assertNotEmpty($compliance['missing_sections']);
    }
}
