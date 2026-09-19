<?php

/**
 * Script de diagnostic : capture la réponse brute de DeepSeek sur le vrai
 * rapport pour identifier pourquoi `json_decode` échoue.
 *
 * Usage : php scripts/diag-analyzer.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\DocAnalyzer\DeepSeekAnalyzer;
use App\DocAnalyzer\DocumentParser;
use Illuminate\Contracts\Console\Kernel;

// Chemin configurable en argument, sinon le rapport de test sur le Bureau.
$docx = $argv[1] ?? 'C:\Users\rey\OneDrive\Desktop\RAPPORT\RAPPORT DE STAGE  la blanche.docx';
if (! file_exists($docx)) {
    fwrite(STDERR, "Fichier introuvable : {$docx}\n");
    exit(1);
}
echo "Source : {$docx}\n";

$parser = new DocumentParser($docx);
$parsed = $parser->parse();
$text = (string) ($parsed['context_text_with_positions'] ?? '');
echo 'Taille texte : '.mb_strlen($text)." caractères\n";
echo 'UTF-8 source valide : '.(mb_check_encoding($text, 'UTF-8') ? 'OUI' : 'NON')."\n";

$analyzer = new DeepSeekAnalyzer((string) config('deepseek.api_key'));

// Récupère callLlm() (privée) avec le modèle/timeout/max_tokens réels.
$ref = new ReflectionMethod($analyzer, 'callLlm');
$ref->setAccessible(true);

$start = microtime(true);
$content = (string) $ref->invoke($analyzer, $text);
$elapsed = round(microtime(true) - $start, 1);

echo "Durée : {$elapsed} s\n";
echo 'Longueur contenu : '.mb_strlen($content)." caractères\n";
echo 'UTF-8 réponse valide : '.(mb_check_encoding($content, 'UTF-8') ? 'OUI' : 'NON')."\n";
echo 'Commence par : '.bin2hex(substr($content, 0, 4))."\n";
echo 'Finit par : '.bin2hex(substr($content, -4))."\n";
echo 'Fin (200 derniers car.) : '.json_encode(mb_substr($content, -200))."\n";

$dump = storage_path('app/diag-raw-response.json');
file_put_contents($dump, $content);
echo "Réponse brute écrite dans : {$dump}\n";

echo "\n--- Diagnostic ---\n";
$decoded = json_decode($content, true);
echo 'json_decode direct : '.($decoded === null ? 'ÉCHEC' : 'OK')."\n";
echo 'json_last_error : '.json_last_error().' ('.json_last_error_msg().")\n";

// Reproduit extractJson()
$body = trim($content);
if (preg_match('/```(?:json)?\s*(.*?)```/is', $body, $m)) {
    echo "Fences markdown présentes\n";
    $body = trim($m[1]);
}

$start2 = strpos($body, '{');
if ($start2 === false) {
    echo "Aucune accolade ouvrante\n";
    exit(1);
}

$depth = 0;
$inString = false;
$escaped = false;
$len = strlen($body);
$found = null;
for ($i = $start2; $i < $len; $i++) {
    $c = $body[$i];
    if ($inString) {
        if ($escaped) {
            $escaped = false;
        } elseif ($c === '\\') {
            $escaped = true;
        } elseif ($c === '"') {
            $inString = false;
        }

        continue;
    }
    if ($c === '"') {
        $inString = true;
    } elseif ($c === '{') {
        $depth++;
    } elseif ($c === '}') {
        $depth--;
        if ($depth === 0) {
            $found = substr($body, $start2, $i - $start2 + 1);
            break;
        }
    }
}

if ($found === null) {
    echo 'Bloc JSON NON équilibré (inString='.($inString ? 'true' : 'false').", depth={$depth})\n";
    exit(1);
}

echo 'Bloc extrait : '.strlen($found)." octets\n";
$decodedBlock = json_decode($found, true);
echo 'json_decode bloc : '.($decodedBlock === null ? 'ÉCHEC' : 'OK')."\n";
echo 'json_last_error : '.json_last_error().' ('.json_last_error_msg().")\n";

if ($decodedBlock === null) {
    // Cherche le plus grand préfixe décodable en fermant les structures
    echo "Localisation :\n";
    $bestCut = 0;
    for ($cut = 1000; $cut < strlen($found); $cut += 1000) {
        $partial = substr($found, 0, $cut);
        if (json_decode($partial, true) !== null) {
            $bestCut = $cut;
        }
    }
    echo "  dernier préfixe décodable ≈ {$bestCut} octets\n";
    echo '  contexte : '.json_encode(substr($found, max(0, $bestCut - 150), 400))."\n";
}
