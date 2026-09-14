<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Services\DocumentGeneration\PdfPreviewService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Footer;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;
use Symfony\Component\Process\Process;

/**
 * Édition de documents DOCX (pièces jointes du chat).
 *
 * L'IA peut demander la MODIFICATION d'une pièce jointe (pas seulement son
 * analyse) : remplacements de texte, édition des titres, ajout de
 * paragraphes, conversion en PDF. Les opérations sont appliquées sur une
 * COPIE du fichier : le fichier original n'est jamais modifié — le résultat
 * est stocké dans `chat/generated/` (comme les autres outils).
 *
 * Opérations supportées (via function calling) :
 *   - replace_text       : remplace toutes les occurrences d'un texte
 *   - edit_title         : remplace le texte d'un titre (par numéro 1-N)
 *   - append_text        : ajoute un paragraphe à la fin du document
 *   - change_title_level : change le NIVEAU d'un titre (Heading1 → Heading3…)
 *   - change_title_color : change la COULEUR d'un titre (rouge, bleu, hex…)
 *   - change_font        : change la POLICE d'un texte/titre (Times New Roman…)
 *   - format_complete    : mise en forme complète du document (police, taille,
 *                          interligne, titres) via un style global
 *   - to_pdf             : convertit le document en PDF (LibreOffice headless)
 *   - pdf_to_docx        : convertit une pièce jointe PDF en DOCX éditable
 *                          (LibreOffice headless)
 *
 * Les opérations de texte modifient une COPIE en mémoire ; la sauvegarde
 * réécrit le fichier DOCX. Les conversions (to_pdf / pdf_to_docx) passent
 * par LibreOffice headless : si l'outil n'est pas installé, le message
 * retourné indique la marche à suivre MANUELLE (Word : Fichier → Enregistrer
 * sous…).
 */
class DocumentEditService
{
    /** Chemins relatifs du stockage (disk 'local'). */
    private const OUTPUT_DIR = 'chat/generated';

    /**
     * Liste des opérations d'édition supportées (utilisée par l'inventaire
     * de capacités injecté dans le prompt système du chat).
     *
     * @return array<int, array{operation: string, description: string}>
     */
    public function supportedOperations(): array
    {
        return [
            ['operation' => 'replace_text', 'description' => 'Remplace toutes les occurrences d\'un texte (search → replacement) dans tout le document (corps, tableaux, en-têtes, pieds).'],
            ['operation' => 'edit_title', 'description' => 'Modifie le texte d\'un titre identifié par son numéro (title_number, 1 = premier).'],
            ['operation' => 'append_text', 'description' => 'Ajoute un paragraphe à la fin du document.'],
            ['operation' => 'change_title_level', 'description' => 'Change le NIVEAU d\'un titre (title_number + new_level 1-6) : Heading1 → Heading3 par exemple.'],
            ['operation' => 'change_title_color', 'description' => 'Change la COULEUR d\'un titre (title_number + color hex FF0000 ou nom français rouge/bleu/vert…).'],
            ['operation' => 'change_font', 'description' => 'Change la POLICE de tout le document (font_name, ex. Times New Roman).'],
            ['operation' => 'format_complete', 'description' => 'Mise en forme complète : police (font_name), taille (font_size), interligne (line_spacing 1.0-2.0), couleur des titres (title_color), niveau des titres (title_level).'],
            ['operation' => 'to_pdf', 'description' => 'Convertit le DOCX en PDF via LibreOffice headless (document_to_pdf).'],
            ['operation' => 'pdf_to_docx', 'description' => 'Convertit une pièce jointe PDF en DOCX éditable via LibreOffice headless (document_to_docx).'],
        ];
    }

    /**
     * Applique une opération d'édition sur une pièce jointe DOCX.
     *
     * @param  array<string, mixed>  $arguments  {
     *                                           source_path: string  chemin relatif (disk local) du DOCX source
     *                                           operation: string    replace_text|edit_title|append_text|to_pdf
     *                                           ...opérations spécifiques
     *                                           }
     * @return array{result?: string, error?: string}
     */
    public function apply(array $arguments): array
    {
        $sourcePath = (string) ($arguments['source_path'] ?? '');
        $operation = (string) ($arguments['operation'] ?? '');
        $outputName = (string) ($arguments['output_filename'] ?? 'document_modifie');

        if ($sourcePath === '') {
            return ['error' => 'Argument source_path manquant (chemin de la pièce jointe à modifier).'];
        }

        if ($operation === '') {
            return ['error' => 'Argument operation manquant (replace_text, edit_title, append_text, '
                .'change_title_level, change_title_color, change_font, format_complete, to_pdf, pdf_to_docx).'];
        }

        // Vérification du chemin : doit être une pièce jointe du chat
        // (chat/attachments/...) ou un fichier déjà généré par le chat
        // (chat/generated/...). On interdit tout autre chemin.
        if (! str_starts_with($sourcePath, 'chat/attachments/')
            && ! str_starts_with($sourcePath, 'chat/generated/')) {
            return ['error' => 'Chemin source invalide : seules les pièces jointes du chat '
                .'ou les fichiers générés peuvent être modifiés.'];
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($sourcePath)) {
            return ['error' => 'Pièce jointe introuvable : '.$sourcePath];
        }

        // PDF → DOCX : pas de chargement PhpWord (fichier PDF) — conversion
        // LibreOffice directe, le résultat est un DOCX éditable.
        if ($operation === 'pdf_to_docx') {
            return $this->convertPdfToDocx($sourcePath, $outputName);
        }

        try {
            $phpWord = IOFactory::load($disk->path($sourcePath));
        } catch (\Throwable $e) {
            return ['error' => 'Impossible de lire le document (format non supporté ?) : '.$e->getMessage()];
        }

        // Conversion PDF = pas de modification en mémoire, on réutilise
        // le fichier source directement.
        if ($operation === 'to_pdf') {
            return $this->convertToPdf($sourcePath, $outputName);
        }

        // Opérations d'édition en mémoire
        switch ($operation) {
            case 'replace_text':
                $search = (string) ($arguments['search'] ?? '');
                $replacement = (string) ($arguments['replacement'] ?? '');
                if ($search === '') {
                    return ['error' => 'Argument search manquant pour replace_text.'];
                }
                $this->replaceTextRecursive($phpWord, $search, $replacement);
                break;

            case 'edit_title':
                $titleNumber = (int) ($arguments['title_number'] ?? 0);
                $newText = (string) ($arguments['new_text'] ?? '');
                if ($titleNumber < 1 || $newText === '') {
                    return ['error' => 'edit_title requiert title_number (≥ 1) et new_text.'];
                }
                $edited = $this->editTitleRecursive($phpWord, $titleNumber, $newText);
                if (! $edited) {
                    return ['error' => 'Titre n°'.$titleNumber.' introuvable dans le document.'];
                }
                break;

            case 'append_text':
                $text = (string) ($arguments['text'] ?? '');
                if ($text === '') {
                    return ['error' => 'Argument text manquant pour append_text.'];
                }
                $this->appendTextToSections($phpWord, $text);
                break;

            case 'change_title_level':
                $titleNumber = (int) ($arguments['title_number'] ?? 0);
                $newLevel = (int) ($arguments['new_level'] ?? 0);
                if ($titleNumber < 1 || $newLevel < 1 || $newLevel > 6) {
                    return ['error' => 'change_title_level requiert title_number (≥ 1) et new_level (1-6).'];
                }
                $changed = $this->changeTitleLevel($phpWord, $titleNumber, $newLevel);
                if (! $changed) {
                    return ['error' => 'Titre n°'.$titleNumber.' introuvable dans le document.'];
                }
                break;

            case 'change_title_color':
                $titleNumber = (int) ($arguments['title_number'] ?? 0);
                $color = (string) ($arguments['color'] ?? '');
                if ($titleNumber < 1 || $color === '') {
                    return ['error' => 'change_title_color requiert title_number (≥ 1) et color (hex ex. FF0000, ou nom).'];
                }
                $changed = $this->changeTitleColor($phpWord, $titleNumber, $this->normalizeColor($color));
                if (! $changed) {
                    return ['error' => 'Titre n°'.$titleNumber.' introuvable dans le document.'];
                }
                break;

            case 'change_font':
                $fontName = (string) ($arguments['font_name'] ?? '');
                if ($fontName === '') {
                    return ['error' => 'change_font requiert font_name (ex. Times New Roman).'];
                }
                $this->changeFontRecursive($phpWord, $fontName);
                break;

            case 'format_complete':
                $style = $this->normalizeFormatStyle($arguments);
                $this->formatComplete($phpWord, $style);
                break;

            default:
                return ['error' => 'Opération inconnue : '.$operation
                    .' (replace_text, edit_title, append_text, change_title_level, '
                    .'change_title_color, change_font, format_complete, to_pdf, pdf_to_docx).'];
        }

        // Sauvegarde en DOCX dans chat/generated (jamais sur la source)
        $outputPath = $this->saveDocx($phpWord, $outputName);

        return ['result' => 'Document modifié et enregistré : '.$outputPath];
    }

    /* ------------------------------------------------------------------
     |  Opérations d'édition
     | ------------------------------------------------------------------ */

    /**
     * Remplace toutes les occurrences d'un texte dans tous les éléments
     * texte du document (sections, tableaux, listes, en-têtes, pieds).
     */
    private function replaceTextRecursive(PhpWord $phpWord, string $search, string $replacement): void
    {
        foreach ($phpWord->getSections() as $section) {
            $this->replaceInContainer($section, $search, $replacement);

            foreach ($section->getHeaders() as $header) {
                $this->replaceInContainer($header, $search, $replacement);
            }
            foreach ($section->getFooters() as $footer) {
                $this->replaceInContainer($footer, $search, $replacement);
            }
        }
    }

    /**
     * Modifie le texte d'un titre identifié par son numéro (1 = premier titre).
     *
     * Les titres sont des objets Title, MAIS certains documents (créés sans
     * addTitleStyle) redeviennent des TextRun portant un style HeadingN/Title
     * à la relecture → fallback sur ces paragraphes stylés.
     */
    private function editTitleRecursive(PhpWord $phpWord, int $titleNumber, string $newText): bool
    {
        $current = 0;

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $isTitle = $element instanceof Title;
                $headingStyle = $element instanceof TextRun ? $this->headingStyleName($element) : null;

                if (! $isTitle && $headingStyle === null) {
                    continue;
                }

                $current++;

                if ($current === $titleNumber) {
                    if ($element instanceof Title) {
                        $text = $element->getText();
                        if ($text instanceof TextRun) {
                            // Titre multi-runs : on modifie le premier run texte
                            foreach ($text->getElements() as $run) {
                                if ($run instanceof Text) {
                                    $run->setText($newText);
                                    break;
                                }
                            }
                        } else {
                            // Title n'expose pas setText() : réflexion sur la
                            // propriété protégée $text.
                            $this->setTitleText($element, $newText);
                        }
                    } elseif ($element instanceof TextRun) {
                        // TextRun stylé HeadingN/Title : on modifie le premier run
                        foreach ($element->getElements() as $run) {
                            if ($run instanceof Text) {
                                $run->setText($newText);
                                break;
                            }
                        }
                    }

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Retourne le nom de style de paragraphe d'un TextRun si c'est un
     * style de titre (HeadingN, Title), sinon null.
     */
    private function headingStyleName(TextRun $textRun): ?string
    {
        $style = $textRun->getParagraphStyle();
        if (is_object($style) && method_exists($style, 'getStyleName')) {
            $name = (string) $style->getStyleName();
            if ($name !== '' && (preg_match('/^Heading[1-9]$/', $name) || $name === 'Title')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Ajoute un paragraphe à la fin de chaque section (le document
     * peut contenir plusieurs sections).
     */
    private function appendTextToSections(PhpWord $phpWord, string $text): void
    {
        foreach ($phpWord->getSections() as $section) {
            $section->addText($text);
        }
    }

    /**
     * Change le niveau d'un titre (Heading1 → Heading3…) via réflexion
     * sur les propriétés protégées $depth et $style de Title.
     */
    private function changeTitleLevel(PhpWord $phpWord, int $titleNumber, int $newLevel): bool
    {
        $current = 0;

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $isTitle = $element instanceof Title;
                $headingStyle = $element instanceof TextRun ? $this->headingStyleName($element) : null;

                if (! $isTitle && $headingStyle === null) {
                    continue;
                }

                $current++;

                if ($current !== $titleNumber) {
                    continue;
                }

                $this->applyHeadingLevel($element, $newLevel);

                return true;
            }
        }

        return false;
    }

    /**
     * Applique un niveau de titre (depth) à un élément (Title ou TextRun
     * stylé HeadingN/Title).
     */
    private function applyHeadingLevel(AbstractElement $element, int $newLevel): void
    {
        $styleName = $newLevel === 0 ? 'Title' : "Heading_{$newLevel}";

        if ($element instanceof Title) {
            // Title : réflexion sur $depth et $style
            $refDepth = new \ReflectionProperty(Title::class, 'depth');
            $refDepth->setAccessible(true);
            $refDepth->setValue($element, $newLevel);

            $refStyle = new \ReflectionProperty(Title::class, 'style');
            $refStyle->setAccessible(true);
            $refStyle->setValue($element, str_replace('_', '', $styleName));

            return;
        }

        if ($element instanceof TextRun) {
            // TextRun stylé HeadingN/Title : on remplace le style de paragraphe
            $style = $element->getParagraphStyle();
            if ($style instanceof Paragraph) {
                $style->setStyleName($styleName);
            } else {
                $element->setParagraphStyle($styleName);
            }
        }

        // S'assure que le style existe (sinon PhpWord n'écrit pas le styleName)
        if (! array_key_exists($styleName, Style::getStyles())) {
            Style::addTitleStyle($newLevel, ['bold' => true, 'size' => max(12, 18 - ($newLevel - 1) * 2)]);
        }
    }

    /**
     * Change la couleur d'un titre (toutes ses parties texte).
     */
    private function changeTitleColor(PhpWord $phpWord, int $titleNumber, string $color): bool
    {
        $current = 0;

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $isTitle = $element instanceof Title;
                $headingStyle = $element instanceof TextRun ? $this->headingStyleName($element) : null;

                if (! $isTitle && $headingStyle === null) {
                    continue;
                }

                $current++;

                if ($current !== $titleNumber) {
                    continue;
                }

                $this->applyTitleColor($element, $color);

                return true;
            }
        }

        return false;
    }

    /**
     * Applique une couleur sur tous les runs d'un titre.
     */
    private function applyTitleColor(AbstractElement $element, string $color): void
    {
        $runs = [];

        if ($element instanceof Title) {
            $text = $element->getText();
            if ($text instanceof TextRun) {
                $runs = $text->getElements();
            } else {
                // Title simple : on le transforme en TextRun pour appliquer
                // le style de police (le titre reste un titre dans le writer).
                $newRun = new TextRun;
                $newRun->addText((string) $text, ['color' => $color]);
                $refText = new \ReflectionProperty(Title::class, 'text');
                $refText->setAccessible(true);
                $refText->setValue($element, $newRun);

                return;
            }
        } elseif ($element instanceof TextRun) {
            $runs = $element->getElements();
        }

        foreach ($runs as $run) {
            if ($run instanceof Text) {
                $font = $run->getFontStyle();
                if ($font instanceof Font) {
                    $font->setColor($color);
                } else {
                    $run->setFontStyle(['color' => $color]);
                }
            } elseif ($run instanceof TextRun) {
                $this->applyTitleColor($run, $color);
            }
        }
    }

    /**
     * Change la police de TOUT le document (texte courant + titres),
     * en gardant les styles de titre existants.
     */
    private function changeFontRecursive(PhpWord $phpWord, string $fontName): void
    {
        // Police par défaut (nouveaux éléments)
        $phpWord->setDefaultFontName($fontName);

        // Styles de titres existants
        foreach (Style::getStyles() as $styleName => $style) {
            $styleName = (string) $styleName;
            if ($style instanceof Font && (str_starts_with($styleName, 'Heading_') || $styleName === 'Title')) {
                $style->setName($fontName);
            }
        }

        // Parcours de tous les éléments texte
        $this->walkElements($phpWord, function (Text $text) use ($fontName) {
            $font = $text->getFontStyle();
            if ($font instanceof Font) {
                $font->setName($fontName);
            } else {
                $text->setFontStyle(['name' => $fontName]);
            }
        });
    }

    /**
     * Applique une mise en forme complète (police, taille, interligne,
     * style de titres) à tout le document.
     */
    private function formatComplete(PhpWord $phpWord, array $style): void
    {
        $fontName = (string) ($style['font_name'] ?? '');
        $fontSize = (float) ($style['font_size'] ?? 0);
        $lineSpacing = (float) ($style['line_spacing'] ?? 0);
        $titleColor = (string) ($style['title_color'] ?? '');
        $titleLevel = (int) ($style['title_level'] ?? 0);

        if ($fontName !== '') {
            $phpWord->setDefaultFontName($fontName);
            foreach (Style::getStyles() as $styleName => $s) {
                $styleName = (string) $styleName;
                if ($s instanceof Font && (str_starts_with($styleName, 'Heading_') || $styleName === 'Title')) {
                    $s->setName($fontName);
                }
            }
        }

        if ($fontSize > 0) {
            $phpWord->setDefaultFontSize((int) $fontSize);
            foreach (Style::getStyles() as $styleName => $s) {
                $styleName = (string) $styleName;
                if ($s instanceof Font && (str_starts_with($styleName, 'Heading_') || $styleName === 'Title')) {
                    $s->setSize($fontSize + 4);
                }
            }
        }

        if ($titleColor !== '') {
            foreach (Style::getStyles() as $styleName => $s) {
                $styleName = (string) $styleName;
                if ($s instanceof Font && (str_starts_with($styleName, 'Heading_') || $styleName === 'Title')) {
                    $s->setColor($titleColor);
                }
            }
        }

        if ($titleLevel > 0 && $titleLevel <= 6) {
            // Ajoute les styles de titres manquants (1..titleLevel)
            for ($i = 1; $i <= $titleLevel; $i++) {
                if (! array_key_exists("Heading_{$i}", Style::getStyles())) {
                    Style::addTitleStyle($i, ['bold' => true, 'size' => max(12, 18 - ($i - 1) * 2)]);
                }
            }
        }

        if ($lineSpacing > 0) {
            foreach ($phpWord->getSections() as $section) {
                $this->applyLineSpacing($section, $lineSpacing);
            }
        }

        $this->walkElements($phpWord, function (Text $text) use ($fontName, $fontSize) {
            $font = $text->getFontStyle();
            if ($font instanceof Font) {
                if ($fontName !== '') {
                    $font->setName($fontName);
                }
                if ($fontSize > 0) {
                    $font->setSize($fontSize);
                }
            } else {
                $newStyle = [];
                if ($fontName !== '') {
                    $newStyle['name'] = $fontName;
                }
                if ($fontSize > 0) {
                    $newStyle['size'] = $fontSize;
                }
                if ($newStyle !== []) {
                    $text->setFontStyle($newStyle);
                }
            }
        });
    }

    /**
     * Applique un interligne (line spacing, ex. 1.5 / 2.0) à tous les
     * paragraphes d'un conteneur (récursif dans les tableaux).
     */
    private function applyLineSpacing(AbstractContainer $container, float $spacing): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof Text || $element instanceof Title) {
                continue;
            }

            if ($element instanceof TextRun || $element instanceof ListItemRun || $element instanceof ListItem) {
                $style = $element->getParagraphStyle();
                if ($style instanceof Paragraph) {
                    $style->setLineHeight($spacing);
                } elseif (is_string($style)) {
                    // Style nommé : on ne touche pas (défini globalement)
                } else {
                    $element->setParagraphStyle(['lineHeight' => $spacing]);
                }
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->applyLineSpacing($cell, $spacing);
                    }
                }
            }
        }
    }

    /**
     * Parcourt récursivement tous les éléments texte du document
     * (sections + tableaux + listes + en-têtes + pieds).
     */
    private function walkElements(PhpWord $phpWord, callable $callback): void
    {
        foreach ($phpWord->getSections() as $section) {
            $this->walkContainer($section, $callback);

            foreach ($section->getHeaders() as $header) {
                $this->walkContainer($header, $callback);
            }
            foreach ($section->getFooters() as $footer) {
                $this->walkContainer($footer, $callback);
            }
        }
    }

    /**
     * Parcourt un conteneur (Section, Cell, Header, Footer…) et appelle
     * le callback sur chaque élément Text (y compris dans les TextRun,
     * ListItem, Tableau).
     */
    private function walkContainer(AbstractContainer $container, callable $callback): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof Text) {
                $callback($element);
            } elseif ($element instanceof Title) {
                $text = $element->getText();
                if ($text instanceof TextRun) {
                    $this->walkContainer($text, $callback);
                } else {
                    $newRun = new TextRun;
                    $newRun->addText((string) $text);
                    $refText = new \ReflectionProperty(Title::class, 'text');
                    $refText->setAccessible(true);
                    $refText->setValue($element, $newRun);
                    $this->walkContainer($newRun, $callback);
                }
            } elseif ($element instanceof ListItem) {
                $textObject = $element->getTextObject();
                if ($textObject instanceof Text) {
                    $callback($textObject);
                } elseif ($textObject instanceof TextRun) {
                    $this->walkContainer($textObject, $callback);
                }
            } elseif ($element instanceof TextRun || $element instanceof ListItemRun) {
                $this->walkContainer($element, $callback);
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->walkContainer($cell, $callback);
                    }
                }
            }
        }
    }

    /**
     * Normalise une couleur (nom français → hex, ou hex tel quel).
     */
    private function normalizeColor(string $color): string
    {
        $color = trim($color);
        $map = [
            'rouge' => 'FF0000', 'red' => 'FF0000',
            'bleu' => '0000FF', 'blue' => '0000FF',
            'vert' => '008000', 'green' => '008000',
            'noir' => '000000', 'black' => '000000',
            'blanc' => 'FFFFFF', 'white' => 'FFFFFF',
            'orange' => 'FFA500',
            'violet' => '800080', 'purple' => '800080',
            'gris' => '808080', 'gray' => '808080',
            'jaune' => 'FFFF00', 'yellow' => 'FFFF00',
        ];
        $lower = strtolower($color);
        if (isset($map[$lower])) {
            return $map[$lower];
        }

        // Hex (avec ou sans #) : FF0000 / #FF0000
        if (preg_match('/^#?([0-9A-Fa-f]{6})$/', $color, $m)) {
            return strtoupper($m[1]);
        }

        return strtoupper($color);
    }

    /**
     * Normalise les arguments de format_complete en style cohérent.
     */
    private function normalizeFormatStyle(array $arguments): array
    {
        return [
            'font_name' => (string) ($arguments['font_name'] ?? ''),
            'font_size' => (float) ($arguments['font_size'] ?? 0),
            'line_spacing' => (float) ($arguments['line_spacing'] ?? 0),
            'title_color' => ($arguments['title_color'] ?? '') !== '' ? $this->normalizeColor((string) $arguments['title_color']) : '',
            'title_level' => (int) ($arguments['title_level'] ?? 0),
        ];
    }

    /* ------------------------------------------------------------------
     |  Parcours récursif
     | ------------------------------------------------------------------ */

    /**
     * Remplace le texte dans un conteneur (Section, TextRun, Cell,
     * Header, Footer, etc.).
     */
    private function replaceInContainer(AbstractContainer $container, string $search, string $replacement): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof Text) {
                $this->replaceInText($element, $search, $replacement);
            } elseif ($element instanceof Title) {
                $this->replaceInTitle($element, $search, $replacement);
            } elseif ($element instanceof ListItem) {
                $this->replaceInText($element->getTextObject(), $search, $replacement);
            } elseif ($element instanceof TextRun || $element instanceof ListItemRun) {
                $this->replaceInContainer($element, $search, $replacement);
            } elseif ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    foreach ($row->getCells() as $cell) {
                        $this->replaceInContainer($cell, $search, $replacement);
                    }
                }
            }
        }
    }

    /**
     * Remplace le texte d'un élément Text (une partie d'un paragraphe).
     */
    private function replaceInText(Text $text, string $search, string $replacement): void
    {
        $content = (string) $text->getText();
        if ($content === '' || ! $this->contains($content, $search)) {
            return;
        }

        $text->setText($this->replace($content, $search, $replacement));
    }

    /**
     * Remplace le texte d'un titre (string ou TextRun).
     */
    private function replaceInTitle(Title $title, string $search, string $replacement): void
    {
        $text = $title->getText();

        if ($text instanceof TextRun) {
            foreach ($text->getElements() as $run) {
                if ($run instanceof Text) {
                    $this->replaceInText($run, $search, $replacement);
                }
            }

            return;
        }

        if (is_string($text) && $this->contains($text, $search)) {
            $this->setTitleText($title, $this->replace($text, $search, $replacement));
        }
    }

    /**
     * Remplace le texte d'un titre simple. Title n'expose pas de setter :
     * on met à jour la propriété protégée $text via réflexion.
     */
    private function setTitleText(Title $title, string $newText): void
    {
        $reflection = new \ReflectionProperty(Title::class, 'text');
        $reflection->setAccessible(true);
        $reflection->setValue($title, $newText);
    }

    /**
     * Recherche insensible à la casse.
     */
    private function contains(string $haystack, string $needle): bool
    {
        return stripos($haystack, $needle) !== false;
    }

    /**
     * Remplace toutes les occurrences (insensible à la casse).
     */
    private function replace(string $haystack, string $search, string $replacement): string
    {
        return str_ireplace($search, $replacement, $haystack);
    }

    /* ------------------------------------------------------------------
     |  Sortie
     | ------------------------------------------------------------------ */

    /**
     * Sauvegarde le PhpWord modifié en DOCX dans chat/generated.
     *
     * @return string Chemin relatif (disk local)
     */
    private function saveDocx(PhpWord $phpWord, string $outputName): string
    {
        $filename = $this->safeFilename($outputName, 'docx');
        $path = self::OUTPUT_DIR.'/'.date('Y/m/d').'/'.$filename;

        $directory = \dirname(Storage::disk('local')->path($path));
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save(Storage::disk('local')->path($path));

        return $path;
    }

    /**
     * Convertit une pièce jointe DOCX en PDF (LibreOffice headless).
     *
     * @return array{result?: string, error?: string}
     */
    private function convertToPdf(string $sourcePath, string $outputName): array
    {
        $disk = Storage::disk('local');

        try {
            $converter = new PdfPreviewService;
            $pdfPath = $converter->convertToPdf($disk->path($sourcePath));
        } catch (\Throwable $e) {
            return ['error' => 'Conversion PDF impossible (LibreOffice requis) : '.$e->getMessage()
                .' — pour convertir manuellement : ouvrez le document dans Word puis Fichier → '
                .'Enregistrer sous → PDF.'];
        }

        // Copie le PDF généré dans chat/generated (avec un nom propre)
        $filename = $this->safeFilename($outputName, 'pdf');
        $outputPath = self::OUTPUT_DIR.'/'.date('Y/m/d').'/'.$filename;
        $disk->put($outputPath, file_get_contents($pdfPath));

        return ['result' => 'Document converti en PDF : '.$outputPath];
    }

    /**
     * Convertit une pièce jointe PDF en DOCX éditable (LibreOffice headless).
     *
     * @return array{result?: string, error?: string}
     */
    private function convertPdfToDocx(string $sourcePath, string $outputName): array
    {
        $disk = Storage::disk('local');

        $soffice = (new PdfPreviewService)->sofficePath();
        if ($soffice === null) {
            return ['error' => 'Conversion PDF→DOCX impossible : LibreOffice est introuvable sur ce '
                .'système. Pour convertir manuellement : ouvrez le PDF dans Word (Fichier → Ouvrir) '
                .'puis Enregistrer sous → Document Word (*.docx).'];
        }

        $source = $disk->path($sourcePath);
        $tempDir = sys_get_temp_dir().'/formadoc_pdf2docx_'.uniqid('', true);
        @mkdir($tempDir, 0775, true);

        $profile = sys_get_temp_dir().'/formadoc_lo_profile_'.uniqid('', true);
        $process = new Process([
            $soffice,
            '--headless',
            '--norestore',
            '-env:UserInstallation=file:///'.str_replace('\\', '/', $profile),
            '--convert-to',
            'docx',
            '--outdir',
            $tempDir,
            $source,
        ], null, null, null, 120);
        $process->run();

        if (! $process->isSuccessful()) {
            @array_map('unlink', glob($tempDir.'/*') ?: []);
            @rmdir($tempDir);

            return ['error' => 'Conversion PDF→DOCX échouée (LibreOffice) : '.$process->getErrorOutput()
                .' — pour convertir manuellement : ouvrez le PDF dans Word (Fichier → Ouvrir) puis '
                .'Enregistrer sous → Document Word (*.docx).'];
        }

        // LibreOffice écrit le DOCX avec le nom du PDF source dans --outdir
        $expected = $tempDir.'/'.pathinfo($source, PATHINFO_FILENAME).'.docx';
        if (! is_file($expected)) {
            $candidates = glob($tempDir.'/*.docx') ?: [];
            $expected = $candidates[0] ?? null;
        }

        if ($expected === null || ! is_file($expected)) {
            @array_map('unlink', glob($tempDir.'/*') ?: []);
            @rmdir($tempDir);

            return ['error' => 'Conversion PDF→DOCX : aucun fichier DOCX généré. Pour convertir '
                .'manuellement : ouvrez le PDF dans Word (Fichier → Ouvrir) puis Enregistrer sous '
                .'→ Document Word (*.docx).'];
        }

        $filename = $this->safeFilename($outputName, 'docx');
        $outputPath = self::OUTPUT_DIR.'/'.date('Y/m/d').'/'.$filename;
        $disk->put($outputPath, file_get_contents($expected));

        // Nettoyage
        @array_map('unlink', glob($tempDir.'/*') ?: []);
        @rmdir($tempDir);
        @array_map('unlink', glob($profile.'/*') ?: []);
        @rmdir($profile);

        return ['result' => 'PDF converti en DOCX éditable : '.$outputPath];
    }

    /**
     * Nettoie un nom de fichier. Évite la double extension
     * (fichier.docx.docx) si le nom porte déjà l'extension.
     */
    private function safeFilename(string $name, string $extension): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'fichier';
        $name = trim($name, '._-');
        $name = $name !== '' ? $name : 'fichier';
        $name = substr($name, 0, 80);
        $extension = preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'docx';

        if (! str_ends_with(strtolower($name), '.'.strtolower($extension))) {
            $name .= '.'.$extension;
        }

        return $name;
    }
}
