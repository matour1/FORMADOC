<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Classification;

use App\Document\Classification\BlockClassifier;
use App\Document\Classification\SignalAggregator;
use App\Document\DocumentPipeline;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Branchement de R2 dans le pipeline (phase R7.x — assemblage).
 *
 * **Ce que ces tests couvrent, et pourquoi.** L'audit des 51 documents réels a
 * montré que `BlockClassifier` n'était instancié que dans les tests : le cycle
 * déterministe → IA → clarification n'était jamais exécuté en production. Les
 * tests unitaires existants ne pouvaient pas le voir, puisqu'ils construisent le
 * classifieur eux-mêmes.
 *
 * Deux propriétés sont vérifiées ici :
 *
 *  1. **Le container fournit un classifieur complet** — sans quoi le branchement
 *     serait à nouveau inerte, comme le fut le registre d'usage en R7 ;
 *  2. **La confiance de l'adaptateur est préservée** pour les titres détectés par
 *     numérotation seule. Sans cela, l'agrégateur recalculait 0,98 en comptant
 *     deux fois le même signal, et 775 titres réels passaient le seuil sans
 *     aucune vérification IA.
 */
class BlockClassifierWiringTest extends TestCase
{
    private function bloc(string $texte, BlockType $type = BlockType::Heading, float $confiance = 0.8): Block
    {
        return new Block(
            blockId: 'b1',
            type: $type,
            text: $texte,
            confidence: $confiance,
            // L'adaptateur remplit ce champ depuis la numérotation du texte
            // quand aucun style Word n'est présent.
            headingLevel: 1,
        );
    }

    /**
     * Un titre NON numéroté : ni chiffre romain, ni décimale, ni mot-clé.
     */
    private function blocSansMotif(string $texte, float $confiance = 0.9): Block
    {
        return new Block(
            blockId: 'b3',
            type: BlockType::Heading,
            text: $texte,
            confidence: $confiance,
            headingLevel: 1,
        );
    }

    // -------------------------------------------------------------------------
    // Le container
    // -------------------------------------------------------------------------

    public function test_le_container_fournit_un_classifieur_complet(): void
    {
        $classifieur = $this->app->make(BlockClassifier::class);

        $this->assertInstanceOf(BlockClassifier::class, $classifieur);

        // Les trois collaborateurs doivent être de vraies implémentations : un
        // `null` non détecté rendrait le cycle silencieusement inopérant.
        $reflexion = new \ReflectionClass($classifieur);

        foreach (['signals', 'detectBlocks', 'policy'] as $nom) {
            $propriete = $reflexion->getProperty($nom);
            $propriete->setAccessible(true);

            $this->assertNotNull(
                $propriete->getValue($classifieur),
                "BlockClassifier doit recevoir un vrai `$nom` par le container."
            );
        }
    }

    public function test_l_agregateur_du_container_preserve_la_confiance_de_l_adaptateur(): void
    {
        // Point de câblage critique : si le container construisait l'agrégateur
        // avec le comportement par défaut, le défaut de double comptage
        // reviendrait sans qu'aucun test unitaire ne le signale.
        $agregateur = $this->app->make(SignalAggregator::class);

        $reflexion = new \ReflectionClass($agregateur);
        $propriete = $reflexion->getProperty('preserveExistingHeadingConfidence');
        $propriete->setAccessible(true);

        $this->assertTrue(
            $propriete->getValue($agregateur),
            'L\'agrégateur du container doit préserver la confiance de l\'adaptateur.'
        );
    }

    // -------------------------------------------------------------------------
    // Préservation de la confiance
    // -------------------------------------------------------------------------

    public function test_un_titre_par_numerotation_seule_garde_la_confiance_de_l_adaptateur(): void
    {
        // « I. DEVELOPPEMENT » : numérotation présente, confiance d'adaptateur
        // 0,80. Le défaut corrigé : l'agrégateur voyait `headingLevel` renseigné
        // ET la numérotation, en concluait « les deux concordent » et renvoyait
        // 0,98 — le même signal compté deux fois.
        $agregateur = new SignalAggregator(preserveExistingHeadingConfidence: true);

        $evaluation = $agregateur->assess($this->bloc('I. DEVELOPPEMENT FRONTEND'));

        $this->assertSame(
            0.8,
            $evaluation['confidence'],
            'La confiance établie par l\'adaptateur doit être conservée, pas recalculée à 0,98.'
        );
        $formation = $evaluation['signals_used'] === ['adapter_heading_level']
            ? true
            : false;

        // Le signal doit être celui de l'adaptateur, et non le résultat d'un
        // double comptage « outline_level + text_pattern ».
        $this->assertTrue($formation, 'Le verdict doit nommer le signal de l\'adaptateur.');
    }

    public function test_un_titre_non_numerote_est_toujours_evalue_normalement(): void
    {
        // « Architecture du système » : aucun motif de numérotation NI mot-clé de
        // section dans le texte. Le `headingLevel` présent vient donc forcément
        // d'un VRAI style Word, et ce signal doit garder sa valeur haute.
        //
        // (Au passage : le mode par défaut de `HeadingNumberingPattern` détecte
        // « INTRODUCTION », « CONCLUSION », « SOMMAIRE » comme des mots-clés de
        // section — c'est intentionnel et mesuré sur le corpus, donc ces titres
        // relèvent légitimement de la préservation.)
        $agregateur = new SignalAggregator(preserveExistingHeadingConfidence: true);

        $evaluation = $agregateur->assess($this->bloc('Architecture du système documentaire'));

        $this->assertGreaterThanOrEqual(
            0.9,
            $evaluation['confidence'],
            'Un titre à style Word reste un signal fort.'
        );
    }

    public function test_un_mot_cle_de_section_releve_de_la_preservation(): void
    {
        // « INTRODUCTION » n'est pas un titre numéroté, mais le détecteur le
        // reconnaît comme un mot-clé de section (signal TEXTE, fiable). Le
        // parseur en déduit donc un niveau, et c'est cette déduction — et non un
        // style Word — qui remplissait `headingLevel` : la préservation doit
        // s'appliquer, sinon le même texte compterait comme deux signaux.
        $agregateur = new SignalAggregator(preserveExistingHeadingConfidence: true);

        $evaluation = $agregateur->assess($this->bloc('INTRODUCTION', confiance: 0.88));

        $this->assertSame(0.88, $evaluation['confidence']);
        $this->assertSame(['adapter_heading_level'], $evaluation['signals_used']);
    }

    public function test_le_mode_par_defaut_reproduit_l_ancien_comportement(): void
    {
        // Le mode par défaut doit rester inchangé : les tests existants de
        // SignalAggregator s'y appuient, et le changement de comportement doit
        // être un CHOIX explicite du site d'appel, pas un effet de bord.
        $agregateur = new SignalAggregator;

        $evaluation = $agregateur->assess($this->bloc('I. DEVELOPPEMENT FRONTEND'));

        $this->assertSame(
            0.98,
            $evaluation['confidence'],
            'Sans le mode explicite, le calcul d\'origine est conservé.'
        );
    }

    public function test_les_blocs_non_heading_ne_sont_pas_concernes(): void
    {
        // Le mode ne doit toucher que les titres : un paragraphe portant par
        // hasard un `headingLevel` ne doit pas voir sa confiance figée.
        $agregateur = new SignalAggregator(preserveExistingHeadingConfidence: true);

        $paragraphe = new Block(
            blockId: 'b2',
            type: BlockType::Paragraph,
            text: 'I. Ceci est une phrase de contenu, pas un titre.',
            confidence: 0.6,
            headingLevel: 1,
        );

        $evaluation = $agregateur->assess($paragraphe);

        $this->assertNotSame(0.6, $evaluation['confidence']);
    }

    // -------------------------------------------------------------------------
    // Le pipeline accepte l'étape de classification
    // -------------------------------------------------------------------------

    public function test_le_pipeline_applique_l_etape_de_classification_fournie(): void
    {
        $chemin = DocxFixture::create(
            DocxFixture::paragraph('I. Titre principal', outlineLevel: 0)
            .DocxFixture::paragraph('Un paragraphe de contenu.')
        );
        $pipeline = app(DocumentPipeline::class);

        config(['document.pipeline.v2' => true]);

        $appel = 0;
        $document = $pipeline->convert($chemin, 'doc-test', function ($structurel) use (&$appel) {
            $appel++;

            return $structurel;
        });

        $this->assertSame(1, $appel, 'L\'étape de classification doit être appelée exactement une fois.');
        $this->assertNotNull($document);

        DocxFixture::cleanup($chemin);
    }

    public function test_une_classification_en_echec_ne_perd_pas_le_document(): void
    {
        // Règle du projet : la classification ne perd JAMAIS de contenu. Une
        // défaillance de l'étape optionnelle doit laisser la structure
        // déterministe intacte, même en mode strict où le pipeline relance
        // normalement ses propres exceptions.
        $chemin = DocxFixture::create(
            DocxFixture::paragraph('I. Titre principal', outlineLevel: 0)
        );
        $pipeline = app(DocumentPipeline::class);

        config(['document.pipeline.v2' => true]);

        $document = $pipeline->convert($chemin, 'doc-test', function (): never {
            throw new \RuntimeException('Clé API absente');
        });

        $this->assertNotNull($document, 'Le document doit survivre à l\'échec de classification.');
        $this->assertGreaterThan(0, $document->count());

        DocxFixture::cleanup($chemin);
    }
}
