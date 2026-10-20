<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Adapters;

use App\Document\Adapters\DocxNativeAdapter;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Detecting ALL-CAPS paragraph titles.
 *
 * **Why this rule exists, and why the bounds are what they are.** On the
 * reference pair (DQP rough → DQP final), 49 titles of the final existed in the
 * rough as plain PARAGRAPHS: none bold, all at body font size. No typographic
 * signal distinguished them.
 *
 * A single usable signal emerged — ALL CAPS, bounded length, no final period:
 *
 *     real titles recovered : 28
 *     false positives       :  3  (« ENVOL », « APNPC SERVICE », « PM SERVICE »)
 *     precision             : 90,3 %
 *     recall                : 39,5 % → 74,1 %
 *
 * The tests below lock those bounds. Losing one of them would silently raise
 * the false-positive rate — a defect that does not raise an error.
 */
class AllCapsHeadingTest extends TestCase
{
    /**
     * @return array<int, Block>
     */
    private function titres(string ...$paragraphes): array
    {
        $contenu = '';

        foreach ($paragraphes as $p) {
            $contenu .= DocxFixture::paragraph($p);
        }

        $document = (new DocxNativeAdapter)->convert(DocxFixture::create($contenu), 'test-caps');

        return $document->headings();
    }

    /**
     * **An ALL-CAPS short paragraph without final period is a heading.**
     */
    public function test_un_paragraphe_en_majuscules_devient_un_titre(): void
    {
        $titres = $this->titres('MISSION');

        $this->assertCount(1, $titres);
        $this->assertSame('MISSION', $titres[0]->text);
        $this->assertSame(1, $titres[0]->headingLevel);
    }

    /**
     * **Accents and digits are accepted.**
     *
     * A regex `^[A-Z]+$` would reject « SCÉNARIO » (É) and « SECTION 1 : … »
     * (digits) — both legitimate titles in the reference document.
     */
    public function test_les_accents_et_les_chiffres_sont_acceptes(): void
    {
        $titres = $this->titres('SCÉNARIO 1 : OUVERTURE', 'SECTION 2 : LE MACRO ENVIRONNEMENT');

        $this->assertCount(2, $titres);
    }

    /**
     * **A final period disqualifies the paragraph.**
     *
     * « CE PROJET EST IMPORTANT. » is a sentence, not a title. This bound alone
     * removes a large share of false positives on real documents.
     */
    public function test_un_point_final_disqualifie_le_paragraphe(): void
    {
        $this->assertCount(0, $this->titres('CE PROJET EST IMPORTANT.'));
    }

    /**
     * **Length is bounded at 80 characters.**
     *
     * Beyond that, an ALL-CAPS paragraph is body text (a quotation, a callout),
     * not a title. Without the bound, long emphatic paragraphs would enter the
     * table of contents.
     */
    public function test_un_texte_trop_long_n_est_pas_un_titre(): void
    {
        $long = str_repeat('TRÈS LONG TEXTE EN MAJUSCULES ', 4); // > 80 caractères

        $this->assertGreaterThan(80, mb_strlen($long));
        $this->assertCount(0, $this->titres($long));
    }

    /**
     * **Mixed case is not promoted.**
     *
     * Ordinary body sentences stay paragraphs — this is what makes the rule
     * selective.
     */
    public function test_la_casse_normale_n_est_pas_promise(): void
    {
        $this->assertCount(0, $this->titres('Le projet vise à regrouper les informations.'));
    }

    /**
     * **Trailing comma disqualifies.**
     *
     * A paragraph that introduces a list is not a heading. The colon is
     * deliberately tolerated (« CAR USAGE : » is a real title in the corpus).
     *
     * **The wording avoids the keyword rules on purpose.** A first version used
     * « RÉSUMÉ DES ÉTAPES, » — but « RÉSUMÉ » is a recognized keyword title
     * (`HeadingNumberingPattern`), so the paragraph was promoted regardless of
     * the comma. The test was then measuring the keyword rule, not the bound it
     * claimed to check.
     */
    public function test_une_virgule_finale_disqualifie(): void
    {
        $this->assertCount(0, $this->titres('PRESENTATION DES MODULES,'));
    }

    /**
     * **The colon is tolerated.**
     *
     * Measured need: « CAR USAGE : » and « SECTION 1: … » are real titles. The
     * colon is a common title punctuation; excluding it would lose titles to
     * avoid nothing.
     */
    public function test_le_deux_points_reste_accepte(): void
    {
        $titres = $this->titres('CAR USAGE :');

        $this->assertCount(1, $titres);
    }

    /**
     * **Confidence stays BELOW the automatic-acceptance threshold.**
     *
     * Capitalisation says « probably a title », never « certainly a title of
     * level N ». A confidence under 0,85 is deliberate: these blocks remain
     * candidates for verification instead of imposing themselves as certainties.
     */
    public function test_la_confiance_reste_sous_le_seuil_d_acceptation(): void
    {
        $titres = $this->titres('MISSION');

        $this->assertLessThan(
            0.85,
            $titres[0]->confidence,
            'La casse ne prouve pas la nature du bloc : la confiance doit rester sous le seuil.'
        );
    }

    /**
     * **An already-classified block is never re-promoted.**
     *
     * The rule only completes paragraphs. A caption or table already typed must
     * keep its type.
     */
    public function test_un_bloc_deja_classe_n_est_pas_repromu(): void
    {
        $document = (new DocxNativeAdapter)->convert(
            DocxFixture::create(DocxFixture::paragraph('Figure 1 : ARCHITECTURE DU SYSTEME')),
            'test-caps'
        );

        $this->assertCount(0, $document->blocksOfType(BlockType::Heading));
        $this->assertCount(1, $document->blocksOfType(BlockType::Caption));
    }
}
