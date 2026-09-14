<?php

declare(strict_types=1);

namespace App\Document\Adapters;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use App\Document\Adapters\DocxOoxml\PackageReader;
use App\Document\Adapters\DocxOoxml\ParagraphReader;
use App\Document\Adapters\DocxOoxml\StyleReader;
use App\Document\Adapters\DocxOoxml\TableReader;
use App\Document\Adapters\DocxOoxml\XmlLoader;
use App\Document\Classification\CaptionPattern;
use App\Document\Classification\HeadingNumberingPattern;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\Fidelity;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use DOMElement;

/**
 * Adaptateur d'entrée pour les documents Word natifs (`.docx`).
 *
 * C'est le remplaçant direct de l'ancienne lecture par PHPWord : au lieu de
 * passer par un modèle objet qui perd la structure native, on lit le XML
 * WordprocessingML directement, ce qui préserve :
 *  - les niveaux de titre (`w:outlineLvl`), signal déterministe ;
 *  - la hiérarchie exacte des styles et leur héritage ;
 *  - la position verticale estimée (signal de tri et de regroupement).
 *
 * Ordre d'assemblage (chaque étape a son lecteur dédié) :
 *  1. `PackageReader`  → ouvre le ZIP, résout les relations
 *  2. `StyleReader`    → charge et résout les styles (héritage inclus)
 *  3. `ParagraphReader`→ analyse chaque paragraphe (runs, gras, taille, images)
 *  4. assemblage → `StructuralDocument`
 *
 * Les tableaux, images et en-têtes/pieds sont traités par des lecteurs dédiés
 * ajoutés en R1.2b ; leur squelette est déjà prévu ici.
 */
final class DocxNativeAdapter implements InputAdapter
{
    /** Signature binaire d'une archive ZIP (tout `.docx` commence par « PK »). */
    private const ZIP_SIGNATURE = "PK\x03\x04";

    private readonly HeadingNumberingPattern $numberingPattern;

    private readonly CaptionPattern $captionPattern;

    public function __construct()
    {
        $this->numberingPattern = new HeadingNumberingPattern;
        $this->captionPattern = new CaptionPattern;
    }

    public function sourceType(): string
    {
        return 'docx';
    }

    public function fidelity(): Fidelity
    {
        return Fidelity::Exact;
    }

    /**
     * Détection sur le CONTENU, pas sur l'extension.
     *
     * Un fichier `.doc` (ancien format binaire) renommé en `.docx` ne commence
     * pas par la signature ZIP : il est rejeté ici plutôt que de produire une
     * structure vide silencieusement.
     */
    public function supports(string $filePath): bool
    {
        if (! is_file($filePath)) {
            return false;
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        $signature = fread($handle, 4);
        fclose($handle);

        return $signature === self::ZIP_SIGNATURE;
    }

    /**
     * Convertit un `.docx` en document structurel.
     *
     * @throws DocxReadException Si le paquet est illisible
     */
    public function convert(string $filePath, string $documentId): StructuralDocument
    {
        $package = new PackageReader($filePath);
        $package->open();

        try {
            $styles = StyleReader::fromPackage($package);
            $paragraphs = new ParagraphReader($styles);
            $tables = new TableReader($paragraphs);

            $body = $this->readBody($package, $paragraphs, $tables);

            return new StructuralDocument(
                documentId: $documentId,
                sourceType: $this->sourceType(),
                blocks: $body['blocks'],
                meta: [
                    'fidelity' => $this->fidelity()->value,
                    'style_count' => $styles->count(),
                    'paragraphs_read' => $body['paragraphs_read'],
                    'paragraphs_skipped' => $body['paragraphs_skipped'],
                    'tables_read' => $body['tables_read'],
                    'tables_with_merges' => $body['tables_with_merges'],
                    'has_headers' => $this->hasHeadersOrFooters($package),
                    'image_count' => count($this->imageRelations($package)),
                ],
            );
        } finally {
            $package->close();
        }
    }

    /**
     * Lit le corps du document (`w:body`) et produit les blocs dans l'ORDRE.
     *
     * L'ordre d'apparition est fondamental : c'est lui qui définit la
     * renumérotation (R4), la proximité des renvois croisés et l'insertion des
     * listes (R5). Paragraphes et tableaux sont donc traités dans une seule
     * boucle, jamais en deux passes séparées.
     *
     * @return array{
     *     blocks: array<int, Block>,
     *     paragraphs_read: int,
     *     paragraphs_skipped: int,
     *     tables_read: int,
     *     tables_with_merges: int
     * }
     *
     * @throws DocxReadException
     */
    private function readBody(PackageReader $package, ParagraphReader $paragraphs, TableReader $tables): array
    {
        $document = XmlLoader::load(
            $package->readOrFail(PackageReader::MAIN_DOCUMENT),
            PackageReader::MAIN_DOCUMENT
        );

        $body = XmlLoader::firstDescendant($document->documentElement, 'w:body');
        if ($body === null) {
            throw DocxReadException::unexpectedRootElement(
                PackageReader::MAIN_DOCUMENT,
                'w:body',
                $document->documentElement?->nodeName ?? 'aucun'
            );
        }

        $blocks = [];
        $read = 0;
        $skipped = 0;
        $tablesRead = 0;
        $tablesWithMerges = 0;
        $blockIndex = 0;

        // La position verticale est l'index de l'élément dans le corps : elle
        // sert uniquement à ORDONNER, jamais à mesurer une distance réelle (le
        // calcul de position exacte est du ressort du moteur de rendu).
        foreach (XmlLoader::childElements($body) as $index => $element) {
            if ($element->nodeName === 'w:tbl') {
                $tableBlock = $this->readTable($tables, $element, $this->blockId($blockIndex), (float) $index);

                if ($tableBlock === null) {
                    continue;
                }

                $tablesRead++;
                if ($tableBlock['has_merges']) {
                    $tablesWithMerges++;
                }

                $blockIndex++;
                $blocks[] = $tableBlock['block'];

                continue;
            }

            if ($element->nodeName !== 'w:p') {
                continue;
            }

            $analysis = $paragraphs->read($element, $this->blockId($blockIndex), (float) $index);

            if ($analysis === null) {
                $skipped++;

                continue;
            }

            $read++;
            // L'identifiant n'avance que pour les blocs RÉELLEMENT produits :
            // sinon les paragraphes vides laisseraient des trous dans la
            // numérotation (b_0001, b_0003, b_0005…).
            $blockIndex++;
            $blocks[] = $this->toBlock($analysis);
        }

        return [
            'blocks' => $blocks,
            'paragraphs_read' => $read,
            'paragraphs_skipped' => $skipped,
            'tables_read' => $tablesRead,
            'tables_with_merges' => $tablesWithMerges,
        ];
    }

    /**
     * Convertit un tableau Word en bloc.
     *
     * @return null|array{block: Block, has_merges: bool} null si le tableau est vide
     */
    private function readTable(TableReader $tables, DOMElement $element, string $blockId, float $positionY): ?array
    {
        $read = $tables->read($element);

        /** @var TableData $tableData */
        $tableData = $read['table_data'];

        // Un tableau sans aucune cellule ne porte aucune information : on
        // l'ignore plutôt que de créer un bloc vide (Word en produit parfois
        // comme résidus de mise en page).
        if ($tableData->rows === 0 || $tableData->cols === 0) {
            return null;
        }

        return [
            'block' => new Block(
                blockId: $blockId,
                type: BlockType::Table,
                // Le texte du bloc est la version aplatie du tableau : utile
                // pour la recherche et le contexte envoyé au LLM. Le contenu
                // structuré reste dans `tableData`, jamais reformulé.
                text: implode(' ', $tableData->flattenCells()),
                indentLevel: 0,
                positionY: $positionY,
                fidelity: $this->fidelity(),
                // Un tableau est identifié sans ambiguïté par la structure XML :
                // seuls les tableaux-sans-légende resteront à clarifier.
                confidence: 0.9,
                tableData: $tableData,
            ),
            'has_merges' => $read['has_merges'],
        ];
    }

    /**
     * Convertit l'analyse d'un paragraphe en bloc du schéma commun.
     *
     * C'est ici que se joue la fiabilité de la détection : le type et le niveau
     * sont établis à partir de signaux CONFRONTÉS (style Word vs numérotation
     * saisie), et la confiance reflète leur accord ou leur contradiction.
     *
     * @param  array<string, mixed>  $analysis
     */
    private function toBlock(array $analysis): Block
    {
        $text = (string) $analysis['text'];
        $hasImage = (bool) $analysis['has_image'];

        // Le marqueur `[image:rId.img]` est retiré pour analyser le texte qui
        // ACCOMPAGNE l'image : c'est lui qui distingue une figure numérotée
        // d'une image décorative.
        $surroundingText = trim((string) preg_replace('/\[image:[^\]]*\]/u', '', $text));
        $caption = $this->captionPattern->detect($surroundingText);

        // --- 1. Traitement des images (figure numérotée vs décorative) ---
        if ($hasImage) {
            if ($caption !== null) {
                return $this->buildFigureBlock($analysis, $caption);
            }

            // Image seule, sans texte : décorative, aucune ambiguïté.
            if ($surroundingText === '') {
                return $this->buildImageBlock($analysis);
            }

            // Image accompagnée de texte non numéroté : c'est une figure dont
            // le numéro viendra de Word (champ SEQ) ou d'une clarification.
            return $this->buildFigureBlock($analysis, null);
        }

        // --- 2. Légende pure (sans image) : pattern texte, 0 token ---
        if ($caption !== null && ! $this->captionPattern->isListEntry($surroundingText)) {
            return $this->buildCaptionBlock($analysis, $caption);
        }

        // --- 3. Titre : confrontation style Word ↔ numérotation saisie ---
        $styleLevel = $analysis['heading_level'];
        $pattern = $this->numberingPattern->detect($text);

        if ($styleLevel !== null && $pattern !== null) {
            return $this->buildHeadingBlock(
                $analysis,
                $pattern['level'],
                $this->headingConfidence($styleLevel, $pattern['level']),
            );
        }

        // Style de titre seul : signal fort mais non corroboré.
        if ($styleLevel !== null) {
            return $this->buildHeadingBlock($analysis, $styleLevel, 0.9);
        }

        // Numérotation seule : l'auteur a numéroté sans appliquer de style.
        // Signal très fiable sur l'intention, mais pas sur la profondeur exacte.
        if ($pattern !== null) {
            return $this->buildHeadingBlock(
                $analysis,
                $pattern['level'],
                $this->patternOnlyConfidence($pattern),
            );
        }

        // --- 4. Ligne courte en gras : ambigu, l'IA devra trancher ---
        if ($analysis['is_bold'] === true
            && mb_strlen($text) > 0
            && mb_strlen($text) <= 80
            && $analysis['run_count'] <= 3) {
            return new Block(
                blockId: (string) $analysis['block_id'],
                type: BlockType::Paragraph,
                text: $text,
                fontSize: $analysis['font_size'],
                isBold: true,
                indentLevel: $this->indentLevelFrom($analysis),
                positionY: $analysis['position_y'],
                fidelity: $this->fidelity(),
                // Volontairement sous le seuil : c'est LE cas justifiant
                // l'appel au tool detect_blocks (§7).
                confidence: 0.6,
            );
        }

        // --- 5. Paragraphe courant : confiance haute, aucun token ---
        return new Block(
            blockId: (string) $analysis['block_id'],
            type: BlockType::Paragraph,
            text: $text,
            fontSize: $analysis['font_size'],
            isBold: (bool) $analysis['is_bold'],
            indentLevel: $this->indentLevelFrom($analysis),
            positionY: $analysis['position_y'],
            fidelity: $this->fidelity(),
            confidence: 0.85,
        );
    }

    /**
     * Confiance d'un titre détecté par la SEULE numérotation (sans style Word).
     *
     * Le degré de fiabilité dépend de la forme de numérotation :
     *  - mot-clé (`CHAPITRE 1`) ou numérotation pointée (`1.1`) : convention de
     *    section sans ambiguïté → confiance haute ;
     *  - énumération (`1)`, `A.`) : forme utilisée aussi bien pour des titres
     *    que pour des listes → confiance plus basse, sous le seuil de 0,85,
     *    pour que l'utilisateur puisse confirmer.
     *
     * @param  array{level: int, kind: string, reliable: bool, matched: string}  $pattern
     */
    private function patternOnlyConfidence(array $pattern): float
    {
        if ($pattern['kind'] === 'keyword') {
            return 0.88;
        }

        return $pattern['reliable'] ? 0.8 : 0.7;
    }

    /**
     * Construit un bloc de titre.
     *
     * @param  array<string, mixed>  $analysis
     */
    private function buildHeadingBlock(array $analysis, int $level, float $confidence): Block
    {
        return new Block(
            blockId: (string) $analysis['block_id'],
            type: BlockType::Heading,
            text: (string) $analysis['text'],
            headingLevel: $level,
            fontSize: $analysis['font_size'],
            isBold: (bool) $analysis['is_bold'],
            indentLevel: $this->indentLevelFrom($analysis),
            positionY: $analysis['position_y'],
            fidelity: $this->fidelity(),
            confidence: $confidence,
        );
    }

    /**
     * Construit un bloc de légende à partir du pattern détecté.
     *
     * @param  array<string, mixed>  $analysis
     * @param  array{category: BlockCategory, original_number: string, number_source: string, caption_text: string, matched: string}  $caption
     */
    private function buildCaptionBlock(array $analysis, array $caption): Block
    {
        return new Block(
            blockId: (string) $analysis['block_id'],
            type: BlockType::Caption,
            text: (string) $analysis['text'],
            fontSize: $analysis['font_size'],
            isBold: (bool) $analysis['is_bold'],
            indentLevel: $this->indentLevelFrom($analysis),
            positionY: $analysis['position_y'],
            fidelity: $this->fidelity(),
            // Une légende reconnue par mot-clé est presque certaine : le motif
            // est une convention stable, saisie par l'auteur.
            confidence: 0.95,
            category: $caption['category'],
            originalNumber: $caption['original_number'],
        );
    }

    /**
     * Construit un bloc figure (image numérotée, listée dans son frontispice).
     *
     * @param  array<string, mixed>  $analysis
     * @param  null|array{category: BlockCategory, original_number: string, number_source: string, caption_text: string, matched: string}  $caption
     */
    private function buildFigureBlock(array $analysis, ?array $caption): Block
    {
        $images = $analysis['images'];

        return new Block(
            blockId: (string) $analysis['block_id'],
            type: BlockType::Figure,
            text: (string) $analysis['text'],
            fontSize: $analysis['font_size'],
            positionY: $analysis['position_y'],
            fidelity: $this->fidelity(),
            // Sans numéro explicite, la confiance indique que la figure est
            // identifiée mais que sa numérotation reste à établir.
            confidence: $caption !== null ? 0.95 : 0.8,
            imageRef: $images[0] ?? null,
            category: $caption['category'] ?? BlockCategory::Figure,
            originalNumber: $caption['original_number'] ?? null,
        );
    }

    /**
     * Construit un bloc image décorative (non numérotée).
     *
     * @param  array<string, mixed>  $analysis
     */
    private function buildImageBlock(array $analysis): Block
    {
        $images = $analysis['images'];

        return new Block(
            blockId: (string) $analysis['block_id'],
            type: BlockType::Image,
            text: (string) $analysis['text'],
            fontSize: $analysis['font_size'],
            positionY: $analysis['position_y'],
            fidelity: $this->fidelity(),
            // Une image sans légende est décorative : aucune ambiguïté.
            confidence: 0.9,
            imageRef: $images[0] ?? null,
        );
    }

    /**
     * Confiance d'un titre, selon l'accord entre style et numérotation.
     *
     * Règle de la refonte (§7) : « Si les signaux sont contradictoires ou
     * absents, confidence doit être < 0.7. »
     *
     * Cas réel rencontré sur les documents du projet : un paragraphe en style
     * « Titre 1 » (niveau 1) dont le texte commence par « 1.1 » — le style a été
     * appliqué à la légère. On ne tranche pas d'autorité : on fait chuter la
     * confiance pour que l'utilisateur soit consulté sur CE bloc précis.
     */
    private function headingConfidence(int $styleLevel, int $patternLevel): float
    {
        if ($styleLevel === $patternLevel) {
            // Accord fort : le style ET la numérotation convergent.
            return 0.98;
        }

        // Contradiction : signaux divergents → sous le seuil de 0,85.
        return 0.65;
    }

    /**
     * Niveau de retrait déduit des propriétés de paragraphe.
     *
     * Le retrait OOXML est en points : 720 twips = 36 pt = un niveau de liste
     * standard dans Word.
     *
     * @param  array<string, mixed>  $analysis
     */
    private function indentLevelFrom(array $analysis): int
    {
        $numberingLevel = $analysis['numbering_level'] ?? null;
        if (is_int($numberingLevel)) {
            return $numberingLevel;
        }

        $indentLeft = $analysis['indent_left'] ?? null;
        if (! is_float($indentLeft) || $indentLeft <= 0) {
            return 0;
        }

        return (int) floor($indentLeft / 36);
    }

    /**
     * Le paquet contient-il un en-tête ou un pied de page ?
     *
     * @throws DocxReadException
     */
    private function hasHeadersOrFooters(PackageReader $package): bool
    {
        foreach ($package->relationsOf(PackageReader::MAIN_DOCUMENT) as $relation) {
            if (in_array($relation['type'], ['header', 'footer'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Relations d'images déclarées par le document.
     *
     * @return array<string, array{target: string, type: string, mode: string}>
     *
     * @throws DocxReadException
     */
    private function imageRelations(PackageReader $package): array
    {
        return $package->relationsOfType(PackageReader::MAIN_DOCUMENT, 'image');
    }

    /**
     * Identifiant de bloc au format `b_0001`.
     *
     * Zéro-padding sur 4 chiffres : l'ordre lexicographique reste correct
     * jusqu'à 9999 blocs, ce qui évite des tris surprenants dans les listes.
     */
    private function blockId(int $index): string
    {
        return sprintf('b_%04d', $index + 1);
    }
}
