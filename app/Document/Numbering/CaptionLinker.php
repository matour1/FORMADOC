<?php

declare(strict_types=1);

namespace App\Document\Numbering;

use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;

/**
 * Rattache chaque légende à son porteur (figure, tableau, annexe, planche).
 *
 * **Pourquoi ce composant existe** — la mesure du corpus réel (526 documents) a
 * montré que `linked_block_id` est vide pour **861 légendes sur 861**. Le champ
 * existe dans le schéma mais aucun adaptateur ne le remplit : la numérotation
 * n'a donc aucun chaînon entre « Figure 3 » et l'image qu'elle désigne.
 * C'est le travail que R4 doit accomplir avant de pouvoir renuméroter.
 *
 * **Comment la liaison est établie** — par trois règles successives, dans cet
 * ordre (l'ordre est significatif, voir plus bas) :
 *
 *  1. **Séries alignées** (confiance 0,9) : plusieurs porteurs suivis du même
 *     nombre de légendes (« Figure 1, Figure 2, Légende 1, Légende 2 »).
 *     L'appariement rang par rang suit l'ordre de lecture.
 *  2. **Adjacence stricte** (confiance 1,0) : un seul porteur voisin immédiat.
 *     Le cas le plus sûr, et le plus courant (légende juste sous sa figure).
 *  3. **Fenêtre bornée** (confiance dégressive) : un porteur unique dans une
 *     fenêtre de `MAX_DISTANCE` blocs. Plusieurs candidats proches signifient
 *     que le rattachement serait une supposition : on ne devine pas, on signale.
 *
 * **Pourquoi l'appariement de séries passe AVANT l'adjacence** — l'adjacence
 * seule croise les paires dans une disposition en séries : elle lierait
 * « Légende 1 » à « Figure 2 » (sa voisine immédiate) et « Légende 2 » à
 * « Figure 2 » également, en affirmant le tout avec une confiance de 1,0. Deux
 * légendes pour une même figure, dont l'une est fausse **et présentée comme
 * certaine**. La détection de séries corrige ce cas avant qu'il ne se produise ;
 * l'adjacence ne s'applique donc qu'aux dispositions sans série.
 *
 * **Ce que ce composant refuse de faire** — inventer une liaison. Une légende
 * non rattachable reste non rattachée et est signalée. Un renvoi faux est pire
 * qu'un renvoi absent : il enverrait le lecteur vers un mauvais tableau.
 */
final class CaptionLinker
{
    /**
     * Distance maximale (en blocs, dans l'ordre du document) d'un rattachement.
     *
     * Fixée à 2 d'après la mesure du corpus : 66 légendes sont adjacentes à
     * leur porteur et 24 en sont séparées par un seul bloc (un commentaire
     * intercalé). Au-delà, la proximité ne veut plus rien dire — et **43 % du
     * corpus (366 légendes) est justement à distance 3 ou plus**. Élargir la
     * fenêtre pour « en rattacher plus » ferait surtout produire des liaisons
     * fausses sur la majorité des cas ; on préfère les signaler.
     */
    public const MAX_DISTANCE = 2;

    /**
     * Confiance d'un rattachement par adjacence stricte.
     */
    public const CONFIDENCE_ADJACENT = 1.0;

    /**
     * Confiance d'un rattachement par appariement de séries alignées.
     *
     * Très haute sans être certaine : l'appariement rang par rang est fiable
     * quand les longueurs correspondent, mais repose sur une hypothèse de
     * disposition qu'un document atypique pourrait invalider.
     */
    public const CONFIDENCE_SERIES = 0.9;

    /**
     * Confiance minimale d'un rattachement par fenêtre.
     */
    public const CONFIDENCE_MIN_WINDOW = 0.55;

    /**
     * Types de blocs qui peuvent être le porteur d'une légende.
     */
    private const CARRIER_TYPES = [
        BlockType::Figure,
        BlockType::Table,
        BlockType::Annexe,
        BlockType::Planche,
    ];

    /**
     * Résultat du rattachement.
     */
    private int $linked = 0;

    private int $orphans = 0;

    /** @var array<int, string> */
    private array $orphanIds = [];

    /** @var array<int, string> */
    private array $lowConfidenceIds = [];

    /**
     * Rattache les légendes de leur porteur et retourne le document complété.
     *
     * Les blocs sont remplacés en une seule passe (`replaceBlocks`), ce qui
     * préserve l'ordre et évite N reconstructions du document.
     *
     * @return array{
     *     document: StructuralDocument,
     *     linked: int,
     *     orphans: array<int, string>,
     *     low_confidence: array<int, string>
     * }
     */
    public function link(StructuralDocument $document): array
    {
        $this->linked = 0;
        $this->orphans = 0;
        $this->orphanIds = [];
        $this->lowConfidenceIds = [];

        $blocks = $document->blocks;
        $carriers = $this->carrierIndexes($blocks);
        $updated = [];

        foreach ($blocks as $index => $block) {
            if ($block->type !== BlockType::Caption) {
                continue;
            }

            $match = $this->findCarrier($blocks, $carriers, $index, $block);

            if ($match === null) {
                // Aucune cible crédible : la légende reste telle quelle. On ne
                // fabrique pas de liaison — un renvoi faux est pire qu'absent.
                $this->orphans++;
                $this->orphanIds[] = $block->blockId;

                continue;
            }

            [$carrierId, $confidence] = $match;

            $updated[] = $block->withLinkedBlockId($carrierId);
            $this->linked++;

            if ($confidence < 1.0) {
                $this->lowConfidenceIds[] = $block->blockId;
            }

            // Le porteur référence la légende en retour : c'est ce lien
            // bidirectionnel qui permet au rendu de garder les deux solidaires
            // (voir `FigureFlowFormatter`).
            $carrierIndex = $this->indexOfBlock($blocks, $carrierId);

            if ($carrierIndex !== null) {
                $updated[] = $blocks[$carrierIndex]->withLinkedBlockId($block->blockId);
            }
        }

        return [
            'document' => $document->replaceBlocks($updated),
            'linked' => $this->linked,
            'orphans' => $this->orphanIds,
            'low_confidence' => $this->lowConfidenceIds,
        ];
    }

    /**
     * Trouve le porteur le plus plausible d'une légende.
     *
     * @param  array<int, Block>  $blocks
     * @param  array<string, array<int, int>>  $carriers  Index des porteurs par catégorie
     * @return null|array{0: string, 1: float} [blockId, confiance] ou null
     */
    private function findCarrier(array $blocks, array $carriers, int $index, Block $caption): ?array
    {
        $category = $caption->effectiveCategory();

        if ($category === null) {
            return null;
        }

        $candidats = $carriers[$category->value] ?? [];

        if ($candidats === []) {
            return null;
        }

        // --- Règle 1 : séries alignées ---
        // Disposition courante dans les rapports : plusieurs figures puis leurs
        // légendes (« Figure 1, Figure 2, Légende 1, Légende 2 »). Quand la série
        // de légendes et la série de porteurs se suivent ET ont la MÊME longueur,
        // l'appariement rang par rang suit l'ordre de lecture.
        //
        // Cette règle est évaluée AVANT l'adjacence : sans elle, l'adjacence
        // lierait les deux légendes à la même figure en affirmant une confiance
        // de 1,0 — un appariement faux présenté comme certain.
        $aligne = $this->alignedRunMatch($blocks, $index, $category);

        if ($aligne !== null) {
            return [$aligne, self::CONFIDENCE_SERIES];
        }

        // Fenêtre de recherche élargie à MAX_DISTANCE : sert à la fois à
        // qualifier l'adjacence (un seul porteur alentour ?) et de repli.
        $proches = array_values(array_filter(
            $candidats,
            static fn (int $position): bool => abs($position - $index) <= self::MAX_DISTANCE
        ));

        // --- Règle 2 : porteur adjacent ---
        // Une légende se place juste SOUS sa figure/tableau (convention
        // académique), parfois juste AU-DESSUS.
        $adjacents = array_values(array_filter(
            $proches,
            static fn (int $position): bool => abs($position - $index) <= 1
        ));

        if (count($adjacents) === 1) {
            // Un seul porteur dans toute la fenêtre : la liaison est certaine.
            if (count($proches) === 1) {
                return [$blocks[$adjacents[0]]->blockId, self::CONFIDENCE_ADJACENT];
            }

            // Plusieurs porteurs alentour mais un seul adjacent : très probable
            // sans être certain. Annoncer 1,0 ici serait un abus de confiance —
            // c'est exactement le cas « Figure 1, Figure 2, Légende 1 ».
            return [$blocks[$adjacents[0]]->blockId, self::CONFIDENCE_SERIES];
        }

        // --- Règle 3 : un seul porteur dans la fenêtre ---
        if (count($proches) !== 1) {
            // Aucun candidat, ou plusieurs candidats également plausibles : seul
            // le numéro écrit dans la légende permettrait de trancher, et il
            // n'est pas fiable. On ne devine pas — on signale.
            return null;
        }

        return [$blocks[$proches[0]]->blockId, $this->confidenceForDistance(abs($proches[0] - $index))];
    }

    /**
     * Apparie une série de légendes avec la série de porteurs qui la précède.
     *
     * Le critère est volontairement strict : les deux séries doivent être
     * contiguës et de **même longueur**. Si les longueurs diffèrent, il n'existe
     * pas de correspondance bijective évidente et on ne devine pas — c'est ce qui
     * distingue cette règle d'une supposition.
     *
     * @param  array<int, Block>  $blocks
     */
    private function alignedRunMatch(array $blocks, int $index, BlockCategory $category): ?string
    {
        // Rang de la légende dans sa série contiguë de même catégorie.
        $rang = 0;
        for ($j = $index - 1; $j >= 0; $j--) {
            if (! $this->isSameCategoryCaption($blocks[$j], $category)) {
                break;
            }
            $rang++;
        }

        $debutSerie = $index - $rang;
        $total = count($blocks);

        // Longueur totale de la série de légendes.
        $longueurSerie = 0;
        for ($j = $debutSerie; $j < $total; $j++) {
            if (! $this->isSameCategoryCaption($blocks[$j], $category)) {
                break;
            }
            $longueurSerie++;
        }

        // Série de porteurs immédiatement avant la série de légendes.
        $debutPorteurs = null;
        $nbPorteurs = 0;

        for ($j = $debutSerie - 1; $j >= 0; $j--) {
            if (! in_array($blocks[$j]->type, self::CARRIER_TYPES, true)
                || $blocks[$j]->type->category() !== $category) {
                break;
            }

            $debutPorteurs = $j;
            $nbPorteurs++;
        }

        // Correspondance bijective exigée : longueurs identiques.
        if ($debutPorteurs === null || $nbPorteurs !== $longueurSerie) {
            return null;
        }

        $cible = $debutPorteurs + $rang;

        return $cible < $debutSerie ? $blocks[$cible]->blockId : null;
    }

    /**
     * Le bloc est-il une légende de la catégorie donnée ?
     */
    private function isSameCategoryCaption(Block $block, BlockCategory $category): bool
    {
        return $block->type === BlockType::Caption
            && $block->effectiveCategory() === $category;
    }

    /**
     * Confiance décroissante selon la distance.
     *
     * À distance 1 la liaison est quasiment certaine (elle n'arrive ici que
     * s'il n'existe qu'un porteur dans la fenêtre). À distance 2, un paragraphe
     * de commentaire s'est intercalé : la confiance reste haute mais passe sous
     * le seuil d'acceptation automatique, donc le rattachement est signalé.
     */
    private function confidenceForDistance(int $distance): float
    {
        return match (true) {
            $distance <= 1 => self::CONFIDENCE_ADJACENT,
            default => 0.8,
        };
    }

    /**
     * Index des blocs porteurs, regroupés par catégorie.
     *
     * Prépare l'index une seule fois : la recherche par légende devient une
     * simple lecture de tableau au lieu d'un parcours complet du document
     * (une légende par bloc × un document par légende serait quadratique).
     *
     * @param  array<int, Block>  $blocks
     * @return array<string, array<int, int>>
     */
    private function carrierIndexes(array $blocks): array
    {
        $carriers = [];

        foreach ($blocks as $index => $block) {
            if (! in_array($block->type, self::CARRIER_TYPES, true)) {
                continue;
            }

            $category = $block->type->category();

            if ($category === null) {
                continue;
            }

            $carriers[$category->value][] = $index;
        }

        return $carriers;
    }

    /**
     * Index d'un bloc par son identifiant.
     *
     * @param  array<int, Block>  $blocks
     */
    private function indexOfBlock(array $blocks, string $blockId): ?int
    {
        foreach ($blocks as $index => $block) {
            if ($block->blockId === $blockId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Statistiques du rattachement, pour le rapport de traitement.
     *
     * @return array{linked: int, orphans: int, low_confidence: int}
     */
    public function statistics(): array
    {
        return [
            'linked' => $this->linked,
            'orphans' => $this->orphans,
            'low_confidence' => count($this->lowConfidenceIds),
        ];
    }
}
