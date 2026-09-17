<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Classification;

use App\Document\Adapters\DocxNativeAdapter;
use App\Document\Classification\BlockClassifier;
use App\Document\Classification\ClassificationPolicy;
use App\Document\Classification\DetectBlocksTool;
use App\Document\Classification\SignalAggregator;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Services\OpenRouter\OpenRouterService;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Nœud racine pour les documents sans titre (étape 7).
 *
 * **Le problème mesuré.** 7 documents sur 51 du corpus réel n'ont AUCUN bloc de
 * type `heading` : devis, bilan d'une page, discours. Leur auteur a écrit un
 * titre sans appliquer de style Word et sans le numéroter, donc rien ne le
 * distingue d'un paragraphe. Sans titre, ces documents ne peuvent recevoir ni
 * sommaire ni hiérarchie — la mise en forme n'a pas de point d'entrée.
 *
 * **La règle testée est délibérément prudente.** La promotion n'a lieu que sur un
 * document DÉPOURVU de titre, et seulement si le premier paragraphe ressemble à un
 * intitulé. Sur un document qui a déjà des titres, deviner en ajouterait un faux.
 */
class RootHeadingPromotionTest extends TestCase
{
    private function classifier(): BlockClassifier
    {
        return new BlockClassifier(
            new SignalAggregator,
            // L'IA n'est jamais consultée dans ces tests (`aiEnabled: false`) :
            // l'outil est construit mais aucun appel réseau n'est émis.
            new DetectBlocksTool($this->app->make(OpenRouterService::class)),
            new ClassificationPolicy,
        );
    }

    /**
     * Document dont aucun bloc ne porte de style ni de numération de titre.
     */
    private function documentSansTitre(): StructuralDocument
    {
        $source = DocxFixture::create(
            DocxFixture::paragraph('DEVIS DU PROJET MEDLINK')
            .DocxFixture::paragraph('Projet MedLink - plateforme de centralisation des carnets de sante.')
            .DocxFixture::paragraph('Le projet vise a regrouper les informations dispersees.')
        );

        return (new DocxNativeAdapter)->convert($source, 'doc-sans-titre');
    }

    private function documentAvecTitre(): StructuralDocument
    {
        $styles = DocxFixture::style('Heading1', 'Heading 1', outlineLevel: 0);

        $source = DocxFixture::create(
            DocxFixture::paragraph('Introduction', styleId: 'Heading1')
            .DocxFixture::paragraph('Un paragraphe de contenu ordinaire.')
            .DocxFixture::paragraph('Un second paragraphe.'),
            $styles
        );

        return (new DocxNativeAdapter)->convert($source, 'doc-avec-titre');
    }

    // -------------------------------------------------------------------------
    // Création du nœud racine
    // -------------------------------------------------------------------------

    public function test_un_document_sans_titre_recoit_un_noeud_racine(): void
    {
        $avant = $this->documentSansTitre();

        // État initial : aucun titre, c'est la condition qui déclenche la promotion.
        $this->assertSame([], $avant->headings());

        $resultat = $this->classifier()->classify($avant, 'default', aiEnabled: false);
        $apres = $resultat['document'];

        $titres = $apres->headings();

        $this->assertCount(1, $titres, 'Un document sans titre doit en recevoir un.');
        $this->assertSame('DEVIS DU PROJET MEDLINK', $titres[0]->text);
        $this->assertSame(1, $titres[0]->headingLevel);
        $this->assertSame($titres[0]->blockId, $resultat['report']['root_heading_created']);
    }

    public function test_le_texte_du_titre_promu_traverse_sans_alteration(): void
    {
        // La promotion change le TYPE du bloc, jamais son contenu. C'est la règle
        // du projet : la classification ne perd ni ne réécrit aucun texte.
        $avant = $this->documentSansTitre();
        $premier = $avant->blocks[0]->text;

        $apres = $this->classifier()->classify($avant, 'default', aiEnabled: false)['document'];

        $this->assertSame($premier, $apres->blocks[0]->text);
        $this->assertSame(BlockType::Heading, $apres->blocks[0]->type);
    }

    public function test_la_confiance_du_titre_promu_reste_a_corriger(): void
    {
        // Une promotion est une DÉDUCTION, pas une observation : la confiance doit
        // rester sous le seuil d'acceptation automatique pour que l'utilisateur
        // puisse corriger et que l'IA puisse confirmer.
        $rapport = $this->classifier()->classify($this->documentSansTitre(), 'default', aiEnabled: false);

        $titre = $rapport['document']->headings()[0];

        $this->assertLessThan(
            Block::AUTO_ACCEPT_THRESHOLD,
            $titre->confidence,
            'Un titre déduit ne doit pas être accepté sans vérification.'
        );
    }

    // -------------------------------------------------------------------------
    // Prudence : ne pas deviner sur un document qui a déjà des titres
    // -------------------------------------------------------------------------

    public function test_un_document_avec_titres_n_est_pas_modifie(): void
    {
        $avant = $this->documentAvecTitre();
        $this->assertNotEmpty($avant->headings());

        $rapport = $this->classifier()->classify($avant, 'default', aiEnabled: false);

        $this->assertNull(
            $rapport['report']['root_heading_created'],
            'Aucun titre ne doit être inventé sur un document qui en a déjà.'
        );
        $this->assertCount(count($avant->headings()), $rapport['document']->headings());
    }

    public function test_une_phrase_de_contenu_n_est_pas_promue(): void
    {
        // Le premier paragraphe est une phrase complète (verbe conjugué,
        // ponctuation finale) : ce n'est pas un intitulé, donc aucune promotion.
        $source = DocxFixture::create(
            DocxFixture::paragraph('Le projet est de centraliser les carnets de sante.')
            .DocxFixture::paragraph('Un autre paragraphe de contenu.')
        );

        $document = (new DocxNativeAdapter)->convert($source, 'doc-phrase');

        $rapport = $this->classifier()->classify($document, 'default', aiEnabled: false);

        $this->assertNull(
            $rapport['report']['root_heading_created'],
            'Une phrase de contenu ne doit pas devenir un titre.'
        );
        $this->assertSame([], $rapport['document']->headings());
    }

    public function test_un_paragraphe_de_tete_trop_long_n_est_pas_promu(): void
    {
        $long = 'Ce document presente les elements du projet et detaille les etapes prevues '
            .'pour la mise en oeuvre ainsi que les ressources necessaires a sa realisation';

        $source = DocxFixture::create(
            DocxFixture::paragraph($long)
            .DocxFixture::paragraph('Suite du contenu.')
        );

        $document = (new DocxNativeAdapter)->convert($source, 'doc-long');

        $rapport = $this->classifier()->classify($document, 'default', aiEnabled: false);

        $this->assertNull($rapport['report']['root_heading_created']);
    }

    public function test_un_document_vide_ne_produit_aucun_titre(): void
    {
        $source = DocxFixture::create(DocxFixture::emptyParagraph());
        $document = (new DocxNativeAdapter)->convert($source, 'doc-vide');

        $rapport = $this->classifier()->classify($document, 'default', aiEnabled: false);

        $this->assertNull($rapport['report']['root_heading_created']);
    }

    public function test_un_document_sans_paragraphe_ne_produit_aucun_titre(): void
    {
        // Que des tableaux : rien à promouvoir, et surtout pas un tableau.
        $source = DocxFixture::create(
            DocxFixture::table([['Colonne A', 'Colonne B'], ['valeur 1', 'valeur 2']])
        );

        $document = (new DocxNativeAdapter)->convert($source, 'doc-tableau');

        $rapport = $this->classifier()->classify($document, 'default', aiEnabled: false);

        $this->assertNull($rapport['report']['root_heading_created']);
        $this->assertSame([], $rapport['document']->headings());
    }

    public function test_le_noeud_racine_sort_de_la_liste_des_clarifications(): void
    {
        // Le bloc promu est classé automatiquement : le laisser dans les
        // « passages à confirmer » demanderait à l'utilisateur de trancher une
        // question déjà résolue — et la promotion reste corrigeable depuis
        // l'écran de clarification puisque sa confiance est basse.
        $rapport = $this->classifier()->classify($this->documentSansTitre(), 'default', aiEnabled: false);

        $this->assertNotContains(
            $rapport['report']['root_heading_created'],
            $rapport['report']['clarification_needed']
        );
    }
}
