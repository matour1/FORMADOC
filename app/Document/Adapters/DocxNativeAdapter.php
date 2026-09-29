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

    /**
     * Écart maximal entre deux entrées consécutives d'un MÊME sommaire.
     *
     * Au-delà, on considère qu'il y a une coupure et que la zone est terminée.
     *
     * **Pourquoi 6.** Les entrées d'un sommaire réel sont entrelacées : une ligne
     * sur deux n'a pas de numéro de page (page absente, texte reporté). Un écart
     * de 6 blocs couvre confortablement ces interruptions, tout en restant très
     * inférieur à la distance qui sépare un sommaire du corps rédigé — le
     * document mesuré a une coupure de plus de 40 blocs entre les deux.
     *
     * Une valeur trop grande fait déborder la zone sur le corps (c'est le défaut
     * corrigé : la plage allait jusqu'au bloc #632 sur 693) ; trop petite, elle
     * coupe le sommaire en morceaux et laisse des entrées en titres.
     */
    private const ECART_MAX_SOMMAIRE = 6;

    /**
     * Nombre de lignes candidates à partir duquel une zone est un sommaire.
     *
     * Fixé à 4 : en dessous, un titre isolé finissant par un chiffre légitime
     * pourrait former une fausse zone. À 4, il faudrait quatre titres
     * légitimement terminés par un nombre rapprochés — cas improbable, alors
     * qu'un sommaire en produit des dizaines.
     */
    private const DENSITE_MINIMALE = 4;

    private readonly HeadingNumberingPattern $numberingPattern;

    private readonly CaptionPattern $captionPattern;

    /**
     * Lecteur de styles de la conversion EN COURS.
     *
     * **Pourquoi une propriété temporaire.** `toBlock()` doit pouvoir reconnaître
     * une entrée de sommaire (`tocStyleLevel`), et pour cela interroger le lecteur
     * de styles — qui n'est disponible que pendant `convert()`. Le repasser en
     * paramètre à travers `readBody()` puis les lecteurs de tableaux alourdirait
     * cinq signatures pour une information sans rapport avec elles.
     *
     * Elle est remise à `null` dans le `finally` de `convert()` : sans cela, un
     * adaptateur réutilisé garderait une référence à une archive fermée.
     */
    private ?StyleReader $stylesEnCours = null;

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

            // Le lecteur de styles est conservé le temps de la conversion :
            // `toBlock()` en a besoin pour reconnaître les entrées de sommaire
            // (`is_list_style`, calculé par `ParagraphReader` mais consommé ici).
            // On l'expose par propriété plutôt que de le repasser à chaque appel :
            // `toBlock()` est appelé une fois par paragraphe, et un document en
            // compte des centaines.
            $this->stylesEnCours = $styles;

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
            $this->stylesEnCours = null;
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

        // --- Passe 2 : reclasser les entrées de sommaire PAR CONTENU ----------
        // Le style Word (`toc N`) couvre les sommaires GÉNÉRÉS. Il ne couvre pas
        // les sommaires TAPÉS À LA MAIN, sans style de liste — le cas mesuré sur
        // le document de référence, où le sommaire fournissait 60 % des titres
        // détectés. Cette passe les reconnaît sur leur contenu.
        //
        // Elle a lieu APRÈS la boucle, et c'est nécessaire : le filtre a besoin
        // de connaître TOUS les titres pour confirmer une entrée par croisement
        // (le même texte doit exister ailleurs comme titre). Un filtre en ligne
        // ne verrait que les titres déjà rencontrés, donc une partie seulement.
        $blocks = $this->reclasserEntreesSommaire($blocks);

        return [
            'blocks' => $blocks,
            'paragraphs_read' => $read,
            'paragraphs_skipped' => $skipped,
            'tables_read' => $tablesRead,
            'tables_with_merges' => $tablesWithMerges,
        ];
    }

    /**
     * Reclasse en `TocEntry` les entrées d'un sommaire tapé à la main.
     *
     * **La difficulté, et pourquoi la solution est prudente.** Une entrée de
     * sommaire ressemble à un titre : elle finit par un numéro de page, comme un
     * titre pourrait finir par un chiffre légitime (« CHAPITRE 2 », « Partie 3 »).
     * Un filtre qui se contenterait du numéro final reclasserait donc de vrais
     * titres, et les retirer serait pire que de les laisser — un titre manquant
     * casse la hiérarchie, un titre superflu se voit.
     *
     * Le filtre exige donc **trois conditions ensemble** :
     *
     *   1. la ligne finit par un numéro de page (1 à 3 chiffres, précédé d'un
     *      blanc ou d'une tabulation) ;
     *   2. elle appartient à une FENÊTRE DENSE : au moins 4 lignes candidates dans
     *      un intervalle de 12 blocs consécutifs ;
     *   3. à l'intérieur d'une telle fenêtre, les lignes intercalées qui ne
     *      portent pas la signature sont reclassées AUSSI — voir plus bas.
     *
     * **Pourquoi une fenêtre dense, et non des lignes strictement consécutives.**
     * La première implémentation exigeait 3 lignes candidates consécutives. Sur
     * le document de référence, elle n'en reclassait que 24 sur 48 : les entrées
     * d'un sommaire réel sont ENTRELACÉES, une ligne sur deux n'ayant pas de
     * numéro de page (numéro absent, ou texte reporté à la ligne suivante). La
     * consécutivité stricte cassait donc les plages à chaque interruption.
     *
     * Mesure après correction par fenêtre : **24 → 48** (la totalité du défaut
     * mesuré sur ce document).
     *
     * **Pourquoi la position dans le document n'intervient pas.** Le document
     * initial proposait « sommaire en première page, table des matières en fin ».
     * C'est une heuristique de position, fragile par nature (les conventions
     * varient selon l'établissement), et le principe 1 du projet l'écarte. Le
     * signal retenu ici est structurel : la densité et la forme des lignes.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, Block>
     */
    private function reclasserEntreesSommaire(array $blocks): array
    {
        // Les intitulés de section sont de VRAIS titres — mais un intitulé suivi
        // d'un NUMÉRO DE PAGE est une entrée de sommaire qui le cite, jamais un
        // titre. « REMERCIEMENTS  iii » ne peut pas être un titre : un titre
        // n'affiche pas sa propre page. Cette liste sert donc à reconnaître ces
        // cas, qui peuvent être isolés et échapper à la densité de fenêtre.
        $intitules = [
            'SOMMAIRE', 'INTRODUCTION', 'CONCLUSION', 'BIBLIOGRAPHIE', 'RESUME', 'RÉSUMÉ',
            'ABSTRACT', 'REMERCIEMENTS', 'DEDICACE', 'DÉDICACE', 'AVANT-PROPOS',
            'TABLE DES MATIERES', 'TABLE DES MATIÈRES', 'LEXIQUE', 'GLOSSAIRE',
            'LISTE DES FIGURES', 'LISTE DES TABLEAUX', 'LISTE DES ANNEXES',
            'SIGLES', 'ABREVIATIONS', 'ABRÉVIATIONS',
        ];

        // --- 1. Marquer les lignes qui portent la signature d'une entrée -----
        $candidats = [];
        $explicites = [];

        foreach ($blocks as $index => $block) {
            if ($block->type !== BlockType::Heading) {
                continue;
            }

            $texte = trim($block->text);

            if ($texte === '' || ! $this->finitParNumeroDePage($texte)) {
                // Cas particulier des intitulés courts : « RESUME  viii » ne fait
                // que 6 caractères avant le numéro, donc le seuil de longueur
                // l'écarte. Or un intitulé de section suivi d'un numéro de page
                // est sans ambiguïté une entrée de sommaire — c'est même la
                // définition d'une entrée.
                if ($texte === '' || ! $this->finitParNumeroDePage($texte, 3)) {
                    continue;
                }
            }

            $candidats[] = $index;

            // Intitulé de section suivi d'un numéro de page : reclassement
            // certain, indépendamment de la densité alentour.
            $base = mb_strtoupper($this->sansNumeroDePage($texte));
            foreach ($intitules as $intitule) {
                if (str_starts_with($base, $intitule)) {
                    $explicites[$index] = true;
                    break;
                }
            }
        }

        if ($candidats === []) {
            return $blocks;
        }

        // --- 2. SEGMENTER en zones de sommaire --------------------------------
        //
        // **Défaut corrigé ici, et il vidait le document de ses titres.**
        // La première version faisait grandir une plage depuis le PREMIER
        // candidat dense jusqu'au dernier, sans jamais la refermer. Sur un
        // document réel du corpus, elle a fini par couvrir les blocs #1 à #632 —
        // TOUT le document — et 277 blocs ont été reclassés, dont des lignes de
        // corps (« Promouvoir les entreprises locales… »). Mesure : 88 titres
        // légitimes subsistaient DANS la plage, donc reclassés à tort.
        //
        // Le défaut ne se voyait PAS sur le document de référence, où le sommaire
        // est compact : un correctif validé sur un seul document est un correctif
        // non validé. C'est exactement ce que le principe 4 du projet prescrit de
        // mesurer avant de généraliser.
        //
        // La segmentation est la bonne lecture : un sommaire est une zone
        // CONTIGUË, pas un ensemble de lignes éparpillées. Les entrées se suivent,
        // et une coupure franche signale la fin du sommaire.
        $zones = [];
        $zone = [];

        foreach ($candidats as $index) {
            if ($zone !== [] && ($index - end($zone)) > self::ECART_MAX_SOMMAIRE) {
                // Coupure franche : la zone courante s'arrête ici.
                if (count($zone) >= self::DENSITE_MINIMALE) {
                    $zones[] = $zone;
                }
                $zone = [];
            }

            $zone[] = $index;
        }

        if (count($zone) >= self::DENSITE_MINIMALE) {
            $zones[] = $zone;
        }

        $reclasser = [];

        foreach ($zones as $zone) {
            // La zone reclassée va du premier au dernier candidat de la zone :
            // c'est ce qui capte les lignes INTERCALÉES sans numéro de page, qui
            // sont majoritaires dans un sommaire réel (une sur deux).
            foreach (range($zone[0], end($zone)) as $i) {
                if ($i < count($blocks)) {
                    $reclasser[$i] = true;
                }
            }
        }

        if ($reclasser === [] && $explicites === []) {
            return $blocks;
        }

        // Les reclassements certains s'ajoutent à ceux déduits de la densité.
        foreach (array_keys($explicites) as $index) {
            $reclasser[$index] = true;
        }

        // --- 3. Reclasser, en conservant le niveau ---------------------------
        foreach (array_keys($reclasser) as $index) {
            $block = $blocks[$index] ?? null;

            // On ne reclassse QUE des titres : un paragraphe, une légende ou une
            // image présents dans la fenêtre gardent leur type. La fenêtre sert à
            // repérer une ZONE, pas à convertir tout ce qu'elle contient.
            if ($block === null || $block->type !== BlockType::Heading) {
                continue;
            }

            $blocks[$index] = new Block(
                blockId: $block->blockId,
                type: BlockType::TocEntry,
                // Le numéro de page est retiré du texte : laissé tel quel, il
                // serait recopié dans le sommaire qu'on génère
                // (« SECTION 1 … 2 »), ce qui est manifestement faux.
                text: $this->sansNumeroDePage($block->text),
                headingLevel: $block->headingLevel,
                fontSize: $block->fontSize,
                isBold: $block->isBold,
                indentLevel: $block->indentLevel,
                positionY: $block->positionY,
                fidelity: $block->fidelity,
                // Confiance légèrement sous le maximum : le signal est fort
                // (densité + forme) mais reste une inférence de contenu, à la
                // différence d'un style Word qui, lui, est explicite.
                confidence: 0.9,
            );
        }

        return $blocks;
    }

    /**
     * La ligne se termine-t-elle par un numéro de page ?
     *
     * **Ce que la mesure du corpus a imposé, et c'est un durcissement.**
     * La première version acceptait tout numéro de 1 à 3 chiffres. Sur un
     * document réel du corpus, cela a reclassé **des lignes de corps et de
     * listes** : « de logiciels de graphisme ; », « Le Journal De Caisse ; »,
     * « Définition » — des lignes qui finissent par un chiffre sans être des
     * entrées de sommaire. Résultat : 277 blocs reclassés sur 366 blocs
     * « à numéro », et **88 titres légitimes subsistaient dans la plage
     * reclassée**. Le correctif vidait le document de ses titres.
     *
     * Trois exigences supplémentaires, chacune tirée de cette mesure :
     *
     *  1. **Une séparation nette avant le numéro** (au moins 2 blancs ou une
     *     tabulation). Une entrée de sommaire a ses points de suite ou un
     *     alignement à droite ; un montant en fin de phrase est séparé par UN
     *     espace. C'est ce qui écarte « Équipements Quantité Ordinateurs 2 ».
     *
     *  2. **Le texte avant le numéro ne doit pas être une phrase.** Une entrée
     *     de sommaire est un intitulé : elle ne se termine par ni `;` ni `,` ni
     *     `:`. C'est ce qui écarte « de logiciels de graphisme ; » et
     *     « D'assurer Le Suivi Financier ; ».
     *
     *  3. **Un intitulé d'une seule ligne sans séparation est refusé.** « Définition »
     *     seul, « Indice de sécurité » seul : rien ne distingue ces titres
     *     légitimes d'une entrée — et les reclasser retirerait un vrai titre.
     *     On les laisse donc en titres, car l'erreur est ASYMÉTRIQUE : un titre
     *     manquant casse la hiérarchie du document, une entrée de sommaire
     *     laissée en titre se voit et se corrige.
     *
     * @param  int  $longueurMinimale  Caractères de texte exigés devant le numéro
     */
    private function finitParNumeroDePage(string $texte, int $longueurMinimale = 8): bool
    {
        // Le numéro doit être SÉPARÉ du texte : au moins deux blancs, une
        // tabulation ou un espace insécable. C'est la signature d'un alignement
        // de sommaire.
        //
        // La séparation est dans un groupe NON capturant et le numéro dans le
        // groupe 1. Une première version écrivait l'alternance à l'envers
        // (`[\t]{1}|[\x{00A0}]{1}| {2,}(\d+)$`) : les deux premières branches
        // matchaient alors une simple tabulation SANS numéro, et `$m[1]`
        // n'existait pas — `Undefined array key 1` faisait échouer la
        // conversion du document entier.
        $motif = '/(?:\t|\x{00A0}| {2,})(\d{1,3}|[ivxlcdmIVXLCDM]{1,6})\.?$/u';

        if (preg_match($motif, trim($texte), $m) !== 1) {
            return false;
        }

        $numero = $m[1];
        $avant = trim(mb_substr(trim($texte), 0, -mb_strlen($numero)));

        if (mb_strlen($avant) < $longueurMinimale) {
            return false;
        }

        if (preg_match('/\p{L}/u', $avant) !== 1) {
            return false;
        }

        // Un intitulé ne se termine pas par une ponctuation de prose. Un
        // point-virgule ou une virgule finale signalent une énumération, pas
        // une entrée de sommaire.
        if (preg_match('/[;,.]$/u', $avant) === 1) {
            return false;
        }

        return true;
    }

    /**
     * Retire le numéro de page final, arabe ou romain.
     */
    private function sansNumeroDePage(string $texte): string
    {
        $nettoye = preg_replace('/[\t\s]+(?:\d{1,3}|[ivxlcdm]{1,6})\.?$/u', '', trim($texte));

        return trim((string) $nettoye);
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

        // --- 2bis. Entrée d'un sommaire DÉJÀ présent ---------------------------
        // **C'est le correctif du défaut le plus coûteux de la détection.**
        //
        // `ParagraphReader` calcule `is_list_style` depuis l'origine, mais cette
        // clé n'était lue NULLE PART : le signal de style était collecté puis
        // jeté. En pratique, une entrée de sommaire stylée `toc 1` n'était donc
        // écartée que si elle portait AUSSI des points de suite — ce qui n'est
        // pas garanti (36 % des légendes du corpus sont à distance ≥ 3 de leur
        // porteur, et un sommaire Word natif extrait par PhpWord ne restitue pas
        // toujours les points de suite).
        //
        // Mesure sur le document de référence : sans ce branchement, le sommaire
        // fournissait **60 % des titres détectés** — numéros de page compris
        // (« SECTION I : PRESENTATION GENERALE DE SOPAL SARL    2 »).
        //
        // On teste AVANT les règles de titre, et c'est indispensable : un style
        // `toc 2` porte un `outlineLevel`, donc `heading_level` est renseigné, et
        // la règle de titre ci-dessous le capturerait en premier.
        //
        // **`tocStyleLevel()` et non `listStyleLevel()`.** La seconde répond
        // aussi pour `list paragraph`, le style que Word applique à TOUTE liste à
        // puces : l'utiliser ici reclassait 175 blocs de contenu en sommaire sur
        // `fn7Ze5U5…docx` (mesuré le 2026-09-29). Seul un style de SOMMAIRE
        // autorise ce reclassement — une liste à puces n'est pas un sommaire.
        $listLevel = $this->stylesEnCours?->tocStyleLevel($analysis['style_id'] ?? null);

        if ($listLevel !== null) {
            return new Block(
                blockId: (string) $analysis['block_id'],
                type: BlockType::TocEntry,
                text: $text,
                // Le niveau est CONSERVÉ : il distingue un sommaire (niveaux 1-2)
                // d'une table des matières complète (tous niveaux). Le jeter
                // priverait le générateur de la distinction documentée dans le
                // modèle de référence.
                headingLevel: $listLevel,
                fontSize: $analysis['font_size'],
                isBold: (bool) $analysis['is_bold'],
                indentLevel: $this->indentLevelFrom($analysis),
                positionY: $analysis['position_y'],
                fidelity: $this->fidelity(),
                // Confiance haute : le style est un signal déterministe, pas une
                // heuristique. Une entrée de sommaire ne doit pas déclencher de
                // clarification — ce serait demander à l'utilisateur de trancher
                // ce que le document dit explicitement.
                confidence: 0.95,
            );
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
