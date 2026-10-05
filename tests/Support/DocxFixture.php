<?php

declare(strict_types=1);

namespace Tests\Support;

use ZipArchive;

/**
 * Fabrique des fichiers `.docx` minimaux pour les tests du parseur natif.
 *
 * On écrit l'OOXML à la main plutôt que d'utiliser PHPWord : les tests doivent
 * vérifier la lecture sur des structures XML MAÎTRISÉES (numérotation locale
 * des styles, `outlineLvl`, fusions de tableau), ce qu'un générateur ne permet
 * pas de contrôler finement.
 */
final class DocxFixture
{
    /** Espace de noms principal. */
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Construit un `.docx` à partir d'un corps XML et de styles optionnels.
     *
     * @param  string  $bodyXml  Contenu de `<w:body>` (paragraphes, tableaux…)
     * @param  string  $stylesXml  Contenu de `<w:styles>` (clé optionnelle)
     * @param  array<string, string>  $extraParts  Parties additionnelles nom → contenu
     * @return string Chemin du fichier temporaire créé
     */
    public static function create(
        string $bodyXml,
        string $stylesXml = '',
        array $extraParts = [],
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'docx_fixture_').'.docx';

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('word/document.xml', self::document($bodyXml));

        if ($stylesXml !== '') {
            $zip->addFromString('word/styles.xml', self::styles($stylesXml));
        }

        foreach ($extraParts as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }

    /**
     * Paragraphe simple.
     *
     * @param  null|string  $styleId  Identifiant de style (`w:pStyle`)
     * @param  null|int  $outlineLevel  Niveau de plan (0 = niveau 1)
     * @param  array{size?: int, bold?: bool, italic?: bool}  $runFormat  Formatage du run
     */
    public static function paragraph(
        string $text,
        ?string $styleId = null,
        ?int $outlineLevel = null,
        array $runFormat = [],
    ): string {
        $paragraphProperties = '';

        if ($styleId !== null || $outlineLevel !== null) {
            $paragraphProperties = '<w:pPr>';

            if ($styleId !== null) {
                $paragraphProperties .= '<w:pStyle w:val="'.self::escape($styleId).'"/>';
            }
            if ($outlineLevel !== null) {
                $paragraphProperties .= '<w:outlineLvl w:val="'.$outlineLevel.'"/>';
            }

            $paragraphProperties .= '</w:pPr>';
        }

        $runProperties = '';
        if ($runFormat !== []) {
            $runProperties = '<w:rPr>';

            if (isset($runFormat['size'])) {
                $runProperties .= '<w:sz w:val="'.$runFormat['size'].'"/>';
            }
            if (($runFormat['bold'] ?? false) === true) {
                $runProperties .= '<w:b/>';
            }
            if (($runFormat['italic'] ?? false) === true) {
                $runProperties .= '<w:i/>';
            }

            $runProperties .= '</w:rPr>';
        }

        return '<w:p>'.$paragraphProperties
            .'<w:r>'.$runProperties.'<w:t xml:space="preserve">'.self::escape($text).'</w:t></w:r>'
            .'</w:p>';
    }

    /**
     * Paragraphe vide (Word en insère comme espacement).
     */
    public static function emptyParagraph(): string
    {
        return '<w:p/>';
    }

    /**
     * Paragraphe image.
     *
     * @param  string  $relationId  Identifiant de relation (`r:embed`)
     */
    public static function imageParagraph(string $relationId, string $captionText = ''): string
    {
        $caption = $captionText === ''
            ? ''
            : '<w:r><w:t xml:space="preserve">'.self::escape($captionText).'</w:t></w:r>';

        return '<w:p><w:r><w:drawing>'
            .'<wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            .'<a:blip r:embed="'.$relationId.'"/>'
            .'</a:graphic></wp:inline></w:drawing></w:r>'.$caption.'</w:p>';
    }

    /**
     * Tableau simple.
     *
     * @param  array<int, array<int, string>>  $rows  Lignes de cellules
     */
    public static function table(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $columns = count($rows[0]);
        $xml = '<w:tbl><w:tblGrid>';

        for ($i = 0; $i < $columns; $i++) {
            $xml .= '<w:gridCol w:w="2000"/>';
        }

        $xml .= '</w:tblGrid>';

        foreach ($rows as $row) {
            $xml .= '<w:tr>';

            foreach ($row as $cell) {
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="2000" w:type="dxa"/></w:tcPr>'
                    .'<w:p><w:r><w:t xml:space="preserve">'.self::escape($cell).'</w:t></w:r></w:p>'
                    .'</w:tc>';
            }

            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>';
    }

    /**
     * Tableau avec fusion horizontale sur la première cellule.
     *
     * Représentation RÉALISTE : une cellule fusionnée sur N colonnes remplace
     * les N cellules d'origine — les suivantes sont donc ABSENTES de l'XML.
     * Le `w:tblGrid` déclare, lui, le nombre réel de colonnes du tableau.
     *
     * @param  array<int, array<int, string>>  $rows  Lignes de cellules (la première
     *                                                peut ne contenir qu'une cellule fusionnée)
     */
    public static function tableWithGridSpan(array $rows, int $span, int $columns = 2): string
    {
        $xml = '<w:tbl><w:tblGrid>';

        for ($i = 0; $i < $columns; $i++) {
            $xml .= '<w:gridCol w:w="2000"/>';
        }

        $xml .= '</w:tblGrid>';

        foreach ($rows as $rowIndex => $row) {
            $xml .= '<w:tr>';

            foreach ($row as $cellIndex => $cell) {
                $spanXml = ($rowIndex === 0 && $cellIndex === 0 && $span > 1)
                    ? '<w:gridSpan w:val="'.$span.'"/>'
                    : '';

                $xml .= '<w:tc><w:tcPr>'.$spanXml
                    .'<w:tcW w:w="2000" w:type="dxa"/></w:tcPr>'
                    .'<w:p><w:r><w:t xml:space="preserve">'.self::escape($cell).'</w:t></w:r></w:p>'
                    .'</w:tc>';
            }

            $xml .= '</w:tr>';
        }

        return $xml.'</w:tbl>';
    }

    /**
     * Définition de style, avec héritage optionnel.
     *
     * @param  null|string  $basedOn  Identifiant du style parent
     * @param  null|int  $outlineLevel  Niveau de plan (0 = niveau 1)
     * @param  array{size?: int, bold?: bool}  $runFormat
     */
    public static function style(
        string $styleId,
        string $name,
        ?string $basedOn = null,
        ?int $outlineLevel = null,
        array $runFormat = [],
        bool $isDefault = false,
    ): string {
        $xml = '<w:style w:type="paragraph" w:styleId="'.self::escape($styleId).'"'
            .($isDefault ? ' w:default="1"' : '').'>'
            .'<w:name w:val="'.self::escape($name).'"/>';

        if ($basedOn !== null) {
            $xml .= '<w:basedOn w:val="'.self::escape($basedOn).'"/>';
        }

        if ($outlineLevel !== null) {
            $xml .= '<w:pPr><w:outlineLvl w:val="'.$outlineLevel.'"/></w:pPr>';
        }

        if ($runFormat !== []) {
            $xml .= '<w:rPr>';

            if (isset($runFormat['size'])) {
                $xml .= '<w:sz w:val="'.$runFormat['size'].'"/>';
            }
            if (($runFormat['bold'] ?? false) === true) {
                $xml .= '<w:b/>';
            }

            $xml .= '</w:rPr>';
        }

        return $xml.'</w:style>';
    }

    /**
     * Supprime un fichier de fixture.
     */
    public static function cleanup(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Échappement XML minimal.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>';
    }

    private static function document(string $bodyXml): string
    {
        // Les espaces de noms `wps` et `wpg` (formes vectorielles) sont déclarés
        // comme dans un vrai document Word. Sans eux, un `<wps:wsp>` serait un
        // XML invalide — et un test de diagramme échouerait pour une raison sans
        // rapport avec le comportement vérifié.
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="'.self::W.'" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            .'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            .'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            .'xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" '
            .'xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup">'
            .'<w:body>'.$bodyXml.'<w:sectPr/></w:body></w:document>';
    }

    private static function styles(string $stylesXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:styles xmlns:w="'.self::W.'">'.$stylesXml.'</w:styles>';
    }
}
