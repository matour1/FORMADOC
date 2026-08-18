<?php

declare(strict_types=1);

namespace App\Services\DocumentGeneration;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Conversion DOCX → PDF via LibreOffice (headless).
 *
 * Génère un aperçu fidèle du DOCX reconstruit : l'utilisateur voit le rendu
 * réel avant de télécharger. LibreOffice est détecté automatiquement sur les
 * chemins d'installation courants (Windows, Linux, macOS).
 *
 * Sous Windows + `php artisan serve` (serveur PHP intégré mono-process),
 * lancer soffice directement depuis la requête HTTP échoue ou bloque
 * (héritage des handles / console). La stratégie fiable est de lancer un
 * script PHP CLI AUTONOME, DÉTACHÉ du serveur, qui fait la conversion
 * dans un environnement propre — là où elle fonctionne de manière prouvée.
 */
class PdfPreviewService
{
    /**
     * Délai maximum (secondes) d'attente du PDF généré.
     */
    private const POLL_TIMEOUT = 120;

    /**
     * Intervalle de scrutation (secondes).
     */
    private const POLL_INTERVAL = 1;

    /**
     * Détecte le chemin de l'exécutable LibreOffice (soffice).
     *
     * Ordre de détection :
     *   1. config('documents.libreoffice_path') (config/documents.php)
     *   2. `soffice` dans le PATH
     *   3. Chemins d'installation courants
     */
    public function sofficePath(): ?string
    {
        $configured = config('documents.libreoffice_path');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        // binaire `soffice` dans le PATH
        $which = DIRECTORY_SEPARATOR === '\\' ? 'where.exe' : 'which';
        $output = [];
        $code = 1;
        exec($which . ' soffice 2>NUL', $output, $code);
        if ($code === 0 && isset($output[0]) && $output[0] !== '') {
            return trim($output[0]);
        }

        // Chemins d'installation courants.
        // IMPORTANT (Windows) : `soffice.com` (console) est à privilégier sur
        // `soffice.exe` (wrapper GUI). Lancé depuis un serveur web (sans session
        // interactive), soffice.exe se détache du process appelant et échoue
        // silencieusement (exit 1, sortie vide). soffice.com reste attaché et
        // fonctionne de manière fiable en contexte headless/web.
        $candidates = [
            'C:\\Program Files\\LibreOffice\\program\\soffice.com',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.com',
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/libreoffice',
            '/usr/bin/soffice',
            '/opt/libreoffice/program/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Chemin du script CLI autonome de conversion.
     */
    public function converterScriptPath(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'convert_pdf_cli.php';
    }

    /**
     * Chemin du binaire PHP CLI à utiliser pour le lancement détaché.
     *
     * Sous Windows, le serveur `php artisan serve` tourne déjà avec ce binaire ;
     * on le réutilise pour garantir la compatibilité des extensions.
     */
    public function phpBinaryPath(): string
    {
        $binary = PHP_BINARY;

        if (DIRECTORY_SEPARATOR === '\\' && stripos(basename($binary), 'php') !== false) {
            // Sur Windows, PHP_BINARY peut pointer vers php-cgi.exe ou un autre
            // binaire ; on préfère php.exe (CLI) qui est côte à côte.
            $cli = dirname($binary) . DIRECTORY_SEPARATOR . 'php.exe';
            if (is_file($cli)) {
                return $cli;
            }
        }

        return $binary;
    }

    /**
     * Convertit un DOCX en PDF (dans le même dossier, extension .pdf).
     *
     * @param string $docxPath Chemin absolu du DOCX source
     *
     * @return string Chemin absolu du PDF généré
     *
     * @throws RuntimeException Si LibreOffice est introuvable ou la conversion échoue
     */
    public function convertToPdf(string $docxPath): string
    {
        $soffice = $this->sofficePath();
        if ($soffice === null) {
            throw new RuntimeException(
                'Aperçu PDF indisponible : LibreOffice est introuvable sur ce système.'
            );
        }

        if (!is_file($docxPath)) {
            throw new RuntimeException("DOCX introuvable : {$docxPath}");
        }

        $outputDir = dirname($docxPath);
        $pdfPath = preg_replace('/\.docx$/i', '.pdf', $docxPath) ?? $docxPath . '.pdf';

        // En CLI pur (tests, artisan), on peut lancer la conversion en direct :
        // c'est fiable et rapide. En contexte HTTP (php artisan serve), on
        // lance le script CLI autonome DÉTACHÉ via WScript.Shell, puis on
        // scrute l'apparition du PDF.
        if (PHP_SAPI === 'cli') {
            return $this->convertDirectly($soffice, $docxPath, $outputDir, $pdfPath);
        }

        return $this->convertDetached($docxPath, $outputDir, $pdfPath);
    }

    /**
     * Conversion en CLI pur : Symfony Process direct.
     */
    private function convertDirectly(string $soffice, string $docxPath, string $outputDir, string $pdfPath): string
    {
        // Profil utilisateur UNIQUE par conversion : sous `php artisan serve`
        // (ou tout serveur mono-process), getmypid() est constant → le profil
        // serait réutilisé entre requêtes et se corromprait. uniqid garantit
        // un profil vierge à chaque conversion.
        $userProfile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formadoc_lo_profile_' . uniqid('', true);

        // Démarre toujours avec un profil vierge (élimine les verrous résiduels)
        $this->removeTree($userProfile);

        // Windows : l'anti-virus peut verrouiller brièvement le DOCX fraîchement
        // écrit par le process web ("source file could not be loaded").
        // → 3 tentatives avec un court délai d'attente entre chacune.
        $lastError = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // Supprime un PDF existant pour détecter proprement l'échec
            if (is_file($pdfPath)) {
                @unlink($pdfPath);
            }

            $process = new Process([
                $soffice,
                '--headless',
                '--norestore',
                '-env:UserInstallation=file:///' . str_replace('\\', '/', $userProfile),
                '--convert-to',
                'pdf:writer_pdf_Export',
                '--outdir',
                $outputDir,
                $docxPath,
            ], null, null, null, 120);
            $process->run();

            if ($process->isSuccessful() && is_file($pdfPath)) {
                // Nettoyage du profil temporaire (arborescence complète)
                $this->removeTree($userProfile);

                return $pdfPath;
            }

            $lastError = $process->getErrorOutput() . $process->getOutput()
                . ' (exit ' . var_export($process->getExitCode(), true) . ')';

            if ($attempt < 3) {
                usleep(500_000); // 500 ms avant nouvelle tentative
            }
        }

        // Nettoyage du profil temporaire
        $this->removeTree($userProfile);

        throw new RuntimeException('Échec de la conversion PDF (LibreOffice) : ' . $lastError);
    }

    /**
     * Conversion en contexte HTTP : lance le script CLI autonome DÉTACHÉ
     * (WScript.Shell->Run), puis scrute la création du PDF.
     */
    private function convertDetached(string $docxPath, string $outputDir, string $pdfPath): string
    {
        // Supprime un PDF / .err existants pour détecter proprement le succès
        if (is_file($pdfPath)) {
            @unlink($pdfPath);
        }
        $errFile = $pdfPath . '.err';
        if (is_file($errFile)) {
            @unlink($errFile);
        }

        $php = $this->phpBinaryPath();
        $script = $this->converterScriptPath();

        if (!is_file($script)) {
            throw new RuntimeException('Script de conversion introuvable : ' . $script);
        }

        // Commande : php convert_pdf_cli.php <docx> <outDir> <pdfPath>
        $cmd = '"' . $php . '" "' . $script . '" "' . $docxPath . '" "' . $outputDir . '" "' . $pdfPath . '"';

        // Lancement DÉTACHÉ : WScript.Shell->Run (fenêtre cachée, non bloquant)
        $launched = false;
        $launchError = null;
        if (class_exists('COM')) {
            try {
                $shell = new \COM('WScript.Shell');
                $shell->Run($cmd, 0, false); // 0 = fenêtre cachée, false = ne pas attendre
                $launched = true;
            } catch (\Throwable $e) {
                $launchError = $e->getMessage();
            }
        }

        if (!$launched) {
            throw new RuntimeException(
                'Impossible de lancer la conversion PDF (COM/WScript.Shell indisponible) : '
                . ($launchError ?? 'COM absent')
            );
        }

        // Scrutation du PDF (timeout self::POLL_TIMEOUT)
        $start = microtime(true);
        while (microtime(true) - $start < self::POLL_TIMEOUT) {
            if (is_file($pdfPath)) {
                return $pdfPath;
            }
            if (is_file($errFile)) {
                $error = trim((string) file_get_contents($errFile));
                @unlink($errFile);
                throw new RuntimeException('Échec de la conversion PDF (LibreOffice) : ' . $error);
            }
            usleep((int) (self::POLL_INTERVAL * 1_000_000));
        }

        throw new RuntimeException(
            'Délai dépassé : la conversion PDF n\'a pas abouti en ' . self::POLL_TIMEOUT . ' secondes.'
        );
    }

    /**
     * Supprime récursivement un dossier (profil utilisateur temporaire).
     */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * Convertit un DOCX en PDF et renvoie le contenu binaire.
     */
    public function pdfContent(string $docxPath): string
    {
        $pdfPath = $this->convertToPdf($docxPath);

        $content = file_get_contents($pdfPath);
        if ($content === false) {
            throw new RuntimeException('Impossible de lire le PDF généré.');
        }

        return $content;
    }
}
