<?php

namespace App\Services\Detection;

use Exception;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;

/**
 * Extraction du texte brut d'un document (DOCX, DOC, TXT).
 *
 * Le texte extrait alimente ensuite la détection (titres via LLM,
 * légendes via regex). L'extraction reste volontairement bas niveau :
 * elle ne fait AUCUNE interprétation de structure.
 *
 * NOTE : le format DOC n'est supporté par PhpWord que pour la lecture
 * de texte simple ; le format PDF n'est pas géré ici (sera ajouté si
 * nécessaire — voir plan de développement).
 */
class TextExtractionService
{
    /**
     * Extraits le texte brut d'un fichier.
     *
     * @param string $absolutePath Chemin absolu du fichier (doit exister)
     * @return string Texte brut normalisé (\n pour les fins de ligne)
     * @throws Exception Si le fichier est introuvable, illisible ou d'un type non supporté
     */
    public function execute(string $absolutePath): string
    {
        try {
            Log::info('TextExtractionService started', ['path' => $absolutePath]);

            if (!file_exists($absolutePath)) {
                throw new Exception('TextExtractionService : fichier introuvable : ' . $absolutePath);
            }

            if (!is_readable($absolutePath)) {
                throw new Exception('TextExtractionService : fichier illisible : ' . $absolutePath);
            }

            $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

            $text = match ($extension) {
                'docx', 'doc' => $this->extractFromWord($absolutePath),
                'txt' => $this->extractFromPlainText($absolutePath),
                default => throw new Exception(
                    'TextExtractionService : extension non supportée "' . $extension . '"'
                ),
            };

            if (mb_strlen(trim($text)) === 0) {
                throw new Exception('TextExtractionService : aucun texte extractible du document');
            }

            Log::info('TextExtractionService completed', [
                'extension' => $extension,
                'characters' => mb_strlen($text),
            ]);

            return $text;
        } catch (Exception $e) {
            Log::error('TextExtractionService failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * Extrait le texte d'un fichier Word (.docx / .doc) via PhpWord.
     */
    private function extractFromWord(string $absolutePath): string
    {
        try {
            $phpWord = IOFactory::load($absolutePath);
            $text = '';

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= $this->elementToText($element) . "\n";
                }
            }

            // Normalisation des fins de ligne
            return $this->normalize($text);
        } catch (Exception $e) {
            throw new Exception(
                'TextExtractionService : échec de lecture du document Word (' . $e->getMessage() . ')'
            );
        }
    }

    /**
     * Convertit un élément PhpWord en texte, en traitant les éléments imbriqués.
     */
    private function elementToText($element): string
    {
        // Élément de texte simple
        if (method_exists($element, 'getText')) {
            $text = $element->getText();
            // getText() peut retourner un tableau (chunks avec styles) ou une string
            if (is_array($text)) {
                return implode('', array_map(fn ($chunk) => $chunk ?? '', $text));
            }
            return (string) ($text ?? '');
        }

        // Texte dans les tableaux (cellules)
        if (method_exists($element, 'getRows')) {
            $out = '';
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $cellElement) {
                        $out .= $this->elementToText($cellElement) . "\t";
                    }
                }
                $out .= "\n";
            }
            return $out;
        }

        // Bloc de texte enrichi (listes, etc.) — parcours récursif
        if (method_exists($element, 'getElements')) {
            $out = '';
            foreach ($element->getElements() as $child) {
                $out .= $this->elementToText($child) . "\n";
            }
            return $out;
        }

        return '';
    }

    /**
     * Lit un fichier texte brut avec détection d'encodage basique.
     */
    private function extractFromPlainText(string $absolutePath): string
    {
        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw new Exception('TextExtractionService : lecture du fichier texte impossible');
        }

        // Détection UTF-8 : sinon tenter l'ISO-8859-1 (courant pour les fichiers Windows)
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }

        return $this->normalize($content);
    }

    /**
     * Normalise les fins de ligne et supprime les caractères de contrôle parasites.
     */
    private function normalize(string $text): string
    {
        // Uniformise \r\n et \r vers \n
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Supprime les caractères de contrôle sauf \n et \t
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? $text;

        return $text;
    }
}
