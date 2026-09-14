<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Editing;

use App\Document\Editing\ChatEditAgent;
use App\Document\Editing\DocumentEditingService;
use App\Document\Editing\ToolWhitelist;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Services\Chat\ChatToolsService;
use App\Services\Chat\EditToolSchemas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests du branchement de R6 dans l'application (tâches 13–16).
 *
 * Deux propriétés sont vérifiées ici, et nulle part ailleurs :
 *  - **Isolation** : un document sans structure native n'est pas éditable, et le
 *    message le dit clairement au lieu d'échouer sur un `null`.
 *  - **Cohérence** : les schémas exposés au modèle et la liste blanche ne peuvent
 *    pas diverger — l'un dérive de l'autre.
 */
class DocumentEditingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function documentEnBase(): Document
    {
        return Document::create([
            'filename' => 'rapport.docx',
            'path' => 'documents/rapport.docx',
            'status' => 'detected',
            'metadata' => ['user_id' => null],
        ]);
    }

    private function structureEnBase(Document $document, array $blocks, ?string $pipeline = 'native'): DocumentStructure
    {
        $structural = new StructuralDocument(
            documentId: (string) $document->id,
            sourceType: 'docx',
            blocks: $blocks,
        );

        return DocumentStructure::create([
            'document_id' => $document->id,
            'structure' => [],
            'structural_json' => $structural->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => $pipeline,
        ]);
    }

    private function paragraphe(string $id, string $text = 'Texte initial'): Block
    {
        return new Block(blockId: $id, type: BlockType::Paragraph, text: $text);
    }

    private function tableau(string $id): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            tableData: TableData::fromGrid([['A', 'B'], ['C', 'D']]),
        );
    }

    // -------------------------------------------------------------------------
    // Chargement
    // -------------------------------------------------------------------------

    public function test_un_document_sans_structure_n_est_pas_editable(): void
    {
        // Message explicite plutôt qu'un échec sur `null` : l'utilisateur doit
        // comprendre qu'il faut d'abord analyser le document.
        $document = $this->documentEnBase();

        $resultat = (new DocumentEditingService)->apply(
            $document->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
        );

        $this->assertFalse($resultat['applied']);
        $this->assertStringContainsString('analysé', (string) $resultat['error']);
    }

    public function test_un_document_du_pipeline_historique_n_est_pas_editable(): void
    {
        // L'ancien pipeline ne produit pas de `structural_json` exploitable :
        // le dire évite de laisser croire à une panne.
        $document = $this->documentEnBase();

        DocumentStructure::create([
            'document_id' => $document->id,
            'structure' => ['titres' => []],
            'structural_json' => null,
            'pipeline' => 'legacy',
        ]);

        $resultat = (new DocumentEditingService)->apply(
            $document->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
        );

        $this->assertFalse($resultat['applied']);
        $this->assertStringContainsString('ancien pipeline', (string) $resultat['error']);
    }

    public function test_un_document_inexistant_est_signale(): void
    {
        $resultat = (new DocumentEditingService)->apply(
            999999,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
        );

        $this->assertFalse($resultat['applied']);
        $this->assertStringContainsString('999999', (string) $resultat['error']);
    }

    // -------------------------------------------------------------------------
    // Application et persistance
    // -------------------------------------------------------------------------

    public function test_une_edition_est_appliquee_et_persistee(): void
    {
        // C'est le point du pont : l'édition doit survivre à la requête.
        $document = $this->documentEnBase();
        $structure = $this->structureEnBase($document, [$this->paragraphe('b_0001')]);

        $resultat = (new DocumentEditingService)->apply(
            $document->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Texte réécrit'],
        );

        $this->assertTrue($resultat['applied']);

        // Relire depuis la base : la modification doit y être.
        $recharge = StructuralDocument::fromArray($structure->fresh()->structural_json);

        $this->assertSame('Texte réécrit', $recharge->blockById('b_0001')->text);
    }

    public function test_la_version_de_schema_et_le_pipeline_sont_conserves(): void
    {
        // Sans `pipeline`, le document semblerait rétrogradé après une simple
        // édition, et le prochain chargement le prendrait pour du legacy.
        $document = $this->documentEnBase();
        $structure = $this->structureEnBase($document, [$this->paragraphe('b_0001')]);

        (new DocumentEditingService)->apply(
            $document->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
        );

        $fraiche = $structure->fresh();

        $this->assertSame(StructuralDocument::SCHEMA_VERSION, $fraiche->schema_version);
        $this->assertSame('native', $fraiche->pipeline);
    }

    public function test_une_action_refusee_ne_modifie_pas_le_document(): void
    {
        // Un échec ne doit rien persister : sinon l'utilisateur verrait son
        // document changer alors que l'opération a échoué.
        $document = $this->documentEnBase();
        $structure = $this->structureEnBase($document, [$this->paragraphe('b_0001', 'Origine')]);

        $resultat = (new DocumentEditingService)->apply(
            $document->id,
            'rewrite_paragraph',
            ['block_id' => 'b_9999', 'text' => 'Nouveau'],
        );

        $this->assertFalse($resultat['applied']);

        $recharge = StructuralDocument::fromArray($structure->fresh()->structural_json);
        $this->assertSame('Origine', $recharge->blockById('b_0001')->text);
    }

    public function test_une_confirmation_requise_ne_persiste_rien(): void
    {
        // Point critique : tant que l'utilisateur n'a pas accepté, le document
        // doit rester intact.
        $document = $this->documentEnBase();

        $blocs = [];
        for ($i = 1; $i <= 8; $i++) {
            $blocs[] = $this->paragraphe(sprintf('b_%04d', $i), "Paragraphe {$i}");
        }

        $structure = $this->structureEnBase($document, $blocs);

        $resultat = (new DocumentEditingService)->apply(
            $document->id,
            'regenerate_section',
            [
                'start_block_id' => 'b_0001',
                'end_block_id' => 'b_0006',
                'content' => ['Nouveau'],
            ],
        );

        $this->assertFalse($resultat['applied']);
        $this->assertTrue($resultat['confirmation_required']);

        $recharge = StructuralDocument::fromArray($structure->fresh()->structural_json);
        $this->assertSame(8, $recharge->count());
    }

    public function test_une_action_confirmee_est_appliquee_et_persistee(): void
    {
        $document = $this->documentEnBase();

        $blocs = [];
        for ($i = 1; $i <= 8; $i++) {
            $blocs[] = $this->paragraphe(sprintf('b_%04d', $i));
        }

        $structure = $this->structureEnBase($document, $blocs);

        $resultat = (new DocumentEditingService)->apply(
            $document->id,
            'regenerate_section',
            [
                'start_block_id' => 'b_0001',
                'end_block_id' => 'b_0006',
                'content' => ['Nouveau'],
            ],
            'chat:1',
            /** confirmed */ true,
        );

        $this->assertTrue($resultat['applied']);

        $recharge = StructuralDocument::fromArray($structure->fresh()->structural_json);
        $this->assertSame(3, $recharge->count());
    }

    // -------------------------------------------------------------------------
    // Annulation
    // -------------------------------------------------------------------------

    public function test_l_annulation_restaure_et_persiste(): void
    {
        // Le bénéfice concret du snapshot : le document redevient tel qu'il était,
        // y compris après rechargement depuis la base.
        $document = $this->documentEnBase();
        $structure = $this->structureEnBase($document, [
            $this->paragraphe('b_0001', 'À garder'),
            $this->paragraphe('b_0002', 'À supprimer'),
        ]);

        $service = new DocumentEditingService;

        $service->apply(
            $document->id,
            'delete_block',
            ['block_id' => 'b_0002'],
            'chat:1',
            true,
        );

        $apresSuppression = StructuralDocument::fromArray($structure->fresh()->structural_json);
        $this->assertSame(1, $apresSuppression->count());

        $annulation = $service->undo($document->id);

        $this->assertTrue($annulation['restored']);

        $restaure = StructuralDocument::fromArray($structure->fresh()->structural_json);
        $this->assertSame(2, $restaure->count());
        $this->assertSame('À supprimer', $restaure->blockById('b_0002')->text);
    }

    public function test_annuler_sans_snapshot_est_signale(): void
    {
        $document = $this->documentEnBase();
        $this->structureEnBase($document, [$this->paragraphe('b_0001')]);

        $annulation = (new DocumentEditingService)->undo($document->id);

        $this->assertFalse($annulation['restored']);
        $this->assertStringContainsString('Aucune action', (string) $annulation['error']);
    }

    public function test_l_historique_des_annulations_est_expose(): void
    {
        $document = $this->documentEnBase();
        $this->structureEnBase($document, [
            $this->paragraphe('b_0001'),
            $this->paragraphe('b_0002'),
        ]);

        $service = new DocumentEditingService;
        $service->apply($document->id, 'delete_block', ['block_id' => 'b_0001'], 'chat:1', true);

        $historique = $service->undoHistory($document->id);

        $this->assertCount(1, $historique);
        $this->assertSame('delete_block', $historique[0]['tool']);
    }

    // -------------------------------------------------------------------------
    // Cohérence des schémas exposés au modèle
    // -------------------------------------------------------------------------

    public function test_les_schemas_correspondent_a_la_liste_blanche(): void
    {
        // L'énumération des types insérables vient de `BlockType::insertable()` :
        // une seule source de vérité, donc aucune divergence possible.
        $noms = EditToolSchemas::names();

        $this->assertSame(ToolWhitelist::editingToolNames(), array_slice($noms, 0, 5));
        $this->assertContains('undo_last_action', $noms);
    }

    public function test_chaque_schema_exige_un_document_id(): void
    {
        // Sans `document_id`, aucun tool ne peut s'exécuter : l'omettre des
        // paramètres requis ferait échouer chaque appel.
        foreach (EditToolSchemas::all() as $schema) {
            $parametres = $schema['function']['parameters'];

            $this->assertContains(
                'document_id',
                $parametres['required'],
                "Le tool « {$schema['function']['name']} » n'exige pas document_id."
            );
        }
    }

    public function test_le_schema_d_insertion_reprend_les_types_autorises(): void
    {
        $schemas = EditToolSchemas::all();
        $insertion = null;

        foreach ($schemas as $schema) {
            if ($schema['function']['name'] === 'insert_block') {
                $insertion = $schema;
                break;
            }
        }

        $this->assertNotNull($insertion);
        $this->assertSame(
            ToolWhitelist::insertableTypes(),
            $insertion['function']['parameters']['properties']['type']['enum']
        );
    }

    public function test_le_schema_de_regeneration_mentionne_le_seuil(): void
    {
        // Le modèle doit connaître le seuil : sinon il ne comprend pas pourquoi
        // une confirmation lui est demandée.
        $seuil = ToolWhitelist::confirmThreshold('regenerate_section') ?? 5;

        $trouve = false;

        foreach (EditToolSchemas::all() as $schema) {
            if ($schema['function']['name'] === 'regenerate_section') {
                $this->assertStringContainsString((string) $seuil, $schema['function']['description']);
                $trouve = true;
            }
        }

        $this->assertTrue($trouve);
    }

    // -------------------------------------------------------------------------
    // Câblage réel par le container
    // -------------------------------------------------------------------------

    public function test_le_chat_recoit_l_editeur_structurel_par_le_container(): void
    {
        // Point de câblage critique : `ChatToolsService` reçoit l'éditeur en
        // paramètre optionnel. Si le container ne l'injectait pas, les tools
        // d'édition répondraient « non disponible » en production — sans erreur
        // visible, et sans qu'aucun test unitaire ne s'en aperçoive.
        $service = $this->app->make(ChatToolsService::class);

        $reflexion = new \ReflectionClass($service);
        $propriete = $reflexion->getProperty('structuralEditor');
        $propriete->setAccessible(true);

        $this->assertInstanceOf(
            DocumentEditingService::class,
            $propriete->getValue($service),
            'Le container doit injecter DocumentEditingService dans ChatToolsService.'
        );
    }

    // -------------------------------------------------------------------------
    // Prompt système
    // -------------------------------------------------------------------------

    public function test_le_prompt_systeme_interdit_de_reecrire_les_donnees_de_tableau(): void
    {
        // Consigne explicite : un modèle à qui l'on demande « corrige ce tableau »
        // propose spontanément de reformuler les montants — c'est précisément ce
        // que le système interdit.
        $prompt = ChatEditAgent::systemPrompt();

        $this->assertStringContainsString('JAMAIS', $prompt);
        $this->assertStringContainsString('tableau', $prompt);
        $this->assertStringContainsString('montants', $prompt);
    }

    public function test_le_prompt_systeme_explique_la_difference_document_piece_jointe(): void
    {
        // C'est la confusion la plus probable : l'utilisateur dit « modifie mon
        // rapport » sans préciser s'il s'agit d'un document analysé.
        $prompt = ChatEditAgent::systemPrompt();

        $this->assertStringContainsString('document_id', $prompt);
        $this->assertStringContainsString('source_path', $prompt);
        $this->assertStringContainsString('pièce jointe', $prompt);
    }

    public function test_le_prompt_systeme_interdit_de_boucler_sur_une_confirmation(): void
    {
        $prompt = ChatEditAgent::systemPrompt();

        $this->assertStringContainsString('confirmed: true', $prompt);
        $this->assertStringContainsString('ne relance pas', mb_strtolower($prompt));
    }

    public function test_le_prompt_systeme_liste_les_outils_de_la_liste_blanche(): void
    {
        $prompt = ChatEditAgent::systemPrompt();

        foreach (ToolWhitelist::editingToolNames() as $nom) {
            $this->assertStringContainsString($nom, $prompt, "Le prompt ne mentionne pas « {$nom} ».");
        }
    }

    public function test_le_prompt_systeme_annonce_le_seuil_reel(): void
    {
        // Le seuil est injecté depuis la liste blanche : le prompt ne peut pas
        // annoncer une valeur que le code n'applique pas.
        $seuil = ToolWhitelist::confirmThreshold('regenerate_section') ?? 5;

        $this->assertStringContainsString((string) $seuil, ChatEditAgent::systemPrompt());
    }

    public function test_le_contexte_document_injecte_l_identifiant(): void
    {
        $contexte = ChatEditAgent::documentContext([
            'document_id' => 42,
            'block_count' => 120,
            'block_ids' => ['b_0001', 'b_0002'],
        ]);

        $this->assertStringContainsString('document_id = 42', $contexte);
        $this->assertStringContainsString('120 blocs', $contexte);
        $this->assertStringContainsString('b_0001', $contexte);
    }

    public function test_le_contexte_document_est_vide_sans_identifiant(): void
    {
        // Sans document ciblé, rien à injecter : le modèle demandera lequel.
        $this->assertSame('', ChatEditAgent::documentContext([]));
    }

    public function test_le_contexte_document_borne_la_liste_des_identifiants(): void
    {
        // Une structure de 700 blocs saturerait le contexte : c'est le poste de
        // coût le plus lourd d'une conversation longue.
        $ids = [];
        for ($i = 1; $i <= 200; $i++) {
            $ids[] = sprintf('b_%04d', $i);
        }

        $contexte = ChatEditAgent::documentContext(['document_id' => 1, 'block_ids' => $ids]);

        $this->assertStringContainsString('…', $contexte);
        $this->assertStringNotContainsString('b_0200', $contexte);
    }
}
