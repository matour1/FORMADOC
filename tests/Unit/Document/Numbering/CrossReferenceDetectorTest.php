<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Numbering;

use App\Document\Numbering\CrossReferenceDetector;
use App\Document\Numbering\NumberingPass;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\CrossRef;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests de la détection et de la résolution des renvois croisés.
 *
 * Enjeu : un renvoi faux est l'erreur la plus grave pour un mémoire — il envoie
 * le lecteur au mauvais endroit sans que personne ne s'en aperçoive. Le parti
 * pris est donc de **ne jamais bloquer** mais de **ne jamais affirmer** non plus
 * une résolution douteuse sans la signaler.
 */
class CrossReferenceDetectorTest extends TestCase
{
    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: 'doc-1', sourceType: 'docx', blocks: $blocks);
    }

    private function figure(string $id, ?string $original = null, ?string $final = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Figure,
            imageRef: $id.'.png',
            originalNumber: $original,
            finalNumber: $final,
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

    private function paragraph(string $id, string $text): Block
    {
        return new Block(blockId: $id, type: BlockType::Paragraph, text: $text);
    }

    // -------------------------------------------------------------------------
    // Détection
    // -------------------------------------------------------------------------

    public function test_un_renvoi_dans_un_paragraphe_est_detecte(): void
    {
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Comme le montre la Figure 3, le processus est linéaire.'),
            $this->caption('b_002', BlockCategory::Figure, '3'),
        ]));

        $this->assertSame(1, $result['detected']);
        $this->assertNotNull($result['document']->blockById('b_001')->crossRef);
    }

    public function test_plusieurs_renvois_dans_un_document_sont_detectes(): void
    {
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir Figure 1 et Tableau 2 pour le détail.'),
            $this->paragraph('b_002', 'Cf. Annexe A.'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
            $this->caption('b_004', BlockCategory::Table, '2'),
            $this->caption('b_005', BlockCategory::Annexe, 'A'),
        ]));

        $this->assertSame(3, $result['detected']);
    }

    public function test_une_legende_ne_se_renvoie_pas_a_elle_meme(): void
    {
        // Sans cette exclusion, une légende « Figure 3 » serait traitée comme un
        // renvoi à elle-même — auto-référence absurde.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->caption('b_001', BlockCategory::Figure, '3'),
        ]));

        $this->assertSame(0, $result['detected']);
        $this->assertNull($result['document']->blockById('b_001')->crossRef);
    }

    public function test_un_paragraphe_sans_renvoi_n_est_pas_touche(): void
    {
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Un paragraphe ordinaire sans aucun renvoi.'),
        ]));

        $this->assertSame(0, $result['detected']);
        $this->assertNull($result['document']->blockById('b_001')->crossRef);
    }

    // -------------------------------------------------------------------------
    // Résolution : numéro unique
    // -------------------------------------------------------------------------

    public function test_un_renvoi_vers_un_numero_unique_est_resolu_avec_certitude(): void
    {
        // Critère d'acceptation R4.6 : « résolution cas simple → confidence 1.0 ».
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir Figure 2 pour le schéma.'),
            $this->caption('b_002', BlockCategory::Figure, '2'),
        ]));

        $crossRef = $result['document']->blockById('b_001')->crossRef;

        $this->assertTrue($crossRef->isResolved());
        $this->assertSame('b_002', $crossRef->resolvedBlockId);
        $this->assertSame(1.0, $crossRef->resolutionConfidence);
        $this->assertFalse($crossRef->isLowConfidence());
    }

    public function test_un_renvoi_resolu_pointe_vers_le_bon_bloc(): void
    {
        // Critère d'acceptation R4.3 : « voir Figure 5 » pointe vers le bloc
        // renuméroté correct.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir Figure 5.'),
            $this->caption('b_002', BlockCategory::Figure, '3'),
            $this->caption('b_003', BlockCategory::Figure, '5'),
        ]));

        $this->assertSame('b_003', $result['document']->blockById('b_001')->crossRef->resolvedBlockId);
    }

    public function test_un_renvoi_vers_un_porteur_est_resolu(): void
    {
        // Le numéro peut vivre sur le porteur (cas théorique) ou sur la légende
        // (cas réel mesuré). Les deux doivent résoudre.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir Tableau 4.'),
            new Block(
                blockId: 'b_002',
                type: BlockType::Table,
                tableData: TableData::fromGrid([['A']]),
                originalNumber: '4',
            ),
        ]));

        $this->assertSame('b_002', $result['document']->blockById('b_001')->crossRef->resolvedBlockId);
    }

    // -------------------------------------------------------------------------
    // Résolution : numéro ambigu → proximité
    // -------------------------------------------------------------------------

    public function test_un_numero_duplique_est_resolu_par_proximite_dans_l_ordre(): void
    {
        // Critère d'acceptation R4.4 : « numéro dupliqué → résolution par
        // proximité, SANS formulaire bloquant ».
        //
        // Mesure : 98 documents du corpus ont des doublons de numéros.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir Tableau 2.'),
            $this->caption('b_002', BlockCategory::Table, '2'),
            $this->paragraph('b_003', 'Texte de liaison.'),
            $this->paragraph('b_004', 'Voir Tableau 2 à nouveau.'),
            $this->caption('b_005', BlockCategory::Table, '2'),
        ]));

        // Le premier renvoi est proche du premier « Tableau 2 »…
        $this->assertSame('b_002', $result['document']->blockById('b_001')->crossRef->resolvedBlockId);
        // …et le second du second : la proximité se juge par INDEX, jamais par
        // distance en caractères.
        $this->assertSame('b_005', $result['document']->blockById('b_004')->crossRef->resolvedBlockId);
    }

    public function test_une_resolution_par_proximite_est_signalee_comme_incertaine(): void
    {
        // Deux candidats équidistants de part et d'autre du renvoi : la
        // proximité ne tranche pas. On résout quand même (sinon le renvoi
        // resterait manifestement faux), mais la confiance passe sous le seuil
        // de signalement.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->caption('b_001', BlockCategory::Figure, '1'),
            $this->paragraph('b_002', 'Voir Figure 1.'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
        ]));

        $crossRef = $result['document']->blockById('b_002')->crossRef;

        $this->assertTrue($crossRef->isResolved());
        $this->assertTrue($crossRef->isLowConfidence());
        $this->assertLessThan(1.0, $crossRef->resolutionConfidence);
        $this->assertNotEmpty($result['low_confidence']);
    }

    public function test_un_numero_inexistant_est_resolu_par_proximite(): void
    {
        // Mesure : 3 documents ont des trous (« Annexe 37 » seule). Le numéro
        // cité n'existe nulle part : on élargit aux éléments de la catégorie et
        // la proximité tranche, avec un signalement.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir Annexe 37.'),
            $this->caption('b_002', BlockCategory::Annexe, '1'),
        ]));

        $crossRef = $result['document']->blockById('b_001')->crossRef;

        $this->assertTrue($crossRef->isResolved());
        $this->assertSame('b_002', $crossRef->resolvedBlockId);
        $this->assertTrue($crossRef->isLowConfidence());
    }

    public function test_un_numero_duplique_sans_candidat_reste_non_resolu(): void
    {
        // Aucun élément de la catégorie : rien à proposer. On ne fabrique pas
        // de cible — un renvoi faux est pire qu'un renvoi non résolu.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir Planche 3.'),
        ]));

        $crossRef = $result['document']->blockById('b_001')->crossRef;

        $this->assertNotNull($crossRef);
        $this->assertFalse($crossRef->isResolved());
        $this->assertSame(1, $result['unresolved']);
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation : aucun blocage
    // -------------------------------------------------------------------------

    public function test_aucun_renvoi_ambigu_ne_declenche_de_formulaire(): void
    {
        // Critère d'acceptation R4.5, test explicite : « Aucun
        // ask_user_clarification déclenché par un renvoi ambigu ».
        //
        // On vérifie qu'aucun composant du module Numbering ne référence un
        // mécanisme de question à l'utilisateur : ni le service de clarification,
        // ni le tool correspondant.
        $fichiers = glob(base_path('app/Document/Numbering/*.php')) ?: [];
        $fautifs = [];

        foreach ($fichiers as $fichier) {
            $contenu = (string) file_get_contents($fichier);

            if (preg_match('/AskUserClarification|ClarificationService|ask_user_clarification/i', $contenu) === 1) {
                $fautifs[] = basename($fichier);
            }
        }

        $this->assertSame(
            [],
            $fautifs,
            'Un renvoi ambigu doit être signalé dans le rapport de fin de traitement, '
            ."jamais par un formulaire bloquant.\nFichiers fautifs :\n - ".implode("\n - ", $fautifs)
        );
    }

    public function test_le_signalement_d_un_renvoi_ambigu_n_est_jamais_bloquant(): void
    {
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir Figure 1.'),
            $this->caption('b_002', BlockCategory::Figure, '1'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
        ]));

        // Le document est produit intégralement : aucun bloc perdu, aucune
        // exception levée. Le signalement accompagne, il ne remplace pas.
        $this->assertSame(3, $result['document']->count());
        $this->assertIsArray($result['low_confidence']);
    }

    // -------------------------------------------------------------------------
    // Intégration avec la renumérotation
    // -------------------------------------------------------------------------

    public function test_la_resolution_utilise_les_numeros_calcules(): void
    {
        // La détection s'appuie sur les numéros *calculés* : la lancer avant la
        // renumérotation résoudrait sur des valeurs fausses dans 98 documents.
        $document = $this->document([
            $this->paragraph('b_001', 'Voir Figure 1.'),
            $this->caption('b_002', BlockCategory::Figure, '7'),
        ]);

        $renumbered = (new NumberingPass)->run($document)['document'];
        $result = (new CrossReferenceDetector)->detect($renumbered);

        // Le renvoi citait « Figure 1 » et la figure portait « 7 » : après
        // renumérotation elle devient « 1 », donc le renvoi est enfin correct.
        $this->assertSame('1', $result['document']->blockById('b_002')->displayNumber());
        $this->assertSame('b_002', $result['document']->blockById('b_001')->crossRef->resolvedBlockId);
    }

    // -------------------------------------------------------------------------
    // Cas limites
    // -------------------------------------------------------------------------

    public function test_un_document_vide_ne_provoque_pas_d_erreur(): void
    {
        $result = (new CrossReferenceDetector)->detect($this->document([]));

        $this->assertSame(0, $result['detected']);
        $this->assertSame(0, $result['resolved']);
        $this->assertSame([], $result['low_confidence']);
    }

    public function test_le_document_d_origine_n_est_pas_modifie(): void
    {
        $document = $this->document([
            $this->paragraph('b_001', 'Voir Figure 2.'),
            $this->caption('b_002', BlockCategory::Figure, '2'),
        ]);

        (new CrossReferenceDetector)->detect($document);

        $this->assertNull($document->blockById('b_001')->crossRef);
    }

    public function test_le_numero_d_un_renvoi_ambigu_est_signale_dans_le_rapport(): void
    {
        // Deux candidats équidistants : la confiance doit passer sous 0,7.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->caption('b_001', BlockCategory::Figure, '1'),
            $this->paragraph('b_002', 'Voir Figure 1.'),
            $this->caption('b_003', BlockCategory::Figure, '1'),
        ]));

        $this->assertCount(1, $result['low_confidence']);
        $this->assertSame('b_002', $result['low_confidence'][0]['block_id']);
        $this->assertLessThan(
            CrossRef::LOW_CONFIDENCE_THRESHOLD,
            $result['low_confidence'][0]['confidence']
        );
    }

    public function test_une_minuscule_sur_le_mot_cle_est_reconnue(): void
    {
        // Les auteurs écrivent « la figure 2 » en minuscule : le mot-clé tolère
        // la casse. Le numéro, lui, reste en chiffres (aucune ambiguïté).
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Comme le montre la figure 2 ci-après.'),
            $this->caption('b_002', BlockCategory::Figure, '2'),
        ]));

        $this->assertTrue($result['document']->blockById('b_001')->crossRef->isResolved());
    }

    public function test_un_mot_commencant_par_une_lettre_n_est_pas_un_numero_de_renvoi(): void
    {
        // Garde-fou essentiel : sans lui, le « ci » de « la figure ci-dessous »
        // serait pris pour un numéro d'annexe et déclencherait une fausse
        // résolution — un renvoi inventé.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir la figure ci-dessous pour le détail.'),
        ]));

        $this->assertSame(0, $result['detected']);
    }

    public function test_un_renvoi_suivi_d_un_ordinal_n_est_pas_detecte(): void
    {
        // Régression trouvée sur le corpus réel : « Tableau n°14 » produisait un
        // renvoi vers un prétendu « Tableau n ». La détection du mot-clé étant
        // insensible à la casse, le numéro alphabétique doit être validé
        // séparément — et une minuscule n'est jamais un numéro.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Voir le Tableau n°14 pour le détail.'),
        ]));

        $this->assertSame(0, $result['detected']);
    }

    public function test_un_renvoi_suivi_d_un_mot_en_apostrophe_n_est_pas_detecte(): void
    {
        // Autre cas réel : « TABLEAU D'AMORTISSEMENT » produisait un renvoi vers
        // un prétendu « Tableau D ».
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', "TABLEAU D'AMORTISSEMENT DES IMMOBILISATIONS"),
        ]));

        $this->assertSame(0, $result['detected']);
    }

    public function test_un_renvoi_vers_une_annexe_en_majuscule_reste_detecte(): void
    {
        // La convention d'annexe (« Annexe B ») doit continuer de fonctionner :
        // le garde-fou rejette les minuscules, pas les majuscules.
        $result = (new CrossReferenceDetector)->detect($this->document([
            $this->paragraph('b_001', 'Cf. Annexe B pour les détails.'),
            $this->caption('b_002', BlockCategory::Annexe, 'B'),
        ]));

        $this->assertSame(1, $result['detected']);
        $this->assertTrue($result['document']->blockById('b_001')->crossRef->isResolved());
    }
}
