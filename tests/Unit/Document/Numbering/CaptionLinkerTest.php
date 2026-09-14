<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Numbering;

use App\Document\Numbering\CaptionLinker;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests du rattachement des légendes à leur porteur.
 *
 * Le corpus mesuré porte **861 légendes dont aucune n'est rattachée** : ce
 * composant crée donc l'intégralité des liens. Le risque principal est de
 * fabriquer une liaison fausse — un renvoi vers un mauvais tableau est pire
 * qu'un renvoi absent, car il est invisible pour l'auteur.
 */
class CaptionLinkerTest extends TestCase
{
    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: 'doc-1', sourceType: 'docx', blocks: $blocks);
    }

    private function figure(string $id, ?string $number = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Figure,
            imageRef: $id.'.png',
            originalNumber: $number,
        );
    }

    private function table(string $id, ?string $number = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            tableData: TableData::fromGrid([['A'], ['B']]),
            originalNumber: $number,
        );
    }

    private function caption(string $id, BlockCategory $category, string $number): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $category->keyword().' '.$number.' : Légende',
            category: $category,
            originalNumber: $number,
        );
    }

    private function paragraph(string $id): Block
    {
        return new Block(blockId: $id, type: BlockType::Paragraph, text: 'Texte');
    }

    // -------------------------------------------------------------------------
    // Adjacence stricte
    // -------------------------------------------------------------------------

    public function test_une_legende_sous_sa_figure_est_rattachee(): void
    {
        // Disposition la plus courante dans un rapport : figure puis légende.
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]));

        $legende = $result['document']->blockById('b_002');

        $this->assertSame('b_001', $legende->linkedBlockId);
        $this->assertSame(1, $result['linked']);
    }

    public function test_une_legende_au_dessus_de_sa_figure_est_rattachee(): void
    {
        // Certains auteurs placent la légende avant la figure.
        $result = (new CaptionLinker)->link($this->document([
            $this->caption('b_001', BlockCategory::Figure, '1'),
            $this->figure('b_002'),
        ]));

        $this->assertSame('b_002', $result['document']->blockById('b_001')->linkedBlockId);
    }

    public function test_le_porteur_reference_sa_legende_en_retour(): void
    {
        // Liaison bidirectionnelle : c'est elle qui permet au rendu de garder
        // les deux solidaires (FigureFlowFormatter en R3).
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]));

        $this->assertSame('b_002', $result['document']->blockById('b_001')->linkedBlockId);
    }

    public function test_une_legende_de_tableau_se_rattache_au_tableau(): void
    {
        $result = (new CaptionLinker)->link($this->document([
            $this->table('b_001'),
            $this->caption('b_002', BlockCategory::Table, '1'),
        ]));

        $this->assertSame('b_001', $result['document']->blockById('b_002')->linkedBlockId);
    }

    public function test_une_legende_ne_se_rattache_pas_a_une_autre_categorie(): void
    {
        // « Tableau 1 » ne doit pas se rattacher à la figure voisine : le
        // mot-clé de la légende fait autorité sur la proximité.
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Table, '1'),
            $this->table('b_003'),
        ]));

        // La légende est adjacente à la figure ET au tableau : la catégorie
        // impose le tableau.
        $this->assertSame('b_003', $result['document']->blockById('b_002')->linkedBlockId);
    }

    // -------------------------------------------------------------------------
    // Fenêtre bornée
    // -------------------------------------------------------------------------

    public function test_un_paragraphe_intercale_conserve_le_rattachement(): void
    {
        // Cas fréquent : un commentaire s'intercale entre la figure et sa
        // légende. Refuser la liaison perdrait 24 légendes mesurées.
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->paragraph('b_002'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
        ]));

        $this->assertSame('b_001', $result['document']->blockById('b_003')->linkedBlockId);
        // La liaison est moins sûre : elle doit être signalée.
        $this->assertContains('b_003', $result['low_confidence']);
    }

    public function test_un_seul_porteur_a_distance_deux_est_retenu(): void
    {
        // Un paragraphe de commentaire s'intercale entre la figure et sa légende.
        // Mesure du corpus : ce cas représente 24 légendes — le perdre serait
        // une perte sèche.
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->paragraph('b_002'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
        ]));

        $this->assertSame('b_001', $result['document']->blockById('b_003')->linkedBlockId);
    }

    public function test_au_dela_de_deux_blocs_la_proximite_ne_suffit_plus(): void
    {
        // Mesure du corpus : 43 % des légendes (366) sont à distance 3 ou plus.
        // Élargir la fenêtre pour « en rattacher plus » produirait surtout des
        // liaisons fausses — on préfère les signaler comme non rattachées.
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->paragraph('b_002'),
            $this->paragraph('b_003'),
            $this->caption('b_004', BlockCategory::Figure, '1'),
        ]));

        $this->assertNull($result['document']->blockById('b_004')->linkedBlockId);
        $this->assertContains('b_004', $result['orphans']);
    }

    public function test_deux_porteurs_proches_ne_sont_pas_tranches(): void
    {
        // Deux figures équidistantes : impossible de décider sans inventer.
        // On ne devine pas — on signale.
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->paragraph('b_002'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
            $this->paragraph('b_004'),
            $this->figure('b_005'),
        ]));

        $this->assertNull($result['document']->blockById('b_003')->linkedBlockId);
        $this->assertContains('b_003', $result['orphans']);
    }

    public function test_un_porteur_trop_eloigne_n_est_pas_rattache(): void
    {
        // Au-delà de la fenêtre, la proximité ne veut plus rien dire.
        $blocks = [$this->figure('b_001')];

        for ($i = 2; $i <= 8; $i++) {
            $blocks[] = $this->paragraph('b_00'.$i);
        }

        $blocks[] = $this->caption('b_009', BlockCategory::Figure, '1');

        $result = (new CaptionLinker)->link($this->document($blocks));

        $this->assertNull($result['document']->blockById('b_009')->linkedBlockId);
        $this->assertContains('b_009', $result['orphans']);
    }

    public function test_une_legende_sans_porteur_dans_le_document_reste_orpheline(): void
    {
        // Cas mesuré : 861 légendes pour 318 porteurs. Une légende seule doit
        // rester non rattachée, jamais rattachée d'office.
        $result = (new CaptionLinker)->link($this->document([
            $this->paragraph('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '12'),
        ]));

        $this->assertNull($result['document']->blockById('b_002')->linkedBlockId);
        $this->assertContains('b_002', $result['orphans']);
    }

    // -------------------------------------------------------------------------
    // Cas limites
    // -------------------------------------------------------------------------

    public function test_un_document_sans_legende_ne_produit_aucun_rattachement(): void
    {
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->paragraph('b_002'),
        ]));

        $this->assertSame(0, $result['linked']);
        $this->assertSame([], $result['orphans']);
    }

    public function test_un_document_vide_ne_provoque_pas_d_erreur(): void
    {
        $result = (new CaptionLinker)->link($this->document([]));

        $this->assertSame(0, $result['linked']);
        $this->assertSame(0, $result['document']->count());
    }

    public function test_les_legendes_consecutives_se_rattachent_chacune_a_son_porteur(): void
    {
        // Disposition courante : deux figures puis leurs deux légendes.
        // L'appariement rang par rang doit faire correspondre Figure 1 ↔ Légende 1
        // et Figure 2 ↔ Légende 2 — sans quoi un lecteur serait envoyé au mauvais
        // schéma, défaut invisible pour l'auteur.
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->figure('b_002'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
            $this->caption('b_004', BlockCategory::Figure, '2'),
        ]));

        $this->assertSame('b_001', $result['document']->blockById('b_003')->linkedBlockId);
        $this->assertSame('b_002', $result['document']->blockById('b_004')->linkedBlockId);
    }

    public function test_une_legende_se_rattache_a_la_figure_immediatement_au_dessus(): void
    {
        // Disposition « Figure 1, Figure 2, Légende 1 » : la légende décrit la
        // figure directement au-dessus d'elle, pas la plus ancienne. Lier à
        // Figure 1 enverrait le lecteur au mauvais schéma.
        $result = (new CaptionLinker)->link($this->document([
            $this->figure('b_001'),
            $this->figure('b_002'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
        ]));

        $this->assertSame('b_002', $result['document']->blockById('b_003')->linkedBlockId);
        // Figure 1 reste sans légende et sera signalée comme telle.
        $this->assertNull($result['document']->blockById('b_001')->linkedBlockId);
    }

    public function test_les_statistiques_refletent_le_rattachement(): void
    {
        $linker = new CaptionLinker;
        $linker->link($this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '1'),
            $this->table('b_003'),
            $this->caption('b_004', BlockCategory::Table, '1'),
        ]));

        $stats = $linker->statistics();

        // Deux légendes, chacune rattachée à son porteur de même catégorie.
        $this->assertSame(2, $stats['linked']);
        $this->assertSame(0, $stats['orphans']);
    }

    public function test_une_legende_sans_porteur_de_sa_categorie_compte_comme_orpheline(): void
    {
        $linker = new CaptionLinker;
        $linker->link($this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Table, '9'),
        ]));

        // Aucun tableau dans le document : la légende de tableau ne peut pas
        // être rattachée à la figure voisine — la catégorie fait autorité.
        $stats = $linker->statistics();

        $this->assertSame(0, $stats['linked']);
        $this->assertSame(1, $stats['orphans']);
    }

    public function test_le_document_d_origine_n_est_pas_modifie(): void
    {
        // Immuabilité : la méthode renvoie un nouveau document, l'original
        // reste intact (socle des snapshots du chat).
        $document = $this->document([
            $this->figure('b_001'),
            $this->caption('b_002', BlockCategory::Figure, '1'),
        ]);

        (new CaptionLinker)->link($document);

        $this->assertNull($document->blockById('b_002')->linkedBlockId);
    }
}
