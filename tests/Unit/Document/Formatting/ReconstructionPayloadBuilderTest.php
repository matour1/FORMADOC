<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Formatting;

use App\Document\Formatting\ReconstructionPayloadBuilder;
use App\Document\Formatting\RenderedDocument;
use App\Document\Formatting\TemplateEngine;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\Fidelity;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests de l'adaptateur vers `DocumentReconstructor` (tâche R3.7).
 *
 * Le risque ici est un **décalage de format** : le reconstructeur lit des clés
 * précises (`rows`, `legends`, `image_data`…) et ignore silencieusement ce
 * qu'il ne reconnaît pas. Une clé erronée ne planterait donc pas — elle
 * produirait un document incomplet sans erreur. Ces tests verrouillent donc
 * chaque clé consommée.
 *
 * Second risque : que l'adaptateur **altère** le contenu au passage. Chaque
 * test de structure est doublé d'une vérification de contenu exact.
 */
class ReconstructionPayloadBuilderTest extends TestCase
{
    private function rendered(array $blocks, string $sourceType = 'docx'): RenderedDocument
    {
        return new RenderedDocument(
            documentId: 'doc-1',
            sourceType: $sourceType,
            blocks: $blocks,
            styles: [],
            template: TemplateEngine::defaultTemplate(),
            fidelity: Fidelity::Exact,
        );
    }

    private function table(array $grid, string $id = 'b_t1'): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            text: '',
            tableData: TableData::fromGrid($grid),
        );
    }

    // -------------------------------------------------------------------------
    // Structure attendue par le reconstructeur
    // -------------------------------------------------------------------------

    public function test_la_charge_utile_contient_les_cles_attendues_par_le_reconstructeur(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([]));

        $this->assertArrayHasKey('analysis', $payload);
        $this->assertArrayHasKey('gabarit', $payload);

        foreach (['body_complet', 'titres', 'sous_titres', 'legends', 'en_tetes', 'pieds_de_page'] as $key) {
            $this->assertArrayHasKey($key, $payload['analysis']);
        }
    }

    public function test_un_titre_produit_un_element_titre_avec_sa_profondeur(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Introduction', headingLevel: 2),
        ]));

        $element = $payload['analysis']['body_complet'][0];

        $this->assertSame('titre', $element['type']);
        $this->assertSame('Introduction', $element['text']);
        $this->assertSame(2, $element['depth']);
    }

    public function test_une_profondeur_de_titre_est_plafonnee_a_trois(): void
    {
        // Le reconstructeur accepte 1–3 : lui transmettre 7 produirait un titre
        // sans style ou une erreur d'écriture.
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Titre profond', headingLevel: 7),
        ]));

        $this->assertSame(3, $payload['analysis']['body_complet'][0]['depth']);
    }

    public function test_un_tableau_produit_des_lignes_de_cellules(): void
    {
        // Clé critique : le reconstructeur lit `rows[]['cells']`. Transmettre
        // `cells` à plat produirait un tableau vide, sans erreur.
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            $this->table([['Nom', 'Note'], ['Alice', '15']]),
        ]));

        $element = $payload['analysis']['body_complet'][0];

        $this->assertSame('tableau', $element['type']);
        $this->assertSame(
            [['cells' => ['Nom', 'Note']], ['cells' => ['Alice', '15']]],
            $element['rows']
        );
    }

    public function test_le_contenu_des_cellules_traverse_l_adaptateur_a_l_identique(): void
    {
        // Vérification par empreinte : même garantie que le restylage.
        $grid = [
            ['Désignation', 'Montant (FCFA)'],
            ['Fournitures — reçu n°42', '1 725 000'],
        ];

        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            $this->table($grid),
        ]));

        $rows = $payload['analysis']['body_complet'][0]['rows'];
        $rebuilt = array_map(
            static fn (array $row): array => $row['cells'],
            $rows
        );

        $this->assertSame($grid, $rebuilt);
    }

    public function test_les_caracteres_speciaux_des_cellules_sont_preserves(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            $this->table([['Ünïcödé'], ['«guillemets» & <balises>']]),
        ]));

        $rows = $payload['analysis']['body_complet'][0]['rows'];

        $this->assertSame('Ünïcödé', $rows[0]['cells'][0]);
        $this->assertSame('«guillemets» & <balises>', $rows[1]['cells'][0]);
    }

    public function test_une_legende_alimente_la_cle_legends_avec_numero_et_libelle(): void
    {
        // Clé `legends` (et non `legendes`) : c'est celle que lit le
        // reconstructeur pour construire la liste des figures.
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(
                blockId: 'b_001',
                type: BlockType::Caption,
                text: 'Figure 1 — Schéma du processus',
                category: BlockCategory::Figure,
                finalNumber: '1',
            ),
        ]));

        $legend = $payload['analysis']['legends'][0];

        $this->assertSame('figure', $legend['type']);
        $this->assertSame('1', $legend['number']);
        // Le reconstructeur recompose « Figure 1 : {label} » : garder le
        // préfixe produirait « Figure 1 : Figure 1 — Schéma ».
        $this->assertSame('Schéma du processus', $legend['label']);
    }

    public function test_une_legende_de_tableau_est_typee_tableau(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(
                blockId: 'b_001',
                type: BlockType::Caption,
                text: 'Tableau 2 : Répartition',
                category: BlockCategory::Table,
                finalNumber: '2',
            ),
        ]));

        $this->assertSame('tableau', $payload['analysis']['legends'][0]['type']);
    }

    public function test_un_libelle_sans_prefixe_de_numero_est_inchange(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(
                blockId: 'b_001',
                type: BlockType::Caption,
                text: 'Schéma du processus de traitement',
                category: BlockCategory::Figure,
            ),
        ]));

        $this->assertSame('Schéma du processus de traitement', $payload['analysis']['legends'][0]['label']);
    }

    public function test_une_image_transmet_son_binaire_et_son_extension(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build(
            $this->rendered([
                new Block(blockId: 'b_001', type: BlockType::Figure, imageRef: 'img_001.png'),
            ]),
            ['img_001.png' => ['data' => 'aGVsbG8=', 'extension' => 'png']],
        );

        $element = $payload['analysis']['body_complet'][0];

        $this->assertSame('image', $element['type']);
        $this->assertSame('aGVsbG8=', $element['image_data']);
        $this->assertSame('png', $element['image_extension']);
        $this->assertSame('img_001.png', $element['image_name']);
    }

    public function test_une_image_sans_binaire_produit_un_element_sans_donnees(): void
    {
        // Le binaire n'est jamais inventé : le reconstructeur affichera
        // « [Image: nom] », ce qui est préférable à une image vide.
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(blockId: 'b_001', type: BlockType::Figure, imageRef: 'img_001.png'),
        ]));

        $element = $payload['analysis']['body_complet'][0];

        $this->assertSame('image', $element['type']);
        $this->assertArrayNotHasKey('image_data', $element);
    }

    public function test_un_chemin_d_image_a_plusieurs_segments_est_reduit_a_son_nom(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(blockId: 'b_001', type: BlockType::Figure, imageRef: 'word/media/image3.jpeg'),
        ]));

        $element = $payload['analysis']['body_complet'][0];

        $this->assertSame('image3.jpeg', $element['image_name']);
        $this->assertSame('jpeg', $element['image_extension']);
    }

    public function test_le_binaire_d_une_image_traverse_l_adaptateur_a_l_identique(): void
    {
        // Critère d'acceptation R3 : « le binaire des images est strictement
        // identique (test de hash) ». On transporte un vrai PNG et on compare
        // l'empreinte du binaire décodé, pas seulement la chaîne base64.
        $binary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
        $this->assertIsString($binary);

        $encoded = base64_encode($binary);
        $payload = (new ReconstructionPayloadBuilder)->build(
            $this->rendered([new Block(blockId: 'b_001', type: BlockType::Figure, imageRef: 'logo.png')]),
            ['logo.png' => ['data' => $encoded, 'extension' => 'png']],
        );

        $data = $payload['analysis']['body_complet'][0]['image_data'];

        // Base64 inchangé ET binaire décodé identique octet pour octet.
        $this->assertSame($encoded, $data);
        $this->assertSame(hash('sha256', $binary), hash('sha256', base64_decode($data, true)));
    }

    public function test_le_binaire_n_est_jamais_reencode_par_l_adaptateur(): void
    {
        // Contre-épreuve : un adaptateur qui décoderait puis réencoderait le
        // binaire pourrait le corrompre silencieusement (perte de métadonnées,
        // changement de compression). On vérifie que la chaîne est reprise telle
        // quelle, sans passer par une transformation.
        $encoded = 'QUJDREVGR0g='; // « ABCDEFGH »

        $payload = (new ReconstructionPayloadBuilder)->build(
            $this->rendered([new Block(blockId: 'b_001', type: BlockType::Figure, imageRef: 'f.bin')]),
            ['f.bin' => ['data' => $encoded, 'extension' => 'bin']],
        );

        $element = $payload['analysis']['body_complet'][0];

        $this->assertSame($encoded, $element['image_data']);
        $this->assertSame('bin', $element['image_extension']);
    }

    public function test_les_en_tetes_et_pieds_sortent_du_corps(): void
    {
        // Sinon leur texte apparaîtrait au milieu du document, en plus de la
        // zone de page.
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(blockId: 'b_001', type: BlockType::Header, text: 'En-tête'),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte'),
            new Block(blockId: 'b_003', type: BlockType::Footer, text: 'Pied'),
        ]));

        $types = array_map(
            static fn (array $element): string => (string) $element['type'],
            $payload['analysis']['body_complet']
        );

        $this->assertSame(['texte'], $types);
        $this->assertSame('En-tête', $payload['analysis']['en_tetes'][0]['texte']);
        $this->assertSame('Pied', $payload['analysis']['pieds_de_page'][0]['texte']);
    }

    public function test_un_renvoi_croise_est_rendu_comme_du_texte(): void
    {
        // Il sera résolu en numéro concret par R4, mais doit déjà occuper sa
        // place dans le flux.
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(blockId: 'b_001', type: BlockType::CrossRef, text: 'voir la figure 3'),
        ]));

        $this->assertSame('texte', $payload['analysis']['body_complet'][0]['type']);
        $this->assertSame('voir la figure 3', $payload['analysis']['body_complet'][0]['text']);
    }

    public function test_l_ordre_du_document_est_preserve(): void
    {
        // C'est l'ordre qui détermine la numérotation (R4) : le regrouper par
        // type produirait des numéros faux.
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Titre', headingLevel: 1),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte'),
            $this->table([['A']], 'b_003'),
            new Block(blockId: 'b_004', type: BlockType::Paragraph, text: 'Suite'),
        ]));

        $types = array_map(
            static fn (array $element): string => (string) $element['type'],
            $payload['analysis']['body_complet']
        );

        $this->assertSame(['titre', 'texte', 'tableau', 'texte'], $types);
    }

    public function test_la_position_est_indexee_dans_l_ordre_du_document(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Titre', headingLevel: 1),
            new Block(blockId: 'b_002', type: BlockType::Paragraph, text: 'Texte'),
        ]));

        $this->assertSame(0, $payload['analysis']['body_complet'][0]['position']['element_index']);
        $this->assertSame(1, $payload['analysis']['body_complet'][1]['position']['element_index']);
    }

    public function test_le_gabarit_est_repris_de_la_description_de_rendu(): void
    {
        $rendered = $this->rendered([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte'),
        ]);

        $payload = (new ReconstructionPayloadBuilder)->build($rendered);

        $this->assertSame($rendered->template, $payload['gabarit']);
        $this->assertSame('Times New Roman', $payload['gabarit']['police']);
    }

    public function test_les_titres_sont_aussi_listes_pour_le_repli_du_corps(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([
            new Block(blockId: 'b_001', type: BlockType::Heading, text: 'Un', headingLevel: 1),
            new Block(blockId: 'b_002', type: BlockType::Heading, text: 'Deux', headingLevel: 2),
        ]));

        $titres = $payload['analysis']['titres'];

        $this->assertCount(2, $titres);
        $this->assertSame('Un', $titres[0]['texte']);
        $this->assertSame(2, $titres[1]['niveau']);
    }

    public function test_un_document_vide_produit_une_charge_utile_valide(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build($this->rendered([]));

        $this->assertSame([], $payload['analysis']['body_complet']);
        $this->assertSame([], $payload['analysis']['titres']);
        $this->assertSame([], $payload['analysis']['legends']);
        $this->assertSame(0, $payload['analysis']['meta']['block_count']);
    }

    public function test_la_tracabilite_indique_la_source_et_la_fidelite(): void
    {
        $payload = (new ReconstructionPayloadBuilder)->build(
            new RenderedDocument(
                documentId: 'doc-1',
                sourceType: 'ocr',
                blocks: [],
                styles: [],
                template: [],
                fidelity: Fidelity::Reconstructed,
            )
        );

        $this->assertSame('ocr', $payload['analysis']['meta']['source_type']);
        $this->assertSame('reconstructed', $payload['analysis']['meta']['fidelity']);
    }
}
