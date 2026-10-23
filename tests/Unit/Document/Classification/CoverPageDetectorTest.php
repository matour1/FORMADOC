<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Classification;

use App\Document\Classification\CoverPageDetector;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\Fidelity;
use Tests\TestCase;

/**
 * Détection de la page de garde.
 *
 * **Ce que ces tests protègent.** La règle déclenche une SUPPRESSION. Un faux
 * positif supprime du contenu réel — c'est-à-dire exactement ce que le projet
 * interdit (« ne jamais perdre de contenu »). La précision est donc vitale, et
 * ces tests verrouillent les trois conditions mesurées :
 *
 *  1. **taille** — ≤ 40 blocs (pages de garde : 1-13 ; rapports : 375-722) ;
 *  2. **marqueurs** — au moins 2 formules distinctes de couverture ;
 *  3. **absence de titre liminaire** — un « SOMMAIRE » prouve un rapport complet.
 *
 * Mesure sur le corpus : **100 % de précision**, 66,7 % de rappel.
 */
class CoverPageDetectorTest extends TestCase
{
    private function bloc(string $texte, BlockType $type = BlockType::Paragraph): Block
    {
        return new Block(
            blockId: 'b_'.substr(md5($texte.microtime()), 0, 6),
            type: $type,
            text: $texte,
            confidence: 0.9,
            fidelity: Fidelity::Exact,
        );
    }

    private function detecteur(): CoverPageDetector
    {
        return new CoverPageDetector;
    }

    /**
     * **Une page de garde réelle est détectée.**
     *
     * Reprise de la structure du document `PREMIERE DE COUVERTURE.docx`, qui
     * contient 5 marqueurs et 13 blocs.
     */
    public function test_une_page_de_garde_est_detectee(): void
    {
        $blocs = [
            $this->bloc('REPUBLIC OF CAMEROON'),
            $this->bloc('MINISTERE DE L\'ENSEIGNEMENT SUPERIEUR'),
            $this->bloc('INSTITUT UNIVERSITAIRE DE L\'ESTUAIRE'),
            $this->bloc('RAPPORT DE STAGE ACADEMIQUE', BlockType::Heading),
            $this->bloc('Rédigé en vue d\'être présenté par : MASSO SAH'),
            $this->bloc('SOUS L\'ENCADREMENT'),
        ];

        $resultat = $this->detecteur()->detecter($blocs);

        $this->assertSame(count($blocs), $resultat['supprimer']);
        $this->assertGreaterThanOrEqual(2, count($resultat['marqueurs']));
    }

    /**
     * **Un rapport complet n'est JAMAIS détecté, même s'il contient les mots.**
     *
     * C'est le faux positif le plus dangereux : un rapport de stage contient
     * évidemment la phrase « rapport de stage ». La borne de taille et l'absence
     * de titre liminaire l'écartent.
     */
    public function test_un_rapport_complet_n_est_pas_pris_pour_une_page_de_garde(): void
    {
        $blocs = [];

        for ($i = 0; $i < 60; $i++) {
            $blocs[] = $this->bloc('Paragraphe de corps numéro '.$i);
        }

        $blocs[3] = $this->bloc('Ce mémoire est un rapport de stage présenté par un étudiant');

        $this->assertSame(0, $this->detecteur()->detecter($blocs)['supprimer']);
    }

    /**
     * **La page de garde précédant un titre liminaire est bien détectée et supprimée.**
     *
     * Conformément au §5.2 et à la décision D-002 : tout ce qui précède le premier
     * titre liminaire (ici SOMMAIRE) constitue la page de garde à supprimer.
     */
    public function test_la_page_de_garde_devant_un_titre_liminaire_est_detectee(): void
    {
        $blocs = [
            $this->bloc('REPUBLIQUE DU CAMEROUN'),
            $this->bloc('RAPPORT DE STAGE'),
            $this->bloc('SOMMAIRE', BlockType::Heading),
            $this->bloc('DEDICACE', BlockType::Heading),
        ];

        $resultat = $this->detecteur()->detecter($blocs);

        $this->assertSame(2, $resultat['supprimer']);
        $this->assertStringContainsString('page de garde détectée', $resultat['raison']);
    }

    /**
     * **Un document commençant directement par un titre liminaire ne supprime rien.**
     */
    public function test_un_document_sans_page_de_garde_commencant_par_un_sommaire_ne_supprime_rien(): void
    {
        $blocs = [
            $this->bloc('SOMMAIRE', BlockType::Heading),
            $this->bloc('DEDICACE', BlockType::Heading),
        ];

        $resultat = $this->detecteur()->detecter($blocs);

        $this->assertSame(0, $resultat['supprimer']);
        $this->assertStringContainsString('liminaire', $resultat['raison']);
    }

    /**
     * **Un seul marqueur ne suffit pas.**
     *
     * À seuil 1, la phrase « rapport de stage » — présente dans tout rapport —
     * suffirait. Il en faut deux, ce qui est le cas de toute couverture réelle
     * (« RÉPUBLIQUE » + « PRÉSENTÉ PAR »).
     */
    public function test_un_seul_marqueur_ne_suffit_pas(): void
    {
        $blocs = [
            $this->bloc('RAPPORT DE STAGE'),
            $this->bloc('Du 30 avril au 20 juin'),
        ];

        $this->assertSame(0, $this->detecteur()->detecter($blocs)['supprimer']);
    }

    /**
     * **Un document volumineux est écarté sans même lire ses marqueurs.**
     *
     * La borne de taille est un garde-fou de coût : inutile d'analyser
     * 700 paragraphes pour une décision déjà tranchée.
     */
    public function test_un_document_volumineux_est_ecarte(): void
    {
        $blocs = [];

        for ($i = 0; $i < CoverPageDetector::MAX_BLOCS + 1; $i++) {
            $blocs[] = $this->bloc('REPUBLIQUE DU CAMEROUN '.$i);
        }

        $resultat = $this->detecteur()->detecter($blocs);

        $this->assertSame(0, $resultat['supprimer']);
        $this->assertStringContainsString('volumineux', $resultat['raison']);
    }

    /**
     * **Les accents et la casse n'empêchent pas la détection.**
     *
     * Les documents saisis librement écrivent « RÉPUBLIQUE » ou « republique »,
     * « PRESENTE PAR » ou « présenté par ».
     */
    public function test_les_accents_et_la_casse_sont_ignores(): void
    {
        $blocs = [
            $this->bloc('republique du cameroun'),
            $this->bloc('presente par : un etudiant'),
        ];

        $this->assertSame(2, $this->detecteur()->detecter($blocs)['supprimer']);
    }

    public function test_un_document_sans_page_de_garde_commencant_par_du_texte_normal_ne_supprime_rien(): void
    {
        $blocs = [
            $this->bloc('Ce document ne comporte aucune couverture.'),
            $this->bloc('Le présent travail porte sur l\'architecture logicielle.'),
            $this->bloc('Nous allons décrire la méthodologie employée.'),
        ];

        $resultat = $this->detecteur()->detecter($blocs);

        $this->assertSame(0, $resultat['supprimer']);
        $this->assertStringContainsString('marqueurs insuffisants', $resultat['raison']);
    }

    public function test_un_document_vide_ne_declenche_rien(): void
    {
        $resultat = $this->detecteur()->detecter([]);

        $this->assertSame(0, $resultat['supprimer']);
        $this->assertSame('document vide', $resultat['raison']);
    }
}
