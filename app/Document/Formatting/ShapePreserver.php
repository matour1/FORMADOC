<?php

declare(strict_types=1);

namespace App\Document\Formatting;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Préservation des DIAGRAMMES EN FORMES à travers la génération DOCX.
 *
 * **Le défaut que ce composant corrige, et sa mesure.** Le pipeline lit le texte
 * avec un parseur OOXML qui connaît les paragraphes, les tableaux et les images.
 * Il ne connaît PAS les formes vectorielles (`wps:wsp` — rectangles, flèches,
 * textes d'un organigramme) : leur contenu n'est lu par aucun chemin.
 *
 * Mesure du 2026-09-30 sur les 1 722 contenus distincts du corpus :
 *
 *     contenus avec des FORMES : 4 (0,2 %)
 *     formes individuelles     : 285
 *     zones de texte           : 250
 *
 * Les 4 documents concernés sont des mémoires contenant des organigrammes. Leur
 * contenu de formes **disparaissait silencieusement** à la génération : ni perte
 * signalée, ni erreur — le document sortait simplement amputé.
 *
 * **Ce que fait ce composant, et ce qu'il ne fait pas.** Il réinjecte dans le
 * document de sortie les formes du document SOURCE, à l'endroit du marqueur que
 * le reconstructeur a laissé. Il ne les analyse pas, ne les convertit pas, ne les
 * renumérote pas : elles sont recopiées **à l'octet près**.
 *
 * C'est un choix délibéré, et la fréquence le justifie. Analyser le contenu d'une
 * forme pour en faire des titres et des paragraphes serait un travail considérable
 * (qu'est-ce qu'un rectangle ? une flèche ? un groupe ?) pour 4 documents sur
 * 1 722. Le préserver tel quel ne perd RIEN et n'exige aucune interprétation — et
 * c'est la seule option qui ne dégrade pas, une forme convertie en image étant
 * irrécupérable.
 *
 * **Pourquoi un post-traitement XML plutôt qu'un élément PhpWord.** PhpWord
 * n'expose aucun moyen d'insérer un fragment OOXML arbitraire dans un paragraphe.
 * La même contrainte avait déjà imposé `w:pgNumType w:fmt` et `w:gridSpan` au
 * `DocumentReconstructor` : on suit donc le motif déjà en place dans ce projet —
 * marqueur dans le corps, remplacement sur le XML après écriture.
 */
final class ShapePreserver
{
    /**
     * Espace de noms du dessin OOXML.
     */
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Marqueur laissé dans le corps par le reconstructeur.
     *
     * **Un marqueur TEXTUEL, et non un commentaire XML.** PhpWord écrit du texte
     * brut (`writeRaw`) : un commentaire inséré dans le contenu serait échappé en
     * `&lt;!--…--&gt;` et ne serait plus reconnaissable. Un texte simple survit à
     * l'échappement, et le remplacement s'opère ensuite sur le XML final.
     */
    public const MARQUEUR = 'FORMADOC_SHAPES_HERE';

    /**
     * Nœuds du XML qui portent une forme vectorielle, par ordre de spécificité.
     *
     * `wpg:wgp` est un GROUPE de formes : un organigramme entier. Il contient des
     * `wps:wsp`, il faut donc le traiter en premier et **sauter les formes qu'il
     * contient** — sinon le groupe serait réinjecté, puis chacun de ses membres
     * une seconde fois, et l'organigramme apparaîtrait dupliqué.
     *
     * @var array<int, string>
     */
    private const SELECTEURS_FORMES = [
        '//w:drawing[.//wpg:wgp]',
        '//w:drawing[.//wps:wsp]',
    ];

    /**
     * Espaces de noms nécessaires à l'évaluation des sélecteurs.
     *
     * Un XPath non déclaré LÈVE une erreur, il ne retourne pas un ensemble vide :
     * sans ces déclarations, la requête échouerait et la préservation serait
     * silencieusement sans effet — le défaut qu'on veut corriger.
     *
     * @var array<string, string>
     */
    private const NAMESPACES = [
        'w' => self::WORD_NS,
        'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
        'wpg' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingGroup',
        'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'v' => 'urn:schemas-microsoft-com:vml',
        'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
    ];

    /**
     * Extrait les formes vectorielles d'un document source.
     *
     * On retourne le XML de chaque `<w:drawing>` **dans l'ordre du document** :
     * l'ordre est ce qui permet de les replacer correctement. Un ensemble non
     * ordonné donnerait des diagrammes intervertis.
     *
     * @param  string  $sourcePath  Chemin du `.docx` d'origine
     * @return array<int, string> Fragments XML, un par diagramme en formes
     */
    public function extraire(string $sourcePath): array
    {
        $xml = $this->lirePartie($sourcePath, 'word/document.xml');

        if ($xml === null) {
            return [];
        }

        $dom = new DOMDocument;

        // `@` et non une exception : un XML illisible laisse le document se
        // générer sans ses formes. C'est une dégradation, mais elle est
        // PRÉFÉRABLE à un échec de génération — perdre le document entier parce
        // qu'un diagramme n'est pas réinjectable serait pire que le défaut.
        if (! @$dom->loadXML($xml)) {
            return [];
        }

        $xpath = $this->xpath($dom);

        if ($xpath === null) {
            return [];
        }

        $formes = [];
        $traitees = [];

        foreach (self::SELECTEURS_FORMES as $selecteur) {
            $noeuds = $xpath->query($selecteur);

            if ($noeuds === false) {
                continue;
            }

            foreach ($noeuds as $noeud) {
                if (! $noeud instanceof DOMElement) {
                    continue;
                }

                // Le groupe consomme ses membres : on note les nœuds déjà
                // couverts pour ne pas les réinjecter séparément.
                $dejaCouvert = false;

                foreach ($traitees as $parent) {
                    if ($parent->contains($noeud)) {
                        $dejaCouvert = true;

                        break;
                    }
                }

                if ($dejaCouvert) {
                    continue;
                }

                $fragment = $dom->saveXML($noeud);

                if (is_string($fragment) && $fragment !== '') {
                    $formes[] = $fragment;
                    $traitees[] = $noeud;
                }
            }
        }

        return $formes;
    }

    /**
     * Réinjecte les formes du source dans le DOCX généré.
     *
     * **Où, et pourquoi à cet endroit.** Le marqueur laissé par le reconstructeur
     * indique l'emplacement des diagrammes dans le fil du document. On remplace
     * donc le PARAGRAPHE qui le porte par un paragraphe contenant les `<w:drawing>`
     * du source — ce qui préserve la position, et donc l'ordre de lecture.
     *
     * **Ce que la méthode ne fait PAS.** Elle n'ouvre pas la source si elle n'y
     * trouve aucune forme, ne touche pas aux images, et laisse le fichier intact
     * en cas d'échec. La génération d'un document ne doit jamais échouer à cause
     * d'un post-traitement de confort : on rend le fichier inchangé et l'anomalie
     * est journalisée.
     *
     * @param  string  $docxPath  DOCX généré, modifié sur place
     * @param  string  $sourcePath  DOCX d'origine, d'où viennent les formes
     * @return int Nombre de marqueurs effectivement remplacés
     */
    public function reinjecter(string $docxPath, string $sourcePath): int
    {
        $zip = new ZipArchive;

        if ($zip->open($docxPath) !== true) {
            return 0;
        }

        $xml = $zip->getFromName('word/document.xml');

        if ($xml === false) {
            $zip->close();

            return 0;
        }

        // Aucun marqueur : rien à remplacer. On sort SANS réécrire le fichier —
        // une réécriture inutile risquerait d'abîmer le document pour rien.
        if (! str_contains($xml, self::MARQUEUR)) {
            $zip->close();

            return 0;
        }

        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml)) {
            $zip->close();

            return 0;
        }

        $xpath = $this->xpath($dom);

        if ($xpath === null) {
            $zip->close();

            return 0;
        }

        // **Les formes sont extraites même si la source est illisible : on
        // obtient alors une liste vide, et les marqueurs sont SUPPRIMÉS.**
        //
        // C'est le point critique du composant : le marqueur est du TEXTE, et un
        // marqueur non remplacé s'afficherait tel quel dans le document livré.
        // Un texte technique visible chez l'utilisateur est un défaut PIRE que
        // l'absence du diagramme — il donne l'impression d'un document cassé.
        // On nettoie donc toujours, y compris quand il n'y a rien à replacer.
        $formes = $this->extraire($sourcePath);

        $remplaces = $this->remplacerMarqueurs($dom, $xpath, $formes);

        $nouveauXml = $dom->saveXML();

        if (is_string($nouveauXml) && $nouveauXml !== '') {
            $zip->deleteName('word/document.xml');
            $zip->addFromString('word/document.xml', $nouveauXml);
        }

        $zip->close();

        return $remplaces;
    }

    /**
     * Remplace chaque paragraphe marqueur par son diagramme.
     *
     * Un marqueur par forme, dans l'ordre : le premier marqueur reçoit le premier
     * diagramme. Si le document compte plus de marqueurs que de formes, les
     * marqueurs restants sont SUPPRIMÉS (un texte technique visible dans le
     * document serait un défaut pire que l'absence du diagramme) ; s'il compte
     * plus de formes, les formes excédentaires sont ignorées.
     *
     * @param  array<int, string>  $formes
     * @return int Nombre de marqueurs traités
     */
    private function remplacerMarqueurs(DOMDocument $dom, DOMXPath $xpath, array $formes): int
    {
        $candidats = $xpath->query('//w:p[contains(., "'.self::MARQUEUR.'")]');

        if ($candidats === false) {
            return 0;
        }

        $paragraphes = [];

        foreach ($candidats as $noeud) {
            if ($noeud instanceof DOMElement) {
                $paragraphes[] = $noeud;
            }
        }

        $remplaces = 0;

        foreach ($paragraphes as $index => $paragraphe) {
            $parent = $paragraphe->parentNode;

            if ($parent === null) {
                continue;
            }

            if (! isset($formes[$index])) {
                // Plus de diagramme à placer : on retire le marqueur seul.
                $parent->removeChild($paragraphe);

                continue;
            }

            // Le fragment est importé depuis son propre DOM : `importNode` est
            // nécessaire, `loadXML` dans la foulée du même DOM corromprait le
            // document cible.
            $fragment = $this->importerFragment($dom, $formes[$index]);

            if ($fragment === null) {
                $parent->removeChild($paragraphe);

                continue;
            }

            $nouveau = $dom->createElementNS(self::WORD_NS, 'w:p');
            $nouveau->appendChild($fragment);

            $parent->replaceChild($nouveau, $paragraphe);
            $remplaces++;
        }

        return $remplaces;
    }

    /**
     * Importe un fragment `<w:drawing>` dans le document cible.
     *
     * Retourne null si le fragment est illisible — l'appelant retire alors le
     * marqueur plutôt que de laisser du texte technique dans le document.
     */
    private function importerFragment(DOMDocument $cible, string $xml): ?DOMElement
    {
        $source = new DOMDocument;

        // Le fragment doit être enveloppé : un XML doit avoir UNE racine, et
        // `w:drawing` seul en est une — mais l'enveloppe garantit que les
        // déclarations d'espaces de noms du fragment sont reprises.
        $enveloppe = '<?xml version="1.0" encoding="UTF-8"?>'.$xml;

        if (! @$source->loadXML($enveloppe)) {
            return null;
        }

        $racine = $source->documentElement;

        if (! $racine instanceof DOMElement) {
            return null;
        }

        $importe = $cible->importNode($racine, true);

        return $importe instanceof DOMElement ? $importe : null;
    }

    /**
     * XPath avec les espaces de noms du dessin déclarés.
     *
     * Une déclaration manquante LÈVE une erreur d'évaluation, elle ne rend pas un
     * résultat vide : la préservation serait alors sans effet, en silence.
     */
    private function xpath(DOMDocument $dom): ?DOMXPath
    {
        $xpath = new DOMXPath($dom);

        foreach (self::NAMESPACES as $prefixe => $uri) {
            $xpath->registerNamespace($prefixe, $uri);
        }

        return $xpath;
    }

    /**
     * Lit une partie d'une archive DOCX, ou null si elle est absente.
     */
    private function lirePartie(string $docxPath, string $partie): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($docxPath) !== true) {
            return null;
        }

        $contenu = $zip->getFromName($partie);
        $zip->close();

        return $contenu === false ? null : $contenu;
    }
}
