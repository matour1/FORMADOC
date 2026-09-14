<?php

declare(strict_types=1);

// Script jetable : crée une couverture DOCX de test pour valider
// l'upload « à partir d'un exemple » + la détection des zones.

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

$phpWord = new PhpWord;
$section = $phpWord->addSection();

$section->addText('REPUBLIQUE DU CAMEROUN', ['size' => 14, 'bold' => true]);
$section->addText('UNIVERSITE DE DOUALA', ['size' => 12]);
$section->addText("Thème : CONCEPTION D'UNE APPLICATION WEB", ['size' => 16, 'bold' => true]);
$section->addText('Présenté par : JEAN DUPONT', ['size' => 11]);
$section->addText('Encadré par : Dr. MARTIN', ['size' => 11]);
$section->addText('Année académique 2024-2025', ['size' => 11]);

$out = 'C:/Temp/couverture_test.docx';
IOFactory::createWriter($phpWord, 'Word2007')->save($out);
echo 'OK: '.$out.PHP_EOL;
