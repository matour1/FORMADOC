<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Clarification;

use App\Document\Clarification\AskUserClarificationTool;
use App\Document\Clarification\ClarificationService;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Models\Document;
use App\Models\DocumentClarification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests du mécanisme de clarification ciblée.
 *
 * Deux invariants sont verrouillés ici :
 *  1. une question porte sur **UN bloc**, jamais sur tout le document ;
 *  2. une réponse ne corrige **que le bloc visé** — imposer une décision à
 *     d'autres blocs serait faire dire à l'utilisateur ce qu'il n'a pas dit.
 */
class ClarificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClarificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ClarificationService(new AskUserClarificationTool);
    }

    private function document(int $id): Document
    {
        return Document::create([
            'filename' => 'rapport.docx',
            'path' => 'documents/rapport.docx',
            'status' => 'detected',
            'metadata' => ['user_id' => null],
        ]);
    }

    private function block(string $id, string $text, bool $bold = false): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Paragraph,
            text: $text,
            isBold: $bold,
            confidence: 0.6,
        );
    }

    // -------------------------------------------------------------------------
    // Création des questions
    // -------------------------------------------------------------------------

    public function test_une_question_est_creee_pour_chaque_bloc_ambigu(): void
    {
        $document = $this->document(1);

        $result = $this->service->createQuestions($document->id, [
            $this->block('b_0001', 'Introduction'),
            $this->block('b_0002', 'Méthodologie'),
        ]);

        $this->assertSame(2, $result['created']);
        $this->assertCount(2, $result['questions']);
        $this->assertSame(2, DocumentClarification::count());
    }

    public function test_une_question_porte_sur_un_seul_bloc(): void
    {
        // Invariant fondamental : jamais de question globale.
        $document = $this->document(1);

        $result = $this->service->createQuestions($document->id, [$this->block('b_0042', 'Introduction')]);
        $question = $result['questions'][0];

        $this->assertSame('b_0042', $question['block_id']);
        $this->assertCount(1, $question['schema']['required']);
        $this->assertArrayHasKey('b_0042', $question['schema']['properties']);
    }

    public function test_une_question_conserve_l_extrait_et_la_raison(): void
    {
        // L'extrait permet de réafficher la question après régénération ;
        // la raison permet de comprendre l'origine de l'incertitude.
        $document = $this->document(1);
        $this->service->createQuestions($document->id, [$this->block('b_0001', '1.1 Institution')]);

        $stored = DocumentClarification::first();

        $this->assertSame('1.1 Institution', $stored->excerpt);
        $this->assertSame(0.6, $stored->confidence);
    }

    public function test_une_question_deja_repondue_n_est_pas_recreee(): void
    {
        // L'utilisateur ne doit pas répondre deux fois à la même question après
        // une nouvelle analyse : sa décision est une vérité, pas un calcul.
        $document = $this->document(1);
        $block = $this->block('b_0001', 'Introduction');

        $this->service->createQuestions($document->id, [$block]);
        DocumentClarification::first()->answer('Titre niveau 1');

        $result = $this->service->createQuestions($document->id, [$block]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, DocumentClarification::count());
        $this->assertSame('Titre niveau 1', DocumentClarification::first()->answer_raw);
    }

    public function test_les_options_proposees_dependent_du_contexte(): void
    {
        $document = $this->document(1);
        $result = $this->service->createQuestions($document->id, [
            $this->block('b_0001', 'Introduction'),
            $this->block('b_0002', 'Figure : Schéma sans numéro'),
        ]);

        $byBlock = [];
        foreach ($result['questions'] as $question) {
            $byBlock[$question['block_id']] = $question['options'];
        }

        // Un texte ordinaire → question « titre ou paragraphe ».
        $this->assertContains('Titre niveau 1', $byBlock['b_0001']);
        // Un texte évoquant une figure → question de catégorie.
        $this->assertContains('Tableau', $byBlock['b_0002']);
    }

    public function test_le_resume_est_lisible(): void
    {
        // L'interface affiche ce résumé : « 3 passages à confirmer ».
        $tool = new AskUserClarificationTool;

        $this->assertSame('Aucune clarification nécessaire.', $tool->summary([])['summary']);

        $one = $tool->summary([$this->block('b_0001', 'Titre')]);
        $this->assertSame(1, $one['count']);
        $this->assertStringContainsString('1 passage', $one['summary']);

        $three = $tool->summary([
            $this->block('b_0001', 'A'),
            $this->block('b_0002', 'B'),
            $this->block('b_0003', 'C'),
        ]);
        $this->assertSame(3, $three['count']);
        $this->assertStringContainsString('3 passages', $three['summary']);
    }

    public function test_une_question_contient_le_formulaire_schema(): void
    {
        $tool = new AskUserClarificationTool;
        $question = $tool->buildQuestion($this->block('b_0001', 'Introduction'));

        $this->assertSame('single_select', $question['input_type']);
        $this->assertSame('object', $question['schema']['type']);
        $this->assertArrayHasKey('properties', $question['schema']);
    }

    // -------------------------------------------------------------------------
    // Application des réponses
    // -------------------------------------------------------------------------

    public function test_une_reponse_corrige_le_type_du_bloc(): void
    {
        $document = $this->document(1);
        $this->service->createQuestions($document->id, [$this->block('b_0001', 'Introduction')]);
        DocumentClarification::first()->answer('Titre niveau 2');

        $structural = new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [$this->block('b_0001', 'Introduction')],
        );

        $result = $this->service->applyAnswers($document->id, $structural);
        $corrected = $result['document']->blockById('b_0001');

        $this->assertSame(1, $result['applied']);
        $this->assertSame(BlockType::Heading, $corrected?->type);
        $this->assertSame(2, $corrected?->headingLevel);
    }

    public function test_une_reponse_porte_la_confiance_a_un(): void
    {
        // La décision vient de l'utilisateur : il n'y a plus rien d'incertain,
        // et aucune nouvelle question ne sera posée sur ce bloc.
        $document = $this->document(1);
        $this->service->createQuestions($document->id, [$this->block('b_0001', 'Introduction')]);
        DocumentClarification::first()->answer('Paragraphe normal');

        $structural = new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [$this->block('b_0001', 'Introduction')],
        );

        $corrected = $this->service->applyAnswers($document->id, $structural)['document']->blockById('b_0001');

        $this->assertSame(1.0, $corrected?->confidence);
        $this->assertTrue($corrected?->isConfident());
    }

    public function test_une_reponse_ne_corrige_que_le_bloc_vise(): void
    {
        // Invariant : ne jamais propager une décision à d'autres blocs. Ici les
        // deux blocs ont un texte SIMILAIRE, mais un seul a reçu une réponse.
        $document = $this->document(1);
        $this->service->createQuestions($document->id, [
            $this->block('b_0001', 'Introduction'),
            $this->block('b_0002', 'Introduction'),
        ]);

        DocumentClarification::where('block_id', 'b_0001')->first()->answer('Titre niveau 1');

        $structural = new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [
                $this->block('b_0001', 'Introduction'),
                $this->block('b_0002', 'Introduction'),
            ],
        );

        $result = $this->service->applyAnswers($document->id, $structural);

        $this->assertSame(BlockType::Heading, $result['document']->blockById('b_0001')?->type);
        $this->assertSame(
            BlockType::Paragraph,
            $result['document']->blockById('b_0002')?->type,
            'Le bloc non répondu ne doit pas être modifié'
        );
        $this->assertSame(1, $result['applied']);
    }

    public function test_une_reponse_sur_un_bloc_disparu_est_ignoree_sans_erreur(): void
    {
        // La structure peut avoir été régénérée : une réponse orpheline ne doit
        // pas faire échouer le traitement.
        $document = $this->document(1);
        $this->service->createQuestions($document->id, [$this->block('b_9999', 'Bloc supprimé')]);
        DocumentClarification::first()->answer('Titre niveau 1');

        $structural = new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [$this->block('b_0001', 'Autre bloc')],
        );

        $result = $this->service->applyAnswers($document->id, $structural);

        $this->assertSame(0, $result['applied']);
        $this->assertSame(1, $result['document']->count());
    }

    public function test_les_questions_en_attente_sont_listees(): void
    {
        $document = $this->document(1);
        $this->service->createQuestions($document->id, [
            $this->block('b_0001', 'Introduction'),
            $this->block('b_0002', 'Méthodologie'),
        ]);
        DocumentClarification::where('block_id', 'b_0001')->first()->answer('Titre niveau 1');

        $pending = $this->service->pendingFor($document->id);

        $this->assertCount(1, $pending);
        $this->assertSame('b_0002', $pending[0]->block_id);
    }

    public function test_le_nombre_de_questions_en_attente_est_rapporte(): void
    {
        $document = $this->document(1);
        $this->service->createQuestions($document->id, [
            $this->block('b_0001', 'Introduction'),
            $this->block('b_0002', 'Méthodologie'),
        ]);
        DocumentClarification::where('block_id', 'b_0001')->first()->answer('Titre niveau 1');

        $structural = new StructuralDocument(
            documentId: 'doc_1',
            sourceType: 'docx',
            blocks: [$this->block('b_0001', 'Introduction'), $this->block('b_0002', 'Méthodologie')],
        );

        $result = $this->service->applyAnswers($document->id, $structural);

        $this->assertSame(1, $result['pending'], 'La question non répondue reste en attente');
    }

    // -------------------------------------------------------------------------
    // Traduction des réponses
    // -------------------------------------------------------------------------

    public function test_les_reponses_sont_traduites_en_types_de_blocs(): void
    {
        $clarification = new DocumentClarification;

        $this->assertSame('heading_1', $clarification->typeFromAnswer('Titre niveau 1'));
        $this->assertSame('heading_2', $clarification->typeFromAnswer('Titre niveau 2'));
        $this->assertSame('paragraph', $clarification->typeFromAnswer('Paragraphe normal'));
        $this->assertSame('figure', $clarification->typeFromAnswer('Figure'));
        $this->assertSame('table', $clarification->typeFromAnswer('Tableau'));
        $this->assertSame('annexe', $clarification->typeFromAnswer('Annexe'));
        $this->assertSame('planche', $clarification->typeFromAnswer('Planche'));
        $this->assertSame('image', $clarification->typeFromAnswer('Image décorative'));
    }

    public function test_une_reponse_figure_numerotee_est_traduite_en_figure(): void
    {
        // Les libellés contiennent « (numérotée) » : la traduction doit
        // fonctionner malgré ce suffixe.
        $clarification = new DocumentClarification;

        $this->assertSame('figure', $clarification->typeFromAnswer('Figure (numérotée)'));
    }

    public function test_une_reponse_inconnue_ne_produit_pas_de_type(): void
    {
        $this->assertNull((new DocumentClarification)->typeFromAnswer('Réponse fantaisiste'));
    }

    public function test_le_niveau_de_titre_est_deduit_de_la_reponse(): void
    {
        $clarification = new DocumentClarification;
        $clarification->answer_raw = 'Titre niveau 3';
        $clarification->answer_type = 'heading_3';

        $this->assertSame(3, $clarification->headingLevelFromAnswer());
    }

    public function test_une_reponse_non_titre_n_a_pas_de_niveau(): void
    {
        $clarification = new DocumentClarification;
        $clarification->answer_raw = 'Paragraphe normal';
        $clarification->answer_type = 'paragraph';

        $this->assertNull($clarification->headingLevelFromAnswer());
    }
}
