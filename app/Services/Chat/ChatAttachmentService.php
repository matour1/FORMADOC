<?php

namespace App\Services\Chat;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * @param UploadedFile[] $files
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
            $filename = Str::uuid() . '.' . $ext;
            $path = 'chat/attachments/' . $sessionId . '/' . $filename;

            try {
                $storedPath = Storage::disk('local')->putFileAs(
                    'chat/attachments/' . $sessionId,
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
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $text .= (string) $element->getText() . "\n";
                        } elseif (method_exists($element, 'getElements')) {
                            foreach ($element->getElements() as $child) {
                                if (method_exists($child, 'getText')) {
                                    $text .= (string) $child->getText() . "\n";
                                }
                            }
                        }
                    }
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
     * Bloc de contexte IA généré à partir des pièces jointes stockées.
     */
    public function contextBlock(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }

        $parts = [];
        foreach ($attachments as $i => $att) {
            $label = '[Pièce jointe '.($i + 1).' : '.$att['name'].']';
            if (! empty($att['content'])) {
                $parts[] = $label."\n".$att['content'];
            } else {
                $parts[] = $label.' (contenu non extractible — disponible pour l\'utilisateur)';
            }
        }

        $block = "Pièces jointes fournies par l'utilisateur :\n" . implode("\n\n", $parts);

        return Str::limit($block, 8000);
    }
}
