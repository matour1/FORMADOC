<?php

declare(strict_types=1);

namespace App\Docx;

use App\Document\Adapters\DocxOoxml\FieldReader;
use App\Document\Adapters\DocxOoxml\PackageReader;
use App\Document\Adapters\DocxOoxml\ParagraphReader;
use App\Document\Adapters\DocxOoxml\StyleReader;
use App\Document\Adapters\DocxOoxml\TableReader;
use App\Document\Adapters\DocxOoxml\XmlLoader;
use App\Document\Classification\CaptionPattern;
use App\Document\Classification\HeadingNumberingPattern;
use DOMDocument;
use DOMElement;

/**
 * Analyseur en LECTURE SEULE d'un `.docx`.
 *
 * **Pourquoi cet instrument existe.** Le projet savait traiter un document, mais
 * pas le décrire. Toute correction de détection était donc faite sans mesure :
 * on modifiait une règle, on regardait un document, et on supposait une
 * amélioration. Les instructions d'implémentation (§4, §11) exigent « mesurer
 * rappel et précision à chaque changement de règle » — impossible sans un outil
 * qui dise, avant toute modification, ce que le document contient réellement.
 *
 * **Ce que cette classe garantit.** Elle n'ÉCRIT RIEN : le paquet est ouvert en
 * lecture, aucun nœud n'est modifié, aucune partie n'est réenregistrée. Elle peut
 * donc être exécutée sur un fichier utilisateur sans risque, et son résultat est
 * reproductible.
 *
 * **Ce qu'elle ne fait pas — et pourquoi c'est dit ici.** Elle ne classe pas les
 * blocs comme le pipeline de production : elle rapporte les SIGNAUX bruts
 * (styles, champs, motifs) et laisse l'appelant juger. Un analyseur qui
 * « déciderait » reproduirait les règles qu'il est censé mesurer, et ne
 * pourrait donc jamais les contredire.
 */
final class DocxAnalyzer
{
    /** Texte maximal d'une liste tapée pour être prise en compte. */
    private const TAILLE_MAX_LIGNE_LISTE = 200;

    private readonly HeadingNumberingPattern $numeroTitre;

    private readonly CaptionPattern $legende;

    private readonly FieldReader $champs;

    public function __construct()
    {
        $this->numeroTitre = new HeadingNumberingPattern;
        $this->legende = new CaptionPattern;
        $this->champs = new FieldReader;
    }

    /**
     * Analyse complète d'un fichier.
     *
     * @return array<string, mixed> Rapport structuré (sérialisable en JSON)
     */
    public function analyser(string $cheminFichier): array
    {
        $paquet = new PackageReader($cheminFichier);
        $paquet->open();

        try {
            $styles = StyleReader::fromPackage($paquet);
            $lecteurParagraphes = new ParagraphReader($styles);
            $lecteurTableaux = new TableReader($lecteurParagraphes);

            $document = XmlLoader::load(
                $paquet->readOrFail(PackageReader::MAIN_DOCUMENT),
                PackageReader::MAIN_DOCUMENT
            );

            $corps = $this->lireCorps($document, $lecteurParagraphes);

            return [
                'fichier' => [
                    'nom' => basename($cheminFichier),
                    'octets' => (int) @filesize($cheminFichier),
                    // Compté à l'ouverture : c'est la mesure qui permet de
                    // détecter une archive anormalement chargée (voir le
                    // garde-fou de bombe de décompression, non implémenté ici).
                    'parties' => count($paquet->listParts()),
                ],
                'statistiques' => $this->statistiques($document, $corps),
                'styles' => $this->etatDesStyles($styles, $corps),
                'champs' => $this->champs($document, $corps),
                'sections' => $this->sections($document),
                'entetes_pieds' => $this->entetesEtPieds($paquet, $document),
                'zones_de_texte' => $this->zonesDeTexte($document),
                'sommaires' => $this->sommairesExistants($corps),
                'candidats_titres' => $this->candidatsTitres($corps),
                'candidats_legendes' => $this->candidatsLegendes($corps),
                'candidats_page_de_garde' => $this->candidatPageDeGarde($corps),
                'avertissements' => $this->avertissements($corps),
            ];
        } finally {
            $paquet->close();
        }
    }

    /**
     * Parcourt le corps et collecte les paragraphes utilisables.
     *
     * On garde AUSSI les paragraphes vides : leur nombre et leurs séries sont une
     * mesure demandée (§4) — c'est même le principal symptôme des documents
     * « bruts » où l'espacement est fait à coups de retours à la ligne.
     *
     * @return array{
     *     paragraphes: array<int, array<string, mixed>>,
     *     vides: int,
     *     serie_vides_max: int,
     *     tableaux: int,
     *     sauts_page: int
     * }
     */
    private function lireCorps(DOMDocument $document, ParagraphReader $lecteur): array
    {
        $racine = $document->documentElement;

        if (! $racine instanceof DOMElement) {
            return ['paragraphes' => [], 'vides' => 0, 'serie_vides_max' => 0, 'tableaux' => 0, 'sauts_page' => 0];
        }

        $corps = XmlLoader::firstChild($racine, 'w:body');

        if ($corps === null) {
            return ['paragraphes' => [], 'vides' => 0, 'serie_vides_max' => 0, 'tableaux' => 0, 'sauts_page' => 0];
        }

        $paragraphes = [];
        $vides = 0;
        $serie = 0;
        $serieMax = 0;
        $tableaux = 0;
        $sautsPage = 0;
        $index = 0;

        foreach (XmlLoader::childElements($corps) as $element) {
            $nom = XmlLoader::localName($element->nodeName);

            if ($nom === 'tbl') {
                $tableaux++;
                // Un tableau rompt une série de paragraphes vides : les vides de
                // part et d'autre ne forment pas une même série visuelle.
                $serie = 0;

                continue;
            }

            if ($nom !== 'p') {
                continue;
            }

            // Un saut de page explicite (`w:br w:type="page"`) compte comme un
            // séparateur : les instructions le mesurent séparément des
            // `pageBreakBefore`, qui sont une PROPRIÉTÉ du paragraphe.
            if ($this->contientSautDePage($element)) {
                $sautsPage++;
            }

            $lu = $lecteur->read($element, 'p_'.str_pad((string) $index, 5, '0', STR_PAD_LEFT));

            if ($lu === null) {
                $vides++;
                $serie++;
                $serieMax = max($serieMax, $serie);
                // On conserve quand même l'entrée : sa POSITION permet de
                // mesurer la série, et de dire où elle se trouve.
                $paragraphes[] = [
                    'index' => $index,
                    'vide' => true,
                    'texte' => '',
                    'style_id' => null,
                    'style_nom' => null,
                    'est_titre_style' => false,
                    'est_legende_style' => false,
                    'est_style_sommaire' => false,
                    'est_style_liste' => false,
                    'niveau_titre' => null,
                    'gras' => false,
                    'taille' => null,
                    'alignement' => null,
                    'image' => false,
                    'forme' => false,
                    'saut_page' => false,
                    'champs' => [],
                ];
                $index++;

                continue;
            }

            $serie = 0;

            $paragraphes[] = [
                'index' => $index,
                'vide' => false,
                'texte' => $lu['text'],
                'style_id' => $lu['style_id'],
                'style_nom' => $lu['style_name'],
                'est_titre_style' => $lu['is_heading_style'],
                'est_legende_style' => $lu['is_caption_style'],
                'est_style_sommaire' => $lu['is_toc_style'],
                'est_style_liste' => $lu['is_list_style'],
                'niveau_titre' => $lu['heading_level'],
                'gras' => $lu['is_bold'],
                'taille' => $lu['font_size'],
                'alignement' => $lu['alignment'],
                'image' => $lu['has_image'],
                'forme' => $lu['has_shape'],
                'saut_page' => $lu['has_break'],
                'champs' => $this->champsDuParagraphe($element),
            ];

            $index++;
        }

        return [
            'paragraphes' => $paragraphes,
            'vides' => $vides,
            'serie_vides_max' => $serieMax,
            'tableaux' => $tableaux,
            'sauts_page' => $sautsPage,
        ];
    }

    /**
     * Statistiques demandées par les instructions (§4).
     *
     * **Elles sont mesurées SUR LE DOCUMENT, pas sur les paragraphes lus.** La
     * distinction n'est pas cosmétique : un compteur calculé à partir des blocs
     * produits ne voit que ce que le lecteur de paragraphes a bien voulu lire.
     * Le premier essai de cet analyseur annonçait ainsi **0 signet `_Toc`** sur
     * un document qui en contient **299** — les signets sont des marques de
     * position, pas des champs, et aucun chemin ne les produisait.
     *
     * Une statistique qui ne peut pas être vérifiée indépendamment est pire
     * qu'aucune statistique : elle fait conclure.
     *
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     */
    private function statistiques(DOMDocument $document, array $corps): array
    {
        $paragraphes = $corps['paragraphes'];
        $nonVides = array_filter($paragraphes, static fn (array $p): bool => ! $p['vide']);

        $images = XmlLoader::query($document, '//w:drawing');
        $enLigne = 0;
        $ancrees = 0;

        foreach ($images as $dessin) {
            // `wp:inline` = image dans le flux du texte ; `wp:anchor` = image
            // flottante (positionnée). La distinction est demandée par les
            // instructions, et elle est utile : une image ancrée ne suit pas son
            // paragraphe de la même manière, donc son déplacement est plus risqué.
            if (XmlLoader::firstDescendant($dessin, 'wp:anchor') !== null) {
                $ancrees++;
            } else {
                $enLigne++;
            }
        }

        return [
            'paragraphes' => count($paragraphes),
            'paragraphes_non_vides' => count($nonVides),
            'paragraphes_vides' => $corps['vides'],
            'serie_vides_max' => $corps['serie_vides_max'],
            'tableaux' => $corps['tableaux'],
            'sauts_page_explicites' => $corps['sauts_page'],
            'images' => $enLigne + $ancrees,
            'images_en_ligne' => $enLigne,
            'images_ancrées' => $ancrees,
            'formes' => count(array_filter($paragraphes, static fn (array $p): bool => $p['forme'])),
            'styles_de_titre' => count(array_filter($paragraphes, static fn (array $p): bool => $p['est_titre_style'])),
            'signets_toc' => count(XmlLoader::query(
                $document,
                '//w:bookmarkStart[starts-with(@w:name, "_Toc")]'
            )),
        ];
    }

    /**
     * Zones de texte (`w:txbxContent`) : contenu et taille.
     *
     * **Pourquoi une lecture dédiée.** Le texte d'une zone de texte n'est PAS un
     * paragraphe du corps : `ParagraphReader` ne le voit pas. Or les documents
     * réels y placent des titres : le DQP brut en compte **26**, et le DQP final
     * y a ses **19 titres de niveau 1**. Les ignorer revient à perdre des titres
     * avant même d'avoir commencé à les détecter.
     *
     * **Attention au doublement.** Word duplique chaque zone dans
     * `mc:AlternateContent` : `mc:Choice` (moderne) et `mc:Fallback` (VML). Une
     * lecture naïve compte donc chaque zone DEUX fois. On compte les zones
     * réellement distinctes en ne retenant que `mc:Choice` quand les deux
     * existent.
     *
     * @return array<string, mixed>
     */
    private function zonesDeTexte(DOMDocument $document): array
    {
        $fallbacks = XmlLoader::query($document, '//mc:Fallback');
        $toutes = XmlLoader::query($document, '//w:txbxContent');

        // Sous-arbres `mc:Fallback` : leur contenu est une COPIE de la version
        // moderne. On note individuellement les zones qu'ils contiennent, pour
        // les EXCLURE ensuite du détail — pas seulement du total.
        //
        // **Le défaut que cela corrige.** La première version soustrayait les
        // copies du total mais les laissait dans le détail : le compteur
        // « candidates à la promotion » affichait alors 52 pour 26 zones réelles.
        // Un total juste avec un détail faux reste un rapport faux.
        $zonesFallback = [];

        foreach ($fallbacks as $fallback) {
            foreach (XmlLoader::query($document, './/w:txbxContent', $fallback) as $zone) {
                $zonesFallback[] = $zone;
            }
        }

        $detail = [];

        foreach ($toutes as $zone) {
            if (in_array($zone, $zonesFallback, true)) {
                continue;
            }

            $texte = trim(preg_replace('/\s+/u', ' ', $zone->textContent) ?? '');
            $paragraphes = count(XmlLoader::query($document, './/w:p', $zone));
            $images = count(XmlLoader::query($document, './/w:drawing', $zone));
            $formes = count(XmlLoader::query($document, './/wps:wsp', $zone));

            $detail[] = [
                'texte' => mb_substr($texte, 0, 160),
                'longueur' => mb_strlen($texte),
                'paragraphes' => $paragraphes,
                'images' => $images,
                'formes' => $formes,
                // Une zone « candidate à la promotion » ne contient qu'un ou
                // deux paragraphes de texte, sans schéma ni image.
                'candidate_titre' => $paragraphes <= 2 && $formes === 0 && $images === 0 && $texte !== '',
            ];
        }

        return [
            'total' => count($toutes),
            'dans_fallback' => count($zonesFallback),
            'distinctes' => count($detail),
            'candidates_titre' => count(array_filter($detail, static fn (array $z): bool => $z['candidate_titre'])),
            'detail' => $detail,
        ];
    }

    /**
     * Styles utilisés et styles définis mais jamais utilisés.
     *
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     */
    private function etatDesStyles(StyleReader $styles, array $corps): array
    {
        $utilises = [];

        foreach ($corps['paragraphes'] as $paragraphe) {
            $id = $paragraphe['style_id'];

            if ($id === null) {
                continue;
            }

            $utilises[$id] = ($utilises[$id] ?? 0) + 1;
        }

        $definis = $styles->styleIds();
        $jamaisUtilises = array_values(array_diff($definis, array_keys($utilises)));

        $detail = [];

        foreach ($utilises as $id => $occurrences) {
            $detail[$id] = [
                'nom' => $styles->nameFor($id),
                'occurrences' => $occurrences,
                'est_titre' => $styles->headingLevelFor($id) !== null,
                'est_legende' => $styles->isCaptionStyle($id),
                'est_sommaire' => $styles->tocStyleLevel($id) !== null,
            ];
        }

        return [
            'definis' => count($definis),
            'utilises' => count($utilises),
            'jamais_utilises' => array_values($jamaisUtilises),
            'detail' => $detail,
        ];
    }

    /**
     * Champs Word du corps, avec leur position.
     *
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     */
    private function champs(DOMDocument $document, array $corps): array
    {
        $parType = [];
        $detail = [];

        foreach ($corps['paragraphes'] as $paragraphe) {
            foreach ($paragraphe['champs'] as $champ) {
                $type = $champ['type'];

                $parType[$type] = ($parType[$type] ?? 0) + 1;

                $detail[] = [
                    'index_paragraphe' => $paragraphe['index'],
                    'instruction' => $champ['instruction'],
                    'type' => $champ['type'],
                    'identifiant' => $champ['identifiant'],
                ];
            }
        }

        return [
            'total' => count($detail),
            'par_type' => $parType,
            'detail' => $detail,
        ];
    }

    /**
     * Lit les champs d'un paragraphe (simples ET complexes).
     *
     * @return array<int, array{instruction: string, type: string, identifiant: null|string}>
     */
    private function champsDuParagraphe(DOMElement $paragraphe): array
    {
        $resultat = [];

        foreach ($this->champs->simpleFieldsIn($paragraphe) as $champ) {
            $resultat[] = [
                'instruction' => $champ['instruction'],
                'type' => $champ['kind'],
                'identifiant' => $champ['identifier'] ?? null,
            ];
        }

        foreach ($this->champs->complexFieldsIn($paragraphe) as $champ) {
            $resultat[] = [
                'instruction' => $champ['instruction'],
                'type' => $champ['kind'],
                'identifiant' => $champ['identifier'] ?? null,
            ];
        }

        return $resultat;
    }

    /**
     * Sections du document : nombre et `sectPr` rencontrés.
     *
     * @return array<string, mixed>
     */
    private function sections(DOMDocument $document): array
    {
        $proprietes = XmlLoader::query($document, '//w:sectPr');

        $sections = [];

        foreach ($proprietes as $index => $section) {
            $numero = XmlLoader::firstChild($section, 'w:pgNumType');
            $titrePage = XmlLoader::firstChild($section, 'w:titlePg');

            $sections[] = [
                'index' => $index + 1,
                'format_numerotation' => $numero?->getAttribute('w:fmt') ?: null,
                'debut_numerotation' => $numero?->getAttribute('w:start') ?: null,
                'page_de_garde_distincte' => $titrePage !== null,
            ];
        }

        return [
            'nombre' => count($sections),
            'detail' => $sections,
        ];
    }

    /**
     * En-têtes et pieds de page : présence, texte, champ PAGE.
     *
     * @return array<string, mixed>
     */
    private function entetesEtPieds(PackageReader $paquet, DOMDocument $document): array
    {
        $parties = array_merge($paquet->listParts('word/header'), $paquet->listParts('word/footer'));

        $detail = [];

        foreach ($parties as $partie) {
            $xml = $paquet->read($partie);

            if ($xml === null) {
                continue;
            }

            $doc = XmlLoader::load($xml, $partie);
            $texte = trim(XmlLoader::query($doc, '//w:t')[0]?->textContent ?? '');
            $complet = implode(' ', array_map(
                static fn (DOMElement $noeud): string => $noeud->textContent,
                XmlLoader::query($doc, '//w:t')
            ));
            $aPage = false;

            foreach (XmlLoader::query($doc, '//w:instrText') as $instr) {
                if (preg_match('/\bPAGE\b/', $instr->textContent) === 1) {
                    $aPage = true;

                    break;
                }
            }

            $detail[] = [
                'partie' => $partie,
                'type' => str_contains($partie, 'header') ? 'entete' : 'pied',
                'texte' => trim(preg_replace('/\s+/u', ' ', $complet) ?? ''),
                'champ_page' => $aPage,
                'vide' => trim($texte) === '' && trim($complet) === '',
            ];
        }

        return [
            'parties' => count($detail),
            'detail' => $detail,
        ];
    }

    /**
     * Sommaires et listes DÉJÀ présents (champ ou tapés).
     *
     * La distinction est essentielle : un sommaire en champ se supprime en
     * retirant le champ, un sommaire tapé se supprime ligne par ligne. Les
     * instructions (§5.3) demandent les deux avant toute génération.
     *
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     */
    private function sommairesExistants(array $corps): array
    {
        $champ = 0;
        $parStyle = 0;
        $lignesTapees = 0;
        $zones = [];

        foreach ($corps['paragraphes'] as $paragraphe) {
            foreach ($paragraphe['champs'] as $c) {
                if ($c['type'] === 'table_of_contents') {
                    $champ++;
                }
            }

            if ($paragraphe['est_style_sommaire']) {
                $parStyle++;
            }

            if ($this->estLigneDeSommaireTape($paragraphe['texte'])) {
                $lignesTapees++;
                $zones[] = $paragraphe['index'];
            }
        }

        return [
            'en_champ' => $champ,
            'par_style' => $parStyle,
            'lignes_tapees' => $lignesTapees,
            'index_lignes_tapees' => $zones,
        ];
    }

    /**
     * Une ligne « texte …  numéro » ressemble-t-elle à une entrée de sommaire ?
     *
     * Le motif est volontairement STRICT (points de suite ou tabulation), pour ne
     * pas classer une phrase finissant par un nombre. Les instructions (§5.3)
     * demandent en plus au moins 3 lignes CONSÉCUTIVES : cette agrégation est
     * faite par l'appelant, pas ici — un détecteur de ligne ne doit pas décider
     * d'une zone.
     */
    private function estLigneDeSommaireTape(string $texte): bool
    {
        if ($texte === '' || mb_strlen($texte) > self::TAILLE_MAX_LIGNE_LISTE) {
            return false;
        }

        return preg_match('/\.{3,}\s*\d+$/u', $texte) === 1
            || preg_match('/\t\s*\d+$/u', $texte) === 1;
    }

    /**
     * Candidats titres, avec le motif qui les a reconnus.
     *
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     */
    private function candidatsTitres(array $corps): array
    {
        $parStyle = [];
        $parMotif = [];
        $incertains = [];

        foreach ($corps['paragraphes'] as $paragraphe) {
            $texte = $paragraphe['texte'];

            if ($texte === '') {
                continue;
            }

            if ($paragraphe['est_titre_style']) {
                $parStyle[] = [
                    'index' => $paragraphe['index'],
                    'texte' => $texte,
                    'niveau' => $paragraphe['niveau_titre'],
                    'style' => $paragraphe['style_id'],
                ];

                continue;
            }

            // Un style de légende n'est PAS un titre : les instructions relèvent
            // le cas de légendes en « Titre 4 » qui polluent la table des
            // matières. Le signaler ici évite de le corriger en silence.
            if ($paragraphe['est_legende_style']) {
                continue;
            }

            $motif = $this->numeroTitre->detect($texte);

            if ($motif !== null) {
                $parMotif[] = [
                    'index' => $paragraphe['index'],
                    'texte' => $texte,
                    'niveau' => $motif['level'],
                    'motif' => $motif['matched'],
                    'type_numerotation' => $motif['kind'],
                ];

                continue;
            }

            // Gras, court, sans point final : candidat faible. Il n'est PAS
            // retenu comme titre — il est listé comme « à vérifier », ce qui est
            // exactement le point d'entrée prévu pour une future IA.
            if ($paragraphe['gras'] && mb_strlen($texte) < 120 && ! str_ends_with(trim($texte), '.')) {
                $incertains[] = [
                    'index' => $paragraphe['index'],
                    'texte' => $texte,
                    'signal' => 'gras_court_sans_point',
                ];
            }
        }

        return [
            'par_style' => ['nombre' => count($parStyle), 'detail' => $parStyle],
            'par_motif' => ['nombre' => count($parMotif), 'detail' => $parMotif],
            'a_verifier' => ['nombre' => count($incertains), 'detail' => $incertains],
        ];
    }

    /**
     * Candidats légendes, avec leur niveau de preuve.
     *
     * Niveaux, du plus fort au plus faible :
     *  - `champ_seq` : un champ `SEQ <Type>` existe dans le paragraphe ;
     *  - `motif` : le texte correspond au motif et un type connu est reconnu ;
     *  - `style` : le paragraphe porte un style de légende, sans motif.
     *
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     */
    private function candidatsLegendes(array $corps): array
    {
        $resultat = [];

        foreach ($corps['paragraphes'] as $paragraphe) {
            $preuve = null;
            $type = null;
            $numero = null;

            foreach ($paragraphe['champs'] as $champ) {
                if ($champ['type'] === 'sequence' && $champ['identifiant'] !== null) {
                    $preuve = 'champ_seq';
                    $type = $champ['identifiant'];

                    break;
                }
            }

            $motif = $this->legende->detect($paragraphe['texte']);

            if ($motif !== null) {
                $preuve ??= 'motif';
                // `keyword()` rend le mot-clé du TYPE (« Figure », « Tableau »),
                // et non `listTitle()` qui désigne la LISTE du frontispice
                // (« LISTE DES FIGURES »). Confondre les deux annoncerait la
                // liste comme type de l'élément.
                $type ??= $motif['category']->keyword();
                $numero = $motif['original_number'];
            }

            if ($preuve === null && $paragraphe['est_legende_style']) {
                $preuve = 'style';
            }

            if ($preuve === null) {
                continue;
            }

            $resultat[] = [
                'index' => $paragraphe['index'],
                'texte' => $paragraphe['texte'],
                'type' => $type,
                'numero' => $numero,
                'preuve' => $preuve,
                'style_titre' => $paragraphe['est_titre_style'],
            ];
        }

        return [
            'nombre' => count($resultat),
            'detail' => $resultat,
        ];
    }

    /**
     * Candidat page de garde : plage de paragraphes avant le premier titre liminaire.
     *
     * **Pourquoi une PLAGE et pas une certitude.** Les instructions (§5.2)
     * demandent de ne rien supprimer au-delà de 2 pages et d'avertir. L'analyseur
     * ne fournit donc qu'un candidat, avec sa taille, pour que la décision reste
     * mesurable et réversible.
     *
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     */
    private function candidatPageDeGarde(array $corps): array
    {
        $liminaires = [
            'sommaire', 'table des matieres', 'table des matières', 'dedicace', 'dédicace',
            'remerciements', 'avant-propos', 'sigles', 'abreviations', 'abréviations',
            'resume', 'résumé', 'abstract', 'liste des figures', 'liste des tableaux',
            'introduction', 'introduction generale', 'introduction générale',
        ];

        $premierTitre = null;

        foreach ($corps['paragraphes'] as $paragraphe) {
            if ($paragraphe['vide']) {
                continue;
            }

            $texte = mb_strtolower(trim($paragraphe['texte']));

            $estLiminaire = false;

            foreach ($liminaires as $mot) {
                if ($texte === $mot || str_starts_with($texte, $mot.' ') || str_starts_with($texte, $mot.':')) {
                    $estLiminaire = true;

                    break;
                }
            }

            if ($estLiminaire || $paragraphe['est_titre_style'] || $this->numeroTitre->isNumbered($paragraphe['texte'])) {
                $premierTitre = $paragraphe['index'];

                break;
            }
        }

        if ($premierTitre === null) {
            return ['candidat' => false, 'raison' => 'aucun titre reconnu'];
        }

        $debut = null;

        foreach ($corps['paragraphes'] as $paragraphe) {
            if (! $paragraphe['vide']) {
                $debut = $paragraphe['index'];

                break;
            }
        }

        return [
            'candidat' => $premierTitre > 0,
            'premier_titre_index' => $premierTitre,
            'debut_contenu_index' => $debut,
            'paragraphes_avant_titre' => $premierTitre,
            'debut_texte' => $debut === null ? null : mb_substr((string) ($corps['paragraphes'][$debut]['texte'] ?? ''), 0, 120),
        ];
    }

    /**
     * Avertissements en lecture : ce que l'analyseur voit d'anormal.
     *
     * @param  array<string, mixed>  $corps
     * @return array<int, string>
     */
    private function avertissements(array $corps): array
    {
        $avertissements = [];

        if ($corps['serie_vides_max'] >= 3) {
            $avertissements[] = 'Série de '.$corps['serie_vides_max'].' paragraphes vides consécutifs détectée.';
        }

        $titresEnLegende = array_filter(
            $corps['paragraphes'],
            static fn (array $p): bool => $p['est_titre_style'] && $p['est_legende_style']
        );

        if ($titresEnLegende !== []) {
            $avertissements[] = count($titresEnLegende).' paragraphe(s) portent à la fois un style de titre et de légende.';
        }

        return $avertissements;
    }

    /**
     * Le paragraphe contient-il un saut de page explicite ?
     */
    private function contientSautDePage(DOMElement $paragraphe): bool
    {
        foreach (XmlLoader::descendants($paragraphe, 'w:br') as $saut) {
            if ((XmlLoader::attributeValue($saut, 'w:type') ?? '') === 'page') {
                return true;
            }
        }

        return false;
    }
}
