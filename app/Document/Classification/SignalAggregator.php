<?php

declare(strict_types=1);

namespace App\Document\Classification;

use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;

/**
 * Agrégation pondérée des signaux déterministes de classification.
 *
 * **Rôle budgétaire central.** Ce composant décide, pour chaque bloc, si la
 * classification est assez sûre pour être appliquée SANS appel au modèle de
 * langage. Sur les documents du projet, cela évite des milliers d'appels :
 *
 * | Mesure | Valeur |
 * |--------|--------|
 * | Blocs ambigus détectés par le parseur | 12 % des blocs |
 * | Signaux « pattern texte » (gratuits) | confiance ≥ 0,9 |
 *
 * Principe de priorité (REFONTE_ARCHITECTURE.md §7) :
 *
 * 1. **Pattern texte explicite** — le plus fiable, car **saisi par l'auteur** :
 *    `Figure 1 :`, `1.1 Contexte`, `CHAPITRE II`. Fiabilité maximale, coût nul.
 * 2. **Signaux visuels** — taille de police, gras, `w:outlineLvl`, position.
 *    Fiables mais indirects : un titre peut être mal stylé.
 *
 * Ne jamais deviner sans signal : un bloc sans aucun signal reste ambigu et
 * sera clarifié, jamais classé d'office.
 */
final class SignalAggregator
{
    /**
     * Poids des signaux, du plus fiable au plus faible.
     *
     * Ces valeurs sont le cœur de la décision économique du pipeline : elles
     * déterminent combien de blocs atteignent le seuil de 0,85 sans appel IA.
     *
     * @var array<string, float>
     */
    private const SIGNAL_WEIGHTS = [
        // Signal texte explicite : l'auteur a écrit « Figure 3 » ou « 1.1 ».
        'text_pattern' => 0.95,
        // Style Word explicite : l'auteur a appliqué « Titre 1 ».
        'outline_level' => 0.92,
        // Style de légende reconnu (« Légende », « Caption »).
        'caption_style' => 0.9,
        // Ligne isolée, courte, sans ponctuation finale.
        'isolated_line' => 0.82,
        // Numérotation de liste sans texte explicite.
        'list_numbering' => 0.6,
    ];

    /**
     * Pénalité appliquée quand un signal CONTREDIT le type déduit.
     *
     * Exemple : un paragraphe long en gras n'est pas un titre ; un texte court
     * sans majuscule initiale n'en est probablement pas un non plus.
     *
     * @var array<string, float>
     */
    private const CONTRADICTION_PENALTIES = [
        // Texte trop long pour un titre (phrase complète).
        'too_long' => 0.35,
        // Termine par un point : c'est une phrase, pas un intitulé.
        'ends_with_period' => 0.2,
        // Contient des marqueurs de phrase (« est », « sont », « a été »).
        'sentence_markers' => 0.25,
    ];

    private readonly HeadingNumberingPattern $numbering;

    private readonly CaptionPattern $captions;

    /**
     * Préserve-t-on la confiance établie par l'adaptateur d'entrée (R1) ?
     *
     * **Le problème que ce mode résout.** `Block::$headingLevel` a DEUX origines
     * possibles, et l'agrégateur ne peut pas les distinguer :
     *
     *  1. un style Word `w:outlineLvl` — signal fort, corroboré par la mise en
     *     forme réelle de l'auteur ;
     *  2. la SEULE numérotation du texte (« I. », « 1.1 », « A. ») — l'adaptateur
     *     remplit le champ avec le niveau qu'il a déduit du texte, sans qu'aucun
     *     style ne confirme.
     *
     * Dans le cas 2, `assessHeadingWithBothSignals()` voit `styleLevel` ET
     * `numbering` et conclut « les deux signaux concordent » à 0,98 — alors qu'en
     * réalité le même signal a été compté deux fois. La mesure sur 51 documents
     * réels est sans ambiguïté : **775 titres** que l'adaptateur estime à 0,80
     * (numérotation seule, fiable) étaient recalculés à 0,98, donc acceptés sans
     * aucune vérification IA.
     *
     * Activer ce mode préserve la confiance de l'adaptateur pour les blocs dont
     * il a explicitement tranché l'ambiguïté (`heading_level` renseigné par la
     * numérotation). Les autres signaux — motifs texte, gras, longueur — restent
     * recalculés normalement, car ils se déduisent du contenu sans cette
     * confusion.
     */
    public function __construct(
        private readonly bool $preserveExistingHeadingConfidence = false,
    ) {
        $this->numbering = new HeadingNumberingPattern;
        $this->captions = new CaptionPattern;
    }

    /**
     * Évalue un bloc et retourne sa classification déterministe.
     *
     * @param  Block  $block  Bloc issu du parseur
     * @return array{
     *     type: BlockType,
     *     confidence: float,
     *     signals_used: array<int, string>,
     *     heading_level: null|int,
     *     category: null|string,
     *     needs_ai: bool,
     *     reason: string
     * } `needs_ai` est LA décision qui évite (ou déclenche) un appel payant.
     */
    public function assess(Block $block): array
    {
        $text = $block->text;

        // --- 0. Types STRUCTURELS : le XML est un signal définitif ---
        //
        // Un tableau n'est pas « probablement » un tableau : le parseur l'a lu
        // dans un élément `<w:tbl>`. Un en-tête vient d'un `<w:headerReference>`.
        // Reclasser ces blocs par heuristique serait une régression — le texte
        // aplati d'un tableau ressemble à une phrase longue et se ferait
        // effectivement prendre pour un paragraphe.
        $structural = $this->structuralTypeOf($block);
        if ($structural !== null) {
            return $structural;
        }

        // --- 1. Patterns texte (priorité absolue, coût nul) ---
        $caption = $this->captions->detect($text);
        if ($caption !== null && ! $this->captions->isListEntry($text)) {
            return $this->verdict(
                type: BlockType::Caption,
                confidence: self::SIGNAL_WEIGHTS['text_pattern'],
                signals: ['text_pattern:caption'],
                headingLevel: null,
                category: $caption['category']->value,
                reason: 'Motif de légende reconnu (« '.$caption['matched'].' »).',
            );
        }

        $numbering = $this->numbering->detect($text);

        // --- 2. Signal de style contre signal de numérotation ---
        $styleLevel = $block->headingLevel;

        // Confiance déjà tranchée par l'adaptateur : on la préserve.
        //
        // Le test porte sur `numbering !== null` ET non sur `styleLevel` seul :
        // si le TEXTE porte une numérotation, alors l'adaptateur a déduit
        // `heading_level` de ce texte (et non d'un style Word), donc le faire
        // « corroborer » par ce même texte reviendrait à compter un signal deux
        // fois. Quand le texte n'est PAS numéroté, `headingLevel` vient
        // nécessairement d'un style Word : le calcul normal reste valable.
        if ($this->preserveExistingHeadingConfidence
            && $styleLevel !== null
            && $numbering !== null
            && $block->type === BlockType::Heading) {
            return $this->verdict(
                type: BlockType::Heading,
                confidence: $block->confidence,
                signals: ['adapter_heading_level'],
                headingLevel: $styleLevel,
                category: null,
                reason: 'Niveau de titre établi par le parseur à partir du texte, sans style Word.',
            );
        }

        if ($styleLevel !== null && $numbering !== null) {
            return $this->assessHeadingWithBothSignals($block, $styleLevel, $numbering);
        }

        if ($styleLevel !== null) {
            // Style seul : signal fort mais non corroboré par le texte.
            return $this->verdict(
                type: BlockType::Heading,
                confidence: self::SIGNAL_WEIGHTS['outline_level'],
                signals: ['outline_level'],
                headingLevel: $styleLevel,
                category: null,
                reason: 'Style de titre Word (w:outlineLvl).',
            );
        }

        if ($numbering !== null) {
            // Numérotation seule : intention claire, profondeur moins sûre.
            $confidence = $numbering['reliable']
                ? self::SIGNAL_WEIGHTS['text_pattern'] - 0.1
                : self::SIGNAL_WEIGHTS['list_numbering'];

            return $this->verdict(
                type: BlockType::Heading,
                confidence: $confidence,
                signals: ['text_pattern:'.$numbering['kind']],
                headingLevel: $numbering['level'],
                category: null,
                reason: 'Numérotation détectée sans style Word.',
            );
        }

        // --- 3. Signaux visuels faibles ---
        return $this->assessByVisualSignals($block);
    }

    /**
     * Évalue un lot de blocs et retourne la partition décisive.
     *
     * @param  array<int, Block>  $blocks
     * @return array{
     *     confident: array<int, array{block: Block, assessment: array<string, mixed>}>,
     *     ambiguous: array<int, array{block: Block, assessment: array<string, mixed>}>,
     *     stats: array{total: int, confident: int, ambiguous: int, free_ratio: float}
     * }
     */
    public function partition(array $blocks, float $threshold = Block::AUTO_ACCEPT_THRESHOLD): array
    {
        $confident = [];
        $ambiguous = [];

        foreach ($blocks as $block) {
            $assessment = $this->assess($block);

            if ($assessment['confidence'] >= $threshold) {
                $confident[] = ['block' => $block, 'assessment' => $assessment];

                continue;
            }

            $ambiguous[] = ['block' => $block, 'assessment' => $assessment];
        }

        $total = count($blocks);

        return [
            'confident' => $confident,
            'ambiguous' => $ambiguous,
            'stats' => [
                'total' => $total,
                'confident' => count($confident),
                'ambiguous' => count($ambiguous),
                // Part des blocs classés SANS appel IA : c'est le gain budgétaire.
                'free_ratio' => $total === 0 ? 0.0 : count($confident) / $total,
            ],
        ];
    }

    /**
     * Contexte compact d'un bloc destiné au modèle de langage.
     *
     * On envoie uniquement les SIGNAUX structurels et un extrait de texte, pas
     * le bloc complet : cela réduit le coût en tokens et évite de noyer le
     * modèle dans des métadonnées inutiles.
     *
     * @return array<string, mixed>
     */
    public function contextFor(Block $block, int $maxTextLength = 160): array
    {
        return [
            'block_id' => $block->blockId,
            'text' => mb_substr($block->text, 0, $maxTextLength),
            'font_size' => $block->fontSize,
            'is_bold' => $block->isBold,
            'indent_level' => $block->indentLevel,
            'position_y' => $block->positionY,
            'current_type' => $block->type->value,
            'current_confidence' => round($block->confidence, 2),
        ];
    }

    // -------------------------------------------------------------------------
    // Décisions internes
    // -------------------------------------------------------------------------

    /**
     * Classifie un bloc dont le type est établi par la STRUCTURE XML.
     *
     * Ces types ne relèvent pas d'une heuristique : le parseur les a lus dans un
     * élément OOXML explicite. Les reclasser par analyse de texte serait une
     * régression — le texte aplati d'un tableau (toutes ses cellules concaténées)
     * ressemble à une phrase longue et se ferait prendre pour un paragraphe,
     * faisant disparaître le tableau de la structure.
     *
     * Seul un élément dont le type dépend d'une INTERPRÉTATION reste soumis à
     * l'analyse : figure (image avec ou sans légende) et légende.
     *
     * @return null|array<string, mixed> null si le type demande une interprétation
     */
    private function structuralTypeOf(Block $block): ?array
    {
        return match ($block->type) {
            BlockType::Table => $this->verdict(
                type: BlockType::Table,
                confidence: 0.98,
                signals: ['xml_element:w:tbl'],
                headingLevel: null,
                category: $block->category?->value,
                reason: 'Élément tableau explicite dans le XML.',
            ),
            BlockType::Header => $this->verdict(
                type: BlockType::Header,
                confidence: 0.98,
                signals: ['xml_element:w:headerReference'],
                headingLevel: null,
                category: null,
                reason: 'En-tête de page.',
            ),
            BlockType::Footer => $this->verdict(
                type: BlockType::Footer,
                confidence: 0.98,
                signals: ['xml_element:w:footerReference'],
                headingLevel: null,
                category: null,
                reason: 'Pied de page.',
            ),
            BlockType::Annexe => $this->verdict(
                type: BlockType::Annexe,
                confidence: 0.95,
                signals: ['explicit_type:annexe'],
                headingLevel: null,
                category: $block->category?->value ?? 'annexe',
                reason: 'Bloc marqué comme annexe par le parseur.',
            ),
            BlockType::Planche => $this->verdict(
                type: BlockType::Planche,
                confidence: 0.95,
                signals: ['explicit_type:planche'],
                headingLevel: null,
                category: $block->category?->value ?? 'planche',
                reason: 'Bloc marqué comme planche par le parseur.',
            ),
            BlockType::CrossRef => $this->verdict(
                type: BlockType::CrossRef,
                confidence: 0.98,
                signals: ['explicit_type:cross_ref'],
                headingLevel: null,
                category: $block->category?->value,
                reason: 'Renvoi croisé détecté par motif.',
            ),
            // Une image peut être une FIGURE (numérotée) ou une image
            // décorative : la distinction demande d'analyser le texte voisin.
            BlockType::Figure => $this->assessFigure($block),
            default => null,
        };
    }

    /**
     * Une image est-elle une figure numérotée ou une image décorative ?
     *
     * La distinction est structurante : une figure entre dans la « liste des
     * figures » et consomme un numéro ; une image décorative n'y figure pas.
     *
     * @return array<string, mixed>
     */
    private function assessFigure(Block $block): array
    {
        $caption = $this->captions->detect($block->text);

        // Motif de légende dans le texte de l'image : figure numérotée.
        if ($caption !== null || $block->originalNumber !== null) {
            return $this->verdict(
                type: BlockType::Figure,
                confidence: self::SIGNAL_WEIGHTS['text_pattern'],
                signals: ['image_with_caption'],
                headingLevel: null,
                category: ($caption['category'] ?? $block->category ?? BlockCategory::Figure)->value,
                reason: 'Image accompagnée d\'une légende numérotée.',
            );
        }

        // Image sans légende : décorative.
        return $this->verdict(
            type: BlockType::Image,
            confidence: self::SIGNAL_WEIGHTS['caption_style'],
            signals: ['image_without_caption'],
            headingLevel: null,
            category: null,
            reason: 'Image sans légende : décorative.',
        );
    }

    /**
     * Confronte le style Word et la numérotation saisie.
     *
     * C'est LE cas révélateur du problème n°1 du projet : un paragraphe en style
     * « Titre 1 » dont le texte est numéroté « 1.1 » porte deux signaux
     * contradictoires. On retient le niveau de la NUMÉROTATION (l'intention de
     * l'auteur est plus fiable que le style appliqué à la légère), mais la
     * confiance chute sous le seuil pour faire trancher l'utilisateur.
     *
     * @param  array{level: int, kind: string, reliable: bool, matched: string}  $numbering
     * @return array<string, mixed>
     */
    private function assessHeadingWithBothSignals(Block $block, int $styleLevel, array $numbering): array
    {
        if ($styleLevel === $numbering['level']) {
            // Accord : les deux signaux convergent, aucun appel IA nécessaire.
            return $this->verdict(
                type: BlockType::Heading,
                confidence: 0.98,
                signals: ['outline_level', 'text_pattern:'.$numbering['kind']],
                headingLevel: $numbering['level'],
                category: null,
                reason: 'Style Word et numérotation concordent.',
            );
        }

        // Contradiction : on ne tranche PAS d'autorité.
        return $this->verdict(
            type: BlockType::Heading,
            confidence: 0.65,
            signals: ['contradiction:style='.$styleLevel.',pattern='.$numbering['level']],
            headingLevel: $numbering['level'],
            category: null,
            reason: sprintf(
                'Contradiction : style Word niveau %d, numérotation niveau %d.',
                $styleLevel,
                $numbering['level']
            ),
        );
    }

    /**
     * Évaluation par signaux visuels seuls (aucun pattern texte reconnu).
     *
     * @return array<string, mixed>
     */
    private function assessByVisualSignals(Block $block): array
    {
        $text = $block->text;

        // --- Images ---
        if ($block->type === BlockType::Image) {
            return $this->verdict(
                type: BlockType::Image,
                confidence: self::SIGNAL_WEIGHTS['caption_style'],
                signals: ['image_element'],
                headingLevel: null,
                category: null,
                reason: 'Élément image sans légende : décoratif.',
            );
        }

        // --- Le texte est-il en gras ? Le gras est un signal de mise en avant. ---
        //
        // Ordre important : on teste le GRAS avant la « forme de phrase ». Un
        // texte long en gras est ambigu (emphase ou titre mal stylé ?) et mérite
        // une clarification, alors qu'un texte long NON gras est un paragraphe
        // évident qui ne doit PAS coûter d'appel.
        if ($block->isBold) {
            if ($this->looksLikeIsolatedHeading($text)) {
                return $this->verdict(
                    type: BlockType::Heading,
                    confidence: self::SIGNAL_WEIGHTS['isolated_line'],
                    signals: ['isolated_line', 'bold'],
                    headingLevel: null,
                    category: null,
                    reason: 'Ligne isolée et en gras : titre probable.',
                );
            }

            // En gras mais trop long ou ponctué : ambiguïté réelle.
            return $this->verdict(
                type: BlockType::Paragraph,
                confidence: 0.7,
                signals: ['bold_but_long'],
                headingLevel: null,
                category: null,
                reason: 'En gras mais forme de phrase : ambiguïté à clarifier.',
            );
        }

        // --- Texte non gras ayant clairement la forme d'une phrase ---
        //
        // Aucun doute possible : c'est un paragraphe. La confiance doit être
        // HAUTE, sinon on paierait un appel au modèle pour confirmer l'évidence
        // (« ceci est un paragraphe ») — ce qui a fait chuter le gain budgétaire
        // de 68 % à 33 % lors de la première implémentation.
        if ($this->isClearlyASentence($text)) {
            return $this->verdict(
                type: BlockType::Paragraph,
                confidence: 0.9,
                signals: ['sentence_shape'],
                headingLevel: null,
                category: null,
                reason: 'Forme d\'une phrase : paragraphe de contenu.',
            );
        }

        // --- Ligne courte sans gras : ambiguïté réelle (titre ou fragment ?) ---
        if ($this->looksLikeIsolatedHeading($text)) {
            return $this->verdict(
                type: BlockType::Paragraph,
                confidence: self::SIGNAL_WEIGHTS['list_numbering'] + 0.1,
                signals: ['isolated_line'],
                headingLevel: null,
                category: null,
                reason: 'Ligne courte sans signal décisif : ambiguïté à clarifier.',
            );
        }

        // --- Paragraphe de contenu ordinaire ---
        return $this->verdict(
            type: BlockType::Paragraph,
            confidence: 0.85,
            signals: ['default_text'],
            headingLevel: null,
            category: null,
            reason: 'Texte courant : paragraphe.',
        );
    }

    /**
     * Le texte a-t-il la forme d'une phrase, sans ambiguïté possible ?
     *
     * On exige plusieurs marqueurs convergents (longueur significative, verbe
     * conjugué, ponctuation finale) : un seul critère ne suffit pas, sinon un
     * titre long serait pris pour une phrase.
     *
     * Cette méthode justifie une confiance HAUTE : il n'y a rien à clarifier
     * quand on peut affirmer « ceci est un paragraphe » sans hésitation.
     */
    private function isClearlyASentence(string $text): bool
    {
        // Un paragraphe de contenu dépasse rarement 100 caractères quand il
        // s'agit d'un intitulé, mais souvent quand c'est une phrase.
        if (mb_strlen($text) < 60) {
            return false;
        }

        $markers = 0;

        // Ponctuation finale : un titre n'en porte pas.
        if (preg_match('/[.;!?]\s*$/u', $text) === 1) {
            $markers++;
        }

        // Verbe conjugué ou pronom de phrase : signature d'une phrase.
        if (preg_match(
            '/\b(est|sont|était|étaient|sera|seront|a été|ont été|permet|permettent|'
            .'doit|doivent|peut|peuvent|nous|vous|il|elle|ils|elles|ce|cette|ces)\b/iu',
            $text
        ) === 1) {
            $markers++;
        }

        // Virgules : un intitulé en contient rarement.
        if (mb_substr_count($text, ',') >= 2) {
            $markers++;
        }

        // Texte long : au-delà de 160 caractères, c'est une phrase.
        if (mb_strlen($text) > 160) {
            $markers++;
        }

        return $markers >= 2;
    }

    /**
     * Le texte a-t-il la forme d'un intitulé isolé ?
     *
     * Critères : court, sans ponctuation finale, sans structure de phrase.
     */
    private function looksLikeIsolatedHeading(string $text): bool
    {
        if ($text === '' || mb_strlen($text) > 80) {
            return false;
        }

        return $this->contradictionPenalty($text) === 0.0;
    }

    /**
     * Pénalité cumulée des marqueurs « ceci est une phrase, pas un titre ».
     */
    private function contradictionPenalty(string $text): float
    {
        $penalty = 0.0;

        // Un titre ne se termine pas par un point final.
        if (preg_match('/[.!?]\s*$/u', $text) === 1) {
            $penalty += self::CONTRADICTION_PENALTIES['ends_with_period'];
        }

        // Marqueurs de phrase fréquents en français.
        if (preg_match(
            '/\b(est|sont|était|étaient|a été|ont été|permet|permettent|doit|doivent|nous|nous avons)\b/iu',
            $text
        ) === 1) {
            $penalty += self::CONTRADICTION_PENALTIES['sentence_markers'];
        }

        // Longueur : au-delà de 120 caractères, c'est une phrase.
        if (mb_strlen($text) > 120) {
            $penalty += self::CONTRADICTION_PENALTIES['too_long'];
        }

        return $penalty;
    }

    /**
     * Construit le verdict de classification.
     *
     * `needs_ai` est calculé ici : c'est la décision qui détermine si ce bloc
     * coûtera des tokens ou non.
     *
     * @param  array<int, string>  $signals
     * @return array<string, mixed>
     */
    private function verdict(
        BlockType $type,
        float $confidence,
        array $signals,
        ?int $headingLevel,
        ?string $category,
        string $reason,
    ): array {
        return [
            'type' => $type,
            'confidence' => round($confidence, 2),
            'signals_used' => $signals,
            'heading_level' => $headingLevel,
            'category' => $category,
            'needs_ai' => $confidence < Block::AUTO_ACCEPT_THRESHOLD,
            'reason' => $reason,
        ];
    }
}
