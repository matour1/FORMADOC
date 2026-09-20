<?php

/**
 * Script CLI autonome de conversion DOCX → PDF via LibreOffice.
 *
 * Utilisation :
 *   php convert_pdf_cli.php <docxPath> <outDir> <pdfPath> [profilePrefix]
 *
 * Ce script est conçu pour être lancé DÉTACHÉ depuis le serveur HTTP
 * (via WScript.Shell->Run), afin que la conversion s'exécute dans un
 * processus PHP CLI pur — où la conversion LibreOffice fonctionne,
 * contrairement au contexte du serveur HTTP mono-process
 * (php artisan serve) qui échoue ou bloque sur soffice.
 *
 * Écrit <pdfPath> si succès, sinon <pdfPath>.err avec le message d'erreur.
 * N'utilise PAS Laravel : exécutable tel quel avec `php convert_pdf_cli.php`.
 */
$docxPath = $argv[1] ?? null;
$outDir = $argv[2] ?? null;
$pdfPath = $argv[3] ?? null;
$prefix = $argv[4] ?? 'formadoc_lo_cli';

$errFile = $pdfPath.'.err';
@unlink($pdfPath);
@unlink($errFile);

$fail = function (string $message) use ($errFile, $pdfPath) {
    file_put_contents($errFile, $message.PHP_EOL);
    if (is_file($pdfPath)) {
        @unlink($pdfPath);
    }
    exit(1);
};

if (! $docxPath || ! $outDir || ! $pdfPath) {
    $fail('Arguments manquants : docxPath, outDir, pdfPath');
}
if (! is_file($docxPath)) {
    $fail('Source introuvable : '.$docxPath);
}
if (! is_dir($outDir)) {
    @mkdir($outDir, 0777, true);
}

// Localisation du binaire LibreOffice
// IMPORTANT (Windows) : `soffice.com` (console) est à privilégier sur
// `soffice.exe` (wrapper GUI). Lancé depuis un serveur web (sans session
// interactive), soffice.exe se détache du process appelant et échoue
// silencieusement (exit 1, sortie vide). soffice.com reste attaché et
// fonctionne de manière fiable en contexte headless/web.
$candidates = [
    'C:\\Program Files\\LibreOffice\\program\\soffice.com',
    'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.com',
    'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
    '/usr/bin/libreoffice',
    '/usr/bin/soffice',
    '/opt/libreoffice/program/soffice',
    '/Applications/LibreOffice.app/Contents/MacOS/soffice',
];
$soffice = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $soffice = $candidate;
        break;
    }
}
if (! $soffice) {
    $fail('LibreOffice introuvable');
}

// Profil utilisateur UNIQUE par conversion (évite les verrous résiduels)
$profile = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.'_'.uniqid('', true);
$cmd = '"'.$soffice.'" --headless --norestore --nologo --nofirststartwizard '
    .'-env:UserInstallation=file:///'.str_replace('\\', '/', $profile)
    .' --convert-to pdf:writer_pdf_Export --outdir "'.$outDir.'" "'.$docxPath.'"';

// Sorties redirigées vers des fichiers (jamais de handles hérités)
$stdoutFile = $pdfPath.'.out';
$stderrFile = $pdfPath.'.err.raw';
@unlink($stdoutFile);
@unlink($stderrFile);

$descriptors = [
    0 => ['file', 'NUL', 'r'],
    1 => ['file', $stdoutFile, 'w'],
    2 => ['file', $stderrFile, 'w'],
];

$proc = proc_open($cmd, $descriptors, $pipes, $outDir);
if (! is_resource($proc)) {
    $fail('Impossible de lancer proc_open');
}

$exit = proc_close($proc);

$stdout = is_file($stdoutFile) ? trim((string) file_get_contents($stdoutFile)) : '';
$stderr = is_file($stderrFile) ? trim((string) file_get_contents($stderrFile)) : '';

// Nettoyage des fichiers temporaires de sortie
@unlink($stdoutFile);
@unlink($stderrFile);

// LibreOffice écrit le PDF avec le NOM du DOCX source dans --outdir
// (ex. C:\Temp\gen_35_20260818_113014.pdf), pas avec le nom pdfPath.
$expectedPdf = $outDir.DIRECTORY_SEPARATOR.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';

if ($exit !== 0) {
    $fail(sprintf(
        'Échec conversion (exit %d)%s%s',
        $exit,
        $stdout !== '' ? "\nstdout: ".$stdout : '',
        $stderr !== '' ? "\nstderr: ".$stderr : ''
    ));
}

// Si le PDF attendu diffère du pdfPath demandé, on le déplace
if (is_file($expectedPdf) && realpath($expectedPdf) !== realpath($pdfPath)) {
    @copy($expectedPdf, $pdfPath);
    @unlink($expectedPdf);
}

if (! is_file($pdfPath)) {
    $fail(sprintf(
        'PDF non généré (exit 0)%s%s',
        $stdout !== '' ? "\nstdout: ".$stdout : '',
        $stderr !== '' ? "\nstderr: ".$stderr : ''
    ));
}

// Nettoyage du profil LibreOffice créé
if (is_dir($profile)) {
    $removeTree = function (string $dir) use (&$removeTree) {
        foreach (glob($dir.'/*') ?: [] as $item) {
            is_dir($item) ? $removeTree($item) : @unlink($item);
        }
        @rmdir($dir);
    };
    $removeTree($profile);
}

exit(0);
