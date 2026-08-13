<?php
// Script de génération d'un rapport de test DOCX pour valider le pipeline.
// Usage : php storage/test_scripts/generate_test_report.php
require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

$phpWord = new PhpWord();
$section = $phpWord->addSection();

// Styles de base
$phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
$phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);
$phpWord->addTitleStyle(3, ['bold' => true, 'size' => 12]);

// Page de garde simulée
$section->addText('RAPPORT DE STAGE', ['bold' => true, 'size' => 20]);
$section->addText('Étude de la plateforme FORMADOC', ['size' => 14]);
$section->addTextBreak();

// Résumé
$section->addTitle('Résumé', 1);
$section->addText("Ce rapport présente l'étude de la plateforme FORMADOC de mise en forme automatique des rapports académiques.");

// Introduction
$section->addTitle('Introduction', 1);
$section->addText("L'objectif de ce stage est d'étudier les mécanismes de mise en forme.");

// Chapitre 1
$section->addTitle('1. Contexte général', 1);
$section->addText('Ce chapitre présente le contexte du projet.');

// Sous-section 1.1
$section->addTitle('1.1 Contexte institutionnel', 2);
$section->addText('L\'établissement exige des normes précises de mise en forme.');

// Sous-section 1.2
$section->addTitle('1.2 Problématique', 2);
$section->addText('Les étudiants passent beaucoup de temps à mettre en forme manuellement.');

// Chapitre 2
$section->addTitle('2. Analyse des besoins', 1);
$section->addText('Analyse des besoins fonctionnels et techniques.');

$section->addTitle('2.1 Besoins fonctionnels', 2);
$section->addText('Les besoins fonctionnels sont les suivants.');

$section->addTitle('2.1.1 Détection des titres', 3);
$section->addText('La détection automatique des titres est essentielle.');

$section->addTitle('2.1.2 Application du gabarit', 3);
$section->addText('Le gabarit définit police, taille, interligne.');

// Légende de figure
$section->addText('Figure 1: Architecture générale de la plateforme');
$section->addText('Le schéma ci-dessus illustre l\'architecture.');

// Légende de tableau
$section->addText('Tableau 1: Comparatif des solutions existantes');
$section->addText('Le tableau présente les solutions comparées.');

// Conclusion
$section->addTitle('Conclusion', 1);
$section->addText('Ce rapport a présenté les besoins et l\'architecture envisagée.');

// Annexe
$section->addTitle('Annexe', 1);
$section->addText('Annexe 1: Questionnaire distribué aux étudiants');

// Sauvegarde
$dir = __DIR__ . '/reports';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$path = $dir . '/rapport_test_structure.docx';
IOFactory::createWriter($phpWord, 'Word2007')->save($path);
echo "Rapport de test généré : $path\n";
