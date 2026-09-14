<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Adapters\DocxOoxml;

use App\Document\Adapters\DocxOoxml\FieldReader;
use Tests\TestCase;

/**
 * Tests du lecteur de champs Word.
 *
 * Enjeu : identifier correctement la NATURE de chaque champ, car elle détermine
 * trois comportements distincts :
 *  - `SEQ`  → porte la numérotation d'un élément (à renuméroter en R4) ;
 *  - `REF`/`PAGEREF` → renvoi croisé à résoudre et réécrire (R4) ;
 *  - `TOC`/`PAGE`/`STYLEREF` → valeur mise en cache OBSOLÈTE, à régénérer.
 */
class FieldReaderTest extends TestCase
{
    private FieldReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new FieldReader;
    }

    // -------------------------------------------------------------------------
    // Analyse d'instruction
    // -------------------------------------------------------------------------

    public function test_une_instruction_seq_est_analysee(): void
    {
        // Forme observée 142 fois dans les documents du projet.
        $parsed = $this->reader->parseInstruction('SEQ Figure \* ARABIC');

        $this->assertSame('sequence', $parsed['kind']);
        $this->assertSame('Figure', $parsed['identifier']);
        $this->assertContains('\*', $parsed['switches']);
    }

    public function test_l_identifiant_d_un_compteur_seq_est_extrait(): void
    {
        $this->assertSame('Tableau', $this->reader->parseInstruction('SEQ Tableau \* ARABIC')['identifier']);
        $this->assertSame('Figure', $this->reader->parseInstruction('SEQ Figure \* ARABIC')['identifier']);
    }

    public function test_les_espaces_multiples_sont_normalises(): void
    {
        // Word insère parfois plusieurs espaces ou des espaces insécables dans
        // les instructions de champ.
        $parsed = $this->reader->parseInstruction('  SEQ   Figure   \*   ARABIC  ');

        $this->assertSame('sequence', $parsed['kind']);
        $this->assertSame('Figure', $parsed['identifier']);
    }

    public function test_un_identifiant_entre_guillemets_est_extrait(): void
    {
        // Les champs TOC utilisent cette forme : `TOC \h \z \c "Figure"`.
        $parsed = $this->reader->parseInstruction('TOC \h \z \c "Figure"');

        $this->assertSame('table_of_contents', $parsed['kind']);
        $this->assertSame('Figure', $parsed['identifier']);
    }

    public function test_un_identifiant_de_signet_est_extrait(): void
    {
        // Les renvois pointent vers un signet généré : `PAGEREF _Toc221266373 \h`.
        $parsed = $this->reader->parseInstruction('PAGEREF _Toc221266373 \h');

        $this->assertSame('page_reference', $parsed['kind']);
        $this->assertSame('_Toc221266373', $parsed['identifier']);
    }

    public function test_une_instruction_vide_est_gerere_sans_erreur(): void
    {
        $parsed = $this->reader->parseInstruction('   ');

        $this->assertSame('unknown', $parsed['kind']);
        $this->assertNull($parsed['identifier']);
    }

    public function test_une_instruction_inconnue_est_classee_unknown(): void
    {
        $parsed = $this->reader->parseInstruction('MONCHAMPINCONNU arg');

        $this->assertSame('unknown', $parsed['kind']);
    }

    // -------------------------------------------------------------------------
    // Reconnaissance de la nature du champ
    // -------------------------------------------------------------------------

    public function test_les_champs_de_sequence_sont_reconnus(): void
    {
        $this->assertTrue($this->reader->isSequence('SEQ Figure \* ARABIC'));
        $this->assertTrue($this->reader->isSequence('  SEQ Tableau  '));
        $this->assertFalse($this->reader->isSequence('PAGEREF _Toc1 \h'));
        $this->assertFalse($this->reader->isSequence('TOC \o "1-3"'));
    }

    public function test_les_renvois_sont_reconnus(): void
    {
        $this->assertTrue($this->reader->isReference('REF _Ref123 \h'));
        $this->assertTrue($this->reader->isReference('PAGEREF _Toc1 \h'));
        $this->assertTrue($this->reader->isReference('NOTEREF _Ref1'));
        $this->assertFalse($this->reader->isReference('SEQ Figure'));
    }

    public function test_les_champs_regeneres_sont_reconnus(): void
    {
        // Ces champs sont rejoués par le moteur de rendu : leur valeur figée
        // dans le document source est obsolète.
        foreach (['TOC \o "1-3"', 'PAGE', 'NUMPAGES', 'STYLEREF 1 \s', 'DATE \\@ "dd/MM/yyyy"'] as $instruction) {
            $this->assertTrue(
                $this->reader->isGeneratedField($instruction),
                "« {$instruction} » devrait être un champ régénéré"
            );
        }
    }

    public function test_un_compteur_seq_n_est_pas_un_champ_regenere(): void
    {
        // Nuance essentielle : SEQ porte une information MÉTIER (le numéro de
        // l'élément), il n'est donc pas « obsolète » — il sera recalculé, mais
        // sa position et sa catégorie doivent être conservées.
        $this->assertFalse($this->reader->isGeneratedField('SEQ Figure \* ARABIC'));
    }

    public function test_la_valeur_en_cache_d_un_champ_regenere_est_obsolete(): void
    {
        $this->assertTrue($this->reader->cachedValueIsStale('PAGE'));
        $this->assertTrue($this->reader->cachedValueIsStale('TOC \o "1-3"'));
        $this->assertFalse($this->reader->cachedValueIsStale('SEQ Figure'));
    }

    // -------------------------------------------------------------------------
    // Catégorie de compteur SEQ
    // -------------------------------------------------------------------------

    public function test_les_categories_standard_sont_reconnues(): void
    {
        $this->assertSame('figure', $this->reader->sequenceCategory('Figure'));
        $this->assertSame('table', $this->reader->sequenceCategory('Tableau'));
        $this->assertSame('annexe', $this->reader->sequenceCategory('Annexe'));
        $this->assertSame('planche', $this->reader->sequenceCategory('Planche'));
    }

    public function test_une_faute_de_frappe_sur_le_compteur_est_tolerée(): void
    {
        // Cas réel : 36 occurrences de « SEQ ttablaeu » dans les documents du
        // projet (au lieu de « Tableau »). Sans tolérance, ces tableaux
        // perdraient leur numérotation.
        $this->assertSame('table', $this->reader->sequenceCategory('ttablaeu'));
        $this->assertSame('table', $this->reader->sequenceCategory('tablaeu'));
    }

    public function test_la_casse_du_compteur_n_a_pas_d_importance(): void
    {
        $this->assertSame('figure', $this->reader->sequenceCategory('FIGURE'));
        $this->assertSame('figure', $this->reader->sequenceCategory('figure'));
    }

    public function test_un_compteur_inconnu_ne_produit_pas_de_categorie(): void
    {
        // Ne pas inventer une catégorie : le bloc restera ambigu et sera
        // clarifié plutôt que mal classé.
        $this->assertNull($this->reader->sequenceCategory('MonCompteur'));
        $this->assertNull($this->reader->sequenceCategory(null));
    }

    // -------------------------------------------------------------------------
    // Extraction depuis un document
    // -------------------------------------------------------------------------

    public function test_les_champs_complexes_sont_extraits_avec_leur_obsoletude(): void
    {
        $paragraph = '<w:p>'
            .'<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            .'<w:r><w:instrText xml:space="preserve"> PAGEREF _Toc1 \h </w:instrText></w:r>'
            .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            .'<w:r><w:t>12</w:t></w:r>'
            .'<w:r><w:fldChar w:fldCharType="end"/></w:r>'
            .'</w:p>';

        $element = $this->wrap($paragraph);
        $fields = $this->reader->complexFieldsIn($element);

        $this->assertCount(1, $fields);
        $this->assertSame('page_reference', $fields[0]['kind']);
        $this->assertSame('_Toc1', $fields[0]['identifier']);
        $this->assertTrue($fields[0]['is_stale']);
    }

    public function test_un_champ_seq_complexe_est_extrait(): void
    {
        $paragraph = '<w:p>'
            .'<w:r><w:instrText xml:space="preserve"> SEQ Tableau \* ARABIC </w:instrText></w:r>'
            .'</w:p>';

        $fields = $this->reader->complexFieldsIn($this->wrap($paragraph));

        $this->assertCount(1, $fields);
        $this->assertSame('sequence', $fields[0]['kind']);
        $this->assertSame('Tableau', $fields[0]['identifier']);
        $this->assertFalse($fields[0]['is_stale']);
    }

    public function test_les_champs_simples_sont_extraits(): void
    {
        $paragraph = '<w:p><w:fldSimple w:instr="PAGE"><w:r><w:t>5</w:t></w:r></w:fldSimple></w:p>';

        $fields = $this->reader->simpleFieldsIn($this->wrap($paragraph));

        $this->assertCount(1, $fields);
        $this->assertSame('page_number', $fields[0]['kind']);
    }

    public function test_un_paragraphe_sans_champ_ne_retourne_rien(): void
    {
        $paragraph = '<w:p><w:r><w:t>Texte ordinaire</w:t></w:r></w:p>';
        $element = $this->wrap($paragraph);

        $this->assertSame([], $this->reader->complexFieldsIn($element));
        $this->assertSame([], $this->reader->simpleFieldsIn($element));
    }

    /**
     * Enveloppe un fragment XML pour pouvoir l'analyser.
     */
    private function wrap(string $fragment): \DOMElement
    {
        $document = new \DOMDocument;
        $document->loadXML(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .$fragment.'</w:root>'
        );

        return $document->documentElement;
    }
}
