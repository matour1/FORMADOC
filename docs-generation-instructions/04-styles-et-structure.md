# 04 – Styles, titres et structure du document

## Styles de titres (obligatoires)

Avant toute génération de contenu, définir les styles :

```php
$phpWord->addTitleStyle(1, [
    'size' => 16, 'bold' => true, 'color' => '1F4E79'
], [
    'spaceBefore' => 240, 'spaceAfter' => 120
]);

$phpWord->addTitleStyle(2, [
    'size' => 14, 'bold' => true, 'color' => '2E75B6'
], [
    'spaceBefore' => 200, 'spaceAfter' => 100
]);

$phpWord->addTitleStyle(3, [
    'size' => 12, 'bold' => true
], [
    'spaceBefore' => 160, 'spaceAfter' => 80
]);
```

Sans ces styles, le `addTOC()` ne fonctionne pas correctement.

## Table des matières

```php
$section->addTitle('Table des matières', 1);
$section->addTOC(
    ['size' => 11],
    ['tabLeader' => \PhpOffice\PhpWord\Style\TOC::TAB_LEADER_DOT],
    1,  // profondeur min
    3   // profondeur max
);
$section->addPageBreak();
```

## Header et Footer (section corps uniquement)

```php
$header = $section->addHeader();
$header->addText(
    $data['titre'] ?? '',
    ['size' => 9, 'italic' => true],
    ['alignment' => Jc::RIGHT]
);

$footer = $section->addFooter();
$footer->addPreserveText(
    'Page {PAGE} / {NUMPAGES}',
    ['size' => 9],
    ['alignment' => Jc::CENTER]
);
```

## Réglage global obligatoire

```php
$phpWord->getSettings()->setUpdateFields(true);
```

Cela force Word à proposer la mise à jour des champs (TOC, numéros de page…) à l’ouverture.

## Structure type d’un rapport de stage

1. Page de garde (section 1)
2. Sommaire
3. Dédicace / Remerciements / Avant-propos (optionnel)
4. Liste des sigles
5. Liste des figures
6. Liste des tableaux
7. Résumé / Abstract
8. Introduction générale
9. Première partie…
10. Deuxième partie…
11. Conclusion
12. Références bibliographiques
13. Table des matières (parfois en fin)

Tu adaptes selon le gabarit exact de ton application.
