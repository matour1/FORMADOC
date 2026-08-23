# 05 – Intégration dans l’application

## Emplacement recommandé

```
app/
└── Services/
    └── Document/
        ├── DocumentGenerator.php      // Classe principale
        ├── CoverPageBuilder.php       // Logique page de garde
        ├── ListBuilder.php            // Listes figures/tableaux
        └── StyleManager.php           // Styles & settings
```

## Exemple d’appel depuis un Controller

```php
public function generate(Request $request)
{
    $data = [
        'titre'                  => $request->input('titre'),
        'auteur'                 => $request->input('auteur'),
        'filiere'                => $request->input('filiere'),
        'specialite'             => $request->input('specialite'),
        'entreprise'             => $request->input('entreprise'),
        'date_debut'             => $request->input('date_debut'),
        'date_fin'               => $request->input('date_fin'),
        'encadreur_academique'   => $request->input('encadreur_academique'),
        'encadreur_pro'          => $request->input('encadreur_pro'),
        'annee'                  => $request->input('annee'),
        'logo_minedop'           => storage_path('app/logos/minedop.png'),
        'logo_isn'               => storage_path('app/logos/isn.png'),
        'logo_reseau'            => storage_path('app/logos/reseau.png'),
        'figures'                => $request->input('figures', []),
        'tableaux'               => $request->input('tableaux', []),
        // ... contenu des chapitres
    ];

    $generator = new DocumentGenerator();
    $path = $generator->generate($data);

    return response()->download($path, 'rapport-stage.docx')
                     ->deleteFileAfterSend(true);
}
```

## Classe DocumentGenerator (squelette)

```php
class DocumentGenerator
{
    public function generate(array $data): string
    {
        $phpWord = new \PhpOffice\PhpWord\PhpWord();
        $phpWord->getSettings()->setUpdateFields(true);
        $phpWord->getSettings()->setThemeFontLang(
            new \PhpOffice\PhpWord\Style\Language(\PhpOffice\PhpWord\Style\Language::FR_FR)
        );

        // Styles
        $this->applyTitleStyles($phpWord);

        // 1. Page de garde
        $coverBuilder = new CoverPageBuilder();
        $coverBuilder->build($phpWord, $data);

        // 2. Corps
        $bodySection = $phpWord->addSection([/* marges normales */]);
        $this->addHeaderFooter($bodySection, $data);

        // TOC
        $bodySection->addTitle('Table des matières', 1);
        $bodySection->addTOC(['size' => 11], [
            'tabLeader' => \PhpOffice\PhpWord\Style\TOC::TAB_LEADER_DOT
        ], 1, 3);
        $bodySection->addPageBreak();

        // Contenu + légendes
        $this->buildContent($bodySection, $data);

        // Listes
        $listBuilder = new ListBuilder();
        $listBuilder->buildFiguresList($bodySection, $data['figures'] ?? []);
        $listBuilder->buildTablesList($bodySection, $data['tableaux'] ?? []);

        // Sauvegarde
        $filename = storage_path('app/temp/rapport_' . uniqid() . '.docx');
        $phpWord->save($filename, 'Word2007');

        return $filename;
    }
}
```

## Gestion des logos

- Stocker les logos dans `storage/app/logos/`
- Toujours vérifier `file_exists()` avant `addImage()`
- Prévoir des versions PNG transparentes de bonne qualité

## Tests recommandés

1. Générer un document et l’ouvrir dans **Microsoft Word** → vérifier la page de garde + TOC
2. Ouvrir le même fichier dans **LibreOffice** → vérifier qu’il n’y a pas de corruption
3. Modifier manuellement un titre dans Word → vérifier que le document reste cohérent
