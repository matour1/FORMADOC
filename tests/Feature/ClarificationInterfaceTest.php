<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Document\Adapters\DocxNativeAdapter;
use App\Document\Structure\StructuralDocument;
use App\Models\Document;
use App\Models\DocumentClarification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Interface de clarification (étape 5).
 *
 * **Ce que ces tests protègent.** Le backend créait des questions en base depuis
 * le branchement de R2, mais rien ne les affichait : l'utilisateur subissait les
 * erreurs de détection sans pouvoir les corriger. Cet écran est ce qui rend la
 * promesse « vous gardez le contrôle » vraie.
 *
 * Trois propriétés comptent, et chacune a son test :
 *
 *  1. **une réponse ne corrige QUE le bloc visé** (§8) — appliquer une décision
 *     utilisateur à des blocs similaires lui ferait dire ce qu'il n'a pas dit ;
 *  2. **l'écran est protégé** — une réponse modifie le contenu du document, donc
 *     un tiers ne doit pas y accéder (404, pas 403, pour ne pas révéler
 *     l'existence du document) ;
 *  3. **la réponse rend la confiance maximale** — c'est une décision humaine, il
 *     n'y a plus rien d'incertain, et le bloc ne doit plus jamais être redemandé.
 */
class ClarificationInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(): User
    {
        return User::factory()->create();
    }

    /**
     * Document réel (avec structure native) appartenant à l'utilisateur.
     */
    private function documentAvecStructure(User $user, ?string $contenu = null): Document
    {
        $contenu ??= DocxFixture::create(
            DocxFixture::paragraph('Introduction', outlineLevel: 0)
            .DocxFixture::paragraph('Un paragraphe de contenu ordinaire.')
        );

        $chemin = Storage::disk('storage')->path('documents/'.basename($contenu));

        if (! is_dir(dirname($chemin))) {
            mkdir(dirname($chemin), 0777, true);
        }

        copy($contenu, $chemin);

        $document = Document::create([
            'filename' => 'rapport.docx',
            'path' => 'documents/'.basename($contenu),
            'status' => 'detected',
            'metadata' => ['user_id' => $user->id],
        ]);

        // Structure native persistée : c'est elle que la clarification corrige.
        $structural = (new DocxNativeAdapter)->convert($chemin, (string) $document->id);

        $document->structure()->create([
            'structural_json' => $structural->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => 'native',
            'structure' => ['titres' => []],
        ]);

        return $document->fresh();
    }

    private function question(Document $document, string $blockId, string $extrait = 'Un passage ambigu'): DocumentClarification
    {
        return DocumentClarification::create([
            'document_id' => $document->id,
            'block_id' => $blockId,
            'question' => 'Ce passage est-il un titre ?',
            'input_type' => 'single_select',
            'options' => ['Titre niveau 1', 'Titre niveau 2', 'Paragraphe normal'],
            'excerpt' => $extrait,
            'confidence' => 0.6,
            'reason' => 'Ligne isolée sans signal décisif.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Accès
    // -------------------------------------------------------------------------

    public function test_l_ecran_liste_les_passages_en_attente(): void
    {
        $user = $this->utilisateur();
        $document = $this->documentAvecStructure($user);
        $this->question($document, 'b1', 'Totale : 135600fcfa');

        $this->actingAs($user)
            ->get(route('documents.clarifications.index', $document))
            ->assertOk()
            ->assertSee('Totale : 135600fcfa', false)
            ->assertSee('Titre niveau 1');
    }

    public function test_l_ecran_signale_l_absence_de_passage_en_attente(): void
    {
        $user = $this->utilisateur();
        $document = $this->documentAvecStructure($user);

        $this->actingAs($user)
            ->get(route('documents.clarifications.index', $document))
            ->assertOk()
            ->assertSee('Aucun passage en attente');
    }

    public function test_un_document_d_autrui_est_introuvable(): void
    {
        // Un tiers ne doit pas pouvoir lire les passages d'un document dont il
        // n'est pas propriétaire — 404 et non 403, pour ne pas révéler son
        // existence (même règle que DocumentController).
        $proprietaire = $this->utilisateur();
        $intrus = $this->utilisateur();
        $document = $this->documentAvecStructure($proprietaire);
        $this->question($document, 'b1');

        $this->actingAs($intrus)
            ->get(route('documents.clarifications.index', $document))
            ->assertNotFound();

        $this->actingAs($intrus)
            ->post(route('documents.clarifications.store', $document), ['answers' => ['b1' => 'Titre niveau 1']])
            ->assertNotFound();
    }

    public function test_un_invite_est_redirige_vers_le_login(): void
    {
        $user = $this->utilisateur();
        $document = $this->documentAvecStructure($user);

        $this->get(route('documents.clarifications.index', $document))
            ->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Une réponse ne corrige QUE le bloc visé
    // -------------------------------------------------------------------------

    public function test_une_reponse_ne_corrige_que_le_bloc_vise(): void
    {
        $user = $this->utilisateur();
        $document = $this->documentAvecStructure($user);

        // Deux blocs distincts, chacun avec sa question.
        $structurel = $document->structure->structuralDocument();
        $blocs = $structurel->blocks;

        $this->assertGreaterThanOrEqual(2, count($blocs));

        $premier = $blocs[0]->blockId;
        $second = $blocs[1]->blockId;

        $this->question($document, $premier, 'Premier passage');
        $this->question($document, $second, 'Second passage');

        // L'utilisateur ne répond QUE pour le premier.
        $this->actingAs($user)
            ->post(route('documents.clarifications.store', $document), [
                'answers' => [$premier => 'Titre niveau 1'],
            ])
            ->assertRedirect(route('documents.clarifications.index', $document));

        // Le premier est corrigé et à confiance maximale (décision humaine).
        $premiere = DocumentClarification::where('block_id', $premier)->firstOrFail();
        $this->assertTrue($premiere->isAnswered());
        $this->assertSame('heading_1', $premiere->answer_type);

        $corrige = $document->fresh()->structure->structuralDocument()->blockById($premier);
        $this->assertSame(1.0, $corrige->confidence);
        $this->assertSame('heading', $corrige->type->value);

        // Le second reste INTACT : c'est la garantie du §8. Le corriger aussi
        // reviendrait à prêter à l'utilisateur une décision qu'il n'a pas prise.
        $seconde = DocumentClarification::where('block_id', $second)->firstOrFail();
        $this->assertFalse($seconde->isAnswered());
        $this->assertNull($seconde->answer_type);

        $intact = $document->fresh()->structure->structuralDocument()->blockById($second);
        $this->assertLessThan(1.0, $intact->confidence);
    }

    public function test_une_reponse_enregistree_ne_est_pas_redemandee(): void
    {
        $user = $this->utilisateur();
        $document = $this->documentAvecStructure($user);
        $bloc = $document->structure->structuralDocument()->blocks[0]->blockId;
        $this->question($document, $bloc);

        $this->actingAs($user)->post(route('documents.clarifications.store', $document), [
            'answers' => [$bloc => 'Paragraphe normal'],
        ]);

        // La question répondue sort de la liste des passages en attente.
        $this->actingAs($user)
            ->get(route('documents.clarifications.index', $document))
            ->assertOk()
            ->assertSee('Aucun passage en attente')
            ->assertSee('Déjà confirmés');
    }

    // -------------------------------------------------------------------------
    // Cas limites
    // -------------------------------------------------------------------------

    public function test_un_bloc_disparu_est_ignore_sans_faire_echouer_l_enregistrement(): void
    {
        // Cas réel : la structure a été régénérée et le bloc n'existe plus. La
        // réponse ne doit ni être appliquée à un autre bloc, ni faire échouer
        // tout l'enregistrement (les autres réponses restent valides).
        $user = $this->utilisateur();
        $document = $this->documentAvecStructure($user);
        $blocReel = $document->structure->structuralDocument()->blocks[0]->blockId;

        $this->question($document, 'bloc-disparu');
        $this->question($document, $blocReel, 'Passage réel');

        $this->actingAs($user)
            ->post(route('documents.clarifications.store', $document), [
                'answers' => [
                    'bloc-disparu' => 'Titre niveau 1',
                    $blocReel => 'Titre niveau 2',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Le bloc réel est bien corrigé.
        $corrige = $document->fresh()->structure->structuralDocument()->blockById($blocReel);
        $this->assertSame('heading', $corrige->type->value);

        // Le bloc disparu est marqué répondu mais reste « en attente » côté
        // comptage d'application : aucune autre donnée n'a été touchée.
        $this->assertTrue(
            DocumentClarification::where('block_id', 'bloc-disparu')->firstOrFail()->isAnswered()
        );
    }

    public function test_repondre_sans_aucune_reponse_est_signale_sans_erreur(): void
    {
        $user = $this->utilisateur();
        $document = $this->documentAvecStructure($user);
        $this->question($document, $document->structure->structuralDocument()->blocks[0]->blockId);

        $this->actingAs($user)
            ->post(route('documents.clarifications.store', $document), [])
            ->assertRedirect()
            ->assertSessionHas('warning');
    }

    public function test_une_reponse_vide_est_ignoree(): void
    {
        $user = $this->utilisateur();
        $document = $this->documentAvecStructure($user);
        $bloc = $document->structure->structuralDocument()->blocks[0]->blockId;
        $this->question($document, $bloc);

        // Un libellé vide (formulaire soumis sans sélection) ne doit pas être
        // enregistré comme une réponse : la question doit rester ouverte.
        $this->actingAs($user)
            ->post(route('documents.clarifications.store', $document), [
                'answers' => [$bloc => '   '],
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertFalse(
            DocumentClarification::where('block_id', $bloc)->firstOrFail()->isAnswered()
        );
    }
}
