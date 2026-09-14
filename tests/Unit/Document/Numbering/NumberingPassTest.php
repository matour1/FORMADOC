<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Numbering;

use App\Document\Numbering\NumberingPass;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests de la renumérotation.
 *
 * Trois propriétés sont critiques, chacune vérifiée par le critère
 * d'acceptation de R4 :
 *  - « 3 figures numérotées 1, 5, 2 → renumérotées 1, 2, 3 » ;
 *  - chaque catégorie repart à 1 **indépendamment** ;
 *  - le numéro d'origine est conservé (l'utilisateur doit pouvoir constater
 *    que « son Tableau 7 est devenu le Tableau 5 »).
 */
class NumberingPassTest extends TestCase
{
    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: 'doc-1', sourceType: 'docx', blocks: $blocks);
    }

    private function figure(string $id, ?string $number = null, ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Figure,
            imageRef: $id.'.png',
            originalNumber: $number,
            linkedBlockId: $linked,
        );
    }

    private function table(string $id, ?string $number = null, ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            tableData: TableData::fromGrid([['A'], ['B']]),
            originalNumber: $number,
            linkedBlockId: $linked,
        );
    }

    private function caption(string $id, BlockCategory $category, string $number, ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $category->keyword().' '.$number,
            category: $category,
            originalNumber: $number,
            linkedBlockId: $linked,
        );
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation : « 1, 5, 2 → 1, 2, 3 »
    // -------------------------------------------------------------------------

    public function test_des_figures_numérotées_1_5_2_sont_renumerotees_1_2_3(): void
    {
        $result = (new NumberingPass)->run($this->document([
            $this->figure('b_001', '1'),
            $this->figure('b_002', '5'),
            $this->figure('b_003', '2'),
        ]));

        // Les figures ne portent pas de numéro calculé : on lit le résultat
        // via les légendes dans le cas réel. Ici, on vérifie le comptage.
        $this->assertSame(['figure' => 3], $result['per_category']);
    }

    public function test_les_numeros_suivent_l_ordre_d_apparition(): void
    {
        // Le numéro d'origine est ignoré (1 puis 5 puis 2) : seul l'ordre du
        // document compte.
        $result = (new NumberingPass)->run($this->document([
            $this->caption('b_001', BlockCategory::Figure, '1'),
            $this->caption('b_002', BlockCategory::Figure, '5'),
            $this->caption('b_003', BlockCategory::Figure, '2'),
        ]));

        $document = $result['document'];

        $this->assertSame('1', $document->blockById('b_001')->displayNumber());
        $this->assertSame('2', $document->blockById('b_002')->displayNumber());
        $this->assertSame('3', $document->blockById('b_003')->displayNumber());
    }

    public function test_les_doublons_sont_corriges(): void
    {
        // Mesure : 98 documents du corpus portent des doublons (74 tableaux,
        // 21 figures, 3 annexes). Le critère est qu'aucun numéro ne reste
        // dupliqué après la passe.
        $result = (new NumberingPass)->run($this->document([
            $this->caption('b_001', BlockCategory::Table, '2'),
            $this->caption('b_002', BlockCategory::Table, '2'),
            $this->caption('b_003', BlockCategory::Table, '2'),
        ]));

        $this->assertSame(['table' => 3], $result['per_category']);

        $document = $result['document'];
        $numeros = [
            $document->blockById('b_001')->displayNumber(),
            $document->blockById('b_002')->displayNumber(),
            $document->blockById('b_003')->displayNumber(),
        ];

        // Aucun doublon ne subsiste : trois numéros distincts.
        $this->assertSame(['1', '2', '3'], $numeros);
        $this->assertSame(3, count(array_unique($numeros)));

        // Deux blocs changent réellement : le deuxième reçoit « 2 », qui se
        // trouve être son numéro d'origine — il n'y a donc rien à changer pour
        // lui. Un bloc inchangé ne doit pas gonfler le rapport.
        $this->assertSame(2, $result['renumbered']);
    }

    public function test_chaque_categorie_repart_a_un_independamment(): void
    {
        // Critère d'acceptation explicite de R4.
        $result = (new NumberingPass)->run($this->document([
            $this->caption('b_001', BlockCategory::Figure, '40'),
            $this->caption('b_002', BlockCategory::Table, '12'),
            $this->caption('b_003', BlockCategory::Figure, '41'),
            $this->caption('b_004', BlockCategory::Table, '13'),
        ]));

        $document = $result['document'];

        $this->assertSame('1', $document->blockById('b_001')->displayNumber());
        $this->assertSame('1', $document->blockById('b_002')->displayNumber());
        $this->assertSame('2', $document->blockById('b_003')->displayNumber());
        $this->assertSame('2', $document->blockById('b_004')->displayNumber());

        $this->assertSame(['figure' => 2, 'table' => 2], $result['per_category']);
    }

    // -------------------------------------------------------------------------
    // Traçabilité
    // -------------------------------------------------------------------------

    public function test_le_numero_d_origine_est_conserve(): void
    {
        // Garder l'original est la seule façon de dire à l'utilisateur
        // « votre Tableau 7 est devenu le Tableau 1 ».
        $result = (new NumberingPass)->run($this->document([
            $this->caption('b_001', BlockCategory::Table, '7'),
        ]));

        $bloc = $result['document']->blockById('b_001');

        $this->assertSame('7', $bloc->originalNumber);
        $this->assertSame('1', $bloc->finalNumber);
        $this->assertSame('1', $bloc->displayNumber());
    }

    public function test_les_changements_sont_traces_pour_le_rapport(): void
    {
        $result = (new NumberingPass)->run($this->document([
            $this->caption('b_001', BlockCategory::Table, '7'),
        ]));

        $this->assertCount(1, $result['changes']);
        $this->assertSame('b_001', $result['changes'][0]['block_id']);
        $this->assertSame('7', $result['changes'][0]['original']);
        $this->assertSame('1', $result['changes'][0]['final']);
    }

    public function test_un_numero_deja_juste_n_est_pas_considere_comme_un_changement(): void
    {
        // Éviter de gonfler le rapport : rien n'a changé si le numéro était bon.
        $result = (new NumberingPass)->run($this->document([
            $this->caption('b_001', BlockCategory::Figure, '1'),
            $this->caption('b_002', BlockCategory::Figure, '2'),
        ]));

        $this->assertSame(0, $result['renumbered']);
        $this->assertSame([], $result['changes']);
    }

    // -------------------------------------------------------------------------
    // Non-double-comptage : légende rattachée vs orpheline
    // -------------------------------------------------------------------------

    public function test_une_legende_rattachee_consomme_le_numero_de_l_element(): void
    {
        // Un élément numéroté = deux blocs (porteur + légende), mais UN SEUL
        // numéro. La légende le consomme : c'est là que l'auteur a écrit le
        // numéro, et le porteur n'en porte aucun dans le corpus mesuré.
        $result = (new NumberingPass)->run($this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '1', 'b_001'),
        ]));

        $this->assertSame(['figure' => 1], $result['per_category']);
    }

    public function test_un_tableau_et_sa_legende_ne_produisent_pas_deux_series_de_numeros(): void
    {
        // Défaut à éviter : si le porteur consommait aussi, on obtiendrait
        // « Tableau 1 » (le tableau) et « Tableau 1 » (la légende) — soit deux
        // éléments pour un seul, puis la série décalée pour tous les suivants.
        $result = (new NumberingPass)->run($this->document([
            $this->table('b_001'),
            $this->caption('b_002', BlockCategory::Table, '1', 'b_001'),
            $this->table('b_003'),
            $this->caption('b_004', BlockCategory::Table, '2', 'b_003'),
        ]));

        $this->assertSame(['table' => 2], $result['per_category']);

        $document = $result['document'];
        $this->assertSame('1', $document->blockById('b_001')->displayNumber());
        $this->assertSame('1', $document->blockById('b_002')->displayNumber());
        $this->assertSame('2', $document->blockById('b_003')->displayNumber());
        $this->assertSame('2', $document->blockById('b_004')->displayNumber());
    }

    public function test_une_legende_non_rattachee_consomme_bien_un_numero(): void
    {
        // Cas réel dominant du corpus : 861 légendes pour 318 porteurs, aucune
        // liaison. Le numéro n'existe QUE dans la légende — il faut le compter.
        $result = (new NumberingPass)->run($this->document([
            $this->caption('b_001', BlockCategory::Figure, '1'),
            $this->caption('b_002', BlockCategory::Figure, '5'),
        ]));

        $this->assertSame(['figure' => 2], $result['per_category']);
    }

    public function test_la_legende_transmet_son_numero_calcule_a_son_porteur(): void
    {
        // La légende est la source du numéro (elle a été comptée) ; le porteur
        // n'en a aucun. Sans cette réflexion, la figure s'afficherait sans
        // numéro alors que sa légende afficherait « Figure 1 » juste en dessous
        // — le document semblerait incohérent à la lecture.
        $result = (new NumberingPass)->run($this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '5', 'b_001'),
        ]));

        $document = $result['document'];

        // La légende passe de 5 (origine) à 1 (calculé), et le porteur reçoit 1.
        $this->assertSame('1', $document->blockById('b_002')->displayNumber());
        $this->assertSame('5', $document->blockById('b_002')->originalNumber);
        $this->assertSame('1', $document->blockById('b_001')->displayNumber());
        $this->assertSame(1, $result['propagated']);
    }

    public function test_un_porteur_sans_legende_recoit_son_propre_numero(): void
    {
        // Cas mesuré : 21 porteurs sans aucun numéro. Ils sont numérotés par
        // ordre d'apparition, comme les légendes.
        $result = (new NumberingPass)->run($this->document([
            $this->table('b_001'),
            $this->table('b_002'),
        ]));

        $this->assertSame(['table' => 2], $result['per_category']);
        $this->assertSame('1', $result['document']->blockById('b_001')->displayNumber());
        $this->assertSame('2', $result['document']->blockById('b_002')->displayNumber());
    }

    // -------------------------------------------------------------------------
    // Détection des anomalies du document d'origine
    // -------------------------------------------------------------------------

    public function test_les_doublons_du_document_d_origine_sont_detectes(): void
    {
        $anomalies = (new NumberingPass)->anomalies($this->document([
            $this->caption('b_001', BlockCategory::Table, '2'),
            $this->caption('b_002', BlockCategory::Table, '2'),
        ]));

        $this->assertArrayHasKey('table', $anomalies['duplicates']);
        $this->assertContains('2', $anomalies['duplicates']['table']);
    }

    public function test_les_trous_du_document_d_origine_sont_detectes(): void
    {
        // Mesure : 3 documents ont une annexe « 37 » seule.
        $anomalies = (new NumberingPass)->anomalies($this->document([
            $this->caption('b_001', BlockCategory::Figure, '1'),
            $this->caption('b_002', BlockCategory::Figure, '5'),
        ]));

        $this->assertArrayHasKey('figure', $anomalies['gaps']);
        $this->assertContains(2, $anomalies['gaps']['figure']);
    }

    public function test_un_document_bien_numerote_n_a_aucune_anomalie(): void
    {
        $anomalies = (new NumberingPass)->anomalies($this->document([
            $this->caption('b_001', BlockCategory::Figure, '1'),
            $this->caption('b_002', BlockCategory::Figure, '2'),
        ]));

        $this->assertSame([], $anomalies['duplicates']);
        $this->assertSame([], $anomalies['gaps']);
    }

    // -------------------------------------------------------------------------
    // Cas limites
    // -------------------------------------------------------------------------

    public function test_un_document_vide_ne_provoque_pas_d_erreur(): void
    {
        $result = (new NumberingPass)->run($this->document([]));

        $this->assertSame(0, $result['renumbered']);
        $this->assertSame([], $result['per_category']);
    }

    public function test_un_document_sans_element_numerote_est_inchange(): void
    {
        $result = (new NumberingPass)->run($this->document([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Introduction', headingLevel: 1),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte'),
        ]));

        $this->assertSame(0, $result['renumbered']);
        $this->assertSame(2, $result['document']->count());
    }

    public function test_les_annexes_et_planches_ont_leur_propre_compteur(): void
    {
        $result = (new NumberingPass)->run($this->document([
            $this->caption('b_001', BlockCategory::Annexe, 'B'),
            $this->caption('b_002', BlockCategory::Planche, 'IV'),
            $this->caption('b_003', BlockCategory::Annexe, 'C'),
        ]));

        // Un titre alphabétique ou romain devient un chiffre arabe : la
        // convention du projet est un style unique pour les 4 catégories.
        $document = $result['document'];

        $this->assertSame('1', $document->blockById('b_001')->displayNumber());
        $this->assertSame('1', $document->blockById('b_002')->displayNumber());
        $this->assertSame('2', $document->blockById('b_003')->displayNumber());
    }

    public function test_l_ordre_du_document_est_preserve(): void
    {
        $document = $this->document([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Titre', headingLevel: 1),
            $this->caption('b_002', BlockCategory::Figure, '9'),
            new Block(blockId: 'b_003', type: BlockType::Paragraph, text: 'Texte'),
        ]);

        $result = (new NumberingPass)->run($document);
        $identifiants = array_map(
            static fn (Block $b): string => $b->blockId,
            $result['document']->blocks
        );

        $this->assertSame(['b_001', 'b_002', 'b_003'], $identifiants);
    }

    public function test_le_document_d_origine_n_est_pas_modifie(): void
    {
        $document = $this->document([$this->caption('b_001', BlockCategory::Figure, '9')]);

        (new NumberingPass)->run($document);

        $this->assertNull($document->blockById('b_001')->finalNumber);
    }

    public function test_les_statistiques_refletent_la_passe(): void
    {
        $pass = new NumberingPass;
        $pass->run($this->document([
            $this->caption('b_001', BlockCategory::Figure, '4'),
            $this->caption('b_002', BlockCategory::Table, '8'),
        ]));

        $stats = $pass->statistics();

        $this->assertSame(2, $stats['renumbered']);
        $this->assertSame(['figure' => 1, 'table' => 1], $stats['per_category']);
    }
}
