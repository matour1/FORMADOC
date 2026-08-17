<?php

namespace App\Services\DocumentGeneration;

use App\Models\CoverPageTemplate;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Rendu d'une page de garde en "ghost-table" (tableau invisible à 100%).
 * Aucune dépendance externe : la structure JSON de l'éditeur est rendue
 * directement par PhpWord, puis un post-traitement XML applique le gridSpan.
 */
class CoverPageRenderer
{
    /**
     * Rend la page de garde dans la première section d'un PhpWord.
     *
     * @param array<string,mixed> $values  Valeurs injectées dans les placeholders {{key}}
     * @param array<int,string>   $imageMap [placeholder => chemin local]
     */
    public function render(
        PhpWord $phpWord,
        CoverPageTemplate $template,
        array $values = [],
        array $imageMap = []
    ): void {
        $this->applyPageStyle($phpWord, $template->page_style ?? []);

        $section = $phpWord->addSection();
        $section->setPageSizeH(intval($template->page_style['marginTopMm'] ?? 25) * 56.7);

        $table = $section->addTable([
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'cellMargin' => 60,
            'width' => 100 * 50, // 100% en twips approx
            'unit' => 'pct',
        ]);

        foreach (($template->elements ?? []) as $row) {
            $this->renderRow($table, $row, $values, $imageMap);
        }

        // On force un saut de page après la garde pour ne pas la "mélanger" au corps
        $section->addPageBreak();
    }

    private function applyPageStyle(PhpWord $phpWord, array $style): void
    {
        $size = $style['size'] ?? 'A4';
        $orientation = $style['orientation'] ?? 'portrait';

        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        // Le style s'applique à toute nouvelle section créée ; la garde utilise
        // la première section (par défaut).
        $section = $phpWord->getSections()[0] ?? null;
        if (!$section) {
            return;
        }

        $section->setPageSize($size, $orientation);
        $section->setMarginTop(intval($style['marginTopMm'] ?? 25) * 56.7);
        $section->setMarginBottom(intval($style['marginBottomMm'] ?? 25) * 56.7);
        $section->setMarginLeft(intval($style['marginLeftMm'] ?? 25) * 56.7);
        $section->setMarginRight(intval($style['marginRightMm'] ?? 25) * 56.7);
    }

    private function renderRow($table, array $row, array $values, array $imageMap): void
    {
        $cells = $row['cells'] ?? [];
        if (!$cells) {
            return;
        }

        // Préparer le gridSpan pour le tableau PhpWord (colMerge)
        $rowStyle = ['cantSplit' => true];
        $table->addRow(null, $rowStyle);

        foreach ($cells as $cell) {
            $span = max(1, intval($cell['gridSpan'] ?? 1));
            $cellStyle = [
                'borderSize' => 0,
                'borderColor' => 'FFFFFF',
                'vAlign' => 'center',
            ];
            // PhpWord 1.4+ écrit w:gridSpan nativement dans le Word2007 writer.
            if ($span > 1) {
                $cellStyle['gridSpan'] = $span;
            }
            $cellEl = $table->addCell(null, $cellStyle);
            $this->renderBlocks($cellEl, $cell['blocks'] ?? [], $values, $imageMap);
        }
    }

    private function renderBlocks($cell, array $blocks, array $values, array $imageMap): void
    {
        foreach ($blocks as $block) {
            $this->renderBlock($cell, $block, $values, $imageMap);
        }
    }

    private function renderBlock($cell, array $block, array $values, array $imageMap): void
    {
        $kind = $block['kind'] ?? 'text';
        $text = $this->resolve($block['text'] ?? '', $values);
        $font = $this->flatFont($block['font'] ?? []);

        switch ($kind) {
            case 'text':
                $alignment = $this->alignmentFor($font['align'] ?? 'center');
                $cell->addText($text, [
                    'bold' => $font['bold'] ?? false,
                    'italic' => $font['italic'] ?? false,
                    'underline' => $font['underline'] ?? false,
                    'size' => $font['size'] ?? 11,
                    'color' => $font['color'] ?? '000000',
                    'name' => $font['name'] ?? 'Calibri',
                ], ['alignment' => $alignment]);
                break;

            case 'spacer':
                $cell->addText('', [], []);
                break;

            case 'divider':
                $cell->addText('', [], [
                    'alignment' => Jc::CENTER,
                    'pBdr' => ['bottom' => ['val' => 'single', 'sz' => 8, 'color' => '888888']],
                ]);
                break;

            case 'image':
            case 'logo':
                $src = $block['src'] ?? null;
                if (!$src && !empty($block['placeholder']) && isset($imageMap[$block['placeholder']])) {
                    $src = $imageMap[$block['placeholder']];
                }
                if ($src && is_file($src)) {
                    $cell->addImage($src, [
                        'height' => intval($block['heightPx'] ?? 120),
                        'alignment' => $this->alignmentFor($font['align'] ?? 'center'),
                    ]);
                } else {
                    $cell->addText('[Image manquante]', ['italic' => true, 'color' => '999999']);
                }
                break;
        }
    }

    private function resolve(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function ($m) use ($values) {
            $key = $m[1];
            return $values[$key] ?? ($values[\Illuminate\Support\Str::snake($key)] ?? '');
        }, $template) ?? $template;
    }

    private function flatFont(array $font): array
    {
        $allowed = [
            'bold' => 'bool',
            'italic' => 'bool',
            'underline' => 'bool',
            'align' => 'string',
            'color' => 'string',
            'size' => 'int',
            'name' => 'string',
        ];
        $out = [];
        foreach ($allowed as $k => $type) {
            if (!array_key_exists($k, $font)) {
                continue;
            }
            $v = $font[$k];
            if ($type === 'bool') {
                $out[$k] = (bool) $v;
            } elseif ($type === 'int') {
                $out[$k] = max(6, min(96, (int) $v));
            } else {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }

    private function alignmentFor(string $align): string
    {
        return match (strtolower($align)) {
            'left' => Jc::LEFT,
            'right' => Jc::RIGHT,
            'justify' => Jc::JUSTIFIED,
            default => Jc::CENTER,
        };
    }
}
