<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Adapters;

use App\Document\Adapters\DocxNativeAdapter;
use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\Fidelity;
use App\Document\Structure\StructuralDocument;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Tests du parseur `.docx` natif.
 *
 * C'est le composant qui remplace la lecture PHPWord : les tests portent donc
 * sur la fidélité de l'extraction et sur la fiabilité de la détection, en
 * particulier la confrontation entre style Word et numérotation saisie.
 */
class DocxNativeAdapterTest extends TestCase
{
    /** @var array<int, string> */
    private array $fixtures = [];

    private DocxNativeAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new DocxNativeAdapter;
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            DocxFixture::cleanup($path);
        }
        $this->fixtures = [];

        parent::tearDown();
    }

    /**
     * Crée une fixture et la mémorise pour le nettoyage.
     *
     * @param  array<string, string>  $extraParts
     */
    private function fixture(string $bodyXml, string $stylesXml = '', array $extraParts = []): string
    {
        $path = DocxFixture::create($bodyXml, $stylesXml, $extraParts);
        $this->fixtures[] = $path;

        return $path;
    }

    private function convert(string $path, string $documentId = 'doc_test'): StructuralDocument
    {
        return $this->adapter->convert($path, $documentId);
    }

    /**
     * Crée une fixture et la convertit en une seule étape.
     *
     * @param  array<string, string>  $extraParts
     */
    private function convertFixture(string $bodyXml, string $stylesXml = '', string $documentId = 'doc_test', array $extraParts = []): StructuralDocument
    {
        return $this->convert($this->fixture($bodyXml, $stylesXml, $extraParts), $documentId);
    }

    // -------------------------------------------------------------------------
    // Contrat de l'adaptateur
    // -------------------------------------------------------------------------

    public function test_le_type_de_source_est_docx(): void
    {
        $this->assertSame('docx', $this->adapter->sourceType());
    }

    public function test_la_fidelite_d_un_docx_est_exacte(): void
    {
        // Un .docx porte sa sémantique : la fidélité est garantie.
        $this->assertSame(Fidelity::Exact, $this->adapter->fidelity());
    }

    public function test_supports_reconnait_une_archive_docx(): void
    {
        $path = $this->fixture(DocxFixture::paragraph('Contenu'));

        $this->assertTrue($this->adapter->supports($path));
    }

    public function test_supports_rejette_un_fichier_non_zip(): void
    {
        // Un .doc renommé en .docx ne commence pas par « PK » : il doit être
        // rejeté plutôt que de produire une structure vide silencieusement.
        $path = tempnam(sys_get_temp_dir(), 'not_a_docx_').'.docx';
        file_put_contents($path, "Ceci n'est pas un fichier zip");
        $this->fixtures[] = $path;

        $this->assertFalse($this->adapter->supports($path));
    }

    public function test_supports_rejette_un_fichier_absent(): void
    {
        $this->assertFalse($this->adapter->supports('/chemin/inexistant.docx'));
    }

    public function test_un_fichier_absent_leve_une_exception_explicite(): void
    {
        $this->expectException(DocxReadException::class);
        $this->expectExceptionMessage('introuvable');

        $this->convert('/chemin/inexistant.docx');
    }

    public function test_une_archive_sans_document_principal_est_rejetee(): void
    {
        // Un ZIP quelconque n'est pas un .docx : le message doit le dire.
        $path = tempnam(sys_get_temp_dir(), 'plain_zip_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('autre.txt', 'rien à voir');
        $zip->close();
        $this->fixtures[] = $path;

        $this->expectException(DocxReadException::class);
        $this->expectExceptionMessage('document principal');

        $this->convert($path);
    }

    // -------------------------------------------------------------------------
    // Extraction du texte et des signaux
    // -------------------------------------------------------------------------

    public function test_un_paragraphe_simple_devient_un_bloc_de_texte(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Un paragraphe de contenu.')
        ));

        $this->assertSame(1, $document->count());

        $block = $document->blocks[0];
        $this->assertSame(BlockType::Paragraph, $block->type);
        $this->assertSame('Un paragraphe de contenu.', $block->text);
    }

    public function test_le_document_produit_le_schema_json_commun(): void
    {
        $document = $this->convertFixture(
            DocxFixture::paragraph('Texte'),
            documentId: 'doc_42'
        );

        $this->assertSame('doc_42', $document->documentId);
        $this->assertSame('docx', $document->sourceType);
        $this->assertSame(Fidelity::Exact, $document->expectedFidelity());
    }

    public function test_les_paragraphes_vides_sont_ignores(): void
    {
        // Word insère beaucoup de paragraphes vides comme espacement : ce ne
        // sont pas des blocs.
        $document = $this->convert($this->fixture(
            DocxFixture::emptyParagraph()
            .DocxFixture::paragraph('Seul vrai contenu')
            .DocxFixture::emptyParagraph()
        ));

        $this->assertSame(1, $document->count());
        $this->assertSame('Seul vrai contenu', $document->blocks[0]->text);
    }

    public function test_les_blocs_recoivent_des_identifiants_uniques_et_ordonnes(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Premier')
            .DocxFixture::paragraph('Deuxième')
            .DocxFixture::paragraph('Troisième')
        ));

        $ids = array_map(static fn ($block): string => $block->blockId, $document->blocks);

        $this->assertSame(['b_0001', 'b_0002', 'b_0003'], $ids);
    }

    public function test_les_accents_et_caracteres_speciaux_sont_preserves(): void
    {
        $text = 'Résumé — étude de cas : 1 250 000 FCFA (taux 12,5 %)';

        $document = $this->convert($this->fixture(DocxFixture::paragraph($text)));

        $this->assertSame($text, $document->blocks[0]->text);
    }

    public function test_la_taille_de_police_est_convertie_en_points(): void
    {
        // OOXML exprime w:sz en demi-points : 32 = 16 pt.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Titre visuel', runFormat: ['size' => 32])
        ));

        $this->assertSame(16.0, $document->blocks[0]->fontSize);
    }

    public function test_le_gras_est_detecte(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('En gras', runFormat: ['bold' => true])
        ));

        $this->assertTrue($document->blocks[0]->isBold);
    }

    // -------------------------------------------------------------------------
    // Détection des titres — style Word
    // -------------------------------------------------------------------------

    public function test_un_style_avec_outline_lvl_donne_un_titre(): void
    {
        // `w:outlineLvl` est LE signal déterministe du niveau hiérarchique.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Introduction', styleId: 'Titre1', outlineLevel: 0),
            DocxFixture::style('Titre1', 'heading 1', outlineLevel: 0)
        ));

        $block = $document->blocks[0];
        $this->assertSame(BlockType::Heading, $block->type);
        $this->assertSame(1, $block->headingLevel);
    }

    public function test_un_style_localise_francais_est_reconnu_par_son_nom(): void
    {
        // Piège réel : sur un Word français, « Heading 1 » a l'identifiant
        // `Titre1`, mais `w:name` reste « heading 1 ». On ne peut donc pas
        // chercher par identifiant.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Introduction', styleId: 'Titre1', outlineLevel: 0),
            DocxFixture::style('Titre1', 'heading 1', outlineLevel: 0)
        ));

        $this->assertSame(1, $document->blocks[0]->headingLevel);
    }

    public function test_un_style_de_niveau_trois_donne_un_titre_de_niveau_trois(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Sous-section', styleId: 'Titre3', outlineLevel: 2),
            DocxFixture::style('Titre3', 'heading 3', outlineLevel: 2)
        ));

        $this->assertSame(3, $document->blocks[0]->headingLevel);
        $this->assertSame(BlockType::Heading, $document->blocks[0]->type);
    }

    public function test_un_titre_avec_style_et_numeration_coherents_a_une_confiance_maximale(): void
    {
        // Style « heading 1 » + texte « 1. Introduction » : les deux signaux
        // convergent → confiance très haute.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('1. Introduction', styleId: 'Titre1', outlineLevel: 0),
            DocxFixture::style('Titre1', 'heading 1', outlineLevel: 0)
        ));

        $block = $document->blocks[0];
        $this->assertSame(BlockType::Heading, $block->type);
        $this->assertSame(1, $block->headingLevel);
        $this->assertGreaterThanOrEqual(0.95, $block->confidence);
        $this->assertTrue($block->isConfident());
    }

    // -------------------------------------------------------------------------
    // Détection des titres — numérotation seule (le cas le plus fréquent)
    // -------------------------------------------------------------------------

    public function test_une_numerotation_decimale_sans_style_detecte_un_titre(): void
    {
        // Cas très fréquent : l'auteur numérote à la main sans appliquer de style.
        // Le pattern texte (priorité 1 de la spec) rattrape le coup.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('1. Introduction')
        ));

        $block = $document->blocks[0];
        $this->assertSame(BlockType::Heading, $block->type);
        $this->assertSame(1, $block->headingLevel);
    }

    public function test_une_sous_numerotation_donne_un_niveau_deux(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('1.1 Contexte du projet')
        ));

        $block = $document->blocks[0];
        $this->assertSame(BlockType::Heading, $block->type);
        $this->assertSame(2, $block->headingLevel);
    }

    public function test_une_numerotation_profonde_donne_le_niveau_correspondant(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('2.3.1 Analyse détaillée')
        ));

        $this->assertSame(3, $document->blocks[0]->headingLevel);
    }

    public function test_un_mot_cle_chapitre_detecte_un_titre_de_niveau_un(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('CHAPITRE 1 : Présentation générale')
        ));

        $block = $document->blocks[0];
        $this->assertSame(BlockType::Heading, $block->type);
        $this->assertSame(1, $block->headingLevel);
    }

    public function test_une_phrase_ordinaire_n_est_pas_un_titre(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Le projet a été mené sur une période de six mois.')
        ));

        $this->assertSame(BlockType::Paragraph, $document->blocks[0]->type);
    }

    // -------------------------------------------------------------------------
    // Contradiction style ↔ numérotation (cœur de la refonte)
    // -------------------------------------------------------------------------

    public function test_une_contradiction_entre_style_et_numerotation_fait_chuter_la_confiance(): void
    {
        // CAS RÉEL rencontré sur les documents du projet : un paragraphe porte
        // le style « heading 1 » mais son texte est numéroté « 1.1 » → c'est un
        // niveau 2. La spec impose confidence < 0.7 en cas de contradiction.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('1.1 Institution', styleId: 'Titre1', outlineLevel: 0),
            DocxFixture::style('Titre1', 'heading 1', outlineLevel: 0)
        ));

        $block = $document->blocks[0];

        // Le niveau retenu suit la numérotation (intention de l'auteur)…
        $this->assertSame(2, $block->headingLevel);

        // …mais la confiance chute : l'utilisateur devra trancher sur ce bloc.
        $this->assertLessThan(0.7, $block->confidence);
        $this->assertTrue($block->needsClarification());
    }

    public function test_le_bloc_contradictoire_apparait_dans_les_ambigus(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('1.1 Institution', styleId: 'Titre1', outlineLevel: 0)
            .DocxFixture::paragraph('2.1 Historique', styleId: 'Titre2', outlineLevel: 1),
            DocxFixture::style('Titre1', 'heading 1', outlineLevel: 0)
            .DocxFixture::style('Titre2', 'heading 2', outlineLevel: 1)
        ));

        // Un seul bloc ambigu : celui dont les signaux se contredisent.
        // L'autre (style niveau 2 = pattern niveau 2) est cohérent.
        $ambiguous = $document->ambiguous();

        $this->assertCount(1, $ambiguous);
        $this->assertSame('1.1 Institution', $ambiguous[0]->text);
    }

    public function test_une_ligne_courte_en_gras_sans_style_reste_ambigue(): void
    {
        // Ce cas justifie l'existence du tool detect_blocks : une heuristique
        // ne peut pas trancher, il faut un signal externe ou l'utilisateur.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Étude de marché', runFormat: ['bold' => true])
        ));

        $block = $document->blocks[0];

        $this->assertSame(BlockType::Paragraph, $block->type);
        $this->assertLessThan(0.85, $block->confidence);
        $this->assertTrue($block->needsClarification());
    }

    // -------------------------------------------------------------------------
    // Légendes
    // -------------------------------------------------------------------------

    public function test_une_legende_de_figure_est_detectee_sans_appel_ia(): void
    {
        // Règle n°1 du projet : une légende suit « Mot N : texte » → regex.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Figure 1 : Architecture du système')
        ));

        $block = $document->blocks[0];

        $this->assertSame(BlockType::Caption, $block->type);
        $this->assertSame(BlockCategory::Figure, $block->category);
        $this->assertSame('1', $block->originalNumber);
        $this->assertGreaterThanOrEqual(0.9, $block->confidence);
    }

    public function test_une_legende_de_tableau_est_detectee_avec_sa_categorie(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Tableau 3 : Résultats de l\'enquête')
        ));

        $block = $document->blocks[0];

        $this->assertSame(BlockType::Caption, $block->type);
        $this->assertSame(BlockCategory::Table, $block->category);
        $this->assertSame('3', $block->originalNumber);
    }

    public function test_une_legende_d_annexe_avec_lettre_est_detectee(): void
    {
        // Les annexes utilisent souvent des lettres : « Annexe B ».
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Annexe B : Questionnaire remis aux stagiaires')
        ));

        $block = $document->blocks[0];

        $this->assertSame(BlockType::Caption, $block->type);
        $this->assertSame(BlockCategory::Annexe, $block->category);
        $this->assertSame('B', $block->originalNumber);
    }

    public function test_une_legende_de_planche_est_detectee(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Planche 2 : Coupe transversale')
        ));

        $block = $document->blocks[0];

        $this->assertSame(BlockType::Caption, $block->type);
        $this->assertSame(BlockCategory::Planche, $block->category);
    }

    public function test_une_phrase_mentionnant_une_figure_n_est_pas_une_legende(): void
    {
        // « la figure 3 montre » ne commence pas par le mot-clé : c'est un renvoi
        // dans le corps du texte, pas une légende.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Comme la figure 3 le montre, le débit augmente.')
        ));

        $this->assertSame(BlockType::Paragraph, $document->blocks[0]->type);
    }

    public function test_une_entree_de_liste_de_figures_n_est_pas_une_legende(): void
    {
        // Les entrées du frontispice portent des points de conduite et un
        // numéro de page : les confondre avec des légendes créeraient des
        // doublons dans la liste générée.
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Figure 1 : Architecture .......... 12')
        ));

        $this->assertNotSame(BlockType::Caption, $document->blocks[0]->type);
    }

    // -------------------------------------------------------------------------
    // Images
    // -------------------------------------------------------------------------

    public function test_une_image_seule_devient_un_bloc_image(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::imageParagraph('rId5'),
            extraParts: ['word/_rels/document.xml.rels' => $this->imageRels('rId5')]
        ));

        $block = $document->blocks[0];

        $this->assertSame(BlockType::Image, $block->type);
        $this->assertNotNull($block->imageRef);
    }

    public function test_une_image_avec_legende_devient_une_figure(): void
    {
        // Distinction structurante : figure = image NUMÉROTÉE (liste dédiée),
        // image = décorative (pas de liste).
        $document = $this->convert($this->fixture(
            DocxFixture::imageParagraph('rId5', 'Figure 2 : Schéma du pipeline'),
            extraParts: ['word/_rels/document.xml.rels' => $this->imageRels('rId5')]
        ));

        $block = $document->blocks[0];

        $this->assertSame(BlockType::Figure, $block->type);
        $this->assertSame(BlockCategory::Figure, $block->category);
        $this->assertSame('2', $block->originalNumber);
    }

    // -------------------------------------------------------------------------
    // Métadonnées
    // -------------------------------------------------------------------------

    public function test_les_metadonnees_tracent_la_conversion(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Texte'),
            DocxFixture::style('Titre1', 'heading 1', outlineLevel: 0)
        ));

        $this->assertSame('exact', $document->meta['fidelity']);
        $this->assertSame(1, $document->meta['style_count']);
        $this->assertSame(1, $document->meta['paragraphs_read']);
        $this->assertSame(0, $document->meta['paragraphs_skipped']);
    }

    public function test_un_document_sans_styles_reste_lisible(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('Texte sans styles.xml')
        ));

        $this->assertSame(1, $document->count());
        $this->assertSame(0, $document->meta['style_count']);
    }

    public function test_le_document_produit_est_toujours_serialisable(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::paragraph('1. Introduction', styleId: 'Titre1', outlineLevel: 0)
            .DocxFixture::paragraph('Figure 1 : Schéma')
            .DocxFixture::paragraph('Contenu courant.')
            .DocxFixture::table([['A', 'B'], ['1', '2']]),
            DocxFixture::style('Titre1', 'heading 1', outlineLevel: 0)
        ));

        $json = $document->toJson();

        $this->assertJson($json);
        $this->assertSame($document->count(), StructuralDocument::fromJson($json)->count());
    }

    // -------------------------------------------------------------------------
    // Entrées de sommaire — la frontière entre PUCE et SOMMAIRE
    // -------------------------------------------------------------------------

    /**
     * **Un paragraphe de puce ne devient PAS une entrée de sommaire.**
     *
     * Test de bout en bout de la régression la plus coûteuse de cette fonction :
     * `list paragraph` est le style que Word applique à toute liste à puces, et
     * le consommer comme signal de sommaire a reclassé **175 paragraphes de
     * contenu** sur un document réel du corpus — toute la liste à puces du corps.
     *
     * Le test est placé ici, et non seulement sur `StyleReader`, parce que c'est
     * `DocxNativeAdapter` qui produit les blocs : un `StyleReader` correct branché
     * au mauvais signal produirait quand même des blocs faux. La régression vivait
     * exactement à cet endroit, et aucun test ne traversait ce chemin.
     */
    public function test_un_paragraphe_de_puce_n_est_pas_une_entree_de_sommaire(): void
    {
        $document = $this->convertFixture(
            DocxFixture::paragraph('Promouvoir les entreprises locales ;', styleId: 'Liste')
            .DocxFixture::paragraph('Proposer des services modernes ;', styleId: 'Liste')
            .DocxFixture::paragraph('Créer des emplois pour les jeunes.', styleId: 'Liste'),
            DocxFixture::style('Liste', 'list paragraph'),
        );

        $this->assertCount(3, $document->blocks,
            'Les puces doivent rester des blocs — les supprimer viderait le document.');

        foreach ($document->blocks as $block) {
            $this->assertNotSame(BlockType::TocEntry, $block->type,
                '« '.mb_substr($block->text, 0, 40).' » est une PUCE. La reclasser en '
                .'entrée de sommaire retire le contenu listé du document.');
        }
    }

    /**
     * Contrôle positif : une VRAIE entrée de sommaire est bien reclassée.
     *
     * Distinguer puce et sommaire ne doit pas casser L1.1 — c'est tout son objet.
     * Une entrée stylée `toc 1` porte un `outlineLevel`, donc la règle de titre la
     * capturerait en premier si le test de style n'était pas placé AVANT elle.
     */
    public function test_une_entree_de_sommaire_stylee_toc_est_reclassee(): void
    {
        $document = $this->convertFixture(
            DocxFixture::paragraph("CHAPITRE I : PRESENTATION\t2", styleId: 'Toc1')
            .DocxFixture::paragraph("CONCLUSION GENERALE\t36", styleId: 'Toc1'),
            DocxFixture::style('Toc1', 'toc 1', outlineLevel: 0),
        );

        $this->assertSame(BlockType::TocEntry, $document->blocks[0]->type);
        $this->assertSame('CHAPITRE I : PRESENTATION', $document->blocks[0]->text,
            'Le numéro de page doit être retiré : laissé tel quel, il serait recopié '
            .'dans le sommaire généré.');

        // Une entrée de sommaire n'est pas un titre : elle ne doit pas alimenter
        // la table des matières qu'on génère, sinon elle s'y cite elle-même.
        $this->assertSame([], $document->headings());
    }

    /**
     * **Un diagramme en FORMES produit un bloc `Shape` au lieu d'être ignoré.**
     *
     * Le défaut mesuré : `w:drawing` était traité comme une image, sans distinguer
     * un bitmap (`a:blip r:embed`) d'une forme vectorielle (`wps:wsp`). Une forme
     * n'ayant aucune relation d'image, le paragraphe se retrouvait avec un texte
     * vide et `has_image = false` : `read()` retournait `null` et le diagramme
     * **disparaissait entièrement**, sans erreur ni signalement.
     *
     * Mesure du 2026-09-30 : 4 contenus du corpus, 285 formes, 250 zones de texte.
     *
     * Le bloc `Shape` ne porte pas le contenu du diagramme (un arbre XML n'a pas
     * sa place dans le schéma structurel) : il marque son EMPLACEMENT, que
     * `ShapePreserver` remplit après l'écriture du DOCX.
     */
    public function test_un_diagramme_en_formes_produit_un_bloc_shape(): void
    {
        // Un `w:drawing` SANS relation d'image : c'est ce qui caractérise une forme.
        // La zone de texte porte du contenu réel (« Organigramme »), qui était
        // perdu — c'est précisément ce que la mesure a mis au jour.
        $forme = '<w:p><w:r><w:drawing><wp:inline><a:graphic><a:graphicData>'
            .'<wps:wsp><wps:txbx><w:txbxContent>'
            .'<w:p><w:r><w:t>Organigramme de la societe</w:t></w:r></w:p>'
            .'</w:txbxContent></wps:txbx></wps:wsp>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';

        $document = $this->convert($this->fixture($forme));

        $this->assertCount(1, $document->blocks,
            'Le paragraphe portant un diagramme doit produire un bloc : avant, il '
            .'était considéré comme vide et purement ignoré.');

        $this->assertSame(BlockType::Shape, $document->blocks[0]->type,
            'Un diagramme en formes doit être typé `Shape`, distinct d\'une image : '
            .'il n\'a aucune relation d\'image à lire.');
    }

    /**
     * Une IMAGE produit toujours un bloc `Image`, pas un `Shape`.
     *
     * Contrôle négatif indispensable : si toute `w:drawing` devenait un `Shape`,
     * les images perdraient leur binaire et ne seraient plus ré-embarquées.
     */
    public function test_une_image_ne_produit_pas_un_bloc_shape(): void
    {
        $document = $this->convert($this->fixture(
            DocxFixture::imageParagraph('rId5'),
            extraParts: ['word/_rels/document.xml.rels' => $this->imageRels('rId5')]
        ));

        foreach ($document->blocks as $block) {
            $this->assertNotSame(BlockType::Shape, $block->type,
                'Une image liée par `r:embed` doit rester une image : la confondre '
                .'avec une forme ferait perdre son binaire.');
        }
    }

    /**
     * Fichier de relations contenant une image.
     */
    private function imageRels(string $relationId): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="'.$relationId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>'
            .'</Relationships>';
    }
}
