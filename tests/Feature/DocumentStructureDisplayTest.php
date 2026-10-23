<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Document\Adapters\DocxNativeAdapter;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Affichage de la structure sur la page document (étape 6).
 *
 * **Le défaut corrigé.** La page lisait `$structure->structure`, le format
 * historique. Sur un document traité par le nouveau pipeline, cette colonne est
 * vide — l'écran affichait donc « 0 titre détecté » sur un document dont la
 * classification en avait relevé des dizaines. L'utilisateur aurait conclu que
 * la mise en forme n'avait rien détecté.
 *
 * Ces tests vérifient que la page affiche la structure NATIVE quand elle existe,
 * et qu'elle retombe sur le format historique sinon (documents traités avant
 * l'activation du pipeline).
 */
class DocumentStructureDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function document(User $user, string $contenu): Document
    {
        $chemin = Storage::disk('storage')->path('documents/'.basename($contenu));

        if (! is_dir(dirname($chemin))) {
            mkdir(dirname($chemin), 0777, true);
        }

        copy($contenu, $chemin);

        return Document::create([
            'filename' => 'rapport.docx',
            'path' => 'documents/'.basename($contenu),
            'status' => 'detected',
            'metadata' => ['user_id' => $user->id],
        ]);
    }

    public function test_la_page_affiche_les_titres_de_la_structure_native(): void
    {
        $user = User::factory()->create();

        // Trois titres avec un VRAI style Heading1 déclaré dans styles.xml :
        // `outlineLvl` seul ne suffit pas, l'adaptateur lit le niveau depuis les
        // styles Word (mesuré en écrivant les tests de l'exporteur).
        $styles = DocxFixture::style('Heading1', 'Heading 1', outlineLevel: 0);

        $source = DocxFixture::create(
            DocxFixture::paragraph('Introduction générale', styleId: 'Heading1')
            .DocxFixture::paragraph('Contexte du projet', styleId: 'Heading1')
            .DocxFixture::paragraph('Un paragraphe ordinaire.')
            .DocxFixture::paragraph('Méthodologie retenue', styleId: 'Heading1'),
            $styles
        );

        $document = $this->document($user, $source);
        $structurel = (new DocxNativeAdapter)->convert(
            Storage::disk('storage')->path($document->path),
            (string) $document->id
        );

        // La structure native est persistée, la colonne historique reste VIDE —
        // c'est exactement la situation qui produisait « 0 titre ».
        $document->structure()->create([
            'structural_json' => $structurel->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => 'native',
            'structure' => ['titres' => []],
        ]);

        $reponse = $this->actingAs($user)->get(route('documents.show', $document));

        $reponse->assertOk();

        // Les titres réellement détectés doivent apparaître.
        $reponse->assertSee('Introduction générale', false);
        $reponse->assertSee('Contexte du projet', false);
        $reponse->assertSee('Méthodologie retenue', false);

        // Et le décompte ne doit PAS être nul.
        $reponse->assertSee('3 titres détectés', false);
    }

    public function test_la_page_retombe_sur_le_format_historique_sans_structure_native(): void
    {
        // Document traité avant l'activation du pipeline : la seule source est
        // `structure`. La page doit continuer de l'afficher.
        $user = User::factory()->create();
        $source = DocxFixture::create(DocxFixture::paragraph('Un paragraphe.'));
        $document = $this->document($user, $source);

        $document->structure()->create([
            'structure' => [
                'titres' => [
                    ['texte' => 'Titre historique', 'niveau' => 1, 'position' => []],
                ],
                'sous_titres' => [],
                'legends' => [
                    ['line' => 12, 'type' => 'figure', 'number' => '1', 'label' => 'Architecture'],
                ],
            ],
            'ambiguities' => [],
        ]);

        $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('Titre historique', false)
            ->assertSee('Architecture', false);
    }

    public function test_la_page_signale_la_provenance_native(): void
    {
        // L'utilisateur doit pouvoir distinguer un document lu par le nouveau
        // pipeline d'un document historique : le comportement de mise en forme
        // n'est pas le même.
        $user = User::factory()->create();
        $source = DocxFixture::create(DocxFixture::paragraph('Un paragraphe.'));
        $document = $this->document($user, $source);

        $structurel = (new DocxNativeAdapter)->convert(
            Storage::disk('storage')->path($document->path),
            (string) $document->id
        );

        $document->structure()->create([
            'structural_json' => $structurel->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => 'native',
            // `structure` est NOT NULL au schéma : on y met un squelette vide,
            // ce qui correspond au cas réel d'un document traité par le nouveau
            // pipeline (la colonne historique existe mais ne porte rien).
            'structure' => ['titres' => []],
        ]);

        $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('analyse native');
    }

    /**
     * Un titre À CONFIRMER ne doit pas être compté comme un titre détecté.
     *
     * **Le défaut corrigé.** L'en-tête annonçait « 208 titres détectés » alors
     * que 68 de ces blocs portaient une confiance sous le seuil d'acceptation
     * (0,85) : la même page demandait, cinquante lignes plus haut, de
     * « confirmer 254 passages ». Le compteur affirmait donc comme établi ce
     * que la page elle-même mettait en doute.
     *
     * **Ce qui est vérifié.** Le total reste exact (aucun titre n'est caché),
     * mais il est décomposé, et les titres déduits sont présentés à part.
     */
    public function test_les_titres_sous_le_seuil_sont_comptes_a_part(): void
    {
        $user = User::factory()->create();
        $source = DocxFixture::create(DocxFixture::paragraph('Un paragraphe.'));
        $document = $this->document($user, $source);

        $structurel = (new DocxNativeAdapter)->convert(
            Storage::disk('storage')->path($document->path),
            (string) $document->id
        );

        // Deux titres établis (confiance 1.0) et un titre déduit (0,82 : la
        // règle « ligne isolée en gras » ne fait que proposer).
        $base = $structurel->blocks[0] ?? null;
        $this->assertNotNull($base);

        $etabli = static fn (string $id, string $texte): Block => new Block(
            blockId: $id,
            type: BlockType::Heading,
            text: $texte,
            headingLevel: 1,
            confidence: 1.0,
        );

        $deduit = static fn (string $id, string $texte): Block => new Block(
            blockId: $id,
            type: BlockType::Heading,
            text: $texte,
            headingLevel: 1,
            confidence: 0.82,
        );

        $avecTitres = new StructuralDocument(
            documentId: (string) $document->id,
            sourceType: $structurel->sourceType,
            blocks: [
                $etabli('b_001', 'Introduction générale'),
                $etabli('b_002', 'Méthodologie'),
                $deduit('b_003', 'La direction commerciale : Elle a pour mission,'),
            ],
            meta: $structurel->meta,
        );

        $document->structure()->create([
            'structural_json' => $avecTitres->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => 'native',
            'structure' => ['titres' => []],
        ]);

        $reponse = $this->actingAs($user)->get(route('documents.show', $document));

        $reponse->assertOk();

        // Le total reste exact : rien n'est masqué.
        $reponse->assertSee('3 titres détectés', false);
        // Mais il est décomposé : 1 seul titre est proposé, pas affirmé.
        $reponse->assertSee('dont', false);
        $reponse->assertSee('1 à confirmer', false);

        // Le titre déduit est présenté dans sa propre section…
        $reponse->assertSee('Titres proposés', false);
        $reponse->assertSee('La direction commerciale', false);
        // …et les titres établis le sont avant, hors de cette section.
        $reponse->assertSee('Introduction générale', false);
    }

    /**
     * Sans titre sous le seuil, la mention « dont … à confirmer » est absente.
     *
     * Afficher « dont 0 à confirmer » serait du bruit, et sur un document dont
     * tous les titres sont établis cela suggérerait un doute qui n'existe pas.
     */
    public function test_la_mention_a_confirmer_est_absente_sans_titre_deduit(): void
    {
        $user = User::factory()->create();
        $source = DocxFixture::create(DocxFixture::paragraph('Un paragraphe.'));
        $document = $this->document($user, $source);

        $structurel = (new DocxNativeAdapter)->convert(
            Storage::disk('storage')->path($document->path),
            (string) $document->id
        );

        $document->structure()->create([
            'structural_json' => $structurel->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => 'native',
            'structure' => ['titres' => []],
        ]);

        $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            // La mention « · dont N à confirmer » ne doit PAS apparaître quand
            // tous les titres sont établis. On cible la formulation exacte du
            // compteur (avec « · dont ») pour ne pas interférer avec la phrase
            // d'aide des clarifications (« Passages dont la nature est incertaine »).
            ->assertDontSee('· dont', false)
            ->assertDontSee('Titres proposés', false);
    }

    /**
     * Quand une page de garde est supprimée, la mention "Supprimée" est affichée.
     */
    public function test_la_page_de_garde_supprimee_est_signalee_a_l_utilisateur(): void
    {
        $user = User::factory()->create();
        $styles = DocxFixture::style('Heading1', 'Heading 1', outlineLevel: 0);
        $source = DocxFixture::create(
            DocxFixture::paragraph('REPUBLIQUE DU CAMEROUN')
            .DocxFixture::paragraph('RAPPORT DE STAGE ACADEMIQUE')
            .DocxFixture::paragraph('SOMMAIRE', styleId: 'Heading1')
            .DocxFixture::paragraph('Texte du rapport.'),
            $styles,
        );
        $document = $this->document($user, $source);

        $structurel = (new DocxNativeAdapter)->convert(
            Storage::disk('storage')->path($document->path),
            (string) $document->id
        );

        $document->structure()->create([
            'structural_json' => $structurel->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => 'native',
            'structure' => ['titres' => []],
        ]);

        $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('Page de garde', false)
            ->assertSee('Supprimée', false)
            ->assertSee('Une page de garde source a été détectée et retirée', false);
    }
}
