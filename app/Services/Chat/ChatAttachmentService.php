<?php

namespace App\Services\Chat;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;

/**
 * Extraction de contenu textuel depuis une pièce jointe de chat.
 *
 * P1-4 : le backend ignorait silencieusement les pièces jointes envoyées
 * par l'UI. Ce service normalise l'upload (validation + stockage privé) et
 * extrait un résumé textuel exploitable par l'IA :
 *  - txt / md : contenu brut
 *  - docx     : texte extrait via PhpWord (lecture du fichier uploadé)
 *  - pdf / xlsx / pptx : pas d'extraction (lib absente) → simple mention
 *    de la pièce jointe dans le contexte, sans dump de contenu.
 *
 * Le fichier est stocké sur le disque 'local' (privé) sous
 * `chat/attachments/{sessionId}/{uuid}.{ext}` pour permettre un
 * téléchargement sécurisé ultérieur (ownership vérifié côté contrôleur).
 */
class ChatAttachmentService
{
    /** Extensions acceptées à l'upload. */
    public const ALLOWED_EXTENSIONS = ['docx', 'pdf', 'txt', 'md', 'xlsx', 'pptx'];

    public const MAX_SIZE_KB = 5120; // 5 Mo

    public const MAX_FILES = 5;

    /** Extensions dont le contenu est extrait pour le contexte IA. */
    public const EXTRACTABLE_EXTENSIONS = ['txt', 'md', 'docx'];

    /**
     * Valide et stocke une liste de fichiers uploadés.
     *
     * @param  UploadedFile[]  $files
     * @return array{ok: bool, files: array<int, array{name: string, path: string, size: int, ext: string, content: ?string}>, errors: string[]}
     */
    public function handle(array $files, int $sessionId): array
    {
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile && $f->isValid()));

        if ($files === []) {
            return ['ok' => true, 'files' => [], 'errors' => []];
        }

        if (count($files) > self::MAX_FILES) {
            return [
                'ok' => false,
                'files' => [],
                'errors' => ['Maximum '.self::MAX_FILES.' pièces jointes par message.'],
            ];
        }

        $stored = [];
        $errors = [];

        foreach ($files as $file) {
            $original = $file->getClientOriginalName();
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

            // Whitelist stricte (P0/1 : rejette .php, .phar, doubles extensions…)
            if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                $errors[] = "« {$original} » : extension .{$ext} non autorisée (docx, pdf, txt, md, xlsx, pptx uniquement).";

                continue;
            }

            if ($file->getSize() > self::MAX_SIZE_KB * 1024) {
                $errors[] = "« {$original} » : dépasse la limite de ".self::MAX_SIZE_KB.' Ko.';

                continue;
            }

            // Nom de stockage unique et sûr (jamais le nom client)
            $filename = Str::uuid().'.'.$ext;
            $path = 'chat/attachments/'.$sessionId.'/'.$filename;

            try {
                $storedPath = Storage::disk('local')->putFileAs(
                    'chat/attachments/'.$sessionId,
                    $file,
                    $filename,
                );

                if (! $storedPath) {
                    $errors[] = "« {$original} » : échec du stockage.";

                    continue;
                }

                $stored[] = [
                    'name' => $original,
                    'path' => $path,
                    'size' => $file->getSize(),
                    'ext' => $ext,
                    'content' => $this->extract($file, $ext),
                ];
            } catch (\Throwable $e) {
                Log::warning('ChatAttachmentService : échec stockage pièce jointe', [
                    'error' => $e->getMessage(),
                ]);
                $errors[] = "« {$original} » : échec du traitement.";
            }
        }

        if ($stored === [] && $errors !== []) {
            return ['ok' => false, 'files' => [], 'errors' => $errors];
        }

        return ['ok' => true, 'files' => $stored, 'errors' => $errors];
    }

    /**
     * Extrait un résumé textuel exploitable par l'IA.
     */
    private function extract(UploadedFile $file, string $ext): ?string
    {
        try {
            if ($ext === 'txt' || $ext === 'md') {
                $content = (string) $file->get();

                return Str::limit($content, 8000);
            }

            if ($ext === 'docx') {
                $phpWord = IOFactory::load($file->getRealPath());
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    $text .= $this->extractContainerText($section);
                }
                $text = trim($text);

                return $text === '' ? null : Str::limit($text, 8000);
            }
        } catch (\Throwable $e) {
            Log::warning('ChatAttachmentService : échec extraction texte', [
                'ext' => $ext,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // pdf / xlsx / pptx : pas d'extraction (aucune lib de parsing) → on
        // signale la pièce jointe sans dump de contenu.
        return null;
    }

    /**
     * Extrait récursivement le texte d'un conteneur PhpWord (section,
     * TextRun, cellule…) en gérant TOUS les types d'éléments.
     *
     * Les DOCX réels (rapports volumineux) contiennent des éléments dont
     * getText() retourne un tableau (footnotes, PreserveText, structures
     * imbriquées) : un cast naïf `(string)` provoquait une erreur
     * « Array to string conversion » et le LLM voyait « contenu non
     * extractible » → il ne pouvait pas utiliser document_analyze.
     *
     * @param  bool  $inline  Contexte TextRun : les Text sont des fragments
     *                        du même paragraphe → concaténés sans saut de ligne.
     */
    private function extractContainerText(object $container, bool $inline = false): string
    {
        if (! method_exists($container, 'getElements')) {
            return '';
        }

        $parts = [];
        foreach ($container->getElements() as $child) {
            $separator = $inline ? '' : "\n";
            if ($child instanceof Text) {
                $parts[] = (string) ($child->getText() ?? '').$separator;
            } elseif ($child instanceof Title) {
                $t = $child->getText();
                if (is_string($t)) {
                    $parts[] = $t."\n";
                } elseif (is_array($t)) {
                    $parts[] = implode('', array_map('strval', $t))."\n";
                }
            } elseif ($child instanceof ListItem) {
                $t = $child->getText();
                $parts[] = (is_string($t) ? $t : '')."\n";
            } elseif ($child instanceof PreserveText) {
                $t = $child->getText();
                $parts[] = (is_array($t) ? implode('', array_map('strval', $t)) : (string) ($t ?? ''))."\n";
            } elseif ($child instanceof TextRun) {
                // TextRun / ListItemRun : conteneur imbriqué (récursif) —
                // les fragments sont inline (un seul paragraphe).
                $parts[] = $this->extractContainerText($child, true).$separator;
            } elseif ($child instanceof Table) {
                $parts[] = $this->extractTableText($child);
            } elseif ($child instanceof Image) {
                $name = $child->getName() ?: basename((string) $child->getSource());
                $parts[] = "[image:{$name}]\n";
            } elseif (method_exists($child, 'getElements')) {
                // Tout autre conteneur (Header, Footer, Cell, Footnote…)
                $parts[] = $this->extractContainerText($child).$separator;
            } elseif (method_exists($child, 'getText')) {
                $t = $child->getText();
                $parts[] = (is_array($t) ? implode(' ', array_map('strval', $t)) : (string) ($t ?? '')).$separator;
            }
        }

        return implode('', $parts);
    }

    /**
     * Extrait le texte d'un tableau PhpWord (lignes → cellules).
     */
    private function extractTableText(Table $table): string
    {
        $parts = [];
        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $cells[] = trim($this->extractContainerText($cell));
            }
            $parts[] = implode(' | ', array_filter($cells))."\n";
        }

        return implode('', $parts);
    }

    /**
     * Bloc de contexte IA généré à partir des pièces jointes stockées.
     */
    public function contextBlock(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }

        $parts = [];
        foreach ($attachments as $i => $att) {
            // Le chemin relatif (chat/attachments/...) est exposé pour que
            // l'IA puisse référencer la pièce jointe avec les outils
            // document_edit / document_to_pdf.
            $label = '[Pièce jointe '.($i + 1).' : '.$att['name']
                .' (chemin : '.($att['path'] ?? '').')]';
            if (! empty($att['content'])) {
                $parts[] = $label."\n".$att['content'];
            } else {
                $parts[] = $label.' (contenu non extractible — disponible pour l\'utilisateur)';
            }
        }

        $block = "Pièces jointes fournies par l'utilisateur :\n".implode("\n\n", $parts);

        return Str::limit($block, 8000);
    }
}
