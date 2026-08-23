# 03 – Listes des figures et des tableaux

## Décision prise

On **ne compte pas** sur les champs SEQ natifs de Word + Table of Figures automatique via PHPWord (support trop limité et fragile).

### Stratégie retenue (pragmatique et éditable)

1. Pendant la génération du contenu, on collecte toutes les figures et tableaux dans des tableaux PHP.
2. On affiche les légendes dans le corps du document avec une numérotation manuelle :
   - `Figure 1 – Description…`
   - `Tableau 3 – Description…`
3. À la fin (ou juste après le sommaire), on génère une **Liste des figures** et une **Liste des tableaux** en parcourant ces tableaux.
4. Le document reste 100 % éditable. L’utilisateur peut modifier les légendes s’il le souhaite.

## Structure de données attendue

```php
$data['figures'] = [
    [
        'numero'   => 1,
        'legende'  => 'Organigramme du RMS',
        'fichier'  => '/path/to/image.png', // optionnel
    ],
    // ...
];

$data['tableaux'] = [
    [
        'numero'  => 1,
        'legende' => 'Fiche signalétique',
    ],
    // ...
];
```

## Génération de la légende dans le corps

```php
// Après avoir inséré l’image ou le tableau
$section->addText(
    'Figure ' . $figure['numero'] . ' – ' . $figure['legende'],
    ['size' => 10, 'italic' => true],
    ['alignment' => Jc::CENTER, 'spaceBefore' => 80, 'spaceAfter' => 200]
);
```

## Génération de la Liste des figures

```php
$section->addTitle('Liste des figures', 1);

foreach ($data['figures'] as $fig) {
    $section->addText(
        'Figure ' . $fig['numero'] . ' – ' . $fig['legende'],
        ['size' => 11],
        ['spaceAfter' => 60]
    );
}
```

Même principe pour les tableaux.

## Alternative avancée (si besoin plus tard)

Si un jour on veut de vrais champs SEQ :

- Injecter du XML brut (`w:fldSimple` ou complex field) pour `SEQ Figure`.
- Laisser Word mettre à jour les champs à l’ouverture (`setUpdateFields(true)`).
- L’utilisateur devra parfois faire un clic droit → « Mettre à jour les champs ».

Cette approche est plus complexe et moins fiable aujourd’hui. On la réserve pour une version 2.

## Avantages de la méthode manuelle

- Fonctionne parfaitement dans Word **et** LibreOffice
- Pas de surprise de numérotation
- Facile à maintenir
- L’utilisateur peut encore éditer le texte
