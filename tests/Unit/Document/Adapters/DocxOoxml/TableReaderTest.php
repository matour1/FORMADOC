<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Adapters\DocxOoxml;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use App\Document\Adapters\DocxOoxml\PackageReader;
use App\Document\Adapters\DocxOoxml\ParagraphReader;
use App\Document\Adapters\DocxOoxml\StyleReader;
use App\Document\Adapters\DocxOoxml\TableReader;
use App\Document\Adapters\DocxOoxml\XmlLoader;
use App\Document\Structure\TableData;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Tests du lecteur de tableaux Word.
 *
 * Enjeu central : le contenu des cellules est lu EXACTEMENT, jamais normalisé
 * au-delà des caractères de contrôle, et les fusions sont interprétées
 * correctement (une cellule fusionnée horizontalement n'apparaît pas dans
 * l'XML des cellules suivantes).
 */
class TableReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            DocxFixture::cleanup($path);
        }
        $this->fixtures = [];

        parent::tearDown();
    }

    /**
     * Lit un tableau depuis un XML de corps de document.
     *
     * Passe par le paquet complet pour vérifier le chemin réel de lecture.
     *
     * @return array{
     *     table_data: TableData,
     *     has_header: bool,
     *     has_merges: bool,
     *     column_widths: array<int, float>,
     *     style_id: null|string,
     *     row_count: int
     * }
     */
    private function readTable(string $tableXml): array
    {
        $path = DocxFixture::create($tableXml);
        $this->fixtures[] = $path;

        $package = new PackageReader($path);
        $package->open();

        $document = XmlLoader::load(
            $package->readOrFail(PackageReader::MAIN_DOCUMENT),
            PackageReader::MAIN_DOCUMENT
        );
        $body = XmlLoader::firstDescendant($document->documentElement, 'w:body');
        $table = XmlLoader::firstDescendant($body, 'w:tbl');

        $reader = new TableReader(new ParagraphReader(StyleReader::fromPackage($package)));
        $result = $reader->read($table);

        $package->close();

        return $result;
    }

    // -------------------------------------------------------------------------
    // Structure de base
    // -------------------------------------------------------------------------

    public function test_un_tableau_simple_est_lu_avec_ses_dimensions(): void
    {
        $result = $this->readTable(DocxFixture::table([
            ['DATES', 'TACHES'],
            ['01/04/2026', 'Installation'],
        ]));

        $this->assertSame(2, $result['table_data']->rows);
        $this->assertSame(2, $result['table_data']->cols);
        $this->assertSame(2, $result['row_count']);
    }

    public function test_le_contenu_des_cellules_est_conserve_a_l_identique(): void
    {
        // Règle absolue : le contenu d'un tableau n'est JAMAIS reformulé.
        // Accents, espaces, ponctuation et chiffres doivent sortir tels quels.
        $result = $this->readTable(DocxFixture::table([
            ['Dénomination sociale', 'RELAIS MULTISERVICES SARL'],
            ['Téléphone', '690 91 98 83/677 87 95 63'],
            ['Siège social', 'TAMDJA, immeuble Beauty'],
        ]));

        $data = $result['table_data'];

        $this->assertSame('Dénomination sociale', $data->cell(0, 0));
        $this->assertSame('RELAIS MULTISERVICES SARL', $data->cell(0, 1));
        $this->assertSame('690 91 98 83/677 87 95 63', $data->cell(1, 1));
        $this->assertSame('TAMDJA, immeuble Beauty', $data->cell(2, 1));
    }

    public function test_une_cellule_vide_est_lue_comme_chaine_vide(): void
    {
        $result = $this->readTable(DocxFixture::table([
            ['A', ''],
            ['', 'D'],
        ]));

        $data = $result['table_data'];

        $this->assertSame('', $data->cell(0, 1));
        $this->assertSame('', $data->cell(1, 0));
        $this->assertSame('D', $data->cell(1, 1));
    }

    public function test_un_tableau_a_une_colonne_et_plusieurs_lignes_est_lu(): void
    {
        $result = $this->readTable(DocxFixture::table([
            ['Étape 1'],
            ['Étape 2'],
            ['Étape 3'],
        ]));

        $this->assertSame(3, $result['table_data']->rows);
        $this->assertSame(1, $result['table_data']->cols);
    }

    public function test_les_espaces_insecables_sont_uniformises_sans_toucher_au_contenu(): void
    {
        // Word insère des espaces insécables : ils doivent devenir des espaces
        // normales, sans altérer le texte lui-même.
        $table = '<w:tbl><w:tblGrid><w:gridCol w:w="1000"/></w:tblGrid><w:tr><w:tc><w:p><w:r>'
            .'<w:t>1 250 000 FCFA</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';

        $result = $this->readTable($table);

        $this->assertSame('1 250 000 FCFA', $result['table_data']->cell(0, 0));
    }

    // -------------------------------------------------------------------------
    // Plusieurs paragraphes dans une cellule
    // -------------------------------------------------------------------------

    public function test_une_cellule_avec_plusieurs_paragraphes_les_conserve(): void
    {
        // Cas réel : une cellule « Tâches » contient une liste d'actions.
        $table = '<w:tbl><w:tblGrid><w:gridCol w:w="1000"/></w:tblGrid><w:tr><w:tc>'
            .'<w:p><w:r><w:t>Installation</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Configuration</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Recette</w:t></w:r></w:p>'
            .'</w:tc></w:tr></w:tbl>';

        $result = $this->readTable($table);

        $this->assertSame("Installation\nConfiguration\nRecette", $result['table_data']->cell(0, 0));
    }

    public function test_les_paragraphes_vides_en_fin_de_cellule_sont_ignores(): void
    {
        // Word ajoute souvent un paragraphe vide final comme espacement.
        $table = '<w:tbl><w:tblGrid><w:gridCol w:w="1000"/></w:tblGrid><w:tr><w:tc>'
            .'<w:p><w:r><w:t>Contenu</w:t></w:r></w:p>'
            .'<w:p/>'
            .'</w:tc></w:tr></w:tbl>';

        $result = $this->readTable($table);

        $this->assertSame('Contenu', $result['table_data']->cell(0, 0));
    }

    public function test_le_texte_reparti_sur_plusieurs_runs_est_reconstitue(): void
    {
        // Cas réel observé : Word fragmente « Du 01/04/2026 » en trois runs
        // (corrections de frappe). La concaténation doit être exacte.
        $table = '<w:tbl><w:tblGrid><w:gridCol w:w="1000"/></w:tblGrid><w:tr><w:tc><w:p>'
            .'<w:r><w:t xml:space="preserve">Du </w:t></w:r>'
            .'<w:r><w:t>01/04/202</w:t></w:r>'
            .'<w:r><w:t>6</w:t></w:r>'
            .'</w:p></w:tc></w:tr></w:tbl>';

        $result = $this->readTable($table);

        $this->assertSame('Du 01/04/2026', $result['table_data']->cell(0, 0));
    }

    // -------------------------------------------------------------------------
    // Fusions horizontales (gridSpan)
    // -------------------------------------------------------------------------

    public function test_une_fusion_horizontale_est_detectee(): void
    {
        // Représentation réaliste : une cellule `gridSpan=2` remplace 2 cellules,
        // les suivantes sont ABSENTES de l'XML.
        $result = $this->readTable(DocxFixture::tableWithGridSpan([
            ['Titre fusionné'],
            ['A', 'B'],
        ], 2, 2));

        $this->assertTrue($result['has_merges']);
        $this->assertTrue($result['table_data']->hasMerges());
        $this->assertSame(2, $result['table_data']->spanAt(0, 0));
        $this->assertSame('Titre fusionné', $result['table_data']->cell(0, 0));
    }

    public function test_le_nombre_de_colonnes_tient_compte_des_fusions(): void
    {
        // Le `w:tblGrid` déclare la structure réelle : c'est lui qui fait foi,
        // même si la première ligne ne contient qu'une cellule fusionnée.
        $result = $this->readTable(DocxFixture::tableWithGridSpan([
            ['Titre fusionné'],
            ['A', 'B'],
        ], 2, 2));

        $this->assertSame(2, $result['table_data']->cols);
    }

    public function test_un_tableau_sans_fusion_le_signale(): void
    {
        $result = $this->readTable(DocxFixture::table([
            ['A', 'B'],
            ['C', 'D'],
        ]));

        $this->assertFalse($result['has_merges']);
        $this->assertFalse($result['table_data']->hasMerges());
    }

    // -------------------------------------------------------------------------
    // Fusions verticales (vMerge)
    // -------------------------------------------------------------------------

    public function test_une_fusion_verticale_est_detectee(): void
    {
        // `<w:vMerge w:val="restart"/>` démarre la fusion, `<w:vMerge/>` la
        // poursuit sur la ligne suivante.
        $table = '<w:tbl><w:tblGrid><w:gridCol w:w="1000"/><w:gridCol w:w="1000"/></w:tblGrid>'
            .'<w:tr>'
            .'<w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr><w:p><w:r><w:t>Bloc</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>A</w:t></w:r></w:p></w:tc>'
            .'</w:tr>'
            .'<w:tr>'
            .'<w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc>'
            .'<w:tc><w:p><w:r><w:t>B</w:t></w:r></w:p></w:tc>'
            .'</w:tr>'
            .'</w:tbl>';

        $result = $this->readTable($table);
        $data = $result['table_data'];

        $this->assertTrue($result['has_merges']);
        $this->assertSame('Bloc', $data->cell(0, 0));
        $this->assertFalse($data->isVMergeContinuation(0, 0));
        $this->assertTrue($data->isVMergeContinuation(1, 0));
    }

    public function test_une_cellule_de_continuation_ne_relit_pas_son_contenu(): void
    {
        // Word affiche le contenu de la cellule d'origine : relire celui de la
        // continuation créerait un doublon affiché.
        $table = '<w:tbl><w:tblGrid><w:gridCol w:w="1000"/></w:tblGrid>'
            .'<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr><w:p><w:r><w:t>Texte</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p><w:r><w:t>RÉPÉTITION</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>';

        $result = $this->readTable($table);

        $this->assertSame('Texte', $result['table_data']->cell(0, 0));
        $this->assertSame('', $result['table_data']->cell(1, 0));
    }

    // -------------------------------------------------------------------------
    // En-tête de tableau
    // -------------------------------------------------------------------------

    public function test_une_ligne_marquee_tbl_header_est_identifiee(): void
    {
        // `w:tblHeader` marque la ligne répétée en haut de chaque page : c'est
        // la ligne d'en-tête, que le moteur de gabarit colorera.
        $table = '<w:tbl><w:tblGrid><w:gridCol w:w="1000"/></w:tblGrid>'
            .'<w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc><w:p><w:r><w:t>En-tête</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Donnée</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>';

        $result = $this->readTable($table);

        $this->assertTrue($result['has_header']);
    }

    public function test_un_tableau_sans_en_tete_le_signale(): void
    {
        $result = $this->readTable(DocxFixture::table([['A'], ['B']]));

        $this->assertFalse($result['has_header']);
    }

    // -------------------------------------------------------------------------
    // Largeurs et style
    // -------------------------------------------------------------------------

    public function test_les_largeurs_de_colonnes_sont_converties_en_points(): void
    {
        // `w:w` est en twips : 3322 twips ≈ 166,1 pt.
        $table = '<w:tbl><w:tblGrid>'
            .'<w:gridCol w:w="3322"/><w:gridCol w:w="6001"/>'
            .'</w:tblGrid><w:tr><w:tc><w:p><w:r><w:t>A</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>B</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';

        $result = $this->readTable($table);

        $this->assertCount(2, $result['column_widths']);
        $this->assertEqualsWithDelta(166.1, $result['column_widths'][0], 0.1);
        $this->assertEqualsWithDelta(300.05, $result['column_widths'][1], 0.1);
    }

    public function test_le_style_du_tableau_est_conserve(): void
    {
        // Permet au moteur de gabarit de connaître la mise en forme d'origine
        // avant de la remplacer.
        $table = '<w:tbl><w:tblPr><w:tblStyle w:val="Grilledutableau"/></w:tblPr>'
            .'<w:tblGrid><w:gridCol w:w="1000"/></w:tblGrid>'
            .'<w:tr><w:tc><w:p><w:r><w:t>A</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';

        $result = $this->readTable($table);

        $this->assertSame('Grilledutableau', $result['style_id']);
    }

    public function test_les_tableaux_imbriques_ne_corrompent_pas_la_lecture(): void
    {
        // Un tableau dans une cellule : les cellules du tableau imbriqué ne
        // doivent PAS apparaître comme des cellules du tableau parent.
        $table = '<w:tbl><w:tblGrid><w:gridCol w:w="1000"/></w:tblGrid><w:tr><w:tc>'
            .'<w:p><w:r><w:t>Cellule externe</w:t></w:r></w:p>'
            .'<w:tbl><w:tblGrid><w:gridCol w:w="500"/></w:tblGrid>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Imbriqué</w:t></w:r></w:p></w:tc></w:tr>'
            .'</w:tbl>'
            .'</w:tc></w:tr></w:tbl>';

        $result = $this->readTable($table);

        $this->assertSame(1, $result['table_data']->rows);
        $this->assertSame(1, $result['table_data']->cols);
    }

    public function test_un_tableau_est_serialisable(): void
    {
        $result = $this->readTable(DocxFixture::table([['A', 'B'], ['C', 'D']]));
        $array = $result['table_data']->toArray();

        $this->assertSame(['A', 'B', 'C', 'D'], $array['cells']);

        $restored = TableData::fromArray($array);
        $this->assertSame('A', $restored->cell(0, 0));
    }

    public function test_un_lecteur_ne_modifie_pas_le_fichier_source(): void
    {
        // Vérifie qu'on ne fait que LIRE : le fichier doit rester intact.
        $path = DocxFixture::create(DocxFixture::table([['A', 'B']]));
        $this->fixtures[] = $path;
        $before = md5_file($path);

        $package = new PackageReader($path);
        $package->open();
        $document = XmlLoader::load($package->readOrFail(PackageReader::MAIN_DOCUMENT), 'document.xml');
        $body = XmlLoader::firstDescendant($document->documentElement, 'w:body');
        $reader = new TableReader(new ParagraphReader(StyleReader::fromPackage($package)));
        $reader->read(XmlLoader::firstDescendant($body, 'w:tbl'));
        $package->close();

        $this->assertSame($before, md5_file($path));
    }

    public function test_une_archive_sans_document_est_rejetee(): void
    {
        $this->expectException(DocxReadException::class);

        (new PackageReader('/chemin/inexistant.docx'))->open();
    }
}
